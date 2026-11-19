/**
 * Dashboard rendering. Markup follows the Web Vitals Monitor design system
 * (assets/css/admin.css); every value is inserted as text, never as HTML.
 */
(function () {
	'use strict';

	var config = window.wvmAdmin || {};
	var i18n = config.i18n || {};
	var thresholds = config.thresholds || {};
	var CORE = ['LCP', 'INP', 'CLS'];
	var DIAGNOSTIC = ['FCP', 'TTFB'];
	var SVG = 'http://www.w3.org/2000/svg';
	var LIVE_INTERVAL = 60000;
	var VISIBLE_ISSUES = 6;
	var state = {
		days: 7,
		device: '',
		path: '',
		timer: 0,
		request: 0,
		pageviews: 0
	};

	// ------------------------------------------------------------------ helpers

	function $(selector) {
		return document.querySelector(selector);
	}

	function t(key) {
		return Object.prototype.hasOwnProperty.call(i18n, key) ? i18n[key] : key;
	}

	// Strings of the suggestions, checklist and AI panels, which can be Persian while the rest is not.
	function ts(key) {
		var strings = (config.suggestions && config.suggestions.i18n) || {};
		return Object.prototype.hasOwnProperty.call(strings, key) ? strings[key] : t(key);
	}

	function sprintf(format) {
		var args = Array.prototype.slice.call(arguments, 1);
		var index = 0;
		return String(format).replace(/%(\d+\$)?[sd]/g, function (match, position) {
			var value = position ? args[parseInt(position, 10) - 1] : args[index++];
			return value == null ? '' : String(value);
		});
	}

	function el(tag, className, content) {
		var node = document.createElement(tag);
		if (className) {
			node.className = className;
		}
		append(node, content);
		return node;
	}

	function append(node, content) {
		if (content == null) {
			return node;
		}
		(Array.isArray(content) ? content : [content]).forEach(function (child) {
			if (child == null) {
				return;
			}
			node.appendChild(typeof child === 'object' ? child : document.createTextNode(String(child)));
		});
		return node;
	}

	function svg(tag, attrs) {
		var node = document.createElementNS(SVG, tag);
		Object.keys(attrs || {}).forEach(function (key) {
			node.setAttribute(key, attrs[key]);
		});
		return node;
	}

	function clear(node) {
		while (node && node.firstChild) {
			node.removeChild(node.firstChild);
		}
		return node;
	}

	function button(className, content, onClick) {
		var node = el('button', className, content);
		node.type = 'button';
		if (onClick) {
			node.addEventListener('click', onClick);
		}
		return node;
	}

	function ltr(text) {
		return el('bdi', '', text);
	}

	function number(value) {
		return Number(value || 0).toLocaleString();
	}

	function confident(samples) {
		return Number(samples) >= Number(config.minimumSamples || 1);
	}

	// Rating keys from the server ('needs-improvement') to the system's state names ('ni').
	function stateOf(status) {
		return { good: 'good', 'needs-improvement': 'ni', poor: 'poor' }[status] || 'unknown';
	}

	function shape(status) {
		return el('span', 'wvm-shape wvm-shape--' + stateOf(status));
	}

	function valueParts(metric, value) {
		var v = Number(value);
		if (metric === 'CLS') {
			return [v.toFixed(3), ''];
		}
		return v >= 1000 ? [(v / 1000).toFixed(2), ' s'] : [String(Math.round(v)), ' ms'];
	}

	function formatMetric(metric, value) {
		if (value == null || isNaN(Number(value))) {
			return 'n/a';
		}
		var parts = valueParts(metric, value);
		return parts[0] + parts[1];
	}

	function formatThreshold(metric, value) {
		if (metric === 'CLS') {
			return Number(value).toFixed(2);
		}
		return value >= 1000 ? (value / 1000).toFixed(1) + ' s' : value + ' ms';
	}

	function rating(metric, value) {
		var limits = thresholds[metric];
		if (!limits || value == null) {
			return 'unknown';
		}
		if (value <= limits.good) {
			return 'good';
		}
		return value <= limits.poor ? 'needs-improvement' : 'poor';
	}

	function rangeName() {
		return t('span' + state.days);
	}

	function deviceName() {
		return state.device ? t('device_' + state.device) : t('allDevices');
	}

	function query(params) {
		return Object.keys(params).filter(function (key) {
			return params[key] !== '' && params[key] != null;
		}).map(function (key) {
			return encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
		}).join('&');
	}

	function fetchJson(base, fresh) {
		var params = { days: state.days, device: state.device, path: state.path };
		if (fresh) {
			params.fresh = 1;
		}
		return fetch(base + (base.indexOf('?') === -1 ? '?' : '&') + query(params), {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.nonce }
		}).then(function (response) {
			if (!response.ok) {
				throw new Error('Request failed');
			}
			return response.json();
		});
	}

	function postJson(url, body) {
		return fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' },
			body: JSON.stringify(body)
		}).then(function (response) {
			return response.json().catch(function () {
				return {};
			}).then(function (data) {
				if (!response.ok) {
					throw new Error((data && data.message) || 'Request failed');
				}
				return data;
			});
		});
	}

	function remember() {
		try {
			window.localStorage.setItem('wvmDashboard', JSON.stringify({ days: state.days, device: state.device }));
		} catch (e) {}
	}

	function restore() {
		try {
			var saved = JSON.parse(window.localStorage.getItem('wvmDashboard') || '{}');
			if ([1, 7, 28, 90].indexOf(Number(saved.days)) !== -1) {
				state.days = Number(saved.days);
			}
			if (['', 'mobile', 'tablet', 'desktop'].indexOf(saved.device) !== -1) {
				state.device = saved.device;
			}
		} catch (e) {}
	}

	function setPath(path) {
		state.path = path || '';
		load(false);
		window.scrollTo({ top: 0, behavior: 'smooth' });
	}

	function list(tag, className, items) {
		var node = el(tag, className);
		items.forEach(function (item) {
			node.appendChild(el('li', '', item));
		});
		return node;
	}

	function section(label, content) {
		return el('div', 'wvm-section', [el('p', 'wvm-label', label), content]);
	}

	function emptyState(title, text) {
		return el('div', 'wvm-empty', [el('strong', '', title), text ? el('p', '', text) : null]);
	}

	function errorText(text) {
		return el('p', 'wvm-muted', [shape('poor'), ' ', text]);
	}

	// ------------------------------------------------------------------ status components

	function ratingBadge(status, samples) {
		var key = confident(samples) ? stateOf(status) : 'unknown';
		var word = { good: 'good', ni: 'needs-improvement', poor: 'poor', unknown: 'unknown' }[key];
		return el('span', 'wvm-rating is-' + key, [shape(key === 'ni' ? 'needs-improvement' : key), t(word)]);
	}

	function severityTag(severity) {
		return el('span', 'wvm-sev is-' + severity, ts(severity));
	}

	function metricChip(metric) {
		var code = /^[A-Z]+$/.test(metric || '') ? metric : ({ navigation: 'NAV', device: 'DEVICE', template: 'PAGES', setup: 'SETUP' }[metric] || '');
		return code ? el('span', 'wvm-chip', code) : null;
	}

	function delta(row, previous) {
		if (!previous || !previous.p75 || row.p75 == null || !confident(previous.samples) || !confident(row.samples)) {
			return null;
		}
		var change = (row.p75 - previous.p75) / previous.p75 * 100;
		var label = sprintf(t('vsPrevious'), rangeName());
		if (Math.abs(change) < 1) {
			return el('span', 'wvm-delta', [el('b', '', '0%'), ' ' + label]);
		}
		return el('span', 'wvm-delta ' + (change > 0 ? 'is-worse' : 'is-better'), [el('b', '', (change > 0 ? '↑ ' : '↓ ') + Math.abs(change).toFixed(0) + '%'), ' ' + label]);
	}

	// ------------------------------------------------------------------ data components

	function distribution(row) {
		var parts = [['good', row.good, 'good'], ['ni', row.needs_improvement, 'needs-improvement'], ['poor', row.poor, 'poor']];
		var bar = el('div', 'wvm-dist');
		bar.setAttribute('role', 'img');
		bar.setAttribute('aria-label', parts.map(function (part) {
			return t(part[2]) + ' ' + part[1] + '%';
		}).join(', '));
		var legend = el('ul', 'wvm-dist-legend');
		parts.forEach(function (part) {
			if (Number(part[1]) > 0) {
				var segment = el('span', 'is-' + part[0]);
				segment.style.flex = String(part[1]);
				bar.appendChild(segment);
			}
			legend.appendChild(el('li', 'is-' + part[0], [shape(part[2]), t(part[2]) + ' ', el('b', '', part[1] + '%')]));
		});
		return el('div', '', [bar, legend]);
	}

	function meter(metric, value) {
		var limits = thresholds[metric];
		if (!limits || value == null) {
			return null;
		}
		var max = limits.poor * 1.5;
		var good = limits.good / max * 100;
		var ni = (limits.poor - limits.good) / max * 100;
		var pos = Math.min(Number(value) / max, 1) * 100;
		var node = el('div', 'wvm-meter');
		node.style.setProperty('--good', good.toFixed(1) + '%');
		node.style.setProperty('--ni', ni.toFixed(1) + '%');
		node.style.setProperty('--pos', pos.toFixed(1) + '%');
		node.setAttribute('role', 'img');
		node.setAttribute('aria-label', sprintf(t('meterLabel'), formatMetric(metric, value), t(rating(metric, value)), formatThreshold(metric, limits.good), formatThreshold(metric, limits.poor)));
		node.appendChild(el('div', 'wvm-meter-track', [el('span', 'is-good'), el('span', 'is-ni'), el('span', 'is-poor')]));
		node.appendChild(el('span', 'wvm-meter-needle'));
		var ticks = el('div', 'wvm-meter-ticks');
		[[good, limits.good], [good + ni, limits.poor]].forEach(function (tick) {
			var label = el('span', '', formatThreshold(metric, tick[1]));
			label.style.insetInlineStart = tick[0].toFixed(1) + '%';
			ticks.appendChild(label);
		});
		node.appendChild(ticks);
		return node;
	}

	function metricCard(row, previous) {
		var diagnostic = DIAGNOSTIC.indexOf(row.metric) !== -1;
		var card = el('section', 'wvm-metric ' + (diagnostic ? 'is-diagnostic wvm-span6' : 'wvm-span4'));
		var name = t('name' + row.metric) + (diagnostic ? ' · ' + t('diagnostic') : '');
		card.appendChild(el('div', 'wvm-metric-head', [
			el('div', '', [el('p', 'wvm-metric-code', row.metric), el('p', 'wvm-metric-name', name)]),
			ratingBadge(row.status, row.samples)
		]));

		var readout = el('p', 'wvm-readout');
		if (row.p75 != null) {
			var parts = valueParts(row.metric, row.p75);
			readout.appendChild(el('bdi', '', [parts[0], parts[1] ? el('small', '', parts[1]) : null]));
		} else {
			readout.appendChild(el('bdi', '', '–'));
		}
		readout.appendChild(el('span', 'wvm-label', 'P75'));
		var change = delta(row, previous);
		if (diagnostic && change) {
			readout.appendChild(change);
		}
		card.appendChild(readout);

		if (!diagnostic) {
			append(card, meter(row.metric, row.p75));
			append(card, change);
			if (row.samples > 0) {
				card.appendChild(distribution(row));
			}
		}

		var stats = el('dl', 'wvm-metric-stats');
		[[t('median'), formatMetric(row.metric, row.median)], [t('p90'), formatMetric(row.metric, row.p90)], [t('samples'), number(row.samples)]].forEach(function (item) {
			stats.appendChild(el('div', '', [el('dt', '', item[0]), el('dd', '', item[1])]));
		});
		card.appendChild(stats);

		if (row.samples > 0 && !confident(row.samples)) {
			card.appendChild(el('p', 'wvm-metric-note', t('insufficient')));
		}
		return card;
	}

	function ratingCell(metric, data) {
		data = data || {};
		var known = confident(data.samples);
		var status = known ? data.status : 'unknown';
		var cell = el('td', 'is-num');
		cell.appendChild(el('span', 'wvm-cell-rating is-' + stateOf(status) + (known ? '' : ' wvm-muted'), [shape(status), formatMetric(metric, data.p75)]));
		return cell;
	}

	// ------------------------------------------------------------------ sections

	function renderFilter() {
		var container = clear($('#wvm-filter'));
		container.hidden = !state.path;
		if (!state.path) {
			return;
		}
		append(container, [
			el('span', '', t('filteredBy')),
			el('code', 'wvm-chip', state.path),
			state.pageviews ? el('span', 'wvm-muted', '· ' + sprintf(t('pageViews'), number(state.pageviews))) : null,
			button('wvm-btn wvm-btn--sm wvm-btn--ghost', t('clearFilter'), function () {
				setPath('');
			})
		]);
	}

	function renderAssessment(overview) {
		var container = clear($('#wvm-assessment'));
		var core = overview.filter(function (row) {
			return CORE.indexOf(row.metric) !== -1;
		});
		var ready = core.every(function (row) {
			return confident(row.samples);
		});
		var status = 'pending';
		if (ready) {
			status = core.every(function (row) {
				return row.status === 'good';
			}) ? 'passed' : 'failed';
		}
		var shapeKey = { passed: 'good', failed: 'poor', pending: 'unknown' }[status];
		var title = state.path ? t('page' + status) : t(status);
		container.className = 'wvm-assess is-' + status;
		container.appendChild(el('p', 'wvm-assess-status', [shape(shapeKey), title]));

		var metrics = el('ul', 'wvm-assess-metrics');
		core.forEach(function (row) {
			if (row.samples > 0) {
				var key = confident(row.samples) ? row.status : 'unknown';
				metrics.appendChild(el('li', 'is-' + stateOf(key), [shape(key), row.metric + ' ', el('b', '', formatMetric(row.metric, row.p75))]));
			}
		});
		container.appendChild(metrics);
		container.appendChild(el('p', 'wvm-assess-note', t('assessmentNote') + ' ' + sprintf(t('assessScope'), deviceName(), rangeName(), number(state.pageviews))));
	}

	function renderOverview(overview, previous) {
		var container = clear($('#wvm-overview'));
		var before = {};
		(previous || []).forEach(function (row) {
			before[row.metric] = row;
		});
		var total = overview.reduce(function (sum, row) {
			return sum + Number(row.samples || 0);
		}, 0);
		if (!total) {
			var empty = emptyState(t('emptyTitle'), t('emptyText'));
			empty.classList.add('wvm-span12');
			container.appendChild(empty);
			return;
		}
		CORE.concat(DIAGNOSTIC).forEach(function (metric) {
			var row = overview.filter(function (item) {
				return item.metric === metric;
			})[0];
			if (row) {
				container.appendChild(metricCard(row, before[metric]));
			}
		});
	}

	function phasePanel(metric, data) {
		var panel = el('section', 'wvm-panel wvm-span6');
		panel.appendChild(el('div', 'wvm-panel-head', [
			el('h2', 'wvm-panel-title', t('where' + metric)),
			el('p', 'wvm-panel-sub', [t('phasesNote'), data.target ? ' ' : null, data.target ? el('code', 'wvm-chip', data.target) : null])
		]));
		var total = data.phases.reduce(function (sum, phase) {
			return sum + phase.value;
		}, 0);
		var wrap = el('div', 'wvm-phases');
		wrap.setAttribute('role', 'img');
		wrap.setAttribute('aria-label', metric + ' ' + formatMetric(metric, total) + ': ' + data.phases.map(function (phase) {
			return t('phase_' + phase.key) + ' ' + formatMetric(metric, phase.value);
		}).join(', '));
		var track = el('div', 'wvm-phases-track');
		var legend = el('ul', 'wvm-phases-legend');
		data.phases.forEach(function (phase, index) {
			var step = 'p' + (index + 1);
			var segment = el('span', step + (phase.flag ? ' is-flag' : ''));
			segment.style.flexGrow = String(Math.max(phase.value, 1));
			track.appendChild(segment);
			legend.appendChild(el('li', phase.flag ? 'is-flag' : '', [el('i', step), t('phase_' + phase.key), el('b', '', formatMetric(metric, phase.value) + ' · ' + Math.round(phase.share * 100) + '%')]));
		});
		append(wrap, [track, legend]);
		panel.appendChild(wrap);
		return panel;
	}

	function renderPhases(page) {
		var container = clear($('#wvm-phases'));
		var phases = (page && page.phases) || {};
		var metrics = ['LCP', 'INP'].filter(function (metric) {
			return phases[metric] && phases[metric].phases && phases[metric].phases.length;
		});
		container.hidden = !state.path || !metrics.length;
		metrics.forEach(function (metric) {
			container.appendChild(phasePanel(metric, phases[metric]));
		});
	}

	function renderTrend(rows) {
		var container = clear($('#wvm-trend'));
		if (!rows.length) {
			container.appendChild(el('p', 'wvm-muted', t('noTrend')));
			return;
		}
		var width = 800;
		var plot = 80;
		var x0 = 40;
		var x1 = 792;
		var x = function (index) {
			return rows.length <= 1 ? (x0 + x1) / 2 : x0 + (x1 - x0) * index / (rows.length - 1);
		};

		CORE.forEach(function (metric) {
			var limits = thresholds[metric];
			var points = [];
			rows.forEach(function (row, index) {
				if (row[metric] && row[metric].p75 != null && confident(row[metric].samples)) {
					points.push({ index: index, value: row[metric].p75, samples: row[metric].samples, date: row.date });
				}
			});
			var latest = points.length ? points[points.length - 1].value : null;
			var key = el('div', 'wvm-trend-key', [el('b', '', metric), el('span', '', formatMetric(metric, latest))]);
			var chart = svg('svg', { 'class': 'wvm-chart', viewBox: '0 0 ' + width + ' 88', role: 'img' });
			chart.setAttribute('aria-label', sprintf(t('trendLabel'), metric, points.length));

			var top = Math.max(limits.poor * 1.15, points.reduce(function (max, point) {
				return Math.max(max, point.value * 1.1);
			}, 0));
			var y = function (value) {
				return (plot - plot * value / top).toFixed(1);
			};
			var yGood = Number(y(limits.good));
			var yPoor = Number(y(limits.poor));
			chart.appendChild(svg('rect', { 'class': 'zone-good', x: x0, y: yGood, width: x1 - x0, height: (plot - yGood).toFixed(1) }));
			chart.appendChild(svg('rect', { 'class': 'zone-poor', x: x0, y: 0, width: x1 - x0, height: yPoor }));
			[yGood, yPoor].forEach(function (lineY, index) {
				chart.appendChild(svg('line', { 'class': 'grid', x1: x0, x2: x1, y1: lineY, y2: lineY }));
				var label = svg('text', { 'class': 'axis', x: x0 - 6, y: lineY + 4, 'text-anchor': 'end' });
				label.textContent = formatThreshold(metric, index ? limits.poor : limits.good);
				chart.appendChild(label);
			});

			var d = '';
			points.forEach(function (point, i) {
				var gap = i > 0 && point.index !== points[i - 1].index + 1;
				d += (d === '' || gap ? 'M' : ' L ') + x(point.index).toFixed(0) + ' ' + y(point.value);
			});
			if (d) {
				chart.appendChild(svg('path', { 'class': 'line', d: d }));
			}
			points.forEach(function (point, i) {
				var last = i === points.length - 1;
				var dot = svg('circle', { 'class': last ? 'dot-end' : 'dot', cx: x(point.index).toFixed(0), cy: y(point.value), r: last ? 4.5 : 3 });
				var tip = svg('title');
				tip.textContent = point.date + ': ' + formatMetric(metric, point.value) + ' (' + number(point.samples) + ' ' + t('samples') + ')';
				dot.appendChild(tip);
				chart.appendChild(dot);
			});
			container.appendChild(el('div', 'wvm-trend-row', [key, chart]));
		});

		var dates = el('div', 'wvm-row');
		dates.style.justifyContent = 'space-between';
		dates.style.paddingInlineStart = '125px';
		append(dates, [el('span', 'wvm-updated wvm-num', rows[0].date), el('span', 'wvm-updated wvm-num', rows[rows.length - 1].date)]);
		container.appendChild(dates);
	}

	function renderHealth(health) {
		var container = clear($('#wvm-health'));
		var collector = health.collector || 'waiting';
		var collectorShape = { active: 'good', stale: 'needs-improvement' }[collector] || 'unknown';
		[
			[t('collector'), [shape(collectorShape), t(collector)], collectorShape],
			[t('lastReceived'), health.last_received || 'n/a'],
			[t('samplesToday'), number(health.samples_today)],
			[t('samplesTotal'), number(health.samples_total)],
			[t('adminVisits'), config.adminsExcluded ? t('excluded') : t('measured')],
			[t('cron'), health.cron_scheduled ? t('yes') : t('no')],
			[t('lastAggregated'), health.last_aggregated || 'n/a']
		].forEach(function (item) {
			container.appendChild(el('dt', '', item[0]));
			container.appendChild(el('dd', item[2] ? 'is-' + stateOf(item[2]) : '', item[1]));
		});
	}

	function renderUrlTable(rows) {
		$('#wvm-url-panel').hidden = !!state.path;
		var tbody = clear($('#wvm-url-table tbody'));
		if (!rows.length) {
			var cell = el('td', 'wvm-muted', t('noData'));
			cell.colSpan = 6;
			tbody.appendChild(el('tr', '', cell));
			return;
		}
		rows.forEach(function (row) {
			var link = button('wvm-url', row.page_path, function () {
				setPath(row.page_path);
			});
			link.title = row.page_path;
			var tr = el('tr', '', el('td', '', link));
			CORE.forEach(function (metric) {
				tr.appendChild(ratingCell(metric, row[metric]));
			});
			tr.appendChild(el('td', 'is-num', number(row.samples)));
			tr.appendChild(el('td', 'is-num', number(row.poor_count)));
			tbody.appendChild(tr);
		});
	}

	function renderDimensionTable(selector, rows, translate) {
		var tbody = clear(document.querySelector(selector + ' tbody'));
		rows.forEach(function (row) {
			var tr = el('tr', '', el('td', '', translate ? t('device_' + row.label) : row.label));
			CORE.forEach(function (metric) {
				tr.appendChild(ratingCell(metric, row[metric]));
			});
			tbody.appendChild(tr);
		});
	}

	// ------------------------------------------------------------------ guidance

	function codeBlock(text, lang) {
		var copy = button('wvm-btn wvm-btn--sm wvm-btn--ghost', ts('copy'));
		copy.addEventListener('click', function () {
			if (!navigator.clipboard) {
				return;
			}
			navigator.clipboard.writeText(text).then(function () {
				copy.textContent = ts('copied');
				window.setTimeout(function () {
					copy.textContent = ts('copy');
				}, 1500);
			}).catch(function () {});
		});
		var pre = el('pre');
		String(text).split('\n').forEach(function (line, index) {
			if (index) {
				pre.appendChild(document.createTextNode('\n'));
			}
			pre.appendChild(/^\s*(\/\/|#|\/\*|\*)/.test(line) ? el('span', 'c', line) : document.createTextNode(line));
		});
		return el('div', 'wvm-code', [el('div', 'wvm-code-head', [el('span', '', lang || ts('code')), copy]), pre]);
	}

	function docsLink(href) {
		var link = el('a', 'wvm-link', ts('learnMore'));
		link.href = href;
		link.target = '_blank';
		link.rel = 'noopener noreferrer';
		return link;
	}

	function renderSuggestions(data) {
		var container = clear($('#wvm-suggestions'));
		var items = (data && data.suggestions) || [];
		if (!items.length) {
			container.appendChild(emptyState(ts('noSuggestions')));
			return;
		}
		var stack = el('div', 'wvm-panel-stack');
		items.forEach(function (item, index) {
			var details = el('details', 'wvm-suggestion');
			details.open = index < 3 && item.severity !== 'info';
			details.appendChild(el('summary', '', [severityTag(item.severity), metricChip(item.metric), el('span', 'wvm-suggestion-title', item.title), el('span', 'wvm-chevron')]));

			var body = el('div', 'wvm-suggestion-body', el('p', '', item.summary));
			if (item.evidence && item.evidence.length) {
				body.appendChild(section(ts('evidence'), list('ul', 'wvm-evidence', item.evidence)));
			}
			if (item.actions && item.actions.length) {
				body.appendChild(section(ts('actions'), list('ol', 'wvm-steps', item.actions)));
			}
			if (item.urls && item.urls.length) {
				var chips = el('div', 'wvm-url-chips');
				item.urls.forEach(function (path) {
					var chip = button('wvm-url-chip', path, function () {
						setPath(path);
					});
					chip.title = ts('viewUrl');
					chips.appendChild(chip);
				});
				body.appendChild(section(ts('affectedUrls'), chips));
			}
			if (item.docs) {
				body.appendChild(el('div', '', docsLink(item.docs)));
			}
			details.appendChild(body);
			stack.appendChild(details);
		});
		container.appendChild(stack);
	}

	function progressBar() {
		var fill = el('span', 'wvm-progress-fill');
		var label = el('span');
		var node = el('span', 'wvm-progress', [el('span', 'wvm-progress-track', fill), label]);
		return {
			node: node,
			update: function (issues) {
				var done = issues.filter(function (issue) {
					return issue.done;
				}).length;
				label.textContent = sprintf(ts('progress'), done, issues.length);
				fill.style.width = (issues.length ? Math.round(done / issues.length * 100) : 100) + '%';
			}
		};
	}

	function issueStatus(issue) {
		if (!issue.done) {
			return null;
		}
		var shapeKey = { verified: 'good', still: 'poor' }[issue.status] || 'unknown';
		var text = ts(issue.status);
		if (issue.doneAt) {
			text += ' · ' + sprintf(ts('doneBy'), issue.doneAt, issue.doneBy || '–');
		}
		return el('p', 'wvm-issue-status is-' + issue.status, [shape(shapeKey), text]);
	}

	function renderIssue(issue, page, open, onChange) {
		var item = el('li', 'wvm-issue' + (issue.done ? ' is-done' : ''));
		var box = el('input', 'wvm-check');
		box.type = 'checkbox';
		box.checked = !!issue.done;
		box.setAttribute('aria-label', issue.title);
		item.appendChild(box);
		item.appendChild(el('div', 'wvm-issue-meta', [severityTag(issue.severity), metricChip(issue.metric)]));
		item.appendChild(el('p', 'wvm-issue-title', issue.title));
		var status = issueStatus(issue);
		append(item, status);

		box.addEventListener('change', function () {
			var done = box.checked;
			box.disabled = true;
			postJson(config.checklistUrl, { key: issue.key, done: done }).then(function () {
				issue.done = done;
				issue.status = done ? 'awaiting' : 'open';
				issue.doneAt = done ? new Date().toISOString().slice(0, 16).replace('T', ' ') : '';
				issue.doneBy = config.currentUser || '';
				item.classList.toggle('is-done', done);
				if (status) {
					status.remove();
				}
				status = issueStatus(issue);
				if (status) {
					item.insertBefore(status, details);
				}
				onChange();
			}).catch(function () {
				box.checked = !done;
				window.alert(ts('saveFailed'));
			}).then(function () {
				box.disabled = false;
			});
		});

		var details = el('details', 'wvm-disclosure');
		details.open = !!open;
		details.appendChild(el('summary', '', [ts('details'), el('span', 'wvm-chevron')]));
		var body = el('div', 'wvm-disclosure-body');
		if (issue.evidence && issue.evidence.length) {
			body.appendChild(section(ts('evidence'), list('ul', 'wvm-evidence', issue.evidence)));
		}
		if (issue.steps && issue.steps.length) {
			body.appendChild(section(ts('actions'), list('ol', 'wvm-steps', issue.steps)));
		}
		if (issue.snippet) {
			body.appendChild(codeBlock(issue.snippet, issue.lang));
		}
		var actions = el('div', 'wvm-row');
		if (config.ai && config.ai.enabled) {
			var ai = aiBlock(issue, page);
			actions.appendChild(ai.button);
			if (issue.docs) {
				var docs = docsLink(issue.docs);
				docs.style.marginInlineStart = 'auto';
				actions.appendChild(docs);
			}
			body.appendChild(actions);
			body.appendChild(ai.output);
		} else if (issue.docs) {
			actions.appendChild(docsLink(issue.docs));
			body.appendChild(actions);
		}
		details.appendChild(body);
		item.appendChild(details);
		return item;
	}

	// "Ask AI" for one issue. The server rebuilds the issue from its key, so only ids are sent.
	function aiBlock(issue, page) {
		var output = el('div');
		var ask = button('wvm-btn', [el('span', 'wvm-tag-ai', 'AI'), ts('askAi')], function () {
			request(false);
		});

		function request(fresh) {
			ask.disabled = true;
			clear(output).appendChild(el('div', 'wvm-ai', el('div', 'wvm-ai-wait', [
				el('p', 'wvm-muted', ts('aiThinking')),
				el('span', 'wvm-skeleton'), el('span', 'wvm-skeleton'), el('span', 'wvm-skeleton')
			])));
			output.querySelectorAll('.wvm-skeleton').forEach(function (line, index) {
				line.style.width = ['70%', '90%', '45%'][index];
			});
			postJson(config.ai.url, { key: issue.key, path: page.path, days: state.days, device: state.device, fresh: fresh }).then(function (answer) {
				ask.hidden = true;
				render(answer);
			}).catch(function (error) {
				clear(output).appendChild(errorText(error.message || ts('aiFailed')));
			}).then(function () {
				ask.disabled = false;
			});
		}

		function render(answer) {
			var level = { high: 3, medium: 2, low: 1 }[answer.confidence] || 1;
			var bars = el('span', 'wvm-conf');
			for (var i = 1; i <= 3; i++) {
				bars.appendChild(el('i', i <= level ? 'on' : ''));
			}
			bars.appendChild(document.createTextNode(sprintf(ts('aiConfidence'), ts(answer.confidence))));
			var model = el('span', 'wvm-ai-model', (answer.model || '') + (answer.cached ? ' · ' + answer.generatedAt : ''));
			var again = button('wvm-btn wvm-btn--sm', ts('aiRegenerate'), function () {
				request(true);
			});
			var body = el('div', 'wvm-ai-body', el('p', 'wvm-ai-summary', answer.summary));
			if (answer.cause) {
				body.appendChild(section(ts('aiCause'), el('p', '', answer.cause)));
			}
			if (answer.steps && answer.steps.length) {
				body.appendChild(section(ts('actions'), list('ol', 'wvm-steps', answer.steps)));
			}
			if (answer.code && answer.code_lang !== 'none') {
				body.appendChild(codeBlock(answer.code, answer.code_lang));
			}
			if (answer.verify) {
				body.appendChild(section(ts('aiVerify'), el('p', '', answer.verify)));
			}
			if (answer.caveats) {
				body.appendChild(section(ts('aiCaveats'), el('p', 'wvm-muted', answer.caveats)));
			}
			clear(output).appendChild(el('div', 'wvm-ai', [
				el('div', 'wvm-ai-head', [el('span', 'wvm-tag-ai', 'AI'), el('h4', '', ts('aiTitle')), bars, model, again]),
				body,
				el('p', 'wvm-ai-foot', ts('aiWarning'))
			]));
		}

		return { button: ask, output: output };
	}

	function issueList(page, progress, openFirst) {
		var listNode = el('ul', 'wvm-issues');
		var hidden = [];
		var update = function () {
			progress.update(page.issues);
		};
		page.issues.forEach(function (issue, index) {
			var node = renderIssue(issue, page, openFirst && index === 0 && !issue.done, update);
			if (!state.path && index >= VISIBLE_ISSUES) {
				node.hidden = true;
				hidden.push(node);
			}
			listNode.appendChild(node);
		});
		update();
		var more = null;
		if (hidden.length) {
			more = el('div', '', button('wvm-btn wvm-btn--sm wvm-btn--ghost', sprintf(ts('showMore'), hidden.length), function (event) {
				hidden.forEach(function (node) {
					node.hidden = false;
				});
				event.currentTarget.parentNode.remove();
			}));
		}
		return [listNode, more];
	}

	function renderPages(data) {
		var container = clear($('#wvm-pages'));
		var pages = (data && data.pages) || [];
		var headProgress = clear($('#wvm-pages-progress'));
		$('#wvm-pages-title').textContent = state.path ? ts('pageChecklistTitle') : ts('pagesTitle');
		$('#wvm-pages-sub').hidden = !!state.path;
		headProgress.hidden = !state.path;
		renderPhases(state.path ? pages[0] : null);

		if (!pages.length) {
			container.appendChild(emptyState(ts('noPages')));
			return;
		}

		if (state.path) {
			if (!pages[0].issues.length) {
				container.appendChild(emptyState(ts('noIssues')));
				return;
			}
			var progress = progressBar();
			headProgress.appendChild(progress.node);
			append(container, issueList(pages[0], progress, true));
			return;
		}

		var stack = el('div', 'wvm-panel-stack');
		pages.forEach(function (page, index) {
			var details = el('details', 'wvm-pagecheck');
			details.open = index === 0;
			var metrics = el('ul', 'wvm-pagecheck-metrics');
			CORE.forEach(function (metric) {
				var value = page.metrics && page.metrics[metric];
				if (value && value.p75 != null) {
					var key = confident(value.samples) ? value.status : 'unknown';
					metrics.appendChild(el('li', 'is-' + stateOf(key) + (confident(value.samples) ? '' : ' wvm-muted'), [shape(key), metric + ' ' + formatMetric(metric, value.p75)]));
				}
			});
			var progress = progressBar();
			details.appendChild(el('summary', '', [el('p', 'wvm-pagecheck-path', page.path), metrics, progress.node, el('span', 'wvm-chevron')]));

			var body = el('div', 'wvm-pagecheck-body', el('div', '', button('wvm-btn wvm-btn--sm', ts('onlyThisPage'), function () {
				setPath(page.path);
			})));
			if (!page.issues.length) {
				body.appendChild(emptyState(ts('noIssues')));
				progress.node.hidden = true;
			} else {
				append(body, issueList(page, progress, index === 0));
			}
			details.appendChild(body);
			stack.appendChild(details);
		});
		container.appendChild(stack);
	}

	// ------------------------------------------------------------------ loading

	function syncControls() {
		document.querySelectorAll('#wvm-range button').forEach(function (node) {
			node.setAttribute('aria-pressed', String(Number(node.getAttribute('data-days')) === state.days));
		});
		document.querySelectorAll('#wvm-device button').forEach(function (node) {
			node.setAttribute('aria-pressed', String(node.getAttribute('data-device') === state.device));
		});
	}

	function load(fresh, live) {
		var request = ++state.request;
		syncControls();
		remember();
		renderFilter();

		fetchJson(config.dashboardUrl, fresh).then(function (data) {
			if (request !== state.request) {
				return;
			}
			var overview = data.overview || [];
			var lcp = overview.filter(function (row) {
				return row.metric === 'LCP';
			})[0];
			state.pageviews = lcp ? lcp.samples : 0;
			renderFilter();
			renderAssessment(overview);
			renderOverview(overview, data.previous);
			renderTrend(data.trend || []);
			renderHealth(data.health || {});
			renderUrlTable(data.urls || []);
			renderDimensionTable('#wvm-device-table', data.devices || [], true);
			renderDimensionTable('#wvm-browser-table', data.browsers || [], false);
			renderDimensionTable('#wvm-pagetype-table', data.pageTypes || [], false);
			$('#wvm-updated').textContent = sprintf(t('updated'), new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }));
		}).catch(function () {
			clear($('#wvm-overview')).appendChild(errorText(t('loadFailed')));
		});

		// The checklists change slowly; auto-refresh would only collapse what the user opened.
		if (live) {
			return;
		}

		$('#wvm-suggestions-panel').hidden = !!state.path;
		fetchJson(config.pagesUrl, fresh).then(function (data) {
			if (request === state.request) {
				renderPages(data);
			}
		}).catch(function () {
			clear($('#wvm-pages')).appendChild(errorText(ts('pagesFailed')));
		});

		if (!state.path) {
			fetchJson(config.suggestionsUrl, fresh).then(function (data) {
				if (request === state.request) {
					renderSuggestions(data);
				}
			}).catch(function () {
				clear($('#wvm-suggestions')).appendChild(errorText(ts('suggestionsFailed')));
			});
		}
	}

	function toggleLive(enabled) {
		window.clearInterval(state.timer);
		state.timer = 0;
		if (enabled) {
			state.timer = window.setInterval(function () {
				if (document.visibilityState === 'visible') {
					load(false, true);
				}
			}, LIVE_INTERVAL);
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		if (!$('#wvm-range')) {
			return;
		}
		restore();
		document.querySelectorAll('#wvm-range button').forEach(function (node) {
			node.addEventListener('click', function () {
				state.days = Number(node.getAttribute('data-days'));
				load(false);
			});
		});
		document.querySelectorAll('#wvm-device button').forEach(function (node) {
			node.addEventListener('click', function () {
				state.device = node.getAttribute('data-device');
				load(false);
			});
		});
		$('#wvm-refresh').addEventListener('click', function () {
			load(true);
		});
		$('#wvm-live').addEventListener('change', function (event) {
			toggleLive(event.target.checked);
		});
		load(false);
	});
})();
