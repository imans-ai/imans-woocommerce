/**
 * Imans Analytics — front-end tracker.
 *
 * Vanilla ES6 (no jQuery). Captures storefront sessions, page views,
 * product views, add-to-cart, remove-from-cart, begin-checkout and
 * search events, and POSTs them to /wp-json/imans-analytics/v1/track.
 *
 * Honors:
 *   - DNT: 1 (server-side bail; this script not even enqueued)
 *   - `imans_optout` cookie (server-side bail; not enqueued)
 *   - window.IMANS_ANALYTICS_HAS_CONSENT === false (client-side bail)
 *   - WP Consent API category 'statistics' (client-side bail until allow)
 *   - Sample rate (client-side bail with weighted random)
 *
 * Storage:
 *   - imans_vid cookie (visitor): random UUIDv4, 2-year SameSite=Lax expiry
 *   - imans_sid cookie (session): random UUIDv4, 30-min sliding SameSite=Lax expiry
 *
 * @package Imans\Analytics
 */
(function () {
	'use strict';

	if (typeof window === 'undefined' || !window.imansAnalyticsConfig) {
		return;
	}
	const cfg = window.imansAnalyticsConfig;

	if (!cfg.endpoint || !cfg.nonce) {
		return;
	}

	/* ---------------------- consent gating ---------------------- */

	function consentGiven() {
		if (window.IMANS_ANALYTICS_HAS_CONSENT === false) {
			return false;
		}
		if (window.IMANS_ANALYTICS_HAS_CONSENT === true) {
			return true;
		}
		if (cfg.consentMode === 'wp_consent' && typeof window.wp_has_consent === 'function') {
			try {
				return !!window.wp_has_consent('statistics');
			} catch (e) {
				return false;
			}
		}
		// 'auto' / 'none' — track unless an explicit denial is seen.
		return true;
	}

	function waitForConsent(cb) {
		if (consentGiven()) {
			cb();
			return;
		}
		const onConsent = function () {
			if (consentGiven()) {
				document.removeEventListener('wp_listen_for_consent_change', onConsent);
				document.removeEventListener('cookieyes_consent_update', onConsent);
				cb();
			}
		};
		document.addEventListener('wp_listen_for_consent_change', onConsent);
		document.addEventListener('cookieyes_consent_update', onConsent);
	}

	/* ---------------------- sampling ---------------------- */

	const sampleRate = Math.max(0, Math.min(100, Number(cfg.sampleRate) || 100));
	if (sampleRate < 100 && Math.random() * 100 >= sampleRate) {
		return;
	}

	/* ---------------------- cookies / ids ---------------------- */

	function uuid() {
		if (window.crypto && typeof window.crypto.randomUUID === 'function') {
			return window.crypto.randomUUID();
		}
		// RFC4122 v4 fallback.
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
			const r = (Math.random() * 16) | 0;
			const v = c === 'x' ? r : (r & 0x3) | 0x8;
			return v.toString(16);
		});
	}

	function readCookie(name) {
		const target = name + '=';
		const parts = document.cookie ? document.cookie.split(';') : [];
		for (let i = 0; i < parts.length; i++) {
			const p = parts[i].trim();
			if (p.indexOf(target) === 0) {
				return decodeURIComponent(p.substring(target.length));
			}
		}
		return '';
	}

	function writeCookie(name, value, maxAgeSeconds) {
		let str = name + '=' + encodeURIComponent(value) + '; Path=/; SameSite=Lax';
		if (location.protocol === 'https:') {
			str += '; Secure';
		}
		if (maxAgeSeconds) {
			str += '; Max-Age=' + maxAgeSeconds;
		}
		document.cookie = str;
	}

	const VID_NAME = 'imans_vid';
	const SID_NAME = 'imans_sid';
	const SID_TTL_SECONDS = 30 * 60;            // 30 min sliding
	const VID_TTL_SECONDS = 2 * 365 * 24 * 60 * 60; // ~2 years

	let visitorId = readCookie(VID_NAME);
	if (!visitorId) {
		visitorId = uuid();
		writeCookie(VID_NAME, visitorId, VID_TTL_SECONDS);
	}

	let sessionId = readCookie(SID_NAME);
	const isNewSession = !sessionId;
	if (isNewSession) {
		sessionId = uuid();
	}
	// Always refresh sliding expiry on activity.
	writeCookie(SID_NAME, sessionId, SID_TTL_SECONDS);

	/* ---------------------- attribution ---------------------- */

	function detectDevice() {
		const ua = (navigator.userAgent || '').toLowerCase();
		if (/ipad|tablet/.test(ua) || (ua.indexOf('android') !== -1 && ua.indexOf('mobile') === -1)) {
			return 'tablet';
		}
		if (/mobi|iphone|ipod|android/.test(ua)) {
			return 'mobile';
		}
		return 'desktop';
	}

	function parseUtm() {
		const out = {};
		try {
			const params = new URL(window.location.href).searchParams;
			['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'].forEach(function (k) {
				const v = params.get(k);
				if (v) out[k] = v.slice(0, 255);
			});
		} catch (e) { /* noop */ }
		return out;
	}

	const sessionMeta = isNewSession
		? Object.assign(
			{
				landing_page: window.location.href,
				referrer: document.referrer || '',
				device_type: detectDevice(),
			},
			parseUtm()
		)
		: {};

	/* ---------------------- event buffer + transport ---------------------- */

	const queue = [];

	function buffer(eventType, extra) {
		queue.push(Object.assign(
			{
				event_type: eventType,
				occurred_at: new Date().toISOString().replace('T', ' ').slice(0, 19),
				page_url: window.location.href,
			},
			extra || {}
		));
		if (queue.length >= 10) {
			flush(false);
		}
	}

	function buildPayload() {
		return {
			session_id: sessionId,
			visitor_id: visitorId,
			events: queue.splice(0, queue.length),
			session_meta: sessionMeta,
		};
	}

	function flush(useBeacon) {
		if (queue.length === 0) {
			return;
		}
		const payload = JSON.stringify(buildPayload());
		const url = cfg.endpoint;

		if (useBeacon && typeof navigator.sendBeacon === 'function') {
			try {
				const blob = new Blob([payload], { type: 'application/json' });
				navigator.sendBeacon(url + '?_wpnonce=' + encodeURIComponent(cfg.nonce), blob);
				return;
			} catch (e) { /* fall through */ }
		}

		try {
			fetch(url, {
				method: 'POST',
				credentials: 'same-origin',
				keepalive: true,
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': cfg.nonce,
				},
				body: payload,
			}).catch(function () { /* swallow */ });
		} catch (e) { /* noop */ }
	}

	/* ---------------------- capture ---------------------- */

	function start() {
		// Page view always (the cheap baseline).
		buffer('page_view', {});

		if (cfg.isProductPage && cfg.productId) {
			buffer('view_item', { product_id: cfg.productId });
		}

		if (cfg.isCheckoutPage) {
			buffer('begin_checkout', {});
		}

		if (cfg.searchTerm) {
			buffer('search', { search_term: cfg.searchTerm.slice(0, 255) });
		}

		// Add-to-cart: WooCommerce single-product form. Listen for submit
		// on .cart so simple and variable products both work.
		document.addEventListener('submit', function (evt) {
			const form = evt.target;
			if (!form || !form.classList || !form.classList.contains('cart')) {
				return;
			}
			const productInput = form.querySelector('input[name="add-to-cart"]') || form.querySelector('button[name="add-to-cart"]');
			const variationInput = form.querySelector('input[name="variation_id"]');
			const qtyInput = form.querySelector('input[name="quantity"]');
			buffer('add_to_cart', {
				product_id: productInput ? Number(productInput.value) || cfg.productId : cfg.productId,
				variation_id: variationInput ? Number(variationInput.value) || null : null,
				quantity: qtyInput ? Number(qtyInput.value) || 1 : 1,
			});
			// Best-effort flush before the page navigates.
			flush(true);
		}, true);

		// Remove-from-cart: WC standard remove links carry the
		// `?remove_item=<key>` query param on the cart page.
		document.addEventListener('click', function (evt) {
			const target = evt.target.closest && evt.target.closest('a.remove');
			if (!target) {
				return;
			}
			buffer('remove_from_cart', {});
		}, true);

		// Flush on tab hide / unload.
		window.addEventListener('pagehide', function () { flush(true); });
		window.addEventListener('beforeunload', function () { flush(true); });
		// Periodic flush so long sessions don't lose events on hard crashes.
		setInterval(function () { flush(false); }, 30000);
	}

	waitForConsent(start);
})();
