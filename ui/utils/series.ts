/**
 * Pure spreadsheet utilities: Smart Fill-Handle series inference, numeric aggregations,
 * and RFC 4180 client-side CSV parsing.
 */

export interface AggregationSummary {
	count: number;
	numericCount: number;
	emptyCount: number;
	sum: number;
	avg: number;
	min: number;
	max: number;
}

/**
 * Generate fill values for drag-to-fill handle operations.
 *
 * - Numeric arithmetic progression when 2+ numeric seeds are provided (e.g. 10, 20 -> 30, 40)
 * - Trailing integer pattern auto-increment for single string seeds (e.g. "SKU-001" -> "SKU-002", "SKU-003")
 * - Clean repetition for plain strings, prices, booleans, or selects
 */
export function generateFillSeries(
	seeds: unknown[],
	count: number,
	fieldType: string
): unknown[] {
	if ( count <= 0 || seeds.length === 0 ) {
		return [];
	}

	const isNumericType = [ 'number', 'currency', 'integer' ].includes( fieldType );

	// 1. Multi-seed numeric progression: [10, 20] -> step = 10 -> 30, 40, 50
	if ( isNumericType && seeds.length >= 2 ) {
		const nums = seeds.map( Number );
		if ( nums.every( ( n ) => ! Number.isNaN( n ) ) ) {
			const step = nums[ nums.length - 1 ] - nums[ nums.length - 2 ];
			const start = nums[ nums.length - 1 ];
			const out: number[] = [];
			for ( let i = 1; i <= count; i++ ) {
				const raw = start + step * i;
				out.push( fieldType === 'integer' ? Math.round( raw ) : Math.round( raw * 100 ) / 100 );
			}
			return out;
		}
	}

	// 2. Single string seed with trailing numeric token (e.g. "SKU-001", "Item 10")
	if ( seeds.length === 1 && typeof seeds[ 0 ] === 'string' && ! isNumericType && fieldType !== 'select' ) {
		const match = /^(.*?)(\d+)$/.exec( seeds[ 0 ] );
		if ( match ) {
			const prefix = match[ 1 ];
			const digits = match[ 2 ];
			const padLen = digits.length;
			const baseNum = parseInt( digits, 10 );
			const out: string[] = [];
			for ( let i = 1; i <= count; i++ ) {
				const next = String( baseNum + i ).padStart( padLen, '0' );
				out.push( `${ prefix }${ next }` );
			}
			return out;
		}
	}

	// 3. Default: cycle/repeat seed values cleanly.
	const out: unknown[] = [];
	for ( let i = 0; i < count; i++ ) {
		out.push( seeds[ i % seeds.length ] );
	}
	return out;
}

/**
 * Parse a locale-formatted scalar or currency string (e.g. "$1,250.00", "49,90", "1.250,99")
 * into a finite number, returning null for plain non-numeric text like "Coffee" or "SKU-001".
 */
export function parseLocaleNumber( value: unknown ): number | null {
	if ( typeof value === 'number' ) {
		return Number.isFinite( value ) ? value : null;
	}
	if ( value === null || value === undefined || typeof value === 'boolean' || typeof value === 'object' ) {
		return null;
	}
	const raw = String( value ).trim();
	if ( raw === '' ) {
		return null;
	}
	// Strip common currency symbols, percent signs, and whitespace only; reject alphabetic text like "SKU-001".
	const stripped = raw.replace( /^[\s$€£¥₺₹₩₽]+|[\s$€£¥₺₹₩₽%]+$/g, '' ).replace( /\s+/g, '' );
	if ( ! /^-?\d[\d.,]*$/.test( stripped ) ) {
		return null;
	}

	const commaCount = ( stripped.match( /,/g ) || [] ).length;
	const dotCount = ( stripped.match( /\./g ) || [] ).length;
	let normalized = stripped;

	if ( commaCount > 0 && dotCount > 0 ) {
		// Whichever separator appears last is the decimal mark (e.g. 1,250.99 vs 1.250,99).
		if ( stripped.lastIndexOf( ',' ) > stripped.lastIndexOf( '.' ) ) {
			normalized = stripped.replace( /\./g, '' ).replace( ',', '.' );
		} else {
			normalized = stripped.replace( /,/g, '' );
		}
	} else if ( commaCount === 1 ) {
		// Single comma: decimal if 1-2 digits after comma (e.g. 49,90), thousands if 3 digits (e.g. 1,250).
		const parts = stripped.split( ',' );
		normalized = parts[ 1 ].length === 3 ? parts.join( '' ) : parts.join( '.' );
	} else if ( commaCount > 1 ) {
		normalized = stripped.replace( /,/g, '' );
	} else if ( dotCount > 1 ) {
		normalized = stripped.replace( /\./g, '' );
	}

	const n = Number( normalized );
	return Number.isFinite( n ) ? n : null;
}

/**
 * Compute real-time SUM, AVG, MIN, MAX, COUNT over a slice of cell values (zero extra SQL).
 */
export function computeNumericAggregation( values: unknown[] ): AggregationSummary {
	let numericCount = 0;
	let emptyCount = 0;
	let sum = 0;
	let min = Infinity;
	let max = -Infinity;

	for ( const v of values ) {
		if ( v === null || v === undefined || v === '' ) {
			emptyCount++;
			continue;
		}
		const n = parseLocaleNumber( v );
		if ( n !== null ) {
			numericCount++;
			sum += n;
			if ( n < min ) {
				min = n;
			}
			if ( n > max ) {
				max = n;
			}
		}
	}

	return {
		count: values.length,
		numericCount,
		emptyCount,
		sum: Math.round( sum * 100 ) / 100,
		avg: numericCount > 0 ? Math.round( ( sum / numericCount ) * 100 ) / 100 : 0,
		min: numericCount > 0 ? min : 0,
		max: numericCount > 0 ? max : 0,
	};
}

/**
 * RFC 4180 client-side CSV parser with automatic delimiter detection (comma, semicolon, tab)
 * and UTF-8 BOM stripping.
 */
export function parseCsvClient( rawText: string ): {
	headers: string[];
	rows: Array<Record<string, string>>;
	delimiter: string;
} {
	const text = rawText.replace( /^\uFEFF/, '' ).trim();
	if ( ! text ) {
		return { headers: [], rows: [], delimiter: ',' };
	}

	const firstLine = text.split( /\r?\n/, 1 )[ 0 ] || '';
	const counts: Array<[ string, number ]> = [
		[ ',', ( firstLine.match( /,/g ) || [] ).length ],
		[ ';', ( firstLine.match( /;/g ) || [] ).length ],
		[ '\t', ( firstLine.match( /\t/g ) || [] ).length ],
	];
	counts.sort( ( a, b ) => b[ 1 ] - a[ 1 ] );
	const delimiter = counts[ 0 ][ 1 ] > 0 ? counts[ 0 ][ 0 ] : ',';

	const matrix: string[][] = [];
	let row: string[] = [];
	let field = '';
	let inQuotes = false;

	for ( let i = 0; i < text.length; i++ ) {
		const ch = text[ i ];
		if ( inQuotes ) {
			if ( ch === '"' ) {
				if ( text[ i + 1 ] === '"' ) {
					field += '"';
					i++;
				} else {
					inQuotes = false;
				}
			} else {
				field += ch;
			}
		} else if ( ch === '"' ) {
			inQuotes = true;
		} else if ( ch === delimiter ) {
			row.push( field );
			field = '';
		} else if ( ch === '\r' ) {
			if ( text[ i + 1 ] === '\n' ) {
				i++;
			}
			row.push( field );
			matrix.push( row );
			row = [];
			field = '';
		} else if ( ch === '\n' ) {
			row.push( field );
			matrix.push( row );
			row = [];
			field = '';
		} else {
			field += ch;
		}
	}

	if ( field !== '' || row.length > 0 ) {
		row.push( field );
		matrix.push( row );
	}

	if ( matrix.length === 0 ) {
		return { headers: [], rows: [], delimiter };
	}

	const headers = matrix[ 0 ].map( ( h, idx ) => h.trim() || `column_${ idx + 1 }` );
	const rows: Array<Record<string, string>> = [];

	for ( let r = 1; r < matrix.length; r++ ) {
		const line = matrix[ r ];
		if ( ! line.some( ( c ) => c.trim() !== '' ) ) {
			continue;
		}
		const obj: Record<string, string> = {};
		for ( let c = 0; c < headers.length; c++ ) {
			obj[ headers[ c ] ] = line[ c ] ?? '';
		}
		rows.push( obj );
	}

	return { headers, rows, delimiter };
}
