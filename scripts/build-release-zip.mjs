import fs from 'node:fs';
import path from 'node:path';
import { execSync } from 'node:child_process';

const ROOT = process.cwd();
const DIST_ROOT = path.join( ROOT, 'dist' );
const STAGE_DIR = path.join( DIST_ROOT, 'rowcraft' );
const ZIP_PATH = path.join( DIST_ROOT, 'rowcraft.zip' );

const INCLUDE = [
	'rowcraft.php',
	'uninstall.php',
	'readme.txt',
	'src',
	'build',
	'languages',
];

fs.rmSync( DIST_ROOT, { recursive: true, force: true } );
fs.mkdirSync( STAGE_DIR, { recursive: true } );

for ( const rel of INCLUDE ) {
	const src = path.join( ROOT, rel );
	if ( ! fs.existsSync( src ) ) {
		continue;
	}
	const dst = path.join( STAGE_DIR, rel );
	fs.cpSync( src, dst, { recursive: true } );
}

execSync( `cd "${ DIST_ROOT }" && zip -rq "${ ZIP_PATH }" rowcraft` );
const sizeKb = ( fs.statSync( ZIP_PATH ).size / 1024 ).toFixed( 1 );
console.log( `Built clean WordPress.org release archive: dist/rowcraft.zip (${ sizeKb } KB)` );
