(function () {
	'use strict';

	var config = window.wvmAdmin || {};
	var i18n = config.i18n || {};
	var thresholds = config.thresholds || {};
	var CORE = ['LCP', 'INP', 'CLS'];
	var NAMES = {
		LCP: 'Largest Contentful Paint',
		INP: 'Interaction to Next Paint',
		CLS: 'Cumulative Layout Shift',
		FCP: 'First Contentful Paint',
		TTFB: 'Time to First Byte'
	};
	var SVG = 'http://www.w3.org/2000/svg';
	var LIVE_INTERVAL = 60000;
	var state = {
		days: 7,
		device: '',
		path: '',
		timer: 0,
		request: 0
	};

	function $(selector) {
		return document.querySelector(selector);
	}

	function t(key) {
		return Object.prototype.hasOwnProperty.call(i18n, key) ? i18n[key] : key;
	}

	// Strings of the suggestions panel, which can use a different language (Persian) than the rest.
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
		if (content != null) {
			node.appendChild(typeof content === 'object' ? content : document.createTextNode(String(content)));
		}
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

	function formatMetric(metric, value) {
		if (value == null || isNaN(Number(value))) {
			return 'n/a';
		}
		if (metric === 'CLS') {
			return Number(value).toFixed(3);
		}
		if (Number(value) >= 1000) {
			return (Number(value) / 1000).toFixed(2) + ' s';
		}
		return Math.round(Number(value)) + ' ms';
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

	function confident(samples) {
		return Number(samples) >= Number(config.minimumSamples || 1);
	}

	function badge(status) {
		status = status || 'unknown';
		return el('span', 'wvm-badge wvm-badge-' + status, t(status));
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

	function remember() {
		try {
			window.localStorage.setItem('wvmDashboard', JSON.stringify({ days: state.days, device: state.device }));
		} catch (e) {}
	}

	function restore() {
		try {
			var saved = JSON.parse(window.localStorage.getItem('wvmDashboard') || '{}');
			if (saved.days) {
				state.days = Number(saved.days);
			}
			if (typeof saved.device === 'string') {
				state.device = saved.device;
			}
		} catch (e) {}
	}

	function setPath(path) {
		state.path = path || '';
		load(false);
		window.scrollTo({ top: 0, behavior: 'smooth' });
	}

	function cell(content, className) {
		var td = el('td', className);
		if (content != null) {
			td.appendChild(typeof content === 'object' ? content : document.createTextNode(String(content)));
		}
		return td;
	}

	function valueCell(metric, data) {
		data = data || {};
		var status = confident(data.samples) ? data.status : 'unknown';
		return cell(el('span', 'wvm-value wvm-value-' + (status || 'unknown'), formatMetric(metric, data.p75)));
	}

	function renderFilter() {
		var container = $('#wvm-filter');
		clear(container);
		container.hidden = !state.path;
		if (!state.path) {
			return;
		}
		container.appendChild(el('span', '', t('filteredBy') + ' '));
		container.appendChild(el('code', '', state.path));
		var button = el('button', 'button button-small', t('clearFilter'));
		button.type = 'button';
		button.addEventListener('click', function () {
			setPath('');
		});
		container.appendChild(button);
	}

	function renderAssessment(rows) {
		var container = clear($('#wvm-assessment'));
		var core = rows.filter(function (row) {
			return CORE.indexOf(row.metric) !== -1;
		});
		var ready = core.filter(function (row) {
			return confident(row.samples);
		});
		var status = 'pending';
		if (ready.length === CORE.length) {
			status = ready.every(function (row) {
				return row.status === 'good';
			}) ? 'passed' : 'failed';
		}
		container.className = 'wvm-assessment wvm-assessment-' + status;
		container.appendChild(el('strong', '', t(status)));
		container.appendChild(el('span', 'wvm-muted', t('assessmentNote')));
	}

	function distribution(row) {
		var bar = el('div', 'wvm-dist');
		[['good', row.good], ['needs-improvement', row.needs_improvement], ['poor', row.poor]].forEach(function (part) {
			if (Number(part[1]) <= 0) {
				return;
			}
			var segment = el('span', 'wvm-dist-' + part[0]);
			segment.style.width = part[1] + '%';
			segment.title = t(part[0]) + ': ' + part[1] + '%';
			bar.appendChild(segment);
		});
		return bar;
	}

	function renderOverview(rows, previous) {
		var container = clear($('#wvm-overview'));
		var before = {};
		(previous || []).forEach(function (row) {
			before[row.metric] = row;
		});

		rows.forEach(function (row) {
			var ready = confident(row.samples);
			var status = ready ? row.status : 'unknown';
			var card = el('section', 'wvm-card' + (CORE.indexOf(row.metric) === -1 ? ' wvm-card-diagnostic' : ''));

			var head = el('div', 'wvm-card-head');
			var title = el('h3', '', row.metric);
			title.title = NAMES[row.metric] || row.metric;
			head.appendChild(title);
			head.appendChild(badge(status));
			card.appendChild(head);
			card.appendChild(el('div', 'wvm-card-name', NAMES[row.metric] || ''));

			card.appendChild(el('div', 'wvm-card-value wvm-value-' + status, formatMetric(row.metric, row.p75)));

			var prev = before[row.metric];
			if (prev && prev.p75 && row.p75 != null && confident(prev.samples)) {
				var change = (row.p75 - prev.p75) / prev.p75 * 100;
				if (Math.abs(change) >= 1) {
					card.appendChild(el('div', 'wvm-delta ' + (change > 0 ? 'wvm-delta-worse' : 'wvm-delta-better'),
						(change > 0 ? '▲ ' : '▼ ') + Math.abs(change).toFixed(0) + '% ' + t('vsPrevious')));
				}
			}

			if (row.samples > 0) {
				card.appendChild(distribution(row));
			}

			var meta = el('dl', 'wvm-metrics-list');
			[
				[t('average'), formatMetric(row.metric, row.average)],
				[t('median'), formatMetric(row.metric, row.median)],
				[t('p90'), formatMetric(row.metric, row.p90)],
				[t('good'), row.good + '%'],
				[t('poor'), row.poor + '%'],
				[t('samples'), Number(row.samples).toLocaleString()]
			].forEach(function (item) {
				meta.appendChild(el('dt', '', item[0]));
				meta.appendChild(el('dd', '', item[1]));
			});
			card.appendChild(meta);

			if (row.samples > 0 && !ready) {
				card.appendChild(el('p', 'wvm-note', t('insufficient')));
			}

			container.appendChild(card);
		});
	}

	function renderScore(rows) {
		var core = rows.filter(function (row) {
			return CORE.indexOf(row.metric) !== -1 && confident(row.samples);
		});
		var node = $('#wvm-score');
		if (!core.length) {
			node.textContent = '–';
			node.className = 'wvm-score';
			return;
		}
		var score = Math.round(core.reduce(function (carry, row) {
			return carry + Number(row.good) + Number(row.needs_improvement) * 0.5;
		}, 0) / core.length);
		node.textContent = score + '/100';
		node.className = 'wvm-score ' + (score >= 90 ? 'wvm-value-good' : (score >= 50 ? 'wvm-value-needs-improvement' : 'wvm-value-poor'));
	}

	function renderAlerts(rows) {
		var container = clear($('#wvm-alerts'));
		var bad = rows.filter(function (row) {
			return CORE.indexOf(row.metric) !== -1 && confident(row.samples) && row.status !== 'good';
		});
		if (!bad.length) {
			container.appendChild(el('p', '', t('noAlerts')));
			return;
		}
		bad.forEach(function (row) {
			var alert = el('div', 'wvm-alert', sprintf(t('alert'), row.metric, formatMetric(row.metric, row.p75)));
			alert.appendChild(badge(row.status));
			container.appendChild(alert);
		});
	}

	function renderTrend(rows) {
		var container = clear($('#wvm-trend'));
		$('#wvm-trend-note').textContent = t('trendNote');
		if (!rows.length) {
			container.appendChild(el('p', 'wvm-muted', t('noTrend')));
			return;
		}

		var width = 600;
		var height = 130;
		var pad = { top: 10, right: 10, bottom: 22, left: 52 };

		CORE.forEach(function (metric) {
			var points = rows.filter(function (row) {
				return row[metric] && row[metric].p75 != null;
			});
			var block = el('div', 'wvm-trend-block');
			block.appendChild(el('h3', '', metric));
			container.appendChild(block);
			if (!points.length) {
				block.appendChild(el('p', 'wvm-muted', t('noTrend')));
				return;
			}

			var limits = thresholds[metric];
			var max = Math.max(limits.poor * 1.15, Math.max.apply(null, points.map(function (row) {
				return row[metric].p75;
			})) * 1.1);
			var plotW = width - pad.left - pad.right;
			var plotH = height - pad.top - pad.bottom;
			var x = function (index) {
				return pad.left + (rows.length <= 1 ? plotW / 2 : plotW * index / (rows.length - 1));
			};
			var y = function (value) {
				return pad.top + plotH - (plotH * value / max);
			};

			var chart = svg('svg', { viewBox: '0 0 ' + width + ' ' + height, role: 'img', 'aria-label': metric + ' P75', preserveAspectRatio: 'none' });

			[['good', limits.good], ['poor', limits.poor]].forEach(function (line) {
				chart.appendChild(svg('line', { x1: pad.left, x2: width - pad.right, y1: y(line[1]), y2: y(line[1]), 'class': 'wvm-threshold wvm-threshold-' + line[0] }));
				var label = svg('text', { x: pad.left - 6, y: y(line[1]) + 4, 'text-anchor': 'end', 'class': 'wvm-axis' });
				label.textContent = formatMetric(metric, line[1]);
				chart.appendChild(label);
			});

			var d = '';
			rows.forEach(function (row, index) {
				if (!row[metric] || row[metric].p75 == null) {
					return;
				}
				d += (d ? ' L ' : 'M ') + x(index).toFixed(1) + ' ' + y(row[metric].p75).toFixed(1);
			});
			chart.appendChild(svg('path', { d: d, 'class': 'wvm-line' }));

			rows.forEach(function (row, index) {
				if (!row[metric] || row[metric].p75 == null) {
					return;
				}
				var value = row[metric].p75;
				var dot = svg('circle', { cx: x(index), cy: y(value), r: 3.5, 'class': 'wvm-dot wvm-dot-' + rating(metric, value) });
				var tip = svg('title');
				tip.textContent = row.date + ' — ' + formatMetric(metric, value) + ' (' + row[metric].samples + ' ' + t('samples') + ')';
				dot.appendChild(tip);
				chart.appendChild(dot);
			});

			[0, rows.length - 1].filter(function (value, index, list) {
				return list.indexOf(value) === index;
			}).forEach(function (index) {
				var label = svg('text', { x: x(index), y: height - 6, 'text-anchor': index === 0 && rows.length > 1 ? 'start' : 'end', 'class': 'wvm-axis' });
				label.textContent = rows[index].date;
				chart.appendChild(label);
			});

			block.appendChild(chart);
		});
	}

	function renderUrlTable(rows) {
		var layout = $('#wvm-url-layout');
		var tbody = clear($('#wvm-url-table tbody'));
		layout.classList.toggle('wvm-url-filtered', !!state.path);
		rows.forEach(function (row) {
			var tr = el('tr');
			var link = el('button', 'button-link wvm-url', row.page_path);
			link.type = 'button';
			link.title = t('viewUrl');
			link.addEventListener('click', function () {
				setPath(row.page_path);
			});
			tr.appendChild(cell(link));
			CORE.forEach(function (metric) {
				tr.appendChild(valueCell(metric, row[metric]));
			});
			tr.appendChild(cell(Number(row.samples).toLocaleString()));
			tr.appendChild(cell(Number(row.poor_count).toLocaleString()));
			tbody.appendChild(tr);
		});
	}

	function renderDimensionTable(selector, rows) {
		var tbody = clear(document.querySelector(selector + ' tbody'));
		rows.forEach(function (row) {
			var tr = el('tr');
			tr.appendChild(cell(row.label));
			CORE.forEach(function (metric) {
				tr.appendChild(valueCell(metric, row[metric]));
			});
			tr.appendChild(cell(Number(row.samples).toLocaleString()));
			tbody.appendChild(tr);
		});
	}

	function renderHealth(health) {
		var container = clear($('#wvm-health'));
		[
			[t('collector'), t(health.collector || 'waiting')],
			[t('lastReceived'), health.last_received || 'n/a'],
			[t('samplesToday'), Number(health.samples_today || 0).toLocaleString()],
			[t('samplesTotal'), Number(health.samples_total || 0).toLocaleString()],
			[t('restApi'), health.rest_api],
			[t('database'), health.database],
			[t('cron'), health.cron_scheduled ? t('yes') : t('no')],
			[t('lastAggregated'), health.last_aggregated || 'n/a']
		].forEach(function (item) {
			var row = el('div', 'wvm-health-row', item[0]);
			row.appendChild(el('strong', '', item[1]));
			container.appendChild(row);
		});
	}

	function list(tag, items, className) {
		var node = el(tag, className);
		items.forEach(function (item) {
			node.appendChild(el('li', '', item));
		});
		return node;
	}

	function renderSuggestions(data) {
		var container = clear($('#wvm-suggestions'));
		var items = (data && data.suggestions) || [];
		if (!items.length) {
			container.appendChild(el('p', 'wvm-empty', ts('noSuggestions')));
			return;
		}

		items.forEach(function (item, index) {
			var details = el('details', 'wvm-suggestion wvm-suggestion-' + item.severity);
			details.open = index < 3 && item.severity !== 'info';

			var summary = el('summary');
			summary.appendChild(el('span', 'wvm-sev wvm-sev-' + item.severity, ts(item.severity)));
			if (item.metric && /^[A-Z]+$/.test(item.metric)) {
				summary.appendChild(el('span', 'wvm-chip', item.metric));
			}
			summary.appendChild(el('span', 'wvm-suggestion-title', item.title));
			details.appendChild(summary);

			var body = el('div', 'wvm-suggestion-body');
			body.appendChild(el('p', '', item.summary));

			if (item.evidence && item.evidence.length) {
				body.appendChild(el('h4', '', ts('evidence')));
				body.appendChild(list('ul', item.evidence, 'wvm-evidence'));
			}
			if (item.actions && item.actions.length) {
				body.appendChild(el('h4', '', ts('actions')));
				body.appendChild(list('ol', item.actions, 'wvm-actions'));
			}
			if (item.urls && item.urls.length && !state.path) {
				body.appendChild(el('h4', '', ts('affectedUrls')));
				var urls = el('div', 'wvm-url-chips');
				item.urls.forEach(function (path) {
					var chip = el('button', 'button button-small', path);
					chip.type = 'button';
					chip.title = ts('viewUrl');
					chip.addEventListener('click', function () {
						setPath(path);
					});
					urls.appendChild(chip);
				});
				body.appendChild(urls);
			}
			if (item.docs) {
				var link = el('a', 'wvm-docs', ts('learnMore') + ' ↗');
				link.href = item.docs;
				link.target = '_blank';
				link.rel = 'noopener noreferrer';
				body.appendChild(link);
			}

			details.appendChild(body);
			container.appendChild(details);
		});
	}

	function postChecklist(key, done) {
		return fetch(config.checklistUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' },
			body: JSON.stringify({ key: key, done: done })
		}).then(function (response) {
			if (!response.ok) {
				throw new Error('Request failed');
			}
		});
	}

	function metricChips(metrics) {
		var wrap = el('span', 'wvm-page-metrics');
		CORE.forEach(function (metric) {
			var data = (metrics || {})[metric];
			if (data && data.p75 != null) {
				wrap.appendChild(el('span', 'wvm-value wvm-value-' + data.status, metric + ' ' + formatMetric(metric, data.p75)));
			}
		});
		return wrap;
	}

	function snippetBlock(issue) {
		var wrap = el('div', 'wvm-snippet');
		var head = el('div', 'wvm-snippet-head', ts('code') + (issue.lang ? ' · ' + issue.lang : ''));
		var button = el('button', 'button button-small', ts('copy'));
		button.type = 'button';
		button.addEventListener('click', function () {
			if (navigator.clipboard) {
				navigator.clipboard.writeText(issue.snippet).then(function () {
					button.textContent = ts('copied');
					window.setTimeout(function () {
						button.textContent = ts('copy');
					}, 1500);
				});
			}
		});
		head.appendChild(button);
		wrap.appendChild(head);
		var pre = el('pre', '', issue.snippet);
		pre.dir = 'ltr';
		wrap.appendChild(pre);
		return wrap;
	}

	function issueStatus(issue) {
		if (!issue.done) {
			return null;
		}
		var text = ts(issue.status);
		if (issue.doneAt) {
			text += ' · ' + sprintf(ts('doneBy'), issue.doneAt, issue.doneBy || '–');
		}
		return el('div', 'wvm-check-status wvm-check-status-' + issue.status, text);
	}

	function renderIssue(issue, onChange) {
		var item = el('li', 'wvm-check' + (issue.done ? ' is-done' : ''));
		var head = el('div', 'wvm-check-head');
		var box = document.createElement('input');
		box.type = 'checkbox';
		box.id = 'wvm-check-' + issue.key;
		box.checked = !!issue.done;
		head.appendChild(box);

		var label = el('label', 'wvm-check-label');
		label.htmlFor = box.id;
		label.appendChild(el('span', 'wvm-sev wvm-sev-' + issue.severity, ts(issue.severity)));
		label.appendChild(el('span', 'wvm-chip', issue.metric));
		label.appendChild(el('span', 'wvm-check-title', issue.title));
		head.appendChild(label);
		item.appendChild(head);

		var status = issueStatus(issue);
		if (status) {
			item.appendChild(status);
		}

		box.addEventListener('change', function () {
			var done = box.checked;
			box.disabled = true;
			postChecklist(issue.key, done).then(function () {
				issue.done = done;
				issue.status = done ? 'awaiting' : 'open';
				issue.doneAt = done ? new Date().toISOString().slice(0, 16).replace('T', ' ') : '';
				issue.doneBy = config.currentUser || '';
				item.classList.toggle('is-done', done);
				var old = item.querySelector('.wvm-check-status');
				if (old) {
					old.remove();
				}
				var fresh = issueStatus(issue);
				if (fresh) {
					head.insertAdjacentElement('afterend', fresh);
				}
				onChange();
			}).catch(function () {
				box.checked = !done;
				window.alert(ts('saveFailed'));
			}).then(function () {
				box.disabled = false;
			});
		});

		var details = el('details', 'wvm-check-details');
		details.appendChild(el('summary', '', ts('details')));
		if (issue.evidence && issue.evidence.length) {
			details.appendChild(el('h4', '', ts('evidence')));
			details.appendChild(list('ul', issue.evidence, 'wvm-evidence'));
		}
		if (issue.steps && issue.steps.length) {
			details.appendChild(el('h4', '', ts('actions')));
			details.appendChild(list('ol', issue.steps, 'wvm-actions'));
		}
		if (issue.snippet) {
			details.appendChild(snippetBlock(issue));
		}
		if (issue.docs) {
			var link = el('a', 'wvm-docs', ts('learnMore') + ' ↗');
			link.href = issue.docs;
			link.target = '_blank';
			link.rel = 'noopener noreferrer';
			details.appendChild(link);
		}
		item.appendChild(details);
		return item;
	}

	function renderPages(data) {
		var container = clear($('#wvm-pages'));
		var pages = (data && data.pages) || [];
		if (!pages.length) {
			container.appendChild(el('p', 'wvm-muted', ts('noPages')));
			return;
		}

		pages.forEach(function (page, index) {
			var details = el('details', 'wvm-page');
			details.open = !!state.path || index === 0;

			var summary = el('summary', 'wvm-page-summary');
			var path = el('code', 'wvm-page-path', page.path);
			path.dir = 'ltr';
			summary.appendChild(path);
			summary.appendChild(metricChips(page.metrics));
			var progress = el('span', 'wvm-progress');
			var bar = el('span', 'wvm-progress-bar');
			var fill = el('span', 'wvm-progress-fill');
			bar.appendChild(fill);
			var label = el('span', 'wvm-progress-label');
			progress.appendChild(bar);
			progress.appendChild(label);
			summary.appendChild(progress);
			details.appendChild(summary);

			function updateProgress() {
				var total = page.issues.length;
				var done = page.issues.filter(function (issue) {
					return issue.done;
				}).length;
				label.textContent = sprintf(ts('progress'), done, total);
				fill.style.width = (total ? Math.round(done / total * 100) : 100) + '%';
			}
			updateProgress();

			var body = el('div', 'wvm-page-body');
			if (!state.path) {
				var only = el('button', 'button button-small', ts('onlyThisPage'));
				only.type = 'button';
				only.addEventListener('click', function () {
					setPath(page.path);
				});
				body.appendChild(only);
			}
			if (!page.issues.length) {
				body.appendChild(el('p', 'wvm-empty', ts('noIssues')));
			}
			var checklist = el('ul', 'wvm-checklist');
			page.issues.forEach(function (issue) {
				checklist.appendChild(renderIssue(issue, updateProgress));
			});
			body.appendChild(checklist);
			details.appendChild(body);
			container.appendChild(details);
		});
	}

	function load(fresh, live) {
		var request = ++state.request;
		renderFilter();
		remember();

		fetchJson(config.dashboardUrl, fresh).then(function (data) {
			if (request !== state.request) {
				return;
			}
			var overview = data.overview || [];
			renderAssessment(overview);
			renderOverview(overview, data.previous);
			renderScore(overview);
			renderAlerts(overview);
			renderTrend(data.trend || []);
			renderUrlTable(data.urls || []);
			renderHealth(data.health || {});
			renderDimensionTable('#wvm-device-table', data.devices || []);
			renderDimensionTable('#wvm-browser-table', data.browsers || []);
			renderDimensionTable('#wvm-pagetype-table', data.pageTypes || []);
			$('#wvm-updated').textContent = sprintf(ts('updated'), new Date().toLocaleTimeString());
		}).catch(function () {
			clear($('#wvm-overview')).appendChild(el('p', 'wvm-error', t('loadFailed')));
		});

		// The checklists change slowly; auto-refresh would only collapse what the user opened.
		if (live) {
			return;
		}

		document.querySelector('.wvm-suggestions-panel').hidden = !!state.path;
		var pagesTarget = $('#wvm-pages');
		if (!pagesTarget.firstChild) {
			pagesTarget.appendChild(el('p', 'wvm-muted', ts('analyzing')));
		}
		fetchJson(config.pagesUrl, fresh).then(function (data) {
			if (request === state.request) {
				renderPages(data);
			}
		}).catch(function () {
			clear(pagesTarget).appendChild(el('p', 'wvm-error', ts('pagesFailed')));
		});

		var target = $('#wvm-suggestions');
		if (!target.firstChild) {
			target.appendChild(el('p', 'wvm-muted', ts('analyzing')));
		}
		fetchJson(config.suggestionsUrl, fresh).then(function (data) {
			if (request === state.request) {
				renderSuggestions(data);
			}
		}).catch(function () {
			clear(target).appendChild(el('p', 'wvm-error', ts('suggestionsFailed')));
		});
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
		var range = $('#wvm-range');
		var device = $('#wvm-device');
		if (!range || !device) {
			return;
		}

		restore();
		range.value = String(state.days);
		if (range.value !== String(state.days)) {
			range.value = '7';
			state.days = 7;
		}
		device.value = state.device;

		range.addEventListener('change', function () {
			state.days = Number(range.value || 7);
			load(false);
		});
		device.addEventListener('change', function () {
			state.device = device.value;
			load(false);
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
