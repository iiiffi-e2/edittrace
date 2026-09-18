<?php
/**
 * Instruments the block render lifecycle for authorized trace sessions.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Integrations\Gutenberg;

use EditTrace\Inspector\TraceRegistry;
use EditTrace\Inspector\TraceSession;
use EditTrace\Support\BlockLabels;
use EditTrace\Support\EditLinks;
use WP_Block;
use WP_HTML_Tag_Processor;

/**
 * Registers every rendered block in the trace registry (with its owning
 * source: post, template, template part, synced pattern, navigation menu,
 * pattern or widget area) and stamps the block root with an opaque
 * data-edittrace-id attribute. Only hooked for authorized users.
 *
 * Mechanism: `render_block_data` runs before a block renders and receives
 * the parent WP_Block, so we can build the hierarchy top-down; the frame id
 * is stored as a private top-level key on the parsed block array (never in
 * attributes, so it can never leak into output). `render_block` runs after
 * rendering and is where the attribute is injected.
 */
final class BlockInstrumenter {

	public const KEY  = '__edittrace';
	public const ATTR = 'data-edittrace-id';

	private const SOURCE_BLOCKS = array(
		'core/template-part' => 'wp_template_part',
		'core/block'         => 'wp_block',
		'core/navigation'    => 'wp_navigation',
		'core/post-content'  => 'post',
		'core/pattern'       => 'pattern',
	);

	private TraceRegistry $registry;

	/** @var array<string,array<string,mixed>> frame id => frame */
	private array $frames = array();

	/** @var array<int,string> spl_object_id => frame id (for blocks rendered outside render_block_data) */
	private array $object_frames = array();

	/** @var array<int,array<string,mixed>> stack of source frames */
	private array $sources = array();

	private int $root_counter = 0;

	public function __construct( TraceSession $session ) {
		$this->registry = $session->get_registry();
	}

	public function register_hooks(): void {
		add_filter( 'render_block_data', array( $this, 'on_render_block_data' ), PHP_INT_MAX, 3 );
		add_filter( 'render_block', array( $this, 'on_render_block' ), PHP_INT_MAX, 3 );
	}

	/**
	 * @param array<string,mixed> $parsed_block Parsed block.
	 * @param array<string,mixed> $source_block Unfiltered parsed block.
	 * @param WP_Block|null       $parent_block Parent block instance.
	 * @return array<string,mixed>
	 */
	public function on_render_block_data( $parsed_block, $source_block, $parent_block ) {
		if ( ! is_array( $parsed_block ) || empty( $parsed_block['blockName'] ) ) {
			return $parsed_block;
		}
		$parent_frame = $parent_block instanceof WP_Block ? $this->frame_for_instance( $parent_block ) : null;
		$frame        = $this->create_frame( $parsed_block, $parent_frame );
		if ( null !== $frame ) {
			$parsed_block[ self::KEY ] = $frame['id'];
		}
		return $parsed_block;
	}

	/**
	 * @param string              $content  Rendered content.
	 * @param array<string,mixed> $block    Parsed block.
	 * @param WP_Block|null       $instance Block instance.
	 */
	public function on_render_block( $content, $block, $instance = null ) {
		if ( ! is_array( $block ) || empty( $block['blockName'] ) ) {
			return $content;
		}
		$frame = null;
		if ( isset( $block[ self::KEY ] ) && isset( $this->frames[ $block[ self::KEY ] ] ) ) {
			$frame = $this->frames[ $block[ self::KEY ] ];
		} elseif ( $instance instanceof WP_Block ) {
			$frame = $this->frame_for_instance( $instance );
		}
		if ( null === $frame ) {
			return $content;
		}

		if ( ! empty( $frame['pushed_source'] ) ) {
			$this->pop_source( $frame['pushed_source'] );
		}

		if ( ! is_string( $content ) || '' === trim( $content ) || empty( $frame['entry'] ) ) {
			return $content;
		}
		return self::stamp( $content, (string) $frame['entry'] );
	}

	/**
	 * Adds the trace attribute to the first tag unless it already carries one.
	 */
	public static function stamp( string $content, string $entry_id ): string {
		if ( ! class_exists( WP_HTML_Tag_Processor::class ) ) {
			return $content;
		}
		$processor = new WP_HTML_Tag_Processor( $content );
		if ( ! $processor->next_tag() ) {
			return $content;
		}
		if ( null !== $processor->get_attribute( self::ATTR ) ) {
			return $content;
		}
		$processor->set_attribute( self::ATTR, $entry_id );
		return $processor->get_updated_html();
	}

	/**
	 * Frame for a WP_Block that already went through render_block_data, or
	 * an ad-hoc frame for blocks rendered directly (navigation items).
	 *
	 * @return array<string,mixed>|null
	 */
	private function frame_for_instance( WP_Block $instance ): ?array {
		$parsed = $instance->parsed_block;
		if ( isset( $parsed[ self::KEY ], $this->frames[ $parsed[ self::KEY ] ] ) ) {
			return $this->frames[ $parsed[ self::KEY ] ];
		}
		$oid = spl_object_id( $instance );
		if ( isset( $this->object_frames[ $oid ], $this->frames[ $this->object_frames[ $oid ] ] ) ) {
			return $this->frames[ $this->object_frames[ $oid ] ];
		}
		if ( empty( $parsed['blockName'] ) ) {
			return null;
		}
		$frame = $this->create_frame( $parsed, null );
		if ( null !== $frame ) {
			$this->object_frames[ $oid ] = $frame['id'];
		}
		return $frame;
	}

	/**
	 * @param array<string,mixed>      $parsed_block Parsed block.
	 * @param array<string,mixed>|null $parent       Parent frame.
	 * @return array<string,mixed>|null
	 */
	private function create_frame( array $parsed_block, ?array $parent ): ?array {
		$name  = (string) $parsed_block['blockName'];
		$attrs = isset( $parsed_block['attrs'] ) && is_array( $parsed_block['attrs'] ) ? $parsed_block['attrs'] : array();

		if ( null !== $parent && ! empty( $parent['pushed_source'] ) ) {
			// Children of a source-bearing block (synced pattern) belong to the new source.
			$source = $parent['pushed_source'];
			$path   = array();
			$labels = array();
		} elseif ( null !== $parent ) {
			$source = $parent['source'];
			$path   = $parent['path'];
			$labels = $parent['labels'];
		} else {
			$source = $this->current_source();
			$path   = array();
			$labels = array();
		}

		$index = $this->next_index( $parent, $source );
		$path[]   = $index;
		$labels[] = BlockLabels::instance_label( $name, $attrs, $index );

		$frame_id = 'f' . ( count( $this->frames ) + 1 );
		$frame    = array(
			'id'            => $frame_id,
			'name'          => $name,
			'source'        => $source,
			'path'          => $path,
			'labels'        => $labels,
			'children'      => 0,
			'pushed_source' => null,
			'entry'         => null,
		);

		if ( isset( self::SOURCE_BLOCKS[ $name ] ) ) {
			$pushed = $this->describe_source_block( $name, $attrs, $source );
			if ( null !== $pushed ) {
				$pushed['counter']       = 0;
				$frame['pushed_source']  = $pushed;
				$this->sources[]         = $pushed;
			}
		}

		$entry = $this->registry->register(
			'block',
			array(
				'name'   => $name,
				'title'  => BlockLabels::title( $name ),
				'path'   => $path,
				'labels' => $labels,
				'source' => self::public_source( $source ),
				'attrs'  => self::safe_attrs( $name, $attrs ),
			)
		);
		$frame['entry']            = $entry;
		$this->frames[ $frame_id ] = $frame;
		return $frame;
	}

	/**
	 * @param array<string,mixed>|null $parent Parent frame.
	 * @param array<string,mixed>      $source Owning source.
	 */
	private function next_index( ?array $parent, array $source ): int {
		if ( null !== $parent ) {
			$index = $this->frames[ $parent['id'] ]['children']++;
			if ( ! empty( $parent['pushed_source'] ) ) {
				// Reset numbering inside the pushed source.
				$last = count( $this->sources ) - 1;
				if ( $last >= 0 && $this->sources[ $last ]['uid'] === $parent['pushed_source']['uid'] ) {
					return $this->sources[ $last ]['counter']++;
				}
			}
			return $index;
		}
		$last = count( $this->sources ) - 1;
		if ( $last >= 0 && isset( $source['uid'] ) && $this->sources[ $last ]['uid'] === $source['uid'] ) {
			return $this->sources[ $last ]['counter']++;
		}
		return $this->root_counter++;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function current_source(): array {
		$last = count( $this->sources ) - 1;
		if ( $last >= 0 ) {
			return $this->sources[ $last ];
		}
		return $this->root_source();
	}

	/**
	 * Determines what a top-level block belongs to when no source block is on the stack.
	 *
	 * @return array<string,mixed>
	 */
	private function root_source(): array {
		global $_wp_current_template_id;

		if ( doing_filter( 'the_content' ) || in_the_loop() ) {
			$post_id = (int) get_the_ID();
			if ( $post_id > 0 ) {
				return $this->post_source( $post_id );
			}
		}
		if ( doing_action( 'dynamic_sidebar' ) ) {
			return array(
				'uid'      => 'widgets',
				'type'     => 'widget',
				'id'       => '',
				'title'    => __( 'Widget Area', 'edittrace' ),
				'label'    => __( 'Widget Area', 'edittrace' ),
				'edit_url' => EditLinks::widgets(),
				'global'   => true,
			);
		}
		if ( ! empty( $_wp_current_template_id ) && function_exists( 'get_block_template' ) ) {
			$template = get_block_template( (string) $_wp_current_template_id, 'wp_template' );
			return array(
				'uid'      => 'wp_template:' . $_wp_current_template_id,
				'type'     => 'wp_template',
				'id'       => (string) $_wp_current_template_id,
				'title'    => $template && ! empty( $template->title ) ? (string) $template->title : (string) $_wp_current_template_id,
				'label'    => __( 'Template', 'edittrace' ),
				'edit_url' => EditLinks::block_template( (string) $_wp_current_template_id, 'wp_template' ),
				'global'   => true,
				'wp_id'    => $template ? (int) $template->wp_id : 0,
			);
		}
		$post_id = (int) get_the_ID();
		if ( $post_id > 0 ) {
			return $this->post_source( $post_id );
		}
		return array(
			'uid'      => 'unknown',
			'type'     => 'unknown',
			'id'       => '',
			'title'    => __( 'Unknown context', 'edittrace' ),
			'label'    => __( 'Unknown', 'edittrace' ),
			'edit_url' => null,
			'global'   => false,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function post_source( int $post_id ): array {
		$post = get_post( $post_id );
		return array(
			'uid'       => 'post:' . $post_id,
			'type'      => 'post',
			'id'        => (string) $post_id,
			'title'     => $post ? (string) $post->post_title : '#' . $post_id,
			'post_type' => $post ? $post->post_type : '',
			'label'     => $post ? EditLinks::post_type_label( $post->post_type ) : __( 'Post', 'edittrace' ),
			'edit_url'  => EditLinks::post( $post_id ),
			'global'    => false,
		);
	}

	/**
	 * Describes the source introduced by a source-bearing block.
	 *
	 * @param array<string,mixed> $attrs   Block attributes.
	 * @param array<string,mixed> $current Current source (for post-content fallbacks).
	 * @return array<string,mixed>|null
	 */
	private function describe_source_block( string $name, array $attrs, array $current ): ?array {
		switch ( $name ) {
			case 'core/template-part':
				$slug  = isset( $attrs['slug'] ) ? (string) $attrs['slug'] : '';
				$theme = ! empty( $attrs['theme'] ) ? (string) $attrs['theme'] : get_stylesheet();
				if ( '' === $slug ) {
					return null;
				}
				$id       = $theme . '//' . $slug;
				$template = function_exists( 'get_block_template' ) ? get_block_template( $id, 'wp_template_part' ) : null;
				return array(
					'uid'      => 'wp_template_part:' . $id,
					'type'     => 'wp_template_part',
					'id'       => $id,
					'title'    => $template && ! empty( $template->title ) ? (string) $template->title : ucfirst( $slug ),
					'label'    => __( 'Template Part', 'edittrace' ),
					'area'     => $template->area ?? ( $attrs['area'] ?? '' ),
					'edit_url' => EditLinks::block_template( $id, 'wp_template_part' ),
					'global'   => true,
					'wp_id'    => $template ? (int) $template->wp_id : 0,
				);
			case 'core/block':
				$ref = isset( $attrs['ref'] ) ? (int) $attrs['ref'] : 0;
				if ( $ref <= 0 ) {
					return null;
				}
				$post = get_post( $ref );
				return array(
					'uid'      => 'wp_block:' . $ref,
					'type'     => 'wp_block',
					'id'       => (string) $ref,
					'title'    => $post ? (string) $post->post_title : '#' . $ref,
					'label'    => __( 'Synced Pattern', 'edittrace' ),
					'edit_url' => EditLinks::post( $ref ),
					'global'   => true,
				);
			case 'core/navigation':
				$ref = isset( $attrs['ref'] ) ? (int) $attrs['ref'] : 0;
				if ( $ref <= 0 && class_exists( '\WP_Navigation_Fallback' ) && ! isset( $attrs['__unstableLocation'] ) ) {
					$fallback = \WP_Navigation_Fallback::get_fallback();
					if ( $fallback instanceof \WP_Post ) {
						$ref = (int) $fallback->ID;
					}
				}
				if ( $ref <= 0 ) {
					return array(
						'uid'      => 'navigation:inline',
						'type'     => 'wp_navigation',
						'id'       => '',
						'title'    => __( 'Navigation', 'edittrace' ),
						'label'    => __( 'Navigation Menu', 'edittrace' ),
						'edit_url' => $current['edit_url'] ?? null,
						'global'   => false,
					);
				}
				$post = get_post( $ref );
				return array(
					'uid'      => 'wp_navigation:' . $ref,
					'type'     => 'wp_navigation',
					'id'       => (string) $ref,
					'title'    => $post ? (string) $post->post_title : '#' . $ref,
					'label'    => __( 'Navigation Menu', 'edittrace' ),
					'edit_url' => EditLinks::navigation_post( $ref ),
					'global'   => true,
				);
			case 'core/post-content':
				$post_id = (int) get_the_ID();
				return $post_id > 0 ? $this->post_source( $post_id ) : null;
			case 'core/pattern':
				$slug = isset( $attrs['slug'] ) ? (string) $attrs['slug'] : '';
				if ( '' === $slug || ! class_exists( '\WP_Block_Patterns_Registry' ) ) {
					return null;
				}
				$pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered( $slug );
				return array(
					'uid'      => 'pattern:' . $slug,
					'type'     => 'pattern',
					'id'       => $slug,
					'title'    => $pattern && ! empty( $pattern['title'] ) ? (string) $pattern['title'] : $slug,
					'label'    => __( 'Pattern', 'edittrace' ),
					'edit_url' => null,
					'global'   => true,
				);
		}
		return null;
	}

	/**
	 * @param array<string,mixed> $source Source frame to pop.
	 */
	private function pop_source( array $source ): void {
		for ( $i = count( $this->sources ) - 1; $i >= 0; $i-- ) {
			if ( $this->sources[ $i ]['uid'] === $source['uid'] ) {
				array_splice( $this->sources, $i );
				return;
			}
		}
	}

	/**
	 * Strips internal counters before storing a source in the registry.
	 *
	 * @param array<string,mixed> $source Source.
	 * @return array<string,mixed>
	 */
	private static function public_source( array $source ): array {
		unset( $source['counter'] );
		return $source;
	}

	/**
	 * Keeps a small, non-sensitive subset of attributes for later matching.
	 *
	 * @param array<string,mixed> $attrs Block attributes.
	 * @return array<string,mixed>
	 */
	private static function safe_attrs( string $name, array $attrs ): array {
		$keep = array( 'ref', 'id', 'slug', 'theme', 'area', 'level', 'url', 'label', 'kind', 'type', 'sizeSlug', 'tagName' );
		$out  = array();
		foreach ( $keep as $key ) {
			if ( isset( $attrs[ $key ] ) && is_scalar( $attrs[ $key ] ) ) {
				$out[ $key ] = is_string( $attrs[ $key ] ) ? substr( $attrs[ $key ], 0, 300 ) : $attrs[ $key ];
			}
		}
		if ( ! empty( $attrs['metadata']['name'] ) && is_string( $attrs['metadata']['name'] ) ) {
			$out['name'] = substr( $attrs['metadata']['name'], 0, 100 );
		}
		unset( $name );
		return $out;
	}
}
