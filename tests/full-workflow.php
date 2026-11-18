<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/http.php';

check(WC_B2B_Mode::is_full(), 'Full business mode is active');
$user = wp_insert_user(array('user_login'=>'quoted-customer-' . wp_rand(), 'user_pass'=>wp_generate_password(), 'role'=>'customer'));
$order = wc_create_order(array('customer_id'=>$user, 'status'=>'pending'));
$item = new WC_Order_Item_Product();
$item->set_name('Fixture order item');
$item->set_quantity(1);
$item->set_subtotal('195.37');
$item->set_total('195.37');
$order->add_item($item);
$order->set_total('195.37');
$order->set_billing_email('quote@example.test');
$order->update_meta_data('_is_b2b_order', 'yes');
$order->save();
$GLOBALS['b2b_test_mail'] = array();
do_action('woocommerce_order_action_wc_b2b_send_quote', $order);
$order = wc_get_order($order->get_id());
check($order->has_status('quote-sent') && WC_B2B_Quote::get_valid_until($order) !== '', 'Staff sends a formal quote and records its validity');
check(count($GLOBALS['b2b_test_mail']) > 0, 'Quote arrives at the test mail sink');

$view = WC_B2B_Quote::get_action_url($order, 'view');
$confirm = WC_B2B_Quote::get_action_url($order, 'confirm');
list($status, $html) = b2b_test_request($view, $user);
check($status === 200 && strpos($html, '195.37') !== false, 'Customer reads the formal quote amount');
list($status) = b2b_test_request($confirm, $user);
wp_cache_flush(); // The loopback request ran in another PHP process.
check($status === 302 && wc_get_order($order->get_id())->has_status('quote-accepted'), 'Customer accepts the quote via the signed storefront URL (HTTP ' . $status . ')');
list($status, $html) = b2b_test_request($order->get_view_order_url(), $user);
check($status === 200 && strpos($html, '195.37') !== false, 'Customer can still see historical amounts in My Account');
list($status, $html) = b2b_test_request($order->get_view_order_url());
check(strpos($html, '195.37') === false, 'Guest cannot see the customer account amount');

list($status) = b2b_test_select_mode('catalog');
check($status === 302, 'An existing full installation can switch to catalog');
list($status, $html) = b2b_test_request($view, $user);
check($status === 200 && strpos($html, '195.37') !== false && !preg_match('/<button[^>]+wc-b2b-(?:confirm|cancel)-btn/i', $html), 'Signed quote keeps its amount in catalog mode without active actions');
list($status, $html) = b2b_test_request($order->get_view_order_url(), $user);
check($status === 200 && strpos($html, '195.37') !== false, 'Order history remains readable in catalog mode');
list($status, $html) = b2b_test_request(add_query_arg('token', 'invalid-test-token', $view));
check($status === 302 && strpos($html, '195.37') === false, 'An invalid signed URL cannot fetch quote amounts');
require_once ABSPATH . 'wp-admin/includes/plugin.php';
deactivate_plugins('wc-to-b2b/wc-to-b2b.php');
WC_B2B_Install::uninstall();
check(wc_get_order($order->get_id())->get_total() === '195.37' && get_option('wc_b2b_payment_instructions') === 'Offline instructions for the fixture', 'Deactivation and ordinary uninstall preserve historical prices and configuration');
