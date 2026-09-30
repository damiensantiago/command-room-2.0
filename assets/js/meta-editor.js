/**
 * Command Room — pantalla "Meta data".
 * Tabs sin recarga de página + editor de bloque <head> con resaltado de
 * variables (%algo%) en vivo, respaldado por un <textarea> oculto real que
 * es el que efectivamente viaja en el POST de guardado.
 */
( function () {
	'use strict';

	var VAR_PATTERN = /(%[a-z_]+%)/gi;

	function escapeHtml( str ) {
		return str
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	}

	function highlight( raw ) {
		var escaped = escapeHtml( raw );
		return escaped.replace( VAR_PATTERN, '<span class="cmdroom-md-var">$1</span>' );
	}

	/**
	 * Lee el texto plano real de un editor contenteditable reconstruyendo
	 * los saltos de línea a mano -- .textContent NO basta: cuando el
	 * navegador decide envolver una línea en su propio <div>/<p> en vez de
	 * pasar por nuestro execCommand('insertText', '\n') del keydown de
	 * Enter (p. ej. al editar/pegar dentro de una línea ya existente),
	 * .textContent concatena esos bloques sin ningún separador y el bloque
	 * entero acaba guardándose como una sola fila -- bug real reportado por
	 * el administrador el 2026-09-24 al corregir un valor y darle a Guardar.
	 */
	function extractText( el ) {
		var lines   = [];
		var current = '';

		function walk( node ) {
			if ( node.nodeType === Node.TEXT_NODE ) {
				current += node.nodeValue;
				return;
			}
			if ( node.nodeType !== Node.ELEMENT_NODE ) {
				return;
			}
			if ( 'BR' === node.nodeName ) {
				lines.push( current );
				current = '';
				return;
			}
			var isBlock = 'DIV' === node.nodeName || 'P' === node.nodeName;
			if ( isBlock && ( lines.length || current ) ) {
				lines.push( current );
				current = '';
			}
			node.childNodes.forEach( walk );
			if ( isBlock ) {
				lines.push( current );
				current = '';
			}
		}

		el.childNodes.forEach( walk );
		lines.push( current );
		return lines.join( '\n' );
	}

	function getCaretOffset( el ) {
		var sel = window.getSelection();
		if ( ! sel || sel.rangeCount === 0 ) {
			return 0;
		}
		var range = sel.getRangeAt( 0 );
		var preRange = range.cloneRange();
		preRange.selectNodeContents( el );
		preRange.setEnd( range.endContainer, range.endOffset );
		return preRange.toString().length;
	}

	function setCaretOffset( el, offset ) {
		var range = document.createRange();
		var sel = window.getSelection();
		var walker = document.createTreeWalker( el, NodeFilter.SHOW_TEXT );
		var node;
		var current = 0;
		var found = false;

		while ( ( node = walker.nextNode() ) ) {
			var next = current + node.length;
			if ( offset <= next ) {
				range.setStart( node, offset - current );
				range.collapse( true );
				found = true;
				break;
			}
			current = next;
		}

		if ( ! found ) {
			range.selectNodeContents( el );
			range.collapse( false );
		}

		sel.removeAllRanges();
		sel.addRange( range );
	}

	function initEditor( editor ) {
		var textarea = editor.nextElementSibling;
		if ( ! textarea || 'TEXTAREA' !== textarea.tagName ) {
			return;
		}

		editor.addEventListener( 'input', function () {
			var offset = getCaretOffset( editor );
			var raw = extractText( editor );
			editor.innerHTML = highlight( raw );
			setCaretOffset( editor, offset );
			textarea.value = raw;
		} );

		editor.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key ) {
				e.preventDefault();
				document.execCommand( 'insertText', false, '\n' );
			}
		} );

		editor.addEventListener( 'paste', function ( e ) {
			e.preventDefault();
			var text = ( e.clipboardData || window.clipboardData ).getData( 'text/plain' );
			document.execCommand( 'insertText', false, text );
		} );
	}

	function initTabs( wrap ) {
		var tabs = wrap.querySelectorAll( '.cmdroom-md-tab' );
		var panels = wrap.querySelectorAll( '.cmdroom-md-panel' );

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var target = tab.getAttribute( 'data-tab' );

				tabs.forEach( function ( t ) {
					t.classList.toggle( 'is-active', t === tab );
				} );
				panels.forEach( function ( panel ) {
					panel.hidden = panel.getAttribute( 'data-tab' ) !== target;
				} );

				if ( window.history && window.history.replaceState ) {
					var url = new URL( window.location.href );
					url.searchParams.set( 'tab', target );
					window.history.replaceState( null, '', url );
				}
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var wrap = document.querySelector( '.cmdroom-metadata-wrap' );
		if ( ! wrap ) {
			return;
		}

		initTabs( wrap );

		wrap.querySelectorAll( '.cmdroom-md-code' ).forEach( initEditor );
	} );
} )();
