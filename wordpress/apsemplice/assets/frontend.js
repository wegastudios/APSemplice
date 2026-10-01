/* APSemplice — front-end: solo conferme prima di azioni che annullano qualcosa. */
(function () {
	'use strict';
	document.addEventListener('click', function (e) {
		var t = e.target && e.target.closest ? e.target.closest('[data-confirm]') : null;
		if (t && !window.confirm(t.getAttribute('data-confirm') || 'Confermi?')) {
			e.preventDefault();
		}
	});
})();
