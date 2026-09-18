<?php
/**
 * Classic navigation menu provider.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Providers;

use EditTrace\Inspector\ElementContext;
use EditTrace\Inspector\TraceContext;
use EditTrace\Inspector\TraceSession;
use EditTrace\Integrations\Navigation\MenuInstrumenter;
use EditTrace\Support\EditLinks;

/**
 * Resolves classic (Appearance → Menus) menu items stamped during render.
 * Block-based navigation menus are handled by the Gutenberg provider.
 */
final class NavigationProvider extends AbstractProvider {

	public function get_name(): string {
		return 'navigation';
	}

	public function get_priority(): int {
		return 95;
	}

	public function register_render_hooks( TraceSession $session ): void {
		( new MenuInstrumenter( $session ) )->register_hooks();
	}

	public function get_dom_markers(): array {
		return array(
			array(
				'selector'            => '[data-edittrace-kind="menu"]',
				'label'               => 'Menu',
				'datasetKeys'         => array( 'edittraceId', 'edittraceKind' ),
				'requiredDatasetKeys' => array( 'edittraceId', 'edittraceKind' ),
			),
		);
	}

	public function supports( ElementContext $context, TraceContext $trace ): bool {
		return null !== $trace->get_registry() && null !== $this->nearest_menu_node( $context );
	}

	public function resolve( ElementContext $context, TraceContext $trace ): array {
		$registry = $trace->get_registry();
		$found    = $this->nearest_menu_node( $context );
		if ( null === $registry || null === $found ) {
			return array();
		}
		$entry = $registry->get( (string) $found['node']['dataset']['edittraceId'] );
		if ( null === $entry || 'menu_item' !== ( $entry['kind'] ?? '' ) ) {
			return array();
		}

		$c               = $this->candidate( 'menu_item' );
		$c->system       = __( 'Navigation', 'edittrace' );
		$c->source_id    = (string) ( $entry['menu_id'] ?? '' );
		$c->source_name  = (string) ( $entry['menu_name'] ?? __( 'Menu', 'edittrace' ) );
		$c->source_label = __( 'Menu', 'edittrace' );
		$c->item_label   = __( 'Menu Item', 'edittrace' );
		$c->item_name    = (string) ( $entry['title'] ?? '' );
		$c->item_key     = 'menu-item-' . (string) ( $entry['item_id'] ?? '' );
		$c->hierarchy    = array( $c->source_name, $c->item_name );
		$c->edit_url     = isset( $entry['edit_url'] ) && is_string( $entry['edit_url'] ) ? $entry['edit_url'] : null;
		$c->edit_label   = __( 'Edit Navigation', 'edittrace' );
		$c->role         = 'content';
		$c->depth        = (int) $found['depth'];
		if ( ! empty( $entry['url'] ) ) {
			$c->details[ __( 'Destination', 'edittrace' ) ] = (string) $entry['url'];
		}
		if ( ! empty( $entry['location_label'] ) ) {
			$c->details[ __( 'Menu Location', 'edittrace' ) ] = (string) $entry['location_label'];
		}
		if ( 'post_type' === ( $entry['type'] ?? '' ) && ! empty( $entry['object_id'] ) ) {
			$linked = get_post( (int) $entry['object_id'] );
			if ( $linked instanceof \WP_Post ) {
				$url = EditLinks::post( (int) $linked->ID );
				if ( $url ) {
					$c->add_action( sprintf( __( 'Edit linked %s', 'edittrace' ), EditLinks::post_type_label( $linked->post_type ) ), $url );
				}
			}
		}
		$c->technical = array(
			'menuId'     => (int) ( $entry['menu_id'] ?? 0 ),
			'itemId'     => (int) ( $entry['item_id'] ?? 0 ),
			'traceEntry' => (string) ( $entry['id'] ?? '' ),
		);
		$c->mark_global( __( 'This menu can appear on multiple pages.', 'edittrace' ) );
		$c->with_confidence( 1.0, __( 'This menu item was rendered by wp_nav_menu() during the page trace.', 'edittrace' ) );
		return array( $c );
	}

	/**
	 * @return array{node:array<string,mixed>,depth:int}|null
	 */
	private function nearest_menu_node( ElementContext $context ): ?array {
		foreach ( $context->chain() as $depth => $node ) {
			if ( ! empty( $node['dataset']['edittraceId'] ) && ( $node['dataset']['edittraceKind'] ?? '' ) === 'menu' ) {
				return array(
					'node'  => $node,
					'depth' => $depth,
				);
			}
		}
		return null;
	}
}
