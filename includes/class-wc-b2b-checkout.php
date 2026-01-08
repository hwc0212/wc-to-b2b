<?php
/**
 * Checkout modifications for B2B workflow.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WC_B2B_Checkout Class.
 */
class WC_B2B_Checkout {
    
    /**
     * Constructor.
     */
    public function __construct() {
        add_action('woocommerce_checkout_fields', array($this, 'add_custom_checkout_fields'));
        add_action('woocommerce_checkout_process', array($this, 'validate_custom_fields'));
        add_action('woocommerce_checkout_update_order_meta', array($this, 'save_custom_fields'));
        add_action('woocommerce_checkout_order_processed', array($this, 'process_b2b_order'), 10, 3);
        
        // Remove payment methods for B2B orders
        add_filter('woocommerce_available_payment_gateways', array($this, 'disable_payment_gateways'));
        
        // Modify checkout button text
        add_filter('woocommerce_order_button_text', array($this, 'change_order_button_text'));
    }
    
    /**
     * Add custom fields to checkout.
     */
    public function add_custom_checkout_fields($fields) {
        // Add phone field if not exists
        if (!isset($fields['billing']['billing_phone'])) {
            $fields['billing']['billing_phone'] = array(
                'label' => __('Phone', 'wc-to-b2b'),
                'placeholder' => _x('Phone', 'placeholder', 'wc-to-b2b'),
                'required' => true,
                'class' => array('form-row-wide'),
                'clear' => true,
                'type' => 'tel',
                'priority' => 100,
            );
        } else {
            $fields['billing']['billing_phone']['required'] = true;
        }
        
        // Ensure email is required
        if (isset($fields['billing']['billing_email'])) {
            $fields['billing']['billing_email']['required'] = true;
        }
        
        // Add message field
        $fields['order']['order_message'] = array(
            'type' => 'textarea',
            'label' => __('Message', 'wc-to-b2b'),
            'placeholder' => _x('Please leave your message or special requirements here...', 'placeholder', 'wc-to-b2b'),
            'required' => false,
            'class' => array('form-row-wide'),
            'clear' => true,
            'priority' => 110,
        );
        
        return $fields;
    }
    
    /**
     * Validate custom fields.
     */
    public function validate_custom_fields() {
        // Validate phone
        if (empty($_POST['billing_phone'])) {
            wc_add_notice(__('Phone is a required field.', 'wc-to-b2b'), 'error');
        }
        
        // Validate email
        if (empty($_POST['billing_email'])) {
            wc_add_notice(__('Email is a required field.', 'wc-to-b2b'), 'error');
        } elseif (!is_email($_POST['billing_email'])) {
            wc_add_notice(__('Please enter a valid email address.', 'wc-to-b2b'), 'error');
        }
    }
    
    /**
     * Save custom fields.
     */
    public function save_custom_fields($order_id) {
        if (!empty($_POST['order_message'])) {
            update_post_meta($order_id, '_order_message', sanitize_textarea_field($_POST['order_message']));
        }
        
        // Mark as B2B order
        update_post_meta($order_id, '_is_b2b_order', 'yes');
        
        // Initialize verification status
        update_post_meta($order_id, '_email_verified', 'no');
        update_post_meta($order_id, '_whatsapp_verified', 'no');
    }
    
    /**
     * Process B2B order after creation.
     */
    public function process_b2b_order($order_id, $posted_data, $order) {
        // Set order status to pending verification
        $order->update_status('pending-verification', __('Order created, awaiting customer verification.', 'wc-to-b2b'));
        
        // Always generate and send email verification (default method)
        $email_token = $this->generate_verification_token($order_id, 'email');
        if ($email_token) {
            do_action('wc_b2b_send_verification_email', $order_id);
        }
        
        // Only send WhatsApp verification if explicitly enabled and phone number provided
        if (get_option('wc_b2b_whatsapp_enabled', 'no') === 'yes' && !empty($order->get_billing_phone())) {
            $whatsapp_token = $this->generate_verification_token($order_id, 'whatsapp');
            if ($whatsapp_token) {
                do_action('wc_b2b_send_whatsapp_verification', $order_id);
            }
        }
    }
    
    /**
     * Generate verification token.
     */
    private function generate_verification_token($order_id, $type = 'email') {
        global $wpdb;
        
        $token = wp_generate_password(32, false);
        $expires_at = date('Y-m-d H:i:s', strtotime('+' . get_option('wc_b2b_verification_expiry', 48) . ' hours'));
        
        $table_name = $wpdb->prefix . 'wc_b2b_verification_tokens';
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'order_id' => $order_id,
                'token' => $token,
                'type' => $type,
                'expires_at' => $expires_at
            ),
            array('%d', '%s', '%s', '%s')
        );
        
        if ($result === false) {
            error_log('WC B2B: Failed to insert verification token for order ' . $order_id);
            return false;
        }
        
        // Store token in order meta for easy access (only store the latest one)
        update_post_meta($order_id, '_verification_token', $token);
        
        return $token;
    }
    
    /**
     * Disable payment gateways for B2B orders.
     */
    public function disable_payment_gateways($gateways) {
        // Only disable if this is a B2B checkout process
        if (is_admin() || !WC()->cart) {
            return $gateways;
        }
        
        // For B2B workflow, we disable all payment gateways initially
        return array();
    }
    
    /**
     * Change order button text.
     */
    public function change_order_button_text() {
        return __('Submit Order for Verification', 'wc-to-b2b');
    }
}