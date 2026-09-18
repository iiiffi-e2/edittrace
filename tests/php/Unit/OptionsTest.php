<?php
declare( strict_types=1 );

namespace EditTrace\Tests\Unit;

use EditTrace\Support\Options;
use EditTrace\Tests\TestCase;

final class OptionsTest extends TestCase {

	public function test_defaults_and_sanitization(): void {
		$options = new Options();
		$this->assertTrue( $options->is_enabled() );
		$this->assertSame( array( 'administrator' ), $options->roles() );

		$clean = Options::sanitize(
			array(
				'enabled'         => '0',
				'roles'           => array( 'editor', 'bad role!!' ),
				'max_results'     => 99,
				'fallback_search' => '',
				'debug'           => '1',
			)
		);
		$this->assertFalse( $clean['enabled'] );
		$this->assertSame( array( 'editor', 'badrole', 'administrator' ), $clean['roles'] );
		$this->assertSame( 10, $clean['max_results'] );
		$this->assertFalse( $clean['fallback_search'] );
		$this->assertTrue( $clean['debug'] );
	}
}
