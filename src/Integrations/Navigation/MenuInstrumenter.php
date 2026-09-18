<?php
/**
 * Instruments classic navigation menus.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Integrations\Navigation;

use EditTrace\Inspector\TraceRegistry;
use EditTrace\Inspector\TraceSession;
use EditTrace\Support\EditLinks;

/**
 * Records every classic menu item output by wp_nav_menu() and stamps its
 * link with an opaque trace id (data-edittrace-id + data-edittrace-kind="menu").
 */
final class MenuInstrumenter {

	private TraceRegistry $registry;

	/** @var array<string,array<string,mixed>> menu term id => info */
	private array $menus = array();

	public function __construct( TraceSession $session ) {
		$this->registry = $session->get_registry();
	}

	public function register_hooks(): void {
		add_filter( 'wp_nav_menu_objects', array( $this, 'on_menu_objects' ), 10, 2 );
		add_filter( 'nav_menu_link_attributes', array( $this, 'on_link_attributes' ), 999, 4 );
	}

	/**
	 * @param array<int,object> $items Menu items.
	 * @param object            $args  wp_nav_menu() args.
	 * @return array<int,object>
	 */
	public function on_menu_objects( $items, $args ) {
		$menu = isset( $args->menu ) ? $args->menu : null;
		if ( ! $menu instanceof \WP_Term && is_scalar( $menu ) && '' !== $menu ) {
			$menu = wp_get_nav_menu_object( $menu );
		}
		if ( $menu instanceof \WP_Term ) {
			$location = isset( $args->theme_location ) ? (string) $args->theme_location : '';
			$this->menus[ (string) $menu->term_id ] = array(
				'id'       => (int) $menu->term_id,
				'name'     => (string) $menu->name,
				'location' => $location,
			);
			if ( is_array( $items ) ) {
				foreach ( $items as $item ) {
					if ( is_object( $item ) && isset( $item->ID ) ) {
						$item->edittrace_menu_id = (int) $menu->term_id; // phpcs:ignore WordPress.NamingConventions
					}
				}
			}
		}
		return $items;
	}

	/**
	 * @param array<string,string> $atts      Link attributes.
	 * @param object               $menu_item Menu item.
	 * @param object               $args      Menu args.
	 * @param int                  $depth     Depth.
	 * @return array<string,string>
	 */
	public function on_link_attributes( $atts, $menu_item, $args, $depth ) {
		if ( ! is_array( $atts ) || ! is_object( $menu_item ) || empty( $menu_item->ID ) ) {
			return $atts;
		}
		$menu_id = isset( $menu_item->edittrace_menu_id ) ? (int) $menu_item->edittrace_menu_id : 0;
		$menu    = $this->menus[ (string) $menu_id ] ?? null;
		if ( null === $menu ) {
			$terms = wp_get_object_terms( (int) $menu_item->ID, 'nav_menu' );
			if ( is_array( $terms ) && isset( $terms[0] ) && $terms[0] instanceof \WP_Term ) {
				$menu = array(
					'id'       => (int) $terms[0]->term_id,
					'name'     => (string) $terms[0]->name,
					'location' => isset( $args->theme_location ) ? (string) $args->theme_location : '',
				);
			}
		}
		$locations = get_registered_nav_menus();
		$entry_id  = $this->registry->register(
			'menu_item',
			array(
				'item_id'        => (int) $menu_item->ID,
				'title'          => (string) ( $menu_item->title ?? '' ),
				'url'            => (string) ( $menu_item->url ?? '' ),
				'type'           => (string) ( $menu_item->type ?? '' ),
				'object'         => (string) ( $menu_item->object ?? '' ),
				'object_id'      => (int) ( $menu_item->object_id ?? 0 ),
				'depth'          => (int) $depth,
				'menu_id'        => $menu['id'] ?? 0,
				'menu_name'      => $menu['name'] ?? '',
				'location'       => $menu['location'] ?? '',
				'location_label' => isset( $menu['location'], $locations[ $menu['location'] ] ) ? (string) $locations[ $menu['location'] ] : '',
				'edit_url'       => ! empty( $menu['id'] ) ? EditLinks::classic_menu( (int) $menu['id'] ) : null,
			)
		);
		if ( null !== $entry_id ) {
			$atts['data-edittrace-id']   = $entry_id;
			$atts['data-edittrace-kind'] = 'menu';
		}
		return $atts;
	}
}
