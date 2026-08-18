<?php
/**
 * Customer account pages for B2B quotes, payments, and shipments.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_B2B_Account {

    public function __construct() {
        add_action('init', array($this, 'add_endpoint'));
        add_filter('woocommerce_account_menu_items', array($this, 'add_menu_item'));
        add_action('woocommerce_account_b2b-orders_endpoint', array($this, 'render_orders_endpoint'));
        add_action('woocommerce_order_details_after_order_table', array($this, 'render_order_panel'));
        add_action('woocommerce_thankyou', array($this, 'render_order_panel_by_id'), 20);
    }

    public function add_endpoint() {
        add_rewrite_endpoint('b2b-orders', EP_ROOT | EP_PAGES);
    }

    public function add_menu_item($items) {
        $new = array();
        foreach ($items as $key => $label) {
            $new[$key] = $label;
            if ('orders' === $key) {
                $new['b2b-orders'] = __('B2B Quotes & Orders', 'wc-to-b2b');
            }
        }
        if (!isset($new['b2b-orders'])) {
            $new['b2b-orders'] = __('B2B Quotes & Orders', 'wc-to-b2b');
        }
        return $new;
    }

    public function render_orders_endpoint() {
        $orders = wc_get_orders(array(
            'customer_id' => get_current_user_id(),
            'limit'       => 50,
            'orderby'     => 'date',
            'order'       => 'DESC',
            'meta_query'  => array(array('key' => '_is_b2b_order', 'value' => 'yes')),
        ));

        echo '<h2>' . esc_html__('B2B Quotes & Orders', 'wc-to-b2b') . '</h2>';
        if (!$orders) {
            echo '<p>' . esc_html__('You do not have any B2B quotes or orders yet.', 'wc-to-b2b') . '</p>';
            return;
        }
        ?>
        <table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders account-orders-table">
            <thead><tr>
                <th><?php esc_html_e('Quote', 'wc-to-b2b'); ?></th>
                <th><?php esc_html_e('Date', 'wc-to-b2b'); ?></th>
                <th><?php esc_html_e('Status', 'wc-to-b2b'); ?></th>
                <th><?php esc_html_e('Total', 'wc-to-b2b'); ?></th>
                <th><?php esc_html_e('Paid / Balance', 'wc-to-b2b'); ?></th>
                <th><?php esc_html_e('Action', 'wc-to-b2b'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($orders as $order) :
                $paid    = WC_B2B_Fulfillment::get_paid_total($order);
                $balance = max(0, (float) $order->get_total() - $paid);
                $quote   = $order->get_meta('_wc_b2b_quote_number', true) ?: '#' . $order->get_order_number();
                ?>
                <tr>
                    <td data-title="<?php esc_attr_e('Quote', 'wc-to-b2b'); ?>"><?php echo esc_html($quote); ?></td>
                    <td data-title="<?php esc_attr_e('Date', 'wc-to-b2b'); ?>"><?php echo esc_html(wc_format_datetime($order->get_date_created(), wc_date_format())); ?></td>
                    <td data-title="<?php esc_attr_e('Status', 'wc-to-b2b'); ?>"><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></td>
                    <td data-title="<?php esc_attr_e('Total', 'wc-to-b2b'); ?>"><?php echo wp_kses_post($order->get_formatted_order_total()); ?></td>
                    <td data-title="<?php esc_attr_e('Paid / Balance', 'wc-to-b2b'); ?>"><?php echo wp_kses_post(wc_price($paid, array('currency' => $order->get_currency())) . ' / ' . wc_price($balance, array('currency' => $order->get_currency()))); ?></td>
                    <td><a class="woocommerce-button button view" href="<?php echo esc_url($order->get_view_order_url()); ?>"><?php esc_html_e('View', 'wc-to-b2b'); ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    public function render_order_panel_by_id($order_id) {
        $order = wc_get_order($order_id);
        if ($order) {
            $this->render_order_panel($order);
        }
    }

    public function render_order_panel($order) {
        if (!WC_B2B_Fulfillment::is_b2b_order($order) || !$this->customer_can_view($order)) {
            return;
        }

        if (class_exists('WC_B2B_Membership') && !WC_B2B_Membership::has_formal_quote($order)) {
            $guest_inquiry = WC_B2B_Membership::is_guest_inquiry($order);
            $show_reference_price = WC_B2B_Membership::can_display_order_prices($order);
            ?>
            <section class="woocommerce-order-details wc-b2b-customer-ledger">
                <h2><?php echo esc_html($guest_inquiry ? __('Verified Inquiry', 'wc-to-b2b') : __('Quote Request Under Review', 'wc-to-b2b')); ?></h2>
                <table class="shop_table shop_table_responsive"><tbody>
                    <tr><th><?php echo esc_html($guest_inquiry ? __('Inquiry Number', 'wc-to-b2b') : __('Order Number', 'wc-to-b2b')); ?></th><td>#<?php echo esc_html($order->get_order_number()); ?></td></tr>
                    <tr><th><?php esc_html_e('Status', 'wc-to-b2b'); ?></th><td><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></td></tr>
                    <?php if (!$guest_inquiry) : ?><tr><th><?php esc_html_e('Member Level', 'wc-to-b2b'); ?></th><td><?php echo esc_html($order->get_meta('_wc_b2b_tier_name', true) ?: __('Registered Customer', 'wc-to-b2b')); ?></td></tr><?php endif; ?>
                    <tr><th><?php esc_html_e('Pricing', 'wc-to-b2b'); ?></th><td><?php esc_html_e('Pending formal quotation', 'wc-to-b2b'); ?></td></tr>
                    <?php if ($show_reference_price) : ?><tr><th><?php echo esc_html($guest_inquiry ? __('Retail Reference Total', 'wc-to-b2b') : __('Reference Total', 'wc-to-b2b')); ?></th><td><?php echo wp_kses_post($order->get_formatted_order_total()); ?></td></tr><?php endif; ?>
                </tbody></table>
                <p><?php echo esc_html($show_reference_price
                    ? __('Your request has been received. The amount above is a reference only; an administrator will review prices and shipping before sending the formal quote.', 'wc-to-b2b')
                    : __('Your inquiry has been received. Prices, offline payment information, and payment records will appear here after the formal quote is sent.', 'wc-to-b2b')); ?></p>
            </section>
            <?php
            return;
        }

        $payments    = WC_B2B_Fulfillment::get_payments($order);
        $shipments   = WC_B2B_Fulfillment::get_shipments($order);
        $paid        = WC_B2B_Fulfillment::get_paid_total($order);
        $balance     = max(0, (float) $order->get_total() - $paid);
        $instructions = WC_B2B_Quote::get_payment_instructions();
        $quote_number = $order->get_meta('_wc_b2b_quote_number', true);
        $valid_until  = $order->get_meta('_wc_b2b_quote_valid_until', true);
        ?>
        <section class="woocommerce-order-details wc-b2b-customer-ledger" id="wc-b2b-quote-<?php echo esc_attr($order->get_id()); ?>">
            <div class="wc-b2b-quote-heading">
                <h2><?php esc_html_e('B2B Quotation & Fulfillment', 'wc-to-b2b'); ?></h2>
                <button type="button" class="button wc-b2b-print" onclick="window.print()"><?php esc_html_e('Print Quote', 'wc-to-b2b'); ?></button>
            </div>
            <table class="shop_table shop_table_responsive">
                <tbody>
                    <tr><th><?php esc_html_e('Quote Number', 'wc-to-b2b'); ?></th><td><?php echo esc_html($quote_number ?: __('Pending', 'wc-to-b2b')); ?></td></tr>
                    <tr><th><?php esc_html_e('Member Level', 'wc-to-b2b'); ?></th><td><?php echo esc_html($order->get_meta('_wc_b2b_tier_name', true) ?: __('Retail / unassigned', 'wc-to-b2b')); ?></td></tr>
                    <tr><th><?php esc_html_e('Quote Valid Until', 'wc-to-b2b'); ?></th><td><?php echo esc_html($valid_until ?: '—'); ?></td></tr>
                    <tr><th><?php esc_html_e('Order Status', 'wc-to-b2b'); ?></th><td><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></td></tr>
                    <tr><th><?php esc_html_e('Recorded Payment', 'wc-to-b2b'); ?></th><td><?php echo wp_kses_post(wc_price($paid, array('currency' => $order->get_currency()))); ?></td></tr>
                    <tr><th><?php esc_html_e('Outstanding Balance', 'wc-to-b2b'); ?></th><td><?php echo wp_kses_post(wc_price($balance, array('currency' => $order->get_currency()))); ?></td></tr>
                </tbody>
            </table>

            <?php if ($instructions && in_array($order->get_status(), array('quote-sent', 'quote-accepted', 'processing', 'partially-shipped', 'shipped'), true)) : ?>
                <div class="wc-b2b-payment-instructions"><h3><?php esc_html_e('Offline Payment Information', 'wc-to-b2b'); ?></h3><?php echo wpautop(wp_kses_post($instructions)); ?></div>
            <?php endif; ?>

            <?php if ('quote-sent' === $order->get_status()) : ?>
                <p class="wc-b2b-quote-actions">
                    <a class="button" href="<?php echo esc_url(WC_B2B_Quote::get_action_url($order, 'confirm')); ?>"><?php esc_html_e('Accept Quote', 'wc-to-b2b'); ?></a>
                    <?php if ($paid <= 0) : ?><a class="button" href="<?php echo esc_url(WC_B2B_Quote::get_action_url($order, 'cancel')); ?>"><?php esc_html_e('Cancel Order', 'wc-to-b2b'); ?></a><?php endif; ?>
                </p>
            <?php endif; ?>

            <h3><?php esc_html_e('Payment History', 'wc-to-b2b'); ?></h3>
            <?php if ($payments) : ?>
                <table class="shop_table shop_table_responsive"><thead><tr><th><?php esc_html_e('Date', 'wc-to-b2b'); ?></th><th><?php esc_html_e('Amount', 'wc-to-b2b'); ?></th><th><?php esc_html_e('Method', 'wc-to-b2b'); ?></th><th><?php esc_html_e('Reference', 'wc-to-b2b'); ?></th></tr></thead><tbody>
                <?php foreach (array_reverse($payments) as $payment) : ?><tr><td><?php echo esc_html($payment['date'] ?? ''); ?></td><td><?php echo wp_kses_post(wc_price((float) ($payment['amount'] ?? 0), array('currency' => $order->get_currency()))); ?></td><td><?php echo esc_html($payment['method'] ?? ''); ?></td><td><?php echo esc_html($payment['reference'] ?? ''); ?></td></tr><?php endforeach; ?>
                </tbody></table>
            <?php else : ?><p><?php esc_html_e('No payment has been recorded yet.', 'wc-to-b2b'); ?></p><?php endif; ?>

            <h3><?php esc_html_e('Shipment History', 'wc-to-b2b'); ?></h3>
            <?php if ($shipments) : ?>
                <table class="shop_table shop_table_responsive"><thead><tr><th><?php esc_html_e('Date', 'wc-to-b2b'); ?></th><th><?php esc_html_e('Carrier', 'wc-to-b2b'); ?></th><th><?php esc_html_e('Tracking', 'wc-to-b2b'); ?></th><th><?php esc_html_e('Contents', 'wc-to-b2b'); ?></th></tr></thead><tbody>
                <?php foreach (array_reverse($shipments) as $shipment) : ?><tr><td><?php echo esc_html($shipment['date'] ?? ''); ?></td><td><?php echo esc_html($shipment['carrier'] ?? ''); ?></td><td><?php if (!empty($shipment['tracking_url'])) : ?><a rel="noopener noreferrer" target="_blank" href="<?php echo esc_url($shipment['tracking_url']); ?>"><?php echo esc_html($shipment['tracking_number'] ?: __('Track shipment', 'wc-to-b2b')); ?></a><?php else : echo esc_html($shipment['tracking_number'] ?? '—'); endif; ?></td><td><?php echo esc_html($shipment['contents'] ?? ''); ?></td></tr><?php endforeach; ?>
                </tbody></table>
            <?php else : ?><p><?php esc_html_e('No shipment has been recorded yet.', 'wc-to-b2b'); ?></p><?php endif; ?>
        </section>
        <style>
        .wc-b2b-quote-heading{display:flex;align-items:center;justify-content:space-between;gap:16px}.wc-b2b-payment-instructions{padding:18px;border-left:4px solid #2271b1;background:#f6f7f7;margin:20px 0}.wc-b2b-quote-actions{display:flex;gap:10px}
        @media print{header,footer,nav,.woocommerce-MyAccount-navigation,.wc-b2b-print,.wc-b2b-quote-actions{display:none!important}.woocommerce-MyAccount-content{width:100%!important}.wc-b2b-customer-ledger{font-size:12px}}
        </style>
        <?php
    }

    private function customer_can_view($order) {
        if (current_user_can('manage_woocommerce')) {
            return true;
        }
        if ($order->get_customer_id()) {
            return get_current_user_id() === (int) $order->get_customer_id();
        }
        $key = isset($_GET['key']) ? wc_clean(wp_unslash($_GET['key'])) : '';
        return $key && hash_equals($order->get_order_key(), $key);
    }
}
