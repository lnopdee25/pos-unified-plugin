<?php
/**
 * Order Sync — bidirectional order sync between WC and Diacos POS.
 *
 * - WC → Diacos: Online orders push to POS when status = processing/completed
 * - Diacos → WC: POS orders pull into WooCommerce as new orders
 * - Status sync both ways
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
		// Push WC orders to Diacos (only if enabled)
		$push_enabled = get_option( 'pos_unified_order_sync_enabled', false );
		if ( $push_enabled ) {
			add_action( 'woocommerce_order_status_processing', array( $this, 'push_order_to_diacos' ) );
			add_action( 'woocommerce_order_status_completed', array( $this, 'push_order_to_diacos' ) );
			add_action( 'woocommerce_order_status_changed', array( $this, 'sync_status_change' ), 10, 4 );
		}

		// Cron: always register pull hooks (they check their own settings internally)
		add_action( 'pos_unified_order_sync', array( $this, 'pull_diacos_orders' ) );
		add_action( 'pos_unified_order_sync', array( $this, 'pull_status_updates' ) );

		// AJAX handler for manual pull
		add_action( 'wp_ajax_pos_unified_manual_pull_orders', array( $this, 'ajax_manual_pull_orders' ) );
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

		$store_id = $this->resolve_store_for_order( $order );
		if ( ! $store_id ) {
			$this->log( "No Diacos store mapped for WC order #{$order_id}" );
			return;
		}

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
			$this->log( "WC #{$order_id} -> Diacos {$created_id}" );
		} else {
			$error_msg = isset( $result['error'] ) ? $result['error'] : 'Unknown error';
			$order->add_order_note( 'Failed to sync to Diacos: ' . $error_msg );
			$this->log( "Failed to push WC #{$order_id}: " . $error_msg );
		}
	}

	/**
	 * AJAX: manual pull orders (triggered from admin button).
	 */
	public function ajax_manual_pull_orders() {
		check_ajax_referer( 'pos_unified_nonce', '_ajax_nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		// Force pull regardless of setting
		$result = $this->do_pull_diacos_orders( true );
		wp_send_json_success( $result );
	}

	/**
	 * Pull new Diacos POS orders into WooCommerce.
	 */
	public function pull_diacos_orders() {
		$pull_enabled = get_option( 'pos_unified_pull_pos_orders', false );
		if ( ! $pull_enabled ) {
			return;
		}

		$this->do_pull_diacos_orders( false );
	}

	/**
	 * Core pull logic — shared by cron and manual AJAX.
	 */
	public function do_pull_diacos_orders( $force = false ) {
		$client = new POS_Unified_API_Client();
		if ( ! $client->is_configured() ) {
			$this->log( 'Pull orders: API not configured' );
			return array( 'created' => 0, 'error' => 'API not configured' );
		}

		$mapper    = POS_Unified_Store_Mapper::instance();
		$store_ids = $mapper->get_enabled_store_ids();
		$created   = 0;

		$this->log( 'Pull orders: starting. Stores: ' . implode( ', ', $store_ids ) );

		if ( empty( $store_ids ) ) {
			$this->log( 'Pull orders: no enabled store mappings found' );
			return array( 'created' => 0, 'error' => 'No stores mapped' );
		}

		foreach ( $store_ids as $store_id ) {
			// Fetch recent orders from Diacos (last 24 hours for manual, 10 min for cron)
			$since = $force ? time() - 86400 : time() - 600;
			$result = $client->get_orders( $store_id, array(
				'updatedSince' => gmdate( 'Y-m-d\TH:i:s\Z', $since ),
				'limit'        => 50,
			) );

			if ( ! $result['success'] ) {
				$this->log( "Failed to fetch orders from Diacos store {$store_id}: " . ( isset( $result['error'] ) ? $result['error'] : 'Unknown' ) );
				continue;
			}

			$orders = array();
			if ( is_array( $result['data'] ) ) {
				$orders = isset( $result['data']['data'] ) ? $result['data']['data'] : $result['data'];
			}
			if ( ! is_array( $orders ) ) {
				$orders = array();
			}

			$this->log( "Fetched " . count( $orders ) . " orders from Diacos store {$store_id}" );

			foreach ( $orders as $diacos_order ) {
				$diacos_order_id = isset( $diacos_order['id'] ) ? $diacos_order['id'] : '';
				$reference       = isset( $diacos_order['reference'] ) ? $diacos_order['reference'] : '';
				$order_number    = isset( $diacos_order['orderNumber'] ) ? $diacos_order['orderNumber'] : '';

				// Skip orders that originated from WooCommerce
				if ( strpos( $reference, 'WC-' ) === 0 ) {
					continue;
				}

				// Skip if already imported (check by Diacos order ID)
				$existing = $this->find_wc_order_by_diacos_id( $diacos_order_id );
				if ( $existing ) {
					continue;
				}

				// Create WooCommerce order
				$wc_order = $this->create_wc_order_from_diacos( $diacos_order, $store_id );
				if ( $wc_order ) {
					$created++;
					$this->log( "Diacos order {$order_number} -> WC #{$wc_order->get_id()}" );
				}
			}
		}

		if ( $created > 0 ) {
			$this->log( "Pulled {$created} new POS orders from Diacos" );
		} else {
			$this->log( 'Pull orders: no new orders to import' );
		}

		update_option( 'pos_unified_last_order_sync', current_time( 'mysql' ) );

		return array( 'created' => $created );
	}

	/**
	 * Create a WooCommerce order from a Diacos POS order.
	 */
	public function create_wc_order_from_diacos( $diacos_order, $store_id ) {
		$diacos_order_id = isset( $diacos_order['id'] ) ? $diacos_order['id'] : '';
		$order_number    = isset( $diacos_order['orderNumber'] ) ? $diacos_order['orderNumber'] : $diacos_order_id;
		$items           = isset( $diacos_order['items'] ) ? $diacos_order['items'] : array();
		$total           = isset( $diacos_order['total'] ) ? (float) $diacos_order['total'] : 0;
		$subtotal        = isset( $diacos_order['subtotal'] ) ? (float) $diacos_order['subtotal'] : $total;
		$tax_total       = isset( $diacos_order['taxTotal'] ) ? (float) $diacos_order['taxTotal'] : 0;
		$status          = isset( $diacos_order['status'] ) ? $diacos_order['status'] : 'confirmed';
		$notes           = isset( $diacos_order['notes'] ) ? $diacos_order['notes'] : '';
		$payment_status  = isset( $diacos_order['paymentStatus'] ) ? $diacos_order['paymentStatus'] : '';
		$customer_name   = isset( $diacos_order['customerName'] ) ? $diacos_order['customerName'] : '';
		$customer_email  = isset( $diacos_order['customerEmail'] ) ? $diacos_order['customerEmail'] : '';
		$source          = isset( $diacos_order['source'] ) ? $diacos_order['source'] : 'pos';
		$created_at      = isset( $diacos_order['createdAt'] ) ? $diacos_order['createdAt'] : '';

		// Extract customer info from metadata if available
		$metadata = isset( $diacos_order['metadata'] ) ? $diacos_order['metadata'] : array();
		if ( empty( $customer_name ) && isset( $metadata['customerName'] ) ) {
			$customer_name = $metadata['customerName'];
		}
		if ( empty( $customer_email ) && isset( $metadata['customerEmail'] ) ) {
			$customer_email = $metadata['customerEmail'];
		}

		// Split customer name
		$name_parts = explode( ' ', $customer_name, 2 );
		$first_name = isset( $name_parts[0] ) ? $name_parts[0] : 'POS';
		$last_name  = isset( $name_parts[1] ) ? $name_parts[1] : 'Customer';

		try {
			$wc_order = wc_create_order();

			// Set billing info
			$wc_order->set_billing_first_name( $first_name );
			$wc_order->set_billing_last_name( $last_name );
			if ( ! empty( $customer_email ) ) {
				$wc_order->set_billing_email( $customer_email );
			}

			// Add line items
			foreach ( $items as $item ) {
				$item_sku  = isset( $item['sku'] ) ? $item['sku'] : '';
				$item_name = isset( $item['name'] ) ? $item['name'] : 'Unknown Product';
				$item_qty  = isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;
				$item_price = isset( $item['unitPrice'] ) ? (float) $item['unitPrice'] : 0;
				$item_total = isset( $item['lineTotal'] ) ? (float) $item['lineTotal'] : ( $item_price * $item_qty );

				// Try to find WC product by SKU
				$wc_product_id = ! empty( $item_sku ) ? wc_get_product_id_by_sku( $item_sku ) : 0;
				$wc_product    = $wc_product_id ? wc_get_product( $wc_product_id ) : null;

				if ( $wc_product ) {
					$order_item_id = $wc_order->add_product( $wc_product, $item_qty, array(
						'subtotal' => $item_price * $item_qty,
						'total'    => $item_total,
					) );
				} else {
					// Add as fee/line item if product not found in WC
					$fee = new WC_Order_Item_Fee();
					$fee->set_name( $item_name . ( $item_sku ? ' [' . $item_sku . ']' : '' ) );
					$fee->set_amount( $item_total );
					$fee->set_total( $item_total );
					$wc_order->add_item( $fee );
					$this->log( "Product SKU '{$item_sku}' not found in WC, added as fee for Diacos order {$order_number}" );
				}
			}

			// Map Diacos status to WC status
			$status_map = array(
				'pending'    => 'on-hold',
				'confirmed'  => 'processing',
				'processing' => 'processing',
				'picked'     => 'processing',
				'packed'     => 'processing',
				'shipped'    => 'completed',
				'delivered'  => 'completed',
				'cancelled'  => 'cancelled',
			);
			$wc_status = isset( $status_map[ $status ] ) ? $status_map[ $status ] : 'processing';

			// Set order meta
			$wc_order->update_meta_data( '_diacos_order_id', $diacos_order_id );
			$wc_order->update_meta_data( '_diacos_store_id', $store_id );
			$wc_order->update_meta_data( '_diacos_order_number', $order_number );
			$wc_order->update_meta_data( '_diacos_source', $source );
			$wc_order->update_meta_data( '_created_via', 'diacos_pos' );

			// Set payment
			if ( $payment_status === 'paid' ) {
				$wc_order->set_payment_method( 'pos' );
				$wc_order->set_payment_method_title( 'POS Payment' );
				$wc_order->set_date_paid( current_time( 'timestamp' ) );
			}

			// Set date if available
			if ( ! empty( $created_at ) ) {
				$timestamp = strtotime( $created_at );
				if ( $timestamp ) {
					$wc_order->set_date_created( $timestamp );
				}
			}

			// Calculate totals and save
			$wc_order->calculate_totals();
			$wc_order->set_status( $wc_status, 'Imported from Diacos POS: ' . $order_number );
			$wc_order->save();

			// Add order note
			$wc_order->add_order_note( sprintf(
				'Imported from Diacos POS (Order: %s, Store: %s, Source: %s)',
				$order_number,
				$store_id,
				$source
			) );

			if ( ! empty( $notes ) ) {
				$wc_order->add_order_note( 'Diacos note: ' . $notes );
			}

			return $wc_order;

		} catch ( Exception $e ) {
			$this->log( "Failed to create WC order from Diacos {$order_number}: " . $e->getMessage() );
			return null;
		}
	}

	/**
	 * Find a WC order by Diacos order ID.
	 */
	private function find_wc_order_by_diacos_id( $diacos_order_id ) {
		if ( empty( $diacos_order_id ) ) {
			return null;
		}

		$orders = wc_get_orders( array(
			'meta_key'   => '_diacos_order_id',
			'meta_value' => $diacos_order_id,
			'limit'      => 1,
		) );

		return ! empty( $orders ) ? $orders[0] : null;
	}

	/**
	 * Sync WC status change to Diacos.
	 */
	public function sync_status_change( $order_id, $old_status, $new_status, $order ) {
		$diacos_order_id = $order->get_meta( '_diacos_order_id' );
		if ( empty( $diacos_order_id ) ) {
			return;
		}

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
	 * Cron: pull order status updates from Diacos for existing synced orders.
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
				$diacos_order_id = isset( $diacos_order['id'] ) ? $diacos_order['id'] : '';
				$diacos_status   = isset( $diacos_order['status'] ) ? $diacos_order['status'] : '';

				// Find matching WC order
				$wc_order = $this->find_wc_order_by_diacos_id( $diacos_order_id );
				if ( ! $wc_order ) {
					// Also check by WC- reference for backwards compat
					$wc_ref = isset( $diacos_order['reference'] ) ? $diacos_order['reference'] : '';
					if ( strpos( $wc_ref, 'WC-' ) === 0 ) {
						$wc_order_id = (int) str_replace( 'WC-', '', $wc_ref );
						$wc_order = wc_get_order( $wc_order_id );
					}
				}

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

		$default_store = get_option( 'pos_unified_default_store', '' );
		if ( ! empty( $default_store ) ) {
			return $default_store;
		}

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
