<?php
/**
 * Plugin Name: WooCommerce to B2B
 * Plugin URI: https://github.com/hwc0212/wc-to-b2b
 * Description: B2B membership pricing, online quotations, offline payment records, shipment tracking, customer account history, and email notifications for WooCommerce.
 * Version: 2.0.0
 * Author: huwencai.com
 * Author URI: https://huwencai.com
 * Text Domain: wc-to-b2b
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Tested up to: 6.5
 * WC requires at least: 5.0
 * WC tested up to: 8.5
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Network: false
 * GitHub Plugin URI: hwc0212/wc-to-b2b
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WC_TO_B2B_VERSION', '2.0.0');
define('WC_TO_B2B_PLUGIN_FILE', __FILE__);
define('WC_TO_B2B_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('WC_TO_B2B_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WC_TO_B2B_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main WC_To_B2B Class
 */
final class WC_To_B2B {
    
    /**
     * The single instance of the class.
     */
    protected static $_instance = null;
    
    /**
     * Main WC_To_B2B Instance.
     */
    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }
    
    /**
     * WC_To_B2B Constructor.
     */
    public function __construct() {
        add_action('before_woocommerce_init', array($this, 'declare_woocommerce_compatibility'));
        $this->init_hooks();
    }
    
    /**
     * Hook into actions and filters.
     */
    private function init_hooks() {
        add_action('init', array($this, 'init'), 0);
        add_action('plugins_loaded', array($this, 'load_plugin_textdomain'));
        add_action('plugins_loaded', array($this, 'bootstrap'), 20);
    }

    /**
     * Load integrations after all plugins, including WooCommerce, are available.
     */
    public function bootstrap() {
        if (!$this->is_woocommerce_active()) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        $this->includes();
        $this->init_classes();
    }
    
    /**
     * Initialize the plugin.
     */
    public function init() {
        // Before init action.
        do_action('wc_to_b2b_before_init');

        if (class_exists('WC_B2B_Install') && get_option('wc_to_b2b_version') !== WC_TO_B2B_VERSION) {
            WC_B2B_Install::install();
        }
        
        // Add cleanup event handlers
        add_action('wc_b2b_cleanup_expired_tokens', array('WC_B2B_Install', 'cleanup_expired_tokens'));
        add_action('wc_b2b_cleanup_old_tokens', array('WC_B2B_Install', 'cleanup_old_tokens'));
        
        // Init action.
        do_action('wc_to_b2b_init');
    }
    
    /**
     * Load plugin textdomain.
     */
    public function load_plugin_textdomain() {
        load_plugin_textdomain('wc-to-b2b', false, dirname(WC_TO_B2B_PLUGIN_BASENAME) . '/languages/');
    }

    /**
     * Declare compatibility with modern WooCommerce order storage.
     */
    public function declare_woocommerce_compatibility() {
        if (class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
            Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', WC_TO_B2B_PLUGIN_FILE, true);
        }
    }
    
    /**
     * Include required core files.
     */
    public function includes() {
        $includes = array(
            'class-wc-b2b-install.php',
            'class-wc-b2b-checkout.php',
            'class-wc-b2b-membership.php',
            'class-wc-b2b-quote.php',
            'class-wc-b2b-order.php',
            'class-wc-b2b-order-manager.php',
            'class-wc-b2b-fulfillment.php',
            'class-wc-b2b-account.php',
            'class-wc-b2b-email.php',
            'class-wc-b2b-notifications.php',
            'class-wc-b2b-admin.php',
            'class-wc-b2b-whatsapp.php',
            'class-wc-b2b-whatsapp-button.php',
            'class-wc-b2b-ajax.php'
        );
        
        foreach ($includes as $file) {
            $filepath = WC_TO_B2B_PLUGIN_PATH . 'includes/' . $file;
            if (file_exists($filepath)) {
                include_once $filepath;
            } else {
                error_log('WC B2B: Missing file ' . $filepath);
            }
        }
    }
    
    /**
     * Initialize classes.
     */
    public function init_classes() {
        $classes = array(
            'WC_B2B_Checkout',
            'WC_B2B_Membership',
            'WC_B2B_Quote',
            'WC_B2B_Order',
            'WC_B2B_Order_Manager',
            'WC_B2B_Fulfillment',
            'WC_B2B_Account',
            'WC_B2B_Email',
            'WC_B2B_Notifications',
            'WC_B2B_Admin',
            'WC_B2B_WhatsApp',
            'WC_B2B_WhatsApp_Button',
            'WC_B2B_Ajax'
        );
        
        foreach ($classes as $class) {
            if (class_exists($class)) {
                new $class();
            } else {
                error_log('WC B2B: Missing class ' . $class);
            }
        }
    }
    
    /**
     * Check if WooCommerce is active.
     */
    private function is_woocommerce_active() {
        return class_exists('WooCommerce');
    }
    
    /**
     * WooCommerce missing notice.
     */
    public function woocommerce_missing_notice() {
        $class = 'notice notice-error';
        $message = sprintf(
            /* translators: %s: WooCommerce download link */
            __('WooCommerce to B2B requires WooCommerce to be installed and active. You can download %s here.', 'wc-to-b2b'),
            '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>'
        );
        
        printf('<div class="%1$s"><p><strong>%2$s</strong></p></div>', esc_attr($class), wp_kses_post($message));
    }
    
    /**
     * Get plugin version.
     */
    public function get_version() {
        return WC_TO_B2B_VERSION;
    }
    
    /**
     * Get plugin path.
     */
    public function plugin_path() {
        return WC_TO_B2B_PLUGIN_PATH;
    }
    
    /**
     * Get plugin URL.
     */
    public function plugin_url() {
        return WC_TO_B2B_PLUGIN_URL;
    }
}

/**
 * Main instance of WC_To_B2B.
 */
function WC_To_B2B() {
    return WC_To_B2B::instance();
}

// Global for backwards compatibility.
$GLOBALS['wc_to_b2b'] = WC_To_B2B();

// The installer must be available when WordPress invokes the activation callback.
if (!class_exists('WC_B2B_Install')) {
    require_once WC_TO_B2B_PLUGIN_PATH . 'includes/class-wc-b2b-install.php';
}

// Activation hook
register_activation_hook(__FILE__, array('WC_B2B_Install', 'install'));

// Deactivation hook
register_deactivation_hook(__FILE__, array('WC_B2B_Install', 'cleanup_events'));

// Uninstall hook
register_uninstall_hook(__FILE__, array('WC_B2B_Install', 'uninstall'));
