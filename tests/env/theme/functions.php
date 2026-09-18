<?php
/**
 * EditTrace test theme: PHP-rendered blocks that output ACF fields, a
 * classic menu, an Elementor library template and plain static HTML.
 */

declare( strict_types=1 );

add_action(
	'init',
	static function (): void {
		register_block_type(
			'edittrace-test/acf-options',
			array(
				'render_callback' => static function (): string {
					if ( ! function_exists( 'get_field' ) ) {
						return '';
					}
					$phone = get_field( 'company_phone', 'option' );
					$link  = get_field( 'company_cta', 'option' );
					$html  = '<div class="acf-options-block">';
					if ( $phone ) {
						$html .= '<a class="company-phone" href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', (string) $phone ) ) . '">' . esc_html( (string) $phone ) . '</a>';
					}
					if ( is_array( $link ) && ! empty( $link['url'] ) ) {
						$html .= ' <a class="company-cta" href="' . esc_url( $link['url'] ) . '">' . esc_html( $link['title'] ?? '' ) . '</a>';
					}
					return $html . '</div>';
				},
			)
		);

		register_block_type(
			'edittrace-test/acf-hero',
			array(
				'render_callback' => static function (): string {
					if ( ! function_exists( 'get_field' ) ) {
						return '';
					}
					$post_id  = get_the_ID();
					$heading  = get_field( 'hero_heading', $post_id );
					$intro    = get_field( 'hero_intro', $post_id );
					$cta      = get_field( 'hero_cta', $post_id );
					$image    = get_field( 'hero_image', $post_id );
					$html     = '<section class="acf-hero">';
					if ( $heading ) {
						$html .= '<h2 class="acf-hero-heading">' . esc_html( (string) $heading ) . '</h2>';
					}
					if ( $intro ) {
						$html .= '<div class="acf-hero-intro">' . wp_kses_post( (string) $intro ) . '</div>';
					}
					if ( is_array( $cta ) && ! empty( $cta['url'] ) ) {
						$html .= '<a class="acf-hero-cta" href="' . esc_url( $cta['url'] ) . '"><span>' . esc_html( $cta['title'] ?? '' ) . '</span></a>';
					}
					if ( is_array( $image ) && ! empty( $image['ID'] ) ) {
						$html .= wp_get_attachment_image( (int) $image['ID'], 'medium', false, array( 'class' => 'acf-hero-image' ) );
					}
					if ( function_exists( 'have_rows' ) && have_rows( 'hero_features', $post_id ) ) {
						$html .= '<ul class="acf-hero-features">';
						while ( have_rows( 'hero_features', $post_id ) ) {
							the_row();
							$html .= '<li class="acf-hero-feature">' . esc_html( (string) get_sub_field( 'feature_label' ) ) . '</li>';
						}
						$html .= '</ul>';
					}
					return $html . '</section>';
				},
			)
		);

		register_block_type(
			'edittrace-test/classic-menu',
			array(
				'render_callback' => static function (): string {
					return (string) wp_nav_menu(
						array(
							'theme_location' => 'primary',
							'container'      => 'nav',
							'menu_class'     => 'classic-menu',
							'echo'           => false,
							'fallback_cb'    => '__return_empty_string',
						)
					);
				},
			)
		);

		register_block_type(
			'edittrace-test/elementor-header',
			array(
				'render_callback' => static function (): string {
					$id = (int) get_option( 'edittrace_test_elementor_header_id' );
					if ( ! $id || ! class_exists( '\Elementor\Plugin' ) ) {
						return '';
					}
					return '<div class="elementor-header-area">' . \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $id ) . '</div>';
				},
			)
		);

		register_block_type(
			'edittrace-test/static-html',
			array(
				'render_callback' => static function (): string {
					// Deliberately untraceable content: hard-coded in the theme.
					return '<p class="static-theme-text">Hard-coded theme text with no database source</p>';
				},
			)
		);

		register_nav_menus( array( 'primary' => 'Primary Navigation' ) );
	}
);

add_action(
	'after_setup_theme',
	static function (): void {
		add_theme_support( 'post-thumbnails' );
		add_theme_support( 'menus' );
	}
);
