/**
 * Command Room — Módulo de afiliación (admin). Los segmentados genéricos
 * (.cr-seg) y los toggles diferidos (data-cr-toggle) ya los cubre
 * servidor-editor.js (se enganchan solos porque esta pantalla también lleva
 * la clase .cmdroom-servidor-wrap) -- este script solo añade lo propio del
 * módulo: el toggle instantáneo de activación (independiente del <form> de
 * cada vista), las filas de producto (Grid/Único) y la Biblioteca (filas +
 * panel de edición + categorías + buscador).
 */
( function () {
	'use strict';

	var MAX_PRODUCTS = 3;

	/* — A. Toggle instantáneo "Activar módulo en el sitio" — */

	function initToggleEnabled( wrap ) {
		var container = wrap.querySelector( '[data-cr-aff-toggle-enabled]' );
		if ( ! container || 'undefined' === typeof cmdroomAffiliate ) {
			return;
		}
		var toggle = container.querySelector( '.cr-toggle' );
		var input  = toggle ? toggle.querySelector( 'input' ) : null;
		var note   = container.querySelector( '[data-cr-aff-activation-note]' );

		container.addEventListener( 'click', function () {
			var next = ! toggle.classList.contains( 'is-on' );
			toggle.classList.toggle( 'is-on', next );
			if ( input ) {
				input.checked = next;
			}
			if ( note ) {
				note.textContent = next
					? 'Se imprime según la ubicación configurada.'
					: 'Mientras esté apagado no se imprime nada (ni CSS ni JS).';
			}
			var body = new URLSearchParams();
			body.set( 'action', 'cmdroom_affiliate_toggle_enabled' );
			body.set( 'value', next ? '1' : '0' );
			body.set( '_wpnonce', container.getAttribute( 'data-nonce' ) || '' );
			fetch( cmdroomAffiliate.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} );
		} );
	}

	/* — B4. Mostrar/ocultar "Después del párrafo [N]" según el segmentado — */

	function initPlacementToggle( wrap ) {
		var row = wrap.querySelector( '.cmdroom-aff-placement-row' );
		if ( ! row ) {
			return;
		}
		var paraField = row.querySelector( '[data-cr-aff-para-field]' );
		if ( ! paraField ) {
			return;
		}
		row.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.cr-seg-opt' );
			if ( ! btn ) {
				return;
			}
			paraField.hidden = 'para' !== btn.getAttribute( 'data-value' );
		} );
	}

	/* — B2. Filas de producto (Grid/Único) — */

	function productRowHTML( num, data ) {
		data = data || {};
		return (
			'<div class="cr-card cmdroom-aff-product">' +
				'<div class="cmdroom-aff-product-top">' +
					'<span class="cmdroom-aff-product-num">Producto ' + num + '</span>' +
					'<span class="cmdroom-aff-product-actions">' +
						'<button type="button" class="cr-btn-secondary cr-btn-compact" data-cr-aff-feature="' + ( num - 1 ) + '">Destacar</button>' +
						'<button type="button" class="cr-btn-secondary cmdroom-aff-remove" data-cr-aff-remove-product>×</button>' +
					'</span>' +
				'</div>' +
				'<div class="cmdroom-aff-product-grid">' +
					'<div><label class="cr-label">Nombre</label><input type="text" class="cr-input" name="p_name[]" value="' + escapeAttr( data.name ) + '" /></div>' +
					'<div><label class="cr-label">Etiqueta</label><input type="text" class="cr-input" name="p_badge[]" value="' + escapeAttr( data.badge ) + '" placeholder="Nuestra elección" /></div>' +
					'<div><label class="cr-label">ASIN</label><input type="text" class="cr-input cmdroom-aff-mono" name="p_asin[]" value="' + escapeAttr( data.asin ) + '" placeholder="B0XXXXXXXX" /></div>' +
				'</div>' +
				'<div><label class="cr-label">Descripción corta · tarjeta</label><input type="text" class="cr-input" name="p_desc[]" value="' + escapeAttr( data.desc ) + '" /></div>' +
				'<div><label class="cr-label">Descripción completa · producto único</label><textarea class="cr-input" name="p_long[]" rows="3">' + escapeHtml( data.long ) + '</textarea></div>' +
				'<div class="cmdroom-aff-product-grid cmdroom-aff-product-grid--ratings">' +
					'<div><label class="cr-label">Valoración (1-5)</label><input type="number" step="0.1" min="0" max="5" class="cr-input" name="p_rating[]" value="' + escapeAttr( data.rating ) + '" placeholder="—" /></div>' +
					'<div><label class="cr-label">Nº de opiniones</label><input type="number" step="1" min="0" class="cr-input" name="p_reviews[]" value="' + escapeAttr( data.reviews ) + '" placeholder="—" /></div>' +
				'</div>' +
			'</div>'
		);
	}

	function escapeHtml( str ) {
		var div = document.createElement( 'div' );
		div.textContent = String( null == str ? '' : str );
		return div.innerHTML;
	}

	function escapeAttr( str ) {
		return escapeHtml( str ).split( '"' ).join( '&quot;' );
	}

	function initProductRows( wrap ) {
		var list = wrap.querySelector( '.cmdroom-aff-products' );
		if ( ! list ) {
			return;
		}
		var addRow      = wrap.querySelector( '.cmdroom-aff-add-row' );
		var addBtn      = wrap.querySelector( '[data-cr-aff-add-product]' );
		var librarySel  = wrap.querySelector( '[data-cr-aff-add-from-library]' );
		var featuredInput = wrap.querySelector( '#cmdroom-aff-featured-input' );

		function renumber() {
			var cards = list.querySelectorAll( '.cmdroom-aff-product' );
			cards.forEach( function ( card, i ) {
				var num = card.querySelector( '.cmdroom-aff-product-num' );
				if ( num && ! num.querySelector( '.cr-pill' ) ) {
					num.textContent = 'Producto ' + ( i + 1 );
				}
				var featureBtn = card.querySelector( '[data-cr-aff-feature]' );
				if ( featureBtn ) {
					featureBtn.setAttribute( 'data-cr-aff-feature', i );
				}
			} );
			if ( addRow ) {
				addRow.hidden = cards.length >= MAX_PRODUCTS;
			}
		}

		function addProduct( data ) {
			var count = list.querySelectorAll( '.mdroom-aff-product, .cmdroom-aff-product' ).length;
			if ( count >= MAX_PRODUCTS ) {
				return;
			}
			var div = document.createElement( 'div' );
			div.innerHTML = productRowHTML( count + 1, data || {} );
			list.appendChild( div.firstChild );
			renumber();
		}

		if ( addBtn ) {
			addBtn.addEventListener( 'click', function () {
				addProduct( {} );
			} );
		}

		if ( librarySel ) {
			librarySel.addEventListener( 'change', function () {
				var opt = librarySel.options[ librarySel.selectedIndex ];
				if ( ! opt || ! opt.value ) {
					return;
				}
				addProduct( {
					name: opt.getAttribute( 'data-name' ) || '',
					badge: opt.getAttribute( 'data-badge' ) || '',
					asin: opt.value,
					desc: opt.getAttribute( 'data-desc' ) || '',
					long: opt.getAttribute( 'data-long' ) || '',
					rating: opt.getAttribute( 'data-rating' ) || '',
					reviews: opt.getAttribute( 'data-reviews' ) || '',
				} );
				librarySel.selectedIndex = 0;
			} );
		}

		list.addEventListener( 'click', function ( e ) {
			var removeBtn = e.target.closest( '[data-cr-aff-remove-product]' );
			if ( removeBtn ) {
				var card = removeBtn.closest( '.cmdroom-aff-product' );
				if ( card ) {
					var cards = Array.prototype.slice.call( list.querySelectorAll( '.cmdroom-aff-product' ) );
					var removedIndex = cards.indexOf( card );
					card.remove();
					// "Si se quita uno anterior, el índice del destacado se
					// ajusta" (handoff) -- si se quita el propio destacado,
					// vuelve a ser el primero (índice 0).
					if ( featuredInput && removedIndex > -1 ) {
						var currentFeatured = parseInt( featuredInput.value, 10 ) || 0;
						if ( removedIndex < currentFeatured ) {
							featuredInput.value = currentFeatured - 1;
						} else if ( removedIndex === currentFeatured ) {
							featuredInput.value = 0;
						}
					}
					renumber();
				}
				return;
			}
			var featureBtn = e.target.closest( '[data-cr-aff-feature]' );
			if ( featureBtn ) {
				var idx = featureBtn.getAttribute( 'data-cr-aff-feature' );
				if ( featuredInput ) {
					featuredInput.value = idx;
				}
				list.querySelectorAll( '.cmdroom-aff-product' ).forEach( function ( card, i ) {
					card.classList.toggle( 'cmdroom-aff-product--dim', false );
					var numEl = card.querySelector( '.cmdroom-aff-product-num' );
					var actions = card.querySelector( '.cmdroom-aff-product-actions' );
					if ( numEl ) {
						var isNowFeatured = String( i ) === String( idx );
						numEl.innerHTML = 'Producto ' + ( i + 1 ) + ( isNowFeatured ? ' <span class="cr-pill cmdroom-aff-pill-accent">CTA destacado</span>' : '' );
						if ( actions ) {
							var destacarBtn = actions.querySelector( '[data-cr-aff-feature]' );
							if ( destacarBtn ) {
								destacarBtn.hidden = isNowFeatured;
							}
						}
					}
				} );
			}
		} );

		renumber();
	}

	/* — C. Biblioteca — */

	function libRowHTML( catButtons ) {
		return (
			'<div class="cmdroom-aff-lib-row" data-cr-aff-lib-row data-search="">' +
				'<div class="cmdroom-aff-lib-row-main">' +
					'<div class="cmdroom-aff-lib-cell-product"><p class="cmdroom-aff-lib-name">(sin nombre)</p><p class="cmdroom-aff-lib-meta">—</p></div>' +
					'<div class="cmdroom-aff-lib-cell-cats"><span class="cr-pill cmdroom-aff-pill-muted">Sin categoría</span></div>' +
					'<div class="cmdroom-aff-lib-cell-usage">Sin usar</div>' +
					'<div class="cmdroom-aff-lib-cell-actions">' +
						'<button type="button" class="cr-btn-secondary cr-btn-compact" data-cr-aff-lib-edit-toggle>Cerrar</button>' +
						'<button type="button" class="cr-btn-secondary cmdroom-aff-remove" data-cr-aff-lib-remove>×</button>' +
					'</div>' +
				'</div>' +
				'<div class="cmdroom-aff-lib-edit" data-cr-aff-lib-edit>' +
					'<input type="hidden" name="lib_id[]" value="" />' +
					'<div class="cmdroom-aff-product-grid">' +
						'<div><label class="cr-label">Nombre</label><input type="text" class="cr-input" name="lib_name[]" value="" data-cr-aff-lib-name-input /></div>' +
						'<div><label class="cr-label">Etiqueta</label><input type="text" class="cr-input" name="lib_badge[]" value="" placeholder="Nuestra elección" /></div>' +
						'<div><label class="cr-label">ASIN</label><input type="text" class="cr-input cmdroom-aff-mono" name="lib_asin[]" value="" placeholder="B0XXXXXXXX" data-cr-aff-lib-asin-input /></div>' +
					'</div>' +
					'<div><label class="cr-label">Descripción corta</label><input type="text" class="cr-input" name="lib_desc[]" value="" /></div>' +
					'<div><label class="cr-label">Descripción completa</label><textarea class="cr-input" name="lib_long[]" rows="3"></textarea></div>' +
					'<div class="cmdroom-aff-product-grid cmdroom-aff-product-grid--ratings">' +
						'<div><label class="cr-label">Valoración (1-5)</label><input type="number" step="0.1" min="0" max="5" class="cr-input" name="lib_rating[]" value="" placeholder="—" /></div>' +
						'<div><label class="cr-label">Nº de opiniones</label><input type="number" step="1" min="0" class="cr-input" name="lib_reviews[]" value="" placeholder="—" /></div>' +
					'</div>' +
					'<div><label class="cr-label">Categorías de la web</label><div class="cmdroom-aff-cat-picker" data-cr-aff-cat-picker>' + catButtons + '</div>' +
					'<input type="hidden" name="lib_cats[]" value="" data-cr-aff-cats-input /></div>' +
				'</div>' +
			'</div>'
		);
	}

	function initLibrary( wrap ) {
		var rowsContainer = wrap.querySelector( '[data-cr-aff-lib-rows]' );
		if ( ! rowsContainer ) {
			return;
		}
		var addBtn   = wrap.querySelector( '[data-cr-aff-lib-add]' );
		var search   = wrap.querySelector( '[data-cr-aff-lib-search]' );
		var emptyMsg = wrap.querySelector( '[data-cr-aff-lib-empty]' );

		// Plantilla de botones de categoría a partir de una fila ya renderizada
		// por PHP (misma lista de categorías reales de WordPress) -- evita
		// duplicar aquí el catálogo de categorías.
		function catButtonsTemplate() {
			var existingPicker = wrap.querySelector( '.cmdroom-aff-cat-picker' );
			if ( ! existingPicker ) {
				return '';
			}
			var clone = existingPicker.cloneNode( true );
			clone.querySelectorAll( '.is-active' ).forEach( function ( btn ) {
				btn.classList.remove( 'is-active' );
			} );
			return clone.innerHTML;
		}

		function updateEmptyState() {
			if ( emptyMsg ) {
				emptyMsg.hidden = rowsContainer.querySelectorAll( '.cmdroom-aff-lib-row' ).length > 0;
			}
		}

		if ( addBtn ) {
			addBtn.addEventListener( 'click', function () {
				var div = document.createElement( 'div' );
				div.innerHTML = libRowHTML( catButtonsTemplate() );
				var row = div.firstChild;
				rowsContainer.insertBefore( row, rowsContainer.firstChild );
				var edit = row.querySelector( '[data-cr-aff-lib-edit]' );
				if ( edit ) {
					edit.hidden = false;
				}
				var editBtn = row.querySelector( '[data-cr-aff-lib-edit-toggle]' );
				if ( editBtn ) {
					editBtn.textContent = 'Cerrar';
				}
				updateEmptyState();
				var nameInput = row.querySelector( '[data-cr-aff-lib-name-input]' );
				if ( nameInput ) {
					nameInput.focus();
				}
			} );
		}

		if ( search ) {
			search.addEventListener( 'input', function () {
				var q = search.value.trim().toLowerCase();
				rowsContainer.querySelectorAll( '[data-cr-aff-lib-row]' ).forEach( function ( row ) {
					var nameInput = row.querySelector( '[data-cr-aff-lib-name-input]' );
					var asinInput = row.querySelector( '[data-cr-aff-lib-asin-input]' );
					var haystack = ( row.getAttribute( 'data-search' ) || '' )
						+ ' ' + ( nameInput ? nameInput.value : '' )
						+ ' ' + ( asinInput ? asinInput.value : '' );
					row.hidden = '' !== q && -1 === haystack.toLowerCase().indexOf( q );
				} );
			} );
		}

		rowsContainer.addEventListener( 'click', function ( e ) {
			var editToggle = e.target.closest( '[data-cr-aff-lib-edit-toggle]' );
			if ( editToggle ) {
				var row  = editToggle.closest( '.cmdroom-aff-lib-row' );
				var edit = row ? row.querySelector( '[data-cr-aff-lib-edit]' ) : null;
				if ( edit ) {
					var nowHidden = ! edit.hidden;
					edit.hidden = nowHidden;
					editToggle.textContent = nowHidden ? 'Editar' : 'Cerrar';
				}
				return;
			}
			var removeBtn = e.target.closest( '[data-cr-aff-lib-remove]' );
			if ( removeBtn ) {
				var toRemove = removeBtn.closest( '.cmdroom-aff-lib-row' );
				if ( toRemove ) {
					toRemove.remove();
					updateEmptyState();
				}
				return;
			}
			var catBtn = e.target.closest( '.cmdroom-aff-cat-btn' );
			if ( catBtn ) {
				var picker = catBtn.closest( '[data-cr-aff-cat-picker]' );
				var hiddenInput = picker ? picker.parentElement.querySelector( '[data-cr-aff-cats-input]' ) : null;
				catBtn.classList.toggle( 'is-active' );
				if ( hiddenInput && picker ) {
					var ids = Array.prototype.slice.call( picker.querySelectorAll( '.is-active' ) ).map( function ( b ) {
						return b.getAttribute( 'data-term' );
					} );
					hiddenInput.value = ids.join( ',' );
				}
			}
		} );

		updateEmptyState();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var wrap = document.querySelector( '.cmdroom-components-wrap' );
		if ( ! wrap ) {
			return;
		}
		initToggleEnabled( wrap );
		initPlacementToggle( wrap );
		initProductRows( wrap );
		initLibrary( wrap );
	} );
} )();
