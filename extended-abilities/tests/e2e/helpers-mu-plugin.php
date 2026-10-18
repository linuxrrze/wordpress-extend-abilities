<?php
/**
 * Test-only helpers, installed as an mu-plugin in the Playground test site. Never ship this.
 *
 * @package Exab
 */

add_action(
	'rest_api_init',
	function () {
		// Lets the tests expire a slot without waiting for the TTL.
		register_rest_route(
			'exab-e2e/v1',
			'/expire/(?P<slot_id>[a-f0-9]{32})',
			array(
				'methods'             => 'POST',
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
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
	}
);
