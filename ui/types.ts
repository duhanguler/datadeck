export type FieldType =
	| 'text'
	| 'textarea'
	| 'number'
	| 'currency'
	| 'integer'
	| 'boolean'
	| 'select'
	| 'multiselect'
	| 'date'
	| 'datetime'
	| 'media'
	| 'taxonomy'
	| 'url'
	| 'email'
	| 'slug'
	| 'readonly';

export type DensityMode = 'compact' | 'default' | 'comfortable';
export type SaveMode = 'staged' | 'instant';
export type LayoutMode = 'table' | 'grid';

export interface EntitySummary {
	slug: string;
	label: string;
	pluralLabel: string;
	group: 'content' | 'commerce' | 'system' | string;
	canCreate: boolean;
}

export interface SelectOption {
	value: string;
	label: string;
}

export interface FieldDefinition {
	id: string;
	label: string;
	type: FieldType;
	group: string;
	provider: string;
	editable: boolean;
	sortable: boolean;
	filterable: boolean;
	defaultVisible: boolean;
	width: number;
	align: 'left' | 'right' | 'center';
	monospace: boolean;
	tabularNums: boolean;
	metaKey: string;
	taxonomy: string;
	options: SelectOption[];
	validation: {
		required?: boolean;
		min?: number;
		max?: number;
		maxLength?: number;
		recommendedMax?: number;
	};
	extra: Record<string, unknown>;
}

export interface AuditLens {
	id: string;
	label: string;
	description: string;
	columns: string[];
}

export interface FilterClause {
	id: string;
	field: string;
	operator: string;
	value: string;
	value2?: string;
}

export interface SavedView {
	id: string;
	entity: string;
	name: string;
	columns: string[];
	columnWidths: Record<string, number>;
	density: DensityMode;
	sortField: string;
	sortDirection: 'asc' | 'desc';
	filters: FilterClause[];
	filterRelation: 'AND' | 'OR';
	roles: string[];
	updatedAt: number;
}

export interface JsonSyncStatus {
	enabled: boolean;
	savePath: string;
	syncAvailable: SavedView[];
}

export interface EntitySchemaResponse {
	entity: EntitySummary;
	fields: FieldDefinition[];
	defaultFields: string[];
	lenses: AuditLens[];
	views: SavedView[];
	jsonSync: JsonSyncStatus;
}

export interface MediaValue {
	id: number;
	url: string;
	alt: string;
	filename: string;
}

export interface TermValue {
	id: number;
	label: string;
	slug: string;
}

export interface RecordRow {
	id: number;
	_editUrl?: string;
	_viewUrl?: string;
	_canEdit?: boolean;
	_isVariation?: boolean;
	_parentId?: number;
	_varCount?: number;
	[fieldId: string]: unknown;
}

export interface QueryPerf {
	queries: number;
	durationMs: number;
	plan: string;
}

export interface RecordsResponse {
	items: RecordRow[];
	total: number;
	totalPages: number;
	page: number;
	perPage: number;
	perf: QueryPerf;
}

export interface CellCoord {
	rowIndex: number;
	colIndex: number;
	recordId: number;
	fieldId: string;
}

export interface CellRange {
	startRow: number;
	endRow: number;
	colIndex: number;
	fieldId: string;
}

export interface StagedEdit {
	recordId: number;
	fieldId: string;
	oldValue: unknown;
	newValue: unknown;
}

export interface HistoryBatch {
	id: number;
	uuid: string;
	entityType: string;
	operationType: string;
	summary: string;
	affectedCount: number;
	status: 'applied' | 'rolled_back' | 'scheduled' | 'reverted' | 'cancelled' | 'failed';
	userName: string;
	scheduledAt: string | null;
	revertAt: string | null;
	createdAt: string;
}

export interface HistoryChangeDiff {
	objectId: number;
	field: string;
	oldValue: unknown;
	newValue: unknown;
}

export interface HistoryDetail extends HistoryBatch {
	changes: HistoryChangeDiff[];
}

export interface BulkRule {
	field: string;
	action: string;
	value?: unknown;
	formula?: string;
	find?: string;
	replace?: string;
	regex?: boolean;
	rounding?: string;
}

export interface DryRunPreviewItem {
	id: number;
	title: string;
	oldValue: unknown;
	newValue: unknown;
	error: string | null;
	changed: boolean;
}

export interface DryRunResponse {
	totalMatching: number;
	field: FieldDefinition;
	previews: DryRunPreviewItem[];
}

export interface BulkChunkResponse {
	batchUuid: string;
	processed: number;
	updated: number;
	lastId: number;
	hasMore: boolean;
	errors: Array<{ id: number; message: string }>;
	durationMs: number;
	nextChunkSize: number;
}

export interface DataDeckBootstrap {
	version: string;
	restRoot: string;
	nonce: string;
	adminUrl: string;
	initialEntity: string;
	entities: EntitySummary[];
	preferences: {
		density: DensityMode;
		saveMode: SaveMode;
		defaultForEntity: Record<string, boolean>;
	};
	capabilities: {
		manageOptions: boolean;
	};
}

declare global {
	interface Window {
		datadeckBootstrap?: DataDeckBootstrap;
		datadeckToggle?: {
			entity: string;
			workspaceUrl: string;
			classicLabel: string;
			modernLabel: string;
		};
		wp?: {
			media?: ( options: Record<string, unknown> ) => {
				on: ( event: string, cb: () => void ) => void;
				state: () => {
					get: ( key: string ) => {
						first: () => { toJSON: () => Record<string, unknown> };
					};
				};
				open: () => void;
			};
		};
	}
}
