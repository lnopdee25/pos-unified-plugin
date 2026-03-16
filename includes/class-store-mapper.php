<?php
/**
 * Maps WC Multi-Location Inventory locations to Diacos stores.
 *
 * Option key: pos_unified_store_map
 * Format: array( array( 'wc_location_id' => string, 'diacos_store_id' => string, 'enabled' => bool ) )
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class POS_Unified_Store_Mapper {

	private static $instance = null;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Get the full mapping array.
	 */
	public function get_mappings() {
		$mappings = get_option( 'pos_unified_store_map', array() );
		return is_array( $mappings ) ? $mappings : array();
	}

	/**
	 * Save mappings.
	 */
	public function save_mappings( $mappings ) {
		update_option( 'pos_unified_store_map', $mappings );
	}

	/**
	 * Get the Diacos store ID for a WC location.
	 */
	public function get_diacos_store_id( $wc_location_id ) {
		foreach ( $this->get_mappings() as $map ) {
			if ( (string) $map['wc_location_id'] === (string) $wc_location_id && ! empty( $map['enabled'] ) ) {
				return $map['diacos_store_id'];
			}
		}
		return null;
	}

	/**
	 * Get the WC location ID for a Diacos store.
	 */
	public function get_wc_location_id( $diacos_store_id ) {
		foreach ( $this->get_mappings() as $map ) {
			if ( isset( $map['diacos_store_id'] ) && $map['diacos_store_id'] === $diacos_store_id && ! empty( $map['enabled'] ) ) {
				return isset( $map['wc_location_id'] ) ? $map['wc_location_id'] : null;
			}
		}
		return null;
	}

	/**
	 * Get all enabled Diacos store IDs.
	 */
	public function get_enabled_store_ids() {
		$ids = array();
		foreach ( $this->get_mappings() as $map ) {
			if ( ! empty( $map['enabled'] ) && ! empty( $map['diacos_store_id'] ) ) {
				$ids[] = $map['diacos_store_id'];
			}
		}
		return $ids;
	}

	/**
	 * Detect WC Multi-Location Inventory locations.
	 * Supports both ATUM Multi-Location and WC native locations.
	 */
	public function get_wc_locations() {
		$locations = array();

		// Check for WC Multi-Location Inventory plugin (taxonomy: location)
		if ( taxonomy_exists( 'location' ) ) {
			$terms = get_terms( array(
				'taxonomy'   => 'location',
				'hide_empty' => false,
			) );
			if ( ! is_wp_error( $terms ) && is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$locations[] = array(
						'id'     => $term->term_id,
						'name'   => $term->name,
						'slug'   => $term->slug,
						'source' => 'wc-multi-location',
					);
				}
			}
		}

		// Check for ATUM Multi-Inventory locations
		if ( taxonomy_exists( 'atum_location' ) ) {
			$terms = get_terms( array(
				'taxonomy'   => 'atum_location',
				'hide_empty' => false,
			) );
			if ( ! is_wp_error( $terms ) && is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$locations[] = array(
						'id'     => $term->term_id,
						'name'   => $term->name,
						'slug'   => $term->slug,
						'source' => 'atum',
					);
				}
			}
		}

		// Fallback: single-location mode
		if ( empty( $locations ) ) {
			$locations[] = array(
				'id'     => 'default',
				'name'   => get_bloginfo( 'name' ) . ' (Default)',
				'slug'   => 'default',
				'source' => 'single',
			);
		}

		return $locations;
	}
}
