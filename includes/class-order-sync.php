<?php
/**
 * Order Sync — pushes WC orders to Diacos POS as sales orders.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class POS_Unified_Order_Sync {

	private static $instance = null;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
			self::$instance->hooks();
		}
		return self::$instance;
	}

	private function hooks() {
		$sync_enabled = get_option( 'pos_unified_order_sync_enabled', false );
		if ( ! $sync_enabled ) {
			return;
		}

		// Push new WC orders to Diacos
		add_action( 'woocommerce_order_status_processing', array( $this, 'push_order_to_diacos' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'push_order_to_diacos' ) );

		// Sync status changes
		add_action( 'woocommerce_order_status_changed', array( $this, 'sync_status_change' ), 10, 4 );

		// Cron: pull Diacos order status updates
		add_action( 'pos_unified_order_sync', array( $this, 'pull_status_updates' ) );
	}

	/**
	 * Push a WC order to Diacos.
	 */
	public function push_order_to_diacos( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Skip if already synced
		$diacos_order_id = $order->get_meta( '_diacos_order_id' );
		if ( ! empty( $diacos_order_id ) ) {
			return;
		}

		$client = new POS_Unified_API_Client();
		if ( ! $client->is_configured() ) {
			return;
		}

		// Determine target Diacos store
		$store_id = $this->resolve_store_for_order( $order );
		if ( ! $store_id ) {
			$this->log( "No Diacos store mapped for WC order #{$order_id}" );
			return;
		}

		// Build line items
		$line_items = array();
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			$qty = max( $item->get_quantity(), 1 );

			$line_items[] = array(
				'sku'       => $product->get_sku(),
				'name'      => $item->get_name(),
				'quantity'  => $item->get_quantity(),
				'unitPrice' => (float) ( $item->get_total() / $qty ),
				'taxRate'   => $this->calculate_tax_rate( $item ),
				'taxAmount' => (float) $item->get_total_tax(),
				'lineTotal' => (float) $item->get_total() + (float) $item->get_total_tax(),
			);
		}

		if ( empty( $line_items ) ) {
			return;
		}

		// Map WC status to Diacos status
		$status_map = array(
			'processing' => 'confirmed',
			'completed'  => 'delivered',
			'on-hold'    => 'pending',
			'pending'    => 'pending',
		);

		$wc_status = $order->get_status();

		$order_data = array(
			'orderNumber'   => $order->get_order_number(),
			'reference'     => 'WC-' . $order->get_id(),
			'status'        => isset( $status_map[ $wc_status ] ) ? $status_map[ $wc_status ] : 'pending',
			'type'          => 'sale',
			'source'        => 'online',
			'customerId'    => null,
			'items'         => $line_items,
			'subtotal'      => (float) $order->get_subtotal(),
			'taxTotal'      => (float) $order->get_total_tax(),
			'total'         => (float) $order->get_total(),
			'paymentStatus' => $order->is_paid() ? 'paid' : 'unpaid',
			'notes'         => $order->get_customer_note(),
			'metadata'      => array(
				'wcOrderId'     => $order->get_id(),
				'wcOrderUrl'    => $order->get_edit_order_url(),
				'customerEmail' => $order->get_billing_email(),
				'customerName'  => $order->get_formatted_billing_full_name(),
			),
		);

		$result = $client->create_order( $store_id, $order_data );

		if ( $result['success'] ) {
			$created_id = isset( $result['data']['id'] ) ? $result['data']['id'] : '';
			$order->update_meta_data( '_diacos_order_id', $created_id );
			$order->update_meta_data( '_diacos_store_id', $store_id );
			$order->save();
			$order->add_order_note( sprintf(
				'Synced to Diacos POS (Order: %s, Store: %s)',
				$created_id,
				$store_id
			) );
			$this->log( "WC #{$order_id} → Diacos {$created_id}" );
		} else {
			$error_msg = isset( $result['error'] ) ? $result['error'] : 'Unknown error';
			$order->add_order_note( 'Failed to sync to Diacos: ' . $error_msg );
			$this->log( "Failed to push WC #{$order_id}: " . $error_msg );
		}
	}

	/**
	 * Sync WC status change to Diacos.
	 */
	public function sync_status_change( $order_id, $old_status, $new_status, $order ) {
		$diacos_order_id = $order->get_meta( '_diacos_order_id' );
		if ( empty( $diacos_order_id ) ) {
			return;
		}

		// Prevent loop from incoming Diacos webhooks
		if ( doing_action( 'pos_unified_status_from_diacos' ) ) {
			return;
		}

		$status_map = array(
			'processing' => 'processing',
			'completed'  => 'delivered',
			'cancelled'  => 'cancelled',
			'refunded'   => 'cancelled',
			'on-hold'    => 'pending',
		);

		$diacos_status = isset( $status_map[ $new_status ] ) ? $status_map[ $new_status ] : null;
		if ( ! $diacos_status ) {
			return;
		}

		$client = new POS_Unified_API_Client();
		$client->update_order_status( $diacos_order_id, $diacos_status );
	}

	/**
	 * Cron: pull order status updates from Diacos.
	 */
	public function pull_status_updates() {
		$client = new POS_Unified_API_Client();
		if ( ! $client->is_configured() ) {
			return;
		}

		$mapper    = POS_Unified_Store_Mapper::instance();
		$store_ids = $mapper->get_enabled_store_ids();

		foreach ( $store_ids as $store_id ) {
			$result = $client->get_orders( $store_id, array(
				'source'       => 'online',
				'updatedSince' => gmdate( 'Y-m-d\TH:i:s\Z', time() - 600 ),
				'limit'        => 50,
			) );

			if ( ! $result['success'] ) {
				continue;
			}

			$orders = array();
			if ( is_array( $result['data'] ) ) {
				$orders = isset( $result['data']['data'] ) ? $result['data']['data'] : $result['data'];
			}
			if ( ! is_array( $orders ) ) {
				$orders = array();
			}

			foreach ( $orders as $diacos_order ) {
				$wc_ref = isset( $diacos_order['reference'] ) ? $diacos_order['reference'] : '';
				if ( strpos( $wc_ref, 'WC-' ) !== 0 ) {
					continue;
				}

				$wc_order_id = (int) str_replace( 'WC-', '', $wc_ref );
				$wc_order    = wc_get_order( $wc_order_id );
				if ( ! $wc_order ) {
					continue;
				}

				$reverse_map = array(
					'confirmed'  => 'processing',
					'processing' => 'processing',
					'picked'     => 'processing',
					'packed'     => 'processing',
					'shipped'    => 'completed',
					'delivered'  => 'completed',
					'cancelled'  => 'cancelled',
				);

				$diacos_status = isset( $diacos_order['status'] ) ? $diacos_order['status'] : '';
				$new_wc_status = isset( $reverse_map[ $diacos_status ] ) ? $reverse_map[ $diacos_status ] : null;

				if ( $new_wc_status && $wc_order->get_status() !== $new_wc_status ) {
					do_action( 'pos_unified_status_from_diacos' );
					$wc_order->update_status( $new_wc_status, 'Status updated from Diacos POS: ' . $diacos_status );
				}
			}
		}

		update_option( 'pos_unified_last_order_sync', current_time( 'mysql' ) );
	}

	/**
	 * Determine which Diacos store an order should go to.
	 */
	private function resolve_store_for_order( $order ) {
		$mapper   = POS_Unified_Store_Mapper::instance();
		$mappings = $mapper->get_mappings();

		if ( empty( $mappings ) ) {
			return null;
		}

		// Use configured default store
		$default_store = get_option( 'pos_unified_default_store', '' );
		if ( ! empty( $default_store ) ) {
			return $default_store;
		}

		// Fallback: first enabled store
		foreach ( $mappings as $map ) {
			if ( ! empty( $map['enabled'] ) && ! empty( $map['diacos_store_id'] ) ) {
				return $map['diacos_store_id'];
			}
		}

		return null;
	}

	private function calculate_tax_rate( $item ) {
		$total = (float) $item->get_total();
		$tax   = (float) $item->get_total_tax();
		if ( $total > 0 ) {
			return round( ( $tax / $total ) * 100, 2 );
		}
		return 0.0;
	}

	private function log( $message ) {
		if ( get_option( 'pos_unified_debug', false ) ) {
			error_log( '[POS Unified] [orders] ' . $message );
		}
	}
}
