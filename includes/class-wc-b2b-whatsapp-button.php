<?php
/**
 * WhatsApp Quick Buy Button functionality.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WC_B2B_WhatsApp_Button Class.
 */
class WC_B2B_WhatsApp_Button {
    
    /**
     * Constructor.
     */
    public function __construct() {
        // Replace add to cart buttons with WhatsApp buttons
        add_action('init', array($this, 'init_whatsapp_buttons'));
        
        // Enqueue frontend styles and scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        
        // Add WhatsApp button settings
        add_filter('woocommerce_get_settings_products', array($this, 'add_whatsapp_settings'), 10, 2);
        
        // AJAX handler for WhatsApp message generation
        add_action('wp_ajax_wc_b2b_generate_whatsapp_message', array($this, 'ajax_generate_whatsapp_message'));
        add_action('wp_ajax_nopriv_wc_b2b_generate_whatsapp_message', array($this, 'ajax_generate_whatsapp_message'));
    }
    
    /**
     * Initialize WhatsApp buttons.
     */
    public function init_whatsapp_buttons() {
        if (get_option('wc_b2b_enable_whatsapp_button', 'no') === 'yes') {
            // Remove default add to cart buttons
            remove_action('woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10);
            remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
            
            // Add WhatsApp buttons
            add_action('woocommerce_after_shop_loop_item', array($this, 'add_whatsapp_button_loop'), 10);
            add_action('woocommerce_single_product_summary', array($this, 'add_whatsapp_button_single'), 30);
        }
    }
    
    /**
     * Add WhatsApp button in product loop.
     */
    public function add_whatsapp_button_loop() {
        global $product;
        
        if (!$product) {
            return;
        }
        
        $this->render_whatsapp_button($product, 'loop');
    }
    
    /**
     * Add WhatsApp button on single product page.
     */
    public function add_whatsapp_button_single() {
        global $product;
        
        if (!$product) {
            return;
        }
        
        $this->render_whatsapp_button($product, 'single');
    }
    
    /**
     * Render WhatsApp button.
     */
    private function render_whatsapp_button($product, $context = 'single') {
        $phone_number = get_option('wc_b2b_whatsapp_phone', '');
        if (empty($phone_number)) {
            return;
        }
        
        $button_text = get_option('wc_b2b_whatsapp_button_text', __('Order via WhatsApp', 'wc-to-b2b'));
        $custom_message = get_option('wc_b2b_whatsapp_custom_message', '');
        
        $product_id = $product->get_id();
        $product_name = $product->get_name();
        $product_price = $product->get_price_html();
        $product_url = get_permalink($product_id);
        
        // Generate default message
        $default_message = sprintf(
            __("Hello! I'm interested in this product:\n\n*%s*\nPrice: %s\nLink: %s\n\nCould you please provide more information?", 'wc-to-b2b'),
            $product_name,
            strip_tags($product_price),
            $product_url
        );
        
        $message = !empty($custom_message) ? $custom_message : $default_message;
        
        // Replace placeholders
        $message = str_replace(
            array('{product_name}', '{product_price}', '{product_url}', '{site_name}'),
            array($product_name, strip_tags($product_price), $product_url, get_bloginfo('name')),
            $message
        );
        
        $whatsapp_url = 'https://wa.me/' . $this->format_phone_number($phone_number) . '?text=' . urlencode($message);
        
        $button_class = $context === 'loop' ? 'wc-b2b-whatsapp-button-loop' : 'wc-b2b-whatsapp-button-single';
        ?>
        <div class="wc-b2b-whatsapp-wrapper">
            <a href="<?php echo esc_url($whatsapp_url); ?>" 
               class="wc-b2b-whatsapp-button <?php echo esc_attr($button_class); ?>" 
               target="_blank" 
               rel="noopener noreferrer"
               data-product-id="<?php echo esc_attr($product_id); ?>">
                <svg class="wc-b2b-whatsapp-icon" viewBox="0 0 24 24" width="20" height="20">
                    <path fill="currentColor" d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893A11.821 11.821 0 0020.465 3.516"/>
                </svg>
                <span><?php echo esc_html($button_text); ?></span>
            </a>
        </div>
        <?php
    }
    
    /**
     * Format phone number for WhatsApp.
     */
    private function format_phone_number($phone) {
        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Remove leading zeros
        $phone = ltrim($phone, '0');
        
        return $phone;
    }
    
    /**
     * Enqueue frontend assets.
     */
    public function enqueue_frontend_assets() {
        if (get_option('wc_b2b_enable_whatsapp_button', 'no') === 'yes') {
            wp_enqueue_style(
                'wc-b2b-whatsapp-button',
                WC_TO_B2B_PLUGIN_URL . 'assets/css/whatsapp-button.css',
                array(),
                WC_TO_B2B_VERSION
            );
            
            wp_enqueue_script(
                'wc-b2b-whatsapp-button',
                WC_TO_B2B_PLUGIN_URL . 'assets/js/whatsapp-button.js',
                array('jquery'),
                WC_TO_B2B_VERSION,
                true
            );
            
            wp_localize_script('wc-b2b-whatsapp-button', 'wc_b2b_whatsapp', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wc_b2b_whatsapp'),
                'phone' => get_option('wc_b2b_whatsapp_phone', ''),
                'messages' => array(
                    'error' => __('Error generating WhatsApp message.', 'wc-to-b2b')
                )
            ));
        }
    }
    
    /**
     * Add WhatsApp settings to WooCommerce.
     */
    public function add_whatsapp_settings($settings, $current_section) {
        if ($current_section === 'wc_b2b_whatsapp') {
            $whatsapp_settings = array(
                array(
                    'name' => __('WhatsApp Quick Buy Settings', 'wc-to-b2b'),
                    'type' => 'title',
                    'desc' => __('Configure WhatsApp quick buy button settings.', 'wc-to-b2b'),
                    'id'   => 'wc_b2b_whatsapp_options'
                ),
                
                array(
                    'name'    => __('Enable WhatsApp Button', 'wc-to-b2b'),
                    'desc'    => __('Replace add to cart buttons with WhatsApp buttons', 'wc-to-b2b'),
                    'id'      => 'wc_b2b_enable_whatsapp_button',
                    'type'    => 'checkbox',
                    'default' => 'no'
                ),
                
                array(
                    'name'        => __('WhatsApp Phone Number', 'wc-to-b2b'),
                    'desc'        => __('Enter your WhatsApp business phone number (with country code, no + sign)', 'wc-to-b2b'),
                    'id'          => 'wc_b2b_whatsapp_phone',
                    'type'        => 'text',
                    'placeholder' => '1234567890'
                ),
                
                array(
                    'name'        => __('Button Text', 'wc-to-b2b'),
                    'desc'        => __('Text displayed on the WhatsApp button', 'wc-to-b2b'),
                    'id'          => 'wc_b2b_whatsapp_button_text',
                    'type'        => 'text',
                    'default'     => __('Order via WhatsApp', 'wc-to-b2b')
                ),
                
                array(
                    'name'        => __('Custom Message Template', 'wc-to-b2b'),
                    'desc'        => __('Custom message template. Use {product_name}, {product_price}, {product_url}, {site_name} as placeholders. Leave empty for default message.', 'wc-to-b2b'),
                    'id'          => 'wc_b2b_whatsapp_custom_message',
                    'type'        => 'textarea',
                    'css'         => 'width: 100%; height: 100px;',
                    'placeholder' => __("Hello! I'm interested in {product_name} from {site_name}. Could you provide more details?", 'wc-to-b2b')
                ),
                
                array(
                    'name'    => __('Button Style', 'wc-to-b2b'),
                    'desc'    => __('Choose the WhatsApp button style', 'wc-to-b2b'),
                    'id'      => 'wc_b2b_whatsapp_button_style',
                    'type'    => 'select',
                    'options' => array(
                        'official' => __('Official WhatsApp Style', 'wc-to-b2b'),
                        'custom'   => __('Custom Style', 'wc-to-b2b')
                    ),
                    'default' => 'official'
                ),
                
                array(
                    'type' => 'sectionend',
                    'id'   => 'wc_b2b_whatsapp_options'
                )
            );
            
            return $whatsapp_settings;
        }
        
        return $settings;
    }
    
    /**
     * AJAX generate WhatsApp message.
     */
    public function ajax_generate_whatsapp_message() {
        check_ajax_referer('wc_b2b_whatsapp', 'nonce');
        
        $product_id = intval($_POST['product_id']);
        $product = wc_get_product($product_id);
        
        if (!$product) {
            wp_send_json_error(__('Product not found.', 'wc-to-b2b'));
        }
        
        $phone_number = get_option('wc_b2b_whatsapp_phone', '');
        if (empty($phone_number)) {
            wp_send_json_error(__('WhatsApp phone number not configured.', 'wc-to-b2b'));
        }
        
        $custom_message = get_option('wc_b2b_whatsapp_custom_message', '');
        $product_name = $product->get_name();
        $product_price = $product->get_price_html();
        $product_url = get_permalink($product_id);
        
        // Generate message
        if (!empty($custom_message)) {
            $message = str_replace(
                array('{product_name}', '{product_price}', '{product_url}', '{site_name}'),
                array($product_name, strip_tags($product_price), $product_url, get_bloginfo('name')),
                $custom_message
            );
        } else {
            $message = sprintf(
                __("Hello! I'm interested in this product:\n\n*%s*\nPrice: %s\nLink: %s\n\nCould you please provide more information?", 'wc-to-b2b'),
                $product_name,
                strip_tags($product_price),
                $product_url
            );
        }
        
        $whatsapp_url = 'https://wa.me/' . $this->format_phone_number($phone_number) . '?text=' . urlencode($message);
        
        wp_send_json_success(array(
            'url' => $whatsapp_url,
            'message' => $message
        ));
    }
}