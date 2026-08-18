<?php
/**
 * Order management for B2B workflow.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WC_B2B_Order Class.
 */
class WC_B2B_Order {
    
    /**
     * Constructor.
     */
    public function __construct() {
        add_action('init', array($this, 'register_order_statuses'));
        add_filter('wc_order_statuses', array($this, 'add_order_statuses'));
        add_filter('woocommerce_order_is_paid', array($this, 'preserve_paid_state_after_shipping'), 10, 2);
        add_filter('woocommerce_valid_order_statuses_for_payment_complete', array($this, 'add_payment_complete_statuses'), 10, 2);
        add_action('woocommerce_order_status_changed', array($this, 'handle_status_change'), 10, 4);
        
        // Add verification actions
        add_action('wp_ajax_wc_b2b_verify_order', array($this, 'ajax_verify_order'));
        add_action('wp_ajax_nopriv_wc_b2b_verify_order', array($this, 'ajax_verify_order'));
        
        // Handle verification link
        add_action('template_redirect', array($this, 'handle_verification_link'));
        
        // Customer order actions
        add_action('wp_ajax_wc_b2b_confirm_order', array($this, 'ajax_confirm_order'));
        add_action('wp_ajax_nopriv_wc_b2b_confirm_order', array($this, 'ajax_confirm_order'));
        add_action('wp_ajax_wc_b2b_cancel_order', array($this, 'ajax_cancel_order'));
        add_action('wp_ajax_nopriv_wc_b2b_cancel_order', array($this, 'ajax_cancel_order'));
    }
    
    /**
     * Register custom order statuses.
     */
    public function register_order_statuses() {
        register_post_status('wc-b2b-verifying', array(
            'label' => _x('Pending Verification', 'Order status', 'wc-to-b2b'),
            'public' => true,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop('Pending Verification <span class="count">(%s)</span>', 'Pending Verification <span class="count">(%s)</span>', 'wc-to-b2b')
        ));
        
        register_post_status('wc-verified', array(
            'label' => _x('Verified', 'Order status', 'wc-to-b2b'),
            'public' => true,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop('Verified <span class="count">(%s)</span>', 'Verified <span class="count">(%s)</span>', 'wc-to-b2b')
        ));
        
        register_post_status('wc-quote-sent', array(
            'label' => _x('Quote Sent', 'Order status', 'wc-to-b2b'),
            'public' => true,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop('Quote Sent <span class="count">(%s)</span>', 'Quote Sent <span class="count">(%s)</span>', 'wc-to-b2b')
        ));

        // Compatibility for early releases whose overlong status was truncated by the database.
        register_post_status('wc-pending-verificat', array(
            'label' => _x('Pending Verification (Legacy)', 'Order status', 'wc-to-b2b'),
            'public' => true,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop('Pending Verification (Legacy) <span class="count">(%s)</span>', 'Pending Verification (Legacy) <span class="count">(%s)</span>', 'wc-to-b2b')
        ));

        register_post_status('wc-quote-accepted', array(
            'label' => _x('Quote Accepted / Awaiting Payment', 'Order status', 'wc-to-b2b'),
            'public' => true,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop('Quote Accepted <span class="count">(%s)</span>', 'Quote Accepted <span class="count">(%s)</span>', 'wc-to-b2b')
        ));

        register_post_status('wc-partially-shipped', array(
            'label' => _x('Partially Shipped', 'Order status', 'wc-to-b2b'),
            'public' => true,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop('Partially Shipped <span class="count">(%s)</span>', 'Partially Shipped <span class="count">(%s)</span>', 'wc-to-b2b')
        ));

        register_post_status('wc-shipped', array(
            'label' => _x('Shipped', 'Order status', 'wc-to-b2b'),
            'public' => true,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop('Shipped <span class="count">(%s)</span>', 'Shipped <span class="count">(%s)</span>', 'wc-to-b2b')
        ));
    }
    
    /**
     * Add custom order statuses to WooCommerce.
     */
    public function add_order_statuses($order_statuses) {
        $new_order_statuses = array();
        
        // Add after pending
        foreach ($order_statuses as $key => $status) {
            $new_order_statuses[$key] = $status;
            
            if ('wc-pending' === $key) {
                $new_order_statuses['wc-b2b-verifying'] = _x('Pending Verification', 'Order status', 'wc-to-b2b');
                $new_order_statuses['wc-pending-verificat'] = _x('Pending Verification (Legacy)', 'Order status', 'wc-to-b2b');
                $new_order_statuses['wc-verified'] = _x('Verified', 'Order status', 'wc-to-b2b');
                $new_order_statuses['wc-quote-sent'] = _x('Quote Sent', 'Order status', 'wc-to-b2b');
                $new_order_statuses['wc-quote-accepted'] = _x('Quote Accepted / Awaiting Payment', 'Order status', 'wc-to-b2b');
                $new_order_statuses['wc-partially-shipped'] = _x('Partially Shipped', 'Order status', 'wc-to-b2b');
                $new_order_statuses['wc-shipped'] = _x('Shipped', 'Order status', 'wc-to-b2b');
            }
        }
        
        return $new_order_statuses;
    }

    public function preserve_paid_state_after_shipping($is_paid, $order) {
        if ($is_paid || !$order instanceof WC_Order || $order->get_meta('_is_b2b_order', true) !== 'yes') {
            return $is_paid;
        }
        if (!in_array($order->get_status(), array('partially-shipped', 'shipped'), true)) {
            return false;
        }
        if ($order->get_date_paid()) {
            return true;
        }
        return class_exists('WC_B2B_Fulfillment') && WC_B2B_Fulfillment::get_paid_total($order) + 0.00001 >= (float) $order->get_total();
    }

    public function add_payment_complete_statuses($statuses, $order) {
        if ($order instanceof WC_Order && $order->get_meta('_is_b2b_order', true) === 'yes') {
            $statuses[] = 'quote-sent';
            $statuses[] = 'quote-accepted';
        }
        return array_unique($statuses);
    }
    
    /**
     * Handle order status changes.
     */
    public function handle_status_change($order_id, $old_status, $new_status, $order) {
        switch ($new_status) {
            case 'verified':
                // Send notification to admin (always email for admin)
                do_action('wc_b2b_send_admin_notification', $order_id);
                if (get_option('wc_b2b_auto_quote', 'yes') === 'yes') {
                    WC_B2B_Quote::prepare_quote($order);
                    $order->update_status('quote-sent', __('Automatic quotation generated after customer verification.', 'wc-to-b2b'));
                }
                break;
                
            case 'quote-sent':
                // Email is the durable record; WhatsApp is an optional additional channel.
                WC_B2B_Quote::prepare_quote($order);
                $verified_via = $order->get_meta('_verified_via', true);
                do_action('wc_b2b_send_quote_email', $order_id);
                if ($verified_via === 'whatsapp') {
                    do_action('wc_b2b_send_whatsapp_quote', $order_id);
                }
                break;
        }
    }
    
    /**
     * Handle verification link from email.
     */
    public function handle_verification_link() {
        if (!isset($_GET['wc_b2b_verify']) || !isset($_GET['token'])) {
            return;
        }
        
        $token = sanitize_text_field($_GET['token']);
        $result = $this->verify_order_by_token($token);
        
        if ($result['success']) {
            wc_add_notice(__('Order verified successfully!', 'wc-to-b2b'), 'success');
            $order = wc_get_order($result['order_id']);
            wp_safe_redirect($order ? WC_B2B_Quote::get_action_url($order, 'view') : wc_get_page_permalink('myaccount'));
        } else {
            wc_add_notice($result['message'], 'error');
            wp_redirect(home_url());
        }
        exit;
    }
    
    /**
     * Verify order by token.
     */
    public function verify_order_by_token($token) {
        global $wpdb;
        
        if (empty($token) || strlen($token) !== 32) {
            return array(
                'success' => false,
                'message' => __('Invalid verification token format.', 'wc-to-b2b')
            );
        }
        
        $table_name = $wpdb->prefix . 'wc_b2b_verification_tokens';
        
        $token_data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE token = %s AND used_at IS NULL AND expires_at > NOW() LIMIT 1",
            $token
        ));
        
        if (!$token_data) {
            return array(
                'success' => false,
                'message' => __('Invalid or expired verification token.', 'wc-to-b2b')
            );
        }
        
        // Mark token as used
        $update_result = $wpdb->update(
            $table_name,
            array('used_at' => current_time('mysql')),
            array('id' => $token_data->id),
            array('%s'),
            array('%d')
        );
        
        if ($update_result === false) {
            error_log('WC B2B: Failed to mark token as used for order ' . $token_data->order_id);
        }
        
        // Update verification status based on token type
        if ($token_data->type === 'whatsapp') {
            $order = wc_get_order($token_data->order_id);
            if ($order) {
                $order->update_meta_data('_whatsapp_verified', 'yes');
                $order->update_meta_data('_whatsapp_verified_at', current_time('mysql'));
                $order->save();
            }
        } else {
            $order = wc_get_order($token_data->order_id);
            if ($order) {
                $order->update_meta_data('_email_verified', 'yes');
                $order->update_meta_data('_email_verified_at', current_time('mysql'));
                $order->save();
            }
        }

        // Check if order should be marked as verified (either method works)
        $order = wc_get_order($token_data->order_id);
        $email_verified = $order && $order->get_meta('_email_verified', true) === 'yes';
        $whatsapp_verified = $order && $order->get_meta('_whatsapp_verified', true) === 'yes';

        if ($order && ($email_verified || $whatsapp_verified)) {
            // Only update status if not already verified
            if (in_array($order->get_status(), array('b2b-verifying', 'pending-verificat'), true)) {
                $verification_method = $token_data->type === 'whatsapp' ? 'WhatsApp' : 'Email';
                // Store which method was used for verification
                $order->update_meta_data('_verified_via', $token_data->type);
                $order->save();
                $order->update_status('verified', sprintf(__('Order verified by customer via %s.', 'wc-to-b2b'), $verification_method));
            }
        }
        
        return array(
            'success' => true,
            'order_id' => $token_data->order_id,
            'verification_method' => $token_data->type
        );
    }
    
    /**
     * AJAX verify order.
     */
    public function ajax_verify_order() {
        check_ajax_referer('wc_b2b_verify', 'nonce');
        
        $token = sanitize_text_field($_POST['token']);
        $result = $this->verify_order_by_token($token);
        
        wp_send_json($result);
    }
    
    /**
     * AJAX confirm order (customer accepts quote).
     */
    public function ajax_confirm_order() {
        check_ajax_referer('wc_b2b_confirm', 'nonce');
        
        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error(__('Order not found.', 'wc-to-b2b'));
        }
        
        // Verify customer can confirm this order
        if (!$this->can_customer_access_order($order)) {
            wp_send_json_error(__('Access denied.', 'wc-to-b2b'));
        }
        
        if ($order->get_status() !== 'quote-sent') {
            wp_send_json_error(__('This quote cannot be accepted in its current status.', 'wc-to-b2b'));
        }
        if (!WC_B2B_Quote::is_quote_valid($order)) {
            wp_send_json_error(__('This quote has expired. Please contact us for a new quote.', 'wc-to-b2b'));
        }
        $order->update_status('quote-accepted', __('Customer accepted the quote and will pay offline.', 'wc-to-b2b'));

        wp_send_json_success(array(
            'message' => __('Quote accepted. Please use the offline payment information on the quote.', 'wc-to-b2b'),
            'redirect_url' => $order->get_view_order_url()
        ));
    }
    
    /**
     * AJAX cancel order.
     */
    public function ajax_cancel_order() {
        check_ajax_referer('wc_b2b_cancel', 'nonce');
        
        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error(__('Order not found.', 'wc-to-b2b'));
        }
        
        // Verify customer can cancel this order
        if (!$this->can_customer_access_order($order)) {
            wp_send_json_error(__('Access denied.', 'wc-to-b2b'));
        }

        if (!in_array($order->get_status(), array('quote-sent', 'quote-accepted', 'b2b-verifying', 'pending-verificat', 'verified'), true)) {
            wp_send_json_error(__('This order cannot be cancelled in its current status.', 'wc-to-b2b'));
        }
        if (class_exists('WC_B2B_Fulfillment') && WC_B2B_Fulfillment::get_paid_total($order) > 0) {
            wp_send_json_error(__('A payment has already been recorded. Please contact us to cancel this order.', 'wc-to-b2b'));
        }

        // Update order status to cancelled
        $order->update_status('cancelled', __('Order cancelled by customer.', 'wc-to-b2b'));
        
        wp_send_json_success(array(
            'message' => __('Order cancelled successfully.', 'wc-to-b2b')
        ));
    }
    
    /**
     * Check if customer can access order.
     */
    private function can_customer_access_order($order) {
        if (current_user_can('manage_woocommerce')) {
            return true;
        }
        return is_user_logged_in() && $order->get_customer_id() && get_current_user_id() === (int) $order->get_customer_id();
    }
}
