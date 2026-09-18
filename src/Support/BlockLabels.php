<?php
/**
 * Human names for blocks.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Support;

use WP_Block_Type_Registry;

/**
 * Turns block names/attributes into labels a site owner recognises.
 */
final class BlockLabels {

	/**
	 * Title of a block type, e.g. core/button → "Button".
	 */
	public static function title( string $block_name ): string {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( $block_name );
		if ( $type && ! empty( $type->title ) ) {
			return (string) $type->title;
		}
		$short = (string) preg_replace( '#^[^/]+/#', '', $block_name );
		return ucwords( str_replace( array( '-', '_' ), ' ', $short ) );
	}

	/**
	 * Label for one block occurrence, taking user-provided names into account.
	 *
	 * @param array<string,mixed> $attrs Block attributes.
	 */
	public static function instance_label( string $block_name, array $attrs, int $index ): string {
		if ( ! empty( $attrs['metadata']['name'] ) && is_string( $attrs['metadata']['name'] ) ) {
			return $attrs['metadata']['name'];
		}
		switch ( $block_name ) {
			case 'core/column':
				return sprintf( 'Column %d', $index + 1 );
			case 'core/navigation-link':
			case 'core/navigation-submenu':
				return ! empty( $attrs['label'] ) ? wp_strip_all_tags( (string) $attrs['label'] ) : self::title( $block_name );
			case 'core/heading':
				return 'Heading' . ( ! empty( $attrs['level'] ) ? ' (H' . (int) $attrs['level'] . ')' : '' );
			case 'core/template-part':
				return ! empty( $attrs['slug'] ) ? 'Template Part: ' . ucfirst( (string) $attrs['slug'] ) : 'Template Part';
			default:
				return self::title( $block_name );
		}
	}
}
