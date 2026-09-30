/**
 * Command Room — pantalla "Configuración" (Archivos y taxonomías +
 * Breadcrumbs + Auto-Image SEO + Herramientas). Dos piezas de cliente:
 * vista previa en vivo de Breadcrumbs, e inserción de variables + vista
 * previa de Auto-Image SEO. Las pestañas en sí son enlaces normales
 * (recargan la página) — igual que en "Servidor".
 */
( function () {
	'use strict';

	function toggleIsOn( scope, selector ) {
		var btn = scope.querySelector( selector );
		return btn ? btn.classList.contains( 'is-on' ) : true;
	}

	/**
	 * Los botones [data-cr-toggle] (IA, Breadcrumbs, Auto-Image SEO) son
	 * type="button" con un checkbox oculto dentro -- sin JS que los conecte
	 * no hacen nada al clic. servidor-editor.js ya trae esta misma lógica
	 * (initDeferredToggles), pero solo se activa dentro de
	 * .cmdroom-servidor-wrap; aquí hace falta la misma pieza para
	 * .cmdroom-config-wrap. Bug real reportado por el administrador 2026-09-25: los
	 * toggles de llms.txt/Markdown en la pestaña IA no se podían
	 * activar/desactivar a mano.
	 */
	function initToggles( wrap ) {
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

	function initCrumbsPreview( wrap ) {
		var form = wrap.querySelector( '[data-cr-breadcrumbs-form]' );
		var preview = wrap.querySelector( '[data-cr-crumbs-preview]' );
		if ( ! form || ! preview ) {
			return;
		}

		function render() {
			var sep       = ( form.querySelector( '[data-cr-crumbs-field="sep"]' ) || {} ).value || '›';
			var homeText  = ( form.querySelector( '[data-cr-crumbs-field="home_text"]' ) || {} ).value || 'Inicio';
			var prefix    = ( form.querySelector( '[data-cr-crumbs-field="prefix"]' ) || {} ).value || '';
			var enabled   = toggleIsOn( form, '[data-cr-toggle][data-cr-crumbs-field="enabled"]' );
			var home      = toggleIsOn( form, '[data-cr-toggle][data-cr-crumbs-field="home"]' );
			var cat       = toggleIsOn( form, '[data-cr-toggle][data-cr-crumbs-field="cat"]' );

			if ( ! enabled ) {
				preview.textContent = 'Migas desactivadas';
				return;
			}

			var parts = [];
			if ( home ) {
				parts.push( homeText || 'Inicio' );
			}
			parts.push( 'Blog' );
			if ( cat ) {
				parts.push( 'SEO local' );
			}
			parts.push( 'Guía de SEO local' );

			var text = parts.join( ' ' + ( sep || '›' ) + ' ' );
			if ( prefix.trim() ) {
				text = prefix + ' ' + text;
			}
			preview.textContent = text;
		}

		form.addEventListener( 'input', render );
		form.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( '[data-cr-toggle]' ) ) {
				render();
			}
		} );
		render();
	}

	function initImageSeoPreview( wrap ) {
		var form  = wrap.querySelector( '[data-cr-imgseo-form]' );
		var preview = wrap.querySelector( '[data-cr-imgseo-preview]' );
		if ( ! form || ! preview ) {
			return;
		}

		var altInput   = form.querySelector( '[data-cr-imgseo-field="alt"]' );
		var titleInput = form.querySelector( '[data-cr-imgseo-field="title"]' );
		var lastFocused = altInput;

		[ altInput, titleInput ].forEach( function ( input ) {
			if ( input ) {
				input.addEventListener( 'focus', function () { lastFocused = input; } );
			}
		} );

		form.querySelectorAll( '[data-cr-imgseo-var]' ).forEach( function ( chip ) {
			chip.addEventListener( 'click', function () {
				var val   = chip.getAttribute( 'data-cr-imgseo-var' );
				var input = lastFocused || altInput;
				if ( ! input ) {
					return;
				}
				var start = null !== input.selectionStart ? input.selectionStart : input.value.length;
				var end   = null !== input.selectionEnd ? input.selectionEnd : input.value.length;
				input.value = input.value.slice( 0, start ) + val + input.value.slice( end );
				input.focus();
				input.selectionStart = input.selectionEnd = start + val.length;
				render();
			} );
		} );

		function applyVars( tpl ) {
			return tpl
				.split( '%image_name%' ).join( 'Zapatilla running azul' )
				.split( '%title%' ).join( 'Guía de SEO local' )
				.split( '%sitename%' ).join( 'Mi sitio' );
		}

		function render() {
			var alt   = altInput ? applyVars( altInput.value ) : '';
			var title = titleInput ? applyVars( titleInput.value ) : '';
			preview.textContent = 'alt="' + alt + '"  title="' + title + '"';
		}

		form.addEventListener( 'input', render );
		render();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var wrap = document.querySelector( '.cmdroom-config-wrap' );
		if ( ! wrap ) {
			return;
		}

		initToggles( wrap );
		initCrumbsPreview( wrap );
		initImageSeoPreview( wrap );
	} );
} )();
