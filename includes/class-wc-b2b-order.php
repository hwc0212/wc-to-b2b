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
        register_post_status('wc-pending-verification', array(
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
                $new_order_statuses['wc-pending-verification'] = _x('Pending Verification', 'Order status', 'wc-to-b2b');
                $new_order_statuses['wc-verified'] = _x('Verified', 'Order status', 'wc-to-b2b');
                $new_order_statuses['wc-quote-sent'] = _x('Quote Sent', 'Order status', 'wc-to-b2b');
            }
        }
        
        return $new_order_statuses;
    }
    
    /**
     * Handle order status changes.
     */
    public function handle_status_change($order_id, $old_status, $new_status, $order) {
        switch ($new_status) {
            case 'verified':
                // Send notification to admin (always email for admin)
                do_action('wc_b2b_send_admin_notification', $order_id);
                break;
                
            case 'quote-sent':
                // Send quote via the same method used for verification
                $verified_via = get_post_meta($order_id, '_verified_via', true);
                
                if ($verified_via === 'whatsapp') {
                    do_action('wc_b2b_send_whatsapp_quote', $order_id);
                } else {
                    // Default to email if no verification method recorded or if verified via email
                    do_action('wc_b2b_send_quote_email', $order_id);
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
            wp_redirect(wc_get_page_permalink('myaccount'));
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
            update_post_meta($token_data->order_id, '_whatsapp_verified', 'yes');
            update_post_meta($token_data->order_id, '_whatsapp_verified_at', current_time('mysql'));
        } else {
            update_post_meta($token_data->order_id, '_email_verified', 'yes');
            update_post_meta($token_data->order_id, '_email_verified_at', current_time('mysql'));
        }
        
        // Check if order should be marked as verified (either method works)
        $email_verified = get_post_meta($token_data->order_id, '_email_verified', true) === 'yes';
        $whatsapp_verified = get_post_meta($token_data->order_id, '_whatsapp_verified', true) === 'yes';
        
        $order = wc_get_order($token_data->order_id);
        if ($order && ($email_verified || $whatsapp_verified)) {
            // Only update status if not already verified
            if ($order->get_status() === 'pending-verification') {
                $verification_method = $token_data->type === 'whatsapp' ? 'WhatsApp' : 'Email';
                $order->update_status('verified', sprintf(__('Order verified by customer via %s.', 'wc-to-b2b'), $verification_method));
                
                // Store which method was used for verification
                update_post_meta($token_data->order_id, '_verified_via', $token_data->type);
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
        
        // Update order status to processing (ready for payment)
        $order->update_status('processing', __('Customer confirmed the quote.', 'wc-to-b2b'));
        
        // Enable payment for this order
        update_post_meta($order_id, '_payment_enabled', 'yes');
        
        wp_send_json_success(array(
            'message' => __('Order confirmed successfully!', 'wc-to-b2b'),
            'payment_url' => $order->get_checkout_payment_url()
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
        // For now, we'll use email verification
        // In a more secure implementation, you might want to use customer accounts
        return true; // Implement proper access control based on your needs
    }
}