<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/http.php';

$table = $wpdb->prefix . 'wc_b2b_verification_tokens';
$tokens_installed = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
if ($argv[1] === 'legacy') {
    check(WC_TO_B2B_VERSION === '2.2.0' && WC_B2B_Mode::get() === 'full', 'Original 2.1.2 upgrade continues full mode');
    check($tokens_installed && get_option('wc_b2b_payment_instructions') === 'Offline instructions for the fixture', 'Full workflow data survives upgrade');
    check(has_action('wc_b2b_send_quote_email') && class_exists('WC_B2B_Checkout'), 'Quotation and email handlers remain available');
    check(!wp_next_scheduled('wc_b2b_check_expired_orders') && !wp_next_scheduled('wc_b2b_send_payment_reminders'), 'Earlier implicit jobs are replaced by separate opt-in switches');
    return;
}
check(WC_B2B_Mode::get() === 'unselected', 'New or unresolved installation waits for administrator choice');
check(!$tokens_installed && !class_exists('WC_B2B_Checkout') && !has_action('wc_b2b_send_quote_email'), 'Activation and upgrade do not install full workflow services');
check(!wp_next_scheduled('wc_b2b_cleanup_expired_tokens') && !wp_next_scheduled('wc_b2b_check_expired_orders'), 'Unselected mode does not enqueue plugin tasks');
if ($argv[1] === 'ambiguous') {
    check(wp_next_scheduled('b2b_fixture_unrelated_event') && !wp_next_scheduled('wc_b2b_send_payment_reminders'), 'Old plugin jobs stop while unrelated WordPress jobs remain');
    $legacy_user = get_option('b2b_test_legacy_user');
    check(get_user_meta($legacy_user, '_wc_b2b_tier', true) === 'gold', 'Unidentified SIHON-like installation leaves membership data alone');
    delete_option('wc_to_b2b_version');
    check(WC_B2B_Mode::get() === 'unselected', 'Missing legacy version gives no authority to run migrations');
}
list($status, , $path) = b2b_test_select_mode('catalog');
check($status === 302 && $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", WC_B2B_Mode::OPTION)) === 'catalog', 'Select catalog through authenticated WordPress mode form (HTTP ' . $status . ' to ' . $path . ')');
