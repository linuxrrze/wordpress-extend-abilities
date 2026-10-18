<?php
/**
 * Plugin Name:       Extended Abilities
 * Description:       Additional MCP-ready abilities for the WordPress Abilities API. Upload local files to the media library through single-use upload slots, manage media metadata, schedule posts, update pages, restore revisions, and expose other plugins' abilities to MCP.
 * Version:           0.2.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            Marcel Ritter
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       extended-abilities
 *
 * @package Exab
 */

defined( 'ABSPATH' ) || exit;

define( 'EXAB_VERSION', '0.2.0' );
define( 'EXAB_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/src/class-slot-store.php';
require_once __DIR__ . '/src/class-schemas.php';
require_once __DIR__ . '/src/class-upload-slots.php';
require_once __DIR__ . '/src/class-media-abilities.php';
require_once __DIR__ . '/src/class-content-abilities.php';
require_once __DIR__ . '/src/class-expose-abilities.php';
require_once __DIR__ . '/src/class-plugin.php';

register_activation_hook( __FILE__, array( \Exab\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Exab\Plugin::class, 'deactivate' ) );

\Exab\Plugin::init();
