<?php
/**
 * Offline payment and shipment history for B2B orders.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_B2B_Fulfillment {

    public function __construct() {
        add_action('add_meta_boxes', array($this, 'add_meta_box'));
        add_action('admin_post_wc_b2b_add_payment', array($this, 'handle_add_payment'));
        add_action('admin_post_wc_b2b_add_shipment', array($this, 'handle_add_shipment'));
    }

    public static function is_b2b_order($order) {
        return $order instanceof WC_Order && 'yes' === $order->get_meta('_is_b2b_order', true);
    }

    public static function get_payments($order) {
        $payments = $order instanceof WC_Order ? $order->get_meta('_wc_b2b_payments', true) : array();
        return is_array($payments) ? $payments : array();
    }

    public static function get_shipments($order) {
        $shipments = $order instanceof WC_Order ? $order->get_meta('_wc_b2b_shipments', true) : array();
        return is_array($shipments) ? $shipments : array();
    }

    public static function get_paid_total($order) {
        $total = 0;
        foreach (self::get_payments($order) as $payment) {
            $total += (float) ($payment['amount'] ?? 0);
        }
        return $total;
    }

    public function add_meta_box() {
        $screens = array('shop_order');
        if (function_exists('wc_get_page_screen_id')) {
            $screens[] = wc_get_page_screen_id('shop-order');
        }
        foreach (array_unique($screens) as $screen) {
            add_meta_box(
                'wc-b2b-payment-shipment-history',
                __('B2B Payments & Shipments', 'wc-to-b2b'),
                array($this, 'render_meta_box'),
                $screen,
                'normal',
                'high'
            );
        }
    }

    private function get_order_from_screen_object($object) {
        if ($object instanceof WC_Order) {
            return $object;
        }
        if ($object instanceof WP_Post) {
            return wc_get_order($object->ID);
        }
        return false;
    }

    public function render_meta_box($object) {
        $order = $this->get_order_from_screen_object($object);
        if (!self::is_b2b_order($order)) {
            echo '<p>' . esc_html__('This is not a B2B order.', 'wc-to-b2b') . '</p>';
            return;
        }
        if (class_exists('WC_B2B_Membership') && (!$order->get_meta('_wc_b2b_quote_number', true) || !in_array($order->get_status(), array('quote-sent', 'quote-accepted', 'processing', 'partially-shipped', 'shipped', 'completed'), true))) {
            echo '<p>' . esc_html__('Payment and shipment records become available after the formal quote is sent.', 'wc-to-b2b') . '</p>';
            return;
        }

        $payments  = self::get_payments($order);
        $shipments = self::get_shipments($order);
        $paid      = self::get_paid_total($order);
        $balance   = max(0, (float) $order->get_total() - $paid);
        ?>
        <div class="wc-b2b-ledger">
            <h3><?php esc_html_e('Payment Records', 'wc-to-b2b'); ?></h3>
            <p><strong><?php esc_html_e('Recorded:', 'wc-to-b2b'); ?></strong> <?php echo wp_kses_post(wc_price($paid, array('currency' => $order->get_currency()))); ?> &nbsp; <strong><?php esc_html_e('Balance:', 'wc-to-b2b'); ?></strong> <?php echo wp_kses_post(wc_price($balance, array('currency' => $order->get_currency()))); ?></p>
            <?php if ($payments) : ?>
            <table class="widefat striped"><thead><tr>
                <th><?php esc_html_e('Date', 'wc-to-b2b'); ?></th><th><?php esc_html_e('Amount', 'wc-to-b2b'); ?></th><th><?php esc_html_e('Method', 'wc-to-b2b'); ?></th><th><?php esc_html_e('Reference', 'wc-to-b2b'); ?></th><th><?php esc_html_e('Note', 'wc-to-b2b'); ?></th>
            </tr></thead><tbody>
            <?php foreach (array_reverse($payments) as $payment) : ?>
                <tr><td><?php echo esc_html($payment['date'] ?? ''); ?></td><td><?php echo wp_kses_post(wc_price((float) ($payment['amount'] ?? 0), array('currency' => $order->get_currency()))); ?></td><td><?php echo esc_html($payment['method'] ?? ''); ?></td><td><?php echo esc_html($payment['reference'] ?? ''); ?></td><td><?php echo esc_html($payment['note'] ?? ''); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
            <?php else : ?><p><?php esc_html_e('No payment has been recorded.', 'wc-to-b2b'); ?></p><?php endif; ?>

            <h4><?php esc_html_e('Add Payment', 'wc-to-b2b'); ?></h4>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wc-b2b-inline-form">
                <input type="hidden" name="action" value="wc_b2b_add_payment" />
                <input type="hidden" name="order_id" value="<?php echo esc_attr($order->get_id()); ?>" />
                <?php wp_nonce_field('wc_b2b_add_payment_' . $order->get_id()); ?>
                <p><label><?php esc_html_e('Payment date', 'wc-to-b2b'); ?><br><input required type="date" name="payment_date" value="<?php echo esc_attr(current_time('Y-m-d')); ?>" /></label></p>
                <p><label><?php esc_html_e('Amount', 'wc-to-b2b'); ?><br><input required type="number" min="0.01" step="0.01" name="amount" value="<?php echo esc_attr(wc_format_decimal($balance, 2)); ?>" /></label></p>
                <p><label><?php esc_html_e('Method', 'wc-to-b2b'); ?><br><input required name="method" placeholder="<?php esc_attr_e('Bank transfer', 'wc-to-b2b'); ?>" /></label></p>
                <p><label><?php esc_html_e('Reference', 'wc-to-b2b'); ?><br><input name="reference" /></label></p>
                <p><label><?php esc_html_e('Internal/customer note', 'wc-to-b2b'); ?><br><textarea name="note" rows="2"></textarea></label></p>
                <?php submit_button(__('Record Payment', 'wc-to-b2b'), 'primary', 'submit', false); ?>
            </form>

            <hr>
            <h3><?php esc_html_e('Shipment Records', 'wc-to-b2b'); ?></h3>
            <?php if ($shipments) : ?>
            <table class="widefat striped"><thead><tr>
                <th><?php esc_html_e('Date', 'wc-to-b2b'); ?></th><th><?php esc_html_e('Carrier', 'wc-to-b2b'); ?></th><th><?php esc_html_e('Tracking Number', 'wc-to-b2b'); ?></th><th><?php esc_html_e('Contents', 'wc-to-b2b'); ?></th><th><?php esc_html_e('Note', 'wc-to-b2b'); ?></th>
            </tr></thead><tbody>
            <?php foreach (array_reverse($shipments) as $shipment) : ?>
                <tr><td><?php echo esc_html($shipment['date'] ?? ''); ?></td><td><?php echo esc_html($shipment['carrier'] ?? ''); ?></td><td><?php echo esc_html($shipment['tracking_number'] ?? ''); ?></td><td><?php echo esc_html($shipment['contents'] ?? ''); ?></td><td><?php echo esc_html($shipment['note'] ?? ''); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
            <?php else : ?><p><?php esc_html_e('No shipment has been recorded.', 'wc-to-b2b'); ?></p><?php endif; ?>

            <h4><?php esc_html_e('Add Shipment', 'wc-to-b2b'); ?></h4>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wc-b2b-inline-form">
                <input type="hidden" name="action" value="wc_b2b_add_shipment" />
                <input type="hidden" name="order_id" value="<?php echo esc_attr($order->get_id()); ?>" />
                <?php wp_nonce_field('wc_b2b_add_shipment_' . $order->get_id()); ?>
                <p><label><?php esc_html_e('Shipping date', 'wc-to-b2b'); ?><br><input required type="date" name="shipping_date" value="<?php echo esc_attr(current_time('Y-m-d')); ?>" /></label></p>
                <p><label><?php esc_html_e('Carrier', 'wc-to-b2b'); ?><br><input required name="carrier" /></label></p>
                <p><label><?php esc_html_e('Tracking number', 'wc-to-b2b'); ?><br><input name="tracking_number" /></label></p>
                <p><label><?php esc_html_e('Tracking URL', 'wc-to-b2b'); ?><br><input type="url" class="widefat" name="tracking_url" /></label></p>
                <p><label><?php esc_html_e('Shipped contents/quantity', 'wc-to-b2b'); ?><br><textarea name="contents" rows="2"></textarea></label></p>
                <p><label><?php esc_html_e('Note', 'wc-to-b2b'); ?><br><textarea name="note" rows="2"></textarea></label></p>
                <p><label><input type="checkbox" name="complete_shipment" value="yes" /> <?php esc_html_e('This shipment completes the order', 'wc-to-b2b'); ?></label></p>
                <?php submit_button(__('Record Shipment', 'wc-to-b2b'), 'primary', 'submit', false); ?>
            </form>
        </div>
        <style>.wc-b2b-ledger .wc-b2b-inline-form{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;background:#f6f7f7;padding:12px;margin-bottom:20px}.wc-b2b-ledger .wc-b2b-inline-form p{margin:0}.wc-b2b-ledger table{margin-bottom:16px}</style>
        <?php
    }

    public function handle_add_payment() {
        $order = $this->authorize_request('wc_b2b_add_payment');
        $amount = isset($_POST['amount']) ? (float) wc_format_decimal(wp_unslash($_POST['amount'])) : 0;
        if ($amount <= 0) {
            $this->redirect_with_notice($order, __('Payment amount must be greater than zero.', 'wc-to-b2b'), 'error');
        }

        $payment = array(
            'id'         => wp_generate_uuid4(),
            'date'       => sanitize_text_field(wp_unslash($_POST['payment_date'] ?? '')),
            'amount'     => $amount,
            'method'     => sanitize_text_field(wp_unslash($_POST['method'] ?? '')),
            'reference'  => sanitize_text_field(wp_unslash($_POST['reference'] ?? '')),
            'note'       => sanitize_textarea_field(wp_unslash($_POST['note'] ?? '')),
            'created_at' => current_time('mysql'),
            'created_by' => get_current_user_id(),
        );

        $payments   = self::get_payments($order);
        $payments[] = $payment;
        $order->update_meta_data('_wc_b2b_payments', $payments);
        $order->save();
        $order->add_order_note(sprintf(__('Offline payment recorded: %1$s (%2$s).', 'wc-to-b2b'), wc_price($amount, array('currency' => $order->get_currency())), $payment['reference'] ?: $payment['method']));

        if (self::get_paid_total($order) + 0.00001 >= (float) $order->get_total() && !$order->get_date_paid()) {
            $order->update_meta_data('_manually_paid', 'yes');
            $order->update_meta_data('_manually_paid_date', current_time('mysql'));
            $order->update_meta_data('_manually_paid_by', get_current_user_id());
            $order->save();
            if (in_array($order->get_status(), array('quote-sent', 'quote-accepted', 'on-hold', 'pending', 'failed'), true)) {
                do_action('wc_b2b_suppress_next_status_email', $order->get_id());
                $order->payment_complete($payment['reference']);
                do_action('wc_b2b_clear_status_email_suppression', $order->get_id());
            } else {
                $order->set_date_paid(time());
                $order->save();
            }
        }

        do_action('wc_b2b_payment_recorded', $order->get_id(), $payment);
        $this->redirect_with_notice($order, __('Payment recorded successfully.', 'wc-to-b2b'));
    }

    public function handle_add_shipment() {
        $order = $this->authorize_request('wc_b2b_add_shipment');
        $shipment = array(
            'id'              => wp_generate_uuid4(),
            'date'            => sanitize_text_field(wp_unslash($_POST['shipping_date'] ?? '')),
            'carrier'         => sanitize_text_field(wp_unslash($_POST['carrier'] ?? '')),
            'tracking_number' => sanitize_text_field(wp_unslash($_POST['tracking_number'] ?? '')),
            'tracking_url'    => esc_url_raw(wp_unslash($_POST['tracking_url'] ?? '')),
            'contents'        => sanitize_textarea_field(wp_unslash($_POST['contents'] ?? '')),
            'note'            => sanitize_textarea_field(wp_unslash($_POST['note'] ?? '')),
            'created_at'      => current_time('mysql'),
            'created_by'      => get_current_user_id(),
        );

        $shipments   = self::get_shipments($order);
        $shipments[] = $shipment;
        $order->update_meta_data('_wc_b2b_shipments', $shipments);
        $order->save();
        $order->add_order_note(sprintf(__('Shipment recorded with %1$s. Tracking: %2$s', 'wc-to-b2b'), $shipment['carrier'], $shipment['tracking_number'] ?: __('not provided', 'wc-to-b2b')));

        $complete = isset($_POST['complete_shipment']) && 'yes' === sanitize_text_field(wp_unslash($_POST['complete_shipment']));
        do_action('wc_b2b_suppress_next_status_email', $order->get_id());
        $order->update_status($complete ? 'shipped' : 'partially-shipped', $complete ? __('All goods have been shipped.', 'wc-to-b2b') : __('A partial shipment has been recorded.', 'wc-to-b2b'));
        do_action('wc_b2b_clear_status_email_suppression', $order->get_id());

        do_action('wc_b2b_shipment_recorded', $order->get_id(), $shipment);
        $this->redirect_with_notice($order, __('Shipment recorded successfully.', 'wc-to-b2b'));
    }

    private function authorize_request($action) {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'wc-to-b2b'));
        }
        $order_id = absint($_POST['order_id'] ?? 0);
        check_admin_referer($action . '_' . $order_id);
        $order = wc_get_order($order_id);
        if (!self::is_b2b_order($order)) {
            wp_die(esc_html__('Invalid B2B order.', 'wc-to-b2b'));
        }
        if (class_exists('WC_B2B_Membership') && (!$order->get_meta('_wc_b2b_quote_number', true) || !in_array($order->get_status(), array('quote-sent', 'quote-accepted', 'processing', 'partially-shipped', 'shipped', 'completed'), true))) {
            wp_die(esc_html__('A formal quote must be sent before recording payments or shipments.', 'wc-to-b2b'));
        }
        return $order;
    }

    private function redirect_with_notice($order, $message, $type = 'success') {
        if (class_exists('WC_Admin_Meta_Boxes')) {
            'error' === $type ? WC_Admin_Meta_Boxes::add_error($message) : WC_Admin_Meta_Boxes::add_message($message);
        }
        wp_safe_redirect($order->get_edit_order_url());
        exit;
    }
}
