<?php
declare( strict_types=1 );

namespace EditTrace\Tests\Integration;

use EditTrace\Providers\GutenbergProvider;
use EditTrace\Tests\TestCase;

final class GutenbergTest extends TestCase {

	public function test_nested_blocks_are_stamped_with_hierarchy_and_resolve_exactly(): void {
		$session = $this->start_session();
		$home    = $this->id( 'home_id' );
		$html    = $this->render_blocks_as_post( $session, $home, get_post( $home )->post_content );

		$stamped = $this->stamped( $html );
		$this->assertNotEmpty( $stamped );
		$registry = $session->get_registry();

		$button = null;
		foreach ( $stamped as $node ) {
			$entry = $registry->get( $node['id'] );
			if ( $entry && 'core/button' === $entry['name'] ) {
				$button = $entry;
			}
		}
		$this->assertNotNull( $button, 'core/button must be registered' );
		$this->assertSame( array( 'Hero', 'Columns', 'Column 2', 'Buttons', 'Button' ), $button['labels'] );
		$this->assertSame( 'post', $button['source']['type'] );
		$this->assertSame( (string) $home, $button['source']['id'] );
		$this->assertSame( array( 2, 0, 1, 0, 0 ), $button['path'] );

		$provider = new GutenbergProvider();
		$element  = $this->element(
			array(
				'tag'       => 'a',
				'text'      => 'Request a Demo',
				'ancestors' => array( array( 'tag' => 'div', 'classes' => array( 'wp-block-button' ), 'dataset' => array( 'edittraceId' => $button['id'] ) ) ),
			)
		);
		$trace = $this->trace( $session );
		$this->assertTrue( $provider->supports( $element, $trace ) );
		$candidates = $provider->resolve( $element, $trace );
		$this->assertSame( 1.0, $candidates[0]->confidence );
		$this->assertSame( array( 'Homepage', 'Hero', 'Columns', 'Column 2', 'Buttons', 'Button' ), $candidates[0]->hierarchy );
		$this->assertSame( 'core/button', $candidates[0]->item_key );
		$this->assertSame( 'Edit Page', $candidates[0]->edit_label );
		$this->assertStringContainsString( 'post=' . $home, (string) $candidates[0]->edit_url );
		$this->assertFalse( $candidates[0]->global );
	}

	public function test_synced_pattern_is_reported_as_global_source_not_the_page(): void {
		$session = $this->start_session();
		$home    = $this->id( 'home_id' );
		$pattern = $this->id( 'pattern_id' );
		$html    = $this->render_blocks_as_post( $session, $home, '<!-- wp:block {"ref":' . $pattern . '} /-->' );
		$this->assertStringContainsString( 'shared-promo-text', $html );

		$paragraph = null;
		foreach ( $this->stamped( $html ) as $node ) {
			$entry = $session->get_registry()->get( $node['id'] );
			if ( $entry && 'core/paragraph' === $entry['name'] ) {
				$paragraph = $entry;
			}
		}
		$this->assertNotNull( $paragraph );
		$this->assertSame( 'wp_block', $paragraph['source']['type'] );
		$this->assertSame( 'Shared Promo', $paragraph['source']['title'] );
		$this->assertTrue( $paragraph['source']['global'] );
		$this->assertSame( array( 0 ), $paragraph['path'], 'Paths restart inside the pattern.' );

		$candidates = ( new GutenbergProvider() )->resolve(
			$this->element( array( 'tag' => 'p', 'dataset' => array( 'edittraceId' => $paragraph['id'] ) ) ),
			$this->trace( $session )
		);
		$this->assertTrue( $candidates[0]->global );
		$this->assertSame( 'Edit Pattern', $candidates[0]->edit_label );
		$this->assertSame( 'Shared Promo', $candidates[0]->source_name );
	}

	public function test_template_part_source_and_site_title_mapping(): void {
		$session = $this->start_session();
		$home    = $this->id( 'home_id' );
		$html    = $this->render_blocks_as_post( $session, $home, '<!-- wp:template-part {"slug":"header","area":"header"} /-->' );
		$tagline = null;
		$title   = null;
		foreach ( $this->stamped( $html ) as $node ) {
			$entry = $session->get_registry()->get( $node['id'] );
			if ( $entry && 'core/paragraph' === $entry['name'] && 'wp_template_part' === $entry['source']['type'] ) {
				$tagline = $entry;
			}
			if ( $entry && 'core/site-title' === $entry['name'] ) {
				$title = $entry;
			}
		}
		$this->assertNotNull( $tagline );
		$this->assertSame( 'Header', $tagline['source']['title'] );
		$this->assertStringContainsString( 'site-editor.php', (string) $tagline['source']['edit_url'] );

		$this->assertNotNull( $title );
		$c = ( new GutenbergProvider() )->resolve( $this->element( array( 'tag' => 'a', 'dataset' => array( 'edittraceId' => $title['id'] ) ) ), $this->trace( $session ) )[0];
		$this->assertSame( 'wp_option', $c->source_type );
		$this->assertStringContainsString( 'options-general.php', (string) $c->edit_url );
		$this->assertTrue( $c->global );
	}

	public function test_expired_trace_yields_no_candidate_and_a_note(): void {
		$provider = new GutenbergProvider();
		$session  = $this->start_session();
		$trace    = $this->trace( $session );
		$element  = $this->element( array( 'tag' => 'p', 'dataset' => array( 'edittraceId' => 'tzz' ) ) );
		$this->assertSame( array(), $provider->resolve( $element, $trace ) );
		$this->assertNotEmpty( $trace->get_notes() );
		$this->assertFalse( $provider->supports( $element, $this->trace( null ) ) );
	}

	public function test_stamp_never_overwrites_existing_marker(): void {
		$html = \EditTrace\Integrations\Gutenberg\BlockInstrumenter::stamp( '<div data-edittrace-id="t1"><p>x</p></div>', 't2' );
		$this->assertStringContainsString( 'data-edittrace-id="t1"', $html );
		$this->assertStringNotContainsString( 't2', $html );
		$this->assertSame( 'plain text', \EditTrace\Integrations\Gutenberg\BlockInstrumenter::stamp( 'plain text', 't3' ) );
	}
}
