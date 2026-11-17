/**
 * Real-user Web Vitals collector.
 *
 * Injected by the inline loader only for sampled, non-bot page views, so it
 * does no sampling itself. Metrics are queued and sent in a single beacon when
 * the page is hidden; repeated reports of the same metric instance (CLS/INP
 * growing after the user returns to the tab) replace the queued value and are
 * upserted server-side by metric id.
 */
(function () {
	'use strict';

	var config = window.wvmConfig || {};
	var api = window.webVitals;
	var queue = {};

	if (!api || !config.endpoint) {
		return;
	}

	function deviceType() {
		var width = window.innerWidth || document.documentElement.clientWidth || 0;
		if (width <= 767) {
			return 'mobile';
		}
		if (width <= 1024) {
			return 'tablet';
		}
		return 'desktop';
	}

	function browserInfo(ua) {
		var match;
		if ((match = ua.match(/Edg\/([\d.]+)/))) {
			return ['Edge', match[1]];
		}
		if ((match = ua.match(/OPR\/([\d.]+)/))) {
			return ['Opera', match[1]];
		}
		if ((match = ua.match(/SamsungBrowser\/([\d.]+)/))) {
			return ['Samsung Internet', match[1]];
		}
		if ((match = ua.match(/(?:Chrome|CriOS)\/([\d.]+)/))) {
			return ['Chrome', match[1]];
		}
		if ((match = ua.match(/(?:Firefox|FxiOS)\/([\d.]+)/))) {
			return ['Firefox', match[1]];
		}
		if ((match = ua.match(/Version\/([\d.]+).*Safari/))) {
			return ['Safari', match[1]];
		}
		return ['Other', ''];
	}

	function osName(ua) {
		if (/Windows/i.test(ua)) {
			return 'Windows';
		}
		if (/Android/i.test(ua)) {
			return 'Android';
		}
		if (/iPhone|iPad|iPod/i.test(ua)) {
			return 'iOS';
		}
		if (/Mac OS X/i.test(ua)) {
			return 'macOS';
		}
		if (/Linux/i.test(ua)) {
			return 'Linux';
		}
		return 'Unknown';
	}

	function context() {
		var ua = navigator.userAgent || '';
		var browser = browserInfo(ua);
		var connection = navigator.connection || {};
		var path = config.path || location.pathname || '/';
		if (!config.sanitizeQuery && location.search) {
			path += location.search;
		}
		return {
			path: path,
			pageType: config.pageType || 'other',
			device: deviceType(),
			browser: browser[0],
			browserVersion: browser[1].split('.')[0],
			os: osName(ua),
			connection: connection.effectiveType ? String(connection.effectiveType) : 'unknown',
			vw: window.innerWidth || 0,
			vh: window.innerHeight || 0,
			language: navigator.language || ''
		};
	}

	function text(value) {
		return typeof value === 'string' ? value.replace(/<[^>]*>/g, '').slice(0, 160) : undefined;
	}

	function resourceUrl(value) {
		if (!value || typeof value !== 'string' || value.indexOf('data:') === 0) {
			return value ? 'data:' : undefined;
		}
		return value.split(/[?#]/)[0].slice(0, 160);
	}

	function isCrossOrigin(url) {
		try {
			return new URL(url, location.href).host !== location.host;
		} catch (e) {
			return false;
		}
	}

	function lcpAttribution(source) {
		var entry = source.lcpEntry || {};
		var element = entry.element;
		var tag = element && element.tagName ? element.tagName.toLowerCase() : '';
		var kind = 'text';
		if (tag === 'img' || tag === 'image') {
			kind = 'img';
		} else if (tag === 'video') {
			kind = 'video';
		} else if (source.url) {
			kind = 'bg-image';
		}
		var output = {
			target: text(source.target),
			url: resourceUrl(source.url),
			timeToFirstByte: source.timeToFirstByte,
			resourceLoadDelay: source.resourceLoadDelay,
			resourceLoadDuration: source.resourceLoadDuration,
			elementRenderDelay: source.elementRenderDelay,
			element: kind
		};
		if (kind === 'img' && element) {
			output.lazy = element.getAttribute('loading') === 'lazy' ? 1 : 0;
			output.fetchPriority = text(element.getAttribute('fetchpriority') || 'auto');
			// Intrinsic vs rendered width (in device pixels) reveals oversized images.
			output.natW = element.naturalWidth || undefined;
			output.dispW = element.clientWidth ? Math.round(element.clientWidth * (window.devicePixelRatio || 1)) : undefined;
		}
		if (source.url) {
			output.crossOrigin = isCrossOrigin(source.url) ? 1 : 0;
		}
		if (source.lcpResourceEntry) {
			output.preloaded = source.lcpResourceEntry.initiatorType === 'link' ? 1 : 0;
			output.bytes = source.lcpResourceEntry.encodedBodySize || source.lcpResourceEntry.transferSize || undefined;
		}
		output.blocking = renderBlocking();
		return output;
	}

	// Render-blocking stylesheets/scripts of this page (Chromium), slowest first: "url|ms;url|ms".
	function renderBlocking() {
		if (!performance.getEntriesByType) {
			return undefined;
		}
		var list = performance.getEntriesByType('resource').filter(function (entry) {
			return entry.renderBlockingStatus === 'blocking';
		}).sort(function (a, b) {
			return b.duration - a.duration;
		}).slice(0, 4).map(function (entry) {
			return resourceUrl(entry.name) + '|' + Math.round(entry.duration);
		});
		return list.length ? list.join(';') : undefined;
	}

	function inpAttribution(source) {
		var output = {
			interactionTarget: text(source.interactionTarget),
			interactionType: text(source.interactionType),
			inputDelay: source.inputDelay,
			processingDuration: source.processingDuration,
			presentationDelay: source.presentationDelay,
			loadState: text(source.loadState),
			totalScriptDuration: source.totalScriptDuration,
			totalStyleAndLayoutDuration: source.totalStyleAndLayoutDuration,
			totalPaintDuration: source.totalPaintDuration
		};
		var longest = source.longestScript;
		if (longest && longest.entry) {
			output.scriptSubpart = text(longest.subpart);
			output.scriptDuration = longest.intersectingDuration;
			output.scriptSource = resourceUrl(longest.entry.sourceURL || '');
			output.scriptInvoker = text(longest.entry.invoker);
			output.scriptInvokerType = text(longest.entry.invokerType);
		}
		return output;
	}

	function clsAttribution(source) {
		var output = {
			largestShiftTarget: text(source.largestShiftTarget),
			largestShiftTime: source.largestShiftTime,
			largestShiftValue: source.largestShiftValue,
			loadState: text(source.loadState)
		};
		var shift = source.largestShiftSource;
		var node = shift && shift.node;
		if (node && node.tagName) {
			var tag = node.tagName.toLowerCase();
			output.shiftTag = tag;
			if (tag === 'img' || tag === 'video' || tag === 'iframe') {
				output.shiftNoDims = node.hasAttribute('width') && node.hasAttribute('height') ? 0 : 1;
				output.shiftSrc = resourceUrl(node.currentSrc || node.src || '');
			}
		}
		if (shift && shift.currentRect && shift.currentRect.height) {
			output.shiftH = Math.round(shift.currentRect.height);
		}
		return output;
	}

	function attribution(metric) {
		var source = metric.attribution || {};
		var output;
		switch (metric.name) {
			case 'LCP':
				output = lcpAttribution(source);
				break;
			case 'INP':
				output = inpAttribution(source);
				break;
			case 'CLS':
				output = clsAttribution(source);
				break;
			case 'FCP':
				output = { timeToFirstByte: source.timeToFirstByte, firstByteToFCP: source.firstByteToFCP, loadState: text(source.loadState) };
				break;
			case 'TTFB':
				output = {
					waitingDuration: source.waitingDuration,
					cacheDuration: source.cacheDuration,
					dnsDuration: source.dnsDuration,
					connectionDuration: source.connectionDuration,
					requestDuration: source.requestDuration
				};
				break;
			default:
				output = {};
		}
		Object.keys(output).forEach(function (key) {
			var value = output[key];
			if (value === undefined || value === null || value === '') {
				delete output[key];
			} else if (typeof value === 'number') {
				output[key] = Math.round(value * 10000) / 10000;
			}
		});
		return output;
	}

	function enqueue(metric) {
		if (!metric || !metric.name || !config.metrics || !config.metrics[metric.name]) {
			return;
		}
		var item = {
			name: metric.name,
			value: metric.value,
			id: metric.id,
			navigationType: metric.navigationType || 'unknown'
		};
		if (config.attribution) {
			try {
				item.attribution = attribution(metric);
			} catch (e) {}
		}
		queue[metric.name + ':' + metric.id] = item;
	}

	function flush() {
		var keys = Object.keys(queue);
		if (!keys.length) {
			return;
		}
		var body = JSON.stringify({
			c: context(),
			m: keys.slice(-10).map(function (key) {
				return queue[key];
			})
		});
		queue = {};

		if (navigator.sendBeacon && navigator.sendBeacon(config.endpoint, new Blob([body], { type: 'application/json' }))) {
			return;
		}
		if (window.fetch) {
			fetch(config.endpoint, {
				method: 'POST',
				body: body,
				credentials: 'omit',
				keepalive: true,
				headers: { 'Content-Type': 'application/json' }
			}).catch(function () {});
		}
	}

	['LCP', 'INP', 'CLS', 'FCP', 'TTFB'].forEach(function (name) {
		var fn = api['on' + name];
		if (config.metrics && config.metrics[name] && typeof fn === 'function') {
			fn(enqueue);
		}
	});

	// Registered after the web-vitals listeners so final LCP/CLS/INP values are queued first.
	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState === 'hidden') {
			flush();
		}
	});
	window.addEventListener('pagehide', flush);
})();
