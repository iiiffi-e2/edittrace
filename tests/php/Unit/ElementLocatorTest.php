<?php
declare( strict_types=1 );

namespace EditTrace\Tests\Unit;

use EditTrace\Integrations\Elementor\ElementLocator;
use EditTrace\Tests\TestCase;

final class ElementLocatorTest extends TestCase {

	/** @return array<int,array<string,mixed>> */
	private function data(): array {
		return array(
			array(
				'id'       => 'aaa1111',
				'elType'   => 'container',
				'settings' => array( '_title' => 'Hero' ),
				'elements' => array(
					array(
						'id'         => 'bbb2222',
						'elType'     => 'container',
						'settings'   => array(),
						'elements'   => array(
							array(
								'id'         => 'ccc3333',
								'elType'     => 'widget',
								'widgetType' => 'button',
								'settings'   => array( 'text' => 'Get Started', 'link' => array( 'url' => 'https://x.test/go/' ) ),
								'elements'   => array(),
							),
						),
					),
				),
			),
		);
	}

	public function test_find_returns_element_ancestors_and_indexes(): void {
		$found = ElementLocator::find( $this->data(), 'ccc3333' );
		$this->assertNotNull( $found );
		$this->assertSame( 'button', $found['element']['widgetType'] );
		$this->assertCount( 2, $found['ancestors'] );
		$this->assertSame( array( 0, 0, 0 ), $found['indexes'] );
		$this->assertSame( array( 'Hero', 'Container', 'Button' ), ElementLocator::hierarchy( $found['ancestors'], $found['element'] ) );
		$this->assertNull( ElementLocator::find( $this->data(), 'nope' ) );
	}

	public function test_search_matches_nested_settings(): void {
		$hits = ElementLocator::search(
			$this->data(),
			static fn( $value, string $key ): bool => is_string( $value ) && false !== strpos( $value, '/go/' )
		);
		$this->assertCount( 1, $hits );
		$this->assertSame( 'link.url', $hits[0]['setting'] );
		$this->assertSame( 'ccc3333', $hits[0]['element']['id'] );
	}
}
