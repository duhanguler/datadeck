import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { deleteView, saveView, syncViewsFromJson } from '../api/client';
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
	onUpdateViews,
	onClose,
}: ColumnsViewsModalProps ) {
	const [ tab, setTab ] = useState<'columns' | 'views'>( 'columns' );
	const [ search, setSearch ] = useState( '' );
	const [ viewName, setViewName ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ syncMessage, setSyncMessage ] = useState<string | null>( null );

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
		} finally {
			setSaving( false );
		}
	};

	const handleDeleteView = async ( id: string ) => {
		await deleteView( id );
		onUpdateViews( views.filter( ( v ) => v.id !== id ) );
	};

	const handleSyncJson = async () => {
		const res = await syncViewsFromJson();
		setSyncMessage( `${ res.synced } view(s) synced from datadeck-json/.` );
	};

	return (
		<div className="rc-backdrop rc-backdrop--drawer" onClick={ onClose }>
			<div className="rc-drawer" onClick={ ( e ) => e.stopPropagation() } role="dialog" aria-modal="true">
				<div className="rc-modal-header">
					<div className="rc-segmented">
						<button type="button" className={ tab === 'columns' ? 'is-active' : '' } onClick={ () => setTab( 'columns' ) }>
							{ `${ __( 'Columns', 'datadeck' ) } (${ visibleColumns.length })` }
						</button>
						<button type="button" className={ tab === 'views' ? 'is-active' : '' } onClick={ () => setTab( 'views' ) }>
							{ `${ __( 'Saved Views & JSON', 'datadeck' ) } (${ views.length })` }
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
					) : (
						<>
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
