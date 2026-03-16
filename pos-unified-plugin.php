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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'POS_UNIFIED_VERSION', '1.0.0' );
define( 'POS_UNIFIED_FILE', __FILE__ );
define( 'POS_UNIFIED_PATH', plugin_dir_path( __FILE__ ) );
define( 'POS_UNIFIED_URL', plugin_dir_url( __FILE__ ) );

// Custom cron interval — must be registered before activation hook fires
add_filter( 'cron_schedules', function ( $schedules ) {
	$schedules['every_five_minutes'] = array(
		'interval' => 300,
		'display'  => esc_html__( 'Every 5 Minutes', 'pos-unified' ),
	);
	return $schedules;
} );

// Boot after WooCommerce
add_action( 'plugins_loaded', 'pos_unified_init', 20 );

function pos_unified_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="error"><p><strong>POS Unified</strong> requires WooCommerce to be installed and active.</p></div>';
		} );
		return;
	}

	require_once POS_UNIFIED_PATH . 'includes/class-api-client.php';
	require_once POS_UNIFIED_PATH . 'includes/class-store-mapper.php';
	require_once POS_UNIFIED_PATH . 'includes/class-inventory-sync.php';
	require_once POS_UNIFIED_PATH . 'includes/class-order-sync.php';
	require_once POS_UNIFIED_PATH . 'includes/class-webhook-handler.php';
	require_once POS_UNIFIED_PATH . 'includes/class-admin.php';

	POS_Unified_Admin::instance();
	POS_Unified_Inventory_Sync::instance();
	POS_Unified_Order_Sync::instance();
	POS_Unified_Webhook_Handler::instance();
}

// Activation: schedule cron
register_activation_hook( __FILE__, function () {
	if ( ! wp_next_scheduled( 'pos_unified_inventory_sync' ) ) {
		wp_schedule_event( time(), 'every_five_minutes', 'pos_unified_inventory_sync' );
	}
	if ( ! wp_next_scheduled( 'pos_unified_order_sync' ) ) {
		wp_schedule_event( time(), 'every_five_minutes', 'pos_unified_order_sync' );
	}
} );

// Deactivation: clear cron
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'pos_unified_inventory_sync' );
	wp_clear_scheduled_hook( 'pos_unified_order_sync' );
} );
