/* APSemplice — app installabile e notifiche (nessuna dipendenza). */
(function () {
	'use strict';
	var C = window.APSE_PWA || {};
	var deferred = null;

	function $$(s) { return Array.prototype.slice.call(document.querySelectorAll(s)); }

	function b64uToBytes(s) {
		var pad = '='.repeat((4 - s.length % 4) % 4);
		var raw = atob((s + pad).replace(/-/g, '+').replace(/_/g, '/'));
		var out = new Uint8Array(raw.length);
		for (var i = 0; i < raw.length; i++) { out[i] = raw.charCodeAt(i); }
		return out;
	}
	function bytesToB64u(buf) {
		var b = new Uint8Array(buf), s = '';
		for (var i = 0; i < b.length; i++) { s += String.fromCharCode(b[i]); }
		return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	}

	if ('serviceWorker' in navigator && C.sw) {
		window.addEventListener('load', function () {
			navigator.serviceWorker.register(C.sw, { scope: C.scope || '/' }).catch(function () {});
		});
	}

	/* Installa l'app */
	window.addEventListener('beforeinstallprompt', function (e) {
		e.preventDefault();
		deferred = e;
		$$('[data-apse-install]').forEach(function (b) { b.hidden = false; });
	});
	window.addEventListener('appinstalled', function () {
		deferred = null;
		$$('[data-apse-install]').forEach(function (b) { b.hidden = true; });
	});
	document.addEventListener('click', function (e) {
		var b = e.target.closest ? e.target.closest('[data-apse-install]') : null;
		if (!b || !deferred) { return; }
		deferred.prompt();
		deferred = null;
		b.hidden = true;
	});
	var standalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone;
	$$('[data-apse-ios]').forEach(function (n) {
		var ios = /iphone|ipad|ipod/i.test(navigator.userAgent);
		n.hidden = !(ios && !standalone);
	});

	/* Notifiche */
	function post(action, data) {
		var f = new FormData();
		f.append('action', action);
		f.append('nonce', C.nonce || '');
		Object.keys(data).forEach(function (k) { f.append(k, data[k]); });
		return fetch(C.ajax, { method: 'POST', body: f, credentials: 'same-origin' }).then(function (r) { return r.json(); });
	}
	function say(box, text) { var m = box.querySelector('[data-apse-push-msg]'); if (m) { m.textContent = text; } }

	function init(box) {
		var on = box.querySelector('[data-apse-push-on]'), off = box.querySelector('[data-apse-push-off]');
		if (!C.push || !C.vapid || !('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
			box.querySelectorAll('[data-apse-push-on],[data-apse-push-off]').forEach(function (b) { b.hidden = true; });
			say(box, 'Questo dispositivo o browser non supporta le notifiche' + ((/iphone|ipad|ipod/i.test(navigator.userAgent) && !standalone) ? ': su iPhone aggiungi prima l\'app alla schermata Home.' : '.'));
			return;
		}
		function refresh() {
			navigator.serviceWorker.ready.then(function (reg) { return reg.pushManager.getSubscription(); }).then(function (sub) {
				if (on) { on.hidden = !!sub; }
				if (off) { off.hidden = !sub; }
				say(box, sub ? 'Le notifiche sono attive su questo dispositivo.' : (Notification.permission === 'denied' ? 'Le notifiche sono bloccate nelle impostazioni del browser.' : ''));
			});
		}
		if (on) {
			on.addEventListener('click', function () {
				Notification.requestPermission().then(function (perm) {
					if (perm !== 'granted') { say(box, 'Permesso negato: puoi cambiarlo dalle impostazioni del browser.'); return null; }
					return navigator.serviceWorker.ready.then(function (reg) {
						return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64uToBytes(C.vapid) });
					}).then(function (sub) {
						var j = sub.toJSON();
						return post('apse_push_subscribe', { endpoint: j.endpoint, p256dh: (j.keys || {}).p256dh || '', auth: (j.keys || {}).auth || '' }).then(function (r) {
							if (!r.success) { sub.unsubscribe(); say(box, (r.data && r.data.message) || 'Non è stato possibile attivare le notifiche.'); }
							refresh();
						});
					});
				}).catch(function () { say(box, 'Non è stato possibile attivare le notifiche.'); });
			});
		}
		if (off) {
			off.addEventListener('click', function () {
				navigator.serviceWorker.ready.then(function (reg) { return reg.pushManager.getSubscription(); }).then(function (sub) {
					if (!sub) { refresh(); return; }
					var ep = sub.endpoint;
					return sub.unsubscribe().then(function () { return post('apse_push_unsubscribe', { endpoint: ep }); }).then(refresh);
				});
			});
		}
		refresh();
	}
	$$('[data-apse-push]').forEach(init);
})();
