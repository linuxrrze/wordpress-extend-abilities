<?php
/**
 * Upload slots: MCP controls the upload, the bytes travel over plain HTTP.
 *
 * 1. exab/create-upload-slot checks the planned upload and returns a URL and token.
 * 2. The client sends the raw file to that URL (PUT or POST, X-Exab-Upload-Token header),
 *    either in one request or in sequential chunks with a Content-Range header (resumable).
 * 3. exab/get-upload-status reports progress and the result.
 *
 * Tiny files can skip all of this with exab/upload-media-inline (base64 in the tool call).
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
 * Abilities and REST route for slot-based and inline uploads.
 */
final class Upload_Slots {

	const REST_NAMESPACE = 'exab/v1';
	const TOKEN_HEADER   = 'X-Exab-Upload-Token';
	const CHUNK_DIR      = 'exab-chunks';

	/**
	 * A chunk that has been "in progress" for longer than this is considered abandoned.
	 */
	const CHUNK_LOCK_TIMEOUT = 120;

	/**
	 * Registers exab/create-upload-slot, exab/get-upload-status and exab/upload-media-inline.
	 */
	public static function register_abilities(): void {
		wp_register_ability(
			'exab/create-upload-slot',
			array(
				'label'               => __( 'Create Media Upload Slot', 'extended-abilities' ),
				'description'         => 'Prepares the upload of a LOCAL file (image, or PDF if enabled) to the media library. Returns an upload URL and token, valid for a few minutes. '
					. 'Then send the raw file bytes with HTTP PUT: run the returned "curl" command after replacing FILE with the local path. '
					. 'For files larger than max_chunk_bytes, run "curl_chunked" instead (it sends Content-Range chunks; an interrupted upload can resume at received_bytes from exab/get-upload-status). '
					. 'The final HTTP response (and exab/get-upload-status) contains the attachment ID and URL. '
					. 'Pass the exact size_bytes and, ideally, sha256 of the file. Title, alt text, caption, parent post and featured image are applied on upload. GPS location data is removed from images unless disabled in the settings. '
					. 'Use this instead of base64 or ewpa/upload-image when the file is on the local disk.',
				'category'            => Plugin::CATEGORY,
				'input_schema'        => Schemas::create_slot_input(),
				'output_schema'       => Schemas::create_slot_output(),
				'permission_callback' => array( __CLASS__, 'can_create' ),
				'execute_callback'    => array( __CLASS__, 'create' ),
				'meta'                => Plugin::ability_meta( false, false, false ),
			)
		);

		wp_register_ability(
			'exab/get-upload-status',
			array(
				'label'               => __( 'Get Media Upload Status', 'extended-abilities' ),
				'description'         => 'Returns the state of an upload slot created with exab/create-upload-slot: pending, receiving (chunked upload in progress, with received_bytes), uploading, completed (with attachment_id and url), failed (with error) or expired.',
				'category'            => Plugin::CATEGORY,
				'input_schema'        => Schemas::status_input(),
				'output_schema'       => Schemas::status_output(),
				'permission_callback' => array( __CLASS__, 'can_read_status' ),
				'execute_callback'    => array( __CLASS__, 'status' ),
				'meta'                => Plugin::ability_meta( true, false, true ),
			)
		);

		wp_register_ability(
			'exab/upload-media-inline',
			array(
				'label'               => __( 'Upload Small Media File (Inline)', 'extended-abilities' ),
				'description'         => sprintf(
					'Uploads a TINY file (at most %d bytes, e.g. a generated icon or a small diagram) passed as base64 in data_base64. A "data:...;base64," prefix is accepted. '
					. 'Every byte of base64 costs output tokens, so for anything larger, or for files on the local disk, use exab/create-upload-slot instead.',
					self::inline_max_bytes()
				),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => Schemas::inline_input( self::inline_max_bytes() ),
				'output_schema'       => Schemas::inline_output(),
				'permission_callback' => array( __CLASS__, 'can_create' ),
				'execute_callback'    => array( __CLASS__, 'upload_inline' ),
				'meta'                => Plugin::ability_meta( false, false, false ),
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
				// The slot token is the only credential; it is checked in receive().
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Permission check for exab/create-upload-slot and exab/upload-media-inline.
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
		$size = (int) $input['size_bytes'];
		$meta = Importer::prepare( $input, $size );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}
		$user_id = $meta['user_id'];

		/**
		 * Filters how many open (pending or receiving) slots one user may hold at the same time.
		 *
		 * @param int $limit   Default 10.
		 * @param int $user_id User ID.
		 */
		$open_limit = (int) apply_filters( 'exab_max_open_slots', 10, $user_id );
		if ( Slot_Store::count_open( $user_id ) >= $open_limit ) {
			return new WP_Error( 'exab_too_many_slots', sprintf( 'You already have %d open upload slots. Use or let them expire first.', $open_limit ) );
		}

		$slot_id = bin2hex( random_bytes( 16 ) );
		$token   = bin2hex( random_bytes( 32 ) );
		$expires = time() + self::ttl();

		$stored = Slot_Store::create(
			$slot_id,
			array_merge(
				$meta,
				array(
					'status'          => Slot_Store::STATUS_PENDING,
					'token_hash'      => hash( 'sha256', $token ),
					'created_at'      => time(),
					'expires_at'      => $expires,
					'received_bytes'  => 0,
					'chunk_lock'      => null,
					'attachment_id'   => null,
					'set_as_featured' => false,
					'gps_removed'     => false,
					'error'           => null,
				)
			)
		);
		if ( ! $stored ) {
			return new WP_Error( 'exab_slot_not_stored', 'Could not store the upload slot.' );
		}

		$url       = rest_url( self::REST_NAMESPACE . '/upload/' . $slot_id );
		$chunk_max = self::chunk_max_bytes();
		$headers   = sprintf( "-H 'Content-Type: %s' -H '%s: %s'", $meta['mime_type'], self::TOKEN_HEADER, $token );

		return array(
			'slot_id'         => $slot_id,
			'upload_url'      => $url,
			'upload_token'    => $token,
			'method'          => 'PUT',
			'headers'         => array(
				self::TOKEN_HEADER => $token,
				'Content-Type'     => $meta['mime_type'],
			),
			'expires_at'      => gmdate( 'c', $expires ),
			'max_bytes'       => $size,
			'max_chunk_bytes' => $chunk_max,
			'curl'            => sprintf( "curl -sS -X PUT --data-binary @FILE %s '%s'", $headers, $url ),
			'curl_chunked'    => sprintf(
				// One line of bash: sends FILE in sequential chunks, stops at the first HTTP error.
				'f=FILE; size=%1$d; chunk=%2$d; off=${OFFSET:-0}; while [ $off -lt $size ]; do len=$(( size-off < chunk ? size-off : chunk )); '
				. 'tail -c +$((off+1)) "$f" | head -c $len | curl -sS -f -X PUT --data-binary @- %3$s -H "Content-Range: bytes $off-$((off+len-1))/$size" \'%4$s\' || break; echo; off=$((off+len)); done',
				$size,
				$chunk_max,
				$headers,
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
	 * Uploads a small base64 file (execute callback of exab/upload-media-inline).
	 *
	 * @param array $input Validated ability input.
	 * @return array|WP_Error
	 */
	public static function upload_inline( $input ) {
		$encoded = preg_replace( '/^data:[^;,]*;base64,/', '', trim( $input['data_base64'] ) );
		$bytes   = base64_decode( preg_replace( '/\s+/', '', $encoded ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $bytes || '' === $bytes ) {
			return new WP_Error( 'exab_invalid_base64', 'data_base64 is not valid base64.' );
		}

		$limit = self::inline_max_bytes();
		if ( strlen( $bytes ) > $limit ) {
			return new WP_Error(
				'exab_inline_too_large',
				sprintf( 'The file is %d bytes; inline uploads are limited to %d bytes. Use exab/create-upload-slot.', strlen( $bytes ), $limit )
			);
		}

		$meta = Importer::prepare( $input, strlen( $bytes ) );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = wp_tempnam( $meta['filename'] );
		if ( ! $tmp || false === file_put_contents( $tmp, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return new WP_Error( 'exab_tmp_failed', 'Could not write the temporary file.' );
		}

		$result = Importer::import_file( $tmp, $meta );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		/** This action is documented in src/class-upload-slots.php */
		do_action( 'exab_media_uploaded', $result['attachment_id'], $meta );

		return array(
			'attachment_id'   => $result['attachment_id'],
			'url'             => (string) wp_get_attachment_url( $result['attachment_id'] ),
			'set_as_featured' => $result['set_as_featured'],
			'gps_removed'     => $result['gps_removed'],
		);
	}

	/**
	 * Receives the file bytes for a slot (REST callback): whole file or one chunk.
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
		if ( ! in_array( $status, array( Slot_Store::STATUS_PENDING, Slot_Store::STATUS_RECEIVING ), true ) ) {
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

		$range = $request->get_header( 'Content-Range' );
		if ( null !== $range && '' !== $range ) {
			return self::receive_chunk( $slot_id, $slot, (string) $range, $request->get_body() );
		}

		if ( Slot_Store::STATUS_RECEIVING === $status ) {
			return new WP_Error(
				'exab_chunked_in_progress',
				sprintf( 'A chunked upload is in progress for this slot. Continue with Content-Range at offset %d.', $slot['received_bytes'] ),
				array(
					'status'          => 409,
					'expected_offset' => (int) $slot['received_bytes'],
				)
			);
		}

		// Whole file in one request. Claim the slot: only one request can win this transition.
		$slot = Slot_Store::transition( $slot_id, $slot, array( 'status' => Slot_Store::STATUS_UPLOADING ) );
		if ( ! $slot ) {
			return new WP_Error( 'exab_slot_used', 'This upload slot is already being used.', array( 'status' => 409 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = wp_tempnam( $slot['filename'] );
		if ( ! $tmp || false === file_put_contents( $tmp, $request->get_body() ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return self::fail( $slot_id, $slot, new WP_Error( 'exab_tmp_failed', 'Could not write the temporary file.', array( 'status' => 500 ) ) );
		}

		return self::finish( $slot_id, $slot, $tmp );
	}

	/**
	 * Appends one chunk. Chunks must arrive in order; the last one triggers the import.
	 *
	 * @param string $slot_id Slot ID.
	 * @param array  $slot    Slot (pending or receiving).
	 * @param string $range   Content-Range header, "bytes START-END/TOTAL".
	 * @param string $body    Chunk bytes.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function receive_chunk( string $slot_id, array $slot, string $range, string $body ) {
		if ( ! preg_match( '#^bytes (\d+)-(\d+)/(\d+)$#', trim( $range ), $m ) ) {
			return new WP_Error( 'exab_invalid_range', 'Content-Range must look like "bytes START-END/TOTAL".', array( 'status' => 400 ) );
		}
		list( , $start, $end, $total ) = array_map( 'intval', $m );

		if ( $total !== (int) $slot['size_bytes'] ) {
			return new WP_Error(
				'exab_range_total_mismatch',
				sprintf( 'Content-Range total is %d, but the slot was created for %d bytes.', $total, $slot['size_bytes'] ),
				array( 'status' => 400 )
			);
		}
		if ( $end < $start || $end >= $total || strlen( $body ) !== $end - $start + 1 ) {
			return new WP_Error(
				'exab_range_length_mismatch',
				sprintf( 'Content-Range %d-%d does not match the %d bytes received.', $start, $end, strlen( $body ) ),
				array( 'status' => 400 )
			);
		}
		if ( strlen( $body ) > self::chunk_max_bytes() ) {
			return new WP_Error(
				'exab_chunk_too_large',
				sprintf( 'Chunks may be at most %d bytes.', self::chunk_max_bytes() ),
				array( 'status' => 413 )
			);
		}
		if ( $start !== (int) $slot['received_bytes'] ) {
			return new WP_Error(
				'exab_range_mismatch',
				sprintf( 'Expected the chunk at offset %d (got %d). Resume from there.', $slot['received_bytes'], $start ),
				array(
					'status'          => 409,
					'expected_offset' => (int) $slot['received_bytes'],
				)
			);
		}
		if ( $slot['chunk_lock'] && time() - (int) $slot['chunk_lock'] < self::CHUNK_LOCK_TIMEOUT ) {
			return new WP_Error( 'exab_chunk_in_progress', 'Another chunk for this slot is being received.', array( 'status' => 409 ) );
		}

		// Claim this offset. Concurrent requests for the same offset lose here.
		$slot = Slot_Store::transition(
			$slot_id,
			$slot,
			array(
				'status'     => Slot_Store::STATUS_RECEIVING,
				'chunk_lock' => time(),
			)
		);
		if ( ! $slot ) {
			return new WP_Error( 'exab_chunk_in_progress', 'Another chunk for this slot is being received.', array( 'status' => 409 ) );
		}

		$path = self::chunk_path( $slot_id );
		if ( ! $path ) {
			return self::fail( $slot_id, $slot, new WP_Error( 'exab_tmp_failed', 'Could not create the chunk directory.', array( 'status' => 500 ) ) );
		}
		// The first chunk starts a fresh file; later ones append. LOCK_EX guards against stray writers.
		$written = file_put_contents( $path, $body, ( $start > 0 ? FILE_APPEND : 0 ) | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		clearstatcache( true, $path );
		if ( strlen( $body ) !== $written || filesize( $path ) !== $end + 1 ) {
			return self::fail( $slot_id, $slot, new WP_Error( 'exab_chunk_write_failed', 'Could not store the chunk.', array( 'status' => 500 ) ) );
		}

		$received = $end + 1;
		if ( $received < $total ) {
			$slot = Slot_Store::transition(
				$slot_id,
				$slot,
				array(
					'received_bytes' => $received,
					'chunk_lock'     => null,
					// Sliding expiry: an upload that keeps making progress does not time out.
					'expires_at'     => max( (int) $slot['expires_at'], time() + self::ttl() ),
				)
			);
			return new WP_REST_Response( self::describe( $slot_id, $slot ), 202 );
		}

		$slot = Slot_Store::transition(
			$slot_id,
			$slot,
			array(
				'status'         => Slot_Store::STATUS_UPLOADING,
				'received_bytes' => $received,
				'chunk_lock'     => null,
			)
		);
		return self::finish( $slot_id, $slot, $path );
	}

	/**
	 * Imports the complete file and records the outcome on the slot.
	 *
	 * @param string $slot_id Slot ID.
	 * @param array  $slot    Claimed slot (status uploading).
	 * @param string $path    Complete file; consumed.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function finish( string $slot_id, array $slot, string $path ) {
		$result = Importer::import_file( $path, $slot );
		if ( is_wp_error( $result ) ) {
			return self::fail( $slot_id, $slot, $result );
		}

		$slot = Slot_Store::transition( $slot_id, $slot, array_merge( $result, array( 'status' => Slot_Store::STATUS_COMPLETED ) ) );

		/**
		 * Fires after a file was imported through an upload slot or inline upload.
		 *
		 * @param int   $attachment_id New attachment ID.
		 * @param array $upload        Slot data, or the upload metadata for inline uploads.
		 */
		do_action( 'exab_media_uploaded', $result['attachment_id'], $slot );

		return new WP_REST_Response( self::describe( $slot_id, $slot ), 201 );
	}

	/**
	 * Marks a slot as failed, removes partial data, and passes the error on.
	 *
	 * @param string   $slot_id Slot ID.
	 * @param array    $slot    Current slot.
	 * @param WP_Error $error   The failure.
	 * @return WP_Error
	 */
	private static function fail( string $slot_id, array $slot, WP_Error $error ): WP_Error {
		self::delete_chunk_file( $slot_id );
		Slot_Store::transition(
			$slot_id,
			$slot,
			array(
				'status'     => Slot_Store::STATUS_FAILED,
				'chunk_lock' => null,
				'error'      => $error->get_error_message(),
			)
		);
		return $error;
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
			'size_bytes'      => (int) $slot['size_bytes'],
			'received_bytes'  => (int) ( $slot['received_bytes'] ?? 0 ),
			'expires_at'      => gmdate( 'c', (int) $slot['expires_at'] ),
			'attachment_id'   => $attachment_id,
			'url'             => $url ? $url : null,
			'set_as_featured' => (bool) $slot['set_as_featured'],
			'gps_removed'     => (bool) ( $slot['gps_removed'] ?? false ),
			'error'           => $slot['error'],
		);
	}

	/**
	 * Path of a slot's chunk file inside a non-public directory of the uploads folder.
	 *
	 * The name includes a keyed hash, so it can't be derived from the (public) slot ID.
	 *
	 * @param string $slot_id Slot ID.
	 * @return string|null Path, or null if the directory cannot be created.
	 */
	public static function chunk_path( string $slot_id ): ?string {
		$dir = self::chunk_dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return null;
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/.htaccess', "Require all denied\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $dir . '/' . $slot_id . '-' . substr( hash_hmac( 'sha256', $slot_id, wp_salt( 'auth' ) ), 0, 32 ) . '.part';
	}

	/**
	 * Directory for partial chunked uploads.
	 *
	 * @return string
	 */
	public static function chunk_dir(): string {
		return wp_upload_dir( null, false )['basedir'] . '/' . self::CHUNK_DIR;
	}

	/**
	 * Removes a slot's partial upload, if any.
	 *
	 * @param string $slot_id Slot ID.
	 */
	public static function delete_chunk_file( string $slot_id ): void {
		$path = self::chunk_dir() . '/' . $slot_id . '-' . substr( hash_hmac( 'sha256', $slot_id, wp_salt( 'auth' ) ), 0, 32 ) . '.part';
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Slot lifetime in seconds (extended by every received chunk).
	 *
	 * @return int
	 */
	public static function ttl(): int {
		/**
		 * Filters the lifetime of an upload slot in seconds.
		 *
		 * @param int $ttl Default 15 minutes.
		 */
		return max( 60, (int) apply_filters( 'exab_slot_ttl', 15 * MINUTE_IN_SECONDS ) );
	}

	/**
	 * Largest accepted chunk in bytes.
	 *
	 * @return int
	 */
	public static function chunk_max_bytes(): int {
		/**
		 * Filters the maximum chunk size. Keep it well below the web server's body limit
		 * and PHP's memory_limit: each chunk is held in memory once.
		 *
		 * @param int $bytes Default 8 MB.
		 */
		return max( 1024, (int) apply_filters( 'exab_chunk_max_bytes', 8 * MB_IN_BYTES ) );
	}

	/**
	 * Largest file accepted by exab/upload-media-inline.
	 *
	 * @return int
	 */
	public static function inline_max_bytes(): int {
		/**
		 * Filters the size limit of inline (base64) uploads.
		 *
		 * @param int $bytes Default 64 KB.
		 */
		return (int) min( Importer::max_bytes(), apply_filters( 'exab_inline_max_bytes', 64 * KB_IN_BYTES ) );
	}
}
