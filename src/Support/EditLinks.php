<?php
/**
 * Builds legitimate admin edit URLs.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Support;

/**
 * Only documented/core-generated URL shapes are used here. When a deep link
 * does not exist, the containing editor is returned instead.
 */
final class EditLinks {

	public static function post( int $post_id ): ?string {
		$url = get_edit_post_link( $post_id, 'raw' );
		return $url ? $url : null;
	}

	/**
	 * Site editor link for a template or template part. Uses the post-based
	 * link when the item is stored in the database, otherwise the same URL
	 * shape core's admin bar uses for theme-file templates.
	 */
	public static function block_template( string $id, string $type = 'wp_template' ): ?string {
		$template = function_exists( 'get_block_template' ) ? get_block_template( $id, $type ) : null;
		if ( $template && ! empty( $template->wp_id ) ) {
			$url = get_edit_post_link( (int) $template->wp_id, 'raw' );
			if ( $url ) {
				return $url;
			}
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return null;
		}
		return add_query_arg(
			array(
				'postType' => $type,
				'postId'   => $id,
				'canvas'   => 'edit',
			),
			admin_url( 'site-editor.php' )
		);
	}

	public static function navigation_post( int $post_id ): ?string {
		return self::post( $post_id );
	}

	public static function classic_menu( int $menu_id ): ?string {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return null;
		}
		return add_query_arg(
			array(
				'action' => 'edit',
				'menu'   => $menu_id,
			),
			admin_url( 'nav-menus.php' )
		);
	}

	public static function widgets(): ?string {
		return current_user_can( 'edit_theme_options' ) ? admin_url( 'widgets.php' ) : null;
	}

	public static function attachment( int $attachment_id ): ?string {
		return self::post( $attachment_id );
	}

	public static function term( int $term_id, string $taxonomy ): ?string {
		$url = get_edit_term_link( $term_id, $taxonomy );
		return is_string( $url ) && '' !== $url ? $url : null;
	}

	public static function user( int $user_id ): ?string {
		$url = get_edit_user_link( $user_id );
		return '' !== $url ? $url : null;
	}

	/**
	 * Human label for a post type.
	 */
	public static function post_type_label( string $post_type ): string {
		$object = get_post_type_object( $post_type );
		if ( $object && ! empty( $object->labels->singular_name ) ) {
			return (string) $object->labels->singular_name;
		}
		return ucwords( str_replace( array( '_', '-' ), ' ', $post_type ) );
	}
}
