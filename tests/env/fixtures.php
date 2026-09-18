<?php
/**
 * Test-site fixtures: pages, Elementor documents, ACF fields, menus and media
 * that the PHPUnit integration tests and Playwright E2E tests rely on.
 *
 * Idempotent: re-running updates the same objects (looked up by slug).
 */
declare( strict_types=1 );

function edittrace_fixture_page( string $slug, string $title, string $content, array $extra = array() ): int {
	$existing = get_page_by_path( $slug, OBJECT, 'page' );
	$args     = array_merge(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_name'    => $slug,
			'post_title'   => $title,
			'post_content' => $content,
		),
		$extra
	);
	if ( $existing ) {
		$args['ID'] = $existing->ID;
		return (int) wp_update_post( $args );
	}
	return (int) wp_insert_post( $args );
}

function edittrace_fixture_attachment( string $filename, string $title ): int {
	$existing = get_posts(
		array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'title'       => $title,
			'numberposts' => 1,
			'fields'      => 'ids',
		)
	);
	if ( $existing ) {
		return (int) $existing[0];
	}
	$upload = wp_upload_dir();
	$path   = trailingslashit( $upload['path'] ) . $filename;
	$img    = imagecreatetruecolor( 800, 500 );
	$bg     = imagecolorallocate( $img, 34, 84, 160 );
	$fg     = imagecolorallocate( $img, 255, 255, 255 );
	imagefill( $img, 0, 0, $bg );
	imagestring( $img, 5, 300, 240, 'EditTrace hero', $fg );
	imagejpeg( $img, $path, 85 );
	imagedestroy( $img );

	$id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => $title,
			'post_status'    => 'inherit',
		),
		$path
	);
	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );
	update_post_meta( $id, '_wp_attachment_image_alt', 'Hero image alt text' );
	return (int) $id;
}

function edittrace_install_fixtures(): void {
	switch_theme( 'edittrace-test-theme' );

	$hero_id  = edittrace_fixture_attachment( 'hero.jpg', 'hero' );
	$hero_url = wp_get_attachment_url( $hero_id );

	// Synced pattern (wp_block).
	$pattern = get_posts(
		array(
			'post_type'   => 'wp_block',
			'name'        => 'shared-promo',
			'numberposts' => 1,
		)
	);
	$pattern_content = '<!-- wp:paragraph {"className":"shared-promo-text"} --><p class="shared-promo-text">Shared promo text from a synced pattern</p><!-- /wp:paragraph -->';
	if ( $pattern ) {
		$pattern_id = (int) $pattern[0]->ID;
		wp_update_post(
			array(
				'ID'           => $pattern_id,
				'post_content' => $pattern_content,
			)
		);
	} else {
		$pattern_id = (int) wp_insert_post(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_name'    => 'shared-promo',
				'post_title'   => 'Shared Promo',
				'post_content' => $pattern_content,
			)
		);
	}

	// Homepage (Gutenberg).
	$homepage = <<<HTML
<!-- wp:heading {"level":1,"className":"home-heading"} -->
<h1 class="wp-block-heading home-heading">Welcome to EditTrace</h1>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"home-intro"} -->
<p class="home-intro">This paragraph lives in the homepage content.</p>
<!-- /wp:paragraph -->
<!-- wp:group {"metadata":{"name":"Hero"},"className":"home-hero","layout":{"type":"constrained"}} -->
<div class="wp-block-group home-hero">
<!-- wp:columns -->
<div class="wp-block-columns">
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:image {"id":{$hero_id},"sizeSlug":"medium","className":"home-image"} -->
<figure class="wp-block-image size-medium home-image"><img src="{$hero_url}" alt="Hero image alt text" class="wp-image-{$hero_id}"/></figure>
<!-- /wp:image -->
</div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:buttons -->
<div class="wp-block-buttons">
<!-- wp:button {"className":"home-cta"} -->
<div class="wp-block-button home-cta"><a class="wp-block-button__link wp-element-button" href="/contact/"><span>Request a Demo</span></a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->
<!-- wp:block {"ref":{$pattern_id}} /-->
HTML;
	$home_id = edittrace_fixture_page( 'homepage', 'Homepage', $homepage );
	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', $home_id );

	$contact_id = edittrace_fixture_page( 'contact', 'Contact', '<!-- wp:paragraph --><p>Contact page paragraph text.</p><!-- /wp:paragraph -->' );
	$services_id = edittrace_fixture_page( 'services', 'Services', '<!-- wp:paragraph --><p>Services page paragraph text.</p><!-- /wp:paragraph -->' );

	// ACF demo page.
	$acf_id = edittrace_fixture_page( 'acf-demo', 'ACF Demo', '<!-- wp:edittrace-test/acf-hero /-->' );
	if ( function_exists( 'update_field' ) ) {
		update_field( 'field_edittrace_hero_heading', 'Custom Fields Power This Heading', $acf_id );
		update_field( 'field_edittrace_hero_intro', '<p>The <strong>intro copy</strong> is a WYSIWYG field.</p><p>Second paragraph of the intro.</p>', $acf_id );
		update_field(
			'field_edittrace_hero_cta',
			array(
				'title'  => 'Book a Consultation',
				'url'    => home_url( '/contact/' ),
				'target' => '',
			),
			$acf_id
		);
		update_field( 'field_edittrace_hero_image', $hero_id, $acf_id );
		update_field( 'field_edittrace_company_phone', '(972) 555-0192', 'option' );
		update_field(
			'field_edittrace_company_cta',
			array(
				'title'  => 'Talk to Sales',
				'url'    => home_url( '/services/' ),
				'target' => '',
			),
			'option'
		);
	}

	// Elementor page.
	$elementor_data = wp_json_encode(
		array(
			array(
				'id'       => 'a1b2c3d',
				'elType'   => 'container',
				'settings' => array( '_title' => 'Hero Container' ),
				'elements' => array(
					array(
						'id'       => 'b2c3d4e',
						'elType'   => 'widget',
						'widgetType' => 'heading',
						'settings' => array(
							'title'       => 'Elementor Hero Heading',
							'header_size' => 'h2',
						),
						'elements' => array(),
					),
					array(
						'id'       => 'c3d4e5f',
						'elType'   => 'widget',
						'widgetType' => 'text-editor',
						'settings' => array( 'editor' => '<p>Elementor text editor paragraph copy.</p>' ),
						'elements' => array(),
					),
					array(
						'id'       => 'd4e5f6a',
						'elType'   => 'container',
						'settings' => array( '_title' => 'CTA Container' ),
						'elements' => array(
							array(
								'id'       => 'e5f6a7b',
								'elType'   => 'widget',
								'widgetType' => 'button',
								'settings' => array(
									'text' => 'Get Started Today',
									'link' => array( 'url' => home_url( '/contact/' ) ),
								),
								'elements' => array(),
							),
							array(
								'id'       => 'f6a7b8c',
								'elType'   => 'widget',
								'widgetType' => 'image',
								'settings' => array(
									'image' => array(
										'id'  => $hero_id,
										'url' => $hero_url,
									),
								),
								'elements' => array(),
							),
						),
					),
				),
			),
		)
	);
	$el_id = edittrace_fixture_page( 'elementor-landing', 'Elementor Landing', '' );
	update_post_meta( $el_id, '_elementor_edit_mode', 'builder' );
	update_post_meta( $el_id, '_elementor_template_type', 'wp-page' );
	update_post_meta( $el_id, '_elementor_version', '3.35.9' );
	update_post_meta( $el_id, '_elementor_data', wp_slash( $elementor_data ) );

	// Elementor "header" library template (Theme Builder simulation).
	$header = get_posts(
		array(
			'post_type'   => 'elementor_library',
			'name'        => 'main-site-header',
			'numberposts' => 1,
			'post_status' => 'any',
		)
	);
	$header_id = $header ? (int) $header[0]->ID : (int) wp_insert_post(
		array(
			'post_type'   => 'elementor_library',
			'post_status' => 'publish',
			'post_name'   => 'main-site-header',
			'post_title'  => 'Main Site Header',
		)
	);
	$header_data = wp_json_encode(
		array(
			array(
				'id'       => '1a2b3c4',
				'elType'   => 'container',
				'settings' => array( '_title' => 'Header Container' ),
				'elements' => array(
					array(
						'id'       => '2b3c4d5',
						'elType'   => 'widget',
						'widgetType' => 'heading',
						'settings' => array(
							'title'       => 'Global Header Heading',
							'header_size' => 'div',
						),
						'elements' => array(),
					),
					array(
						'id'       => '3c4d5e6',
						'elType'   => 'widget',
						'widgetType' => 'button',
						'settings' => array(
							'text' => 'Header CTA Button',
							'link' => array( 'url' => home_url( '/services/' ) ),
						),
						'elements' => array(),
					),
				),
			),
		)
	);
	update_post_meta( $header_id, '_elementor_edit_mode', 'builder' );
	update_post_meta( $header_id, '_elementor_template_type', 'header' );
	update_post_meta( $header_id, '_elementor_version', '3.35.9' );
	update_post_meta( $header_id, '_elementor_data', wp_slash( $header_data ) );
	wp_set_object_terms( $header_id, 'header', 'elementor_library_type' );
	update_option( 'edittrace_test_elementor_header_id', $header_id );

	// Classic menu.
	$menu = wp_get_nav_menu_object( 'Primary Navigation' );
	if ( ! $menu ) {
		$menu_id = (int) wp_create_nav_menu( 'Primary Navigation' );
		wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'     => 'Our Services',
				'menu-item-object'    => 'page',
				'menu-item-object-id' => $services_id,
				'menu-item-type'      => 'post_type',
				'menu-item-status'    => 'publish',
			)
		);
		wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'  => 'Pricing Plans',
				'menu-item-url'    => 'https://example.com/pricing/',
				'menu-item-type'   => 'custom',
				'menu-item-status' => 'publish',
			)
		);
	} else {
		$menu_id = (int) $menu->term_id;
	}
	set_theme_mod( 'nav_menu_locations', array( 'primary' => $menu_id ) );

	// Block navigation (wp_navigation).
	$nav = get_posts(
		array(
			'post_type'   => 'wp_navigation',
			'name'        => 'main-navigation',
			'numberposts' => 1,
			'post_status' => 'any',
		)
	);
	$nav_content = '<!-- wp:navigation-link {"label":"About Us","url":"/about-us/","kind":"custom","className":"nav-about"} /--><!-- wp:navigation-link {"label":"Contact","type":"page","id":' . $contact_id . ',"url":"' . get_permalink( $contact_id ) . '","kind":"post-type"} /-->';
	if ( $nav ) {
		$nav_id = (int) $nav[0]->ID;
		wp_update_post(
			array(
				'ID'           => $nav_id,
				'post_content' => $nav_content,
			)
		);
	} else {
		$nav_id = (int) wp_insert_post(
			array(
				'post_type'    => 'wp_navigation',
				'post_status'  => 'publish',
				'post_name'    => 'main-navigation',
				'post_title'   => 'Main Navigation',
				'post_content' => $nav_content,
			)
		);
	}

	// Customised header template part (stored in the DB) that references the navigation post.
	$theme = get_stylesheet();
	$part  = get_posts(
		array(
			'post_type'   => 'wp_template_part',
			'name'        => 'header',
			'numberposts' => 1,
			'post_status' => 'any',
			'tax_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy' => 'wp_theme',
					'field'    => 'name',
					'terms'    => $theme,
				),
			),
		)
	);
	$part_content = '<!-- wp:group {"tagName":"header","className":"site-header","layout":{"type":"flex","justifyContent":"space-between"}} -->
<header class="wp-block-group site-header">
<!-- wp:site-title /-->
<!-- wp:paragraph {"className":"header-tagline"} -->
<p class="header-tagline">Header tagline from the template part</p>
<!-- /wp:paragraph -->
<!-- wp:navigation {"ref":' . $nav_id . ',"className":"block-navigation"} /-->
<!-- wp:edittrace-test/classic-menu /-->
</header>
<!-- /wp:group -->
<!-- wp:edittrace-test/elementor-header /-->';
	if ( $part ) {
		wp_update_post(
			array(
				'ID'           => $part[0]->ID,
				'post_content' => $part_content,
			)
		);
	} else {
		$part_id = (int) wp_insert_post(
			array(
				'post_type'    => 'wp_template_part',
				'post_status'  => 'publish',
				'post_name'    => 'header',
				'post_title'   => 'Header',
				'post_content' => $part_content,
				'tax_input'    => array(
					'wp_theme'              => array( $theme ),
					'wp_template_part_area' => array( 'header' ),
				),
			)
		);
		wp_set_post_terms( $part_id, $theme, 'wp_theme' );
		wp_set_post_terms( $part_id, 'header', 'wp_template_part_area' );
	}

	update_option( 'edittrace_test_footer_text', 'Footer disclaimer stored in a plain option' );

	update_option( 'edittrace_test_ids', compact( 'home_id', 'contact_id', 'services_id', 'acf_id', 'el_id', 'header_id', 'hero_id', 'pattern_id', 'menu_id', 'nav_id' ) );
}
