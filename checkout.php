<?php
/*
Plugin Name: Bitcredits Woocommerce
Plugin URI: http://bitcredits.io
Description: This plugin adds the Bitcredits Bitcoin payment gateway to your WooCommerce shopping cart.  WooCommerce is required.
Version: 1.0
Author: Bitcredits Core Team
Author URI: http://bitcredits.io
License: MIT
*/

/**
 * Check if WooCommerce is active
 **/
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) 
{
    function declareWooBitcredits() 
    {
        if ( ! class_exists( 'WC_Payment_Gateways' ) ) 
            return;

        class WC_Bitcredits extends WC_Payment_Gateway 
        {

            public static $amount;

            public function __construct() 
            {
                $this->id = 'bitcredits';
                $this->icon = plugin_dir_url(__FILE__).'bitcredits.png';
                $this->has_fields = true;
             
                // Load the form fields.
                $this->init_form_fields();
             
                // Load the settings.
                $this->init_settings();
             
                // Define user set variables
                $this->title = $this->settings['title'];
                $this->description = $this->settings['description'];

                // Actions
                add_action('woocommerce_update_options_payment_gateways_'.$this->id, array(&$this, 'process_admin_options'));
            }
            
            function init_form_fields() 
            {
                $this->form_fields = array(
                    'enabled' => array(
                        'title' => __( 'Enable/Disable', 'woothemes' ),
                        'type' => 'checkbox',
                        'label' => __( 'Enable Bitcredits Payment', 'woothemes' ),
                        'default' => 'yes'
                    ),
                    'title' => array(
                        'title' => __( 'Title', 'woothemes' ),
                        'type' => 'text',
                        'description' => __( 'This controls the title which the user sees during checkout.', 'woothemes' ),
                        'default' => __( 'Bitcoin', 'woothemes' )
                    ),
                    'description' => array(
                        'title' => __( 'Customer Message', 'woothemes' ),
                        'type' => 'textarea',
                        'description' => __( 'Message to explain how the customer will be paying for the purchase.', 'woothemes' ),
                        'default' => 'Please pay below.'
                    ),
                    'api_key' => array(
                        'title' => __('API Key', 'woothemes'),
                        'type' => 'text',
                        'description' => __('Enter the API key you created at bitcredits.io'),
                    ),
                    'api_endpoint' => array(
                        'title' => __('API Endpoint', 'woothemes'),
                        'type' => 'text',
                        'description' => __('Enter the API endpoint you wish to use'),
                        'default' => 'https://api.bitcredits.io'
                    )
                );
            }
                
            public function admin_options() {
                ?>
                <h3><?php _e('Bitcoin Payment', 'woothemes'); ?></h3>
                <p><?php _e('Allows bitcoin payments via Bitcredits.io', 'woothemes'); ?></p>
                <table class="form-table">
                <?php $this->generate_settings_html(); ?>
                </table>
                <?php
            }
            
            function payment_fields() {
                get_currentuserinfo();
                global $current_user;
                ?>
                <div id="bitcredits-payment-box">Loading...</div>
                <script type="text/javascript">
                //<![CDATA[
                (function(){
                    if (document.getElementById("BitC") == null) {
                        var bitc=document.createElement('script');
                        bitc.type='text/javascript';
                        bitc.setAttribute("id", "BitC");
                        bitc.src = '<?php echo $this->get_option( 'api_endpoint' ); ?>/v1/bitcredits.js';
                        var s=document.getElementsByTagName('script')[0];
                        s.parentNode.insertBefore(bitc,s);
                    }
                    window.BitCredits = window.BitCredits || [];
                    window.BitCredits.push(['onConfigReady', function(){
                        window.BitCredits.push(['setupWoocommerce', <?php echo self::$amount; ?>, <?php echo json_encode(array(
                            'email' => $current_user->user_email
                        )); ?>]);
                    }]);
                }());
                //]]>
                </script>
                <?php
            }
             
            function process_payment( $order_id ) {
                
                global $woocommerce, $wpdb;

                $order = new WC_Order( $order_id );
                $key = $this->get_option( 'api_key' );
                    $endpoint = $this->get_option( 'api_endpoint' );

                    if (!isset($_COOKIE['bitc'])){
                        $woocommerce->add_error(__('Could not place order.'));
                        return;
                    }

                    $method = '/v1/transactions';
                    $data = array(
                        'api_key' => $key,
                        'src_token' => $_COOKIE['bitc'],
                        'dst_account' => '/woocommerce/orders/'.$order->get_order_number(),
                        'dst_account_create' => true,
                        'amount' => $order->get_total(),
                        'data' => array(
                            'email' => $order->billing_email,
                            'firstname' => $order->billing_first_name,
                            'lastname' => $order->billing_last_name,
                            'order_id' => $order->get_order_number()
                        )
                    );
                
                    $ch = curl_init();
                    $data_string = json_encode($data);
                    curl_setopt($ch, CURLOPT_URL, $endpoint . $method);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Content-Length: ' . strlen($data_string)));
                    $result = curl_exec($ch);
                    $res = json_decode($result, true);

                    if ($res == null
                     || !isset($res['status'])) {
                        $woocommerce->add_error(__('Transaction not completed.'));
                        return;
                    } elseif ($res['status'] == 'error') {
                        if (isset($res['message'])) {
                            $woocommerce->add_error(__('Error while processing payment: ').$res['message']);
                            return;
                        } else {
                            $woocommerce->add_error(__('Transaction not completed. No error message was provided.'));
                            return;
                        }
                    }

                $order->payment_complete();

                $woocommerce->cart->empty_cart();
            
                return array(
                    'result'    => 'success',
                    'redirect'  => $this->get_return_url( $order ),
                );
            }    

        }
    }

    function add_bitcredits_gateway( $methods ) {
        $methods[] = 'WC_Bitcredits'; 
        return $methods;
    }
    
    function setTotal($amount){
        WC_Bitcredits::$amount = $amount;

        return $amount;
    }

    add_filter('woocommerce_calculated_total', 'setTotal' );
    add_filter('woocommerce_payment_gateways', 'add_bitcredits_gateway' );

    add_action('plugins_loaded', 'declareWooBitcredits', 0);
}