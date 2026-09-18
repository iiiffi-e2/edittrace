<?php
declare( strict_types=1 );

namespace EditTrace\Tests\Unit;

use EditTrace\Inspector\Confidence;
use EditTrace\Tests\TestCase;

final class ConfidenceTest extends TestCase {

	public function test_statuses(): void {
		$this->assertSame( 'exact', Confidence::status( 1.0 ) );
		$this->assertSame( 'high', Confidence::status( 0.99 ) );
		$this->assertSame( 'high', Confidence::status( 0.75 ) );
		$this->assertSame( 'possible', Confidence::status( 0.74 ) );
		$this->assertSame( 'possible', Confidence::status( 0.4 ) );
		$this->assertSame( 'unknown', Confidence::status( 0.39 ) );
	}

	public function test_clamp(): void {
		$this->assertSame( 1.0, Confidence::clamp( 4.2 ) );
		$this->assertSame( 0.0, Confidence::clamp( -1 ) );
		$this->assertSame( 0.56, Confidence::clamp( 0.556 ) );
	}
}
