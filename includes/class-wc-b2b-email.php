<?php
/**
 * Email functionality for B2B workflow.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WC_B2B_Email Class.
 */
class WC_B2B_Email {
    
    /**
     * Constructor.
     */
    public function __construct() {
        add_action('wc_b2b_send_verification_email', array($this, 'send_verification_email'));
        add_action('wc_b2b_send_admin_notification', array($this, 'send_admin_notification'));
        add_action('wc_b2b_send_quote_email', array($this, 'send_quote_email'));
        add_action('wc_b2b_send_order_cancelled_email', array($this, 'send_order_cancelled_email'));
        
        // Add email templates to WooCommerce
        add_filter('woocommerce_email_classes', array($this, 'add_email_classes'));
        add_filter('woocommerce_email_actions', array($this, 'add_email_actions'));
    }
    
    /**
     * Send verification email to customer.
     */
    public function send_verification_email($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }
        
        $token = get_post_meta($order_id, '_verification_token', true);
        if (!$token) {
            return false;
        }
        
        $verification_url = add_query_arg(array(
            'wc_b2b_verify' => '1',
            'token' => $token
        ), home_url());
        
        $to = $order->get_billing_email();
        $subject = sprintf(__('Please verify your order #%s', 'wc-to-b2b'), $order->get_order_number());
        
        $message = $this->get_verification_email_template($order, $verification_url);
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        return wp_mail($to, $subject, $message, $headers);
    }
    
    /**
     * Send admin notification when order is verified.
     */
    public function send_admin_notification($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }
        
        $admin_email = get_option('wc_b2b_admin_email', get_option('admin_email'));
        $subject = sprintf(__('Order #%s has been verified', 'wc-to-b2b'), $order->get_order_number());
        
        $message = $this->get_admin_notification_template($order);
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        return wp_mail($admin_email, $subject, $message, $headers);
    }
    
    /**
     * Send quote email to customer.
     */
    public function send_quote_email($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }
        
        $to = $order->get_billing_email();
        $subject = sprintf(__('Your quote for order #%s is ready', 'wc-to-b2b'), $order->get_order_number());
        
        $message = $this->get_quote_email_template($order);
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        return wp_mail($to, $subject, $message, $headers);
    }
    
    /**
     * Get verification email template.
     */
    private function get_verification_email_template($order, $verification_url) {
        ob_start();
        ?>
        <div style="background-color: #f7f7f7; padding: 20px; font-family: Arial, sans-serif;">
            <div style="max-width: 600px; margin: 0 auto; background-color: white; padding: 30px; border-radius: 5px;">
                <h2 style="color: #333; margin-bottom: 20px;"><?php _e('Order Verification Required', 'wc-to-b2b'); ?></h2>
                
                <p><?php printf(__('Hello %s,', 'wc-to-b2b'), $order->get_billing_first_name()); ?></p>
                
                <p><?php printf(__('Thank you for your order #%s. To proceed with your order, please verify your email address by clicking the button below:', 'wc-to-b2b'), $order->get_order_number()); ?></p>
                
                <div style="text-align: center; margin: 30px 0;">
                    <a href="<?php echo esc_url($verification_url); ?>" style="background-color: #0073aa; color: white; padding: 12px 30px; text-decoration: none; border-radius: 3px; display: inline-block;"><?php _e('Verify Order', 'wc-to-b2b'); ?></a>
                </div>
                
                <p><?php _e('If the button doesn\'t work, you can copy and paste this link into your browser:', 'wc-to-b2b'); ?></p>
                <p><a href="<?php echo esc_url($verification_url); ?>"><?php echo esc_url($verification_url); ?></a></p>
                
                <hr style="margin: 30px 0; border: none; border-top: 1px solid #eee;">
                
                <h3><?php _e('Order Details:', 'wc-to-b2b'); ?></h3>
                <p><strong><?php _e('Order Number:', 'wc-to-b2b'); ?></strong> #<?php echo $order->get_order_number(); ?></p>
                <p><strong><?php _e('Order Date:', 'wc-to-b2b'); ?></strong> <?php echo wc_format_datetime($order->get_date_created()); ?></p>
                <p><strong><?php _e('Total:', 'wc-to-b2b'); ?></strong> <?php echo $order->get_formatted_order_total(); ?></p>
                
                <?php $message = get_post_meta($order->get_id(), '_order_message', true); ?>
                <?php if ($message): ?>
                <p><strong><?php _e('Your Message:', 'wc-to-b2b'); ?></strong></p>
                <p style="background-color: #f9f9f9; padding: 15px; border-left: 3px solid #0073aa;"><?php echo nl2br(esc_html($message)); ?></p>
                <?php endif; ?>
                
                <p style="margin-top: 30px; color: #666; font-size: 12px;"><?php _e('This verification link will expire in 24 hours.', 'wc-to-b2b'); ?></p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Get admin notification template.
     */
    private function get_admin_notification_template($order) {
        ob_start();
        
        $billing_address = $order->get_formatted_billing_address();
        $shipping_address = $order->get_formatted_shipping_address();
        $message = get_post_meta($order->get_id(), '_order_message', true);
        $verified_via = get_post_meta($order->get_id(), '_verified_via', true);
        
        ?>
        <div style="background-color: #f7f7f7; padding: 20px; font-family: Arial, sans-serif;">
            <div style="max-width: 800px; margin: 0 auto; background-color: white; padding: 30px; border-radius: 5px;">
                <h2 style="color: #333; margin-bottom: 20px; border-bottom: 2px solid #0073aa; padding-bottom: 10px;">
                    📋 <?php _e('New B2B Order - Ready for Quote', 'wc-to-b2b'); ?>
                </h2>
                
                <div style="background-color: #e7f3ff; padding: 15px; margin: 20px 0; border-radius: 5px; border-left: 4px solid #0073aa;">
                    <p style="margin: 0; font-weight: bold; color: #0073aa;">
                        <?php printf(__('Order #%s has been verified and is ready for your quote. All information needed for pricing is included below.', 'wc-to-b2b'), $order->get_order_number()); ?>
                    </p>
                </div>
                
                <!-- Customer Contact Information -->
                <div style="background-color: #f9f9f9; padding: 20px; margin: 20px 0; border-radius: 5px;">
                    <h3 style="color: #333; margin-top: 0; border-bottom: 1px solid #ddd; padding-bottom: 8px;">
                        👤 <?php _e('Customer Contact Information', 'wc-to-b2b'); ?>
                    </h3>
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr>
                            <td style="padding: 8px 0; font-weight: bold; width: 120px;"><?php _e('Name:', 'wc-to-b2b'); ?></td>
                            <td style="padding: 8px 0;"><?php echo $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(); ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 8px 0; font-weight: bold;"><?php _e('Email:', 'wc-to-b2b'); ?></td>
                            <td style="padding: 8px 0;"><a href="mailto:<?php echo $order->get_billing_email(); ?>"><?php echo $order->get_billing_email(); ?></a></td>
                        </tr>
                        <tr>
                            <td style="padding: 8px 0; font-weight: bold;"><?php _e('Phone:', 'wc-to-b2b'); ?></td>
                            <td style="padding: 8px 0;"><a href="tel:<?php echo $order->get_billing_phone(); ?>"><?php echo $order->get_billing_phone(); ?></a></td>
                        </tr>
                        <tr>
                            <td style="padding: 8px 0; font-weight: bold;"><?php _e('Company:', 'wc-to-b2b'); ?></td>
                            <td style="padding: 8px 0;"><?php echo $order->get_billing_company() ?: __('Not provided', 'wc-to-b2b'); ?></td>
                        </tr>
                        <?php if ($verified_via): ?>
                        <tr>
                            <td style="padding: 8px 0; font-weight: bold;"><?php _e('Verified via:', 'wc-to-b2b'); ?></td>
                            <td style="padding: 8px 0;">
                                <span style="background: #28a745; color: white; padding: 2px 8px; border-radius: 3px; font-size: 12px;">
                                    <?php echo $verified_via === 'whatsapp' ? 'WhatsApp' : __('Email', 'wc-to-b2b'); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
                
                <!-- Order Details -->
                <div style="background-color: #f9f9f9; padding: 20px; margin: 20px 0; border-radius: 5px;">
                    <h3 style="color: #333; margin-top: 0; border-bottom: 1px solid #ddd; padding-bottom: 8px;">
                        📦 <?php _e('Order Details', 'wc-to-b2b'); ?>
                    </h3>
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr>
                            <td style="padding: 8px 0; font-weight: bold; width: 120px;"><?php _e('Order Number:', 'wc-to-b2b'); ?></td>
                            <td style="padding: 8px 0;">#<?php echo $order->get_order_number(); ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 8px 0; font-weight: bold;"><?php _e('Order Date:', 'wc-to-b2b'); ?></td>
                            <td style="padding: 8px 0;"><?php echo wc_format_datetime($order->get_date_created()); ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 8px 0; font-weight: bold;"><?php _e('Current Total:', 'wc-to-b2b'); ?></td>
                            <td style="padding: 8px 0; font-size: 16px; color: #0073aa;"><strong><?php echo $order->get_formatted_order_total(); ?></strong></td>
                        </tr>
                    </table>
                </div>
                
                <!-- Product Details -->
                <div style="background-color: #f9f9f9; padding: 20px; margin: 20px 0; border-radius: 5px;">
                    <h3 style="color: #333; margin-top: 0; border-bottom: 1px solid #ddd; padding-bottom: 8px;">
                        🛍️ <?php _e('Products Ordered', 'wc-to-b2b'); ?>
                    </h3>
                    <table style="width: 100%; border-collapse: collapse; background: white; border-radius: 3px;">
                        <thead>
                            <tr style="background: #f5f5f5;">
                                <th style="padding: 12px; text-align: left; border-bottom: 1px solid #ddd;"><?php _e('Product', 'wc-to-b2b'); ?></th>
                                <th style="padding: 12px; text-align: center; border-bottom: 1px solid #ddd;"><?php _e('Qty', 'wc-to-b2b'); ?></th>
                                <th style="padding: 12px; text-align: right; border-bottom: 1px solid #ddd;"><?php _e('Unit Price', 'wc-to-b2b'); ?></th>
                                <th style="padding: 12px; text-align: right; border-bottom: 1px solid #ddd;"><?php _e('Total', 'wc-to-b2b'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($order->get_items() as $item_id => $item): ?>
                            <?php 
                                $product = $item->get_product();
                                $unit_price = $item->get_total() / $item->get_quantity();
                            ?>
                            <tr>
                                <td style="padding: 12px; border-bottom: 1px solid #eee;">
                                    <strong><?php echo $item->get_name(); ?></strong>
                                    <?php if ($product && $product->get_sku()): ?>
                                    <br><small style="color: #666;"><?php _e('SKU:', 'wc-to-b2b'); ?> <?php echo $product->get_sku(); ?></small>
                                    <?php endif; ?>
                                    <?php 
                                    $item_meta = $item->get_formatted_meta_data();
                                    if ($item_meta): ?>
                                    <br><small style="color: #666;">
                                        <?php foreach ($item_meta as $meta): ?>
                                            <?php echo $meta->display_key; ?>: <?php echo $meta->display_value; ?><br>
                                        <?php endforeach; ?>
                                    </small>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #eee;"><?php echo $item->get_quantity(); ?></td>
                                <td style="padding: 12px; text-align: right; border-bottom: 1px solid #eee;"><?php echo wc_price($unit_price); ?></td>
                                <td style="padding: 12px; text-align: right; border-bottom: 1px solid #eee; font-weight: bold;"><?php echo wc_price($item->get_total()); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background: #f9f9f9;">
                                <td colspan="3" style="padding: 12px; text-align: right; font-weight: bold; border-top: 2px solid #0073aa;"><?php _e('Subtotal:', 'wc-to-b2b'); ?></td>
                                <td style="padding: 12px; text-align: right; font-weight: bold; border-top: 2px solid #0073aa;"><?php echo wc_price($order->get_subtotal()); ?></td>
                            </tr>
                            <?php if ($order->get_shipping_total() > 0): ?>
                            <tr style="background: #f9f9f9;">
                                <td colspan="3" style="padding: 12px; text-align: right; font-weight: bold;"><?php _e('Shipping:', 'wc-to-b2b'); ?></td>
                                <td style="padding: 12px; text-align: right; font-weight: bold;"><?php echo wc_price($order->get_shipping_total()); ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr style="background: #e7f3ff;">
                                <td colspan="3" style="padding: 15px; text-align: right; font-weight: bold; font-size: 16px; color: #0073aa;"><?php _e('Total:', 'wc-to-b2b'); ?></td>
                                <td style="padding: 15px; text-align: right; font-weight: bold; font-size: 16px; color: #0073aa;"><?php echo $order->get_formatted_order_total(); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                
                <!-- Addresses -->
                <div style="display: flex; gap: 20px; margin: 20px 0;">
                    <div style="flex: 1; background-color: #f9f9f9; padding: 20px; border-radius: 5px;">
                        <h3 style="color: #333; margin-top: 0; border-bottom: 1px solid #ddd; padding-bottom: 8px;">
                            📍 <?php _e('Billing Address', 'wc-to-b2b'); ?>
                        </h3>
                        <div style="line-height: 1.6;">
                            <?php echo $billing_address ? $billing_address : __('Not provided', 'wc-to-b2b'); ?>
                        </div>
                    </div>
                    
                    <?php if ($shipping_address && $shipping_address !== $billing_address): ?>
                    <div style="flex: 1; background-color: #f9f9f9; padding: 20px; border-radius: 5px;">
                        <h3 style="color: #333; margin-top: 0; border-bottom: 1px solid #ddd; padding-bottom: 8px;">
                            🚚 <?php _e('Shipping Address', 'wc-to-b2b'); ?>
                        </h3>
                        <div style="line-height: 1.6;">
                            <?php echo $shipping_address; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Customer Message -->
                <?php if ($message): ?>
                <div style="background-color: #fff3cd; padding: 20px; margin: 20px 0; border-radius: 5px; border-left: 4px solid #ffc107;">
                    <h3 style="color: #856404; margin-top: 0; border-bottom: 1px solid #ffeaa7; padding-bottom: 8px;">
                        💬 <?php _e('Customer Message & Requirements', 'wc-to-b2b'); ?>
                    </h3>
                    <div style="background: white; padding: 15px; border-radius: 3px; line-height: 1.6; color: #333;">
                        <?php echo nl2br(esc_html($message)); ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Action Instructions -->
                <div style="background-color: #d1ecf1; padding: 20px; margin: 30px 0; border-radius: 5px; border-left: 4px solid #17a2b8;">
                    <h3 style="color: #0c5460; margin-top: 0; border-bottom: 1px solid #bee5eb; padding-bottom: 8px;">
                        ⚡ <?php _e('Next Steps', 'wc-to-b2b'); ?>
                    </h3>
                    <ol style="color: #0c5460; line-height: 1.8; margin: 0; padding-left: 20px;">
                        <li><strong><?php _e('Review the order details above', 'wc-to-b2b'); ?></strong></li>
                        <li><strong><?php _e('Contact the customer using the provided email/phone', 'wc-to-b2b'); ?></strong></li>
                        <li><strong><?php _e('Discuss requirements and prepare your quote', 'wc-to-b2b'); ?></strong></li>
                        <li><strong><?php _e('Send quote via email or your preferred system', 'wc-to-b2b'); ?></strong></li>
                        <li><strong><?php _e('Update order status in WordPress when quote is sent', 'wc-to-b2b'); ?></strong></li>
                    </ol>
                </div>
                
                <!-- Quick Actions -->
                <div style="text-align: center; margin: 30px 0;">
                    <a href="mailto:<?php echo $order->get_billing_email(); ?>?subject=<?php echo urlencode(sprintf(__('Quote for Order #%s', 'wc-to-b2b'), $order->get_order_number())); ?>" 
                       style="background-color: #28a745; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; display: inline-block; margin: 0 10px;">
                        📧 <?php _e('Reply to Customer', 'wc-to-b2b'); ?>
                    </a>
                    
                    <a href="<?php echo admin_url('post.php?post=' . $order->get_id() . '&action=edit'); ?>" 
                       style="background-color: #0073aa; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; display: inline-block; margin: 0 10px;">
                        🔧 <?php _e('Manage in WordPress', 'wc-to-b2b'); ?>
                    </a>
                </div>
                
                <!-- Footer -->
                <hr style="margin: 30px 0; border: none; border-top: 1px solid #eee;">
                <p style="color: #666; font-size: 12px; text-align: center; margin: 0;">
                    <?php printf(__('This notification was sent from %s | Order Date: %s', 'wc-to-b2b'), 
                        get_bloginfo('name'), 
                        wc_format_datetime($order->get_date_created())
                    ); ?>
                </p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Get quote email template.
     */
    private function get_quote_email_template($order) {
        ob_start();
        
        $confirm_url = add_query_arg(array(
            'wc_b2b_action' => 'confirm',
            'order_id' => $order->get_id(),
            'nonce' => wp_create_nonce('wc_b2b_confirm_' . $order->get_id())
        ), home_url());
        
        $cancel_url = add_query_arg(array(
            'wc_b2b_action' => 'cancel',
            'order_id' => $order->get_id(),
            'nonce' => wp_create_nonce('wc_b2b_cancel_' . $order->get_id())
        ), home_url());
        ?>
        <div style="background-color: #f7f7f7; padding: 20px; font-family: Arial, sans-serif;">
            <div style="max-width: 600px; margin: 0 auto; background-color: white; padding: 30px; border-radius: 5px;">
                <h2 style="color: #333; margin-bottom: 20px;"><?php _e('Your Quote is Ready', 'wc-to-b2b'); ?></h2>
                
                <p><?php printf(__('Hello %s,', 'wc-to-b2b'), $order->get_billing_first_name()); ?></p>
                
                <p><?php printf(__('Your quote for order #%s is ready for review. Please find the details below:', 'wc-to-b2b'), $order->get_order_number()); ?></p>
                
                <div style="background-color: #f9f9f9; padding: 20px; margin: 20px 0; border-radius: 3px;">
                    <h3><?php _e('Order Summary:', 'wc-to-b2b'); ?></h3>
                    
                    <?php foreach ($order->get_items() as $item_id => $item): ?>
                    <?php 
                        $custom_price = wc_get_order_item_meta($item_id, '_wc_b2b_custom_price', true);
                        $unit_price = $custom_price ? $custom_price : ($item->get_total() / $item->get_quantity());
                    ?>
                    <div style="border-bottom: 1px solid #eee; padding: 10px 0;">
                        <strong><?php echo $item->get_name(); ?></strong><br>
                        <?php _e('Quantity:', 'wc-to-b2b'); ?> <?php echo $item->get_quantity(); ?><br>
                        <?php _e('Unit Price:', 'wc-to-b2b'); ?> <?php echo wc_price($unit_price); ?><br>
                        <?php _e('Total:', 'wc-to-b2b'); ?> <?php echo wc_price($item->get_total()); ?>
                        <?php if ($custom_price): ?>
                        <br><small style="color: #666; font-style: italic;"><?php _e('(Custom B2B Price)', 'wc-to-b2b'); ?></small>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    
                    <div style="margin-top: 20px; padding-top: 20px; border-top: 2px solid #0073aa;">
                        <p><strong><?php _e('Subtotal:', 'wc-to-b2b'); ?></strong> <?php echo wc_price($order->get_subtotal()); ?></p>
                        <p><strong><?php _e('Shipping:', 'wc-to-b2b'); ?></strong> <?php echo wc_price($order->get_shipping_total()); ?></p>
                        <p><strong><?php _e('Total:', 'wc-to-b2b'); ?></strong> <?php echo $order->get_formatted_order_total(); ?></p>
                    </div>
                </div>
                
                <p><?php _e('Please review the quote and choose one of the following options:', 'wc-to-b2b'); ?></p>
                
                <div style="text-align: center; margin: 30px 0;">
                    <a href="<?php echo esc_url($confirm_url); ?>" style="background-color: #28a745; color: white; padding: 12px 30px; text-decoration: none; border-radius: 3px; display: inline-block; margin-right: 10px;"><?php _e('Accept Quote', 'wc-to-b2b'); ?></a>
                    <a href="<?php echo esc_url($cancel_url); ?>" style="background-color: #dc3545; color: white; padding: 12px 30px; text-decoration: none; border-radius: 3px; display: inline-block;"><?php _e('Cancel Order', 'wc-to-b2b'); ?></a>
                </div>
                
                <p style="color: #666; font-size: 12px;"><?php _e('If you accept the quote, you will be redirected to complete the payment.', 'wc-to-b2b'); ?></p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Add email classes to WooCommerce.
     */
    public function add_email_classes($email_classes) {
        // We can add custom email classes here if needed
        return $email_classes;
    }
    
    /**
     * Send order cancelled email to customer.
     */
    public function send_order_cancelled_email($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }
        
        $to = $order->get_billing_email();
        $subject = sprintf(__('Order #%s has been cancelled', 'wc-to-b2b'), $order->get_order_number());
        
        $message = $this->get_order_cancelled_template($order);
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        return wp_mail($to, $subject, $message, $headers);
    }
    
    /**
     * Get order cancelled email template.
     */
    private function get_order_cancelled_template($order) {
        ob_start();
        ?>
        <div style="background-color: #f7f7f7; padding: 20px; font-family: Arial, sans-serif;">
            <div style="max-width: 600px; margin: 0 auto; background-color: white; padding: 30px; border-radius: 5px;">
                <h2 style="color: #d63638; margin-bottom: 20px;"><?php _e('Order Cancelled', 'wc-to-b2b'); ?></h2>
                
                <p><?php printf(__('Hello %s,', 'wc-to-b2b'), $order->get_billing_first_name()); ?></p>
                
                <p><?php printf(__('We regret to inform you that your order #%s has been cancelled due to non-payment within the specified time period.', 'wc-to-b2b'), $order->get_order_number()); ?></p>
                
                <div style="background-color: #f9f9f9; padding: 20px; margin: 20px 0; border-radius: 3px;">
                    <h3><?php _e('Cancelled Order Details:', 'wc-to-b2b'); ?></h3>
                    <p><strong><?php _e('Order Number:', 'wc-to-b2b'); ?></strong> #<?php echo $order->get_order_number(); ?></p>
                    <p><strong><?php _e('Order Date:', 'wc-to-b2b'); ?></strong> <?php echo wc_format_datetime($order->get_date_created()); ?></p>
                    <p><strong><?php _e('Total Amount:', 'wc-to-b2b'); ?></strong> <?php echo $order->get_formatted_order_total(); ?></p>
                </div>
                
                <p><?php _e('If you are still interested in these products, please feel free to place a new order or contact us directly.', 'wc-to-b2b'); ?></p>
                
                <div style="text-align: center; margin: 30px 0;">
                    <a href="<?php echo home_url(); ?>" style="background-color: #0073aa; color: white; padding: 12px 30px; text-decoration: none; border-radius: 3px; display: inline-block;"><?php _e('Continue Shopping', 'wc-to-b2b'); ?></a>
                </div>
                
                <p><?php _e('Thank you for your understanding.', 'wc-to-b2b'); ?></p>
                
                <hr style="margin: 30px 0; border: none; border-top: 1px solid #eee;">
                <p style="color: #666; font-size: 12px;"><?php _e('This is an automated notification about your order cancellation.', 'wc-to-b2b'); ?></p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Add email actions to WooCommerce.
     */
    public function add_email_actions($email_actions) {
        $email_actions[] = 'wc_b2b_send_verification_email';
        $email_actions[] = 'wc_b2b_send_admin_notification';
        $email_actions[] = 'wc_b2b_send_quote_email';
        $email_actions[] = 'wc_b2b_send_order_cancelled_email';
        
        return $email_actions;
    }
}