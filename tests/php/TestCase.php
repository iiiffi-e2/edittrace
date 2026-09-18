<?php
/**
 * Base test case with WordPress helpers.
 */

declare( strict_types=1 );

namespace EditTrace\Tests;

use EditTrace\Inspector\ElementContext;
use EditTrace\Inspector\TraceContext;
use EditTrace\Inspector\TraceSession;
use EditTrace\Support\Options;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase {

	/** @var array<string,int> */
	protected static array $ids = array();

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$ids       = get_option( 'edittrace_test_ids', array() );
		self::$ids = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	protected function setUp(): void {
		parent::setUp();
		wp_set_current_user( 0 );
		edittrace()->set_session( null );
		edittrace()->get_options()->flush();
		delete_option( Options::OPTION_NAME );
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );
		edittrace()->set_session( null );
		edittrace()->set_providers( null );
		parent::tearDown();
	}

	protected function as_admin(): int {
		$admin = get_user_by( 'login', 'admin' );
		$this->assertNotFalse( $admin, 'The test site must have an "admin" user.' );
		wp_set_current_user( (int) $admin->ID );
		return (int) $admin->ID;
	}

	protected function as_subscriber(): int {
		$user = get_user_by( 'login', 'edittrace_subscriber' );
		if ( ! $user ) {
			$id = wp_insert_user(
				array(
					'user_login' => 'edittrace_subscriber',
					'user_pass'  => wp_generate_password(),
					'user_email' => 'subscriber@example.com',
					'role'       => 'subscriber',
				)
			);
			$this->assertIsInt( $id );
			$user = get_user_by( 'id', $id );
		}
		wp_set_current_user( (int) $user->ID );
		return (int) $user->ID;
	}

	protected function id( string $key ): int {
		$this->assertArrayHasKey( $key, self::$ids, "Fixture id {$key} missing; run tests/env/setup.sh." );
		return self::$ids[ $key ];
	}

	/**
	 * Starts a trace session for the admin and returns it.
	 */
	protected function start_session(): TraceSession {
		$user_id = $this->as_admin();
		$session = TraceSession::start( $user_id );
		edittrace()->set_session( $session );
		return $session;
	}

	/**
	 * @param array<string,mixed> $element Element context data.
	 */
	protected function element( array $element ): ElementContext {
		return ElementContext::from_array( $element );
	}

	protected function trace( ?TraceSession $session ): TraceContext {
		return new TraceContext( $session, edittrace()->get_options() );
	}

	/**
	 * Renders block markup the way the frontend does, with the given session's
	 * instrumentation hooked, and returns the HTML.
	 */
	protected function render_blocks_as_post( TraceSession $session, int $post_id, string $content ): string {
		global $post;
		$post = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		setup_postdata( $post );
		$instrumenter = new \EditTrace\Integrations\Gutenberg\BlockInstrumenter( $session );
		$instrumenter->register_hooks();
		try {
			$html = apply_filters( 'the_content', $content );
		} finally {
			remove_filter( 'render_block_data', array( $instrumenter, 'on_render_block_data' ), PHP_INT_MAX );
			remove_filter( 'render_block', array( $instrumenter, 'on_render_block' ), PHP_INT_MAX );
			wp_reset_postdata();
		}
		return (string) $html;
	}

	/**
	 * Extracts data-edittrace-id values from HTML in document order.
	 *
	 * @return array<int,array{id:string,tag:string,html:string}>
	 */
	protected function stamped( string $html ): array {
		preg_match_all( '/<([a-z0-9]+)[^>]*\sdata-edittrace-id="([^"]+)"[^>]*>/i', $html, $m, PREG_SET_ORDER );
		$out = array();
		foreach ( $m as $match ) {
			$out[] = array(
				'id'   => $match[2],
				'tag'  => strtolower( $match[1] ),
				'html' => $match[0],
			);
		}
		return $out;
	}
}
