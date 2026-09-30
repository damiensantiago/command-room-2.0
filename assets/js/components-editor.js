/**
 * Command Room — pantalla "Componentes". Tres piezas de cliente:
 * 1. "Ver todos": el interruptor guarda al instante por AJAX y actualiza la
 *    pestaña correspondiente (aparece/desaparece) y el resumen "N de 13"
 *    sin recargar -- el resto de la pantalla (pestañas de componente,
 *    subpestañas del Ticker) son enlaces normales que sí recargan.
 * 2. Filas de mensaje del Ticker (Configurado): añadir/quitar sin JS de
 *    índices -- los campos van en arrays paralelos msg_text[]/msg_url[],
 *    así que una fila nueva no necesita saber su posición.
 * 3. Vista previa del Ticker en vivo: se recalcula en el navegador según lo
 *    que haya en el formulario en ESE momento (sin guardar), reutilizando
 *    los datos en bruto que el PHP deja embebidos en
 *    #cmdroom-ticker-preview-data -- ver Cmdroom_Ticker_Resolver::apply_vars()/
 *    resolve_mixed() en PHP, que este módulo replica en JS para Automático y
 *    Mixto. Configurado no necesita datos embebidos: lee los campos de texto
 *    directamente.
 */
( function () {
	'use strict';

	function initComponentToggles( wrap ) {
		if ( 'undefined' === typeof cmdroomComponents ) {
			return;
		}
		wrap.querySelectorAll( '[data-cr-component-toggle]' ).forEach( function ( container ) {
			var toggle = container.querySelector( '.cr-toggle' );
			var input  = toggle ? toggle.querySelector( 'input' ) : null;
			var key    = container.getAttribute( 'data-key' );

			container.addEventListener( 'click', function () {
				if ( ! toggle || ! key ) {
					return;
				}
				var next = ! toggle.classList.contains( 'is-on' );
				toggle.classList.toggle( 'is-on', next );
				if ( input ) {
					input.checked = next;
				}

				var tab = wrap.querySelector( '[data-cr-component-tab="' + key + '"]' );
				if ( tab ) {
					tab.hidden = ! next;
				}
				var row = container.closest( '.cmdroom-components-all-row' );
				var configureBtn = row ? row.querySelector( '.cmdroom-components-all-configure' ) : null;
				if ( configureBtn ) {
					configureBtn.hidden = ! next;
				}

				var summary = wrap.querySelector( '[data-cr-components-summary]' );
				if ( summary ) {
					var count = wrap.querySelectorAll( '[data-cr-component-toggle] .cr-toggle.is-on' ).length;
					var total = wrap.querySelectorAll( '[data-cr-component-toggle]' ).length;
					summary.textContent = count + ' de ' + total + ' componentes activos.';
				}

				var body = new URLSearchParams();
				body.set( 'action', 'cmdroom_toggle_component' );
				body.set( 'key', key );
				body.set( 'value', next ? '1' : '0' );
				body.set( '_wpnonce', cmdroomComponents.nonce );
				fetch( cmdroomComponents.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString(),
				} );
			} );
		} );
	}

	/* — Filas de mensaje (Configurado) — */

	function initTickerMessageRows( wrap, onChange ) {
		var grid = wrap.querySelector( '[data-cr-ticker-messages]' );
		var addBtn = wrap.querySelector( '[data-cr-ticker-add-row]' );
		if ( ! grid || ! addBtn ) {
			return;
		}

		function addRow() {
			var row = document.createElement( 'div' );
			row.className = 'cmdroom-ticker-msg-row';
			row.innerHTML =
				'<span class="cmdroom-ticker-msg-num"></span>' +
				'<input type="text" class="cr-input" name="msg_text[]" placeholder="Envío gratis desde 50 €" />' +
				'<input type="text" class="cr-input" name="msg_url[]" placeholder="/envios/" />' +
				'<button type="button" class="cr-btn-secondary cmdroom-ticker-msg-remove" data-cr-ticker-remove-row>×</button>';
			grid.appendChild( row );
			renumber();
			var firstInput = row.querySelector( 'input' );
			if ( firstInput ) {
				firstInput.focus();
			}
			if ( onChange ) {
				onChange();
			}
		}

		function renumber() {
			grid.querySelectorAll( '.cmdroom-ticker-msg-row' ).forEach( function ( row, i ) {
				var num = row.querySelector( '.cmdroom-ticker-msg-num' );
				if ( num ) {
					num.textContent = 'Mensaje ' + ( i + 1 );
				}
			} );
		}

		addBtn.addEventListener( 'click', addRow );

		grid.addEventListener( 'click', function ( e ) {
			var removeBtn = e.target.closest( '[data-cr-ticker-remove-row]' );
			if ( ! removeBtn ) {
				return;
			}
			var row = removeBtn.closest( '.cmdroom-ticker-msg-row' );
			if ( row ) {
				row.remove();
				renumber();
				if ( onChange ) {
					onChange();
				}
			}
		} );

		renumber();
	}

	/* — Vista previa en vivo — */

	function escapeHtml( str ) {
		var div = document.createElement( 'div' );
		div.textContent = String( null == str ? '' : str );
		return div.innerHTML;
	}

	function escapeAttr( str ) {
		return escapeHtml( str ).split( '"' ).join( '&quot;' );
	}

	function applyVars( tpl, vars ) {
		var out = String( tpl || '' );
		Object.keys( vars || {} ).forEach( function ( key ) {
			out = out.split( key ).join( vars[ key ] );
		} );
		return out;
	}

	var SEC_PER_MSG = { slow: 9, normal: 6, fast: 3.5 };

	function buildBarHTML( messages, style, emptyText ) {
		var bg = style.bg || '#201e1d';
		var fg = style.fg || '#ffffff';
		if ( ! messages.length ) {
			return (
				'<div class="cmdroom-ticker" style="--cmdroom-ticker-bg:' + bg + ';--cmdroom-ticker-color:' + fg + ';">' +
					'<span class="cmdroom-ticker-empty">' + escapeHtml( emptyText ) + '</span>' +
				'</div>'
			);
		}
		var secPerMsg = SEC_PER_MSG[ style.speed ] || SEC_PER_MSG.normal;
		var duration  = Math.max( 8, messages.length * secPerMsg );
		var pauseClass = style.pauseOnHover ? ' cmdroom-ticker--pauseable' : '';

		function pass( hidden ) {
			var items = messages.map( function ( m, i ) {
				var sep = i > 0 ? '<span class="cmdroom-ticker-sep" aria-hidden="true">' + escapeHtml( style.separator ) + '</span>' : '';
				var inner = m.url
					? '<a href="' + escapeAttr( m.url ) + '">' + escapeHtml( m.text ) + '</a>'
					: escapeHtml( m.text );
				return sep + '<span class="cmdroom-ticker-item">' + inner + '</span>';
			} ).join( '' );
			return '<div class="cmdroom-ticker-content"' + ( hidden ? ' aria-hidden="true"' : '' ) + '>' + items + '</div>';
		}

		var styleAttr = '--cmdroom-ticker-bg:' + bg + ';--cmdroom-ticker-color:' + fg + ';--cmdroom-ticker-duration:' + duration + 's;';
		return (
			'<div class="cmdroom-ticker' + pauseClass + '" style="' + styleAttr + '" role="marquee" aria-live="off">' +
				'<div class="cmdroom-ticker-track">' + pass( false ) + pass( true ) + '</div>' +
			'</div>'
		);
	}

	/** Misma forma que Cmdroom_Ticker_Resolver::resolve_mixed() en PHP. */
	function resolveMixedLive( fixed, auto, order, ratio, max ) {
		ratio = Math.max( 1, ratio || 1 );
		max   = Math.max( 1, max || 1 );

		function tag( list, source ) {
			return list.map( function ( m ) {
				return { text: m.text, url: m.url, source: source };
			} );
		}

		var out;
		if ( 'fixed' === order ) {
			out = tag( fixed, 'fixed' ).concat( tag( auto, 'auto' ) );
		} else if ( 'auto' === order ) {
			out = tag( auto, 'auto' ).concat( tag( fixed, 'fixed' ) );
		} else {
			out = [];
			var fi = 0;
			var ai = 0;
			while ( fi < fixed.length || ai < auto.length ) {
				if ( fi < fixed.length ) {
					out.push( { text: fixed[ fi ].text, url: fixed[ fi ].url, source: 'fixed' } );
					fi++;
				}
				for ( var k = 0; k < ratio && ai < auto.length; k++ ) {
					out.push( { text: auto[ ai ].text, url: auto[ ai ].url, source: 'auto' } );
					ai++;
				}
			}
		}
		return out.slice( 0, max );
	}

	/** Misma forma que Cmdroom_Ticker_Resolver::resolve_auto() en PHP, sobre el pool en bruto embebido. */
	function resolveAutoLive( raw, form ) {
		var days = parseInt( ( form.querySelector( '[name="days"]' ) || {} ).value, 10 ) || 7;
		var max  = parseInt( ( form.querySelector( '[name="max_per_source"]' ) || {} ).value, 10 ) || 3;
		var cutoff = ( Date.now() / 1000 ) - ( days * 86400 );
		var out = [];

		[ 'posts', 'sale', 'shipping', 'coupons' ].forEach( function ( key ) {
			var onInput  = form.querySelector( '[name="src_' + key + '_on"]' );
			var tplInput = form.querySelector( '[name="src_' + key + '_tpl"]' );
			if ( ! onInput || ! onInput.checked ) {
				return;
			}
			var tpl  = tplInput ? tplInput.value : '';
			var pool = ( raw[ key ] || [] ).slice();
			if ( 'posts' === key ) {
				pool = pool.filter( function ( item ) {
					return item.ts >= cutoff;
				} );
			}
			pool.slice( 0, max ).forEach( function ( item ) {
				out.push( { text: applyVars( tpl, item.vars ), url: item.url } );
			} );
		} );

		return out;
	}

	function readManualMessages( grid ) {
		var texts = grid.querySelectorAll( '[name="msg_text[]"]' );
		var urls  = grid.querySelectorAll( '[name="msg_url[]"]' );
		var out   = [];
		texts.forEach( function ( input, i ) {
			var text = ( input.value || '' ).trim();
			if ( '' === text ) {
				return;
			}
			out.push( { text: text, url: urls[ i ] ? urls[ i ].value.trim() : '' } );
		} );
		return out;
	}

	function initTickerPreview( wrap ) {
		var previewEl = wrap.querySelector( '[data-cr-ticker-preview]' );
		var form      = wrap.querySelector( '[data-cr-ticker-form]' );
		if ( ! previewEl || ! form ) {
			return null;
		}

		var view        = previewEl.getAttribute( 'data-view' );
		var modeLabel   = previewEl.getAttribute( 'data-mode-label' );
		var dayLabel    = previewEl.getAttribute( 'data-day-label' );
		var emptyText   = previewEl.getAttribute( 'data-empty-text' );
		var caption     = previewEl.querySelector( '[data-cr-ticker-caption]' );
		var slotBelow   = previewEl.querySelector( '[data-cr-ticker-bar-slot="below_menu"]' );
		var slotBottom  = previewEl.querySelector( '[data-cr-ticker-bar-slot="bottom"]' );
		var msgGrid     = wrap.querySelector( '[data-cr-ticker-messages]' );

		var dataScript = document.getElementById( 'cmdroom-ticker-preview-data' );
		var rawData = {};
		if ( dataScript ) {
			try {
				rawData = JSON.parse( dataScript.textContent || '{}' );
			} catch ( e ) {
				rawData = {};
			}
		}

		function readStyle() {
			var speedInput = form.querySelector( '[name="speed"]' );
			var posInput   = form.querySelector( '[name="position"]' );
			var sepInput   = form.querySelector( '[name="separator"]' );
			var bgInput    = form.querySelector( '[name="bg_color"]' );
			var fgInput    = form.querySelector( '[name="text_color"]' );
			var pauseInput = form.querySelector( '[name="pause_on_hover"]' );
			return {
				speed: speedInput ? speedInput.value : 'normal',
				position: posInput ? posInput.value : 'below_menu',
				separator: sepInput ? sepInput.value : '·',
				bg: bgInput ? bgInput.value : '#201e1d',
				fg: fgInput ? fgInput.value : '#ffffff',
				pauseOnHover: !! ( pauseInput && pauseInput.checked ),
			};
		}

		function currentMessages() {
			if ( 'manual' === view && msgGrid ) {
				return readManualMessages( msgGrid );
			}
			if ( 'auto' === view ) {
				return resolveAutoLive( rawData.auto || {}, form );
			}
			if ( 'mixed' === view ) {
				var orderInput = form.querySelector( '[name="mixed_order"]' );
				var ratioInput = form.querySelector( '[name="mixed_ratio"]' );
				var maxInput   = form.querySelector( '[name="mixed_max"]' );
				return resolveMixedLive(
					rawData.fixed || [],
					rawData.auto || [],
					orderInput ? orderInput.value : 'interleave',
					ratioInput ? parseInt( ratioInput.value, 10 ) : 2,
					maxInput ? parseInt( maxInput.value, 10 ) : 8
				);
			}
			return [];
		}

		function render() {
			var style    = readStyle();
			var messages = currentMessages();
			var html     = buildBarHTML( messages, style, emptyText );

			if ( slotBelow ) {
				slotBelow.hidden = 'below_menu' !== style.position;
				if ( 'below_menu' === style.position ) {
					slotBelow.innerHTML = html;
				}
			}
			if ( slotBottom ) {
				slotBottom.hidden = 'bottom' !== style.position;
				if ( 'bottom' === style.position ) {
					slotBottom.innerHTML = html;
				}
			}

			if ( caption ) {
				var text = 'Modo ' + modeLabel;
				if ( dayLabel ) {
					text += ' · ' + dayLabel;
				}
				text += ' · ' + messages.length + ' mensaje' + ( 1 === messages.length ? '' : 's' );
				caption.textContent = text;
			}
		}

		// Bubbling: los listeners propios de cada control (toggles, segmentados)
		// ya han actualizado su input/checkbox antes de llegar aquí, así que
		// render() siempre lee el estado ya resuelto.
		form.addEventListener( 'input', render );
		form.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( '[data-cr-toggle], .cr-seg-opt' ) ) {
				render();
			}
		} );

		render();
		return render;
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var wrap = document.querySelector( '.cmdroom-components-wrap' );
		if ( ! wrap ) {
			return;
		}

		initComponentToggles( wrap );
		var refreshPreview = initTickerPreview( wrap );
		initTickerMessageRows( wrap, refreshPreview );
	} );
} )();
