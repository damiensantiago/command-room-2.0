/**
 * Command Room — pantalla "Servidor" (Redirecciones + Monitor 404 +
 * Limpieza). Las pestañas son enlaces normales (recargan la página, no hay
 * estado en cliente) — este script solo cubre lo que pasa dentro de la
 * pestaña ya renderizada: el diálogo de redirección, los segmentados, los
 * toggles y las confirmaciones de borrado.
 */
( function () {
	'use strict';

	function initSegmented( wrap ) {
		wrap.querySelectorAll( '.cr-seg' ).forEach( function ( seg ) {
			var hidden = seg.parentElement.querySelector( 'input[type="hidden"].cr-seg-value' );
			seg.querySelectorAll( '.cr-seg-opt' ).forEach( function ( opt ) {
				opt.addEventListener( 'click', function () {
					seg.querySelectorAll( '.cr-seg-opt' ).forEach( function ( o ) {
						o.classList.toggle( 'is-active', o === opt );
					} );
					if ( hidden ) {
						hidden.value = opt.getAttribute( 'data-value' );
					}
				} );
			} );
		} );
	}

	function setSegmented( seg, value ) {
		var hidden = seg.parentElement.querySelector( 'input[type="hidden"].cr-seg-value' );
		seg.querySelectorAll( '.cr-seg-opt' ).forEach( function ( opt ) {
			var active = opt.getAttribute( 'data-value' ) === value;
			opt.classList.toggle( 'is-active', active );
			if ( active && hidden ) {
				hidden.value = value;
			}
		} );
	}

	/* — Diálogo de redirección — */

	function initRedirectDialog( wrap ) {
		var backdrop = wrap.querySelector( '.cr-dialog-backdrop' );
		if ( ! backdrop ) {
			return;
		}
		var dialog   = backdrop.querySelector( '.cr-dialog' );
		var title    = dialog.querySelector( '.cr-dialog-title' );
		var idField  = dialog.querySelector( '[name="id"]' );
		var srcField = dialog.querySelector( '[name="source"]' );
		var dstField = dialog.querySelector( '[name="destination"]' );
		var srcTypeSeg = dialog.querySelector( '.cr-seg-source-type' );
		var httpTypeSeg = dialog.querySelector( '.cr-seg-http-type' );
		var enabledField = dialog.querySelector( '[name="enabled"]' );

		function open( data ) {
			data = data || {};
			title.textContent = data.id ? 'Editar redirección' : 'Nueva redirección';
			idField.value = data.id || '';
			srcField.value = data.source || '';
			dstField.value = data.destination || '';
			if ( enabledField ) {
				enabledField.checked = undefined === data.enabled ? true : !! data.enabled;
			}
			setSegmented( srcTypeSeg, data.sourceType || 'exact' );
			setSegmented( httpTypeSeg, String( data.httpType || '301' ) );
			backdrop.classList.add( 'is-open' );
			( srcField.value ? dstField : srcField ).focus();
		}

		function close() {
			backdrop.classList.remove( 'is-open' );
		}

		wrap.querySelectorAll( '[data-cr-open-dialog]' ).forEach( function ( trigger ) {
			trigger.addEventListener( 'click', function () {
				open( {
					id: trigger.getAttribute( 'data-id' ) || '',
					source: trigger.getAttribute( 'data-source' ) || '',
					destination: trigger.getAttribute( 'data-destination' ) || '',
					sourceType: trigger.getAttribute( 'data-source-type' ) || 'exact',
					httpType: trigger.getAttribute( 'data-http-type' ) || '301',
					enabled: '0' !== trigger.getAttribute( 'data-enabled' )
				} );
			} );
		} );

		backdrop.querySelectorAll( '[data-cr-close-dialog]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', close );
		} );

		backdrop.addEventListener( 'click', function ( e ) {
			if ( e.target === backdrop ) {
				close();
			}
		} );

		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key && backdrop.classList.contains( 'is-open' ) ) {
				close();
			}
		} );

		// Cross-flow desde Monitor 404: ?prefill_source=... abre el diálogo
		// ya con el origen relleno, sin que el usuario tenga que pulsar nada.
		var params = new URLSearchParams( window.location.search );
		var prefill = params.get( 'prefill_source' );
		if ( prefill ) {
			open( { source: prefill } );
		}
	}

	/* — Toggle instantáneo (Monitor 404 → Registrar errores 404) — */

	function initInstantToggle( wrap ) {
		var container = wrap.querySelector( '[data-cr-instant-toggle]' );
		if ( ! container ) {
			return;
		}
		var toggle = container.querySelector( '.cr-toggle' );
		var input  = toggle ? toggle.querySelector( 'input' ) : null;
		var url    = container.getAttribute( 'data-action-url' );

		container.addEventListener( 'click', function () {
			if ( ! toggle ) {
				return;
			}
			var next = ! toggle.classList.contains( 'is-on' );
			toggle.classList.toggle( 'is-on', next );
			if ( input ) {
				input.checked = next;
			}
			if ( ! url ) {
				return;
			}
			var body = new URLSearchParams();
			body.set( 'action', 'cmdroom_toggle_log404' );
			body.set( 'value', next ? '1' : '0' );
			body.set( '_wpnonce', container.getAttribute( 'data-nonce' ) || '' );
			fetch( url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() } );
		} );
	}

	/* — Toggles diferidos (Limpieza) — */

	function initDeferredToggles( wrap ) {
		wrap.querySelectorAll( '[data-cr-toggle]' ).forEach( function ( toggle ) {
			var input = toggle.querySelector( 'input' );
			toggle.addEventListener( 'click', function () {
				var next = ! toggle.classList.contains( 'is-on' );
				toggle.classList.toggle( 'is-on', next );
				if ( input ) {
					input.checked = next;
				}
			} );
		} );
	}

	/* — Confirmaciones — */

	function initConfirms( wrap ) {
		wrap.querySelectorAll( '[data-cr-confirm]' ).forEach( function ( form ) {
			form.addEventListener( 'submit', function ( e ) {
				if ( ! window.confirm( form.getAttribute( 'data-cr-confirm' ) ) ) {
					e.preventDefault();
				}
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var wrap = document.querySelector( '.cmdroom-servidor-wrap' );
		if ( ! wrap ) {
			return;
		}

		initSegmented( wrap );
		initRedirectDialog( wrap );
		initInstantToggle( wrap );
		initDeferredToggles( wrap );
		initConfirms( wrap );
	} );
} )();
