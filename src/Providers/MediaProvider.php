<?php
/**
 * Media Library provider.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Providers;

use EditTrace\Inspector\ElementContext;
use EditTrace\Inspector\SourceCandidate;
use EditTrace\Inspector\TraceContext;
use EditTrace\Support\Url;

/**
 * Resolves images (and links to media files) to WordPress attachments using
 * the wp-image-N class, gallery data-id attributes, or the upload URL.
 */
final class MediaProvider extends AbstractProvider {

	public function get_name(): string {
		return 'media';
	}

	public function get_priority(): int {
		return 50;
	}

	public function supports( ElementContext $context, TraceContext $trace ): bool {
		return '' !== $context->src || $this->is_media_link( $context ) || null !== $context->nearest_with_class( '/^wp-image-(\d+)$/' );
	}

	public function resolve( ElementContext $context, TraceContext $trace ): array {
		$id     = 0;
		$reason = '';
		$score  = 0.0;

		$class = $context->nearest_with_class( '/^wp-image-(\d+)$/' );
		if ( null !== $class && $class['depth'] <= 1 ) {
			$id     = (int) $class['match'][1];
			$score  = 1.0;
			$reason = __( 'The image carries the attachment id in its wp-image class.', 'edittrace' );
		}
		if ( 0 === $id && 'img' === $context->tag && ! empty( $context->dataset['id'] ) && ctype_digit( (string) $context->dataset['id'] ) && ! $context->has_class( 'elementor-element' ) ) {
			$id     = (int) $context->dataset['id'];
			$score  = 1.0;
			$reason = __( 'The image carries the attachment id in a data-id attribute.', 'edittrace' );
		}
		$url = '' !== $context->src ? $context->src : ( $this->is_media_link( $context ) ? $context->href : '' );
		if ( 0 === $id && '' !== $url ) {
			$id = $trace->remember( 'media:' . $url, static fn(): int => self::url_to_attachment( $url ) );
			if ( $id > 0 ) {
				$score  = 1.0;
				$reason = __( 'The file URL belongs to this Media Library attachment.', 'edittrace' );
			}
		}
		if ( $id <= 0 ) {
			return array();
		}
		$attachment = get_post( $id );
		if ( ! $attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type ) {
			return array();
		}

		$file     = (string) get_attached_file( $id );
		$filename = '' !== $file ? wp_basename( $file ) : wp_basename( (string) wp_get_attachment_url( $id ) );
		$meta     = wp_get_attachment_metadata( $id );
		$alt      = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );

		$c               = $this->candidate( 'attachment' );
		$c->system       = __( 'Media Library', 'edittrace' );
		$c->source_id    = (string) $id;
		$c->source_name  = $filename;
		$c->source_label = __( 'Attachment', 'edittrace' );
		$c->item_label   = __( 'Title', 'edittrace' );
		$c->item_name    = $attachment->post_title;
		$c->item_key     = $filename;
		$c->hierarchy    = array( __( 'Media Library', 'edittrace' ), $filename );
		$c->edit_url     = \EditTrace\Support\EditLinks::attachment( $id );
		$c->edit_label   = __( 'Edit Media', 'edittrace' );
		$c->role         = 'media';
		$c->depth        = null !== $class ? (int) $class['depth'] : 0;
		$c->details[ __( 'Attachment ID', 'edittrace' ) ] = (string) $id;
		if ( is_array( $meta ) && ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
			$c->details[ __( 'Dimensions', 'edittrace' ) ] = (int) $meta['width'] . ' × ' . (int) $meta['height'];
		}
		if ( '' !== $alt ) {
			$c->details[ __( 'Alt text', 'edittrace' ) ] = $alt;
		}
		$c->technical = array(
			'attachmentId' => $id,
			'mimeType'     => $attachment->post_mime_type,
		);
		if ( current_user_can( 'upload_files' ) ) {
			$c->add_action( __( 'Open Media Library', 'edittrace' ), admin_url( 'upload.php?item=' . $id ) );
		}
		$c->with_confidence( $score, $reason );
		return array( $c );
	}

	private function is_media_link( ElementContext $context ): bool {
		if ( '' === $context->href ) {
			return false;
		}
		$path = (string) wp_parse_url( $context->href, PHP_URL_PATH );
		return 1 === preg_match( '/\.(jpe?g|png|gif|webp|avif|svg|pdf|mp4|mp3|webm|zip|docx?|xlsx?|pptx?)$/i', $path ) && false !== strpos( $context->href, '/uploads/' );
	}

	/**
	 * Attachment id for an upload URL, tolerant of size suffixes and -scaled files.
	 */
	public static function url_to_attachment( string $url ): int {
		$url = strtok( $url, '?' ) ?: $url;
		foreach ( array_unique( array( $url, Url::strip_size_suffix( $url ), str_replace( '-scaled.', '.', Url::strip_size_suffix( $url ) ) ) ) as $candidate ) {
			$id = (int) attachment_url_to_postid( $candidate );
			if ( $id > 0 ) {
				return $id;
			}
			// Try the -scaled variant used for large uploads.
			$scaled = (string) preg_replace( '/(\.[a-z0-9]+)$/i', '-scaled$1', $candidate );
			if ( $scaled !== $candidate ) {
				$id = (int) attachment_url_to_postid( $scaled );
				if ( $id > 0 ) {
					return $id;
				}
			}
		}
		return 0;
	}
}
