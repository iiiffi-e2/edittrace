<?php
declare( strict_types=1 );

namespace EditTrace\Tests\Unit;

use EditTrace\Support\Url;
use EditTrace\Tests\TestCase;

final class UrlTest extends TestCase {

	public function test_normalize_is_idempotent_and_scheme_insensitive(): void {
		$a = Url::normalize( 'https://www.Example.com/services/?x=1#top' );
		$this->assertSame( 'example.com/services?x=1', $a );
		$this->assertSame( $a, Url::normalize( $a ) );
		$this->assertSame( Url::normalize( 'http://example.com/services' ), Url::normalize( 'https://example.com/services/' ) );
	}

	public function test_relative_urls_resolve_against_home(): void {
		$this->assertSame( Url::normalize( home_url( '/contact/' ) ), Url::normalize( '/contact/' ) );
	}

	public function test_size_suffix_and_same_image(): void {
		$this->assertSame( 'https://x.test/up/hero.jpg', Url::strip_size_suffix( 'https://x.test/up/hero-300x188.jpg' ) );
		$this->assertTrue( Url::same_image( 'https://x.test/up/hero-768x480.jpg', 'https://x.test/up/hero.jpg' ) );
		$this->assertTrue( Url::same_image( 'https://x.test/up/hero-scaled.jpg', 'x.test/up/hero.jpg' ) );
		$this->assertFalse( Url::same_image( 'https://x.test/up/hero.jpg', 'https://x.test/up/other.jpg' ) );
	}

	public function test_tel_and_mailto(): void {
		$this->assertSame( 'tel:+19725550192', Url::normalize( 'tel:+19725550192' ) );
		$this->assertSame( '', Url::normalize( 'javascript:alert(1)' ) );
		$this->assertSame( '', Url::normalize( 'ftp://example.com/file' ) );
	}
}
