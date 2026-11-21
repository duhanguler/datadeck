import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';

const BUILD_DIR = path.resolve( process.cwd(), 'build' );
const INITIAL_JS_BUDGET_KB = 45;
const TOGGLE_JS_BUDGET_KB = 2;

function gzipSizeKb( filePath ) {
	const raw = fs.readFileSync( filePath );
	const gz = zlib.gzipSync( raw, { level: 9 } );
	return {
		rawKb: +( raw.length / 1024 ).toFixed( 2 ),
		gzipKb: +( gz.length / 1024 ).toFixed( 2 ),
	};
}

if ( ! fs.existsSync( BUILD_DIR ) ) {
	console.error( 'Build directory missing. Run `npm run build` first.' );
	process.exit( 1 );
}

const mainJs = path.join( BUILD_DIR, 'datadeck-app.js' );
const toggleJs = path.join( BUILD_DIR, 'datadeck-toggle.js' );
const mainCss = path.join( BUILD_DIR, 'datadeck-app.css' );

let failed = false;

const appStats = gzipSizeKb( mainJs );
console.log( `datadeck-app.js    : ${ appStats.rawKb } KB raw | ${ appStats.gzipKb } KB gzip (budget <= ${ INITIAL_JS_BUDGET_KB } KB)` );
if ( appStats.gzipKb > INITIAL_JS_BUDGET_KB ) {
	console.error( `FAIL: datadeck-app.js exceeds ${ INITIAL_JS_BUDGET_KB } KB gzip budget!` );
	failed = true;
}

const toggleStats = gzipSizeKb( toggleJs );
console.log( `datadeck-toggle.js : ${ toggleStats.rawKb } KB raw | ${ toggleStats.gzipKb } KB gzip (budget <= ${ TOGGLE_JS_BUDGET_KB } KB)` );
if ( toggleStats.gzipKb > TOGGLE_JS_BUDGET_KB ) {
	console.error( `FAIL: datadeck-toggle.js exceeds ${ TOGGLE_JS_BUDGET_KB } KB gzip budget!` );
	failed = true;
}

if ( fs.existsSync( mainCss ) ) {
	const cssStats = gzipSizeKb( mainCss );
	console.log( `datadeck-app.css   : ${ cssStats.rawKb } KB raw | ${ cssStats.gzipKb } KB gzip` );
}

const chunksDir = path.join( BUILD_DIR, 'chunks' );
if ( fs.existsSync( chunksDir ) ) {
	console.log( '\nLazy-loaded chunks (loaded on demand only):' );
	for ( const f of fs.readdirSync( chunksDir ).filter( ( x ) => x.endsWith( '.js' ) ) ) {
		const s = gzipSizeKb( path.join( chunksDir, f ) );
		console.log( `  chunks/${ f.padEnd( 32 ) } : ${ s.rawKb } KB raw | ${ s.gzipKb } KB gzip` );
	}
}

if ( failed ) {
	process.exit( 1 );
}

console.log( '\nBundle performance budgets PASSED.' );
