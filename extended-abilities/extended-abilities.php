<?php
/**
 * Plugin Name:       Extended Abilities
 * Description:       Additional MCP-ready abilities for the WordPress Abilities API. Milestone 1: upload local files to the media library through single-use upload slots.
 * Version:           0.1.0
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

define( 'EXAB_VERSION', '0.1.0' );
define( 'EXAB_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/src/class-slot-store.php';
require_once __DIR__ . '/src/class-schemas.php';
require_once __DIR__ . '/src/class-upload-slots.php';
require_once __DIR__ . '/src/class-plugin.php';

register_activation_hook( __FILE__, array( \Exab\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Exab\Plugin::class, 'deactivate' ) );

\Exab\Plugin::init();
