<?php
/**
 * Plugin Name: POS Unified - Diacos Integration
 * Plugin URI:  https://opdee.com
 * Description: Syncs WooCommerce inventory and orders with Diacos POS via API key auth. Maps WC locations to Diacos stores.
 * Version:     1.0.0
 * Author:      Opdee
 * Author URI:  https://opdee.com
 * License:     GPL-2.0+
 * Text Domain: pos-unified
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 5.8
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 */

// Prevent direct access
defined('ABSPATH') || exit;

// Plugin constants
define('POS_UNIFIED_VERSION', '1.0.0');
define('POS_UNIFIED_FILE', __FILE__);
define('POS_UNIFIED_PATH', plugin_dir_path(__FILE__));
define('POS_UNIFIED_URL', plugin_dir_url(__FILE__));
define('POS_UNIFIED_BASENAME', plugin_basename(__FILE__));

/**
 * Custom cron interval
 */
add_filter('cron_schedules', 'pos_unified_cron_schedules');
function pos_unified_cron_schedules($schedules) {
    $schedules['every_five_minutes'] = array(
        'interval' => 300,
        'display'  => 'Every 5 Minutes',
    );
    return $schedules;
}

/**
 * Check if WooCommerce is active
 */
function pos_unified_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'pos_unified_wc_missing_notice');
        return false;
    }
    return true;
}

/**
 * WooCommerce missing notice
 */
function pos_unified_wc_missing_notice() {
    echo '<div class="notice notice-error"><p><strong>POS Unified</strong> requires WooCommerce to be installed and active.</p></div>';
}

/**
 * Initialize the plugin
 */
function pos_unified_init() {
    if (!pos_unified_check_woocommerce()) {
        return;
    }

    // Include required files
    require_once POS_UNIFIED_PATH . 'includes/class-api-client.php';
    require_once POS_UNIFIED_PATH . 'includes/class-store-mapper.php';
    require_once POS_UNIFIED_PATH . 'includes/class-inventory-sync.php';
    require_once POS_UNIFIED_PATH . 'includes/class-order-sync.php';
    require_once POS_UNIFIED_PATH . 'includes/class-webhook-handler.php';
    require_once POS_UNIFIED_PATH . 'includes/class-admin.php';

    // HPOS compatibility
    add_action('before_woocommerce_init', 'pos_unified_declare_hpos_compat');

    // Initialize components
    POS_Unified_Admin::instance();
    POS_Unified_Inventory_Sync::instance();
    POS_Unified_Order_Sync::instance();
    POS_Unified_Webhook_Handler::instance();
}
add_action('plugins_loaded', 'pos_unified_init', 20);

/**
 * Declare HPOS compatibility
 */
function pos_unified_declare_hpos_compat() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', POS_UNIFIED_FILE, true);
    }
}

/**
 * Activation hook
 */
function pos_unified_activate() {
    if (!wp_next_scheduled('pos_unified_inventory_sync')) {
        wp_schedule_event(time(), 'every_five_minutes', 'pos_unified_inventory_sync');
    }
    if (!wp_next_scheduled('pos_unified_order_sync')) {
        wp_schedule_event(time(), 'every_five_minutes', 'pos_unified_order_sync');
    }
}
register_activation_hook(__FILE__, 'pos_unified_activate');

/**
 * Deactivation hook
 */
function pos_unified_deactivate() {
    wp_clear_scheduled_hook('pos_unified_inventory_sync');
    wp_clear_scheduled_hook('pos_unified_order_sync');
}
register_deactivation_hook(__FILE__, 'pos_unified_deactivate');

/**
 * Plugin action links
 */
function pos_unified_action_links($links) {
    $plugin_links = array(
        '<a href="' . admin_url('admin.php?page=pos-unified') . '">Settings</a>',
    );
    return array_merge($plugin_links, $links);
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'pos_unified_action_links');
