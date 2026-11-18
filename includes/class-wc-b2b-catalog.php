<?php
/** Public product presentation and purchase restrictions for inquiry catalogs. */
defined('ABSPATH') || exit;

final class WC_B2B_Catalog {
    private $head_buffer_level = 0;
    private $management_requests = array();

    public function __construct() {
        foreach (array('product', 'product_variation') as $type) {
            foreach (array('price', 'regular_price', 'sale_price') as $field) {
                add_filter("woocommerce_{$type}_get_{$field}", array($this, 'hide_price'), PHP_INT_MAX);
            }
        }
        add_filter('woocommerce_get_price_html', array($this, 'hide_price'), PHP_INT_MAX);
        add_filter('woocommerce_get_variation_prices_hash', array($this, 'variation_cache_key'));
        add_filter('woocommerce_variation_is_visible', array($this, 'restore_variation_visibility'), 1, 4);
        add_filter('woocommerce_available_variation', array($this, 'hide_variation_prices'), PHP_INT_MAX);
        add_filter('woocommerce_product_is_on_sale', array($this, 'disable_public_flag'), PHP_INT_MAX);
        add_filter('woocommerce_is_purchasable', array($this, 'disable_public_flag'), PHP_INT_MAX);
        add_filter('woocommerce_variation_is_purchasable', array($this, 'disable_public_flag'), PHP_INT_MAX);

        add_filter('woocommerce_catalog_orderby', array($this, 'hide_price_ordering'));
        add_filter('woocommerce_default_catalog_orderby_options', array($this, 'hide_price_ordering'));
        add_filter('woocommerce_get_catalog_ordering_args', array($this, 'catalog_ordering_args'), PHP_INT_MAX, 2);
        add_action('woocommerce_product_query', array($this, 'remove_price_filter'), 99);
        add_filter('woocommerce_enable_post_clause_filtering', array($this, 'disable_public_flag'), PHP_INT_MAX);
        add_action('pre_get_posts', array($this, 'disable_block_price_queries'), 99);
        add_filter('widget_display_callback', array($this, 'hide_price_widget'), 10, 2);
        add_filter('pre_render_block', array($this, 'hide_commerce_blocks'), 10, 2);

        add_filter('woocommerce_structured_data_product', array($this, 'strip_product_offers'), PHP_INT_MAX);
        add_filter('wpseo_schema_product', array($this, 'strip_product_offers'), PHP_INT_MAX);
        add_filter('rank_math/json_ld', array($this, 'strip_product_offers'), PHP_INT_MAX);
        add_action('wp_head', array($this, 'capture_product_head'), PHP_INT_MIN);
        add_action('wp_head', array($this, 'output_product_head'), PHP_INT_MAX);

        add_action('wp', array($this, 'hide_native_purchase_controls'));
        add_action('wc_ajax_get_refreshed_fragments', array($this, 'empty_cart_fragments'), 0);
        add_action('wp_ajax_woocommerce_get_refreshed_fragments', array($this, 'empty_cart_fragments'), 0);
        add_action('wp_ajax_nopriv_woocommerce_get_refreshed_fragments', array($this, 'empty_cart_fragments'), 0);
        add_filter('wc_get_template', array($this, 'empty_mini_cart_template'), 10, 2);
        add_filter('woocommerce_my_account_my_orders_actions', array($this, 'hide_payment_action'));
        add_filter('woocommerce_add_to_cart_validation', '__return_false', PHP_INT_MAX);
        add_action('woocommerce_after_checkout_validation', array($this, 'block_checkout'), PHP_INT_MAX, 2);
        add_action('template_redirect', array($this, 'redirect_purchase_pages'), 1);

        add_filter('rest_request_before_callbacks', array($this, 'restrict_store_request'), 10, 3);
        add_filter('rest_request_after_callbacks', array($this, 'filter_rest_response'), PHP_INT_MAX, 3);
    }

    private function is_public() {
        // Frontend policy applies equally to administrators and shoppers. An
        // admin-ajax request may be a public variation or quick-view request.
        return !(is_admin() && !wp_doing_ajax()) && !wp_doing_cron()
            && !(defined('WP_CLI') && WP_CLI) && !$this->management_requests;
    }

    public function hide_price($value) {
        return $this->is_public() ? '' : $value;
    }

    public function disable_public_flag($value) {
        return $this->is_public() ? false : $value;
    }

    public function variation_cache_key($hash) {
        $hash['wc_b2b_catalog_public'] = $this->is_public();
        return $hash;
    }

    public function restore_variation_visibility($visible, $variation_id, $product_id, $variation) {
        // WooCommerce considers an empty public price invisible. Preserve the
        // normal publication rule while still allowing plugins to hide variants.
        return $this->is_public() ? $variation->get_status() === 'publish' && $variation->get_price('edit') !== '' : $visible;
    }

    public function hide_variation_prices($variation) {
        if ($this->is_public()) {
            $variation['price_html'] = '';
            $variation['display_price'] = null;
            $variation['display_regular_price'] = null;
            $variation['is_purchasable'] = false;
        }
        return $variation;
    }

    public function hide_price_ordering($choices) {
        unset($choices['price'], $choices['price-desc']);
        return $choices;
    }

    public function catalog_ordering_args($args, $orderby) {
        if ($this->is_public() && $orderby === 'price') {
            remove_filter('posts_clauses', array(WC()->query, 'order_by_price_asc_post_clauses'));
            remove_filter('posts_clauses', array(WC()->query, 'order_by_price_desc_post_clauses'));
            $args = array('orderby' => 'menu_order title', 'order' => 'ASC', 'meta_key' => '');
        }
        return $args;
    }

    public function remove_price_filter() {
        if ($this->is_public()) {
            // Older WooCommerce versions read these directly from GET.
            unset($_GET['min_price'], $_GET['max_price']);
        }
    }

    public function disable_block_price_queries($query) {
        if (!$this->is_public() || !$query->get('isProductCollection')) {
            return;
        }
        $query->set('priceRange', array());
        if ($query->get('orderby') === 'price') {
            $query->set('orderby', 'menu_order title');
        }
    }

    public function hide_price_widget($instance, $widget) {
        if ($this->is_public() && in_array($widget->id_base, array('woocommerce_price_filter', 'woocommerce_layered_nav_filters', 'woocommerce_widget_cart'), true)) {
            return false;
        }
        return $instance;
    }

    public function hide_commerce_blocks($rendered, $block) {
        $blocks = array(
            'woocommerce/product-price',
            'woocommerce/product-sale-badge',
            'woocommerce/product-template-sale-badge',
            'woocommerce/filter-wrapper',
            'woocommerce/product-filter-price',
            'woocommerce/price-filter',
            'woocommerce/mini-cart',
            'woocommerce/add-to-cart-with-options-button',
            'woocommerce/add-to-cart-with-options-quantity-selector',
            'woocommerce/cart',
            'woocommerce/checkout',
        );
        $name = $block['blockName'] ?? '';
        if ($name === 'woocommerce/filter-wrapper' && ($block['attrs']['filterType'] ?? '') !== 'price-filter') {
            return $rendered;
        }
        return $this->is_public() && in_array($name, $blocks, true) ? '' : $rendered;
    }

    public function strip_product_offers($schema) {
        if (!is_array($schema) || !$this->is_public()) {
            return $schema;
        }
        if (array_intersect(array('Product', 'ProductGroup'), (array) ($schema['@type'] ?? array()))) {
            unset($schema['offers']);
        }
        // JSON-LD integrations may pass a graph containing several nodes.
        foreach ($schema as $key => $node) {
            if (is_array($node)) {
                $schema[$key] = $this->strip_product_offers($node);
            }
        }
        return $schema;
    }

    public function capture_product_head() {
        if (is_product()) {
            ob_start();
            $this->head_buffer_level = ob_get_level();
        }
    }

    public function output_product_head() {
        if (!$this->head_buffer_level || ob_get_level() !== $this->head_buffer_level) {
            return;
        }
        $this->head_buffer_level = 0;
        $head = ob_get_clean();
        // Only inspect actual meta tags in the product head. Consume script,
        // style and comments whole so their contents are left intact.
        $pattern = '~<!--.*?-->|<(script|style)\b[^>]*>.*?</\1\s*>|<meta\b(?:[^<>\x22\x27]++|"[^"]*"|\x27[^\x27]*\x27)*>~is';
        $labels = array();
        preg_match_all($pattern, $head, $tags);
        $price_labels = array('price', 'product price', strtolower(__('Price', 'woocommerce')));
        foreach ($tags[0] as $tag) {
            $attrs = $this->meta_attributes($tag);
            $name = $attrs['name'] ?? $attrs['property'] ?? '';
            if (preg_match('/^twitter:label([1-9][0-9]*)$/D', $name, $match)
                && in_array(trim($attrs['content'] ?? ''), $price_labels, true)) {
                $labels[] = $match[1];
            }
        }
        echo preg_replace_callback($pattern, function ($match) use ($labels) {
            $attrs = $this->meta_attributes($match[0]);
            foreach (array('name', 'property', 'itemprop') as $key) {
                $value = $attrs[$key] ?? '';
                if (preg_match('~^(?:(?:og:)?product:(?:price|sale_price)|og:price|twitter:(?:price|sale_price))(?::|$)~', $value)
                    || in_array($value, array('price', 'pricecurrency', 'lowprice', 'highprice'), true)
                    || (preg_match('/^twitter:(?:label|data)([1-9][0-9]*)$/D', $value, $pair) && in_array($pair[1], $labels, true))) {
                    return '';
                }
            }
            return $match[0];
        }, $head) ?? $head; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    private function meta_attributes($tag) {
        if (strncasecmp($tag, '<meta', 5) !== 0) {
            return array();
        }
        preg_match_all('~\s+([^\s=/>]+)\s*=\s*(?:"([^"]*)"|\x27([^\x27]*)\x27|([^\s>]+))~',
            $tag, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $attrs = array();
        foreach ($matches as $attr) {
            $attrs[strtolower($attr[1])] = strtolower(html_entity_decode($attr[2] ?? $attr[3] ?? $attr[4], ENT_QUOTES, 'UTF-8'));
        }
        return $attrs;
    }

    public function hide_native_purchase_controls() {
        remove_action('woocommerce_single_variation', 'woocommerce_single_variation_add_to_cart_button', 20);
    }

    public function empty_mini_cart_template($template, $name) {
        return $this->is_public() && $name === 'cart/mini-cart.php'
            ? WC_TO_B2B_PLUGIN_PATH . 'templates/catalog-mini-cart.php'
            : $template;
    }

    public function empty_cart_fragments() {
        wp_send_json(array('fragments' => array('div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content"></div>'), 'cart_hash' => ''));
    }

    public function hide_payment_action($actions) {
        unset($actions['pay']);
        return $actions;
    }

    public function block_checkout($data, $errors) {
        $errors->add('wc_b2b_catalog_only', __('This store accepts product inquiries through its catalog.', 'wc-to-b2b'));
    }

    public function redirect_purchase_pages() {
        if (is_cart() || (is_checkout() && !is_order_received_page())) {
            nocache_headers();
            wp_safe_redirect(wc_get_page_permalink('shop'), 302);
            exit;
        }
    }

    public function restrict_store_request($result, $handler, $request) {
        $route = $request->get_route();
        if (preg_match('~^/wc/v[1-3]/(?:products|orders)(?:/|$)~', $route) && current_user_can('manage_woocommerce')) {
            $this->management_requests[spl_object_hash($request)] = true;
            return $result;
        }
        if (preg_match('~^/wc/store(?:/v[0-9]+)?/(cart|checkout)(?:/|$)~', $route)) {
            return new WP_Error('wc_b2b_catalog_only', __('This store accepts product inquiries through its catalog.', 'wc-to-b2b'), array('status' => 403));
        }
        if ($this->is_store_product_request($route)) {
            foreach (array('min_price', 'max_price', 'on_sale', 'calculate_price_range') as $param) {
                $request->set_param($param, null);
            }
            if ($request->get_param('orderby') === 'price') {
                $request->set_param('orderby', 'date');
            }
        }
        return $result;
    }

    public function filter_rest_response($response, $handler, $request) {
        unset($this->management_requests[spl_object_hash($request)]);
        if (!$this->is_store_product_request($request->get_route()) || is_wp_error($response)) {
            return $response;
        }
        $response = rest_ensure_response($response);
        $data = $response->get_data();
        if (!is_array($data) || $response->get_status() >= 400) {
            return $response;
        }
        if (array_key_exists('price_range', $data)) {
            $data['price_range'] = null; // Product collection aggregate.
        }
        if (isset($data['id'])) {
            $data = $this->hide_store_product_prices($data);
        } else {
            foreach ($data as $key => $product) {
                if (is_int($key) && is_array($product) && isset($product['id'])) {
                    $data[$key] = $this->hide_store_product_prices($product);
                }
            }
        }
        $response->set_data($data);
        return $response;
    }

    private function is_store_product_request($route) {
        return (bool) preg_match('~^/wc/store(?:/v[0-9]+)?/products(?:/[^/]+)?/?$~', $route);
    }

    private function hide_store_product_prices(array $data) {
        if (!isset($data['prices'])) {
            return $data;
        }
        $prices = (array) $data['prices'];
        foreach (array('price', 'regular_price', 'sale_price') as $field) {
            $prices[$field] = '';
        }
        $prices['price_range'] = null;
        $data['prices'] = (object) $prices;
        $data['price_html'] = '';
        $data['on_sale'] = false;
        $data['is_purchasable'] = false;
        return $data;
    }
}
