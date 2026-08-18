<?php
/**
 * Order expiry and payment reminder management.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WC_B2B_Order_Manager Class.
 */
class WC_B2B_Order_Manager {
    
    /**
     * Constructor.
     */
    public function __construct() {
        // Schedule cron jobs
        add_action('init', array($this, 'schedule_cron_jobs'));
        
        // Cron job handlers
        add_action('wc_b2b_check_expired_orders', array($this, 'check_expired_orders'));
        add_action('wc_b2b_send_payment_reminders', array($this, 'send_payment_reminders'));
        
        // Admin actions
        add_action('woocommerce_order_actions', array($this, 'add_manual_payment_action'));
        add_action('woocommerce_order_action_wc_b2b_mark_paid_manually', array($this, 'mark_order_paid_manually'));
        add_action('woocommerce_order_action_wc_b2b_cancel_order_manually', array($this, 'cancel_order_manually'));
        
        // AJAX handlers
        add_action('wp_ajax_wc_b2b_mark_paid', array($this, 'ajax_mark_paid'));
        add_action('wp_ajax_wc_b2b_send_payment_reminder', array($this, 'ajax_send_payment_reminder'));
        
        // Add order meta boxes
        add_action('add_meta_boxes', array($this, 'add_order_expiry_meta_box'));
        
        // Hook into order status changes
        add_action('woocommerce_order_status_changed', array($this, 'handle_order_status_change'), 10, 4);
    }
    
    /**
     * Schedule cron jobs.
     */
    public function schedule_cron_jobs() {
        if (!wp_next_scheduled('wc_b2b_check_expired_orders')) {
            wp_schedule_event(time(), 'daily', 'wc_b2b_check_expired_orders');
        }
        
        if (!wp_next_scheduled('wc_b2b_send_payment_reminders')) {
            wp_schedule_event(time(), 'daily', 'wc_b2b_send_payment_reminders');
        }
    }
    
    /**
     * Check for expired orders and cancel them.
     */
    public function check_expired_orders() {
        $expiry_days = get_option('wc_b2b_order_expiry', 21);
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$expiry_days} days"));
        
        $args = array(
            'status' => array('quote-sent', 'quote-accepted', 'processing', 'partially-shipped', 'shipped'),
            'meta_query' => array(
                array(
                    'key' => '_is_b2b_order',
                    'value' => 'yes'
                )
            ),
            'date_created' => '<' . $cutoff_date,
            'limit' => -1
        );
        
        $orders = wc_get_orders($args);
        
        foreach ($orders as $order) {
            // Check if order is still unpaid
            if (!$order->is_paid() && !$this->is_manually_paid($order->get_id())) {
                $order->update_status('cancelled', __('Order automatically cancelled due to expiry (3 weeks without payment).', 'wc-to-b2b'));
                
                // Send cancellation notification via the same method used for verification
                $verified_via = $order->get_meta('_verified_via', true);
                
                if ($verified_via === 'whatsapp') {
                    $this->send_whatsapp_cancellation_notice($order->get_id());
                }
                
                // Log the action
                $order->add_order_note(__('Order automatically cancelled after 3 weeks without payment.', 'wc-to-b2b'));
            }
        }
    }
    
    /**
     * Send payment reminders for unpaid orders.
     */
    public function send_payment_reminders() {
        $reminder_interval = get_option('wc_b2b_payment_reminder_interval', 7);
        
        $args = array(
            'status' => array('quote-sent', 'quote-accepted', 'processing', 'partially-shipped', 'shipped'),
            'meta_query' => array(
                array(
                    'key' => '_is_b2b_order',
                    'value' => 'yes'
                )
            ),
            'limit' => -1
        );
        
        $orders = wc_get_orders($args);
        
        foreach ($orders as $order) {
            if (!$order->is_paid() && !$this->is_manually_paid($order->get_id())) {
                $last_reminder = $order->get_meta('_last_payment_reminder', true);
                $order_date = $order->get_date_created()->getTimestamp();
                $now = time();
                
                // Calculate when to send reminder
                if (empty($last_reminder)) {
                    // First reminder after 1 week
                    $send_reminder_after = $order_date + (7 * 24 * 60 * 60);
                } else {
                    // Subsequent reminders every week
                    $send_reminder_after = strtotime($last_reminder) + ($reminder_interval * 24 * 60 * 60);
                }
                
                if ($now >= $send_reminder_after) {
                    // Check if order is not expired yet
                    $expiry_days = get_option('wc_b2b_order_expiry', 21);
                    $expiry_date = $order_date + ($expiry_days * 24 * 60 * 60);
                    
                    if ($now < $expiry_date) {
                        $this->send_payment_reminder($order->get_id());
                        $order->update_meta_data('_last_payment_reminder', current_time('mysql'));
                        $order->save();
                        $order->add_order_note(__('Payment reminder sent to customer.', 'wc-to-b2b'));
                    }
                }
            }
        }
    }
    
    /**
     * Send payment reminder based on verification method used.
     */
    private function send_payment_reminder($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }
        
        // Check which method was actually used for verification
        $verified_via = $order->get_meta('_verified_via', true);
        
        // Send via the same method that was used for verification
        if ($verified_via === 'whatsapp') {
            return $this->send_whatsapp_payment_reminder($order_id);
        } else {
            // Default to email if no verification method recorded or if verified via email
            return $this->send_email_payment_reminder($order_id);
        }
    }
    
    /**
     * Send payment reminder email.
     */
    private function send_email_payment_reminder($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }
        
        $to = $order->get_billing_email();
        $subject = sprintf(__('Payment Reminder for Order #%s', 'wc-to-b2b'), $order->get_order_number());
        
        $message = $this->get_payment_reminder_template($order);
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        return wp_mail($to, $subject, $message, $headers);
    }
    
    /**
     * Send WhatsApp payment reminder.
     */
    private function send_whatsapp_payment_reminder($order_id) {
        if (get_option('wc_b2b_whatsapp_enabled') !== 'yes') {
            return false;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }
        
        $phone = $this->format_phone_number($order->get_billing_phone());
        if (!$phone) {
            return false;
        }
        
        $expiry_days = get_option('wc_b2b_order_expiry', 21);
        $order_date = $order->get_date_created()->getTimestamp();
        $days_left = ceil(($order_date + ($expiry_days * 24 * 60 * 60) - time()) / (24 * 60 * 60));
        
        $payment_url = $order->get_customer_id() ? $order->get_view_order_url() : WC_B2B_Quote::get_action_url($order, 'view');
        
        $message = sprintf(
            __("Payment Reminder 💰\n\nHello %s!\n\nYour order #%s is still awaiting offline payment.\n\n⚠️ Important: Your order will be cancelled in %d days if payment is not received.\n\nOrder Total: %s\n\nView quote and payment information: %s\n\nIf you have already paid or have questions, please contact us immediately.", 'wc-to-b2b'),
            $order->get_billing_first_name(),
            $order->get_order_number(),
            $days_left,
            $order->get_formatted_order_total(),
            $payment_url
        );
        
        return $this->send_whatsapp_message($phone, $message);
    }
    
    /**
     * Send WhatsApp message via API.
     */
    private function send_whatsapp_message($phone, $message) {
        $api_url = get_option('wc_b2b_whatsapp_api_url');
        $api_key = get_option('wc_b2b_whatsapp_api_key');
        
        if (empty($api_url) || empty($api_key)) {
            return false;
        }
        
        $data = array(
            'phone' => $phone,
            'message' => $message,
            'api_key' => $api_key
        );
        
        $response = wp_remote_post($api_url, array(
            'body' => json_encode($data),
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('WhatsApp API Error: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        return $response_code === 200;
    }
    
    /**
     * Format phone number for WhatsApp.
     */
    private function format_phone_number($phone) {
        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        if (empty($phone)) {
            return false;
        }
        
        // Remove leading zeros
        $phone = ltrim($phone, '0');
        
        return $phone;
    }
    
    /**
     * Send WhatsApp cancellation notice.
     */
    private function send_whatsapp_cancellation_notice($order_id) {
        if (get_option('wc_b2b_whatsapp_enabled') !== 'yes') {
            return false;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }
        
        $phone = $this->format_phone_number($order->get_billing_phone());
        if (!$phone) {
            return false;
        }
        
        $message = sprintf(
            __("Order Cancelled ❌\n\nHello %s,\n\nWe regret to inform you that your order #%s has been cancelled due to non-payment within the specified time period.\n\nOrder Total: %s\n\nIf you are still interested in these products, please feel free to place a new order or contact us directly.\n\nThank you for your understanding.", 'wc-to-b2b'),
            $order->get_billing_first_name(),
            $order->get_order_number(),
            $order->get_formatted_order_total()
        );
        
        return $this->send_whatsapp_message($phone, $message);
    }
    
    /**
     * Get payment reminder email template.
     */
    private function get_payment_reminder_template($order) {
        $expiry_days = get_option('wc_b2b_order_expiry', 21);
        $order_date = $order->get_date_created()->getTimestamp();
        $expiry_date = date('Y-m-d', $order_date + ($expiry_days * 24 * 60 * 60));
        $days_left = ceil(($order_date + ($expiry_days * 24 * 60 * 60) - time()) / (24 * 60 * 60));
        
        $payment_url = $order->get_customer_id() ? $order->get_view_order_url() : WC_B2B_Quote::get_action_url($order, 'view');
        
        ob_start();
        ?>
        <div style="background-color: #f7f7f7; padding: 20px; font-family: Arial, sans-serif;">
            <div style="max-width: 600px; margin: 0 auto; background-color: white; padding: 30px; border-radius: 5px;">
                <h2 style="color: #333; margin-bottom: 20px;"><?php _e('Payment Reminder', 'wc-to-b2b'); ?></h2>
                
                <p><?php printf(__('Hello %s,', 'wc-to-b2b'), $order->get_billing_first_name()); ?></p>
                
                <p><?php printf(__('This is a friendly reminder that your order #%s is still awaiting payment.', 'wc-to-b2b'), $order->get_order_number()); ?></p>
                
                <div style="background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin: 20px 0; border-radius: 3px;">
                    <p style="margin: 0; color: #856404;">
                        <strong><?php _e('Important:', 'wc-to-b2b'); ?></strong> 
                        <?php printf(__('Your order will be automatically cancelled in %d days (on %s) if payment is not received.', 'wc-to-b2b'), $days_left, $expiry_date); ?>
                    </p>
                </div>
                
                <div style="background-color: #f9f9f9; padding: 20px; margin: 20px 0; border-radius: 3px;">
                    <h3><?php _e('Order Summary:', 'wc-to-b2b'); ?></h3>
                    <p><strong><?php _e('Order Number:', 'wc-to-b2b'); ?></strong> #<?php echo $order->get_order_number(); ?></p>
                    <p><strong><?php _e('Order Date:', 'wc-to-b2b'); ?></strong> <?php echo wc_format_datetime($order->get_date_created()); ?></p>
                    <p><strong><?php _e('Total Amount:', 'wc-to-b2b'); ?></strong> <?php echo $order->get_formatted_order_total(); ?></p>
                </div>
                
                <div style="text-align: center; margin: 30px 0;">
                    <a href="<?php echo esc_url($payment_url); ?>" style="background-color: #0073aa; color: white; padding: 12px 30px; text-decoration: none; border-radius: 3px; display: inline-block;"><?php _e('View Quote & Payment Information', 'wc-to-b2b'); ?></a>
                </div>
                
                <p><?php _e('If you have already made the payment or have any questions, please contact us immediately.', 'wc-to-b2b'); ?></p>
                
                <p><?php _e('Thank you for your business!', 'wc-to-b2b'); ?></p>
                
                <hr style="margin: 30px 0; border: none; border-top: 1px solid #eee;">
                <p style="color: #666; font-size: 12px;"><?php printf(__('This is an automated reminder. Order expires on: %s', 'wc-to-b2b'), $expiry_date); ?></p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Add manual payment action to order actions.
     */
    public function add_manual_payment_action($actions) {
        global $theorder;
        
        if (!$theorder || $theorder->get_meta('_is_b2b_order', true) !== 'yes') {
            return $actions;
        }
        
        $status = $theorder->get_status();
        
        if (in_array($status, array('quote-sent', 'quote-accepted', 'processing'), true) && !$theorder->is_paid()) {
            $actions['wc_b2b_mark_paid_manually'] = __('Mark as Paid (Manual Payment)', 'wc-to-b2b');
        }
        
        if (in_array($status, array('quote-sent', 'quote-accepted', 'processing', 'verified'), true)) {
            $actions['wc_b2b_cancel_order_manually'] = __('Cancel Order Manually', 'wc-to-b2b');
        }
        
        return $actions;
    }
    
    /**
     * Mark order as paid manually.
     */
    public function mark_order_paid_manually($order) {
        $outstanding = max(0, (float) $order->get_total() - WC_B2B_Fulfillment::get_paid_total($order));
        if ($outstanding > 0) {
            $payments = WC_B2B_Fulfillment::get_payments($order);
            $payment = array(
                'id' => wp_generate_uuid4(),
                'date' => current_time('Y-m-d'),
                'amount' => $outstanding,
                'method' => __('Manual offline payment', 'wc-to-b2b'),
                'reference' => '',
                'note' => __('Recorded using the legacy “Mark as paid” order action.', 'wc-to-b2b'),
                'created_at' => current_time('mysql'),
                'created_by' => get_current_user_id(),
            );
            $payments[] = $payment;
            $order->update_meta_data('_wc_b2b_payments', $payments);
        }
        $order->update_meta_data('_manually_paid', 'yes');
        $order->update_meta_data('_manually_paid_date', current_time('mysql'));
        $order->update_meta_data('_manually_paid_by', get_current_user_id());
        $order->save();
        if (in_array($order->get_status(), array('quote-sent', 'quote-accepted', 'on-hold', 'pending', 'failed'), true)) {
            do_action('wc_b2b_suppress_next_status_email', $order->get_id());
            $order->payment_complete();
            do_action('wc_b2b_clear_status_email_suppression', $order->get_id());
        } elseif (!$order->get_date_paid()) {
            $order->set_date_paid(time());
            $order->save();
        }
        $order->add_order_note(__('Order marked as paid manually (offline payment received).', 'wc-to-b2b'));
        if (isset($payment)) {
            do_action('wc_b2b_payment_recorded', $order->get_id(), $payment);
        }
    }
    
    /**
     * Cancel order manually.
     */
    public function cancel_order_manually($order) {
        $order->update_status('cancelled', __('Order cancelled manually by admin.', 'wc-to-b2b'));
    }
    
    /**
     * Check if order is manually paid.
     */
    private function is_manually_paid($order_id) {
        $order = wc_get_order($order_id);
        return $order && $order->get_meta('_manually_paid', true) === 'yes';
    }
    
    /**
     * AJAX mark order as paid.
     */
    public function ajax_mark_paid() {
        check_ajax_referer('wc_b2b_admin', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied.', 'wc-to-b2b'));
        }
        
        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);
        
        if (!$order || $order->get_meta('_is_b2b_order', true) !== 'yes') {
            wp_send_json_error(__('Invalid B2B order.', 'wc-to-b2b'));
        }
        
        $this->mark_order_paid_manually($order);
        
        wp_send_json_success(array(
            'message' => __('Order marked as paid successfully!', 'wc-to-b2b')
        ));
    }
    
    /**
     * AJAX send payment reminder.
     */
    public function ajax_send_payment_reminder() {
        check_ajax_referer('wc_b2b_admin', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied.', 'wc-to-b2b'));
        }
        
        $order_id = intval($_POST['order_id']);
        
        if ($this->send_payment_reminder($order_id)) {
            $order = wc_get_order($order_id);
            $order->update_meta_data('_last_payment_reminder', current_time('mysql'));
            $order->save();
            $order->add_order_note(__('Payment reminder sent manually by admin.', 'wc-to-b2b'));
            
            wp_send_json_success(array(
                'message' => __('Payment reminder sent successfully!', 'wc-to-b2b')
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Failed to send payment reminder.', 'wc-to-b2b')
            ));
        }
    }
    
    /**
     * Add order expiry meta box.
     */
    public function add_order_expiry_meta_box() {
        $screens = array('shop_order');
        if (function_exists('wc_get_page_screen_id')) {
            $screens[] = wc_get_page_screen_id('shop-order');
        }
        foreach (array_unique($screens) as $screen) {
            add_meta_box('wc-b2b-order-expiry', __('B2B Order Expiry & Payment', 'wc-to-b2b'), array($this, 'order_expiry_meta_box'), $screen, 'side', 'high');
        }
    }
    
    /**
     * Order expiry meta box content.
     */
    public function order_expiry_meta_box($post) {
        $order = $post instanceof WC_Order ? $post : wc_get_order($post->ID);
        if (!$order || $order->get_meta('_is_b2b_order', true) !== 'yes') {
            echo '<p>' . __('This is not a B2B order.', 'wc-to-b2b') . '</p>';
            return;
        }
        
        $expiry_days = get_option('wc_b2b_order_expiry', 21);
        $order_date = $order->get_date_created()->getTimestamp();
        $expiry_date = $order_date + ($expiry_days * 24 * 60 * 60);
        $days_left = ceil(($expiry_date - time()) / (24 * 60 * 60));
        $is_manually_paid = $this->is_manually_paid($order->get_id());
        $last_reminder = $order->get_meta('_last_payment_reminder', true);
        
        ?>
        <div class="wc-b2b-expiry-info">
            <?php wp_nonce_field('wc_b2b_order_expiry', 'wc_b2b_expiry_nonce'); ?>
            
            <p><strong><?php _e('Order Expiry:', 'wc-to-b2b'); ?></strong></p>
            <p><?php echo date('Y-m-d H:i', $expiry_date); ?></p>
            
            <?php if ($days_left > 0): ?>
                <p style="color: <?php echo $days_left <= 3 ? '#d63638' : '#00a32a'; ?>;">
                    <?php printf(__('%d days remaining', 'wc-to-b2b'), $days_left); ?>
                </p>
            <?php else: ?>
                <p style="color: #d63638;"><?php _e('Order expired', 'wc-to-b2b'); ?></p>
            <?php endif; ?>
            
            <hr>
            
            <p><strong><?php _e('Payment Status:', 'wc-to-b2b'); ?></strong></p>
            <?php if ($order->is_paid()): ?>
                <p style="color: #00a32a;"><?php _e('Paid', 'wc-to-b2b'); ?></p>
            <?php elseif ($is_manually_paid): ?>
                <p style="color: #00a32a;"><?php _e('Manually Paid', 'wc-to-b2b'); ?></p>
                <p><small><?php _e('Marked as paid:', 'wc-to-b2b'); ?> <?php echo esc_html($order->get_meta('_manually_paid_date', true)); ?></small></p>
            <?php else: ?>
                <p style="color: #d63638;"><?php _e('Unpaid', 'wc-to-b2b'); ?></p>
                
                <?php if (in_array($order->get_status(), array('quote-sent', 'processing'))): ?>
                <button type="button" class="button button-primary" id="wc-b2b-mark-paid" data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                    <?php _e('Mark as Paid', 'wc-to-b2b'); ?>
                </button>
                <?php endif; ?>
            <?php endif; ?>
            
            <hr>
            
            <p><strong><?php _e('Payment Reminders:', 'wc-to-b2b'); ?></strong></p>
            <?php if ($last_reminder): ?>
                <p><small><?php _e('Last reminder:', 'wc-to-b2b'); ?> <?php echo date('Y-m-d H:i', strtotime($last_reminder)); ?></small></p>
            <?php else: ?>
                <p><small><?php _e('No reminders sent yet', 'wc-to-b2b'); ?></small></p>
            <?php endif; ?>
            
            <?php if (!$order->is_paid() && !$is_manually_paid && in_array($order->get_status(), array('quote-sent', 'processing'))): ?>
            <button type="button" class="button" id="wc-b2b-send-reminder" data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                <?php _e('Send Payment Reminder', 'wc-to-b2b'); ?>
            </button>
            <?php endif; ?>
            
            <div id="wc-b2b-expiry-messages" style="margin-top: 15px;"></div>
        </div>
        
        <style>
        .wc-b2b-expiry-info button {
            width: 100%;
            margin-bottom: 10px;
        }
        </style>
        <?php
    }
    
    /**
     * Handle order status changes.
     */
    public function handle_order_status_change($order_id, $old_status, $new_status, $order) {
        if ($order->get_meta('_is_b2b_order', true) !== 'yes') {
            return;
        }
        
        // Reset reminder counter when order is paid
        if ($new_status === 'completed' || $order->is_paid()) {
            $order->delete_meta_data('_last_payment_reminder');
            $order->save();
        }
    }
}
