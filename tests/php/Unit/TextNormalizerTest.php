<?php
declare( strict_types=1 );

namespace EditTrace\Tests\Unit;

use EditTrace\Search\IgnoredStrings;
use EditTrace\Search\TextNormalizer;
use EditTrace\Tests\TestCase;

final class TextNormalizerTest extends TestCase {

	public function test_normalize_strips_markup_entities_and_whitespace(): void {
		$this->assertSame( 'hello world', TextNormalizer::normalize( "  <p>Hello&nbsp;<strong>World</strong></p>\n " ) );
		$this->assertSame( 'a & b', TextNormalizer::normalize( 'A &amp; B.' ) );
	}

	public function test_equals_handles_phone_number_formatting(): void {
		$this->assertTrue( TextNormalizer::equals( '(972) 555-0192', '972-555-0192' ) );
		$this->assertTrue( TextNormalizer::equals( 'Request a Demo', 'request a demo!' ) );
		$this->assertFalse( TextNormalizer::equals( 'Request a Demo', 'Request a Quote' ) );
		$this->assertFalse( TextNormalizer::equals( '', '' ) );
	}

	public function test_contains_requires_meaningful_needle(): void {
		$this->assertTrue( TextNormalizer::contains( '<p>The intro copy is a field.</p><p>Second paragraph</p>', 'Second paragraph' ) );
		$this->assertFalse( TextNormalizer::contains( 'The intro copy', 'ab' ) );
	}

	public function test_ignored_strings(): void {
		$this->assertTrue( IgnoredStrings::is_ignored( 'Home' ) );
		$this->assertTrue( IgnoredStrings::is_ignored( 'Submit' ) );
		$this->assertTrue( IgnoredStrings::is_ignored( '42' ) );
		$this->assertFalse( IgnoredStrings::is_ignored( 'Request a Consultation' ) );
	}

	public function test_ignored_strings_filter(): void {
		$filter = static fn( array $strings ): array => array_merge( $strings, array( 'request a demo' ) );
		add_filter( 'edittrace/ignored_search_strings', $filter );
		try {
			$this->assertTrue( IgnoredStrings::is_ignored( 'Request a Demo' ) );
		} finally {
			remove_filter( 'edittrace/ignored_search_strings', $filter );
		}
	}
}
