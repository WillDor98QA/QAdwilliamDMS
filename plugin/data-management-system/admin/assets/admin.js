/**
 * Data Management admin behaviour (MP §26, §34, §35; ARCH §74).
 *
 * - Bulk actions: dim the page, show "Are you sure you want to perform this action?",
 *   and only on Confirm post the selection to the server. Cancel changes nothing.
 *   The server independently refuses unconfirmed or unauthorized requests.
 * - Permanent delete / role delete: the same dialog with a stronger message.
 * - Cascading Region → Constituency → Polling Station filters.
 * Keyboard: focus moves into the dialog, Tab is trapped, Escape cancels,
 * focus returns to the triggering control.
 */
( function () {
	'use strict';

	var cfg = window.dmsAdmin || {};
	var t = cfg.i18n || {};

	function fmt( s, v ) {
		return String( s || '' ).replace( '%d', v );
	}

	function confirmDialog( options ) {
		return new Promise( function ( resolve ) {
			var opener = document.activeElement;
			var overlay = document.createElement( 'div' );
			overlay.className = 'dms-modal-overlay';
			overlay.innerHTML =
				'<div class="dms-modal" role="alertdialog" aria-modal="true" aria-labelledby="dms-modal-title" aria-describedby="dms-modal-body">' +
				'<h2 id="dms-modal-title"></h2><div id="dms-modal-body"></div>' +
				'<div class="dms-modal-actions"><button type="button" class="button" data-cancel></button> ' +
				'<button type="button" class="button button-primary" data-confirm></button></div></div>';
			overlay.querySelector( '#dms-modal-title' ).textContent = t.confirmTitle;
			var body = overlay.querySelector( '#dms-modal-body' );
			( options.lines || [] ).forEach( function ( line, i ) {
				var p = document.createElement( 'p' );
				if ( i === 0 ) {
					var strong = document.createElement( 'strong' );
					strong.textContent = line;
					p.appendChild( strong );
				} else {
					p.textContent = line;
				}
				body.appendChild( p );
			} );
			var cancel = overlay.querySelector( '[data-cancel]' );
			var ok = overlay.querySelector( '[data-confirm]' );
			cancel.textContent = t.cancel;
			ok.textContent = options.confirmLabel || t.confirm;
			if ( options.danger ) {
				ok.classList.add( 'dms-button-danger' );
			}
			document.body.appendChild( overlay );
			document.body.classList.add( 'dms-modal-open' );
			cancel.focus();

			function close( result ) {
				overlay.remove();
				document.body.classList.remove( 'dms-modal-open' );
				if ( opener && opener.focus ) {
					opener.focus();
				}
				resolve( result );
			}
			cancel.addEventListener( 'click', function () {
				close( false );
			} );
			ok.addEventListener( 'click', function () {
				close( true );
			} );
			overlay.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Escape' ) {
					e.preventDefault();
					close( false );
				} else if ( e.key === 'Tab' ) {
					var first = cancel;
					var last = ok;
					if ( e.shiftKey && document.activeElement === first ) {
						e.preventDefault();
						last.focus();
					} else if ( ! e.shiftKey && document.activeElement === last ) {
						e.preventDefault();
						first.focus();
					}
				}
			} );
		} );
	}

	// ---- Bulk actions on list screens ------------------------------------
	document.querySelectorAll( '[data-dms-list-form]' ).forEach( function ( listForm ) {
		var postForm = document.querySelector( '[data-dms-post-form="bulk"]' );
		if ( ! postForm ) {
			return;
		}
		listForm.addEventListener( 'submit', function ( e ) {
			var submitter = e.submitter;
			if ( ! submitter || ( submitter.id !== 'doaction' && submitter.id !== 'doaction2' ) ) {
				return; // Search / filter submit.
			}
			e.preventDefault();
			var select = listForm.querySelector( submitter.id === 'doaction' ? '#bulk-action-selector-top' : '#bulk-action-selector-bottom' );
			var action = select ? select.value : '-1';
			var ids = Array.prototype.map.call( listForm.querySelectorAll( 'input[name="ids[]"]:checked' ), function ( el ) {
				return el.value;
			} );
			if ( action === '-1' ) {
				window.alert( t.chooseAction );
				return;
			}
			if ( ! ids.length ) {
				window.alert( t.nothingSelected );
				return;
			}
			var destructive = action === 'delete';
			confirmDialog( {
				lines: [ destructive ? t.confirmDestructive : t.confirmBulk, fmt( t.selected, ids.length ) ],
				danger: destructive,
				confirmLabel: destructive ? t.deleteButton : t.confirm,
			} ).then( function ( confirmed ) {
				if ( ! confirmed ) {
					return;
				}
				postForm.querySelectorAll( '[data-dms-dynamic]' ).forEach( function ( el ) {
					el.remove();
				} );
				function add( name, value ) {
					var input = document.createElement( 'input' );
					input.type = 'hidden';
					input.name = name;
					input.value = value;
					input.setAttribute( 'data-dms-dynamic', '' );
					postForm.appendChild( input );
				}
				ids.forEach( function ( id ) {
					add( 'ids[]', id );
				} );
				add( 'bulk_action', action );
				add( 'confirmed', '1' );
				var officer = listForm.querySelector( '[data-dms-bulk-officer]' );
				if ( officer ) {
					add( 'officer_id', officer.value );
				}
				postForm.submit();
			} );
		} );
	} );

	// ---- Single destructive actions ---------------------------------------
	document.querySelectorAll( '[data-dms-confirm-delete]' ).forEach( function ( flag ) {
		var form = flag.form;
		form.addEventListener( 'submit', function ( e ) {
			if ( flag.value === '1' ) {
				return;
			}
			e.preventDefault();
			confirmDialog( { lines: [ t.confirmDelete ], danger: true, confirmLabel: t.deleteButton } ).then( function ( ok ) {
				if ( ok ) {
					flag.value = '1';
					form.submit();
				}
			} );
		} );
	} );
	document.querySelectorAll( 'form[data-dms-confirm]' ).forEach( function ( form ) {
		var confirmed = false;
		form.addEventListener( 'submit', function ( e ) {
			if ( confirmed ) {
				return;
			}
			e.preventDefault();
			confirmDialog( { lines: [ form.getAttribute( 'data-dms-confirm' ) ], danger: true } ).then( function ( ok ) {
				if ( ok ) {
					confirmed = true;
					form.submit();
				}
			} );
		} );
	} );

	// ---- Cascading electoral filters --------------------------------------
	function cascade( parent, child, path, placeholder ) {
		if ( ! parent || ! child ) {
			return;
		}
		parent.addEventListener( 'change', function () {
			child.innerHTML = '';
			var blank = document.createElement( 'option' );
			blank.value = '';
			blank.textContent = placeholder;
			child.appendChild( blank );
			child.disabled = true;
			child.dispatchEvent( new Event( 'change' ) );
			if ( ! parent.value ) {
				return;
			}
			fetch( cfg.restBase + path.replace( '{id}', encodeURIComponent( parent.value ) ), { credentials: 'same-origin' } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( items ) {
					( Array.isArray( items ) ? items : [] ).forEach( function ( item ) {
						var o = document.createElement( 'option' );
						o.value = item.id;
						o.textContent = item.name;
						child.appendChild( o );
					} );
					child.disabled = false;
				} );
		} );
	}
	var region = document.querySelector( '[data-dms-cascade="region"]' );
	var constituency = document.querySelector( '[data-dms-cascade="constituency"]' );
	var station = document.querySelector( '[data-dms-cascade="polling_station"]' );
	cascade( region, constituency, 'regions/{id}/constituencies', constituency ? constituency.options[ 0 ].textContent : t.all );
	cascade( constituency, station, 'constituencies/{id}/polling-stations', station ? station.options[ 0 ].textContent : t.all );
} )();
