<?php
/**
 * Webhook Handler — receives push events from Diacos for real-time sync.
 *
 * REST endpoint: POST /wp-json/pos-unified/v1/webhook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class POS_Unified_Webhook_Handler {

	private static $instance = null;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
			self::$instance->hooks();
		}
		return self::$instance;
	}

	private function hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route( 'pos-unified/v1', '/webhook', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_webhook' ),
			'permission_callback' => array( $this, 'verify_signature' ),
		) );
	}

	/**
	 * Verify webhook signature.
	 */
	public function verify_signature( $request ) {
		$secret = get_option( 'pos_unified_webhook_secret', '' );

		// If no secret is configured, allow the webhook (for initial setup)
		if ( empty( $secret ) ) {
			$this->log( 'Warning: webhook secret not configured, accepting without verification' );
			return true;
		}

		$signature = $request->get_header( 'X-Diacos-Signature' );
		if ( empty( $signature ) ) {
			return false;
		}

		$payload  = $request->get_body();
		$expected = hash_hmac( 'sha256', $payload, $secret );

		return hash_equals( $expected, $signature );
	}

	/**
	 * Handle incoming webhook event.
	 */
	public function handle_webhook( $request ) {
		$body  = $request->get_json_params();
		$event = isset( $body['event'] ) ? $body['event'] : '';
		$data  = isset( $body['data'] ) ? $body['data'] : array();

		$this->log( "Received webhook: {$event}" );

		switch ( $event ) {
			case 'inventory.updated':
				$this->handle_inventory_update( $data );
				break;

			case 'order.created':
			case 'order.updated':
				$this->handle_order_created( $data );
				break;

			case 'order.status_changed':
				$this->handle_order_status_change( $data );
				break;

			case 'product.created':
			case 'product.updated':
				$this->handle_product_update( $data );
				break;

			default:
				$this->log( "Unknown event: {$event}" );
				return new WP_REST_Response( array( 'status' => 'ignored' ), 200 );
		}

		return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
	}

	/**
	 * Handle real-time inventory update from Diacos.
	 */
	private function handle_inventory_update( $data ) {
		$sku      = isset( $data['sku'] ) ? $data['sku'] : '';
		$store_id = isset( $data['storeId'] ) ? $data['storeId'] : '';
		$quantity = isset( $data['stockOnHand'] ) ? $data['stockOnHand'] : null;

		if ( empty( $sku ) || $quantity === null ) {
			$this->log( 'Inventory update missing sku or stockOnHand' );
			return;
		}

		$wc_product_id = wc_get_product_id_by_sku( $sku );
		if ( ! $wc_product_id ) {
			$this->log( "No WC product found for SKU: {$sku}" );
			return;
		}

		$product = wc_get_product( $wc_product_id );
		if ( ! $product || ! $product->managing_stock() ) {
			return;
		}

		// Update location-specific stock if mapped
		if ( ! empty( $store_id ) ) {
			$mapper         = POS_Unified_Store_Mapper::instance();
			$wc_location_id = $mapper->get_wc_location_id( $store_id );

			if ( $wc_location_id && $wc_location_id !== 'default' ) {
				update_post_meta( $product->get_id(), "_stock_location_{$wc_location_id}", (int) $quantity );
			}
		}

		// Update main stock
		wc_update_product_stock( $product, (int) $quantity, 'set' );

		$this->log( "Stock updated for SKU {$sku}: {$quantity}" );
	}

	/**
	 * Handle new POS order from Diacos — create it in WooCommerce automatically.
	 */
	private function handle_order_created( $data ) {
		$diacos_order_id = isset( $data['id'] ) ? $data['id'] : '';
		$reference       = isset( $data['reference'] ) ? $data['reference'] : '';
		$order_number    = isset( $data['orderNumber'] ) ? $data['orderNumber'] : $diacos_order_id;
		$store_id        = isset( $data['storeId'] ) ? $data['storeId'] : '';

		// Skip orders that originated from WooCommerce
		if ( strpos( $reference, 'WC-' ) === 0 ) {
			$this->log( "Skipping order {$order_number} — originated from WooCommerce" );
			return;
		}

		// Skip if already imported
		if ( ! empty( $diacos_order_id ) ) {
			$existing = wc_get_orders( array(
				'meta_key'   => '_diacos_order_id',
				'meta_value' => $diacos_order_id,
				'limit'      => 1,
			) );
			if ( ! empty( $existing ) ) {
				$this->log( "Order {$order_number} already exists in WC as #{$existing[0]->get_id()}" );
				return;
			}
		}

		// Use the order sync class to create the WC order
		$order_sync = POS_Unified_Order_Sync::instance();
		$wc_order   = $order_sync->create_wc_order_from_diacos( $data, $store_id );

		if ( $wc_order ) {
			$this->log( "Order {$order_number} -> WC #{$wc_order->get_id()} (via webhook)" );
		} else {
			$this->log( "Failed to create WC order from Diacos {$order_number}" );
		}
	}

	/**
	 * Handle order status change from Diacos.
	 */
	private function handle_order_status_change( $data ) {
		$diacos_order_id = isset( $data['id'] ) ? $data['id'] : '';
		$reference       = isset( $data['reference'] ) ? $data['reference'] : '';
		$status          = isset( $data['status'] ) ? $data['status'] : '';

		// Find WC order by Diacos order ID first, then by WC- reference
		$order = null;
		if ( ! empty( $diacos_order_id ) ) {
			$orders = wc_get_orders( array(
				'meta_key'   => '_diacos_order_id',
				'meta_value' => $diacos_order_id,
				'limit'      => 1,
			) );
			if ( ! empty( $orders ) ) {
				$order = $orders[0];
			}
		}

		if ( ! $order && strpos( $reference, 'WC-' ) === 0 ) {
			$wc_order_id = (int) str_replace( 'WC-', '', $reference );
			$order       = wc_get_order( $wc_order_id );
		}

		if ( ! $order ) {
			$this->log( "WC order not found for Diacos order {$diacos_order_id}" );
			return;
		}

		$status_map = array(
			'confirmed'  => 'processing',
			'processing' => 'processing',
			'shipped'    => 'completed',
			'delivered'  => 'completed',
			'cancelled'  => 'cancelled',
		);

		$new_status = isset( $status_map[ $status ] ) ? $status_map[ $status ] : null;
		if ( $new_status && $order->get_status() !== $new_status ) {
			do_action( 'pos_unified_status_from_diacos' );
			$order->update_status( $new_status, "Diacos POS status: {$status}" );
			$this->log( "Order #{$order->get_id()} status updated to {$new_status}" );
		}
	}

	/**
	 * Handle product create/update from Diacos.
	 */
	private function handle_product_update( $data ) {
		$sku = isset( $data['sku'] ) ? $data['sku'] : 'unknown';
		$this->log( 'Product update received: ' . $sku );
	}

	private function log( $message ) {
		if ( get_option( 'pos_unified_debug', false ) ) {
			error_log( '[POS Unified] [webhook] ' . $message );
		}
	}
}
