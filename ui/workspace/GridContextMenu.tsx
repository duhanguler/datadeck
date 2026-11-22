import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { FieldDefinition, RecordRow } from '../types';

export interface ContextMenuState {
	x: number;
	y: number;
	record: RecordRow;
	field: FieldDefinition;
	value: unknown;
}

interface GridContextMenuProps {
	menu: ContextMenuState;
	selectedCount: number;
	onFilterEquals: ( field: FieldDefinition, value: unknown ) => void;
	onFilterNotEquals: ( field: FieldDefinition, value: unknown ) => void;
	onCopyValue: ( value: unknown ) => void;
	onApplyToSelected: ( field: FieldDefinition, value: unknown ) => void;
	onInspectHistory: ( record: RecordRow, fieldId: string ) => void;
	onDuplicateRow: ( id: number ) => void;
	onClose: () => void;
}

function serializeFilterValue( value: unknown ): string {
	if ( value === null || value === undefined ) {
		return '';
	}
	if ( Array.isArray( value ) ) {
		const first = value[ 0 ] as { id?: number; label?: string } | undefined;
		return first?.id ? String( first.id ) : String( first ?? '' );
	}
	if ( typeof value === 'object' && 'id' in ( value as Record<string, unknown> ) ) {
		return String( ( value as { id: unknown } ).id );
	}
	return String( value );
}

export function GridContextMenu( {
	menu,
	selectedCount,
	onFilterEquals,
	onFilterNotEquals,
	onCopyValue,
	onApplyToSelected,
	onInspectHistory,
	onDuplicateRow,
	onClose,
}: GridContextMenuProps ) {
	useEffect( () => {
		const onDismiss = () => onClose();
		const onKey = ( e: KeyboardEvent ) => {
			if ( e.key === 'Escape' ) {
				onClose();
			}
		};
		window.addEventListener( 'click', onDismiss );
		window.addEventListener( 'keydown', onKey );
		return () => {
			window.removeEventListener( 'click', onDismiss );
			window.removeEventListener( 'keydown', onKey );
		};
	}, [ onClose ] );

	const clampedLeft = Math.min( menu.x, Math.max( 8, window.innerWidth - 230 ) );
	const clampedTop = Math.min( menu.y, Math.max( 8, window.innerHeight - 250 ) );
	const strVal = serializeFilterValue( menu.value );
	const canFilter = menu.field.filterable && strVal !== '';
	const canApplyBulk = selectedCount > 1 && menu.field.editable;

	return (
		<div
			className="rc-context-menu"
			role="menu"
			style={ { top: `${ clampedTop }px`, left: `${ clampedLeft }px` } }
			onClick={ ( e ) => e.stopPropagation() }
		>
			<div className="rc-context-menu-head">
				{ `${ menu.field.label } · #${ menu.record.id }` }
			</div>

			<button
				type="button"
				role="menuitem"
				className="rc-context-item"
				onClick={ () => {
					onCopyValue( menu.value );
					onClose();
				} }
			>
				{ __( 'Copy cell value', 'datadeck' ) }
			</button>

			{ canFilter && (
				<>
					<button
						type="button"
						role="menuitem"
						className="rc-context-item"
						onClick={ () => {
							onFilterEquals( menu.field, strVal );
							onClose();
						} }
					>
						{ __( 'Filter by this value (=)', 'datadeck' ) }
					</button>
					<button
						type="button"
						role="menuitem"
						className="rc-context-item"
						onClick={ () => {
							onFilterNotEquals( menu.field, strVal );
							onClose();
						} }
					>
						{ __( 'Exclude this value (!=)', 'datadeck' ) }
					</button>
				</>
			) }

			{ canApplyBulk && (
				<button
					type="button"
					role="menuitem"
					className="rc-context-item"
					onClick={ () => {
						onApplyToSelected( menu.field, menu.value );
						onClose();
					} }
				>
					{ `${ __( 'Apply to', 'datadeck' ) } ${ selectedCount } ${ __( 'selected rows', 'datadeck' ) }` }
				</button>
			) }

			<div className="rc-context-sep" />

			<button
				type="button"
				role="menuitem"
				className="rc-context-item"
				onClick={ () => {
					onInspectHistory( menu.record, menu.field.id );
					onClose();
				} }
			>
				{ __( 'View cell audit history', 'datadeck' ) }
			</button>

			{ ! menu.record._isVariation && (
				<button
					type="button"
					role="menuitem"
					className="rc-context-item"
					onClick={ () => {
						onDuplicateRow( menu.record.id );
						onClose();
					} }
				>
					{ __( 'Duplicate row', 'datadeck' ) }
				</button>
			) }

			{ menu.record._editUrl && (
				<a
					role="menuitem"
					className="rc-context-item"
					href={ menu.record._editUrl }
					target="_blank"
					rel="noreferrer"
					onClick={ onClose }
				>
					{ __( 'Open in Classic Editor', 'datadeck' ) }
				</a>
			) }
		</div>
	);
}
