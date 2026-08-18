<?php
/**
 * WhatsApp integration for B2B workflow.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WC_B2B_WhatsApp Class.
 */
class WC_B2B_WhatsApp {
    
    /**
     * Constructor.
     */
    public function __construct() {
        add_action('wc_b2b_send_whatsapp_verification', array($this, 'send_verification_message'));
        add_action('wc_b2b_send_whatsapp_quote', array($this, 'send_quote_message'));
        
        // Add WhatsApp verification endpoint
        add_action('wp_ajax_wc_b2b_whatsapp_verify', array($this, 'ajax_whatsapp_verify'));
        add_action('wp_ajax_nopriv_wc_b2b_whatsapp_verify', array($this, 'ajax_whatsapp_verify'));
        
        // Handle WhatsApp webhook (if needed)
        add_action('rest_api_init', array($this, 'register_webhook_endpoint'));
    }
    
    /**
     * Send verification message via WhatsApp.
     */
    public function send_verification_message($order_id) {
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
        
        $token = $order->get_meta('_verification_token', true);
        if (!$token) {
            return false;
        }
        
        $verification_url = add_query_arg(array(
            'wc_b2b_whatsapp_verify' => '1',
            'token' => $token
        ), home_url());
        
        $message = sprintf(
            __("Hello %s!\n\nYour order #%s needs verification.\n\nClick here to verify: %s\n\nOr reply with: VERIFY %s", 'wc-to-b2b'),
            $order->get_billing_first_name(),
            $order->get_order_number(),
            $verification_url,
            $token
        );
        
        return $this->send_whatsapp_message($phone, $message);
    }
    
    /**
     * Send quote message via WhatsApp.
     */
    public function send_quote_message($order_id) {
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
        
        $confirm_url = WC_B2B_Quote::get_action_url($order, 'confirm');
        $cancel_url = WC_B2B_Quote::get_action_url($order, 'cancel');
        
        $message = sprintf(
            __("Hello %s!\n\nYour quote for order #%s is ready.\n\nTotal: %s\n\nTo accept: %s\nTo cancel: %s\n\nOr reply:\nACCEPT %d - to accept\nCANCEL %d - to cancel", 'wc-to-b2b'),
            $order->get_billing_first_name(),
            $order->get_order_number(),
            $order->get_formatted_order_total(),
            $confirm_url,
            $cancel_url,
            $order_id,
            $order_id
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
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code === 200) {
            return true;
        } else {
            error_log('WhatsApp API Error: ' . $response_body);
            return false;
        }
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
        
        // Add country code if not present (assuming international format)
        if (strlen($phone) < 10) {
            return false;
        }
        
        // If phone doesn't start with country code, you might want to add default country code
        // This is a simple implementation - you may need to adjust based on your requirements
        if (!preg_match('/^[1-9]/', $phone)) {
            return false;
        }
        
        return $phone;
    }
    
    /**
     * AJAX WhatsApp verification.
     */
    public function ajax_whatsapp_verify() {
        if (!isset($_GET['token'])) {
            wp_die(__('Invalid verification link.', 'wc-to-b2b'));
        }
        
        $token = sanitize_text_field($_GET['token']);
        
        // Use the same verification logic as email
        $order_handler = new WC_B2B_Order();
        $result = $order_handler->verify_order_by_token($token);
        
        if ($result['success']) {
            wp_redirect(add_query_arg('verified', '1', home_url()));
        } else {
            wp_redirect(add_query_arg('error', urlencode($result['message']), home_url()));
        }
        exit;
    }
    
    /**
     * Register webhook endpoint for WhatsApp responses.
     */
    public function register_webhook_endpoint() {
        register_rest_route('wc-b2b/v1', '/whatsapp-webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_whatsapp_webhook'),
            'permission_callback' => array($this, 'verify_webhook_signature')
        ));
    }
    
    /**
     * Handle WhatsApp webhook for text responses.
     */
    public function handle_whatsapp_webhook($request) {
        $data = $request->get_json_params();
        
        if (!isset($data['phone']) || !isset($data['message'])) {
            return new WP_Error('invalid_data', 'Missing required fields', array('status' => 400));
        }
        
        $phone = $this->format_phone_number($data['phone']);
        $message = strtoupper(trim($data['message']));
        
        // Handle VERIFY command
        if (preg_match('/^VERIFY\s+([A-Za-z0-9]+)$/', $message, $matches)) {
            $token = $matches[1];
            $order_handler = new WC_B2B_Order();
            $result = $order_handler->verify_order_by_token($token);
            
            if ($result['success']) {
                $response_message = __('Order verified successfully! You will receive updates via email.', 'wc-to-b2b');
            } else {
                $response_message = __('Invalid or expired verification code.', 'wc-to-b2b');
            }
            
            $this->send_whatsapp_message($phone, $response_message);
            return rest_ensure_response(array('status' => 'processed'));
        }
        
        // Handle ACCEPT command
        if (preg_match('/^ACCEPT\s+(\d+)$/', $message, $matches)) {
            $order_id = intval($matches[1]);
            $order = wc_get_order($order_id);
            
            if ($order && $order->get_status() === 'quote-sent' && WC_B2B_Quote::is_quote_valid($order) && $this->verify_phone_matches_order($phone, $order)) {
                $order->update_status('quote-accepted', __('Customer accepted the quote via WhatsApp and will pay offline.', 'wc-to-b2b'));

                $response_message = sprintf(
                    __('Order #%s confirmed. View offline payment information: %s', 'wc-to-b2b'),
                    $order->get_order_number(),
                    WC_B2B_Quote::get_action_url($order, 'view')
                );
            } else {
                $response_message = __('Invalid order or phone number mismatch.', 'wc-to-b2b');
            }
            
            $this->send_whatsapp_message($phone, $response_message);
            return rest_ensure_response(array('status' => 'processed'));
        }
        
        // Handle CANCEL command
        if (preg_match('/^CANCEL\s+(\d+)$/', $message, $matches)) {
            $order_id = intval($matches[1]);
            $order = wc_get_order($order_id);
            
            if ($order && $this->verify_phone_matches_order($phone, $order)) {
                $order->update_status('cancelled', __('Order cancelled by customer via WhatsApp.', 'wc-to-b2b'));
                $response_message = sprintf(__('Order #%s has been cancelled.', 'wc-to-b2b'), $order->get_order_number());
            } else {
                $response_message = __('Invalid order or phone number mismatch.', 'wc-to-b2b');
            }
            
            $this->send_whatsapp_message($phone, $response_message);
            return rest_ensure_response(array('status' => 'processed'));
        }
        
        return rest_ensure_response(array('status' => 'ignored'));
    }
    
    /**
     * Verify webhook signature (implement based on your WhatsApp API provider).
     */
    public function verify_webhook_signature($request) {
        // Implement signature verification based on your WhatsApp API provider
        // For now, we'll allow all requests - you should implement proper security
        return true;
    }
    
    /**
     * Verify phone number matches order.
     */
    private function verify_phone_matches_order($phone, $order) {
        $order_phone = $this->format_phone_number($order->get_billing_phone());
        return $phone === $order_phone;
    }
}
