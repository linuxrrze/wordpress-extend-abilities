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
delete_option( 'exab_allow_pdf' );
delete_option( 'exab_strip_gps' );

// Partial chunked uploads.
$exab_chunk_dir = wp_upload_dir( null, false )['basedir'] . '/exab-chunks';
if ( is_dir( $exab_chunk_dir ) ) {
	foreach ( array_merge( (array) glob( $exab_chunk_dir . '/*' ), array( $exab_chunk_dir . '/.htaccess' ) ) as $exab_file ) {
		if ( is_file( $exab_file ) ) {
			wp_delete_file( $exab_file );
		}
	}
	rmdir( $exab_chunk_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}
wp_clear_scheduled_hook( 'exab_cleanup_slots' );
