<?php
/**
 * B2B membership levels and tier pricing.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_B2B_Membership {

    const USER_META_KEY = '_wc_b2b_tier';
    const REGISTERED_TIER = 'registered';
    const REGULAR_TIER    = 'regular';
    const VIP_TIER        = 'vip';

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('show_user_profile', array($this, 'render_user_tier_field'));
        add_action('edit_user_profile', array($this, 'render_user_tier_field'));
        add_action('personal_options_update', array($this, 'save_user_tier_field'));
        add_action('edit_user_profile_update', array($this, 'save_user_tier_field'));

        add_action('woocommerce_product_options_pricing', array($this, 'render_product_prices'));
        add_action('woocommerce_process_product_meta', array($this, 'save_product_prices'));
        add_action('woocommerce_variation_options_pricing', array($this, 'render_variation_prices'), 10, 3);
        add_action('woocommerce_save_product_variation', array($this, 'save_variation_prices'), 10, 2);

        add_filter('woocommerce_product_get_price', array($this, 'filter_product_price'), 99, 2);
        add_filter('woocommerce_product_get_sale_price', array($this, 'filter_product_price'), 99, 2);
        add_filter('woocommerce_product_variation_get_price', array($this, 'filter_product_price'), 99, 2);
        add_filter('woocommerce_product_variation_get_sale_price', array($this, 'filter_product_price'), 99, 2);
        add_filter('woocommerce_variation_prices_price', array($this, 'filter_variation_price'), 99, 3);
        add_filter('woocommerce_variation_prices_sale_price', array($this, 'filter_variation_price'), 99, 3);
        add_filter('woocommerce_get_variation_prices_hash', array($this, 'variation_prices_hash'), 99, 3);
        add_filter('woocommerce_get_price_html', array($this, 'add_tier_label_to_price'), 99, 2);
        add_filter('woocommerce_cart_item_price', array($this, 'hide_guest_cart_amount'), 99, 3);
        add_filter('woocommerce_cart_item_subtotal', array($this, 'hide_guest_cart_amount'), 99, 3);
        add_filter('woocommerce_cart_subtotal', array($this, 'hide_guest_cart_total'), 99, 3);
        add_filter('woocommerce_cart_totals_order_total_html', array($this, 'hide_guest_total_html'), 99, 1);
        add_filter('woocommerce_cart_totals_fee_html', array($this, 'hide_guest_cart_amount'), 99, 2);
        add_filter('woocommerce_cart_totals_taxes_total_html', array($this, 'hide_guest_cart_amount'), 99, 2);
        add_filter('woocommerce_cart_shipping_method_full_label', array($this, 'hide_guest_shipping_amount'), 99, 2);
        add_filter('woocommerce_widget_cart_item_quantity', array($this, 'hide_guest_widget_price'), 99, 3);
        add_filter('woocommerce_order_formatted_line_subtotal', array($this, 'hide_guest_order_line_total'), 99, 3);
        add_filter('woocommerce_get_order_item_totals', array($this, 'hide_guest_order_totals'), 99, 3);
        add_filter('woocommerce_structured_data_product', array($this, 'hide_guest_structured_prices'), 99, 2);
        add_filter('woocommerce_product_add_to_cart_text', array($this, 'guest_add_to_cart_text'), 99, 2);
        add_filter('woocommerce_product_single_add_to_cart_text', array($this, 'guest_add_to_cart_text'), 99, 2);
        add_filter('body_class', array($this, 'add_price_visibility_body_class'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_styles'));

        add_action('woocommerce_checkout_create_order', array($this, 'store_tier_on_order'), 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'store_tier_on_order_item'), 10, 4);
        add_action('woocommerce_store_api_checkout_order_processed', array($this, 'store_tier_on_store_api_order'), 4, 1);
    }

    public static function get_tiers() {
        $tiers = get_option('wc_b2b_membership_tiers', array());
        $tiers = is_array($tiers) ? $tiers : array();
        $by_id = array();
        foreach ($tiers as $tier) {
            $id = sanitize_key($tier['id'] ?? '');
            if ($id) {
                $by_id[$id] = $tier;
            }
        }

        $regular = $by_id[self::REGULAR_TIER] ?? ($by_id['silver'] ?? array());
        $vip     = $by_id[self::VIP_TIER] ?? ($by_id['gold'] ?? array());

        return array(
            self::REGISTERED_TIER => array(
                'id'       => self::REGISTERED_TIER,
                'name'     => __('Registered Customer', 'wc-to-b2b'),
                'discount' => 0,
            ),
            self::REGULAR_TIER => array(
                'id'       => self::REGULAR_TIER,
                'name'     => __('Regular Customer', 'wc-to-b2b'),
                'discount' => min(100, max(0, (float) ($regular['discount'] ?? 10))),
            ),
            self::VIP_TIER => array(
                'id'       => self::VIP_TIER,
                'name'     => __('VIP Customer', 'wc-to-b2b'),
                'discount' => min(100, max(0, (float) ($vip['discount'] ?? 20))),
            ),
        );
    }

    public static function get_user_tier($user_id = 0) {
        $user_id = $user_id ?: get_current_user_id();
        if (!$user_id) {
            return null;
        }

        if (class_exists('WC_B2B_Registration') && !WC_B2B_Registration::is_user_verified($user_id)) {
            return null;
        }

        $tier_id = sanitize_key(get_user_meta($user_id, self::USER_META_KEY, true));
        $aliases = array('standard' => self::REGISTERED_TIER, 'silver' => self::REGULAR_TIER, 'gold' => self::VIP_TIER);
        $tier_id = $aliases[$tier_id] ?? $tier_id;
        if (!$tier_id) {
            $tier_id = self::REGISTERED_TIER;
        }
        $tiers   = self::get_tiers();
        return isset($tiers[$tier_id]) ? $tiers[$tier_id] : $tiers[self::REGISTERED_TIER];
    }

    public static function are_catalog_prices_hidden() {
        if (!is_user_logged_in() || (class_exists('WC_B2B_Registration') && !WC_B2B_Registration::is_user_verified(get_current_user_id()))) {
            return !self::guest_prices_are_visible();
        }
        return false;
    }

    public static function guest_prices_are_visible() {
        return 'show' === get_option('wc_b2b_guest_price_display', 'hide');
    }

    public static function guest_inquiries_are_enabled() {
        return 'account_required' !== get_option('wc_b2b_customer_access_mode', 'guest_inquiry');
    }

    public static function can_display_order_prices($order) {
        if (!self::is_guest_inquiry($order)) {
            return true;
        }

        return self::guest_prices_are_visible() || self::has_formal_quote($order);
    }

    public static function is_guest_inquiry($order) {
        return $order instanceof WC_Order
            && 'yes' === $order->get_meta('_is_b2b_order', true)
            && ('yes' === $order->get_meta('_wc_b2b_guest_inquiry', true) || !$order->get_customer_id());
    }

    public static function has_formal_quote($order) {
        return $order instanceof WC_Order
            && (bool) $order->get_meta('_wc_b2b_quote_number', true);
    }

    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('B2B Member Levels', 'wc-to-b2b'),
            __('B2B Member Levels', 'wc-to-b2b'),
            'manage_woocommerce',
            'wc-b2b-member-levels',
            array($this, 'render_tiers_page')
        );
    }

    public function render_tiers_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to manage B2B member levels.', 'wc-to-b2b'));
        }

        if (isset($_POST['wc_b2b_save_tiers'])) {
            check_admin_referer('wc_b2b_save_tiers');
            $this->save_tiers();
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Member levels saved.', 'wc-to-b2b') . '</p></div>';
        }

        $tiers = array_values(self::get_tiers());
        $show_guest_prices = self::guest_prices_are_visible();
        $access_mode = self::guest_inquiries_are_enabled() ? 'guest_inquiry' : 'account_required';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('B2B Member Levels', 'wc-to-b2b'); ?></h1>
            <p><?php esc_html_e('Every verified account has one of three fixed levels: registered customers see retail prices, regular customers see wholesale prices, and VIP customers see VIP prices.', 'wc-to-b2b'); ?></p>
            <form method="post">
                <?php wp_nonce_field('wc_b2b_save_tiers'); ?>
                <table class="form-table"><tr>
                    <th scope="row"><?php esc_html_e('Customer Access Mode', 'wc-to-b2b'); ?></th>
                    <td>
                        <fieldset>
                            <label><input type="radio" name="wc_b2b_customer_access_mode" value="guest_inquiry" <?php checked($access_mode, 'guest_inquiry'); ?> /> <?php esc_html_e('Allow guests to submit an inquiry after verifying their email', 'wc-to-b2b'); ?></label><br>
                            <label><input type="radio" name="wc_b2b_customer_access_mode" value="account_required" <?php checked($access_mode, 'account_required'); ?> /> <?php esc_html_e('Require customers to register, verify their email, and sign in before checkout', 'wc-to-b2b'); ?></label>
                        </fieldset>
                        <p class="description"><?php esc_html_e('All submissions wait for an administrator to adjust prices and shipping and send the formal quote manually.', 'wc-to-b2b'); ?></p>
                    </td>
                </tr><tr>
                    <th scope="row"><?php esc_html_e('Guest Price Display', 'wc-to-b2b'); ?></th>
                    <td>
                        <label><input type="checkbox" name="wc_b2b_show_guest_prices" value="yes" <?php checked($show_guest_prices); ?> /> <?php esc_html_e('Show WooCommerce retail prices to visitors who are not signed in', 'wc-to-b2b'); ?></label>
                        <p class="description"><?php esc_html_e('When disabled, catalog, cart, checkout, verification emails, and pre-quote inquiry pages hide all amounts. If guest inquiries are allowed, every guest must verify the submitted email before the inquiry is delivered.', 'wc-to-b2b'); ?></p>
                    </td>
                </tr></table>
                <table class="widefat striped" id="wc-b2b-tier-table">
                    <thead><tr>
                        <th><?php esc_html_e('Level ID', 'wc-to-b2b'); ?></th>
                        <th><?php esc_html_e('Level Name', 'wc-to-b2b'); ?></th>
                        <th><?php esc_html_e('Default Discount (%)', 'wc-to-b2b'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($tiers as $index => $tier) : ?>
                        <?php $this->render_tier_row($index, $tier); ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php submit_button(__('Save Member Levels', 'wc-to-b2b'), 'primary', 'wc_b2b_save_tiers'); ?>
            </form>
            <p class="description"><?php esc_html_e('Registered customers use each product or variation\'s WooCommerce retail price. Regular and VIP fixed prices can be entered separately on every product or variation; when blank, the level discount above is used.', 'wc-to-b2b'); ?></p>
        </div>
        <?php
    }

    private function render_tier_row($index, $tier) {
        ?>
        <tr>
            <td><code><?php echo esc_html($tier['id']); ?></code><input type="hidden" name="tiers[<?php echo esc_attr($index); ?>][id]" value="<?php echo esc_attr($tier['id']); ?>" /></td>
            <td><?php echo esc_html($tier['name']); ?></td>
            <td><input type="number" min="0" max="100" step="0.01" name="tiers[<?php echo esc_attr($index); ?>][discount]" value="<?php echo esc_attr($tier['discount']); ?>" <?php disabled(self::REGISTERED_TIER === $tier['id']); ?> /><?php if (self::REGISTERED_TIER === $tier['id']) : ?><input type="hidden" name="tiers[<?php echo esc_attr($index); ?>][discount]" value="0" /> <span class="description"><?php esc_html_e('Always retail price', 'wc-to-b2b'); ?></span><?php endif; ?></td>
        </tr>
        <?php
    }

    private function save_tiers() {
        $posted = isset($_POST['tiers']) && is_array($_POST['tiers']) ? wp_unslash($_POST['tiers']) : array();
        $tiers  = array();

        $current = self::get_tiers();
        foreach (array(self::REGISTERED_TIER, self::REGULAR_TIER, self::VIP_TIER) as $index => $id) {
            $tier = $posted[$index] ?? array();
            $tiers[$id] = array(
                'id'       => $id,
                'name'     => $current[$id]['name'],
                'discount' => self::REGISTERED_TIER === $id ? 0 : min(100, max(0, (float) ($tier['discount'] ?? $current[$id]['discount']))),
            );
        }

        update_option('wc_b2b_membership_tiers', array_values($tiers));
        update_option('wc_b2b_guest_price_display', isset($_POST['wc_b2b_show_guest_prices']) ? 'show' : 'hide');
        $access_mode = isset($_POST['wc_b2b_customer_access_mode']) ? sanitize_key(wp_unslash($_POST['wc_b2b_customer_access_mode'])) : 'guest_inquiry';
        $access_mode = 'account_required' === $access_mode ? 'account_required' : 'guest_inquiry';
        update_option('wc_b2b_customer_access_mode', $access_mode);

        // Keep legacy settings deterministic for installations upgraded from 2.0.
        update_option('wc_b2b_require_account', 'account_required' === $access_mode ? 'yes' : 'no');
        update_option('wc_b2b_verify_guests', 'yes');
        update_option('wc_b2b_auto_quote', 'no');
    }

    public function render_user_tier_field($user) {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $selected = sanitize_key(get_user_meta($user->ID, self::USER_META_KEY, true));
        $selected = array('standard' => self::REGISTERED_TIER, 'silver' => self::REGULAR_TIER, 'gold' => self::VIP_TIER)[$selected] ?? $selected;
        $selected = $selected ?: self::REGISTERED_TIER;
        ?>
        <h2><?php esc_html_e('B2B Membership', 'wc-to-b2b'); ?></h2>
        <table class="form-table"><tr>
            <th><label for="wc_b2b_tier"><?php esc_html_e('Member Level', 'wc-to-b2b'); ?></label></th>
            <td><select name="wc_b2b_tier" id="wc_b2b_tier">
                <?php foreach (self::get_tiers() as $tier) : ?>
                    <option value="<?php echo esc_attr($tier['id']); ?>" <?php selected($selected, $tier['id']); ?>><?php echo esc_html($tier['name']); ?></option>
                <?php endforeach; ?>
            </select></td>
        </tr></table>
        <?php
    }

    public function save_user_tier_field($user_id) {
        if (!current_user_can('manage_woocommerce') || !current_user_can('edit_user', $user_id)) {
            return;
        }
        $tier_id = isset($_POST['wc_b2b_tier']) ? sanitize_key(wp_unslash($_POST['wc_b2b_tier'])) : '';
        $tiers   = self::get_tiers();
        update_user_meta($user_id, self::USER_META_KEY, isset($tiers[$tier_id]) ? $tier_id : self::REGISTERED_TIER);
    }

    public function render_product_prices() {
        foreach (self::get_tiers() as $tier) {
            if (self::REGISTERED_TIER === $tier['id']) {
                continue;
            }
            woocommerce_wp_text_input(array(
                'id'                => $this->price_meta_key($tier['id']),
                'label'             => sprintf(__('%s fixed price', 'wc-to-b2b'), $tier['name']),
                'description'       => __('Leave blank to use this level\'s default discount.', 'wc-to-b2b'),
                'desc_tip'          => true,
                'type'              => 'number',
                'custom_attributes' => array('step' => 'any', 'min' => '0'),
                'data_type'         => 'price',
            ));
        }
    }

    public function save_product_prices($product_id) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }
        foreach (self::get_tiers() as $tier) {
            if (self::REGISTERED_TIER === $tier['id']) {
                continue;
            }
            $key   = $this->price_meta_key($tier['id']);
            $value = isset($_POST[$key]) ? wc_format_decimal(wp_unslash($_POST[$key])) : '';
            if ('' === $value) {
                $product->delete_meta_data($key);
            } else {
                $product->update_meta_data($key, $value);
            }
        }
        $product->save_meta_data();
    }

    public function render_variation_prices($loop, $variation_data, $variation) {
        foreach (self::get_tiers() as $tier) {
            if (self::REGISTERED_TIER === $tier['id']) {
                continue;
            }
            $key = $this->price_meta_key($tier['id']);
            woocommerce_wp_text_input(array(
                'id'                => $key . '_' . $loop,
                'name'              => $key . '[' . $loop . ']',
                'value'             => $this->get_saved_tier_price($variation->ID, $tier['id']),
                'label'             => sprintf(__('%s fixed price', 'wc-to-b2b'), $tier['name']),
                'wrapper_class'     => 'form-row form-row-full',
                'type'              => 'number',
                'custom_attributes' => array('step' => 'any', 'min' => '0'),
                'data_type'         => 'price',
            ));
        }
    }

    public function save_variation_prices($variation_id, $loop) {
        $product = wc_get_product($variation_id);
        if (!$product) {
            return;
        }
        foreach (self::get_tiers() as $tier) {
            if (self::REGISTERED_TIER === $tier['id']) {
                continue;
            }
            $key    = $this->price_meta_key($tier['id']);
            $values = isset($_POST[$key]) && is_array($_POST[$key]) ? wp_unslash($_POST[$key]) : array();
            $value  = isset($values[$loop]) ? wc_format_decimal($values[$loop]) : '';
            if ('' === $value) {
                $product->delete_meta_data($key);
            } else {
                $product->update_meta_data($key, $value);
            }
        }
        $product->save_meta_data();
    }

    public function filter_product_price($price, $product) {
        if (is_admin() && !wp_doing_ajax()) {
            return $price;
        }
        $tier = self::get_user_tier();
        return $tier ? $this->calculate_tier_price($product, $tier, $price) : $price;
    }

    public function filter_variation_price($price, $variation, $product) {
        if (is_admin() && !wp_doing_ajax()) {
            return $price;
        }
        $tier = self::get_user_tier();
        return $tier ? $this->calculate_tier_price($variation, $tier, $price) : $price;
    }

    private function calculate_tier_price($product, $tier, $base_price) {
        if ('' === $base_price || !is_numeric($base_price)) {
            return $base_price;
        }

        if (self::REGISTERED_TIER === $tier['id']) {
            return $base_price;
        }

        $fixed = $product->get_meta($this->price_meta_key($tier['id']), true);
        if ('' === $fixed) {
            $fixed = $product->get_meta($this->legacy_price_meta_key($tier['id']), true);
        }
        if ('' === $fixed && $product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            $fixed  = $parent ? $parent->get_meta($this->price_meta_key($tier['id']), true) : '';
            if ('' === $fixed && $parent) {
                $fixed = $parent->get_meta($this->legacy_price_meta_key($tier['id']), true);
            }
        }
        if ('' !== $fixed && is_numeric($fixed)) {
            return (string) max(0, (float) $fixed);
        }

        $discount = min(100, max(0, (float) $tier['discount']));
        return (string) round((float) $base_price * (1 - ($discount / 100)), wc_get_price_decimals());
    }

    public function variation_prices_hash($hash, $product, $for_display) {
        $tier = self::get_user_tier();
        $hash['wc_b2b_tier'] = self::are_catalog_prices_hidden() ? 'hidden' : ($tier ? $tier['id'] . ':' . $tier['discount'] : 'retail');
        return $hash;
    }

    public function add_tier_label_to_price($html, $product) {
        if (self::are_catalog_prices_hidden() && !is_admin()) {
            return $this->hidden_price_html();
        }
        $tier = self::get_user_tier();
        if (!$tier || is_admin()) {
            return $html;
        }
        return $html . ' <small class="wc-b2b-tier-label">' . esc_html($tier['name']) . '</small>';
    }

    public function hide_guest_cart_amount($html) {
        return self::are_catalog_prices_hidden() ? $this->hidden_price_text() : $html;
    }

    public function hide_guest_cart_total($html) {
        return self::are_catalog_prices_hidden() ? $this->hidden_price_text() : $html;
    }

    public function hide_guest_total_html($html) {
        return self::are_catalog_prices_hidden() ? $this->hidden_price_text() : $html;
    }

    public function hide_guest_shipping_amount($label, $method) {
        return self::are_catalog_prices_hidden() && is_object($method) && method_exists($method, 'get_label') ? esc_html($method->get_label()) : $label;
    }

    public function hide_guest_widget_price($html, $cart_item, $cart_item_key) {
        if (!self::are_catalog_prices_hidden()) {
            return $html;
        }
        return '<span class="quantity">' . absint($cart_item['quantity'] ?? 0) . ' &times; ' . esc_html($this->hidden_price_text()) . '</span>';
    }

    public function hide_guest_order_line_total($subtotal, $item, $order) {
        return self::can_display_order_prices($order) ? $subtotal : $this->hidden_price_text();
    }

    public function hide_guest_order_totals($totals, $order, $tax_display) {
        if (self::can_display_order_prices($order)) {
            return $totals;
        }

        foreach (array('cart_subtotal', 'discount', 'shipping', 'fee', 'tax', 'order_total') as $key) {
            if (isset($totals[$key])) {
                $totals[$key]['value'] = $this->hidden_price_text();
            }
        }
        return $totals;
    }

    public function hide_guest_structured_prices($markup, $product) {
        if (self::are_catalog_prices_hidden()) {
            unset($markup['offers']);
        }
        return $markup;
    }

    public function guest_add_to_cart_text($text, $product = null) {
        $is_unverified = is_user_logged_in() && class_exists('WC_B2B_Registration') && !WC_B2B_Registration::is_user_verified(get_current_user_id());
        if (!is_user_logged_in() || $is_unverified) {
            return self::guest_inquiries_are_enabled() ? __('Add to Inquiry', 'wc-to-b2b') : __('Add to Quote', 'wc-to-b2b');
        }
        return $text;
    }

    public function add_price_visibility_body_class($classes) {
        $formal_guest_view = false;
        if (isset($_GET['wc_b2b_action'], $_GET['order_id']) && 'view' === sanitize_key(wp_unslash($_GET['wc_b2b_action']))) {
            $view_order = wc_get_order(absint($_GET['order_id']));
            $formal_guest_view = $view_order && self::can_display_order_prices($view_order);
        }
        if (self::are_catalog_prices_hidden() && !$formal_guest_view) {
            $classes[] = 'wc-b2b-prices-hidden';
        }
        return $classes;
    }

    public function enqueue_frontend_styles() {
        wp_enqueue_style('wc-b2b-frontend', WC_TO_B2B_PLUGIN_URL . 'assets/css/frontend.css', array(), WC_TO_B2B_VERSION);
    }

    private function hidden_price_html() {
        $account_url = wc_get_page_permalink('myaccount');
        return '<span class="wc-b2b-price-login"><a href="' . esc_url($account_url) . '">' . esc_html__('Register or sign in to view prices', 'wc-to-b2b') . '</a></span>';
    }

    private function hidden_price_text() {
        return __('Price provided after quotation', 'wc-to-b2b');
    }

    public function store_tier_on_order($order, $data) {
        $tier = self::get_user_tier($order->get_customer_id());
        if ($tier) {
            $order->update_meta_data('_wc_b2b_tier_id', $tier['id']);
            $order->update_meta_data('_wc_b2b_tier_name', $tier['name']);
        }
    }

    public function store_tier_on_store_api_order($order) {
        if ($order instanceof WC_Order) {
            $this->store_tier_on_order($order, array());
            $order->save();
        }
    }

    public function store_tier_on_order_item($item, $cart_item_key, $values, $order) {
        $tier = self::get_user_tier($order->get_customer_id());
        if ($tier) {
            $item->add_meta_data('_wc_b2b_tier', $tier['name'], true);
            $item->add_meta_data('_wc_b2b_unit_price', $values['data']->get_price(), true);
        }
    }

    private function price_meta_key($tier_id) {
        return '_wc_b2b_price_' . sanitize_key($tier_id);
    }

    private function legacy_price_meta_key($tier_id) {
        return self::REGULAR_TIER === $tier_id ? '_wc_b2b_price_silver' : '_wc_b2b_price_gold';
    }

    private function get_saved_tier_price($product_id, $tier_id) {
        $value = get_post_meta($product_id, $this->price_meta_key($tier_id), true);
        return '' !== $value ? $value : get_post_meta($product_id, $this->legacy_price_meta_key($tier_id), true);
    }
}
