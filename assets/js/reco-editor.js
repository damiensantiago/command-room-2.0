/**
 * Command Room — Carrusel de recomendación de contenidos (admin). Los
 * segmentados genéricos (.cr-seg) y los toggles diferidos (data-cr-toggle)
 * ya los cubre servidor-editor.js (esta pantalla también lleva la clase
 * .cmdroom-servidor-wrap) -- este script solo añade lo propio del
 * componente: el toggle instantáneo de activación, mostrar/ocultar el
 * formulario de invitar web amiga, las categorías compartidas (chips) y el
 * reflejo en vivo del título en la vista previa.
 */
( function () {
	'use strict';

	/* — Toggle instantáneo "Activar carrusel en el sitio" — */

	function initToggleEnabled( wrap ) {
		var container = wrap.querySelector( '[data-cr-reco-toggle-enabled]' );
		if ( ! container || 'undefined' === typeof cmdroomReco ) {
			return;
		}
		var toggle = container.querySelector( '.cr-toggle' );
		var input  = toggle ? toggle.querySelector( 'input' ) : null;
		var note   = container.querySelector( '[data-cr-reco-activation-note]' );

		container.addEventListener( 'click', function () {
			var next = ! toggle.classList.contains( 'is-on' );
			toggle.classList.toggle( 'is-on', next );
			if ( input ) {
				input.checked = next;
			}
			if ( note ) {
				note.textContent = next
					? 'Se imprime según la ubicación configurada.'
					: 'Mientras esté apagado no se imprime nada.';
			}
			var body = new URLSearchParams();
			body.set( 'action', 'cmdroom_reco_toggle_enabled' );
			body.set( 'value', next ? '1' : '0' );
			body.set( '_wpnonce', container.getAttribute( 'data-nonce' ) || '' );
			fetch( cmdroomReco.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} );
		} );
	}

	/* — D. Mostrar/ocultar el formulario "+ Invitar web" — */

	function initInviteToggle( wrap ) {
		var btn  = wrap.querySelector( '[data-cr-reco-invite-toggle]' );
		var form = wrap.querySelector( '[data-cr-reco-invite-form]' );
		if ( ! btn || ! form ) {
			return;
		}
		btn.addEventListener( 'click', function () {
			form.hidden = ! form.hidden;
			if ( ! form.hidden ) {
				var input = form.querySelector( 'input[name="invite_domain"]' );
				if ( input ) {
					input.focus();
				}
			}
		} );
	}

	/* — D. Categorías compartidas (chips) — */

	function initCatPicker( wrap ) {
		var picker = wrap.querySelector( '[data-cr-reco-cat-picker]' );
		var hiddenInput = wrap.querySelector( '[data-cr-reco-shared-input]' );
		if ( ! picker || ! hiddenInput ) {
			return;
		}
		picker.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.cmdroom-aff-cat-btn' );
			if ( ! btn ) {
				return;
			}
			btn.classList.toggle( 'is-active' );
			var ids = Array.prototype.slice.call( picker.querySelectorAll( '.is-active' ) ).map( function ( b ) {
				return b.getAttribute( 'data-term' );
			} );
			hiddenInput.value = ids.join( ',' );
		} );
	}

	/* — E. Reflejo en vivo del título en la vista previa — */

	function initTitleLive( wrap ) {
		var input = wrap.querySelector( '[data-cr-reco-title-input]' );
		var preview = wrap.querySelector( '[data-cr-reco-preview] .cr-reco-title' );
		if ( ! input || ! preview ) {
			return;
		}
		input.addEventListener( 'input', function () {
			preview.textContent = input.value || 'También te puede interesar';
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var wrap = document.querySelector( '.cmdroom-components-wrap' );
		if ( ! wrap ) {
			return;
		}
		initToggleEnabled( wrap );
		initInviteToggle( wrap );
		initCatPicker( wrap );
		initTitleLive( wrap );
	} );
} )();
