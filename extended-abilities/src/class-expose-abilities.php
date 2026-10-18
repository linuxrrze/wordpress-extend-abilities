<?php
/**
 * Settings → Extended Abilities: expose other plugins' abilities to MCP.
 *
 * Many plugins register abilities without `meta.mcp.public`, so the MCP Adapter hides them.
 * The admin picks abilities here; a wp_register_ability_args filter then marks them public
 * while they are registered. Each ability's own permission_callback still applies.
 *
 * @package Exab
 */

namespace Exab;

use WP_Ability;

defined( 'ABSPATH' ) || exit;

/**
 * Settings page and registration filter.
 */
final class Expose_Abilities {

	const OPTION = 'exab_expose_abilities';
	const GROUP  = 'exab_expose';
	const PAGE   = 'extended-abilities';

	/**
	 * Registers hooks.
	 */
	public static function init(): void {
		add_filter( 'wp_register_ability_args', array( __CLASS__, 'maybe_expose' ), 10, 2 );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
	}

	/**
	 * Marks selected abilities as MCP-public while they are registered.
	 *
	 * @param array  $args Ability arguments.
	 * @param string $name Ability name.
	 * @return array
	 */
	public static function maybe_expose( $args, $name ) {
		if ( in_array( $name, self::exposed(), true ) ) {
			$args['meta']                  = isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : array();
			$args['meta']['mcp']           = isset( $args['meta']['mcp'] ) && is_array( $args['meta']['mcp'] ) ? $args['meta']['mcp'] : array();
			$args['meta']['mcp']['public'] = true;
		}
		return $args;
	}

	/**
	 * Names of the abilities the admin chose to expose.
	 *
	 * @return string[]
	 */
	public static function exposed(): array {
		return self::sanitize( get_option( self::OPTION, array() ) );
	}

	/**
	 * Keeps only well-formed ability names.
	 *
	 * @param mixed $value Raw option value.
	 * @return string[]
	 */
	public static function sanitize( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$names = array_filter(
			array_map( 'strval', $value ),
			static fn( $name ) => (bool) preg_match( '#^[a-z0-9-]+(/[a-z0-9-]+)+$#', $name )
		);
		return array_values( array_unique( $names ) );
	}

	/**
	 * Registers the option for options.php.
	 */
	public static function register_setting(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Adds Settings → Extended Abilities.
	 */
	public static function add_page(): void {
		add_options_page(
			__( 'Extended Abilities', 'extended-abilities' ),
			__( 'Extended Abilities', 'extended-abilities' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Abilities that are not MCP-public on their own, plus those exposed here.
	 * Read-only abilities first, destructive last.
	 *
	 * @return WP_Ability[]
	 */
	public static function candidates(): array {
		$exposed    = self::exposed();
		$candidates = array();

		foreach ( wp_get_abilities() as $ability ) {
			$meta      = $ability->get_meta();
			$is_public = ! empty( $meta['mcp']['public'] );
			if ( ! $is_public || in_array( $ability->get_name(), $exposed, true ) ) {
				$candidates[] = $ability;
			}
		}

		$rank = static function ( WP_Ability $ability ): int {
			$annotations = $ability->get_meta()['annotations'] ?? array();
			if ( ! empty( $annotations['readonly'] ) ) {
				return 0;
			}
			return empty( $annotations['destructive'] ) ? 1 : 2;
		};
		usort(
			$candidates,
			static fn( $a, $b ) => array( $rank( $a ), $a->get_name() ) <=> array( $rank( $b ), $b->get_name() )
		);

		return $candidates;
	}

	/**
	 * Renders the settings page.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$exposed    = self::exposed();
		$candidates = self::candidates();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Extended Abilities', 'extended-abilities' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<?php Settings::render_fields(); ?>
				<h2><?php esc_html_e( 'Expose abilities to MCP', 'extended-abilities' ); ?></h2>
				<p>
					<?php esc_html_e( 'Abilities registered by other plugins that the MCP Adapter does not show, because they are not marked as MCP-public. Checked abilities become visible to MCP clients. Each ability still checks the calling user\'s permissions.', 'extended-abilities' ); ?>
				</p>
				<?php if ( ! $candidates ) : ?>
					<p><em><?php esc_html_e( 'Every registered ability is already MCP-public.', 'extended-abilities' ); ?></em></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th scope="col" style="width:4em"><?php esc_html_e( 'Expose', 'extended-abilities' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Ability', 'extended-abilities' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Type', 'extended-abilities' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Description', 'extended-abilities' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $candidates as $ability ) : ?>
							<?php
							$name        = $ability->get_name();
							$annotations = $ability->get_meta()['annotations'] ?? array();
							$field_id    = 'exab-expose-' . sanitize_html_class( str_replace( '/', '--', $name ) );
							if ( ! empty( $annotations['readonly'] ) ) {
								$type = '<span>' . esc_html__( 'read-only', 'extended-abilities' ) . '</span>';
							} elseif ( ! empty( $annotations['destructive'] ) ) {
								$type = '<strong style="color:#b32d2e">' . esc_html__( 'destructive', 'extended-abilities' ) . '</strong>';
							} else {
								$type = '<span>' . esc_html__( 'writes', 'extended-abilities' ) . '</span>';
							}
							?>
							<tr>
								<td>
									<input type="checkbox" id="<?php echo esc_attr( $field_id ); ?>"
										name="<?php echo esc_attr( self::OPTION ); ?>[]"
										value="<?php echo esc_attr( $name ); ?>"
										<?php checked( in_array( $name, $exposed, true ) ); ?> />
								</td>
								<td>
									<label for="<?php echo esc_attr( $field_id ); ?>">
										<code><?php echo esc_html( $name ); ?></code><br />
										<?php echo esc_html( $ability->get_label() ); ?>
									</label>
								</td>
								<td><?php echo wp_kses_post( $type ); ?></td>
								<td><?php echo esc_html( $ability->get_description() ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
