/**
 * Command Room — pantalla "Variables".
 * Tabs/sub-tabs sin recarga (mismo patrón que Metas), copiar al
 * portapapeles, filtro "En uso en" con resaltado de columna, seguimiento
 * de cambios sin guardar en vivo (el guardado real sigue siendo un POST
 * normal -- un único <form> envuelve las 4 pestañas, así que nada se
 * pierde al cambiar de pestaña) y selector de imagen vía wp.media.
 */
( function () {
	'use strict';

	function initTabs( wrap ) {
		var tabs   = wrap.querySelectorAll( '.cmdroom-md-tab' );
		var panels = wrap.querySelectorAll( '.cmdroom-md-panel' );

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				var target = tab.getAttribute( 'data-tab' );
				tabs.forEach( function ( t ) { t.classList.toggle( 'is-active', t === tab ); } );
				panels.forEach( function ( panel ) { panel.hidden = panel.getAttribute( 'data-tab' ) !== target; } );
				resetContextFilter( wrap );
			} );
		} );
	}

	function initSubtabs( wrap ) {
		wrap.querySelectorAll( '.cr-vars-subtab' ).forEach( function ( sub ) {
			sub.addEventListener( 'click', function () {
				wrap.querySelectorAll( '.cr-vars-subtab' ).forEach( function ( s ) {
					s.classList.toggle( 'is-active', s === sub );
				} );
			} );
		} );
	}

	function initCopy( wrap ) {
		wrap.querySelectorAll( '[data-cr-copy]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var text = btn.getAttribute( 'data-cr-copy' );
				var done = function () {
					btn.classList.add( 'is-copied' );
					window.clearTimeout( btn._crCopyTimer );
					btn._crCopyTimer = window.setTimeout( function () {
						btn.classList.remove( 'is-copied' );
					}, 1400 );
				};
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( text ).then( done );
				} else {
					var tmp = document.createElement( 'textarea' );
					tmp.value = text;
					document.body.appendChild( tmp );
					tmp.select();
					document.execCommand( 'copy' );
					document.body.removeChild( tmp );
					done();
				}
			} );
		} );
	}

	function activePanel( wrap ) {
		return wrap.querySelector( '.cmdroom-md-panel:not([hidden])' );
	}

	function resetContextFilter( wrap ) {
		wrap.querySelectorAll( '.cr-vars-ctx-seg .cr-seg-opt' ).forEach( function ( b ) {
			b.classList.remove( 'is-active' );
		} );
		applyContextFilter( wrap, null );
	}

	/**
	 * Recorre los hijos directos de .cr-vars-grid (cabecera / cabeceras de
	 * grupo / filas, todos hermanos, sin anidar) para poder ocultar un
	 * grupo entero cuando ninguna de sus filas pasa el filtro.
	 */
	function applyContextFilter( wrap, ctx ) {
		var panel = activePanel( wrap );
		if ( ! panel ) {
			return;
		}

		panel.querySelectorAll( '.cr-vars-ctx-head, .cr-vars-ctx-cell' ).forEach( function ( cell ) {
			cell.classList.toggle( 'is-filtered', !! ctx && cell.getAttribute( 'data-ctx-col' ) === ctx );
		} );

		var anyVisible = false;

		panel.querySelectorAll( '.cr-vars-grid' ).forEach( function ( grid ) {
			var currentGroupEl    = null;
			var currentGroupCount = 0;

			Array.prototype.forEach.call( grid.children, function ( el ) {
				if ( el.classList.contains( 'cr-vars-head' ) ) {
					return;
				}
				if ( el.classList.contains( 'cr-vars-group-head' ) ) {
					if ( currentGroupEl ) {
						currentGroupEl.hidden = 0 === currentGroupCount;
					}
					currentGroupEl    = el;
					currentGroupCount = 0;
					return;
				}
				var contexts = ( el.getAttribute( 'data-contexts' ) || '' ).split( ' ' );
				var visible  = ! ctx || contexts.indexOf( ctx ) !== -1;
				el.hidden    = ! visible;
				if ( visible ) {
					currentGroupCount++;
					anyVisible = true;
				}
			} );
			if ( currentGroupEl ) {
				currentGroupEl.hidden = 0 === currentGroupCount;
			}
		} );

		var emptyEl = panel.querySelector( '.cr-vars-empty' );
		if ( emptyEl ) {
			emptyEl.hidden = ! ctx || anyVisible;
		}
	}

	function initContextFilter( wrap ) {
		wrap.querySelectorAll( '.cr-vars-ctx-seg' ).forEach( function ( seg ) {
			seg.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest( '.cr-seg-opt' );
				if ( ! btn ) {
					return;
				}
				var ctx       = btn.getAttribute( 'data-ctx' );
				var wasActive = btn.classList.contains( 'is-active' );
				seg.querySelectorAll( '.cr-seg-opt' ).forEach( function ( b ) { b.classList.remove( 'is-active' ); } );
				if ( ! wasActive ) {
					btn.classList.add( 'is-active' );
				}
				applyContextFilter( wrap, wasActive ? null : ctx );
			} );
		} );

		wrap.querySelectorAll( '[data-cr-vars-clear]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				resetContextFilter( wrap );
			} );
		} );
	}

	// ---- Cambios sin guardar ----

	function fieldInput( field ) {
		return field.querySelector( '[data-cr-vars-input]' );
	}

	function fieldIsDirty( field ) {
		var input = fieldInput( field );
		if ( ! input ) {
			return false;
		}
		var saved = input.getAttribute( 'data-cr-vars-saved' );
		return null !== saved && input.value !== saved;
	}

	function updateFieldState( field ) {
		var input = fieldInput( field );
		if ( ! input ) {
			return;
		}
		var dirty = fieldIsDirty( field );
		field.classList.toggle( 'is-dirty', dirty );

		var helpText = field.querySelector( '[data-cr-vars-help-text]' );
		if ( helpText ) {
			if ( dirty ) {
				if ( ! helpText.hasAttribute( 'data-cr-vars-original' ) ) {
					helpText.setAttribute( 'data-cr-vars-original', helpText.textContent );
				}
				helpText.textContent = 'Sin guardar.';
			} else if ( helpText.hasAttribute( 'data-cr-vars-original' ) ) {
				helpText.textContent = helpText.getAttribute( 'data-cr-vars-original' );
			}
		}

		var resetBtn = field.querySelector( '[data-cr-vars-reset]' );
		if ( resetBtn ) {
			var defaultVal = field.getAttribute( 'data-default' );
			resetBtn.hidden = null === defaultVal || input.value === defaultVal;
		}
	}

	function updateSaveBar( wrap ) {
		var dirtyCount = 0;
		wrap.querySelectorAll( '[data-cr-vars-field]' ).forEach( function ( field ) {
			if ( fieldIsDirty( field ) ) {
				dirtyCount++;
			}
		} );

		// El toolbar (estado + botón Guardar) se repite en cada pestaña que
		// lo tenga (Metas/Datos estructurados/Open Graph) -- las tres
		// instancias comparten el mismo <form>, así que se actualizan todas
		// a la vez para que el estado sea consistente se mire desde donde
		// se mire.
		var text = dirtyCount > 0
			? dirtyCount + ' ' + ( 1 === dirtyCount ? 'valor sin guardar' : 'valores sin guardar' )
			: '';

		wrap.querySelectorAll( '[data-cr-vars-status]' ).forEach( function ( statusEl ) {
			statusEl.classList.toggle( 'is-dirty', dirtyCount > 0 );
			statusEl.textContent = text;
		} );
		wrap.querySelectorAll( '[data-cr-vars-submit]' ).forEach( function ( btn ) {
			btn.disabled = 0 === dirtyCount;
		} );
	}

	function initDirtyTracking( wrap ) {
		wrap.querySelectorAll( '[data-cr-vars-field]' ).forEach( updateFieldState );

		function onFieldEvent( e ) {
			var input = e.target.closest ? e.target.closest( '[data-cr-vars-input]' ) : null;
			if ( ! input ) {
				return;
			}
			var field = input.closest( '[data-cr-vars-field]' );
			if ( field ) {
				updateFieldState( field );
			}
			updateSaveBar( wrap );
		}

		wrap.addEventListener( 'input', onFieldEvent );
		wrap.addEventListener( 'change', onFieldEvent );

		wrap.addEventListener( 'click', function ( e ) {
			var resetBtn = e.target.closest( '[data-cr-vars-reset]' );
			if ( ! resetBtn ) {
				return;
			}
			var field       = resetBtn.closest( '[data-cr-vars-field]' );
			var input       = fieldInput( field );
			var defaultVal  = field.getAttribute( 'data-default' );
			if ( input && null !== defaultVal ) {
				input.value = defaultVal;
				input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				input.focus();
			}
		} );

		updateSaveBar( wrap );
	}

	// ---- Imagen (favicon / imagen de respaldo) vía wp.media ----

	function initImagePickers( wrap ) {
		wrap.querySelectorAll( '[data-cr-vars-image-pick]' ).forEach( function ( btn ) {
			var box       = btn.closest( '.cr-vars-image-box' );
			var input     = box.querySelector( '[data-cr-vars-image-id]' );
			var thumb     = box.querySelector( '[data-cr-vars-thumb]' );
			var nameEl    = box.querySelector( '[data-cr-vars-filename]' );
			var removeBtn = box.querySelector( '[data-cr-vars-image-remove]' );
			var frame;

			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				if ( ! window.wp || ! window.wp.media ) {
					return;
				}
				if ( frame ) {
					frame.open();
					return;
				}
				frame = window.wp.media( {
					title: btn.getAttribute( 'data-frame-title' ) || '',
					multiple: false,
					library: { type: 'image' }
				} );
				frame.on( 'select', function () {
					var attachment = frame.state().get( 'selection' ).first().toJSON();
					input.value = attachment.id;
					thumb.innerHTML = '';
					var src = ( attachment.sizes && attachment.sizes.thumbnail ) ? attachment.sizes.thumbnail.url : attachment.url;
					if ( src ) {
						var img = document.createElement( 'img' );
						img.src = src;
						img.alt = '';
						thumb.appendChild( img );
					}
					if ( nameEl ) {
						nameEl.textContent = attachment.filename || attachment.title || '';
					}
					btn.textContent = 'Cambiar';
					if ( removeBtn ) {
						removeBtn.hidden = false;
					}
					input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				} );
				frame.open();
			} );

			if ( removeBtn ) {
				removeBtn.addEventListener( 'click', function () {
					input.value = '0';
					thumb.innerHTML = '';
					if ( nameEl ) {
						nameEl.textContent = '(usa el valor por defecto)';
					}
					btn.textContent = 'Elegir';
					removeBtn.hidden = true;
					input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				} );
			}
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var wrap = document.querySelector( '.cmdroom-variables-wrap' );
		if ( ! wrap ) {
			return;
		}
		initTabs( wrap );
		initSubtabs( wrap );
		initCopy( wrap );
		initContextFilter( wrap );
		initDirtyTracking( wrap );
		initImagePickers( wrap );
	} );
} )();
