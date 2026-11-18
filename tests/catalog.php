<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/http.php';
check(WC_B2B_Mode::get() === 'catalog', 'Catalog loads on next WordPress request');
check(!class_exists('WC_B2B_Checkout') && !class_exists('WC_B2B_WhatsApp_Button') && !has_action('wc_b2b_send_quote_email'), 'Quote checkout, WhatsApp injection and quote mail are inactive');
check(!wp_next_scheduled('wc_b2b_cleanup_expired_tokens') && !wp_next_scheduled('wc_b2b_check_expired_orders') && !wp_next_scheduled('wc_b2b_send_payment_reminders'), 'Catalog registers no plugin cron jobs');

function fixture_user($name, $role) {
    return wp_insert_user(array('user_login'=>$name, 'user_pass'=>wp_generate_password(), 'role'=>$role));
}

$admin = (int) get_users(array('role'=>'administrator', 'number'=>1, 'fields'=>'ID'))[0];
$customer = fixture_user('catalog-customer-' . wp_rand(), 'customer');
$vip = fixture_user('catalog-vip-' . wp_rand(), 'customer');
update_user_meta($vip, '_wc_b2b_tier', 'vip');

$simple = new WC_Product_Simple();
$simple->set_name('Catalog fixture simple');
$simple->set_regular_price('873.29');
$simple->set_sale_price('734.12');
$simple->set_status('publish');
$simple->save();

$variable = new WC_Product_Variable();
$variable->set_name('Catalog fixture variable');
$variable->set_status('publish');
$color = new WC_Product_Attribute();
$color->set_name('Finish');
$color->set_options(array('White', 'Black'));
$color->set_visible(true);
$color->set_variation(true);
$variable->set_attributes(array($color));
$variable->save();
$variants = array();
foreach (array('white'=>'997.13', 'black'=>'1011.29') as $finish => $price) {
    $child = new WC_Product_Variation();
    $child->set_parent_id($variable->get_id());
    $child->set_status('publish');
    $child->set_attributes(array('finish' => $finish));
    $child->set_regular_price($price);
    $child->save();
    $variants[] = $child;
}
WC_Product_Variable::sync($variable);

$identities = array('guest'=>0, 'customer'=>$customer, 'vip'=>$vip, 'admin'=>$admin);
foreach ($identities as $label => $user) {
    foreach (array($simple, $variable) as $product) {
        list($status, $html) = b2b_test_request($product->get_permalink(), $user);
        check($status === 200 && strpos($html, $product->get_name()) !== false, "$label loads real product template (HTTP $status)");
        check(substr_count($html, 'data-fixture-inquiry="yes"') === 1 && strpos($html, 'data-fixture-whatsapp') !== false && strpos($html, 'wpcf7-form') !== false, "$label retains site inquiry and WhatsApp");
        check(!preg_match('/873\.29|734\.12|997\.13|1011\.29|product:price:|twitter:data1|class="onsale"|woocommerce-Price-amount|<button[^>]+single_add_to_cart_button/i', $html), "$label public HTML and promotional markup contain no prices or purchase button");
        check(strpos($html, 'fixture:non-price') !== false, "$label unrelated social metadata survives");
        check(!preg_match('/"offers"\s*:/', $html), "$label product JSON-LD contains no offers");
    }
    foreach (array($simple, $variable, $variants[0]) as $product) {
        list($status, $body) = b2b_test_request(rest_url('wc/store/v1/products/'.$product->get_id()), $user);
        $data = json_decode($body, true);
        if ($product->is_type('variation') && $status === 404) {
            continue; // WooCommerce excludes variations from its detail route by default.
        }
        check($status === 200 && $data['id'] === $product->get_id(), "$label gets Store API product");
        check($data['prices']['price'] === '' && $data['prices']['regular_price'] === '' && $data['prices']['sale_price'] === ''
            && $data['prices']['price_range'] === null && $data['price_html'] === '' && !$data['on_sale'] && !$data['is_purchasable'],
            "$label Store API suppresses commerce fields");
    }
    list($status, $body) = b2b_test_request(add_query_arg('wc-ajax', 'get_variation', home_url('/')), $user,
        array('product_id'=>$variable->get_id(), 'attribute_finish'=>'white'));
    $variant = json_decode($body, true);
    check($status === 200 && isset($variant['variation_id']) && $variant['variation_is_visible'] && !$variant['is_purchasable']
        && $variant['display_price'] === null && $variant['display_regular_price'] === null && $variant['price_html'] === '',
        "$label variation lookup keeps choices and hides numeric values");
    list($status, $body) = b2b_test_request(add_query_arg('wc-ajax', 'fixture_quick_view', home_url('/')), $user, array('product_id'=>$simple->get_id()));
    check($status === 200 && json_decode($body, true) === array('price'=>'', 'html'=>'', 'on_sale'=>false), "$label quick-view template receives hidden prices");
}

$shop = wc_get_page_permalink('shop');
list($status, $html) = b2b_test_request(add_query_arg(array('min_price'=>50000, 'orderby'=>'price-desc'), $shop));
check($status === 200 && strpos($html, 'Catalog fixture simple') !== false && strpos($html, 'Catalog fixture variable') !== false, 'Shop query ignores price sorting and filtering');
check(strpos($html, 'woocommerce-Price-amount') === false && strpos($html, 'value="price') === false && strpos($html, 'class="onsale"') === false, 'Product cards and shop ordering controls contain no prices or sale badges');
list($status, $body) = b2b_test_request(add_query_arg(array('min_price'=>5000000, 'orderby'=>'price', 'on_sale'=>'true'), rest_url('wc/store/v1/products')));
check($status === 200 && count(json_decode($body, true)) >= 2, 'Store API list ignores requested price and sale filters');
list($status, $body) = b2b_test_request(add_query_arg('calculate_price_range', 'true', rest_url('wc/store/v1/products/collection-data')));
check($status === 200 && array_key_exists('price_range', json_decode($body, true)) && json_decode($body, true)['price_range'] === null, 'Store API does not expose collection price limits');

foreach (array('cart', 'checkout') as $part) {
    list($status) = b2b_test_request(wc_get_page_permalink($part));
    check($status === 302, "Classic $part page cannot open a new purchase");
    list($status, $body) = b2b_test_request(rest_url('wc/store/v1/'.$part));
    check($status === 403 && json_decode($body, true)['code'] === 'wc_b2b_catalog_only', "Store API blocks $part");
}
list($status, $body) = b2b_test_request(rest_url('wc/store/v1/cart/add-item'), 0, array('id'=>$simple->get_id(), 'quantity'=>1));
check($status === 403, 'Store API blocks a direct add-item request');
list($status, $body) = b2b_test_request(add_query_arg('add-to-cart', $simple->get_id(), $simple->get_permalink()));
check($status === 200 && strpos($body, 'added to your cart') === false, 'A direct classic add-to-cart URL cannot add a product');
list($status, $body) = b2b_test_request(add_query_arg('wc-ajax', 'get_refreshed_fragments', home_url('/')), $admin);
$fragments = json_decode($body, true);
check($status === 200 && $fragments['cart_hash'] === '' && strip_tags(implode('', $fragments['fragments'])) === '', 'Classic mini-cart AJAX exposes no stale cart contents');
list($status, $body) = b2b_test_request(rest_url('wc/store/v1/batch'), 0, array('requests'=>array(
    array('method'=>'POST', 'path'=>'/wc/store/v1/cart/add-item', 'body'=>array('id'=>$simple->get_id(), 'quantity'=>1)),
)));
$batch = json_decode($body, true);
check($status === 207 && $batch['responses'][0]['status'] === 403, 'Batch dispatch enforces the catalog cart restriction');
$count_before = (int) wc_orders_count('checkout-draft');
list($status) = b2b_test_request(rest_url('wc/store/v1/checkout'), 0, array('payment_method'=>'cod'));
check($status === 403 && (int) wc_orders_count('checkout-draft') === $count_before, 'Direct Store API checkout creates no draft');
WC()->initialize_session();
WC()->initialize_cart();
check(WC()->cart->add_to_cart($simple->get_id()) === false, 'WooCommerce cart service rejects the product');
list($status, $body) = b2b_test_request(rest_url('wc/v3/products/'.$simple->get_id()), $admin);
$edit = json_decode($body, true);
check($status === 200 && $edit['regular_price'] === '873.29' && $edit['sale_price'] === '734.12', 'Administrator WooCommerce edit API retains original product prices');
check(wc_get_product($simple->get_id())->get_regular_price('edit') === '873.29' && wc_get_product($variants[0]->get_id())->get_regular_price('edit') === '997.13',
    'Stored product and variation prices are unchanged');
check(!count($GLOBALS['b2b_test_mail'] ?? array()), 'Catalog did not dispatch an email');
