/**
 * Command Room — pantalla "Robots.txt".
 * Tabla de bots de IA con vista previa en vivo del bloque que se añade al
 * final de /robots.txt, y alta de bots nuevos sin recargar la página. La
 * lógica de generación del bloque duplica a propósito
 * Cmdroom_Robots_Settings::build_block() en PHP — es la misma regla, solo
 * que aquí opera sobre el estado actual del formulario (sin guardar) para
 * que la previsualización se actualice al momento.
 */
( function () {
	'use strict';

	function splitPaths( raw ) {
		return raw
			.split( ',' )
			.map( function ( s ) { return s.trim(); } )
			.filter( function ( s ) { return s.length > 0; } );
	}

	function buildBlock( rows ) {
		var groups = [];

		rows.forEach( function ( row ) {
			var lines = [];
			if ( row.blocked ) {
				lines.push( 'User-agent: ' + row.ua );
				lines.push( 'Disallow: /' );
				row.paths.forEach( function ( p ) { lines.push( 'Allow: ' + p ); } );
			} else if ( row.paths.length ) {
				lines.push( 'User-agent: ' + row.ua );
				row.paths.forEach( function ( p ) { lines.push( 'Disallow: ' + p ); } );
			}
			if ( lines.length ) {
				groups.push( lines.join( '\n' ) );
			}
		} );

		return groups.length ? groups.join( '\n\n' ) : '# Ningún bot de IA bloqueado';
	}

	function readRows( tbody ) {
		var rows = [];
		tbody.querySelectorAll( '.cmdroom-robots-row' ).forEach( function ( tr ) {
			var uaInput = tr.querySelector( '.cmdroom-robots-ua-value' );
			if ( ! uaInput || '' === uaInput.value ) {
				return;
			}
			var blockedInput = tr.querySelector( '.cmdroom-robots-blocked' );
			var pathsInput   = tr.querySelector( '.cmdroom-robots-paths' );
			rows.push( {
				ua: uaInput.value,
				blocked: !! ( blockedInput && blockedInput.checked ),
				paths: splitPaths( pathsInput ? pathsInput.value : '' )
			} );
		} );
		return rows;
	}

	function updateRowState( tr ) {
		var blockedInput = tr.querySelector( '.cmdroom-robots-blocked' );
		var label        = tr.querySelector( '.cmdroom-robots-paths-label' );
		var pathsInput   = tr.querySelector( '.cmdroom-robots-paths' );
		var blocked      = !! ( blockedInput && blockedInput.checked );

		tr.classList.toggle( 'is-blocked', blocked );
		if ( label ) {
			label.textContent = blocked ? 'Permitir solo en' : 'Bloquear solo en';
		}
		if ( pathsInput ) {
			pathsInput.placeholder = blocked ? '/blog/, /guias/' : '/area-clientes/, /privado/';
		}
	}

	function refreshPreview( wrap, tbody ) {
		var pre = wrap.querySelector( '.cmdroom-robots-preview' );
		if ( pre ) {
			pre.textContent = buildBlock( readRows( tbody ) );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var wrap = document.querySelector( '.cmdroom-robots-wrap' );
		if ( ! wrap ) {
			return;
		}

		var tbody    = wrap.querySelector( '.cmdroom-robots-table tbody' );
		var addRow   = wrap.querySelector( '.cmdroom-robots-add-row' );
		var template = wrap.querySelector( '#cmdroom-robots-row-template' );
		if ( ! tbody ) {
			return;
		}

		tbody.addEventListener( 'change', function ( e ) {
			if ( e.target.classList.contains( 'cmdroom-robots-blocked' ) ) {
				var tr = e.target.closest( 'tr' );
				if ( tr ) {
					updateRowState( tr );
					refreshPreview( wrap, tbody );
				}
			}
		} );

		tbody.addEventListener( 'input', function ( e ) {
			if ( e.target.classList.contains( 'cmdroom-robots-paths' ) ) {
				refreshPreview( wrap, tbody );
			}
		} );

		function existingUAs() {
			var list = [];
			tbody.querySelectorAll( '.cmdroom-robots-ua-value' ).forEach( function ( input ) {
				if ( input.value ) {
					list.push( input.value.toLowerCase() );
				}
			} );
			return list;
		}

		function addBot() {
			if ( ! addRow || ! template ) {
				return;
			}
			var uaInput      = addRow.querySelector( '.cmdroom-robots-new-ua' );
			var companyInput = addRow.querySelector( '.cmdroom-robots-new-company' );
			var descInput    = addRow.querySelector( '.cmdroom-robots-new-desc' );
			var ua           = uaInput.value.trim();

			if ( '' === ua || existingUAs().indexOf( ua.toLowerCase() ) !== -1 ) {
				return;
			}

			var index    = tbody.querySelectorAll( '.cmdroom-robots-row' ).length;
			var html     = template.innerHTML.split( '__INDEX__' ).join( String( index ) );
			var scratch  = document.createElement( 'tbody' );
			scratch.innerHTML = html;
			var newRow   = scratch.querySelector( 'tr' );

			var company = companyInput.value.trim() || '—';
			var desc    = descInput.value.trim() || 'Añadido manualmente.';

			newRow.querySelector( '.cmdroom-robots-ua-value' ).value = ua;
			newRow.querySelector( '.cmdroom-robots-ua-text' ).textContent = ua;
			newRow.querySelector( '.cmdroom-robots-company-value' ).value = company;
			newRow.querySelector( '.cmdroom-robots-company-text' ).textContent = company;
			newRow.querySelector( '.cmdroom-robots-desc-value' ).value = desc;
			newRow.querySelector( '.cmdroom-robots-desc-text' ).textContent = desc;

			tbody.insertBefore( newRow, addRow );
			updateRowState( newRow );
			refreshPreview( wrap, tbody );

			uaInput.value = '';
			companyInput.value = '';
			descInput.value = '';
			uaInput.focus();
		}

		if ( addRow ) {
			var addBtn = addRow.querySelector( '.cmdroom-robots-add-btn' );
			if ( addBtn ) {
				addBtn.addEventListener( 'click', addBot );
			}
			addRow.querySelectorAll( 'input' ).forEach( function ( input ) {
				input.addEventListener( 'keydown', function ( e ) {
					if ( 'Enter' === e.key ) {
						e.preventDefault();
						addBot();
					}
				} );
			} );
		}
	} );
} )();
