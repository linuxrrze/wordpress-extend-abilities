<?php
/**
 * Persistence for upload slots.
 *
 * Slots live in non-autoloaded options named `exab_slot_{id}` and are read
 * and written with direct queries, so the object cache can never serve a
 * stale status. State changes use compare-and-swap, which makes a slot
 * single-use even when two uploads race.
 *
 * @package Exab
 */

namespace Exab;

defined( 'ABSPATH' ) || exit;

/**
 * Stores, loads and transitions upload slots.
 */
final class Slot_Store {

	const PREFIX = 'exab_slot_';

	const STATUS_PENDING   = 'pending';
	const STATUS_RECEIVING = 'receiving';
	const STATUS_UPLOADING = 'uploading';
	const STATUS_COMPLETED = 'completed';
	const STATUS_FAILED    = 'failed';
	const STATUS_EXPIRED   = 'expired';

	/**
	 * How long finished or expired slots are kept for status queries.
	 */
	const RETENTION = DAY_IN_SECONDS;

	/**
	 * Saves a new slot.
	 *
	 * @param string $id   Slot ID (32 hex characters).
	 * @param array  $slot Slot data.
	 * @return bool Whether the slot was stored.
	 */
	public static function create( string $id, array $slot ): bool {
		global $wpdb;

		// INSERT (not add_option) so an ID collision fails instead of overwriting.
		$inserted = $wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => self::PREFIX . $id,
				'option_value' => wp_json_encode( $slot ),
				'autoload'     => 'off',
			),
			array( '%s', '%s', '%s' )
		);

		return 1 === $inserted;
	}

	/**
	 * Loads a slot.
	 *
	 * @param string $id Slot ID.
	 * @return array|null Slot data with an added `_raw` key for compare-and-swap, or null.
	 */
	public static function get( string $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- intentionally uncached, see class doc.
		$raw = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::PREFIX . $id )
		);
		if ( null === $raw ) {
			return null;
		}

		$slot = json_decode( $raw, true );
		if ( ! is_array( $slot ) ) {
			return null;
		}
		$slot['_raw'] = $raw;
		return $slot;
	}

	/**
	 * Atomically replaces a slot if nobody changed it since it was loaded.
	 *
	 * @param string $id      Slot ID.
	 * @param array  $current Slot as returned by get() (must contain `_raw`).
	 * @param array  $changes Fields to merge into the slot.
	 * @return array|null The updated slot (with new `_raw`), or null if the slot changed meanwhile.
	 */
	public static function transition( string $id, array $current, array $changes ): ?array {
		global $wpdb;

		$raw_before = $current['_raw'];
		unset( $current['_raw'] );
		$next      = array_merge( $current, $changes );
		$raw_after = wp_json_encode( $next );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- compare-and-swap needs a conditional UPDATE.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$raw_after,
				self::PREFIX . $id,
				$raw_before
			)
		);
		if ( 1 !== $updated ) {
			return null;
		}

		$next['_raw'] = $raw_after;
		return $next;
	}

	/**
	 * Returns the effective status, turning stale pending or receiving slots into `expired`.
	 *
	 * @param array $slot Slot data.
	 * @return string One of the STATUS_* constants.
	 */
	public static function effective_status( array $slot ): string {
		$open = array( self::STATUS_PENDING, self::STATUS_RECEIVING );
		if ( in_array( $slot['status'], $open, true ) && time() > (int) $slot['expires_at'] ) {
			return self::STATUS_EXPIRED;
		}
		return $slot['status'];
	}

	/**
	 * Counts a user's open (pending or receiving), not yet expired slots.
	 *
	 * @param int $user_id User ID.
	 * @return int Number of open slots.
	 */
	public static function count_open( int $user_id ): int {
		$open = 0;
		foreach ( self::all() as $slot ) {
			$status = self::effective_status( $slot );
			if ( (int) $slot['user_id'] === $user_id && ( self::STATUS_PENDING === $status || self::STATUS_RECEIVING === $status ) ) {
				++$open;
			}
		}
		return $open;
	}

	/**
	 * Deletes slots that expired more than RETENTION seconds ago, and partial uploads of
	 * expired slots. Runs hourly via cron.
	 */
	public static function delete_stale(): void {
		global $wpdb;

		$cutoff = time() - self::RETENTION;
		foreach ( self::all() as $name => $slot ) {
			if ( (int) $slot['expires_at'] < $cutoff ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->delete( $wpdb->options, array( 'option_name' => $name ), array( '%s' ) );
			}
			// Abandoned chunked uploads: remove their partial file once the slot has expired.
			if ( (int) $slot['expires_at'] < time() && self::STATUS_COMPLETED !== $slot['status'] ) {
				Upload_Slots::delete_chunk_file( substr( $name, strlen( self::PREFIX ) ) );
			}
		}
	}

	/**
	 * Deletes every slot. Used on uninstall.
	 */
	public static function delete_all(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::PREFIX ) . '%' )
		);
	}

	/**
	 * Loads all slots.
	 *
	 * @return array<string, array> Slots keyed by option name.
	 */
	private static function all(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( self::PREFIX ) . '%'
			)
		);

		$slots = array();
		foreach ( $rows as $row ) {
			$slot = json_decode( $row->option_value, true );
			if ( is_array( $slot ) && isset( $slot['status'], $slot['user_id'], $slot['expires_at'] ) ) {
				$slots[ $row->option_name ] = $slot;
			}
		}
		return $slots;
	}
}
