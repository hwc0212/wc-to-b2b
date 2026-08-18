<?php
/**
 * Admin functionality for B2B workflow.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WC_B2B_Admin Class.
 */
class WC_B2B_Admin {
    
    /**
     * Constructor.
     */
    public function __construct() {
        add_action('add_meta_boxes', array($this, 'add_order_meta_boxes'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Add order actions
        add_filter('woocommerce_order_actions', array($this, 'add_order_actions'));
        add_action('woocommerce_order_action_wc_b2b_send_verification', array($this, 'send_verification_email'));
        add_action('woocommerce_order_action_wc_b2b_manual_verify', array($this, 'manual_verify_order'));
        add_action('woocommerce_order_action_wc_b2b_send_quote', array($this, 'send_quote_to_customer'));
        
        // AJAX actions
        add_action('wp_ajax_wc_b2b_resend_verification', array($this, 'ajax_resend_verification'));
        add_action('wp_ajax_wc_b2b_manual_verify', array($this, 'ajax_manual_verify'));
        add_action('wp_ajax_wc_b2b_update_item_price', array($this, 'ajax_update_item_price'));
        add_action('wp_ajax_wc_b2b_update_shipping_cost', array($this, 'ajax_update_shipping_cost'));
        add_action('wp_ajax_wc_b2b_cleanup_tokens', array($this, 'ajax_cleanup_tokens'));
        
        // Enable price editing for B2B orders
        add_action('woocommerce_admin_order_items_after_line_items', array($this, 'add_b2b_price_editing'));
        add_filter('woocommerce_admin_order_item_headers', array($this, 'add_b2b_price_column_header'));
        add_action('woocommerce_admin_order_item_values', array($this, 'add_b2b_price_column_content'), 10, 3);
    }
    
    /**
     * Add order meta boxes.
     */
    public function add_order_meta_boxes() {
        $screens = array('shop_order');
        if (function_exists('wc_get_page_screen_id')) {
            $screens[] = wc_get_page_screen_id('shop-order');
        }
        foreach (array_unique($screens) as $screen) {
            add_meta_box('wc-b2b-order-actions', __('B2B Order Actions', 'wc-to-b2b'), array($this, 'order_actions_meta_box'), $screen, 'side', 'high');
            add_meta_box('wc-b2b-order-info', __('B2B Order Information', 'wc-to-b2b'), array($this, 'order_info_meta_box'), $screen, 'normal', 'high');
        }
    }
    
    /**
     * Order actions meta box.
     */
    public function order_actions_meta_box($post) {
        $order = $post instanceof WC_Order ? $post : wc_get_order($post->ID);
        $is_b2b = $order ? $order->get_meta('_is_b2b_order', true) : '';
        
        if ($is_b2b !== 'yes') {
            echo '<p>' . __('This is not a B2B order.', 'wc-to-b2b') . '</p>';
            return;
        }
        
        $status = $order->get_status();
        ?>
        <div class="wc-b2b-actions">
            <?php wp_nonce_field('wc_b2b_order_actions', 'wc_b2b_nonce'); ?>
            
            <?php if (in_array($status, array('b2b-verifying', 'pending-verificat'), true)): ?>
                <p><strong><?php _e('Order Status:', 'wc-to-b2b'); ?></strong> <?php _e('Pending Verification', 'wc-to-b2b'); ?></p>
                
                <button type="button" class="button" id="wc-b2b-resend-verification" data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                    <?php _e('Resend Verification Email', 'wc-to-b2b'); ?>
                </button>
                
                <button type="button" class="button button-primary" id="wc-b2b-manual-verify" data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                    <?php _e('Manual Verify', 'wc-to-b2b'); ?>
                </button>
                
            <?php elseif ($status === 'verified'): ?>
                <p><strong><?php _e('Order Status:', 'wc-to-b2b'); ?></strong> <?php _e('Verified - Ready for Quote', 'wc-to-b2b'); ?></p>
                <p class="description"><?php _e('You can now modify the order pricing and shipping, then send the quote to customer.', 'wc-to-b2b'); ?></p>
                
            <?php elseif ($status === 'quote-sent'): ?>
                <p><strong><?php _e('Order Status:', 'wc-to-b2b'); ?></strong> <?php _e('Quote Sent - Awaiting Customer Response', 'wc-to-b2b'); ?></p>
                
            <?php endif; ?>
            
            <div id="wc-b2b-messages"></div>
        </div>
        
        <style>
        .wc-b2b-actions button {
            width: 100%;
            margin-bottom: 10px;
        }
        #wc-b2b-messages {
            margin-top: 15px;
        }
        .wc-b2b-message {
            padding: 8px 12px;
            margin: 5px 0;
            border-radius: 3px;
        }
        .wc-b2b-message.success {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        .wc-b2b-message.error {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        </style>
        <?php
    }
    
    /**
     * Order info meta box.
     */
    public function order_info_meta_box($post) {
        $order = $post instanceof WC_Order ? $post : wc_get_order($post->ID);
        $is_b2b = $order ? $order->get_meta('_is_b2b_order', true) : '';
        
        if ($is_b2b !== 'yes') {
            return;
        }
        
        $message = $order->get_meta('_order_message', true);
        $email_verified = $order->get_meta('_email_verified', true) === 'yes';
        $whatsapp_verified = $order->get_meta('_whatsapp_verified', true) === 'yes';
        $verified_via = $order->get_meta('_verified_via', true);
        $email_verified_at = $order->get_meta('_email_verified_at', true);
        $whatsapp_verified_at = $order->get_meta('_whatsapp_verified_at', true);
        ?>
        <table class="form-table">
            <tr>
                <th><label><?php _e('Verification Status:', 'wc-to-b2b'); ?></label></th>
                <td>
                    <div style="margin-bottom: 10px;">
                        <strong><?php _e('Email:', 'wc-to-b2b'); ?></strong> 
                        <span style="color: <?php echo $email_verified ? '#00a32a' : '#d63638'; ?>;">
                            <?php echo $email_verified ? __('Verified', 'wc-to-b2b') : __('Not Verified', 'wc-to-b2b'); ?>
                        </span>
                        <?php if ($email_verified && $email_verified_at): ?>
                            <br><small><?php echo date('Y-m-d H:i:s', strtotime($email_verified_at)); ?></small>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (get_option('wc_b2b_whatsapp_enabled', 'no') === 'yes'): ?>
                    <div style="margin-bottom: 10px;">
                        <strong><?php _e('WhatsApp:', 'wc-to-b2b'); ?></strong> 
                        <span style="color: <?php echo $whatsapp_verified ? '#00a32a' : '#d63638'; ?>;">
                            <?php echo $whatsapp_verified ? __('Verified', 'wc-to-b2b') : __('Not Verified', 'wc-to-b2b'); ?>
                        </span>
                        <?php if ($whatsapp_verified && $whatsapp_verified_at): ?>
                            <br><small><?php echo date('Y-m-d H:i:s', strtotime($whatsapp_verified_at)); ?></small>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($verified_via): ?>
                    <p class="description">
                        <strong><?php _e('Verified via:', 'wc-to-b2b'); ?></strong> <?php echo $verified_via === 'whatsapp' ? 'WhatsApp' : __('Email', 'wc-to-b2b'); ?>
                        <br>
                        <em><?php printf(__('All future notifications will be sent via %s', 'wc-to-b2b'), $verified_via === 'whatsapp' ? 'WhatsApp' : __('email', 'wc-to-b2b')); ?></em>
                    </p>
                    <?php elseif ($email_verified || $whatsapp_verified): ?>
                    <p class="description">
                        <em><?php _e('Verification method will determine notification channel for future communications.', 'wc-to-b2b'); ?></em>
                    </p>
                    <?php endif; ?>
                </td>
            </tr>
            
            <?php if ($message): ?>
            <tr>
                <th><label><?php _e('Customer Message:', 'wc-to-b2b'); ?></label></th>
                <td>
                    <div style="background: #f9f9f9; padding: 15px; border-left: 3px solid #0073aa;">
                        <?php echo nl2br(esc_html($message)); ?>
                    </div>
                </td>
            </tr>
            <?php endif; ?>
        </table>
        <?php
    }
    
    /**
     * Enqueue admin scripts.
     */
    public function enqueue_admin_scripts($hook) {
        $screen = get_current_screen();
        $hpos_screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : '';
        $is_legacy = in_array($hook, array('post.php', 'post-new.php'), true) && $screen && 'shop_order' === $screen->post_type;
        $is_hpos = $screen && $hpos_screen && $screen->id === $hpos_screen;
        if (!$is_legacy && !$is_hpos) {
            return;
        }
        
        // Enqueue CSS
        wp_enqueue_style(
            'wc-b2b-admin',
            WC_TO_B2B_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            WC_TO_B2B_VERSION
        );
        
        // Enqueue JavaScript
        wp_enqueue_script(
            'wc-b2b-admin',
            WC_TO_B2B_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            WC_TO_B2B_VERSION,
            true
        );
        
        wp_localize_script('wc-b2b-admin', 'wc_b2b_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc_b2b_admin'),
            'messages' => array(
                'verification_sent' => __('Verification email sent successfully!', 'wc-to-b2b'),
                'order_verified' => __('Order verified successfully!', 'wc-to-b2b'),
                'error' => __('An error occurred. Please try again.', 'wc-to-b2b'),
                'processing' => __('Processing...', 'wc-to-b2b')
            )
        ));
    }
    
    /**
     * Add admin menu.
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('B2B Settings', 'wc-to-b2b'),
            __('B2B Settings', 'wc-to-b2b'),
            'manage_woocommerce',
            'wc-b2b-settings',
            array($this, 'settings_page')
        );
    }
    
    /**
     * Settings page.
     */
    public function settings_page() {
        if (isset($_POST['submit'])) {
            $this->save_settings();
        }
        
        $settings = $this->get_settings();
        ?>
        <div class="wrap">
            <h1><?php _e('WooCommerce to B2B Settings', 'wc-to-b2b'); ?></h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('wc_b2b_settings', 'wc_b2b_settings_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Admin Email', 'wc-to-b2b'); ?></th>
                        <td>
                            <input type="email" name="admin_email" value="<?php echo esc_attr($settings['admin_email']); ?>" class="regular-text" />
                            <p class="description"><?php _e('Email address to receive order notifications.', 'wc-to-b2b'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('Verification Expiry', 'wc-to-b2b'); ?></th>
                        <td>
                            <input type="number" name="verification_expiry" value="<?php echo esc_attr($settings['verification_expiry']); ?>" min="1" max="168" /> <?php _e('hours', 'wc-to-b2b'); ?>
                            <p class="description"><?php _e('How long verification links remain valid.', 'wc-to-b2b'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('Order Expiry', 'wc-to-b2b'); ?></th>
                        <td>
                            <input type="number" name="order_expiry" value="<?php echo esc_attr($settings['order_expiry']); ?>" min="1" max="90" /> <?php _e('days', 'wc-to-b2b'); ?>
                            <p class="description"><?php _e('Orders will be automatically cancelled after this period without payment.', 'wc-to-b2b'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('Payment Reminder Interval', 'wc-to-b2b'); ?></th>
                        <td>
                            <input type="number" name="payment_reminder_interval" value="<?php echo esc_attr($settings['payment_reminder_interval']); ?>" min="1" max="30" /> <?php _e('days', 'wc-to-b2b'); ?>
                            <p class="description"><?php _e('How often to send payment reminder emails to customers.', 'wc-to-b2b'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('Enable WhatsApp', 'wc-to-b2b'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="whatsapp_enabled" value="yes" <?php checked($settings['whatsapp_enabled'], 'yes'); ?> />
                                <?php _e('Enable WhatsApp integration', 'wc-to-b2b'); ?>
                            </label>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('WhatsApp API URL', 'wc-to-b2b'); ?></th>
                        <td>
                            <input type="url" name="whatsapp_api_url" value="<?php echo esc_attr($settings['whatsapp_api_url']); ?>" class="regular-text" />
                            <p class="description"><?php _e('WhatsApp API endpoint URL.', 'wc-to-b2b'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('WhatsApp API Key', 'wc-to-b2b'); ?></th>
                        <td>
                            <input type="text" name="whatsapp_api_key" value="<?php echo esc_attr($settings['whatsapp_api_key']); ?>" class="regular-text" />
                            <p class="description"><?php _e('WhatsApp API authentication key.', 'wc-to-b2b'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('Enable WhatsApp Button', 'wc-to-b2b'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="enable_whatsapp_button" value="yes" <?php checked($settings['enable_whatsapp_button'], 'yes'); ?> />
                                <?php _e('Replace add to cart buttons with WhatsApp buttons', 'wc-to-b2b'); ?>
                            </label>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('WhatsApp Phone Number', 'wc-to-b2b'); ?></th>
                        <td>
                            <input type="text" name="whatsapp_phone" value="<?php echo esc_attr($settings['whatsapp_phone']); ?>" class="regular-text" />
                            <p class="description"><?php _e('Enter your WhatsApp business phone number (with country code, no + sign).', 'wc-to-b2b'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('WhatsApp Button Text', 'wc-to-b2b'); ?></th>
                        <td>
                            <input type="text" name="whatsapp_button_text" value="<?php echo esc_attr($settings['whatsapp_button_text']); ?>" class="regular-text" />
                            <p class="description"><?php _e('Text displayed on the WhatsApp button.', 'wc-to-b2b'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <h2><?php _e('Data Management', 'wc-to-b2b'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Complete Uninstall', 'wc-to-b2b'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="complete_uninstall" value="yes" <?php checked($settings['complete_uninstall'], 'yes'); ?> />
                                <?php _e('Remove all plugin data when uninstalling', 'wc-to-b2b'); ?>
                            </label>
                            <p class="description">
                                <?php _e('When enabled, uninstalling the plugin will remove all database tables, settings, and order metadata. This action cannot be undone.', 'wc-to-b2b'); ?>
                                <br>
                                <strong><?php _e('Warning: This will permanently delete all B2B order data and verification tokens.', 'wc-to-b2b'); ?></strong>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('Token Cleanup', 'wc-to-b2b'); ?></th>
                        <td>
                            <p class="description">
                                <?php _e('Verification tokens are automatically cleaned up:', 'wc-to-b2b'); ?>
                            </p>
                            <ul style="margin-left: 20px;">
                                <li><?php _e('• Expired tokens: Cleaned daily', 'wc-to-b2b'); ?></li>
                                <li><?php _e('• Used tokens older than 30 days: Cleaned weekly', 'wc-to-b2b'); ?></li>
                            </ul>
                            <button type="button" class="button" id="wc-b2b-cleanup-tokens">
                                <?php _e('Clean Up Expired Tokens Now', 'wc-to-b2b'); ?>
                            </button>
                            <div id="wc-b2b-cleanup-result" style="margin-top: 10px;"></div>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Get settings.
     */
    private function get_settings() {
        return array(
            'admin_email' => get_option('wc_b2b_admin_email', get_option('admin_email')),
            'verification_expiry' => get_option('wc_b2b_verification_expiry', '48'),
            'order_expiry' => get_option('wc_b2b_order_expiry', '21'),
            'payment_reminder_interval' => get_option('wc_b2b_payment_reminder_interval', '7'),
            'whatsapp_enabled' => get_option('wc_b2b_whatsapp_enabled', 'no'),
            'whatsapp_api_url' => get_option('wc_b2b_whatsapp_api_url', ''),
            'whatsapp_api_key' => get_option('wc_b2b_whatsapp_api_key', ''),
            'enable_whatsapp_button' => get_option('wc_b2b_enable_whatsapp_button', 'no'),
            'whatsapp_phone' => get_option('wc_b2b_whatsapp_phone', ''),
            'whatsapp_button_text' => get_option('wc_b2b_whatsapp_button_text', __('Order via WhatsApp', 'wc-to-b2b')),
            'complete_uninstall' => get_option('wc_b2b_complete_uninstall', 'no')
        );
    }
    
    /**
     * Save settings.
     */
    private function save_settings() {
        if (!wp_verify_nonce($_POST['wc_b2b_settings_nonce'], 'wc_b2b_settings')) {
            return;
        }
        
        update_option('wc_b2b_admin_email', sanitize_email($_POST['admin_email']));
        update_option('wc_b2b_verification_expiry', intval($_POST['verification_expiry']));
        update_option('wc_b2b_order_expiry', intval($_POST['order_expiry']));
        update_option('wc_b2b_payment_reminder_interval', intval($_POST['payment_reminder_interval']));
        update_option('wc_b2b_whatsapp_enabled', isset($_POST['whatsapp_enabled']) ? 'yes' : 'no');
        update_option('wc_b2b_whatsapp_api_url', esc_url_raw($_POST['whatsapp_api_url']));
        update_option('wc_b2b_whatsapp_api_key', sanitize_text_field($_POST['whatsapp_api_key']));
        update_option('wc_b2b_enable_whatsapp_button', isset($_POST['enable_whatsapp_button']) ? 'yes' : 'no');
        update_option('wc_b2b_whatsapp_phone', sanitize_text_field($_POST['whatsapp_phone']));
        update_option('wc_b2b_whatsapp_button_text', sanitize_text_field($_POST['whatsapp_button_text']));
        update_option('wc_b2b_complete_uninstall', isset($_POST['complete_uninstall']) ? 'yes' : 'no');
        
        echo '<div class="notice notice-success"><p>' . __('Settings saved successfully!', 'wc-to-b2b') . '</p></div>';
    }
    
    /**
     * Add order actions.
     */
    public function add_order_actions($actions) {
        global $theorder;
        
        if (!$theorder || $theorder->get_meta('_is_b2b_order', true) !== 'yes') {
            return $actions;
        }
        
        $status = $theorder->get_status();
        
        if (in_array($status, array('b2b-verifying', 'pending-verificat'), true)) {
            $actions['wc_b2b_send_verification'] = __('Resend verification email', 'wc-to-b2b');
            $actions['wc_b2b_manual_verify'] = __('Manual verify order', 'wc-to-b2b');
        } elseif ($status === 'verified') {
            $actions['wc_b2b_send_quote'] = __('Send quote to customer', 'wc-to-b2b');
        }
        
        return $actions;
    }
    
    /**
     * Send verification via both methods.
     */
    public function send_verification_email($order) {
        // Always send email verification (default method)
        do_action('wc_b2b_send_verification_email', $order->get_id());
        $note = __('Verification email resent to customer.', 'wc-to-b2b');
        
        // Only send WhatsApp verification if explicitly enabled and phone available
        if (get_option('wc_b2b_whatsapp_enabled', 'no') === 'yes' && !empty($order->get_billing_phone())) {
            do_action('wc_b2b_send_whatsapp_verification', $order->get_id());
            $note = __('Verification sent via both email and WhatsApp.', 'wc-to-b2b');
        }
        
        $order->add_order_note($note);
    }
    
    /**
     * Manual verify order action.
     */
    public function manual_verify_order($order) {
        $order->update_status('verified', __('Order manually verified by admin.', 'wc-to-b2b'));
    }
    
    /**
     * Send quote to customer action.
     */
    public function send_quote_to_customer($order) {
        $verified_via = $order->get_meta('_verified_via', true);
        
        if ($verified_via === 'whatsapp') {
            $note_message = __('Quote sent to customer via email and WhatsApp.', 'wc-to-b2b');
        } else {
            $note_message = __('Quote sent to customer via email.', 'wc-to-b2b');
        }
            
        $order->update_status('quote-sent', $note_message);
    }
    
    /**
     * AJAX resend verification.
     */
    public function ajax_resend_verification() {
        check_ajax_referer('wc_b2b_admin', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'wc-to-b2b')));
        }
        
        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);
        
        // Always send email verification (default method)
        do_action('wc_b2b_send_verification_email', $order_id);
        $message = __('Verification email sent successfully!', 'wc-to-b2b');
        
        // Only send WhatsApp verification if explicitly enabled and phone available
        if (get_option('wc_b2b_whatsapp_enabled', 'no') === 'yes' && $order && !empty($order->get_billing_phone())) {
            do_action('wc_b2b_send_whatsapp_verification', $order_id);
            $message = __('Verification sent via both email and WhatsApp!', 'wc-to-b2b');
        }
        
        wp_send_json_success(array(
            'message' => $message
        ));
    }
    
    /**
     * AJAX manual verify.
     */
    public function ajax_manual_verify() {
        check_ajax_referer('wc_b2b_admin', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'wc-to-b2b')));
        }
        
        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);
        
        if ($order) {
            $order->update_status('verified', __('Order manually verified by admin.', 'wc-to-b2b'));
            wp_send_json_success(array(
                'message' => __('Order verified successfully!', 'wc-to-b2b')
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Order not found.', 'wc-to-b2b')
            ));
        }
    }
    
    /**
     * AJAX update item price.
     */
    public function ajax_update_item_price() {
        check_ajax_referer('wc_b2b_admin', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'wc-to-b2b')));
        }
        
        $order_id = intval($_POST['order_id']);
        $item_id = intval($_POST['item_id']);
        $new_price = floatval($_POST['new_price']);
        $quantity = intval($_POST['quantity']);
        
        $order = wc_get_order($order_id);
        if (!$order || $order->get_meta('_is_b2b_order', true) !== 'yes') {
            wp_send_json_error(array(
                'message' => __('Invalid B2B order.', 'wc-to-b2b')
            ));
        }
        
        // Check if order is in editable status
        if (!in_array($order->get_status(), array('verified', 'b2b-verifying', 'pending-verificat'), true)) {
            wp_send_json_error(array(
                'message' => __('Order cannot be modified in current status.', 'wc-to-b2b')
            ));
        }
        
        $item = $order->get_item($item_id);
        if (!$item) {
            wp_send_json_error(array(
                'message' => __('Order item not found.', 'wc-to-b2b')
            ));
        }
        
        // Update item price and total
        $item->set_subtotal($new_price * $quantity);
        $item->set_total($new_price * $quantity);
        $item->save();
        
        // Store custom price for reference
        wc_update_order_item_meta($item_id, '_wc_b2b_custom_price', $new_price);
        wc_update_order_item_meta($item_id, '_wc_b2b_original_price', $item->get_product()->get_price());
        
        // Recalculate order totals
        $order->calculate_totals();
        $order->save();
        
        // Add order note
        $order->add_order_note(sprintf(
            __('Item "%s" price updated to %s by admin.', 'wc-to-b2b'),
            $item->get_name(),
            wc_price($new_price)
        ));
        
        wp_send_json_success(array(
            'message' => __('Item price updated successfully!', 'wc-to-b2b'),
            'new_total' => $order->get_formatted_order_total(),
            'item_total' => wc_price($new_price * $quantity)
        ));
    }
    
    /**
     * AJAX update shipping cost.
     */
    public function ajax_update_shipping_cost() {
        check_ajax_referer('wc_b2b_admin', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'wc-to-b2b')));
        }
        
        $order_id = intval($_POST['order_id']);
        $new_shipping_cost = floatval($_POST['shipping_cost']);
        
        $order = wc_get_order($order_id);
        if (!$order || $order->get_meta('_is_b2b_order', true) !== 'yes') {
            wp_send_json_error(array(
                'message' => __('Invalid B2B order.', 'wc-to-b2b')
            ));
        }
        
        // Check if order is in editable status
        if (!in_array($order->get_status(), array('verified', 'b2b-verifying', 'pending-verificat'), true)) {
            wp_send_json_error(array(
                'message' => __('Order cannot be modified in current status.', 'wc-to-b2b')
            ));
        }
        
        // Remove existing shipping
        foreach ($order->get_shipping_methods() as $shipping_item_id => $shipping_item) {
            $order->remove_item($shipping_item_id);
        }
        
        // Add new shipping if cost > 0
        if ($new_shipping_cost > 0) {
            $shipping_item = new WC_Order_Item_Shipping();
            $shipping_item->set_method_title(__('Custom Shipping', 'wc-to-b2b'));
            $shipping_item->set_method_id('custom_b2b_shipping');
            $shipping_item->set_total($new_shipping_cost);
            $order->add_item($shipping_item);
        }
        
        // Recalculate order totals
        $order->calculate_totals();
        $order->save();
        
        // Add order note
        $order->add_order_note(sprintf(
            __('Shipping cost updated to %s by admin.', 'wc-to-b2b'),
            wc_price($new_shipping_cost)
        ));
        
        wp_send_json_success(array(
            'message' => __('Shipping cost updated successfully!', 'wc-to-b2b'),
            'new_total' => $order->get_formatted_order_total(),
            'shipping_total' => wc_price($new_shipping_cost)
        ));
    }
    
    /**
     * Add B2B price editing interface.
     */
    public function add_b2b_price_editing($order_id) {
        $order = $order_id instanceof WC_Order ? $order_id : wc_get_order($order_id);
        if (!$order || $order->get_meta('_is_b2b_order', true) !== 'yes') {
            return;
        }
        
        $status = $order->get_status();
        if (!in_array($status, array('verified', 'b2b-verifying', 'pending-verificat'), true)) {
            return;
        }
        ?>
        <div class="wc-b2b-price-editing" style="margin-top: 20px; padding: 15px; background: #f9f9f9; border-radius: 3px;">
            <h4><?php _e('B2B Price Management', 'wc-to-b2b'); ?></h4>
            <p class="description"><?php _e('You can modify item prices and shipping costs for this B2B order.', 'wc-to-b2b'); ?></p>
            
            <table class="wc-b2b-items-table" style="width: 100%; margin-top: 15px;">
                <thead>
                    <tr>
                        <th><?php _e('Item', 'wc-to-b2b'); ?></th>
                        <th><?php _e('Original Price', 'wc-to-b2b'); ?></th>
                        <th><?php _e('Current Price', 'wc-to-b2b'); ?></th>
                        <th><?php _e('Quantity', 'wc-to-b2b'); ?></th>
                        <th><?php _e('New Price', 'wc-to-b2b'); ?></th>
                        <th><?php _e('Action', 'wc-to-b2b'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($order->get_items() as $item_id => $item): ?>
                    <?php 
                        $product = $item->get_product();
                        $original_price = $product ? $product->get_price() : 0;
                        $current_price = $item->get_total() / $item->get_quantity();
                        $custom_price = wc_get_order_item_meta($item_id, '_wc_b2b_custom_price', true);
                    ?>
                    <tr data-item-id="<?php echo $item_id; ?>">
                        <td><strong><?php echo $item->get_name(); ?></strong></td>
                        <td><?php echo wc_price($original_price); ?></td>
                        <td class="current-price"><?php echo wc_price($current_price); ?></td>
                        <td><?php echo $item->get_quantity(); ?></td>
                        <td>
                            <input type="number" 
                                   class="wc-b2b-new-price" 
                                   step="0.01" 
                                   min="0" 
                                   value="<?php echo $custom_price ? $custom_price : $current_price; ?>"
                                   style="width: 100px;" />
                        </td>
                        <td>
                            <button type="button" 
                                    class="button wc-b2b-update-price" 
                                    data-item-id="<?php echo $item_id; ?>"
                                    data-quantity="<?php echo $item->get_quantity(); ?>">
                                <?php _e('Update', 'wc-to-b2b'); ?>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div class="wc-b2b-shipping-section" style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #ddd;">
                <h5><?php _e('Shipping Cost', 'wc-to-b2b'); ?></h5>
                <table style="width: 100%;">
                    <tr>
                        <td style="width: 150px;"><strong><?php _e('Current Shipping:', 'wc-to-b2b'); ?></strong></td>
                        <td class="current-shipping"><?php echo wc_price($order->get_shipping_total()); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php _e('New Shipping Cost:', 'wc-to-b2b'); ?></strong></td>
                        <td>
                            <input type="number" 
                                   id="wc-b2b-new-shipping" 
                                   step="0.01" 
                                   min="0" 
                                   value="<?php echo $order->get_shipping_total(); ?>"
                                   style="width: 100px;" />
                            <button type="button" 
                                    class="button wc-b2b-update-shipping" 
                                    style="margin-left: 10px;">
                                <?php _e('Update Shipping', 'wc-to-b2b'); ?>
                            </button>
                        </td>
                    </tr>
                </table>
            </div>
            
            <div class="wc-b2b-total-section" style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #ddd;">
                <h5><?php _e('Order Total', 'wc-to-b2b'); ?></h5>
                <p><strong><?php _e('Current Total:', 'wc-to-b2b'); ?> <span class="current-total"><?php echo $order->get_formatted_order_total(); ?></span></strong></p>
            </div>
            
            <div id="wc-b2b-price-messages" style="margin-top: 15px;"></div>
        </div>
        
        <style>
        .wc-b2b-items-table {
            border-collapse: collapse;
        }
        .wc-b2b-items-table th,
        .wc-b2b-items-table td {
            padding: 8px 12px;
            border: 1px solid #ddd;
            text-align: left;
        }
        .wc-b2b-items-table th {
            background-color: #f5f5f5;
            font-weight: bold;
        }
        .wc-b2b-items-table tbody tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        </style>
        <?php
    }
    
    /**
     * Add B2B price column header.
     */
    public function add_b2b_price_column_header($headers) {
        global $post, $theorder;
        $order = $theorder instanceof WC_Order ? $theorder : ($post ? wc_get_order($post->ID) : false);
        if ($order && $order->get_meta('_is_b2b_order', true) === 'yes') {
            $headers['b2b_custom_price'] = __('B2B Price', 'wc-to-b2b');
        }
        return $headers;
    }
    
    /**
     * Add B2B price column content.
     */
    public function add_b2b_price_column_content($product, $item, $item_id) {
        global $post, $theorder;
        $order = $theorder instanceof WC_Order ? $theorder : ($post ? wc_get_order($post->ID) : false);
        if ($order && $order->get_meta('_is_b2b_order', true) === 'yes') {
            $custom_price = wc_get_order_item_meta($item_id, '_wc_b2b_custom_price', true);
            if ($custom_price) {
                echo '<div class="b2b-custom-price">';
                echo '<small>' . __('Custom:', 'wc-to-b2b') . ' ' . wc_price($custom_price) . '</small>';
                echo '</div>';
            }
        }
    }
    
    /**
     * AJAX cleanup tokens.
     */
    public function ajax_cleanup_tokens() {
        check_ajax_referer('wc_b2b_admin', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array(
                'message' => __('Permission denied.', 'wc-to-b2b')
            ));
        }
        
        $expired_count = WC_B2B_Install::cleanup_expired_tokens();
        $old_count = WC_B2B_Install::cleanup_old_tokens();
        
        $total_cleaned = $expired_count + $old_count;
        
        if ($total_cleaned > 0) {
            wp_send_json_success(array(
                'message' => sprintf(
                    __('Successfully cleaned up %d tokens (%d expired, %d old used tokens).', 'wc-to-b2b'),
                    $total_cleaned,
                    $expired_count,
                    $old_count
                )
            ));
        } else {
            wp_send_json_success(array(
                'message' => __('No tokens needed cleanup. Database is already clean.', 'wc-to-b2b')
            ));
        }
    }
}
