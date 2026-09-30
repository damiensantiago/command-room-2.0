/**
 * Command Room — Sitemap HTML (admin). Los segmentados genéricos (.cr-seg)
 * y los toggles diferidos (data-cr-toggle) ya los cubre servidor-editor.js
 * (esta pantalla también lleva la clase .cmdroom-servidor-wrap) -- este
 * script cubre lo propio del componente:
 *   1. Toggle instantáneo "Activar sitemap HTML" (AJAX, fuera del <form>).
 *   2. Navegación interactiva de la vista previa (niveles/migas/celdas/
 *      paginación) por AJAX, sin recargar la página y sin tocar ninguna
 *      URL pública -- todo ocurre dentro de #cmdroom-htmlmap-preview-root.
 *   3. Scroll de los carruseles (30 días / top tags).
 *   4. Chips de "Tipos de contenido incluidos" (checkbox oculto + pill).
 *
 * Delegación de eventos: los clics de navegación se escuchan en el
 * contenedor `[data-cr-htmlmap-preview]` (que nunca se reemplaza entero),
 * no en los nodos internos (que sí se reemplazan en cada respuesta AJAX).
 */
( function () {
	'use strict';

	/* — 1. Toggle instantáneo "Activar sitemap HTML" — */

	function initToggleEnabled( wrap ) {
		var container = wrap.querySelector( '[data-cr-htmlmap-toggle-enabled]' );
		if ( ! container || 'undefined' === typeof cmdroomHtmlmap ) {
			return;
		}
		var toggle = container.querySelector( '.cr-toggle' );
		var input  = toggle ? toggle.querySelector( 'input' ) : null;

		container.addEventListener( 'click', function () {
			var next = ! toggle.classList.contains( 'is-on' );
			toggle.classList.toggle( 'is-on', next );
			if ( input ) {
				input.checked = next;
			}
			var body = new URLSearchParams();
			body.set( 'action', 'cmdroom_htmlmap_toggle_enabled' );
			body.set( 'value', next ? '1' : '0' );
			body.set( '_wpnonce', container.getAttribute( 'data-nonce' ) || '' );
			fetch( cmdroomHtmlmap.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} ).then( function () {
				// Refresca la vista previa para reflejar la franja "Desactivado".
				var previewWrap = wrap.querySelector( '[data-cr-htmlmap-preview]' );
				if ( previewWrap ) {
					navigateFromRoot( previewWrap, {} );
				}
			} );
		} );
	}

	/* — 2. Navegación interactiva de la vista previa — */

	function currentState( previewWrap ) {
		var root = previewWrap.querySelector( '#cmdroom-htmlmap-preview-root' );
		if ( ! root ) {
			return { view: 'home', y: '', m: '', d: '', page: '1' };
		}
		return {
			view: root.getAttribute( 'data-view' ) || 'home',
			y: root.getAttribute( 'data-y' ) || '',
			m: root.getAttribute( 'data-m' ) || '',
			d: root.getAttribute( 'data-d' ) || '',
			page: root.getAttribute( 'data-page' ) || '1',
		};
	}

	function navigateFromRoot( previewWrap, overrides ) {
		if ( 'undefined' === typeof cmdroomHtmlmap ) {
			return;
		}
		var state = currentState( previewWrap );
		var target = Object.assign( {}, state, overrides );

		var body = new URLSearchParams();
		body.set( 'action', 'cmdroom_htmlmap_preview' );
		body.set( 'view', target.view );
		body.set( 'y', target.y );
		body.set( 'm', target.m );
		body.set( 'd', target.d );
		body.set( 'page', target.page || '1' );
		body.set( '_wpnonce', previewWrap.getAttribute( 'data-nonce' ) || '' );

		previewWrap.classList.add( 'is-loading' );

		fetch( cmdroomHtmlmap.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} )
			.then( function ( res ) { return res.json(); } )
			.then( function ( json ) {
				if ( json && json.success && json.data && json.data.html ) {
					previewWrap.innerHTML = json.data.html;
				}
			} )
			.finally( function () {
				previewWrap.classList.remove( 'is-loading' );
			} );
	}

	function initPreviewNav( wrap ) {
		var previewWrap = wrap.querySelector( '[data-cr-htmlmap-preview]' );
		if ( ! previewWrap ) {
			return;
		}

		previewWrap.addEventListener( 'click', function ( e ) {
			var navEl = e.target.closest( '[data-cr-htmlmap-nav]' );
			if ( navEl && previewWrap.contains( navEl ) ) {
				e.preventDefault();
				navigateFromRoot( previewWrap, {
					view: navEl.getAttribute( 'data-view' ) || 'home',
					y: navEl.getAttribute( 'data-y' ) || '',
					m: navEl.getAttribute( 'data-m' ) || '',
					d: navEl.getAttribute( 'data-d' ) || '',
					page: navEl.getAttribute( 'data-page' ) || '1',
				} );
				return;
			}

			/* — 3. Flechas de carrusel (desplazan la pista, no navegan) — */
			var arrow = e.target.closest( '[data-cr-htmlmap-car]' );
			if ( arrow && previewWrap.contains( arrow ) ) {
				var track = arrow.parentElement.querySelector( '.cmdroom-htmlmap-car-track' );
				if ( track ) {
					var dir = 'prev' === arrow.getAttribute( 'data-cr-htmlmap-car' ) ? -1 : 1;
					track.scrollBy( { left: dir * track.clientWidth * 0.8, behavior: 'smooth' } );
				}
			}
		} );
	}

	/* — 4. Chips "Tipos de contenido incluidos" — */

	function initPostTypePicker( wrap ) {
		var picker = wrap.querySelector( '[data-cr-htmlmap-pt-picker]' );
		if ( ! picker ) {
			return;
		}
		picker.querySelectorAll( '[data-checkbox]' ).forEach( function ( btn ) {
			var key   = btn.getAttribute( 'data-checkbox' );
			var input = picker.querySelector( '[data-checkbox-input="' + key + '"]' );
			btn.addEventListener( 'click', function () {
				var next = ! btn.classList.contains( 'is-active' );
				btn.classList.toggle( 'is-active', next );
				if ( input ) {
					input.checked = next;
				}
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var wrap = document.querySelector( '.cmdroom-htmlmap-screen' );
		if ( ! wrap ) {
			return;
		}
		initToggleEnabled( wrap );
		initPreviewNav( wrap );
		initPostTypePicker( wrap );
	} );
} )();
