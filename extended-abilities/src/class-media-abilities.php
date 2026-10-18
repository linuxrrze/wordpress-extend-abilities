<?php
/**
 * Media library abilities: read one item, edit its metadata, set featured images.
 *
 * @package Exab
 */

namespace Exab;

use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * exab/get-media-item, exab/update-media, exab/set-featured-image.
 */
final class Media_Abilities {

	/**
	 * Registers the abilities.
	 */
	public static function register_abilities(): void {
		wp_register_ability(
			'exab/get-media-item',
			array(
				'label'               => __( 'Get Media Item', 'extended-abilities' ),
				'description'         => 'Returns one media library item: title, alt text, caption, description, MIME type, URL, dimensions, file size, parent post, the URL of every image size, and ready-to-paste Gutenberg block markup (wp:image) for the requested size. Use the block markup to insert the image into post content.',
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'attachment_id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'attachment_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'size'          => array(
							'type'        => 'string',
							'default'     => 'large',
							'description' => 'Image size for block_markup, e.g. thumbnail, medium, large, full. Falls back to full.',
						),
					),
				),
				'output_schema'       => self::item_schema(),
				'permission_callback' => array( __CLASS__, 'can_read_item' ),
				'execute_callback'    => array( __CLASS__, 'get_item' ),
				'meta'                => Plugin::ability_meta( true, false, true ),
			)
		);

		wp_register_ability(
			'exab/update-media',
			array(
				'label'               => __( 'Update Media Item', 'extended-abilities' ),
				'description'         => 'Updates the metadata of a media library item: title, alt text, caption, description, and the post it is attached to (post_id, 0 to detach). Only the fields you pass are changed; an empty string clears a field. Returns the updated item like exab/get-media-item.',
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'attachment_id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'attachment_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'title'         => array(
							'type'      => 'string',
							'maxLength' => 200,
						),
						'alt_text'      => array(
							'type'      => 'string',
							'maxLength' => 1000,
						),
						'caption'       => array(
							'type'      => 'string',
							'maxLength' => 2000,
						),
						'description'   => array(
							'type'      => 'string',
							'maxLength' => 10000,
						),
						'post_id'       => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => 'Attach to this post; 0 detaches.',
						),
					),
				),
				'output_schema'       => self::item_schema(),
				'permission_callback' => array( __CLASS__, 'can_update_item' ),
				'execute_callback'    => array( __CLASS__, 'update_item' ),
				'meta'                => Plugin::ability_meta( false, false, true ),
			)
		);

		wp_register_ability(
			'exab/set-featured-image',
			array(
				'label'               => __( 'Set Featured Image', 'extended-abilities' ),
				'description'         => 'Sets the featured image (post thumbnail) of a post, page or custom post type item. Pass attachment_id 0 to remove the featured image.',
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'post_id', 'attachment_id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'post_id'       => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'attachment_id' => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => 'Image attachment ID; 0 removes the featured image.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'required'   => array( 'post_id', 'featured_image_id' ),
					'properties' => array(
						'post_id'           => array( 'type' => 'integer' ),
						'featured_image_id' => array( 'type' => array( 'integer', 'null' ) ),
						'url'               => array( 'type' => array( 'string', 'null' ) ),
					),
				),
				'permission_callback' => array( __CLASS__, 'can_set_featured' ),
				'execute_callback'    => array( __CLASS__, 'set_featured' ),
				'meta'                => Plugin::ability_meta( false, false, true ),
			)
		);
	}

	/**
	 * Permission for exab/get-media-item.
	 *
	 * @param array $input Ability input.
	 * @return bool|WP_Error
	 */
	public static function can_read_item( $input ) {
		$attachment = self::attachment( (int) $input['attachment_id'] );
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}
		return current_user_can( 'read_post', $attachment->ID );
	}

	/**
	 * Permission for exab/update-media.
	 *
	 * @param array $input Ability input.
	 * @return bool|WP_Error
	 */
	public static function can_update_item( $input ) {
		$attachment = self::attachment( (int) $input['attachment_id'] );
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}
		if ( ! current_user_can( 'edit_post', $attachment->ID ) ) {
			return false;
		}
		if ( ! empty( $input['post_id'] ) && ! current_user_can( 'edit_post', (int) $input['post_id'] ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Permission for exab/set-featured-image.
	 *
	 * @param array $input Ability input.
	 * @return bool|WP_Error
	 */
	public static function can_set_featured( $input ) {
		$post = get_post( (int) $input['post_id'] );
		if ( ! $post ) {
			return new WP_Error( 'exab_post_not_found', sprintf( 'Post %d does not exist.', $input['post_id'] ) );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return false;
		}
		if ( ! empty( $input['attachment_id'] ) && ! current_user_can( 'read_post', (int) $input['attachment_id'] ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Executes exab/get-media-item.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function get_item( $input ) {
		$attachment = self::attachment( (int) $input['attachment_id'] );
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}
		return self::describe( $attachment, $input['size'] ?? 'large' );
	}

	/**
	 * Executes exab/update-media.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function update_item( $input ) {
		$attachment = self::attachment( (int) $input['attachment_id'] );
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}

		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : null;
		if ( $post_id && ! get_post( $post_id ) ) {
			return new WP_Error( 'exab_post_not_found', sprintf( 'Post %d does not exist.', $post_id ) );
		}

		$update = array( 'ID' => $attachment->ID );
		if ( isset( $input['title'] ) ) {
			$update['post_title'] = sanitize_text_field( $input['title'] );
		}
		if ( isset( $input['caption'] ) ) {
			$update['post_excerpt'] = wp_kses_post( $input['caption'] );
		}
		if ( isset( $input['description'] ) ) {
			$update['post_content'] = wp_kses_post( $input['description'] );
		}
		if ( null !== $post_id ) {
			$update['post_parent'] = $post_id;
		}

		if ( count( $update ) > 1 ) {
			$result = wp_update_post( wp_slash( $update ), true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( isset( $input['alt_text'] ) ) {
			$alt = sanitize_text_field( $input['alt_text'] );
			if ( '' === $alt ) {
				delete_post_meta( $attachment->ID, '_wp_attachment_image_alt' );
			} else {
				update_post_meta( $attachment->ID, '_wp_attachment_image_alt', wp_slash( $alt ) );
			}
		}

		return self::describe( get_post( $attachment->ID ), 'large' );
	}

	/**
	 * Executes exab/set-featured-image.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function set_featured( $input ) {
		$post_id       = (int) $input['post_id'];
		$attachment_id = (int) $input['attachment_id'];

		if ( 0 === $attachment_id ) {
			delete_post_thumbnail( $post_id );
			return array(
				'post_id'           => $post_id,
				'featured_image_id' => null,
				'url'               => null,
			);
		}

		$attachment = self::attachment( $attachment_id );
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}
		if ( ! wp_attachment_is_image( $attachment ) ) {
			return new WP_Error( 'exab_not_an_image', sprintf( 'Attachment %d is not an image.', $attachment_id ) );
		}
		if ( ! post_type_supports( get_post_type( $post_id ), 'thumbnail' ) ) {
			return new WP_Error( 'exab_no_thumbnail_support', sprintf( 'Post type "%s" does not support featured images.', get_post_type( $post_id ) ) );
		}

		// set_post_thumbnail() returns false when the value is unchanged, so check the result instead.
		set_post_thumbnail( $post_id, $attachment_id );
		if ( (int) get_post_thumbnail_id( $post_id ) !== $attachment_id ) {
			return new WP_Error( 'exab_featured_failed', 'Could not set the featured image.' );
		}

		return array(
			'post_id'           => $post_id,
			'featured_image_id' => $attachment_id,
			'url'               => wp_get_attachment_url( $attachment_id ) ?: null,
		);
	}

	/**
	 * Loads an attachment or returns a not-found error.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return WP_Post|WP_Error
	 */
	private static function attachment( int $attachment_id ) {
		$post = get_post( $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'exab_attachment_not_found', sprintf( 'Media item %d does not exist.', $attachment_id ) );
		}
		return $post;
	}

	/**
	 * Builds the public view of an attachment.
	 *
	 * @param WP_Post $attachment Attachment.
	 * @param string  $size       Size for the block markup.
	 * @return array Matches item_schema().
	 */
	private static function describe( WP_Post $attachment, string $size ): array {
		$id       = $attachment->ID;
		$is_image = wp_attachment_is_image( $attachment );
		$meta     = wp_get_attachment_metadata( $id );
		$meta     = is_array( $meta ) ? $meta : array();
		$file     = get_attached_file( $id );
		$alt      = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );

		$filesize = $meta['filesize'] ?? ( $file && file_exists( $file ) ? filesize( $file ) : null );

		$sizes = array();
		if ( $is_image ) {
			foreach ( array_merge( array_keys( $meta['sizes'] ?? array() ), array( 'full' ) ) as $name ) {
				$src = wp_get_attachment_image_src( $id, $name );
				if ( $src ) {
					$sizes[ $name ] = array(
						'url'    => $src[0],
						'width'  => (int) $src[1],
						'height' => (int) $src[2],
					);
				}
			}
		}

		$block_size = isset( $sizes[ $size ] ) ? $size : 'full';

		return array(
			'attachment_id' => $id,
			'title'         => $attachment->post_title,
			'alt_text'      => $alt,
			'caption'       => $attachment->post_excerpt,
			'description'   => $attachment->post_content,
			'mime_type'     => $attachment->post_mime_type,
			'url'           => (string) wp_get_attachment_url( $id ),
			'width'         => isset( $meta['width'] ) ? (int) $meta['width'] : null,
			'height'        => isset( $meta['height'] ) ? (int) $meta['height'] : null,
			'filesize'      => null !== $filesize ? (int) $filesize : null,
			'post_id'       => $attachment->post_parent ? (int) $attachment->post_parent : null,
			'sizes'         => (object) $sizes,
			'block_markup'  => $is_image && isset( $sizes[ $block_size ] )
				? self::image_block( $id, $block_size, $sizes[ $block_size ]['url'], $alt, $attachment->post_excerpt )
				: null,
		);
	}

	/**
	 * Gutenberg markup for a core/image block, as the block editor would save it.
	 *
	 * @param int    $id      Attachment ID.
	 * @param string $size    Size slug.
	 * @param string $src     Image URL for that size.
	 * @param string $alt     Alt text.
	 * @param string $caption Caption (HTML allowed).
	 * @return string
	 */
	private static function image_block( int $id, string $size, string $src, string $alt, string $caption ): string {
		$attrs = wp_json_encode(
			array(
				'id'              => $id,
				'sizeSlug'        => $size,
				'linkDestination' => 'none',
			)
		);
		$figcaption = '' !== $caption
			? '<figcaption class="wp-element-caption">' . wp_kses_post( $caption ) . '</figcaption>'
			: '';

		return sprintf(
			"<!-- wp:image %s -->\n<figure class=\"wp-block-image size-%s\"><img src=\"%s\" alt=\"%s\" class=\"wp-image-%d\"/>%s</figure>\n<!-- /wp:image -->",
			$attrs,
			esc_attr( $size ),
			esc_url( $src ),
			esc_attr( $alt ),
			$id,
			$figcaption
		);
	}

	/**
	 * Output schema shared by get-media-item and update-media.
	 *
	 * @return array
	 */
	private static function item_schema(): array {
		$nullable_int = array( 'type' => array( 'integer', 'null' ) );

		return array(
			'type'       => 'object',
			'required'   => array( 'attachment_id', 'url', 'mime_type' ),
			'properties' => array(
				'attachment_id' => array( 'type' => 'integer' ),
				'title'         => array( 'type' => 'string' ),
				'alt_text'      => array( 'type' => 'string' ),
				'caption'       => array( 'type' => 'string' ),
				'description'   => array( 'type' => 'string' ),
				'mime_type'     => array( 'type' => 'string' ),
				'url'           => array( 'type' => 'string' ),
				'width'         => $nullable_int,
				'height'        => $nullable_int,
				'filesize'      => $nullable_int,
				'post_id'       => $nullable_int,
				'sizes'         => array(
					'type'                 => 'object',
					'additionalProperties' => array(
						'type'       => 'object',
						'properties' => array(
							'url'    => array( 'type' => 'string' ),
							'width'  => array( 'type' => 'integer' ),
							'height' => array( 'type' => 'integer' ),
						),
					),
				),
				'block_markup'  => array( 'type' => array( 'string', 'null' ) ),
			),
		);
	}
}
