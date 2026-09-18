<?php
/**
 * Advanced Custom Fields provider.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Providers;

use EditTrace\Inspector\ElementContext;
use EditTrace\Inspector\SourceCandidate;
use EditTrace\Inspector\TraceContext;
use EditTrace\Inspector\TraceSession;
use EditTrace\Integrations\ACF\FieldInfo;
use EditTrace\Integrations\ACF\RenderRegistry;
use EditTrace\Search\IgnoredStrings;
use EditTrace\Search\TextNormalizer;
use EditTrace\Support\Url;

/**
 * Matches the clicked element's text / href / image against the ACF fields
 * that were actually loaded while rendering this page (the render registry).
 * No database scanning is involved.
 */
final class ACFProvider extends AbstractProvider {

	public function get_name(): string {
		return 'acf';
	}

	public function get_priority(): int {
		return 80;
	}

	public function register_render_hooks( TraceSession $session ): void {
		if ( FieldInfo::available() ) {
			( new RenderRegistry( $session ) )->register_hooks();
		}
	}

	public function supports( ElementContext $context, TraceContext $trace ): bool {
		$registry = $trace->get_registry();
		if ( null === $registry || 0 === $registry->count( 'acf_field' ) ) {
			return false;
		}
		return '' !== $context->text || '' !== $context->href || '' !== $context->src || '' !== $context->alt;
	}

	public function resolve( ElementContext $context, TraceContext $trace ): array {
		$registry = $trace->get_registry();
		if ( null === $registry ) {
			return array();
		}
		$attachment_id = $this->attachment_id_from_classes( $context );
		$text          = TextNormalizer::normalize( $context->text );
		$text_ok       = '' !== $text && ! IgnoredStrings::is_ignored( $context->text );
		$href          = '' !== $context->href ? Url::normalize( $context->href ) : '';
		$src           = '' !== $context->src ? Url::normalize( Url::strip_size_suffix( $context->src ) ) : '';
		$alt           = TextNormalizer::normalize( $context->alt );

		$matches = array();
		foreach ( $registry->by_kind( 'acf_field' ) as $entry ) {
			$score  = 0.0;
			$reason = '';
			$texts  = (array) ( $entry['texts'] ?? array() );
			$urls   = (array) ( $entry['urls'] ?? array() );
			$ids    = array_map( 'intval', (array) ( $entry['attachments'] ?? array() ) );
			$type   = (string) ( $entry['type'] ?? '' );

			if ( $attachment_id > 0 && in_array( $attachment_id, $ids, true ) ) {
				$score  = 1.0;
				$reason = __( 'The image attachment is the value of this field.', 'edittrace' );
			} elseif ( '' !== $src && $this->url_in( $src, $urls, true ) ) {
				$score  = 1.0;
				$reason = __( 'The image URL matches this field value.', 'edittrace' );
			}

			if ( $score < 1.0 && $text_ok ) {
				foreach ( $texts as $candidate_text ) {
					if ( $candidate_text === $text || TextNormalizer::equals( $candidate_text, $text ) ) {
						$score  = 1.0;
						$reason = __( 'The clicked text equals this field value.', 'edittrace' );
						break;
					}
				}
				if ( $score < 1.0 && in_array( $type, array( 'wysiwyg', 'textarea' ), true ) && TextNormalizer::length( $text ) >= 12 ) {
					foreach ( $texts as $candidate_text ) {
						if ( TextNormalizer::contains( $candidate_text, $text ) ) {
							$score  = max( $score, 0.85 );
							$reason = __( 'The clicked text is part of this field value.', 'edittrace' );
							break;
						}
					}
				}
			}

			if ( $score < 1.0 && '' !== $href && $this->url_in( $href, $urls, false ) ) {
				$link_types = array( 'link', 'url', 'page_link', 'file', 'image', 'post_object', 'relationship' );
				$s          = in_array( $type, $link_types, true ) ? ( '' === $text || ! $text_ok ? 0.9 : 0.85 ) : 0.75;
				if ( $s > $score ) {
					$score  = $s;
					$reason = __( 'The link destination matches this field value.', 'edittrace' );
				}
				if ( 'link' === $type && $text_ok ) {
					foreach ( $texts as $candidate_text ) {
						if ( TextNormalizer::equals( $candidate_text, $text ) ) {
							$score  = 1.0;
							$reason = __( 'Both the link text and destination match this link field.', 'edittrace' );
							break;
						}
					}
				}
			}

			if ( $score < 0.85 && '' !== $alt ) {
				foreach ( $texts as $candidate_text ) {
					if ( TextNormalizer::equals( $candidate_text, $alt ) && ! empty( $ids ) ) {
						$score  = max( $score, 0.75 );
						$reason = __( 'The image alt text matches this field.', 'edittrace' );
						break;
					}
				}
			}

			if ( $score > 0.0 ) {
				$matches[] = array(
					'entry'  => $entry,
					'score'  => $score,
					'reason' => $reason,
				);
			}
		}

		if ( empty( $matches ) ) {
			return array();
		}

		// Two fields with identical values: neither can be called exact.
		$exact = array_filter( $matches, static fn( array $m ): bool => $m['score'] >= 1.0 );
		if ( count( $exact ) > 1 ) {
			foreach ( $matches as &$m ) {
				if ( $m['score'] >= 1.0 ) {
					$m['score']  = 0.9;
					$m['reason'] .= ' ' . __( 'Another ACF field rendered on this page has the same value, so the exact origin is ambiguous.', 'edittrace' );
				}
			}
			unset( $m );
		}

		$candidates = array();
		foreach ( $matches as $m ) {
			$candidates[] = $this->from_entry( $m['entry'], (float) $m['score'], (string) $m['reason'] );
		}
		return $candidates;
	}

	/**
	 * @param string[] $urls Normalized URLs.
	 */
	private function url_in( string $needle, array $urls, bool $image ): bool {
		foreach ( $urls as $url ) {
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}
			if ( $image ? Url::same_image( $needle, $url ) : $needle === $url ) {
				return true;
			}
		}
		return false;
	}

	private function attachment_id_from_classes( ElementContext $context ): int {
		$found = $context->nearest_with_class( '/^wp-image-(\d+)$/' );
		if ( null !== $found && 0 === $found['depth'] ) {
			return (int) $found['match'][1];
		}
		return 0;
	}

	/**
	 * @param array<string,mixed> $entry Registry entry.
	 */
	private function from_entry( array $entry, float $score, string $reason ): SourceCandidate {
		$object    = (array) ( $entry['object'] ?? array() );
		$group     = isset( $entry['group'] ) && is_array( $entry['group'] ) ? $entry['group'] : null;
		$ancestors = (array) ( $entry['ancestors'] ?? array() );

		$c               = $this->candidate( 'option' === ( $object['type'] ?? '' ) ? 'acf_option' : 'acf_field' );
		$c->system       = __( 'Advanced Custom Fields', 'edittrace' );
		$c->source_id    = (string) ( $object['id'] ?? '' );
		$c->source_name  = (string) ( $object['title'] ?? '' );
		$c->source_label = (string) ( $object['label'] ?? '' );
		$c->item_label   = __( 'Field', 'edittrace' );
		$c->item_name    = (string) ( $entry['label'] ?? $entry['name'] ?? '' );
		$c->item_key     = (string) ( $entry['name'] ?? '' );
		$c->role         = 'content';
		$c->depth        = 0;

		$hierarchy = array( $c->source_name );
		if ( $group && ! empty( $group['title'] ) ) {
			$c->details[ __( 'Field Group', 'edittrace' ) ] = (string) $group['title'];
		}
		foreach ( $ancestors as $ancestor ) {
			$label = (string) ( $ancestor['label'] ?? '' );
			$type  = (string) ( $ancestor['type'] ?? '' );
			if ( '' === $label ) {
				continue;
			}
			$hierarchy[] = $label;
			$type_label  = $this->type_label( $type );
			$c->details[ $type_label ] = $label;
		}
		if ( ! empty( $entry['layout'] ) ) {
			$c->details[ __( 'Layout', 'edittrace' ) ] = (string) $entry['layout'];
			$hierarchy[]                                 = (string) $entry['layout'];
		}
		if ( isset( $entry['row'] ) && null !== $entry['row'] ) {
			/* translators: %d: row number */
			$c->details[ __( 'Row', 'edittrace' ) ] = sprintf( __( 'Row %d', 'edittrace' ), (int) $entry['row'] + 1 );
		}
		$hierarchy[]  = $c->item_name;
		$c->hierarchy = $hierarchy;

		$c->edit_url   = isset( $object['edit_url'] ) && is_string( $object['edit_url'] ) ? $object['edit_url'] : null;
		$c->edit_label = (string) ( $object['edit_label'] ?? __( 'Edit', 'edittrace' ) );

		if ( 'option' === ( $object['type'] ?? '' ) ) {
			$c->mark_global( __( 'This value may appear throughout the website.', 'edittrace' ) );
			if ( null === $c->edit_url ) {
				$c->details[ __( 'Note', 'edittrace' ) ] = function_exists( 'acf_get_options_pages' )
					? __( 'No options page matching this field group could be determined.', 'edittrace' )
					: __( 'Options pages require ACF PRO; the value is stored in WordPress options.', 'edittrace' );
			}
		} elseif ( ! empty( $object['global'] ) ) {
			$c->mark_global();
		}
		$group_url = FieldInfo::group_edit_url( $group );
		if ( $group_url ) {
			$c->add_action( __( 'Field Group Settings', 'edittrace' ), $group_url );
		}

		$c->technical = array(
			'fieldKey'   => (string) ( $entry['key'] ?? '' ),
			'fieldType'  => (string) ( $entry['type'] ?? '' ),
			'objectId'   => (string) ( $entry['post_id'] ?? '' ),
			'objectType' => (string) ( $object['type'] ?? '' ),
			'groupKey'   => $group ? (string) ( $group['key'] ?? '' ) : '',
			'traceEntry' => (string) ( $entry['id'] ?? '' ),
		);
		if ( 'post' === ( $object['type'] ?? '' ) ) {
			$c->technical['postId'] = (int) ( $object['id'] ?? 0 );
		}
		$c->with_confidence( $score, $reason );
		return $c;
	}

	private function type_label( string $type ): string {
		switch ( $type ) {
			case 'repeater':
				return __( 'Repeater', 'edittrace' );
			case 'flexible_content':
				return __( 'Flexible Content', 'edittrace' );
			case 'group':
				return __( 'Group', 'edittrace' );
			default:
				return __( 'Parent Field', 'edittrace' );
		}
	}
}
