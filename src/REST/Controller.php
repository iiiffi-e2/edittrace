<?php
/**
 * REST API.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\REST;

use EditTrace\Inspector\ElementContext;
use EditTrace\Inspector\TraceContext;
use EditTrace\Inspector\TraceSession;
use EditTrace\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * edittrace/v1 namespace: POST /inspect and POST /search.
 *
 * Authentication relies on WordPress' cookie + X-WP-Nonce REST auth; the
 * permission callback then applies EditTrace's own capability rules.
 */
final class Controller {

	public const NAMESPACE = 'edittrace/v1';

	private Plugin $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/inspect',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'inspect' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => $this->args(),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/search',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'search' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => $this->args(),
			)
		);
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function args(): array {
		return array(
			'traceId' => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => static fn( $v ): string => is_scalar( $v ) ? strtolower( (string) $v ) : '',
				'validate_callback' => static fn( $v ): bool => ! is_scalar( $v ) || '' === $v || 1 === preg_match( '/^[a-f0-9]{32}$/i', (string) $v ),
			),
			'element' => array(
				'type'     => 'object',
				'required' => true,
			),
		);
	}

	/**
	 * @return bool|WP_Error
	 */
	public function permission( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'edittrace_unauthenticated', __( 'You must be logged in.', 'edittrace' ), array( 'status' => 401 ) );
		}
		// Cookie-authenticated REST requests must carry a valid nonce, otherwise
		// WordPress treats them as logged-out; double-check explicitly.
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'edittrace_bad_nonce', __( 'Invalid security token. Reload the page and try again.', 'edittrace' ), array( 'status' => 403 ) );
		}
		if ( ! $this->plugin->get_access()->user_can_inspect() ) {
			return new WP_Error( 'edittrace_forbidden', __( 'You are not allowed to use EditTrace.', 'edittrace' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public function inspect( WP_REST_Request $request ): WP_REST_Response {
		[ $element, $trace ] = $this->prepare( $request );
		$result              = $this->plugin->get_resolver()->resolve( $element, $trace );
		return new WP_REST_Response( $result->to_array(), 200 );
	}

	public function search( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->plugin->get_options()->fallback_search_enabled() ) {
			return new WP_REST_Response(
				array(
					'code'    => 'edittrace_search_disabled',
					'message' => __( 'Fallback search is disabled in EditTrace settings.', 'edittrace' ),
				),
				403
			);
		}
		[ $element, $trace ] = $this->prepare( $request );
		$result              = $this->plugin->get_resolver()->search( $element, $trace );
		return new WP_REST_Response( $result->to_array(), 200 );
	}

	/**
	 * @return array{0:ElementContext,1:TraceContext}
	 */
	private function prepare( WP_REST_Request $request ): array {
		$body    = $request->get_json_params();
		$element = isset( $body['element'] ) && is_array( $body['element'] ) ? $body['element'] : array();
		$token   = (string) ( $request->get_param( 'traceId' ) ?? ( $element['traceId'] ?? '' ) );

		$element['traceId'] = $token;
		$context            = ElementContext::from_array( $element );

		$session = null;
		if ( '' !== $context->trace_id ) {
			$session = TraceSession::load( $context->trace_id, $this->plugin->get_storage() );
			if ( $session && ! $session->belongs_to( get_current_user_id() ) ) {
				$session = null;
			}
		}

		$trace = new TraceContext( $session, $this->plugin->get_options() );
		if ( null === $session ) {
			$trace->note(
				'' === $context->trace_id
					? __( 'No trace token was sent; only DOM evidence is available.', 'edittrace' )
					: __( 'The page trace has expired. Reload the page for exact results.', 'edittrace' )
			);
		}
		return array( $context, $trace );
	}
}
