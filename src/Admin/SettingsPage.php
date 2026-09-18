<?php
/**
 * Settings → EditTrace.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Admin;

use EditTrace\Support\Options;

/**
 * Minimal Settings API page.
 */
final class SettingsPage {

	public const SLUG = 'edittrace';

	private Options $options;

	public function __construct( Options $options ) {
		$this->options = $options;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( EDITTRACE_FILE ), array( $this, 'action_links' ) );
	}

	public function add_menu(): void {
		add_options_page( 'EditTrace', 'EditTrace', 'manage_options', self::SLUG, array( $this, 'render' ) );
	}

	/**
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Settings', 'edittrace' ) . '</a>' );
		return $links;
	}

	public function register_settings(): void {
		register_setting(
			'edittrace',
			Options::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Options::class, 'sanitize' ),
				'default'           => Options::defaults(),
			)
		);
		add_action( 'update_option_' . Options::OPTION_NAME, array( $this->options, 'flush' ) );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = $this->options->all();
		$roles    = wp_roles()->get_names();
		?>
		<div class="wrap">
			<h1>EditTrace</h1>
			<p><?php esc_html_e( 'Click anything on your WordPress site. See exactly where to edit it. Open any frontend page and use the "EditTrace" toolbar button.', 'edittrace' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'edittrace' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable EditTrace', 'edittrace' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( Options::OPTION_NAME ); ?>[enabled]" value="1" <?php checked( $settings['enabled'] ); ?>> <?php esc_html_e( 'Show the inspector to allowed users on the frontend', 'edittrace' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Allowed roles', 'edittrace' ); ?></th>
						<td>
							<?php foreach ( $roles as $slug => $name ) : ?>
								<label style="display:block;margin-bottom:4px;">
									<input type="checkbox" name="<?php echo esc_attr( Options::OPTION_NAME ); ?>[roles][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $settings['roles'], true ) ); ?> <?php disabled( 'administrator' === $slug ); ?>>
									<?php echo esc_html( translate_user_role( $name ) ); ?>
								</label>
							<?php endforeach; ?>
							<input type="hidden" name="<?php echo esc_attr( Options::OPTION_NAME ); ?>[roles][]" value="administrator">
							<p class="description"><?php esc_html_e( 'Administrators always have access. Users also need the "edit_posts" capability.', 'edittrace' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Fallback search', 'edittrace' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( Options::OPTION_NAME ); ?>[fallback_search]" value="1" <?php checked( $settings['fallback_search'] ); ?>> <?php esc_html_e( 'Search post content, custom fields and options when no exact source is found', 'edittrace' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="edittrace-max-results"><?php esc_html_e( 'Maximum search results', 'edittrace' ); ?></label></th>
						<td><input id="edittrace-max-results" type="number" min="1" max="10" name="<?php echo esc_attr( Options::OPTION_NAME ); ?>[max_results]" value="<?php echo esc_attr( (string) $settings['max_results'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Developer debug mode', 'edittrace' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( Options::OPTION_NAME ); ?>[debug]" value="1" <?php checked( $settings['debug'] ); ?>> <?php esc_html_e( 'Show full technical details (trace ids, provider scores) in the inspector', 'edittrace' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
