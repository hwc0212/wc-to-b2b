<?php
/** Provision only a disposable WordPress installation. */
require __DIR__ . '/bootstrap.php';
switch ($argv[1]) {
    case 'activate':
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        foreach (array('woocommerce/woocommerce.php', 'wc-to-b2b/wc-to-b2b.php') as $file) {
            $result = activate_plugin($file);
            check(!is_wp_error($result), 'Activate ' . $file);
        }
        delete_transient('_wc_activation_redirect'); // The WooCommerce setup wizard is outside this fixture.
        return;
    case 'legacy':
        check(WC_TO_B2B_VERSION === '2.1.2' && get_option('wc_to_b2b_version') === '2.1.2', 'Original installer actually ran');
        update_option('wc_b2b_payment_instructions', 'Offline instructions for the fixture');
        update_option('wc_b2b_enable_whatsapp_button', 'no');
        return;
    case 'legacy-pending':
        update_option('wc_to_b2b_version', '2.1.2-sihon.2');
        wp_schedule_single_event(time() + 3600, 'b2b_fixture_unrelated_event');
        wp_schedule_single_event(time() + 3600, 'wc_b2b_send_payment_reminders');
        $user = wp_insert_user(array('user_login'=>'legacy-tier-' . wp_rand(), 'user_pass'=>wp_generate_password(), 'role'=>'customer'));
        update_user_meta($user, '_wc_b2b_tier', 'gold');
        update_option('b2b_test_legacy_user', $user);
        return;
    case 'form-plugin':
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $result = activate_plugin('contact-form-7/wp-contact-form-7.php');
        check(!is_wp_error($result), 'Activate Contact Form 7 fixture');
        return;
    case 'form':
        $form = WPCF7_ContactForm::get_template(array('title'=>'Site-owned inquiry fixture'));
        $form->set_properties(array('form'=>'<label>Message [textarea inquiry]</label>[submit "Send inquiry"]'));
        update_option('b2b_test_cf7_form', $form->save());
        return;
    case 'select-full':
        require __DIR__ . '/http.php';
        list($status) = b2b_test_select_mode('full');
        check($status === 302, 'Select full mode through WordPress');
        return;
    case 'flush':
        flush_rewrite_rules(false);
        return;
    case 'prepare-pages':
        WC_Install::create_pages();
        update_option('woocommerce_coming_soon', 'no');
        global $wp_rewrite;
        $wp_rewrite->set_permalink_structure('/%postname%/');
        flush_rewrite_rules(false);
        return;
}
