<?php
/**
 * Upload slots: MCP controls the upload, the bytes travel over plain HTTP.
 *
 * 1. exab/create-upload-slot checks the planned upload and returns a single-use URL and token.
 * 2. The client sends the raw file to that URL (PUT or POST, X-Exab-Upload-Token header).
 * 3. exab/get-upload-status reports the result.
 *
 * @package Exab
 */

namespace Exab;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Abilities and REST route for slot-based uploads.
 */
final class Upload_Slots {

	const REST_NAMESPACE = 'exab/v1';
	const TOKEN_HEADER   = 'X-Exab-Upload-Token';

	/**
	 * Image types accepted in this version (further limited by the user's allowed MIME types).
	 *
	 * @return string[]
	 */
	public static function default_mime_types(): array {
		return array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' );
	}

	/**
	 * Registers exab/create-upload-slot and exab/get-upload-status.
	 */
	public static function register_abilities(): void {
		wp_register_ability(
			'exab/create-upload-slot',
			array(
				'label'               => __( 'Create Media Upload Slot', 'extended-abilities' ),
				'description'         => 'Prepares the upload of a LOCAL image file to the media library. Returns a single-use upload URL and token, valid for a few minutes. '
					. 'Then send the raw file bytes with HTTP PUT: run the returned "curl" command after replacing FILE with the local path. '
					. 'The HTTP response (and exab/get-upload-status) contains the attachment ID and URL. '
					. 'Pass the exact size_bytes and, ideally, sha256 of the file. Title, alt text, caption, parent post and featured image are applied on upload. '
					. 'Use this instead of base64 or ewpa/upload-image when the file is on the local disk.',
				'category'            => Plugin::CATEGORY,
				'input_schema'        => Schemas::create_slot_input(),
				'output_schema'       => Schemas::create_slot_output(),
				'permission_callback' => array( __CLASS__, 'can_create' ),
				'execute_callback'    => array( __CLASS__, 'create' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
				),
			)
		);

		wp_register_ability(
			'exab/get-upload-status',
			array(
				'label'               => __( 'Get Media Upload Status', 'extended-abilities' ),
				'description'         => 'Returns the state of an upload slot created with exab/create-upload-slot: pending, uploading, completed (with attachment_id and url), failed (with error) or expired.',
				'category'            => Plugin::CATEGORY,
				'input_schema'        => Schemas::status_input(),
				'output_schema'       => Schemas::status_output(),
				'permission_callback' => array( __CLASS__, 'can_read_status' ),
				'execute_callback'    => array( __CLASS__, 'status' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	/**
	 * Registers the route that receives the file bytes.
	 */
	public static function register_rest_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/upload/(?P<slot_id>[a-f0-9]{32})',
			array(
				'methods'             => array( WP_REST_Server::CREATABLE, 'PUT' ),
				'callback'            => array( __CLASS__, 'receive' ),
				// The single-use token is the only credential; it is checked in receive().
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Permission check for exab/create-upload-slot.
	 *
	 * @param mixed $input Ability input.
	 * @return bool
	 */
	public static function can_create( $input = null ): bool {
		if ( ! current_user_can( 'upload_files' ) ) {
			return false;
		}
		if ( ! empty( $input['post_id'] ) && ! current_user_can( 'edit_post', (int) $input['post_id'] ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Permission check for exab/get-upload-status. Ownership is checked in status().
	 *
	 * @return bool
	 */
	public static function can_read_status(): bool {
		return current_user_can( 'upload_files' );
	}

	/**
	 * Creates a slot (execute callback of exab/create-upload-slot).
	 *
	 * @param array $input Validated ability input.
	 * @return array|WP_Error
	 */
	public static function create( $input ) {
		$user_id  = get_current_user_id();
		$mime     = $input['mime_type'];
		$size     = (int) $input['size_bytes'];
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

		/**
		 * Filters how many pending slots one user may hold at the same time.
		 *
		 * @param int $limit   Default 10.
		 * @param int $user_id User ID.
		 */
		$open_limit = (int) apply_filters( 'exab_max_open_slots', 10, $user_id );
		if ( Slot_Store::count_open( $user_id ) >= $open_limit ) {
			return new WP_Error( 'exab_too_many_slots', sprintf( 'You already have %d open upload slots. Use or let them expire first.', $open_limit ) );
		}

		/**
		 * Filters the lifetime of an upload slot in seconds.
		 *
		 * @param int $ttl Default 15 minutes.
		 */
		$ttl     = max( 60, (int) apply_filters( 'exab_slot_ttl', 15 * MINUTE_IN_SECONDS ) );
		$slot_id = bin2hex( random_bytes( 16 ) );
		$token   = bin2hex( random_bytes( 32 ) );
		$expires = time() + $ttl;

		$stored = Slot_Store::create(
			$slot_id,
			array(
				'status'          => Slot_Store::STATUS_PENDING,
				'user_id'         => $user_id,
				'token_hash'      => hash( 'sha256', $token ),
				'filename'        => $filename,
				'mime_type'       => $mime,
				'size_bytes'      => $size,
				'sha256'          => $input['sha256'] ?? null,
				'title'           => isset( $input['title'] ) ? sanitize_text_field( $input['title'] ) : null,
				'alt_text'        => isset( $input['alt_text'] ) ? sanitize_text_field( $input['alt_text'] ) : null,
				'caption'         => isset( $input['caption'] ) ? wp_kses_post( $input['caption'] ) : null,
				'description'     => isset( $input['description'] ) ? wp_kses_post( $input['description'] ) : null,
				'post_id'         => $post_id,
				'set_featured'    => $featured,
				'created_at'      => time(),
				'expires_at'      => $expires,
				'attachment_id'   => null,
				'set_as_featured' => false,
				'error'           => null,
			)
		);
		if ( ! $stored ) {
			return new WP_Error( 'exab_slot_not_stored', 'Could not store the upload slot.' );
		}

		$url = rest_url( self::REST_NAMESPACE . '/upload/' . $slot_id );

		return array(
			'slot_id'      => $slot_id,
			'upload_url'   => $url,
			'upload_token' => $token,
			'method'       => 'PUT',
			'headers'      => array(
				self::TOKEN_HEADER => $token,
				'Content-Type'     => $mime,
			),
			'expires_at'   => gmdate( 'c', $expires ),
			'max_bytes'    => $size,
			'curl'         => sprintf(
				"curl -sS -X PUT --data-binary @FILE -H 'Content-Type: %s' -H '%s: %s' '%s'",
				$mime,
				self::TOKEN_HEADER,
				$token,
				$url
			),
		);
	}

	/**
	 * Reports a slot's state (execute callback of exab/get-upload-status).
	 *
	 * @param array $input Validated ability input.
	 * @return array|WP_Error
	 */
	public static function status( $input ) {
		$slot = Slot_Store::get( $input['slot_id'] );

		// Other users' slots look exactly like missing ones.
		if ( ! $slot || (int) $slot['user_id'] !== get_current_user_id() ) {
			return new WP_Error( 'exab_slot_not_found', 'Upload slot not found.' );
		}

		return self::describe( $input['slot_id'], $slot );
	}

	/**
	 * Receives the file bytes for a slot (REST callback).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function receive( WP_REST_Request $request ) {
		$slot_id = $request['slot_id'];
		$token   = (string) $request->get_header( self::TOKEN_HEADER );
		$slot    = Slot_Store::get( $slot_id );

		if ( ! $slot || '' === $token || ! hash_equals( $slot['token_hash'], hash( 'sha256', $token ) ) ) {
			return new WP_Error( 'exab_invalid_token', 'Unknown upload slot or invalid token.', array( 'status' => 403 ) );
		}

		$status = Slot_Store::effective_status( $slot );
		if ( Slot_Store::STATUS_EXPIRED === $status ) {
			return new WP_Error( 'exab_slot_expired', 'This upload slot has expired. Create a new one.', array( 'status' => 410 ) );
		}
		if ( Slot_Store::STATUS_PENDING !== $status ) {
			return new WP_Error( 'exab_slot_used', sprintf( 'This upload slot was already used (status: %s).', $status ), array( 'status' => 409 ) );
		}

		// Checked before claiming: a wrong header is a client mistake that a retry can fix.
		$content_type = strtolower( trim( explode( ';', (string) $request->get_header( 'Content-Type' ) )[0] ) );
		if ( $content_type !== $slot['mime_type'] ) {
			return new WP_Error(
				'exab_wrong_content_type',
				sprintf( 'Send the file with "Content-Type: %s" (got "%s").', $slot['mime_type'], $content_type ),
				array( 'status' => 415 )
			);
		}

		// Claim the slot. Only one request can win this transition.
		$slot = Slot_Store::transition( $slot_id, $slot, array( 'status' => Slot_Store::STATUS_UPLOADING ) );
		if ( ! $slot ) {
			return new WP_Error( 'exab_slot_used', 'This upload slot is already being used.', array( 'status' => 409 ) );
		}

		$result = self::import( $request->get_body(), $slot );

		if ( is_wp_error( $result ) ) {
			Slot_Store::transition(
				$slot_id,
				$slot,
				array(
					'status' => Slot_Store::STATUS_FAILED,
					'error'  => $result->get_error_message(),
				)
			);
			return $result;
		}

		$slot = Slot_Store::transition( $slot_id, $slot, array_merge( $result, array( 'status' => Slot_Store::STATUS_COMPLETED ) ) );

		/**
		 * Fires after a file was imported through an upload slot.
		 *
		 * @param int   $attachment_id New attachment ID.
		 * @param array $slot          Slot data.
		 */
		do_action( 'exab_media_uploaded', $result['attachment_id'], $slot );

		return new WP_REST_Response( self::describe( $slot_id, $slot ), 201 );
	}

	/**
	 * Validates the received bytes and creates the attachment as the slot owner.
	 *
	 * @param string $body Raw request body.
	 * @param array  $slot Claimed slot.
	 * @return array|WP_Error Fields to store on the slot (attachment_id, set_as_featured), or an error.
	 */
	private static function import( string $body, array $slot ) {
		if ( strlen( $body ) !== (int) $slot['size_bytes'] ) {
			return new WP_Error(
				'exab_size_mismatch',
				sprintf( 'Received %d bytes, but the slot was created for %d bytes.', strlen( $body ), $slot['size_bytes'] ),
				array( 'status' => 400 )
			);
		}
		if ( ! empty( $slot['sha256'] ) && ! hash_equals( $slot['sha256'], hash( 'sha256', $body ) ) ) {
			return new WP_Error( 'exab_checksum_mismatch', 'The SHA-256 of the received file does not match the slot.', array( 'status' => 400 ) );
		}

		// Act as the slot owner from here on, and re-check: the role may have changed since the slot was created.
		wp_set_current_user( (int) $slot['user_id'] );
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'exab_forbidden', 'The slot owner may no longer upload files.', array( 'status' => 403 ) );
		}
		$post_id = (int) $slot['post_id'];
		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'exab_forbidden', sprintf( 'The slot owner may no longer edit post %d.', $post_id ), array( 'status' => 403 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = wp_tempnam( $slot['filename'] );
		if ( ! $tmp || false === file_put_contents( $tmp, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return new WP_Error( 'exab_tmp_failed', 'Could not write the temporary file.', array( 'status' => 500 ) );
		}

		// Sniff the real type from the content; the extension and header could lie.
		$real_mime = wp_get_image_mime( $tmp );
		if ( $real_mime !== $slot['mime_type'] ) {
			wp_delete_file( $tmp );
			return new WP_Error(
				'exab_content_mismatch',
				sprintf( 'The file content is not a valid %s image (detected: %s).', $slot['mime_type'], $real_mime ? $real_mime : 'unknown' ),
				array( 'status' => 415 )
			);
		}

		$post_data = array();
		if ( null !== $slot['caption'] ) {
			$post_data['post_excerpt'] = $slot['caption'];
		}
		if ( null !== $slot['description'] ) {
			$post_data['post_content'] = $slot['description'];
		}

		$attachment_id = media_handle_sideload(
			array(
				'name'     => $slot['filename'],
				'tmp_name' => $tmp,
			),
			$post_id,
			$slot['title'],
			$post_data
		);

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
			$attachment_id->add_data( array( 'status' => 400 ) );
			return $attachment_id;
		}

		if ( null !== $slot['alt_text'] && '' !== $slot['alt_text'] ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $slot['alt_text'] );
		}

		$set_as_featured = false;
		if ( $post_id && $slot['set_featured'] ) {
			$set_as_featured = (bool) set_post_thumbnail( $post_id, $attachment_id );
		}

		return array(
			'attachment_id'   => (int) $attachment_id,
			'set_as_featured' => $set_as_featured,
		);
	}

	/**
	 * Public view of a slot (never includes the token hash).
	 *
	 * @param string $slot_id Slot ID.
	 * @param array  $slot    Slot data.
	 * @return array Matches Schemas::status_output().
	 */
	private static function describe( string $slot_id, array $slot ): array {
		$attachment_id = $slot['attachment_id'] ? (int) $slot['attachment_id'] : null;
		$url           = $attachment_id ? wp_get_attachment_url( $attachment_id ) : null;

		return array(
			'slot_id'         => $slot_id,
			'status'          => Slot_Store::effective_status( $slot ),
			'filename'        => $slot['filename'],
			'expires_at'      => gmdate( 'c', (int) $slot['expires_at'] ),
			'attachment_id'   => $attachment_id,
			'url'             => $url ? $url : null,
			'set_as_featured' => (bool) $slot['set_as_featured'],
			'error'           => $slot['error'],
		);
	}

	/**
	 * Image types this user may upload, as WordPress' extension => MIME map.
	 *
	 * @return array<string, string> E.g. array( 'jpg|jpeg|jpe' => 'image/jpeg' ).
	 */
	public static function allowed_mime_map(): array {
		/**
		 * Filters the MIME types accepted by upload slots.
		 *
		 * @param string[] $types Default: JPEG, PNG, GIF, WebP, AVIF.
		 */
		$wanted = (array) apply_filters( 'exab_allowed_mime_types', self::default_mime_types() );

		return array_filter(
			get_allowed_mime_types(),
			static fn( $mime ) => in_array( $mime, $wanted, true )
		);
	}

	/**
	 * Image types this user may upload.
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
}
