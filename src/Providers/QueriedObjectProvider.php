<?php
/**
 * Current queried object provider.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Providers;

use EditTrace\Inspector\ElementContext;
use EditTrace\Inspector\TraceContext;
use EditTrace\Search\IgnoredStrings;
use EditTrace\Search\TextNormalizer;
use EditTrace\Support\Url;

/**
 * Weak-evidence provider: the object the page is about. It only produces
 * a visible candidate when the element's text/href/src actually occurs in
 * that object's stored content, so the current page is never reported as
 * the source just because it is the current page.
 */
final class QueriedObjectProvider extends AbstractProvider {

	public function get_name(): string {
		return 'queried_object';
	}

	public function get_priority(): int {
		return 20;
	}

	public function supports( ElementContext $context, TraceContext $trace ): bool {
		$page = $trace->get_page();
		return 'post' === ( $page['object_type'] ?? '' ) && (int) ( $page['object_id'] ?? 0 ) > 0;
	}

	public function resolve( ElementContext $context, TraceContext $trace ): array {
		$page = $trace->get_page();
		$post = get_post( (int) $page['object_id'] );
		if ( ! $post instanceof \WP_Post ) {
			return array();
		}

		$content    = (string) $post->post_content;
		$evidence   = array();
		$confidence = 0.0;

		if ( '' !== $context->text && ! IgnoredStrings::is_ignored( $context->text ) ) {
			if ( TextNormalizer::contains( $content, $context->text ) ) {
				$confidence = max( $confidence, 0.6 );
				$evidence[] = 'text';
			} elseif ( TextNormalizer::equals( $post->post_title, $context->text ) ) {
				$confidence = max( $confidence, 0.85 );
				$evidence[] = 'title';
			}
		}
		if ( '' !== $context->href && false !== stripos( $content, Url::path( $context->href ) ) ) {
			$confidence = max( $confidence, 0.5 );
			$evidence[] = 'href';
		}
		if ( '' !== $context->src && false !== stripos( $content, wp_basename( Url::strip_size_suffix( (string) wp_parse_url( $context->src, PHP_URL_PATH ) ) ) ) ) {
			$confidence = max( $confidence, 0.5 );
			$evidence[] = 'src';
		}

		if ( 0.0 === $confidence ) {
			return array();
		}

		$type_obj = get_post_type_object( $post->post_type );
		$label    = $type_obj ? $type_obj->labels->singular_name : ucfirst( $post->post_type );
		$edit_url = get_edit_post_link( $post->ID, 'raw' );

		$c               = $this->candidate( 'post' );
		$c->system       = __( 'WordPress', 'edittrace' );
		$c->source_id    = (string) $post->ID;
		$c->source_name  = $post->post_title;
		$c->source_label = $label;
		$c->item_label   = in_array( 'title', $evidence, true ) ? __( 'Title', 'edittrace' ) : __( 'Content', 'edittrace' );
		$c->item_name    = in_array( 'title', $evidence, true ) ? $post->post_title : TextNormalizer::excerpt( $context->text ?: $context->href ?: $context->src, 60 );
		$c->hierarchy    = array( $post->post_title );
		$c->edit_url     = $edit_url ?: null;
		/* translators: %s: post type label, e.g. Page */
		$c->edit_label = sprintf( __( 'Edit %s', 'edittrace' ), $label );
		$c->role       = 'content';
		$c->technical  = array(
			'postId'   => $post->ID,
			'postType' => $post->post_type,
			'match'    => implode( ',', $evidence ),
		);
		$c->with_confidence( $confidence, sprintf( 'Found in the %s "%s" (%s).', strtolower( $label ), $post->post_title, implode( ', ', $evidence ) ) );
		return array( $c );
	}
}
