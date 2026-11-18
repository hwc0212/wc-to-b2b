<?php
$root = getenv('B2B_TEST_WP_ROOT');
if (PHP_SAPI !== 'cli' || !$root || getenv('B2B_TEST_DISPOSABLE') !== '1') {
    exit(2);
}
require rtrim($root, '/') . '/wp-load.php';
if (wp_get_environment_type() !== 'local' || strpos(DB_NAME, 'gml_regression_') !== 0) {
    exit(2);
}

function check($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS $message\n";
}

add_filter('pre_wp_mail', static function ($result, $mail) {
    $GLOBALS['b2b_test_mail'][] = $mail;
    return true;
}, 1, 2);
add_filter('pre_http_request', static function ($result, $args, $url) {
    if ($url === 'https://whatsapp.example.test/send') {
        $GLOBALS['b2b_test_http'][] = $args;
        return array('response' => array('code' => 200), 'body' => '{}');
    }
    return new WP_Error('b2b_test_http_blocked');
}, 1, 3);
