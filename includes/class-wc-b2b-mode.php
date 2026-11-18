<?php
/** Installation provenance and explicit choice of business workflow. */
defined('ABSPATH') || exit;

final class WC_B2B_Mode {
    const OPTION = 'wc_b2b_operating_mode';

    public static function get() {
        $mode = get_option(self::OPTION, '');
        if (in_array($mode, array('catalog', 'full'), true)) {
            return $mode;
        }

        // The original installer records its release. A missing, unfamiliar
        // or suffixed version gives no authority to start the full workflow.
        $legacy = get_option('wc_to_b2b_version', '');
        if ($mode === '' && is_string($legacy) && preg_match('/^2\.[01]\.\d+$/D', $legacy)
            && version_compare($legacy, '2.1.2', '<=')
            && is_array(get_option('wc_b2b_membership_tiers')) && get_option('wc_b2b_enable_verification', null) !== null) {
            return 'full';
        }
        return 'unselected';
    }

    public static function is_full() {
        return self::get() === 'full';
    }

    public function __construct() {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_notices', array($this, 'notice'));
        add_action('admin_post_wc_b2b_select_mode', array($this, 'save'));
    }

    public function menu() {
        add_submenu_page('woocommerce', __('B2B Operating Mode', 'wc-to-b2b'), __('B2B Operating Mode', 'wc-to-b2b'),
            'manage_woocommerce', 'wc-b2b-mode', array($this, 'page'));
    }

    public function notice() {
        if (self::get() !== 'unselected' || !current_user_can('manage_woocommerce')) {
            return;
        }
        printf('<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p>',
            esc_html__('WooCommerce to B2B needs an operating mode. Quote workflows are paused until you choose one.', 'wc-to-b2b'),
            esc_url(admin_url('admin.php?page=wc-b2b-mode')),
            esc_html__('Choose mode', 'wc-to-b2b'));
    }

    public function page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $mode = self::get();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('B2B Operating Mode', 'wc-to-b2b'); ?></h1>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wc_b2b_select_mode">
                <?php wp_nonce_field('wc_b2b_select_mode'); ?>
                <fieldset>
                    <legend><?php esc_html_e('Choose how your store takes inquiries', 'wc-to-b2b'); ?></legend>
                    <p><label><input required type="radio" name="mode" value="catalog" <?php checked($mode, 'catalog'); ?>>
                    <strong><?php esc_html_e('Inquiry catalog', 'wc-to-b2b'); ?></strong></label><br>
                    <?php esc_html_e('Hide public product prices and disable storefront checkout. Use your site’s existing inquiry form or contact links.', 'wc-to-b2b'); ?></p>
                    <p><label><input required type="radio" name="mode" value="full" <?php checked($mode, 'full'); ?>>
                    <strong><?php esc_html_e('Full B2B quotation workflow', 'wc-to-b2b'); ?></strong></label><br>
                    <?php esc_html_e('Enable quote checkout, membership pricing and customer verification. Configure optional order automation in B2B Settings.', 'wc-to-b2b'); ?></p>
                </fieldset>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function save() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'wc-to-b2b'), '', array('response' => 403));
        }
        check_admin_referer('wc_b2b_select_mode');
        $mode = isset($_POST['mode']) && is_string($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : '';
        if (!in_array($mode, array('catalog', 'full'), true)) {
            wp_die(esc_html__('Select an operating mode.', 'wc-to-b2b'), '', array('response' => 400));
        }

        $previous = self::get();
        if ($mode !== $previous) {
            WC_B2B_Install::cleanup_events();
            // A resumed workflow must not enroll orders from before the switch.
            foreach (array('wc_b2b_auto_cancel_enabled', 'wc_b2b_auto_reminders_enabled') as $option) {
                delete_option($option . '_since');
                if ($mode === 'full' && get_option($option) === 'yes') {
                    update_option($option . '_since', time(), false);
                }
            }
        }
        update_option(self::OPTION, $mode, false);
        // The account history endpoint is present in every mode.
        add_rewrite_endpoint('b2b-orders', EP_ROOT | EP_PAGES);
        flush_rewrite_rules(false);
        wp_safe_redirect(admin_url('admin.php?page=wc-b2b-mode'));
        exit;
    }
}
