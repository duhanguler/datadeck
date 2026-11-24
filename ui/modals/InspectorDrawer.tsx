import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { fetchRecordHistory, fetchSingleRecord, rollbackSingleChange } from '../api/client';
import type { FieldDefinition, RecordHistoryEntry, RecordRow, StagedEdit } from '../types';

interface InspectorDrawerProps {
	entity: string;
	record: RecordRow;
	fields: FieldDefinition[];
	initialTab?: 'edit' | 'history';
	initialHistoryField?: string;
	onStageEdits: ( edits: StagedEdit[] ) => void;
	onRestoredCell?: ( msg: string ) => void;
	onClose: () => void;
}

/**
 * Lightweight, zero-bloat side-panel record inspector with Edit Fields & Cell-Level Audit History.
 */
export default function InspectorDrawer( {
	entity,
	record,
	fields,
	initialTab = 'edit',
	initialHistoryField = '',
	onStageEdits,
	onRestoredCell,
	onClose,
}: InspectorDrawerProps ) {
	const [ tab, setTab ] = useState<'edit' | 'history'>( initialTab );
	const [ baselineRecord, setBaselineRecord ] = useState<RecordRow>( { ...record } );
	const [ localData, setLocalData ] = useState<RecordRow>( { ...record } );
	const [ loadingRecord, setLoadingRecord ] = useState( false );
	const [ historyField, setHistoryField ] = useState<string>( initialHistoryField );
	const [ historyItems, setHistoryItems ] = useState<RecordHistoryEntry[]>( [] );
	const [ loadingHistory, setLoadingHistory ] = useState( false );
	const [ restoringId, setRestoringId ] = useState<number | null>( null );
	const [ errorMsg, setErrorMsg ] = useState<string | null>( null );
	const canEditRecord = baselineRecord._canEdit !== false && record._canEdit !== false;

	useEffect( () => {
		let cancelled = false;
		setBaselineRecord( { ...record } );
		setLocalData( { ...record } );
		setErrorMsg( null );
		setLoadingRecord( true );
		fetchSingleRecord( entity, record.id )
			.then( ( res ) => {
				if ( ! cancelled && res.record ) {
					const merged = { ...record, ...res.record };
					setBaselineRecord( merged );
					setLocalData( merged );
				}
			} )
			.catch( () => {} )
			.finally( () => {
				if ( ! cancelled ) {
					setLoadingRecord( false );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ entity, record ] );

	useEffect( () => {
		setTab( initialTab );
		setHistoryField( initialHistoryField );
	}, [ record.id, initialTab, initialHistoryField ] );

	const editableFields = useMemo(
		() => fields.filter( ( f ) => f.editable && ! [ 'media', 'taxonomy' ].includes( f.type ) ),
		[ fields ]
	);

	const grouped = useMemo( () => {
		const map: Record<string, FieldDefinition[]> = {};
		for ( const f of editableFields ) {
			if ( ! map[ f.group ] ) {
				map[ f.group ] = [];
			}
			map[ f.group ].push( f );
		}
		return map;
	}, [ editableFields ] );

	useEffect( () => {
		if ( tab !== 'history' ) {
			return;
		}
		let cancelled = false;
		setLoadingHistory( true );
		fetchRecordHistory( entity, record.id, historyField || undefined )
			.then( ( res ) => {
				if ( ! cancelled ) {
					setHistoryItems( res.changes );
				}
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setLoadingHistory( false );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ tab, entity, record.id, historyField ] );

	const setField = ( id: string, val: unknown ) => {
		setLocalData( ( prev ) => ( { ...prev, [ id ]: val } ) );
	};

	const handleApply = () => {
		if ( ! canEditRecord ) {
			onClose();
			return;
		}
		const staged: StagedEdit[] = [];
		for ( const f of editableFields ) {
			if ( JSON.stringify( localData[ f.id ] ) !== JSON.stringify( baselineRecord[ f.id ] ) ) {
				staged.push( {
					recordId: record.id,
					fieldId: f.id,
					oldValue: baselineRecord[ f.id ],
					newValue: localData[ f.id ],
				} );
			}
		}
		if ( staged.length > 0 ) {
			onStageEdits( staged );
		}
		onClose();
	};

	const handleRestoreChange = async ( entry: RecordHistoryEntry ) => {
		setRestoringId( entry.changeId );
		setErrorMsg( null );
		try {
			const res = await rollbackSingleChange( entry.changeId );
			onRestoredCell?.( `${ __( 'Restored', 'datadeck' ) } ${ res.field } #${ res.objectId }` );
			const refreshed = await fetchRecordHistory( entity, record.id, historyField || undefined );
			setHistoryItems( refreshed.changes );
		} catch ( err ) {
			setErrorMsg( err instanceof Error ? err.message : __( 'Failed to restore cell.', 'datadeck' ) );
		} finally {
			setRestoringId( null );
		}
	};

	const formatVal = ( v: unknown ): string => {
		if ( v === null || v === undefined || v === '' ) {
			return '—';
		}
		if ( typeof v === 'object' ) {
			return JSON.stringify( v );
		}
		return String( v );
	};

	return (
		<div className="rc-backdrop rc-backdrop--drawer" onClick={ onClose }>
			<div className="rc-drawer" onClick={ ( e ) => e.stopPropagation() } role="dialog" aria-modal="true">
				<div className="rc-modal-header">
					<div style={ { display: 'flex', flexDirection: 'column', gap: '6px', minWidth: 0 } }>
						<h2 style={ { overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } }>
							{ `#${ record.id } — ${ String( record.title || record.display_name || record.customer_name || record.author_name || '' ) }` }
						</h2>
						<div className="rc-segmented" style={ { alignSelf: 'flex-start' } }>
							<button type="button" className={ tab === 'edit' ? 'is-active' : '' } onClick={ () => setTab( 'edit' ) }>
								{ __( 'Edit Fields', 'datadeck' ) }
							</button>
							<button type="button" className={ tab === 'history' ? 'is-active' : '' } onClick={ () => setTab( 'history' ) }>
								{ __( 'Audit History', 'datadeck' ) }
							</button>
						</div>
					</div>
					<button type="button" className="rc-btn rc-btn--ghost" onClick={ onClose }>
						{ __( 'Close', 'datadeck' ) }
					</button>
				</div>

				<div className="rc-modal-body">
					{ errorMsg && (
						<div style={ { padding: '8px', fontSize: '12px', color: 'var(--rc-danger)', background: 'var(--rc-danger-bg)', borderRadius: '4px' } }>
							{ errorMsg }
						</div>
					) }
					{ tab === 'edit' ? (
						loadingRecord ? (
							<div style={ { fontSize: '12.5px', color: 'var(--rc-ink-muted)' } }>
								{ __( 'Loading all record fields…', 'datadeck' ) }
							</div>
						) : (
						Object.entries( grouped ).map( ( [ group, list ] ) => (
							<div key={ group } style={ { display: 'flex', flexDirection: 'column', gap: '10px' } }>
								<div
									style={ {
										fontSize: '11px',
										fontWeight: 600,
										textTransform: 'uppercase',
										letterSpacing: '0.04em',
										color: 'var(--rc-ink-muted)',
										borderBottom: '1px solid var(--rc-border-grid)',
										paddingBottom: '4px',
									} }
								>
									{ group }
								</div>

								{ list.map( ( f ) => {
									const val = localData[ f.id ];
									if ( f.type === 'boolean' ) {
										return (
											<label
												key={ f.id }
												style={ {
													display: 'flex',
													alignItems: 'center',
													justifyContent: 'space-between',
													fontSize: '12.5px',
													padding: '2px 0',
												} }
											>
												<span>{ f.label }</span>
												<input
													type="checkbox"
													checked={ Boolean( val ) }
													onChange={ ( e ) => setField( f.id, e.target.checked ) }
												/>
											</label>
										);
									}

									if ( f.type === 'select' ) {
										return (
											<div key={ f.id } className="rc-field-row">
												<label>{ f.label }</label>
												<select
													value={ String( val ?? '' ) }
													onChange={ ( e ) => setField( f.id, e.target.value ) }
												>
													<option value="">—</option>
													{ f.options.map( ( o ) => (
														<option key={ o.value } value={ o.value }>
															{ o.label }
														</option>
													) ) }
												</select>
											</div>
										);
									}

									if ( f.type === 'textarea' ) {
										const str = String( val ?? '' );
										return (
											<div key={ f.id } className="rc-field-row">
												<label style={ { display: 'flex', justifyContent: 'space-between' } }>
													<span>{ f.label }</span>
													{ f.validation?.recommendedMax && (
														<span className={ `rc-char-meter ${ str.length > f.validation.recommendedMax ? 'is-over' : '' }` }>
															{ str.length }/{ f.validation.recommendedMax }
														</span>
													) }
												</label>
												<textarea
													rows={ 3 }
													value={ str }
													onChange={ ( e ) => setField( f.id, e.target.value ) }
												/>
											</div>
										);
									}

									const isNum = [ 'number', 'currency', 'integer' ].includes( f.type );
									const strVal = val === null || val === undefined ? '' : String( val );

									return (
										<div key={ f.id } className="rc-field-row">
											<label style={ { display: 'flex', justifyContent: 'space-between' } }>
												<span>{ f.label }</span>
												{ f.validation?.recommendedMax && (
													<span className={ `rc-char-meter ${ strVal.length > f.validation.recommendedMax ? 'is-over' : '' }` }>
														{ strVal.length }/{ f.validation.recommendedMax }
													</span>
												) }
											</label>
											<input
												type={ isNum ? 'number' : f.type === 'date' ? 'date' : 'text' }
												step={ f.type === 'integer' ? '1' : isNum ? '0.01' : undefined }
												className={ f.monospace ? 'rc-mono-input' : undefined }
												value={ strVal }
												onChange={ ( e ) => {
													const v = e.target.value;
													if ( isNum ) {
														setField( f.id, v === '' ? null : Number( v ) );
													} else {
														setField( f.id, v );
													}
												} }
											/>
										</div>
									);
								} ) }
							</div>
						) )
						)
					) : (
						<>
							<div className="rc-field-row">
								<label>{ __( 'Filter by field', 'datadeck' ) }</label>
								<select value={ historyField } onChange={ ( e ) => setHistoryField( e.target.value ) }>
									<option value="">{ __( 'All fields on this record', 'datadeck' ) }</option>
									{ fields.map( ( f ) => (
										<option key={ f.id } value={ f.id }>
											{ f.label }
										</option>
									) ) }
								</select>
							</div>

							{ loadingHistory ? (
								<div style={ { fontSize: '12.5px', color: 'var(--rc-ink-muted)' } }>
									{ __( 'Loading cell history…', 'datadeck' ) }
								</div>
							) : historyItems.length === 0 ? (
								<div style={ { fontSize: '12.5px', color: 'var(--rc-ink-muted)', padding: '16px 0' } }>
									{ __( 'No recorded DataDeck mutations for this record yet.', 'datadeck' ) }
								</div>
							) : (
								<div style={ { display: 'flex', flexDirection: 'column', gap: '8px' } }>
									{ historyItems.map( ( item ) => (
										<div
											key={ item.changeId }
											style={ {
												padding: '8px 10px',
												border: '1px solid var(--rc-border)',
												borderRadius: '4px',
												display: 'flex',
												flexDirection: 'column',
												gap: '4px',
											} }
										>
											<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '6px' } }>
												<span style={ { fontSize: '12px', fontWeight: 600, fontFamily: 'var(--rc-font-mono)' } }>
													{ item.field }
												</span>
												<button
													type="button"
													className="rc-btn"
													style={ { height: '24px', fontSize: '11.5px' } }
													disabled={ restoringId === item.changeId }
													onClick={ () => handleRestoreChange( item ) }
												>
													{ restoringId === item.changeId
														? __( 'Restoring…', 'datadeck' )
														: __( 'Restore Cell', 'datadeck' ) }
												</button>
											</div>
											<div style={ { fontSize: '12px' } }>
												<span className="rc-diff-old">{ formatVal( item.oldValue ) }</span>
												{ ' → ' }
												<span className="rc-diff-new">{ formatVal( item.newValue ) }</span>
											</div>
											<div style={ { fontSize: '11px', color: 'var(--rc-ink-muted)' } }>
												{ `${ item.operationType } · ${ item.userName } · ${ item.createdAt }` }
											</div>
										</div>
									) ) }
								</div>
							) }
						</>
					) }
				</div>

				<div className="rc-modal-footer">
					{ record._editUrl ? (
						<a href={ record._editUrl } target="_blank" rel="noreferrer" className="rc-btn rc-btn--ghost" style={ { textDecoration: 'none' } }>
							{ __( 'Open Classic Editor', 'datadeck' ) }
						</a>
					) : <span /> }
					{ tab === 'edit' && (
						<div style={ { display: 'flex', gap: '8px' } }>
							<button type="button" className="rc-btn rc-btn--ghost" onClick={ onClose }>
								{ __( 'Cancel', 'datadeck' ) }
							</button>
							<button type="button" className="rc-btn rc-btn--primary" disabled={ ! canEditRecord || loadingRecord } onClick={ handleApply }>
								{ __( 'Apply Changes', 'datadeck' ) }
							</button>
						</div>
					) }
				</div>
			</div>
		</div>
	);
}
