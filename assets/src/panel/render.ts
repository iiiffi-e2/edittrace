/**
 * Tiny HTML templating helpers (escaped by default).
 */
export function esc( value: unknown ): string {
	return String( value ?? '' )
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' )
		.replace( /'/g, '&#039;' );
}

export function attr( value: unknown ): string {
	return esc( value );
}

export function isSafeUrl( url: string | null | undefined ): url is string {
	if ( ! url ) {
		return false;
	}
	return /^(https?:)?\/\//i.test( url ) || url.startsWith( '/' );
}

export function truncate( value: string, max = 90 ): string {
	return value.length > max ? value.slice( 0, max - 1 ) + '…' : value;
}
