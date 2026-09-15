'use strict';
const { test } = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const { page, change } = require( './helpers' );

const SIG = 'abc123';

function markup( { selected = '', showAllChecked = false, cats = [] } = {} ) {
	const checked = ( id ) => ( cats.includes( id ) ? ' checked' : '' );
	return `
	<div id="product_catdiv"><ul id="product_catchecklist">
		<li><input type="checkbox" name="tax_input[product_cat][]" value="5"${ checked( 5 ) }></li>
		<li><input type="checkbox" name="tax_input[product_cat][]" value="6"${ checked( 6 ) }></li>
	</ul></div>
	<div id="pmh-assign">
		<select id="pmh_blank_select">
			<option value="">— No blank —</option>
			<option value="1" data-cats="5" data-kind="apparel"${ selected === '1' ? ' selected' : '' }>Tee blank</option>
			<option value="2" data-cats="6" data-kind="apparel"${ selected === '2' ? ' selected' : '' }>Hoodie blank</option>
			<option value="3" data-cats="" data-kind="accessory"${ selected === '3' ? ' selected' : '' }>Mug</option>
			<option value="4" data-cats="5" data-kind="apparel"${ selected === '4' ? ' selected' : '' }>Tee blank, no chart</option>
		</select>
		<input type="checkbox" id="pmh_show_all"${ showAllChecked ? ' checked' : '' }>
		<div id="pmh_blank_preview"></div>
	</div>`;
}

function config( product = {} ) {
	return {
		pmhAssign: {
			blanks: {
				1: { name: 'Tee blank', kind: 'apparel', cats: [ 5 ], material: '100% cotton', weight: '5 oz', sizes: [ 'S', 'M', 'L', 'XL', '2XL', '3XL' ], signature: SIG },
				2: { name: 'Hoodie blank', kind: 'apparel', cats: [ 6 ], material: '', weight: '', sizes: [ 'S', 'M' ], signature: 'other' },
				3: { name: 'Mug', kind: 'accessory', cats: [], material: 'Ceramic', weight: '', sizes: [], signature: '' },
				4: { name: 'Tee blank, no chart', kind: 'apparel', cats: [ 5 ], material: '', weight: '', sizes: [], signature: '' },
			},
			product: Object.assign( { sizesState: 'sizes', sizes: [ 'S', 'M', 'L', 'XL', '2XL' ], hasPrintfulChart: false, signature: '', printfulSizes: [] }, product ),
			i18n: {
				noBlank: 'NOBLANK', notApparel: 'NOTAPPAREL', noChart: 'NOCHART', sizesAll: 'ALL:', sizesFiltered: 'FILTERED:',
				sizesNone: 'NONE', sizesNoOverlap: 'NOOVERLAP:', notForCats: 'NOTFORCATS', match: 'MATCH', mismatch: 'MISMATCH',
				suggest: 'SUGGEST:', suggestNone: 'SUGGESTNONE', material: 'Material', weight: 'Weight',
			},
		},
	};
}

const visible = ( doc ) => [ ...doc.querySelectorAll( '#pmh_blank_select option' ) ].filter( ( o ) => o.value && ! o.hidden ).map( ( o ) => o.value );
const previewText = ( doc ) => doc.getElementById( 'pmh_blank_preview' ).textContent;

test( 'options are filtered by ticked categories; unrestricted blanks always show', async () => {
	const dom = await page( markup( { cats: [ 5 ] } ), 'admin/js/blank-filter.js', config() );
	assert.deepEqual( visible( dom.window.document ), [ '1', '3', '4' ] );
} );

test( 'ticking a category re-filters without a request', async () => {
	const dom = await page( markup( { cats: [ 5 ] } ), 'admin/js/blank-filter.js', config() );
	const doc = dom.window.document;
	const hoodies = doc.querySelector( '#product_catchecklist input[value="6"]' );
	hoodies.checked = true;
	change( hoodies );
	assert.deepEqual( visible( doc ), [ '1', '2', '3', '4' ] );
} );

test( 'show all bypasses the filter', async () => {
	const dom = await page( markup( { cats: [] } ), 'admin/js/blank-filter.js', config() );
	const doc = dom.window.document;
	assert.deepEqual( visible( doc ), [ '3' ] );
	const all = doc.getElementById( 'pmh_show_all' );
	all.checked = true;
	change( all );
	assert.deepEqual( visible( doc ), [ '1', '2', '3', '4' ] );
} );

test( 'a saved blank outside the filter stays selected, turns show-all on and is flagged', async () => {
	const dom = await page( markup( { selected: '2', cats: [ 5 ] } ), 'admin/js/blank-filter.js', config() );
	const doc = dom.window.document;
	assert.equal( doc.getElementById( 'pmh_blank_select' ).value, '2' );
	assert.ok( doc.getElementById( 'pmh_show_all' ).checked );
	assert.match( previewText( doc ), /NOTFORCATS/ );
} );

test( 'preview: filtered sizes from saved variations', async () => {
	const dom = await page( markup( { selected: '1', cats: [ 5 ] } ), 'admin/js/blank-filter.js', config() );
	const text = previewText( dom.window.document );
	assert.match( text, /Tee blank/ );
	assert.match( text, /Material100% cotton/ );
	assert.match( text, /FILTERED: S, M, L, XL, 2XL/ );
	assert.doesNotMatch( text, /3XL/ );
} );

test( 'preview: no size attribute shows the full chart', async () => {
	const dom = await page( markup( { selected: '1', cats: [ 5 ] } ), 'admin/js/blank-filter.js', config( { sizesState: 'unfiltered', sizes: [] } ) );
	assert.match( previewText( dom.window.document ), /ALL: S, M, L, XL, 2XL, 3XL/ );
} );

test( 'preview: no overlap and no variations warn', async () => {
	const none = await page( markup( { selected: '1', cats: [ 5 ] } ), 'admin/js/blank-filter.js', config( { sizesState: 'none', sizes: [] } ) );
	assert.match( previewText( none.window.document ), /NONE/ );
	const mismatch = await page( markup( { selected: '1', cats: [ 5 ] } ), 'admin/js/blank-filter.js', config( { sizes: [ '10', '12' ] } ) );
	assert.match( previewText( mismatch.window.document ), /NOOVERLAP: 10, 12/ );
} );

test( 'preview: accessory and chartless blanks', async () => {
	const mug = await page( markup( { selected: '3', cats: [ 5 ] } ), 'admin/js/blank-filter.js', config() );
	assert.match( previewText( mug.window.document ), /NOTAPPAREL/ );
	const bare = await page( markup( { selected: '4', cats: [ 5 ] } ), 'admin/js/blank-filter.js', config() );
	assert.match( previewText( bare.window.document ), /NOCHART/ );
} );

test( 'preview: Printful chart match, mismatch with suggestion, and suggestion when nothing is chosen', async () => {
	const cfg = { hasPrintfulChart: true, signature: SIG };
	const match = await page( markup( { selected: '1', cats: [ 5 ] } ), 'admin/js/blank-filter.js', config( cfg ) );
	assert.match( previewText( match.window.document ), /MATCH/ );
	assert.doesNotMatch( previewText( match.window.document ), /MISMATCH/ );

	const mismatch = await page( markup( { selected: '2', cats: [ 6 ] } ), 'admin/js/blank-filter.js', config( cfg ) );
	assert.match( previewText( mismatch.window.document ), /MISMATCH/ );
	assert.match( previewText( mismatch.window.document ), /SUGGEST: Tee blank/ );

	const none = await page( markup( { cats: [ 5 ] } ), 'admin/js/blank-filter.js', config( cfg ) );
	assert.match( previewText( none.window.document ), /NOBLANK/ );
	assert.match( previewText( none.window.document ), /SUGGEST: Tee blank/ );
} );

test( 'changing the selection re-renders the preview', async () => {
	const dom = await page( markup( { selected: '1', cats: [ 5, 6 ] } ), 'admin/js/blank-filter.js', config() );
	const doc = dom.window.document;
	const select = doc.getElementById( 'pmh_blank_select' );
	select.value = '2';
	change( select );
	assert.match( previewText( doc ), /Hoodie blank/ );
	assert.match( previewText( doc ), /FILTERED: S, M/ );
} );

test( 'missing config or markup does nothing', async () => {
	await assert.doesNotReject( () => page( '<p>nothing</p>', 'admin/js/blank-filter.js' ) );
} );
