<?php
declare( strict_types=1 );

namespace EditTrace\Tests\Integration;

use EditTrace\Providers\ElementorProvider;
use EditTrace\Tests\TestCase;

final class ElementorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			$this->markTestSkipped( 'Elementor is not installed in the test site.' );
		}
	}

	public function test_widget_resolves_to_document_and_hierarchy(): void {
		$this->as_admin();
		$el_id    = $this->id( 'el_id' );
		$provider = new ElementorProvider();
		$element  = $this->element(
			array(
				'tag'       => 'a',
				'text'      => 'Get Started Today',
				'ancestors' => array(
					array( 'tag' => 'div', 'classes' => array( 'elementor-widget-container' ) ),
					array( 'tag' => 'div', 'classes' => array( 'elementor-element', 'elementor-widget' ), 'dataset' => array( 'id' => 'e5f6a7b', 'element_type' => 'widget', 'widget_type' => 'button.default' ) ),
					array( 'tag' => 'div', 'classes' => array( 'elementor-element' ), 'dataset' => array( 'id' => 'd4e5f6a', 'element_type' => 'container' ) ),
					array( 'tag' => 'div', 'classes' => array( 'elementor' ), 'dataset' => array( 'elementorId' => (string) $el_id, 'elementorType' => 'wp-page' ) ),
				),
			)
		);
		$trace = $this->trace( null );
		$this->assertTrue( $provider->supports( $element, $trace ) );
		$c = $provider->resolve( $element, $trace )[0];
		$this->assertSame( 1.0, $c->confidence );
		$this->assertSame( 'elementor_widget', $c->source_type );
		$this->assertSame( 'Elementor Landing', $c->source_name );
		$this->assertSame( 'Button', $c->item_name );
		$this->assertSame( array( 'Elementor Landing', 'Hero Container', 'CTA Container', 'Button' ), $c->hierarchy );
		$this->assertStringContainsString( 'action=elementor', (string) $c->edit_url );
		$this->assertSame( 'Edit in Elementor', $c->edit_label );
		$this->assertFalse( $c->global );
		$this->assertSame( 2, $c->depth );
	}

	public function test_header_template_is_reported_as_global_theme_builder_document(): void {
		$this->as_admin();
		$header  = $this->id( 'header_id' );
		$element = $this->element(
			array(
				'tag'       => 'span',
				'text'      => 'Header CTA Button',
				'ancestors' => array(
					array( 'tag' => 'a', 'classes' => array( 'elementor-button' ) ),
					array( 'tag' => 'div', 'classes' => array( 'elementor-element', 'elementor-widget' ), 'dataset' => array( 'id' => '3c4d5e6', 'element_type' => 'widget', 'widget_type' => 'button.default' ) ),
					array( 'tag' => 'div', 'classes' => array( 'elementor' ), 'dataset' => array( 'elementorId' => (string) $header, 'elementorType' => 'header' ) ),
				),
			)
		);
		$c = ( new ElementorProvider() )->resolve( $element, $this->trace( null ) )[0];
		$this->assertSame( 'Main Site Header', $c->source_name );
		$this->assertTrue( $c->global );
		$this->assertStringContainsString( 'Theme Builder', $c->system );
		$this->assertSame( 'Header', $c->source_label );
		$this->assertStringContainsString( 'post=' . $header, (string) $c->edit_url );
	}

	public function test_unknown_element_falls_back_to_document_with_lower_confidence(): void {
		$this->as_admin();
		$el_id   = $this->id( 'el_id' );
		$element = $this->element(
			array(
				'tag'       => 'div',
				'ancestors' => array(
					array( 'tag' => 'div', 'classes' => array( 'elementor-element' ), 'dataset' => array( 'id' => 'zzzzzzz', 'element_type' => 'widget' ) ),
					array( 'tag' => 'div', 'classes' => array( 'elementor' ), 'dataset' => array( 'elementorId' => (string) $el_id, 'elementorType' => 'wp-page' ) ),
				),
			)
		);
		$trace = $this->trace( null );
		$c     = ( new ElementorProvider() )->resolve( $element, $trace )[0];
		$this->assertSame( 'elementor_document', $c->source_type );
		$this->assertLessThan( 1.0, $c->confidence );
		$this->assertNotEmpty( $trace->get_notes() );
	}

	public function test_element_found_in_other_rendered_document_is_high_not_exact(): void {
		$session = $this->start_session();
		$session->get_registry()->register( 'elementor_document', array( 'document_id' => $this->id( 'header_id' ), 'type' => 'header' ) );
		$element = $this->element(
			array(
				'tag'       => 'div',
				'ancestors' => array(
					array( 'tag' => 'div', 'classes' => array( 'elementor-element' ), 'dataset' => array( 'id' => '2b3c4d5', 'element_type' => 'widget', 'widget_type' => 'heading.default' ) ),
				),
			)
		);
		$c = ( new ElementorProvider() )->resolve( $element, $this->trace( $session ) )[0];
		$this->assertSame( 'Heading', $c->item_name );
		$this->assertSame( 0.95, $c->confidence );
	}
}
