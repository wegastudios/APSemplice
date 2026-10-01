/* APSemplice — front-end: conferme prima di annullare, e totale dei pagamenti online. */
(function () {
	'use strict';
	document.addEventListener('click', function (e) {
		var t = e.target && e.target.closest ? e.target.closest('[data-confirm]') : null;
		if (t && !window.confirm(t.getAttribute('data-confirm') || 'Confermi?')) {
			e.preventDefault();
		}
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
		var btn = form.querySelector('button[type="submit"]'); if (btn) { btn.disabled = !any; }
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
})();
