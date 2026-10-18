<?php
/**
 * Bootstrap: hooks for abilities, REST routes and slot cleanup.
 *
 * @package Exab
 */

namespace Exab;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into WordPress.
 */
final class Plugin {

	const CATEGORY         = 'exab-media';
	const CONTENT_CATEGORY = 'exab-content';
	const CLEANUP_HOOK     = 'exab_cleanup_slots';

	/**
	 * Registers all hooks.
	 */
	public static function init(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_abilities_api_notice' ) );
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( Upload_Slots::class, 'register_abilities' ) );
		add_action( 'wp_abilities_api_init', array( Media_Abilities::class, 'register_abilities' ) );
		add_action( 'wp_abilities_api_init', array( Content_Abilities::class, 'register_abilities' ) );
		add_action( 'rest_api_init', array( Upload_Slots::class, 'register_rest_routes' ) );
		add_action( self::CLEANUP_HOOK, array( Slot_Store::class, 'delete_stale' ) );
		Expose_Abilities::init();
		Settings::init();
	}

	/**
	 * Meta shared by all exab abilities: REST + MCP visible, with the given annotations.
	 *
	 * @param bool $readonly    Does not modify anything.
	 * @param bool $destructive May delete or overwrite data irrecoverably.
	 * @param bool $idempotent  Repeating the call with the same input has no further effect.
	 * @return array
	 */
	public static function ability_meta( bool $readonly, bool $destructive, bool $idempotent ): array {
		return array(
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true ),
			'annotations'  => array(
				'readonly'    => $readonly,
				'destructive' => $destructive,
				'idempotent'  => $idempotent,
			),
		);
	}

	/**
	 * Registers the ability categories.
	 */
	public static function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Media (Extended Abilities)', 'extended-abilities' ),
				'description' => __( 'Upload and manage media library items.', 'extended-abilities' ),
			)
		);
		wp_register_ability_category(
			self::CONTENT_CATEGORY,
			array(
				'label'       => __( 'Content (Extended Abilities)', 'extended-abilities' ),
				'description' => __( 'Schedule posts, update pages and work with revisions.', 'extended-abilities' ),
			)
		);
	}

	/**
	 * Schedules the hourly cleanup of stale upload slots.
	 */
	public static function activate(): void {
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CLEANUP_HOOK );
		}
	}

	/**
	 * Removes the cleanup event. Slots are deleted on uninstall.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );
	}

	/**
	 * Shown when WordPress is older than 6.9 (no Abilities API).
	 */
	public static function missing_abilities_api_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Extended Abilities requires the WordPress Abilities API (WordPress 6.9 or newer).', 'extended-abilities' )
		);
	}
}
