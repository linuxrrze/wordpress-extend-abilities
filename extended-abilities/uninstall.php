<?php
/**
 * Removes all upload slots. Attachments created through them stay.
 *
 * @package Exab
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/class-slot-store.php';

\Exab\Slot_Store::delete_all();
wp_clear_scheduled_hook( 'exab_cleanup_slots' );
