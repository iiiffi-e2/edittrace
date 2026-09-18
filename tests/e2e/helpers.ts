import { expect, type Page } from '@playwright/test';

export interface ResultCandidate {
	provider: string;
	system: string;
	sourceType: string;
	sourceId: string;
	sourceName: string;
	sourceLabel: string;
	itemLabel: string;
	itemName: string;
	itemKey: string;
	hierarchy: string[];
	confidence: number;
	status: string;
	editUrl: string | null;
	editLabel: string;
	global: boolean;
	technical: Record< string, unknown >;
}

export interface Result {
	status: string;
	primary: ResultCandidate | null;
	candidates: ResultCandidate[];
	searched: boolean;
	page: { title: string };
}

/**
 * Activates Inspector Mode through the admin bar button.
 */
export async function activate( page: Page ): Promise< void > {
	await page.waitForFunction( () => !! ( window as unknown as { EditTrace?: unknown } ).EditTrace );
	await page.click( '#wp-admin-bar-edittrace > a' );
	await expect( page.locator( '#edittrace-root' ) ).toHaveClass( /edittrace-root--active/ );
}

/**
 * Clicks a page element in Inspector Mode and returns the panel result.
 */
export async function inspect( page: Page, selector: string ): Promise< Result > {
	const resultPromise = page.evaluate(
		() =>
			new Promise< Result >( ( resolve ) => {
				document.addEventListener( 'edittrace:result', ( e ) => resolve( ( e as CustomEvent ).detail as Result ), { once: true } );
			} )
	);
	const target = page.locator( selector ).first();
	await target.evaluate( ( el ) => el.scrollIntoView( { block: 'center' } ) );
	await target.click( { force: true } );
	return resultPromise;
}

export function panel( page: Page ) {
	return page.locator( '#edittrace-root' ).locator( 'aside.edittrace-panel' );
}
