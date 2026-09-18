<?php
/**
 * The final answer for one inspect request.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Inspector;

/**
 * Wraps ranked candidates plus request/page/diagnostic information.
 */
final class SourceResult {

	public const STATUS_EXACT      = 'exact';
	public const STATUS_CANDIDATES = 'candidates';
	public const STATUS_UNKNOWN    = 'unknown';

	/** @var SourceCandidate[] */
	private array $candidates;

	private ElementContext $element;

	private TraceContext $context;

	private bool $searched;

	/**
	 * @param SourceCandidate[] $candidates Ranked candidates (best first).
	 */
	public function __construct( ElementContext $element, TraceContext $context, array $candidates, bool $searched = false ) {
		$this->element    = $element;
		$this->context    = $context;
		$this->candidates = array_values( $candidates );
		$this->searched   = $searched;
	}

	public function get_status(): string {
		$primary = $this->get_primary();
		if ( null === $primary ) {
			return self::STATUS_UNKNOWN;
		}
		if ( $primary->confidence >= 1.0 ) {
			return self::STATUS_EXACT;
		}
		if ( $primary->confidence >= Confidence::THRESHOLD_POSSIBLE ) {
			return self::STATUS_CANDIDATES;
		}
		return self::STATUS_UNKNOWN;
	}

	public function get_primary(): ?SourceCandidate {
		return $this->candidates[0] ?? null;
	}

	/**
	 * @return SourceCandidate[]
	 */
	public function get_candidates(): array {
		return $this->candidates;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		$debug   = $this->context->is_debug();
		$page    = $this->context->get_page();
		$primary = $this->get_primary();

		$visible = array();
		$hidden  = array();
		$exact   = $primary && $primary->confidence >= 1.0;
		foreach ( $this->candidates as $candidate ) {
			// With an exact answer, weak text matches are noise; containers and media stay useful.
			$weak_noise = $exact && $candidate !== $primary && $candidate->confidence < Confidence::THRESHOLD_HIGH && ! in_array( $candidate->role, array( 'container', 'media' ), true );
			if ( $candidate->confidence >= Confidence::THRESHOLD_POSSIBLE && ! $weak_noise ) {
				$visible[] = $candidate->to_array( $debug );
			} else {
				$hidden[] = $candidate->to_array( $debug );
			}
		}

		return array(
			'status'      => $this->get_status(),
			'selected'    => array(
				'summary' => $this->element->summary(),
				'tag'     => $this->element->tag,
				'text'    => $this->element->text,
				'href'    => $this->element->href,
				'src'     => $this->element->src,
				'alt'     => $this->element->alt,
			),
			'primary'     => $primary && $primary->confidence >= Confidence::THRESHOLD_POSSIBLE ? $primary->to_array( $debug ) : null,
			'candidates'  => $visible,
			'weak'        => $hidden,
			'searched'    => $this->searched,
			'trace'       => array(
				'available' => $this->context->has_session(),
				'id'        => $debug ? $this->element->trace_id : null,
				'entries'   => $this->context->get_registry() ? $this->context->get_registry()->count() : 0,
			),
			'page'        => array(
				'title'      => $page['title'] ?? '',
				'url'        => $page['url'] ?? $this->element->page_url,
				'objectType' => $page['object_type'] ?? null,
				'objectId'   => $page['object_id'] ?? null,
				'postType'   => $page['post_type'] ?? null,
				'editUrl'    => $page['edit_url'] ?? null,
				'template'   => $page['template'] ?? null,
			),
			'notes'       => $this->context->get_notes(),
			'debug'       => $debug,
		);
	}
}
