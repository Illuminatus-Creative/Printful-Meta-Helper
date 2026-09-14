'use strict';
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );

const ROOT = path.resolve( __dirname, '..', '..' );

/**
 * Build a page, expose optional globals, then run a plugin script inside it.
 * The script runs after the DOM exists, matching in_footer enqueueing.
 */
async function page( html, scriptFile, globals = {} ) {
	const dom = new JSDOM( `<!doctype html><html><body>${ html }</body></html>`, {
		runScripts: 'outside-only',
		url: 'https://example.test/',
	} );
	// jsdom fires DOMContentLoaded asynchronously; wait so scripts that
	// gate on readyState boot before assertions run.
	if ( dom.window.document.readyState === 'loading' ) {
		await new Promise( ( resolve ) => dom.window.addEventListener( 'DOMContentLoaded', resolve ) );
	}
	Object.assign( dom.window, globals );
	dom.window.eval( fs.readFileSync( path.join( ROOT, scriptFile ), 'utf8' ) );
	return dom;
}

function click( el ) {
	el.dispatchEvent( new el.ownerDocument.defaultView.Event( 'click', { bubbles: true } ) );
}

function input( el, value ) {
	el.value = value;
	el.dispatchEvent( new el.ownerDocument.defaultView.Event( 'input', { bubbles: true } ) );
}

function change( el ) {
	el.dispatchEvent( new el.ownerDocument.defaultView.Event( 'change', { bubbles: true } ) );
}

function fixture( name ) {
	return fs.readFileSync( path.join( ROOT, 'tests', 'fixtures', name ), 'utf8' );
}

module.exports = { page, click, input, change, fixture };
