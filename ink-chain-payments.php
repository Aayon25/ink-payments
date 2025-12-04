<?php
/**
 * Plugin Name: Ink Chain Payments
 * Description: Accept ETH and ARCH payments - FIXED Alchemy integration with full logging.
 * Version: 1.5.0-fixed
 * Author: Archival Ink
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'plugins_loaded', 'icp_init_gateway', 11 );

function icp_init_gateway() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) return;

    class WC_Gateway_Ink_Chain extends WC_Payment_Gateway {

        public function __construct() {
            $this->id                 = 'ink_chain';
            $this->has_fields         = true;
            $this->method_title       = 'Ink Chain';
            $this->method_description = 'Accept ETH and ARCH payments on Ink Chain with real-time InkyPump pricing.';

            $this->init_form_fields();
            $this->init_settings();

            $this->enabled            = $this->get_option( 'enabled' );
            $this->title              = $this->get_option( 'title' );
            $this->description        = $this->get_option( 'description' );
            $this->ink_address        = $this->get_option( 'ink_address' );
            $this->network_name       = $this->get_option( 'network_name' );
            $this->payment_window_minutes = absint( $this->get_option( 'payment_window_minutes', 60 ) );
            $this->alchemy_api_key    = $this->get_option( 'alchemy_api_key' );
            $this->arch_token_address = $this->get_option( 'arch_token_address' );
            $this->default_slippage   = floatval( $this->get_option( 'default_slippage', 2 ) );
            $this->eth_usd_price      = floatval( $this->get_option( 'eth_usd_price', 3000 ) );
            $this->auto_verify        = $this->get_option( 'auto_verify', 'yes' );

            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
            add_action( 'woocommerce_email_instructions', array( $this, 'email_instructions' ), 10, 3 );
            add_action( 'woocommerce_order_item_add_action_buttons', array( $this, 'add_verify_payment_button' ) );
            add_action( 'wp_ajax_icp_verify_payment', array( $this, 'ajax_verify_payment' ) );
            // Add order action dropdown entry for manual verification
            add_filter( 'woocommerce_order_actions', array( $this, 'register_verify_order_action' ) );
            add_action( 'woocommerce_order_action_icp_verify_payment', array( $this, 'handle_verify_order_action' ) );

// Auto-verification hooks
            add_action( 'woocommerce_order_status_on-hold', array( $this, 'schedule_auto_verification' ), 10, 2 );
            add_action( 'icp_auto_verify_payment', array( $this, 'auto_verify_payment' ), 10, 1 );
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title'   => 'Enable/Disable',
                    'type'    => 'checkbox',
                    'label'   => 'Enable Ink Chain payments',
                    'default' => 'no',
                ),
                'title' => array(
                    'title'       => 'Title',
                    'type'        => 'text',
                    'description' => 'Payment method title.',
                    'default'     => 'Pay with Ink Chain',
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => 'Description',
                    'type'        => 'textarea',
                    'description' => 'Shown at checkout.',
                    'default'     => 'Pay with ETH or ARCH tokens.',
                    'desc_tip'    => true,
                ),
                'ink_address' => array(
                    'title'       => 'Your Ink Wallet Address',
                    'type'        => 'text',
                    'description' => 'Address for receiving payments.',
                    'default'     => '',
                ),
                'network_name' => array(
                    'title'       => 'Network Name',
                    'type'        => 'text',
                    'default'     => 'Ink Chain (L2)',
                ),
                'payment_window_minutes' => array(
                    'title'       => 'Payment Window (minutes)',
                    'type'        => 'number',
                    'default'     => 60,
                    'desc_tip'    => true,
                ),
                'alchemy_api_key' => array(
                    'title'       => 'Alchemy API Key',
                    'type'        => 'text',
                    'description' => 'For payment verification.',
                    'default'     => '',
                    'desc_tip'    => true,
                ),
                'arch_token_address' => array(
                    'title'       => 'ARCH Token Address',
                    'type'        => 'text',
                    'description' => 'ARCH contract address on Ink Chain.',
                    'default'     => '',
                    'desc_tip'    => true,
                ),
                'eth_usd_price' => array(
                    'title'       => 'Manual ETH Price (USD)',
                    'type'        => 'number',
                    'description' => 'Used when InkyPump only returns token price in ETH. No external price API is called.',
                    'default'     => 3000,
                    'custom_attributes' => array( 'step' => '0.01', 'min' => '0' ),
                    'desc_tip'    => true,
                ),
                'default_slippage' => array(
                    'title'       => 'Slippage (%)',
                    'type'        => 'number',
                    'default'     => 2,
                    'custom_attributes' => array( 'step' => '0.1', 'min' => '0' ),
                    'desc_tip'    => true,
                ),
                'auto_verify' => array(
                    'title'       => 'Automatic Verification',
                    'type'        => 'checkbox',
                    'label'       => 'Automatically verify payments via Alchemy',
                    'description' => 'Check payments every 2 minutes for up to 2 hours.',
                    'default'     => 'yes',
                    'desc_tip'    => true,
                ),
            );
        }

        public function payment_fields() {
            if ( $this->description ) {
                echo wpautop( wp_kses_post( $this->description ) );
            }
            if ( ! empty( $this->network_name ) ) {
                echo '<p><strong>Network:</strong> ' . esc_html( $this->network_name ) . '</p>';
            }
            echo '<div style="margin: 15px 0;"><p><strong>Payment Token:</strong></p>';
            echo '<label style="display: block; margin-bottom: 10px;"><input type="radio" name="ink_chain_token" value="eth" checked style="margin-right: 8px;" />ETH</label>';
            if ( ! empty( $this->arch_token_address ) ) {
                echo '<label style="display: block;"><input type="radio" name="ink_chain_token" value="arch" style="margin-right: 8px;" />ARCH Token</label>';
            }
            echo '</div>';
        }

        public function process_payment( $order_id ) {
            $order = wc_get_order( $order_id );
            $selected_token = isset( $_POST['ink_chain_token'] ) ? sanitize_text_field( $_POST['ink_chain_token'] ) : 'eth';
            $order->update_meta_data( '_ink_chain_payment_token', $selected_token );
            $order->save();
            $order->update_status( 'on-hold', 'Awaiting Ink Chain payment.' );
            wc_reduce_stock_levels( $order_id );
            WC()->cart->empty_cart();
            return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
        }

        public function get_token_price_usd( $token_symbol ) {
            if ( $token_symbol === 'ETH' ) {
                // Use manual ETH/USD price from plugin settings to avoid external price API dependencies.
                if ( empty( $this->eth_usd_price ) || $this->eth_usd_price <= 0 ) {
                    throw new Exception( 'ETH USD price is not configured. Please set "Manual ETH Price (USD)" in the Ink Chain Payments settings.' );
                }
                return floatval( $this->eth_usd_price );
            }
            
            if ( $token_symbol === 'ARCH' && ! empty( $this->arch_token_address ) ) {
                $url = 'https://inkypump.com/api/token?address=' . $this->arch_token_address;
                $response = wp_remote_get( $url, array( 
                    'timeout' => 15,
                    'headers' => array( 'accept' => 'application/json' ),
                ) );
                
                if ( is_wp_error( $response ) ) {
                    throw new Exception( 'InkyPump API error: ' . $response->get_error_message() );
                }
                
                $code = wp_remote_retrieve_response_code( $response );
                if ( $code !== 200 ) {
                    $body = wp_remote_retrieve_body( $response );
                    throw new Exception( 'InkyPump API returned status ' . $code . ': ' . substr( $body, 0, 200 ) );
                }
                
                $body = wp_remote_retrieve_body( $response );
                $json = json_decode( $body, true );
                
                // Handle different response formats
                $price = null;
                if ( isset( $json['data']['priceUSD'] ) ) {
                    $price = $json['data']['priceUSD'];
                } elseif ( isset( $json['data']['price_usd'] ) ) {
                    $price = $json['data']['price_usd'];
                } elseif ( isset( $json['priceUSD'] ) ) {
                    $price = $json['priceUSD'];
                } elseif ( isset( $json['price_usd'] ) ) {
                    $price = $json['price_usd'];
                } elseif ( isset( $json['data']['price_eth'] ) ) {
                    // If price is in ETH, convert to USD
                    $price_eth = floatval( $json['data']['price_eth'] );
                    $eth_usd = $this->get_token_price_usd( 'ETH' );
                    $price = $price_eth * $eth_usd;
                } elseif ( isset( $json['price_eth'] ) ) {
                    $price_eth = floatval( $json['price_eth'] );
                    $eth_usd = $this->get_token_price_usd( 'ETH' );
                    $price = $price_eth * $eth_usd;
                }
                
                if ( $price === null ) {
                    throw new Exception( 'ARCH price not found in InkyPump response. Full response: ' . substr( $body, 0, 500 ) );
                }
                
                $price = floatval( $price );
                if ( $price <= 0 ) {
                    throw new Exception( 'Invalid ARCH price from InkyPump: ' . $price );
                }
                
                return $price;
            }
            
            throw new Exception( 'Unknown token: ' . $token_symbol );
        }

        public function calculate_crypto_amount( $order_total_usd, $token_symbol, $slippage_percent = null ) {
            if ( $slippage_percent === null ) {
                $slippage_percent = $this->default_slippage;
            }
            
            try {
                $token_price_usd = $this->get_token_price_usd( $token_symbol );
                $base_amount = $order_total_usd / $token_price_usd;
                return $base_amount * ( 1 + $slippage_percent / 100 );
            } catch ( Exception $e ) {
                // Store error for display
                return array( 'error' => $e->getMessage() );
            }
        }

        public function thankyou_page( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) return;
            
            $order_total = $order->get_formatted_order_total();
            $order_total_numeric = $order->get_total();
            $selected_token = $order->get_meta( '_ink_chain_payment_token', true );
            if ( empty( $selected_token ) ) $selected_token = 'eth';
            
            $token_symbol = strtoupper( $selected_token );
            $token_name = $token_symbol === 'ETH' ? 'Ethereum (ETH)' : 'ARCH Token';
            
            $crypto_result = $this->calculate_crypto_amount( $order_total_numeric, $token_symbol );
            
            // Check if error occurred
            if ( is_array( $crypto_result ) && isset( $crypto_result['error'] ) ) {
                echo '<div style="max-width:800px;margin:0 auto 40px;">';
                echo '<h2>Payment Error</h2>';
                echo '<div style="background:#fee;border:2px solid #c00;padding:20px;margin:20px 0;border-radius:5px;">';
                echo '<p><strong>Unable to fetch ' . esc_html( $token_symbol ) . ' price from InkyPump API.</strong></p>';
                echo '<p>Error: ' . esc_html( $crypto_result['error'] ) . '</p>';
                echo '<p>Please contact support or try again later.</p>';
                echo '</div>';
                echo '</div>';
                return;
            }
            
            $crypto_amount = $crypto_result;
            
            try {
                $token_price = $this->get_token_price_usd( $token_symbol );
            } catch ( Exception $e ) {
                $token_price = 0;
            }
            
            $crypto_amount_formatted = $token_symbol === 'ETH' ? number_format( $crypto_amount, 6 ) . ' ETH' : number_format( $crypto_amount, 2 ) . ' ARCH';

            echo '<div style="max-width:800px;margin:0 auto 40px;">';
            echo '<h2>Waiting for Ink Chain Payment</h2>';
            echo '<p>Your order is on hold while we wait for your payment.</p>';
            echo '<ol style="margin-left:20px;margin-top:20px;font-size:15px;line-height:1.8;">';
            echo '<li><strong>Review order details.</strong> Order #<code>#' . esc_html( $order->get_order_number() ) . '</code></li>';
            echo '<li><strong>Send payment.</strong><br />';
            if ( ! empty( $this->network_name ) ) {
                echo 'Network: <strong>' . esc_html( $this->network_name ) . '</strong><br />';
            }
            echo 'Token: <strong>' . esc_html( $token_name ) . '</strong><br />';
            if ( $token_symbol === 'ARCH' && ! empty( $this->arch_token_address ) ) {
                echo 'Contract: <code style="font-size:12px;">' . esc_html( $this->arch_token_address ) . '</code><br />';
            }
            echo 'Send to: <code>' . esc_html( $this->ink_address ) . '</code><br />';
            echo '<div style="background:#f5f5f5;padding:12px;margin:10px 0;border-left:3px solid #0073aa;">';
            echo '<strong>Order Total:</strong> ' . wp_kses_post( $order_total ) . '<br />';
            echo '<strong>Amount to send:</strong> <span style="font-size:18px;color:#0073aa;"><strong>' . esc_html( $crypto_amount_formatted ) . '</strong></span><br />';
            if ( $token_price > 0 ) {
                echo '<em style="font-size:13px;color:#666;">(Includes ' . number_format( $this->default_slippage, 1 ) . '% slippage. Rate: 1 ' . esc_html( $token_symbol ) . ' = $' . number_format( $token_price, 6 ) . ')</em>';
            }
            echo '</div>';
            if ( $this->payment_window_minutes > 0 ) {
                echo '<em>Send within ' . $this->payment_window_minutes . ' minutes.</em>';
            }
            echo '</li>';
            echo '<li><strong>Wait for confirmation.</strong> We will verify and process your order.</li>';
            echo '</ol>';
            echo '</div>';
        }

        public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
            if ( $sent_to_admin || ! $order instanceof WC_Order || $order->get_payment_method() !== $this->id || ! $order->has_status( 'on-hold' ) || empty( $this->ink_address ) ) {
                return;
            }
            $order_total = $order->get_formatted_order_total();
            $order_total_numeric = $order->get_total();
            $selected_token = $order->get_meta( '_ink_chain_payment_token', true );
            if ( empty( $selected_token ) ) $selected_token = 'eth';
            $token_symbol = strtoupper( $selected_token );
            $token_name = $token_symbol === 'ETH' ? 'ETH' : 'ARCH';
            
            $crypto_result = $this->calculate_crypto_amount( $order_total_numeric, $token_symbol );
            if ( is_array( $crypto_result ) && isset( $crypto_result['error'] ) ) {
                return; // Skip email if error
            }
            
            $crypto_amount = $crypto_result;
            $crypto_amount_formatted = $token_symbol === 'ETH' ? number_format( $crypto_amount, 6 ) . ' ETH' : number_format( $crypto_amount, 2 ) . ' ARCH';

            if ( $plain_text ) {
                echo "\nInk Chain Payment\n";
                echo "Network: " . $this->network_name . "\n";
                echo "Token: " . $token_name . "\n";
                echo "Send to: " . $this->ink_address . "\n";
                echo "Amount: " . $crypto_amount_formatted . "\n";
            } else {
                echo '<h2>Ink Chain Payment</h2>';
                echo '<p><strong>Network:</strong> ' . esc_html( $this->network_name ) . '<br />';
                echo '<strong>Token:</strong> ' . esc_html( $token_name ) . '<br />';
                echo '<strong>Send to:</strong> ' . esc_html( $this->ink_address ) . '<br />';
                echo '<strong>Amount:</strong> ' . esc_html( $crypto_amount_formatted ) . '</p>';
            }
        }

        public function get_incoming_transactions( $limit = 100 ) {
            if ( empty( $this->alchemy_api_key ) || empty( $this->ink_address ) ) {
                error_log( 'ICP: Missing Alchemy API key or wallet address' );
                return array();
            }
            
            $rpc_url = 'https://ink-mainnet.g.alchemy.com/v2/' . $this->alchemy_api_key;
            
            $request_body = array(
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'alchemy_getAssetTransfers',
                'params' => array(
                    array(
                        'toAddress' => $this->ink_address,
                        'category' => array( 'external', 'erc20' ),
                        'maxCount' => '0x' . dechex( $limit ),
                        'order' => 'desc',
                        'withMetadata' => true
                    )
                )
            );
            
            error_log( 'ICP: Alchemy API URL: ' . $rpc_url );
            error_log( 'ICP: Request params: ' . wp_json_encode( $request_body['params'][0] ) );
            
            $response = wp_remote_post( $rpc_url, array(
                'headers' => array( 'Content-Type' => 'application/json' ),
                'body' => wp_json_encode( $request_body ),
                'timeout' => 30
            ) );
            
            if ( is_wp_error( $response ) ) {
                error_log( 'ICP: Alchemy HTTP error: ' . $response->get_error_message() );
                return array();
            }
            
            $status_code = wp_remote_retrieve_response_code( $response );
            $body = wp_remote_retrieve_body( $response );
            
            error_log( 'ICP: Alchemy response code: ' . $status_code );
            error_log( 'ICP: Alchemy response body: ' . substr( $body, 0, 500 ) );
            
            if ( $status_code !== 200 ) {
                error_log( 'ICP: Alchemy returned non-200 status' );
                return array();
            }
            
            $data = json_decode( $body, true );
            
            if ( isset( $data['error'] ) ) {
                error_log( 'ICP: Alchemy RPC error: ' . wp_json_encode( $data['error'] ) );
                return array();
            }
            
            if ( ! isset( $data['result']['transfers'] ) ) {
                error_log( 'ICP: No transfers array in response' );
                return array();
            }
            
            $transfers = $data['result']['transfers'];
            error_log( 'ICP: Found ' . count( $transfers ) . ' total transfers' );
            
            return $transfers;
        }

        
        public function verify_transaction( $order ) {
            if ( empty( $this->alchemy_api_key ) ) {
                return array( 'success' => false, 'message' => 'Alchemy not configured.' );
            }
            if ( empty( $this->ink_address ) ) {
                return array( 'success' => false, 'message' => 'Wallet address not configured.' );
            }

            error_log( 'ICP SIMPLE VERIFY: starting' );
            error_log( 'ICP SIMPLE VERIFY: Order ID ' . $order->get_id() );

            // Determine token symbol (ARCH vs ETH)
            $selected_token = $order->get_meta( '_ink_chain_payment_token', true );
            if ( empty( $selected_token ) ) {
                $selected_token = 'eth';
            }
            $token_symbol   = strtoupper( $selected_token );
            $expected_erc20 = ( $token_symbol === 'ARCH' );

            error_log( 'ICP SIMPLE VERIFY: Token symbol ' . $token_symbol );

            // Pull recent incoming transfers to our address
            $transfers = $this->get_incoming_transactions();
            error_log( 'ICP SIMPLE VERIFY: Transfers count ' . count( $transfers ) );

            if ( empty( $transfers ) ) {
                return array( 'success' => false, 'message' => 'No incoming transfers found.' );
            }

            $our_address      = strtolower( $this->ink_address );
            $order_timestamp  = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time();
            $window_seconds   = 7200; // 2 hour window
            $checked_count    = 0;

            foreach ( $transfers as $transfer ) {
                $checked_count++;

                $tx_hash = isset( $transfer['hash'] ) ? $transfer['hash'] : '';
                if ( empty( $tx_hash ) ) {
                    continue;
                }

                // Skip if already used for another order
                if ( $this->is_transaction_used( $tx_hash, $order->get_id() ) ) {
                    continue;
                }

                // Destination address must match our Ink receiving wallet
                $to_address = isset( $transfer['to'] ) ? strtolower( $transfer['to'] ) : '';
                if ( empty( $to_address ) || $to_address !== $our_address ) {
                    continue;
                }

                // Token type must match (ERC20 for ARCH, external/native for ETH)
                $category = isset( $transfer['category'] ) ? $transfer['category'] : '';
                $is_erc20 = ( $category === 'erc20' );
                if ( $expected_erc20 !== $is_erc20 ) {
                    continue;
                }

                // Basic time check: TX must be after order creation and within 2 hours
                $tx_time_str = isset( $transfer['metadata']['blockTimestamp'] ) ? $transfer['metadata']['blockTimestamp'] : '';
                if ( ! empty( $tx_time_str ) ) {
                    $tx_timestamp = strtotime( $tx_time_str );
                    if ( $tx_timestamp < $order_timestamp || ( $tx_timestamp - $order_timestamp ) > $window_seconds ) {
                        continue;
                    }
                }

                // If we reach here, we consider the payment confirmed
                $order->update_meta_data( '_ink_chain_tx_hash', $tx_hash );
                $order->save();

                $order->add_order_note(
                    sprintf(
                        'Ink Chain payment confirmed on-chain. TX: %s',
                        $tx_hash
                    )
                );

                return array(
                    'success'     => true,
                    'message'     => 'Payment confirmed on-chain.',
                    'transaction' => $transfer,
                );
            }

            return array(
                'success' => false,
                'message' => 'No matching transfer (address/time/token) found. Checked ' . $checked_count . ' transactions.',
            );
        }

private function is_transaction_used( $tx_hash, $current_order_id ) {
            global $wpdb;
            
            $result = $wpdb->get_var( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} 
                WHERE meta_key = '_ink_chain_tx_hash' 
                AND meta_value = %s 
                AND post_id != %d
                LIMIT 1",
                $tx_hash,
                $current_order_id
            ) );
            
            return ! empty( $result );
        }

        
        /**
         * Register custom order action for verifying Ink Chain payment.
         */
        public function register_verify_order_action( $actions ) {
            // Key 'icp_verify_payment' will trigger action hook 'woocommerce_order_action_icp_verify_payment'.
            $actions['icp_verify_payment'] = __( 'Verify Ink Payment', 'ink-chain-payments' );
            return $actions;
        }

        /**
         * Handle the custom order action for verification from the dropdown.
         *
         * @param int|WC_Order $order
         */
        public function handle_verify_order_action( $order ) {
            if ( is_numeric( $order ) ) {
                $order = wc_get_order( $order );
            }
            if ( ! $order instanceof WC_Order ) {
                return;
            }

            $result = $this->verify_transaction( $order );
            if ( $result['success'] ) {
                $order->update_status( 'processing', 'Payment verified via order action.' );
                $order->add_order_note( 'Ink Chain payment verified via order action.' );
            } else {
                $order->add_order_note( 'Ink Chain payment verification failed: ' . $result['message'] );
            }
        }

public function add_verify_payment_button( $order ) {
            if ( ! $order instanceof WC_Order || $order->get_payment_method() !== $this->id || ! $order->has_status( 'on-hold' ) || empty( $this->alchemy_api_key ) ) return;
            ?>
            <button type="button" class="button icp-verify-payment" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>">Verify Payment</button>
            <div class="icp-result" style="margin-top:10px;"></div>
            <script>
            jQuery(function($) {
                $('.icp-verify-payment').on('click', function(e) {
                    e.preventDefault();
                    var btn = $(this), orderId = btn.data('order-id'), result = $('.icp-result');
                    btn.prop('disabled', true).text('Verifying...');
                    result.html('');
                    $.ajax({
                        url: ajaxurl, type: 'POST',
                        data: { action: 'icp_verify_payment', order_id: orderId, nonce: '<?php echo esc_js( wp_create_nonce( 'icp_verify' ) ); ?>' },
                        success: function(r) {
                            if (r.success) {
                                result.html('<div class="notice notice-success inline"><p>' + r.data.message + '</p></div>');
                                if (r.data.verified) setTimeout(function() { location.reload(); }, 2000);
                            } else {
                                result.html('<div class="notice notice-error inline"><p>' + r.data.message + '</p></div>');
                            }
                        },
                        error: function() { result.html('<div class="notice notice-error inline"><p>Error occurred.</p></div>'); },
                        complete: function() { btn.prop('disabled', false).text('Verify Payment'); }
                    });
                });
            });
            </script>
            <?php
        }

        /**
         * Schedule automatic payment verification when order is placed
         */
        public function schedule_auto_verification( $order_id, $order ) {
            if ( ! $order instanceof WC_Order || $order->get_payment_method() !== $this->id ) {
                return;
            }
            
            if ( $this->auto_verify !== 'yes' || empty( $this->alchemy_api_key ) ) {
                return;
            }
            
            // Schedule recurring checks every 2 minutes for up to 2 hours (60 checks)
            $payment_window = $this->payment_window_minutes * 60; // Convert to seconds
            $check_interval = 120; // 2 minutes
            $max_checks = min( 60, floor( $payment_window / $check_interval ) );
            
            for ( $i = 1; $i <= $max_checks; $i++ ) {
                $timestamp = time() + ( $check_interval * $i );
                
                if ( function_exists( 'as_schedule_single_action' ) ) {
                    // Use Action Scheduler if available (WooCommerce 3.5+)
                    as_schedule_single_action( $timestamp, 'icp_auto_verify_payment', array( $order_id ), 'ink-chain-payments' );
                } else {
                    // Fallback to wp_schedule_single_event
                    wp_schedule_single_event( $timestamp, 'icp_auto_verify_payment', array( $order_id ) );
                }
            }
            
            $order->add_order_note( sprintf( 'Automatic payment verification scheduled. Will check every 2 minutes for %d minutes.', $this->payment_window_minutes ) );
        }
        
        /**
         * Automatically verify payment via scheduled action
         */
        public function auto_verify_payment( $order_id ) {
            $order = wc_get_order( $order_id );
            
            if ( ! $order || $order->get_payment_method() !== $this->id ) {
                return;
            }
            
            // Only verify if still on-hold
            if ( ! $order->has_status( 'on-hold' ) ) {
                return;
            }
            
            // Attempt verification
            $result = $this->verify_transaction( $order );
            
            if ( $result['success'] ) {
                // Payment found and verified
                $order->update_status( 'processing', 'Payment automatically verified via Alchemy.' );
                $order->add_order_note( sprintf( 'Auto-verification successful. TX: %s', $order->get_meta( '_ink_chain_tx_hash' ) ) );
                
                // Cancel any remaining scheduled checks for this order
                $this->cancel_scheduled_verifications( $order_id );
            }
            // If not successful, do nothing - next scheduled check will try again
        }
        
        /**
         * Cancel all scheduled verification checks for an order
         */
        private function cancel_scheduled_verifications( $order_id ) {
            if ( function_exists( 'as_unschedule_all_actions' ) ) {
                as_unschedule_all_actions( 'icp_auto_verify_payment', array( $order_id ), 'ink-chain-payments' );
            } else {
                // Fallback for wp_cron
                $timestamp = wp_next_scheduled( 'icp_auto_verify_payment', array( $order_id ) );
                while ( $timestamp ) {
                    wp_unschedule_event( $timestamp, 'icp_auto_verify_payment', array( $order_id ) );
                    $timestamp = wp_next_scheduled( 'icp_auto_verify_payment', array( $order_id ) );
                }
            }
        }

        public function ajax_verify_payment() {
            check_ajax_referer( 'icp_verify', 'nonce' );
            if ( ! current_user_can( 'edit_shop_orders' ) ) wp_send_json_error( array( 'message' => 'Permission denied.' ) );
            $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
            $order = wc_get_order( $order_id );
            if ( ! $order ) wp_send_json_error( array( 'message' => 'Invalid order.' ) );
            $result = $this->verify_transaction( $order );
            if ( $result['success'] ) {
                $order->update_status( 'processing', 'Payment verified.' );
                wp_send_json_success( array( 'message' => $result['message'], 'verified' => true ) );
            } else {
                wp_send_json_success( array( 'message' => $result['message'], 'verified' => false ) );
            }
        }
    }

    function icp_add_gateway( $gateways ) {
        $gateways[] = 'WC_Gateway_Ink_Chain';
        return $gateways;
    }
    add_filter( 'woocommerce_payment_gateways', 'icp_add_gateway' );
}
