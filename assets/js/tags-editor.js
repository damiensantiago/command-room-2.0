( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var wrap = document.querySelector( '[data-cr-tags-cleanup]' );
		if ( ! wrap ) {
			return;
		}

		var nonce  = wrap.getAttribute( 'data-nonce' );
		var status = wrap.querySelector( '[data-cr-tags-status]' );

		function showStatus( message, isError ) {
			if ( ! status ) {
				return;
			}
			status.textContent = message;
			status.hidden = false;
			status.classList.toggle( 'is-error', !! isError );
		}

		function post( action, extra ) {
			var body = new URLSearchParams( Object.assign( { action: action, nonce: nonce }, extra || {} ) );
			return fetch( window.ajaxurl || '/wp-admin/admin-ajax.php', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} ).then( function ( r ) { return r.json(); } );
		}

		wrap.addEventListener( 'click', function ( e ) {
			var mergeBtn = e.target.closest( '[data-cr-tags-merge]' );
			if ( mergeBtn ) {
				var row    = mergeBtn.closest( '.cmdroom-tags-row' );
				var select = row.querySelector( '.cmdroom-tags-merge-select' );
				var toId   = select.value;
				if ( ! toId ) {
					showStatus( 'Elige antes una etiqueta destino.', true );
					return;
				}
				if ( ! window.confirm( 'Fusionar "' + row.children[0].textContent.trim() + '" en "' + select.options[ select.selectedIndex ].text + '"? Esto mueve todos sus posts y la borra.' ) ) {
					return;
				}
				mergeBtn.disabled = true;
				post( 'cmdroom_tags_merge', { from: row.getAttribute( 'data-term-id' ), to: toId } ).then( function ( res ) {
					mergeBtn.disabled = false;
					if ( res.success ) {
						showStatus( res.data.message, false );
						row.remove();
					} else {
						showStatus( res.data && res.data.message ? res.data.message : 'Error al fusionar.', true );
					}
				} );
				return;
			}

			var delOne = e.target.closest( '[data-cr-tags-delete-one]' );
			if ( delOne ) {
				var deleteRow = delOne.closest( '.cmdroom-tags-row' );
				if ( ! window.confirm( 'Eliminar la etiqueta "' + deleteRow.children[0].textContent.trim() + '"?' ) ) {
					return;
				}
				delOne.disabled = true;
				post( 'cmdroom_tags_delete_one', { term_id: deleteRow.getAttribute( 'data-term-id' ) } ).then( function ( res ) {
					if ( res.success ) {
						showStatus( res.data.message, false );
						deleteRow.remove();
					} else {
						delOne.disabled = false;
						showStatus( res.data && res.data.message ? res.data.message : 'Error al eliminar.', true );
					}
				} );
				return;
			}

			var delEmpty = e.target.closest( '[data-cr-tags-delete-empty]' );
			if ( delEmpty ) {
				if ( ! window.confirm( 'Eliminar todas las etiquetas sin entradas?' ) ) {
					return;
				}
				delEmpty.disabled = true;
				post( 'cmdroom_tags_delete_empty' ).then( function ( res ) {
					delEmpty.disabled = false;
					if ( res.success ) {
						showStatus( res.data.message, false );
						wrap.querySelectorAll( '.cmdroom-tags-row.is-empty' ).forEach( function ( row ) { row.remove(); } );
					} else {
						showStatus( res.data && res.data.message ? res.data.message : 'Error al eliminar.', true );
					}
				} );
				return;
			}

			var createBtn = e.target.closest( '[data-cr-tags-create]' );
			if ( createBtn ) {
				var nameField = wrap.querySelector( '[data-cr-tags-create-name]' );
				var name      = nameField.value.trim();
				if ( ! name ) {
					showStatus( 'Escribe un nombre para la etiqueta.', true );
					return;
				}
				createBtn.disabled = true;
				post( 'cmdroom_tags_create', { name: name } ).then( function ( res ) {
					createBtn.disabled = false;
					if ( res.success ) {
						// Recarga: el listado nuevo necesita una fila más en el
						// <select> de "Fusionar en..." de TODAS las filas
						// existentes, no solo una fila nueva propia -- más simple
						// recargar que reconstruir esas opciones a mano en JS.
						window.location.reload();
					} else {
						showStatus( res.data && res.data.message ? res.data.message : 'Error al crear.', true );
					}
				} );
			}
		} );

		/* — Diálogo "Metas de la etiqueta" — */

		var dialog = document.querySelector( '[data-cr-tags-dialog]' );
		if ( ! dialog ) {
			return;
		}

		var dialogTitleField = dialog.querySelector( '[data-cr-tags-dialog-title]' );
		var dialogDescField  = dialog.querySelector( '[data-cr-tags-dialog-desc]' );
		var dialogSaveBtn    = dialog.querySelector( '[data-cr-tags-dialog-save]' );
		var activeConfigureBtn = null;

		function openDialog( trigger ) {
			activeConfigureBtn = trigger;
			dialogTitleField.value = trigger.getAttribute( 'data-title' ) || '';
			dialogDescField.value  = trigger.getAttribute( 'data-description' ) || '';
			dialog.classList.add( 'is-open' );
			dialogTitleField.focus();
		}

		function closeDialog() {
			dialog.classList.remove( 'is-open' );
			activeConfigureBtn = null;
		}

		wrap.addEventListener( 'click', function ( e ) {
			var configureBtn = e.target.closest( '[data-cr-tags-configure]' );
			if ( configureBtn ) {
				openDialog( configureBtn );
			}
		} );

		dialog.querySelectorAll( '[data-cr-close-dialog]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', closeDialog );
		} );

		dialog.addEventListener( 'click', function ( e ) {
			if ( e.target === dialog ) {
				closeDialog();
			}
		} );

		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key && dialog.classList.contains( 'is-open' ) ) {
				closeDialog();
			}
		} );

		dialogSaveBtn.addEventListener( 'click', function () {
			if ( ! activeConfigureBtn ) {
				return;
			}
			var row = activeConfigureBtn.closest( '.cmdroom-tags-row' );
			var title = dialogTitleField.value.trim();
			var desc  = dialogDescField.value.trim();

			dialogSaveBtn.disabled = true;
			post( 'cmdroom_tags_save_meta', { term_id: row.getAttribute( 'data-term-id' ), title: title, description: desc } ).then( function ( res ) {
				dialogSaveBtn.disabled = false;
				if ( res.success ) {
					activeConfigureBtn.setAttribute( 'data-title', title );
					activeConfigureBtn.setAttribute( 'data-description', desc );
					var pill = row.querySelector( '[data-cr-tags-custom-pill]' );
					if ( pill ) {
						pill.hidden = ! res.data.has_custom;
					}
					showStatus( res.data.message, false );
					closeDialog();
				} else {
					showStatus( res.data && res.data.message ? res.data.message : 'Error al guardar.', true );
				}
			} );
		} );
	} );

	document.addEventListener( 'DOMContentLoaded', function () {
		var bulkWrap = document.querySelector( '[data-cr-tags-bulk]' );
		if ( ! bulkWrap ) {
			return;
		}

		var bulkNonce   = bulkWrap.getAttribute( 'data-nonce' );
		var countEl     = bulkWrap.querySelector( '[data-cr-bulk-count]' );
		var statusEl    = bulkWrap.querySelector( '[data-cr-bulk-status]' );
		var applyBtn    = bulkWrap.querySelector( '[data-cr-bulk-apply]' );
		var addInput    = bulkWrap.querySelector( '#cmdroom-bulk-add' );
		var removeInput = bulkWrap.querySelector( '#cmdroom-bulk-remove' );
		var debounceTimer;

		function bulkPost( action, extra ) {
			var body = new URLSearchParams( Object.assign( { action: action, nonce: bulkNonce }, extra || {} ) );
			return fetch( window.ajaxurl || '/wp-admin/admin-ajax.php', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} ).then( function ( r ) { return r.json(); } );
		}

		function currentFilters() {
			var filters = {};
			bulkWrap.querySelectorAll( '[data-cr-bulk-filter]' ).forEach( function ( field ) {
				filters[ field.getAttribute( 'data-cr-bulk-filter' ) ] = field.value;
			} );
			return filters;
		}

		function refreshCount() {
			countEl.textContent = 'Contando…';
			bulkPost( 'cmdroom_tags_bulk_preview', currentFilters() ).then( function ( res ) {
				if ( res.success ) {
					countEl.textContent = res.data.count + ' posts coinciden con estos filtros.';
				} else {
					countEl.textContent = res.data && res.data.message ? res.data.message : 'Error al contar.';
				}
			} );
		}

		bulkWrap.querySelectorAll( '[data-cr-bulk-filter]' ).forEach( function ( field ) {
			field.addEventListener( 'input', function () {
				clearTimeout( debounceTimer );
				debounceTimer = setTimeout( refreshCount, 400 );
			} );
			field.addEventListener( 'change', refreshCount );
		} );

		refreshCount();

		applyBtn.addEventListener( 'click', function () {
			var addTags    = addInput.value.trim();
			var removeTags = removeInput.value.trim();

			if ( ! addTags && ! removeTags ) {
				statusEl.hidden = false;
				statusEl.classList.add( 'is-error' );
				statusEl.textContent = 'Indica al menos una etiqueta para añadir o quitar.';
				return;
			}
			if ( ! window.confirm( 'Aplicar estos cambios de etiquetas a todos los posts que coinciden con el filtro?' ) ) {
				return;
			}

			applyBtn.disabled = true;
			var payload = Object.assign( { add_tags: addTags, remove_tags: removeTags }, currentFilters() );
			bulkPost( 'cmdroom_tags_bulk_apply', payload ).then( function ( res ) {
				applyBtn.disabled = false;
				statusEl.hidden = false;
				statusEl.classList.toggle( 'is-error', ! res.success );
				statusEl.textContent = res.success ? res.data.message : ( res.data && res.data.message ? res.data.message : 'Error al aplicar.' );
				if ( res.success ) {
					refreshCount();
				}
			} );
		} );
	} );
}() );
