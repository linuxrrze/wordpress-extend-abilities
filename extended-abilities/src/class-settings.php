<?php
/**
 * Upload options on Settings → Extended Abilities.
 *
 * @package Exab
 */

namespace Exab;

defined( 'ABSPATH' ) || exit;

/**
 * Options: allow PDF uploads, strip GPS data.
 */
final class Settings {

	const ALLOW_PDF = 'exab_allow_pdf';
	const STRIP_GPS = 'exab_strip_gps';

	/**
	 * Registers hooks.
	 */
	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Whether PDF uploads are enabled. Default: off.
	 *
	 * @return bool
	 */
	public static function allow_pdf(): bool {
		return (bool) get_option( self::ALLOW_PDF, false );
	}

	/**
	 * Whether GPS data is removed from uploaded images. Default: on.
	 *
	 * @return bool
	 */
	public static function strip_gps(): bool {
		/**
		 * Filters whether GPS location data is removed from uploaded images.
		 *
		 * @param bool $strip Value of the setting (default true).
		 */
		return (bool) apply_filters( 'exab_strip_gps', (bool) get_option( self::STRIP_GPS, true ) );
	}

	/**
	 * Registers both options in the settings page's group.
	 */
	public static function register_settings(): void {
		foreach ( array( self::ALLOW_PDF => false, self::STRIP_GPS => true ) as $option => $default ) {
			register_setting(
				Expose_Abilities::GROUP,
				$option,
				array(
					'type'              => 'boolean',
					'default'           => $default,
					'sanitize_callback' => 'rest_sanitize_boolean',
					'show_in_rest'      => false,
				)
			);
		}
	}

	/**
	 * Renders the upload options (inside the settings page form).
	 */
	public static function render_fields(): void {
		?>
		<h2><?php esc_html_e( 'Uploads', 'extended-abilities' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Location data', 'extended-abilities' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( self::STRIP_GPS ); ?>" value="1" <?php checked( self::strip_gps() ); ?> />
						<?php esc_html_e( 'Remove GPS coordinates from uploaded JPEG, PNG and WebP images (EXIF and XMP). Other metadata, such as orientation, is kept.', 'extended-abilities' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'PDF', 'extended-abilities' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( self::ALLOW_PDF ); ?>" value="1" <?php checked( self::allow_pdf() ); ?> />
						<?php esc_html_e( 'Allow PDF uploads through MCP. Uploaded files are publicly reachable by URL.', 'extended-abilities' ); ?>
					</label>
				</td>
			</tr>
		</table>
		<?php
	}
}
