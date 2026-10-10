/* AssociazioneSemplice — blocchi Gutenberg (senza build). Il disegno è sempre fatto dal server. */
(function (wp) {
	'use strict';
	var el = wp.element.createElement;
	var registerBlockType = wp.blocks.registerBlockType;
	var InspectorControls = (wp.blockEditor || wp.editor).InspectorControls;
	var InnerBlocks = (wp.blockEditor || wp.editor).InnerBlocks;
	var useBlockProps = (wp.blockEditor || wp.editor).useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var TextControl = wp.components.TextControl;
	var ServerSideRender = wp.serverSideRender || wp.components.ServerSideRender;
	var D = window.ASEM_BLOCKS || { views: {}, rules: {}, activities: [] };

	function options(map, emptyLabel) {
		var out = emptyLabel ? [{ value: '', label: emptyLabel }] : [];
		Object.keys(map).forEach(function (k) { out.push({ value: k, label: map[k] }); });
		return out;
	}

	/* --- Vista AssociazioneSemplice (area soci, tessera, attività, prossimi eventi, …) --- */
	registerBlockType('associazionesemplice/vista', {
		apiVersion: 2,
		title: 'AssociazioneSemplice',
		description: 'Area soci, tessera, attività ed eventi dell\'associazione.',
		icon: 'groups',
		category: 'widgets',
		keywords: ['soci', 'tessera', 'attività', 'eventi'],
		edit: function (props) {
			var a = props.attributes, set = props.setAttributes, v = a.vista;
			var controls = [
				el(SelectControl, { key: 'v', label: 'Cosa mostrare', value: v, options: options(D.views), onChange: function (x) { set({ vista: x }); } })
			];
			if (v === 'attivita') {
				controls.push(
					el(TextControl, { key: 'a', label: 'Anno sociale (es. 2025/2026, vuoto = in corso)', value: a.anno, onChange: function (x) { set({ anno: x }); } }),
					el(SelectControl, { key: 't', label: 'Tipo di attività', value: a.tipo, options: [
						{ value: '', label: 'Tutte' }, { value: 'corso', label: 'Corsi' }, { value: 'evento', label: 'Eventi una tantum' }, { value: 'ricorrente', label: 'Eventi ricorrenti' }
					], onChange: function (x) { set({ tipo: x }); } }),
					el(TextControl, { key: 'i', label: 'Solo questa attività (ID, facoltativo)', value: a.id, onChange: function (x) { set({ id: x }); } }),
					el(TextControl, { key: 'd', label: 'Date da mostrare per attività', value: a.date, onChange: function (x) { set({ date: x }); } })
				);
			}
			if (v === 'prossimi_eventi') {
				controls.push(el(TextControl, { key: 'l', label: 'Numero di eventi', value: a.limite, onChange: function (x) { set({ limite: x }); } }));
			}
			return el('div', useBlockProps ? useBlockProps() : {},
				el(InspectorControls, null, el(PanelBody, { title: 'AssociazioneSemplice', initialOpen: true }, controls)),
				el(ServerSideRender, { block: 'associazionesemplice/vista', attributes: a })
			);
		},
		save: function () { return null; }
	});

	/* --- Contenuto riservato: i blocchi dentro li vedono solo gli aventi diritto --- */
	var rules = Object.assign({}, D.rules); delete rules['public'];
	registerBlockType('associazionesemplice/riservato', {
		apiVersion: 2,
		title: 'Contenuto riservato',
		description: 'I blocchi al suo interno sono visibili solo a soci, volontari o iscritti a specifiche attività.',
		icon: 'lock',
		category: 'widgets',
		keywords: ['riservato', 'soci', 'privato'],
		edit: function (props) {
			var a = props.attributes, set = props.setAttributes;
			var controls = [
				el(SelectControl, { key: 'r', label: 'Chi può vederlo', value: a.accesso, options: options(rules), onChange: function (x) { set({ accesso: x }); } })
			];
			if (a.accesso === 'activity') {
				controls.push(el(SelectControl, {
					key: 'act', label: 'Attività', multiple: true, value: (a.attivita || []).map(String), options: D.activities.map(function (x) { return { value: String(x.value), label: x.label }; }),
					onChange: function (vals) { set({ attivita: (vals || []).map(function (n) { return parseInt(n, 10); }) }); }
				}));
			}
			controls.push(el(TextControl, { key: 'm', label: 'Messaggio per gli altri (facoltativo)', value: a.messaggio, onChange: function (x) { set({ messaggio: x }); } }));
			var label = rules[a.accesso] || '';
			return el('div', useBlockProps ? useBlockProps({ style: { border: '1px dashed #999', padding: '12px', borderRadius: '6px' } }) : {},
				el(InspectorControls, null, el(PanelBody, { title: 'Accesso', initialOpen: true }, controls)),
				el('p', { style: { margin: '0 0 8px', fontSize: '12px', opacity: 0.8 } }, '🔒 Contenuto riservato — ' + label),
				el(InnerBlocks, null)
			);
		},
		save: function () { return el(InnerBlocks.Content); }
	});
})(window.wp);
