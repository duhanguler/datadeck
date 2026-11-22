import { lazy, Suspense, useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { Icon } from '@wordpress/components';
import {
	backup,
	columns as columnsIcon,
	download,
	funnel,
	plus,
	redo,
	search as searchIcon,
	trash,
	undo,
	upload,
} from '@wordpress/icons';
import {
	buildExportUrl,
	commitEdits,
	createRecord,
	deleteRecords,
	duplicateRecord,
	fetchRecords,
	fetchSchema,
	rollbackBatch,
	savePreferences,
	type RecordsQueryParams,
} from '../api/client';
import { cellKey, STORE_NAME, type WorkspaceState } from '../store';
import type {
	ConflictItem,
	DensityMode,
	FieldDefinition,
	RecordRow,
	SaveMode,
	StagedEdit,
} from '../types';
import { FilterBar } from './FilterBar';
import { GridContextMenu, type ContextMenuState } from './GridContextMenu';
import { SpreadsheetGrid } from './SpreadsheetGrid';
import type { PaletteCommand } from '../modals/CommandPalette';

// Code-split heavy drawers & modals so initial boot bundle stays strictly < 45 KB gzipped.
const BulkFormulaModal = lazy( () => import( /* webpackChunkName: "bulk-modal" */ '../modals/BulkFormulaModal' ) );
const CsvImportModal = lazy( () => import( /* webpackChunkName: "import-modal" */ '../modals/CsvImportModal' ) );
const HistoryDrawer = lazy( () => import( /* webpackChunkName: "history-drawer" */ '../modals/HistoryDrawer' ) );
const ColumnsViewsModal = lazy( () => import( /* webpackChunkName: "columns-modal" */ '../modals/ColumnsViewsModal' ) );
const InspectorDrawer = lazy( () => import( /* webpackChunkName: "inspector-drawer" */ '../modals/InspectorDrawer' ) );
const CommandPalette = lazy( () => import( /* webpackChunkName: "command-palette" */ '../modals/CommandPalette' ) );

export function App() {
	const state = useSelect(
		( select ) => ( select( STORE_NAME ) as { getState: () => WorkspaceState } ).getState(),
		[]
	);
	const dispatch = useDispatch( STORE_NAME ) as Record<string, ( ...args: unknown[] ) => void>;

	const [ showFilters, setShowFilters ] = useState( false );
	const [ showQuickAdd, setShowQuickAdd ] = useState( false );
	const [ quickTitle, setQuickTitle ] = useState( '' );
	const [ quickPrice, setQuickPrice ] = useState( '' );
	const [ quickSku, setQuickSku ] = useState( '' );

	const [ showBulkModal, setShowBulkModal ] = useState( false );
	const [ showImportModal, setShowImportModal ] = useState( false );
	const [ showHistory, setShowHistory ] = useState( false );
	const [ showColumns, setShowColumns ] = useState( false );
	const [ showPalette, setShowPalette ] = useState( false );
	const [ inspectRecord, setInspectRecord ] = useState<{
		record: RecordRow;
		tab: 'edit' | 'history';
		field?: string;
	} | null>( null );
	const [ contextMenu, setContextMenu ] = useState<ContextMenuState | null>( null );
	const [ conflicts, setConflicts ] = useState<{
		items: ConflictItem[];
		pendingEdits: StagedEdit[];
		mode: 'inline' | 'paste';
	} | null>( null );
	const [ searchInput, setSearchInput ] = useState( '' );

	const visibleFieldDefs: FieldDefinition[] = useMemo(
		() =>
			state.visibleColumns
				.map( ( id ) => state.fieldMap[ id ] )
				.filter( ( f ): f is FieldDefinition => Boolean( f ) ),
		[ state.visibleColumns, state.fieldMap ]
	);

	const selectedIdList = useMemo(
		() => Object.keys( state.selectedIds ).map( Number ),
		[ state.selectedIds ]
	);
	const stagedCount = useMemo(
		() => Object.keys( state.stagedEdits ).length,
		[ state.stagedEdits ]
	);

	const queryParams: RecordsQueryParams = useMemo(
		() => ( {
			page: state.page,
			per_page: state.perPage,
			search: state.search,
			sort_field: state.sortField,
			sort_direction: state.sortDirection,
			filters: state.filters,
			filter_relation: state.filterRelation,
			fields: state.visibleColumns,
			lens: state.activeLens,
			flatten_variations: state.flattenVariations,
		} ),
		[
			state.page,
			state.perPage,
			state.search,
			state.sortField,
			state.sortDirection,
			state.filters,
			state.filterRelation,
			state.visibleColumns,
			state.activeLens,
			state.flattenVariations,
		]
	);

	// 1. Load entity schema when entity switches and sync browser URL cleanly.
	useEffect( () => {
		let cancelled = false;
		if ( window.history?.replaceState ) {
			const url = new URL( window.location.href );
			url.searchParams.set( 'entity', state.entity );
			window.history.replaceState( null, '', url.toString() );
		}
		dispatch.setLoadingSchema( true );
		fetchSchema( state.entity )
			.then( ( res ) => {
				if ( cancelled ) {
					return;
				}
				dispatch.setSchema( {
					entityMeta: res.entity,
					fields: res.fields,
					defaultFields: res.defaultFields,
					lenses: res.lenses,
					views: res.views,
					jsonSync: res.jsonSync,
				} );
			} )
			.catch( () => {
				if ( ! cancelled ) {
					dispatch.setLoadingSchema( false );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ state.entity ] );

	// 2. Debounce search input cleanly.
	useEffect( () => {
		const timer = window.setTimeout( () => {
			if ( searchInput !== state.search ) {
				dispatch.setQueryParam( { search: searchInput, page: 1 } );
			}
		}, 240 );
		return () => window.clearTimeout( timer );
	}, [ searchInput ] );

	// 3. Query records with field projection and AbortController cancellation.
	const reloadRecords = useCallback( () => {
		if ( state.loadingSchema || state.visibleColumns.length === 0 ) {
			return;
		}
		dispatch.setLoadingRecords( true );
		fetchRecords( state.entity, queryParams, true )
			.then( ( res ) => {
				dispatch.setRecords( res );
			} )
			.catch( ( err ) => {
				if ( err && ( err as { name?: string } ).name === 'AbortError' ) {
					return;
				}
				dispatch.setLoadingRecords( false );
			} );
	}, [ state.entity, state.loadingSchema, state.visibleColumns, queryParams ] );

	useEffect( () => {
		reloadRecords();
	}, [ reloadRecords ] );

	// Auto-dismiss quiet toast after 5s.
	useEffect( () => {
		if ( ! state.toast ) {
			return;
		}
		const t = window.setTimeout( () => dispatch.setToast( null ), 5500 );
		return () => window.clearTimeout( t );
	}, [ state.toast ] );

	const showToast = useCallback(
		( message: string, undoUuid?: string ) => {
			dispatch.setToast( { message, undoUuid } );
		},
		[ dispatch ]
	);

	// Commit staged edits (or instant single-cell edits) with optimistic concurrency checking.
	const executeCommit = useCallback(
		async ( editsToCommit: StagedEdit[], mode: 'inline' | 'paste' = 'inline', force = false ) => {
			if ( editsToCommit.length === 0 ) {
				return;
			}
			dispatch.setCommitting( true );
			try {
				const recordMap: Record<number, RecordRow> = {};
				for ( const r of state.records ) {
					recordMap[ r.id ] = r;
				}
				for ( const children of Object.values( state.expandedParents ) ) {
					for ( const c of children ) {
						recordMap[ c.id ] = c;
					}
				}

				const res = await commitEdits(
					state.entity,
					editsToCommit.map( ( e ) => ( {
						id: e.recordId,
						field: e.fieldId,
						value: e.newValue,
						expectedModifiedAt: recordMap[ e.recordId ]?._modifiedAt || undefined,
					} ) ),
					mode,
					force
				);
				const errorMap: Record<string, string> = {};
				for ( const err of res.errors || [] ) {
					errorMap[ cellKey( err.id, err.field ) ] = err.message;
				}
				const conflictSet = new Set( ( res.conflicts || [] ).map( ( c ) => cellKey( c.id, c.field ) ) );
				const nonConflicted = editsToCommit.filter( ( e ) => ! conflictSet.has( cellKey( e.recordId, e.fieldId ) ) );

				dispatch.applyCommittedEdits( nonConflicted, errorMap, res.modifiedMap || {} );

				if ( res.conflicts && res.conflicts.length > 0 ) {
					setConflicts( {
						items: res.conflicts,
						pendingEdits: editsToCommit.filter( ( e ) => conflictSet.has( cellKey( e.recordId, e.fieldId ) ) ),
						mode,
					} );
					showToast( `${ res.conflicts.length } ${ __( 'concurrent edit conflict(s) detected', 'datadeck' ) }` );
				} else {
					setConflicts( null );
				}

				if ( res.updated > 0 ) {
					showToast(
						`${ res.updated } ${ __( 'change(s) saved', 'datadeck' ) }`,
						res.batchUuid || undefined
					);
				}
			} catch ( err ) {
				dispatch.setCommitting( false );
				showToast( err instanceof Error ? err.message : __( 'Save failed', 'datadeck' ) );
			}
		},
		[ state.entity, state.records, state.expandedParents, dispatch, showToast ]
	);

	const handleStageOrSave = useCallback(
		( edits: StagedEdit[], mode: 'inline' | 'paste' = 'inline' ) => {
			dispatch.stageEdits( edits );
			if ( state.saveMode === 'instant' ) {
				executeCommit( edits, mode );
			}
		},
		[ state.saveMode, dispatch, executeCommit ]
	);

	// Global keyboard shortcuts: Cmd+K Palette, Cmd+S Save, Cmd+Z Undo.
	useEffect( () => {
		const onKey = ( e: KeyboardEvent ) => {
			const mod = e.metaKey || e.ctrlKey;
			if ( mod && e.key.toLowerCase() === 'k' ) {
				e.preventDefault();
				setShowPalette( ( v ) => ! v );
				return;
			}
			if ( mod && e.key.toLowerCase() === 's' ) {
				if ( Object.keys( state.stagedEdits ).length > 0 ) {
					e.preventDefault();
					executeCommit( Object.values( state.stagedEdits ), 'inline' );
				}
				return;
			}
			if ( mod && e.key.toLowerCase() === 'z' && ! state.editingCell ) {
				const activeTag = document.activeElement?.tagName;
				if ( activeTag === 'INPUT' || activeTag === 'TEXTAREA' ) {
					return;
				}
				e.preventDefault();
				if ( e.shiftKey ) {
					dispatch.redoStaged();
				} else {
					dispatch.undoStaged();
				}
			}
		};
		window.addEventListener( 'keydown', onKey );
		return () => window.removeEventListener( 'keydown', onKey );
	}, [ state.stagedEdits, state.editingCell, executeCommit, dispatch ] );

	const handleToggleVariations = useCallback(
		async ( parentId: number ) => {
			if ( state.expandedParents[ parentId ] ) {
				dispatch.setExpandedVariations( parentId, null );
				return;
			}
			const res = await fetchRecords(
				state.entity,
				{
					page: 1,
					per_page: 100,
					parent_id: parentId,
					fields: state.visibleColumns,
					sort_field: 'id',
					sort_direction: 'asc',
				},
				false
			);
			dispatch.setExpandedVariations( parentId, res.items );
		},
		[ state.entity, state.expandedParents, state.visibleColumns, dispatch ]
	);

	const handleQuickCreate = async ( e: React.FormEvent ) => {
		e.preventDefault();
		if ( ! quickTitle.trim() ) {
			return;
		}
		const payload: Record<string, unknown> = { title: quickTitle.trim(), status: 'draft' };
		if ( state.entity === 'product' ) {
			if ( quickPrice.trim() ) {
				payload.regular_price = Number( quickPrice );
			}
			if ( quickSku.trim() ) {
				payload.sku = quickSku.trim();
			}
		}
		const res = await createRecord( state.entity, payload );
		dispatch.prependRecord( res.record );
		setQuickTitle( '' );
		setQuickPrice( '' );
		setQuickSku( '' );
		showToast( `${ __( 'Created', 'datadeck' ) } #${ res.id }` );
	};

	const handleDuplicate = async ( id: number ) => {
		const res = await duplicateRecord( state.entity, id );
		dispatch.prependRecord( res.record );
		showToast( `${ __( 'Duplicated as', 'datadeck' ) } #${ res.id }` );
	};

	const handleDeleteSelected = async () => {
		if ( selectedIdList.length === 0 ) {
			return;
		}
		if ( ! window.confirm( `${ __( 'Move', 'datadeck' ) } ${ selectedIdList.length } ${ __( 'item(s) to trash?', 'datadeck' ) }` ) ) {
			return;
		}
		const res = await deleteRecords( state.entity, selectedIdList );
		dispatch.clearSelection();
		reloadRecords();
		showToast( `${ res.deleted.length } ${ __( 'item(s) moved to trash', 'datadeck' ) }` );
	};

	const handleDensityChange = ( d: DensityMode ) => {
		dispatch.setDensity( d );
		savePreferences( { density: d } ).catch( () => {} );
	};

	const handleSaveModeChange = ( m: SaveMode ) => {
		dispatch.setSaveMode( m );
		savePreferences( { saveMode: m } ).catch( () => {} );
	};

	const paletteCommands: PaletteCommand[] = useMemo( () => {
		const cmds: PaletteCommand[] = [
			{ id: 'bulk', label: __( 'Open Bulk Edit & AST Formula Engine', 'datadeck' ), hint: 'Bulk', run: () => setShowBulkModal( true ) },
			{ id: 'import', label: __( 'Import CSV / JSON (Match by ID or SKU)', 'datadeck' ), hint: 'Import', run: () => setShowImportModal( true ) },
			{ id: 'history', label: __( 'Open Change History & Rollback Drawer', 'datadeck' ), hint: 'Audit', run: () => setShowHistory( true ) },
			{ id: 'cols', label: __( 'Manage Columns, Formulas & Saved Views', 'datadeck' ), hint: 'Views', run: () => setShowColumns( true ) },
			{ id: 'export-csv', label: __( 'Export Filtered Records as CSV (Streaming)', 'datadeck' ), hint: 'CSV', run: () => window.open( buildExportUrl( state.entity, queryParams, 'csv' ), '_blank' ) },
			{ id: 'export-json', label: __( 'Export Filtered Records as JSON', 'datadeck' ), hint: 'JSON', run: () => window.open( buildExportUrl( state.entity, queryParams, 'json' ), '_blank' ) },
			{ id: 'dens-compact', label: __( 'Density: Compact (30px spreadsheet)', 'datadeck' ), run: () => handleDensityChange( 'compact' ) },
			{ id: 'dens-default', label: __( 'Density: Default (36px)', 'datadeck' ), run: () => handleDensityChange( 'default' ) },
			{ id: 'dens-comf', label: __( 'Density: Comfortable (48px)', 'datadeck' ) , run: () => handleDensityChange( 'comfortable' ) },
		];
		for ( const ent of state.entities ) {
			cmds.push( {
				id: `ent-${ ent.slug }`,
				label: `${ __( 'Switch to', 'datadeck' ) } ${ ent.pluralLabel }`,
				hint: ent.group,
				run: () => {
					setSearchInput( '' );
					dispatch.setEntity( ent.slug );
				},
			} );
		}
		for ( const lens of state.lenses ) {
			cmds.push( {
				id: `lens-${ lens.id }`,
				label: `${ __( 'Audit Lens:', 'datadeck' ) } ${ lens.label }`,
				hint: 'Lens',
				run: () => dispatch.setQueryParam( { activeLens: lens.id, page: 1 } ),
			} );
		}
		return cmds;
	}, [ state.entities, state.lenses, state.entity, queryParams ] );

	return (
		<div className="datadeck-root" data-density={ state.density }>
			{/* 44px Unified Command Bar */}
			<header className="rc-topbar">
				<div className="rc-topbar-left">
					<span className="rc-brand">
						<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.6" aria-hidden="true">
							<rect x="2.5" y="3" width="15" height="14" rx="2" />
							<line x1="2.5" y1="7.5" x2="17.5" y2="7.5" />
							<line x1="2.5" y1="12" x2="17.5" y2="12" />
							<line x1="8" y1="7.5" x2="8" y2="17" />
						</svg>
						DataDeck
					</span>

					<select
						className="rc-entity-select"
						value={ state.entity }
						aria-label={ __( 'Select entity', 'datadeck' ) }
						onChange={ ( e ) => {
							setSearchInput( '' );
							dispatch.setEntity( e.target.value );
						} }
					>
						{ state.entities.map( ( ent ) => (
							<option key={ ent.slug } value={ ent.slug }>
								{ ent.pluralLabel }
							</option>
						) ) }
					</select>

					<div className="rc-search-wrap">
						<Icon icon={ searchIcon } size={ 15 } />
						<input
							type="text"
							className="rc-search-input"
							placeholder={
								state.entity === 'product'
									? __( 'Search title, ID, SKU…', 'datadeck' )
									: state.entity === 'comment'
									? __( 'Search comment, author…', 'datadeck' )
									: state.entity === 'user'
									? __( 'Search user, email…', 'datadeck' )
									: state.entity === 'shop_order'
									? __( 'Search order #, email…', 'datadeck' )
									: __( 'Search title, ID, slug…', 'datadeck' )
							}
							value={ searchInput }
							onChange={ ( e ) => setSearchInput( e.target.value ) }
						/>
						{ searchInput && (
							<button type="button" className="rc-search-clear" onClick={ () => setSearchInput( '' ) }>
								×
							</button>
						) }
					</div>

					<button
						type="button"
						className={ `rc-btn ${ showFilters || state.filters.length > 0 ? 'rc-btn--active' : '' }` }
						onClick={ () => setShowFilters( ( v ) => ! v ) }
					>
						<Icon icon={ funnel } size={ 15 } />
						{ __( 'Filter', 'datadeck' ) }
						{ state.filters.length > 0 && ` (${ state.filters.length })` }
					</button>
				</div>

				<div className="rc-topbar-right">
					{ state.entityMeta?.canCreate && (
						<button
							type="button"
							className={ `rc-btn ${ showQuickAdd ? 'rc-btn--active' : '' }` }
							title={ __( 'Create a new draft row', 'datadeck' ) }
							onClick={ () => setShowQuickAdd( ( v ) => ! v ) }
						>
							<Icon icon={ plus } size={ 15 } />
							{ __( 'New', 'datadeck' ) }
						</button>
					) }

					<button
						type="button"
						className="rc-btn"
						title={ __( 'Bulk Edit & AST Formula Engine', 'datadeck' ) }
						onClick={ () => setShowBulkModal( true ) }
					>
						{ __( 'Bulk', 'datadeck' ) }
					</button>

					<button type="button" className="rc-btn" onClick={ () => setShowColumns( true ) }>
						<Icon icon={ columnsIcon } size={ 15 } />
						{ __( 'Columns', 'datadeck' ) }
					</button>

					<button type="button" className="rc-btn" onClick={ () => setShowHistory( true ) }>
						<Icon icon={ backup } size={ 15 } />
						{ __( 'History', 'datadeck' ) }
					</button>

					<button type="button" className="rc-btn" onClick={ () => setShowImportModal( true ) }>
						<Icon icon={ upload } size={ 15 } />
						{ __( 'Import', 'datadeck' ) }
					</button>

					<a
						className="rc-btn"
						href={ buildExportUrl( state.entity, queryParams, 'csv' ) }
						style={ { textDecoration: 'none' } }
					>
						<Icon icon={ download } size={ 15 } />
						CSV
					</a>

					<div className="rc-segmented" role="group" aria-label={ __( 'Row density', 'datadeck' ) }>
						{ ( [ 'compact', 'default', 'comfortable' ] as DensityMode[] ).map( ( d ) => (
							<button
								key={ d }
								type="button"
								className={ state.density === d ? 'is-active' : '' }
								title={ `Density: ${ d }` }
								onClick={ () => handleDensityChange( d ) }
							>
								{ d === 'compact' ? 'S' : d === 'default' ? 'M' : 'L' }
							</button>
						) ) }
					</div>

					<button type="button" className="rc-btn rc-btn--ghost" onClick={ () => setShowPalette( true ) }>
						<span className="rc-kbd">⌘K</span>
					</button>
				</div>
			</header>

			{/* 36px Audit Lenses & Saved Views Tab Strip */}
			<div className="rc-subbar">
				<div className="rc-tabs" role="tablist">
					{ state.lenses.map( ( lens ) => (
						<button
							key={ lens.id }
							type="button"
							role="tab"
							aria-selected={ state.activeViewId === null && state.activeLens === lens.id }
							title={ lens.description }
							className={ `rc-tab ${ state.activeViewId === null && state.activeLens === lens.id ? 'is-active' : '' }` }
							onClick={ () => dispatch.setQueryParam( { activeLens: lens.id, page: 1 } ) }
						>
							{ lens.label }
						</button>
					) ) }

					{ state.views.length > 0 && <span className="rc-divider-v" style={ { margin: '0 6px' } } /> }

					{ state.views.map( ( view ) => (
						<button
							key={ view.id }
							type="button"
							role="tab"
							aria-selected={ state.activeViewId === view.id }
							className={ `rc-tab ${ state.activeViewId === view.id ? 'is-active' : '' }` }
							onClick={ () => dispatch.applySavedView( view ) }
						>
							{ view.name }
							<span className="rc-tab-tag">View</span>
						</button>
					) ) }
				</div>

				<div style={ { display: 'flex', alignItems: 'center', gap: '10px', flexShrink: 0 } }>
					{ state.entity === 'product' && (
						<label style={ { display: 'inline-flex', alignItems: 'center', gap: '5px', fontSize: '12px', color: 'var(--rc-ink-secondary)', cursor: 'pointer', whiteSpace: 'nowrap' } }>
							<input
								type="checkbox"
								checked={ state.flattenVariations }
								onChange={ ( e ) =>
									dispatch.setQueryParam( { flattenVariations: e.target.checked, page: 1 } )
								}
							/>
							{ __( 'Flatten variations', 'datadeck' ) }
						</label>
					) }
					<div className="rc-segmented" title={ __( 'Cell save strategy', 'datadeck' ) }>
						<button
							type="button"
							className={ state.saveMode === 'staged' ? 'is-active' : '' }
							onClick={ () => handleSaveModeChange( 'staged' ) }
						>
							{ __( 'Staged Save', 'datadeck' ) }
						</button>
						<button
							type="button"
							className={ state.saveMode === 'instant' ? 'is-active' : '' }
							onClick={ () => handleSaveModeChange( 'instant' ) }
						>
							{ __( 'Instant Save', 'datadeck' ) }
						</button>
					</div>
				</div>
			</div>

			{/* Collapsible AND/OR Filter Bar */}
			{ showFilters && (
				<FilterBar
					fields={ state.fields }
					filters={ state.filters }
					relation={ state.filterRelation }
					onChange={ ( nextFilters, nextRel ) =>
						dispatch.setQueryParam( { filters: nextFilters, filterRelation: nextRel, page: 1 } )
					}
				/>
			) }

			{/* Inline Quick-Add Row */}
			{ showQuickAdd && (
				<form className="rc-quick-add" onSubmit={ handleQuickCreate }>
					<input
						type="text"
						style={ { width: '260px' } }
						placeholder={ `${ __( 'New', 'datadeck' ) } ${ state.entityMeta?.label || 'item' } ${ __( 'title…', 'datadeck' ) }` }
						value={ quickTitle }
						onChange={ ( e ) => setQuickTitle( e.target.value ) }
						autoFocus
					/>
					{ state.entity === 'product' && (
						<>
							<input
								type="text"
								style={ { width: '120px' } }
								placeholder="SKU"
								value={ quickSku }
								onChange={ ( e ) => setQuickSku( e.target.value ) }
							/>
							<input
								type="number"
								step="0.01"
								style={ { width: '110px' } }
								placeholder={ __( 'Price', 'datadeck' ) }
								value={ quickPrice }
								onChange={ ( e ) => setQuickPrice( e.target.value ) }
							/>
						</>
					) }
					<button type="submit" className="rc-btn rc-btn--primary">
						{ __( 'Create Draft', 'datadeck' ) }
					</button>
					<button type="button" className="rc-btn rc-btn--ghost" onClick={ () => setShowQuickAdd( false ) }>
						{ __( 'Cancel', 'datadeck' ) }
					</button>
				</form>
			) }

			{/* Contextual Action Ribbon (Staged Edits OR Selected Rows) */}
			{ ( stagedCount > 0 || selectedIdList.length > 0 ) && (
				<div className="rc-action-ribbon">
					<div className="rc-action-ribbon-left">
						<span className="rc-ribbon-dot" />
						{ stagedCount > 0 && (
							<strong>{ `${ stagedCount } ${ __( 'unsaved cell edit(s)', 'datadeck' ) }` }</strong>
						) }
						{ selectedIdList.length > 0 && (
							<span>{ `${ selectedIdList.length } ${ __( 'row(s) selected', 'datadeck' ) }` }</span>
						) }
					</div>
					<div className="rc-action-ribbon-right">
						{ selectedIdList.length > 0 && (
							<>
								<button type="button" className="rc-btn" onClick={ () => setShowBulkModal( true ) }>
									{ `${ __( 'Bulk Edit', 'datadeck' ) } (${ selectedIdList.length })` }
								</button>
								<button type="button" className="rc-btn rc-btn--danger" onClick={ handleDeleteSelected }>
									<Icon icon={ trash } size={ 14 } />
									{ __( 'Trash', 'datadeck' ) }
								</button>
								<button type="button" className="rc-btn rc-btn--ghost" onClick={ () => dispatch.clearSelection() }>
									{ __( 'Clear selection', 'datadeck' ) }
								</button>
							</>
						) }
						{ stagedCount > 0 && (
							<>
								<button
									type="button"
									className="rc-btn rc-btn--ghost"
									disabled={ state.undoStack.length === 0 }
									title="Undo (⌘Z)"
									onClick={ () => dispatch.undoStaged() }
								>
									<Icon icon={ undo } size={ 14 } />
								</button>
								<button
									type="button"
									className="rc-btn rc-btn--ghost"
									disabled={ state.redoStack.length === 0 }
									title="Redo (⇧⌘Z)"
									onClick={ () => dispatch.redoStaged() }
								>
									<Icon icon={ redo } size={ 14 } />
								</button>
								<button type="button" className="rc-btn" onClick={ () => dispatch.discardStaged() }>
									{ __( 'Discard', 'datadeck' ) }
								</button>
								<button
									type="button"
									className="rc-btn rc-btn--primary"
									disabled={ state.committing }
									onClick={ () => executeCommit( Object.values( state.stagedEdits ), 'inline' ) }
								>
									{ state.committing ? __( 'Saving…', 'datadeck' ) : `${ __( 'Save Changes', 'datadeck' ) } (${ stagedCount })` }
								</button>
							</>
						) }
					</div>
				</div>
			) }

			{/* Optimistic Concurrency Conflict Resolution Strip */}
			{ conflicts && conflicts.items.length > 0 && (
				<div className="rc-conflict-bar" role="alert">
					<div>
						<strong>{ __( 'Concurrent Edit Conflict:', 'datadeck' ) }</strong>{ ' ' }
						{ `${ conflicts.items.length } ${ __( 'field(s) were modified on the server while you were editing.', 'datadeck' ) }` }
						<span style={ { marginLeft: '8px', fontFamily: 'var(--rc-font-mono)', fontSize: '11.5px' } }>
							{ `#${ conflicts.items[ 0 ].id } ${ conflicts.items[ 0 ].field }: server="${ String( conflicts.items[ 0 ].serverValue ?? '' ) }" vs yours="${ String( conflicts.items[ 0 ].attemptedValue ?? '' ) }"` }
						</span>
					</div>
					<div style={ { display: 'flex', gap: '6px', flexShrink: 0 } }>
						<button
							type="button"
							className="rc-btn"
							onClick={ () => {
								setConflicts( null );
								dispatch.discardStaged();
								reloadRecords();
							} }
						>
							{ __( 'Reload Server Values', 'datadeck' ) }
						</button>
						<button
							type="button"
							className="rc-btn rc-btn--danger"
							onClick={ () => executeCommit( conflicts.pendingEdits, conflicts.mode, true ) }
						>
							{ __( 'Force Overwrite', 'datadeck' ) }
						</button>
					</div>
				</div>
			) }

			{/* Main Virtualized 60 FPS Spreadsheet Grid */}
			<SpreadsheetGrid
				records={ state.records }
				expandedParents={ state.expandedParents }
				columns={ visibleFieldDefs }
				columnWidths={ state.columnWidths }
				density={ state.density }
				sortField={ state.sortField }
				sortDirection={ state.sortDirection }
				selectedIds={ state.selectedIds }
				focusedCell={ state.focusedCell }
				editingCell={ state.editingCell }
				selectedRange={ state.selectedRange }
				loading={ state.loadingSchema || state.loadingRecords }
				stagedEdits={ state.stagedEdits }
				onSort={ ( fid ) => {
					const dir = state.sortField === fid && state.sortDirection === 'asc' ? 'desc' : 'asc';
					dispatch.setQueryParam( { sortField: fid, sortDirection: dir, page: 1 } );
				} }
				onResizeColumn={ ( fid, width ) => dispatch.setColumnWidth( fid, width ) }
				onReorderColumns={ ( fromId, toId ) => dispatch.reorderColumns( fromId, toId ) }
				onToggleSelectRow={ ( id ) => dispatch.toggleSelectRow( id ) }
				onToggleSelectAll={ ( ids ) => dispatch.setSelectedIds( ids ) }
				onFocusCell={ ( cell ) => dispatch.setFocusedCell( cell ) }
				onEditCell={ ( cell ) => dispatch.setEditingCell( cell ) }
				onSelectRange={ ( range ) => dispatch.setSelectedRange( range ) }
				onStageOrSaveEdits={ handleStageOrSave }
				onToggleVariations={ handleToggleVariations }
				onInspectRow={ ( row ) => setInspectRecord( { record: row, tab: 'edit' } ) }
				onDuplicateRow={ handleDuplicate }
				onOpenContextMenu={ ( menu ) => setContextMenu( menu ) }
			/>

			{/* 36px Footer with Pagination & Live SQL Query Budget Telemetry */}
			<footer className="rc-footer">
				<div className="rc-footer-left">
					<span>{ `${ state.total.toLocaleString() } ${ __( 'records', 'datadeck' ) }` }</span>
					<select
						className="rc-entity-select"
						style={ { height: '24px', fontSize: '11.5px', paddingRight: '22px' } }
						value={ state.perPage }
						onChange={ ( e ) => dispatch.setQueryParam( { perPage: Number( e.target.value ), page: 1 } ) }
					>
						{ [ 25, 50, 100, 250, 500 ].map( ( n ) => (
							<option key={ n } value={ n }>
								{ `${ n } / page` }
							</option>
						) ) }
					</select>
					{ state.perf && (
						<span className="rc-perf-pill" title={ `Query plan: ${ state.perf.plan }` }>
							{ `${ state.perf.queries } SQL · ${ state.perf.durationMs }ms` }
						</span>
					) }
				</div>

				<div className="rc-footer-right">
					<span>{ `Page ${ state.page } / ${ state.totalPages }` }</span>
					<button
						type="button"
						className="rc-btn"
						style={ { height: '24px' } }
						disabled={ state.page <= 1 }
						onClick={ () => dispatch.setQueryParam( { page: state.page - 1 } ) }
					>
						{ __( 'Prev', 'datadeck' ) }
					</button>
					<button
						type="button"
						className="rc-btn"
						style={ { height: '24px' } }
						disabled={ state.page >= state.totalPages }
						onClick={ () => dispatch.setQueryParam( { page: state.page + 1 } ) }
					>
						{ __( 'Next', 'datadeck' ) }
					</button>
				</div>
			</footer>

			{/* Lazy-Loaded Drawers & Modals */}
			<Suspense fallback={ null }>
				{ showBulkModal && (
					<BulkFormulaModal
						entity={ state.entity }
						fields={ state.fields }
						selectedIds={ selectedIdList }
						totalMatching={ state.total }
						queryParams={ queryParams }
						onClose={ () => setShowBulkModal( false ) }
						onCompleted={ ( msg, uuid ) => {
							setShowBulkModal( false );
							dispatch.clearSelection();
							reloadRecords();
							showToast( msg, uuid );
						} }
					/>
				) }

				{ showImportModal && (
					<CsvImportModal
						entity={ state.entity }
						entityLabel={ state.entityMeta?.pluralLabel || state.entity }
						fields={ state.fields }
						onClose={ () => setShowImportModal( false ) }
						onCompleted={ ( msg, uuid ) => {
							setShowImportModal( false );
							reloadRecords();
							showToast( msg, uuid );
						} }
					/>
				) }

				{ showHistory && (
					<HistoryDrawer
						entity={ state.entity }
						onClose={ () => setShowHistory( false ) }
						onRolledBack={ ( msg ) => {
							reloadRecords();
							showToast( msg );
						} }
					/>
				) }

				{ showColumns && (
					<ColumnsViewsModal
						entity={ state.entity }
						fields={ state.fields }
						visibleColumns={ state.visibleColumns }
						columnWidths={ state.columnWidths }
						density={ state.density }
						sortField={ state.sortField }
						sortDirection={ state.sortDirection }
						filters={ state.filters }
						filterRelation={ state.filterRelation }
						views={ state.views }
						jsonSync={ state.jsonSync }
						onUpdateColumns={ ( cols ) => dispatch.setVisibleColumns( cols ) }
						onUpdateFields={ ( nextFields ) => dispatch.setFields( nextFields ) }
						onUpdateViews={ ( views ) => dispatch.setViews( views ) }
						onClose={ () => setShowColumns( false ) }
					/>
				) }

				{ inspectRecord && (
					<InspectorDrawer
						entity={ state.entity }
						record={ inspectRecord.record }
						fields={ state.fields }
						initialTab={ inspectRecord.tab }
						initialHistoryField={ inspectRecord.field }
						onStageEdits={ ( edits ) => handleStageOrSave( edits, 'inline' ) }
						onRestoredCell={ ( msg ) => {
							reloadRecords();
							showToast( msg );
						} }
						onClose={ () => setInspectRecord( null ) }
					/>
				) }

				{ showPalette && (
					<CommandPalette commands={ paletteCommands } onClose={ () => setShowPalette( false ) } />
				) }
			</Suspense>

			{ contextMenu && (
				<GridContextMenu
					menu={ contextMenu }
					selectedCount={ selectedIdList.length }
					onFilterEquals={ ( field, val ) => {
						const next = [
							...state.filters,
							{ id: `ctx_${ Date.now() }`, field: field.id, operator: 'is', value: String( val ) },
						];
						setShowFilters( true );
						dispatch.setQueryParam( { filters: next, page: 1 } );
					} }
					onFilterNotEquals={ ( field, val ) => {
						const next = [
							...state.filters,
							{ id: `ctx_${ Date.now() }`, field: field.id, operator: 'isnot', value: String( val ) },
						];
						setShowFilters( true );
						dispatch.setQueryParam( { filters: next, page: 1 } );
					} }
					onCopyValue={ ( val ) => {
						let txt = '';
						if ( val !== null && val !== undefined ) {
							if ( Array.isArray( val ) ) {
								txt = val
									.map( ( item ) =>
										typeof item === 'object' && item && 'label' in item
											? String( ( item as { label: unknown } ).label )
											: String( item )
									)
									.join( ', ' );
							} else if ( typeof val === 'object' && 'url' in ( val as Record<string, unknown> ) ) {
								txt = String( ( val as { url: unknown } ).url || '' );
							} else if ( typeof val === 'object' ) {
								txt = JSON.stringify( val );
							} else {
								txt = String( val );
							}
						}
						if ( navigator.clipboard?.writeText ) {
							navigator.clipboard.writeText( txt ).catch( () => {} );
						}
						showToast( __( 'Copied cell value', 'datadeck' ) );
					} }
					onApplyToSelected={ ( field, val ) => {
						const byId: Record<number, RecordRow> = {};
						state.records.forEach( ( r ) => {
							byId[ r.id ] = r;
						} );
						const edits: StagedEdit[] = selectedIdList
							.filter( ( id ) => byId[ id ] && byId[ id ]._canEdit !== false )
							.map( ( id ) => ( {
								recordId: id,
								fieldId: field.id,
								oldValue: byId[ id ][ field.id ],
								newValue: val,
							} ) );
						if ( edits.length > 0 ) {
							handleStageOrSave( edits, 'inline' );
							showToast( `${ __( 'Applied to', 'datadeck' ) } ${ edits.length } ${ __( 'selected row(s)', 'datadeck' ) }` );
						}
					} }
					onInspectHistory={ ( record, fieldId ) =>
						setInspectRecord( { record, tab: 'history', field: fieldId } )
					}
					onDuplicateRow={ handleDuplicate }
					onClose={ () => setContextMenu( null ) }
				/>
			) }

			{/* Quiet Toast with One-Click Rollback */}
			{ state.toast && (
				<div className="rc-toast" role="status" aria-live="polite">
					<span>{ state.toast.message }</span>
					{ state.toast.undoUuid && (
						<button
							type="button"
							onClick={ async () => {
								const uuid = state.toast?.undoUuid;
								if ( ! uuid ) {
									return;
								}
								dispatch.setToast( null );
								const res = await rollbackBatch( uuid );
								reloadRecords();
								showToast( `${ res.restored } ${ __( 'record(s) restored', 'datadeck' ) }` );
							} }
						>
							{ __( 'Rollback', 'datadeck' ) }
						</button>
					) }
				</div>
			) }
		</div>
	);
}
