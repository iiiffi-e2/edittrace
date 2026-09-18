import * as esbuild from 'esbuild';
import { copyFileSync, mkdirSync } from 'node:fs';

const watch = process.argv.includes( '--watch' );
mkdirSync( 'assets/build', { recursive: true } );

const options = {
	entryPoints: [ 'assets/src/index.ts' ],
	bundle: true,
	minify: ! watch,
	sourcemap: watch ? 'inline' : false,
	format: 'iife',
	target: [ 'es2020', 'chrome90', 'firefox90', 'safari14' ],
	outfile: 'assets/build/edittrace.js',
	loader: { '.css': 'text' },
	legalComments: 'none',
	logLevel: 'info',
};

copyFileSync( 'assets/src/styles/adminbar.css', 'assets/build/edittrace-adminbar.css' );

if ( watch ) {
	const ctx = await esbuild.context( options );
	await ctx.watch();
} else {
	await esbuild.build( options );
}
