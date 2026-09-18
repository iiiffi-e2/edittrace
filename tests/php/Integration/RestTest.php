<?php
declare( strict_types=1 );

namespace EditTrace\Tests\Integration;

use EditTrace\Tests\TestCase;
use WP_REST_Request;

final class RestTest extends TestCase {

	private function request( string $route, array $body, ?string $nonce = null ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/edittrace/v1/' . $route );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( null !== $nonce ) {
			$request->set_header( 'X-WP-Nonce', $nonce );
		}
		$request->set_body( (string) wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	public function test_anonymous_requests_are_rejected(): void {
		$response = $this->request( 'inspect', array( 'element' => array( 'tag' => 'a', 'text' => 'x' ) ) );
		$this->assertSame( 401, $response->get_status() );
		$response = $this->request( 'search', array( 'element' => array( 'tag' => 'a', 'text' => 'x' ) ) );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_logged_in_without_nonce_or_without_role_is_rejected(): void {
		$this->as_admin();
		$response = $this->request( 'inspect', array( 'element' => array( 'tag' => 'a' ) ) );
		$this->assertSame( 403, $response->get_status() );

		$this->as_subscriber();
		$response = $this->request( 'inspect', array( 'element' => array( 'tag' => 'a' ) ), wp_create_nonce( 'wp_rest' ) );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_admin_inspect_returns_result_and_rejects_foreign_trace(): void {
		$this->as_admin();
		$response = $this->request( 'inspect', array( 'element' => array( 'tag' => 'a', 'text' => 'Welcome to EditTrace' ) ), wp_create_nonce( 'wp_rest' ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertContains( $data['status'], array( 'exact', 'candidates', 'unknown' ) );
		$this->assertFalse( $data['trace']['available'] );

		// A trace created by another user is not usable.
		$other = \EditTrace\Inspector\TraceSession::start( 999999 );
		$other->persist( edittrace()->get_storage() );
		$response = $this->request( 'inspect', array( 'traceId' => $other->get_token(), 'element' => array( 'tag' => 'a', 'text' => 'x' ) ), wp_create_nonce( 'wp_rest' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['trace']['available'] );
		$this->assertNotEmpty( $response->get_data()['notes'] );
	}

	public function test_invalid_trace_id_is_rejected_by_schema(): void {
		$this->as_admin();
		$response = $this->request( 'inspect', array( 'traceId' => 'DROP TABLE', 'element' => array( 'tag' => 'a' ) ), wp_create_nonce( 'wp_rest' ) );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_search_respects_setting(): void {
		$this->as_admin();
		update_option( \EditTrace\Support\Options::OPTION_NAME, array( 'fallback_search' => false ) );
		edittrace()->get_options()->flush();
		$response = $this->request( 'search', array( 'element' => array( 'tag' => 'p', 'text' => 'Footer disclaimer stored in a plain option' ) ), wp_create_nonce( 'wp_rest' ) );
		$this->assertSame( 403, $response->get_status() );

		delete_option( \EditTrace\Support\Options::OPTION_NAME );
		edittrace()->get_options()->flush();
		$response = $this->request( 'search', array( 'element' => array( 'tag' => 'p', 'text' => 'Footer disclaimer stored in a plain option' ) ), wp_create_nonce( 'wp_rest' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'candidates', $response->get_data()['status'] );
		$this->assertSame( 'edittrace_test_footer_text', $response->get_data()['primary']['sourceId'] );
	}
}
