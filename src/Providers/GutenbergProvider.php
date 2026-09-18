<?php
/**
 * Gutenberg / block provider.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Providers;

use EditTrace\Inspector\ElementContext;
use EditTrace\Inspector\SourceCandidate;
use EditTrace\Inspector\TraceContext;
use EditTrace\Inspector\TraceSession;
use EditTrace\Integrations\Gutenberg\BlockInstrumenter;
use EditTrace\Support\BlockLabels;

/**
 * Resolves elements stamped by the BlockInstrumenter back to the block,
 * its path, and its owning source (page, template, template part, synced
 * pattern, navigation menu...). Exact by construction: the marker was put
 * there by this render.
 */
final class GutenbergProvider extends AbstractProvider {

	public function get_name(): string {
		return 'gutenberg';
	}

	public function get_priority(): int {
		return 100;
	}

	public function register_render_hooks( TraceSession $session ): void {
		( new BlockInstrumenter( $session ) )->register_hooks();
	}

	public function get_dom_markers(): array {
		return array(
			array(
				'selector'            => '[data-edittrace-id]:not([data-edittrace-kind])',
				'label'               => 'Gutenberg',
				'typeFromClassPrefix' => 'wp-block-',
				'datasetKeys'         => array( 'edittraceId', 'edittraceKind' ),
				'requiredDatasetKeys' => array( 'edittraceId' ),
				'forbiddenDatasetKeys' => array( 'edittraceKind' ),
			),
		);
	}

	public function supports( ElementContext $context, TraceContext $trace ): bool {
		return null !== $trace->get_registry() && null !== $context->nearest_with_dataset( 'edittraceId' );
	}

	public function resolve( ElementContext $context, TraceContext $trace ): array {
		$registry = $trace->get_registry();
		if ( null === $registry ) {
			return array();
		}
		$found = $context->nearest_with_dataset( 'edittraceId' );
		if ( null === $found ) {
			return array();
		}
		$entry = $registry->get( (string) $found['node']['dataset']['edittraceId'] );
		if ( null === $entry ) {
			$trace->note( __( 'The clicked block was not found in the page trace (it may have been rendered after the trace was saved).', 'edittrace' ) );
			return array();
		}
		if ( 'block' !== ( $entry['kind'] ?? '' ) ) {
			return array();
		}
		$block = $this->from_entry( $entry, $found['depth'] );

		// Another system's element (e.g. an Elementor widget) sits between the
		// clicked element and this block: the block merely contains it.
		$foreign = $this->closer_foreign_marker_depth( $context, $trace, $found['depth'] );
		if ( null !== $foreign ) {
			$block->role = 'container';
			$block->with_confidence( 0.5, __( 'This block contains the clicked element, but the element is produced by another system inside it.', 'edittrace' ) );
		}
		$candidates = array( $block );

		// If the block belongs to a synced pattern/template part etc., the
		// enclosing page may still be where the user inserted it; expose it as
		// a secondary container candidate so both destinations are one click away.
		$container = $this->container_candidate( $entry, $context, $trace );
		if ( $container ) {
			$candidates[] = $container;
		}
		return $candidates;
	}

	/**
	 * @param array<string,mixed> $entry Registry entry.
	 */
	private function from_entry( array $entry, int $depth ): SourceCandidate {
		$source = (array) ( $entry['source'] ?? array() );
		$type   = (string) ( $source['type'] ?? 'unknown' );
		$name   = (string) ( $entry['name'] ?? '' );
		$attrs  = (array) ( $entry['attrs'] ?? array() );
		$labels = array_values( (array) ( $entry['labels'] ?? array() ) );

		$c               = $this->candidate( $this->source_type_for( $type ) );
		$c->system       = $this->system_for( $type, $name );
		$c->source_id    = (string) ( $source['id'] ?? '' );
		$c->source_name  = (string) ( $source['title'] ?? '' );
		$c->source_label = (string) ( $source['label'] ?? '' );
		$c->item_label   = $this->is_menu_item( $name ) ? __( 'Menu Item', 'edittrace' ) : __( 'Block', 'edittrace' );
		$c->item_name    = $this->is_menu_item( $name ) ? ( $labels[ count( $labels ) - 1 ] ?? BlockLabels::title( $name ) ) : BlockLabels::title( $name );
		$c->item_key     = $name;
		$c->hierarchy    = array_merge( array( $c->source_name ), $labels );
		$c->edit_url     = isset( $source['edit_url'] ) && is_string( $source['edit_url'] ) ? $source['edit_url'] : null;
		$c->edit_label   = $this->edit_label_for( $type, $source );
		$c->role         = 'content';
		$c->depth        = $depth;
		$c->technical    = array(
			'blockName'  => $name,
			'blockPath'  => implode( '.', array_map( 'intval', (array) ( $entry['path'] ?? array() ) ) ),
			'traceEntry' => (string) ( $entry['id'] ?? '' ),
			'sourceType' => $type,
			'sourceId'   => (string) ( $source['id'] ?? '' ),
		);
		if ( 'post' === $type ) {
			$c->technical['postId'] = (int) ( $source['id'] ?? 0 );
		}
		if ( ! empty( $attrs['url'] ) && $this->is_menu_item( $name ) ) {
			$c->details[ __( 'Destination', 'edittrace' ) ] = (string) $attrs['url'];
		}
		if ( ! empty( $source['global'] ) ) {
			$c->mark_global( $this->global_note_for( $type ) );
		}
		if ( 'pattern' === $type ) {
			$c->details[ __( 'Defined in', 'edittrace' ) ] = __( 'Theme or plugin pattern file', 'edittrace' );
		}
		$c->with_confidence(
			1.0,
			$depth > 0
				? __( 'The clicked element is inside this block, which was rendered during the page trace.', 'edittrace' )
				: __( 'This block was rendered during the page trace.', 'edittrace' )
		);
		return $c;
	}

	/**
	 * Secondary candidate pointing at the enclosing page for global sources.
	 *
	 * @param array<string,mixed> $entry Registry entry.
	 */
	private function container_candidate( array $entry, ElementContext $context, TraceContext $trace ): ?SourceCandidate {
		$source = (array) ( $entry['source'] ?? array() );
		if ( empty( $source['global'] ) ) {
			return null;
		}
		$page = $trace->get_page();
		if ( 'post' !== ( $page['object_type'] ?? '' ) || empty( $page['edit_url'] ) ) {
			return null;
		}
		unset( $context );
		$type = (string) ( $source['type'] ?? '' );
		// Template parts and templates wrap the page, they are not "in" it.
		if ( in_array( $type, array( 'wp_template', 'wp_template_part', 'widget' ), true ) ) {
			return null;
		}
		$c               = $this->candidate( 'post' );
		$c->system       = __( 'WordPress', 'edittrace' );
		$c->source_id    = (string) $page['object_id'];
		$c->source_name  = (string) $page['title'];
		$c->source_label = isset( $page['post_type'] ) ? \EditTrace\Support\EditLinks::post_type_label( (string) $page['post_type'] ) : __( 'Page', 'edittrace' );
		$c->item_label   = __( 'Contains', 'edittrace' );
		$c->item_name    = (string) ( $source['label'] ?? '' ) . ': ' . (string) ( $source['title'] ?? '' );
		$c->hierarchy    = array( $c->source_name );
		$c->edit_url     = (string) $page['edit_url'];
		$c->edit_label   = sprintf( __( 'Edit %s', 'edittrace' ), $c->source_label );
		$c->role         = 'container';
		$c->technical    = array( 'postId' => (int) $page['object_id'] );
		$c->with_confidence( 0.5, __( 'The current page embeds this global content; the content itself is edited at its source.', 'edittrace' ) );
		return $c;
	}

	private function is_menu_item( string $name ): bool {
		return in_array( $name, array( 'core/navigation-link', 'core/navigation-submenu', 'core/home-link', 'core/page-list-item' ), true );
	}

	private function source_type_for( string $type ): string {
		switch ( $type ) {
			case 'wp_template':
				return 'block_template';
			case 'wp_template_part':
				return 'block_template_part';
			case 'wp_block':
				return 'synced_pattern';
			case 'wp_navigation':
				return 'navigation_block';
			case 'pattern':
				return 'block_pattern';
			case 'widget':
				return 'block_widget';
			default:
				return 'gutenberg_block';
		}
	}

	private function system_for( string $type, string $name ): string {
		if ( 'wp_navigation' === $type || $this->is_menu_item( $name ) ) {
			return __( 'Navigation', 'edittrace' );
		}
		return __( 'Gutenberg', 'edittrace' );
	}

	/**
	 * @param array<string,mixed> $source Source.
	 */
	private function edit_label_for( string $type, array $source ): string {
		switch ( $type ) {
			case 'wp_template':
				return __( 'Edit Template', 'edittrace' );
			case 'wp_template_part':
				return __( 'Edit Template Part', 'edittrace' );
			case 'wp_block':
				return __( 'Edit Pattern', 'edittrace' );
			case 'wp_navigation':
				return __( 'Edit Navigation', 'edittrace' );
			case 'widget':
				return __( 'Edit Widgets', 'edittrace' );
			case 'post':
				/* translators: %s: post type label */
				return sprintf( __( 'Edit %s', 'edittrace' ), (string) ( $source['label'] ?? __( 'Post', 'edittrace' ) ) );
			default:
				return __( 'Edit', 'edittrace' );
		}
	}

	private function global_note_for( string $type ): string {
		switch ( $type ) {
			case 'wp_template':
				return __( 'This template is used by every page that matches it.', 'edittrace' );
			case 'wp_template_part':
				return __( 'This template part appears on every page that uses it. Changes here can affect multiple pages.', 'edittrace' );
			case 'wp_block':
				return __( 'This synced pattern is reused wherever it is inserted. Changes here affect every instance.', 'edittrace' );
			case 'wp_navigation':
				return __( 'This navigation menu can appear on multiple pages.', 'edittrace' );
			case 'pattern':
				return __( 'This pattern is defined in code (theme or plugin), not in the database.', 'edittrace' );
			default:
				return __( 'Changes here may affect multiple pages.', 'edittrace' );
		}
	}
}
