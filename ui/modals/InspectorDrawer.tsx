import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { FieldDefinition, RecordRow, StagedEdit } from '../types';

interface InspectorDrawerProps {
	record: RecordRow;
	fields: FieldDefinition[];
	onStageEdits: ( edits: StagedEdit[] ) => void;
	onClose: () => void;
}

/**
 * Lightweight, zero-bloat side-panel record inspector aligned with WordPress --wpds-* tokens.
 */
export default function InspectorDrawer( {
	record,
	fields,
	onStageEdits,
	onClose,
}: InspectorDrawerProps ) {
	const [ localData, setLocalData ] = useState<RecordRow>( { ...record } );

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

	const setField = ( id: string, val: unknown ) => {
		setLocalData( ( prev ) => ( { ...prev, [ id ]: val } ) );
	};

	const handleApply = () => {
		const staged: StagedEdit[] = [];
		for ( const f of editableFields ) {
			if ( JSON.stringify( localData[ f.id ] ) !== JSON.stringify( record[ f.id ] ) ) {
				staged.push( {
					recordId: record.id,
					fieldId: f.id,
					oldValue: record[ f.id ],
					newValue: localData[ f.id ],
				} );
			}
		}
		if ( staged.length > 0 ) {
			onStageEdits( staged );
		}
		onClose();
	};

	return (
		<div className="rc-backdrop rc-backdrop--drawer" onClick={ onClose }>
			<div className="rc-drawer" onClick={ ( e ) => e.stopPropagation() } role="dialog" aria-modal="true">
				<div className="rc-modal-header">
					<h2>{ `#${ record.id } — ${ String( record.title || record.display_name || record.customer_name || '' ) }` }</h2>
					<button type="button" className="rc-btn rc-btn--ghost" onClick={ onClose }>
						{ __( 'Close', 'datadeck' ) }
					</button>
				</div>

				<div className="rc-modal-body">
					{ Object.entries( grouped ).map( ( [ group, list ] ) => (
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
					) ) }
				</div>

				<div className="rc-modal-footer">
					{ record._editUrl ? (
						<a href={ record._editUrl } target="_blank" rel="noreferrer" className="rc-btn rc-btn--ghost" style={ { textDecoration: 'none' } }>
							{ __( 'Open Classic Editor', 'datadeck' ) }
						</a>
					) : <span /> }
					<div style={ { display: 'flex', gap: '8px' } }>
						<button type="button" className="rc-btn rc-btn--ghost" onClick={ onClose }>
							{ __( 'Cancel', 'datadeck' ) }
						</button>
						<button type="button" className="rc-btn rc-btn--primary" onClick={ handleApply }>
							{ __( 'Apply Changes', 'datadeck' ) }
						</button>
					</div>
				</div>
			</div>
		</div>
	);
}
