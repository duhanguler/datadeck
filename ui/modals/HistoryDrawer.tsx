import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { cancelSchedule, fetchHistory, fetchHistoryDetail, rollbackBatch } from '../api/client';
import type { HistoryBatch, HistoryDetail } from '../types';

interface HistoryDrawerProps {
	entity: string;
	onClose: () => void;
	onRolledBack: ( message: string ) => void;
}

export default function HistoryDrawer( { entity, onClose, onRolledBack }: HistoryDrawerProps ) {
	const [ batches, setBatches ] = useState<HistoryBatch[]>( [] );
	const [ loading, setLoading ] = useState( true );
	const [ detail, setDetail ] = useState<HistoryDetail | null>( null );
	const [ busyUuid, setBusyUuid ] = useState<string | null>( null );
	const [ errorMsg, setErrorMsg ] = useState<string | null>( null );

	const reload = () => {
		setLoading( true );
		fetchHistory( entity )
			.then( ( res ) => setBatches( res.batches ) )
			.catch( ( err ) => setErrorMsg( err instanceof Error ? err.message : 'Failed to load history' ) )
			.finally( () => setLoading( false ) );
	};

	useEffect( reload, [ entity ] );

	const handleInspect = async ( uuid: string ) => {
		if ( detail?.uuid === uuid ) {
			setDetail( null );
			return;
		}
		try {
			const res = await fetchHistoryDetail( uuid );
			setDetail( res );
		} catch ( err ) {
			setErrorMsg( err instanceof Error ? err.message : 'Failed to load diff' );
		}
	};

	const handleRollback = async ( uuid: string ) => {
		setBusyUuid( uuid );
		setErrorMsg( null );
		try {
			const res = await rollbackBatch( uuid );
			onRolledBack( `${ res.restored } ${ __( 'records restored from snapshot', 'rowcraft' ) }` );
			reload();
		} catch ( err ) {
			setErrorMsg( err instanceof Error ? err.message : 'Rollback failed' );
		} finally {
			setBusyUuid( null );
		}
	};

	const handleCancelSchedule = async ( uuid: string ) => {
		setBusyUuid( uuid );
		try {
			await cancelSchedule( uuid );
			reload();
		} finally {
			setBusyUuid( null );
		}
	};

	return (
		<div className="rc-backdrop rc-backdrop--drawer" onClick={ onClose }>
			<div className="rc-drawer" onClick={ ( e ) => e.stopPropagation() } role="dialog" aria-modal="true">
				<div className="rc-modal-header">
					<h2>{ __( 'Change History & Rollback', 'rowcraft' ) }</h2>
					<button type="button" className="rc-btn rc-btn--ghost" onClick={ onClose }>
						{ __( 'Close', 'rowcraft' ) }
					</button>
				</div>

				<div className="rc-modal-body">
					{ errorMsg && (
						<div style={ { padding: '8px', fontSize: '12px', color: 'var(--rc-danger)', background: 'var(--rc-danger-bg)', borderRadius: '4px' } }>
							{ errorMsg }
						</div>
					) }

					{ loading ? (
						<div style={ { fontSize: '12.5px', color: 'var(--rc-ink-muted)' } }>
							{ __( 'Loading audit snapshots…', 'rowcraft' ) }
						</div>
					) : batches.length === 0 ? (
						<div className="rc-empty-state" style={ { height: '180px' } }>
							<span>{ __( 'No changes recorded for this entity yet.', 'rowcraft' ) }</span>
						</div>
					) : (
						batches.map( ( batch ) => (
							<div
								key={ batch.uuid }
								style={ {
									border: '1px solid var(--rc-border)',
									borderRadius: '4px',
									padding: '10px 12px',
									display: 'flex',
									flexDirection: 'column',
									gap: '8px',
								} }
							>
								<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: '8px' } }>
									<div>
										<div style={ { fontSize: '13px', fontWeight: 600 } }>{ batch.summary }</div>
										<div style={ { fontSize: '11.5px', color: 'var(--rc-ink-muted)', marginTop: '2px', fontVariantNumeric: 'tabular-nums' } }>
											{ `${ batch.userName } · ${ batch.createdAt } · ${ batch.affectedCount } rows · ${ batch.operationType }` }
										</div>
										{ batch.scheduledAt && (
											<div style={ { fontSize: '11.5px', color: 'var(--rc-accent)', marginTop: '2px' } }>
												{ `Start: ${ batch.scheduledAt }${ batch.revertAt ? ` · Auto-revert: ${ batch.revertAt }` : '' }` }
											</div>
										) }
									</div>
									<span className="rc-tab-tag">{ batch.status }</span>
								</div>

								<div style={ { display: 'flex', gap: '6px' } }>
									<button type="button" className="rc-btn" onClick={ () => handleInspect( batch.uuid ) }>
										{ detail?.uuid === batch.uuid ? __( 'Hide Diff', 'rowcraft' ) : __( 'View Diff', 'rowcraft' ) }
									</button>
									{ batch.status === 'applied' && (
										<button
											type="button"
											className="rc-btn rc-btn--danger"
											disabled={ busyUuid === batch.uuid }
											onClick={ () => handleRollback( batch.uuid ) }
										>
											{ busyUuid === batch.uuid ? __( 'Restoring…', 'rowcraft' ) : __( 'One-Click Rollback', 'rowcraft' ) }
										</button>
									) }
									{ batch.status === 'scheduled' && (
										<button
											type="button"
											className="rc-btn rc-btn--danger"
											disabled={ busyUuid === batch.uuid }
											onClick={ () => handleCancelSchedule( batch.uuid ) }
										>
											{ __( 'Cancel Schedule', 'rowcraft' ) }
										</button>
									) }
								</div>

								{ detail?.uuid === batch.uuid && (
									<table className="rc-diff-table">
										<thead>
											<tr>
												<th>ID</th>
												<th>{ __( 'Field', 'rowcraft' ) }</th>
												<th>{ __( 'Old', 'rowcraft' ) }</th>
												<th>{ __( 'New', 'rowcraft' ) }</th>
											</tr>
										</thead>
										<tbody>
											{ detail.changes.map( ( c, idx ) => (
												<tr key={ idx }>
													<td className="rc-td--mono">#{ c.objectId }</td>
													<td className="rc-td--mono">{ c.field }</td>
													<td className="rc-diff-old">{ JSON.stringify( c.oldValue ) }</td>
													<td className="rc-diff-new">{ JSON.stringify( c.newValue ) }</td>
												</tr>
											) ) }
										</tbody>
									</table>
								) }
							</div>
						) )
					) }
				</div>
			</div>
		</div>
	);
}
