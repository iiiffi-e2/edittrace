<?php
/**
 * Describes ACF fields, groups and objects for display.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Integrations\ACF;

use EditTrace\Support\EditLinks;

/**
 * Uses public ACF functions (feature-detected) to turn a field + post_id
 * into human information: field group, owning object, edit destination.
 */
final class FieldInfo {

	public static function available(): bool {
		return function_exists( 'acf_get_field' ) && function_exists( 'acf_decode_post_id' );
	}

	/**
	 * Resolves the field group and the chain of parent fields.
	 *
	 * @param array<string,mixed> $field Field array.
	 * @return array{group:array<string,mixed>|null,ancestors:array<int,array<string,mixed>>}
	 */
	public static function lineage( array $field ): array {
		$ancestors = array();
		$group     = null;
		$parent    = $field['parent'] ?? '';
		$guard     = 0;
		while ( $parent && $guard++ < 10 ) {
			if ( function_exists( 'acf_is_field_group_key' ) && is_string( $parent ) && acf_is_field_group_key( $parent ) ) {
				$g = acf_get_field_group( $parent );
				if ( is_array( $g ) ) {
					$group = $g;
				}
				break;
			}
			$parent_field = function_exists( 'acf_get_field' ) ? acf_get_field( $parent ) : null;
			if ( ! is_array( $parent_field ) ) {
				// Numeric parent id that is a field group post.
				if ( is_numeric( $parent ) && function_exists( 'acf_get_field_group' ) ) {
					$g = acf_get_field_group( (int) $parent );
					if ( is_array( $g ) ) {
						$group = $g;
					}
				}
				break;
			}
			array_unshift( $ancestors, self::summarize_field( $parent_field ) );
			$parent = $parent_field['parent'] ?? '';
		}
		if ( null === $group && function_exists( 'acf_get_field_group' ) && ! empty( $field['key'] ) ) {
			// Some local fields expose the group directly.
			$g = acf_get_field_group( (string) ( $field['parent'] ?? '' ) );
			if ( is_array( $g ) ) {
				$group = $g;
			}
		}
		return array(
			'group'     => $group ? array(
				'key'   => (string) ( $group['key'] ?? '' ),
				'title' => (string) ( $group['title'] ?? '' ),
				'id'    => (int) ( $group['ID'] ?? 0 ),
			) : null,
			'ancestors' => $ancestors,
		);
	}

	/**
	 * @param array<string,mixed> $field Field.
	 * @return array{key:string,name:string,label:string,type:string}
	 */
	public static function summarize_field( array $field ): array {
		return array(
			'key'   => (string) ( $field['key'] ?? '' ),
			'name'  => (string) ( $field['name'] ?? '' ),
			'label' => (string) ( $field['label'] ?? ( $field['name'] ?? '' ) ),
			'type'  => (string) ( $field['type'] ?? '' ),
		);
	}

	/**
	 * Describes the object a value belongs to.
	 *
	 * @param mixed $post_id ACF post id (123, "option", "user_3", "term_9", "block_abc"...).
	 * @return array<string,mixed>
	 */
	public static function describe_object( $post_id, ?array $group = null ): array {
		$decoded = acf_decode_post_id( $post_id );
		$type    = (string) ( $decoded['type'] ?? '' );
		$id      = $decoded['id'] ?? '';

		switch ( $type ) {
			case 'post':
				$post = get_post( (int) $id );
				return array(
					'type'      => 'post',
					'id'        => (string) (int) $id,
					'title'     => $post ? (string) $post->post_title : '#' . $id,
					'label'     => $post ? EditLinks::post_type_label( $post->post_type ) : __( 'Post', 'edittrace' ),
					'post_type' => $post ? $post->post_type : '',
					'edit_url'  => $post ? EditLinks::post( (int) $post->ID ) : null,
					/* translators: %s: post type label */
					'edit_label' => sprintf( __( 'Edit %s', 'edittrace' ), $post ? EditLinks::post_type_label( $post->post_type ) : __( 'Post', 'edittrace' ) ),
					'global'    => false,
				);
			case 'term':
				$term = get_term( (int) $id );
				$tax  = $term instanceof \WP_Term ? get_taxonomy( $term->taxonomy ) : null;
				return array(
					'type'       => 'term',
					'id'         => (string) (int) $id,
					'title'      => $term instanceof \WP_Term ? $term->name : '#' . $id,
					'label'      => $tax ? (string) $tax->labels->singular_name : __( 'Term', 'edittrace' ),
					'edit_url'   => $term instanceof \WP_Term ? EditLinks::term( (int) $term->term_id, $term->taxonomy ) : null,
					'edit_label' => __( 'Edit Term', 'edittrace' ),
					'global'     => true,
				);
			case 'user':
				$user = get_user_by( 'id', (int) $id );
				return array(
					'type'       => 'user',
					'id'         => (string) (int) $id,
					'title'      => $user ? $user->display_name : '#' . $id,
					'label'      => __( 'User', 'edittrace' ),
					'edit_url'   => $user ? EditLinks::user( (int) $user->ID ) : null,
					'edit_label' => __( 'Edit User', 'edittrace' ),
					'global'     => false,
				);
			case 'option':
				return self::describe_options( (string) $post_id, $group );
			case 'block':
				$current = (int) get_the_ID();
				$post    = $current ? get_post( $current ) : null;
				return array(
					'type'       => 'block',
					'id'         => (string) $post_id,
					'title'      => $post ? (string) $post->post_title : __( 'ACF Block', 'edittrace' ),
					'label'      => __( 'ACF Block', 'edittrace' ),
					'edit_url'   => $post ? EditLinks::post( (int) $post->ID ) : null,
					'edit_label' => $post ? sprintf( __( 'Edit %s', 'edittrace' ), EditLinks::post_type_label( $post->post_type ) ) : __( 'Edit', 'edittrace' ),
					'global'     => false,
				);
			default:
				return array(
					'type'       => '' !== $type ? $type : 'unknown',
					'id'         => (string) $post_id,
					'title'      => (string) $post_id,
					'label'      => ucfirst( $type ),
					'edit_url'   => null,
					'edit_label' => __( 'Edit', 'edittrace' ),
					'global'     => false,
				);
		}
	}

	/**
	 * ACF options: find the options page from the field group's location rules.
	 *
	 * @param array<string,mixed>|null $group Field group.
	 * @return array<string,mixed>
	 */
	private static function describe_options( string $post_id, ?array $group ): array {
		$page_info = self::find_options_page( $post_id, $group );
		$title     = $page_info['title'] ?? __( 'Site Options', 'edittrace' );
		return array(
			'type'       => 'option',
			'id'         => $post_id,
			'title'      => $title,
			'label'      => __( 'Options Page', 'edittrace' ),
			'edit_url'   => $page_info['url'] ?? null,
			'edit_label' => __( 'Edit Options', 'edittrace' ),
			'global'     => true,
			'menu_slug'  => $page_info['slug'] ?? '',
		);
	}

	/**
	 * @param array<string,mixed>|null $group Field group.
	 * @return array{title:string,url:string,slug:string}|null
	 */
	public static function find_options_page( string $post_id, ?array $group ): ?array {
		if ( ! function_exists( 'acf_get_options_pages' ) ) {
			return null;
		}
		$pages = acf_get_options_pages();
		if ( ! is_array( $pages ) || empty( $pages ) ) {
			return null;
		}
		$slugs = array();
		$full  = is_array( $group ) && ! empty( $group['key'] ) && function_exists( 'acf_get_field_group' ) ? acf_get_field_group( (string) $group['key'] ) : $group;
		if ( is_array( $full ) && ! empty( $full['location'] ) && is_array( $full['location'] ) ) {
			foreach ( $full['location'] as $and_group ) {
				foreach ( (array) $and_group as $rule ) {
					if ( ( $rule['param'] ?? '' ) === 'options_page' && ( $rule['operator'] ?? '==' ) === '==' && ! empty( $rule['value'] ) ) {
						$slugs[] = (string) $rule['value'];
					}
				}
			}
		}
		$match = null;
		foreach ( $pages as $slug => $page ) {
			$page_post_id = (string) ( $page['post_id'] ?? 'options' );
			$page_slug    = (string) ( $page['menu_slug'] ?? $slug );
			if ( in_array( $page_slug, $slugs, true ) && $page_post_id === $post_id ) {
				$match = $page;
				break;
			}
		}
		if ( null === $match ) {
			$same = array_values( array_filter( $pages, static fn( $p ): bool => (string) ( $p['post_id'] ?? 'options' ) === $post_id ) );
			if ( 1 === count( $same ) ) {
				$match = $same[0];
			} elseif ( ! empty( $slugs ) ) {
				foreach ( $pages as $slug => $page ) {
					if ( in_array( (string) ( $page['menu_slug'] ?? $slug ), $slugs, true ) ) {
						$match = $page;
						break;
					}
				}
			}
		}
		if ( null === $match ) {
			return null;
		}
		$menu_slug = (string) ( $match['menu_slug'] ?? '' );
		$parent    = (string) ( $match['parent_slug'] ?? '' );
		if ( '' !== $parent && false !== strpos( $parent, '.php' ) ) {
			$url = add_query_arg( 'page', $menu_slug, admin_url( $parent ) );
		} else {
			$url = admin_url( 'admin.php?page=' . rawurlencode( $menu_slug ) );
		}
		return array(
			'title' => (string) ( $match['page_title'] ?? ( $match['menu_title'] ?? $menu_slug ) ),
			'url'   => $url,
			'slug'  => $menu_slug,
		);
	}

	/**
	 * Edit link for a field group definition (a legitimate secondary destination).
	 */
	public static function group_edit_url( ?array $group ): ?string {
		if ( ! is_array( $group ) || empty( $group['id'] ) ) {
			return null;
		}
		return EditLinks::post( (int) $group['id'] );
	}
}
