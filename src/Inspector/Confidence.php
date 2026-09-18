<?php
/**
 * Confidence scoring helpers.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Inspector;

/**
 * Maps 0.00–1.00 scores to human-readable statuses.
 */
final class Confidence {

	public const EXACT    = 1.0;
	public const HIGH     = 0.85;
	public const POSSIBLE = 0.55;
	public const UNKNOWN  = 0.2;

	public const THRESHOLD_HIGH     = 0.75;
	public const THRESHOLD_POSSIBLE = 0.4;

	public static function clamp( float $score ): float {
		return round( max( 0.0, min( 1.0, $score ) ), 2 );
	}

	/**
	 * Machine status: exact|high|possible|unknown.
	 */
	public static function status( float $score ): string {
		$score = self::clamp( $score );
		if ( $score >= 1.0 ) {
			return 'exact';
		}
		if ( $score >= self::THRESHOLD_HIGH ) {
			return 'high';
		}
		if ( $score >= self::THRESHOLD_POSSIBLE ) {
			return 'possible';
		}
		return 'unknown';
	}

	/**
	 * Human label for a score.
	 */
	public static function label( float $score ): string {
		switch ( self::status( $score ) ) {
			case 'exact':
				return __( 'Exact', 'edittrace' );
			case 'high':
				return __( 'High', 'edittrace' );
			case 'possible':
				return __( 'Possible', 'edittrace' );
			default:
				return __( 'Unknown', 'edittrace' );
		}
	}
}
