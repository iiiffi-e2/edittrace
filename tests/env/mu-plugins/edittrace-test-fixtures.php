<?php
/**
 * Plugin Name: EditTrace Test Fixtures
 * Description: Registers ACF field groups and a simulated Elementor Theme Builder "header" document type for the EditTrace test site. Test-only.
 */

declare( strict_types=1 );

if ( ! defined( 'EDITTRACE_TEST_ENV' ) ) {
	return;
}

/*
 * ACF field groups (local PHP registration). The options group uses the
 * options_page location; ACF free has no options page UI but the values
 * are still readable through get_field( ..., 'option' ), which is what the
 * render registry observes.
 */
add_action(
	'acf/init',
	static function (): void {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}
		acf_add_local_field_group(
			array(
				'key'      => 'group_edittrace_hero',
				'title'    => 'Homepage Hero',
				'fields'   => array(
					array(
						'key'   => 'field_edittrace_hero_heading',
						'label' => 'Hero Heading',
						'name'  => 'hero_heading',
						'type'  => 'text',
					),
					array(
						'key'   => 'field_edittrace_hero_intro',
						'label' => 'Hero Intro',
						'name'  => 'hero_intro',
						'type'  => 'wysiwyg',
					),
					array(
						'key'   => 'field_edittrace_hero_cta',
						'label' => 'Hero Call To Action',
						'name'  => 'hero_cta',
						'type'  => 'link',
					),
					array(
						'key'           => 'field_edittrace_hero_image',
						'label'         => 'Hero Image',
						'name'          => 'hero_image',
						'type'          => 'image',
						'return_format' => 'array',
					),
					array(
						'key'        => 'field_edittrace_hero_features',
						'label'      => 'Hero Features',
						'name'       => 'hero_features',
						'type'       => 'repeater',
						'sub_fields' => array(
							array(
								'key'   => 'field_edittrace_feature_label',
								'label' => 'Feature Label',
								'name'  => 'feature_label',
								'type'  => 'text',
							),
						),
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'page',
						),
					),
				),
			)
		);
		acf_add_local_field_group(
			array(
				'key'      => 'group_edittrace_company',
				'title'    => 'Company Information',
				'fields'   => array(
					array(
						'key'   => 'field_edittrace_company_phone',
						'label' => 'Main Phone Number',
						'name'  => 'company_phone',
						'type'  => 'text',
					),
					array(
						'key'   => 'field_edittrace_company_cta',
						'label' => 'Header Call To Action',
						'name'  => 'company_cta',
						'type'  => 'link',
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'options_page',
							'operator' => '==',
							'value'    => 'company-settings',
						),
					),
				),
			)
		);
	}
);

/*
 * Elementor Theme Builder is an Elementor Pro feature. To exercise the
 * global-header code path on a site with only Elementor free, register a
 * minimal "header" library document type modelled on Pro's Theme_Document
 * (get_location()). EditTrace itself only relies on public document APIs
 * and feature detection, never on this shim.
 */
add_action(
	'elementor/documents/register',
	static function ( $documents ): void {
		if ( class_exists( '\ElementorPro\Plugin' ) || ! class_exists( '\Elementor\Modules\Library\Documents\Library_Document' ) ) {
			return;
		}
		if ( ! class_exists( 'EditTrace_Test_Header_Document' ) ) {
			// phpcs:ignore Generic.Files.OneObjectStructurePerFile
			final class EditTrace_Test_Header_Document extends \Elementor\Modules\Library\Documents\Library_Document {
				public static function get_properties() {
					$properties             = parent::get_properties();
					$properties['location'] = 'header';
					return $properties;
				}
				public function get_name() {
					return 'header';
				}
				public static function get_title() {
					return 'Header';
				}
				public function get_location() {
					return 'header';
				}
			}
		}
		$documents->register_document_type( 'header', EditTrace_Test_Header_Document::class );
	}
);
