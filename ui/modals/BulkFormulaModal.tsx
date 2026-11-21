import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	dryRunBulk,
	executeBulkChunk,
	scheduleCampaign,
	validateFormula,
	type RecordsQueryParams,
} from '../api/client';
import type { BulkRule, DryRunResponse, FieldDefinition } from '../types';

interface BulkFormulaModalProps {
	entity: string;
	fields: FieldDefinition[];
	selectedIds: number[];
	totalMatching: number;
	queryParams: RecordsQueryParams;
	onClose: () => void;
	onCompleted: ( message: string, batchUuid?: string ) => void;
}

const ACTIONS = [
	{ value: 'set', label: 'Set exact value' },
	{ value: 'formula', label: 'AST Formula (safe expression)' },
	{ value: 'template', label: 'Dynamic template ({title}, {sku}, {site_name})' },
	{ value: 'math_percent', label: 'Adjust number by % (+15 or -20)' },
	{ value: 'math_fixed', label: 'Adjust number by fixed amount (+5 or -10)' },
	{ value: 'round_ending', label: 'Psychological price ending (.99 / .95)' },
	{ value: 'prepend', label: 'Prepend text' },
	{ value: 'append', label: 'Append text' },
	{ value: 'find_replace', label: 'Find & Replace (plain or regex)' },
	{ value: 'case_transform', label: 'Transform case (Title / Upper / Lower)' },
	{ value: 'taxonomy_add', label: 'Taxonomy: Add terms' },
	{ value: 'taxonomy_remove', label: 'Taxonomy: Remove terms' },
	{ value: 'taxonomy_replace', label: 'Taxonomy: Replace terms' },
	{ value: 'date_shift', label: 'Shift date by +/- days' },
	{ value: 'clear', label: 'Clear value' },
];

export default function BulkFormulaModal( {
	entity,
	fields,
	selectedIds,
	totalMatching,
	queryParams,
	onClose,
	onCompleted,
}: BulkFormulaModalProps ) {
	const editableFields = fields.filter( ( f ) => f.editable && f.type !== 'media' );
	const [ scope, setScope ] = useState<'selected' | 'filtered'>(
		selectedIds.length > 0 ? 'selected' : 'filtered'
	);
	const [ targetField, setTargetField ] = useState<string>( editableFields[ 0 ]?.id || '' );
	const [ action, setAction ] = useState<string>( 'set' );
	const [ value, setValue ] = useState<string>( '' );
	const [ formula, setFormula ] = useState<string>( "if(regular_price > 0, round(regular_price * 0.85, 0) - 0.01, '')" );
	const [ findText, setFindText ] = useState<string>( '' );
	const [ replaceText, setReplaceText ] = useState<string>( '' );
	const [ isRegex, setIsRegex ] = useState<boolean>( false );
	const [ rounding, setRounding ] = useState<string>( '2dec' );

	const [ modeTab, setModeTab ] = useState<'now' | 'schedule'>( 'now' );
	const [ campaignName, setCampaignName ] = useState<string>( '' );
	const [ scheduledAt, setScheduledAt ] = useState<string>( '' );
	const [ revertAt, setRevertAt ] = useState<string>( '' );

	const [ formulaStatus, setFormulaStatus ] = useState<{ valid: boolean; error: string | null } | null>( null );
	const [ preview, setPreview ] = useState<DryRunResponse | null>( null );
	const [ previewLoading, setPreviewLoading ] = useState( false );
	const [ errorMsg, setErrorMsg ] = useState<string | null>( null );

	const [ running, setRunning ] = useState( false );
	const [ progress, setProgress ] = useState<{ processed: number; total: number; updated: number; chunkMs: number } | null>( null );

	const activeField = editableFields.find( ( f ) => f.id === targetField );

	useEffect( () => {
		if ( action !== 'formula' || ! formula.trim() ) {
			setFormulaStatus( null );
			return;
		}
		const t = window.setTimeout( () => {
			validateFormula( formula )
				.then( ( res ) => setFormulaStatus( res ) )
				.catch( () => {} );
		}, 260 );
		return () => window.clearTimeout( t );
	}, [ action, formula ] );

	const buildRule = (): BulkRule => ( {
		field: targetField,
		action,
		value: action === 'formula' ? formula : value,
		formula: action === 'formula' ? formula : undefined,
		find: action === 'find_replace' ? findText : undefined,
		replace: action === 'find_replace' ? replaceText : undefined,
		regex: action === 'find_replace' ? isRegex : undefined,
		rounding,
	} );

	const activeIds = scope === 'selected' ? selectedIds : [];
	const targetCount = scope === 'selected' ? selectedIds.length : totalMatching;

	const runPreview = async () => {
		setPreviewLoading( true );
		setErrorMsg( null );
		try {
			const res = await dryRunBulk( entity, buildRule(), activeIds, queryParams );
			setPreview( res );
		} catch ( err ) {
			setErrorMsg( err instanceof Error ? err.message : __( 'Dry-run failed.', 'datadeck' ) );
		} finally {
			setPreviewLoading( false );
		}
	};

	const executeNow = async () => {
		setRunning( true );
		setErrorMsg( null );
		let afterId = 0;
		let chunkSize = 50;
		let batchUuid: string | undefined;
		let processedTotal = 0;
		let updatedTotal = 0;

		try {
			while ( true ) {
				const res = await executeBulkChunk( entity, {
					rule: buildRule(),
					ids: activeIds,
					query: queryParams,
					afterId,
					chunkSize,
					batchUuid,
					revertAt: revertAt || undefined,
				} );

				batchUuid = res.batchUuid;
				processedTotal += res.processed;
				updatedTotal += res.updated;
				afterId = res.lastId;
				chunkSize = res.nextChunkSize;

				setProgress( {
					processed: processedTotal,
					total: Math.max( targetCount, processedTotal ),
					updated: updatedTotal,
					chunkMs: res.durationMs,
				} );

				if ( ! res.hasMore || res.processed === 0 ) {
					break;
				}
			}

			onCompleted(
				`${ updatedTotal } ${ __( 'records updated', 'datadeck' ) }`,
				batchUuid
			);
		} catch ( err ) {
			setErrorMsg( err instanceof Error ? err.message : __( 'Bulk execution failed.', 'datadeck' ) );
			setRunning( false );
		}
	};

	const submitSchedule = async () => {
		if ( ! scheduledAt ) {
			setErrorMsg( __( 'Please pick a start date and time.', 'datadeck' ) );
			return;
		}
		setRunning( true );
		setErrorMsg( null );
		try {
			await scheduleCampaign( entity, {
				name: campaignName || `Scheduled ${ action } on ${ activeField?.label || targetField }`,
				rule: buildRule(),
				ids: activeIds,
				query: queryParams,
				scheduledAt,
				revertAt: revertAt || undefined,
			} );
			onCompleted( __( 'Campaign scheduled with automatic rollback snapshot.', 'datadeck' ) );
		} catch ( err ) {
			setErrorMsg( err instanceof Error ? err.message : __( 'Failed to schedule campaign.', 'datadeck' ) );
			setRunning( false );
		}
	};

	return (
		<div className="rc-backdrop" onClick={ () => ! running && onClose() }>
			<div className="rc-modal" onClick={ ( e ) => e.stopPropagation() } role="dialog" aria-modal="true">
				<div className="rc-modal-header">
					<h2>{ __( 'Bulk Edit & Formula Engine', 'datadeck' ) }</h2>
					<div className="rc-segmented">
						<button
							type="button"
							className={ modeTab === 'now' ? 'is-active' : '' }
							onClick={ () => setModeTab( 'now' ) }
						>
							{ __( 'Run Now', 'datadeck' ) }
						</button>
						<button
							type="button"
							className={ modeTab === 'schedule' ? 'is-active' : '' }
							onClick={ () => setModeTab( 'schedule' ) }
						>
							{ __( 'Schedule & Auto-Revert', 'datadeck' ) }
						</button>
					</div>
				</div>

				<div className="rc-modal-body">
					<div style={ { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px' } }>
						<div className="rc-field-row">
							<label>{ __( 'Target Scope', 'datadeck' ) }</label>
							<select
								value={ scope }
								onChange={ ( e ) => setScope( e.target.value as 'selected' | 'filtered' ) }
							>
								{ selectedIds.length > 0 && (
									<option value="selected">
										{ `${ selectedIds.length } ${ __( 'selected rows', 'datadeck' ) }` }
									</option>
								) }
								<option value="filtered">
									{ `${ __( 'All matching current filters', 'datadeck' ) } (${ totalMatching })` }
								</option>
							</select>
						</div>

						<div className="rc-field-row">
							<label>{ __( 'Target Field', 'datadeck' ) }</label>
							<select value={ targetField } onChange={ ( e ) => setTargetField( e.target.value ) }>
								{ editableFields.map( ( f ) => (
									<option key={ f.id } value={ f.id }>
										{ `${ f.label } (${ f.group })` }
									</option>
								) ) }
							</select>
						</div>
					</div>

					<div className="rc-field-row">
						<label>{ __( 'Operation', 'datadeck' ) }</label>
						<select value={ action } onChange={ ( e ) => setAction( e.target.value ) }>
							{ ACTIONS.map( ( a ) => (
								<option key={ a.value } value={ a.value }>
									{ a.label }
								</option>
							) ) }
						</select>
					</div>

					{ action === 'formula' && (
						<div className="rc-field-row">
							<label>{ __( 'Safe AST Formula (zero eval)', 'datadeck' ) }</label>
							<input
								type="text"
								className="rc-mono-input"
								value={ formula }
								onChange={ ( e ) => setFormula( e.target.value ) }
								placeholder="round(regular_price * 0.85, 0) - 0.01"
							/>
							<div style={ { fontSize: '11.5px', color: 'var(--rc-ink-secondary)' } }>
								{ __( 'Functions: round, ceil, floor, min, max, if(cond, a, b), concat, upper, lower, replace, coalesce. Fields: regular_price, sale_price, stock_quantity, title, sku, etc.', 'datadeck' ) }
							</div>
							{ formulaStatus && (
								<div
									style={ {
										fontSize: '12px',
										color: formulaStatus.valid ? 'var(--rc-success)' : 'var(--rc-danger)',
									} }
								>
									{ formulaStatus.valid ? __( 'Valid AST expression', 'datadeck' ) : formulaStatus.error }
								</div>
							) }
						</div>
					) }

					{ action === 'find_replace' && (
						<div style={ { display: 'grid', gridTemplateColumns: '1fr 1fr auto', gap: '10px', alignItems: 'end' } }>
							<div className="rc-field-row">
								<label>{ __( 'Find', 'datadeck' ) }</label>
								<input type="text" className="rc-mono-input" value={ findText } onChange={ ( e ) => setFindText( e.target.value ) } />
							</div>
							<div className="rc-field-row">
								<label>{ __( 'Replace with', 'datadeck' ) }</label>
								<input type="text" className="rc-mono-input" value={ replaceText } onChange={ ( e ) => setReplaceText( e.target.value ) } />
							</div>
							<label style={ { display: 'inline-flex', alignItems: 'center', gap: '6px', height: '32px', fontSize: '12.5px' } }>
								<input type="checkbox" checked={ isRegex } onChange={ ( e ) => setIsRegex( e.target.checked ) } />
								Regex
							</label>
						</div>
					) }

					{ action !== 'formula' && action !== 'find_replace' && action !== 'clear' && (
						<div style={ { display: 'grid', gridTemplateColumns: action === 'math_percent' || action === 'math_fixed' ? '1fr 180px' : '1fr', gap: '10px' } }>
							<div className="rc-field-row">
								<label>{ __( 'Value', 'datadeck' ) }</label>
								{ action === 'case_transform' ? (
									<select value={ value } onChange={ ( e ) => setValue( e.target.value ) }>
										<option value="title">Title Case</option>
										<option value="upper">UPPERCASE</option>
										<option value="lower">lowercase</option>
									</select>
								) : activeField?.type === 'select' && action === 'set' ? (
									<select value={ value } onChange={ ( e ) => setValue( e.target.value ) }>
										<option value="">{ __( 'Select…', 'datadeck' ) }</option>
										{ activeField.options.map( ( o ) => (
											<option key={ o.value } value={ o.value }>
												{ o.label }
											</option>
										) ) }
									</select>
								) : (
									<input
										type="text"
										value={ value }
										onChange={ ( e ) => setValue( e.target.value ) }
										placeholder={
											action === 'template'
												? '{title} — {site_name}'
												: action === 'round_ending'
												? '0.99'
												: ''
										}
									/>
								) }
							</div>

							{ ( action === 'math_percent' || action === 'math_fixed' ) && (
								<div className="rc-field-row">
									<label>{ __( 'Rounding', 'datadeck' ) }</label>
									<select value={ rounding } onChange={ ( e ) => setRounding( e.target.value ) }>
										<option value="2dec">2 decimals</option>
										<option value="int">Nearest integer</option>
										<option value="99">End in .99</option>
										<option value="95">End in .95</option>
									</select>
								</div>
							) }
						</div>
					) }

					{ modeTab === 'schedule' && (
						<div style={ { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px', padding: '10px', background: 'var(--rc-bg-canvas)', border: '1px solid var(--rc-border)', borderRadius: '4px' } }>
							<div className="rc-field-row" style={ { gridColumn: '1 / -1' } }>
								<label>{ __( 'Campaign Label', 'datadeck' ) }</label>
								<input
									type="text"
									placeholder={ __( 'e.g. Weekend Flash Sale (-20%)', 'datadeck' ) }
									value={ campaignName }
									onChange={ ( e ) => setCampaignName( e.target.value ) }
								/>
							</div>
							<div className="rc-field-row">
								<label>{ __( 'Apply At (UTC)', 'datadeck' ) }</label>
								<input type="datetime-local" value={ scheduledAt } onChange={ ( e ) => setScheduledAt( e.target.value ) } />
							</div>
							<div className="rc-field-row">
								<label>{ __( 'Auto-Revert At (Optional UTC)', 'datadeck' ) }</label>
								<input type="datetime-local" value={ revertAt } onChange={ ( e ) => setRevertAt( e.target.value ) } />
							</div>
						</div>
					) }

					{ errorMsg && (
						<div style={ { padding: '8px 10px', fontSize: '12.5px', color: 'var(--rc-danger)', background: 'var(--rc-danger-bg)', border: '1px solid color-mix(in srgb, var(--rc-danger) 30%, transparent)', borderRadius: '4px' } }>
							{ errorMsg }
						</div>
					) }

					{ preview && (
						<div>
							<div style={ { fontSize: '12px', fontWeight: 600, marginBottom: '6px', color: 'var(--rc-ink-secondary)' } }>
								{ `${ __( 'Dry-Run Diff Preview', 'datadeck' ) } — ${ preview.previews.length } of ${ preview.totalMatching } matching records` }
							</div>
							<table className="rc-diff-table">
								<thead>
									<tr>
										<th>ID</th>
										<th>{ __( 'Record', 'datadeck' ) }</th>
										<th>{ __( 'Before', 'datadeck' ) }</th>
										<th>{ __( 'After', 'datadeck' ) }</th>
									</tr>
								</thead>
								<tbody>
									{ preview.previews.map( ( row ) => (
										<tr key={ row.id }>
											<td className="rc-td--mono rc-td--tabular">#{ row.id }</td>
											<td>{ row.title }</td>
											<td className="rc-diff-old">{ String( row.oldValue ?? '—' ) }</td>
											<td className="rc-diff-new">
												{ row.error ? (
													<span style={ { color: 'var(--rc-danger)' } }>{ row.error }</span>
												) : (
													String( row.newValue ?? '—' )
												) }
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
					) }

					{ progress && (
						<div style={ { display: 'flex', flexDirection: 'column', gap: '6px' } }>
							<div style={ { display: 'flex', justifyContent: 'space-between', fontSize: '12px', fontVariantNumeric: 'tabular-nums' } }>
								<span>{ `${ progress.processed } / ${ progress.total } processed (${ progress.updated } updated)` }</span>
								<span>{ `${ progress.chunkMs }ms / chunk` }</span>
							</div>
							<div className="rc-progress-track">
								<div
									className="rc-progress-fill"
									style={ { width: `${ Math.min( 100, Math.round( ( progress.processed / Math.max( 1, progress.total ) ) * 100 ) ) }%` } }
								/>
							</div>
						</div>
					) }
				</div>

				<div className="rc-modal-footer">
					<button type="button" className="rc-btn" disabled={ previewLoading || running } onClick={ runPreview }>
						{ previewLoading ? __( 'Simulating…', 'datadeck' ) : __( 'Dry-Run Preview', 'datadeck' ) }
					</button>
					<div style={ { display: 'flex', gap: '8px' } }>
						<button type="button" className="rc-btn rc-btn--ghost" disabled={ running } onClick={ onClose }>
							{ __( 'Cancel', 'datadeck' ) }
						</button>
						{ modeTab === 'now' ? (
							<button type="button" className="rc-btn rc-btn--primary" disabled={ running || targetCount === 0 } onClick={ executeNow }>
								{ running
									? __( 'Applying chunks…', 'datadeck' )
									: `${ __( 'Apply to', 'datadeck' ) } ${ targetCount } ${ __( 'records', 'datadeck' ) }` }
							</button>
						) : (
							<button type="button" className="rc-btn rc-btn--primary" disabled={ running || targetCount === 0 } onClick={ submitSchedule }>
								{ running ? __( 'Scheduling…', 'datadeck' ) : __( 'Schedule Campaign', 'datadeck' ) }
							</button>
						) }
					</div>
				</div>
			</div>
		</div>
	);
}
