<?php
declare( strict_types=1 );

namespace EditTrace\Tests\Integration;

use EditTrace\Providers\MediaProvider;
use EditTrace\Providers\NavigationProvider;
use EditTrace\Providers\QueriedObjectProvider;
use EditTrace\Search\FallbackSearch;
use EditTrace\Tests\TestCase;

final class ProvidersTest extends TestCase {

	public function test_provider_priority_order_and_registration_filter(): void {
		$names = array_map( static fn( $p ): string => $p->get_name(), edittrace()->get_resolver()->get_providers() );
		$this->assertSame( array( 'gutenberg', 'navigation', 'elementor', 'acf', 'media', 'queried_object', 'database' ), $names );

		$extra = new class() extends \EditTrace\Providers\AbstractProvider {
			public function get_name(): string { return 'bricks'; }
			public function get_priority(): int { return 85; }
			public function resolve( \EditTrace\Inspector\ElementContext $c, \EditTrace\Inspector\TraceContext $t ): array { return array(); }
		};
		$filter = static fn( array $providers ): array => array_merge( $providers, array( $extra ) );
		add_filter( 'edittrace/providers', $filter );
		edittrace()->set_providers( null );
		try {
			$names = array_map( static fn( $p ): string => $p->get_name(), edittrace()->get_resolver()->get_providers() );
			$this->assertSame( 'bricks', $names[3] );
		} finally {
			remove_filter( 'edittrace/providers', $filter );
			edittrace()->set_providers( null );
		}
	}

	public function test_media_provider_resolves_by_class_and_by_url(): void {
		$this->as_admin();
		$hero     = $this->id( 'hero_id' );
		$provider = new MediaProvider();
		$trace    = $this->trace( null );

		$by_class = $provider->resolve( $this->element( array( 'tag' => 'img', 'src' => 'https://cdn.example/x.jpg', 'classes' => array( 'wp-image-' . $hero ) ) ), $trace )[0];
		$this->assertSame( (string) $hero, $by_class->source_id );
		$this->assertSame( 1.0, $by_class->confidence );
		$this->assertSame( 'hero.jpg', $by_class->source_name );
		$this->assertSame( '800 × 500', $by_class->details['Dimensions'] );
		$this->assertStringContainsString( 'post=' . $hero, (string) $by_class->edit_url );

		$url    = str_replace( '.jpg', '-300x188.jpg', (string) wp_get_attachment_url( $hero ) );
		$by_url = $provider->resolve( $this->element( array( 'tag' => 'img', 'src' => $url ) ), $trace )[0];
		$this->assertSame( (string) $hero, $by_url->source_id );

		$this->assertSame( array(), $provider->resolve( $this->element( array( 'tag' => 'img', 'src' => home_url( '/wp-content/uploads/nope.jpg' ) ) ), $trace ) );
	}

	public function test_classic_menu_items_are_stamped_and_resolved(): void {
		$session      = $this->start_session();
		$instrumenter = new \EditTrace\Integrations\Navigation\MenuInstrumenter( $session );
		$instrumenter->register_hooks();
		try {
			$html = (string) wp_nav_menu( array( 'theme_location' => 'primary', 'echo' => false, 'fallback_cb' => '__return_empty_string' ) );
		} finally {
			remove_filter( 'wp_nav_menu_objects', array( $instrumenter, 'on_menu_objects' ) );
			remove_filter( 'nav_menu_link_attributes', array( $instrumenter, 'on_link_attributes' ), 999 );
		}
		$this->assertStringContainsString( 'data-edittrace-kind="menu"', $html );
		preg_match( '/data-edittrace-id="([^"]+)"[^>]*data-edittrace-kind="menu"/', $html, $m );
		$this->assertNotEmpty( $m );

		$c = ( new NavigationProvider() )->resolve(
			$this->element( array( 'tag' => 'a', 'text' => 'Our Services', 'dataset' => array( 'edittraceId' => $m[1], 'edittraceKind' => 'menu' ) ) ),
			$this->trace( $session )
		)[0];
		$this->assertSame( 'Primary Navigation', $c->source_name );
		$this->assertSame( 'Our Services', $c->item_name );
		$this->assertStringContainsString( 'nav-menus.php?action=edit&menu=' . $this->id( 'menu_id' ), (string) $c->edit_url );
		$this->assertTrue( $c->global );
		$this->assertSame( 1.0, $c->confidence );
		$this->assertNotEmpty( $c->actions, 'Linked page action expected.' );
	}

	public function test_queried_object_provider_only_claims_verified_content(): void {
		$session  = $this->start_session();
		$provider = new QueriedObjectProvider();
		$trace    = $this->trace( $session );
		// The CLI session has no queried object → unsupported.
		$this->assertFalse( $provider->supports( $this->element( array( 'tag' => 'p', 'text' => 'x' ) ), $trace ) );

		$session->add_page_context( array( 'object_type' => 'post', 'object_id' => $this->id( 'home_id' ), 'title' => 'Homepage' ) );
		$hit = $provider->resolve( $this->element( array( 'tag' => 'p', 'text' => 'This paragraph lives in the homepage content.' ) ), $trace );
		$this->assertCount( 1, $hit );
		$this->assertLessThan( 1.0, $hit[0]->confidence );
		$this->assertSame( array(), $provider->resolve( $this->element( array( 'tag' => 'p', 'text' => 'Definitely not on the homepage' ) ), $trace ) );
		$this->assertSame( array(), $provider->resolve( $this->element( array( 'tag' => 'p', 'text' => 'Home' ) ), $trace ), 'Generic strings are ignored.' );
	}

	public function test_fallback_search_is_bounded_and_finds_targeted_matches(): void {
		$this->as_admin();
		$search = new FallbackSearch( 5 );
		$hits   = $search->run( array( 'text' => 'Footer disclaimer stored in a plain option', 'href' => '', 'src' => '', 'alt' => '', 'current_post' => 0 ) );
		$this->assertNotEmpty( $hits );
		$this->assertSame( 'option', $hits[0]['kind'] );
		$this->assertSame( 'edittrace_test_footer_text', $hits[0]['id'] );
		$this->assertLessThanOrEqual( 5, count( $hits ) );

		$hits = $search->run( array( 'text' => 'Get Started Today', 'href' => '', 'src' => '', 'alt' => '', 'current_post' => 0 ) );
		$kinds = array_column( $hits, 'kind' );
		$this->assertContains( 'elementor', $kinds );
		$el = $hits[ array_search( 'elementor', $kinds, true ) ];
		$this->assertSame( 'e5f6a7b', $el['element_id'] );
		$this->assertLessThan( 1.0, $el['score'] );

		$this->assertSame( array(), $search->run( array( 'text' => 'Home', 'href' => '', 'src' => '', 'alt' => '', 'current_post' => 0 ) ) );
		$this->assertSame( array(), $search->run( array( 'text' => str_repeat( 'a', 400 ), 'href' => '', 'src' => '', 'alt' => '', 'current_post' => 0 ) ) );
	}

	public function test_sensitive_options_are_never_returned(): void {
		$this->as_admin();
		update_option( 'edittrace_test_api_secret_token', 'zq-secret-value-777' );
		try {
			$hits = ( new FallbackSearch() )->run( array( 'text' => 'zq-secret-value-777', 'href' => '', 'src' => '', 'alt' => '', 'current_post' => 0 ) );
			$this->assertSame( array(), array_filter( $hits, static fn( array $h ): bool => 'option' === $h['kind'] ) );
		} finally {
			delete_option( 'edittrace_test_api_secret_token' );
		}
	}
}
