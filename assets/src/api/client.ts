import type { EditTraceConfig, ElementContext, SourceResult } from '../types';

/**
 * Minimal REST client for edittrace/v1.
 */
export class ApiClient {
	constructor( private readonly config: EditTraceConfig ) {}

	inspect( element: ElementContext, signal?: AbortSignal ): Promise< SourceResult > {
		return this.post( 'inspect', element, signal );
	}

	search( element: ElementContext, signal?: AbortSignal ): Promise< SourceResult > {
		return this.post( 'search', element, signal );
	}

	private async post( route: string, element: ElementContext, signal?: AbortSignal ): Promise< SourceResult > {
		const url = this.config.restUrl.replace( /\/$/, '' ) + '/' + route;
		const response = await fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': this.config.nonce,
			},
			body: JSON.stringify( { traceId: this.config.traceId, element } ),
			signal,
		} );
		let body: unknown = null;
		try {
			body = await response.json();
		} catch {
			body = null;
		}
		if ( ! response.ok ) {
			const message =
				body && typeof body === 'object' && 'message' in body
					? String( ( body as { message: unknown } ).message )
					: `HTTP ${ response.status }`;
			throw new Error( message );
		}
		return body as SourceResult;
	}
}
