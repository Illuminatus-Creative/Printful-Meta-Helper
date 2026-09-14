'use strict';
const { test } = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const { page, click } = require( './helpers' );

function chart( id, extra = '' ) {
	return `<div id="${ id }" class="pmh-chart pmh-chart--x pmh-chart--unit-in ${ extra }" data-pmh-unit="in">
	<div class="pmh-chart__toggle">
		<button type="button" class="pmh-chart__unit" data-unit="in" aria-pressed="true">Inches</button>
		<button type="button" class="pmh-chart__unit" data-unit="cm" aria-pressed="false">Centimeters</button>
	</div></div>`;
}

test( 'clicking cm swaps the wrapper class, aria state and data attribute', async () => {
	const dom = await page( chart( 'a' ), 'public/js/unit-toggle.js' );
	const doc = dom.window.document;
	click( doc.querySelector( '#a [data-unit="cm"]' ) );

	const a = doc.getElementById( 'a' );
	assert.ok( a.classList.contains( 'pmh-chart--unit-cm' ) );
	assert.ok( ! a.classList.contains( 'pmh-chart--unit-in' ) );
	assert.equal( a.getAttribute( 'data-pmh-unit' ), 'cm' );
	assert.equal( doc.querySelector( '#a [data-unit="cm"]' ).getAttribute( 'aria-pressed' ), 'true' );
	assert.equal( doc.querySelector( '#a [data-unit="in"]' ).getAttribute( 'aria-pressed' ), 'false' );
} );

test( 'every chart on the page follows, except locked ones', async () => {
	const dom = await page( chart( 'a' ) + chart( 'b' ) + chart( 'c', 'pmh-chart--locked' ), 'public/js/unit-toggle.js' );
	const doc = dom.window.document;
	click( doc.querySelector( '#a [data-unit="cm"]' ) );
	assert.ok( doc.getElementById( 'b' ).classList.contains( 'pmh-chart--unit-cm' ) );
	assert.ok( doc.getElementById( 'c' ).classList.contains( 'pmh-chart--unit-in' ), 'locked chart untouched' );
} );

test( 'choice is remembered and applied on the next page', async () => {
	const first = await page( chart( 'a' ), 'public/js/unit-toggle.js' );
	click( first.window.document.querySelector( '#a [data-unit="cm"]' ) );
	assert.equal( first.window.localStorage.getItem( 'pmh_unit' ), 'cm' );

	// A fresh document on the same origin shares localStorage in jsdom only
	// when handed the same storage; emulate by seeding it.
	const second = await page( chart( 'a' ), 'public/js/unit-toggle.js' );
	second.window.localStorage.setItem( 'pmh_unit', 'cm' );
	second.window.pmhApplyUnit();
	assert.ok( second.window.document.getElementById( 'a' ).classList.contains( 'pmh-chart--unit-cm' ) );
} );

test( 'a nonsense unit is ignored', async () => {
	const dom = await page( chart( 'a' ).replace( 'data-unit="cm"', 'data-unit="furlongs"' ), 'public/js/unit-toggle.js' );
	const doc = dom.window.document;
	click( doc.querySelector( '#a [data-unit="furlongs"]' ) );
	assert.ok( doc.getElementById( 'a' ).classList.contains( 'pmh-chart--unit-in' ) );
} );
