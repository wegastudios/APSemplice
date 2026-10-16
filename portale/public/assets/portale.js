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
