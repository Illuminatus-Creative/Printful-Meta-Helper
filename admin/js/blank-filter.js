/* Product edit screen: filter the blank dropdown by the ticked product
   categories and preview the chosen blank. Data comes from window.pmhAssign,
   printed inline at page load; nothing is fetched. */
( function () {
	'use strict';

	var cfg = window.pmhAssign;
	var select = document.getElementById( 'pmh_blank_select' );
	var showAll = document.getElementById( 'pmh_show_all' );
	var preview = document.getElementById( 'pmh_blank_preview' );
	var catBox = document.getElementById( 'product_catdiv' );

	if ( ! cfg || ! select || ! preview ) {
		return;
	}

	function checkedCategoryIds() {
		var ids = [];
		if ( ! catBox ) {
			return ids;
		}
		var inputs = catBox.querySelectorAll( '#product_catchecklist input[type="checkbox"]:checked' );
		for ( var i = 0; i < inputs.length; i++ ) {
			var id = parseInt( inputs[ i ].value, 10 );
			if ( id && ids.indexOf( id ) === -1 ) {
				ids.push( id );
			}
		}
		return ids;
	}

	function optionCats( option ) {
		var raw = option.getAttribute( 'data-cats' ) || '';
		return raw ? raw.split( ',' ).map( function ( s ) { return parseInt( s, 10 ); } ) : [];
	}

	function appliesTo( option, catIds ) {
		var cats = optionCats( option );
		if ( ! cats.length ) {
			return true; // no restriction
		}
		for ( var i = 0; i < cats.length; i++ ) {
			if ( catIds.indexOf( cats[ i ] ) !== -1 ) {
				return true;
			}
		}
		return false;
	}

	/* Hide options the filter excludes. The selected option is never hidden:
	   silently blanking it would drop an assignment because someone toggled a
	   category; it is flagged in the preview instead. */
	function applyFilter() {
		var catIds = checkedCategoryIds();
		var all = showAll && showAll.checked;
		var options = select.options;
		for ( var i = 0; i < options.length; i++ ) {
			var opt = options[ i ];
			if ( ! opt.value ) {
				continue;
			}
			var visible = all || opt.selected || appliesTo( opt, catIds );
			opt.hidden = ! visible;
			opt.disabled = ! visible;
		}
		renderPreview();
	}

	function el( tag, cls, text ) {
		var node = document.createElement( tag );
		if ( cls ) {
			node.className = cls;
		}
		if ( text !== undefined ) {
			node.textContent = text;
		}
		return node;
	}

	function intersect( chartSizes, productSizes ) {
		return chartSizes.filter( function ( s ) { return productSizes.indexOf( s ) !== -1; } );
	}

	function matchingBlankNames() {
		var names = [];
		Object.keys( cfg.blanks ).forEach( function ( id ) {
			var b = cfg.blanks[ id ];
			if ( b.signature && b.signature === cfg.product.signature ) {
				names.push( b.name );
			}
		} );
		return names;
	}

	function renderPreview() {
		var t = cfg.i18n;
		var id = select.value;
		preview.innerHTML = '';

		if ( ! id || ! cfg.blanks[ id ] ) {
			preview.appendChild( el( 'p', 'pmh-assign__hint', t.noBlank ) );
			if ( cfg.product.hasPrintfulChart ) {
				var names = matchingBlankNames();
				preview.appendChild( el( 'p', 'pmh-assign__hint', names.length ? t.suggest + ' ' + names.join( ', ' ) : t.suggestNone ) );
			}
			return;
		}

		var b = cfg.blanks[ id ];
		var opt = select.options[ select.selectedIndex ];

		var head = el( 'p', 'pmh-assign__name', b.name );
		preview.appendChild( head );

		if ( b.material || b.weight ) {
			var dl = el( 'dl', 'pmh-assign__facts' );
			if ( b.material ) {
				dl.appendChild( el( 'dt', null, t.material ) );
				dl.appendChild( el( 'dd', null, b.material ) );
			}
			if ( b.weight ) {
				dl.appendChild( el( 'dt', null, t.weight ) );
				dl.appendChild( el( 'dd', null, b.weight ) );
			}
			preview.appendChild( dl );
		}

		if ( ! appliesTo( opt, checkedCategoryIds() ) ) {
			preview.appendChild( el( 'p', 'pmh-assign__warn', t.notForCats ) );
		}

		if ( b.kind !== 'apparel' ) {
			preview.appendChild( el( 'p', 'pmh-assign__hint', t.notApparel ) );
		} else if ( ! b.sizes.length ) {
			preview.appendChild( el( 'p', 'pmh-assign__warn', t.noChart ) );
		} else {
			var state = cfg.product.sizesState;
			if ( state === 'unfiltered' ) {
				preview.appendChild( el( 'p', 'pmh-assign__ok', t.sizesAll + ' ' + b.sizes.join( ', ' ) ) );
			} else if ( state === 'none' ) {
				preview.appendChild( el( 'p', 'pmh-assign__warn', t.sizesNone ) );
			} else {
				var sizes = intersect( b.sizes, cfg.product.sizes );
				if ( sizes.length ) {
					preview.appendChild( el( 'p', 'pmh-assign__ok', t.sizesFiltered + ' ' + sizes.join( ', ' ) ) );
				} else {
					preview.appendChild( el( 'p', 'pmh-assign__warn', t.sizesNoOverlap + ' ' + cfg.product.sizes.join( ', ' ) ) );
				}
			}
		}

		if ( cfg.product.hasPrintfulChart && b.kind === 'apparel' ) {
			if ( b.signature && b.signature === cfg.product.signature ) {
				preview.appendChild( el( 'p', 'pmh-assign__ok', t.match ) );
			} else {
				preview.appendChild( el( 'p', 'pmh-assign__warn', t.mismatch ) );
				var matches = matchingBlankNames();
				if ( matches.length ) {
					preview.appendChild( el( 'p', 'pmh-assign__hint', t.suggest + ' ' + matches.join( ', ' ) ) );
				}
			}
		}
	}

	select.addEventListener( 'change', renderPreview );
	if ( showAll ) {
		showAll.addEventListener( 'change', applyFilter );
	}
	if ( catBox ) {
		// Covers the "All" and "Most used" tabs; core mirrors ticks between them.
		catBox.addEventListener( 'change', function ( event ) {
			if ( event.target && event.target.type === 'checkbox' ) {
				applyFilter();
			}
		} );
	}

	// Start with "Show all" on when the saved blank would otherwise be filtered out.
	var selected = select.options[ select.selectedIndex ];
	if ( showAll && selected && selected.value && ! appliesTo( selected, checkedCategoryIds() ) ) {
		showAll.checked = true;
	}
	applyFilter();
} )();
