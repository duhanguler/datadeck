import { useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { useVirtualizer } from '@tanstack/react-virtual';
import { __ } from '@wordpress/i18n';
import { InlineCell } from '../cells/InlineCell';
import { computeNumericAggregation, generateFillSeries, parseLocaleNumber } from '../utils/series';
import type { ContextMenuState } from './GridContextMenu';
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
	onReorderColumns: ( fromId: string, toId: string ) => void;
	onToggleSelectRow: ( id: number ) => void;
	onToggleSelectAll: ( ids: number[] ) => void;
	onFocusCell: ( cell: CellCoord | null ) => void;
	onEditCell: ( cell: CellCoord | null ) => void;
	onSelectRange: ( range: CellRange | null ) => void;
	onStageOrSaveEdits: ( edits: StagedEdit[], mode?: 'inline' | 'paste' ) => void;
	onToggleVariations: ( parentId: number ) => void;
	onInspectRow: ( record: RecordRow ) => void;
	onDuplicateRow?: ( id: number ) => void;
	onOpenContextMenu: ( menu: ContextMenuState ) => void;
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
		const n = parseLocaleNumber( trimmed );
		return n === null ? trimmed : n;
	}
	if ( field.type === 'integer' ) {
		if ( trimmed === '' ) {
			return null;
		}
		const n = parseLocaleNumber( trimmed );
		return n === null ? trimmed : Math.trunc( n );
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
	onReorderColumns,
	onToggleSelectRow,
	onToggleSelectAll,
	onFocusCell,
	onEditCell,
	onSelectRange,
	onStageOrSaveEdits,
	onToggleVariations,
	onInspectRow,
	onDuplicateRow,
	onOpenContextMenu,
}: SpreadsheetGridProps ) {
	const scrollRef = useRef<HTMLDivElement>( null );
	const rangeAnchorRef = useRef<{ rowIndex: number; colIndex: number } | null>( null );
	const [ scrolledX, setScrolledX ] = useState( false );
	const [ initialChar, setInitialChar ] = useState<string | undefined>( undefined );
	const [ resizingCol, setResizingCol ] = useState<string | null>( null );
	const [ dragColId, setDragColId ] = useState<string | null>( null );
	const [ dropTargetColId, setDropTargetColId ] = useState<string | null>( null );
	const [ fillDrag, setFillDrag ] = useState<{
		colIndex: number;
		seedStartRow: number;
		seedEndRow: number;
		targetEndRow: number;
	} | null>( null );

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
				const anchor =
					rangeAnchorRef.current && rangeAnchorRef.current.colIndex === clampedCol
						? rangeAnchorRef.current.rowIndex
						: focusedCell.rowIndex;
				rangeAnchorRef.current = { rowIndex: anchor, colIndex: clampedCol };
				onSelectRange( {
					startRow: Math.min( anchor, clampedRow ),
					endRow: Math.max( anchor, clampedRow ),
					colIndex: clampedCol,
					fieldId: col.id,
				} );
			} else {
				rangeAnchorRef.current = { rowIndex: clampedRow, colIndex: clampedCol };
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
					if ( val === null || val === undefined ) {
						lines.push( '' );
					} else if ( Array.isArray( val ) ) {
						lines.push(
							val
								.map( ( item ) =>
									typeof item === 'object' && item && 'label' in item
										? String( ( item as { label: unknown } ).label )
										: String( item )
								)
								.join( ', ' )
						);
					} else if ( typeof val === 'object' && 'url' in ( val as Record<string, unknown> ) ) {
						lines.push( String( ( val as { url: unknown } ).url || '' ) );
					} else if ( typeof val === 'object' ) {
						lines.push( JSON.stringify( val ) );
					} else {
						lines.push( String( val ) );
					}
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
				if ( targetCol.type === 'media' ) {
					const parsedId = parseInt( rowVals[ cOffset ].trim(), 10 );
					if ( Number.isNaN( parsedId ) || parsedId <= 0 ) {
						continue;
					}
					edits.push( {
						recordId: targetRow.id,
						fieldId: targetCol.id,
						oldValue: targetRow[ targetCol.id ],
						newValue: parsedId,
					} );
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

	// Commit Drag-to-Fill Handle series on mouseup.
	useEffect( () => {
		if ( ! fillDrag ) {
			return;
		}
		const onMouseUp = () => {
			const col = columns[ fillDrag.colIndex ];
			if ( col && col.editable && fillDrag.targetEndRow > fillDrag.seedEndRow ) {
				const seeds: unknown[] = [];
				for ( let r = fillDrag.seedStartRow; r <= fillDrag.seedEndRow; r++ ) {
					const row = flatRows[ r ];
					if ( row ) {
						const k = `${ row.id }:${ col.id }`;
						seeds.push( stagedEdits[ k ] !== undefined ? stagedEdits[ k ].newValue : row[ col.id ] );
					}
				}
				const count = fillDrag.targetEndRow - fillDrag.seedEndRow;
				const generated = generateFillSeries( seeds, count, col.type );
				const edits: StagedEdit[] = [];
				for ( let i = 0; i < count; i++ ) {
					const targetRow = flatRows[ fillDrag.seedEndRow + 1 + i ];
					if ( targetRow && targetRow._canEdit !== false ) {
						edits.push( {
							recordId: targetRow.id,
							fieldId: col.id,
							oldValue: targetRow[ col.id ],
							newValue: generated[ i ],
						} );
					}
				}
				if ( edits.length > 0 ) {
					onStageOrSaveEdits( edits, 'inline' );
					onSelectRange( {
						startRow: fillDrag.seedStartRow,
						endRow: fillDrag.targetEndRow,
						colIndex: fillDrag.colIndex,
						fieldId: col.id,
					} );
				}
			}
			setFillDrag( null );
		};
		window.addEventListener( 'mouseup', onMouseUp );
		return () => window.removeEventListener( 'mouseup', onMouseUp );
	}, [ fillDrag, columns, flatRows, stagedEdits, onStageOrSaveEdits, onSelectRange ] );

	// Live client-side aggregation summary (zero extra SQL).
	const aggSummary = useMemo( () => {
		if ( flatRows.length === 0 ) {
			return null;
		}
		const isNumericCol = ( f?: FieldDefinition ) =>
			Boolean( f && [ 'number', 'currency', 'integer' ].includes( f.type ) && f.id !== 'id' );

		let targetCol: FieldDefinition | undefined;
		let targetRows = flatRows;
		let scopeLabel: string = __( 'Visible page', 'datadeck' );

		if ( selectedRange ) {
			const col = columns[ selectedRange.colIndex ];
			if ( isNumericCol( col ) ) {
				targetCol = col;
				targetRows = flatRows.slice( selectedRange.startRow, selectedRange.endRow + 1 );
				scopeLabel = `${ __( 'Selected range', 'datadeck' ) } (${ targetRows.length })`;
			}
		}

		if ( ! targetCol && focusedCell ) {
			const col = columns[ focusedCell.colIndex ];
			if ( isNumericCol( col ) ) {
				targetCol = col;
			}
		}

		if ( ! targetCol ) {
			targetCol = columns.find( ( c ) => isNumericCol( c ) );
		}

		if ( ! targetCol ) {
			return null;
		}

		const values = targetRows.map( ( row ) => {
			const k = `${ row.id }:${ targetCol!.id }`;
			return stagedEdits[ k ] !== undefined ? stagedEdits[ k ].newValue : row[ targetCol!.id ];
		} );
		const stats = computeNumericAggregation( values );
		const currency = typeof targetCol.extra?.currency === 'string' ? targetCol.extra.currency : '';
		const suffix = typeof targetCol.extra?.suffix === 'string' ? targetCol.extra.suffix : '';

		return {
			label: targetCol.label,
			scopeLabel,
			currency,
			suffix,
			stats,
		};
	}, [ flatRows, columns, selectedRange, focusedCell, stagedEdits ] );

	const fmtAgg = ( n: number, currency: string, suffix: string ) => {
		if ( currency ) {
			return `${ currency }${ n.toLocaleString( undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 } ) }`;
		}
		if ( suffix ) {
			return `${ n.toLocaleString() }${ suffix }`;
		}
		return n.toLocaleString();
	};

	return (
		<div className="rc-grid-wrapper">
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
							aria-label={ __( 'Select all visible rows', 'datadeck' ) }
							onChange={ () => onToggleSelectAll( allSelected ? [] : allIds ) }
						/>
					</div>

					{ columns.map( ( col, colIdx ) => {
						const w = columnWidths[ col.id ] || col.width || 150;
						const isSorted = sortField === col.id;
						const isStickyCol = colIdx === stickyColIndex;
						const canDrag = ! isStickyCol;
						return (
							<div
								key={ col.id }
								draggable={ canDrag }
								onDragStart={ ( e ) => {
									if ( ! canDrag ) {
										return;
									}
									setDragColId( col.id );
									e.dataTransfer.effectAllowed = 'move';
									e.dataTransfer.setData( 'text/plain', col.id );
								} }
								onDragOver={ ( e ) => {
									if ( ! dragColId || dragColId === col.id || isStickyCol ) {
										return;
									}
									e.preventDefault();
									setDropTargetColId( col.id );
								} }
								onDragLeave={ () => {
									if ( dropTargetColId === col.id ) {
										setDropTargetColId( null );
									}
								} }
								onDrop={ ( e ) => {
									e.preventDefault();
									if ( dragColId && dragColId !== col.id && ! isStickyCol ) {
										onReorderColumns( dragColId, col.id );
									}
									setDragColId( null );
									setDropTargetColId( null );
								} }
								onDragEnd={ () => {
									setDragColId( null );
									setDropTargetColId( null );
								} }
								className={ [
									'rc-th',
									col.sortable ? 'rc-th--sortable' : '',
									col.align === 'right' ? 'rc-th--right' : '',
									col.align === 'center' ? 'rc-th--center' : '',
									isStickyCol ? 'rc-th--sticky-primary' : '',
									dropTargetColId === col.id ? 'is-drop-target' : '',
								]
									.filter( Boolean )
									.join( ' ' ) }
								style={ { width: `${ w }px` } }
								role="columnheader"
								onClick={ () => col.sortable && onSort( col.id ) }
							>
								<span className="rc-th-label">{ col.label }</span>
								{ Boolean( col.extra?.computed ) && (
									<span className="rc-fx-tag" title={ String( col.extra?.formula || '' ) }>fx</span>
								) }
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
						<span>{ __( 'No matching records found.', 'datadeck' ) }</span>
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

										const isFillTarget =
											fillDrag !== null &&
											fillDrag.colIndex === colIdx &&
											vRow.index > fillDrag.seedEndRow &&
											vRow.index <= fillDrag.targetEndRow;

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
												isFillTarget={ isFillTarget }
												initialChar={ isEditing ? initialChar : undefined }
												isExpandedParent={ isExpandedParent }
												onStartFillDrag={ () => {
													const seedStart =
														selectedRange && selectedRange.colIndex === colIdx
															? selectedRange.startRow
															: vRow.index;
													const seedEnd =
														selectedRange && selectedRange.colIndex === colIdx
															? selectedRange.endRow
															: vRow.index;
													setFillDrag( {
														colIndex: colIdx,
														seedStartRow: seedStart,
														seedEndRow: seedEnd,
														targetEndRow: seedEnd,
													} );
												} }
												onFillDragEnter={ () => {
													if ( fillDrag && fillDrag.colIndex === colIdx && vRow.index >= fillDrag.seedEndRow ) {
														setFillDrag( ( prev ) =>
															prev ? { ...prev, targetEndRow: vRow.index } : null
														);
													}
												} }
												onContextMenuCell={ ( e, currentVal ) => {
													onOpenContextMenu( {
														x: e.clientX,
														y: e.clientY,
														record,
														field: col,
														value: currentVal,
													} );
												} }
												onFocus={ ( shiftKey ) => {
													if ( editingCell && ! isEditing ) {
														onEditCell( null );
													}
													if ( shiftKey && focusedCell && focusedCell.colIndex === colIdx ) {
														const anchor =
															rangeAnchorRef.current && rangeAnchorRef.current.colIndex === colIdx
																? rangeAnchorRef.current.rowIndex
																: focusedCell.rowIndex;
														rangeAnchorRef.current = { rowIndex: anchor, colIndex: colIdx };
														onSelectRange( {
															startRow: Math.min( anchor, vRow.index ),
															endRow: Math.max( anchor, vRow.index ),
															colIndex: colIdx,
															fieldId: col.id,
														} );
													} else {
														rangeAnchorRef.current = { rowIndex: vRow.index, colIndex: colIdx };
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

		{ aggSummary && aggSummary.stats.numericCount > 0 && (
			<div className="rc-agg-bar" aria-label={ __( 'Live column summary', 'datadeck' ) }>
				<span className="rc-agg-scope">
					<strong>{ aggSummary.label }</strong> · { aggSummary.scopeLabel }
				</span>
				<div className="rc-agg-metrics">
					<span>
						<em>SUM</em> { fmtAgg( aggSummary.stats.sum, aggSummary.currency, aggSummary.suffix ) }
					</span>
					<span>
						<em>AVG</em> { fmtAgg( aggSummary.stats.avg, aggSummary.currency, aggSummary.suffix ) }
					</span>
					<span>
						<em>MIN</em> { fmtAgg( aggSummary.stats.min, aggSummary.currency, aggSummary.suffix ) }
					</span>
					<span>
						<em>MAX</em> { fmtAgg( aggSummary.stats.max, aggSummary.currency, aggSummary.suffix ) }
					</span>
					<span>
						<em>COUNT</em> { aggSummary.stats.numericCount }/{ aggSummary.stats.count }
					</span>
				</div>
			</div>
		) }
		</div>
	);
}
