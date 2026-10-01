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
})();
