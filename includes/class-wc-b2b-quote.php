<?php
/**
 * Offline quotation workflow and quote-only checkout gateway.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_B2B_Quote {

    public function __construct() {
        add_filter('woocommerce_payment_gateways', array($this, 'register_gateway'));
        add_filter('woocommerce_checkout_registration_required', array($this, 'maybe_require_account'));
        add_action('admin_menu', array($this, 'add_settings_menu'));
        add_action('woocommerce_thankyou_b2b_quote', array($this, 'render_thankyou_quote'));

        if (did_action('woocommerce_blocks_loaded')) {
            $this->load_blocks_support();
        } else {
            add_action('woocommerce_blocks_loaded', array($this, 'load_blocks_support'));
        }
    }

    public function register_gateway($gateways) {
        $gateways[] = 'WC_Gateway_B2B_Quote';
        return $gateways;
    }

    public function maybe_require_account($required) {
        return !is_user_logged_in() && !WC_B2B_Membership::guest_inquiries_are_enabled();
    }

    public function load_blocks_support() {
        if (!class_exists('Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType')) {
            return;
        }
        require_once WC_TO_B2B_PLUGIN_PATH . 'includes/class-wc-b2b-blocks.php';
        add_action('woocommerce_blocks_payment_method_type_registration', array($this, 'register_blocks_payment_method'));
    }

    public function register_blocks_payment_method($registry) {
        if (class_exists('WC_B2B_Blocks_Payment_Method')) {
            $registry->register(new WC_B2B_Blocks_Payment_Method());
        }
    }

    public function add_settings_menu() {
        add_submenu_page(
            'woocommerce',
            __('B2B Quote Settings', 'wc-to-b2b'),
            __('B2B Quote Settings', 'wc-to-b2b'),
            'manage_woocommerce',
            'wc-b2b-quote-settings',
            array($this, 'render_settings_page')
        );
    }

    public function render_settings_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to manage B2B quote settings.', 'wc-to-b2b'));
        }

        if (isset($_POST['wc_b2b_save_quote_settings'])) {
            check_admin_referer('wc_b2b_quote_settings');
            update_option('wc_b2b_auto_quote', 'no');
            update_option('wc_b2b_require_account', WC_B2B_Membership::guest_inquiries_are_enabled() ? 'no' : 'yes');
            update_option('wc_b2b_verify_guests', 'yes');
            update_option('wc_b2b_quote_validity_days', max(1, min(365, absint($_POST['quote_validity_days'] ?? 30))));
            update_option('wc_b2b_payment_instructions', wp_kses_post(wp_unslash($_POST['payment_instructions'] ?? '')));
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Quote settings saved.', 'wc-to-b2b') . '</p></div>';
        }

        $validity      = get_option('wc_b2b_quote_validity_days', 21);
        $instructions  = get_option('wc_b2b_payment_instructions', '');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('B2B Quote & Offline Payment Settings', 'wc-to-b2b'); ?></h1>
            <form method="post">
                <?php wp_nonce_field('wc_b2b_quote_settings'); ?>
                <table class="form-table">
                    <tr><th><?php esc_html_e('Quote Preparation', 'wc-to-b2b'); ?></th><td>
                        <p><strong><?php esc_html_e('Manual review is always required.', 'wc-to-b2b'); ?></strong></p>
                        <p class="description"><?php esc_html_e('Every submission remains ready for review until an administrator adjusts product prices and shipping, then sends the formal quote manually.', 'wc-to-b2b'); ?></p>
                    </td></tr>
                    <tr><th><?php esc_html_e('Customer Access Rules', 'wc-to-b2b'); ?></th><td>
                        <?php if (WC_B2B_Membership::guest_inquiries_are_enabled()) : ?>
                            <p><?php esc_html_e('Guests may submit inquiries, but each inquiry is delivered only after the guest clicks its email verification link. Registered customers must verify their account email before signing in.', 'wc-to-b2b'); ?></p>
                        <?php else : ?>
                            <p><?php esc_html_e('Customers must register, verify their account email, and sign in before requesting a quote. Guest checkout is disabled.', 'wc-to-b2b'); ?></p>
                        <?php endif; ?>
                        <p class="description"><?php esc_html_e('Change this rule under WooCommerce → B2B Member Levels → Customer Access Mode.', 'wc-to-b2b'); ?></p>
                    </td></tr>
                    <tr><th><label for="quote_validity_days"><?php esc_html_e('Quote Validity', 'wc-to-b2b'); ?></label></th><td>
                        <input id="quote_validity_days" type="number" min="1" max="365" name="quote_validity_days" value="<?php echo esc_attr($validity); ?>" /> <?php esc_html_e('days', 'wc-to-b2b'); ?>
                    </td></tr>
                    <tr><th><label for="payment_instructions"><?php esc_html_e('Offline Payment Information', 'wc-to-b2b'); ?></label></th><td>
                        <textarea id="payment_instructions" name="payment_instructions" rows="10" class="large-text"><?php echo esc_textarea($instructions); ?></textarea>
                        <p class="description"><?php esc_html_e('Enter bank name, beneficiary, account number, SWIFT, payment reference instructions, and contact details. This appears on quotes, customer order pages, and emails.', 'wc-to-b2b'); ?></p>
                    </td></tr>
                </table>
                <?php submit_button(__('Save Quote Settings', 'wc-to-b2b'), 'primary', 'wc_b2b_save_quote_settings'); ?>
            </form>
        </div>
        <?php
    }

    public static function prepare_quote($order) {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order);
        }
        if (!$order) {
            return;
        }

        if (!$order->get_meta('_wc_b2b_quote_number', true)) {
            $order->update_meta_data('_wc_b2b_quote_number', 'QT-' . current_time('Ymd') . '-' . $order->get_id());
        }
        if (!$order->get_meta('_wc_b2b_quote_created_at', true)) {
            $order->update_meta_data('_wc_b2b_quote_created_at', current_time('mysql'));
        }
        $validity = max(1, absint(get_option('wc_b2b_quote_validity_days', 21)));
        $order->update_meta_data('_wc_b2b_quote_valid_until', date_i18n('Y-m-d', current_time('timestamp') + DAY_IN_SECONDS * $validity));
        $order->save();
    }

    public static function get_action_token($order, $action) {
        return hash_hmac('sha256', $order->get_id() . '|' . $order->get_order_key() . '|' . sanitize_key($action), wp_salt('nonce'));
    }

    public static function validate_action_token($order, $action, $token) {
        if (!$order || !is_string($token) || '' === $token) {
            return false;
        }
        return hash_equals(self::get_action_token($order, $action), $token);
    }

    public static function get_action_url($order, $action) {
        return add_query_arg(array(
            'wc_b2b_action' => sanitize_key($action),
            'order_id'      => $order->get_id(),
            'key'           => $order->get_order_key(),
            'token'         => self::get_action_token($order, $action),
        ), home_url('/'));
    }

    public static function get_payment_instructions() {
        return trim((string) get_option('wc_b2b_payment_instructions', ''));
    }

    public static function is_quote_valid($order) {
        $valid_until = $order instanceof WC_Order ? $order->get_meta('_wc_b2b_quote_valid_until', true) : '';
        return !$valid_until || $valid_until >= current_time('Y-m-d');
    }

    public function render_thankyou_quote($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        echo '<section class="woocommerce-order-details wc-b2b-thankyou-quote">';
        $guest_inquiry = WC_B2B_Membership::is_guest_inquiry($order);
        echo '<h2>' . esc_html($guest_inquiry ? __('Inquiry submitted', 'wc-to-b2b') : __('Quotation submitted', 'wc-to-b2b')) . '</h2>';
        if ($guest_inquiry && in_array($order->get_status(), array('b2b-verifying', 'pending-verificat'), true)) {
            echo '<p>' . esc_html__('Please check your inbox and click the verification link. Your inquiry will be delivered to our reception team only after verification.', 'wc-to-b2b') . '</p>';
        } elseif ('quote-sent' === $order->get_status()) {
            echo '<p>' . esc_html__('Your formal quote has been generated and emailed to you. Please review it and use the offline payment information shown below.', 'wc-to-b2b') . '</p>';
        } else {
            echo '<p>' . esc_html__('Your request was received. We will email the formal quote after it has been reviewed.', 'wc-to-b2b') . '</p>';
        }
        $instructions = self::get_payment_instructions();
        if ($instructions && in_array($order->get_status(), array('quote-sent', 'quote-accepted', 'processing'), true)) {
            echo '<div class="wc-b2b-payment-instructions"><h3>' . esc_html__('Offline Payment Information', 'wc-to-b2b') . '</h3>' . wpautop(wp_kses_post($instructions)) . '</div>';
        }
        echo '</section>';
    }
}

if (!class_exists('WC_Payment_Gateway') && defined('WC_ABSPATH')) {
    require_once WC_ABSPATH . 'abstracts/abstract-wc-payment-gateway.php';
}

if (class_exists('WC_Payment_Gateway')) {
    class WC_Gateway_B2B_Quote extends WC_Payment_Gateway {

        public function __construct() {
            $is_guest = !is_user_logged_in();
            $guest_prices_hidden = WC_B2B_Membership::are_catalog_prices_hidden();
            $this->id                 = 'b2b_quote';
            $this->has_fields         = false;
            $this->method_title       = __('B2B Offline Quote', 'wc-to-b2b');
            $this->method_description = __('Submits a quote request without collecting an online payment; an administrator prepares and sends the formal quote manually.', 'wc-to-b2b');
            $this->title              = $is_guest ? __('Email-verified inquiry', 'wc-to-b2b') : __('Offline quotation', 'wc-to-b2b');
            $this->description        = $is_guest
                ? ($guest_prices_hidden
                    ? __('Submit an inquiry without displayed prices. We will receive it only after you verify your email; an administrator will then review prices and shipping.', 'wc-to-b2b')
                    : __('Displayed amounts are retail references. Submit the inquiry and verify your email; an administrator will then review prices and shipping.', 'wc-to-b2b'))
                : __('Submit a quote request for administrator review. Prices and shipping may be adjusted before the formal quote and offline payment instructions are sent manually.', 'wc-to-b2b');
            $this->enabled            = 'yes';
        }

        public function is_available() {
            return is_user_logged_in() || WC_B2B_Membership::guest_inquiries_are_enabled();
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                return array('result' => 'failure');
            }
            if (WC()->cart) {
                WC()->cart->empty_cart();
            }
            return array(
                'result'   => 'success',
                'redirect' => $this->get_return_url($order),
            );
        }
    }
}
