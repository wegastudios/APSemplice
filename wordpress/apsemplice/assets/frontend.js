/* APSemplice — front-end: conferme prima di annullare, e totale dei pagamenti online. */
(function () {
	'use strict';
	document.addEventListener('click', function (e) {
		var t = e.target && e.target.closest ? e.target.closest('[data-confirm]') : null;
		if (t && !window.confirm(t.getAttribute('data-confirm') || 'Confermi?')) {
			e.preventDefault();
		}
	});

	// Coordinate bancarie: pulsante «Copia» (negli appunti; se il browser non lo consente si seleziona il testo)
	document.addEventListener('click', function (e) {
		var b = e.target && e.target.closest ? e.target.closest('[data-apsf-copy]') : null;
		if (!b) { return; }
		var text = b.getAttribute('data-apsf-copy') || '';
		var done = function () { var old = b.textContent; b.textContent = 'Copiato'; setTimeout(function () { b.textContent = old; }, 1500); };
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(done, function () {});
			return;
		}
		var t = document.createElement('textarea'); t.value = text; t.setAttribute('readonly', ''); t.style.position = 'fixed'; t.style.opacity = '0';
		document.body.appendChild(t); t.select();
		try { document.execCommand('copy'); done(); } catch (err) {}
		document.body.removeChild(t);
	});

	function euro(cents) {
		return new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(cents / 100);
	}
	function updateTotal(form) {
		var total = 0, any = false;
		form.querySelectorAll('input[type="checkbox"][data-cents]').forEach(function (c) {
			if (c.checked) { total += parseInt(c.getAttribute('data-cents'), 10) || 0; any = true; }
		});
		var out = form.querySelector('.apsf-pay-total'); if (out) { out.textContent = euro(total); }
		form.querySelectorAll('button[type="submit"]').forEach(function (btn) { btn.disabled = !any; });
	}
	document.addEventListener('change', function (e) {
		var form = e.target && e.target.closest ? e.target.closest('.apsf-pay form') : null;
		if (form && e.target.matches('input[type="checkbox"][data-cents]')) { updateTotal(form); }
	});

	// Documenti del tesoriere: le foto si riducono sul telefono prima dell'invio (max 1600 px, JPEG).
	function shrink(file) {
		return new Promise(function (resolve) {
			if (!/^image\/(jpeg|png|webp)$/.test(file.type) || !window.createImageBitmap || !window.DataTransfer) { resolve(file); return; }
			window.createImageBitmap(file).then(function (bmp) {
				var scale = Math.min(1, 1600 / Math.max(bmp.width, bmp.height));
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
			var dt = new DataTransfer();
			files.forEach(function (f) { dt.items.add(f); });
			input.files = dt.files;
			var box = input.closest('.apsf-docs'), info = box && box.querySelector('.apsf-doc-info');
			if (box && !info) { info = document.createElement('span'); info.className = 'apsf-doc-info apsf-small'; box.appendChild(info); }
			var n = 0;
			Array.prototype.forEach.call(input.form.querySelectorAll('.apse-doc-input'), function (i) { n += i.files.length; });
			if (info) { info.textContent = n + (n === 1 ? ' file pronto' : ' file pronti'); }
		});
	});

	// Ingressi agli eventi: ricerca e filtri sulla lista dei prenotati.
	function applyFilter(root) {
		var q = (root.querySelector('.apsf-search') || { value: '' }).value.toLowerCase().replace(/[^a-z0-9]/g, '');
		var chip = root.querySelector('.apsf-chip.is-on');
		var f = chip ? chip.getAttribute('data-filter') : 'all';
		root.querySelectorAll('.apsf-booked').forEach(function (li) {
			var ok = (f === 'all' || li.getAttribute('data-state') === f) && (q === '' || (li.getAttribute('data-name') || '').indexOf(q) !== -1);
			li.hidden = !ok;
		});
	}
	document.addEventListener('input', function (e) {
		var root = e.target && e.target.closest ? e.target.closest('.apsf-checkin') : null;
		if (root && e.target.classList.contains('apsf-search')) { applyFilter(root); }
	});
	document.addEventListener('click', function (e) {
		var chip = e.target && e.target.closest ? e.target.closest('.apsf-chip') : null;
		if (!chip) { return; }
		var root = chip.closest('.apsf-checkin');
		root.querySelectorAll('.apsf-chip').forEach(function (c) { c.classList.remove('is-on'); });
		chip.classList.add('is-on');
		applyFilter(root);
	});

	// Ingressi agli eventi: scansione del QR dal telefono (dove il browser sa leggere i QR; altrimenti basta la fotocamera del telefono).
	document.addEventListener('click', function (e) {
		var btn = e.target && e.target.closest ? e.target.closest('[data-apsf-scan]') : null;
		if (!btn) { return; }
		var box = btn.closest('.apsf-scan'), msg = box.querySelector('.apsf-scan-msg'), video = box.querySelector('.apsf-scan-video');
		if (!('BarcodeDetector' in window) || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
			msg.textContent = 'Questo browser non legge i QR da qui: apri la fotocamera del telefono e inquadra il QR del biglietto, poi tocca il link per registrare l\'ingresso.';
			return;
		}
		var detector = new window.BarcodeDetector({ formats: ['qr_code'] }), stream = null, timer = null;
		function stop() { if (timer) { clearInterval(timer); } if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); } video.hidden = true; }
		navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } }).then(function (s) {
			stream = s; video.srcObject = s; video.hidden = false; video.play();
			msg.textContent = 'Inquadra il QR del biglietto…';
			timer = setInterval(function () {
				detector.detect(video).then(function (codes) {
					for (var i = 0; i < codes.length; i++) {
						if (String(codes[i].rawValue).indexOf('apse_ticket=') !== -1) {
							stop();
							var form = box.querySelector('.apsf-scan-form');
							form.querySelector('input[name="ticket"]').value = codes[i].rawValue;
							form.submit();
							return;
						}
					}
				}).catch(function () {});
			}, 300);
		}).catch(function () { msg.textContent = 'Non riesco ad aprire la fotocamera: controlla il permesso del browser.'; });
	});
})();

/* Stampa della tessera: si stampa solo la tessera (il resto della pagina resta nascosto dallo stile di stampa) */
(function () {
	document.addEventListener('click', function (e) {
		var b = e.target && e.target.closest ? e.target.closest('.apsf-print-card') : null;
		if (!b) { return; }
		e.preventDefault();
		document.body.classList.add('apsf-printing');
		var done = function () { document.body.classList.remove('apsf-printing'); window.removeEventListener('afterprint', done); };
		window.addEventListener('afterprint', done);
		window.print();
	});
})();
