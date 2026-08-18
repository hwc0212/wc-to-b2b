<?php
/**
 * B2B membership levels and tier pricing.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_B2B_Membership {

    const USER_META_KEY = '_wc_b2b_tier';

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

        add_action('woocommerce_checkout_create_order', array($this, 'store_tier_on_order'), 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'store_tier_on_order_item'), 10, 4);
        add_action('woocommerce_store_api_checkout_order_processed', array($this, 'store_tier_on_store_api_order'), 4, 1);
    }

    public static function get_tiers() {
        $tiers = get_option('wc_b2b_membership_tiers', array());
        if (!is_array($tiers)) {
            return array();
        }

        $clean = array();
        foreach ($tiers as $tier) {
            if (empty($tier['id']) || empty($tier['name'])) {
                continue;
            }
            $id = sanitize_key($tier['id']);
            if (!$id) {
                continue;
            }
            $clean[$id] = array(
                'id'       => $id,
                'name'     => sanitize_text_field($tier['name']),
                'discount' => min(100, max(0, (float) ($tier['discount'] ?? 0))),
            );
        }
        return $clean;
    }

    public static function get_user_tier($user_id = 0) {
        $user_id = $user_id ?: get_current_user_id();
        if (!$user_id) {
            return null;
        }

        $tier_id = sanitize_key(get_user_meta($user_id, self::USER_META_KEY, true));
        $tiers   = self::get_tiers();
        return isset($tiers[$tier_id]) ? $tiers[$tier_id] : null;
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
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('B2B Member Levels', 'wc-to-b2b'); ?></h1>
            <p><?php esc_html_e('Assign a level to each WordPress user. Product-level fixed prices override the level discount.', 'wc-to-b2b'); ?></p>
            <form method="post">
                <?php wp_nonce_field('wc_b2b_save_tiers'); ?>
                <table class="widefat striped" id="wc-b2b-tier-table">
                    <thead><tr>
                        <th><?php esc_html_e('Level ID', 'wc-to-b2b'); ?></th>
                        <th><?php esc_html_e('Level Name', 'wc-to-b2b'); ?></th>
                        <th><?php esc_html_e('Default Discount (%)', 'wc-to-b2b'); ?></th>
                        <th><?php esc_html_e('Action', 'wc-to-b2b'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($tiers as $index => $tier) : ?>
                        <?php $this->render_tier_row($index, $tier); ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p><button type="button" class="button" id="wc-b2b-add-tier"><?php esc_html_e('Add Member Level', 'wc-to-b2b'); ?></button></p>
                <?php submit_button(__('Save Member Levels', 'wc-to-b2b'), 'primary', 'wc_b2b_save_tiers'); ?>
            </form>
            <p class="description"><?php esc_html_e('After saving, edit users to assign a level, then edit products to enter optional fixed prices for each level.', 'wc-to-b2b'); ?></p>
        </div>
        <script>
        (function() {
            var table = document.querySelector('#wc-b2b-tier-table tbody');
            var add = document.getElementById('wc-b2b-add-tier');
            if (!table || !add) return;
            add.addEventListener('click', function() {
                var index = 'new_' + Date.now();
                var row = document.createElement('tr');
                row.innerHTML = '<td><input required pattern="[a-z0-9_-]+" name="tiers[' + index + '][id]" /></td>' +
                    '<td><input required class="regular-text" name="tiers[' + index + '][name]" /></td>' +
                    '<td><input type="number" min="0" max="100" step="0.01" name="tiers[' + index + '][discount]" value="0" /></td>' +
                    '<td><button type="button" class="button-link-delete wc-b2b-remove-tier"><?php echo esc_js(__('Remove', 'wc-to-b2b')); ?></button></td>';
                table.appendChild(row);
            });
            table.addEventListener('click', function(event) {
                if (event.target.classList.contains('wc-b2b-remove-tier')) {
                    event.target.closest('tr').remove();
                }
            });
        }());
        </script>
        <?php
    }

    private function render_tier_row($index, $tier) {
        ?>
        <tr>
            <td><input required pattern="[a-z0-9_-]+" name="tiers[<?php echo esc_attr($index); ?>][id]" value="<?php echo esc_attr($tier['id']); ?>" /></td>
            <td><input required class="regular-text" name="tiers[<?php echo esc_attr($index); ?>][name]" value="<?php echo esc_attr($tier['name']); ?>" /></td>
            <td><input type="number" min="0" max="100" step="0.01" name="tiers[<?php echo esc_attr($index); ?>][discount]" value="<?php echo esc_attr($tier['discount']); ?>" /></td>
            <td><button type="button" class="button-link-delete wc-b2b-remove-tier"><?php esc_html_e('Remove', 'wc-to-b2b'); ?></button></td>
        </tr>
        <?php
    }

    private function save_tiers() {
        $posted = isset($_POST['tiers']) && is_array($_POST['tiers']) ? wp_unslash($_POST['tiers']) : array();
        $tiers  = array();

        foreach ($posted as $tier) {
            $id   = sanitize_key($tier['id'] ?? '');
            $name = sanitize_text_field($tier['name'] ?? '');
            if (!$id || !$name || isset($tiers[$id])) {
                continue;
            }
            $tiers[$id] = array(
                'id'       => $id,
                'name'     => $name,
                'discount' => min(100, max(0, (float) ($tier['discount'] ?? 0))),
            );
        }

        update_option('wc_b2b_membership_tiers', array_values($tiers));
    }

    public function render_user_tier_field($user) {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $selected = sanitize_key(get_user_meta($user->ID, self::USER_META_KEY, true));
        ?>
        <h2><?php esc_html_e('B2B Membership', 'wc-to-b2b'); ?></h2>
        <table class="form-table"><tr>
            <th><label for="wc_b2b_tier"><?php esc_html_e('Member Level', 'wc-to-b2b'); ?></label></th>
            <td><select name="wc_b2b_tier" id="wc_b2b_tier">
                <option value=""><?php esc_html_e('No B2B level (retail price)', 'wc-to-b2b'); ?></option>
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
        if ($tier_id && isset($tiers[$tier_id])) {
            update_user_meta($user_id, self::USER_META_KEY, $tier_id);
        } else {
            delete_user_meta($user_id, self::USER_META_KEY);
        }
    }

    public function render_product_prices() {
        foreach (self::get_tiers() as $tier) {
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
            $key = $this->price_meta_key($tier['id']);
            woocommerce_wp_text_input(array(
                'id'                => $key . '_' . $loop,
                'name'              => $key . '[' . $loop . ']',
                'value'             => get_post_meta($variation->ID, $key, true),
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

        $fixed = $product->get_meta($this->price_meta_key($tier['id']), true);
        if ('' === $fixed && $product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            $fixed  = $parent ? $parent->get_meta($this->price_meta_key($tier['id']), true) : '';
        }
        if ('' !== $fixed && is_numeric($fixed)) {
            return (string) max(0, (float) $fixed);
        }

        $discount = min(100, max(0, (float) $tier['discount']));
        return (string) round((float) $base_price * (1 - ($discount / 100)), wc_get_price_decimals());
    }

    public function variation_prices_hash($hash, $product, $for_display) {
        $tier = self::get_user_tier();
        $hash['wc_b2b_tier'] = $tier ? $tier['id'] . ':' . $tier['discount'] : 'retail';
        return $hash;
    }

    public function add_tier_label_to_price($html, $product) {
        $tier = self::get_user_tier();
        if (!$tier || is_admin()) {
            return $html;
        }
        return $html . ' <small class="wc-b2b-tier-label">' . esc_html($tier['name']) . '</small>';
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
}
