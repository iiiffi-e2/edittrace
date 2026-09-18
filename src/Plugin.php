<?php
/**
 * Plugin wiring.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace;

use EditTrace\Admin\AdminBar;
use EditTrace\Admin\Assets;
use EditTrace\Admin\SettingsPage;
use EditTrace\Inspector\SourceResolver;
use EditTrace\Inspector\TraceSession;
use EditTrace\Inspector\TraceStorage;
use EditTrace\Inspector\TransientTraceStorage;
use EditTrace\Providers\ACFProvider;
use EditTrace\Providers\DatabaseFallbackProvider;
use EditTrace\Providers\ElementorProvider;
use EditTrace\Providers\GutenbergProvider;
use EditTrace\Providers\MediaProvider;
use EditTrace\Providers\NavigationProvider;
use EditTrace\Providers\QueriedObjectProvider;
use EditTrace\Providers\SourceProviderInterface;
use EditTrace\REST\Controller;
use EditTrace\Security\Access;
use EditTrace\Support\Options;

/**
 * Composition root. Holds the few long-lived services and hooks them up.
 */
final class Plugin {

	public const CLEANUP_HOOK = 'edittrace_cleanup_traces';

	private static ?Plugin $instance = null;

	private Options $options;

	private Access $access;

	private TraceStorage $storage;

	private ?TraceSession $session = null;

	/** @var SourceProviderInterface[]|null */
	private ?array $providers = null;

	private bool $booted = false;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->options = new Options();
		$this->access  = new Access( $this->options );
		$this->storage = new TransientTraceStorage();
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'template_redirect', array( $this, 'maybe_start_session' ), 1 );
		add_action( self::CLEANUP_HOOK, array( TransientTraceStorage::class, 'purge_expired' ) );

		( new SettingsPage( $this->options ) )->register();
		( new AdminBar( $this ) )->register();
		( new Assets( $this ) )->register();
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'edittrace', false, dirname( plugin_basename( EDITTRACE_FILE ) ) . '/languages' );
	}

	public function register_rest_routes(): void {
		( new Controller( $this ) )->register_routes();
	}

	/**
	 * Starts a trace session for authorized users on frontend page loads.
	 * Nothing here runs for anonymous visitors.
	 */
	public function maybe_start_session(): void {
		if ( null !== $this->session ) {
			return;
		}
		if ( ! $this->is_frontend_request() || ! $this->access->user_can_inspect() ) {
			return;
		}
		$this->session = TraceSession::start( get_current_user_id() );

		foreach ( $this->get_providers() as $provider ) {
			$provider->register_render_hooks( $this->session );
		}

		/**
		 * Fires once a trace session has started for an authorized user.
		 *
		 * @param TraceSession $session The session.
		 */
		do_action( 'edittrace/session_started', $this->session );

		add_action( 'shutdown', array( $this, 'persist_session' ), 0 );
	}

	/**
	 * Saves the registry so the REST API can answer for this page.
	 */
	public function persist_session(): void {
		if ( null === $this->session ) {
			return;
		}
		global $_wp_current_template_id;
		if ( ! empty( $_wp_current_template_id ) ) {
			$this->session->add_page_context( array( 'template' => (string) $_wp_current_template_id ) );
		}
		$this->session->persist( $this->storage );
	}

	private function is_frontend_request(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || wp_is_json_request() || is_feed() || is_embed() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return false;
		}
		if ( isset( $_GET['elementor-preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return false;
		}
		return true;
	}

	/**
	 * @return SourceProviderInterface[]
	 */
	public function get_providers(): array {
		if ( null !== $this->providers ) {
			return $this->providers;
		}
		$providers = array(
			new GutenbergProvider(),
			new ElementorProvider(),
			new ACFProvider(),
			new NavigationProvider(),
			new MediaProvider(),
			new QueriedObjectProvider(),
			new DatabaseFallbackProvider( $this->options ),
		);

		/**
		 * Filters the registered source providers. Add a provider instance
		 * implementing SourceProviderInterface to support another builder.
		 *
		 * @param SourceProviderInterface[] $providers Providers.
		 */
		$providers = (array) apply_filters( 'edittrace/providers', $providers );

		$this->providers = array_values(
			array_filter( $providers, static fn( $p ): bool => $p instanceof SourceProviderInterface )
		);
		return $this->providers;
	}

	public function get_resolver(): SourceResolver {
		return new SourceResolver( $this->get_providers() );
	}

	public function get_options(): Options {
		return $this->options;
	}

	public function get_access(): Access {
		return $this->access;
	}

	public function get_storage(): TraceStorage {
		return $this->storage;
	}

	public function get_session(): ?TraceSession {
		return $this->session;
	}

	/**
	 * Aggregated DOM markers from all providers for the inspector script.
	 *
	 * @return array<int,array<string,string>>
	 */
	public function get_dom_markers(): array {
		$markers = array();
		foreach ( $this->get_providers() as $provider ) {
			foreach ( $provider->get_dom_markers() as $marker ) {
				$markers[] = $marker;
			}
		}
		return $markers;
	}

	public static function activate(): void {
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CLEANUP_HOOK );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );
	}

	/**
	 * Testing aid: replaces the resolved provider list.
	 *
	 * @param SourceProviderInterface[]|null $providers Providers or null to reset.
	 */
	public function set_providers( ?array $providers ): void {
		$this->providers = $providers;
	}

	/**
	 * Testing aid: replaces the active session.
	 */
	public function set_session( ?TraceSession $session ): void {
		$this->session = $session;
	}
}
