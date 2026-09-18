<?php
declare( strict_types=1 );

namespace EditTrace\Tests\Integration;

use EditTrace\Integrations\ACF\Fingerprint;
use EditTrace\Integrations\ACF\RenderRegistry;
use EditTrace\Providers\ACFProvider;
use EditTrace\Tests\TestCase;

final class ACFTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'get_field' ) ) {
			$this->markTestSkipped( 'ACF is not installed in the test site.' );
		}
	}

	/**
	 * Simulates a template reading fields with the registry hooked.
	 */
	private function render_fields( \EditTrace\Inspector\TraceSession $session, callable $render ): void {
		// ACF caches loaded values per request; reset so hooks fire again.
		if ( function_exists( 'acf_get_store' ) && acf_get_store( 'values' ) ) {
			acf_get_store( 'values' )->reset();
		}
		$registry = new RenderRegistry( $session );
		$registry->register_hooks();
		try {
			$render();
		} finally {
			remove_filter( 'acf/load_value', array( $registry, 'on_load_value' ), 20 );
			remove_filter( 'acf/format_value', array( $registry, 'on_format_value' ), 20 );
		}
	}

	public function test_page_field_and_options_field_are_recorded_and_matched(): void {
		$session = $this->start_session();
		$acf_id  = $this->id( 'acf_id' );
		$this->render_fields(
			$session,
			static function () use ( $acf_id ): void {
				get_field( 'hero_heading', $acf_id );
				get_field( 'hero_cta', $acf_id );
				get_field( 'hero_image', $acf_id );
				get_field( 'hero_intro', $acf_id );
				get_field( 'company_phone', 'option' );
			}
		);
		$this->assertSame( 5, $session->get_registry()->count( 'acf_field' ) );

		$provider = new ACFProvider();
		$trace    = $this->trace( $session );

		$heading = $provider->resolve( $this->element( array( 'tag' => 'h2', 'text' => 'Custom Fields Power This Heading' ) ), $trace );
		$this->assertCount( 1, $heading );
		$this->assertSame( 1.0, $heading[0]->confidence );
		$this->assertSame( 'Hero Heading', $heading[0]->item_name );
		$this->assertSame( 'hero_heading', $heading[0]->item_key );
		$this->assertSame( 'ACF Demo', $heading[0]->source_name );
		$this->assertSame( 'Page', $heading[0]->source_label );
		$this->assertSame( 'Homepage Hero', $heading[0]->details['Field Group'] );
		$this->assertStringContainsString( 'post=' . $acf_id, (string) $heading[0]->edit_url );
		$this->assertFalse( $heading[0]->global );

		$phone = $provider->resolve( $this->element( array( 'tag' => 'a', 'text' => '972-555-0192', 'href' => 'tel:9725550192' ) ), $trace );
		$this->assertCount( 1, $phone );
		$this->assertSame( 1.0, $phone[0]->confidence );
		$this->assertSame( 'acf_option', $phone[0]->source_type );
		$this->assertSame( 'Main Phone Number', $phone[0]->item_name );
		$this->assertSame( 'Site Options', $phone[0]->source_name );
		$this->assertSame( 'Company Information', $phone[0]->details['Field Group'] );
		$this->assertTrue( $phone[0]->global );

		$link = $provider->resolve( $this->element( array( 'tag' => 'a', 'text' => 'Book a Consultation', 'href' => home_url( '/contact/' ) ) ), $trace )[0];
		$this->assertSame( 1.0, $link->confidence );
		$this->assertSame( 'hero_cta', $link->item_key );

		$image = $provider->resolve( $this->element( array( 'tag' => 'img', 'src' => str_replace( '.jpg', '-300x188.jpg', (string) wp_get_attachment_url( $this->id( 'hero_id' ) ) ) ) ), $trace )[0];
		$this->assertSame( 1.0, $image->confidence );
		$this->assertSame( 'hero_image', $image->item_key );

		$intro = $provider->resolve( $this->element( array( 'tag' => 'p', 'text' => 'Second paragraph of the intro.' ) ), $trace )[0];
		$this->assertSame( 'hero_intro', $intro->item_key );
		$this->assertSame( 0.85, $intro->confidence, 'Partial WYSIWYG match is High, not Exact.' );

		$this->assertSame( array(), $provider->resolve( $this->element( array( 'tag' => 'p', 'text' => 'Nothing like this' ) ), $trace ) );
	}

	public function test_identical_values_are_ambiguous_not_exact(): void {
		$session = $this->start_session();
		$acf_id  = $this->id( 'acf_id' );
		update_field( 'field_edittrace_hero_heading', '(972) 555-0192', $acf_id );
		try {
			$this->render_fields(
				$session,
				static function () use ( $acf_id ): void {
					get_field( 'hero_heading', $acf_id );
					get_field( 'company_phone', 'option' );
				}
			);
			$found = ( new ACFProvider() )->resolve( $this->element( array( 'tag' => 'a', 'text' => '(972) 555-0192' ) ), $this->trace( $session ) );
			$this->assertCount( 2, $found );
			$this->assertSame( 0.9, $found[0]->confidence );
			$this->assertSame( 0.9, $found[1]->confidence );
		} finally {
			update_field( 'field_edittrace_hero_heading', 'Custom Fields Power This Heading', $acf_id );
		}
	}

	public function test_fingerprints_skip_sensitive_and_handle_structured_types(): void {
		$this->assertSame( array(), Fingerprint::build( array( 'type' => 'password' ), 'hunter2' )['texts'] );
		$link = Fingerprint::build( array( 'type' => 'link' ), array( 'title' => 'Go', 'url' => 'https://example.com/go/' ) );
		$this->assertSame( array( 'go' ), $link['texts'] );
		$this->assertSame( array( 'example.com/go' ), $link['urls'] );
		$select = Fingerprint::build( array( 'type' => 'select', 'choices' => array( 'a' => 'Alpha' ) ), 'a' );
		$this->assertSame( array( 'a', 'alpha' ), $select['texts'] );
		$image = Fingerprint::build( array( 'type' => 'image' ), $this->id( 'hero_id' ) );
		$this->assertSame( array( $this->id( 'hero_id' ) ), $image['attachments'] );
		$this->assertNotEmpty( $image['urls'] );
	}

	public function test_sub_field_row_context_from_active_loop(): void {
		$parent = array( 'key' => 'field_rep', 'name' => 'rows', 'type' => 'repeater' );
		acf_add_loop(
			array(
				'selector' => 'rows',
				'name'     => 'rows',
				'value'    => array( array( 'field_sub' => 'a' ), array( 'field_sub' => 'b' ) ),
				'field'    => $parent,
				'i'        => 1,
				'post_id'  => 1,
				'key'      => 'field_rep',
			)
		);
		try {
			$loop = RenderRegistry::active_loop_for( array( 'key' => 'field_sub', 'parent' => 'field_rep' ) );
			$this->assertSame( 1, $loop['row'] );
			$this->assertNull( $loop['layout'] );
			$this->assertNull( RenderRegistry::active_loop_for( array( 'key' => 'field_other', 'parent' => 'field_nope' ) )['row'] );
		} finally {
			acf_remove_loop();
		}
	}
}
