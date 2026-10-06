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

	/* Popup di licenza: chiudibile solo se il pulsante c'è (prima settimana) */
	document.addEventListener('click', function (e) {
		if (e.target && e.target.id === 'apse-overlay-close') { var o = $('#apse-license-overlay'); if (o) { o.parentNode.removeChild(o); } }
	});

	/* Conferma prima di azioni distruttive */
	document.addEventListener('click', function (e) {
		var t = e.target.closest ? e.target.closest('[data-confirm]') : null;
		if (t && !window.confirm(t.getAttribute('data-confirm'))) { e.preventDefault(); }
	});

	/* Elenchi lunghi: campo di testo che filtra la select collegata */
	$$('.apse-filter').forEach(function (inp) {
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
	var typeSel = $('#apse-type');
	if (typeSel) {
		var hints = {
			founder: 'Socio fondatore: tessera sempre rinnovata (scadenza a lungo termine, vedi Impostazioni). Email obbligatoria.',
			ordinary: 'Socio ordinario: tessera valida per anno sociale, si rinnova con la quota associativa. Email obbligatoria.',
			volunteer: 'Socio e volontario: come l\'ordinario, e può tenere le attività. Email obbligatoria.',
			guest: 'Ospite: non è socio, partecipa alle attività tramite il socio che lo ospita. Non ha tessera.'
		};
		var applyType = function () {
			var t = typeSel.value, guest = t === 'guest';
			$$('.apse-row-host').forEach(function (r) { r.style.display = guest ? '' : 'none'; });
			$$('.apse-row-card').forEach(function (r) { r.style.display = guest ? 'none' : ''; });
			$$('.apse-email-note').forEach(function (r) { r.style.display = guest ? 'none' : ''; });
			var hint = $('#apse-type-hint'); if (hint) { hint.textContent = hints[t] || ''; }
			var mail = $('#apse-email'); if (mail) { mail.required = false; }
		};
		typeSel.addEventListener('change', applyType);
		applyType();
	}
	/* Impostazioni: mostra solo i campi del gateway scelto */
	var payProvider = $('#apse-pay-provider');
	if (payProvider) {
		var applyProvider = function () {
			var p = payProvider.value;
			$$('.apse-pay-stripe').forEach(function (r) { r.style.display = p === 'stripe' ? '' : 'none'; });
			$$('.apse-pay-paypal').forEach(function (r) { r.style.display = p === 'paypal' ? '' : 'none'; });
		};
		payProvider.addEventListener('change', applyProvider);
		applyProvider();
	}

	var nextCard = $('#apse-next-card');
	if (nextCard) {
		nextCard.addEventListener('click', function () { $('#apse-card').value = nextCard.getAttribute('data-next'); });
	}

	/* Nuova attività: campi diversi per tipo */
	var kindSel = $('#apse-kind');
	if (kindSel) {
		var kindHints = {
			course: 'Corso: i soci si iscrivono per mesi e pagano una quota mensile (anche gratuita).',
			event: 'Evento una tantum: una data, prenotazione obbligatoria, un contributo per partecipante (anche gratuito).',
			recurring: 'Evento ricorrente: molte date (le aggiungi dopo la creazione); ci si iscrive al singolo evento e si paga per ogni evento.'
		};
		var applyKind = function () {
			var k = kindSel.value;
			$$('.apse-row-event').forEach(function (r) { r.style.display = k === 'event' ? '' : 'none'; });
			$$('.apse-row-sessions').forEach(function (r) { r.style.display = k === 'course' ? 'none' : ''; });
			$$('.apse-row-course').forEach(function (r) { r.style.display = k === 'course' ? '' : 'none'; });
			$$('.apse-row-event input[name="session_date"]').forEach(function (i) { i.required = k === 'event'; });
			$$('.apse-fee-label').forEach(function (l) { l.textContent = k === 'course' ? 'Contributo soci' : 'Contributo soci (a evento)'; });
			var hint = $('#apse-kind-hint'); if (hint) { hint.textContent = kindHints[k] || ''; }
		};
		kindSel.addEventListener('change', applyKind);
		applyKind();
	}

	/* Programma: righe dinamiche "data + orario" con la spunta "ricorrente" (si ripete ogni settimana fino a una data di fine) */
	$$('.apse-when').forEach(function (wrap) {
		var rows = $('.apse-when-rows', wrap), seq = 0;
		var DEF = JSON.parse(wrap.getAttribute('data-default') || '{}');
		var INIT = JSON.parse(wrap.getAttribute('data-rows') || '[]');
		var DAYNAMES = ['domenica', 'lunedì', 'martedì', 'mercoledì', 'giovedì', 'venerdì', 'sabato'];
		function field(label, input) { var l = el('label', { style: 'margin-right:10px' }); l.appendChild(document.createTextNode(label + ' ')); l.appendChild(input); return l; }
		function dayName(v) { if (!v) { return 'settimana'; } var d = new Date(v + 'T12:00:00'); return isNaN(d) ? 'settimana' : DAYNAMES[d.getDay()]; }
		function add(r) {
			r = r || {};
			var i = seq++, n = 'when[' + i + ']';
			var row = el('div', { 'class': 'apse-when-row', style: 'border:1px solid #ccd0d4;border-radius:6px;padding:8px;margin:6px 0;background:#fff' });
			var date = el('input', { type: 'date', name: n + '[date]', value: r.date || DEF.date || '' });
			var from = el('input', { type: 'time', name: n + '[from]', value: r.from || DEF.from || '' });
			var to = el('input', { type: 'time', name: n + '[to]', value: r.to || DEF.to || '' });
			var rec = el('input', { type: 'checkbox', name: n + '[recurring]', value: '1' });
			if (r.recurring) { rec.checked = true; }
			row.appendChild(field('Giorno', date));
			row.appendChild(field('dalle', from));
			row.appendChild(field('alle', to));
			var rl = el('label', { style: 'margin-right:10px' }); rl.appendChild(rec); rl.appendChild(document.createTextNode(' ricorrente')); row.appendChild(rl);
			var rbox = el('span', { 'class': 'apse-rec' });
			var rep = el('select', { name: n + '[repeat]' });
			var dn = el('option', { value: 'weekly', text: 'ogni ' + dayName(date.value) });
			rep.appendChild(dn);
			rep.appendChild(el('option', { value: 'daily', text: 'tutti i giorni' }));
			rep.appendChild(el('option', { value: 'weekdays', text: 'dal lunedì al venerdì' }));
			if (r.repeat) { rep.value = r.repeat; }
			rbox.appendChild(rep);
			rbox.appendChild(document.createTextNode(' '));
			rbox.appendChild(field('fino al', el('input', { type: 'date', name: n + '[end]', value: r.end || '' })));
			row.appendChild(rbox);
			var rm = el('button', { type: 'button', 'class': 'button-link-delete', text: 'Togli' });
			rm.addEventListener('click', function () { row.parentNode.removeChild(row); });
			row.appendChild(rm);
			function sync() { rbox.style.display = rec.checked ? '' : 'none'; dn.textContent = 'ogni ' + dayName(date.value); }
			rec.addEventListener('change', sync); date.addEventListener('change', sync); date.addEventListener('input', sync);
			sync();
			rows.appendChild(row);
		}
		$$('[data-add]', wrap).forEach(function (b) { b.addEventListener('click', function () { add({}); }); });
		if (INIT.length) { INIT.forEach(add); } else { add({}); }
	});

	/* Calcolatrice del resto: dove si incassa in contanti (conto di tipo cassa) si scrive quanto si è ricevuto e dice il resto */
	$$('[data-apse-change]').forEach(function (wrap) {
		var types = JSON.parse(wrap.getAttribute('data-types') || '{}');
		var income = wrap.getAttribute('data-income');
		income = income ? JSON.parse(income) : null;
		var box = $('.apse-cashbox', wrap), tendered = $('.apse-tendered', wrap), out = $('.apse-change-out', wrap), quick = $('.apse-quick', wrap);
		var amountEl = $('[name="amount"]', wrap), acc = $('[name="account_id"]', wrap), cat = $('[name="category_id"]', wrap);
		var DEN = [50000, 20000, 10000, 5000, 2000, 1000, 500, 200, 100, 50, 20, 10, 5, 2, 1];
		var fixedFee = wrap.getAttribute('data-fee');
		var guestIds = JSON.parse(wrap.getAttribute('data-guest-ids') || '[]');
		var personEl = $('[name="person_id"]', wrap), newEl = $('[name="new_first_name"]', wrap), payEl = $('[name="pay"]', wrap);
		function due() {
			if (fixedFee === null) { return parseMoney(amountEl.value) || 0; }
			if (payEl && !payEl.checked) { return 0; }
			var guest = (newEl && newEl.value.trim() !== '') || (personEl && guestIds.indexOf(parseInt(personEl.value, 10)) > -1);
			return parseInt(guest ? wrap.getAttribute('data-guest-fee') : fixedFee, 10) || 0;
		}
		function active() {
			return types[acc.value] === 'cash' && due() > 0 && (!income || !cat || income.indexOf(parseInt(cat.value, 10)) > -1);
		}
		function refresh() {
			var on = active(); // a scomparsa: compare solo se si incassa in contanti e c'e' un importo
			box.style.display = on ? '' : 'none';
			if (!on) { return; }
			var t = due(), got = parseMoney(tendered.value);
			quick.innerHTML = '';
			var q = [t]; [500, 1000, 2000, 5000, 10000, 20000].forEach(function (x) { if (x >= t && q.indexOf(x) < 0) { q.push(x); } });
			q.slice(0, 4).forEach(function (v) {
				var b = el('button', { type: 'button', 'class': 'button', text: v === t ? 'Esatto' : eur(v).replace(',00', '') });
				b.addEventListener('click', function () { tendered.value = plain(v); refresh(); });
				quick.appendChild(b);
			});
			if (got == null) { out.className = 'apse-change-out description'; out.textContent = 'Scrivi quanto ti hanno dato.'; return; }
			if (got < t) { out.className = 'apse-change-out apse-neg'; out.textContent = 'Mancano ' + eur(t - got); return; }
			var rest = got - t, parts = [];
			DEN.forEach(function (d) { var k = Math.floor(rest / d); if (k > 0) { parts.push(k + ' × ' + eur(d)); rest -= k * d; } });
			out.className = 'apse-change-out apse-ok';
			out.textContent = 'Resto da dare: ' + eur(got - t) + (parts.length ? ' (' + parts.join(' · ') + ')' : '');
		}
		[amountEl, acc, cat, tendered, personEl, newEl, payEl].forEach(function (x) { if (x) { x.addEventListener('input', refresh); x.addEventListener('change', refresh); } });
		var form = wrap.closest('form');
		if (form) {
			form.addEventListener('submit', function (e) {
				var got = parseMoney(tendered.value);
				if (active() && got != null && got < due()) { e.preventDefault(); window.alert('I contanti ricevuti non bastano.'); }
			});
		}
		refresh();
	});

	/* Incasso multi-voce con calcolo del resto */
	var dataEl = $('#apse-income-data');
	if (!dataEl) { return; }
	var D = JSON.parse(dataEl.textContent);
	var form = $('form.apse-income');
	var box = $('#apse-lines');
	var lines = [], seq = 0, ctx = null;
	var DENOMS = [50000, 20000, 10000, 5000, 2000, 1000, 500, 200, 100, 50, 20, 10, 5, 2, 1];

	function catByKind(kind) { return D.categories.filter(function (c) { return c.kind === kind; })[0]; }
	function addLine(l) { l.key = ++seq; lines.push(l); render(); }

	function render() {
		box.innerHTML = '';
		lines.forEach(function (l, i) {
			var n = 'lines[' + i + ']';
			var row = el('div', { 'class': 'apse-line' });
			row.appendChild(el('span', { 'class': 'apse-line-title', text: l.title }));
			if (l.month) {
				var prev = el('button', { type: 'button', 'class': 'button button-small', text: '‹' });
				var next = el('button', { type: 'button', 'class': 'button button-small', text: '›' });
				prev.addEventListener('click', function () { l.month = shiftMonth(l.month, -1); render(); });
				next.addEventListener('click', function () { l.month = shiftMonth(l.month, 1); render(); });
				row.appendChild(prev); row.appendChild(el('span', { text: monthLabel(l.month) })); row.appendChild(next);
			}
			if (l.kind === 'membership') {
				row.appendChild(el('span', { 'class': 'description', text: l.note || 'tessera fino al 31 dicembre' }));
			}
			var amt = el('input', { type: 'text', name: n + '[amount]', inputmode: 'decimal', value: l.amount, size: '8', 'aria-label': 'Importo' });
			amt.addEventListener('input', function () { l.amount = amt.value; update(); });
			row.appendChild(amt); row.appendChild(el('span', { text: '€' }));
			// Sconto, promozione o arrotondamento: la voce conta come pagata per intero anche se si incassa meno
			var disc = el('input', { type: 'text', name: n + '[discount]', inputmode: 'decimal', value: l.discount || '', size: '6', placeholder: 'sconto', 'aria-label': 'Sconto' });
			disc.addEventListener('input', function () {
				l.discount = disc.value;
				if (l.list == null) { l.list = parseMoney(l.amount) || 0; }
				var d = parseMoney(disc.value) || 0;
				l.amount = plain(Math.max(0, l.list - d));
				amt.value = l.amount; update();
			});
			var why = el('input', { type: 'text', name: n + '[discount_note]', value: l.discountNote || '', size: '16', placeholder: 'motivo (es. open day)', 'aria-label': 'Motivo dello sconto' });
			why.addEventListener('input', function () { l.discountNote = why.value; });
			var free = el('button', { type: 'button', 'class': 'button button-small', text: 'Gratis' });
			free.addEventListener('click', function () {
				if (l.list == null) { l.list = parseMoney(l.amount) || 0; }
				l.discount = plain(l.list); l.amount = plain(0); render();
			});
			row.appendChild(disc); row.appendChild(why); row.appendChild(free);
			[['category_id', l.category], ['activity_id', l.activity || ''], ['session_id', l.session || ''], ['competence_month', l.month || ''], ['description', l.title]].forEach(function (p) {
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
		$('#apse-total').textContent = eur(t);
		var cash = D.accountTypes[$('#apse-account').value] === 'cash' && t > 0;
		$('#apse-cash').style.display = cash ? '' : 'none';
		if (!cash) { return; }
		var tenderedEl = $('#apse-tendered');
		var tendered = parseMoney(tenderedEl.value);
		if (tendered == null) { tendered = t; }
		var quick = $('#apse-quick'); quick.innerHTML = '';
		var q = [t]; [500, 1000, 2000, 5000, 10000, 20000].forEach(function (x) { if (x >= t && q.indexOf(x) < 0) { q.push(x); } });
		q.slice(0, 4).forEach(function (v) {
			var b = el('button', { type: 'button', 'class': 'button', text: v === t ? 'Esatto' : eur(v).replace(',00', '') });
			b.addEventListener('click', function () { tenderedEl.value = plain(v); update(); });
			quick.appendChild(b);
		});
		var out = $('#apse-change');
		if (tendered < t) {
			out.className = 'apse-change apse-neg'; out.textContent = 'Mancano ' + eur(t - tendered);
		} else {
			var rest = tendered - t, parts = [];
			DENOMS.forEach(function (d) { var k = Math.floor(rest / d); if (k > 0) { parts.push(k + ' × ' + eur(d)); rest -= k * d; } });
			out.className = 'apse-change apse-ok';
			out.textContent = 'Resto da dare: ' + eur(tendered - t) + (parts.length ? ' (' + parts.join(' · ') + ')' : '');
		}
	}

	/* Voci: quota associativa, mensilità, altro */
	/* La quota va all'anno più recente creato (se non è quello in corso, l'anno in corso è in omaggio): lo decide il programma */
	function membershipLine(c) {
		var plan = ctx && ctx.membership;
		var note = plan ? 'tessera ' + plan.year + ' fino al 31 dicembre' + (plan.free ? ' · ' + plan.free + ' in omaggio' : '') : 'tessera fino al 31 dicembre';
		return { title: 'Quota associativa' + (plan ? ' ' + plan.year : ''), category: c.id, kind: 'membership', amount: plain(D.membershipFee), note: note };
	}
	$('#apse-add-membership').addEventListener('click', function () {
		var c = catByKind('membership'); if (!c) { return; }
		addLine(membershipLine(c));
	});

	var actSel = $('#apse-add-activity-select'), otherSel = $('#apse-add-other-select');
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
		var month = mine ? mine.month : $('#apse-date').value.substr(0, 7);
		addLine({ title: a.name, category: c.id, kind: 'activity_fee', activity: a.id, month: month, amount: plain(mine ? mine.fee : a.fee) });
	});
	otherSel.addEventListener('change', function () {
		var id = parseInt(otherSel.value, 10); otherSel.value = ''; if (!id) { return; }
		var c = D.categories.filter(function (x) { return x.id === id; })[0];
		if (c) { addLine({ title: c.name, category: c.id, kind: c.kind, amount: '' }); }
	});

	/* Contributi di eventi a cui la persona è prenotata (e non ha ancora pagato) */
	var bookSel = $('#apse-add-booking-select');
	function fillBookings() {
		bookSel.innerHTML = '';
		bookSel.appendChild(el('option', { value: '', text: '+ Contributo evento…' }));
		(ctx && ctx.bookings || []).forEach(function (b, i) { bookSel.appendChild(el('option', { value: String(i), text: b.label + ' — ' + eur(b.amount) })); });
		bookSel.disabled = !(ctx && ctx.bookings && ctx.bookings.length);
	}
	bookSel.addEventListener('change', function () {
		var i = parseInt(bookSel.value, 10); bookSel.value = ''; if (isNaN(i) || !ctx || !ctx.bookings[i]) { return; }
		var b = ctx.bookings[i], c = catByKind('activity_fee'); if (!c) { return; }
		addLine({ title: b.label, category: c.id, kind: 'activity_fee', activity: b.activity_id, session: b.session_id, amount: plain(b.amount) });
	});

	var autofilled = false, autoPid = null;
	/* Con qualunque operazione di cassa su una persona con la tessera non valida, la quota associativa si inserisce da sola (si può togliere) */
	function autoMembership() {
		var mc = catByKind('membership');
		if (!mc || !ctx || !ctx.person || autoPid === ctx.person.id) { return; }
		autoPid = ctx.person.id;
		if (ctx.needs_membership && !ctx.suspended && !ctx.is_guest && !ctx.is_founder && !lines.some(function (l) { return l.kind === 'membership'; })) { addLine(membershipLine(mc)); }
	}
	/* Dal pulsante "Incassa": le voci dovute si inseriscono da sole, con gli importi mancanti */
	function autofillDues() {
		if (autofilled || !D.autofill || !ctx) { return; }
		autofilled = true;
		var c = catByKind('activity_fee');
		if (!c) { return; }
		(ctx.dues || []).forEach(function (d) {
			addLine({ title: d.name, category: c.id, kind: 'activity_fee', activity: d.activity_id, month: d.month, amount: plain(d.amount) });
		});
		(ctx.bookings || []).forEach(function (b) {
			addLine({ title: b.label, category: c.id, kind: 'activity_fee', activity: b.activity_id, session: b.session_id, amount: plain(b.amount) });
		});
	}

	/* Persona scelta: tessera, attività a cui è iscritta, mese da pagare */
	function loadContext() {
		var pid = $('#apse-person-select').value, info = $('#apse-person-info');
		ctx = null; fillActivities(); fillBookings(); info.textContent = ''; info.className = 'apse-info';
		if (!pid) { return; }
		var fd = new FormData();
		fd.append('action', 'apse_person_context'); fd.append('nonce', D.nonce); fd.append('person_id', pid); fd.append('date', $('#apse-date').value);
		fetch(D.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); }).then(function (res) {
			if (!res.success || !res.data.person) { return; }
			ctx = res.data; fillActivities(); fillBookings(); autoMembership(); autofillDues();
			var txt = ctx.person.type_label;
			if (ctx.is_guest) { txt += ' · non è socio: paga solo le attività'; }
			else if (ctx.is_founder) { txt += ' · tessera sempre rinnovata'; }
			else if (ctx.suspended) { txt += ' · ⚠ socio sospeso (inattivo): riattivalo dalla sua scheda prima di incassare'; info.className = 'apse-info apse-info-warn'; }
			else if (ctx.needs_membership) { txt += ' · ⚠ tessera non valida: serve la quota associativa'; info.className = 'apse-info apse-info-warn'; }
			else { txt += ' · tessera valida fino al ' + ctx.active_until.split('-').reverse().join('/'); }
			info.textContent = txt;
		});
	}
	$('#apse-person-select').addEventListener('change', loadContext);
	$('#apse-date').addEventListener('change', loadContext);
	if ($('#apse-person-select').value) { loadContext(); }

	/* Modalità di pagamento: propone il conto giusto */
	$('#apse-account').addEventListener('change', update);	$('#apse-tendered').addEventListener('input', update);

	form.addEventListener('submit', function (e) {
		var bad = !lines.length || lines.some(function (l) { return !(parseMoney(l.amount) > 0 || parseMoney(l.discount) > 0); });
		if (bad) { e.preventDefault(); window.alert('Aggiungi almeno una voce e inserisci un importo valido per ognuna.'); return; }
		if (D.accountTypes[$('#apse-account').value] === 'cash') {
			var t = parseMoney($('#apse-tendered').value);
			if (t != null && t < total()) { e.preventDefault(); window.alert('I contanti ricevuti non bastano.'); }
		}
	});

	// Documenti: le foto si riducono sul telefono prima dell'invio (max 1600 px, JPEG): uno scontrino passa da pochi MB a ~300 KB.
	var MAX_SIDE = 1600;
	function shrink(file) {
		return new Promise(function (resolve) {
			if (!/^image\/(jpeg|png|webp)$/.test(file.type) || !window.createImageBitmap || !window.DataTransfer) { resolve(file); return; }
			window.createImageBitmap(file).then(function (bmp) {
				var scale = Math.min(1, MAX_SIDE / Math.max(bmp.width, bmp.height));
				if (scale === 1 && file.size < 1500000) { resolve(file); return; }
				var c = document.createElement('canvas');
				c.width = Math.round(bmp.width * scale); c.height = Math.round(bmp.height * scale);
				c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
				c.toBlob(function (blob) {
					if (!blob || blob.size >= file.size) { resolve(file); return; }
					resolve(new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg', lastModified: Date.now() }));
				}, 'image/jpeg', 0.82);
			}).catch(function () { resolve(file); });
		});
	}
	document.addEventListener('change', function (e) {
		var input = e.target;
		if (!input.classList || !input.classList.contains('apse-doc-input') || !input.files || !input.files.length) { return; }
		Promise.all(Array.prototype.map.call(input.files, shrink)).then(function (files) {
			if (window.DataTransfer) {
				var dt = new DataTransfer();
				files.forEach(function (f) { dt.items.add(f); });
				input.files = dt.files;
			}
			var cell = input.parentNode, info = cell.querySelector('.apse-doc-info');
			if (!info) { info = el('span', { 'class': 'apse-doc-info description' }); cell.appendChild(info); }
			var n = 0; $$('.apse-doc-input', input.form).forEach(function (i) { n += i.files.length; });
			info.textContent = n + (n === 1 ? ' file pronto' : ' file pronti');
		});
	});

	fillActivities();
	fillBookings();
	render();
})();
