<?php
/**
 * Replace the file of an existing media item, keeping its ID (and normally its URL).
 *
 * exab/replace-media-file opens an upload slot in "replace" mode. The bytes arrive through the
 * normal slot route (single PUT or chunked), pass the same checks (size, checksum, content
 * sniffing, GPS removal), and then replace() swaps the file:
 *
 * 1. Remember the URL of every image size.
 * 2. Delete the old files (all sizes, scaled/rotated copies, editor backups).
 * 3. Write the new file under the original file name and regenerate the metadata and sizes.
 * 4. Rewrite references to changed URLs in the content of posts the user may edit
 *    (each update creates a revision); posts the user may not edit are reported, not changed.
 *
 * @package Exab
 */

namespace Exab;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * exab/replace-media-file.
 */
final class Media_Replacer {

	/**
	 * Registers the ability.
	 */
	public static function register_abilities(): void {
		wp_register_ability(
			'exab/replace-media-file',
			array(
				'label'               => __( 'Replace Media File', 'extended-abilities' ),
				'description'         => 'Replaces the file of an existing media item with a LOCAL file of the same type, keeping the attachment ID, file name, title, alt text and caption. '
					. 'Works like exab/create-upload-slot: it returns an upload URL, token and "curl"/"curl_chunked" commands; replace FILE with the local path and run one. '
					. 'The old file and all its sizes are deleted (this cannot be undone). The main URL stays the same unless WordPress scales or rotates one of the two versions. '
					. 'References to changed size URLs in posts the user may edit are updated (with a revision); the final response lists posts_updated and posts_skipped.',
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'attachment_id', 'size_bytes' ),
					'additionalProperties' => false,
					'properties'           => array(
						'attachment_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'size_bytes'    => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Exact size of the new file in bytes.',
						),
						'sha256'        => array(
							'type'        => 'string',
							'pattern'     => '^[a-f0-9]{64}$',
							'description' => 'Recommended: lowercase hex SHA-256 of the new file.',
						),
					),
				),
				'output_schema'       => Schemas::create_slot_output(),
				'permission_callback' => array( __CLASS__, 'can_replace' ),
				'execute_callback'    => array( __CLASS__, 'create_slot' ),
				// Destructive: the old file is deleted. MCP clients should ask before running this.
				'meta'                => Plugin::ability_meta( false, true, false ),
			)
		);
	}

	/**
	 * Permission: upload files and edit this media item.
	 *
	 * @param array $input Ability input.
	 * @return bool|WP_Error
	 */
	public static function can_replace( $input ) {
		$attachment = get_post( (int) $input['attachment_id'] );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error( 'exab_attachment_not_found', sprintf( 'Media item %d does not exist.', $input['attachment_id'] ) );
		}
		return current_user_can( 'upload_files' ) && current_user_can( 'edit_post', $attachment->ID );
	}

	/**
	 * Opens an upload slot in replace mode (execute callback).
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function create_slot( $input ) {
		$meta = self::prepare( (int) $input['attachment_id'], (int) $input['size_bytes'], $input['sha256'] ?? null );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}
		return Upload_Slots::open_slot( $meta );
	}

	/**
	 * Builds slot metadata for a replacement: same type and file name as the current file.
	 *
	 * @param int         $attachment_id Attachment to replace.
	 * @param int         $size          New file size.
	 * @param string|null $sha256        New file checksum.
	 * @return array|WP_Error
	 */
	public static function prepare( int $attachment_id, int $size, ?string $sha256 ) {
		$mime = get_post_mime_type( $attachment_id );
		if ( ! $mime || ! in_array( $mime, Importer::allowed_mime_types(), true ) ) {
			return new WP_Error(
				'exab_mime_not_allowed',
				sprintf( 'Media items of type %s cannot be replaced through this plugin.', $mime ? $mime : 'unknown' )
			);
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file ) {
			return new WP_Error( 'exab_no_file', sprintf( 'Media item %d has no file.', $attachment_id ) );
		}

		$max_bytes = Importer::max_bytes();
		if ( $size > $max_bytes ) {
			return new WP_Error( 'exab_file_too_large', sprintf( 'File is %d bytes; the maximum is %d bytes.', $size, $max_bytes ) );
		}

		return array(
			'user_id'               => get_current_user_id(),
			'filename'              => wp_basename( self::target_path( $attachment_id ) ),
			'mime_type'             => $mime,
			'size_bytes'            => $size,
			'sha256'                => $sha256,
			'title'                 => null,
			'alt_text'              => null,
			'caption'               => null,
			'description'           => null,
			'post_id'               => 0,
			'set_featured'          => false,
			'replace_attachment_id' => $attachment_id,
		);
	}

	/**
	 * Swaps the file of an attachment. The new file has already been verified by the Importer.
	 *
	 * @param int    $attachment_id Attachment.
	 * @param string $tmp           Verified new file; consumed on success.
	 * @return array|WP_Error attachment_id, set_as_featured, posts_updated, posts_skipped.
	 */
	public static function replace( int $attachment_id, string $tmp ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$old_urls = self::size_urls( $attachment_id );
		$old_meta = wp_get_attachment_metadata( $attachment_id );
		$old_meta = is_array( $old_meta ) ? $old_meta : array();
		$attached = get_attached_file( $attachment_id );
		$target   = self::target_path( $attachment_id );
		$backups  = get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true );

		// Removes all sizes, scaled/rotated copies, the original and editor backups.
		// (Backups are passed only when present: that branch reads $meta['file'], which non-images lack.)
		wp_delete_attachment_files( $attachment_id, $old_meta, is_array( $backups ) && $backups ? $backups : null, $attached );
		foreach ( array( $attached, $target ) as $leftover ) {
			if ( $leftover && file_exists( $leftover ) ) {
				wp_delete_file( $leftover );
			}
		}

		// rename() fails across file systems (e.g. /tmp on another mount); fall back to copy.
		$moved = @rename( $tmp, $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $moved && @copy( $tmp, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			wp_delete_file( $tmp );
			$moved = true;
		}
		if ( ! $moved ) {
			return new WP_Error( 'exab_replace_failed', 'Could not move the new file into place.', array( 'status' => 500 ) );
		}
		// Same permissions as a normal upload.
		$stat = stat( dirname( $target ) );
		chmod( $target, $stat['mode'] & 0000666 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod

		delete_post_meta( $attachment_id, '_wp_attachment_backup_sizes' );
		update_attached_file( $attachment_id, $target );
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $target ) );
		// Bumps post_modified, so caches and sitemaps notice the change.
		wp_update_post( array( 'ID' => $attachment_id ) );

		$new_urls = self::size_urls( $attachment_id );
		$changes  = array();
		foreach ( $old_urls as $size => $old_url ) {
			$new_url = $new_urls[ $size ] ?? $new_urls['full'];
			if ( $old_url !== $new_url ) {
				$changes[ $old_url ] = $new_url;
			}
		}
		list( $updated, $skipped ) = self::rewrite_content( $changes );

		return array(
			'attachment_id'   => $attachment_id,
			'set_as_featured' => false,
			'posts_updated'   => $updated,
			'posts_skipped'   => $skipped,
		);
	}

	/**
	 * Where the new file goes: the original upload's path (not a -scaled/-rotated copy).
	 *
	 * @param int $attachment_id Attachment.
	 * @return string
	 */
	private static function target_path( int $attachment_id ): string {
		$original = wp_attachment_is_image( $attachment_id ) ? wp_get_original_image_path( $attachment_id ) : false;
		return $original ? $original : (string) get_attached_file( $attachment_id );
	}

	/**
	 * URL of every size of an attachment, plus "full" (and "original" for scaled images).
	 *
	 * @param int $attachment_id Attachment.
	 * @return array<string, string>
	 */
	private static function size_urls( int $attachment_id ): array {
		$urls = array( 'full' => (string) wp_get_attachment_url( $attachment_id ) );
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return $urls;
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		foreach ( array_keys( $meta['sizes'] ?? array() ) as $size ) {
			$src = wp_get_attachment_image_src( $attachment_id, $size );
			if ( $src ) {
				$urls[ $size ] = $src[0];
			}
		}
		$original = wp_get_original_image_url( $attachment_id );
		if ( $original && $original !== $urls['full'] ) {
			$urls['original'] = $original;
		}
		return $urls;
	}

	/**
	 * Replaces old URLs in post content. Only posts the current user may edit are changed.
	 *
	 * @param array<string, string> $changes Old URL => new URL.
	 * @return array{0: int[], 1: int[]} Updated post IDs, skipped post IDs.
	 */
	private static function rewrite_content( array $changes ): array {
		global $wpdb;

		if ( ! $changes ) {
			return array( array(), array() );
		}

		$ids = array();
		foreach ( array_keys( $changes ) as $old_url ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$found = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type NOT IN ('revision', 'attachment') AND post_status <> 'auto-draft' AND post_content LIKE %s",
					'%' . $wpdb->esc_like( $old_url ) . '%'
				)
			);
			$ids = array_merge( $ids, array_map( 'intval', $found ) );
		}

		$updated = array();
		$skipped = array();
		foreach ( array_unique( $ids ) as $post_id ) {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				$skipped[] = $post_id;
				continue;
			}
			$content = get_post_field( 'post_content', $post_id, 'raw' );
			// strtr() replaces longest matches first and never re-replaces its own output.
			$new_content = strtr( $content, $changes );
			if ( $new_content !== $content ) {
				$result = wp_update_post(
					array(
						'ID'           => $post_id,
						'post_content' => wp_slash( $new_content ),
					),
					true
				);
				if ( is_wp_error( $result ) ) {
					$skipped[] = $post_id;
					continue;
				}
				$updated[] = $post_id;
			}
		}

		sort( $updated );
		sort( $skipped );
		return array( $updated, $skipped );
	}
}
