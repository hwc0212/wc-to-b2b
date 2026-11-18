<?php
/** Test-only MU plugin for the site-owned inquiry and test receivers. */
if (wp_get_environment_type() !== 'local' || strpos(DB_NAME, 'gml_regression_') !== 0) {
    return;
}
add_filter('pre_wp_mail', '__return_true', 100);
add_action('woocommerce_single_product_summary', static function () {
    $form_id = (int) get_option('b2b_test_cf7_form');
    echo '<button type="button" data-fixture-inquiry="yes">Get Quote</button>';
    echo '<a href="https://whatsapp.example.test/inquiry" data-fixture-whatsapp="yes">WhatsApp</a>';
    if ($form_id) {
        echo '<div hidden>' . do_shortcode('[contact-form-7 id="' . $form_id . '"]') . '</div>';
    }
}, 25);
add_action('wp_head', static function () {
    if (!is_product()) {
        return;
    }
    echo "<meta content='734.12' property='product:price:amount'>";
    echo '<meta property="product:price:currency" content="USD">';
    echo '<meta name="twitter:label1" content="Price"><meta name="twitter:data1" content="$734.12">';
    echo '<meta property="fixture:non-price" content="Retain">';
});
add_action('wc_ajax_fixture_quick_view', static function () {
    $product = wc_get_product(absint($_POST['product_id'] ?? 0));
    wp_send_json(array('price'=>$product->get_price(), 'html'=>$product->get_price_html(), 'on_sale'=>$product->is_on_sale()));
});
