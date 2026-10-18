<?php
/**
 * Test fixtures, run once by the blueprint: users, posts, application passwords.
 * Writes the credentials to /e2e-out/creds.json for the test runner.
 *
 * @package Exab
 */

require_once '/wordpress/wp-load.php';

$users = array(
	'admin'      => 1,
	'author'     => wp_create_user( 'e2e-author', wp_generate_password(), 'author@example.test' ),
	'author2'    => wp_create_user( 'e2e-author2', wp_generate_password(), 'author2@example.test' ),
	'subscriber' => wp_create_user( 'e2e-subscriber', wp_generate_password(), 'subscriber@example.test' ),
);
( new WP_User( $users['author'] ) )->set_role( 'author' );
( new WP_User( $users['author2'] ) )->set_role( 'author' );
( new WP_User( $users['subscriber'] ) )->set_role( 'subscriber' );

$creds = array( 'users' => array() );
foreach ( $users as $key => $user_id ) {
	list( $password ) = WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => 'e2e' ) );
	$creds['users'][ $key ] = array(
		'id'       => $user_id,
		'login'    => get_userdata( $user_id )->user_login,
		'password' => $password,
	);
}

$creds['posts'] = array(
	'by_author' => wp_insert_post(
		array(
			'post_title'  => 'Post by author',
			'post_author' => $users['author'],
			'post_status' => 'draft',
		)
	),
	'by_admin'  => wp_insert_post(
		array(
			'post_title'  => 'Post by admin',
			'post_author' => 1,
			'post_status' => 'publish',
		)
	),
);
$creds['max_upload_bytes'] = wp_max_upload_size();

file_put_contents( '/e2e-out/creds.json', wp_json_encode( $creds, JSON_PRETTY_PRINT ) );
