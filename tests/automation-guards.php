<?php
/** Real WooCommerce database regression. Run with B2B_TEST_WP_ROOT pointing to a disposable installation. */
require __DIR__ . '/bootstrap.php';

$GLOBALS['b2b_test_mail'] = array();
$GLOBALS['b2b_test_http'] = array();
$cancel = 'wc_b2b_check_expired_orders';
$remind = 'wc_b2b_send_payment_reminders';

function automation_order($status, $valid_until, $age = 8, array $meta = array()) {
    $order = wc_create_order(array('status' => 'pending'));
    check($order instanceof WC_Order, 'Create WooCommerce order');
    $order->set_billing_email('mailbox@example.test');
    $order->set_billing_phone('+12025550123');
    $order->set_total('159.27');
    $order->set_date_created(time() - DAY_IN_SECONDS * $age);
    $order->update_meta_data('_is_b2b_order', 'yes');
    $order->update_meta_data('_email_verified', 'yes');
    $order->update_meta_data('_wc_b2b_quote_valid_until', $valid_until);
    foreach ($meta as $key => $value) {
        $order->update_meta_data($key, $value);
    }
    $order->set_status($status);
    $order->save();
    // A normal quote-sent transition generates a fresh validity date. Set the
    // explicit test deadline after that transition, including missing dates.
    $order->update_meta_data('_wc_b2b_quote_valid_until', $valid_until);
    $order->update_meta_data('_wc_b2b_quote_created_at', wp_date('Y-m-d H:i:s', time() - DAY_IN_SECONDS * $age));
    $order->save_meta_data();
    return $order->get_id();
}

$past = wp_date('Y-m-d', time() - DAY_IN_SECONDS);
$future = wp_date('Y-m-d', time() + DAY_IN_SECONDS);
$expired_id = automation_order('quote-sent', $past);
$historic_id = automation_order('quote-sent', $past, 50);
$historic_reminder = automation_order('quote-sent', $future, 50);
$missing_id = automation_order('quote-sent', '');
$malformed_id = automation_order('quote-sent', '2026-02-31');
$accepted_id = automation_order('quote-accepted', $past);
$partial_id = automation_order('quote-sent', $past, 8, array('_wc_b2b_payments'=>array(array('amount'=>5))));
$protected = array();
foreach (array('processing', 'partially-shipped', 'shipped') as $status) {
    $protected[$status] = automation_order($status, $past);
}
$reminder_id = automation_order('quote-sent', $future);
$whatsapp_id = automation_order('quote-sent', $future, 8, array('_verified_via'=>'whatsapp'));
update_option('wc_b2b_whatsapp_enabled', 'yes');
update_option('wc_b2b_whatsapp_api_url', 'https://whatsapp.example.test/send');
update_option('wc_b2b_whatsapp_api_key', 'fixture-only');
$GLOBALS['b2b_test_mail'] = $GLOBALS['b2b_test_http'] = array();

check(!WC_B2B_Quote::is_quote_valid(wc_get_order($expired_id)), 'The quote expires while its order status remains unchanged');
foreach (array('cancel', 'reminders') as $task) {
    WC_B2B_Order_Manager::configure_automation($task, false);
}
foreach (array($cancel, $remind) as $event) {
    wp_schedule_single_event(time() + 600, $event);
}
do_action($cancel);
do_action($remind);
check(!count($GLOBALS['b2b_test_mail']) && !count($GLOBALS['b2b_test_http']) && wc_get_order($expired_id)->get_status() === 'quote-sent', 'Old callbacks do nothing when disabled');
WC_B2B_Order_Manager::schedule_cron_jobs();
check(!wp_next_scheduled($cancel) && !wp_next_scheduled($remind), 'Disabled jobs are unscheduled');

WC_B2B_Order_Manager::configure_automation('reminders', true);
check(wp_next_scheduled($remind) && !wp_next_scheduled($cancel), 'Reminders have an independent switch');
do_action($remind);
check(!count($GLOBALS['b2b_test_mail']), 'Enabling reminders leaves the historical backlog untouched');
update_option('wc_b2b_auto_reminders_enabled_since', time() - 10 * DAY_IN_SECONDS);
do_action($remind);
do_action($remind);
check(count($GLOBALS['b2b_test_mail']) === 1 && count($GLOBALS['b2b_test_http']) === 1, 'One email and one WhatsApp reminder are captured across two runs');
check(wc_get_order($reminder_id)->get_meta('_last_payment_reminder') && wc_get_order($whatsapp_id)->get_meta('_last_payment_reminder'), 'Both deliveries have persisted timestamps');
check(!wc_get_order($historic_reminder)->get_meta('_last_payment_reminder'), 'Historical reminders remain untouched');

WC_B2B_Order_Manager::configure_automation('cancel', true);
do_action($cancel);
check(wc_get_order($expired_id)->get_status() === 'quote-sent', 'Enabling cancellation does not cancel existing orders');
update_option('wc_b2b_auto_cancel_enabled_since', time() - 10 * DAY_IN_SECONDS);
do_action($cancel);
do_action($cancel);
check(wc_get_order($expired_id)->get_status() === 'cancelled', 'Eligible new expired quote is cancelled');
foreach (array($historic_id, $missing_id, $malformed_id, $partial_id) as $id) {
    check(wc_get_order($id)->get_status() === 'quote-sent', 'Historic, incomplete or partially paid order remains under manual control');
}
check(wc_get_order($accepted_id)->get_status() === 'quote-accepted', 'Accepted quote survives auto cancellation');
foreach ($protected as $status => $id) {
    check(wc_get_order($id)->get_status() === $status, 'Fulfilled order status is preserved: ' . $status);
}
check(wc_get_order($expired_id)->get_total() === '159.27', 'Stored amount remains intact');
