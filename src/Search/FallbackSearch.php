<?php
/**
 * Bounded database search for content that no provider could trace.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Search;

use EditTrace\Integrations\Elementor\Documents;
use EditTrace\Integrations\Elementor\ElementLocator;
use EditTrace\Support\EditLinks;

/**
 * Targeted, limited queries over posts, post meta (including Elementor
 * data), options and terms. Exact matches are tried before partial ones.
 * All SQL goes through $wpdb->prepare(); values are never echoed back.
 */
final class FallbackSearch {

	public const MAX_NEEDLE = 200;
	public const MIN_NEEDLE = 3;
	public const TIME_BUDGET = 2.5; // seconds

	private const SENSITIVE_OPTION_PATTERN = '/(secret|token|password|passwd|salt|nonce|api_key|apikey|private|license|licence|auth|credential|_key$|session)/i';

	private const EXCLUDED_POST_TYPES = array( 'revision', 'nav_menu_item', 'oembed_cache', 'customize_changeset', 'user_request', 'wp_global_styles', 'attachment', 'acf-field', 'acf-field-group' );

	private int $limit;

	private float $started = 0.0;

	private int $queries = 0;

	public function __construct( int $limit = 10 ) {
		$this->limit = max( 1, min( 10, $limit ) );
	}

	/**
	 * Runs the search. Returns raw hits; the provider turns them into candidates.
	 *
	 * @param array{text:string,href:string,src:string,alt:string,current_post:int} $input Search input.
	 * @return array<int,array<string,mixed>>
	 */
	public function run( array $input ): array {
		$this->started = microtime( true );
		$this->queries = 0;
		$hits          = array();

		$needles = $this->needles( $input );
		if ( empty( $needles ) ) {
			return array();
		}

		foreach ( $needles as $needle ) {
			if ( $this->out_of_budget() ) {
				break;
			}
			$hits = array_merge( $hits, $this->search_posts( $needle, (int) $input['current_post'] ) );
			$hits = array_merge( $hits, $this->search_postmeta( $needle ) );
			$hits = array_merge( $hits, $this->search_elementor( $needle ) );
			$hits = array_merge( $hits, $this->search_options( $needle ) );
			$hits = array_merge( $hits, $this->search_terms( $needle ) );
		}

		// De-duplicate by target, keep best score.
		$unique = array();
		foreach ( $hits as $hit ) {
			$key = $hit['kind'] . ':' . $hit['id'] . ':' . ( $hit['field'] ?? '' );
			if ( ! isset( $unique[ $key ] ) || $unique[ $key ]['score'] < $hit['score'] ) {
				$unique[ $key ] = $hit;
			}
		}
		$hits = array_values( $unique );
		usort( $hits, static fn( array $a, array $b ): int => $b['score'] <=> $a['score'] );
		return array_slice( $hits, 0, $this->limit );
	}

	/**
	 * @param array{text:string,href:string,src:string,alt:string,current_post:int} $input Input.
	 * @return array<int,array{value:string,kind:string}>
	 */
	private function needles( array $input ): array {
		$needles = array();
		$text    = TextNormalizer::plain( $input['text'] ?? '', self::MAX_NEEDLE + 1 );
		if ( '' !== $text && ! IgnoredStrings::is_ignored( $text ) && TextNormalizer::length( $text ) >= self::MIN_NEEDLE && TextNormalizer::length( $text ) <= self::MAX_NEEDLE ) {
			$needles[] = array(
				'value' => $text,
				'kind'  => 'text',
			);
		}
		$alt = TextNormalizer::plain( $input['alt'] ?? '', self::MAX_NEEDLE + 1 );
		if ( '' !== $alt && ! IgnoredStrings::is_ignored( $alt ) && TextNormalizer::length( $alt ) >= self::MIN_NEEDLE ) {
			$needles[] = array(
				'value' => $alt,
				'kind'  => 'text',
			);
		}
		foreach ( array( 'href', 'src' ) as $key ) {
			$url = (string) ( $input[ $key ] ?? '' );
			if ( '' === $url ) {
				continue;
			}
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			if ( 'src' === $key ) {
				$path = \EditTrace\Support\Url::strip_size_suffix( $path );
			}
			if ( strlen( $path ) >= 4 && '/' !== $path ) {
				$needles[] = array(
					'value' => $path,
					'kind'  => 'url',
				);
			}
		}
		return array_slice( $needles, 0, 3 );
	}

	private function out_of_budget(): bool {
		return ( microtime( true ) - $this->started ) > self::TIME_BUDGET || $this->queries >= 14;
	}

	/**
	 * @return string[]
	 */
	private function post_types(): array {
		$types = get_post_types( array( 'public' => true ), 'names' );
		$types = array_merge( array_values( $types ), array( 'wp_template', 'wp_template_part', 'wp_block', 'wp_navigation', 'elementor_library' ) );
		return array_values( array_unique( array_diff( $types, self::EXCLUDED_POST_TYPES ) ) );
	}

	/**
	 * @param array{value:string,kind:string} $needle Needle.
	 * @return array<int,array<string,mixed>>
	 */
	private function search_posts( array $needle, int $current_post ): array {
		global $wpdb;
		$types = $this->post_types();
		if ( empty( $types ) ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$hits         = array();

		if ( 'text' === $needle['kind'] ) {
			++$this->queries;
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT ID, post_title, post_type FROM {$wpdb->posts} WHERE post_status IN ('publish','private','draft','future','pending') AND post_type IN ($placeholders) AND post_title = %s LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					array_merge( $types, array( $needle['value'], $this->limit ) )
				)
			);
			foreach ( (array) $rows as $row ) {
				$hits[] = $this->post_hit( $row, 0.85, __( 'The post title equals the clicked text.', 'edittrace' ), 'title', $current_post );
			}
		}
		if ( $this->out_of_budget() ) {
			return $hits;
		}
		++$this->queries;
		$like = '%' . $wpdb->esc_like( $needle['value'] ) . '%';
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT ID, post_title, post_type FROM {$wpdb->posts} WHERE post_status IN ('publish','private','draft','future','pending') AND post_type IN ($placeholders) AND post_content LIKE %s ORDER BY post_modified DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( $types, array( $like, $this->limit ) )
			)
		);
		foreach ( (array) $rows as $row ) {
			$hits[] = $this->post_hit( $row, 'url' === $needle['kind'] ? 0.5 : 0.55, __( 'The value appears in this content.', 'edittrace' ), 'content', $current_post );
		}
		return $hits;
	}

	/**
	 * @param object $row Row.
	 * @return array<string,mixed>
	 */
	private function post_hit( $row, float $score, string $reason, string $field, int $current_post ): array {
		$post_id = (int) $row->ID;
		if ( $post_id === $current_post ) {
			$score += 0.1;
		}
		$global = in_array( $row->post_type, array( 'wp_template', 'wp_template_part', 'wp_block', 'wp_navigation', 'elementor_library' ), true );
		return array(
			'kind'       => 'post',
			'id'         => $post_id,
			'field'      => $field,
			'title'      => (string) $row->post_title,
			'label'      => EditLinks::post_type_label( (string) $row->post_type ),
			'post_type'  => (string) $row->post_type,
			'score'      => min( 0.95, $score ),
			'reason'     => $reason,
			'edit_url'   => EditLinks::post( $post_id ),
			'global'     => $global,
		);
	}

	/**
	 * @param array{value:string,kind:string} $needle Needle.
	 * @return array<int,array<string,mixed>>
	 */
	private function search_postmeta( array $needle ): array {
		global $wpdb;
		$hits = array();
		if ( $this->out_of_budget() ) {
			return $hits;
		}
		++$this->queries;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_key, p.post_title, p.post_type FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key NOT LIKE %s AND pm.meta_value = %s AND p.post_status <> 'trash' LIMIT %d",
				$wpdb->esc_like( '_' ) . '%',
				$needle['value'],
				$this->limit
			)
		);
		foreach ( (array) $rows as $row ) {
			$hits[] = $this->meta_hit( $row, 0.85, __( 'A custom field on this item has exactly this value.', 'edittrace' ) );
		}
		if ( 'text' === $needle['kind'] && TextNormalizer::length( $needle['value'] ) >= 8 && ! $this->out_of_budget() ) {
			++$this->queries;
			$like = '%' . $wpdb->esc_like( $needle['value'] ) . '%';
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT pm.post_id, pm.meta_key, p.post_title, p.post_type FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key NOT LIKE %s AND pm.meta_value LIKE %s AND p.post_status <> 'trash' LIMIT %d",
					$wpdb->esc_like( '_' ) . '%',
					$like,
					$this->limit
				)
			);
			foreach ( (array) $rows as $row ) {
				$hits[] = $this->meta_hit( $row, 0.55, __( 'A custom field on this item contains this value.', 'edittrace' ) );
			}
		}
		return $hits;
	}

	/**
	 * @param object $row Row.
	 * @return array<string,mixed>
	 */
	private function meta_hit( $row, float $score, string $reason ): array {
		$post_id  = (int) $row->post_id;
		$meta_key = (string) $row->meta_key;
		$field    = $meta_key;
		$acf      = null;
		if ( function_exists( 'acf_get_field' ) ) {
			// ACF stores the field key in a "_{name}" sibling; resolve the label when possible.
			$field_key = get_post_meta( $post_id, '_' . $meta_key, true );
			if ( is_string( $field_key ) && 0 === strpos( $field_key, 'field_' ) ) {
				$acf = acf_get_field( $field_key );
				if ( is_array( $acf ) && ! empty( $acf['label'] ) ) {
					$field = (string) $acf['label'];
				}
			}
		}
		return array(
			'kind'      => 'postmeta',
			'id'        => $post_id,
			'field'     => $meta_key,
			'title'     => (string) $row->post_title,
			'label'     => EditLinks::post_type_label( (string) $row->post_type ),
			'post_type' => (string) $row->post_type,
			'item'      => $field,
			'acf'       => is_array( $acf ),
			'score'     => $score,
			'reason'    => $reason,
			'edit_url'  => EditLinks::post( $post_id ),
			'global'    => false,
		);
	}

	/**
	 * Elementor stores JSON in _elementor_data; search the JSON-encoded needle.
	 *
	 * @param array{value:string,kind:string} $needle Needle.
	 * @return array<int,array<string,mixed>>
	 */
	private function search_elementor( array $needle ): array {
		global $wpdb;
		if ( ! Documents::available() || $this->out_of_budget() ) {
			return array();
		}
		$encoded = wp_json_encode( $needle['value'], JSON_UNESCAPED_UNICODE );
		$encoded = is_string( $encoded ) ? trim( $encoded, '"' ) : $needle['value'];
		++$this->queries;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT pm.post_id, p.post_title, p.post_type FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_elementor_data' AND pm.meta_value LIKE %s AND p.post_status <> 'trash' LIMIT %d",
				'%' . $wpdb->esc_like( $encoded ) . '%',
				$this->limit
			)
		);
		$hits = array();
		foreach ( (array) $rows as $row ) {
			$post_id  = (int) $row->post_id;
			$document = Documents::get( $post_id );
			if ( ! $document ) {
				continue;
			}
			$info   = Documents::describe( $document );
			$value  = TextNormalizer::normalize( $needle['value'] );
			$found  = ElementLocator::search(
				Documents::elements( $document ),
				static function ( $setting, string $key ) use ( $value, $needle ): bool {
					if ( ! is_string( $setting ) || '' === $setting ) {
						return false;
					}
					if ( 'url' === $needle['kind'] ) {
						return false !== stripos( $setting, $needle['value'] );
					}
					$normalized = TextNormalizer::normalize( $setting );
					return $normalized === $value || ( TextNormalizer::length( $value ) >= 8 && false !== strpos( $normalized, $value ) );
				},
				array(),
				0,
				2
			);
			if ( empty( $found ) ) {
				continue;
			}
			foreach ( $found as $hit ) {
				$element = $hit['element'];
				$labels  = ElementLocator::hierarchy( $hit['ancestors'], $element, array( Documents::class, 'type_title' ) );
				$exact   = 'url' !== $needle['kind'] && TextNormalizer::normalize( (string) self::setting_value( $element, $hit['setting'] ) ) === $value;
				$hits[]  = array(
					'kind'        => 'elementor',
					'id'          => $post_id,
					'field'       => (string) ( $element['id'] ?? '' ),
					'title'       => (string) $info['title'],
					'label'       => (string) $info['label'],
					'system'      => (string) $info['system'],
					'post_type'   => (string) $row->post_type,
					'item'        => end( $labels ) ?: '',
					'item_key'    => ElementLocator::type_key( $element ),
					'hierarchy'   => array_merge( array( (string) $info['title'] ), $labels ),
					'element_id'  => (string) ( $element['id'] ?? '' ),
					'score'       => $exact ? 0.9 : 0.6,
					'reason'      => $exact ? __( 'An Elementor widget setting equals this value.', 'edittrace' ) : __( 'An Elementor widget setting contains this value.', 'edittrace' ),
					'edit_url'    => $info['edit_url'],
					'global'      => (bool) $info['global'],
					'global_note' => (string) $info['global_note'],
				);
			}
		}
		return $hits;
	}

	/**
	 * @param array<string,mixed> $element Element.
	 * @return mixed
	 */
	private static function setting_value( array $element, string $path ) {
		$value = $element['settings'] ?? array();
		foreach ( explode( '.', $path ) as $part ) {
			if ( ! is_array( $value ) || ! array_key_exists( $part, $value ) ) {
				return null;
			}
			$value = $value[ $part ];
		}
		return $value;
	}

	/**
	 * @param array{value:string,kind:string} $needle Needle.
	 * @return array<int,array<string,mixed>>
	 */
	private function search_options( array $needle ): array {
		global $wpdb;
		$hits = array();
		if ( $this->out_of_budget() ) {
			return $hits;
		}
		++$this->queries;
		$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_value = %s LIMIT %d",
				$wpdb->esc_like( '_transient' ) . '%',
				$wpdb->esc_like( '_site_transient' ) . '%',
				$needle['value'],
				5
			)
		);
		foreach ( (array) $rows as $name ) {
			$hit = $this->option_hit( (string) $name, 0.8, __( 'A WordPress option has exactly this value.', 'edittrace' ) );
			if ( $hit ) {
				$hits[] = $hit;
			}
		}
		if ( 'text' === $needle['kind'] && TextNormalizer::length( $needle['value'] ) >= 8 && ! $this->out_of_budget() ) {
			++$this->queries;
			$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s AND LENGTH(option_value) < 200000 AND option_value LIKE %s LIMIT %d",
					$wpdb->esc_like( '_transient' ) . '%',
					$wpdb->esc_like( '_site_transient' ) . '%',
					'%' . $wpdb->esc_like( $needle['value'] ) . '%',
					5
				)
			);
			foreach ( (array) $rows as $name ) {
				$hit = $this->option_hit( (string) $name, 0.5, __( 'A WordPress option contains this value (possibly inside serialized settings).', 'edittrace' ) );
				if ( $hit ) {
					$hits[] = $hit;
				}
			}
		}
		return $hits;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function option_hit( string $name, float $score, string $reason ): ?array {
		if ( preg_match( self::SENSITIVE_OPTION_PATTERN, $name ) ) {
			return null;
		}
		$core = array(
			'blogname'        => array( __( 'Site Title', 'edittrace' ), 'options-general.php' ),
			'blogdescription' => array( __( 'Tagline', 'edittrace' ), 'options-general.php' ),
			'admin_email'     => array( __( 'Administration Email', 'edittrace' ), 'options-general.php' ),
			'siteurl'         => array( __( 'WordPress Address', 'edittrace' ), 'options-general.php' ),
			'home'            => array( __( 'Site Address', 'edittrace' ), 'options-general.php' ),
		);
		$title    = $name;
		$edit_url = null;
		$system   = __( 'WordPress Options', 'edittrace' );
		$label    = __( 'Option', 'edittrace' );
		$acf_name = '';
		if ( isset( $core[ $name ] ) ) {
			$title    = $core[ $name ][0];
			$edit_url = current_user_can( 'manage_options' ) ? admin_url( $core[ $name ][1] ) : null;
			$system   = __( 'WordPress Settings', 'edittrace' );
			$label    = __( 'Setting', 'edittrace' );
		} elseif ( 0 === strpos( $name, 'options_' ) && function_exists( 'acf_get_field' ) ) {
			$field_key = get_option( '_' . $name );
			if ( is_string( $field_key ) && 0 === strpos( $field_key, 'field_' ) ) {
				$field = acf_get_field( $field_key );
				if ( is_array( $field ) ) {
					$title    = (string) ( $field['label'] ?? $name );
					$acf_name = (string) ( $field['name'] ?? '' );
					$system   = __( 'Advanced Custom Fields', 'edittrace' );
					$label    = __( 'Options Field', 'edittrace' );
					$lineage  = \EditTrace\Integrations\ACF\FieldInfo::lineage( $field );
					$page     = \EditTrace\Integrations\ACF\FieldInfo::find_options_page( 'options', $lineage['group'] );
					$edit_url = $page['url'] ?? null;
				}
			}
		} elseif ( 0 === strpos( $name, 'theme_mods_' ) ) {
			$title    = __( 'Theme Settings (Customizer)', 'edittrace' );
			$edit_url = current_user_can( 'edit_theme_options' ) ? admin_url( 'customize.php' ) : null;
			$system   = __( 'Theme Options', 'edittrace' );
			$label    = __( 'Theme Mods', 'edittrace' );
		} elseif ( 0 === strpos( $name, 'widget_' ) ) {
			$title    = __( 'Widgets', 'edittrace' );
			$edit_url = EditLinks::widgets();
			$system   = __( 'Widgets', 'edittrace' );
			$label    = __( 'Widget Settings', 'edittrace' );
		}
		return array(
			'kind'     => 'option',
			'id'       => $name,
			'field'    => '',
			'title'    => $title,
			'label'    => $label,
			'system'   => $system,
			'item'     => '' !== $acf_name ? $acf_name : $name,
			'score'    => $score,
			'reason'   => $reason,
			'edit_url' => $edit_url,
			'global'   => true,
		);
	}

	/**
	 * @param array{value:string,kind:string} $needle Needle.
	 * @return array<int,array<string,mixed>>
	 */
	private function search_terms( array $needle ): array {
		global $wpdb;
		if ( 'text' !== $needle['kind'] || $this->out_of_budget() ) {
			return array();
		}
		++$this->queries;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT t.term_id, t.name, tt.taxonomy FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE t.name = %s LIMIT %d",
				$needle['value'],
				5
			)
		);
		$hits = array();
		foreach ( (array) $rows as $row ) {
			$tax = get_taxonomy( (string) $row->taxonomy );
			if ( ! $tax || ! $tax->show_ui ) {
				continue;
			}
			$hits[] = array(
				'kind'     => 'term',
				'id'       => (int) $row->term_id,
				'field'    => (string) $row->taxonomy,
				'title'    => (string) $row->name,
				'label'    => (string) $tax->labels->singular_name,
				'score'    => 0.7,
				'reason'   => __( 'A term name equals the clicked text.', 'edittrace' ),
				'edit_url' => EditLinks::term( (int) $row->term_id, (string) $row->taxonomy ),
				'global'   => true,
			);
		}
		return $hits;
	}
}
