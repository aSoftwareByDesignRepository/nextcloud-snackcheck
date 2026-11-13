/**
 * Bridge for the shared transfer page (apps/_shared/config-transfer):
 * exposes the window.SnackCheckApi / SnackCheckMessaging contract the
 * synced js/transfer.js expects, in SnackCheck's own API envelope
 * ({ok,data} / {ok,error:{code}}). App-specific — not synced.
 */
(function () {
	'use strict';
	function requestToken() {
		return (window.OC && OC.requestToken) || '';
	}
	async function parse(resp) {
		const j = await resp.json().catch(() => null);
		if (!resp.ok || (j && (j.ok === false || j.success === false))) {
			const errObj = j && j.error;
			throw {
				code: (errObj && (errObj.code || errObj)) || 'SERVER_ERROR',
				context: (j && j.context) || {},
			};
		}
		return j && Object.prototype.hasOwnProperty.call(j, 'data') ? j.data : j;
	}
	window.SnackCheckApi = {
		url(path) {
			return OC.generateUrl('/apps/snackcheck' + path);
		},
		async get(path, params) {
			let u = this.url(path);
			if (params) {
				const q = Object.keys(params)
					.filter((k) => params[k] !== undefined && params[k] !== null)
					.map((k) => encodeURIComponent(k) + '=' + encodeURIComponent(params[k]))
					.join('&');
				if (q) u += '?' + q;
			}
			return parse(await fetch(u, { credentials: 'same-origin' }));
		},
		async post(path, body) {
			return parse(await fetch(this.url(path), {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', requesttoken: requestToken() },
				body: JSON.stringify(body || {}),
			}));
		},
		async upload(path, file, extra) {
			const fd = new FormData();
			fd.append('file', file);
			if (extra) {
				Object.keys(extra).forEach((k) => fd.append(k, extra[k]));
			}
			return parse(await fetch(this.url(path), {
				method: 'POST', credentials: 'same-origin',
				headers: { requesttoken: requestToken() },
				body: fd,
			}));
		},
	};
	// Messaging: the synced transfer.js asks for .alert/.polite — route both
	// through the app-owned #snk-toast banner (visible, replace-text dedup,
	// role=alert|status) and mirror into the snk-transfer-result-live status
	// region the synced part renders. OC.Notification is gone from NC≥31
	// bundles — it may only ever be a fallback behind the app-owned channel,
	// never the only write.
	const t = (s) => (window.OC && OC.L10N && OC.L10N.translate)
		? OC.L10N.translate('snackcheck', s) : s;
	function toast(text, kind) {
		const el = document.getElementById('snk-toast');
		if (!el) {
			throw new Error('SnackCheck: missing #snk-toast');
		}
		const isErr = kind === 'error';
		el.hidden = false;
		el.classList.remove('snk-toast--ok', 'snk-toast--error');
		el.classList.add(isErr ? 'snk-toast--error' : 'snk-toast--ok');
		el.setAttribute('role', isErr ? 'alert' : 'status');
		el.setAttribute('aria-live', isErr ? 'assertive' : 'polite');
		el.textContent = '';
		const span = document.createElement('span');
		span.className = 'snk-toast__text';
		span.textContent = String(text);
		el.appendChild(span);
		if (isErr) {
			// §13 family contract: error toasts offer a "Report this
			// problem" mailto when the shared feedback pack is loaded —
			// same shape as app.js's own toast().
			try {
				const feedback = window.SbdAppFeedback;
				if (feedback && typeof feedback.buildMailto === 'function') {
					const link = document.createElement('a');
					link.className = 'snk-nav-footer__toast-link';
					link.href = feedback.buildMailto('problem', {});
					link.textContent = t('Report this problem');
					el.appendChild(link);
				}
			} catch (e) { /* never break the toast */ }
		}
		window.clearTimeout(el._snkHide);
		el._snkHide = window.setTimeout(function () {
			if (!el.querySelector('[data-snk-action="undo"]')) {
				el.hidden = true;
				el.textContent = '';
				el.classList.remove('snk-toast--ok', 'snk-toast--error');
			}
		}, isErr ? 7000 : 4000);
	}
	function announce(text, kind) {
		try {
			toast(text, kind);
			return;
		} catch (e) { /* fall through to the platform fallback */ }
		if (window.OC && OC.Notification && OC.Notification.showTemporary) {
			OC.Notification.showTemporary(text);
		}
	}
	function live(text) {
		const el = document.getElementById('snk-transfer-result-live');
		if (el) el.textContent = text;
	}
	const msg = window.SnackCheckMessaging || {};
	if (typeof msg.announce !== 'function') {
		msg.announce = announce;
	}
	if (typeof msg.alert !== 'function') {
		msg.alert = function (text) { msg.announce(text, 'error'); live(text); };
	}
	if (typeof msg.polite !== 'function') {
		msg.polite = function (text) { msg.announce(text, 'success'); live(text); };
	}
	window.SnackCheckMessaging = msg;
})();
