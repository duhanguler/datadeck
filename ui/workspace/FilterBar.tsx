import { __ } from '@wordpress/i18n';
import type { FieldDefinition, FilterClause } from '../types';

interface FilterBarProps {
	fields: FieldDefinition[];
	filters: FilterClause[];
	relation: 'AND' | 'OR';
	onChange: ( filters: FilterClause[], relation: 'AND' | 'OR' ) => void;
}

const OPERATORS: Record<string, Array<{ value: string; label: string; unary?: boolean }>> = {
	number: [
		{ value: 'is', label: '=' },
		{ value: 'isnot', label: '!=' },
		{ value: 'gt', label: '>' },
		{ value: 'gte', label: '>=' },
		{ value: 'lt', label: '<' },
		{ value: 'lte', label: '<=' },
		{ value: 'between', label: 'between' },
		{ value: 'isempty', label: 'is empty', unary: true },
		{ value: 'isnotempty', label: 'is not empty', unary: true },
	],
	select: [
		{ value: 'is', label: 'is' },
		{ value: 'isnot', label: 'is not' },
		{ value: 'isempty', label: 'is empty', unary: true },
		{ value: 'isnotempty', label: 'is not empty', unary: true },
	],
	text: [
		{ value: 'contains', label: 'contains' },
		{ value: 'notcontains', label: 'does not contain' },
		{ value: 'is', label: 'equals' },
		{ value: 'isnot', label: 'not equals' },
		{ value: 'isempty', label: 'is empty', unary: true },
		{ value: 'isnotempty', label: 'is not empty', unary: true },
	],
	date: [
		{ value: 'inlastdays', label: 'in last N days' },
		{ value: 'gte', label: 'after' },
		{ value: 'lte', label: 'before' },
		{ value: 'isempty', label: 'is empty', unary: true },
	],
};

function opsForField( field?: FieldDefinition ) {
	if ( ! field ) {
		return OPERATORS.text;
	}
	if ( [ 'number', 'currency', 'integer' ].includes( field.type ) ) {
		return OPERATORS.number;
	}
	if ( [ 'select', 'taxonomy', 'boolean' ].includes( field.type ) ) {
		return OPERATORS.select;
	}
	if ( [ 'date', 'datetime' ].includes( field.type ) ) {
		return OPERATORS.date;
	}
	return OPERATORS.text;
}

export function FilterBar( { fields, filters, relation, onChange }: FilterBarProps ) {
	const filterableFields = fields.filter( ( f ) => f.filterable );

	const addFilter = () => {
		const first = filterableFields[ 0 ];
		if ( ! first ) {
			return;
		}
		const ops = opsForField( first );
		onChange(
			[
				...filters,
				{
					id: `${ Date.now() }-${ Math.random().toString( 36 ).slice( 2, 6 ) }`,
					field: first.id,
					operator: ops[ 0 ].value,
					value: '',
				},
			],
			relation
		);
	};

	const updateClause = ( id: string, patch: Partial<FilterClause> ) => {
		onChange(
			filters.map( ( f ) => ( f.id === id ? { ...f, ...patch } : f ) ),
			relation
		);
	};

	const removeClause = ( id: string ) => {
		onChange(
			filters.filter( ( f ) => f.id !== id ),
			relation
		);
	};

	return (
		<div className="rc-filterbar">
			{ filters.length > 1 && (
				<select
					className="rc-entity-select"
					style={ { height: '26px', fontSize: '11.5px', paddingRight: '22px' } }
					value={ relation }
					onChange={ ( e ) => onChange( filters, e.target.value as 'AND' | 'OR' ) }
					aria-label={ __( 'Filter logic', 'rowcraft' ) }
				>
					<option value="AND">{ __( 'Match ALL (AND)', 'rowcraft' ) }</option>
					<option value="OR">{ __( 'Match ANY (OR)', 'rowcraft' ) }</option>
				</select>
			) }

			{ filters.map( ( clause ) => {
				const field = filterableFields.find( ( f ) => f.id === clause.field );
				const ops = opsForField( field );
				const activeOp = ops.find( ( o ) => o.value === clause.operator );
				const isUnary = Boolean( activeOp?.unary );

				return (
					<div key={ clause.id } className="rc-filter-pill">
						<select
							value={ clause.field }
							onChange={ ( e ) => {
								const nextField = filterableFields.find( ( f ) => f.id === e.target.value );
								const nextOps = opsForField( nextField );
								updateClause( clause.id, {
									field: e.target.value,
									operator: nextOps[ 0 ].value,
									value: '',
								} );
							} }
						>
							{ filterableFields.map( ( f ) => (
								<option key={ f.id } value={ f.id }>
									{ f.label }
								</option>
							) ) }
						</select>

						<select
							value={ clause.operator }
							onChange={ ( e ) => updateClause( clause.id, { operator: e.target.value } ) }
						>
							{ ops.map( ( op ) => (
								<option key={ op.value } value={ op.value }>
									{ op.label }
								</option>
							) ) }
						</select>

						{ ! isUnary && field && ( field.type === 'select' || field.type === 'taxonomy' ) && field.options.length > 0 ? (
							<select
								value={ clause.value }
								onChange={ ( e ) => updateClause( clause.id, { value: e.target.value } ) }
							>
								<option value="">{ __( 'Select…', 'rowcraft' ) }</option>
								{ field.options.map( ( o ) => (
									<option key={ o.value } value={ o.value }>
										{ o.label }
									</option>
								) ) }
							</select>
						) : ! isUnary ? (
							<>
								<input
									type="text"
									style={ { width: clause.operator === 'between' ? '72px' : '120px' } }
									placeholder={ clause.operator === 'inlastdays' ? '7' : __( 'Value…', 'rowcraft' ) }
									value={ clause.value }
									onChange={ ( e ) => updateClause( clause.id, { value: e.target.value } ) }
								/>
								{ clause.operator === 'between' && (
									<input
										type="text"
										style={ { width: '72px' } }
										placeholder={ __( 'Max…', 'rowcraft' ) }
										value={ clause.value2 || '' }
										onChange={ ( e ) => updateClause( clause.id, { value2: e.target.value } ) }
									/>
								) }
							</>
						) : null }

						<button
							type="button"
							className="rc-icon-btn"
							aria-label={ __( 'Remove filter', 'rowcraft' ) }
							onClick={ () => removeClause( clause.id ) }
						>
							×
						</button>
					</div>
				);
			} ) }

			<button type="button" className="rc-btn rc-btn--ghost" onClick={ addFilter }>
				+ { __( 'Add filter', 'rowcraft' ) }
			</button>

			{ filters.length > 0 && (
				<button
					type="button"
					className="rc-btn rc-btn--ghost"
					onClick={ () => onChange( [], 'AND' ) }
				>
					{ __( 'Clear all', 'rowcraft' ) }
				</button>
			) }
		</div>
	);
}
