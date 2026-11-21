import { useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { useVirtualizer } from '@tanstack/react-virtual';
import { __ } from '@wordpress/i18n';
import { InlineCell } from '../cells/InlineCell';
import type {
	CellCoord,
	CellRange,
	DensityMode,
	FieldDefinition,
	RecordRow,
	StagedEdit,
} from '../types';

interface SpreadsheetGridProps {
	records: RecordRow[];
	expandedParents: Record<number, RecordRow[]>;
	columns: FieldDefinition[];
	columnWidths: Record<string, number>;
	density: DensityMode;
	sortField: string;
	sortDirection: 'asc' | 'desc';
	selectedIds: Record<number, boolean>;
	focusedCell: CellCoord | null;
	editingCell: CellCoord | null;
	selectedRange: CellRange | null;
	loading: boolean;
	stagedEdits: Record<string, StagedEdit>;
	onSort: ( fieldId: string ) => void;
	onResizeColumn: ( fieldId: string, width: number ) => void;
	onToggleSelectRow: ( id: number ) => void;
	onToggleSelectAll: ( ids: number[] ) => void;
	onFocusCell: ( cell: CellCoord | null ) => void;
	onEditCell: ( cell: CellCoord | null ) => void;
	onSelectRange: ( range: CellRange | null ) => void;
	onStageOrSaveEdits: ( edits: StagedEdit[], mode?: 'inline' | 'paste' ) => void;
	onToggleVariations: ( parentId: number ) => void;
	onInspectRow: ( record: RecordRow ) => void;
	onDuplicateRow: ( id: number ) => void;
}

const ROW_HEIGHTS: Record<DensityMode, number> = {
	compact: 30,
	default: 36,
	comfortable: 48,
};

function parsePastedCell( raw: string, field: FieldDefinition ): unknown {
	const trimmed = raw.trim();
	if ( field.type === 'number' || field.type === 'currency' ) {
		if ( trimmed === '' ) {
			return null;
		}
		const clean = trimmed.replace( /[^0-9.,-]/g, '' ).replace( ',', '.' );
		const n = Number( clean );
		return Number.isNaN( n ) ? trimmed : n;
	}
	if ( field.type === 'integer' ) {
		if ( trimmed === '' ) {
			return null;
		}
		const n = parseInt( trimmed, 10 );
		return Number.isNaN( n ) ? trimmed : n;
	}
	if ( field.type === 'boolean' ) {
		return [ 'true', '1', 'yes', 'on' ].includes( trimmed.toLowerCase() );
	}
	return trimmed;
}

export function SpreadsheetGrid( {
	records,
	expandedParents,
	columns,
	columnWidths,
	density,
	sortField,
	sortDirection,
	selectedIds,
	focusedCell,
	editingCell,
	selectedRange,
	loading,
	stagedEdits,
	onSort,
	onResizeColumn,
	onToggleSelectRow,
	onToggleSelectAll,
	onFocusCell,
	onEditCell,
	onSelectRange,
	onStageOrSaveEdits,
	onToggleVariations,
	onInspectRow,
	onDuplicateRow,
}: SpreadsheetGridProps ) {
	const scrollRef = useRef<HTMLDivElement>( null );
	const [ scrolledX, setScrolledX ] = useState( false );
	const [ initialChar, setInitialChar ] = useState<string | undefined>( undefined );
	const [ resizingCol, setResizingCol ] = useState<string | null>( null );

	// Flatten parent records with any expanded WooCommerce variation sub-rows.
	const flatRows = useMemo( () => {
		const out: RecordRow[] = [];
		for ( const rec of records ) {
			out.push( rec );
			const children = expandedParents[ rec.id ];
			if ( children && children.length > 0 ) {
				for ( const child of children ) {
					out.push( { ...child, _isVariation: true, _parentId: rec.id } );
				}
			}
		}
		return out;
	}, [ records, expandedParents ] );

	const rowHeight = ROW_HEIGHTS[ density ];

	const rowVirtualizer = useVirtualizer( {
		count: flatRows.length,
		getScrollElement: () => scrollRef.current,
		estimateSize: () => rowHeight,
		overscan: 10,
	} );

	useEffect( () => {
		rowVirtualizer.measure();
	}, [ rowHeight, flatRows.length ] );

	// Sticky-pin column 0 (immediately right of 38px checkbox) so left: 38px never overlaps preceding columns.
	const stickyColIndex = 0;

	// Identify semantic primary text column (for hover actions & variation expansion arrow).
	const primaryColIndex = useMemo( () => {
		const preferred = [ 'title', 'display_name', 'customer_name' ];
		for ( const id of preferred ) {
			const idx = columns.findIndex( ( c ) => c.id === id );
			if ( idx >= 0 ) {
				return idx;
			}
		}
		return 0;
	}, [ columns ] );

	const totalWidth = useMemo( () => {
		let sum = 38; // Checkbox column
		for ( const col of columns ) {
			sum += columnWidths[ col.id ] || col.width || 150;
		}
		return sum;
	}, [ columns, columnWidths ] );

	const allIds = useMemo( () => flatRows.map( ( r ) => r.id ), [ flatRows ] );
	const allSelected = allIds.length > 0 && allIds.every( ( id ) => Boolean( selectedIds[ id ] ) );

	const startColumnResize = ( e: React.MouseEvent, fieldId: string, startWidth: number ) => {
		e.preventDefault();
		e.stopPropagation();
		setResizingCol( fieldId );
		const startX = e.clientX;

		const onMove = ( ev: MouseEvent ) => {
			onResizeColumn( fieldId, startWidth + ( ev.clientX - startX ) );
		};
		const onUp = () => {
			setResizingCol( null );
			window.removeEventListener( 'mousemove', onMove );
			window.removeEventListener( 'mouseup', onUp );
		};
		window.addEventListener( 'mousemove', onMove );
		window.addEventListener( 'mouseup', onUp );
	};

	const moveFocus = useCallback(
		( nextRow: number, nextCol: number, extendRange = false ) => {
			const clampedRow = Math.max( 0, Math.min( flatRows.length - 1, nextRow ) );
			const clampedCol = Math.max( 0, Math.min( columns.length - 1, nextCol ) );
			const row = flatRows[ clampedRow ];
			const col = columns[ clampedCol ];
			if ( ! row || ! col ) {
				return;
			}

			if ( extendRange && focusedCell && focusedCell.colIndex === clampedCol ) {
				onSelectRange( {
					startRow: Math.min( focusedCell.rowIndex, clampedRow ),
					endRow: Math.max( focusedCell.rowIndex, clampedRow ),
					colIndex: clampedCol,
					fieldId: col.id,
				} );
			} else {
				onSelectRange( null );
			}

			onFocusCell( {
				rowIndex: clampedRow,
				colIndex: clampedCol,
				recordId: row.id,
				fieldId: col.id,
			} );
			rowVirtualizer.scrollToIndex( clampedRow, { align: 'auto' } );
		},
		[ flatRows, columns, focusedCell, onFocusCell, onSelectRange, rowVirtualizer ]
	);

	// Global spreadsheet keyboard navigation, Fill Down (Cmd+D), and Copy (Cmd+C).
	const handleGridKeyDown = ( e: React.KeyboardEvent<HTMLDivElement> ) => {
		if ( editingCell || ! focusedCell ) {
			return;
		}
		const { rowIndex, colIndex } = focusedCell;
		const mod = e.metaKey || e.ctrlKey;

		if ( e.key === 'ArrowDown' ) {
			e.preventDefault();
			moveFocus( rowIndex + 1, colIndex, e.shiftKey );
			return;
		}
		if ( e.key === 'ArrowUp' ) {
			e.preventDefault();
			moveFocus( rowIndex - 1, colIndex, e.shiftKey );
			return;
		}
		if ( e.key === 'ArrowRight' || ( e.key === 'Tab' && ! e.shiftKey ) ) {
			e.preventDefault();
			moveFocus( rowIndex, colIndex + 1 );
			return;
		}
		if ( e.key === 'ArrowLeft' || ( e.key === 'Tab' && e.shiftKey ) ) {
			e.preventDefault();
			moveFocus( rowIndex, colIndex - 1 );
			return;
		}
		if ( e.key === 'Enter' || e.key === 'F2' ) {
			e.preventDefault();
			const col = columns[ colIndex ];
			if ( col?.editable ) {
				setInitialChar( undefined );
				onEditCell( focusedCell );
			}
			return;
		}
		if ( e.key === 'Escape' ) {
			onSelectRange( null );
			return;
		}

		// Fill Down (Cmd/Ctrl + D): copy top cell across selected vertical range.
		if ( mod && e.key.toLowerCase() === 'd' && selectedRange ) {
			e.preventDefault();
			const col = columns[ selectedRange.colIndex ];
			const topRow = flatRows[ selectedRange.startRow ];
			if ( ! col?.editable || ! topRow ) {
				return;
			}
			const topKey = `${ topRow.id }:${ col.id }`;
			const topValue = stagedEdits[ topKey ] !== undefined ? stagedEdits[ topKey ].newValue : topRow[ col.id ];
			const edits: StagedEdit[] = [];
			for ( let r = selectedRange.startRow + 1; r <= selectedRange.endRow; r++ ) {
				const target = flatRows[ r ];
				if ( target && target._canEdit !== false ) {
					edits.push( {
						recordId: target.id,
						fieldId: col.id,
						oldValue: target[ col.id ],
						newValue: topValue,
					} );
				}
			}
			if ( edits.length > 0 ) {
				onStageOrSaveEdits( edits, 'inline' );
			}
			return;
		}

		// Copy focused cell or vertical range as TSV for Excel/Google Sheets.
		if ( mod && e.key.toLowerCase() === 'c' ) {
			const startR = selectedRange ? selectedRange.startRow : rowIndex;
			const endR = selectedRange ? selectedRange.endRow : rowIndex;
			const col = columns[ colIndex ];
			if ( col && navigator.clipboard ) {
				const lines: string[] = [];
				for ( let r = startR; r <= endR; r++ ) {
					const row = flatRows[ r ];
					if ( ! row ) {
						continue;
					}
					const k = `${ row.id }:${ col.id }`;
					const val = stagedEdits[ k ] !== undefined ? stagedEdits[ k ].newValue : row[ col.id ];
					lines.push( val === null || val === undefined ? '' : String( val ) );
				}
				navigator.clipboard.writeText( lines.join( '\n' ) ).catch( () => {} );
			}
			return;
		}

		// Direct typing starts inline editing immediately (true Excel feel).
		if ( ! mod && ! e.altKey && e.key.length === 1 ) {
			const col = columns[ colIndex ];
			if ( col?.editable && [ 'text', 'textarea', 'number', 'currency', 'integer', 'slug', 'url', 'email' ].includes( col.type ) ) {
				e.preventDefault();
				setInitialChar( e.key );
				onEditCell( focusedCell );
			}
		}
	};

	// Excel / Google Sheets multi-cell TSV paste handler.
	const handlePaste = ( e: React.ClipboardEvent<HTMLDivElement> ) => {
		if ( editingCell || ! focusedCell ) {
			return;
		}
		const text = e.clipboardData.getData( 'text/plain' );
		if ( ! text ) {
			return;
		}
		e.preventDefault();

		const matrix = text
			.replace( /\r\n/g, '\n' )
			.replace( /\r/g, '\n' )
			.replace( /\n$/, '' )
			.split( '\n' )
			.map( ( line ) => line.split( '\t' ) );

		const edits: StagedEdit[] = [];
		for ( let rOffset = 0; rOffset < matrix.length; rOffset++ ) {
			const targetRow = flatRows[ focusedCell.rowIndex + rOffset ];
			if ( ! targetRow || targetRow._canEdit === false ) {
				continue;
			}
			const rowVals = matrix[ rOffset ];
			for ( let cOffset = 0; cOffset < rowVals.length; cOffset++ ) {
				const targetCol = columns[ focusedCell.colIndex + cOffset ];
				if ( ! targetCol || ! targetCol.editable ) {
					continue;
				}
				edits.push( {
					recordId: targetRow.id,
					fieldId: targetCol.id,
					oldValue: targetRow[ targetCol.id ],
					newValue: parsePastedCell( rowVals[ cOffset ], targetCol ),
				} );
			}
		}

		if ( edits.length > 0 ) {
			onStageOrSaveEdits( edits, 'paste' );
		}
	};

	return (
		<div
			ref={ scrollRef }
			className={ `rc-grid-shell ${ loading ? 'is-fetching' : '' }` }
			data-scrolled-x={ scrolledX ? 'true' : 'false' }
			tabIndex={ 0 }
			role="grid"
			aria-rowcount={ flatRows.length }
			aria-colcount={ columns.length + 1 }
			onKeyDown={ handleGridKeyDown }
			onPaste={ handlePaste }
			onScroll={ ( e ) => {
				const left = e.currentTarget.scrollLeft;
				if ( ( left > 0 ) !== scrolledX ) {
					setScrolledX( left > 0 );
				}
			} }
		>
			<div className="rc-grid-table" style={ { width: `${ totalWidth }px` } }>
				{/* Sticky Header Row */}
				<div className="rc-grid-header" role="row">
					<div
						className="rc-th rc-th--center rc-th--sticky-check"
						style={ { width: '38px' } }
						role="columnheader"
					>
						<input
							type="checkbox"
							checked={ allSelected }
							aria-label={ __( 'Select all visible rows', 'rowcraft' ) }
							onChange={ () => onToggleSelectAll( allSelected ? [] : allIds ) }
						/>
					</div>

					{ columns.map( ( col, colIdx ) => {
						const w = columnWidths[ col.id ] || col.width || 150;
						const isSorted = sortField === col.id;
						const isStickyCol = colIdx === stickyColIndex;
						return (
							<div
								key={ col.id }
								className={ [
									'rc-th',
									col.sortable ? 'rc-th--sortable' : '',
									col.align === 'right' ? 'rc-th--right' : '',
									col.align === 'center' ? 'rc-th--center' : '',
									isStickyCol ? 'rc-th--sticky-primary' : '',
								]
									.filter( Boolean )
									.join( ' ' ) }
								style={ { width: `${ w }px` } }
								role="columnheader"
								onClick={ () => col.sortable && onSort( col.id ) }
							>
								<span className="rc-th-label">{ col.label }</span>
								{ isSorted && (
									<span className="rc-sort-arrow" aria-hidden="true">
										{ sortDirection === 'asc' ? '▲' : '▼' }
									</span>
								) }
								<span
									className={ `rc-col-resizer ${ resizingCol === col.id ? 'is-resizing' : '' }` }
									onMouseDown={ ( e ) => startColumnResize( e, col.id, w ) }
									onClick={ ( e ) => e.stopPropagation() }
								/>
							</div>
						);
					} ) }
				</div>

				{/* Virtualized Body — renders only ~30 visible DOM rows even at 500/page */}
				{ flatRows.length === 0 && ! loading ? (
					<div className="rc-empty-state">
						<span>{ __( 'No matching records found.', 'rowcraft' ) }</span>
					</div>
				) : (
					<div
						className="rc-grid-body"
						style={ { height: `${ rowVirtualizer.getTotalSize() }px` } }
					>
						{ rowVirtualizer.getVirtualItems().map( ( vRow ) => {
							const record = flatRows[ vRow.index ];
							if ( ! record ) {
								return null;
							}
							const isSelected = Boolean( selectedIds[ record.id ] );
							const isExpandedParent = Boolean( expandedParents[ record.id ] );

							return (
								<div
									key={ `${ record.id }-${ record._isVariation ? 'v' : 'p' }` }
									className={ [
										'rc-tr',
										isSelected ? 'is-selected' : '',
										record._isVariation ? 'is-variation' : '',
									]
										.filter( Boolean )
										.join( ' ' ) }
									style={ {
										width: `${ totalWidth }px`,
										top: `${ vRow.start }px`,
									} }
									role="row"
									aria-rowindex={ vRow.index + 1 }
								>
									<div
										className="rc-td rc-td--center rc-td--sticky-check"
										style={ { width: '38px' } }
									>
										<input
											type="checkbox"
											checked={ isSelected }
											aria-label={ `Select #${ record.id }` }
											onChange={ () => onToggleSelectRow( record.id ) }
										/>
									</div>

									{ columns.map( ( col, colIdx ) => {
										const isFocused =
											focusedCell?.rowIndex === vRow.index &&
											focusedCell?.colIndex === colIdx;
										const isEditing =
											editingCell?.rowIndex === vRow.index &&
											editingCell?.colIndex === colIdx;
										const isInRange =
											selectedRange !== null &&
											selectedRange.colIndex === colIdx &&
											vRow.index >= selectedRange.startRow &&
											vRow.index <= selectedRange.endRow;

										return (
											<InlineCell
												key={ col.id }
												record={ record }
												field={ col }
												rowIndex={ vRow.index }
												colIndex={ colIdx }
												width={ columnWidths[ col.id ] || col.width || 150 }
												isPrimary={ colIdx === primaryColIndex }
												isSticky={ colIdx === stickyColIndex }
												isFocused={ isFocused }
												isEditing={ isEditing }
												isInRange={ isInRange }
												initialChar={ isEditing ? initialChar : undefined }
												isExpandedParent={ isExpandedParent }
												onFocus={ ( shiftKey ) => {
													if ( editingCell && ! isEditing ) {
														onEditCell( null );
													}
													if ( shiftKey && focusedCell && focusedCell.colIndex === colIdx ) {
														onSelectRange( {
															startRow: Math.min( focusedCell.rowIndex, vRow.index ),
															endRow: Math.max( focusedCell.rowIndex, vRow.index ),
															colIndex: colIdx,
															fieldId: col.id,
														} );
													} else {
														onSelectRange( null );
													}
													onFocusCell( {
														rowIndex: vRow.index,
														colIndex: colIdx,
														recordId: record.id,
														fieldId: col.id,
													} );
												} }
												onStartEdit={ () => {
													setInitialChar( undefined );
													onEditCell( {
														rowIndex: vRow.index,
														colIndex: colIdx,
														recordId: record.id,
														fieldId: col.id,
													} );
												} }
												onCommitEdit={ ( newValue, move ) => {
													onStageOrSaveEdits(
														[
															{
																recordId: record.id,
																fieldId: col.id,
																oldValue: record[ col.id ],
																newValue,
															},
														],
														'inline'
													);
													onEditCell( null );
													setInitialChar( undefined );
													if ( move === 'next-col' ) {
														moveFocus( vRow.index, colIdx + 1 );
													} else if ( move === 'prev-col' ) {
														moveFocus( vRow.index, colIdx - 1 );
													} else if ( move === 'next-row' ) {
														moveFocus( vRow.index + 1, colIdx );
													} else if ( move === 'prev-row' ) {
														moveFocus( vRow.index - 1, colIdx );
													}
												} }
												onCancelEdit={ () => {
													onEditCell( null );
													setInitialChar( undefined );
												} }
												onToggleVariations={ onToggleVariations }
												onInspectRow={ onInspectRow }
												onDuplicateRow={ onDuplicateRow }
											/>
										);
									} ) }
								</div>
							);
						} ) }
					</div>
				) }
			</div>
		</div>
	);
}
