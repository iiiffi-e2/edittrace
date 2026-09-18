<?php
declare( strict_types=1 );

namespace EditTrace\Tests\Unit;

use EditTrace\Inspector\ElementContext;
use EditTrace\Tests\TestCase;

final class ElementContextTest extends TestCase {

	public function test_sanitizes_and_bounds_input(): void {
		$ctx = ElementContext::from_array(
			array(
				'tag'       => 'A<script>',
				'text'      => "  <b>Request</b>\n a   Demo " . str_repeat( 'x', 2000 ),
				'href'      => 'javascript:alert(1)',
				'src'       => 'data:image/png;base64,AAAA',
				'classes'   => array( 'ok-class', 'bad class', '<x>' ),
				'dataset'   => array(
					'edittraceId' => 't1',
					'bad key'     => 'x',
					'id'          => array( 'nested' ),
				),
				'ancestors' => array_fill( 0, 20, array( 'tag' => 'div', 'classes' => 'a b' ) ),
				'traceId'   => 'ABCDEF0123456789ABCDEF0123456789',
			)
		);
		$this->assertSame( '', $ctx->tag );
		$this->assertStringStartsWith( 'Request a Demo', $ctx->text );
		$this->assertSame( ElementContext::MAX_TEXT, strlen( $ctx->text ) );
		$this->assertSame( '', $ctx->href );
		$this->assertSame( '', $ctx->src );
		$this->assertSame( array( 'ok-class' ), $ctx->classes );
		$this->assertSame( array( 'edittraceId' => 't1' ), $ctx->dataset );
		$this->assertCount( ElementContext::MAX_ANCESTORS, $ctx->ancestors );
		$this->assertSame( array( 'a', 'b' ), $ctx->ancestors[0]['classes'] );
		$this->assertSame( 'abcdef0123456789abcdef0123456789', $ctx->trace_id );
	}

	public function test_nearest_helpers_and_summary(): void {
		$ctx = ElementContext::from_array(
			array(
				'tag'       => 'span',
				'text'      => '',
				'src'       => 'https://x.test/wp-content/uploads/hero.jpg',
				'ancestors' => array(
					array( 'tag' => 'a', 'classes' => array( 'wp-image-12' ) ),
					array( 'tag' => 'div', 'dataset' => array( 'edittraceId' => 't9' ) ),
				),
			)
		);
		$found = $ctx->nearest_with_dataset( 'edittraceId' );
		$this->assertSame( 2, $found['depth'] );
		$class = $ctx->nearest_with_class( '/^wp-image-(\d+)$/' );
		$this->assertSame( '12', $class['match'][1] );
		$this->assertSame( 1, $class['depth'] );
		$this->assertSame( 'hero.jpg', $ctx->summary() );
		$this->assertTrue( $ctx->is_image() );
	}
}
