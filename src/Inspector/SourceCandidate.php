<?php
/**
 * A possible source for the clicked element.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Inspector;

/**
 * Standardized value object returned by providers.
 */
final class SourceCandidate {

	public string $provider = '';
	/** e.g. gutenberg_block, elementor_widget, acf_field, attachment, menu_item */
	public string $source_type = '';
	/** Stable identifier of the source object (post id, element id, field key...). */
	public string $source_id = '';
	/** Human name of the container: "Homepage", "Site Options", "Main Site Header". */
	public string $source_name = '';
	/** Human label of the container type: "Page", "Template Part", "Options Page". */
	public string $source_label = '';
	/** What kind of item within the container: "Widget", "Block", "Field", "Menu Item". */
	public string $item_label = '';
	/** Human item name: "Button", "Phone Number", "Services". */
	public string $item_name = '';
	/** Machine item name: core/button, heading.default, phone_number. */
	public string $item_key = '';
	/** Human system name: "Gutenberg", "Elementor", "Advanced Custom Fields". */
	public string $system = '';
	/** @var string[] Human readable breadcrumb: Homepage → Hero → Button. */
	public array $hierarchy = array();
	public float $confidence = 0.0;
	public ?string $edit_url = null;
	public string $edit_label = '';
	/** @var array<int,array{label:string,url:string}> */
	public array $actions = array();
	public bool $global = false;
	public string $global_note = '';
	/** Role of this candidate relative to the element: structure|content|media|container. */
	public string $role = 'content';
	/** Short explanation of the evidence used. */
	public string $reason = '';
	/** @var array<string,mixed> Technical metadata (never sensitive values). */
	public array $technical = array();
	/** @var array<string,mixed> Usage information (counts, locations). */
	public array $usage = array();
	/** @var array<string,string> Extra display rows: label => value. */
	public array $details = array();

	public static function make( string $provider, string $source_type ): self {
		$c              = new self();
		$c->provider    = $provider;
		$c->source_type = $source_type;
		return $c;
	}

	public function with_confidence( float $score, string $reason = '' ): self {
		$this->confidence = Confidence::clamp( $score );
		if ( '' !== $reason ) {
			$this->reason = $reason;
		}
		return $this;
	}

	public function add_action( string $label, string $url ): self {
		if ( '' !== $url ) {
			$this->actions[] = array(
				'label' => $label,
				'url'   => $url,
			);
		}
		return $this;
	}

	public function mark_global( string $note = '' ): self {
		$this->global      = true;
		$this->global_note = '' !== $note ? $note : __( 'Changes here may affect multiple pages.', 'edittrace' );
		return $this;
	}

	/**
	 * Key used to de-duplicate candidates.
	 */
	public function identity(): string {
		return implode( '|', array( $this->provider, $this->source_type, $this->source_id, $this->item_key ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array( bool $debug = false ): array {
		$data = array(
			'provider'     => $this->provider,
			'system'       => $this->system,
			'sourceType'   => $this->source_type,
			'sourceId'     => $this->source_id,
			'sourceName'   => $this->source_name,
			'sourceLabel'  => $this->source_label,
			'itemLabel'    => $this->item_label,
			'itemName'     => $this->item_name,
			'itemKey'      => $this->item_key,
			'hierarchy'    => array_values( $this->hierarchy ),
			'confidence'   => $this->confidence,
			'status'       => Confidence::status( $this->confidence ),
			'statusLabel'  => Confidence::label( $this->confidence ),
			'editUrl'      => $this->edit_url,
			'editLabel'    => $this->edit_label,
			'actions'      => $this->actions,
			'global'       => $this->global,
			'globalNote'   => $this->global_note,
			'role'         => $this->role,
			'reason'       => $this->reason,
			'details'      => (object) $this->details,
			'usage'        => (object) $this->usage,
			'technical'    => $this->technical,
		);
		if ( ! $debug ) {
			// Without debug mode keep the technical block small but useful.
			$data['technical'] = array_intersect_key(
				$this->technical,
				array_flip( array( 'postId', 'documentId', 'elementId', 'blockName', 'blockPath', 'fieldKey', 'objectId', 'template', 'traceEntry', 'attachmentId', 'menuId', 'itemId', 'widgetType', 'elementType', 'documentType', 'match' ) )
			);
		}
		return $data;
	}
}
