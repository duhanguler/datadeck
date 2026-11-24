import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	deleteComputedField,
	deleteView,
	saveComputedField,
	saveView,
	syncViewsFromJson,
	validateFormula,
} from '../api/client';
import type {
	DensityMode,
	FieldDefinition,
	FilterClause,
	JsonSyncStatus,
	SavedView,
} from '../types';

interface ColumnsViewsModalProps {
	entity: string;
	fields: FieldDefinition[];
	visibleColumns: string[];
	columnWidths: Record<string, number>;
	density: DensityMode;
	sortField: string;
	sortDirection: 'asc' | 'desc';
	filters: FilterClause[];
	filterRelation: 'AND' | 'OR';
	views: SavedView[];
	jsonSync: JsonSyncStatus | null;
	onUpdateColumns: ( columns: string[] ) => void;
	onUpdateFields: ( fields: FieldDefinition[] ) => void;
	onUpdateViews: ( views: SavedView[] ) => void;
	onClose: () => void;
}

export default function ColumnsViewsModal( {
	entity,
	fields,
	visibleColumns,
	columnWidths,
	density,
	sortField,
	sortDirection,
	filters,
	filterRelation,
	views,
	jsonSync,
	onUpdateColumns,
	onUpdateFields,
	onUpdateViews,
	onClose,
}: ColumnsViewsModalProps ) {
	const [ tab, setTab ] = useState<'columns' | 'formulas' | 'views'>( 'columns' );
	const [ search, setSearch ] = useState( '' );
	const [ viewName, setViewName ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ syncMessage, setSyncMessage ] = useState<string | null>( null );
	const [ viewError, setViewError ] = useState<string | null>( null );

	const [ cfLabel, setCfLabel ] = useState( '' );
	const [ cfFormula, setCfFormula ] = useState( '' );
	const [ cfFormat, setCfFormat ] = useState<'number' | 'currency' | 'percent' | 'text'>( 'currency' );
	const [ cfError, setCfError ] = useState<string | null>( null );

	const computedFields = useMemo(
		() => fields.filter( ( f ) => Boolean( f.extra?.computed ) ),
		[ fields ]
	);

	const grouped = useMemo( () => {
		const map: Record<string, FieldDefinition[]> = {};
		const q = search.trim().toLowerCase();
		for ( const f of fields ) {
			if ( q && ! f.label.toLowerCase().includes( q ) && ! f.id.toLowerCase().includes( q ) ) {
				continue;
			}
			if ( ! map[ f.group ] ) {
				map[ f.group ] = [];
			}
			map[ f.group ].push( f );
		}
		return map;
	}, [ fields, search ] );

	const toggleColumn = ( id: string ) => {
		if ( visibleColumns.includes( id ) ) {
			if ( visibleColumns.length <= 1 ) {
				return;
			}
			onUpdateColumns( visibleColumns.filter( ( c ) => c !== id ) );
		} else {
			onUpdateColumns( [ ...visibleColumns, id ] );
		}
	};

	const moveColumn = ( id: string, dir: -1 | 1 ) => {
		const idx = visibleColumns.indexOf( id );
		if ( idx < 0 ) {
			return;
		}
		const target = idx + dir;
		if ( target < 0 || target >= visibleColumns.length ) {
			return;
		}
		const copy = [ ...visibleColumns ];
		const [ item ] = copy.splice( idx, 1 );
		copy.splice( target, 0, item );
		onUpdateColumns( copy );
	};

	const handleSaveView = async () => {
		if ( ! viewName.trim() ) {
			return;
		}
		setSaving( true );
		setViewError( null );
		try {
			const res = await saveView( entity, {
				name: viewName.trim(),
				columns: visibleColumns,
				columnWidths,
				density,
				sortField,
				sortDirection,
				filters,
				filterRelation,
			} );
			onUpdateViews( [ ...views.filter( ( v ) => v.id !== res.view.id ), res.view ] );
			setViewName( '' );
		} catch ( err ) {
			setViewError( err instanceof Error ? err.message : __( 'Failed to save view.', 'datadeck' ) );
		} finally {
			setSaving( false );
		}
	};

	const handleDeleteView = async ( id: string ) => {
		setViewError( null );
		try {
			await deleteView( id );
			onUpdateViews( views.filter( ( v ) => v.id !== id ) );
		} catch ( err ) {
			setViewError( err instanceof Error ? err.message : __( 'Failed to delete view.', 'datadeck' ) );
		}
	};

	const handleSyncJson = async () => {
		setViewError( null );
		try {
			const res = await syncViewsFromJson( entity );
			if ( res.views ) {
				onUpdateViews( res.views );
			}
			setSyncMessage( `${ res.synced } view(s) synced from datadeck-json/.` );
		} catch ( err ) {
			setViewError( err instanceof Error ? err.message : __( 'Failed to sync views.', 'datadeck' ) );
		}
	};

	const handleSaveComputed = async () => {
		if ( ! cfLabel.trim() || ! cfFormula.trim() ) {
			return;
		}
		setSaving( true );
		setCfError( null );
		try {
			const check = await validateFormula( cfFormula );
			if ( ! check.valid ) {
				setCfError( check.error || __( 'Invalid formula syntax.', 'datadeck' ) );
				return;
			}
			const res = await saveComputedField( entity, {
				label: cfLabel.trim(),
				formula: cfFormula.trim(),
				format: cfFormat,
			} );
			onUpdateFields( res.fields );
			if ( ! visibleColumns.includes( res.computedField.id ) ) {
				onUpdateColumns( [ ...visibleColumns, res.computedField.id ] );
			}
			setCfLabel( '' );
			setCfFormula( '' );
		} catch ( err ) {
			setCfError( err instanceof Error ? err.message : __( 'Failed to save formula column.', 'datadeck' ) );
		} finally {
			setSaving( false );
		}
	};

	const handleDeleteComputed = async ( fieldId: string ) => {
		setCfError( null );
		try {
			const res = await deleteComputedField( entity, fieldId );
			onUpdateFields( res.fields );
		} catch ( err ) {
			setCfError( err instanceof Error ? err.message : __( 'Failed to delete formula column.', 'datadeck' ) );
		}
	};

	return (
		<div className="rc-backdrop rc-backdrop--drawer" onClick={ onClose }>
			<div className="rc-drawer" onClick={ ( e ) => e.stopPropagation() } role="dialog" aria-modal="true">
				<div className="rc-modal-header">
					<div className="rc-segmented">
						<button type="button" className={ tab === 'columns' ? 'is-active' : '' } onClick={ () => setTab( 'columns' ) }>
							{ `${ __( 'Columns', 'datadeck' ) } (${ visibleColumns.length })` }
						</button>
						<button type="button" className={ tab === 'formulas' ? 'is-active' : '' } onClick={ () => setTab( 'formulas' ) }>
							{ `${ __( 'Formulas', 'datadeck' ) } (${ computedFields.length })` }
						</button>
						<button type="button" className={ tab === 'views' ? 'is-active' : '' } onClick={ () => setTab( 'views' ) }>
							{ `${ __( 'Views & JSON', 'datadeck' ) } (${ views.length })` }
						</button>
					</div>
					<button type="button" className="rc-btn rc-btn--ghost" onClick={ onClose }>
						{ __( 'Done', 'datadeck' ) }
					</button>
				</div>

				<div className="rc-modal-body">
					{ tab === 'columns' ? (
						<>
							<input
								type="text"
								className="rc-search-input"
								style={ { paddingLeft: '10px' } }
								placeholder={ __( 'Filter fields (ACF, WooCommerce, SEO, Meta)…', 'datadeck' ) }
								value={ search }
								onChange={ ( e ) => setSearch( e.target.value ) }
							/>

							<div>
								<div style={ { fontSize: '11.5px', fontWeight: 600, color: 'var(--rc-ink-secondary)', marginBottom: '6px', textTransform: 'uppercase' } }>
									{ __( 'Active Order', 'datadeck' ) }
								</div>
								<div style={ { display: 'flex', flexDirection: 'column', gap: '4px' } }>
									{ visibleColumns.map( ( cid, idx ) => {
										const def = fields.find( ( f ) => f.id === cid );
										if ( ! def ) {
											return null;
										}
										return (
											<div
												key={ cid }
												style={ {
													display: 'flex',
													alignItems: 'center',
													justifyContent: 'space-between',
													padding: '5px 8px',
													border: '1px solid var(--rc-border)',
													borderRadius: '4px',
													fontSize: '12.5px',
												} }
											>
												<span>
													{ def.label }{ ' ' }
													<span style={ { color: 'var(--rc-ink-muted)', fontSize: '11px' } }>({ def.group })</span>
												</span>
												<div style={ { display: 'flex', gap: '4px' } }>
													<button type="button" className="rc-icon-btn" disabled={ idx === 0 } onClick={ () => moveColumn( cid, -1 ) }>↑</button>
													<button type="button" className="rc-icon-btn" disabled={ idx === visibleColumns.length - 1 } onClick={ () => moveColumn( cid, 1 ) }>↓</button>
													<button type="button" className="rc-icon-btn" onClick={ () => toggleColumn( cid ) }>×</button>
												</div>
											</div>
										);
									} ) }
								</div>
							</div>

							{ Object.entries( grouped ).map( ( [ groupName, list ] ) => (
								<div key={ groupName }>
									<div style={ { fontSize: '11.5px', fontWeight: 600, color: 'var(--rc-ink-secondary)', marginBottom: '6px', textTransform: 'uppercase' } }>
										{ groupName }
									</div>
									<div style={ { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '4px' } }>
										{ list.map( ( f ) => {
											const active = visibleColumns.includes( f.id );
											return (
												<label key={ f.id } className="rc-popover-opt" style={ { border: '1px solid var(--rc-border-grid)' } }>
													<input type="checkbox" checked={ active } onChange={ () => toggleColumn( f.id ) } />
													<span style={ { overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } }>{ f.label }</span>
												</label>
											);
										} ) }
									</div>
								</div>
							) ) }
						</>
					) : tab === 'formulas' ? (
						<>
							<div style={ { fontSize: '12px', color: 'var(--rc-ink-secondary)' } }>
								{ __( 'Virtual AST formula columns evaluate live on hydrated rows without touching your database. Dependencies hydrate automatically even when hidden.', 'datadeck' ) }
							</div>

							<div className="rc-field-row">
								<label>{ __( 'Column Label', 'datadeck' ) }</label>
								<input
									type="text"
									placeholder={ __( 'e.g. Net Margin or Inventory Value', 'datadeck' ) }
									value={ cfLabel }
									onChange={ ( e ) => setCfLabel( e.target.value ) }
								/>
							</div>

							<div className="rc-field-row">
								<label>{ __( 'AST Formula Expression', 'datadeck' ) }</label>
								<input
									type="text"
									className="rc-mono-input"
									placeholder="round(regular_price * stock_quantity, 2)"
									value={ cfFormula }
									onChange={ ( e ) => {
										setCfFormula( e.target.value );
										setCfError( null );
									} }
								/>
								{ cfError && <span style={ { fontSize: '11.5px', color: 'var(--rc-danger)' } }>{ cfError }</span> }
							</div>

							<div style={ { display: 'flex', gap: '8px', alignItems: 'flex-end' } }>
								<div className="rc-field-row" style={ { flex: 1 } }>
									<label>{ __( 'Display Format', 'datadeck' ) }</label>
									<select value={ cfFormat } onChange={ ( e ) => setCfFormat( e.target.value as 'number' | 'currency' | 'percent' | 'text' ) }>
										<option value="currency">{ __( 'Currency', 'datadeck' ) }</option>
										<option value="number">{ __( 'Number', 'datadeck' ) }</option>
										<option value="percent">{ __( 'Percentage (%)', 'datadeck' ) }</option>
										<option value="text">{ __( 'Text', 'datadeck' ) }</option>
									</select>
								</div>
								<button
									type="button"
									className="rc-btn rc-btn--primary"
									disabled={ saving || ! cfLabel.trim() || ! cfFormula.trim() }
									onClick={ handleSaveComputed }
								>
									{ __( 'Add Formula Column', 'datadeck' ) }
								</button>
							</div>

							{ computedFields.length > 0 && (
								<div style={ { display: 'flex', flexDirection: 'column', gap: '6px' } }>
									<div style={ { fontSize: '11.5px', fontWeight: 600, color: 'var(--rc-ink-secondary)', textTransform: 'uppercase' } }>
										{ __( 'Active Computed Columns', 'datadeck' ) }
									</div>
									{ computedFields.map( ( cf ) => {
										const isVisible = visibleColumns.includes( cf.id );
										const isBuiltIn = Boolean( cf.extra?.builtIn );
										return (
											<div
												key={ cf.id }
												style={ {
													display: 'flex',
													alignItems: 'center',
													justifyContent: 'space-between',
													gap: '8px',
													padding: '8px 10px',
													border: '1px solid var(--rc-border)',
													borderRadius: '4px',
												} }
											>
												<div style={ { minWidth: 0 } }>
													<div style={ { fontSize: '12.5px', fontWeight: 600 } }>
														{ cf.label }{ ' ' }
														{ isBuiltIn && <span className="rc-tab-tag">Built-in</span> }
													</div>
													<div style={ { fontSize: '11px', fontFamily: 'var(--rc-font-mono)', color: 'var(--rc-ink-muted)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } }>
														{ String( cf.extra?.formula || '' ) }
													</div>
												</div>
												<div style={ { display: 'flex', gap: '6px', flexShrink: 0 } }>
													<button type="button" className="rc-btn" onClick={ () => toggleColumn( cf.id ) }>
														{ isVisible ? __( 'Hide', 'datadeck' ) : __( 'Show', 'datadeck' ) }
													</button>
													{ ! isBuiltIn && (
														<button type="button" className="rc-btn rc-btn--danger" onClick={ () => handleDeleteComputed( cf.id ) }>
															{ __( 'Delete', 'datadeck' ) }
														</button>
													) }
												</div>
											</div>
										);
									} ) }
								</div>
							) }
						</>
					) : (
						<>
							{ viewError && (
								<div style={ { padding: '8px', fontSize: '12px', color: 'var(--rc-danger)', background: 'var(--rc-danger-bg)', borderRadius: '4px' } }>
									{ viewError }
								</div>
							) }
							<div className="rc-field-row">
								<label>{ __( 'Save Current Columns, Widths & Filters as View', 'datadeck' ) }</label>
								<div style={ { display: 'flex', gap: '6px' } }>
									<input
										type="text"
										placeholder={ __( 'e.g. Store Manager Inventory', 'datadeck' ) }
										value={ viewName }
										onChange={ ( e ) => setViewName( e.target.value ) }
									/>
									<button type="button" className="rc-btn rc-btn--primary" disabled={ saving || ! viewName.trim() } onClick={ handleSaveView }>
										{ __( 'Save', 'datadeck' ) }
									</button>
								</div>
							</div>

							{ views.length > 0 && (
								<div style={ { display: 'flex', flexDirection: 'column', gap: '6px' } }>
									{ views.map( ( v ) => (
										<div
											key={ v.id }
											style={ {
												display: 'flex',
												alignItems: 'center',
												justifyContent: 'space-between',
												padding: '8px 10px',
												border: '1px solid var(--rc-border)',
												borderRadius: '4px',
											} }
										>
											<div>
												<div style={ { fontSize: '13px', fontWeight: 600 } }>{ v.name }</div>
												<div style={ { fontSize: '11.5px', color: 'var(--rc-ink-muted)' } }>
													{ `${ v.columns.length } columns · ${ v.filters.length } filters · ${ v.density }` }
												</div>
											</div>
											<button type="button" className="rc-btn rc-btn--danger" onClick={ () => handleDeleteView( v.id ) }>
												{ __( 'Delete', 'datadeck' ) }
											</button>
										</div>
									) ) }
								</div>
							) }

							<div style={ { padding: '10px 12px', background: 'var(--rc-bg-canvas)', border: '1px solid var(--rc-border)', borderRadius: '4px', fontSize: '12px' } }>
								<div style={ { fontWeight: 600, marginBottom: '4px' } }>
									{ __( 'Git-Syncable Local JSON (datadeck-json/)', 'datadeck' ) }
								</div>
								<div style={ { color: 'var(--rc-ink-secondary)', marginBottom: '8px' } }>
									{ jsonSync?.enabled
										? `${ __( 'Active sync directory:', 'datadeck' ) } ${ jsonSync.savePath }`
										: __( 'Create a datadeck-json/ folder inside your active theme to automatically version-control saved views in Git.', 'datadeck' ) }
								</div>
								{ jsonSync && jsonSync.syncAvailable.length > 0 && (
									<button type="button" className="rc-btn rc-btn--primary" onClick={ handleSyncJson }>
										{ `${ __( 'Sync', 'datadeck' ) } ${ jsonSync.syncAvailable.length } ${ __( 'view(s) from JSON', 'datadeck' ) }` }
									</button>
								) }
								{ syncMessage && (
									<div style={ { marginTop: '6px', color: 'var(--rc-success)' } }>{ syncMessage }</div>
								) }
							</div>
						</>
					) }
				</div>
			</div>
		</div>
	);
}
