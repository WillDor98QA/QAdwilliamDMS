/**
 * Public registration form — progressive enhancement (ARCH §36, MP §9, §35, CR-01).
 *
 * - Cascading Region → Constituency → Polling Station, fetched per parent.
 * - Submits JSON to the REST API. The server decides whether OTP applies:
 *     201 → created, 202 → show the Phone Verification step, 422 → field errors,
 *     429 → wait, 403 → refresh the form nonce and retry.
 * - Client-side checks are for convenience only; the server re-validates everything.
 */
( function () {
	'use strict';

	var cfg = window.dmsRegistration || {};
	var t = cfg.i18n || {};

	function fmt( s, v ) {
		return String( s || '' ).replace( '%s', v );
	}

	function api( path, options ) {
		return fetch( cfg.restBase + path, Object.assign( { credentials: 'same-origin' }, options || {} ) ).then( function ( res ) {
			return res.json().catch( function () {
				return {};
			} ).then( function ( body ) {
				return { status: res.status, body: body };
			} );
		} );
	}

	function init( form ) {
		var state = { nonce: null, otpRequestId: null, timer: null };
		var alertBox = form.querySelector( '[data-dms-alert]' );
		var submitBtn = form.querySelector( '[data-dms-submit]' );
		var success = form.parentNode.querySelector( '[data-dms-success]' );

		function refreshConfig() {
			return api( 'form' ).then( function ( r ) {
				if ( r.status === 200 && r.body.nonce ) {
					state.nonce = r.body.nonce;
					if ( ! state.otpRequestId ) {
						submitBtn.textContent = r.body.otp && r.body.otp.enabled ? t.continueOtp : t.submit;
					}
				}
			} );
		}

		function showAlert( message ) {
			alertBox.textContent = message;
			alertBox.hidden = ! message;
			if ( message ) {
				alertBox.focus();
			}
		}

		function clearErrors() {
			showAlert( '' );
			form.querySelectorAll( '[data-dms-error-for]' ).forEach( function ( el ) {
				el.textContent = '';
			} );
			form.querySelectorAll( '[aria-invalid="true"]' ).forEach( function ( el ) {
				el.removeAttribute( 'aria-invalid' );
			} );
		}

		function showFieldErrors( errors ) {
			var first = null;
			Object.keys( errors || {} ).forEach( function ( key ) {
				var slot = form.querySelector( '[data-dms-error-for="' + key + '"]' );
				var input = form.querySelector( '[name="' + key + '"]' );
				if ( slot ) {
					slot.textContent = errors[ key ];
				}
				if ( input ) {
					input.setAttribute( 'aria-invalid', 'true' );
					first = first || input;
				}
			} );
			if ( first ) {
				first.focus();
			}
			if ( ! first || errors.form ) {
				showAlert( errors.form || t.fixErrors );
			}
		}

		// A field's error clears as soon as the person changes it (UI-01).
		function clearFieldError( e ) {
			var field = e.target;
			if ( ! field.name || field.getAttribute( 'aria-invalid' ) !== 'true' ) {
				return;
			}
			field.removeAttribute( 'aria-invalid' );
			var slot = form.querySelector( '[data-dms-error-for="' + field.name + '"]' );
			if ( slot ) {
				slot.textContent = '';
			}
		}
		form.addEventListener( 'input', clearFieldError );
		form.addEventListener( 'change', clearFieldError );

		// ---- Cascading electoral selects ---------------------------------
		var selects = {
			region: form.querySelector( '[data-dms-level="region"]' ),
			constituency: form.querySelector( '[data-dms-level="constituency"]' ),
			polling_station: form.querySelector( '[data-dms-level="polling_station"]' ),
		};

		function reset( select ) {
			if ( ! select ) {
				return;
			}
			select.innerHTML = '';
			var opt = document.createElement( 'option' );
			opt.value = '';
			opt.textContent = t.select;
			select.appendChild( opt );
			select.disabled = true;
		}

		function load( select, path ) {
			reset( select );
			select.setAttribute( 'aria-busy', 'true' );
			return api( path ).then( function ( r ) {
				select.removeAttribute( 'aria-busy' );
				if ( r.status !== 200 || ! Array.isArray( r.body ) ) {
					showAlert( r.body && r.body.message ? r.body.message : t.loadFailed );
					return;
				}
				r.body.forEach( function ( item ) {
					var opt = document.createElement( 'option' );
					opt.value = item.id;
					opt.textContent = item.name;
					select.appendChild( opt );
				} );
				select.disabled = false;
			} );
		}

		if ( selects.region ) {
			selects.region.addEventListener( 'change', function () {
				reset( selects.polling_station );
				if ( this.value ) {
					load( selects.constituency, 'regions/' + encodeURIComponent( this.value ) + '/constituencies' );
				} else {
					reset( selects.constituency );
				}
			} );
		}
		if ( selects.constituency ) {
			selects.constituency.addEventListener( 'change', function () {
				if ( this.value ) {
					load( selects.polling_station, 'constituencies/' + encodeURIComponent( this.value ) + '/polling-stations' );
				} else {
					reset( selects.polling_station );
				}
			} );
		}

		// ---- OTP step ----------------------------------------------------
		function otpStep() {
			var step = form.querySelector( '[data-dms-otp]' );
			if ( ! step ) {
				// OTP was switched on after this page was cached: add the step now.
				var tpl = form.parentNode.querySelector( '[data-dms-otp-template]' );
				form.querySelector( '.dms-actions' ).insertAdjacentHTML( 'beforebegin', tpl.innerHTML );
				step = form.querySelector( '[data-dms-otp]' );
				step.querySelector( '[data-dms-resend]' ).addEventListener( 'click', resend );
			}
			return step;
		}

		function startResendTimer( seconds ) {
			var step = otpStep();
			var btn = step.querySelector( '[data-dms-resend]' );
			var label = step.querySelector( '[data-dms-resend-timer]' );
			clearInterval( state.timer );
			var left = Math.max( 0, parseInt( seconds, 10 ) || 0 );
			btn.disabled = left > 0;
			label.textContent = left > 0 ? fmt( t.resendIn, left ) : '';
			state.timer = setInterval( function () {
				left -= 1;
				if ( left <= 0 ) {
					clearInterval( state.timer );
					btn.disabled = false;
					label.textContent = '';
				} else {
					label.textContent = fmt( t.resendIn, left );
				}
			}, 1000 );
		}

		function enterOtpStep( body ) {
			var step = otpStep();
			state.otpRequestId = body.otp_request_id;
			step.hidden = false;
			step.querySelector( '[data-dms-otp-message]' ).textContent = body.message;
			submitBtn.textContent = t.verifySubmit;
			startResendTimer( body.resend_after );
			step.querySelector( '[name="otp_code"]' ).focus();
		}

		function resend() {
			clearErrors();
			var phone = form.querySelector( '[name="phone"]' );
			post( 'registrations/otp/resend', { otp_request_id: state.otpRequestId, phone: phone ? phone.value : '' } );
		}

		var existingResend = form.querySelector( '[data-dms-resend]' );
		if ( existingResend ) {
			existingResend.addEventListener( 'click', resend );
		}

		// ---- Submission --------------------------------------------------
		function payload() {
			var data = {};
			new FormData( form ).forEach( function ( value, key ) {
				data[ key ] = value;
			} );
			data.consent = form.querySelector( '[name="consent"]' ).checked;
			if ( state.otpRequestId ) {
				data.otp_request_id = state.otpRequestId;
			} else {
				delete data.otp_code;
			}
			return data;
		}

		function post( path, data, retried ) {
			submitBtn.disabled = true;
			form.setAttribute( 'aria-busy', 'true' );
			var original = submitBtn.textContent;
			submitBtn.textContent = t.working;

			return api( path, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-DMS-Nonce': state.nonce || '' },
				body: JSON.stringify( data ),
			} ).then( function ( r ) {
				submitBtn.disabled = false;
				form.removeAttribute( 'aria-busy' );
				submitBtn.textContent = original;

				if ( r.status === 201 ) {
					form.hidden = true;
					success.textContent = fmt( t.success, r.body.registration_number );
					success.hidden = false;
					success.focus();
					return;
				}
				if ( r.status === 202 ) {
					enterOtpStep( r.body );
					return;
				}
				if ( r.status === 403 && ! retried ) {
					return refreshConfig().then( function () {
						return post( path, data, true );
					} );
				}
				if ( r.status === 422 ) {
					showFieldErrors( r.body.errors );
					if ( r.body.errors && r.body.errors.otp_code && /request a new code/i.test( r.body.errors.otp_code ) ) {
						startResendTimer( 0 );
					}
					return;
				}
				if ( r.status === 429 && state.otpRequestId ) {
					startResendTimer( r.body.retry_after );
				}
				if ( r.status === 409 && r.body.code === 'dms_otp_consumed' ) {
					state.otpRequestId = null;
					submitBtn.textContent = t.continueOtp;
				}
				showAlert( ( r.body && r.body.message ) || t.genericError );
			} ).catch( function () {
				submitBtn.disabled = false;
				form.removeAttribute( 'aria-busy' );
				submitBtn.textContent = original;
				showAlert( t.genericError );
			} );
		}

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			clearErrors();
			if ( ! form.checkValidity() ) {
				var invalid = {};
				form.querySelectorAll( ':invalid' ).forEach( function ( el ) {
					if ( el.name ) {
						invalid[ el.name ] = el.validationMessage;
					}
				} );
				showFieldErrors( invalid );
				return;
			}
			var send = function () {
				return post( 'registrations', payload() );
			};
			if ( state.nonce ) {
				send();
			} else {
				refreshConfig().then( send );
			}
		} );

		refreshConfig();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '[data-dms-form]' ).forEach( init );
	} );
} )();
