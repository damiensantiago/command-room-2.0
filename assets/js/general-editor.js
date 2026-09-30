( function () {
	'use strict';

	var MONTHS_ES = [ 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic' ];

	function fmtDate( iso, withYear ) {
		var parts = iso.split( '-' );
		var d = parseInt( parts[2], 10 );
		var m = MONTHS_ES[ parseInt( parts[1], 10 ) - 1 ];
		return withYear ? d + ' ' + m + ' ' + parts[0] : d + ' ' + m;
	}

	function fmtNumber( n ) {
		return Math.round( n ).toLocaleString( 'es-ES' );
	}

	function fmtValue( metric, v ) {
		if ( 'ctr' === metric ) {
			return v.toFixed( 1 ).replace( '.', ',' ) + '%';
		}
		if ( 'pos' === metric ) {
			return v.toFixed( 1 ).replace( '.', ',' );
		}
		return v >= 1000 ? ( v / 1000 ).toFixed( 1 ).replace( '.', ',' ) + 'k' : fmtNumber( v );
	}

	function ceilingFor( metric, max ) {
		if ( 'ctr' === metric || 'pos' === metric ) {
			return Math.max( 1, Math.ceil( max ) );
		}
		var base = max > 60 ? 30 : 3;
		return Math.max( base, Math.ceil( max / base ) * base );
	}

	/* ---------------- GSC performance ---------------- */

	function initGsc() {
		var dataEl = document.getElementById( 'cmdroom-gsc-data' );
		var card   = document.getElementById( 'cmdroom-gsc-card' );
		if ( ! dataEl || ! card ) {
			return;
		}

		var payload = JSON.parse( dataEl.textContent );
		var series  = payload.series || [];
		var metrics = payload.metrics || {};

		var state = {
			range: 28,
			on: { clicks: true, impr: true, ctr: false, pos: false },
		};

		var svg          = card.querySelector( '[data-cr-gsc-svg]' );
		var chartArea     = card.querySelector( '[data-cr-gsc-chart-area]' );
		var tooltip       = card.querySelector( '[data-cr-gsc-tooltip]' );
		var rangeLabel    = card.querySelector( '[data-cr-gsc-range-label]' );
		var axisLeftLabel = card.querySelector( '[data-cr-gsc-axis-left]' );
		var axisRightLabel = card.querySelector( '[data-cr-gsc-axis-right]' );
		var ticksLeft     = card.querySelector( '[data-cr-gsc-ticks-left]' );
		var ticksRight    = card.querySelector( '[data-cr-gsc-ticks-right]' );
		var xaxis         = card.querySelector( '[data-cr-gsc-xaxis]' );

		function activeMetrics() {
			return Object.keys( state.on ).filter( function ( k ) { return state.on[ k ]; } );
		}

		function currentWindow() {
			return series.slice( series.length - state.range );
		}
		function previousWindow() {
			return series.slice( Math.max( 0, series.length - state.range * 2 ), series.length - state.range );
		}

		function sum( rows, key ) {
			return rows.reduce( function ( acc, r ) { return acc + r[ key ]; }, 0 );
		}
		function avg( rows, key ) {
			return rows.length ? sum( rows, key ) / rows.length : 0;
		}

		function metricTotal( rows, metric ) {
			if ( 'clicks' === metric || 'impr' === metric ) {
				return sum( rows, metric );
			}
			if ( 'ctr' === metric ) {
				var c = sum( rows, 'clicks' );
				var i = sum( rows, 'impr' );
				return i > 0 ? ( c / i * 100 ) : 0;
			}
			return avg( rows, 'pos' );
		}

		function renderCards() {
			var cur  = currentWindow();
			var prev = previousWindow();

			Object.keys( metrics ).forEach( function ( key ) {
				var btn = card.querySelector( '[data-cr-gsc-metric="' + key + '"]' );
				if ( ! btn ) {
					return;
				}
				var curVal  = metricTotal( cur, key );
				var prevVal = metricTotal( prev, key );
				btn.querySelector( '[data-cr-gsc-value]' ).textContent = fmtValue( key, curVal );

				var deltaEl = btn.querySelector( '[data-cr-gsc-delta]' );
				if ( prevVal > 0 ) {
					if ( 'pos' === key ) {
						var diff = prevVal - curVal; // positivo = mejora (posición baja)
						deltaEl.textContent = ( diff >= 0 ? '▲ ' : '▼ ' ) + Math.abs( diff ).toFixed( 1 ).replace( '.', ',' ) + ' vs. periodo anterior';
					} else {
						var pct = ( curVal - prevVal ) / prevVal * 100;
						deltaEl.textContent = ( pct >= 0 ? '▲ ' : '▼ ' ) + Math.abs( Math.round( pct ) ) + '% vs. periodo anterior';
					}
				} else {
					deltaEl.textContent = '';
				}

				btn.classList.toggle( 'is-active', !! state.on[ key ] );
			} );
		}

		function niceRangeLabel() {
			var cur = currentWindow();
			if ( ! cur.length ) {
				rangeLabel.textContent = '';
				return;
			}
			rangeLabel.textContent = fmtDate( cur[0].date ) + ' – ' + fmtDate( cur[ cur.length - 1 ].date, true );
		}

		function svgNS( tag ) {
			return document.createElementNS( 'http://www.w3.org/2000/svg', tag );
		}

		function renderChart() {
			var cur = currentWindow();
			var active = activeMetrics();
			svg.innerHTML = '';

			// líneas guía
			[ 0, 80, 160 ].forEach( function ( y ) {
				var l = svgNS( 'line' );
				l.setAttribute( 'x1', 0 ); l.setAttribute( 'x2', 1000 );
				l.setAttribute( 'y1', y ); l.setAttribute( 'y2', y );
				l.setAttribute( 'stroke', '#eef0f3' );
				l.setAttribute( 'vector-effect', 'non-scaling-stroke' );
				svg.appendChild( l );
			} );
			var base = svgNS( 'line' );
			base.setAttribute( 'x1', 0 ); base.setAttribute( 'x2', 1000 );
			base.setAttribute( 'y1', 240 ); base.setAttribute( 'y2', 240 );
			base.setAttribute( 'stroke', '#c9ced6' );
			base.setAttribute( 'vector-effect', 'non-scaling-stroke' );
			svg.appendChild( base );

			if ( ! cur.length || ! active.length ) {
				updateAxis( active, cur );
				return;
			}

			var n = cur.length;
			var ceilings = {};
			active.forEach( function ( m ) {
				ceilings[ m ] = ceilingFor( m, Math.max.apply( null, cur.map( function ( r ) { return r[ m ]; } ) ) );
			} );

			function xFor( i ) {
				return n > 1 ? ( i / ( n - 1 ) ) * 1000 : 500;
			}
			function yFor( metric, v ) {
				var ceil = ceilings[ metric ];
				if ( 'pos' === metric ) {
					return ( v / ceil ) * 240;
				}
				return 240 - ( v / ceil ) * 240;
			}

			active.forEach( function ( metric, idx ) {
				var color = metrics[ metric ].color;
				var points = cur.map( function ( r, i ) { return [ xFor( i ), yFor( metric, r[ metric ] ) ]; } );
				var d = points.map( function ( p, i ) { return ( i === 0 ? 'M' : 'L' ) + p[0].toFixed( 2 ) + ' ' + p[1].toFixed( 2 ); } ).join( ' ' );

				if ( 0 === idx && 'pos' !== metric ) {
					var area = d + ' L ' + xFor( n - 1 ).toFixed( 2 ) + ' 240 L 0 240 Z';
					var fillPath = svgNS( 'path' );
					fillPath.setAttribute( 'd', area );
					fillPath.setAttribute( 'fill', color );
					fillPath.setAttribute( 'fill-opacity', '0.07' );
					fillPath.setAttribute( 'stroke', 'none' );
					svg.appendChild( fillPath );
				}

				var path = svgNS( 'path' );
				path.setAttribute( 'd', d );
				path.setAttribute( 'fill', 'none' );
				path.setAttribute( 'stroke', color );
				path.setAttribute( 'stroke-width', '2.25' );
				path.setAttribute( 'stroke-linejoin', 'round' );
				path.setAttribute( 'stroke-linecap', 'round' );
				path.setAttribute( 'vector-effect', 'non-scaling-stroke' );
				svg.appendChild( path );
			} );

			updateAxis( active, cur, ceilings, yFor );
			bindHover( cur, active, ceilings, xFor, yFor );
		}

		function tickLabel( metric, v ) {
			return fmtValue( metric, v );
		}

		function updateAxis( active, cur, ceilings, yFor ) {
			var left  = active[0] || null;
			var right = active[1] || null;

			axisLeftLabel.textContent  = left ? metrics[ left ].label.replace( ' totales', '' ) : '';
			axisRightLabel.textContent = right ? metrics[ right ].label.replace( ' totales', '' ) : '';

			[ [ ticksLeft, left ], [ ticksRight, right ] ].forEach( function ( pair ) {
				var el = pair[0];
				var metric = pair[1];
				el.innerHTML = '';
				if ( ! metric || ! ceilings ) {
					return;
				}
				var ceil = ceilings[ metric ];
				var vals = 'pos' === metric ? [ 0, ceil / 3, ceil * 2 / 3, ceil ] : [ ceil, ceil * 2 / 3, ceil / 3, 0 ];
				vals.forEach( function ( v ) {
					var span = document.createElement( 'span' );
					span.textContent = tickLabel( metric, v );
					el.appendChild( span );
				} );
			} );

			xaxis.innerHTML = '';
			var left36 = document.createElement( 'span' );
			xaxis.appendChild( left36 );
			var mid = document.createElement( 'span' );
			if ( cur.length ) {
				var every = Math.max( 1, Math.round( cur.length / 7 ) );
				for ( var i = 0; i < cur.length; i += every ) {
					var s = document.createElement( 'span' );
					s.textContent = fmtDate( cur[ i ].date );
					mid.appendChild( s );
				}
			}
			xaxis.appendChild( mid );
			var right36 = document.createElement( 'span' );
			xaxis.appendChild( right36 );
		}

		function bindHover( cur, active, ceilings, xFor, yFor ) {
			var n = cur.length;
			if ( n < 2 ) {
				return;
			}

			function onMove( e ) {
				var rect = chartArea.getBoundingClientRect();
				var relX = ( e.clientX - rect.left ) / rect.width;
				var index = Math.round( relX * ( n - 1 ) );
				index = Math.max( 0, Math.min( n - 1, index ) );

				var existingLine = svg.querySelector( '.cmdroom-gsc-hover-line' );
				if ( existingLine ) { existingLine.remove(); }
				svg.querySelectorAll( '.cmdroom-gsc-hover-dot' ).forEach( function ( d ) { d.remove(); } );

				var x = xFor( index );
				var vLine = svgNS( 'line' );
				vLine.setAttribute( 'class', 'cmdroom-gsc-hover-line' );
				vLine.setAttribute( 'x1', x ); vLine.setAttribute( 'x2', x );
				vLine.setAttribute( 'y1', 0 ); vLine.setAttribute( 'y2', 240 );
				vLine.setAttribute( 'stroke', '#9ca3af' );
				vLine.setAttribute( 'stroke-width', '1' );
				vLine.setAttribute( 'vector-effect', 'non-scaling-stroke' );
				svg.appendChild( vLine );

				var rows = [];
				active.forEach( function ( metric ) {
					var v = cur[ index ][ metric ];
					var y = yFor( metric, v );
					var dot = svgNS( 'circle' );
					dot.setAttribute( 'class', 'cmdroom-gsc-hover-dot' );
					dot.setAttribute( 'cx', x ); dot.setAttribute( 'cy', y ); dot.setAttribute( 'r', 4.5 );
					dot.setAttribute( 'fill', metrics[ metric ].color );
					dot.setAttribute( 'stroke', '#fff' );
					dot.setAttribute( 'stroke-width', '2' );
					dot.setAttribute( 'vector-effect', 'non-scaling-stroke' );
					svg.appendChild( dot );
					rows.push( { color: metrics[ metric ].color, label: metrics[ metric ].label, value: fmtValue( metric, v ) } );
				} );

				tooltip.innerHTML = '<div class="cmdroom-gsc-tooltip-date">' + fmtDate( cur[ index ].date, true ) + '</div>' +
					rows.map( function ( r ) {
						return '<div class="cmdroom-gsc-tooltip-row"><span style="display:flex;align-items:center;gap:6px;"><span class="cmdroom-gsc-tooltip-sq" style="background:' + r.color + '"></span>' + r.label + '</span><strong>' + r.value + '</strong></div>';
					} ).join( '' );
				tooltip.hidden = false;

				var leftPct = relX * 100 + 1.5;
				if ( relX > 0.6 ) {
					tooltip.style.left = 'auto';
					tooltip.style.right = ( 100 - relX * 100 + 1.5 ) + '%';
				} else {
					tooltip.style.right = 'auto';
					tooltip.style.left = leftPct + '%';
				}
			}

			function onLeave() {
				var existingLine = svg.querySelector( '.cmdroom-gsc-hover-line' );
				if ( existingLine ) { existingLine.remove(); }
				svg.querySelectorAll( '.cmdroom-gsc-hover-dot' ).forEach( function ( d ) { d.remove(); } );
				tooltip.hidden = true;
			}

			chartArea.addEventListener( 'mousemove', onMove );
			chartArea.addEventListener( 'mouseleave', onLeave );
		}

		function renderAll() {
			renderCards();
			niceRangeLabel();
			renderChart();
		}

		card.querySelectorAll( '[data-cr-gsc-range]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				state.range = parseInt( btn.getAttribute( 'data-cr-gsc-range' ), 10 );
				card.querySelectorAll( '[data-cr-gsc-range]' ).forEach( function ( b ) { b.classList.remove( 'is-active' ); } );
				btn.classList.add( 'is-active' );
				renderAll();
			} );
		} );

		card.querySelectorAll( '[data-cr-gsc-metric]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var key = btn.getAttribute( 'data-cr-gsc-metric' );
				var activeCount = activeMetrics().length;
				if ( state.on[ key ] && activeCount <= 1 ) {
					return; // al menos una tiene que quedar activa
				}
				state.on[ key ] = ! state.on[ key ];
				renderAll();
			} );
		} );

		renderAll();
	}

	/* ---------------- Auditoría: filtro de severidad ---------------- */

	/*
	 * Filtro de severidad + botón "Ver todos" por tipo de problema
	 * (2026-09-24): una fila extra puede estar oculta por dos motivos a la
	 * vez -- no coincide con el filtro de severidad activo, o el grupo
	 * sigue plegado. Se guarda el estado de expansión por tipo en
	 * expandedTypes y se recalcula la visibilidad completa cada vez que
	 * cambia cualquiera de los dos, en vez de tocar "hidden" a ciegas.
	 */
	function initAudit() {
		var filterGroup = document.querySelector( '[data-cr-audit-filter]' );
		if ( ! filterGroup ) {
			return;
		}

		var currentSeverity = 'all';
		var expandedTypes   = {};

		function applyVisibility() {
			document.querySelectorAll( '[data-cr-audit-row]' ).forEach( function ( row ) {
				var matchesSeverity = 'all' === currentSeverity || row.getAttribute( 'data-severity' ) === currentSeverity;
				var extraType = row.getAttribute( 'data-cr-audit-extra' );
				var isExpanded = ! extraType || !! expandedTypes[ extraType ];
				row.hidden = ! ( matchesSeverity && isExpanded );
			} );
		}

		filterGroup.querySelectorAll( '[data-cr-audit-severity]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				currentSeverity = btn.getAttribute( 'data-cr-audit-severity' );
				filterGroup.querySelectorAll( '[data-cr-audit-severity]' ).forEach( function ( b ) { b.classList.remove( 'is-active' ); } );
				btn.classList.add( 'is-active' );
				applyVisibility();
			} );
		} );

		document.querySelectorAll( '[data-cr-audit-toggle]' ).forEach( function ( btn ) {
			var count = btn.getAttribute( 'data-cr-audit-count' );
			btn.addEventListener( 'click', function () {
				var type = btn.getAttribute( 'data-cr-audit-toggle' );
				expandedTypes[ type ] = ! expandedTypes[ type ];
				var expanded = expandedTypes[ type ];
				btn.setAttribute( 'aria-expanded', expanded ? 'true' : 'false' );
				btn.textContent = ( expanded ? 'Ocultar las ' : 'Ver las ' ) + count + ' ' + ( expanded ? '▴' : '▾' );
				applyVisibility();
			} );
		} );

		applyVisibility();
	}

	/*
	 * Toggle de "Barra lateral" en la tabla de Módulos: el checkbox real
	 * sí cambia de estado al hacer clic (y se guarda bien al pulsar
	 * "Guardar"), pero el knob/color son la clase .is-active del <span>
	 * que lo envuelve, pintada solo en el servidor al cargar -- sin esto
	 * el interruptor parece que no responde hasta recargar la página.
	 */
	function initModuleToggles() {
		document.querySelectorAll( '.cmdroom-general-toggle input[type="checkbox"]' ).forEach( function ( input ) {
			input.addEventListener( 'change', function () {
				input.closest( '.cmdroom-general-toggle' ).classList.toggle( 'is-active', input.checked );
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initGsc();
		initAudit();
		initModuleToggles();
	} );
} )();
