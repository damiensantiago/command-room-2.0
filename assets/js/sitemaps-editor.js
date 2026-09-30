/**
 * Command Room — pantalla "Sitemaps".
 * Dos cosas: pestañas Configuración/Avanzado (como el resto de pantallas
 * rediseñadas) y el panel "Variables" por fila de la tabla de definiciones,
 * que abre/cierra como acordeón -- solo uno a la vez, igual que en el
 * prototipo de Claude Design.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var wrap = document.querySelector( '.cmdroom-sitemaps-wrap' );
		if ( ! wrap ) {
			return;
		}

		// Pestañas: puede haber más de un contenedor con el mismo data-tab
		// (Avanzado reparte su contenido entre el <form> de guardado y el
		// <form> propio del botón de ping, que no pueden anidarse) -- se
		// alternan todos a la vez.
		var tabButtons = wrap.querySelectorAll( '.cmdroom-md-tabs .cmdroom-md-tab' );
		var panels     = wrap.querySelectorAll( '.cmdroom-sitemaps-panel' );

		tabButtons.forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var target = btn.getAttribute( 'data-tab' );
				tabButtons.forEach( function ( b ) {
					b.classList.toggle( 'is-active', b === btn );
				} );
				panels.forEach( function ( panel ) {
					panel.hidden = panel.getAttribute( 'data-tab' ) !== target;
				} );
			} );
		} );

		// Panel Variables: acordeón de una fila a la vez.
		var toggles = wrap.querySelectorAll( '.cmdroom-sitemaps-vars-toggle' );
		toggles.forEach( function ( toggle ) {
			toggle.addEventListener( 'click', function () {
				var row    = toggle.getAttribute( 'data-row' );
				var target = wrap.querySelector( '.cmdroom-sitemaps-vars-row[data-row="' + row + '"]' );
				if ( ! target ) {
					return;
				}
				var willOpen = target.hidden;

				wrap.querySelectorAll( '.cmdroom-sitemaps-vars-row' ).forEach( function ( r ) {
					r.hidden = true;
				} );
				toggles.forEach( function ( t ) {
					t.textContent = 'Configurar';
				} );

				if ( willOpen ) {
					target.hidden = false;
					toggle.textContent = 'Cerrar';
				}
			} );
		} );
	} );
} )();
