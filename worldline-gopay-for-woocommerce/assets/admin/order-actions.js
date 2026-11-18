( function () {
	'use strict';

	var openAction = null;
	var manualAmount = false;

	function itemsTable() {
		return document.querySelector( 'table.woocommerce_order_items' );
	}

	function totalsRow() {
		return document.querySelector( '.wc-order-data-row.wc-order-totals-items' );
	}

	function bulkRow() {
		return document.querySelector( '.wc-order-data-row.wc-order-bulk-actions' );
	}

	function panelFor( action ) {
		return document.getElementById( 'wlop-' + action + '-panel' );
	}

	// Price format from the store configuration (decimal/thousand separators and number of
	// decimals), exactly as WooCommerce's own price fields use it. The merchant controls precision
	// via WooCommerce > Settings > General; for currencies with cents set it to 2.
	function priceFormat() {
		var w = window.woocommerce_admin_meta_boxes || {};
		var decimals = parseInt( w.currency_format_num_decimals, 10 );
		return {
			decimal: w.currency_format_decimal_sep || '.',
			thousand: w.currency_format_thousand_sep || ',',
			decimals: decimals >= 0 ? decimals : 2
		};
	}

	function num( text ) {
		var f = priceFormat();
		var s = String( text == null ? '' : text ).replace( /\s/g, '' );
		if ( f.thousand ) {
			s = s.split( f.thousand ).join( '' );
		}
		s = s.split( f.decimal ).join( '.' ).replace( /[^0-9.\-]/g, '' );
		var n = parseFloat( s );
		return isFinite( n ) ? n : 0;
	}

	function fmt( n ) {
		var f = priceFormat();
		return ( isFinite( n ) ? n : 0 ).toFixed( f.decimals ).replace( '.', f.decimal );
	}

	function roundMoney( n ) {
		var factor = Math.pow( 10, priceFormat().decimals );
		return Math.round( ( isFinite( n ) ? n : 0 ) * factor ) / factor;
	}

	function amountOf( el ) {
		if ( el.dataset.amount !== undefined && el.dataset.amount !== '' ) {
			var m = parseFloat( el.dataset.amount );
			return isFinite( m ) ? m : 0;
		}
		return num( el.value );
	}

	function itemInfo( row ) {
		var items = window.wlopItems || {};
		var id = row.getAttribute( 'data-order_item_id' );
		return id && items[ id ] ? items[ id ] : null;
	}

	// Panels are printed hidden in the admin footer; relocate after the buttons row and slide in
	// like WooCommerce's refund panel.
	function relocate( panel ) {
		var anchor = bulkRow();
		if ( anchor && anchor.parentNode && panel.parentNode !== anchor.parentNode ) {
			anchor.parentNode.insertBefore( panel, anchor.nextSibling );
		}
	}

	// Per-line Qty / total / tax inputs, mirroring WooCommerce's refund UI. Tax inputs are added
	// only where the table already has tax cells (i.e. taxes are enabled), as refund does.
	function injectLineInputs() {
		var table = itemsTable();
		if ( ! table ) {
			return;
		}
		table.querySelectorAll( 'tr.item, tr.fee, tr.shipping' ).forEach( function ( row ) {
			var costTd = row.querySelector( 'td.line_cost' );
			if ( ! costTd ) {
				return;
			}
			var info = itemInfo( row );
			var qtyTd = row.querySelector( 'td.quantity' );
			var origQty = info ? ( parseFloat( info.qty ) || 0 ) : ( qtyTd ? num( qtyTd.textContent ) : 0 );
			var lineCost = info ? ( parseFloat( info.total ) || 0 ) : num( ( costTd.querySelector( '.view' ) || costTd ).textContent );
			var unit = origQty > 0 ? lineCost / origQty : 0;

			if ( qtyTd && row.classList.contains( 'item' ) && qtyTd.dataset.wlopOrig === undefined ) {
				qtyTd.dataset.wlopOrig = qtyTd.innerHTML;
				qtyTd.insertAdjacentHTML( 'beforeend',
					'<div class="edit wlop-edit"><input type="number" class="input-text wlop-line-qty" min="0" max="' + origQty +
					'" value="0" data-unit="' + unit + '" autocomplete="off"></div>' );
			}

			if ( costTd.dataset.wlopOrig === undefined ) {
				costTd.dataset.wlopOrig = costTd.innerHTML;
				costTd.insertAdjacentHTML( 'beforeend',
					'<div class="edit wlop-edit"><input type="text" class="input-text wlop-line-total wc_input_price" value="0" data-amount="0" inputmode="decimal" autocomplete="off"></div>' );
			}

			var taxTds = row.querySelectorAll( 'td.line_tax' );
			taxTds.forEach( function ( taxTd ) {
				if ( taxTd.dataset.wlopOrig !== undefined ) {
					return;
				}
				var lineTax = ( info && taxTds.length === 1 )
					? ( parseFloat( info.tax ) || 0 )
					: num( ( taxTd.querySelector( '.view' ) || taxTd ).textContent );
				var unitTax = origQty > 0 ? lineTax / origQty : 0;
				taxTd.dataset.wlopOrig = taxTd.innerHTML;
				taxTd.insertAdjacentHTML( 'beforeend',
					'<div class="edit wlop-edit"><input type="text" class="input-text wlop-line-tax wc_input_price" value="0" data-amount="0" inputmode="decimal" autocomplete="off" data-unit-tax="' + unitTax + '"></div>' );
			} );
		} );
	}

	function restoreLineInputs() {
		var table = itemsTable();
		if ( ! table ) {
			return;
		}
		table.querySelectorAll( 'td.quantity, td.line_cost, td.line_tax' ).forEach( function ( td ) {
			if ( td.dataset.wlopOrig !== undefined ) {
				td.innerHTML = td.dataset.wlopOrig;
				delete td.dataset.wlopOrig;
			}
		} );
	}

	function updateTotal( panel ) {
		var amount = panel.querySelector( '.wlop-panel-amount' );
		var total = panel.querySelector( '.wlop-panel-total' );
		if ( amount && total ) {
			total.textContent = fmt( amountOf( amount ) );
		}
	}

	function validate( panel, silent ) {
		var amount = panel.querySelector( '.wlop-panel-amount' );
		var err = panel.querySelector( '.wlop-panel-error' );
		var submitBtn = panel.querySelector( '.wlop-panel-submit' );
		if ( ! amount ) {
			return true;
		}
		var val = amountOf( amount );
		var max = parseFloat( amount.getAttribute( 'data-max' ) );
		if ( ! isFinite( max ) ) {
			max = 0;
		}
		var msg = '';
		if ( val <= 0 ) {
			msg = amount.getAttribute( 'data-err-positive' ) || '';
		} else if ( val > max + 0.0001 ) {
			msg = amount.getAttribute( 'data-err-max' ) || '';
		}
		if ( err ) {
			err.textContent = silent ? '' : msg;
			err.style.display = ( ! silent && msg ) ? '' : 'none';
		}
		if ( submitBtn ) {
			submitBtn.disabled = msg !== '';
		}
		return msg === '';
	}

	// Sum the per-line total + tax inputs into the open panel's amount. The amount field is
	// read-only (like the refund field): it always reflects the selected line items.
	function recalc() {
		var table = itemsTable();
		var panel = openAction ? panelFor( openAction ) : null;
		if ( ! table || ! panel ) {
			return;
		}
		var sum = 0;
		table.querySelectorAll( '.wlop-line-total, .wlop-line-tax' ).forEach( function ( el ) {
			sum += roundMoney( amountOf( el ) );
		} );
		sum = roundMoney( sum );
		var amount = panel.querySelector( '.wlop-panel-amount' );
		if ( amount && ! manualAmount ) {
			amount.dataset.amount = String( sum );
			amount.value = amount.type === 'number'
				? ( isFinite( sum ) ? sum.toFixed( priceFormat().decimals ) : '0' )
				: fmt( sum );
		}
		updateTotal( panel );
	}

	function setRowsHidden( hidden ) {
		var $ = window.jQuery;
		[ totalsRow(), bulkRow() ].forEach( function ( row ) {
			if ( ! row ) {
				return;
			}
			if ( $ ) {
				if ( hidden ) { $( row ).slideUp(); } else { $( row ).slideDown(); }
			} else {
				row.style.display = hidden ? 'none' : '';
			}
		} );
	}

	function open( action ) {
		var panel = panelFor( action );
		if ( ! panel ) {
			return;
		}
		relocate( panel );
		openAction = action;
		manualAmount = false;
		var $ = window.jQuery;
		[ 'capture', 'cancel' ].forEach( function ( other ) {
			var p = panelFor( other );
			if ( ! p || other === action ) {
				return;
			}
			if ( $ ) { $( p ).slideUp(); } else { p.style.display = 'none'; }
		} );
		injectLineInputs();
		setRowsHidden( true );
		if ( $ ) { $( panel ).slideDown(); } else { panel.style.display = ''; }
		recalc();
		validate( panel, true );
	}

	function close( panel ) {
		var $ = window.jQuery;
		if ( $ ) { $( panel ).slideUp(); } else { panel.style.display = 'none'; }
		setRowsHidden( false );
		restoreLineInputs();
		openAction = null;
	}

	function submit( button ) {
		if ( ! window.ajaxurl ) {
			return;
		}
		var panel = button.closest( '.wlop-action-panel' );
		if ( panel && ! validate( panel ) ) {
			return;
		}
		var amount = panel ? panel.querySelector( '.wlop-panel-amount' ) : null;
		var amountVal = amount ? String( amountOf( amount ) ) : '';
		var body = new URLSearchParams( {
			action: button.getAttribute( 'data-ajax-action' ),
			order: button.getAttribute( 'data-order' ),
			nonce: button.getAttribute( 'data-nonce' ),
			amount: amountVal
		} );

		button.disabled = true;
		fetch( window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				var msg = res && res.data && res.data.message ? res.data.message : '';
				if ( res && res.success ) {
					if ( msg ) { window.alert( msg ); }
					window.location.reload();
				} else {
					window.alert( msg || 'Action failed.' );
					button.disabled = false;
				}
			} )
			.catch( function () {
				window.alert( 'Action failed.' );
				button.disabled = false;
			} );
	}

	document.addEventListener( 'click', function ( e ) {
		var capture = e.target.closest( '.wlop-do-capture' );
		if ( capture ) { e.preventDefault(); open( 'capture' ); return; }

		var cancel = e.target.closest( '.wlop-do-cancel' );
		if ( cancel ) { e.preventDefault(); open( 'cancel' ); return; }

		var closeBtn = e.target.closest( '.wlop-panel-close' );
		if ( closeBtn ) {
			e.preventDefault();
			var panel = closeBtn.closest( '.wlop-action-panel' );
			if ( panel ) { close( panel ); }
			return;
		}

		var submitBtn = e.target.closest( '.wlop-panel-submit' );
		if ( submitBtn ) { e.preventDefault(); submit( submitBtn ); return; }
	} );

	document.addEventListener( 'input', function ( e ) {
		var t = e.target;
		if ( ! t || ! t.classList ) {
			return;
		}

		if ( t.classList.contains( 'wlop-line-qty' ) ) {
			manualAmount = false;
			var q = parseFloat( t.value ) || 0;
			var row = t.closest( 'tr' );
			if ( row ) {
				var lineTotal = row.querySelector( '.wlop-line-total' );
				if ( lineTotal ) {
					var amt = ( parseFloat( t.dataset.unit ) || 0 ) * q;
					lineTotal.dataset.amount = String( amt );
					lineTotal.value = fmt( amt );
				}
				row.querySelectorAll( '.wlop-line-tax' ).forEach( function ( tx ) {
					var taxAmt = ( parseFloat( tx.dataset.unitTax ) || 0 ) * q;
					tx.dataset.amount = String( taxAmt );
					tx.value = fmt( taxAmt );
				} );
			}
			recalc();
			if ( openAction ) { validate( panelFor( openAction ) ); }
			return;
		}

		if ( t.classList.contains( 'wlop-line-total' ) || t.classList.contains( 'wlop-line-tax' ) ) {
			manualAmount = false;
			delete t.dataset.amount;
			recalc();
			if ( openAction ) { validate( panelFor( openAction ) ); }
			return;
		}

		if ( t.classList.contains( 'wlop-panel-amount' ) ) {
			manualAmount = true;
			t.dataset.amount = String( num( t.value ) );
			var p = t.closest( '.wlop-action-panel' );
			if ( p ) { updateTotal( p ); validate( p ); }
		}
	} );

	document.addEventListener( 'change', function ( e ) {
		var t = e.target;
		if ( t && t.classList && t.classList.contains( 'wlop-panel-amount' ) ) {
			t.value = fmt( amountOf( t ) );
		}
	} );

	var R = window.wlopRefund;
	if ( R && R.split ) {
		var fullAmount = parseFloat( R.amount ) || 0;
		var lockRefund = function () {
			var amt = document.getElementById( 'refund_amount' );
			if ( amt && ! amt.readOnly ) {
				amt.value = fmt( fullAmount );
				amt.readOnly = true;
			}
			document.querySelectorAll(
				'.wc-order-refund-items input.refund_line_total, .wc-order-refund-items input.refund_line_tax, .wc-order-refund-items input.refund_order_item_qty'
			).forEach( function ( i ) { if ( ! i.readOnly ) { i.readOnly = true; } } );
			var btn = document.querySelector( '.do-api-refund' );
			if ( btn && btn.textContent !== R.label ) {
				btn.textContent = R.label;
			}
		};
		if ( window.MutationObserver ) {
			new MutationObserver( lockRefund ).observe(
				document.body,
				{ childList: true, subtree: true, characterData: true }
			);
		}
		document.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( '.refund-items' ) ) {
				lockRefund();
				setTimeout( lockRefund, 50 );
			}
		} );
	}
	if ( R && R.wero && window.jQuery ) {
		var reasonId = 'wlop-refund-reason';
		var addReasonRow = function () {
			var input = document.getElementById( 'refund_reason' );
			var row = input ? input.closest( 'tr' ) : null;
			if ( ! row || document.getElementById( reasonId ) ) {
				return;
			}
			var table = row.closest( 'table' );
			if ( table ) {
				table.classList.add( 'wlop-wero-refund' );
			}
			var noteRow = document.createElement( 'tr' );
			var noteCell = document.createElement( 'td' );
			noteCell.colSpan = 2;
			var note = document.createElement( 'div' );
			note.className = 'wlop-wero-note';
			note.textContent = R.reasonNote;
			noteCell.appendChild( note );
			noteRow.appendChild( noteCell );
			var tr = document.createElement( 'tr' );
			var labelCell = document.createElement( 'td' );
			labelCell.className = 'label';
			var label = document.createElement( 'label' );
			label.htmlFor = reasonId;
			var tip = document.createElement( 'span' );
			tip.className = 'woocommerce-help-tip';
			tip.tabIndex = 0;
			tip.setAttribute( 'aria-label', R.reasonTooltip );
			tip.setAttribute( 'data-tip', R.reasonTooltip );
			label.appendChild( tip );
			label.appendChild( document.createTextNode( ' ' + R.reasonLabel + ':' ) );
			labelCell.appendChild( label );
			var fieldCell = document.createElement( 'td' );
			fieldCell.className = 'total';
			var select = document.createElement( 'select' );
			select.id = reasonId;
			select.className = 'wlop-refund-reason';
			( R.reasons || [] ).forEach( function ( value ) {
				var option = document.createElement( 'option' );
				option.value = value;
				option.textContent = value;
				select.appendChild( option );
			} );
			fieldCell.appendChild( select );
			tr.appendChild( labelCell );
			tr.appendChild( fieldCell );
			row.parentNode.insertBefore( noteRow, row.nextSibling );
			row.parentNode.insertBefore( tr, noteRow.nextSibling );
			window.jQuery( document.body ).trigger( 'init_tooltips' );
			var sizeNote = function () {
				var start = label.getBoundingClientRect().left;
				var end = select.getBoundingClientRect().right;
				if ( select.offsetWidth && end > start ) {
					note.style.width = Math.round( end - start ) + 'px';
				}
			};
			if ( window.ResizeObserver ) {
				var resizeObserver = new ResizeObserver( sizeNote );
				resizeObserver.observe( select );
				resizeObserver.observe( label );
			}
			sizeNote();
		};
		addReasonRow();
		if ( window.MutationObserver ) {
			new MutationObserver( addReasonRow ).observe(
				document.getElementById( 'woocommerce-order-items' ) || document.body,
				{ childList: true, subtree: true }
			);
		}
		window.jQuery.ajaxPrefilter( function ( options ) {
			var select = document.getElementById( reasonId );
			if ( ! select || typeof options.data !== 'string' || options.data.indexOf( 'action=woocommerce_refund_line_items' ) === -1 ) {
				return;
			}
			options.data += '&' + encodeURIComponent( R.reasonField ) + '=' + encodeURIComponent( select.value );
		} );
	}
	var L = window.wlopEditLock;
	if ( L && L.locked ) {
		if ( document.body ) {
			document.body.classList.add( 'wlop-order-locked' );
		}
		var relabel = function () {
			var note = document.querySelector( '.wc-order-bulk-actions p.add-items span.description' );
			if ( note && note.getAttribute( 'data-wlop-relabelled' ) !== '1' ) {
				note.textContent = L.message;
				note.setAttribute( 'data-wlop-relabelled', '1' );
			}
		};
		relabel();
		if ( window.MutationObserver ) {
			new MutationObserver( relabel ).observe( document.body, { childList: true, subtree: true } );
		}
	}
} )();
