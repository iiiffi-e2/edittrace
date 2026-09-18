<?php
/**
 * Elementor document access through public APIs.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Integrations\Elementor;

use EditTrace\Inspector\TraceSession;

/**
 * Thin, feature-detected wrapper around Elementor's documents manager.
 * Everything Elementor-specific that touches the runtime lives here so the
 * provider stays testable and Pro-only features degrade gracefully.
 */
final class Documents {

	private const THEME_LOCATIONS = array( 'header', 'footer', 'single', 'archive', 'search-results', 'error-404', 'popup', 'loop-item', 'product', 'product-archive', 'single-page', 'single-post' );

	public static function available(): bool {
		return class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance ) && isset( \Elementor\Plugin::$instance->documents );
	}

	/**
	 * Records the documents Elementor renders during this request.
	 */
	public static function register_render_hooks( TraceSession $session ): void {
		add_action(
			'elementor/frontend/before_get_builder_content',
			static function ( $document ) use ( $session ): void {
				if ( ! is_object( $document ) || ! method_exists( $document, 'get_main_id' ) ) {
					return;
				}
				$id = (int) $document->get_main_id();
				if ( $id <= 0 ) {
					return;
				}
				foreach ( $session->get_registry()->by_kind( 'elementor_document' ) as $entry ) {
					if ( (int) ( $entry['document_id'] ?? 0 ) === $id ) {
						return;
					}
				}
				$session->get_registry()->register(
					'elementor_document',
					array(
						'document_id' => $id,
						'type'        => method_exists( $document, 'get_name' ) ? (string) $document->get_name() : '',
					)
				);
			},
			10,
			1
		);
	}

	/**
	 * @return object|null Elementor\Core\Base\Document
	 */
	public static function get( int $post_id ): ?object {
		if ( ! self::available() || $post_id <= 0 ) {
			return null;
		}
		try {
			$document = \Elementor\Plugin::$instance->documents->get( $post_id );
		} catch ( \Throwable $e ) {
			return null;
		}
		return is_object( $document ) ? $document : null;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function elements( object $document ): array {
		if ( ! method_exists( $document, 'get_elements_data' ) ) {
			return array();
		}
		try {
			$data = $document->get_elements_data();
		} catch ( \Throwable $e ) {
			return array();
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Describes a document for display: title, type label, global status, edit URL.
	 *
	 * @return array<string,mixed>
	 */
	public static function describe( object $document ): array {
		$post      = method_exists( $document, 'get_post' ) ? $document->get_post() : null;
		$post_id   = $post instanceof \WP_Post ? (int) $post->ID : ( method_exists( $document, 'get_main_id' ) ? (int) $document->get_main_id() : 0 );
		$type_name = method_exists( $document, 'get_name' ) ? (string) $document->get_name() : '';
		$title     = $post instanceof \WP_Post ? (string) $post->post_title : '#' . $post_id;
		$post_type = $post instanceof \WP_Post ? (string) $post->post_type : '';
		$edit_url  = method_exists( $document, 'get_edit_url' ) ? (string) $document->get_edit_url() : '';

		$is_library = 'elementor_library' === $post_type;
		$location   = null;
		if ( method_exists( $document, 'get_location' ) ) {
			try {
				$loc      = $document->get_location();
				$location = is_string( $loc ) && '' !== $loc ? $loc : null;
			} catch ( \Throwable $e ) {
				$location = null;
			}
		}
		if ( null === $location && $is_library && in_array( $type_name, self::THEME_LOCATIONS, true ) ) {
			$location = $type_name;
		}

		$type_title = '';
		try {
			$type_title = is_callable( array( $document, 'get_title' ) ) ? (string) $document::get_title() : '';
		} catch ( \Throwable $e ) {
			$type_title = '';
		}
		if ( '' === $type_title ) {
			$type_title = ucwords( str_replace( array( '-', '_' ), ' ', $type_name ) );
		}

		if ( null !== $location ) {
			$system = __( 'Elementor Theme Builder', 'edittrace' );
			$label  = $type_title;
			$global = true;
			$note   = __( 'This Theme Builder template is used across multiple pages.', 'edittrace' );
		} elseif ( $is_library ) {
			$system = __( 'Elementor', 'edittrace' );
			/* translators: %s: template type, e.g. Section */
			$label  = sprintf( __( '%s Template', 'edittrace' ), $type_title );
			$global = true;
			$note   = __( 'This saved template can be inserted on multiple pages.', 'edittrace' );
		} else {
			$system = __( 'Elementor', 'edittrace' );
			$label  = '' !== $post_type ? \EditTrace\Support\EditLinks::post_type_label( $post_type ) : $type_title;
			$global = false;
			$note   = '';
		}

		return array(
			'id'          => $post_id,
			'title'       => $title,
			'type'        => $type_name,
			'type_title'  => $type_title,
			'post_type'   => $post_type,
			'system'      => $system,
			'label'       => $label,
			'global'      => $global,
			'global_note' => $note,
			'location'    => $location,
			'edit_url'    => '' !== $edit_url ? $edit_url : null,
			'is_library'  => $is_library,
		);
	}

	/**
	 * Title for an element type via Elementor's managers.
	 *
	 * @param array<string,mixed> $element Element data.
	 */
	public static function type_title( array $element ): string {
		if ( ! self::available() ) {
			return '';
		}
		try {
			if ( ( $element['elType'] ?? '' ) === 'widget' && ! empty( $element['widgetType'] ) ) {
				$name   = (string) preg_replace( '/\.[^.]+$/', '', (string) $element['widgetType'] );
				$widget = \Elementor\Plugin::$instance->widgets_manager->get_widget_types( $name );
				return $widget && method_exists( $widget, 'get_title' ) ? (string) $widget->get_title() : '';
			}
			$type = (string) ( $element['elType'] ?? '' );
			$el   = '' !== $type ? \Elementor\Plugin::$instance->elements_manager->get_element_types( $type ) : null;
			return $el && method_exists( $el, 'get_title' ) ? (string) $el->get_title() : '';
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Parses Elementor dynamic tag settings on a widget into readable info.
	 *
	 * @param array<string,mixed> $settings Widget settings.
	 * @return array<int,array{control:string,tag:string,settings:array<string,mixed>}>
	 */
	public static function dynamic_tags( array $settings ): array {
		$dynamic = isset( $settings['__dynamic__'] ) && is_array( $settings['__dynamic__'] ) ? $settings['__dynamic__'] : array();
		if ( empty( $dynamic ) || ! self::available() || ! isset( \Elementor\Plugin::$instance->dynamic_tags ) || ! method_exists( \Elementor\Plugin::$instance->dynamic_tags, 'tag_text_to_tag_data' ) ) {
			return array();
		}
		$out = array();
		foreach ( $dynamic as $control => $tag_text ) {
			if ( ! is_string( $tag_text ) ) {
				continue;
			}
			try {
				$data = \Elementor\Plugin::$instance->dynamic_tags->tag_text_to_tag_data( $tag_text );
			} catch ( \Throwable $e ) {
				continue;
			}
			if ( ! is_array( $data ) || empty( $data['name'] ) ) {
				continue;
			}
			$out[] = array(
				'control'  => (string) $control,
				'tag'      => (string) $data['name'],
				'settings' => isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : array(),
			);
		}
		return $out;
	}
}
