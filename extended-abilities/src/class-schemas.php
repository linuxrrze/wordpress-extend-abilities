<?php
/**
 * JSON schemas for all abilities, kept in one place.
 *
 * @package Exab
 */

namespace Exab;

defined( 'ABSPATH' ) || exit;

/**
 * Input and output schemas.
 */
final class Schemas {

	/**
	 * Input of exab/create-upload-slot.
	 */
	public static function create_slot_input(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'filename', 'mime_type', 'size_bytes' ),
			'additionalProperties' => false,
			'properties'           => array(
				'filename'     => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 200,
					'description' => 'File name including extension, e.g. "board.jpg". The extension must match mime_type.',
				),
				'mime_type'    => array(
					'type'        => 'string',
					'enum'        => Importer::configured_mime_types(),
					'description' => 'MIME type of the file: an image type, or application/pdf if PDF uploads are enabled.',
				),
				'size_bytes'   => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Exact file size in bytes (e.g. from `stat -c %s FILE`).',
				),
				'sha256'       => array(
					'type'        => 'string',
					'pattern'     => '^[a-f0-9]{64}$',
					'description' => 'Recommended: lowercase hex SHA-256 of the file (e.g. from `sha256sum FILE`). The upload is rejected on mismatch.',
				),
				'title'        => array(
					'type'        => 'string',
					'maxLength'   => 200,
					'description' => 'Media library title. Defaults to the file name.',
				),
				'alt_text'     => array(
					'type'        => 'string',
					'maxLength'   => 1000,
					'description' => 'Alternative text describing the image for screen readers.',
				),
				'caption'      => array(
					'type'      => 'string',
					'maxLength' => 2000,
				),
				'description'  => array(
					'type'      => 'string',
					'maxLength' => 10000,
				),
				'post_id'      => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Attach the upload to this post or page.',
				),
				'set_featured' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Also set the upload as featured image of post_id.',
				),
			),
		);
	}

	/**
	 * Output of exab/create-upload-slot.
	 */
	public static function create_slot_output(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'slot_id', 'upload_url', 'upload_token', 'method', 'headers', 'expires_at', 'curl' ),
			'properties' => array(
				'slot_id'      => array( 'type' => 'string' ),
				'upload_url'   => array( 'type' => 'string' ),
				'upload_token' => array( 'type' => 'string' ),
				'method'       => array( 'type' => 'string' ),
				'headers'      => array(
					'type'                 => 'object',
					'additionalProperties' => array( 'type' => 'string' ),
				),
				'expires_at'   => array( 'type' => 'string' ),
				'max_bytes'    => array( 'type' => 'integer' ),
				'max_chunk_bytes' => array( 'type' => 'integer' ),
				'curl'         => array(
					'type'        => 'string',
					'description' => 'Ready-to-run command. Replace FILE with the local path.',
				),
				'curl_chunked' => array(
					'type'        => 'string',
					'description' => 'Bash one-liner for chunked upload. Replace FILE with the local path; set OFFSET=<received_bytes> to resume.',
				),
			),
		);
	}

	/**
	 * Input of exab/get-upload-status.
	 */
	public static function status_input(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'slot_id' ),
			'additionalProperties' => false,
			'properties'           => array(
				'slot_id' => array(
					'type'    => 'string',
					'pattern' => '^[a-f0-9]{32}$',
				),
			),
		);
	}

	/**
	 * Output of exab/get-upload-status. Also the body of a successful upload.
	 */
	public static function status_output(): array {
		$nullable_int    = array( 'type' => array( 'integer', 'null' ) );
		$nullable_string = array( 'type' => array( 'string', 'null' ) );

		return array(
			'type'       => 'object',
			'required'   => array( 'slot_id', 'status' ),
			'properties' => array(
				'slot_id'         => array( 'type' => 'string' ),
				'status'          => array(
					'type' => 'string',
					'enum' => array(
						Slot_Store::STATUS_PENDING,
						Slot_Store::STATUS_RECEIVING,
						Slot_Store::STATUS_UPLOADING,
						Slot_Store::STATUS_COMPLETED,
						Slot_Store::STATUS_FAILED,
						Slot_Store::STATUS_EXPIRED,
					),
				),
				'filename'        => array( 'type' => 'string' ),
				'size_bytes'      => array( 'type' => 'integer' ),
				'received_bytes'  => array( 'type' => 'integer' ),
				'expires_at'      => array( 'type' => 'string' ),
				'attachment_id'   => $nullable_int,
				'url'             => $nullable_string,
				'set_as_featured' => array( 'type' => 'boolean' ),
				'gps_removed'     => array( 'type' => 'boolean' ),
				'replaced'        => array( 'type' => 'boolean' ),
				'posts_updated'   => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'integer' ),
					'description' => 'After a replacement: posts whose content referenced old image URLs and was updated.',
				),
				'posts_skipped'   => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'integer' ),
					'description' => 'After a replacement: posts with old URLs that the user may not edit (left unchanged).',
				),
				'error'           => $nullable_string,
			),
		);
	}

	/**
	 * Input of exab/upload-media-inline: the slot input without size/checksum, plus the data.
	 *
	 * @param int $max_bytes Largest accepted file.
	 * @return array
	 */
	public static function inline_input( int $max_bytes ): array {
		$schema = self::create_slot_input();
		unset( $schema['properties']['size_bytes'], $schema['properties']['sha256'] );

		$schema['required']                  = array( 'filename', 'mime_type', 'data_base64' );
		$schema['properties']['data_base64'] = array(
			'type'        => 'string',
			'minLength'   => 4,
			// Base64 grows by 4/3; leave room for a data: URI prefix and line breaks.
			'maxLength'   => (int) ( ceil( $max_bytes / 3 ) * 4 * 1.05 ) + 100,
			'description' => sprintf( 'File content as base64 (max. %d bytes decoded). A data: URI prefix is accepted.', $max_bytes ),
		);
		return $schema;
	}

	/**
	 * Output of exab/upload-media-inline.
	 *
	 * @return array
	 */
	public static function inline_output(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'attachment_id', 'url' ),
			'properties' => array(
				'attachment_id'   => array( 'type' => 'integer' ),
				'url'             => array( 'type' => 'string' ),
				'set_as_featured' => array( 'type' => 'boolean' ),
				'gps_removed'     => array( 'type' => 'boolean' ),
			),
		);
	}
}
