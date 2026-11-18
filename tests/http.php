<?php
/** Loopback HTTP helpers for the isolated WooCommerce tests. */
function b2b_test_request($url, $user = 0, array $post = null) {
    $headers = array('Host: gml-regression.test');
    $path = wp_make_link_relative($url);
    if ($user) {
        $expires = time() + HOUR_IN_SECONDS;
        $token = WP_Session_Tokens::get_instance($user)->create($expires);
        $login = wp_generate_auth_cookie($user, $expires, 'logged_in', $token);
        $auth = wp_generate_auth_cookie($user, $expires, 'auth', $token);
        $_COOKIE[LOGGED_IN_COOKIE] = $login;
        wp_set_current_user($user);
        $headers[] = 'Cookie: ' . LOGGED_IN_COOKIE . '=' . rawurlencode($login) . '; ' . AUTH_COOKIE . '=' . rawurlencode($auth);
        $headers[] = 'X-WP-Nonce: ' . wp_create_nonce('wp_rest');
        if (isset($post['_nonce_action'])) {
            $post['_wpnonce'] = wp_create_nonce($post['_nonce_action']);
            unset($post['_nonce_action']);
        }
    }
    $options = array('header'=>implode("\r\n", $headers), 'ignore_errors'=>true, 'follow_location'=>0, 'timeout'=>15);
    if ($post !== null) {
        $options['method'] = 'POST';
        $options['header'] .= "\r\nContent-Type: application/x-www-form-urlencoded";
        $options['content'] = http_build_query($post);
    }
    $body = file_get_contents('http://127.0.0.1:18086' . $path, false, stream_context_create(array('http' => $options)));
    $location = '';
    foreach ($http_response_header as $header) {
        if (stripos($header, 'Location: ') === 0) {
            $location = wp_parse_url(substr($header, 10), PHP_URL_PATH);
        }
    }
    return array((int) explode(' ', $http_response_header[0])[1], $body, $location);
}

function b2b_test_select_mode($mode) {
    $admins = get_users(array('role'=>'administrator', 'number'=>1, 'fields'=>'ID'));
    return b2b_test_request(admin_url('admin-post.php'), (int) $admins[0],
        array('action'=>'wc_b2b_select_mode', 'mode'=>$mode, '_nonce_action'=>'wc_b2b_select_mode'));
}
