<?php
/**
 * Removes upload slots and settings. Attachments and content changes stay.
 *
 * @package Exab
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/class-slot-store.php';

\Exab\Slot_Store::delete_all();
delete_option( 'exab_expose_abilities' );
wp_clear_scheduled_hook( 'exab_cleanup_slots' );
