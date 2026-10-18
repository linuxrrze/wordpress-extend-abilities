<?php
/**
 * Shared upload pipeline: what may be uploaded, and how a received file becomes an attachment.
 *
 * Used by upload slots (single PUT and chunked) and by exab/upload-media-inline.
 *
 * @package Exab
 */

namespace Exab;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Validation, content sniffing, GPS removal and import into the media library.
 */
final class Importer {

	const IMAGE_TYPES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' );
	const PDF_TYPE    = 'application/pdf';

	/**
	 * Types offered by the plugin, before per-user restrictions: images, plus PDF if enabled.
	 *
	 * @return string[]
	 */
	public static function configured_mime_types(): array {
		$types = self::IMAGE_TYPES;
		if ( Settings::allow_pdf() ) {
			$types[] = self::PDF_TYPE;
		}

		/**
		 * Filters the MIME types accepted for uploads.
		 *
		 * @param string[] $types Default: JPEG, PNG, GIF, WebP, AVIF, plus PDF when enabled in the settings.
		 */
		return array_values( (array) apply_filters( 'exab_allowed_mime_types', $types ) );
	}

	/**
	 * Types this user may upload, as WordPress' extension => MIME map.
	 *
	 * @return array<string, string> E.g. array( 'jpg|jpeg|jpe' => 'image/jpeg' ).
	 */
	public static function allowed_mime_map(): array {
		$wanted = self::configured_mime_types();

		return array_filter(
			get_allowed_mime_types(),
			static fn( $mime ) => in_array( $mime, $wanted, true )
		);
	}

	/**
	 * Types this user may upload.
	 *
	 * @return string[]
	 */
	public static function allowed_mime_types(): array {
		return array_values( array_unique( self::allowed_mime_map() ) );
	}

	/**
	 * Largest accepted file in bytes.
	 *
	 * @return int
	 */
	public static function max_bytes(): int {
		/**
		 * Filters the maximum upload size. Never exceeds wp_max_upload_size().
		 *
		 * @param int $bytes Default: wp_max_upload_size().
		 */
		return (int) min( wp_max_upload_size(), apply_filters( 'exab_max_upload_bytes', wp_max_upload_size() ) );
	}

	/**
	 * Validates an upload request and normalizes its metadata. Runs as the requesting user.
	 *
	 * @param array $input Ability input (filename, mime_type, sha256, title, alt_text, caption, description, post_id, set_featured).
	 * @param int   $size  File size in bytes.
	 * @return array|WP_Error Normalized upload metadata.
	 */
	public static function prepare( array $input, int $size ) {
		$mime     = $input['mime_type'];
		$filename = sanitize_file_name( $input['filename'] );
		$post_id  = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		$featured = ! empty( $input['set_featured'] );

		if ( ! in_array( $mime, self::allowed_mime_types(), true ) ) {
			return new WP_Error( 'exab_mime_not_allowed', sprintf( 'MIME type %s is not allowed for uploads by this user.', $mime ) );
		}

		$by_extension = wp_check_filetype( $filename, self::allowed_mime_map() );
		if ( $by_extension['type'] !== $mime ) {
			return new WP_Error( 'exab_extension_mismatch', sprintf( 'The extension of "%s" does not match %s.', $filename, $mime ) );
		}

		$max_bytes = self::max_bytes();
		if ( $size > $max_bytes ) {
			return new WP_Error( 'exab_file_too_large', sprintf( 'File is %d bytes; the maximum is %d bytes.', $size, $max_bytes ) );
		}

		if ( $post_id ) {
			if ( ! get_post( $post_id ) ) {
				return new WP_Error( 'exab_invalid_post', sprintf( 'Post %d does not exist.', $post_id ) );
			}
		} elseif ( $featured ) {
			return new WP_Error( 'exab_featured_needs_post', 'set_featured requires post_id.' );
		}
		if ( $featured && ! str_starts_with( $mime, 'image/' ) ) {
			return new WP_Error( 'exab_featured_needs_image', 'Only images can be featured images.' );
		}

		return array(
			'user_id'      => get_current_user_id(),
			'filename'     => $filename,
			'mime_type'    => $mime,
			'size_bytes'   => $size,
			'sha256'       => $input['sha256'] ?? null,
			'title'        => isset( $input['title'] ) ? sanitize_text_field( $input['title'] ) : null,
			'alt_text'     => isset( $input['alt_text'] ) ? sanitize_text_field( $input['alt_text'] ) : null,
			'caption'      => isset( $input['caption'] ) ? wp_kses_post( $input['caption'] ) : null,
			'description'  => isset( $input['description'] ) ? wp_kses_post( $input['description'] ) : null,
			'post_id'      => $post_id,
			'set_featured' => $featured,
		);
	}

	/**
	 * Verifies a received file and imports it as the upload's owner. Always consumes $tmp.
	 *
	 * @param string $tmp  Path of the received file.
	 * @param array  $meta Metadata from prepare() (slot data has the same keys).
	 * @return array|WP_Error attachment_id, set_as_featured, gps_removed; or an error.
	 */
	public static function import_file( string $tmp, array $meta ) {
		$result = self::import_or_fail( $tmp, $meta );
		if ( is_wp_error( $result ) && file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}
		return $result;
	}

	/**
	 * Does the work of import_file(); the caller cleans up on failure.
	 *
	 * @param string $tmp  Path of the received file.
	 * @param array  $meta Upload metadata.
	 * @return array|WP_Error
	 */
	private static function import_or_fail( string $tmp, array $meta ) {
		clearstatcache( true, $tmp );
		$size = (int) filesize( $tmp );
		if ( $size !== (int) $meta['size_bytes'] ) {
			return new WP_Error(
				'exab_size_mismatch',
				sprintf( 'Received %d bytes, but the upload was announced with %d bytes.', $size, $meta['size_bytes'] ),
				array( 'status' => 400 )
			);
		}
		if ( ! empty( $meta['sha256'] ) && ! hash_equals( $meta['sha256'], (string) hash_file( 'sha256', $tmp ) ) ) {
			return new WP_Error( 'exab_checksum_mismatch', 'The SHA-256 of the received file does not match the announced checksum.', array( 'status' => 400 ) );
		}

		// Act as the owner and re-check: the role may have changed since the upload was prepared.
		wp_set_current_user( (int) $meta['user_id'] );
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'exab_forbidden', 'The upload owner may no longer upload files.', array( 'status' => 403 ) );
		}
		$post_id = (int) $meta['post_id'];
		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'exab_forbidden', sprintf( 'The upload owner may no longer edit post %d.', $post_id ), array( 'status' => 403 ) );
		}

		// Sniff the real type from the content; the extension and header could lie.
		$real_mime = self::sniff( $tmp, $meta['mime_type'] );
		if ( $real_mime !== $meta['mime_type'] ) {
			return new WP_Error(
				'exab_content_mismatch',
				sprintf( 'The file content is not a valid %s file (detected: %s).', $meta['mime_type'], $real_mime ? $real_mime : 'unknown' ),
				array( 'status' => 415 )
			);
		}

		$gps_removed = false;
		if ( Settings::strip_gps() ) {
			$gps_removed = Gps_Scrubber::scrub_file( $tmp, $meta['mime_type'] );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$post_data = array();
		if ( null !== $meta['caption'] ) {
			$post_data['post_excerpt'] = $meta['caption'];
		}
		if ( null !== $meta['description'] ) {
			$post_data['post_content'] = $meta['description'];
		}

		$attachment_id = media_handle_sideload(
			array(
				'name'     => $meta['filename'],
				'tmp_name' => $tmp,
			),
			$post_id,
			$meta['title'],
			$post_data
		);
		if ( is_wp_error( $attachment_id ) ) {
			$attachment_id->add_data( array( 'status' => 400 ) );
			return $attachment_id;
		}

		if ( null !== $meta['alt_text'] && '' !== $meta['alt_text'] ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_slash( $meta['alt_text'] ) );
		}

		$set_as_featured = false;
		if ( $post_id && $meta['set_featured'] ) {
			$set_as_featured = (bool) set_post_thumbnail( $post_id, $attachment_id );
		}

		return array(
			'attachment_id'   => (int) $attachment_id,
			'set_as_featured' => $set_as_featured,
			'gps_removed'     => $gps_removed,
		);
	}

	/**
	 * Detects the real type of a file for the types this plugin supports.
	 *
	 * @param string $path    File path.
	 * @param string $claimed Announced MIME type.
	 * @return string|false Detected MIME type, or false if unknown.
	 */
	public static function sniff( string $path, string $claimed ) {
		if ( self::PDF_TYPE === $claimed ) {
			// PDF files start with "%PDF-" (readers tolerate leading junk; we don't).
			$head = (string) file_get_contents( $path, false, null, 0, 5 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			return '%PDF-' === $head ? self::PDF_TYPE : false;
		}
		return wp_get_image_mime( $path );
	}
}
