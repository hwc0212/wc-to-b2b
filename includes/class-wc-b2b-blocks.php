<?php
/**
 * Checkout Blocks integration for the B2B quote gateway.
 */

if (!defined('ABSPATH')) {
    exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class WC_B2B_Blocks_Payment_Method extends AbstractPaymentMethodType {

    protected $name = 'b2b_quote';

    public function initialize() {
        $this->settings = array();
    }

    public function is_active() {
        return true;
    }

    public function get_payment_method_script_handles() {
        $handle = 'wc-b2b-blocks-checkout';
        wp_register_script(
            $handle,
            WC_TO_B2B_PLUGIN_URL . 'assets/js/blocks-checkout.js',
            array('wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities'),
            WC_TO_B2B_VERSION,
            true
        );
        return array($handle);
    }

    public function get_payment_method_data() {
        $is_guest = !is_user_logged_in();
        $guest_prices_hidden = WC_B2B_Membership::are_catalog_prices_hidden();
        return array(
            'title'       => $is_guest ? __('Email-verified inquiry', 'wc-to-b2b') : __('Offline quotation', 'wc-to-b2b'),
            'description' => $is_guest
                ? ($guest_prices_hidden
                    ? __('Submit an inquiry without displayed prices. We will receive it only after you verify your email, then prepare a formal quote.', 'wc-to-b2b')
                    : __('Displayed amounts are retail references. Submit the inquiry and verify your email; we will then review it and prepare the formal quote.', 'wc-to-b2b'))
                : __('Submit the order to receive a formal quotation and offline payment instructions. No online payment will be collected.', 'wc-to-b2b'),
            'button_label' => $is_guest ? __('Submit Inquiry & Verify Email', 'wc-to-b2b') : __('Submit B2B Quote Order', 'wc-to-b2b'),
            'supports'    => array('products'),
        );
    }
}
