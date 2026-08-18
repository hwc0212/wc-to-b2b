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
        add_action('woocommerce_checkout_create_order', array($this, 'save_custom_fields_hpos'), 20, 2);
        add_action('woocommerce_checkout_order_processed', array($this, 'process_b2b_order'), 10, 3);
        add_action('woocommerce_store_api_checkout_order_processed', array($this, 'save_store_api_order_meta'), 5, 1);
        add_action('woocommerce_store_api_checkout_order_processed', array($this, 'process_store_api_order'), 10, 1);
        
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
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        if (!empty($_POST['order_message'])) {
            $order->update_meta_data('_order_message', sanitize_textarea_field(wp_unslash($_POST['order_message'])));
        }
        $order->update_meta_data('_is_b2b_order', 'yes');
        $order->update_meta_data('_email_verified', 'no');
        $order->update_meta_data('_whatsapp_verified', 'no');
        $order->save();
        $order->save();
    }

    /**
     * Save B2B metadata through the WooCommerce CRUD layer for HPOS.
     */
    public function save_custom_fields_hpos($order, $data) {
        if (!empty($_POST['order_message'])) {
            $order->update_meta_data('_order_message', sanitize_textarea_field(wp_unslash($_POST['order_message'])));
        }
        $order->update_meta_data('_is_b2b_order', 'yes');
        $order->update_meta_data('_email_verified', 'no');
        $order->update_meta_data('_whatsapp_verified', 'no');
    }

    public function save_store_api_order_meta($order) {
        if (!$order instanceof WC_Order) {
            return;
        }
        $order->update_meta_data('_is_b2b_order', 'yes');
        $order->update_meta_data('_email_verified', 'no');
        $order->update_meta_data('_whatsapp_verified', 'no');
    }

    public function process_store_api_order($order) {
        if ($order instanceof WC_Order) {
            $this->process_b2b_order($order->get_id(), array(), $order);
        }
    }
    
    /**
     * Process B2B order after creation.
     */
    public function process_b2b_order($order_id, $posted_data, $order) {
        if (in_array($order->get_status(), array('b2b-verifying', 'verified', 'quote-sent', 'quote-accepted', 'processing', 'completed'), true)) {
            return;
        }
        $verify_guest = !$order->get_customer_id() && get_option('wc_b2b_verify_guests', 'yes') === 'yes';

        if ($verify_guest) {
            $order->update_status('b2b-verifying', __('Order created, awaiting customer verification.', 'wc-to-b2b'));
            $email_token = $this->generate_verification_token($order_id, 'email');
            if ($email_token) {
                do_action('wc_b2b_send_verification_email', $order_id);
            }
            if (get_option('wc_b2b_whatsapp_enabled', 'no') === 'yes' && $order->get_billing_phone()) {
                $whatsapp_token = $this->generate_verification_token($order_id, 'whatsapp');
                if ($whatsapp_token) {
                    do_action('wc_b2b_send_whatsapp_verification', $order_id);
                }
            }
            return;
        }

        $order->update_meta_data('_email_verified', 'yes');
        $order->update_meta_data('_email_verified_at', current_time('mysql'));
        $order->update_meta_data('_verified_via', 'account');
        $order->save();

        if (get_option('wc_b2b_auto_quote', 'yes') === 'yes') {
            WC_B2B_Quote::prepare_quote($order);
            $order->update_status('quote-sent', __('Automatic quotation generated from the customer membership price.', 'wc-to-b2b'));
        } else {
            $order->update_status('verified', __('Order received and ready for administrator quotation.', 'wc-to-b2b'));
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
        $order = wc_get_order($order_id);
        if ($order) {
            $order->update_meta_data('_verification_token', $token);
            $order->save();
        }
        
        return $token;
    }
    
    /**
     * Disable payment gateways for B2B orders.
     */
    public function disable_payment_gateways($gateways) {
        // Only disable if this is a B2B checkout process
        if (is_admin() && !wp_doing_ajax()) {
            return $gateways;
        }
        
        // Checkout is an order/quotation submission. Never expose an online gateway.
        return isset($gateways['b2b_quote']) ? array('b2b_quote' => $gateways['b2b_quote']) : $gateways;
    }
    
    /**
     * Change order button text.
     */
    public function change_order_button_text() {
        return __('Submit B2B Quote Order', 'wc-to-b2b');
    }
}
