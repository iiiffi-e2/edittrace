<?php
declare( strict_types=1 );

namespace EditTrace\Tests\Integration;

use EditTrace\Security\Access;
use EditTrace\Support\Options;
use EditTrace\Tests\TestCase;

final class AccessTest extends TestCase {

	public function test_logged_out_users_are_never_allowed_even_with_filter(): void {
		$access = new Access( new Options() );
		$this->assertFalse( $access->user_can_inspect() );

		$grant = static fn(): bool => true;
		add_filter( 'edittrace/user_can_inspect', $grant );
		try {
			$this->assertFalse( $access->user_can_inspect(), 'Filters cannot grant anonymous access.' );
		} finally {
			remove_filter( 'edittrace/user_can_inspect', $grant );
		}
	}

	public function test_admin_allowed_subscriber_denied_and_filter_can_extend(): void {
		$access = new Access( new Options() );
		$this->as_admin();
		$this->assertTrue( $access->user_can_inspect() );

		$this->as_subscriber();
		$this->assertFalse( $access->user_can_inspect() );

		$grant = static fn( bool $allowed, \WP_User $user ): bool => 'edittrace_subscriber' === $user->user_login ? true : $allowed;
		add_filter( 'edittrace/user_can_inspect', $grant, 10, 2 );
		try {
			$this->assertTrue( $access->user_can_inspect() );
		} finally {
			remove_filter( 'edittrace/user_can_inspect', $grant );
		}
	}

	public function test_disabled_setting_blocks_everyone(): void {
		update_option( Options::OPTION_NAME, array( 'enabled' => false ) );
		$options = new Options();
		$access  = new Access( $options );
		$this->as_admin();
		$this->assertFalse( $access->user_can_inspect() );
	}

	public function test_admin_without_switch_gets_no_session_and_switch_turns_it_on(): void {
		$plugin  = edittrace();
		$user_id = $this->as_admin();
		\EditTrace\Support\UserPreferences::set_tracing_enabled( $user_id, false );
		$plugin->maybe_start_session();
		$this->assertNull( $plugin->get_session(), 'Tracing is opt-in per user.' );

		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		$bar = new \WP_Admin_Bar();
		( new \EditTrace\Admin\AdminBar( $plugin ) )->add_node( $bar );
		$node = $bar->get_node( 'edittrace' );
		$this->assertNotNull( $node, 'The toolbar still offers a way to turn EditTrace on.' );
		$this->assertStringContainsString( 'admin-post.php', $node->href );
		$this->assertStringContainsString( 'state=on', $node->href );
		$this->assertStringContainsString( '_wpnonce=', $node->href );

		\EditTrace\Support\UserPreferences::set_tracing_enabled( $user_id, true );
		try {
			$plugin->maybe_start_session();
			$this->assertNotNull( $plugin->get_session() );
			$bar = new \WP_Admin_Bar();
			( new \EditTrace\Admin\AdminBar( $plugin ) )->add_node( $bar );
			$this->assertSame( '#edittrace', $bar->get_node( 'edittrace' )->href );
			$this->assertStringContainsString( 'state=off', $bar->get_node( 'edittrace-off' )->href );
		} finally {
			\EditTrace\Support\UserPreferences::set_tracing_enabled( $user_id, false );
			remove_action( 'shutdown', array( $plugin, 'persist_session' ), 0 );
		}
	}

	public function test_no_session_or_assets_for_anonymous_frontend_request(): void {
		$plugin = edittrace();
		$plugin->maybe_start_session();
		$this->assertNull( $plugin->get_session() );

		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		$bar = new \WP_Admin_Bar();
		( new \EditTrace\Admin\AdminBar( $plugin ) )->add_node( $bar );
		$this->assertNull( $bar->get_node( 'edittrace' ) );

		( new \EditTrace\Admin\Assets( $plugin ) )->enqueue();
		$this->assertFalse( wp_script_is( \EditTrace\Admin\Assets::HANDLE, 'enqueued' ) );
	}
}
