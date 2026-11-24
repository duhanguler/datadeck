import { memo, useEffect, useRef, useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { copy, drawerRight, external } from '@wordpress/icons';
import { Icon } from '@wordpress/components';
import { STORE_NAME } from '../store';
import type { FieldDefinition, MediaValue, RecordRow, TermValue } from '../types';
import { parseLocaleNumber } from '../utils/series';

interface InlineCellProps {
	record: RecordRow;
	field: FieldDefinition;
	rowIndex: number;
	colIndex: number;
	width: number;
	isPrimary: boolean;
	isSticky: boolean;
	isFocused: boolean;
	isEditing: boolean;
	isInRange: boolean;
	isFillTarget?: boolean;
	initialChar?: string;
	isExpandedParent?: boolean;
	onFocus: ( shiftKey: boolean ) => void;
	onStartEdit: () => void;
	onCommitEdit: ( newValue: unknown, move?: 'next-col' | 'prev-col' | 'next-row' | 'prev-row' ) => void;
	onCancelEdit: () => void;
	onStartFillDrag?: () => void;
	onFillDragEnter?: () => void;
	onContextMenuCell?: ( e: React.MouseEvent, currentValue: unknown ) => void;
	onToggleVariations?: ( parentId: number ) => void;
	onInspectRow?: ( record: RecordRow ) => void;
	onDuplicateRow?: ( id: number ) => void;
}

function statusDotClass( value: string ): string {
	switch ( value ) {
		case 'publish':
		case 'instock':
		case 'completed':
		case 'approve':
			return 'rc-status-dot--ok';
		case 'draft':
		case 'pending':
		case 'onbackorder':
		case 'processing':
		case 'on-hold':
		case 'hold':
			return 'rc-status-dot--warn';
		case 'outofstock':
		case 'failed':
		case 'cancelled':
		case 'trash':
		case 'spam':
			return 'rc-status-dot--danger';
		case 'future':
		case 'private':
			return 'rc-status-dot--info';
		default:
			return '';
	}
}

function formatNumber( val: unknown, currency?: string, suffix?: string ): string {
	if ( val === null || val === undefined || val === '' ) {
		return '—';
	}
	const num = Number( val );
	if ( Number.isNaN( num ) ) {
		return String( val );
	}
	if ( currency ) {
		return `${ currency }${ num.toFixed( 2 ) }`;
	}
	if ( suffix ) {
		return `${ num }${ suffix }`;
	}
	return String( num );
}

export const InlineCell = memo( function InlineCell( {
	record,
	field,
	width,
	isPrimary,
	isSticky,
	isFocused,
	isEditing,
	isInRange,
	isFillTarget,
	initialChar,
	isExpandedParent,
	onFocus,
	onStartEdit,
	onCommitEdit,
	onCancelEdit,
	onStartFillDrag,
	onFillDragEnter,
	onContextMenuCell,
	onToggleVariations,
	onInspectRow,
	onDuplicateRow,
}: InlineCellProps ) {
	// O(1) cell-level subscription: typing in one cell never re-renders sibling cells.
	const stagedEdit = useSelect(
		( select ) =>
			( select( STORE_NAME ) as { getStagedEditForCell: ( id: number, f: string ) => { newValue: unknown } | undefined } ).getStagedEditForCell(
				record.id,
				field.id
			),
		[ record.id, field.id ]
	);
	const cellError = useSelect(
		( select ) =>
			( select( STORE_NAME ) as { getCellError: ( id: number, f: string ) => string | undefined } ).getCellError(
				record.id,
				field.id
			),
		[ record.id, field.id ]
	);

	const rawValue = stagedEdit !== undefined ? stagedEdit.newValue : record[ field.id ];
	const isDirty = stagedEdit !== undefined;
	const canEdit = field.editable && record._canEdit !== false;

	const [ draft, setDraft ] = useState<string>( '' );
	const [ popoverPos, setPopoverPos ] = useState<{ top: number; left: number } | null>( null );
	const [ selectedTermIds, setSelectedTermIds ] = useState<number[]>( [] );
	const [ selectedMultiVals, setSelectedMultiVals ] = useState<string[]>( [] );
	const cellRef = useRef<HTMLDivElement>( null );
	const inputRef = useRef<HTMLInputElement | HTMLSelectElement>( null );
	const committedRef = useRef<boolean>( false );

	useEffect( () => {
		if ( ! isEditing ) {
			setPopoverPos( null );
			committedRef.current = false;
			return;
		}
		committedRef.current = false;

		if ( field.type === 'taxonomy' || field.type === 'multiselect' ) {
			if ( field.type === 'taxonomy' ) {
				const ids = Array.isArray( rawValue )
					? rawValue.map( ( t ) => ( typeof t === 'object' && t && 'id' in t ? Number( ( t as TermValue ).id ) : Number( t ) ) ).filter( Boolean )
					: [];
				setSelectedTermIds( ids );
			} else {
				const vals = Array.isArray( rawValue )
					? rawValue.map( String )
					: typeof rawValue === 'string' && rawValue.trim() !== ''
					? rawValue.split( ',' ).map( ( s ) => s.trim() ).filter( Boolean )
					: [];
				setSelectedMultiVals( vals );
			}
			if ( cellRef.current ) {
				const rect = cellRef.current.getBoundingClientRect();
				setPopoverPos( {
					top: Math.min( window.innerHeight - 270, rect.bottom + 2 ),
					left: Math.min( window.innerWidth - 250, rect.left ),
				} );
			}
			return;
		}

		if ( field.type === 'media' ) {
			const wpMedia = window.wp?.media;
			if ( wpMedia ) {
				let didSelect = false;
				const frame = wpMedia( {
					title: __( 'Select Media', 'datadeck' ),
					button: { text: __( 'Use Selected', 'datadeck' ) },
					multiple: false,
				} );
				frame.on( 'select', () => {
					didSelect = true;
					const sel = frame.state().get( 'selection' ).first().toJSON();
					const sizes = sel.sizes as Record<string, { url?: string }> | undefined;
					onCommitEdit( {
						id: Number( sel.id ),
						url: String( sizes?.thumbnail?.url || sel.url || '' ),
						alt: String( sel.alt || '' ),
						filename: String( sel.filename || '' ),
					} );
				} );
				frame.on( 'close', () => {
					if ( ! didSelect ) {
						onCancelEdit();
					}
				} );
				frame.open();
			} else {
				onCancelEdit();
			}
			return;
		}

		if ( field.type === 'boolean' ) {
			onCommitEdit( ! Boolean( rawValue ) );
			return;
		}

		if ( initialChar ) {
			setDraft( initialChar );
		} else if ( rawValue === null || rawValue === undefined ) {
			setDraft( '' );
		} else {
			setDraft( String( rawValue ) );
		}

		requestAnimationFrame( () => {
			if ( inputRef.current ) {
				inputRef.current.focus();
				if ( inputRef.current instanceof HTMLInputElement && ! initialChar ) {
					inputRef.current.select();
				}
			}
		} );
	}, [ isEditing ] );

	const coerceDraft = ( text: string ): unknown => {
		if ( field.type === 'number' || field.type === 'currency' ) {
			const trimmed = text.trim();
			if ( trimmed === '' ) {
				return null;
			}
			const parsed = parseLocaleNumber( trimmed );
			return parsed === null ? trimmed : parsed;
		}
		if ( field.type === 'integer' ) {
			const trimmed = text.trim();
			if ( trimmed === '' ) {
				return null;
			}
			const parsed = parseLocaleNumber( trimmed );
			return parsed === null ? trimmed : Math.trunc( parsed );
		}
		return text;
	};

	const safeCommit = ( val: unknown, move?: 'next-col' | 'prev-col' | 'next-row' | 'prev-row' ) => {
		if ( committedRef.current ) {
			return;
		}
		committedRef.current = true;
		onCommitEdit( val, move );
	};

	const safeCancel = () => {
		if ( committedRef.current ) {
			return;
		}
		committedRef.current = true;
		onCancelEdit();
	};

	const handleInputKeyDown = ( e: React.KeyboardEvent<HTMLInputElement | HTMLSelectElement> ) => {
		if ( e.key === 'Escape' ) {
			e.preventDefault();
			e.stopPropagation();
			safeCancel();
			return;
		}
		if ( e.key === 'Enter' ) {
			e.preventDefault();
			e.stopPropagation();
			safeCommit( coerceDraft( draft ), e.shiftKey ? 'prev-row' : 'next-row' );
			return;
		}
		if ( e.key === 'Tab' ) {
			e.preventDefault();
			e.stopPropagation();
			safeCommit( coerceDraft( draft ), e.shiftKey ? 'prev-col' : 'next-col' );
		}
	};

	const classes = [
		'rc-td',
		isSticky ? 'rc-td--sticky-primary' : '',
		field.align === 'right' ? 'rc-td--right' : '',
		field.align === 'center' ? 'rc-td--center' : '',
		field.monospace ? 'rc-td--mono' : '',
		field.tabularNums ? 'rc-td--tabular' : '',
		field.extra?.computed ? 'rc-td--computed' : '',
		canEdit ? 'rc-td--editable' : '',
		isFocused ? 'is-focused' : '',
		isInRange ? 'is-in-range' : '',
		isFillTarget ? 'is-fill-target' : '',
		isDirty ? 'is-dirty' : '',
		cellError ? 'is-error' : '',
	]
		.filter( Boolean )
		.join( ' ' );

	const currencySymbol = typeof field.extra?.currency === 'string' ? field.extra.currency : undefined;
	const suffixSymbol = typeof field.extra?.suffix === 'string' ? field.extra.suffix : undefined;
	const recommendedMax = field.validation?.recommendedMax;

	// Pricing anomaly check: sale_price >= regular_price
	const isSaleAnomaly =
		field.id === 'sale_price' &&
		rawValue !== null &&
		rawValue !== '' &&
		record.regular_price !== null &&
		record.regular_price !== '' &&
		Number( rawValue ) >= Number( record.regular_price );

	const renderDisplay = () => {
		if ( field.type === 'media' ) {
			const media = rawValue as MediaValue | null | undefined;
			if ( media && typeof media === 'object' && media.url ) {
				return <img className="rc-thumb" src={ media.url } alt={ media.alt || '' } loading="lazy" />;
			}
			return <span className="rc-thumb-empty">—</span>;
		}

		if ( field.type === 'boolean' ) {
			return (
				<input
					type="checkbox"
					checked={ Boolean( rawValue ) }
					disabled={ ! canEdit }
					aria-label={ field.label }
					onChange={ ( e ) => {
						e.stopPropagation();
						if ( canEdit ) {
							onCommitEdit( e.target.checked );
						}
					} }
					onClick={ ( e ) => e.stopPropagation() }
				/>
			);
		}

		if ( field.type === 'select' ) {
			const str = String( rawValue ?? '' );
			const opt = field.options.find( ( o ) => o.value === str );
			const label = opt ? opt.label : str || '—';
			const showDot = field.id === 'status' || field.id === 'stock_status';
			return (
				<span className="rc-status-chip">
					{ showDot && <span className={ `rc-status-dot ${ statusDotClass( str ) }` } /> }
					<span className="rc-cell-text">{ label }</span>
				</span>
			);
		}

		if ( field.type === 'taxonomy' || field.type === 'multiselect' ) {
			const terms = Array.isArray( rawValue )
				? rawValue
				: typeof rawValue === 'string' && rawValue.trim() !== ''
				? rawValue.split( ',' ).map( ( s ) => s.trim() ).filter( Boolean )
				: [];
			if ( terms.length === 0 ) {
				return <span className="rc-cell-muted">—</span>;
			}
			return (
				<div className="rc-term-list">
					{ terms.slice( 0, 3 ).map( ( t, idx ) => {
						const label =
							typeof t === 'object' && t && 'label' in t
								? String( ( t as TermValue ).label )
								: field.options.find( ( o ) => o.value === String( t ) )?.label || String( t );
						return (
							<span key={ idx } className="rc-term-tag">
								{ label }
							</span>
						);
					} ) }
					{ terms.length > 3 && <span className="rc-cell-muted">+{ terms.length - 3 }</span> }
				</div>
			);
		}

		if ( field.type === 'currency' || field.type === 'number' || field.type === 'integer' ) {
			const formatted = formatNumber(
				rawValue,
				field.type === 'currency' ? currencySymbol : undefined,
				suffixSymbol
			);
			return (
				<span
					className={ `rc-cell-text ${ formatted === '—' ? 'rc-cell-muted' : '' }` }
					style={ isSaleAnomaly ? { color: 'var(--rc-danger)', fontWeight: 600 } : undefined }
					title={ isSaleAnomaly ? __( 'Sale price is greater than or equal to regular price', 'datadeck' ) : undefined }
				>
					{ formatted }
				</span>
			);
		}

		const text = rawValue === null || rawValue === undefined ? '' : String( rawValue );
		const charLen = text.length;

		return (
			<>
				{ isPrimary && record._isVariation && <span className="rc-var-indent" aria-hidden="true" /> }
				{ isPrimary && Number( record._varCount || 0 ) > 0 && onToggleVariations && (
					<button
						type="button"
						className="rc-var-toggle"
						aria-label={ __( 'Toggle variations', 'datadeck' ) }
						onClick={ ( e ) => {
							e.stopPropagation();
							onToggleVariations( record.id );
						} }
					>
						{ isExpandedParent ? '▾' : '▸' }
					</button>
				) }
				<span className={ `rc-cell-text ${ text === '' ? 'rc-cell-muted' : '' }` } style={ isPrimary ? { fontWeight: 500 } : undefined }>
					{ text || '—' }
				</span>
				{ isPrimary && Number( record._varCount || 0 ) > 0 && (
					<span className="rc-var-count" title={ __( 'Variations', 'datadeck' ) }>
						{ record._varCount }
					</span>
				) }
				{ recommendedMax && text !== '' && (
					<span className={ `rc-char-meter ${ charLen > recommendedMax ? 'is-over' : '' }` }>
						{ charLen }/{ recommendedMax }
					</span>
				) }
				{ isPrimary && (
					<span className="rc-row-hover-actions" onClick={ ( e ) => e.stopPropagation() }>
						{ onInspectRow && (
							<button
								type="button"
								className="rc-icon-btn"
								title={ __( 'Inspect record', 'datadeck' ) }
								onClick={ () => onInspectRow( record ) }
							>
								<Icon icon={ drawerRight } size={ 14 } />
							</button>
						) }
						{ onDuplicateRow && ! record._isVariation && (
							<button
								type="button"
								className="rc-icon-btn"
								title={ __( 'Duplicate row', 'datadeck' ) }
								onClick={ () => onDuplicateRow( record.id ) }
							>
								<Icon icon={ copy } size={ 14 } />
							</button>
						) }
						{ record._editUrl && (
							<a
								className="rc-icon-btn"
								href={ record._editUrl }
								target="_blank"
								rel="noreferrer"
								title={ __( 'Open in WordPress editor', 'datadeck' ) }
							>
								<Icon icon={ external } size={ 14 } />
							</a>
						) }
					</span>
				) }
			</>
		);
	};

	const renderEditor = () => {
		if ( ! isEditing || ! canEdit ) {
			return null;
		}

		if ( field.type === 'select' ) {
			return (
				<select
					ref={ inputRef as React.RefObject<HTMLSelectElement> }
					className="rc-cell-select"
					value={ draft }
					onChange={ ( e ) => {
						setDraft( e.target.value );
						safeCommit( e.target.value );
					} }
					onBlur={ () => safeCancel() }
					onKeyDown={ handleInputKeyDown }
				>
					{ field.options.map( ( opt ) => (
						<option key={ opt.value } value={ opt.value }>
							{ opt.label }
						</option>
					) ) }
				</select>
			);
		}

		if ( ( field.type === 'taxonomy' || field.type === 'multiselect' ) && popoverPos ) {
			const commitPopover = () => {
				if ( field.type === 'taxonomy' ) {
					const mapped: TermValue[] = selectedTermIds.map( ( id ) => {
						const opt = field.options.find( ( o ) => Number( o.value ) === id );
						return { id, label: opt?.label || String( id ), slug: '' };
					} );
					safeCommit( mapped );
				} else {
					safeCommit( selectedMultiVals );
				}
			};
			return (
				<div
					className="rc-popover"
					style={ { top: popoverPos.top, left: popoverPos.left } }
					onClick={ ( e ) => e.stopPropagation() }
					onKeyDown={ ( e ) => {
						if ( e.key === 'Escape' ) {
							e.preventDefault();
							e.stopPropagation();
							safeCancel();
						}
					} }
				>
					<div className="rc-popover-list">
						{ field.options.length === 0 ? (
							<div style={ { padding: '8px', fontSize: '12px', color: 'var(--rc-ink-muted)' } }>
								{ __( 'No options available.', 'datadeck' ) }
							</div>
						) : (
							field.options.map( ( opt ) => {
								const isTax = field.type === 'taxonomy';
								const tid = Number( opt.value );
								const checked = isTax
									? selectedTermIds.includes( tid )
									: selectedMultiVals.includes( opt.value );
								return (
									<label key={ opt.value } className="rc-popover-opt">
										<input
											type="checkbox"
											checked={ checked }
											onChange={ () => {
												if ( isTax ) {
													setSelectedTermIds( ( prev ) =>
														checked ? prev.filter( ( x ) => x !== tid ) : [ ...prev, tid ]
													);
												} else {
													setSelectedMultiVals( ( prev ) =>
														checked ? prev.filter( ( x ) => x !== opt.value ) : [ ...prev, opt.value ]
													);
												}
											} }
										/>
										<span>{ opt.label }</span>
									</label>
								);
							} )
						) }
					</div>
					<div className="rc-modal-footer" style={ { padding: '6px 8px' } }>
						<button type="button" className="rc-btn rc-btn--ghost" onClick={ safeCancel }>
							{ __( 'Cancel', 'datadeck' ) }
						</button>
						<button type="button" className="rc-btn rc-btn--primary" onClick={ commitPopover }>
							{ __( 'Apply', 'datadeck' ) }
						</button>
					</div>
				</div>
			);
		}

		if ( field.type === 'media' || field.type === 'boolean' ) {
			return null;
		}

		return (
			<input
				ref={ inputRef as React.RefObject<HTMLInputElement> }
				className="rc-cell-input"
				type={ field.type === 'date' ? 'date' : 'text' }
				value={ draft }
				onChange={ ( e ) => setDraft( e.target.value ) }
				onBlur={ () => safeCommit( coerceDraft( draft ) ) }
				onKeyDown={ handleInputKeyDown }
			/>
		);
	};

	return (
		<div
			ref={ cellRef }
			className={ classes }
			style={ { width: `${ width }px` } }
			role="gridcell"
			title={ cellError || undefined }
			onClick={ ( e ) => onFocus( e.shiftKey ) }
			onMouseEnter={ () => onFillDragEnter?.() }
			onContextMenu={ ( e ) => {
				if ( onContextMenuCell ) {
					e.preventDefault();
					onFocus( false );
					onContextMenuCell( e, rawValue );
				}
			} }
			onDoubleClick={ () => {
				if ( canEdit ) {
					onStartEdit();
				}
			} }
		>
			{ renderDisplay() }
			{ renderEditor() }
			{ isFocused && ! isEditing && canEdit && onStartFillDrag && ! [ 'media', 'taxonomy', 'multiselect' ].includes( field.type ) && (
				<span
					className="rc-fill-handle"
					title={ __( 'Drag down to fill series', 'datadeck' ) }
					onMouseDown={ ( e ) => {
						e.preventDefault();
						e.stopPropagation();
						onStartFillDrag();
					} }
				/>
			) }
		</div>
	);
} );
