import apiFetch from '@wordpress/api-fetch';
import type {
	BulkChunkResponse,
	BulkRule,
	ConflictItem,
	CsvAnalyzeResponse,
	CsvApplyResponse,
	CsvDryRunResponse,
	DensityMode,
	DryRunResponse,
	EntitySchemaResponse,
	FieldDefinition,
	FilterClause,
	HistoryBatch,
	HistoryDetail,
	RecordHistoryEntry,
	RecordRow,
	RecordsResponse,
	SaveMode,
	SavedView,
} from '../types';

const REST_PREFIX = '/datadeck/v1/';

let activeQueryController: AbortController | null = null;

function buildQueryString( params: Record<string, unknown> ): string {
	const search = new URLSearchParams();
	Object.entries( params ).forEach( ( [ key, value ] ) => {
		if ( value === undefined || value === null || value === '' ) {
			return;
		}
		if ( Array.isArray( value ) ) {
			if ( key === 'fields' ) {
				if ( value.length > 0 ) {
					search.set( key, value.join( ',' ) );
				}
			} else if ( value.length > 0 ) {
				search.set( key, JSON.stringify( value ) );
			}
			return;
		}
		search.set( key, String( value ) );
	} );
	const str = search.toString();
	return str ? `?${ str }` : '';
}

export async function fetchSchema( entity: string ): Promise<EntitySchemaResponse> {
	return apiFetch<EntitySchemaResponse>( {
		path: `${ REST_PREFIX }schema/${ encodeURIComponent( entity ) }`,
	} );
}

export interface RecordsQueryParams {
	page: number;
	per_page: number;
	search?: string;
	sort_field?: string;
	sort_direction?: 'asc' | 'desc';
	filters?: FilterClause[];
	filter_relation?: 'AND' | 'OR';
	fields?: string[];
	lens?: string;
	parent_id?: number;
	flatten_variations?: boolean;
}

export async function fetchRecords(
	entity: string,
	params: RecordsQueryParams,
	abortPrevious = true
): Promise<RecordsResponse> {
	let signal: AbortSignal | undefined;
	if ( abortPrevious ) {
		if ( activeQueryController ) {
			activeQueryController.abort();
		}
		activeQueryController = new AbortController();
		signal = activeQueryController.signal;
	}

	const qs = buildQueryString( {
		page: params.page,
		per_page: params.per_page,
		search: params.search,
		sort_field: params.sort_field,
		sort_direction: params.sort_direction,
		filters: params.filters?.filter( ( f ) => f.field && f.operator ),
		filter_relation: params.filter_relation,
		fields: params.fields,
		lens: params.lens && params.lens !== 'all' ? params.lens : undefined,
		parent_id: params.parent_id && params.parent_id > 0 ? params.parent_id : undefined,
		flatten_variations: params.flatten_variations ? 1 : undefined,
	} );

	return apiFetch<RecordsResponse>( {
		path: `${ REST_PREFIX }records/${ encodeURIComponent( entity ) }${ qs }`,
		signal,
	} );
}

export async function fetchSingleRecord(
	entity: string,
	id: number
): Promise<{ record: RecordRow }> {
	return apiFetch<{ record: RecordRow }>( {
		path: `${ REST_PREFIX }records/${ encodeURIComponent( entity ) }/${ id }`,
	} );
}

export async function createRecord(
	entity: string,
	data: Record<string, unknown>
): Promise<{ id: number; record: RecordRow }> {
	return apiFetch( {
		path: `${ REST_PREFIX }records/${ encodeURIComponent( entity ) }`,
		method: 'POST',
		data,
	} );
}

export async function duplicateRecord(
	entity: string,
	id: number
): Promise<{ id: number; record: RecordRow }> {
	return apiFetch( {
		path: `${ REST_PREFIX }records/${ encodeURIComponent( entity ) }/${ id }/duplicate`,
		method: 'POST',
	} );
}

export async function deleteRecords(
	entity: string,
	ids: number[]
): Promise<{ deleted: number[]; errors: Array<{ id: number; message: string }> }> {
	return apiFetch( {
		path: `${ REST_PREFIX }records/${ encodeURIComponent( entity ) }`,
		method: 'DELETE',
		data: { ids },
	} );
}

export async function commitEdits(
	entity: string,
	edits: Array<{ id: number; field: string; value: unknown; expectedModifiedAt?: string }>,
	mode: 'inline' | 'paste' = 'inline',
	force = false
): Promise<{
	updated: number;
	errors: Array<{ id: number; field: string; message: string }>;
	conflicts: ConflictItem[];
	modifiedMap: Record<string, string>;
	batchUuid: string;
}> {
	return apiFetch( {
		path: `${ REST_PREFIX }batch/${ encodeURIComponent( entity ) }`,
		method: 'POST',
		data: { mode, edits, force },
	} );
}

export async function dryRunBulk(
	entity: string,
	rule: BulkRule,
	ids: number[],
	query: RecordsQueryParams
): Promise<DryRunResponse> {
	return apiFetch<DryRunResponse>( {
		path: `${ REST_PREFIX }batch/${ encodeURIComponent( entity ) }`,
		method: 'POST',
		data: {
			mode: 'dry_run',
			rule,
			ids,
			query,
		},
	} );
}

export async function executeBulkChunk(
	entity: string,
	payload: {
		rule: BulkRule;
		ids: number[];
		query: RecordsQueryParams;
		afterId: number;
		chunkSize: number;
		batchUuid?: string;
		revertAt?: string;
	}
): Promise<BulkChunkResponse> {
	return apiFetch<BulkChunkResponse>( {
		path: `${ REST_PREFIX }batch/${ encodeURIComponent( entity ) }`,
		method: 'POST',
		data: {
			mode: 'bulk',
			...payload,
		},
	} );
}

export async function validateFormula(
	expression: string
): Promise<{ valid: boolean; error: string | null }> {
	return apiFetch( {
		path: `${ REST_PREFIX }formula/validate`,
		method: 'POST',
		data: { expression },
	} );
}

export async function scheduleCampaign(
	entity: string,
	payload: {
		name: string;
		rule: BulkRule;
		ids: number[];
		query: RecordsQueryParams;
		scheduledAt: string;
		revertAt?: string;
	}
): Promise<{ uuid: string; status: string; scheduledAt: string; revertAt: string | null }> {
	return apiFetch( {
		path: `${ REST_PREFIX }schedules/${ encodeURIComponent( entity ) }`,
		method: 'POST',
		data: payload,
	} );
}

export async function cancelSchedule( uuid: string ): Promise<{ cancelled: boolean }> {
	return apiFetch( {
		path: `${ REST_PREFIX }schedules/${ encodeURIComponent( uuid ) }`,
		method: 'DELETE',
	} );
}

export async function fetchHistory( entity?: string ): Promise<{ batches: HistoryBatch[] }> {
	const qs = buildQueryString( { entity, limit: 40 } );
	return apiFetch( {
		path: `${ REST_PREFIX }history${ qs }`,
	} );
}

export async function fetchHistoryDetail( uuid: string ): Promise<HistoryDetail> {
	return apiFetch( {
		path: `${ REST_PREFIX }history/${ encodeURIComponent( uuid ) }`,
	} );
}

export async function rollbackBatch(
	uuid: string
): Promise<{ restored: number; errors: unknown[]; rollbackUuid: string }> {
	return apiFetch( {
		path: `${ REST_PREFIX }history/${ encodeURIComponent( uuid ) }/rollback`,
		method: 'POST',
	} );
}

export async function saveView(
	entity: string,
	view: Partial<SavedView>
): Promise<{ view: SavedView }> {
	return apiFetch( {
		path: `${ REST_PREFIX }views/${ encodeURIComponent( entity ) }`,
		method: 'POST',
		data: view,
	} );
}

export async function deleteView( id: string ): Promise<{ deleted: boolean }> {
	return apiFetch( {
		path: `${ REST_PREFIX }views/${ encodeURIComponent( id ) }`,
		method: 'DELETE',
	} );
}

export async function syncViewsFromJson(
	entity?: string
): Promise<{ synced: number; views?: SavedView[] }> {
	return apiFetch( {
		path: `${ REST_PREFIX }views/sync-json`,
		method: 'POST',
		data: entity ? { entity } : {},
	} );
}

export async function savePreferences( prefs: {
	density?: DensityMode;
	saveMode?: SaveMode;
	defaultForEntity?: Record<string, boolean>;
} ): Promise<void> {
	await apiFetch( {
		path: `${ REST_PREFIX }preferences`,
		method: 'POST',
		data: prefs,
	} );
}

export function buildExportUrl(
	entity: string,
	params: RecordsQueryParams,
	format: 'csv' | 'json'
): string {
	const root = window.datadeckBootstrap?.restRoot || '/wp-json/datadeck/v1/';
	const nonce = window.datadeckBootstrap?.nonce || '';
	const qs = buildQueryString( {
		format,
		_wpnonce: nonce,
		search: params.search,
		sort_field: params.sort_field,
		sort_direction: params.sort_direction,
		filters: params.filters?.filter( ( f ) => f.field && f.operator ),
		filter_relation: params.filter_relation,
		fields: params.fields,
		lens: params.lens && params.lens !== 'all' ? params.lens : undefined,
		flatten_variations: params.flatten_variations ? 1 : undefined,
	} );
	const base = `${ root }export/${ encodeURIComponent( entity ) }`;
	if ( ! qs ) {
		return base;
	}
	return base.includes( '?' ) ? `${ base }&${ qs.slice( 1 ) }` : `${ base }${ qs }`;
}

export async function fetchRecordHistory(
	entity: string,
	recordId: number,
	field?: string
): Promise<{ changes: RecordHistoryEntry[] }> {
	const qs = buildQueryString( { field, limit: 50 } );
	return apiFetch( {
		path: `${ REST_PREFIX }history/record/${ encodeURIComponent( entity ) }/${ recordId }${ qs }`,
	} );
}

export async function rollbackSingleChange(
	changeId: number
): Promise<{ objectId: number; field: string; restoredValue: unknown; rollbackUuid: string }> {
	return apiFetch( {
		path: `${ REST_PREFIX }history/change/${ changeId }/rollback`,
		method: 'POST',
	} );
}

export async function saveComputedField(
	entity: string,
	payload: { label: string; formula: string; format: 'number' | 'currency' | 'percent' | 'text'; id?: string }
): Promise<{ computedField: { id: string; label: string; formula: string; format: string }; fields: FieldDefinition[] }> {
	return apiFetch( {
		path: `${ REST_PREFIX }computed-fields/${ encodeURIComponent( entity ) }`,
		method: 'POST',
		data: payload,
	} );
}

export async function deleteComputedField(
	entity: string,
	fieldId: string
): Promise<{ deleted: boolean; fields: FieldDefinition[] }> {
	return apiFetch( {
		path: `${ REST_PREFIX }computed-fields/${ encodeURIComponent( entity ) }/${ encodeURIComponent( fieldId ) }`,
		method: 'DELETE',
	} );
}

export async function analyzeImport(
	entity: string,
	content: string,
	format: 'csv' | 'json' = 'csv'
): Promise<CsvAnalyzeResponse> {
	return apiFetch<CsvAnalyzeResponse>( {
		path: `${ REST_PREFIX }import/${ encodeURIComponent( entity ) }/analyze`,
		method: 'POST',
		data: { content, format },
	} );
}

export async function dryRunImport(
	entity: string,
	payload: {
		rows: Array<Record<string, unknown>>;
		mapping: Record<string, string>;
		matchBy: string;
		createMissing: boolean;
	}
): Promise<CsvDryRunResponse> {
	return apiFetch<CsvDryRunResponse>( {
		path: `${ REST_PREFIX }import/${ encodeURIComponent( entity ) }/dry-run`,
		method: 'POST',
		data: payload,
	} );
}

export async function applyImportChunk(
	entity: string,
	payload: {
		rows: Array<Record<string, unknown>>;
		mapping: Record<string, string>;
		matchBy: string;
		createMissing: boolean;
		batchUuid?: string;
	}
): Promise<CsvApplyResponse> {
	return apiFetch<CsvApplyResponse>( {
		path: `${ REST_PREFIX }import/${ encodeURIComponent( entity ) }/apply`,
		method: 'POST',
		data: payload,
	} );
}
