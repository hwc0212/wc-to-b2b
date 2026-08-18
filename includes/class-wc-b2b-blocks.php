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
        return array(
            'title'       => __('Offline quotation', 'wc-to-b2b'),
            'description' => __('Submit the order to receive a formal quotation and offline payment instructions. No online payment will be collected.', 'wc-to-b2b'),
            'supports'    => array('products'),
        );
    }
}
