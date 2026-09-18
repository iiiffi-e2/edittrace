<?php
/**
 * Observes ACF values as they are loaded/formatted during a traced render.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Integrations\ACF;

use EditTrace\Inspector\TraceRegistry;
use EditTrace\Inspector\TraceSession;

/**
 * Hooks acf/load_value and acf/format_value (public ACF filters) and records
 * every field involved in generating the page together with value
 * fingerprints. Nothing is written to the HTML; the registry is stored
 * server-side under the trace token.
 */
final class RenderRegistry {

	public const MAX_FIELDS = 600;

	private TraceRegistry $registry;

	/** @var array<string,string> uid => registry entry id */
	private array $index = array();

	public function __construct( TraceSession $session ) {
		$this->registry = $session->get_registry();
	}

	public function register_hooks(): void {
		add_filter( 'acf/load_value', array( $this, 'on_load_value' ), 20, 3 );
		add_filter( 'acf/format_value', array( $this, 'on_format_value' ), 20, 3 );
	}

	/**
	 * @param mixed               $value   Raw value.
	 * @param mixed               $post_id ACF post id.
	 * @param array<string,mixed> $field   Field.
	 * @return mixed
	 */
	public function on_load_value( $value, $post_id, $field ) {
		$this->record( $value, $post_id, $field, 'raw' );
		return $value;
	}

	/**
	 * @param mixed               $value   Formatted value.
	 * @param mixed               $post_id ACF post id.
	 * @param array<string,mixed> $field   Field.
	 * @return mixed
	 */
	public function on_format_value( $value, $post_id, $field ) {
		$this->record( $value, $post_id, $field, 'formatted' );
		return $value;
	}

	/**
	 * @param mixed               $value   Value.
	 * @param mixed               $post_id ACF post id.
	 * @param array<string,mixed> $field   Field.
	 */
	private function record( $value, $post_id, $field, string $stage ): void {
		if ( ! is_array( $field ) || empty( $field['key'] ) || ! is_scalar( $post_id ) || ( '' === $post_id && 0 !== $post_id ) ) {
			return;
		}
		try {
			$prints = Fingerprint::build( $field, $value );
		} catch ( \Throwable $e ) {
			return;
		}
		if ( empty( $prints['texts'] ) && empty( $prints['urls'] ) && empty( $prints['attachments'] ) ) {
			return;
		}

		$loop = self::active_loop_for( $field );
		$uid  = $field['key'] . '|' . (string) $post_id . '|' . ( null === $loop['row'] ? '' : (string) $loop['row'] );

		if ( isset( $this->index[ $uid ] ) ) {
			$existing = $this->registry->get( $this->index[ $uid ] );
			if ( $existing ) {
				$this->registry->update(
					$this->index[ $uid ],
					array_merge(
						Fingerprint::merge( $existing, $prints ),
						array( 'stages' => array_values( array_unique( array_merge( (array) ( $existing['stages'] ?? array() ), array( $stage ) ) ) ) )
					)
				);
			}
			return;
		}
		if ( count( $this->index ) >= self::MAX_FIELDS ) {
			return;
		}

		$lineage = FieldInfo::lineage( $field );
		$object  = FieldInfo::describe_object( $post_id, $lineage['group'] );

		$entry_id = $this->registry->register(
			'acf_field',
			array_merge(
				$prints,
				array(
					'key'       => (string) $field['key'],
					'name'      => (string) ( $field['name'] ?? '' ),
					'label'     => (string) ( $field['label'] ?? ( $field['name'] ?? '' ) ),
					'type'      => (string) ( $field['type'] ?? '' ),
					'post_id'   => (string) $post_id,
					'object'    => $object,
					'group'     => $lineage['group'],
					'ancestors' => $lineage['ancestors'],
					'row'       => $loop['row'],
					'layout'    => $loop['layout'],
					'stages'    => array( $stage ),
				)
			)
		);
		if ( null !== $entry_id ) {
			$this->index[ $uid ] = $entry_id;
		}
	}

	/**
	 * Row index / layout when the field is a sub field being read inside an
	 * active have_rows() loop of its parent.
	 *
	 * @param array<string,mixed> $field Field.
	 * @return array{row:int|null,layout:string|null}
	 */
	public static function active_loop_for( array $field ): array {
		$none = array(
			'row'    => null,
			'layout' => null,
		);
		if ( empty( $field['parent'] ) || ! function_exists( 'acf_get_loop' ) ) {
			return $none;
		}
		try {
			$loop = acf_get_loop( 'active' );
		} catch ( \Throwable $e ) {
			return $none;
		}
		if ( ! is_array( $loop ) || empty( $loop['field']['key'] ) || $loop['field']['key'] !== $field['parent'] || ! isset( $loop['i'] ) ) {
			return $none;
		}
		$row    = (int) $loop['i'];
		$layout = null;
		if ( ( $loop['field']['type'] ?? '' ) === 'flexible_content' ) {
			$layout_name = $loop['value'][ $row ]['acf_fc_layout'] ?? null;
			if ( is_string( $layout_name ) && ! empty( $loop['field']['layouts'] ) && is_array( $loop['field']['layouts'] ) ) {
				foreach ( $loop['field']['layouts'] as $l ) {
					if ( ( $l['name'] ?? '' ) === $layout_name ) {
						$layout = (string) ( $l['label'] ?? $layout_name );
						break;
					}
				}
				$layout = $layout ?? $layout_name;
			}
		}
		return array(
			'row'    => $row,
			'layout' => $layout,
		);
	}
}
