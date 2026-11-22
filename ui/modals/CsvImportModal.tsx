import { useMemo, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { analyzeImport, applyImportChunk, dryRunImport } from '../api/client';
import type {
	CsvAnalyzeResponse,
	CsvDryRunResponse,
	FieldDefinition,
} from '../types';
import { parseCsvClient } from '../utils/series';

interface CsvImportModalProps {
	entity: string;
	entityLabel: string;
	fields: FieldDefinition[];
	onClose: () => void;
	onCompleted: ( message: string, batchUuid?: string ) => void;
}

type ImportStep = 'upload' | 'map' | 'preview';

export default function CsvImportModal( {
	entity,
	entityLabel,
	fields,
	onClose,
	onCompleted,
}: CsvImportModalProps ) {
	const [ step, setStep ] = useState<ImportStep>( 'upload' );
	const [ rawText, setRawText ] = useState( '' );
	const [ fileName, setFileName ] = useState( '' );
	const [ format, setFormat ] = useState<'csv' | 'json'>( 'csv' );
	const [ parsedRows, setParsedRows ] = useState<Array<Record<string, unknown>>>( [] );
	const [ analysis, setAnalysis ] = useState<CsvAnalyzeResponse | null>( null );
	const [ mapping, setMapping ] = useState<Record<string, string>>( {} );
	const [ matchBy, setMatchBy ] = useState<string>( 'id' );
	const [ createMissing, setCreateMissing ] = useState( false );
	const [ preview, setPreview ] = useState<CsvDryRunResponse | null>( null );

	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState<string | null>( null );
	const [ progress, setProgress ] = useState<{ processed: number; total: number } | null>( null );
	const fileInputRef = useRef<HTMLInputElement>( null );

	const mappableFields = useMemo(
		() => fields.filter( ( f ) => f.editable || f.id === 'id' || f.id === 'sku' || f.id === 'slug' || f.id === 'user_email' ),
		[ fields ]
	);

	const handleFile = ( file: File ) => {
		const isJson = file.name.toLowerCase().endsWith( '.json' );
		setFormat( isJson ? 'json' : 'csv' );
		setFileName( file.name );
		const reader = new FileReader();
		reader.onload = () => {
			setRawText( String( reader.result || '' ) );
			setError( null );
		};
		reader.readAsText( file );
	};

	const handleAnalyze = async () => {
		if ( ! rawText.trim() ) {
			setError( __( 'Upload a CSV/JSON file or paste CSV rows first.', 'datadeck' ) );
			return;
		}
		setBusy( true );
		setError( null );
		try {
			let localRows: Array<Record<string, unknown>> = [];
			if ( format === 'json' ) {
				const parsed = JSON.parse( rawText );
				if ( ! Array.isArray( parsed ) ) {
					throw new Error( __( 'JSON must be an array of objects.', 'datadeck' ) );
				}
				localRows = parsed;
			} else {
				const parsed = parseCsvClient( rawText );
				localRows = parsed.rows;
			}

			if ( localRows.length === 0 ) {
				throw new Error( __( 'No data rows detected.', 'datadeck' ) );
			}

			// Send a small sample (header + first 10 lines) for fast server-side auto-mapping.
			const sampleContent =
				format === 'json'
					? JSON.stringify( localRows.slice( 0, 10 ) )
					: rawText.split( /\r?\n/ ).slice( 0, 15 ).join( '\n' );

			const res = await analyzeImport( entity, sampleContent, format );
			setParsedRows( localRows );
			setAnalysis( { ...res, totalRows: localRows.length } );
			setMapping( res.suggestedMapping );

			// Prefer SKU match when SKU column is present on WooCommerce products.
			const mappedVals = Object.values( res.suggestedMapping );
			if ( entity === 'product' && mappedVals.includes( 'sku' ) && ! mappedVals.includes( 'id' ) ) {
				setMatchBy( 'sku' );
			} else if ( mappedVals.includes( 'id' ) ) {
				setMatchBy( 'id' );
			} else if ( res.matchCandidates.length > 0 ) {
				setMatchBy( res.matchCandidates[ 0 ].value );
			}

			setStep( 'map' );
		} catch ( err ) {
			setError( err instanceof Error ? err.message : __( 'Failed to parse file.', 'datadeck' ) );
		} finally {
			setBusy( false );
		}
	};

	const handleDryRun = async () => {
		setBusy( true );
		setError( null );
		try {
			const res = await dryRunImport( entity, {
				rows: parsedRows.slice( 0, 500 ),
				mapping,
				matchBy,
				createMissing,
			} );
			setPreview( res );
			setStep( 'preview' );
		} catch ( err ) {
			setError( err instanceof Error ? err.message : __( 'Dry run failed.', 'datadeck' ) );
		} finally {
			setBusy( false );
		}
	};

	const handleApply = async () => {
		setBusy( true );
		setError( null );
		const chunkSize = 50;
		let batchUuid: string | undefined;
		let totalUpdated = 0;
		let totalCreated = 0;
		let totalProcessed = 0;

		try {
			for ( let offset = 0; offset < parsedRows.length; offset += chunkSize ) {
				const slice = parsedRows.slice( offset, offset + chunkSize );
				const res = await applyImportChunk( entity, {
					rows: slice,
					mapping,
					matchBy,
					createMissing,
					batchUuid,
				} );
				batchUuid = res.batchUuid || batchUuid;
				totalUpdated += res.updated;
				totalCreated += res.created;
				totalProcessed += res.processed;
				setProgress( { processed: totalProcessed, total: parsedRows.length } );
			}

			onCompleted(
				`${ __( 'Import complete:', 'datadeck' ) } ${ totalUpdated } ${ __( 'updated', 'datadeck' ) }, ${ totalCreated } ${ __( 'created', 'datadeck' ) }`,
				batchUuid
			);
		} catch ( err ) {
			setError( err instanceof Error ? err.message : __( 'Import interrupted.', 'datadeck' ) );
		} finally {
			setBusy( false );
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
		<div className="rc-backdrop" onClick={ () => ! busy && onClose() }>
			<div className="rc-modal" style={ { width: 'min(760px, 95vw)' } } onClick={ ( e ) => e.stopPropagation() } role="dialog" aria-modal="true">
				<div className="rc-modal-header">
					<h2>{ `${ __( 'Import CSV / JSON', 'datadeck' ) } — ${ entityLabel }` }</h2>
					<button type="button" className="rc-btn rc-btn--ghost" disabled={ busy } onClick={ onClose }>
						{ __( 'Close', 'datadeck' ) }
					</button>
				</div>

				<div className="rc-modal-body">
					<div className="rc-step-strip">
						<span className={ step === 'upload' ? 'is-active' : '' }>1. { __( 'Upload / Paste', 'datadeck' ) }</span>
						<span>→</span>
						<span className={ step === 'map' ? 'is-active' : '' }>2. { __( 'Match & Map Columns', 'datadeck' ) }</span>
						<span>→</span>
						<span className={ step === 'preview' ? 'is-active' : '' }>3. { __( 'Dry-Run Diff & Apply', 'datadeck' ) }</span>
					</div>

					{ error && (
						<div style={ { padding: '8px 10px', border: '1px solid var(--rc-danger)', background: 'var(--rc-danger-soft)', color: 'var(--rc-danger)', borderRadius: '4px', fontSize: '12.5px' } }>
							{ error }
						</div>
					) }

					{ step === 'upload' && (
						<>
							<div
								className="rc-dropzone"
								onDragOver={ ( e ) => e.preventDefault() }
								onDrop={ ( e ) => {
									e.preventDefault();
									const f = e.dataTransfer.files?.[ 0 ];
									if ( f ) {
										handleFile( f );
									}
								} }
								onClick={ () => fileInputRef.current?.click() }
							>
								<input
									ref={ fileInputRef }
									type="file"
									accept=".csv,.tsv,.txt,.json"
									style={ { display: 'none' } }
									onChange={ ( e ) => {
										const f = e.target.files?.[ 0 ];
										if ( f ) {
											handleFile( f );
										}
									} }
								/>
								<div style={ { fontWeight: 600, fontSize: '13px' } }>
									{ fileName || __( 'Drop a CSV or JSON file here, or click to browse', 'datadeck' ) }
								</div>
								<div style={ { fontSize: '11.5px', color: 'var(--rc-ink-muted)', marginTop: '4px' } }>
									{ __( 'Supports comma, semicolon, or tab delimiters. Every import records delta snapshots for One-Click Rollback.', 'datadeck' ) }
								</div>
							</div>

							<div className="rc-field-row">
								<label>{ __( 'Or paste CSV / JSON directly', 'datadeck' ) }</label>
								<textarea
									rows={ 6 }
									className="rc-mono-input"
									placeholder={ 'sku,regular_price,stock_quantity\nPRO-001,89.99,25\nPRO-002,129.00,14' }
									value={ rawText }
									onChange={ ( e ) => {
										setRawText( e.target.value );
										setFormat( e.target.value.trim().startsWith( '[' ) ? 'json' : 'csv' );
									} }
								/>
							</div>
						</>
					) }

					{ step === 'map' && analysis && (
						<>
							<div style={ { display: 'flex', gap: '12px', alignItems: 'flex-end', flexWrap: 'wrap' } }>
								<div className="rc-field-row" style={ { minWidth: '180px' } }>
									<label>{ __( 'Match existing records by', 'datadeck' ) }</label>
									<select value={ matchBy } onChange={ ( e ) => setMatchBy( e.target.value ) }>
										{ analysis.matchCandidates.map( ( opt ) => (
											<option key={ opt.value } value={ opt.value }>
												{ opt.label }
											</option>
										) ) }
									</select>
								</div>

								{ analysis.canCreate && (
									<label style={ { display: 'inline-flex', alignItems: 'center', gap: '6px', fontSize: '12.5px', height: '32px' } }>
										<input
											type="checkbox"
											checked={ createMissing }
											onChange={ ( e ) => setCreateMissing( e.target.checked ) }
										/>
										{ __( 'Create new records when match is not found', 'datadeck' ) }
									</label>
								) }

								<span style={ { marginLeft: 'auto', fontSize: '12px', color: 'var(--rc-ink-muted)' } }>
									{ `${ analysis.totalRows } ${ __( 'row(s) detected', 'datadeck' ) }` }
								</span>
							</div>

							<table className="rc-diff-table">
								<thead>
									<tr>
										<th>{ __( 'Incoming Column', 'datadeck' ) }</th>
										<th>{ __( 'Sample Value', 'datadeck' ) }</th>
										<th>{ __( 'Target DataDeck Field', 'datadeck' ) }</th>
									</tr>
								</thead>
								<tbody>
									{ analysis.headers.map( ( h ) => (
										<tr key={ h }>
											<td style={ { fontFamily: 'var(--rc-font-mono)', fontWeight: 600 } }>{ h }</td>
											<td style={ { color: 'var(--rc-ink-muted)', maxWidth: '180px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } }>
												{ formatVal( analysis.sampleRows[ 0 ]?.[ h ] ) }
											</td>
											<td>
												<select
													value={ mapping[ h ] || '' }
													style={ { width: '100%', height: '28px', fontSize: '12px' } }
													onChange={ ( e ) =>
														setMapping( ( prev ) => ( { ...prev, [ h ]: e.target.value } ) )
													}
												>
													<option value="">{ __( '— Skip column —', 'datadeck' ) }</option>
													{ mappableFields.map( ( f ) => (
														<option key={ f.id } value={ f.id }>
															{ `${ f.label } (${ f.id })` }
														</option>
													) ) }
												</select>
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</>
					) }

					{ step === 'preview' && preview && (
						<>
							<div style={ { display: 'flex', gap: '12px', fontSize: '12.5px', padding: '8px 10px', background: 'var(--rc-bg-canvas)', border: '1px solid var(--rc-border)', borderRadius: '4px' } }>
								<span>
									<strong>{ preview.willUpdate }</strong> { __( 'will update', 'datadeck' ) }
								</span>
								<span>
									<strong>{ preview.willCreate }</strong> { __( 'will create', 'datadeck' ) }
								</span>
								<span style={ { color: 'var(--rc-ink-muted)' } }>
									<strong>{ preview.willSkip }</strong> { __( 'unchanged / skipped', 'datadeck' ) }
								</span>
								{ preview.errorsCount > 0 && (
									<span style={ { color: 'var(--rc-danger)' } }>
										<strong>{ preview.errorsCount }</strong> { __( 'error(s)', 'datadeck' ) }
									</span>
								) }
							</div>

							{ parsedRows.length > 500 && (
								<div style={ { fontSize: '11.5px', color: 'var(--rc-ink-secondary)' } }>
									{ `${ __( 'Dry-run analyzed the first 500 of', 'datadeck' ) } ${ parsedRows.length } ${ __( 'rows. Clicking Apply will import all rows in 50-row chunks.', 'datadeck' ) }` }
								</div>
							) }

							<table className="rc-diff-table">
								<thead>
									<tr>
										<th>#</th>
										<th>{ __( 'Match', 'datadeck' ) }</th>
										<th>{ __( 'Action', 'datadeck' ) }</th>
										<th>{ __( 'Field Diffs', 'datadeck' ) }</th>
									</tr>
								</thead>
								<tbody>
									{ preview.previews.map( ( p ) => (
										<tr key={ p.rowNumber }>
											<td style={ { fontFamily: 'var(--rc-font-mono)' } }>{ p.rowNumber }</td>
											<td style={ { fontFamily: 'var(--rc-font-mono)' } }>
												{ p.matchValue || '—' }
												{ p.recordId ? ` (#${ p.recordId })` : '' }
											</td>
											<td style={ { textTransform: 'uppercase', fontSize: '11px', fontWeight: 600 } }>
												{ p.action }
											</td>
											<td>
												{ p.error ? (
													<span style={ { color: 'var(--rc-danger)' } }>{ p.error }</span>
												) : p.changes.length === 0 ? (
													<span style={ { color: 'var(--rc-ink-muted)' } }>{ __( 'No changes', 'datadeck' ) }</span>
												) : (
													p.changes.slice( 0, 4 ).map( ( c ) => (
														<div key={ c.field } style={ { fontSize: '11.5px' } }>
															<strong>{ c.label }:</strong>{ ' ' }
															<span className="rc-diff-old">{ formatVal( c.oldValue ) }</span>
															{ ' → ' }
															<span className="rc-diff-new">{ formatVal( c.newValue ) }</span>
														</div>
													) )
												) }
											</td>
										</tr>
									) ) }
								</tbody>
							</table>

							{ progress && (
								<div>
									<div style={ { fontSize: '12px', marginBottom: '4px' } }>
										{ `${ __( 'Importing…', 'datadeck' ) } ${ progress.processed } / ${ progress.total }` }
									</div>
									<div className="rc-progress-track">
										<div
											className="rc-progress-fill"
											style={ { width: `${ Math.round( ( progress.processed / Math.max( 1, progress.total ) ) * 100 ) }%` } }
										/>
									</div>
								</div>
							) }
						</>
					) }
				</div>

				<div className="rc-modal-footer">
					<div>
						{ step !== 'upload' && (
							<button
								type="button"
								className="rc-btn rc-btn--ghost"
								disabled={ busy }
								onClick={ () => setStep( step === 'preview' ? 'map' : 'upload' ) }
							>
								{ __( 'Back', 'datadeck' ) }
							</button>
						) }
					</div>
					<div style={ { display: 'flex', gap: '8px' } }>
						<button type="button" className="rc-btn rc-btn--ghost" disabled={ busy } onClick={ onClose }>
							{ __( 'Cancel', 'datadeck' ) }
						</button>
						{ step === 'upload' && (
							<button type="button" className="rc-btn rc-btn--primary" disabled={ busy || ! rawText.trim() } onClick={ handleAnalyze }>
								{ busy ? __( 'Analyzing…', 'datadeck' ) : __( 'Next: Map Columns', 'datadeck' ) }
							</button>
						) }
						{ step === 'map' && (
							<button type="button" className="rc-btn rc-btn--primary" disabled={ busy } onClick={ handleDryRun }>
								{ busy ? __( 'Computing Diff…', 'datadeck' ) : __( 'Preview Dry-Run Diff', 'datadeck' ) }
							</button>
						) }
						{ step === 'preview' && preview && (
							<button
								type="button"
								className="rc-btn rc-btn--primary"
								disabled={ busy || ( preview.willUpdate === 0 && preview.willCreate === 0 ) }
								onClick={ handleApply }
							>
								{ busy
									? __( 'Applying…', 'datadeck' )
									: `${ __( 'Apply Import', 'datadeck' ) } (${ preview.willUpdate + preview.willCreate })` }
							</button>
						) }
					</div>
				</div>
			</div>
		</div>
	);
}
