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
	'to_schedule' => wp_insert_post(
		array(
			'post_title'  => 'Draft to schedule',
			'post_author' => $users['author'],
			'post_status' => 'draft',
		)
	),
	'published_by_author' => wp_insert_post(
		array(
			'post_title'  => 'Already published',
			'post_author' => $users['author'],
			'post_status' => 'publish',
		)
	),
	'parent_page' => wp_insert_post(
		array(
			'post_title'  => 'Parent page',
			'post_type'   => 'page',
			'post_status' => 'publish',
		)
	),
);
$creds['posts']['child_page'] = wp_insert_post(
	array(
		'post_title'  => 'Child page',
		'post_type'   => 'page',
		'post_status' => 'draft',
		'post_parent' => $creds['posts']['parent_page'],
	)
);

// A post with revisions. WordPress saves a revision on each update (not on insert),
// so v1 → v2 → v3 → v4 yields revisions v2, v3, v4.
wp_set_current_user( $users['author'] );
$creds['posts']['with_revisions'] = wp_insert_post(
	array(
		'post_title'   => 'Revisions v1',
		'post_content' => 'content version 1',
		'post_author'  => $users['author'],
		'post_status'  => 'draft',
	)
);
foreach ( array( 2, 3, 4 ) as $version ) {
	wp_update_post(
		array(
			'ID'           => $creds['posts']['with_revisions'],
			'post_title'   => "Revisions v$version",
			'post_content' => "content version $version",
		)
	);
}
wp_set_current_user( 0 );

// Makes "site time zone" tests meaningful (UTC would hide offset bugs).
update_option( 'timezone_string', 'Europe/Berlin' );
$creds['max_upload_bytes'] = wp_max_upload_size();

// Real images from GD; the tests add EXIF/XMP GPS data to them.
$fixtures = '/e2e-out/fixtures';
wp_mkdir_p( $fixtures );
$draw = function ( int $w, int $h ) {
	$img = imagecreatetruecolor( $w, $h );
	for ( $x = 0; $x < $w; $x += 8 ) {
		imagefilledrectangle( $img, $x, 0, $x + 7, $h - 1, imagecolorallocate( $img, ( $x * 3 ) % 256, ( $x * 7 ) % 256, 128 ) );
	}
	return $img;
};
imagejpeg( $draw( 640, 480 ), "$fixtures/plain.jpg", 85 );
imagejpeg( $draw( 160, 120 ), "$fixtures/small.jpg", 80 );
imagewebp( $draw( 96, 64 ), "$fixtures/plain.webp", 80 );
$creds['fixtures'] = array(
	'jpeg'       => 'plain.jpg',
	'small_jpeg' => 'small.jpg',
	'webp'       => 'plain.webp',
	'webp_size'  => array( 96, 64 ),
);

file_put_contents( '/e2e-out/creds.json', wp_json_encode( $creds, JSON_PRETTY_PRINT ) );
