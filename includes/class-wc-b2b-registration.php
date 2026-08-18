<?php
/**
 * Customer account email verification.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_B2B_Registration {

    const VERIFIED_META = '_wc_b2b_email_verified';
    const TOKEN_META    = '_wc_b2b_email_verification_hash';
    const EXPIRES_META  = '_wc_b2b_email_verification_expires';

    public function __construct() {
        add_action('user_register', array($this, 'handle_new_user'), 20, 1);
        add_action('profile_update', array($this, 'handle_profile_update'), 20, 2);
        add_filter('wp_authenticate_user', array($this, 'require_verified_email'), 30, 2);
        add_filter('woocommerce_registration_auth_new_customer', '__return_false');
        add_filter('woocommerce_registration_redirect', array($this, 'registration_redirect'));
        add_filter('pre_option_woocommerce_enable_myaccount_registration', array($this, 'enable_my_account_registration'));
        add_action('template_redirect', array($this, 'handle_verification_link'), 5);
        add_action('woocommerce_before_customer_login_form', array($this, 'render_account_notice'));
        add_filter('login_message', array($this, 'render_wordpress_login_notice'));

        add_action('show_user_profile', array($this, 'render_verification_field'));
        add_action('edit_user_profile', array($this, 'render_verification_field'));
    }

    /**
     * Accounts created before this feature are grandfathered as verified.
     */
    public static function is_user_verified($user_id) {
        $user_id = absint($user_id);
        if (!$user_id) {
            return false;
        }

        return 'no' !== get_user_meta($user_id, self::VERIFIED_META, true);
    }

    public function handle_new_user($user_id) {
        $user = get_userdata($user_id);
        if (!$user) {
            return;
        }

        if (user_can($user, 'manage_woocommerce')) {
            update_user_meta($user_id, self::VERIFIED_META, 'yes');
            return;
        }

        update_user_meta($user_id, self::VERIFIED_META, 'no');
        if (!get_user_meta($user_id, WC_B2B_Membership::USER_META_KEY, true)) {
            update_user_meta($user_id, WC_B2B_Membership::USER_META_KEY, 'registered');
        }
        $this->send_verification_email($user_id);
    }

    public function handle_profile_update($user_id, $old_user_data) {
        $user = get_userdata($user_id);
        if (!$user || user_can($user, 'manage_woocommerce')) {
            return;
        }

        if ($old_user_data instanceof WP_User && 0 !== strcasecmp($old_user_data->user_email, $user->user_email)) {
            update_user_meta($user_id, self::VERIFIED_META, 'no');
            $this->send_verification_email($user_id);
            return;
        }

        if (!current_user_can('manage_woocommerce') || empty($_POST['wc_b2b_verification_field'])) {
            return;
        }

        check_admin_referer('update-user_' . $user_id);
        if (!empty($_POST['wc_b2b_resend_account_verification'])) {
            update_user_meta($user_id, self::VERIFIED_META, 'no');
            $this->send_verification_email($user_id);
            return;
        }

        $verified = isset($_POST['wc_b2b_email_verified']) ? 'yes' : 'no';
        update_user_meta($user_id, self::VERIFIED_META, $verified);

        if ('yes' === $verified) {
            $this->clear_verification_token($user_id);
        }
    }

    public function require_verified_email($user, $password) {
        if (is_wp_error($user) || !$user instanceof WP_User || self::is_user_verified($user->ID)) {
            return $user;
        }

        return new WP_Error(
            'wc_b2b_email_not_verified',
            __('Please verify your email address using the link we sent before signing in.', 'wc-to-b2b')
        );
    }

    private function create_verification_token($user_id) {
        $token   = wp_generate_password(48, false, false);
        $expires = time() + max(1, absint(get_option('wc_b2b_verification_expiry', 48))) * HOUR_IN_SECONDS;

        update_user_meta($user_id, self::TOKEN_META, wp_hash_password($token));
        update_user_meta($user_id, self::EXPIRES_META, $expires);

        return $token;
    }

    private function clear_verification_token($user_id) {
        delete_user_meta($user_id, self::TOKEN_META);
        delete_user_meta($user_id, self::EXPIRES_META);
    }

    private function send_verification_email($user_id) {
        $user = get_userdata($user_id);
        if (!$user || !is_email($user->user_email)) {
            return false;
        }

        $token = $this->create_verification_token($user_id);
        $url   = add_query_arg(array(
            'wc_b2b_verify_account' => '1',
            'user_id'               => $user_id,
            'token'                 => $token,
        ), home_url('/'));
        $subject = sprintf(__('[%s] Verify your customer account', 'wc-to-b2b'), wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES));
        $name    = $user->display_name ?: $user->user_login;

        $message  = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:24px">';
        $message .= '<h2>' . esc_html__('Verify your email address', 'wc-to-b2b') . '</h2>';
        $message .= '<p>' . sprintf(esc_html__('Hello %s,', 'wc-to-b2b'), esc_html($name)) . '</p>';
        $message .= '<p>' . esc_html__('Click the button below to confirm that this email address is valid. You can sign in and view your customer-level prices after verification.', 'wc-to-b2b') . '</p>';
        $message .= '<p style="margin:28px 0"><a href="' . esc_url($url) . '" style="background:#2271b1;color:#fff;padding:12px 20px;text-decoration:none">' . esc_html__('Verify Email', 'wc-to-b2b') . '</a></p>';
        $message .= '<p>' . esc_html__('If the button does not work, copy this link into your browser:', 'wc-to-b2b') . '<br><a href="' . esc_url($url) . '">' . esc_html($url) . '</a></p>';
        $message .= '<p style="color:#666;font-size:12px">' . sprintf(esc_html__('This link expires in %d hours. A newer verification email invalidates earlier links.', 'wc-to-b2b'), max(1, absint(get_option('wc_b2b_verification_expiry', 48)))) . '</p>';
        $message .= '</div>';

        return wp_mail($user->user_email, $subject, $message, array('Content-Type: text/html; charset=UTF-8'));
    }

    public function handle_verification_link() {
        if (empty($_GET['wc_b2b_verify_account'])) {
            return;
        }

        $user_id = isset($_GET['user_id']) ? absint($_GET['user_id']) : 0;
        $token   = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
        $hash    = $user_id ? get_user_meta($user_id, self::TOKEN_META, true) : '';
        $expires = $user_id ? absint(get_user_meta($user_id, self::EXPIRES_META, true)) : 0;
        $token_matches = $user_id && $token && $hash && wp_check_password($token, $hash, $user_id);
        $success = $token_matches && $expires >= time();

        if ($success) {
            update_user_meta($user_id, self::VERIFIED_META, 'yes');
            if (!get_user_meta($user_id, WC_B2B_Membership::USER_META_KEY, true)) {
                update_user_meta($user_id, WC_B2B_Membership::USER_META_KEY, 'registered');
            }
            $this->clear_verification_token($user_id);
            wp_safe_redirect(add_query_arg('wc_b2b_account_verified', '1', wc_get_page_permalink('myaccount')));
            exit;
        }

        if ($token_matches && get_userdata($user_id) && !self::is_user_verified($user_id)) {
            $this->send_verification_email($user_id);
        }
        wp_safe_redirect(add_query_arg('wc_b2b_account_verification_failed', '1', wc_get_page_permalink('myaccount')));
        exit;
    }

    public function render_account_notice() {
        if (!empty($_GET['wc_b2b_account_verified'])) {
            wc_print_notice(__('Email verified successfully. You can now sign in and view your retail price.', 'wc-to-b2b'), 'success');
        } elseif (!empty($_GET['wc_b2b_registration_pending'])) {
            wc_print_notice(__('Registration received. Check your inbox and verify your email before signing in.', 'wc-to-b2b'), 'success');
        } elseif (!empty($_GET['wc_b2b_account_verification_failed'])) {
            wc_print_notice(__('That verification link was invalid or expired. If it was an expired valid link, a new email has been sent; otherwise contact us for help.', 'wc-to-b2b'), 'error');
        }
    }

    public function registration_redirect($redirect) {
        return add_query_arg('wc_b2b_registration_pending', '1', $redirect ?: wc_get_page_permalink('myaccount'));
    }

    public function enable_my_account_registration($pre_option) {
        return 'yes';
    }

    public function render_wordpress_login_notice($message) {
        if (!empty($_GET['wc_b2b_account_verified'])) {
            $message .= '<p class="message">' . esc_html__('Email verified successfully. You can now sign in.', 'wc-to-b2b') . '</p>';
        }
        return $message;
    }

    public function render_verification_field($user) {
        if (!current_user_can('manage_woocommerce') || user_can($user, 'manage_woocommerce')) {
            return;
        }
        ?>
        <h2><?php esc_html_e('B2B Email Verification', 'wc-to-b2b'); ?></h2>
        <input type="hidden" name="wc_b2b_verification_field" value="1" />
        <table class="form-table"><tr>
            <th><label for="wc_b2b_email_verified"><?php esc_html_e('Verified Email', 'wc-to-b2b'); ?></label></th>
            <td>
                <label><input type="checkbox" id="wc_b2b_email_verified" name="wc_b2b_email_verified" value="yes" <?php checked(self::is_user_verified($user->ID)); ?> /> <?php esc_html_e('Allow this customer to sign in', 'wc-to-b2b'); ?></label><br>
                <label><input type="checkbox" name="wc_b2b_resend_account_verification" value="yes" /> <?php esc_html_e('Send a new verification email when this profile is saved', 'wc-to-b2b'); ?></label>
                <p class="description"><?php esc_html_e('New customer accounts must verify their email. Accounts that existed before this feature are treated as verified.', 'wc-to-b2b'); ?></p>
            </td>
        </tr></table>
        <?php
    }
}
