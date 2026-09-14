/* Blank edit screen: grid editor for the size charts.
   Columns are sizes, rows are measurements. The JSON textarea stays as the
   transport: the grid reads it on load and writes it on every change, so
   the save layer is unchanged and "Edit as JSON" is always available. */
( function () {
	'use strict';

	var CELL_OK = /^\s*[\d.,\s½¼¾⅓⅔⅛⅜⅝⅞\/"″]+(\s*(?:-|–|—|to)\s*[\d.,\s½¼¾⅓⅔⅛⅜⅝⅞\/"″]+)?\s*$/;
	var i18n = window.pmhGridI18n || {};

	function t( key, fallback ) {
		return i18n[ key ] || fallback;
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

	function button( cls, label, title ) {
		var b = el( 'button', 'button button-small ' + cls, label );
		b.type = 'button';
		if ( title ) {
			b.title = title;
			b.setAttribute( 'aria-label', title );
		}
		return b;
	}

	/* Stored values are [28] or [34, 37]; the grid edits text like "34-37". */
	function valueToText( v ) {
		if ( Array.isArray( v ) ) {
			return v.map( String ).join( '-' );
		}
		return v === undefined || v === null ? '' : String( v );
	}

	function parseModel( json ) {
		var model = { sizes: [], note: '', rows: [] };
		if ( ! json || ! json.trim() ) {
			return model;
		}
		var data = JSON.parse( json ); // caller handles the throw
		model.note = typeof data.note === 'string' ? data.note : '';
		model.sizes = Array.isArray( data.sizes ) ? data.sizes.map( String ) : [];
		( Array.isArray( data.rows ) ? data.rows : [] ).forEach( function ( row ) {
			if ( ! row || typeof row !== 'object' ) {
				return;
			}
			var values = {};
			Object.keys( row.values || {} ).forEach( function ( size ) {
				values[ size ] = valueToText( row.values[ size ] );
				if ( model.sizes.indexOf( size ) === -1 ) {
					model.sizes.push( size );
				}
			} );
			model.rows.push( { label: row.label ? String( row.label ) : '', values: values } );
		} );
		return model;
	}

	function serialise( model ) {
		var sizes = model.sizes.map( function ( s ) { return s.trim(); } ).filter( Boolean );
		var rows = model.rows
			.map( function ( row ) {
				var values = {};
				sizes.forEach( function ( size ) {
					var v = ( row.values[ size ] || '' ).trim();
					if ( v ) {
						values[ size ] = v; // server parses "28" / "34-37" / "16 ½"
					}
				} );
				return { label: row.label.trim(), values: values };
			} )
			.filter( function ( row ) { return row.label && Object.keys( row.values ).length; } );
		if ( ! sizes.length || ! rows.length ) {
			return '';
		}
		return JSON.stringify( { sizes: sizes, note: model.note.trim(), rows: rows }, null, 2 );
	}

	function Grid( textarea ) {
		this.textarea = textarea;
		this.root = el( 'div', 'pmh-grid' );
		this.error = el( 'p', 'pmh-grid__error' );
		this.error.hidden = true;
		this.jsonToggle = button( 'pmh-grid__json-toggle', t( 'editJson', 'Edit as JSON' ) );
		this.showingJson = false;

		textarea.parentNode.insertBefore( this.root, textarea );
		textarea.parentNode.insertBefore( this.error, textarea );
		textarea.parentNode.insertBefore( this.jsonToggle, textarea );
		textarea.hidden = true;

		var self = this;
		this.jsonToggle.addEventListener( 'click', function () { self.toggleJson(); } );

		this.load();
	}

	Grid.prototype.load = function () {
		try {
			this.model = parseModel( this.textarea.value );
			this.error.hidden = true;
		} catch ( e ) {
			this.model = { sizes: [], note: '', rows: [] };
			this.error.textContent = t( 'badJson', 'The JSON could not be read; fix it in the JSON view or start the grid from scratch.' );
			this.error.hidden = false;
		}
		this.render();
	};

	Grid.prototype.sync = function () {
		this.textarea.value = serialise( this.model );
	};

	Grid.prototype.toggleJson = function () {
		if ( this.showingJson ) {
			// Coming back from JSON: re-read whatever was typed. If it does not
			// parse, stay in the JSON view so it can be fixed.
			this.load();
			if ( ! this.error.hidden ) {
				this.root.hidden = true;
				return;
			}
			this.textarea.hidden = true;
			this.root.hidden = false;
			this.jsonToggle.textContent = t( 'editJson', 'Edit as JSON' );
		} else {
			this.sync();
			this.textarea.hidden = false;
			this.root.hidden = true;
			this.jsonToggle.textContent = t( 'editGrid', 'Back to grid' );
		}
		this.showingJson = ! this.showingJson;
	};

	Grid.prototype.render = function () {
		var self = this;
		var m = this.model;
		this.root.innerHTML = '';

		// Note.
		var noteWrap = el( 'p', 'pmh-grid__note' );
		var noteLabel = el( 'label', null, t( 'note', 'Note' ) + ' ' );
		var noteInput = el( 'input', 'regular-text' );
		noteInput.type = 'text';
		noteInput.value = m.note;
		noteInput.placeholder = t( 'notePlaceholder', 'Product measurements may vary by up to 2" (5 cm).' );
		noteInput.addEventListener( 'input', function () { m.note = noteInput.value; self.sync(); } );
		noteLabel.appendChild( noteInput );
		noteWrap.appendChild( noteLabel );
		this.root.appendChild( noteWrap );

		// Table.
		var wrap = el( 'div', 'pmh-grid__scroll' );
		var table = el( 'table', 'pmh-grid__table' );
		var thead = el( 'thead' );
		var headRow = el( 'tr' );
		headRow.appendChild( el( 'th', 'pmh-grid__corner', t( 'measurement', 'Measurement' ) ) );

		m.sizes.forEach( function ( size, i ) {
			var th = el( 'th', 'pmh-grid__size' );
			var input = el( 'input', 'pmh-grid__size-input' );
			input.type = 'text';
			input.value = size;
			input.placeholder = 'XL';
			input.setAttribute( 'aria-label', t( 'sizeName', 'Size name' ) );
			input.addEventListener( 'input', function () { self.renameSize( i, input.value ); } );
			th.appendChild( input );
			var rm = button( 'pmh-grid__remove', '×', t( 'removeSize', 'Remove this size' ) );
			rm.addEventListener( 'click', function () { self.removeSize( i ); } );
			th.appendChild( rm );
			headRow.appendChild( th );
		} );

		var addTh = el( 'th', 'pmh-grid__add' );
		var addSize = button( 'pmh-grid__add-size', '+ ' + t( 'size', 'Size' ) );
		addSize.addEventListener( 'click', function () { self.addSize(); } );
		addTh.appendChild( addSize );
		headRow.appendChild( addTh );
		thead.appendChild( headRow );
		table.appendChild( thead );

		var tbody = el( 'tbody' );
		m.rows.forEach( function ( row, r ) {
			var tr = el( 'tr' );
			var labelTd = el( 'td', 'pmh-grid__label-cell' );
			var label = el( 'input', 'pmh-grid__label' );
			label.type = 'text';
			label.value = row.label;
			label.placeholder = t( 'labelPlaceholder', 'Length' );
			label.setAttribute( 'aria-label', t( 'measurement', 'Measurement' ) );
			label.addEventListener( 'input', function () { row.label = label.value; self.sync(); } );
			labelTd.appendChild( label );
			tr.appendChild( labelTd );

			m.sizes.forEach( function ( size, col ) {
				var td = el( 'td', 'pmh-grid__cell' );
				var input = el( 'input', 'pmh-grid__value' );
				input.type = 'text';
				input.value = row.values[ size ] || '';
				input.placeholder = '28 or 34-37';
				input.setAttribute( 'aria-label', row.label + ' ' + size );
				input.addEventListener( 'input', function () {
					// Look the size up by column at edit time: the header may
					// have been renamed since this cell was rendered.
					row.values[ m.sizes[ col ] ] = input.value;
					input.classList.toggle( 'pmh-grid__value--bad', !! input.value.trim() && ! CELL_OK.test( input.value ) );
					self.sync();
				} );
				td.appendChild( input );
				tr.appendChild( td );
			} );

			var rmTd = el( 'td', 'pmh-grid__remove-cell' );
			var rm = button( 'pmh-grid__remove', '×', t( 'removeRow', 'Remove this measurement' ) );
			rm.addEventListener( 'click', function () { self.removeRow( r ); } );
			rmTd.appendChild( rm );
			tr.appendChild( rmTd );
			tbody.appendChild( tr );
		} );
		table.appendChild( tbody );

		var tfoot = el( 'tfoot' );
		var footRow = el( 'tr' );
		var footTd = el( 'td' );
		footTd.colSpan = m.sizes.length + 2;
		var addRow = button( 'pmh-grid__add-row', '+ ' + t( 'measurement', 'Measurement' ) );
		addRow.addEventListener( 'click', function () { self.addRow(); } );
		footTd.appendChild( addRow );
		footRow.appendChild( footTd );
		tfoot.appendChild( footRow );
		table.appendChild( tfoot );

		wrap.appendChild( table );
		this.root.appendChild( wrap );

		if ( ! m.sizes.length || ! m.rows.length ) {
			this.root.appendChild( el( 'p', 'description', t( 'empty', 'No chart yet. Add a size and a measurement, or use one of the import boxes above.' ) ) );
		}
	};

	Grid.prototype.addSize = function () {
		this.model.sizes.push( '' );
		this.render();
		var inputs = this.root.querySelectorAll( '.pmh-grid__size-input' );
		if ( inputs.length ) {
			inputs[ inputs.length - 1 ].focus();
		}
	};

	Grid.prototype.renameSize = function ( index, name ) {
		var old = this.model.sizes[ index ];
		if ( old === name ) {
			return;
		}
		this.model.sizes[ index ] = name;
		this.model.rows.forEach( function ( row ) {
			if ( Object.prototype.hasOwnProperty.call( row.values, old ) ) {
				row.values[ name ] = row.values[ old ];
				delete row.values[ old ];
			}
		} );
		this.sync();
	};

	Grid.prototype.removeSize = function ( index ) {
		var size = this.model.sizes[ index ];
		this.model.sizes.splice( index, 1 );
		this.model.rows.forEach( function ( row ) { delete row.values[ size ]; } );
		this.sync();
		this.render();
	};

	Grid.prototype.addRow = function () {
		this.model.rows.push( { label: '', values: {} } );
		this.render();
		var labels = this.root.querySelectorAll( '.pmh-grid__label' );
		if ( labels.length ) {
			labels[ labels.length - 1 ].focus();
		}
	};

	Grid.prototype.removeRow = function ( index ) {
		this.model.rows.splice( index, 1 );
		this.sync();
		this.render();
	};

	function boot() {
		var grids = [];
		var areas = document.querySelectorAll( 'textarea.pmh-chart-json' );
		for ( var i = 0; i < areas.length; i++ ) {
			grids.push( new Grid( areas[ i ] ) );
		}

		// The add-new form saves over AJAX and core clears its fields
		// afterwards; rebuild the grids so they match the cleared textareas.
		if ( window.jQuery && document.getElementById( 'addtag' ) ) {
			window.jQuery( document ).on( 'ajaxComplete', function ( event, xhr, settings ) {
				if ( settings && settings.data && String( settings.data ).indexOf( 'action=add-tag' ) !== -1 ) {
					grids.forEach( function ( g ) {
						if ( document.getElementById( 'addtag' ).contains( g.textarea ) ) {
							g.load();
						}
					} );
				}
			} );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
