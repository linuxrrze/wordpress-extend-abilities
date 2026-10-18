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
					'enum'        => Upload_Slots::default_mime_types(),
					'description' => 'MIME type of the file. Images only in this version.',
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
				'curl'         => array(
					'type'        => 'string',
					'description' => 'Ready-to-run command. Replace FILE with the local path.',
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
						Slot_Store::STATUS_UPLOADING,
						Slot_Store::STATUS_COMPLETED,
						Slot_Store::STATUS_FAILED,
						Slot_Store::STATUS_EXPIRED,
					),
				),
				'filename'        => array( 'type' => 'string' ),
				'expires_at'      => array( 'type' => 'string' ),
				'attachment_id'   => $nullable_int,
				'url'             => $nullable_string,
				'set_as_featured' => array( 'type' => 'boolean' ),
				'error'           => $nullable_string,
			),
		);
	}
}
