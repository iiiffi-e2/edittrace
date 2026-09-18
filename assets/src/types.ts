/**
 * Shared types mirrored from the PHP side.
 */

export interface DomMarker {
	/** CSS selector identifying a source-bearing element. */
	selector: string;
	/** Human system label, e.g. "Elementor". */
	label: string;
	/** Dataset key whose value describes the element type (e.g. widget_type). */
	typeFromDataset?: string;
	/** Class prefix whose remainder describes the element type (e.g. wp-block-). */
	typeFromClassPrefix?: string;
	/** Dataset keys worth sending to the server for this marker. */
	datasetKeys?: string[];
}

export interface EditTraceConfig {
	version: string;
	restUrl: string;
	nonce: string;
	traceId: string;
	page: { title: string; editUrl: string };
	markers: DomMarker[];
	fallbackSearch: boolean;
	debug: boolean;
	adminUrl: string;
	i18n: Record<string, string>;
}

export interface AncestorContext {
	tag: string;
	id: string;
	classes: string[];
	dataset: Record<string, string>;
}

export interface ElementContext extends AncestorContext {
	text: string;
	href: string;
	src: string;
	alt: string;
	width: number;
	height: number;
	ancestors: AncestorContext[];
	pageUrl: string;
	traceId: string;
}

export interface SourceAction {
	label: string;
	url: string;
}

export interface SourceCandidate {
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
	status: 'exact' | 'high' | 'possible' | 'unknown';
	statusLabel: string;
	editUrl: string | null;
	editLabel: string;
	actions: SourceAction[];
	global: boolean;
	globalNote: string;
	role: string;
	reason: string;
	details: Record<string, string>;
	usage: Record<string, unknown>;
	technical: Record<string, unknown>;
}

export interface SourceResult {
	status: 'exact' | 'candidates' | 'unknown';
	selected: { summary: string; tag: string; text: string; href: string; src: string; alt: string };
	primary: SourceCandidate | null;
	candidates: SourceCandidate[];
	weak: SourceCandidate[];
	searched: boolean;
	trace: { available: boolean; id: string | null; entries: number };
	page: {
		title: string;
		url: string;
		objectType: string | null;
		objectId: number | null;
		postType: string | null;
		editUrl: string | null;
		template: string | null;
	};
	notes: string[];
	debug: boolean;
}

export interface ApiError {
	code: string;
	message: string;
}
