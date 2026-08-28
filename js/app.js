(function () {
	'use strict';
	function t(key, fallback) {
		if (window.OC && OC.L10N && typeof OC.L10N.translate === 'function') {
			try {
				const tr = OC.L10N.translate('snackcheck', key);
				if (tr) return tr;
			} catch (e) { /* fall through */ }
		}
		return fallback || key;
	}
	/**
	 * Aristoteles: match server parseEuroToCents — reject scientific notation / negatives.
	 * Returns integer cents or null when invalid (caller must not coerce with || 0).
	 */
	function parseEuroToCentsClient(raw) {
		const normalized = String(raw == null ? '' : raw).trim().replace(/\s/g, '').replace(',', '.');
		if (!normalized || /[eE]/.test(normalized)) {
			return null;
		}
		if (!/^-?\d+(\.\d+)?$/.test(normalized)) {
			return null;
		}
		const euro = Number(normalized);
		if (!Number.isFinite(euro) || euro < 0 || euro > 1000000) {
			return null;
		}
		const cents = Math.round(euro * 100);
		if (cents < 0 || cents > 100000000) {
			return null;
		}
		return cents;
	}

	/** Bachus: never toast raw API codes at kitchen users. */
	function userFacingError(err) {
		const raw = String((err && err.message) || err || '');
		const code = raw.replace(/^Error:\s*/i, '').trim();
		const map = {
			photo_too_large: t('The photo is too large. Maximum size is 2 MB.', 'The photo is too large. Maximum size is 2 MB.'),
			photo_type_invalid: t('Only JPEG, PNG, or WebP photos are allowed.', 'Only JPEG, PNG, or WebP photos are allowed.'),
			photo_not_found: t('No photo for this item.', 'No photo for this item.'),
			upload_failed: t('Could not upload the photo.', 'Could not upload the photo.'),
			period_closed: t('Period closed. Ask a kitchen admin to open the next period before logging.', 'Period closed. Ask a kitchen admin to open the next period before logging.'),
			item_inactive: t('That snack is no longer available.', 'That snack is no longer available.'),
			item_not_found: t('That snack is no longer available.', 'That snack is no longer available.'),
			proxy_reason_required: t('Reason needs at least 3 characters', 'Reason needs at least 3 characters'),
			proxy_forbidden: t('You cannot log for a colleague.', 'You cannot log for a colleague.'),
			hospitality_forbidden: t('You cannot book on company.', 'You cannot book on company.'),
			hospitality_reason_required: t('Reason needs at least 3 characters', 'Reason needs at least 3 characters'),
			site_required: t('Pick a site above before logging. Each kitchen has its own catalog.', 'Pick a site above before logging. Each kitchen has its own catalog.'),
			validation_failed: t('Please check your entries and try again.', 'Please check your entries and try again.'),
			rate_limited: t('Too many attempts. Wait a moment and try again.', 'Too many attempts. Wait a moment and try again.'),
			unlock_busy: t('Please try again in a moment.', 'Please try again in a moment.'),
			terminal_busy: t('Please try again in a moment.', 'Please try again in a moment.'),
			license_busy: t('Please try again in a moment.', 'Please try again in a moment.')
		};
		if (map[code]) return map[code];
		// Nextcloud SecurityMiddleware → CrossSiteRequestForgeryException uses HTTP 412.
		if (/^HTTP\s*412\b/.test(code) || /csrf/i.test(code)) {
			return t('Session expired. Reload the page and try again.', 'Session expired. Reload the page and try again.');
		}
		if (/^HTTP\s*4\d\d/.test(code)) {
			return t('Something went wrong. Please try again.', 'Something went wrong. Please try again.');
		}
		if (/^HTTP\s*5\d\d/.test(code) || code === 'network') {
			return t('Server busy. Please try again.', 'Server busy. Please try again.');
		}
		// Known-looking snake_case codes stay mapped; unknown → generic (never dump codes).
		if (/^[a-z][a-z0-9_]+$/.test(code)) {
			return t('Something went wrong. Please try again.', 'Something went wrong. Please try again.');
		}
		return code || t('Something went wrong. Please try again.', 'Something went wrong. Please try again.');
	}
	/**
	 * Nextcloud puts the CSRF token on head[data-requesttoken] and OC.requestToken.
	 * A lone meta[name=requesttoken] is often missing — empty header → HTTP 412.
	 */
	function token() {
		if (typeof window.OC !== 'undefined' && typeof OC.requestToken === 'string' && OC.requestToken !== '') {
			return OC.requestToken;
		}
		const head = document.querySelector('head');
		const fromHead = head ? head.getAttribute('data-requesttoken') : null;
		if (fromHead) {
			return fromHead;
		}
		const meta = document.querySelector('meta[name="requesttoken"]');
		const fromMeta = meta ? meta.getAttribute('content') : null;
		if (fromMeta) {
			return fromMeta;
		}
		const input = document.querySelector('input[name="requesttoken"]');
		return input && input.value ? String(input.value) : '';
	}
	function applyCsrfToken(tok) {
		if (!tok) return;
		if (typeof window.OC !== 'undefined') {
			OC.requestToken = tok;
		}
		const head = document.querySelector('head');
		if (head) {
			head.setAttribute('data-requesttoken', tok);
		}
		const meta = document.querySelector('meta[name="requesttoken"]');
		if (meta) {
			meta.setAttribute('content', tok);
		}
		document.querySelectorAll('input[name="requesttoken"]').forEach(function (el) {
			el.value = tok;
		});
	}
	/** Stale/empty CSRF (multi-tab, long idle) → refresh once like @nextcloud/axios. */
	async function refreshCsrfToken() {
		try {
			const url = (typeof OC !== 'undefined' && typeof OC.generateUrl === 'function')
				? OC.generateUrl('/csrftoken')
				: '/index.php/csrftoken';
			const res = await fetch(url, {
				method: 'GET',
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					'OCS-APIRequest': 'true',
					'X-Requested-With': 'XMLHttpRequest',
				},
			});
			if (!res.ok) return '';
			const data = await res.json().catch(function () { return null; });
			const tok = data && typeof data.token === 'string' ? data.token : '';
			if (tok) applyCsrfToken(tok);
			return tok;
		} catch (e) {
			return '';
		}
	}
	function announce(msg, assertive) {
		const id = assertive ? 'snk-alert-region' : 'snk-live-region';
		const region = document.getElementById(id);
		if (!region) return;
		region.textContent = '';
		// Force a DOM mutation so screen readers re-announce identical text.
		void region.offsetWidth;
		region.textContent = String(msg || '');
	}
	function toast(msg, undoId, assertive) {
		const el = document.getElementById('snk-toast');
		if (!el) {
			// Every SnackCheck page must ship #snk-toast — never fall back to alert (blocks Undo).
			console.error('SnackCheck: missing #snk-toast', msg);
			announce(msg, !!assertive);
			return;
		}
		el.hidden = false;
		el.textContent = '';
		const span = document.createElement('span');
		span.textContent = msg;
		el.appendChild(span);
		if (undoId) {
			const btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'snk-btn';
			btn.textContent = el.getAttribute('data-undo-label') || 'Undo';
			btn.setAttribute('data-snk-action', 'undo');
			btn.setAttribute('data-log-id', String(undoId));
			el.appendChild(btn);
			window.setTimeout(function () { if (btn.parentNode) btn.remove(); }, 60000);
		}
		announce(msg, !!assertive);
		window.clearTimeout(el._snkHide);
		el._snkHide = window.setTimeout(function () {
			if (!el.querySelector('[data-snk-action="undo"]')) {
				el.hidden = true;
				el.textContent = '';
			}
		}, undoId ? 62000 : 5000);
	}
	const snkDialogTriggers = typeof WeakMap === 'function' ? new WeakMap() : null;
	function openSnkDialog(dlg, trigger) {
		const restore = trigger || document.activeElement;
		if (snkDialogTriggers) {
			snkDialogTriggers.set(dlg, restore);
		}
		if (typeof dlg.showModal === 'function') {
			dlg.showModal();
		} else {
			dlg.setAttribute('open', 'open');
		}
		// Prefer an explicit initial focus (e.g. Name on Edit). Else Cancel / least-destructive.
		const preferred = dlg.querySelector('[data-snk-initial-focus]');
		const focusable = preferred || dlg.querySelector(
			'button[value="cancel"], button[value="no"], .snk-btn--secondary, input:not([type=hidden]):not([type=file]), textarea, select, button'
		);
		if (focusable) focusable.focus();
		const onClose = function () {
			dlg.removeEventListener('close', onClose);
			const prev = snkDialogTriggers ? snkDialogTriggers.get(dlg) : restore;
			if (snkDialogTriggers) {
				snkDialogTriggers.delete(dlg);
			}
			if (prev && typeof prev.focus === 'function') {
				prev.focus();
			}
		};
		dlg.addEventListener('close', onClose);
	}
	function ensureRestockDialog() {
		let dlg = document.getElementById('snk-restock-dialog');
		if (dlg) return dlg;
		dlg = document.createElement('dialog');
		dlg.id = 'snk-restock-dialog';
		dlg.className = 'snk-dialog';
		dlg.setAttribute('aria-labelledby', 'snk-restock-title');
		dlg.innerHTML = '<form method="dialog" data-snk-form="catalog-restock">'
			+ '<h2 id="snk-restock-title" class="snk-h2">' + t('Restock', 'Restock') + '</h2>'
			+ '<input type="hidden" name="itemId" id="snk-restock-item-id" />'
			+ '<label class="snk-field"><span>' + t('Add quantity', 'Add quantity') + '</span>'
			+ '<input name="qty" id="snk-restock-qty" class="snk-input" type="number" min="1" step="1" value="1" required /></label>'
			+ '<div class="snk-actions">'
			+ '<button type="submit" class="snk-btn snk-btn--secondary" value="cancel">' + t('Cancel', 'Cancel') + '</button>'
			+ '<button type="submit" class="snk-btn snk-btn--primary" value="confirm">' + t('Restock', 'Restock') + '</button>'
			+ '</div></form>';
		document.body.appendChild(dlg);
		return dlg;
	}
	/** Promise-based confirm via native <dialog> (no window.confirm). */
	function snkConfirm(message, title, opts) {
		return new Promise(function (resolve) {
			let dlg = document.getElementById('snk-confirm-dialog');
			if (!dlg) {
				dlg = document.createElement('dialog');
				dlg.id = 'snk-confirm-dialog';
				dlg.className = 'snk-dialog';
				dlg.setAttribute('aria-labelledby', 'snk-confirm-title');
				dlg.innerHTML = '<form method="dialog">'
					+ '<h2 id="snk-confirm-title" class="snk-h2"></h2>'
					+ '<p id="snk-confirm-body" class="snk-lead"></p>'
					+ '<div class="snk-actions">'
					+ '<button type="submit" class="snk-btn snk-btn--secondary" value="no" id="snk-confirm-no"></button>'
					+ '<button type="submit" class="snk-btn snk-btn--primary" value="yes" id="snk-confirm-yes"></button>'
					+ '</div></form>';
				document.body.appendChild(dlg);
			}
			dlg.querySelector('#snk-confirm-title').textContent = title || t('Please confirm', 'Please confirm');
			dlg.querySelector('#snk-confirm-body').textContent = message;
			const yes = dlg.querySelector('#snk-confirm-yes');
			const no = dlg.querySelector('#snk-confirm-no');
			yes.textContent = t('Confirm', 'Confirm');
			no.textContent = t('Cancel', 'Cancel');
			yes.className = (opts && opts.danger) ? 'snk-btn snk-btn--danger' : 'snk-btn snk-btn--primary';
			no.className = 'snk-btn snk-btn--secondary';
			const onClose = function () {
				dlg.removeEventListener('close', onClose);
				resolve(dlg.returnValue === 'yes');
			};
			dlg.addEventListener('close', onClose);
			openSnkDialog(dlg, document.activeElement);
		});
	}
	function toFormBody(body) {
		const params = new URLSearchParams();
		Object.keys(body || {}).forEach(function (k) {
			const v = body[k];
			if (v === undefined || v === null) return;
			if (Array.isArray(v)) {
				if (v.length === 0) {
					// Signal empty array to PHP (e.g. clear catalog tags on edit).
					params.append(k + '[]', '');
					return;
				}
				v.forEach(function (item) { params.append(k + '[]', String(item)); });
				return;
			}
			if (typeof v === 'string' && (k === 'accessUsers' || k === 'accessGroups' || k === 'appAdmins' || k === 'managerUids' || k === 'hospitalityAllowedUserIds')) {
				v.split(/[\s,;]+/).filter(Boolean).forEach(function (item) { params.append(k + '[]', item); });
				return;
			}
			params.set(k, String(v));
		});
		return params.toString();
	}
	async function api(method, url, body) {
		const needsCsrf = method !== 'GET' && method !== 'HEAD';
		const maxAttempts = needsCsrf ? 2 : 1;
		if (needsCsrf && !token()) {
			await refreshCsrfToken();
		}
		if (body && method === 'POST') {
			body.idempotencyKey = body.idempotencyKey || (crypto.randomUUID
				? crypto.randomUUID()
				: ('snk-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10) + '-' + Math.random().toString(36).slice(2, 10)));
		}
		let lastError = null;
		for (let attempt = 0; attempt < maxAttempts; attempt++) {
			const csrf = token();
			const headers = { requesttoken: csrf };
			let payload;
			if (body && method !== 'GET' && method !== 'DELETE' && method !== 'HEAD') {
				headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
				if (method === 'POST' && body.idempotencyKey) {
					headers['Idempotency-Key'] = body.idempotencyKey;
				}
				// NC accepts requesttoken in header OR POST body — send both (defense in depth).
				body.requesttoken = csrf;
				payload = toFormBody(body);
			}
			const res = await fetch(url, {
				method: method,
				headers: headers,
				body: payload,
				credentials: 'same-origin',
			});
			const ct = res.headers.get('content-type') || '';
			const data = ct.indexOf('json') >= 0 ? await res.json().catch(function () { return {}; }) : {};
			if (res.ok && data.ok !== false) {
				return data;
			}
			// Stale CSRF: refresh once and retry (same Idempotency-Key → no double charge).
			if (needsCsrf && res.status === 412 && attempt + 1 < maxAttempts) {
				const fresh = await refreshCsrfToken();
				if (fresh) {
					continue;
				}
			}
			lastError = new Error((data.error && data.error.code) || data.code || ('HTTP ' + res.status));
			break;
		}
		throw lastError || new Error('HTTP 412');
	}
	async function post(url, body) { return api('POST', url, body || {}); }

	const CATALOG_IMAGE_MAX_BYTES = 2097152;
	const CATALOG_IMAGE_TYPES = {
		'image/jpeg': true,
		'image/png': true,
		'image/webp': true
	};

	/** @returns {string|null} error code or null when ok */
	function validateCatalogImageFile(file) {
		if (!file) return null;
		const type = String(file.type || '').toLowerCase();
		if (!CATALOG_IMAGE_TYPES[type]) {
			return 'photo_type_invalid';
		}
		if (!file.size || file.size > CATALOG_IMAGE_MAX_BYTES) {
			return 'photo_too_large';
		}
		return null;
	}

	function revokeEditPhotoObjectUrl(root) {
		if (!root || !root._snkPhotoObjectUrl) return;
		try {
			URL.revokeObjectURL(root._snkPhotoObjectUrl);
		} catch (e) { /* ignore */ }
		root._snkPhotoObjectUrl = null;
	}

	function setEditPhotoPreview(root, url, opts) {
		if (!root) return;
		const frame = root.querySelector('[data-snk-edit-photo-preview]');
		const img = root.querySelector('[data-snk-edit-photo-img]');
		const pickLabel = root.querySelector('[data-snk-edit-photo-pick-label]');
		const clearBtn = root.querySelector('[data-snk-action="clear-item-image"]');
		const hasUrl = !!(url && String(url).trim());
		const showClear = !!(opts && opts.showClear);
		revokeEditPhotoObjectUrl(root);
		if (img) {
			if (hasUrl) {
				img.hidden = false;
				img.src = url;
			} else {
				img.hidden = true;
				img.removeAttribute('src');
			}
		}
		if (frame) {
			frame.setAttribute('data-has-preview', hasUrl ? '1' : '0');
		}
		if (pickLabel) {
			pickLabel.textContent = hasUrl
				? t('Replace picture', 'Replace picture')
				: t('Choose picture', 'Choose picture');
		}
		if (clearBtn) {
			clearBtn.hidden = !showClear;
		}
	}

	async function uploadCatalogImage(itemId, file) {
		if (!file || !itemId) return;
		const invalid = validateCatalogImageFile(file);
		if (invalid) {
			throw new Error(invalid);
		}
		if (!token()) {
			await refreshCsrfToken();
		}
		let lastError = null;
		for (let attempt = 0; attempt < 2; attempt++) {
			const csrf = token();
			const fd = new FormData();
			fd.append('image', file);
			fd.append('requesttoken', csrf);
			const res = await fetch(OC.generateUrl('/apps/snackcheck/api/catalog/' + itemId + '/image'), {
				method: 'POST',
				headers: { requesttoken: csrf },
				body: fd,
				credentials: 'same-origin'
			});
			if (res.ok) {
				const ct = res.headers.get('content-type') || '';
				if (ct.indexOf('json') >= 0) {
					const data = await res.json().catch(function () { return {}; });
					if (data && data.ok === false) {
						lastError = new Error((data.error && data.error.code) || 'upload_failed');
						break;
					}
				}
				return;
			}
			if (res.status === 412 && attempt === 0) {
				const fresh = await refreshCsrfToken();
				if (fresh) {
					continue;
				}
			}
			let code = 'upload_failed';
			try {
				const j = await res.json();
				if (j && j.error && j.error.code) code = j.error.code;
			} catch (e) { /* ignore */ }
			lastError = new Error(code);
			break;
		}
		throw lastError || new Error('upload_failed');
	}

	function wireEditPhotoInputs() {
		document.querySelectorAll('[data-snk-edit-photo]').forEach(function (root) {
			if (root.dataset.snkPhotoWired === '1') return;
			root.dataset.snkPhotoWired = '1';
			const input = root.querySelector('[data-snk-edit-photo-input], input[type="file"][name="image"]');
			if (!input) return;
			input.addEventListener('change', function () {
				const file = input.files && input.files[0];
				if (!file) {
					const serverUrl = root.getAttribute('data-server-image-url') || '';
					const hasServer = root.getAttribute('data-server-has-image') === '1';
					setEditPhotoPreview(root, serverUrl, { showClear: hasServer });
					return;
				}
				const invalid = validateCatalogImageFile(file);
				if (invalid) {
					input.value = '';
					toast(userFacingError(new Error(invalid)), null, true);
					const serverUrl = root.getAttribute('data-server-image-url') || '';
					const hasServer = root.getAttribute('data-server-has-image') === '1';
					setEditPhotoPreview(root, serverUrl, { showClear: hasServer });
					return;
				}
				revokeEditPhotoObjectUrl(root);
				const objectUrl = URL.createObjectURL(file);
				root._snkPhotoObjectUrl = objectUrl;
				const frame = root.querySelector('[data-snk-edit-photo-preview]');
				const img = root.querySelector('[data-snk-edit-photo-img]');
				const pickLabel = root.querySelector('[data-snk-edit-photo-pick-label]');
				if (img) {
					img.hidden = false;
					img.src = objectUrl;
				}
				if (frame) {
					frame.setAttribute('data-has-preview', '1');
				}
				if (pickLabel) {
					pickLabel.textContent = t('Replace picture', 'Replace picture');
				}
				// Pending local file — clear still means "remove saved picture" only when server has one.
				const clearBtn = root.querySelector('[data-snk-action="clear-item-image"]');
				if (clearBtn) {
					clearBtn.hidden = root.getAttribute('data-server-has-image') !== '1';
				}
			});
		});
	}

	function activeChipTarget() {
		return document.querySelector('.snk-chip-target[data-snk-active="1"]');
	}
	function chipLabels(input) {
		if (!input._snkLabels) {
			input._snkLabels = {};
			try {
				const raw = input.getAttribute('data-snk-labels') || '{}';
				const parsed = JSON.parse(raw);
				if (parsed && typeof parsed === 'object') {
					Object.keys(parsed).forEach(function (k) {
						input._snkLabels[k] = String(parsed[k] || k);
					});
				}
			} catch (e) { /* ignore */ }
		}
		return input._snkLabels;
	}
	function chipIds(input) {
		return String(input.value || '').split(/[\s,;]+/).filter(Boolean);
	}
	function renderChipList(input) {
		const field = input.closest('[data-snk-chip-field]');
		if (!field) return;
		const list = field.querySelector('[data-snk-chip-list]');
		const empty = field.querySelector('[data-snk-chip-empty]');
		if (!list) return;
		const labels = chipLabels(input);
		const ids = chipIds(input);
		list.replaceChildren();
		ids.forEach(function (id) {
			const dn = labels[id] || id;
			const li = document.createElement('li');
			li.className = 'snk-chip';
			li.setAttribute('role', 'listitem');
			li.setAttribute('data-snk-chip-id', id);
			const text = document.createElement('span');
			text.className = 'snk-chip__text';
			text.textContent = dn;
			li.appendChild(text);
			if (dn !== id) {
				const meta = document.createElement('span');
				meta.className = 'snk-chip__id';
				meta.textContent = id;
				li.appendChild(meta);
			}
			const btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'snk-chip__remove';
			btn.setAttribute('data-snk-chip-remove', '1');
			btn.setAttribute('aria-label', t('Remove %s', 'Remove %s').replace('%s', dn));
			btn.textContent = '×';
			btn.addEventListener('click', function (ev) {
				ev.preventDefault();
				ev.stopPropagation();
				removeChipId(input, id);
			});
			li.appendChild(btn);
			list.appendChild(li);
		});
		if (empty) {
			empty.hidden = ids.length > 0;
		}
		input.setAttribute('value', input.value);
	}
	function removeChipId(input, userId) {
		if (input.classList.contains('snk-chip-single')) {
			input.value = '';
		} else {
			input.value = chipIds(input).filter(function (id) { return id !== userId; }).join(',');
		}
		const labels = chipLabels(input);
		delete labels[userId];
		input.setAttribute('data-snk-labels', JSON.stringify(labels));
		renderChipList(input);
		input.dispatchEvent(new Event('change', { bubbles: true }));
		document.dispatchEvent(new CustomEvent('snk-chips-changed'));
		updateChipHints();
	}
	function setChipTarget(input, userId, single, displayName) {
		if (!input) return;
		const labels = chipLabels(input);
		const dn = (displayName && String(displayName)) || userId;
		labels[userId] = dn;
		input.setAttribute('data-snk-labels', JSON.stringify(labels));
		if (single || input.classList.contains('snk-chip-single')) {
			input.value = userId;
		} else {
			const cur = chipIds(input);
			if (cur.indexOf(userId) === -1) cur.push(userId);
			input.value = cur.join(',');
		}
		renderChipList(input);
		input.dispatchEvent(new Event('change', { bubbles: true }));
		document.dispatchEvent(new CustomEvent('snk-chips-changed'));
	}
	function activateChipTarget(input) {
		if (!input) return;
		document.querySelectorAll('.snk-chip-target').forEach(function (x) {
			x.removeAttribute('data-snk-active');
			const f = x.closest('[data-snk-chip-field]');
			if (f) f.classList.remove('is-active');
		});
		input.setAttribute('data-snk-active', '1');
		const field = input.closest('[data-snk-chip-field]');
		if (field) field.classList.add('is-active');
		updateChipHints();
		const search = findUserSearchNear(input);
		if (search) {
			search.classList.add('snk-input--ready');
			window.setTimeout(function () { search.focus(); }, 0);
		}
	}
	function findUserSearchNear(el) {
		const field = el && el.closest ? el.closest('[data-snk-chip-field]') : null;
		if (field && field.classList.contains('snk-chip-field--inline')) {
			const inField = field.querySelector('[data-snk-user-search]');
			if (inField) return inField;
		}
		const form = el && el.closest ? el.closest('form') : null;
		const section = el && el.closest
			? (el.closest('.snk-proxy-pick')
				|| el.closest('#snk-mode-proxy')
				|| el.closest('.snk-chip-search')
				|| el.closest('.snk-card__body')
				|| el.closest('.snk-section')
				|| el.closest('.snk-card'))
			: null;
		if (section) {
			const inSection = section.querySelector('[data-snk-user-search]');
			if (inSection) return inSection;
		}
		if (form) {
			const inForm = form.querySelector('[data-snk-user-search]');
			if (inForm) return inForm;
		}
		return document.querySelector('[data-snk-user-search]');
	}
	function findResultsList(input) {
		const wrap = input.closest('.snk-chip-search')
			|| input.closest('.snk-proxy-pick')
			|| (input.parentElement && input.parentElement.parentElement)
			|| input.parentElement;
		if (wrap) {
			const local = wrap.querySelector('[data-snk-user-results]');
			if (local) return local;
		}
		return document.querySelector('[data-snk-user-results]');
	}
	function resolveChipTargetForSearch(searchInput) {
		const field = searchInput.closest('[data-snk-chip-field]');
		if (field) {
			const chip = field.querySelector('.snk-chip-target');
			if (chip) {
				if (chip.getAttribute('data-snk-active') !== '1') {
					activateChipTarget(chip);
				}
				return chip;
			}
		}
		const active = activeChipTarget();
		if (active) return active;
		// Chip targets sit beside the search box — never scope to .snk-chip-search alone.
		const scope = searchInput.closest('.snk-proxy-pick')
			|| searchInput.closest('#snk-mode-proxy')
			|| searchInput.closest('[data-snk-proxy-fields]')
			|| searchInput.closest('form')
			|| searchInput.closest('.snk-section')
			|| document;
		const chips = scope.querySelectorAll('.snk-chip-target');
		if (chips.length === 1) {
			activateChipTarget(chips[0]);
			return chips[0];
		}
		return null;
	}
	function updateChipHints() {
		const active = activeChipTarget();
		document.querySelectorAll('[data-snk-chip-hint]').forEach(function (el) {
			if (active) {
				const label = active.closest('label');
				const name = label && label.querySelector('span') ? label.querySelector('span').textContent.trim() : '';
				if (name) {
					el.textContent = t('Search below → adding to {field}', 'Search below → adding to {field}').replace('{field}', name);
				} else {
					el.textContent = t('Field selected — search below', 'Field selected — search below');
				}
			} else {
				el.textContent = t('Choose… then search', 'Choose… then search');
			}
		});
	}
	function formBodyLastWins(fd) {
		const body = {};
		fd.forEach(function (value, key) {
			// Never coerce File into the JSON/urlencoded body — image uploads use FormData separately.
			if (typeof File !== 'undefined' && value instanceof File) {
				return;
			}
			body[key] = value;
		});
		return body;
	}
	function wireChipFields() {
		document.querySelectorAll('.snk-chip-target').forEach(function (input) {
			renderChipList(input);
			const field = input.closest('[data-snk-chip-field]');
			if (!field || field.dataset.snkChipWired === '1') return;
			field.dataset.snkChipWired = '1';
			field.querySelectorAll('[data-snk-chip-activate]').forEach(function (btn) {
				btn.addEventListener('click', function (ev) {
					ev.preventDefault();
					activateChipTarget(input);
				});
			});
			field.addEventListener('click', function (ev) {
				if (ev.target.closest('[data-snk-chip-remove]')) return;
				if (ev.target.closest('[data-snk-chip-activate]')) return;
				activateChipTarget(input);
			});
			// Proxy single-colleague: mark ready without focusing (panel may be hidden on Log/Me).
			if (field.getAttribute('data-snk-chip-auto') === '1') {
				input.setAttribute('data-snk-active', '1');
				field.classList.add('is-active');
				const search = findUserSearchNear(input);
				if (search) search.classList.add('snk-input--ready');
			}
		});
		updateChipHints();
	}
	function wireUserSearch() {
		document.querySelectorAll('[data-snk-user-search]').forEach(function (input) {
			const list = findResultsList(input);
			if (!list || !window.OC) return;
			let timer = null;
			let inflight = 0;
			let activeIdx = 0;
			if (!list.id) {
				list.id = 'snk-user-results-' + Math.random().toString(36).slice(2, 9);
			}
			list.setAttribute('role', 'listbox');
			if (!list.getAttribute('aria-label')) {
				list.setAttribute('aria-label', t('Matching people', 'Matching people'));
			}
			input.setAttribute('role', 'combobox');
			input.setAttribute('aria-autocomplete', 'list');
			input.setAttribute('aria-expanded', 'false');
			input.setAttribute('aria-controls', list.id);
			input.setAttribute('aria-haspopup', 'listbox');

			function setExpanded(open) {
				input.setAttribute('aria-expanded', open ? 'true' : 'false');
				if (!open) {
					input.removeAttribute('aria-activedescendant');
					activeIdx = 0;
				}
			}
			function clearResults() {
				list.replaceChildren();
				setExpanded(false);
			}
			function optionNodes() {
				return Array.prototype.slice.call(list.querySelectorAll('[role="option"]'));
			}
			function setActiveOption(idx) {
				const opts = optionNodes();
				if (!opts.length) {
					input.removeAttribute('aria-activedescendant');
					return;
				}
				if (idx < 0) idx = opts.length - 1;
				if (idx >= opts.length) idx = 0;
				activeIdx = idx;
				opts.forEach(function (li, i) {
					const on = i === activeIdx;
					li.setAttribute('aria-selected', on ? 'true' : 'false');
					if (on) {
						input.setAttribute('aria-activedescendant', li.id);
						li.scrollIntoView({ block: 'nearest' });
					}
				});
			}
			function pickRow(u) {
				const chip = resolveChipTargetForSearch(input) || activeChipTarget();
				if (!chip) {
					toast(t('Choose… then search', 'Choose… then search'));
					return;
				}
				const id = u.gid || u.userId;
				setChipTarget(chip, id, undefined, u.displayName || id);
				updateChipHints();
				input.value = '';
				clearResults();
				const hosp = document.getElementById('snk-hosp-enabled');
				if (hosp) hosp.dispatchEvent(new Event('change'));
			}
			function renderRows(rows) {
				list.replaceChildren();
				if (!rows.length) {
					const empty = document.createElement('li');
					empty.setAttribute('role', 'status');
					empty.className = 'snk-user-results__empty';
					empty.textContent = t('No matches', 'No matches');
					list.appendChild(empty);
					setExpanded(true);
					return;
				}
				rows.forEach(function (u, oi) {
					const li = document.createElement('li');
					const oid = list.id + '-o' + oi;
					li.id = oid;
					li.setAttribute('role', 'option');
					li.setAttribute('aria-selected', oi === 0 ? 'true' : 'false');
					const id = u.gid || u.userId;
					const dn = u.displayName && String(u.displayName) !== String(id) ? String(u.displayName) : '';
					if (dn) {
						const line = document.createElement('div');
						line.className = 'snk-user-results__name';
						line.textContent = dn;
						li.appendChild(line);
						const sub = document.createElement('div');
						sub.className = 'snk-user-results__id';
						sub.textContent = String(id);
						li.appendChild(sub);
					} else {
						li.textContent = String(id);
					}
					li.addEventListener('mousedown', function (ev) {
						if (ev.button !== 0) return;
						ev.preventDefault();
						pickRow(u);
					});
					list.appendChild(li);
				});
				setExpanded(true);
				setActiveOption(0);
			}

			input.addEventListener('focus', function () {
				resolveChipTargetForSearch(input);
			});
			input.addEventListener('input', function () {
				clearTimeout(timer);
				inflight += 1;
				const q = input.value.trim();
				if (q.length < 2) {
					clearResults();
					return;
				}
				const my = inflight;
				timer = setTimeout(async function () {
					const target = resolveChipTargetForSearch(input);
					if (!target) {
						if (my !== inflight) return;
						clearResults();
						toast(t('Choose… then search', 'Choose… then search'));
						updateChipHints();
						return;
					}
					const isGroups = target.getAttribute('data-snk-picker') === 'groups';
					const scope = (input.getAttribute('data-snk-search-scope') || 'access').toLowerCase();
					let url = isGroups
						? OC.generateUrl('/apps/snackcheck/api/admin/groups/search') + '?q=' + encodeURIComponent(q)
						: OC.generateUrl('/apps/snackcheck/api/admin/users/search') + '?q=' + encodeURIComponent(q);
					if (!isGroups && scope === 'directory') {
						url += '&scope=directory';
					}
					try {
						const res = await fetch(url, {
							headers: { requesttoken: token() },
							credentials: 'same-origin',
						});
						const data = await res.json();
						if (my !== inflight) return;
						const rows = isGroups
							? ((data.data && data.data.groups) || []).map(function (g) {
								return { userId: g.gid, displayName: g.displayName, gid: g.gid };
							})
							: ((data.data && data.data.users) || []);
						renderRows(rows);
					} catch (e) {
						if (my !== inflight) return;
						clearResults();
						toast(t('Search failed', 'Search failed'), null, true);
					}
				}, 250);
			});
			input.addEventListener('keydown', function (ev) {
				const opts = optionNodes();
				if (ev.key === 'ArrowDown') {
					if (!opts.length) return;
					ev.preventDefault();
					setActiveOption(activeIdx + 1);
				} else if (ev.key === 'ArrowUp') {
					if (!opts.length) return;
					ev.preventDefault();
					setActiveOption(activeIdx - 1);
				} else if (ev.key === 'Enter') {
					if (!opts.length) return;
					ev.preventDefault();
					const li = opts[activeIdx] || opts[0];
					li.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true, button: 0 }));
				} else if (ev.key === 'Escape') {
					if (input.getAttribute('aria-expanded') === 'true') {
						ev.preventDefault();
						ev.stopPropagation();
						clearResults();
					}
				}
			});
			input.addEventListener('blur', function () {
				window.setTimeout(function () {
					if (list.contains(document.activeElement) || document.activeElement === input) return;
					clearResults();
				}, 150);
			});
		});
	}

	const siteSelect = document.getElementById('snk-site-select');
	if (siteSelect) {
		const stickyKey = 'snk_sticky_site_id';
		try {
			const sticky = window.localStorage.getItem(stickyKey);
			if (sticky && !new URL(window.location.href).searchParams.get('siteId')) {
				const opt = Array.prototype.find.call(siteSelect.options, function (o) { return o.value === sticky; });
				if (opt && siteSelect.value !== sticky) {
					const url = new URL(window.location.href);
					url.searchParams.set('siteId', sticky);
					window.location = url.toString();
					return;
				}
			}
		} catch (e) { /* ignore */ }
		siteSelect.addEventListener('change', function () {
			if (!siteSelect.value) {
				return;
			}
			try { window.localStorage.setItem(stickyKey, siteSelect.value); } catch (e) { /* ignore */ }
			const url = new URL(window.location.href);
			url.searchParams.set('siteId', siteSelect.value);
			window.location = url.toString();
		});
	}

	/** Sticky qty for one-tap logging (default 1). */
	let snkLogQty = 1;
	document.querySelectorAll('[data-snk-qty]').forEach(function (chip) {
		chip.addEventListener('click', function () {
			const n = Number(chip.getAttribute('data-snk-qty'));
			if (!Number.isFinite(n) || n < 1) return;
			snkLogQty = n;
			document.querySelectorAll('[data-snk-qty]').forEach(function (c) {
				const on = c === chip;
				c.classList.toggle('is-active', on);
				c.setAttribute('aria-pressed', on ? 'true' : 'false');
			});
		});
	});

	function currentLogMode() {
		const checked = document.querySelector('[data-snk-mode]:checked');
		return checked ? String(checked.value || 'self') : 'self';
	}
	function syncLogModePanels() {
		const mode = currentLogMode();
		const proxy = document.getElementById('snk-mode-proxy');
		const hosp = document.getElementById('snk-mode-hospitality');
		const lead = document.getElementById('snk-log-lead');
		if (proxy) proxy.hidden = mode !== 'proxy';
		if (hosp) hosp.hidden = mode !== 'hospitality';
		document.querySelectorAll('.snk-mode-chip').forEach(function (chip) {
			const input = chip.querySelector('[data-snk-mode]');
			chip.classList.toggle('is-active', !!(input && input.checked));
		});
		if (lead) {
			if (mode === 'proxy') {
				lead.textContent = t('Pick colleague + reason, then tap a snack.', 'Pick colleague + reason, then tap a snack.');
			} else if (mode === 'hospitality') {
				lead.textContent = t('Enter a reason, then tap a snack.', 'Enter a reason, then tap a snack.');
			} else {
				lead.textContent = t('Tap a snack. Done.', 'Tap a snack. Done.');
			}
		}
		// Auto-activate sole colleague chip so search works without a ritual tap.
		if (mode === 'proxy' && proxy) {
			const chips = proxy.querySelectorAll('.snk-chip-target');
			if (chips.length === 1) {
				activateChipTarget(chips[0]);
			}
			const search = proxy.querySelector('[data-snk-user-search]');
			if (search) {
				window.setTimeout(function () { search.focus(); }, 0);
			}
		}
		if (mode === 'hospitality') {
			const reason = document.getElementById('snk-hosp-reason');
			if (reason) {
				window.setTimeout(function () { reason.focus(); }, 0);
			}
		}
	}
	document.querySelectorAll('[data-snk-mode]').forEach(function (radio) {
		radio.addEventListener('change', syncLogModePanels);
	});
	syncLogModePanels();

	(function wireRowActionMenus() {
		document.querySelectorAll('.snk-row-actions__more').forEach(function (details) {
			details.addEventListener('toggle', function () {
				if (!details.open) return;
				document.querySelectorAll('.snk-row-actions__more[open]').forEach(function (other) {
					if (other !== details) other.open = false;
				});
			});
		});
	})();

	(function wirePayrollSiteFilters() {
		const bar = document.querySelector('[data-snk-payroll-site-filters]');
		const hidden = document.getElementById('snk-payroll-site');
		if (!bar || !hidden) return;
		function syncTabIndex() {
			bar.querySelectorAll('[data-snk-payroll-site]').forEach(function (b) {
				const on = b.getAttribute('aria-checked') === 'true';
				b.tabIndex = on ? 0 : -1;
			});
		}
		function activate(btn) {
			if (!btn) return;
			const val = String(btn.getAttribute('data-snk-payroll-site') || 'all');
			hidden.value = val;
			bar.querySelectorAll('[data-snk-payroll-site]').forEach(function (b) {
				const on = b === btn;
				b.classList.toggle('snk-filter--active', on);
				b.setAttribute('aria-checked', on ? 'true' : 'false');
			});
			syncTabIndex();
		}
		syncTabIndex();
		bar.addEventListener('click', function (ev) {
			const btn = ev.target.closest('[data-snk-payroll-site]');
			if (!btn || !bar.contains(btn)) return;
			ev.preventDefault();
			activate(btn);
		});
		bar.addEventListener('keydown', function (ev) {
			const radios = Array.prototype.slice.call(bar.querySelectorAll('[data-snk-payroll-site]'));
			if (!radios.length) return;
			const cur = document.activeElement && bar.contains(document.activeElement)
				? document.activeElement
				: radios.find(function (r) { return r.getAttribute('aria-checked') === 'true'; }) || radios[0];
			const idx = radios.indexOf(cur);
			if (idx < 0) return;
			let next = -1;
			if (ev.key === 'ArrowRight' || ev.key === 'ArrowDown') next = (idx + 1) % radios.length;
			if (ev.key === 'ArrowLeft' || ev.key === 'ArrowUp') next = (idx - 1 + radios.length) % radios.length;
			if (ev.key === 'Home') next = 0;
			if (ev.key === 'End') next = radios.length - 1;
			if (next < 0) return;
			ev.preventDefault();
			activate(radios[next]);
			radios[next].focus();
		});
	})();

	(function wireLogBrowse() {
		const root = document.querySelector('[data-snk-log-browse], [data-snk-log-catalog]');
		if (!root && !document.querySelector('[data-snk-log-catalog]')) return;
		const find = document.querySelector('[data-snk-log-find]');
		const filters = document.querySelector('[data-snk-log-filters]');
		const empty = document.querySelector('[data-snk-log-empty]');
		let cat = 'all';
		function apply() {
			const q = find ? String(find.value || '').trim().toLowerCase() : '';
			let visible = 0;
			document.querySelectorAll('[data-snk-tile-item]').forEach(function (li) {
				const name = String(li.getAttribute('data-snk-name') || '');
				const itemCat = String(li.getAttribute('data-snk-cat') || '');
				const catOk = cat === 'all' || itemCat === cat;
				const textOk = !q || name.indexOf(q) !== -1;
				const show = catOk && textOk;
				li.hidden = !show;
				if (show) visible += 1;
			});
			document.querySelectorAll('[data-snk-log-group]').forEach(function (sec) {
				const any = sec.querySelector('[data-snk-tile-item]:not([hidden])');
				sec.hidden = !any;
			});
			if (empty) empty.hidden = visible > 0;
		}
		if (find) {
			find.addEventListener('input', apply);
		}
		if (filters) {
			filters.addEventListener('click', function (ev) {
				const btn = ev.target.closest('[data-snk-log-cat]');
				if (!btn) return;
				cat = String(btn.getAttribute('data-snk-log-cat') || 'all');
				filters.querySelectorAll('[data-snk-log-cat]').forEach(function (b) {
					const on = b === btn;
					b.classList.toggle('snk-filter--active', on);
					b.setAttribute('aria-pressed', on ? 'true' : 'false');
				});
				apply();
			});
		}
	})();

	function flashTile(btn, ok) {
		if (!btn) return;
		btn.classList.remove('is-logging', 'is-ok', 'is-err');
		btn.classList.add(ok ? 'is-ok' : 'is-err');
		window.setTimeout(function () {
			btn.classList.remove('is-ok', 'is-err');
		}, ok ? 700 : 1400);
	}

	const benefitsForm = document.getElementById('snk-benefits-form');
	if (benefitsForm) {
		function multiSiteControl() {
			return document.getElementById('snk-multisite-enabled')
				|| benefitsForm.querySelector('input[name="multiSiteEnabled"][type="checkbox"]');
		}
		function multiSiteIsOn(el) {
			return !!(el && el.type === 'checkbox' && el.checked);
		}
		function syncMultiSiteWas(el) {
			if (!el) return;
			el.setAttribute('data-was', multiSiteIsOn(el) ? '1' : '0');
		}
		benefitsForm.addEventListener('submit', async function (ev) {
			const sel = multiSiteControl();
			if (!sel) return;
			const turningOn = multiSiteIsOn(sel) && sel.getAttribute('data-was') !== '1';
			if (turningOn) {
				ev.preventDefault();
				ev.stopImmediatePropagation();
				const ok = await snkConfirm(
					t(
						'Enable multi-site? Existing catalog, devices, and logs stay on the Default site. You can add more sites next.',
						'Enable multi-site? Existing catalog, devices, and logs stay on the Default site. You can add more sites next.'
					),
					t('Enable multi-site', 'Enable multi-site')
				);
				if (!ok) {
					sel.checked = false;
					syncMultiSiteWas(sel);
					return;
				}
				syncMultiSiteWas(sel);
				if (typeof benefitsForm.requestSubmit === 'function') {
					benefitsForm.requestSubmit();
				} else {
					// Never use HTMLFormElement.submit() — it skips the AJAX submit listener.
					benefitsForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
				}
			}
		}, true);
		const ms = multiSiteControl();
		if (ms) {
			syncMultiSiteWas(ms);
			ms.addEventListener('change', function () {
				if (!ms.checked) {
					ms.setAttribute('data-was', '0');
				}
			});
		}
		document.addEventListener('snk-settings-saved', function () {
			syncMultiSiteWas(multiSiteControl());
		});
	}

	document.addEventListener('click', async function (ev) {
		const btn = ev.target.closest('[data-snk-action]');
		if (!btn) return;
		const action = btn.getAttribute('data-snk-action');
		try {
			btn.disabled = true;
			if (action === 'log') {
				btn.classList.add('is-logging');
				btn.setAttribute('aria-busy', 'true');
				const mode = currentLogMode();
				const qty = snkLogQty >= 1 ? snkLogQty : 1;
				const payload = {
					itemId: Number(btn.getAttribute('data-item-id')),
					siteId: Number(btn.getAttribute('data-site-id')),
					qty: qty,
					mode: mode
				};
				if (mode === 'proxy') {
					const target = document.querySelector('[data-snk-proxy-fields] [name="targetUserId"]');
					const reason = document.getElementById('snk-proxy-reason');
					const uid = target ? String(target.value || '').trim() : '';
					const why = reason ? String(reason.value || '').trim() : '';
					if (!uid) {
						toast(t('Pick a colleague first', 'Pick a colleague first'));
						const search = document.querySelector('#snk-mode-proxy [data-snk-user-search]');
						if (search) search.focus();
						btn.classList.remove('is-logging');
						btn.removeAttribute('aria-busy');
						flashTile(btn, false);
						return;
					}
					if (why.length < 3) {
						toast(t('Reason needs at least 3 characters', 'Reason needs at least 3 characters'));
						if (reason) reason.focus();
						btn.classList.remove('is-logging');
						btn.removeAttribute('aria-busy');
						flashTile(btn, false);
						return;
					}
					payload.targetUserId = uid;
					payload.proxyReason = why;
				} else if (mode === 'hospitality') {
					const reason = document.getElementById('snk-hosp-reason');
					const why = reason ? String(reason.value || '').trim() : '';
					if (why.length < 3) {
						toast(t('Reason needs at least 3 characters', 'Reason needs at least 3 characters'));
						if (reason) reason.focus();
						btn.classList.remove('is-logging');
						btn.removeAttribute('aria-busy');
						flashTile(btn, false);
						return;
					}
					payload.hospitalityReason = why;
				}
				try {
					const data = await post(OC.generateUrl('/apps/snackcheck/api/logs'), payload);
					const label = mode === 'proxy'
						? t('Proxy logged', 'Proxy logged')
						: (mode === 'hospitality' ? t('Booked on company', 'Booked on company') : t('Logged', 'Logged'));
					toast((data.data && data.data.replay) ? t('OK (replay)', 'OK (replay)') : label, data.data && data.data.id);
					flashTile(btn, true);
				} catch (logErr) {
					flashTile(btn, false);
					throw logErr;
				} finally {
					btn.classList.remove('is-logging');
					btn.removeAttribute('aria-busy');
				}
			} else if (action === 'undo') {
				await post(OC.generateUrl('/apps/snackcheck/api/logs/' + btn.getAttribute('data-log-id') + '/undo'), {});
				toast(t('Undone', 'Undone'));
			} else if (action === 'void-log') {
				const dlg = document.getElementById('snk-void-dialog');
				const idEl = document.getElementById('snk-void-log-id');
				if (dlg && idEl && typeof dlg.showModal === 'function') {
					idEl.value = btn.getAttribute('data-log-id') || '';
					openSnkDialog(dlg, btn);
				}
			} else if (action === 'focus-site') {
				const sel = document.getElementById('snk-site-select');
				if (sel) {
					if (typeof sel.focus === 'function') {
						sel.focus();
					}
					if (sel.scrollIntoView) {
						sel.scrollIntoView({ behavior: 'smooth', block: 'center' });
					}
				} else {
					toast(t('Use the Site menu in the page header', 'Use the Site menu in the page header'));
				}
			} else if (action === 'starter') {
				const siteEl = document.getElementById('snk-site-select');
				const starterBody = {};
				if (siteEl && siteEl.tagName === 'SELECT' && siteEl.value) {
					starterBody.siteId = Number(siteEl.value);
				}
				await post(OC.generateUrl('/apps/snackcheck/api/catalog/starter'), starterBody);
				window.location.reload();
			} else if (action === 'close-period') {
				const id = btn.getAttribute('data-period-id');
				const data = await post(OC.generateUrl('/apps/snackcheck/api/periods/' + id + '/close'), { confirm: '0' });
				if (data.data && data.data.warnings && data.data.warnings.length) {
					const labels = {
						zero_logs: t('No snacks logged this period', 'No snacks logged this period'),
						huge_mom_delta: t('Consumption changed a lot vs last period', 'Consumption changed a lot vs last period')
					};
					const msg = data.data.warnings.map(function (w) { return labels[w] || w; }).join('; ');
					const dlg = document.getElementById('snk-close-dialog');
					const idEl = document.getElementById('snk-close-period-id');
					const warnEl = document.getElementById('snk-close-warnings');
					if (dlg && idEl && typeof dlg.showModal === 'function') {
						idEl.value = id || '';
						if (warnEl) warnEl.textContent = msg;
						openSnkDialog(dlg, btn);
						return;
					}
					const okClose = await snkConfirm(msg + '. ' + t('Close anyway?', 'Close anyway?'), t('Close period', 'Close period'), { danger: true });
					if (!okClose) return;
					await post(OC.generateUrl('/apps/snackcheck/api/periods/' + id + '/close'), { confirm: '1' });
				} else if (!(data.data && data.data.state === 'closed')) {
					await post(OC.generateUrl('/apps/snackcheck/api/periods/' + id + '/close'), { confirm: '1' });
				}
				window.location.reload();
			} else if (action === 'reopen-period') {
				const dlg = document.getElementById('snk-reopen-dialog');
				const idEl = document.getElementById('snk-reopen-period-id');
				const reasonEl = document.getElementById('snk-reopen-reason');
				if (dlg && idEl && typeof dlg.showModal === 'function') {
					idEl.value = btn.getAttribute('data-period-id') || '';
					if (reasonEl) reasonEl.value = '';
					openSnkDialog(dlg, btn);
					return;
				}
				toast(t('Reopen dialog missing — reload the Periods page.', 'Reopen dialog missing — reload the Periods page.'));
				return;
			} else if (action === 'handed-hr') {
				await post(OC.generateUrl('/apps/snackcheck/api/periods/' + btn.getAttribute('data-period-id') + '/handed-to-hr'), {});
				toast(t('Marked handed to HR', 'Marked handed to HR'));
				window.location.reload();
			} else if (action === 'open-next-period') {
				await post(OC.generateUrl('/apps/snackcheck/api/periods/open-next'), {});
				window.location.reload();
			} else if (action === 'payroll') {
				const siteEl = document.getElementById('snk-payroll-site');
				const siteQ = siteEl && siteEl.value && siteEl.value !== 'all' ? ('&siteId=' + encodeURIComponent(siteEl.value)) : '';
				window.location = OC.generateUrl('/apps/snackcheck/api/periods/' + btn.getAttribute('data-period-id') + '/payroll?format=xlsx' + siteQ);
			} else if (action === 'hospitality-export') {
				const siteEl = document.getElementById('snk-payroll-site') || document.getElementById('snk-site-select');
				const siteQ = siteEl && siteEl.value && siteEl.value !== 'all' ? ('?siteId=' + encodeURIComponent(siteEl.value)) : '';
				window.location = OC.generateUrl('/apps/snackcheck/api/periods/' + btn.getAttribute('data-period-id') + '/hospitality-export' + siteQ);
			} else if (action === 'shopping-csv') {
				const siteEl = document.getElementById('snk-site-select');
				const params = new URLSearchParams();
				params.set('format', 'csv');
				if (siteEl && siteEl.value) {
					params.set('siteId', siteEl.value);
				}
				const cat = new URL(window.location.href).searchParams.get('category');
				if (cat && cat !== 'all') {
					params.set('category', cat);
				}
				window.location = OC.generateUrl('/apps/snackcheck/api/pulse/shopping-list') + '?' + params.toString();
			} else if (action === 'shopping-print') {
				const siteEl = document.getElementById('snk-site-select');
				const params = new URLSearchParams();
				params.set('format', 'html');
				if (siteEl && siteEl.value) {
					params.set('siteId', siteEl.value);
				}
				const cat = new URL(window.location.href).searchParams.get('category');
				if (cat && cat !== 'all') {
					params.set('category', cat);
				}
				window.open(OC.generateUrl('/apps/snackcheck/api/pulse/shopping-list') + '?' + params.toString(), '_blank', 'noopener');
			} else if (action === 'delete-item') {
				const dlg = document.getElementById('snk-deactivate-dialog');
				if (dlg) {
					document.getElementById('snk-deactivate-item-id').value = btn.getAttribute('data-item-id') || '';
					openSnkDialog(dlg, btn);
					return;
				}
				const okDel = await snkConfirm(t('Deactivate this item?', 'Deactivate this item?'), t('Deactivate item', 'Deactivate item'), { danger: true });
				if (!okDel) return;
				await api('DELETE', OC.generateUrl('/apps/snackcheck/api/catalog/' + btn.getAttribute('data-item-id')));
				window.location.reload();
			} else if (action === 'edit-item') {
				const dlg = document.getElementById('snk-edit-item-dialog');
				if (!dlg) return;
				document.getElementById('snk-edit-item-id').value = btn.getAttribute('data-item-id') || '';
				document.getElementById('snk-edit-name').value = btn.getAttribute('data-name') || '';
				document.getElementById('snk-edit-price').value = String(btn.getAttribute('data-price-euro') || '0').replace('.', ',');
				document.getElementById('snk-edit-category').value = btn.getAttribute('data-category') || 'other';
				document.getElementById('snk-edit-onhand').value = btn.getAttribute('data-on-hand') || '';
				document.getElementById('snk-edit-par').value = btn.getAttribute('data-par') || '';
				document.getElementById('snk-edit-active').value = btn.getAttribute('data-active') === '0' ? '0' : '1';
				const tagCsv = String(btn.getAttribute('data-tags') || '');
				const tagSet = {};
				tagCsv.split(/[\s,;]+/).filter(Boolean).forEach(function (tag) { tagSet[tag] = true; });
				dlg.querySelectorAll('.snk-edit-tag').forEach(function (cb) {
					cb.checked = !!tagSet[cb.getAttribute('data-tag')];
				});
				const photoRoot = dlg.querySelector('[data-snk-edit-photo]');
				const imgInput = document.getElementById('snk-edit-image');
				if (imgInput) imgInput.value = '';
				const hasImage = btn.getAttribute('data-has-image') === '1';
				const imageUrl = btn.getAttribute('data-image-url') || '';
				if (photoRoot) {
					photoRoot.setAttribute('data-server-has-image', hasImage ? '1' : '0');
					photoRoot.setAttribute('data-server-image-url', hasImage ? imageUrl : '');
					setEditPhotoPreview(photoRoot, hasImage ? imageUrl : '', { showClear: hasImage });
					const clearBtn = photoRoot.querySelector('[data-snk-action="clear-item-image"]');
					if (clearBtn) {
						clearBtn.setAttribute('data-item-id', btn.getAttribute('data-item-id') || '');
					}
				}
				const more = dlg.querySelector('details.snk-details');
				if (more) more.open = false;
				openSnkDialog(dlg, btn);
			} else if (action === 'clear-item-image') {
				const itemId = btn.getAttribute('data-item-id') || '';
				if (!itemId) return;
				const okClear = await snkConfirm(
					t('Remove this picture?', 'Remove this picture?'),
					t('Remove picture', 'Remove picture'),
					{ danger: true }
				);
				if (!okClear) return;
				await api('DELETE', OC.generateUrl('/apps/snackcheck/api/catalog/' + itemId + '/image'));
				toast(t('Picture removed', 'Picture removed'));
				window.location.reload();
			} else if (action === 'copy-item') {
				const dlg = document.getElementById('snk-copy-item-dialog');
				if (!dlg) return;
				document.getElementById('snk-copy-item-id').value = btn.getAttribute('data-item-id') || '';
				const nameEl = document.getElementById('snk-copy-item-name');
				if (nameEl) {
					nameEl.textContent = btn.getAttribute('data-name') || '';
				}
				openSnkDialog(dlg, btn);
			} else if (action === 'restock') {
				const itemId = btn.getAttribute('data-item-id') || '';
				const def = Number(btn.getAttribute('data-default-qty') || '1');
				const qty = Number.isFinite(def) && def >= 1 ? def : 1;
				// One-tap restock when marked instant (pulse top-up + catalog Restock).
				if (btn.getAttribute('data-instant') === '1' && itemId) {
					await post(OC.generateUrl('/apps/snackcheck/api/catalog/' + itemId + '/restock'), { qty: qty });
					toast(t('Restocked', 'Restocked') + ' +' + qty);
					window.location.reload();
					return;
				}
				const dlg = document.getElementById('snk-restock-dialog') || ensureRestockDialog();
				document.getElementById('snk-restock-item-id').value = itemId;
				document.getElementById('snk-restock-qty').value = String(qty);
				openSnkDialog(dlg, btn);
			} else if (action === 'deactivate-site') {
				const okSite = await snkConfirm(t('Deactivate this site?', 'Deactivate this site?'), t('Deactivate site', 'Deactivate site'), { danger: true });
				if (!okSite) return;
				await api('PUT', OC.generateUrl('/apps/snackcheck/api/sites/' + btn.getAttribute('data-site-id')), { active: '0' });
				window.location.reload();
			} else if (action === 'activate-site') {
				await api('PUT', OC.generateUrl('/apps/snackcheck/api/sites/' + btn.getAttribute('data-site-id')), { active: '1' });
				window.location.reload();
			} else if (action === 'revoke-terminal') {
				const okRevoke = await snkConfirm(
					t('Revoke this kitchen tablet? It will stop working immediately.', 'Revoke this kitchen tablet? It will stop working immediately.'),
					t('Revoke tablet', 'Revoke tablet'),
					{ danger: true }
				);
				if (!okRevoke) return;
				await post(OC.generateUrl('/apps/snackcheck/api/admin/license/terminals/revoke'), {
					deviceId: btn.getAttribute('data-device-id') || '',
				});
				window.location.reload();
			} else if (action === 'clear-license') {
				const okClearLic = await snkConfirm(
					t('Remove this license? Kitchen tablets stop working immediately. The web app stays free.', 'Remove this license? Kitchen tablets stop working immediately. The web app stays free.'),
					t('Remove license', 'Remove license'),
					{ danger: true }
				);
				if (!okClearLic) return;
				await api('DELETE', OC.generateUrl('/apps/snackcheck/api/admin/license'));
				toast(t('License removed', 'License removed'));
				window.location.reload();
			}
		} catch (e) {
			toast(userFacingError(e), null, true);
		} finally {
			btn.disabled = false;
		}
	});

	document.addEventListener('submit', async function (ev) {
		const form = ev.target.closest('[data-snk-form]');
		if (!form) return;
		ev.preventDefault();
		if (form.getAttribute('data-snk-busy') === '1') return;
		const kind = form.getAttribute('data-snk-form');
		const fd = new FormData(form);
		// Last-wins for duplicate keys (hidden 0 + checkbox 1 switches).
		const body = formBodyLastWins(fd);
		const submitBtns = Array.prototype.slice.call(form.querySelectorAll('button[type="submit"], input[type="submit"]'));
		form.setAttribute('data-snk-busy', '1');
		form.setAttribute('aria-busy', 'true');
		submitBtns.forEach(function (b) { b.disabled = true; });
		announce(t('Saving…', 'Saving…'));
		try {
			if (kind === 'license') {
				await post(OC.generateUrl('/apps/snackcheck/api/admin/license'), body);
				window.location.reload();
			} else if (kind === 'terminal') {
				const data = await post(OC.generateUrl('/apps/snackcheck/api/admin/license/terminals'), body);
				const tok = (data.data && data.data.deviceToken) || '';
				const panel = document.getElementById('snk-terminal-token-panel') || (function () {
					const p = document.createElement('div');
					p.id = 'snk-terminal-token-panel';
					p.className = 'snk-callout snk-callout--ok';
					p.setAttribute('role', 'status');
					form.parentNode.insertBefore(p, form.nextSibling);
					return p;
				})();
				panel.hidden = false;
				panel.innerHTML = '';
				const label = document.createElement('p');
				label.textContent = t('Copy this token onto the kitchen tablet now — it is shown only once.', 'Copy this token onto the kitchen tablet now — it is shown only once.');
				panel.appendChild(label);
				const code = document.createElement('code');
				code.className = 'snk-token';
				code.textContent = tok;
				panel.appendChild(code);
				const copyBtn = document.createElement('button');
				copyBtn.type = 'button';
				copyBtn.className = 'snk-btn snk-btn--primary';
				copyBtn.textContent = t('Copy token', 'Copy token');
				copyBtn.addEventListener('click', function () {
					if (navigator.clipboard && navigator.clipboard.writeText) {
						navigator.clipboard.writeText(tok).then(function () {
							toast(t('Copied', 'Copied'));
						}).catch(function () {
							toast(tok);
						});
					} else {
						toast(tok);
					}
				});
				panel.appendChild(copyBtn);
				try {
					copyBtn.focus();
				} catch (err) { /* ignore */ }
				toast(t('Token ready — copy it below', 'Token ready — copy it below'));
			} else if (kind === 'settings') {
				if (Object.prototype.hasOwnProperty.call(body, 'subsidyAllowanceEuro')) {
					const cents = parseEuroToCentsClient(body.subsidyAllowanceEuro);
					if (cents === null) {
						toast(t('Please check your entries and try again.', 'Please check your entries and try again.'), null, true);
						return;
					}
					body.subsidyAllowanceCents = String(cents);
					delete body.subsidyAllowanceEuro;
				}
				if (String(body.hospitalityEnabled || '') === '1') {
					const company = String(body.hospitalityCompanyUserId || '').trim();
					const allow = String(body.hospitalityAllowedUserIds || '').trim();
					if (!company || !allow) {
						body.hospitalityEnabled = '0';
						const en = document.getElementById('snk-hosp-enabled');
						if (en) en.checked = false;
						toast(t('Company treats left off — add company user and allowlist first.', 'Company treats left off — add company user and allowlist first.'));
					}
				}
				await post(OC.generateUrl('/apps/snackcheck/api/admin/settings'), body);
				toast(t('Saved', 'Saved'));
				document.dispatchEvent(new CustomEvent('snk-settings-saved'));
			} else if (kind === 'catalog-create') {
				if (fd.has('priceEuro')) {
					const cents = parseEuroToCentsClient(body.priceEuro);
					if (cents === null) {
						toast(t('Please check your entries and try again.', 'Please check your entries and try again.'), null, true);
						return;
					}
					body.priceCents = cents;
					delete body.priceEuro;
				}
				const tags = fd.getAll('tags[]').concat(fd.getAll('tags')).filter(Boolean);
				if (tags.length) {
					body.tags = tags;
				}
				delete body['tags[]'];
				const fileInput = form.querySelector('input[name="image"]');
				const pendingFile = fileInput && fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
				if (pendingFile) {
					const invalid = validateCatalogImageFile(pendingFile);
					if (invalid) {
						toast(userFacingError(new Error(invalid)), null, true);
						return;
					}
				}
				delete body.image;
				const created = await post(OC.generateUrl('/apps/snackcheck/api/catalog'), body);
				const newId = created && created.data && created.data.id;
				if (newId && pendingFile) {
					await uploadCatalogImage(newId, pendingFile);
				}
				window.location.reload();
			} else if (kind === 'catalog-update') {
				const submitter = ev.submitter;
				if (submitter && submitter.value === 'cancel') {
					form.closest('dialog')?.close();
					return;
				}
				if (fd.has('priceEuro')) {
					const cents = parseEuroToCentsClient(body.priceEuro);
					if (cents === null) {
						toast(t('Please check your entries and try again.', 'Please check your entries and try again.'), null, true);
						return;
					}
					body.priceCents = cents;
					delete body.priceEuro;
				}
				body.tags = fd.getAll('tags[]').concat(fd.getAll('tags')).filter(Boolean);
				delete body['tags[]'];
				const itemId = body.itemId;
				delete body.itemId;
				delete body.image;
				const fileInput = form.querySelector('input[name="image"]');
				const pendingFile = fileInput && fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
				if (pendingFile) {
					const invalid = validateCatalogImageFile(pendingFile);
					if (invalid) {
						toast(userFacingError(new Error(invalid)), null, true);
						return;
					}
				}
				await api('PUT', OC.generateUrl('/apps/snackcheck/api/catalog/' + itemId), body);
				if (pendingFile) {
					await uploadCatalogImage(itemId, pendingFile);
				}
				form.closest('dialog')?.close();
				window.location.reload();
			} else if (kind === 'catalog-copy') {
				const submitter = ev.submitter;
				if (submitter && submitter.value === 'cancel') {
					form.closest('dialog')?.close();
					return;
				}
				const itemId = body.itemId;
				await post(OC.generateUrl('/apps/snackcheck/api/catalog/' + itemId + '/copy'), {
					targetSiteId: Number(body.targetSiteId)
				});
				form.closest('dialog')?.close();
				toast(t('Copied', 'Copied'));
				window.location.reload();
			} else if (kind === 'catalog-restock') {
				const submitter = ev.submitter;
				if (submitter && submitter.value === 'cancel') {
					form.closest('dialog')?.close();
					return;
				}
				const qty = Number(body.qty);
				if (!Number.isFinite(qty) || qty < 1) {
					toast(t('Enter a quantity of at least 1', 'Enter a quantity of at least 1'));
					return;
				}
				await post(OC.generateUrl('/apps/snackcheck/api/catalog/' + body.itemId + '/restock'), { qty: qty });
				form.closest('dialog')?.close();
				window.location.reload();
			} else if (kind === 'catalog-deactivate') {
				const submitter = ev.submitter;
				if (submitter && submitter.value === 'cancel') {
					form.closest('dialog')?.close();
					return;
				}
				await api('DELETE', OC.generateUrl('/apps/snackcheck/api/catalog/' + body.itemId));
				form.closest('dialog')?.close();
				window.location.reload();
			} else if (kind === 'site-create') {
				await post(OC.generateUrl('/apps/snackcheck/api/sites'), body);
				window.location.reload();
			} else if (kind === 'site-update') {
				await api('PUT', OC.generateUrl('/apps/snackcheck/api/sites/' + form.getAttribute('data-site-id')), body);
				toast(t('Saved', 'Saved'));
			} else if (kind === 'unlock-pin') {
				await post(OC.generateUrl('/apps/snackcheck/api/admin/unlock/pin'), body);
				const pinInput = form.querySelector('#snk-unlock-pin, input[name="pin"]');
				if (pinInput instanceof HTMLInputElement) {
					pinInput.value = '';
				}
				toast(t('PIN saved', 'PIN saved'));
			} else if (kind === 'unlock-qr') {
				await post(OC.generateUrl('/apps/snackcheck/api/admin/unlock/qr'), body);
				const qrInput = form.querySelector('#snk-unlock-qr, input[name="payload"]');
				if (qrInput instanceof HTMLInputElement) {
					qrInput.value = '';
				}
				toast(t('QR saved', 'QR saved'));
			} else if (kind === 'void-log') {
				const submitter = ev.submitter;
				if (submitter && submitter.value === 'cancel') {
					form.closest('dialog')?.close();
					return;
				}
				await post(OC.generateUrl('/apps/snackcheck/api/logs/' + body.logId + '/void'), { reason: body.reason });
				form.closest('dialog')?.close();
				window.location.reload();
			} else if (kind === 'reopen-period') {
				const submitter = ev.submitter;
				if (submitter && submitter.value === 'cancel') {
					form.closest('dialog')?.close();
					return;
				}
				const reason = String(body.reason || '').trim();
				if (reason.length < 3) {
					toast(t('Reopen reason required (min 3 characters)', 'Reopen reason required (min 3 characters)'));
					return;
				}
				await post(OC.generateUrl('/apps/snackcheck/api/periods/' + body.periodId + '/reopen'), { reason: reason });
				form.closest('dialog')?.close();
				window.location.reload();
			} else if (kind === 'close-period-confirm') {
				const submitter = ev.submitter;
				if (submitter && submitter.value === 'cancel') {
					form.closest('dialog')?.close();
					return;
				}
				await post(OC.generateUrl('/apps/snackcheck/api/periods/' + body.periodId + '/close'), { confirm: '1' });
				form.closest('dialog')?.close();
				window.location.reload();
			}
		} catch (e) {
			toast(userFacingError(e), null, true);
		} finally {
			form.removeAttribute('data-snk-busy');
			form.removeAttribute('aria-busy');
			submitBtns.forEach(function (b) {
				if (b.id === 'snk-benefits-save') {
					const en = document.getElementById('snk-hosp-enabled');
					if (en) {
						en.dispatchEvent(new Event('change'));
						return;
					}
				}
				b.disabled = false;
			});
		}
	});

	wireChipFields();
	wireUserSearch();
	wireEditPhotoInputs();
	/* Mobile nav: Nextcloud #app-navigation-toggle only (design-system checklist — no custom burger). */
})();
