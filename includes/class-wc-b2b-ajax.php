<?php
/**
 * AJAX functionality for B2B workflow.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WC_B2B_Ajax Class.
 */
class WC_B2B_Ajax {
    
    /**
     * Constructor.
     */
    public function __construct() {
        // Frontend AJAX actions
        add_action('wp_ajax_wc_b2b_customer_action', array($this, 'handle_customer_action'));
        add_action('wp_ajax_nopriv_wc_b2b_customer_action', array($this, 'handle_customer_action'));
        
        // Handle URL-based actions
        add_action('template_redirect', array($this, 'handle_url_actions'));
        
        // Enqueue frontend scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
    }
    
    /**
     * Handle customer actions (confirm/cancel order).
     */
    public function handle_customer_action() {
        $action = sanitize_text_field($_POST['customer_action']);
        $order_id = intval($_POST['order_id']);
        $nonce = sanitize_text_field($_POST['nonce']);
        
        if (!$order_id) {
            wp_send_json_error(__('Invalid order ID.', 'wc-to-b2b'));
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(__('Order not found.', 'wc-to-b2b'));
        }
        
        switch ($action) {
            case 'confirm':
                if (!wp_verify_nonce($nonce, 'wc_b2b_confirm_' . $order_id)) {
                    wp_send_json_error(__('Security check failed.', 'wc-to-b2b'));
                }
                
                $this->confirm_order($order);
                break;
                
            case 'cancel':
                if (!wp_verify_nonce($nonce, 'wc_b2b_cancel_' . $order_id)) {
                    wp_send_json_error(__('Security check failed.', 'wc-to-b2b'));
                }
                
                $this->cancel_order($order);
                break;
                
            default:
                wp_send_json_error(__('Invalid action.', 'wc-to-b2b'));
        }
    }
    
    /**
     * Handle URL-based actions.
     */
    public function handle_url_actions() {
        if (!isset($_GET['wc_b2b_action'])) {
            return;
        }
        
        $action = sanitize_text_field($_GET['wc_b2b_action']);
        $order_id = intval($_GET['order_id']);
        $nonce = sanitize_text_field($_GET['nonce']);
        
        if (!$order_id) {
            wc_add_notice(__('Invalid order ID.', 'wc-to-b2b'), 'error');
            wp_redirect(home_url());
            exit;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice(__('Order not found.', 'wc-to-b2b'), 'error');
            wp_redirect(home_url());
            exit;
        }
        
        switch ($action) {
            case 'confirm':
                if (!wp_verify_nonce($nonce, 'wc_b2b_confirm_' . $order_id)) {
                    wc_add_notice(__('Security check failed.', 'wc-to-b2b'), 'error');
                    wp_redirect(home_url());
                    exit;
                }
                
                $result = $this->confirm_order($order);
                if ($result['success']) {
                    wc_add_notice($result['message'], 'success');
                    wp_redirect($result['redirect_url']);
                } else {
                    wc_add_notice($result['message'], 'error');
                    wp_redirect(home_url());
                }
                exit;
                
            case 'cancel':
                if (!wp_verify_nonce($nonce, 'wc_b2b_cancel_' . $order_id)) {
                    wc_add_notice(__('Security check failed.', 'wc-to-b2b'), 'error');
                    wp_redirect(home_url());
                    exit;
                }
                
                $result = $this->cancel_order($order);
                wc_add_notice($result['message'], $result['success'] ? 'success' : 'error');
                wp_redirect(home_url());
                exit;
                
            case 'view':
                // Show order details page
                $this->show_order_details($order);
                exit;
        }
    }
    
    /**
     * Confirm order.
     */
    private function confirm_order($order) {
        if ($order->get_status() !== 'quote-sent') {
            return array(
                'success' => false,
                'message' => __('This order cannot be confirmed at this time.', 'wc-to-b2b')
            );
        }
        
        // Update order status to processing (ready for payment)
        $order->update_status('processing', __('Customer confirmed the quote.', 'wc-to-b2b'));
        
        // Enable payment for this order
        update_post_meta($order->get_id(), '_payment_enabled', 'yes');
        
        $result = array(
            'success' => true,
            'message' => __('Order confirmed successfully! You will be redirected to payment.', 'wc-to-b2b'),
            'redirect_url' => $order->get_checkout_payment_url()
        );
        
        if (wp_doing_ajax()) {
            wp_send_json_success($result);
        }
        
        return $result;
    }
    
    /**
     * Cancel order.
     */
    private function cancel_order($order) {
        if (!in_array($order->get_status(), array('quote-sent', 'pending-verification', 'verified'))) {
            return array(
                'success' => false,
                'message' => __('This order cannot be cancelled at this time.', 'wc-to-b2b')
            );
        }
        
        // Update order status to cancelled
        $order->update_status('cancelled', __('Order cancelled by customer.', 'wc-to-b2b'));
        
        $result = array(
            'success' => true,
            'message' => __('Order cancelled successfully.', 'wc-to-b2b')
        );
        
        if (wp_doing_ajax()) {
            wp_send_json_success($result);
        }
        
        return $result;
    }
    
    /**
     * Show order details page.
     */
    private function show_order_details($order) {
        // Set up WordPress environment for template
        global $wp_query;
        $wp_query->is_page = true;
        $wp_query->is_singular = true;
        
        // Load header
        get_header();
        
        // Display order details
        $this->display_order_details_template($order);
        
        // Load footer
        get_footer();
    }
    
    /**
     * Display order details template.
     */
    private function display_order_details_template($order) {
        $status = $order->get_status();
        $can_confirm = ($status === 'quote-sent');
        $can_cancel = in_array($status, array('quote-sent', 'pending-verification', 'verified'));
        ?>
        <div class="wc-b2b-order-details" style="max-width: 800px; margin: 40px auto; padding: 20px; font-family: Arial, sans-serif;">
            <h1><?php printf(__('Order #%s Details', 'wc-to-b2b'), $order->get_order_number()); ?></h1>
            
            <div class="order-status" style="background: #f9f9f9; padding: 15px; margin: 20px 0; border-radius: 5px;">
                <strong><?php _e('Status:', 'wc-to-b2b'); ?></strong> 
                <span class="status-<?php echo esc_attr($status); ?>">
                    <?php echo wc_get_order_status_name($status); ?>
                </span>
            </div>
            
            <div class="order-info" style="margin: 30px 0;">
                <h3><?php _e('Order Information', 'wc-to-b2b'); ?></h3>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr style="border-bottom: 1px solid #eee;">
                        <td style="padding: 10px 0; font-weight: bold;"><?php _e('Order Date:', 'wc-to-b2b'); ?></td>
                        <td style="padding: 10px 0;"><?php echo wc_format_datetime($order->get_date_created()); ?></td>
                    </tr>
                    <tr style="border-bottom: 1px solid #eee;">
                        <td style="padding: 10px 0; font-weight: bold;"><?php _e('Customer:', 'wc-to-b2b'); ?></td>
                        <td style="padding: 10px 0;"><?php echo $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(); ?></td>
                    </tr>
                    <tr style="border-bottom: 1px solid #eee;">
                        <td style="padding: 10px 0; font-weight: bold;"><?php _e('Email:', 'wc-to-b2b'); ?></td>
                        <td style="padding: 10px 0;"><?php echo $order->get_billing_email(); ?></td>
                    </tr>
                    <tr style="border-bottom: 1px solid #eee;">
                        <td style="padding: 10px 0; font-weight: bold;"><?php _e('Phone:', 'wc-to-b2b'); ?></td>
                        <td style="padding: 10px 0;"><?php echo $order->get_billing_phone(); ?></td>
                    </tr>
                </table>
            </div>
            
            <div class="order-items" style="margin: 30px 0;">
                <h3><?php _e('Order Items', 'wc-to-b2b'); ?></h3>
                <table style="width: 100%; border-collapse: collapse; border: 1px solid #ddd;">
                    <thead>
                        <tr style="background: #f5f5f5;">
                            <th style="padding: 12px; text-align: left; border-bottom: 1px solid #ddd;"><?php _e('Product', 'wc-to-b2b'); ?></th>
                            <th style="padding: 12px; text-align: center; border-bottom: 1px solid #ddd;"><?php _e('Quantity', 'wc-to-b2b'); ?></th>
                            <th style="padding: 12px; text-align: right; border-bottom: 1px solid #ddd;"><?php _e('Price', 'wc-to-b2b'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($order->get_items() as $item): ?>
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 12px;"><?php echo $item->get_name(); ?></td>
                            <td style="padding: 12px; text-align: center;"><?php echo $item->get_quantity(); ?></td>
                            <td style="padding: 12px; text-align: right;"><?php echo wc_price($item->get_total()); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background: #f9f9f9; font-weight: bold;">
                            <td colspan="2" style="padding: 12px; text-align: right;"><?php _e('Total:', 'wc-to-b2b'); ?></td>
                            <td style="padding: 12px; text-align: right;"><?php echo $order->get_formatted_order_total(); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            
            <?php $message = get_post_meta($order->get_id(), '_order_message', true); ?>
            <?php if ($message): ?>
            <div class="customer-message" style="margin: 30px 0;">
                <h3><?php _e('Your Message', 'wc-to-b2b'); ?></h3>
                <div style="background: #f9f9f9; padding: 15px; border-left: 3px solid #0073aa;">
                    <?php echo nl2br(esc_html($message)); ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($can_confirm || $can_cancel): ?>
            <div class="order-actions" style="margin: 40px 0; text-align: center;">
                <?php if ($can_confirm): ?>
                <button type="button" class="wc-b2b-confirm-btn" data-order-id="<?php echo $order->get_id(); ?>" style="background: #28a745; color: white; padding: 12px 30px; border: none; border-radius: 3px; margin-right: 10px; cursor: pointer; font-size: 16px;">
                    <?php _e('Accept Quote & Proceed to Payment', 'wc-to-b2b'); ?>
                </button>
                <?php endif; ?>
                
                <?php if ($can_cancel): ?>
                <button type="button" class="wc-b2b-cancel-btn" data-order-id="<?php echo $order->get_id(); ?>" style="background: #dc3545; color: white; padding: 12px 30px; border: none; border-radius: 3px; cursor: pointer; font-size: 16px;">
                    <?php _e('Cancel Order', 'wc-to-b2b'); ?>
                </button>
                <?php endif; ?>
            </div>
            
            <div id="wc-b2b-messages" style="margin: 20px 0;"></div>
            <?php endif; ?>
        </div>
        
        <style>
        .wc-b2b-confirm-btn:hover { background: #218838 !important; }
        .wc-b2b-cancel-btn:hover { background: #c82333 !important; }
        .wc-b2b-message {
            padding: 12px 15px;
            margin: 10px 0;
            border-radius: 3px;
        }
        .wc-b2b-message.success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        .wc-b2b-message.error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        </style>
        <?php
    }
    
    /**
     * Enqueue frontend scripts.
     */
    public function enqueue_frontend_scripts() {
        if (isset($_GET['wc_b2b_action']) && $_GET['wc_b2b_action'] === 'view') {
            wp_enqueue_script(
                'wc-b2b-frontend',
                WC_TO_B2B_PLUGIN_URL . 'assets/js/frontend.js',
                array('jquery'),
                WC_TO_B2B_VERSION,
                true
            );
            
            wp_localize_script('wc-b2b-frontend', 'wc_b2b_frontend', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'confirm_nonce' => wp_create_nonce('wc_b2b_confirm_' . intval($_GET['order_id'])),
                'cancel_nonce' => wp_create_nonce('wc_b2b_cancel_' . intval($_GET['order_id'])),
                'messages' => array(
                    'confirm_order' => __('Are you sure you want to accept this quote?', 'wc-to-b2b'),
                    'cancel_order' => __('Are you sure you want to cancel this order?', 'wc-to-b2b'),
                    'processing' => __('Processing...', 'wc-to-b2b')
                )
            ));
        }
    }
}