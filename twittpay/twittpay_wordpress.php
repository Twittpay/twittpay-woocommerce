<?php
/*
Plugin Name: TwittPay Gateway
Plugin URI: https://checkout.twittpay.com
Description: TwittPay WooCommerce Payment Gateway
Author: TwittPay
Version: 1.1.0
*/

/**
 * WHAT CHANGED IN 1.1.0
 * ---------------------
 * 1. The create request now sends a webhook_url, and there is a matching
 *    endpoint to receive it: /?wc-api=twittpay_webhook
 *
 *    Until now this plugin learned the result of a payment in exactly one way:
 *    the customer's browser coming back to ?wc-api=twittpay_success. If the
 *    payment is parked as pending and the merchant approves it an hour later -
 *    by which time the customer has long closed the tab - nobody ever told
 *    WooCommerce anything and the order stayed on hold forever. The webhook is
 *    called server to server, so the approval completes the order on its own.
 *
 * 2. The verify response is read correctly now. metadata comes back as a JSON
 *    string, not as an object, so the old is_object() test never matched and the
 *    order id was only ever found from the query string - which a webhook does
 *    not have.
 *
 * 3. The Brand Key setting field was missing. The code read
 *    $this->get_option('brand_key') and sent it in the metadata, but there was
 *    no box to type it into, so it was always empty.
 *
 * 4. A pending payment is now shown to the customer as pending instead of as a
 *    failure, and the order is left on hold rather than sent back to checkout.
 *
 * 5. Completing an order goes through one method used by both the browser return
 *    and the webhook, and it refuses to run twice for the same order. The
 *    webhook and the browser can easily both arrive.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Prevent fatal errors if WooCommerce is not active.
 */
add_action('plugins_loaded', 'twittpay_init_gateway', 11);
function twittpay_init_gateway() {
    if (!class_exists('WC_Payment_Gateway')) {
        // WooCommerce is not active - do nothing.
        return;
    }

    class WC_TWITTPAY_GATEWAY extends WC_Payment_Gateway {

        public $payment_url = 'https://checkout.twittpay.com/api/payment/create';
        public $verify_url = 'https://checkout.twittpay.com/api/payment/verify';
        public $log_enabled = false;
        public $log = null;
        public $usd_to_bdt_rate; // Conversion rate
        public $api_key;
        public $brand_key;
        public $order_status;
        public $digital_order_status;

        public function __construct() {
            $this->id = 'twittpay';
            $this->method_title = 'TwittPay';
            $this->method_description = 'TwittPay payment gateway for WooCommerce';
            $this->has_fields = false;

            // Icon (logo)
            $this->icon = 'https://checkout.twittpay.com/logo.png';

            $this->init_form_fields();
            $this->init_settings();

            // Get settings
            $this->title = $this->get_option('title', 'TwittPay');
            $this->api_key = $this->get_option('api_key');
            $this->brand_key = $this->get_option('brand_key');
            $this->order_status = $this->get_option('order_status', 'processing');
            $this->digital_order_status = $this->get_option('digital_order_status', 'completed');
            $this->log_enabled = $this->get_option('logging') === 'yes';
            // Get new rate setting
            $this->usd_to_bdt_rate = $this->get_option('usd_to_bdt_rate', 110);

            // Hook for saving settings
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

            // Hook for custom return URLs
            add_action('woocommerce_api_twittpay_success', array($this, 'payment_success_return'));
            add_action('woocommerce_api_twittpay_cancel', array($this, 'payment_cancel_return'));

            // Server to server webhook. This is the one that lets a payment
            // approved by the merchant later still complete the order.
            add_action('woocommerce_api_twittpay_webhook', array($this, 'payment_webhook'));

            // Hooks to display transaction details in Admin and Customer Order Views
            add_action('woocommerce_admin_order_data_after_order_details', array($this, 'display_admin_order_details'));
            add_action('woocommerce_order_details_after_order_table', array($this, 'display_customer_order_details'));
        }

        /**
         * Get logger instance
         */
        public function get_logger() {
            if ( $this->log_enabled && empty( $this->log ) ) {
                $this->log = wc_get_logger();
            }
            return $this->log;
        }

        /**
         * Write to log
         */
        public function log( $message, $level = 'info' ) {
            if ( $this->log_enabled ) {
                $logger = $this->get_logger();
                if ( $logger ) {
                    $logger->log( $level, $message, array( 'source' => $this->id ) );
                }
            }
        }

        /**
         * Initialize Gateway Settings Form Fields
         */
        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'Enable/Disable',
                    'type' => 'checkbox',
                    'label' => 'Enable TwittPay Payment Gateway',
                    'default' => 'no'
                ),
                'title' => array(
                    'title' => 'Title',
                    'type' => 'text',
                    'default' => 'TwittPay',
                    'description' => 'This controls the title which the user sees during checkout.'
                ),
                'description' => array(
                    'title' => 'Description',
                    'type' => 'textarea',
                    'default' => 'Pay securely using TwittPay.',
                    'description' => 'This controls the description which the user sees during checkout.'
                ),
                'api_key' => array(
                    'title' => 'Brand Key',
                    'type' => 'text',
                    'description' => 'Enter your TwittPay Brand Key | <a href="https://twittpay.com/user/brands" target="_blank">Get your brand key</a>'
                ),
                // --- MISSING FIELD, ADDED IN 1.1.0 ---
                'brand_key' => array(
                    'title' => 'Brand Key (optional)',
                    'type' => 'text',
                    'description' => 'Sent along with the order in the payment metadata. Leave it empty if you were not given one - the Brand Key above is what authorises the request.'
                ),
                // --- USD CONVERSION FIELD ---
                'usd_to_bdt_rate' => array(
                    'title' => 'USD to BDT Conversion Rate',
                    'type' => 'text',
                    'default' => '110', // Default value set to 110
                    'description' => 'Enter the exchange rate (e.g. <b>110</b> if 1 USD = 110 BDT). The final payment amount sent to TwittPay is calculated as <b>Total Amount &times; Conversion Rate</b>.',
                    'custom_attributes' => array(
                        'placeholder' => '110'
                    )
                ),
                // --- DESIGN/INSTRUCTION ADDITION ---
                'instruction_section' => array(
                    'title' => 'Important Instructions',
                    'type' => 'title',
                    'description' => '<div style="background:#fff7de; border:1px solid #ffcc66; padding:10px; margin-top:15px; border-radius:5px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                        <p style="margin:0; font-size:14px; color:#5c5c5c;">
                            <span class="dashicons dashicons-lightbulb" style="color:#d54e21; margin-right:5px; vertical-align:middle;"></span>
                            <strong>Currency &amp; Rate:</strong> TwittPay only accepts BDT. If your WooCommerce currency is USD, the system uses the <b>USD to BDT Conversion Rate</b> above to work out the final BDT amount.
                        </p>
                        <p style="margin:5px 0 0 0; font-size:14px; color:#5c5c5c;">
                            <span class="dashicons dashicons-cloud" style="color:#007cba; margin-right:5px; vertical-align:middle;"></span>
                            <strong>Pending payments:</strong> this plugin gives TwittPay a webhook address, so a payment the merchant approves later still completes the order by itself. Nothing to configure - just make sure this site is reachable from the internet.
                        </p>
                    </div>'
                ),
                // ---------------------------------
                'payment_url' => array(
                    'title' => 'Payment API URL (readonly)',
                    'type' => 'text',
                    'default' => $this->payment_url,
                    'custom_attributes' => array('readonly' => 'readonly')
                ),
                'order_status' => array(
                    'title' => 'Order Status After Payment (Physical products)',
                    'type' => 'select',
                    'options' => array(
                        'on-hold' => 'On Hold',
                        'processing' => 'Processing',
                        'completed' => 'Completed'
                    ),
                    'default' => 'processing',
                    'description' => 'Status for physical/shippable products upon successful payment.'
                ),
                'digital_order_status' => array(
                    'title' => 'Order Status After Payment (Digital products)',
                    'type' => 'select',
                    'options' => array(
                        'processing' => 'Processing',
                        'completed' => 'Completed'
                    ),
                    'default' => 'completed',
                    'description' => 'Status for virtual/downloadable products upon successful payment. <b>"Completed" is required for automatic download access.</b>'
                ),
                'logging' => array(
                    'title' => 'Logging',
                    'type' => 'checkbox',
                    'label' => 'Enable debug logging',
                    'default' => 'no',
                    'description' => 'Log TwittPay API requests and responses inside <b>WooCommerce &gt; Status &gt; Logs</b>.'
                )
            );
        }

        /**
         * Process the payment and return redirect to gateway
         */
        public function process_payment($order_id){
            $order = wc_get_order($order_id);

            // Build success and cancel URLs (WooCommerce API endpoints)
            $success_url = home_url('/?wc-api=twittpay_success');
            $cancel_url = home_url('/?wc-api=twittpay_cancel');

            // Server to server address. No order_id on it on purpose - the
            // webhook is answered by the gateway, not by the customer, and the
            // order id is read back out of the metadata instead.
            $webhook_url = home_url('/?wc-api=twittpay_webhook');

            // --- CONVERSION LOGIC IMPLEMENTATION ---
            $amount = $order->get_total();
            $invoice_amount = $order->get_total();

            // Check if WooCommerce base currency is USD, then apply conversion
            if (get_woocommerce_currency() === 'USD') {
                $rate = floatval($this->usd_to_bdt_rate);
                if ($rate > 0) {
                    $original_amount = $amount;
                    $amount = $amount * $rate;
                    $this->log(sprintf('Converting USD %.2f to BDT %.2f using rate %.2f.', $original_amount, $amount, $rate));
                    // Add note to order for clarity
                    $order->add_order_note(sprintf('Amount converted from USD %.2f to BDT %.2f using rate %.2f.', $original_amount, $amount, $rate));
                }
            }
            // --- END CONVERSION LOGIC ---

            // Amount formatting (used for BDT amount after potential conversion)
            if (intval($amount) == $amount) {
                $amount = number_format($amount, 0, '.', '');
            } else {
                $amount = rtrim(rtrim(number_format($amount, 3, '.', ''), '0'), '.');
            }

            // Everything the webhook will need later goes in here, because a
            // webhook carries no query string of ours.
            $metadata = array(
                'order_id' => $order_id,
                'order_key' => $order->get_order_key(),
                'invoice_amount' => (string) $invoice_amount,
                'invoice_currency' => get_woocommerce_currency(),
                'source' => 'woocommerce',
                'brand_key' => $this->brand_key
            );

            $payload = array(
                'cus_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'cus_email' => $order->get_billing_email(),
                'success_url' => add_query_arg('order_id', $order_id, $success_url), // Pass order_id to success URL
                'cancel_url' => $cancel_url,
                'webhook_url' => $webhook_url,
                'amount' => (string) $amount,
                'metadata' => $metadata,
            );

            $args = array(
                'method' => 'POST',
                'timeout' => 30,
                'headers' => array(
                    'API-KEY' => $this->api_key,
                    'Content-Type' => 'application/json'
                ),
                'body' => wp_json_encode($payload)
            );

            $this->log('Payment Request for Order #' . $order_id . ': ' . wp_json_encode($payload));

            $response = wp_remote_post($this->payment_url, $args);

            if (is_wp_error($response)) {
                $this->log('Payment API Error for Order #' . $order_id . ': ' . $response->get_error_message(), 'error');
                wc_add_notice('Payment error: API not reachable. ' . $response->get_error_message(), 'error');
                return array('result' => 'failure');
            }

            $body = wp_remote_retrieve_body($response);
            $result = json_decode($body);

            $this->log('Payment Response for Order #' . $order_id . ': ' . $body);

            if (isset($result->payment_url) && !empty($result->payment_url)) {
                // Mark order as pending or on-hold until payment is verified
                $order->update_status('on-hold', 'Redirecting to TwittPay for payment.');
                return array(
                    'result' => 'success',
                    'redirect' => esc_url_raw($result->payment_url)
                );
            }

            $message = isset($result->message) ? $result->message : 'Unknown error from payment gateway';
            wc_add_notice('Payment failed: ' . $message, 'error');
            $order->add_order_note('Payment failed during API request. Message: ' . $message);
            return array('result' => 'failure');
        }

        /**
         * Ask the gateway what really happened to a transaction.
         *
         * Returns the decoded object, or null if the call could not be made or
         * came back unreadable. Never trust the caller's own claim of success -
         * both the browser return and the webhook go through here.
         */
        private function verify_transaction($transaction_id) {
            $args = array(
                'method' => 'POST',
                'timeout' => 30,
                'headers' => array(
                    'API-KEY' => $this->api_key,
                    'Content-Type' => 'application/json'
                ),
                'body' => wp_json_encode(array('transaction_id' => $transaction_id))
            );

            $this->log('Verification Request for Transaction ID: ' . $transaction_id);

            $response = wp_remote_post($this->verify_url, $args);

            if (is_wp_error($response)) {
                $this->log('Verification API Error for Transaction ID: ' . $transaction_id . ': ' . $response->get_error_message(), 'error');
                return null;
            }

            $body = wp_remote_retrieve_body($response);
            $this->log('Verification Response for Transaction ID: ' . $transaction_id . ': ' . $body);

            $data = json_decode($body);

            if (!is_object($data)) {
                $this->log('Verification response could not be read for Transaction ID: ' . $transaction_id, 'error');
                return null;
            }

            return $data;
        }

        /**
         * The verify endpoint returns metadata as a JSON string, not as an
         * object. The old code tested is_object() on it, which never matched, so
         * nothing in the metadata was ever used. Decode it properly here.
         */
        private function read_metadata($data) {
            if (!isset($data->metadata)) {
                return array();
            }

            $meta = $data->metadata;

            if (is_string($meta)) {
                if ($meta === '') {
                    return array();
                }
                $decoded = json_decode($meta, true);
                return is_array($decoded) ? $decoded : array();
            }

            if (is_object($meta)) {
                return (array) $meta;
            }

            if (is_array($meta)) {
                return $meta;
            }

            return array();
        }

        /**
         * Work out which order a verify response belongs to. The metadata is the
         * reliable source; the query parameter is only there for the browser.
         */
        private function resolve_order_id($data, $fallback = 0) {
            $meta = $this->read_metadata($data);

            if (!empty($meta['order_id'])) {
                return intval($meta['order_id']);
            }

            return intval($fallback);
        }

        /**
         * Mark an order paid. Used by both the browser return and the webhook,
         * so it has to be safe to call twice - and it will be called twice, any
         * time the customer comes back at about the same moment the webhook
         * arrives.
         *
         * Returns true if this call is the one that completed the order.
         */
        private function complete_order($order, $transaction_id, $data) {
            if ($order->get_meta('_twittpay_payment_completed', true) === 'yes') {
                $this->log('Order #' . $order->get_id() . ' was already completed by TwittPay. Ignoring this one.');
                return false;
            }

            // --- Store Payment Details (Transaction ID, etc.) ---
            $payment_details = array();
            if (isset($data->payment_method)) {
                $payment_details['Payment Method'] = $data->payment_method;
            } elseif (isset($data->method)) {
                $payment_details['Payment Method'] = $data->method;
            }
            if (isset($data->amount) && isset($data->currency)) {
                $payment_details['Amount Paid'] = $data->amount . ' ' . $data->currency;
            } elseif (isset($data->amount)) {
                $payment_details['Amount Paid'] = $data->amount . ' BDT';
            }

            // Store details in order metadata
            $order->update_meta_data('_twittpay_transaction_id', $transaction_id);
            $order->update_meta_data('_twittpay_payment_details', $payment_details);
            $order->update_meta_data('_twittpay_payment_completed', 'yes');
            $order->save();

            // Determine if order contains only virtual/downloadable products
            $is_digital = true;
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                if ($product && !$product->is_virtual() && !$product->is_downloadable()) {
                    $is_digital = false;
                    break;
                }
            }

            $target_status = $is_digital ? $this->digital_order_status : $this->order_status;

            // Complete or change status accordingly
            if ($target_status === 'completed') {
                // This function sets status to completed, reduces stock, and grants download access.
                $order->payment_complete($transaction_id);
                $order->add_order_note('Payment completed via TwittPay. Transaction ID: ' . $transaction_id);
            } else {
                // Change status, but manually reduce stock if not completed
                $order->update_status($target_status, 'Payment verified via TwittPay. Transaction ID: ' . $transaction_id);
                $order->reduce_order_stock();
            }

            $this->log('Order #' . $order->get_id() . ' status updated to ' . $target_status . '.', 'info');

            return true;
        }

        /**
         * Success return handler - the customer's browser coming back.
         */
        public function payment_success_return(){
            // Check for transaction ID first
            $transaction_id = isset($_GET['transactionId']) ? sanitize_text_field($_GET['transactionId']) : null;
            $order_id_query = isset($_GET['order_id']) ? intval(sanitize_text_field($_GET['order_id'])) : 0;

            $this->log('Success Return Handler called. Transaction ID: ' . $transaction_id . ', Order ID (query): ' . $order_id_query);

            if (!$transaction_id) {
                wp_safe_redirect(home_url());
                exit;
            }

            $data = $this->verify_transaction($transaction_id);

            if ($data === null) {
                $order = $order_id_query ? wc_get_order($order_id_query) : null;
                if ($order) {
                    $order->add_order_note('TwittPay verification failed: API error. Please verify manually. Transaction ID: ' . $transaction_id);
                    wp_safe_redirect($order->get_checkout_payment_url());
                } else {
                    wp_safe_redirect(home_url());
                }
                exit;
            }

            $order_id = $this->resolve_order_id($data, $order_id_query);

            if (!$order_id) {
                $this->log('Could not determine Order ID from metadata or query params.', 'error');
                wp_safe_redirect(home_url());
                exit;
            }

            $order = wc_get_order($order_id);
            if (!$order) {
                $this->log('Order #' . $order_id . ' not found in WooCommerce.', 'error');
                wp_safe_redirect(home_url());
                exit;
            }

            $status = isset($data->status) ? strtoupper($data->status) : '';

            if ($status === 'COMPLETED') {
                $this->complete_order($order, $transaction_id, $data);
                wp_safe_redirect($order->get_checkout_order_received_url());
                exit;
            }

            if ($status === 'PENDING') {
                // The customer has submitted the payment and the merchant has
                // not approved it yet. This is not a failure and must not be
                // shown as one - the order simply stays on hold until the
                // webhook arrives with the decision.
                $order->add_order_note('TwittPay payment is pending the merchant\'s approval. Transaction ID: ' . $transaction_id);
                $order->update_meta_data('_twittpay_transaction_id', $transaction_id);
                $order->save();

                wc_add_notice('Thank you. Your payment has been received and is waiting to be checked. Your order will be confirmed as soon as it is approved - you do not need to pay again.', 'notice');

                $this->log('Order #' . $order_id . ' is pending approval at the gateway.');

                wp_safe_redirect($order->get_checkout_order_received_url());
                exit;
            }

            // Payment failed
            $status_message = ($status !== '') ? esc_html($status) : 'Verification Failed';
            $order->add_order_note('TwittPay Payment Status: ' . $status_message . '. Transaction ID: ' . $transaction_id);
            wc_add_notice('Payment verification failed. Current status: ' . $status_message, 'error');

            $this->log('Order #' . $order_id . ' verification failed. Status: ' . $status_message, 'warning');

            // Redirect to payment page to allow re-attempt or other payment method selection
            wp_safe_redirect($order->get_checkout_payment_url());
            exit;
        }

        /**
         * Webhook handler - the gateway calling this site directly.
         *
         * This is what makes a pending payment work. When the merchant approves
         * it, hours later and with no customer anywhere near a browser, the
         * gateway posts here and the order finally completes.
         *
         * The gateway posts the transaction id form-encoded, so it lands in
         * $_POST. A JSON body is accepted too. Nothing in the posted body is
         * trusted: the status always comes from the verify endpoint.
         *
         * Answers in plain text and never redirects.
         */
        public function payment_webhook(){
            $transaction_id = '';

            foreach (array('transactionId', 'transaction_id') as $key) {
                if (!empty($_REQUEST[$key])) {
                    $transaction_id = sanitize_text_field(wp_unslash($_REQUEST[$key]));
                    break;
                }
            }

            if ($transaction_id === '') {
                $raw = file_get_contents('php://input');
                if (!empty($raw)) {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        foreach (array('transactionId', 'transaction_id') as $key) {
                            if (!empty($decoded[$key])) {
                                $transaction_id = sanitize_text_field($decoded[$key]);
                                break;
                            }
                        }
                    }
                }
            }

            if ($transaction_id === '') {
                $this->log('Webhook called with no transaction id.', 'error');
                status_header(400);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Missing transaction id.';
                exit;
            }

            $this->log('Webhook called for Transaction ID: ' . $transaction_id);

            $data = $this->verify_transaction($transaction_id);

            if ($data === null) {
                // 500 on purpose, so it shows up as a failure at the gateway end
                // rather than looking like it was handled.
                status_header(500);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Could not verify this transaction.';
                exit;
            }

            $order_id = $this->resolve_order_id($data, 0);

            header('Content-Type: text/plain; charset=utf-8');

            if (!$order_id) {
                $this->log('Webhook for Transaction ID ' . $transaction_id . ' carried no order id in its metadata.', 'error');
                status_header(200);
                echo 'No order id in the metadata.';
                exit;
            }

            $order = wc_get_order($order_id);

            if (!$order) {
                $this->log('Webhook for Transaction ID ' . $transaction_id . ' points at Order #' . $order_id . ', which does not exist here.', 'error');
                status_header(200);
                echo 'Order not found.';
                exit;
            }

            $status = isset($data->status) ? strtoupper($data->status) : '';

            if ($status === 'COMPLETED') {
                $done = $this->complete_order($order, $transaction_id, $data);
                status_header(200);
                echo $done
                    ? ('OK - order ' . $order_id . ' completed.')
                    : ('OK - order ' . $order_id . ' was already completed.');
                exit;
            }

            if ($status === 'PENDING') {
                $order->add_order_note('TwittPay reports this payment as pending the merchant\'s approval. Transaction ID: ' . $transaction_id);
                $order->update_meta_data('_twittpay_transaction_id', $transaction_id);
                $order->save();

                status_header(200);
                echo 'OK - noted as pending.';
                exit;
            }

            // Anything else means the payment did not go through. Do not cancel
            // the order outright - the merchant may still be looking at it, and
            // a cancelled order cannot be paid again from the customer's side.
            $order->add_order_note('TwittPay reports this payment as ' . ($status !== '' ? $status : 'not completed') . '. Transaction ID: ' . $transaction_id);
            $order->save();

            $this->log('Webhook for Order #' . $order_id . ' reported status ' . $status, 'warning');

            status_header(200);
            echo 'OK - noted as not completed.';
            exit;
        }

        public function payment_cancel_return(){
            wc_add_notice('Your payment was cancelled by the user.', 'error');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        /**
         * Display payment details on the Admin Order Edit screen.
         * @param WC_Order $order
         */
        public function display_admin_order_details($order) {
            $transaction_id = $order->get_meta('_twittpay_transaction_id', true);
            $payment_details = $order->get_meta('_twittpay_payment_details', true);

            if ($this->id !== $order->get_payment_method() || empty($transaction_id)) {
                return;
            }

            echo '<div class="twittpay_payment_details">';
            echo '<h3>' . $this->method_title . ' Details</h3>';
            echo '<p><strong>Transaction ID:</strong> ' . esc_html($transaction_id) . '</p>';
            if (is_array($payment_details)) {
                foreach ($payment_details as $key => $value) {
                    echo '<p><strong>' . esc_html($key) . ':</strong> ' . esc_html($value) . '</p>';
                }
            }
            if ($order->get_meta('_twittpay_payment_completed', true) !== 'yes') {
                echo '<p><strong>Note:</strong> this payment has not been confirmed by the gateway yet.</p>';
            }
            echo '</div>';
        }

        /**
         * Display payment details on the Customer Order View (My Account -> Orders).
         * @param WC_Order $order
         */
        public function display_customer_order_details($order) {
            $transaction_id = $order->get_meta('_twittpay_transaction_id', true);
            $payment_details = $order->get_meta('_twittpay_payment_details', true);

            if ($this->id !== $order->get_payment_method() || empty($transaction_id)) {
                return;
            }

            echo '<div class="twittpay_customer_details">';
            echo '<h2>Payment Details</h2>';
            echo '<p><strong>Transaction ID:</strong> ' . esc_html($transaction_id) . '</p>';
            if (is_array($payment_details) && isset($payment_details['Amount Paid'])) {
                echo '<p><strong>Paid Amount:</strong> ' . esc_html($payment_details['Amount Paid']) . '</p>';
            }
            if ($order->get_meta('_twittpay_payment_completed', true) !== 'yes') {
                echo '<p>This payment is still being checked. Your order will be confirmed once it is approved.</p>';
            }
            echo '</div>';
        }
    }

    // Register the gateway
    add_filter('woocommerce_payment_gateways', function($gateways){
        $gateways[] = 'WC_TWITTPAY_GATEWAY';
        return $gateways;
    });
}
