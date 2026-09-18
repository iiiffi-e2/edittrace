<?php
/**
 * Frontend inspector assets.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Admin;

use EditTrace\Plugin;
use EditTrace\REST\Controller;

/**
 * Enqueues the inspector bundle for authorized users only. Logged-out
 * visitors never receive a script, style, or configuration.
 */
final class Assets {

	public const HANDLE = 'edittrace-inspector';

	private Plugin $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 100 );
	}

	public function enqueue(): void {
		$session = $this->plugin->get_session();
		if ( null === $session ) {
			return;
		}

		$file = EDITTRACE_PATH . 'assets/build/edittrace.js';
		if ( ! is_file( $file ) ) {
			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			EDITTRACE_URL . 'assets/build/edittrace.js',
			array(),
			(string) filemtime( $file ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_add_inline_script( self::HANDLE, 'window.EditTraceConfig = ' . wp_json_encode( $this->config( $session ) ) . ';', 'before' );

		$css = EDITTRACE_PATH . 'assets/build/edittrace-adminbar.css';
		if ( is_file( $css ) ) {
			wp_enqueue_style( 'edittrace-adminbar', EDITTRACE_URL . 'assets/build/edittrace-adminbar.css', array(), (string) filemtime( $css ) );
		}
	}

	/**
	 * @param \EditTrace\Inspector\TraceSession $session Active session.
	 * @return array<string,mixed>
	 */
	private function config( $session ): array {
		$options = $this->plugin->get_options();
		$page    = $session->get_page();

		$config = array(
			'version'        => EDITTRACE_VERSION,
			'restUrl'        => esc_url_raw( rest_url( Controller::NAMESPACE ) ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'traceId'        => $session->get_token(),
			'page'           => array(
				'title'   => (string) ( $page['title'] ?? '' ),
				'editUrl' => (string) ( $page['edit_url'] ?? '' ),
			),
			'markers'        => $this->plugin->get_dom_markers(),
			'fallbackSearch' => $options->fallback_search_enabled(),
			'debug'          => $options->is_debug(),
			'adminUrl'       => esc_url_raw( admin_url() ),
			'i18n'           => array(
				'active'   => __( 'EditTrace Active', 'edittrace' ),
				'parent'   => __( 'Parent', 'edittrace' ),
				'child'    => __( 'Child', 'edittrace' ),
				'exit'     => __( 'Exit', 'edittrace' ),
				'loading'  => __( 'Tracing source…', 'edittrace' ),
				'search'   => __( 'Search WordPress', 'edittrace' ),
				'error'    => __( 'EditTrace could not reach the server.', 'edittrace' ),
			),
		);

		/**
		 * Filters the configuration passed to the inspector script.
		 *
		 * @param array<string,mixed> $config Configuration.
		 */
		return (array) apply_filters( 'edittrace/frontend_config', $config );
	}
}
