<?php
/**
 * Elementor provider.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Providers;

use EditTrace\Inspector\ElementContext;
use EditTrace\Inspector\SourceCandidate;
use EditTrace\Inspector\TraceContext;
use EditTrace\Inspector\TraceSession;
use EditTrace\Integrations\Elementor\Documents;
use EditTrace\Integrations\Elementor\ElementLocator;

/**
 * Maps Elementor's frontend element identifiers (data-id / data-element_type /
 * data-widget_type and the document wrapper's data-elementor-id) back to the
 * document and element that produced them, using Elementor's public document
 * API. Theme Builder / library documents are reported as global content.
 */
final class ElementorProvider extends AbstractProvider {

	public function get_name(): string {
		return 'elementor';
	}

	public function get_priority(): int {
		return 90;
	}

	public function register_render_hooks( TraceSession $session ): void {
		if ( Documents::available() ) {
			Documents::register_render_hooks( $session );
		}
	}

	public function get_dom_markers(): array {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return array();
		}
		return array(
			array(
				'selector'            => '.elementor-element[data-id]',
				'label'               => 'Elementor',
				'typeFromDataset'     => 'widget_type',
				'typeFallbackDataset' => 'element_type',
				'datasetKeys'         => array( 'id', 'element_type', 'widget_type' ),
				'requiredDatasetKeys' => array( 'id', 'element_type' ),
			),
			array(
				'selector'        => '[data-elementor-id]',
				'label'           => 'Elementor',
				'typeFromDataset' => 'elementorType',
				'datasetKeys'     => array( 'elementorId', 'elementorType' ),
				'requiredDatasetKeys' => array( 'elementorId' ),
			),
		);
	}

	public function supports( ElementContext $context, TraceContext $trace ): bool {
		return Documents::available() && ( null !== $this->nearest_element( $context ) || null !== $context->nearest_with_dataset( 'elementorId' ) );
	}

	public function resolve( ElementContext $context, TraceContext $trace ): array {
		$element_node = $this->nearest_element( $context );
		$doc_node     = $context->nearest_with_dataset( 'elementorId' );
		$dom_doc_id   = $doc_node ? (int) $doc_node['node']['dataset']['elementorId'] : 0;
		$element_id   = $element_node ? (string) $element_node['node']['dataset']['id'] : '';

		$candidates = array();

		if ( '' !== $element_id ) {
			$hit = $this->locate( $element_id, $dom_doc_id, $trace );
			if ( null !== $hit ) {
				$element_candidate = $this->element_candidate( $hit, $element_node['depth'] );
				$foreign           = $this->closer_foreign_marker_depth( $context, $trace, $element_node['depth'] );
				if ( null !== $foreign ) {
					$element_candidate->role = 'container';
					$element_candidate->with_confidence( 0.5, __( 'This Elementor element contains the clicked element, but the element is produced by another system inside it.', 'edittrace' ) );
				}
				$candidates[] = $element_candidate;
				$global       = $this->global_widget_candidate( $hit );
				if ( $global ) {
					$candidates[] = $global;
				}
				return $candidates;
			}
			$trace->note( sprintf( __( 'Elementor element %s was not found in the saved data of the documents rendered on this page.', 'edittrace' ), $element_id ) );
		}

		if ( $dom_doc_id > 0 ) {
			$document = Documents::get( $dom_doc_id );
			if ( $document ) {
				$info = Documents::describe( $document );
				$c    = $this->document_candidate( $info );
				$c->depth = (int) $doc_node['depth'];
				$c->with_confidence(
					'' !== $element_id ? 0.7 : 0.9,
					'' !== $element_id
						? __( 'The element belongs to this Elementor document, but its exact widget could not be located in the saved data.', 'edittrace' )
						: __( 'The clicked element is the Elementor document wrapper.', 'edittrace' )
				);
				$candidates[] = $c;
			}
		}
		return $candidates;
	}

	/**
	 * @return array{node:array<string,mixed>,depth:int}|null
	 */
	private function nearest_element( ElementContext $context ): ?array {
		foreach ( $context->chain() as $depth => $node ) {
			if ( ! empty( $node['dataset']['id'] ) && in_array( 'elementor-element', $node['classes'], true ) ) {
				return array(
					'node'  => $node,
					'depth' => $depth,
				);
			}
		}
		return null;
	}

	/**
	 * Tries likely documents first: the DOM document, then documents that
	 * rendered during the trace, then the queried object. Never scans all posts.
	 *
	 * @return array<string,mixed>|null
	 */
	private function locate( string $element_id, int $dom_doc_id, TraceContext $trace ): ?array {
		$ids = array();
		if ( $dom_doc_id > 0 ) {
			$ids[] = $dom_doc_id;
		}
		$registry = $trace->get_registry();
		if ( $registry ) {
			foreach ( $registry->by_kind( 'elementor_document' ) as $entry ) {
				$ids[] = (int) ( $entry['document_id'] ?? 0 );
			}
		}
		$page = $trace->get_page();
		if ( 'post' === ( $page['object_type'] ?? '' ) ) {
			$ids[] = (int) ( $page['object_id'] ?? 0 );
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );

		foreach ( array_slice( $ids, 0, 12 ) as $doc_id ) {
			$document = Documents::get( $doc_id );
			if ( ! $document ) {
				continue;
			}
			$elements = Documents::elements( $document );
			if ( empty( $elements ) ) {
				continue;
			}
			$found = ElementLocator::find( $elements, $element_id );
			if ( null !== $found ) {
				return array(
					'document'   => $document,
					'info'       => Documents::describe( $document ),
					'element'    => $found['element'],
					'ancestors'  => $found['ancestors'],
					'indexes'    => $found['indexes'],
					'exact_doc'  => $doc_id === $dom_doc_id,
					'element_id' => $element_id,
				);
			}
		}
		return null;
	}

	/**
	 * @param array<string,mixed> $hit Located element.
	 */
	private function element_candidate( array $hit, int $depth ): SourceCandidate {
		$info     = $hit['info'];
		$element  = $hit['element'];
		$el_type  = (string) ( $element['elType'] ?? 'element' );
		$type_key = ElementLocator::type_key( $element );
		$title    = Documents::type_title( $element );
		if ( '' === $title ) {
			$title = ucwords( str_replace( array( '-', '_' ), ' ', (string) preg_replace( '/\.default$/', '', $type_key ) ) );
		}
		$labels = ElementLocator::hierarchy( $hit['ancestors'], $element, array( Documents::class, 'type_title' ) );

		$c               = $this->candidate( 'widget' === $el_type ? 'elementor_widget' : 'elementor_element' );
		$c->system       = (string) $info['system'];
		$c->source_id    = (string) $info['id'];
		$c->source_name  = (string) $info['title'];
		$c->source_label = (string) $info['label'];
		$c->item_label   = 'widget' === $el_type ? __( 'Widget', 'edittrace' ) : ucfirst( $el_type );
		$c->item_name    = $title;
		$c->item_key     = $type_key;
		$c->hierarchy    = array_merge( array( (string) $info['title'] ), $labels );
		$c->edit_url     = $info['edit_url'];
		$c->edit_label   = __( 'Edit in Elementor', 'edittrace' );
		$c->role         = 'structure';
		$c->depth        = $depth;
		$c->technical    = array(
			'documentId'   => (int) $info['id'],
			'documentType' => (string) $info['type'],
			'elementId'    => (string) $hit['element_id'],
			'elementType'  => $el_type,
			'widgetType'   => (string) ( $element['widgetType'] ?? '' ),
			'elementPath'  => implode( '.', array_map( 'intval', $hit['indexes'] ) ),
		);
		if ( ! empty( $info['location'] ) ) {
			$c->details[ __( 'Location', 'edittrace' ) ] = ucwords( str_replace( '-', ' ', (string) $info['location'] ) );
		}
		if ( ! empty( $info['global'] ) ) {
			$c->mark_global( (string) $info['global_note'] );
		}
		$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
		foreach ( Documents::dynamic_tags( $settings ) as $tag ) {
			$c->details[ sprintf( __( 'Dynamic "%s"', 'edittrace' ), $tag['control'] ) ] = $this->describe_tag( $tag );
		}

		$c->with_confidence(
			! empty( $hit['exact_doc'] ) ? 1.0 : 0.95,
			! empty( $hit['exact_doc'] )
				? ( $depth > 0
					? __( 'The clicked element is inside this Elementor element; its id was matched in the document data.', 'edittrace' )
					: __( 'Elementor element id matched in the document data.', 'edittrace' ) )
				: __( 'The element id was found in another Elementor document rendered on this page.', 'edittrace' )
		);
		return $c;
	}

	/**
	 * Elementor Pro global widgets point at a library template; report it.
	 *
	 * @param array<string,mixed> $hit Located element.
	 */
	private function global_widget_candidate( array $hit ): ?SourceCandidate {
		$element = $hit['element'];
		if ( 'global' !== (string) ( $element['widgetType'] ?? '' ) ) {
			return null;
		}
		$settings    = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
		$template_id = (int) ( $settings['templateID'] ?? 0 );
		$document    = $template_id > 0 ? Documents::get( $template_id ) : null;
		if ( ! $document ) {
			return null;
		}
		$info = Documents::describe( $document );
		$c    = $this->document_candidate( $info );
		$c->source_type = 'elementor_global_widget';
		$c->item_label  = __( 'Global Widget', 'edittrace' );
		$c->item_name   = (string) $info['title'];
		$c->mark_global( __( 'This global widget is reused wherever it is placed. Editing it changes every instance.', 'edittrace' ) );
		$c->with_confidence( 1.0, __( 'The clicked widget is an Elementor global widget.', 'edittrace' ) );
		return $c;
	}

	/**
	 * @param array<string,mixed> $info Document description.
	 */
	private function document_candidate( array $info ): SourceCandidate {
		$c               = $this->candidate( 'elementor_document' );
		$c->system       = (string) $info['system'];
		$c->source_id    = (string) $info['id'];
		$c->source_name  = (string) $info['title'];
		$c->source_label = (string) $info['label'];
		$c->item_label   = __( 'Document', 'edittrace' );
		$c->item_name    = (string) $info['type_title'];
		$c->item_key     = (string) $info['type'];
		$c->hierarchy    = array( (string) $info['title'] );
		$c->edit_url     = $info['edit_url'];
		$c->edit_label   = __( 'Edit in Elementor', 'edittrace' );
		$c->role         = 'structure';
		$c->technical    = array(
			'documentId'   => (int) $info['id'],
			'documentType' => (string) $info['type'],
		);
		if ( ! empty( $info['global'] ) ) {
			$c->mark_global( (string) $info['global_note'] );
		}
		return $c;
	}

	/**
	 * @param array{control:string,tag:string,settings:array<string,mixed>} $tag Tag data.
	 */
	private function describe_tag( array $tag ): string {
		$name = $tag['tag'];
		if ( 0 === strpos( $name, 'acf-' ) && ! empty( $tag['settings']['key'] ) && is_string( $tag['settings']['key'] ) ) {
			$parts = explode( ':', $tag['settings']['key'], 2 );
			$label = $parts[1] ?? $parts[0];
			if ( function_exists( 'acf_get_field' ) ) {
				$field = acf_get_field( $parts[0] );
				if ( is_array( $field ) && ! empty( $field['label'] ) ) {
					$label = (string) $field['label'];
				}
			}
			return sprintf( __( 'ACF field "%s"', 'edittrace' ), $label );
		}
		return sprintf( __( 'Dynamic tag "%s"', 'edittrace' ), str_replace( '-', ' ', $name ) );
	}
}
