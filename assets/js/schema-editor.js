/**
 * Command Room — pantalla "Datos estructurados".
 * El PHP solo pinta el shell (pestañas, cabecera, botón Guardar); todo el
 * contenido de cada pestaña (chips, terminal, menú "+ Añadir", Librería) lo
 * pinta este script a partir del estado inicial embebido en
 * #cmdroom-schema-state-data / #cmdroom-schema-library-data. Antes de
 * enviar el formulario, serializa el estado completo en el campo oculto
 * #cmdroom-schema-state -- ver Cmdroom_Schema_Settings::handle_save().
 */
( function () {
	'use strict';

	var VAR_PATTERN = /(%[a-z_]+%)/gi;
	var KEY_PATTERN = /("(?:[^"\\]|\\.)*"(?=\s*:))/g;

	function escapeHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	}

	function highlightJson( raw ) {
		var escaped = escapeHtml( raw );
		var parts   = escaped.split( KEY_PATTERN );
		return parts
			.map( function ( part, i ) {
				if ( i % 2 === 1 ) {
					return '<span class="cmdroom-md-key">' + part + '</span>';
				}
				return part.replace( VAR_PATTERN, '<span class="cmdroom-md-var">$1</span>' );
			} )
			.join( '' );
	}

	function uuid() {
		if ( window.crypto && window.crypto.randomUUID ) {
			return window.crypto.randomUUID();
		}
		return 'blk-' + Date.now().toString( 36 ) + '-' + Math.random().toString( 36 ).slice( 2 );
	}

	/**
	 * Lee el texto plano real de un editor contenteditable reconstruyendo
	 * los saltos de línea a mano -- .textContent NO basta: cuando el
	 * navegador decide envolver una línea en su propio <div>/<p> en vez de
	 * pasar por nuestro execCommand('insertText', '\n') del keydown de
	 * Enter (p. ej. al editar/pegar dentro de una línea ya existente),
	 * .textContent concatena esos bloques sin ningún separador y el JSON
	 * entero acaba guardándose como una sola fila -- mismo bug reportado por
	 * el administrador el 2026-09-24 en Metas, también presente aquí (misma técnica de
	 * editor). Ver meta-editor.js, extractText().
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
		var range    = sel.getRangeAt( 0 );
		var preRange = range.cloneRange();
		preRange.selectNodeContents( el );
		preRange.setEnd( range.endContainer, range.endOffset );
		return preRange.toString().length;
	}

	function setCaretOffset( el, offset ) {
		var range   = document.createRange();
		var sel     = window.getSelection();
		var walker  = document.createTreeWalker( el, NodeFilter.SHOW_TEXT );
		var node;
		var current = 0;
		var found   = false;

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

	function readJson( id ) {
		var el = document.getElementById( id );
		if ( ! el ) {
			return null;
		}
		try {
			return JSON.parse( el.textContent );
		} catch ( e ) {
			return null;
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var root = document.getElementById( 'cmdroom-schema-content' );
		if ( ! root ) {
			return;
		}

		var state   = readJson( 'cmdroom-schema-state-data' ) || {};
		var library = readJson( 'cmdroom-schema-library-data' ) || {};
		var tabs    = readJson( 'cmdroom-schema-tabs-data' ) || {};

		var groupKeys  = Object.keys( tabs );
		var activeTab  = groupKeys[ 0 ] || 'general';
		var activeById = {}; // group -> selected block id
		var menuOpen   = false;

		// Librería (2026-09-24): libraryOverrides solo guarda los tipos
		// tocados en esta sesión de edición (nuevos o con descripción/JSON
		// cambiados) -- es lo único que se manda al servidor en
		// library_state, nunca los 21 tipos de fábrica enteros.
		var libraryOverrides   = {};
		var editingLibraryType = null;

		groupKeys.forEach( function ( g ) {
			state[ g ] = Array.isArray( state[ g ] ) ? state[ g ] : [];
			activeById[ g ] = state[ g ].length ? state[ g ][ 0 ].id : null;
		} );

		var tabButtons = document.querySelectorAll( '#cmdroom-schema-tabs .cmdroom-md-tab' );

		function setActiveTab( tab ) {
			activeTab = tab;
			menuOpen  = false;
			tabButtons.forEach( function ( btn ) {
				btn.classList.toggle( 'is-active', btn.getAttribute( 'data-tab' ) === tab );
			} );
			render();
		}

		tabButtons.forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				setActiveTab( btn.getAttribute( 'data-tab' ) );
			} );
		} );

		function typesUsedIn( group ) {
			return state[ group ].map( function ( b ) { return b.type; } );
		}

		function typesUsedAnywhere() {
			var used = {};
			groupKeys.forEach( function ( g ) {
				state[ g ].forEach( function ( b ) {
					if ( ! used[ b.type ] ) {
						used[ b.type ] = [];
					}
					used[ b.type ].push( tabs[ g ] );
				} );
			} );
			return used;
		}

		function addBlock( group, type ) {
			var json = library[ type ] ? library[ type ].json : '{\n  "@context": "https://schema.org",\n  "@type": "' + type + '"\n}';
			var block = { id: uuid(), type: type, json: json };
			state[ group ].push( block );
			activeById[ group ] = block.id;
		}

		function addBlankBlock( group ) {
			var block = { id: uuid(), type: '', json: '{\n  "@context": "https://schema.org",\n  "@type": ""\n}' };
			state[ group ].push( block );
			activeById[ group ] = block.id;
		}

		function removeBlock( group, id ) {
			var idx = state[ group ].findIndex( function ( b ) { return b.id === id; } );
			if ( idx === -1 ) {
				return;
			}
			state[ group ].splice( idx, 1 );
			if ( activeById[ group ] === id ) {
				activeById[ group ] = state[ group ].length ? state[ group ][ 0 ].id : null;
			}
		}

		function renderChipsPanel( group ) {
			var wrap = document.createElement( 'div' );

			var chips = document.createElement( 'div' );
			chips.className = 'cmdroom-schema-chips';

			state[ group ].forEach( function ( block ) {
				var chip = document.createElement( 'span' );
				chip.className = 'cmdroom-schema-chip' + ( block.id === activeById[ group ] ? ' is-selected' : '' );

				var nameBtn = document.createElement( 'button' );
				nameBtn.type = 'button';
				nameBtn.className = 'cmdroom-schema-chip-name';
				nameBtn.textContent = block.type || '(sin tipo)';
				nameBtn.addEventListener( 'click', function () {
					activeById[ group ] = block.id;
					render();
				} );

				var removeBtn = document.createElement( 'button' );
				removeBtn.type = 'button';
				removeBtn.className = 'cmdroom-schema-chip-remove';
				removeBtn.title = 'Eliminar';
				removeBtn.textContent = '×';
				removeBtn.addEventListener( 'click', function () {
					removeBlock( group, block.id );
					render();
				} );

				chip.appendChild( nameBtn );
				chip.appendChild( removeBtn );
				chips.appendChild( chip );
			} );

			var addWrap = document.createElement( 'span' );
			addWrap.className = 'cmdroom-schema-add-wrap';

			var addBtn = document.createElement( 'button' );
			addBtn.type = 'button';
			addBtn.className = 'cmdroom-schema-add';
			addBtn.textContent = '+ Añadir';
			addBtn.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				menuOpen = ! menuOpen;
				render();
			} );

			addWrap.appendChild( addBtn );

			if ( menuOpen ) {
				addWrap.appendChild( renderAddMenu( group ) );
			}

			chips.appendChild( addWrap );
			wrap.appendChild( chips );

			if ( ! state[ group ].length ) {
				var empty = document.createElement( 'p' );
				empty.className = 'cmdroom-schema-empty';
				empty.textContent = 'Sin bloques en esta pestaña. Usa "+ Añadir" para crear uno.';
				wrap.appendChild( empty );
				return wrap;
			}

			var activeBlock = state[ group ].find( function ( b ) { return b.id === activeById[ group ]; } );
			if ( activeBlock ) {
				wrap.appendChild( renderTerminal( group, activeBlock, tabs[ group ] ) );
			}

			return wrap;
		}

		function renderAddMenu( group ) {
			var menu = document.createElement( 'div' );
			menu.className = 'cmdroom-schema-menu';
			menu.addEventListener( 'click', function ( e ) { e.stopPropagation(); } );

			var blank = document.createElement( 'button' );
			blank.type = 'button';
			blank.className = 'cmdroom-schema-menu-new';
			blank.innerHTML = '<div class="cmdroom-schema-menu-new-title">+ Nuevo JSON-LD</div>' +
				'<div class="cmdroom-schema-menu-new-desc">Empieza con un bloque vacío</div>';
			blank.addEventListener( 'click', function () {
				addBlankBlock( group );
				menuOpen = false;
				render();
			} );
			menu.appendChild( blank );

			var header = document.createElement( 'div' );
			header.className = 'cmdroom-schema-menu-header';
			header.textContent = 'DESDE LA LIBRERÍA';
			menu.appendChild( header );

			var list = document.createElement( 'div' );
			list.className = 'cmdroom-schema-menu-list';

			var used = typesUsedIn( group );
			Object.keys( library ).forEach( function ( type ) {
				if ( used.indexOf( type ) !== -1 ) {
					return;
				}
				var item = document.createElement( 'button' );
				item.type = 'button';
				item.className = 'cmdroom-schema-menu-item';
				item.innerHTML = '<div class="cmdroom-schema-menu-item-name">' + escapeHtml( type ) + '</div>' +
					'<div class="cmdroom-schema-menu-item-desc">' + escapeHtml( library[ type ].description ) + '</div>';
				item.addEventListener( 'click', function () {
					addBlock( group, type );
					menuOpen = false;
					render();
				} );
				list.appendChild( item );
			} );

			menu.appendChild( list );
			return menu;
		}

		function renderTerminal( group, block, tabLabel ) {
			var wrap = document.createElement( 'div' );
			wrap.className = 'cmdroom-md-block';

			var label = document.createElement( 'label' );
			label.className = 'cmdroom-md-block-label';
			label.textContent = 'JSON-LD — ' + tabLabel;
			wrap.appendChild( label );

			var terminal = document.createElement( 'div' );
			terminal.className = 'cmdroom-md-terminal';

			var bar = document.createElement( 'div' );
			bar.className = 'cmdroom-md-terminal-bar';
			[ 'red', 'amber', 'green' ].forEach( function ( color ) {
				var dot = document.createElement( 'span' );
				dot.className = 'cmdroom-md-dot cmdroom-md-dot-' + color;
				bar.appendChild( dot );
			} );
			terminal.appendChild( bar );

			var body = document.createElement( 'div' );
			body.className = 'cmdroom-md-terminal-body';

			var code = document.createElement( 'div' );
			code.className = 'cmdroom-md-code';
			code.contentEditable = 'true';
			code.spellcheck = false;
			code.innerHTML = highlightJson( block.json );

			code.addEventListener( 'input', function () {
				var offset = getCaretOffset( code );
				var raw    = extractText( code );
				code.innerHTML = highlightJson( raw );
				setCaretOffset( code, offset );
				block.json = raw;
			} );

			code.addEventListener( 'keydown', function ( e ) {
				if ( 'Enter' === e.key ) {
					e.preventDefault();
					document.execCommand( 'insertText', false, '\n' );
				}
			} );

			code.addEventListener( 'paste', function ( e ) {
				e.preventDefault();
				var text = ( e.clipboardData || window.clipboardData ).getData( 'text/plain' );
				document.execCommand( 'insertText', false, text );
			} );

			body.appendChild( code );
			terminal.appendChild( body );
			wrap.appendChild( terminal );

			return wrap;
		}

		/**
		 * Terminal de edición de JSON reutilizado para la Librería -- mismo
		 * look que renderTerminal() (chips), pero sin atarlo a un
		 * group/block de un tipo de página: aquí edita directamente
		 * library[type].json.
		 */
		function renderLibraryCodeEditor( entry ) {
			var terminal = document.createElement( 'div' );
			terminal.className = 'cmdroom-md-terminal';

			var bar = document.createElement( 'div' );
			bar.className = 'cmdroom-md-terminal-bar';
			[ 'red', 'amber', 'green' ].forEach( function ( color ) {
				var dot = document.createElement( 'span' );
				dot.className = 'cmdroom-md-dot cmdroom-md-dot-' + color;
				bar.appendChild( dot );
			} );
			terminal.appendChild( bar );

			var body = document.createElement( 'div' );
			body.className = 'cmdroom-md-terminal-body';

			var code = document.createElement( 'div' );
			code.className = 'cmdroom-md-code';
			code.contentEditable = 'true';
			code.spellcheck = false;
			code.innerHTML = highlightJson( entry.json || '' );

			code.addEventListener( 'input', function () {
				var offset = getCaretOffset( code );
				var raw    = extractText( code );
				code.innerHTML = highlightJson( raw );
				setCaretOffset( code, offset );
				entry.json = raw;
			} );
			code.addEventListener( 'keydown', function ( e ) {
				if ( 'Enter' === e.key ) {
					e.preventDefault();
					document.execCommand( 'insertText', false, '\n' );
				}
			} );
			code.addEventListener( 'paste', function ( e ) {
				e.preventDefault();
				var text = ( e.clipboardData || window.clipboardData ).getData( 'text/plain' );
				document.execCommand( 'insertText', false, text );
			} );

			body.appendChild( code );
			terminal.appendChild( body );
			return terminal;
		}

		function renderLibrary() {
			var wrap = document.createElement( 'div' );

			var toolbar = document.createElement( 'div' );
			toolbar.className = 'cmdroom-schema-library-toolbar';
			var addBtn = document.createElement( 'button' );
			addBtn.type = 'button';
			addBtn.className = 'cmdroom-schema-btn cmdroom-schema-btn-primary';
			addBtn.textContent = '+ Nuevo tipo';
			addBtn.addEventListener( 'click', function () {
				var raw = window.prompt( 'Nombre del tipo (@type de schema.org, p. ej. Product o Recipe):' );
				var type = raw ? raw.trim() : '';
				if ( ! type ) {
					return;
				}
				if ( library[ type ] ) {
					window.alert( 'Ya existe un tipo con ese nombre.' );
					return;
				}
				library[ type ] = {
					label: type,
					description: '',
					json: '{\n  "@context": "https://schema.org",\n  "@type": "' + type + '"\n}',
					custom: true,
				};
				libraryOverrides[ type ] = library[ type ];
				editingLibraryType = type;
				render();
			} );
			toolbar.appendChild( addBtn );
			wrap.appendChild( toolbar );

			var grid = document.createElement( 'div' );
			grid.className = 'cmdroom-schema-library-grid';

			var used = typesUsedAnywhere();

			Object.keys( library ).forEach( function ( type ) {
				var entry    = library[ type ];
				var editing  = editingLibraryType === type;
				var card     = document.createElement( 'div' );
				card.className = 'cmdroom-schema-card' + ( editing ? ' is-editing' : '' );

				var row = document.createElement( 'div' );
				row.className = 'cmdroom-schema-card-row';

				var name = document.createElement( 'span' );
				name.className = 'cmdroom-schema-card-name';
				name.textContent = type;
				row.appendChild( name );

				var badges = document.createElement( 'span' );
				badges.className = 'cmdroom-schema-card-badges';
				var inUse = !! used[ type ];
				var badge = document.createElement( 'span' );
				badge.className = 'cmdroom-schema-badge ' + ( inUse ? 'in-use' : 'available' );
				badge.textContent = inUse ? 'En uso' : 'Disponible';
				badges.appendChild( badge );
				if ( entry.custom ) {
					var customBadge = document.createElement( 'span' );
					customBadge.className = 'cmdroom-schema-badge custom';
					customBadge.textContent = 'Propio';
					badges.appendChild( customBadge );
				}
				if ( libraryOverrides[ type ] ) {
					var editedBadge = document.createElement( 'span' );
					editedBadge.className = 'cmdroom-schema-badge edited';
					editedBadge.textContent = 'Editado';
					badges.appendChild( editedBadge );
				}
				row.appendChild( badges );

				card.appendChild( row );

				if ( editing ) {
					var descLabel = document.createElement( 'label' );
					descLabel.className = 'cmdroom-md-block-label';
					descLabel.textContent = 'Descripción';
					card.appendChild( descLabel );

					var descInput = document.createElement( 'textarea' );
					descInput.className = 'cmdroom-schema-desc-input';
					descInput.value = entry.description;
					descInput.rows = 2;
					descInput.addEventListener( 'input', function () {
						entry.description = descInput.value;
						libraryOverrides[ type ] = entry;
					} );
					card.appendChild( descInput );

					var jsonLabel = document.createElement( 'label' );
					jsonLabel.className = 'cmdroom-md-block-label';
					jsonLabel.textContent = 'JSON de partida';
					card.appendChild( jsonLabel );

					card.appendChild( renderLibraryCodeEditor( entry ) );
					// El terminal muta entry.json por referencia -- se marca
					// como override en cuanto se abre a editar, no hace
					// falta esperar a un evento de cambio.
					libraryOverrides[ type ] = entry;

					var doneBtn = document.createElement( 'button' );
					doneBtn.type = 'button';
					doneBtn.className = 'cmdroom-schema-btn';
					doneBtn.textContent = 'Listo';
					doneBtn.style.marginTop = '8px';
					doneBtn.addEventListener( 'click', function () {
						editingLibraryType = null;
						render();
					} );
					card.appendChild( doneBtn );
				} else {
					var desc = document.createElement( 'p' );
					desc.className = 'cmdroom-schema-card-desc';
					desc.textContent = entry.description;
					card.appendChild( desc );

					var footer = document.createElement( 'p' );
					footer.className = 'cmdroom-schema-card-footer';
					footer.textContent = inUse ? 'Usado en: ' + used[ type ].join( ', ' ) : 'Sin asignar';
					card.appendChild( footer );

					var editBtn = document.createElement( 'button' );
					editBtn.type = 'button';
					editBtn.className = 'cmdroom-schema-btn';
					editBtn.textContent = 'Editar';
					editBtn.addEventListener( 'click', function () {
						editingLibraryType = type;
						render();
					} );
					card.appendChild( editBtn );
				}

				grid.appendChild( card );
			} );

			wrap.appendChild( grid );
			return wrap;
		}

		function render() {
			root.innerHTML = '';
			if ( 'libreria' === activeTab ) {
				root.appendChild( renderLibrary() );
			} else {
				root.appendChild( renderChipsPanel( activeTab ) );
			}
		}

		document.addEventListener( 'click', function () {
			if ( menuOpen ) {
				menuOpen = false;
				render();
			}
		} );

		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key && menuOpen ) {
				menuOpen = false;
				render();
			}
		} );

		var form = document.getElementById( 'cmdroom-schema-form' );
		if ( form ) {
			form.addEventListener( 'submit', function () {
				var out = {};
				groupKeys.forEach( function ( g ) {
					out[ g ] = state[ g ].map( function ( b ) {
						return { id: b.id, type: b.type, json: b.json };
					} );
				} );
				document.getElementById( 'cmdroom-schema-state' ).value = JSON.stringify( out );

				var libOut = {};
				Object.keys( libraryOverrides ).forEach( function ( type ) {
					var entry = libraryOverrides[ type ];
					libOut[ type ] = { label: entry.label, description: entry.description, json: entry.json };
				} );
				document.getElementById( 'cmdroom-schema-library-state' ).value = JSON.stringify( libOut );
			} );
		}

		setActiveTab( activeTab );
	} );
} )();
