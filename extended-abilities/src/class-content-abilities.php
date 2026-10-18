<?php
/**
 * Content abilities that EWPA does not cover: scheduling, page updates, revisions.
 *
 * @package Exab
 */

namespace Exab;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * exab/schedule-post, exab/update-page, exab/list-revisions, exab/restore-revision.
 */
final class Content_Abilities {

	/**
	 * Registers the abilities.
	 */
	public static function register_abilities(): void {
		wp_register_ability(
			'exab/schedule-post',
			array(
				'label'               => __( 'Schedule Post', 'extended-abilities' ),
				'description'         => 'Schedules an existing draft or pending post (any post type, including pages) for publication at a future date. The date is ISO 8601; without a UTC offset it is interpreted in the site time zone. Rescheduling an already scheduled post is allowed; published posts are rejected. To unschedule, set the status back to draft (e.g. ewpa/update-post).',
				'category'            => Plugin::CONTENT_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'post_id', 'date' ),
					'additionalProperties' => false,
					'properties'           => array(
						'post_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'date'    => array(
							'type'        => 'string',
							'minLength'   => 10,
							'maxLength'   => 40,
							'description' => 'E.g. "2026-10-25T09:00:00+02:00", or "2026-10-25 09:00" in site time.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'required'   => array( 'post_id', 'status', 'date_local', 'date_gmt' ),
					'properties' => array(
						'post_id'    => array( 'type' => 'integer' ),
						'status'     => array( 'type' => 'string' ),
						'date_local' => array( 'type' => 'string' ),
						'date_gmt'   => array( 'type' => 'string' ),
						'timezone'   => array( 'type' => 'string' ),
					),
				),
				'permission_callback' => array( __CLASS__, 'can_schedule' ),
				'execute_callback'    => array( __CLASS__, 'schedule' ),
				'meta'                => Plugin::ability_meta( false, false, true ),
			)
		);

		wp_register_ability(
			'exab/update-page',
			array(
				'label'               => __( 'Update Page', 'extended-abilities' ),
				'description'         => 'Updates an existing page. Only the fields you pass are changed: title, content (HTML or Gutenberg blocks), excerpt, slug, status, parent page (0 for top level) and menu order.',
				'category'            => Plugin::CONTENT_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'page_id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'page_id'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'title'      => array( 'type' => 'string' ),
						'content'    => array( 'type' => 'string' ),
						'excerpt'    => array( 'type' => 'string' ),
						'slug'       => array(
							'type'      => 'string',
							'maxLength' => 200,
						),
						'status'     => array(
							'type' => 'string',
							'enum' => array( 'draft', 'pending', 'private', 'publish' ),
						),
						'parent_id'  => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'menu_order' => array( 'type' => 'integer' ),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'required'   => array( 'page_id', 'status' ),
					'properties' => array(
						'page_id'      => array( 'type' => 'integer' ),
						'status'       => array( 'type' => 'string' ),
						'slug'         => array( 'type' => 'string' ),
						'parent_id'    => array( 'type' => 'integer' ),
						'link'         => array( 'type' => 'string' ),
						'modified_gmt' => array( 'type' => 'string' ),
					),
				),
				'permission_callback' => array( __CLASS__, 'can_update_page' ),
				'execute_callback'    => array( __CLASS__, 'update_page' ),
				'meta'                => Plugin::ability_meta( false, false, true ),
			)
		);

		wp_register_ability(
			'exab/list-revisions',
			array(
				'label'               => __( 'List Revisions', 'extended-abilities' ),
				'description'         => 'Lists the revisions (and autosaves) of a post or page, newest first: revision ID, date, author, title and content length. Use exab/restore-revision to go back to one.',
				'category'            => Plugin::CONTENT_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'post_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'limit'   => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'required'   => array( 'post_id', 'revisions' ),
					'properties' => array(
						'post_id'   => array( 'type' => 'integer' ),
						'revisions' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'revision_id'    => array( 'type' => 'integer' ),
									'date_gmt'       => array( 'type' => 'string' ),
									'author'         => array( 'type' => 'string' ),
									'title'          => array( 'type' => 'string' ),
									'is_autosave'    => array( 'type' => 'boolean' ),
									'content_length' => array( 'type' => 'integer' ),
								),
							),
						),
					),
				),
				'permission_callback' => array( __CLASS__, 'can_edit_parent_post' ),
				'execute_callback'    => array( __CLASS__, 'list_revisions' ),
				'meta'                => Plugin::ability_meta( true, false, true ),
			)
		);

		wp_register_ability(
			'exab/restore-revision',
			array(
				'label'               => __( 'Restore Revision', 'extended-abilities' ),
				'description'         => 'Restores a post or page to a revision from exab/list-revisions. The current state is saved as a new revision first, so a restore can itself be undone.',
				'category'            => Plugin::CONTENT_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'revision_id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'revision_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'required'   => array( 'post_id', 'restored_revision_id' ),
					'properties' => array(
						'post_id'              => array( 'type' => 'integer' ),
						'restored_revision_id' => array( 'type' => 'integer' ),
						'title'                => array( 'type' => 'string' ),
					),
				),
				'permission_callback' => array( __CLASS__, 'can_restore' ),
				'execute_callback'    => array( __CLASS__, 'restore' ),
				'meta'                => Plugin::ability_meta( false, false, true ),
			)
		);
	}

	/**
	 * Permission for exab/schedule-post: edit the post and publish its post type.
	 *
	 * @param array $input Ability input.
	 * @return bool|WP_Error
	 */
	public static function can_schedule( $input ) {
		$post = get_post( (int) $input['post_id'] );
		if ( ! $post ) {
			return new WP_Error( 'exab_post_not_found', sprintf( 'Post %d does not exist.', $input['post_id'] ) );
		}
		$type = get_post_type_object( $post->post_type );
		return $type
			&& current_user_can( 'edit_post', $post->ID )
			&& current_user_can( $type->cap->publish_posts );
	}

	/**
	 * Executes exab/schedule-post.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function schedule( $input ) {
		$post = get_post( (int) $input['post_id'] );
		if ( ! in_array( $post->post_status, array( 'draft', 'pending', 'future', 'auto-draft' ), true ) ) {
			return new WP_Error(
				'exab_not_schedulable',
				sprintf( 'Post %d has status "%s"; only drafts, pending and scheduled posts can be scheduled.', $post->ID, $post->post_status )
			);
		}

		try {
			$date = new DateTimeImmutable( $input['date'], wp_timezone() );
		} catch ( Exception $e ) {
			return new WP_Error( 'exab_invalid_date', sprintf( 'Could not parse date "%s".', $input['date'] ) );
		}
		if ( $date->getTimestamp() <= time() ) {
			return new WP_Error( 'exab_date_in_past', 'The date must be in the future.' );
		}

		$local = $date->setTimezone( wp_timezone() );
		$gmt   = $date->setTimezone( new DateTimeZone( 'UTC' ) );

		$result = wp_update_post(
			array(
				'ID'            => $post->ID,
				'post_status'   => 'future',
				'post_date'     => $local->format( 'Y-m-d H:i:s' ),
				'post_date_gmt' => $gmt->format( 'Y-m-d H:i:s' ),
				'edit_date'     => true,
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$status = get_post_status( $post->ID );
		if ( 'future' !== $status ) {
			return new WP_Error( 'exab_schedule_failed', sprintf( 'WordPress set the status to "%s" instead of "future".', $status ) );
		}

		return array(
			'post_id'    => $post->ID,
			'status'     => $status,
			'date_local' => $local->format( 'c' ),
			'date_gmt'   => $gmt->format( 'c' ),
			'timezone'   => wp_timezone_string(),
		);
	}

	/**
	 * Permission for exab/update-page. Publishing needs publish_pages.
	 *
	 * @param array $input Ability input.
	 * @return bool|WP_Error
	 */
	public static function can_update_page( $input ) {
		$page = self::page( (int) $input['page_id'] );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		if ( ! current_user_can( 'edit_post', $page->ID ) ) {
			return false;
		}
		$status = $input['status'] ?? null;
		if ( in_array( $status, array( 'publish', 'private' ), true ) && ! current_user_can( 'publish_pages' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Executes exab/update-page.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function update_page( $input ) {
		$page   = self::page( (int) $input['page_id'] );
		$update = array( 'ID' => $page->ID );

		$fields = array(
			'title'      => 'post_title',
			'content'    => 'post_content',
			'excerpt'    => 'post_excerpt',
			'slug'       => 'post_name',
			'status'     => 'post_status',
			'menu_order' => 'menu_order',
		);
		foreach ( $fields as $key => $field ) {
			if ( isset( $input[ $key ] ) ) {
				$update[ $field ] = $input[ $key ];
			}
		}

		if ( isset( $input['parent_id'] ) ) {
			$parent_id = (int) $input['parent_id'];
			if ( $parent_id ) {
				$parent = self::page( $parent_id );
				if ( is_wp_error( $parent ) ) {
					return $parent;
				}
				if ( $parent_id === $page->ID || in_array( $page->ID, get_post_ancestors( $parent_id ), true ) ) {
					return new WP_Error( 'exab_parent_loop', 'A page cannot be its own parent or the parent of one of its ancestors.' );
				}
			}
			$update['post_parent'] = $parent_id;
		}

		if ( count( $update ) > 1 ) {
			// wp_update_post() expects slashed data; kses filters still apply for users without unfiltered_html.
			$result = wp_update_post( wp_slash( $update ), true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$page = get_post( $page->ID );
		return array(
			'page_id'      => $page->ID,
			'status'       => $page->post_status,
			'slug'         => $page->post_name,
			'parent_id'    => (int) $page->post_parent,
			'link'         => (string) get_permalink( $page ),
			'modified_gmt' => mysql_to_rfc3339( $page->post_modified_gmt ),
		);
	}

	/**
	 * Permission for exab/list-revisions: edit the post the revisions belong to.
	 *
	 * @param array $input Ability input.
	 * @return bool|WP_Error
	 */
	public static function can_edit_parent_post( $input ) {
		$post = get_post( (int) $input['post_id'] );
		if ( ! $post || 'revision' === $post->post_type ) {
			return new WP_Error( 'exab_post_not_found', sprintf( 'Post %d does not exist.', $input['post_id'] ) );
		}
		return current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Executes exab/list-revisions.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function list_revisions( $input ) {
		$post_id   = (int) $input['post_id'];
		$revisions = wp_get_post_revisions(
			$post_id,
			array(
				'posts_per_page' => (int) ( $input['limit'] ?? 20 ),
				'check_enabled'  => false,
			)
		);

		$items = array();
		foreach ( $revisions as $revision ) {
			$author  = get_userdata( (int) $revision->post_author );
			$items[] = array(
				'revision_id'    => $revision->ID,
				'date_gmt'       => mysql_to_rfc3339( $revision->post_date_gmt ),
				'author'         => $author ? $author->display_name : '',
				'title'          => $revision->post_title,
				'is_autosave'    => (bool) wp_is_post_autosave( $revision ),
				'content_length' => strlen( $revision->post_content ),
			);
		}

		return array(
			'post_id'   => $post_id,
			'revisions' => $items,
		);
	}

	/**
	 * Permission for exab/restore-revision.
	 *
	 * @param array $input Ability input.
	 * @return bool|WP_Error
	 */
	public static function can_restore( $input ) {
		$revision_id = (int) $input['revision_id']; // wp_get_post_revision() takes its argument by reference.
		$revision    = wp_get_post_revision( $revision_id );
		if ( ! $revision ) {
			return new WP_Error( 'exab_revision_not_found', sprintf( 'Revision %d does not exist.', $input['revision_id'] ) );
		}
		return current_user_can( 'edit_post', $revision->post_parent );
	}

	/**
	 * Executes exab/restore-revision.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function restore( $input ) {
		$revision_id = (int) $input['revision_id'];
		$post_id     = wp_restore_post_revision( $revision_id );
		if ( ! $post_id || is_wp_error( $post_id ) ) {
			return is_wp_error( $post_id ) ? $post_id : new WP_Error( 'exab_restore_failed', sprintf( 'Could not restore revision %d.', $revision_id ) );
		}

		return array(
			'post_id'              => (int) $post_id,
			'restored_revision_id' => $revision_id,
			'title'                => get_the_title( $post_id ),
		);
	}

	/**
	 * Loads a page or returns a not-found error.
	 *
	 * @param int $page_id Page ID.
	 * @return WP_Post|WP_Error
	 */
	private static function page( int $page_id ) {
		$page = get_post( $page_id );
		if ( ! $page || 'page' !== $page->post_type ) {
			return new WP_Error( 'exab_page_not_found', sprintf( 'Page %d does not exist.', $page_id ) );
		}
		return $page;
	}
}
