<?php
/**
 * Customer email notifications for B2B status and ledger updates.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_B2B_Notifications {

    private $suppressed_orders = array();

    public function __construct() {
        add_action('wc_b2b_suppress_next_status_email', array($this, 'suppress_next_status_email'));
        add_action('wc_b2b_clear_status_email_suppression', array($this, 'clear_status_email_suppression'));
        add_action('woocommerce_order_status_changed', array($this, 'send_status_email'), 30, 4);
        add_action('wc_b2b_payment_recorded', array($this, 'send_payment_email'), 10, 2);
        add_action('wc_b2b_shipment_recorded', array($this, 'send_shipment_email'), 10, 2);
    }

    public function suppress_next_status_email($order_id) {
        $this->suppressed_orders[absint($order_id)] = true;
    }

    public function clear_status_email_suppression($order_id) {
        unset($this->suppressed_orders[absint($order_id)]);
    }

    public function send_status_email($order_id, $old_status, $new_status, $order) {
        if (!WC_B2B_Fulfillment::is_b2b_order($order)) {
            return;
        }
        if ($order->get_status() !== $new_status) {
            return;
        }

        if (!empty($this->suppressed_orders[$order_id])) {
            unset($this->suppressed_orders[$order_id]);
            return;
        }

        // These transitions have dedicated verification/quote emails.
        if (in_array($new_status, array('pending', 'b2b-verifying', 'pending-verificat', 'quote-sent'), true)) {
            return;
        }
        if ('verified' === $new_status && !WC_B2B_Membership::is_guest_inquiry($order) && get_option('wc_b2b_auto_quote', 'yes') === 'yes') {
            return;
        }

        $dedupe_key = $old_status . '>' . $new_status . ':' . current_time('Y-m-d H:i');
        if ($order->get_meta('_wc_b2b_last_status_email', true) === $dedupe_key) {
            return;
        }
        $order->update_meta_data('_wc_b2b_last_status_email', $dedupe_key);
        $order->save();

        $subject = sprintf(
            __('Order #%1$s status updated: %2$s', 'wc-to-b2b'),
            $order->get_order_number(),
            wc_get_order_status_name($new_status)
        );
        $body = '<p>' . sprintf(
            esc_html__('Hello %s,', 'wc-to-b2b'),
            esc_html($order->get_billing_first_name())
        ) . '</p>';
        $body .= '<p>' . sprintf(
            esc_html__('Your B2B order #%1$s has changed from “%2$s” to “%3$s”.', 'wc-to-b2b'),
            esc_html($order->get_order_number()),
            esc_html(wc_get_order_status_name($old_status)),
            esc_html(wc_get_order_status_name($new_status))
        ) . '</p>';
        $body .= $this->order_summary($order);
        $body .= $this->view_button($order);
        $this->send($order, $subject, $body);
    }

    public function send_payment_email($order_id, $payment) {
        $order = wc_get_order($order_id);
        if (!WC_B2B_Fulfillment::is_b2b_order($order)) {
            return;
        }

        $subject = sprintf(__('Payment recorded for order #%s', 'wc-to-b2b'), $order->get_order_number());
        $body  = '<p>' . sprintf(esc_html__('Hello %s,', 'wc-to-b2b'), esc_html($order->get_billing_first_name())) . '</p>';
        $body .= '<p>' . esc_html__('We have recorded the following offline payment:', 'wc-to-b2b') . '</p>';
        $body .= '<table cellspacing="0" cellpadding="8" style="width:100%;border-collapse:collapse;border:1px solid #ddd">';
        $body .= '<tr><th style="text-align:left;border:1px solid #ddd">' . esc_html__('Date', 'wc-to-b2b') . '</th><td style="border:1px solid #ddd">' . esc_html($payment['date'] ?? '') . '</td></tr>';
        $body .= '<tr><th style="text-align:left;border:1px solid #ddd">' . esc_html__('Amount', 'wc-to-b2b') . '</th><td style="border:1px solid #ddd">' . wp_kses_post(wc_price((float) ($payment['amount'] ?? 0), array('currency' => $order->get_currency()))) . '</td></tr>';
        $body .= '<tr><th style="text-align:left;border:1px solid #ddd">' . esc_html__('Method', 'wc-to-b2b') . '</th><td style="border:1px solid #ddd">' . esc_html($payment['method'] ?? '') . '</td></tr>';
        $body .= '<tr><th style="text-align:left;border:1px solid #ddd">' . esc_html__('Reference', 'wc-to-b2b') . '</th><td style="border:1px solid #ddd">' . esc_html($payment['reference'] ?? '') . '</td></tr></table>';
        $body .= '<p><strong>' . esc_html__('Outstanding balance:', 'wc-to-b2b') . '</strong> ' . wp_kses_post(wc_price(max(0, (float) $order->get_total() - WC_B2B_Fulfillment::get_paid_total($order)), array('currency' => $order->get_currency()))) . '</p>';
        $body .= $this->view_button($order);
        $this->send($order, $subject, $body);
    }

    public function send_shipment_email($order_id, $shipment) {
        $order = wc_get_order($order_id);
        if (!WC_B2B_Fulfillment::is_b2b_order($order)) {
            return;
        }

        $subject = sprintf(__('Shipment update for order #%s', 'wc-to-b2b'), $order->get_order_number());
        $body  = '<p>' . sprintf(esc_html__('Hello %s,', 'wc-to-b2b'), esc_html($order->get_billing_first_name())) . '</p>';
        $body .= '<p>' . esc_html__('A shipment has been recorded for your B2B order.', 'wc-to-b2b') . '</p>';
        $body .= '<table cellspacing="0" cellpadding="8" style="width:100%;border-collapse:collapse;border:1px solid #ddd">';
        $body .= '<tr><th style="text-align:left;border:1px solid #ddd">' . esc_html__('Shipping date', 'wc-to-b2b') . '</th><td style="border:1px solid #ddd">' . esc_html($shipment['date'] ?? '') . '</td></tr>';
        $body .= '<tr><th style="text-align:left;border:1px solid #ddd">' . esc_html__('Carrier', 'wc-to-b2b') . '</th><td style="border:1px solid #ddd">' . esc_html($shipment['carrier'] ?? '') . '</td></tr>';
        $tracking = esc_html($shipment['tracking_number'] ?? '');
        if (!empty($shipment['tracking_url'])) {
            $tracking = '<a href="' . esc_url($shipment['tracking_url']) . '">' . ($tracking ?: esc_html__('Track shipment', 'wc-to-b2b')) . '</a>';
        }
        $body .= '<tr><th style="text-align:left;border:1px solid #ddd">' . esc_html__('Tracking', 'wc-to-b2b') . '</th><td style="border:1px solid #ddd">' . $tracking . '</td></tr>';
        $body .= '<tr><th style="text-align:left;border:1px solid #ddd">' . esc_html__('Contents', 'wc-to-b2b') . '</th><td style="border:1px solid #ddd">' . nl2br(esc_html($shipment['contents'] ?? '')) . '</td></tr></table>';
        $body .= $this->view_button($order);
        $this->send($order, $subject, $body);
    }

    private function order_summary($order) {
        if (class_exists('WC_B2B_Membership') && WC_B2B_Membership::is_guest_inquiry($order) && !WC_B2B_Membership::has_formal_quote($order)) {
            $show_reference_price = WC_B2B_Membership::can_display_order_prices($order);
            return '<div style="background:#f6f7f7;padding:16px;margin:18px 0">' .
                '<p><strong>' . esc_html__('Inquiry:', 'wc-to-b2b') . '</strong> #' . esc_html($order->get_order_number()) . '</p>' .
                ($show_reference_price
                    ? '<p><strong>' . esc_html__('Retail reference total:', 'wc-to-b2b') . '</strong> ' . wp_kses_post($order->get_formatted_order_total()) . '</p><p>' . esc_html__('The final price will be provided in the formal quote after review.', 'wc-to-b2b') . '</p>'
                    : '<p>' . esc_html__('Prices will be provided in the formal quote after the inquiry is reviewed.', 'wc-to-b2b') . '</p>') .
                '</div>';
        }
        return '<div style="background:#f6f7f7;padding:16px;margin:18px 0">' .
            '<p><strong>' . esc_html__('Quote:', 'wc-to-b2b') . '</strong> ' . esc_html($order->get_meta('_wc_b2b_quote_number', true) ?: '#' . $order->get_order_number()) . '</p>' .
            '<p><strong>' . esc_html__('Total:', 'wc-to-b2b') . '</strong> ' . wp_kses_post($order->get_formatted_order_total()) . '</p>' .
            '</div>';
    }

    private function view_button($order) {
        $url = $order->get_customer_id() ? $order->get_view_order_url() : WC_B2B_Quote::get_action_url($order, 'view');
        return '<p style="margin:24px 0"><a href="' . esc_url($url) . '" style="display:inline-block;padding:11px 18px;background:#2271b1;color:#fff;text-decoration:none">' . esc_html__('View Order', 'wc-to-b2b') . '</a></p>';
    }

    private function send($order, $subject, $body) {
        $email = $order->get_billing_email();
        if (!$email || !is_email($email)) {
            return false;
        }
        if (function_exists('WC') && WC()->mailer()) {
            $body = WC()->mailer()->wrap_message($subject, $body);
        }
        return wp_mail($email, $subject, $body, array('Content-Type: text/html; charset=UTF-8'));
    }
}
