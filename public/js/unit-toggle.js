/* Printful Meta Helper: inches / centimetres switch.
   Swaps a class on the chart wrapper; CSS shows the matching values.
   The chosen unit is remembered per browser. */
( function () {
	'use strict';

	var STORAGE_KEY = 'pmh_unit';
	var UNITS = [ 'in', 'cm' ];

	function readSaved() {
		try {
			var v = window.localStorage.getItem( STORAGE_KEY );
			return UNITS.indexOf( v ) !== -1 ? v : null;
		} catch ( e ) {
			return null;
		}
	}

	function save( unit ) {
		try {
			window.localStorage.setItem( STORAGE_KEY, unit );
		} catch ( e ) {
			/* private mode or blocked storage: the switch still works for this page */
		}
	}

	function setUnit( chart, unit ) {
		if ( UNITS.indexOf( unit ) === -1 ) {
			return;
		}
		chart.classList.remove( 'pmh-chart--unit-in', 'pmh-chart--unit-cm' );
		chart.classList.add( 'pmh-chart--unit-' + unit );
		chart.setAttribute( 'data-pmh-unit', unit );

		var buttons = chart.querySelectorAll( '.pmh-chart__unit' );
		for ( var i = 0; i < buttons.length; i++ ) {
			buttons[ i ].setAttribute( 'aria-pressed', buttons[ i ].getAttribute( 'data-unit' ) === unit ? 'true' : 'false' );
		}
	}

	function applySaved( root ) {
		var saved = readSaved();
		if ( ! saved ) {
			return;
		}
		var charts = ( root || document ).querySelectorAll( '.pmh-chart:not(.pmh-chart--locked)' );
		for ( var i = 0; i < charts.length; i++ ) {
			setUnit( charts[ i ], saved );
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '.pmh-chart__unit' ) : null;
		if ( ! button ) {
			return;
		}
		var chart = button.closest( '.pmh-chart' );
		if ( ! chart ) {
			return;
		}
		var unit = button.getAttribute( 'data-unit' );
		setUnit( chart, unit );
		save( unit );

		// Keep every chart on the page in step.
		var others = document.querySelectorAll( '.pmh-chart:not(.pmh-chart--locked)' );
		for ( var i = 0; i < others.length; i++ ) {
			if ( others[ i ] !== chart ) {
				setUnit( others[ i ], unit );
			}
		}
	} );

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () { applySaved(); } );
	} else {
		applySaved();
	}

	// Charts injected later (tabs, modals, AJAX-loaded content).
	window.pmhApplyUnit = applySaved;
} )();
