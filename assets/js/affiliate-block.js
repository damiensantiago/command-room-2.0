/**
 * Bloque de Gutenberg "command-room/afiliacion" -- sin build tooling (el
 * repo no tiene webpack/babel), registrado directamente con wp.element como
 * el resto de scripts admin del plugin. render_callback en PHP
 * (Cmdroom_Affiliate::block_render_callback()) reenvía al mismo shortcode
 * handler que [cmdroom_afiliacion], así que la vista previa del editor
 * (ServerSideRender) y el resultado real en el sitio son siempre el mismo
 * marcado -- ver Cmdroom_Affiliate::render().
 *
 * Degrada con seguridad: si wp-server-side-render no está disponible en el
 * núcleo de WordPress del sitio, el script simplemente no se registra (ver
 * Cmdroom_Affiliate::register_block(), dependencia del wp_register_script) y
 * el shortcode/admin siguen funcionando exactamente igual sin el bloque.
 */
( function ( blocks, element, blockEditor, components, i18n, serverSideRender ) {
	if ( ! blocks || ! element || ! serverSideRender ) {
		return;
	}

	var el = element.createElement;
	var __ = i18n.__;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var TextControl = components.TextControl;
	var ServerSideRender = serverSideRender;

	blocks.registerBlockType( 'command-room/afiliacion', {
		title: __( 'Módulo de afiliación', 'command-room' ),
		description: __( 'Productos de afiliado de Amazon (grid comparativa, producto único o carrusel de la biblioteca).', 'command-room' ),
		icon: 'cart',
		category: 'widgets',
		attributes: {
			formato: { type: 'string', default: 'grid' },
			productos: { type: 'string', default: '' },
			producto: { type: 'string', default: '' },
			asins: { type: 'string', default: '' },
		},

		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			var controls = [
				el( SelectControl, {
					key: 'formato',
					label: __( 'Formato', 'command-room' ),
					value: attributes.formato,
					options: [
						{ label: __( 'Grid comparativa', 'command-room' ), value: 'grid' },
						{ label: __( 'Producto único', 'command-room' ), value: 'unico' },
						{ label: __( 'Carrusel', 'command-room' ), value: 'carrusel' },
					],
					onChange: function ( value ) {
						setAttributes( { formato: value } );
					},
				} ),
			];

			if ( 'unico' === attributes.formato ) {
				controls.push(
					el( TextControl, {
						key: 'producto',
						label: __( 'Producto (nº, del módulo global)', 'command-room' ),
						value: attributes.producto,
						onChange: function ( value ) {
							setAttributes( { producto: value } );
						},
					} )
				);
			} else {
				controls.push(
					el( TextControl, {
						key: 'productos',
						label: __( 'Productos (índices separados por coma, ej. 1,2,3)', 'command-room' ),
						value: attributes.productos,
						onChange: function ( value ) {
							setAttributes( { productos: value } );
						},
					} )
				);
			}

			controls.push(
				el( TextControl, {
					key: 'asins',
					label: __( 'ASINs de la biblioteca (opcional, tiene prioridad)', 'command-room' ),
					help: __( 'Ej. B0XXXXXXX1,B0XXXXXXX2 -- si se rellena, ignora Productos/Producto.', 'command-room' ),
					value: attributes.asins,
					onChange: function ( value ) {
						setAttributes( { asins: value } );
					},
				} )
			);

			return el(
				element.Fragment,
				{},
				el(
					InspectorControls,
					{},
					el( PanelBody, { title: __( 'Ajustes del módulo', 'command-room' ) }, controls )
				),
				el( ServerSideRender, {
					block: 'command-room/afiliacion',
					attributes: attributes,
				} )
			);
		},

		save: function () {
			return null; // render_callback dinámico -- nada que guardar en el post_content además del bloque.
		},
	} );
} )(
	window.wp && window.wp.blocks,
	window.wp && window.wp.element,
	window.wp && ( window.wp.blockEditor || window.wp.editor ),
	window.wp && window.wp.components,
	window.wp && window.wp.i18n,
	window.wp && window.wp.serverSideRender
);
