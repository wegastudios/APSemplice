/* APSemplice — interazioni della schermata di amministrazione (nessuna dipendenza). */
(function () {
	'use strict';

	var $ = function (s, r) { return (r || document).querySelector(s); };
	var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
	var MONTHS = ['Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];

	function el(tag, attrs, children) {
		var e = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (k === 'text') { e.textContent = attrs[k]; } else { e.setAttribute(k, attrs[k]); }
		});
		(children || []).forEach(function (c) { e.appendChild(c); });
		return e;
	}
	function eur(cents) { return new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(cents / 100); }
	function plain(cents) { return (cents / 100).toFixed(2).replace('.', ','); }
	function parseMoney(s) {
		if (s == null) { return null; }
		s = String(s).replace(/[€\s ]/g, '');
		if (!s) { return null; }
		if (s.indexOf(',') > -1) { s = s.replace(/\./g, '').replace(',', '.'); } else if (/^\d{1,3}(\.\d{3})+$/.test(s)) { s = s.replace(/\./g, ''); }
		if (!/^-?\d+(\.\d+)?$/.test(s)) { return null; }
		return Math.round(parseFloat(s) * 100);
	}
	function monthLabel(ym) { return MONTHS[parseInt(ym.substr(5, 2), 10) - 1] + ' ' + ym.substr(0, 4); }
	function shiftMonth(ym, d) {
		var y = parseInt(ym.substr(0, 4), 10), m = parseInt(ym.substr(5, 2), 10) - 1 + d;
		y += Math.floor(m / 12); m = ((m % 12) + 12) % 12;
		return y + '-' + ('0' + (m + 1)).slice(-2);
	}

	/* Conferma prima di azioni distruttive */
	document.addEventListener('click', function (e) {
		var t = e.target.closest ? e.target.closest('[data-confirm]') : null;
		if (t && !window.confirm(t.getAttribute('data-confirm'))) { e.preventDefault(); }
	});

	/* Elenchi lunghi: campo di testo che filtra la select collegata */
	$$('.aps-filter').forEach(function (inp) {
		var sel = $(inp.getAttribute('data-target'));
		if (!sel) { return; }
		var all = $$('option', sel).map(function (o) { return { value: o.value, label: o.textContent }; });
		inp.addEventListener('input', function () {
			var q = inp.value.trim().toLowerCase(), cur = sel.value;
			sel.innerHTML = '';
			all.forEach(function (o) {
				if (!q || o.value === '' || o.label.toLowerCase().indexOf(q) > -1 || o.value === cur) {
					var opt = el('option', { value: o.value, text: o.label });
					if (o.value === cur) { opt.selected = true; }
					sel.appendChild(opt);
				}
			});
		});
	});

	/* Scheda persona: regole per tipo */
	var typeSel = $('#aps-type');
	if (typeSel) {
		var hints = {
			founder: 'Socio fondatore: tessera sempre rinnovata (scadenza a lungo termine, vedi Impostazioni). Email obbligatoria.',
			ordinary: 'Socio ordinario: tessera valida per anno sociale, si rinnova con la quota associativa. Email obbligatoria.',
			volunteer: 'Socio e volontario: come l\'ordinario, e può tenere le attività. Email obbligatoria.',
			guest: 'Ospite: non è socio, partecipa alle attività tramite il socio che lo ospita. Non ha tessera.'
		};
		var applyType = function () {
			var t = typeSel.value, guest = t === 'guest';
			$$('.aps-row-host').forEach(function (r) { r.style.display = guest ? '' : 'none'; });
			$$('.aps-row-card').forEach(function (r) { r.style.display = guest ? 'none' : ''; });
			$$('.aps-email-req, .aps-email-note').forEach(function (r) { r.style.display = guest ? 'none' : ''; });
			var hint = $('#aps-type-hint'); if (hint) { hint.textContent = hints[t] || ''; }
			var mail = $('#aps-email'); if (mail) { mail.required = !guest; }
		};
		typeSel.addEventListener('change', applyType);
		applyType();
	}
	var nextCard = $('#aps-next-card');
	if (nextCard) {
		nextCard.addEventListener('click', function () { $('#aps-card').value = nextCard.getAttribute('data-next'); });
	}

	/* Incasso multi-voce con calcolo del resto */
	var dataEl = $('#aps-income-data');
	if (!dataEl) { return; }
	var D = JSON.parse(dataEl.textContent);
	var form = $('form.aps-income');
	var box = $('#aps-lines');
	var lines = [], seq = 0, ctx = null;
	var DENOMS = [50000, 20000, 10000, 5000, 2000, 1000, 500, 200, 100, 50, 20, 10, 5, 2, 1];

	function catByKind(kind) { return D.categories.filter(function (c) { return c.kind === kind; })[0]; }
	function addLine(l) { l.key = ++seq; lines.push(l); render(); }

	function render() {
		box.innerHTML = '';
		lines.forEach(function (l, i) {
			var n = 'lines[' + i + ']';
			var row = el('div', { 'class': 'aps-line' });
			row.appendChild(el('span', { 'class': 'aps-line-title', text: l.title }));
			if (l.month) {
				var prev = el('button', { type: 'button', 'class': 'button button-small', text: '‹' });
				var next = el('button', { type: 'button', 'class': 'button button-small', text: '›' });
				prev.addEventListener('click', function () { l.month = shiftMonth(l.month, -1); render(); });
				next.addEventListener('click', function () { l.month = shiftMonth(l.month, 1); render(); });
				row.appendChild(prev); row.appendChild(el('span', { text: monthLabel(l.month) })); row.appendChild(next);
			}
			if (l.kind === 'membership') {
				var ys = el('select', { name: n + '[social_year]' });
				[D.socialYear, D.nextYear].forEach(function (y) {
					var o = el('option', { value: y, text: 'Anno sociale ' + y }); if (y === l.socialYear) { o.selected = true; } ys.appendChild(o);
				});
				ys.addEventListener('change', function () { l.socialYear = ys.value; });
				row.appendChild(ys);
			}
			var amt = el('input', { type: 'text', name: n + '[amount]', inputmode: 'decimal', value: l.amount, size: '8', 'aria-label': 'Importo' });
			amt.addEventListener('input', function () { l.amount = amt.value; update(); });
			row.appendChild(amt); row.appendChild(el('span', { text: '€' }));
			[['category_id', l.category], ['activity_id', l.activity || ''], ['competence_month', l.month || ''], ['description', l.title]].forEach(function (p) {
				row.appendChild(el('input', { type: 'hidden', name: n + '[' + p[0] + ']', value: p[1] }));
			});
			var rm = el('button', { type: 'button', 'class': 'button button-link-delete', text: 'Rimuovi' });
			rm.addEventListener('click', function () { lines.splice(i, 1); render(); });
			row.appendChild(rm);
			box.appendChild(row);
		});
		update();
	}

	function total() { return lines.reduce(function (s, l) { return s + (parseMoney(l.amount) || 0); }, 0); }

	function update() {
		var t = total();
		$('#aps-total').textContent = eur(t);
		var cash = $('#aps-method').value === 'cash' && t > 0;
		$('#aps-cash').style.display = cash ? '' : 'none';
		if (!cash) { return; }
		var tenderedEl = $('#aps-tendered');
		var tendered = parseMoney(tenderedEl.value);
		if (tendered == null) { tendered = t; }
		var quick = $('#aps-quick'); quick.innerHTML = '';
		var q = [t]; [500, 1000, 2000, 5000, 10000, 20000].forEach(function (x) { if (x >= t && q.indexOf(x) < 0) { q.push(x); } });
		q.slice(0, 4).forEach(function (v) {
			var b = el('button', { type: 'button', 'class': 'button', text: v === t ? 'Esatto' : eur(v).replace(',00', '') });
			b.addEventListener('click', function () { tenderedEl.value = plain(v); update(); });
			quick.appendChild(b);
		});
		var out = $('#aps-change');
		if (tendered < t) {
			out.className = 'aps-change aps-neg'; out.textContent = 'Mancano ' + eur(t - tendered);
		} else {
			var rest = tendered - t, parts = [];
			DENOMS.forEach(function (d) { var k = Math.floor(rest / d); if (k > 0) { parts.push(k + ' × ' + eur(d)); rest -= k * d; } });
			out.className = 'aps-change aps-ok';
			out.textContent = 'Resto da dare: ' + eur(tendered - t) + (parts.length ? ' (' + parts.join(' · ') + ')' : '');
		}
	}

	/* Voci: quota associativa, mensilità, altro */
	$('#aps-add-membership').addEventListener('click', function () {
		var c = catByKind('membership'); if (!c) { return; }
		addLine({ title: 'Quota associativa', category: c.id, kind: 'membership', socialYear: D.socialYear, amount: plain(D.membershipFee) });
	});

	var actSel = $('#aps-add-activity-select'), otherSel = $('#aps-add-other-select');
	D.categories.forEach(function (c) { otherSel.appendChild(el('option', { value: c.id, text: c.name })); });
	function fillActivities() {
		actSel.innerHTML = '';
		actSel.appendChild(el('option', { value: '', text: '+ Mensilità attività…' }));
		var mine = {};
		(ctx && ctx.activities || []).forEach(function (a) { mine[a.id] = a; });
		var ordered = D.activities.slice().sort(function (a, b) { return (mine[b.id] ? 1 : 0) - (mine[a.id] ? 1 : 0); });
		ordered.forEach(function (a) { actSel.appendChild(el('option', { value: a.id, text: a.name + (mine[a.id] ? ' ✓ iscritto' : '') })); });
	}
	actSel.addEventListener('change', function () {
		var id = parseInt(actSel.value, 10); actSel.value = ''; if (!id) { return; }
		var a = D.activities.filter(function (x) { return x.id === id; })[0];
		var c = catByKind('activity_fee'); if (!a || !c) { return; }
		var mine = (ctx && ctx.activities || []).filter(function (x) { return x.id === id; })[0];
		var month = mine ? mine.month : $('#aps-date').value.substr(0, 7);
		addLine({ title: a.name, category: c.id, kind: 'activity_fee', activity: a.id, month: month, amount: plain(a.fee) });
	});
	otherSel.addEventListener('change', function () {
		var id = parseInt(otherSel.value, 10); otherSel.value = ''; if (!id) { return; }
		var c = D.categories.filter(function (x) { return x.id === id; })[0];
		if (c) { addLine({ title: c.name, category: c.id, kind: c.kind, amount: '' }); }
	});

	/* Persona scelta: tessera, attività a cui è iscritta, mese da pagare */
	function loadContext() {
		var pid = $('#aps-person-select').value, info = $('#aps-person-info');
		ctx = null; fillActivities(); info.textContent = ''; info.className = 'aps-info';
		if (!pid) { return; }
		var fd = new FormData();
		fd.append('action', 'aps_person_context'); fd.append('nonce', D.nonce); fd.append('person_id', pid); fd.append('date', $('#aps-date').value);
		fetch(D.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); }).then(function (res) {
			if (!res.success || !res.data.person) { return; }
			ctx = res.data; fillActivities();
			var txt = ctx.person.type_label;
			if (ctx.is_guest) { txt += ' · non è socio: paga solo le attività'; }
			else if (ctx.is_founder) { txt += ' · tessera sempre rinnovata'; }
			else if (ctx.needs_membership) { txt += ' · ⚠ tessera non valida: serve la quota associativa'; info.className = 'aps-info aps-info-warn'; }
			else { txt += ' · tessera valida fino al ' + ctx.active_until.split('-').reverse().join('/'); }
			info.textContent = txt;
		});
	}
	$('#aps-person-select').addEventListener('change', loadContext);
	$('#aps-date').addEventListener('change', loadContext);

	/* Modalità di pagamento: propone il conto giusto */
	$('#aps-method').addEventListener('change', function () {
		var acc = D.accountByMethod[this.value]; if (acc) { $('#aps-account').value = String(acc); }
		update();
	});
	$('#aps-tendered').addEventListener('input', update);

	form.addEventListener('submit', function (e) {
		var bad = !lines.length || lines.some(function (l) { return !(parseMoney(l.amount) > 0); });
		if (bad) { e.preventDefault(); window.alert('Aggiungi almeno una voce e inserisci un importo valido per ognuna.'); return; }
		if ($('#aps-method').value === 'cash') {
			var t = parseMoney($('#aps-tendered').value);
			if (t != null && t < total()) { e.preventDefault(); window.alert('I contanti ricevuti non bastano.'); }
		}
	});

	fillActivities();
	render();
})();
