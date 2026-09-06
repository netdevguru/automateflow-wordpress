/**
 * Editor registration for the AutomateFlow form block.
 *
 * Written against wp.element.createElement rather than JSX so the plugin ships without a
 * build step: what is in the repository is exactly what runs, which is both a
 * wordpress.org review expectation and one less thing to keep in sync.
 *
 * The block is server-rendered — see Netdevguru_Bridge_Forms::render_block() — so this file only
 * has to collect the form uuid. It deliberately does not preview the real form: doing so
 * would need the API key in the editor, and the key never leaves the server.
 */
( function ( blocks, element, blockEditor, components, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;

	blocks.registerBlockType( 'netdevguru-bridge/form', {
		title: __( 'AutomateFlow Form', 'netdevguru-bridge-for-automateflow' ),
		description: __( 'Embed a subscription form from your AutomateFlow workspace.', 'netdevguru-bridge-for-automateflow' ),
		icon: 'email-alt',
		category: 'widgets',
		attributes: {
			uuid: { type: 'string', default: '' },
			title: { type: 'string', default: '' }
		},

		edit: function ( props ) {
			var blockProps = blockEditor.useBlockProps();

			return el(
				'div',
				blockProps,
				el(
					components.Placeholder,
					{
						icon: 'email-alt',
						label: __( 'AutomateFlow Form', 'netdevguru-bridge-for-automateflow' ),
						instructions: __(
							'Paste the form UUID from your AutomateFlow workspace. The form renders on the published page.',
							'netdevguru-bridge-for-automateflow'
						)
					},
					el( components.TextControl, {
						label: __( 'Form UUID', 'netdevguru-bridge-for-automateflow' ),
						value: props.attributes.uuid,
						onChange: function ( value ) {
							props.setAttributes( { uuid: value } );
						}
					} ),
					el( components.TextControl, {
						label: __( 'Heading (optional)', 'netdevguru-bridge-for-automateflow' ),
						help: __( 'Overrides the form name from AutomateFlow.', 'netdevguru-bridge-for-automateflow' ),
						value: props.attributes.title,
						onChange: function ( value ) {
							props.setAttributes( { title: value } );
						}
					} )
				)
			);
		},

		// Server-rendered: returning null tells WordPress to store only the attributes and
		// call the PHP render callback at output time.
		save: function () {
			return null;
		}
	} );
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.i18n
);
