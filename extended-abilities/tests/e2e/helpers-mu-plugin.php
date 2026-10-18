<?php
/**
 * Test-only helpers, installed as an mu-plugin in the Playground test site. Never ship this.
 *
 * @package Exab
 */

// Two "third-party" abilities that are NOT MCP-public, for the expose setting.
add_action(
	'wp_abilities_api_categories_init',
	function () {
		wp_register_ability_category(
			'exab-e2e',
			array(
				'label'       => 'E2E',
				'description' => 'Test abilities.',
			)
		);
	}
);
add_action(
	'wp_abilities_api_init',
	function () {
		wp_register_ability(
			'exab-e2e/hidden-echo',
			array(
				'label'               => 'Hidden Echo',
				'description'         => 'Returns its input. Not MCP-public by itself.',
				'category'            => 'exab-e2e',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'text' => array( 'type' => 'string' ) ),
				),
				'permission_callback' => fn() => current_user_can( 'read' ),
				'execute_callback'    => fn( $input ) => array( 'echo' => $input['text'] ?? '' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'readonly' => true ),
				),
			)
		);
		wp_register_ability(
			'exab-e2e/hidden-wipe',
			array(
				'label'               => 'Hidden Wipe',
				'description'         => 'Pretends to delete things. Admins only.',
				'category'            => 'exab-e2e',
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'execute_callback'    => fn() => array( 'wiped' => false ),
				'meta'                => array( 'annotations' => array( 'destructive' => true ) ),
			)
		);
	}
);

// Small chunks, so the ~200 KB fixture is sent in several pieces.
add_filter( 'exab_chunk_max_bytes', fn() => 100000 );

add_action(
	'rest_api_init',
	function () {
		$admin_only = fn() => current_user_can( 'manage_options' );

		// Sets one of the plugin's own options (exab_*).
		register_rest_route(
			'exab-e2e/v1',
			'/option',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => function ( WP_REST_Request $request ) {
					$name = (string) $request['name'];
					if ( ! str_starts_with( $name, 'exab_' ) ) {
						return new WP_Error( 'bad_option', 'only exab_* options', array( 'status' => 400 ) );
					}
					update_option( $name, $request['value'] );
					return array( $name => get_option( $name ) );
				},
			)
		);

		// Runs the GPS scrubber on arbitrary bytes (formats and malformed input without a real upload).
		register_rest_route(
			'exab-e2e/v1',
			'/scrub',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => function ( WP_REST_Request $request ) {
					$data    = base64_decode( (string) $request['data_base64'] );
					$removed = \Exab\Gps_Scrubber::scrub( $data, (string) $request['mime'] );
					return array(
						'removed'     => $removed,
						'data_base64' => base64_encode( $data ),
					);
				},
			)
		);

		// Lists partial chunk files, to check that they are cleaned up.
		register_rest_route(
			'exab-e2e/v1',
			'/chunk-files',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin_only,
				'callback'            => function () {
					$files = glob( \Exab\Upload_Slots::chunk_dir() . '/*.part' );
					return array( 'files' => array_map( 'basename', $files ? $files : array() ) );
				},
			)
		);

		// Lets the tests expire a slot without waiting for the TTL.
		register_rest_route(
			'exab-e2e/v1',
			'/expire/(?P<slot_id>[a-f0-9]{32})',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => function ( WP_REST_Request $request ) {
					$slot = \Exab\Slot_Store::get( $request['slot_id'] );
					if ( ! $slot ) {
						return new WP_Error( 'not_found', 'no slot', array( 'status' => 404 ) );
					}
					\Exab\Slot_Store::transition( $request['slot_id'], $slot, array( 'expires_at' => time() - 1 ) );
					return array( 'expired' => true );
				},
			)
		);

		// Sets the expose option the way options.php would (through the registered sanitizer).
		register_rest_route(
			'exab-e2e/v1',
			'/expose',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => function ( WP_REST_Request $request ) {
					update_option( \Exab\Expose_Abilities::OPTION, \Exab\Expose_Abilities::sanitize( $request['names'] ) );
					return array( 'exposed' => \Exab\Expose_Abilities::exposed() );
				},
			)
		);

		// Renders the settings page so notices in the template show up in the debug log.
		register_rest_route(
			'exab-e2e/v1',
			'/render-settings',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin_only,
				'callback'            => function () {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
					require_once ABSPATH . 'wp-admin/includes/template.php';
					ob_start();
					\Exab\Expose_Abilities::render_page();
					return array( 'html' => ob_get_clean() );
				},
			)
		);
	}
);
