<?php
/**
 * Installation related functions and actions.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WC_B2B_Install Class.
 */
class WC_B2B_Install {
    
    /**
     * Install WC_To_B2B.
     */
    public static function install() {
        if (!defined('WC_TO_B2B_INSTALLING')) {
            define('WC_TO_B2B_INSTALLING', true);
        }
        
        self::create_tables();
        self::create_options();
        self::migrate_membership_data();
        self::schedule_cleanup_events();
        self::update_version();
        add_rewrite_endpoint('b2b-orders', EP_ROOT | EP_PAGES);
        flush_rewrite_rules();
        
        // Trigger action
        do_action('wc_to_b2b_installed');
    }
    
    /**
     * Set up the database tables.
     */
    private static function create_tables() {
        global $wpdb;
        
        $wpdb->hide_errors();
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        
        // Create verification tokens table
        $table_name = $wpdb->prefix . 'wc_b2b_verification_tokens';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            token varchar(255) NOT NULL,
            type varchar(50) NOT NULL DEFAULT 'email',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime NOT NULL,
            used_at datetime NULL,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY token (token),
            KEY expires_at (expires_at),
            KEY type (type)
        ) $charset_collate;";
        
        dbDelta($sql);
        
        // Check if table was created successfully
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            error_log('WC B2B: Failed to create verification tokens table');
        }
    }
    
    /**
     * Default options.
     */
    private static function create_options() {
        $default_options = array(
            'wc_b2b_enable_verification' => 'yes',
            'wc_b2b_verification_expiry' => '48', // hours
            'wc_b2b_order_expiry' => '21', // days (3 weeks)
            'wc_b2b_payment_reminder_interval' => '7', // days (weekly)
            'wc_b2b_admin_email' => get_option('admin_email'),
            'wc_b2b_whatsapp_enabled' => 'no',
            'wc_b2b_whatsapp_api_url' => '',
            'wc_b2b_whatsapp_api_key' => '',
            'wc_b2b_checkout_fields' => array(
                'phone' => 'required',
                'email' => 'required',
                'message' => 'optional'
            ),
            'wc_b2b_complete_uninstall' => 'no',
            'wc_b2b_membership_tiers' => array(
                array('id' => 'registered', 'name' => __('Registered Customer', 'wc-to-b2b'), 'discount' => 0),
                array('id' => 'regular', 'name' => __('Regular Customer', 'wc-to-b2b'), 'discount' => 10),
                array('id' => 'vip', 'name' => __('VIP Customer', 'wc-to-b2b'), 'discount' => 20)
            ),
            'wc_b2b_guest_price_display' => 'hide',
            'wc_b2b_auto_quote' => 'yes',
            'wc_b2b_require_account' => 'no',
            'wc_b2b_verify_guests' => 'yes',
            'wc_b2b_quote_validity_days' => '21',
            'wc_b2b_payment_instructions' => ''
        );
        
        foreach ($default_options as $option => $value) {
            if (false === get_option($option, false)) {
                add_option($option, $value);
            }
        }
    }

    /**
     * Replace the former free-form levels with the three supported customer levels.
     */
    private static function migrate_membership_data() {
        global $wpdb;

        $existing = get_option('wc_b2b_membership_tiers', array());
        $by_id    = array();
        if (is_array($existing)) {
            foreach ($existing as $tier) {
                $id = sanitize_key($tier['id'] ?? '');
                if ($id) {
                    $by_id[$id] = $tier;
                }
            }
        }

        $regular = $by_id['regular'] ?? ($by_id['silver'] ?? array());
        $vip     = $by_id['vip'] ?? ($by_id['gold'] ?? array());
        update_option('wc_b2b_membership_tiers', array(
            array('id' => 'registered', 'name' => __('Registered Customer', 'wc-to-b2b'), 'discount' => 0),
            array('id' => 'regular', 'name' => __('Regular Customer', 'wc-to-b2b'), 'discount' => min(100, max(0, (float) ($regular['discount'] ?? 10)))),
            array('id' => 'vip', 'name' => __('VIP Customer', 'wc-to-b2b'), 'discount' => min(100, max(0, (float) ($vip['discount'] ?? 20)))),
        ));

        $wpdb->update($wpdb->usermeta, array('meta_value' => 'registered'), array('meta_key' => '_wc_b2b_tier', 'meta_value' => 'standard'), array('%s'), array('%s', '%s'));
        $wpdb->update($wpdb->usermeta, array('meta_value' => 'regular'), array('meta_key' => '_wc_b2b_tier', 'meta_value' => 'silver'), array('%s'), array('%s', '%s'));
        $wpdb->update($wpdb->usermeta, array('meta_value' => 'vip'), array('meta_key' => '_wc_b2b_tier', 'meta_value' => 'gold'), array('%s'), array('%s', '%s'));
    }
    
    /**
     * Schedule cleanup events.
     */
    private static function schedule_cleanup_events() {
        // Schedule daily cleanup of expired tokens
        if (!wp_next_scheduled('wc_b2b_cleanup_expired_tokens')) {
            wp_schedule_event(time(), 'daily', 'wc_b2b_cleanup_expired_tokens');
        }
        
        // Schedule weekly cleanup of old used tokens (older than 30 days)
        if (!wp_next_scheduled('wc_b2b_cleanup_old_tokens')) {
            wp_schedule_event(time(), 'weekly', 'wc_b2b_cleanup_old_tokens');
        }
    }
    
    /**
     * Update WC version to current.
     */
    private static function update_version() {
        delete_option('wc_to_b2b_version');
        add_option('wc_to_b2b_version', WC_TO_B2B_VERSION);
    }
    
    /**
     * Uninstall WC_To_B2B.
     */
    public static function uninstall() {
        // Check if complete uninstall is enabled
        if (get_option('wc_b2b_complete_uninstall', 'no') === 'yes') {
            self::remove_all_data();
        } else {
            // Only remove scheduled events and temporary data
            self::cleanup_events();
            self::cleanup_expired_tokens();
        }
    }
    
    /**
     * Remove all plugin data (complete uninstall).
     */
    private static function remove_all_data() {
        global $wpdb;
        
        // Remove database tables
        $table_name = $wpdb->prefix . 'wc_b2b_verification_tokens';
        $wpdb->query("DROP TABLE IF EXISTS $table_name");
        
        // Remove all plugin options
        $options_to_remove = array(
            'wc_b2b_enable_verification',
            'wc_b2b_verification_expiry',
            'wc_b2b_order_expiry',
            'wc_b2b_payment_reminder_interval',
            'wc_b2b_admin_email',
            'wc_b2b_whatsapp_enabled',
            'wc_b2b_whatsapp_api_url',
            'wc_b2b_whatsapp_api_key',
            'wc_b2b_enable_whatsapp_button',
            'wc_b2b_whatsapp_phone',
            'wc_b2b_whatsapp_button_text',
            'wc_b2b_checkout_fields',
            'wc_b2b_complete_uninstall',
            'wc_b2b_membership_tiers',
            'wc_b2b_guest_price_display',
            'wc_b2b_auto_quote',
            'wc_b2b_require_account',
            'wc_b2b_verify_guests',
            'wc_b2b_quote_validity_days',
            'wc_b2b_payment_instructions',
            'wc_to_b2b_version'
        );
        
        foreach ($options_to_remove as $option) {
            delete_option($option);
        }
        
        // Remove order meta data
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_wc_b2b_%' OR meta_key LIKE '_is_b2b_order%' OR meta_key LIKE '_order_message%' OR meta_key LIKE '_email_verified%' OR meta_key LIKE '_whatsapp_verified%' OR meta_key LIKE '_verified_via%' OR meta_key LIKE '_verification_token%' OR meta_key LIKE '_manually_paid%' OR meta_key LIKE '_last_payment_reminder%'");
        $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key = '_wc_b2b_tier' OR meta_key LIKE '_wc_b2b_email_%'");

        $hpos_meta_table = $wpdb->prefix . 'wc_orders_meta';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hpos_meta_table)) === $hpos_meta_table) {
            $wpdb->query("DELETE FROM {$hpos_meta_table} WHERE meta_key LIKE '_wc_b2b_%' OR meta_key IN ('_is_b2b_order', '_order_message', '_email_verified', '_whatsapp_verified', '_verified_via', '_verification_token', '_manually_paid', '_manually_paid_date', '_manually_paid_by', '_last_payment_reminder')");
        }
        
        // Remove scheduled events
        self::cleanup_events();
        
        // Remove custom order statuses (they will be cleaned up automatically when plugin is deactivated)
    }
    
    /**
     * Cleanup scheduled events.
     */
    public static function cleanup_events() {
        wp_clear_scheduled_hook('wc_b2b_cleanup_expired_tokens');
        wp_clear_scheduled_hook('wc_b2b_cleanup_old_tokens');
        wp_clear_scheduled_hook('wc_b2b_check_expired_orders');
        wp_clear_scheduled_hook('wc_b2b_send_payment_reminders');
    }
    
    /**
     * Cleanup expired verification tokens.
     */
    public static function cleanup_expired_tokens() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wc_b2b_verification_tokens';
        
        // Delete expired and unused tokens
        $deleted = $wpdb->query(
            "DELETE FROM $table_name 
             WHERE expires_at < NOW() 
             AND used_at IS NULL"
        );
        
        if ($deleted > 0) {
            error_log("WC B2B: Cleaned up $deleted expired verification tokens");
        }
        
        return $deleted;
    }
    
    /**
     * Cleanup old used tokens (older than 30 days).
     */
    public static function cleanup_old_tokens() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wc_b2b_verification_tokens';
        
        // Delete used tokens older than 30 days
        $deleted = $wpdb->query(
            "DELETE FROM $table_name 
             WHERE used_at IS NOT NULL 
             AND used_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        
        if ($deleted > 0) {
            error_log("WC B2B: Cleaned up $deleted old used verification tokens");
        }
        
        return $deleted;
    }
}
