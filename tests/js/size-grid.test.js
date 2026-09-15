'use strict';
const { test } = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const { page, click, input } = require( './helpers' );

const CHART = {
	sizes: [ 'S', 'M' ],
	note: 'May vary',
	rows: [
		{ label: 'Length', values: { S: [ 28 ], M: [ 29 ] } },
		{ label: 'Chest', values: { S: [ 34, 37 ], M: [ 38, 41 ] } },
	],
};

function markup( json ) {
	return `<div class="form-field"><textarea id="pmh_chart" name="pmh_chart" class="code pmh-chart-json">${ json }</textarea></div>`;
}

async function load( json = JSON.stringify( CHART ) ) {
	const dom = await page( markup( json ), 'admin/js/size-grid.js' );
	const doc = dom.window.document;
	return { dom, doc, ta: doc.getElementById( 'pmh_chart' ), grid: doc.querySelector( '.pmh-grid' ) };
}

const stored = ( ta ) => JSON.parse( ta.value );
const headers = ( grid ) => [ ...grid.querySelectorAll( '.pmh-grid__size-input' ) ].map( ( i ) => i.value );
const labels = ( grid ) => [ ...grid.querySelectorAll( '.pmh-grid__label' ) ].map( ( i ) => i.value );
const cells = ( grid ) => [ ...grid.querySelectorAll( '.pmh-grid__value' ) ].map( ( i ) => i.value );

test( 'renders the stored chart and hides the textarea', async () => {
	const { ta, grid } = await load();
	assert.ok( ta.hidden );
	assert.deepEqual( headers( grid ), [ 'S', 'M' ] );
	assert.deepEqual( labels( grid ), [ 'Length', 'Chest' ] );
	assert.deepEqual( cells( grid ), [ '28', '29', '34-37', '38-41' ], 'ranges render as text' );
	assert.equal( grid.querySelector( '.pmh-grid__note input' ).value, 'May vary' );
} );

test( 'editing a cell writes the textarea immediately', async () => {
	const { ta, grid } = await load();
	input( grid.querySelectorAll( '.pmh-grid__value' )[ 0 ], '28 ½' );
	assert.equal( stored( ta ).rows[ 0 ].values.S, '28 ½' );
	assert.equal( stored( ta ).rows[ 1 ].values.M, '38-41', 'untouched cells survive as text' );
} );

test( 'bad cells are outlined; empty cells are dropped from the JSON', async () => {
	const { ta, grid } = await load();
	const cell = grid.querySelectorAll( '.pmh-grid__value' )[ 0 ];
	input( cell, 'n/a' );
	assert.ok( cell.classList.contains( 'pmh-grid__value--bad' ) );
	input( cell, '' );
	assert.ok( ! cell.classList.contains( 'pmh-grid__value--bad' ) );
	assert.equal( stored( ta ).rows[ 0 ].values.S, undefined );
} );

test( 'add and remove a size', async () => {
	const { ta, grid, doc } = await load();
	click( grid.querySelector( '.pmh-grid__add-size' ) );
	const inputs = doc.querySelectorAll( '.pmh-grid__size-input' );
	assert.equal( inputs.length, 3 );
	assert.equal( doc.activeElement, inputs[ 2 ], 'new size input is focused' );
	input( inputs[ 2 ], 'L' );
	input( doc.querySelectorAll( '.pmh-grid__value' )[ 2 ], '30' );
	assert.deepEqual( stored( ta ).sizes, [ 'S', 'M', 'L' ] );
	assert.equal( stored( ta ).rows[ 0 ].values.L, '30' );

	click( doc.querySelectorAll( '.pmh-grid__size .pmh-grid__remove' )[ 0 ] );
	assert.deepEqual( stored( ta ).sizes, [ 'M', 'L' ] );
	assert.equal( stored( ta ).rows[ 0 ].values.S, undefined );
} );

test( 'renaming a size carries its values', async () => {
	const { ta, grid } = await load();
	input( grid.querySelectorAll( '.pmh-grid__size-input' )[ 1 ], 'XL' );
	assert.deepEqual( stored( ta ).sizes, [ 'S', 'XL' ] );
	assert.equal( stored( ta ).rows[ 0 ].values.XL, '29' );
	assert.equal( stored( ta ).rows[ 0 ].values.M, undefined );
} );

test( 'add and remove a measurement', async () => {
	const { ta, grid, doc } = await load();
	click( grid.querySelector( '.pmh-grid__add-row' ) );
	const labelInputs = doc.querySelectorAll( '.pmh-grid__label' );
	assert.equal( labelInputs.length, 3 );
	assert.equal( doc.activeElement, labelInputs[ 2 ] );
	input( labelInputs[ 2 ], 'Sleeve' );
	assert.equal( stored( ta ).rows.length, 2, 'a row with no values is not stored' );
	input( doc.querySelectorAll( 'tbody tr' )[ 2 ].querySelector( '.pmh-grid__value' ), '15 5/8' );
	assert.equal( stored( ta ).rows[ 2 ].label, 'Sleeve' );

	click( doc.querySelectorAll( '.pmh-grid__remove-cell .pmh-grid__remove' )[ 0 ] );
	assert.deepEqual( stored( ta ).rows.map( ( r ) => r.label ), [ 'Chest', 'Sleeve' ] );
} );

test( 'an emptied grid clears the textarea, which the server treats as "no chart"', async () => {
	const { ta, doc } = await load();
	click( doc.querySelectorAll( '.pmh-grid__remove-cell .pmh-grid__remove' )[ 0 ] );
	assert.ok( ! doc.querySelector( '.pmh-grid .description' ), 'one row left: no hint' );
	click( doc.querySelectorAll( '.pmh-grid__remove-cell .pmh-grid__remove' )[ 0 ] );
	assert.equal( ta.value, '' );
	assert.ok( doc.querySelector( '.pmh-grid .description' ), 'empty-state hint shown even though sizes remain' );
} );

test( 'JSON view round-trips edits and refuses to hide broken JSON', async () => {
	const { ta, doc } = await load();
	const toggle = doc.querySelector( '.pmh-grid__json-toggle' );
	click( toggle );
	assert.ok( ! ta.hidden );
	assert.ok( doc.querySelector( '.pmh-grid' ).hidden );

	ta.value = JSON.stringify( { sizes: [ 'L' ], note: '', rows: [ { label: 'Width', values: { L: [ 22 ] } } ] } );
	click( toggle );
	assert.ok( ta.hidden );
	assert.deepEqual( headers( doc.querySelector( '.pmh-grid' ) ), [ 'L' ] );
	assert.deepEqual( labels( doc.querySelector( '.pmh-grid' ) ), [ 'Width' ] );

	click( toggle );
	ta.value = '{broken';
	click( toggle );
	assert.ok( ! ta.hidden, 'stays in JSON view' );
	assert.ok( ! doc.querySelector( '.pmh-grid__error' ).hidden );
	assert.equal( ta.value, '{broken', 'text preserved for fixing' );
} );

test( 'unreadable stored JSON shows an error and leaves the textarea untouched', async () => {
	const { ta, doc } = await load( '{oops' );
	assert.ok( ! doc.querySelector( '.pmh-grid__error' ).hidden );
	assert.equal( ta.value, '{oops' );
} );

test( 'empty textarea renders an empty grid', async () => {
	const { grid } = await load( '' );
	assert.deepEqual( headers( grid ), [] );
	assert.ok( grid.querySelector( '.description' ) );
} );
