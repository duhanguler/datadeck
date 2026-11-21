import { createReduxStore, register } from '@wordpress/data';
import type {
	AuditLens,
	CellCoord,
	CellRange,
	DensityMode,
	EntitySummary,
	FieldDefinition,
	FilterClause,
	JsonSyncStatus,
	LayoutMode,
	QueryPerf,
	RecordRow,
	SaveMode,
	SavedView,
	StagedEdit,
} from '../types';

export const STORE_NAME = 'rowcraft/workspace';

export interface WorkspaceState {
	entity: string;
	entities: EntitySummary[];
	entityMeta: EntitySummary | null;
	fields: FieldDefinition[];
	fieldMap: Record<string, FieldDefinition>;
	visibleColumns: string[];
	columnWidths: Record<string, number>;
	lenses: AuditLens[];
	activeLens: string;
	views: SavedView[];
	activeViewId: string | null;
	jsonSync: JsonSyncStatus | null;

	records: RecordRow[];
	total: number;
	totalPages: number;
	page: number;
	perPage: number;
	search: string;
	sortField: string;
	sortDirection: 'asc' | 'desc';
	filters: FilterClause[];
	filterRelation: 'AND' | 'OR';
	flattenVariations: boolean;
	expandedParents: Record<number, RecordRow[]>;
	perf: QueryPerf | null;

	loadingSchema: boolean;
	loadingRecords: boolean;
	committing: boolean;

	selectedIds: Record<number, boolean>;
	focusedCell: CellCoord | null;
	editingCell: CellCoord | null;
	selectedRange: CellRange | null;

	/** Normalized map: stagedEdits[`${recordId}:${fieldId}`] = StagedEdit for O(1) cell subscriptions */
	stagedEdits: Record<string, StagedEdit>;
	cellErrors: Record<string, string>;
	undoStack: Array<Record<string, StagedEdit>>;
	redoStack: Array<Record<string, StagedEdit>>;

	density: DensityMode;
	saveMode: SaveMode;
	layout: LayoutMode;
	toast: { message: string; undoUuid?: string } | null;
}

const boot = window.rowcraftBootstrap;

const DEFAULT_STATE: WorkspaceState = {
	entity: boot?.initialEntity || 'post',
	entities: boot?.entities || [],
	entityMeta: null,
	fields: [],
	fieldMap: {},
	visibleColumns: [],
	columnWidths: {},
	lenses: [],
	activeLens: 'all',
	views: [],
	activeViewId: null,
	jsonSync: null,

	records: [],
	total: 0,
	totalPages: 1,
	page: 1,
	perPage: 50,
	search: '',
	sortField: 'id',
	sortDirection: 'desc',
	filters: [],
	filterRelation: 'AND',
	flattenVariations: false,
	expandedParents: {},
	perf: null,

	loadingSchema: true,
	loadingRecords: false,
	committing: false,

	selectedIds: {},
	focusedCell: null,
	editingCell: null,
	selectedRange: null,

	stagedEdits: {},
	cellErrors: {},
	undoStack: [],
	redoStack: [],

	density: boot?.preferences?.density || 'default',
	saveMode: boot?.preferences?.saveMode || 'staged',
	layout: 'table',
	toast: null,
};

export function cellKey( recordId: number, fieldId: string ): string {
	return `${ recordId }:${ fieldId }`;
}

type Action = { type: string; [key: string]: unknown };

const actions = {
	setEntity( entity: string ) {
		return { type: 'SET_ENTITY', entity };
	},
	setLoadingSchema( loading: boolean ) {
		return { type: 'SET_LOADING_SCHEMA', loading };
	},
	setSchema( payload: {
		entityMeta: EntitySummary;
		fields: FieldDefinition[];
		defaultFields: string[];
		lenses: AuditLens[];
		views: SavedView[];
		jsonSync: JsonSyncStatus;
	} ) {
		return { type: 'SET_SCHEMA', payload };
	},
	setLoadingRecords( loading: boolean ) {
		return { type: 'SET_LOADING_RECORDS', loading };
	},
	setRecords( payload: {
		items: RecordRow[];
		total: number;
		totalPages: number;
		page: number;
		perPage: number;
		perf: QueryPerf;
	} ) {
		return { type: 'SET_RECORDS', payload };
	},
	prependRecord( record: RecordRow ) {
		return { type: 'PREPEND_RECORD', record };
	},
	setQueryParam( patch: Partial<Pick<WorkspaceState, 'page' | 'perPage' | 'search' | 'sortField' | 'sortDirection' | 'filters' | 'filterRelation' | 'activeLens' | 'flattenVariations'>> ) {
		return { type: 'SET_QUERY_PARAM', patch };
	},
	setVisibleColumns( columns: string[] ) {
		return { type: 'SET_VISIBLE_COLUMNS', columns };
	},
	setColumnWidth( fieldId: string, width: number ) {
		return { type: 'SET_COLUMN_WIDTH', fieldId, width };
	},
	applySavedView( view: SavedView ) {
		return { type: 'APPLY_SAVED_VIEW', view };
	},
	setViews( views: SavedView[] ) {
		return { type: 'SET_VIEWS', views };
	},
	toggleSelectRow( id: number ) {
		return { type: 'TOGGLE_SELECT_ROW', id };
	},
	setSelectedIds( ids: number[] ) {
		return { type: 'SET_SELECTED_IDS', ids };
	},
	clearSelection() {
		return { type: 'CLEAR_SELECTION' };
	},
	setFocusedCell( cell: CellCoord | null ) {
		return { type: 'SET_FOCUSED_CELL', cell };
	},
	setEditingCell( cell: CellCoord | null ) {
		return { type: 'SET_EDITING_CELL', cell };
	},
	setSelectedRange( range: CellRange | null ) {
		return { type: 'SET_SELECTED_RANGE', range };
	},
	stageEdits( edits: StagedEdit[] ) {
		return { type: 'STAGE_EDITS', edits };
	},
	undoStaged() {
		return { type: 'UNDO_STAGED' };
	},
	redoStaged() {
		return { type: 'REDO_STAGED' };
	},
	discardStaged() {
		return { type: 'DISCARD_STAGED' };
	},
	setCommitting( committing: boolean ) {
		return { type: 'SET_COMMITTING', committing };
	},
	applyCommittedEdits( edits: StagedEdit[], errors: Record<string, string> ) {
		return { type: 'APPLY_COMMITTED_EDITS', edits, errors };
	},
	setExpandedVariations( parentId: number, rows: RecordRow[] | null ) {
		return { type: 'SET_EXPANDED_VARIATIONS', parentId, rows };
	},
	setDensity( density: DensityMode ) {
		return { type: 'SET_DENSITY', density };
	},
	setSaveMode( saveMode: SaveMode ) {
		return { type: 'SET_SAVE_MODE', saveMode };
	},
	setLayout( layout: LayoutMode ) {
		return { type: 'SET_LAYOUT', layout };
	},
	setToast( toast: { message: string; undoUuid?: string } | null ) {
		return { type: 'SET_TOAST', toast };
	},
};

function reducer( state: WorkspaceState = DEFAULT_STATE, action: Action ): WorkspaceState {
	switch ( action.type ) {
		case 'SET_ENTITY':
			return {
				...state,
				entity: action.entity as string,
				page: 1,
				search: '',
				filters: [],
				activeLens: 'all',
				activeViewId: null,
				selectedIds: {},
				focusedCell: null,
				editingCell: null,
				selectedRange: null,
				stagedEdits: {},
				cellErrors: {},
				undoStack: [],
				redoStack: [],
				expandedParents: {},
				flattenVariations: false,
				loadingSchema: true,
			};

		case 'SET_LOADING_SCHEMA':
			return { ...state, loadingSchema: Boolean( action.loading ) };

		case 'SET_SCHEMA': {
			const p = action.payload as {
				entityMeta: EntitySummary;
				fields: FieldDefinition[];
				defaultFields: string[];
				lenses: AuditLens[];
				views: SavedView[];
				jsonSync: JsonSyncStatus;
			};
			const fieldMap: Record<string, FieldDefinition> = {};
			const widths: Record<string, number> = {};
			for ( const f of p.fields ) {
				fieldMap[ f.id ] = f;
				widths[ f.id ] = f.width;
			}
			return {
				...state,
				entityMeta: p.entityMeta,
				fields: p.fields,
				fieldMap,
				visibleColumns: p.defaultFields.length > 0 ? p.defaultFields : p.fields.slice( 0, 8 ).map( ( f ) => f.id ),
				columnWidths: widths,
				lenses: p.lenses,
				views: p.views,
				jsonSync: p.jsonSync,
				loadingSchema: false,
			};
		}

		case 'SET_LOADING_RECORDS':
			return { ...state, loadingRecords: Boolean( action.loading ) };

		case 'SET_RECORDS': {
			const p = action.payload as {
				items: RecordRow[];
				total: number;
				totalPages: number;
				page: number;
				perPage: number;
				perf: QueryPerf;
			};
			return {
				...state,
				records: p.items,
				total: p.total,
				totalPages: p.totalPages,
				page: p.page,
				perPage: p.perPage,
				perf: p.perf,
				loadingRecords: false,
			};
		}

		case 'PREPEND_RECORD':
			return {
				...state,
				records: [ action.record as RecordRow, ...state.records ],
				total: state.total + 1,
			};

		case 'SET_QUERY_PARAM': {
			const patch = action.patch as Partial<WorkspaceState>;
			const nextLens = patch.activeLens;
			let nextCols = state.visibleColumns;
			if ( nextLens && nextLens !== state.activeLens ) {
				const lensObj = state.lenses.find( ( l ) => l.id === nextLens );
				if ( lensObj && lensObj.columns.length > 0 ) {
					nextCols = lensObj.columns.filter( ( c ) => Boolean( state.fieldMap[ c ] ) );
				}
			}
			return {
				...state,
				...patch,
				visibleColumns: nextCols,
				activeViewId: nextLens ? null : state.activeViewId,
			};
		}

		case 'SET_VISIBLE_COLUMNS':
			return { ...state, visibleColumns: action.columns as string[] };

		case 'SET_COLUMN_WIDTH':
			return {
				...state,
				columnWidths: {
					...state.columnWidths,
					[ action.fieldId as string ]: Math.max( 56, Math.min( 640, Math.round( action.width as number ) ) ),
				},
			};

		case 'APPLY_SAVED_VIEW': {
			const v = action.view as SavedView;
			return {
				...state,
				activeViewId: v.id,
				activeLens: 'all',
				visibleColumns: v.columns.length > 0 ? v.columns.filter( ( c ) => Boolean( state.fieldMap[ c ] ) ) : state.visibleColumns,
				columnWidths: { ...state.columnWidths, ...( v.columnWidths || {} ) },
				density: v.density || state.density,
				sortField: v.sortField || 'id',
				sortDirection: v.sortDirection || 'desc',
				filters: v.filters || [],
				filterRelation: v.filterRelation || 'AND',
				page: 1,
			};
		}

		case 'SET_VIEWS':
			return { ...state, views: action.views as SavedView[] };

		case 'TOGGLE_SELECT_ROW': {
			const id = action.id as number;
			const next = { ...state.selectedIds };
			if ( next[ id ] ) {
				delete next[ id ];
			} else {
				next[ id ] = true;
			}
			return { ...state, selectedIds: next };
		}

		case 'SET_SELECTED_IDS': {
			const next: Record<number, boolean> = {};
			for ( const id of action.ids as number[] ) {
				next[ id ] = true;
			}
			return { ...state, selectedIds: next };
		}

		case 'CLEAR_SELECTION':
			return { ...state, selectedIds: {} };

		case 'SET_FOCUSED_CELL':
			return { ...state, focusedCell: action.cell as CellCoord | null };

		case 'SET_EDITING_CELL':
			return { ...state, editingCell: action.cell as CellCoord | null };

		case 'SET_SELECTED_RANGE':
			return { ...state, selectedRange: action.range as CellRange | null };

		case 'STAGE_EDITS': {
			const incoming = action.edits as StagedEdit[];
			if ( incoming.length === 0 ) {
				return state;
			}
			const nextStaged = { ...state.stagedEdits };
			const nextErrors = { ...state.cellErrors };
			for ( const edit of incoming ) {
				const k = cellKey( edit.recordId, edit.fieldId );
				const existing = nextStaged[ k ];
				const originalOld = existing ? existing.oldValue : edit.oldValue;
				if ( JSON.stringify( originalOld ) === JSON.stringify( edit.newValue ) ) {
					delete nextStaged[ k ];
				} else {
					nextStaged[ k ] = {
						recordId: edit.recordId,
						fieldId: edit.fieldId,
						oldValue: originalOld,
						newValue: edit.newValue,
					};
				}
				delete nextErrors[ k ];
			}
			return {
				...state,
				stagedEdits: nextStaged,
				cellErrors: nextErrors,
				undoStack: [ ...state.undoStack.slice( -30 ), state.stagedEdits ],
				redoStack: [],
			};
		}

		case 'UNDO_STAGED': {
			if ( state.undoStack.length === 0 ) {
				return state;
			}
			const prev = state.undoStack[ state.undoStack.length - 1 ];
			return {
				...state,
				stagedEdits: prev,
				undoStack: state.undoStack.slice( 0, -1 ),
				redoStack: [ ...state.redoStack, state.stagedEdits ],
			};
		}

		case 'REDO_STAGED': {
			if ( state.redoStack.length === 0 ) {
				return state;
			}
			const next = state.redoStack[ state.redoStack.length - 1 ];
			return {
				...state,
				stagedEdits: next,
				redoStack: state.redoStack.slice( 0, -1 ),
				undoStack: [ ...state.undoStack, state.stagedEdits ],
			};
		}

		case 'DISCARD_STAGED':
			return {
				...state,
				stagedEdits: {},
				cellErrors: {},
				undoStack: [],
				redoStack: [],
			};

		case 'SET_COMMITTING':
			return { ...state, committing: Boolean( action.committing ) };

		case 'APPLY_COMMITTED_EDITS': {
			const edits = action.edits as StagedEdit[];
			const errors = action.errors as Record<string, string>;
			const succeededByRecord: Record<number, Record<string, unknown>> = {};
			const remainingStaged = { ...state.stagedEdits };

			for ( const edit of edits ) {
				const k = cellKey( edit.recordId, edit.fieldId );
				if ( ! errors[ k ] ) {
					if ( ! succeededByRecord[ edit.recordId ] ) {
						succeededByRecord[ edit.recordId ] = {};
					}
					succeededByRecord[ edit.recordId ][ edit.fieldId ] = edit.newValue;
					delete remainingStaged[ k ];
				}
			}

			const patchRow = ( row: RecordRow ): RecordRow => {
				const updates = succeededByRecord[ row.id ];
				return updates ? { ...row, ...updates } : row;
			};

			const nextExpanded: Record<number, RecordRow[]> = {};
			for ( const [ pid, list ] of Object.entries( state.expandedParents ) ) {
				nextExpanded[ Number( pid ) ] = list.map( patchRow );
			}

			return {
				...state,
				records: state.records.map( patchRow ),
				expandedParents: nextExpanded,
				stagedEdits: remainingStaged,
				cellErrors: errors,
				committing: false,
			};
		}

		case 'SET_EXPANDED_VARIATIONS': {
			const pid = action.parentId as number;
			const rows = action.rows as RecordRow[] | null;
			const next = { ...state.expandedParents };
			if ( rows === null ) {
				delete next[ pid ];
			} else {
				next[ pid ] = rows;
			}
			return { ...state, expandedParents: next };
		}

		case 'SET_DENSITY':
			return { ...state, density: action.density as DensityMode };

		case 'SET_SAVE_MODE':
			return { ...state, saveMode: action.saveMode as SaveMode };

		case 'SET_LAYOUT':
			return { ...state, layout: action.layout as LayoutMode };

		case 'SET_TOAST':
			return { ...state, toast: action.toast as WorkspaceState['toast'] };

		default:
			return state;
	}
}

const selectors = {
	getState( state: WorkspaceState ): WorkspaceState {
		return state;
	},
	/** O(1) cell-level selector so typing in one cell never re-renders the entire grid */
	getStagedEditForCell( state: WorkspaceState, recordId: number, fieldId: string ): StagedEdit | undefined {
		return state.stagedEdits[ cellKey( recordId, fieldId ) ];
	},
	getCellError( state: WorkspaceState, recordId: number, fieldId: string ): string | undefined {
		return state.cellErrors[ cellKey( recordId, fieldId ) ];
	},
	getStagedCount( state: WorkspaceState ): number {
		return Object.keys( state.stagedEdits ).length;
	},
	getSelectedIdList( state: WorkspaceState ): number[] {
		return Object.keys( state.selectedIds ).map( Number );
	},
};

export const store = createReduxStore( STORE_NAME, {
	reducer,
	actions,
	selectors,
} );

register( store );
