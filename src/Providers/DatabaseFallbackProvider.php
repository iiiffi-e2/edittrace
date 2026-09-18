<?php
/**
 * Generic fallback provider (on request only).
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Providers;

use EditTrace\Inspector\ElementContext;
use EditTrace\Inspector\SourceCandidate;
use EditTrace\Inspector\TraceContext;
use EditTrace\Search\FallbackSearch;
use EditTrace\Support\Options;

/**
 * Runs the bounded database search and converts hits into candidates.
 * Never produces an "Exact" result: the search proves a value is stored
 * somewhere, not that it rendered the clicked element.
 */
final class DatabaseFallbackProvider extends AbstractProvider {

	private Options $options;

	public function __construct( Options $options ) {
		$this->options = $options;
	}

	public function get_name(): string {
		return 'database';
	}

	public function get_priority(): int {
		return 5;
	}

	public function is_fallback(): bool {
		return true;
	}

	public function supports( ElementContext $context, TraceContext $trace ): bool {
		return $this->options->fallback_search_enabled() && ( '' !== $context->text || '' !== $context->href || '' !== $context->src || '' !== $context->alt );
	}

	public function resolve( ElementContext $context, TraceContext $trace ): array {
		$page   = $trace->get_page();
		$search = new FallbackSearch( $this->options->max_results() );
		$hits   = $search->run(
			array(
				'text'         => $context->text,
				'href'         => $context->href,
				'src'          => $context->src,
				'alt'          => $context->alt,
				'current_post' => 'post' === ( $page['object_type'] ?? '' ) ? (int) ( $page['object_id'] ?? 0 ) : 0,
			)
		);
		$candidates = array();
		foreach ( $hits as $hit ) {
			$candidates[] = $this->from_hit( $hit );
		}
		if ( empty( $candidates ) ) {
			$trace->note( __( 'WordPress search: no stored content matched the clicked value.', 'edittrace' ) );
		}
		return $candidates;
	}

	/**
	 * @param array<string,mixed> $hit Search hit.
	 */
	private function from_hit( array $hit ): SourceCandidate {
		$kind = (string) $hit['kind'];
		$c    = $this->candidate( 'search_' . $kind );
		$c->source_id    = (string) $hit['id'];
		$c->source_name  = (string) $hit['title'];
		$c->source_label = (string) ( $hit['label'] ?? '' );
		$c->edit_url     = isset( $hit['edit_url'] ) && is_string( $hit['edit_url'] ) ? $hit['edit_url'] : null;
		$c->hierarchy    = isset( $hit['hierarchy'] ) && is_array( $hit['hierarchy'] ) ? $hit['hierarchy'] : array( (string) $hit['title'] );
		$c->depth        = 0;

		switch ( $kind ) {
			case 'post':
				$c->system     = __( 'WordPress', 'edittrace' );
				$c->item_label = 'title' === ( $hit['field'] ?? '' ) ? __( 'Title', 'edittrace' ) : __( 'Content', 'edittrace' );
				$c->item_name  = 'title' === ( $hit['field'] ?? '' ) ? (string) $hit['title'] : __( 'Page content', 'edittrace' );
				$c->edit_label = sprintf( __( 'Edit %s', 'edittrace' ), (string) $hit['label'] );
				$c->technical  = array( 'postId' => (int) $hit['id'] );
				break;
			case 'postmeta':
				$c->system     = ! empty( $hit['acf'] ) ? __( 'Advanced Custom Fields', 'edittrace' ) : __( 'Custom Field', 'edittrace' );
				$c->item_label = __( 'Field', 'edittrace' );
				$c->item_name  = (string) ( $hit['item'] ?? $hit['field'] );
				$c->item_key   = (string) $hit['field'];
				$c->edit_label = sprintf( __( 'Edit %s', 'edittrace' ), (string) $hit['label'] );
				$c->technical  = array(
					'postId'  => (int) $hit['id'],
					'metaKey' => (string) $hit['field'],
				);
				break;
			case 'elementor':
				$c->source_type = 'elementor_widget';
				$c->system      = (string) ( $hit['system'] ?? __( 'Elementor', 'edittrace' ) );
				$c->item_label  = __( 'Widget', 'edittrace' );
				$c->item_name   = (string) ( $hit['item'] ?? '' );
				$c->item_key    = (string) ( $hit['item_key'] ?? '' );
				$c->edit_label  = __( 'Edit in Elementor', 'edittrace' );
				$c->role        = 'structure';
				$c->technical   = array(
					'documentId' => (int) $hit['id'],
					'elementId'  => (string) ( $hit['element_id'] ?? '' ),
				);
				if ( ! empty( $hit['global'] ) ) {
					$c->mark_global( (string) ( $hit['global_note'] ?? '' ) );
				}
				break;
			case 'option':
				$c->system     = (string) ( $hit['system'] ?? __( 'WordPress Options', 'edittrace' ) );
				$c->item_label = __( 'Option', 'edittrace' );
				$c->item_name  = (string) ( $hit['item'] ?? $hit['id'] );
				$c->item_key   = (string) $hit['id'];
				$c->edit_label = __( 'Edit Settings', 'edittrace' );
				$c->technical  = array( 'option' => (string) $hit['id'] );
				if ( null === $c->edit_url ) {
					$c->details[ __( 'Note', 'edittrace' ) ] = __( 'This option has no dedicated settings screen; it is managed by a plugin or theme.', 'edittrace' );
				}
				break;
			case 'term':
				$c->system     = __( 'WordPress', 'edittrace' );
				$c->item_label = __( 'Term', 'edittrace' );
				$c->item_name  = (string) $hit['title'];
				$c->edit_label = __( 'Edit Term', 'edittrace' );
				$c->technical  = array(
					'termId'   => (int) $hit['id'],
					'taxonomy' => (string) $hit['field'],
				);
				break;
		}
		if ( ! empty( $hit['global'] ) && ! $c->global ) {
			$c->mark_global();
		}
		$c->with_confidence( min( 0.95, (float) $hit['score'] ), (string) $hit['reason'] );
		return $c;
	}
}
