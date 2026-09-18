<?php
declare( strict_types=1 );

namespace EditTrace\Tests\Unit;

use EditTrace\Inspector\TraceRegistry;
use EditTrace\Inspector\TraceSession;
use EditTrace\Inspector\TransientTraceStorage;
use EditTrace\Tests\TestCase;

final class TraceRegistryTest extends TestCase {

	public function test_register_returns_opaque_ids_and_round_trips(): void {
		$registry = new TraceRegistry();
		$a        = $registry->register( 'block', array( 'name' => 'core/paragraph' ) );
		$b        = $registry->register( 'acf_field', array( 'key' => 'field_x' ) );
		$this->assertMatchesRegularExpression( '/^t[a-z0-9]+$/', (string) $a );
		$this->assertNotSame( $a, $b );
		$this->assertSame( 'core/paragraph', $registry->get( $a )['name'] );
		$this->assertSame( 1, $registry->count( 'acf_field' ) );

		$restored = TraceRegistry::from_array( $registry->to_array() );
		$this->assertSame( $registry->get( $b ), $restored->get( $b ) );
		$this->assertSame( 2, $restored->count() );
	}

	public function test_registry_is_capped(): void {
		$registry = new TraceRegistry();
		for ( $i = 0; $i < TraceRegistry::MAX_ENTRIES; $i++ ) {
			$this->assertNotNull( $registry->register( 'block', array() ) );
		}
		$this->assertNull( $registry->register( 'block', array() ) );
	}

	public function test_session_persists_and_expires_with_user_binding(): void {
		$storage = new TransientTraceStorage();
		$session = TraceSession::start( 7 );
		$session->get_registry()->register( 'block', array( 'name' => 'core/heading' ) );
		$this->assertTrue( $session->persist( $storage ) );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $session->get_token() );

		$loaded = TraceSession::load( $session->get_token(), $storage );
		$this->assertNotNull( $loaded );
		$this->assertTrue( $loaded->belongs_to( 7 ) );
		$this->assertFalse( $loaded->belongs_to( 8 ) );
		$this->assertSame( 1, $loaded->get_registry()->count( 'block' ) );

		$storage->delete( $session->get_token() );
		$this->assertNull( TraceSession::load( $session->get_token(), $storage ) );
		$this->assertNull( TraceSession::load( 'not-a-token', $storage ) );
	}

	public function test_purge_expired_removes_stale_transients(): void {
		$storage = new TransientTraceStorage();
		$token   = bin2hex( random_bytes( 16 ) );
		$storage->save( $token, array( 'x' => 1 ), 60 );
		update_option( '_transient_timeout_' . TransientTraceStorage::PREFIX . $token, time() - 10 );
		TransientTraceStorage::purge_expired();
		$this->assertFalse( get_option( '_transient_' . TransientTraceStorage::PREFIX . $token ) );
	}
}
