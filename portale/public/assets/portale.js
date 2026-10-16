// Scelta mensile/annuale nei prezzi e copia della chiave di licenza.
(function () {
	var box = document.querySelector('.pricing');
	if (box) {
		var btns = box.querySelectorAll('.seg button');
		btns.forEach(function (b) {
			b.addEventListener('click', function () {
				box.setAttribute('data-period', b.getAttribute('data-period'));
				btns.forEach(function (o) { o.setAttribute('aria-pressed', o === b ? 'true' : 'false'); });
			});
		});
	}
	document.querySelectorAll('[data-copy]').forEach(function (b) {
		b.addEventListener('click', function () {
			var t = b.getAttribute('data-copy');
			var done = function () { var o = b.textContent; b.textContent = 'Copiata'; setTimeout(function () { b.textContent = o; }, 1600); };
			if (navigator.clipboard) { navigator.clipboard.writeText(t).then(done); }
		});
	});
})();

// Consenso ai cookie: gli script non necessari stanno in <template> e partono solo dopo la scelta della categoria.
(function () {
	var cc = document.getElementById('cc');
	var templates = document.querySelectorAll('template[data-cc-cat]');
	var NAME = 'asem_cc';
	function read() {
		var m = document.cookie.match(new RegExp('(?:^|; )' + NAME + '=([^;]*)'));
		if (!m) { return null; }
		try { return JSON.parse(decodeURIComponent(m[1])); } catch (e) { return null; }
	}
	function write(prefs) {
		var d = new Date(); d.setMonth(d.getMonth() + 6);
		document.cookie = NAME + '=' + encodeURIComponent(JSON.stringify(prefs)) + '; expires=' + d.toUTCString() + '; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
	}
	function activate(prefs) {
		templates.forEach(function (t) {
			if (t.getAttribute('data-done') || !prefs[t.getAttribute('data-cc-cat')]) { return; }
			t.setAttribute('data-done', '1');
			var target = t.getAttribute('data-cc-slot') === 'head' ? document.head : document.body;
			Array.prototype.forEach.call(t.content.childNodes, function (n) {
				if (n.nodeName === 'SCRIPT') {
					var s = document.createElement('script');
					Array.prototype.forEach.call(n.attributes, function (a) { s.setAttribute(a.name, a.value); });
					s.text = n.textContent;
					target.appendChild(s);
				} else {
					target.appendChild(document.importNode(n, true));
				}
			});
		});
	}
	var cats = cc ? (cc.getAttribute('data-cats') || '').split(',').filter(Boolean) : [];
	var rev = cc ? parseInt(cc.getAttribute('data-rev'), 10) : 0;
	var saved = read();
	if (saved && saved.rev === rev) { activate(saved); }
	if (!cc) { return; }
	function show() {
		var cur = read() || {};
		cc.querySelectorAll('[data-cc-pref]').forEach(function (i) { i.checked = !!cur[i.getAttribute('data-cc-pref')]; });
		cc.hidden = false;
	}
	function choose(map) {
		var before = read();
		var prefs = { rev: rev };
		cats.forEach(function (c) { prefs[c] = !!map[c]; });
		write(prefs);
		cc.hidden = true;
		var withdrawn = before && cats.some(function (c) { return before[c] && !prefs[c]; });
		if (withdrawn) { location.reload(); } else { activate(prefs); }
	}
	if (!saved || saved.rev !== rev) { show(); }
	cc.addEventListener('click', function (ev) {
		var b = ev.target.closest('[data-cc]');
		if (!b) { return; }
		var a = b.getAttribute('data-cc'), all = {};
		if (a === 'custom') {
			var box = cc.querySelector('.cc-prefs');
			if (box.hidden) { box.hidden = false; b.textContent = 'Salva la scelta'; return; }
			cats.forEach(function (c) { var i = cc.querySelector('[data-cc-pref="' + c + '"]'); all[c] = i && i.checked; });
			choose(all);
		} else {
			cats.forEach(function (c) { all[c] = a === 'all'; });
			choose(all);
		}
	});
	document.querySelectorAll('[data-cc-open]').forEach(function (a) {
		a.addEventListener('click', function (ev) { ev.preventDefault(); show(); });
	});
})();
