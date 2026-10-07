(function () {
	'use strict';
	var cfg = window.lvmuAdmin || {};
	var i18n = cfg.i18n || {};

	function fmt(str) {
		var args = Array.prototype.slice.call(arguments, 1);
		var i = 0;
		return str.replace(/%(\d\$)?s/g, function () { return args[i++]; });
	}
	function bytes(n) {
		var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0;
		while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
		return (i ? n.toFixed(1) : n) + ' ' + u[i];
	}

	/* ---------- progress polling ---------- */
	var box = document.getElementById('lvmu-progress');
	var maxSeen = { scan: 0, percent: 0 };
	function setText(id, t) { var el = document.getElementById(id); if (el) { el.textContent = t || ''; } }
	function showStatus(d) {
		var icon = document.getElementById('lvmu-status-icon');
		if (icon) { icon.classList.toggle('lvmu-spin', d.status === 'running'); }
		setText('lvmu-status-line', d.line);
		setText('lvmu-status-date', d.line_date);
	}
	var jobsBox = document.getElementById('lvmu-jobs');
	var indexing = !!cfg.running;
	var jobsSeen = !!cfg.jobs;
	function showJobs(d) {
		if (jobsBox && typeof d.jobs_html === 'string' && jobsBox.innerHTML !== d.jobs_html) {
			// Keep expanded details open while nothing changed in them.
			if (!jobsBox.querySelector('details[open]')) { jobsBox.innerHTML = d.jobs_html; }
		}
	}
	function poll() {
		var body = new URLSearchParams({ action: 'lvmu_status', nonce: cfg.nonce });
		fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (!res || !res.success) { return; }
				var d = res.data;
				showJobs(d);
				if (d.status === 'running' && box) {
					indexing = true;
					var bar = box.querySelector('.lvmu-bar');
					// Never let the bar go backwards within one run.
					if (maxSeen.scan !== d.scan_id) { maxSeen = { scan: d.scan_id, percent: 0 }; }
					maxSeen.percent = Math.max(maxSeen.percent, d.percent);
					var pct = maxSeen.percent;
					showStatus(d);
					box.hidden = false;
					setText('lvmu-progress-text', pct === d.percent ? d.text : d.text.replace(/\(\d+%\)$/, '(' + pct + '%)'));
					bar.setAttribute('aria-valuenow', pct);
					bar.firstElementChild.style.width = pct + '%';
					jobsSeen = jobsSeen || d.jobs_active;
					setTimeout(poll, cfg.interval || 3000);
				} else if (indexing) {
					showStatus(d);
					setText('lvmu-progress-text', i18n.done || '');
					window.location.href = window.location.pathname + '?page=lvmu';
				} else if (d.jobs_active) {
					jobsSeen = true;
					setTimeout(poll, cfg.interval || 3000);
				} else if (jobsSeen) {
					// Last delete job finished: reload so the list and totals are current.
					window.location.href = window.location.pathname + '?page=lvmu';
				}
			})
			.catch(function () { setTimeout(poll, (cfg.interval || 3000) * 2); });
	}
	if ((box && cfg.running) || cfg.jobs) { setTimeout(poll, 1500); }

	/* ---------- list table ---------- */
	var form = document.getElementById('lvmu-bulk-form');
	if (!form) { return; }
	var selectAll = document.getElementById('lvmu-select-all');
	var bar = document.getElementById('lvmu-select-all-bar');
	var allowUsed = document.getElementById('lvmu-allow-used');
	var warning = document.getElementById('lvmu-used-warning');

	function rows() { return form.querySelectorAll('tbody input[name="file[]"]:not(:disabled)'); }
	function checked() { return form.querySelectorAll('tbody input[name="file[]"]:checked:not(:disabled)'); }

	/* Used files are locked unless "Also delete files marked as used" is checked. */
	function allowUsedOn() {
		var boxes = form.querySelectorAll('input[name="lvmu_allow_used"]');
		for (var i = 0; i < boxes.length; i++) { if (boxes[i].checked) { return true; } }
		return false;
	}
	function lockUsed() {
		var on = allowUsedOn();
		form.querySelectorAll('tbody input[name="file[]"][data-lvmu-used]').forEach(function (cb) {
			cb.disabled = !on;
			if (!on) { cb.checked = false; }
		});
		form.querySelectorAll('a.lvmu-delete--locked').forEach(function (a) {
			a.setAttribute('aria-disabled', on ? 'false' : 'true');
			a.classList.toggle('lvmu-delete--locked', !on);
			a.classList.toggle('lvmu-delete--unlocked', on);
		});
		if (!on) {
			form.querySelectorAll('input[type=checkbox][id^="cb-select-all"]').forEach(function (b) { b.checked = false; });
			setAll(false);
		}
		updateBar();
	}
	function uncheckLocked() {
		form.querySelectorAll('tbody input[name="file[]"]:disabled').forEach(function (cb) { cb.checked = false; });
	}
	function setAll(on) { if (selectAll) { selectAll.value = on ? '1' : '0'; } }

	function updateBar() {
		if (!bar) { return; }
		var n = checked().length, total = parseInt(bar.dataset.total, 10);
		bar.textContent = '';
		if (selectAll.value === '1') {
			bar.hidden = false;
			bar.appendChild(document.createTextNode(fmt(i18n.allSelected, bar.dataset.totalH) + ' '));
			var c = document.createElement('button');
			c.type = 'button'; c.className = 'button-link'; c.textContent = i18n.clear;
			c.addEventListener('click', function () {
				setAll(false);
				form.querySelectorAll('input[type=checkbox][id^="cb-select-all"]').forEach(function (b) { b.checked = false; });
				rows().forEach(function (r) { r.checked = false; });
				updateBar();
			});
			bar.appendChild(c);
		} else if (n > 0 && n === rows().length && total > n) {
			bar.hidden = false;
			bar.appendChild(document.createTextNode(fmt(i18n.pageSelected, n) + ' '));
			var b = document.createElement('button');
			b.type = 'button'; b.className = 'button-link';
			b.textContent = fmt(i18n.selectAll, bar.dataset.totalH);
			b.addEventListener('click', function () { setAll(true); updateBar(); });
			bar.appendChild(b);
		} else {
			bar.hidden = true;
		}
	}
	form.addEventListener('change', function (e) {
		if (e.target.name === 'file[]' || e.target.id.indexOf('cb-select-all') === 0) {
			setAll(false);
			// WP core toggles the row checkboxes in its own handler; read after it ran.
			setTimeout(function () { uncheckLocked(); updateBar(); }, 0);
		}
	});

	if (allowUsed && warning) {
		allowUsed.addEventListener('change', function () { warning.hidden = !allowUsed.checked; });
	}
	form.querySelectorAll('input[name="lvmu_allow_used"]').forEach(function (cb) {
		cb.addEventListener('change', function () {
			form.querySelectorAll('input[name="lvmu_allow_used"]').forEach(function (o) { o.checked = cb.checked; });
			lockUsed();
		});
	});
	lockUsed();

	form.addEventListener('submit', function (e) {
		var a1 = form.querySelector('select[name="action"]'), a2 = form.querySelector('select[name="action2"]');
		var act = (a1 && a1.value !== '-1') ? a1.value : (a2 ? a2.value : '-1');
		if (act !== 'lvmu_delete') { return; }
		var msg;
		if (selectAll.value === '1') {
			msg = fmt(i18n.confirmBulkAll, bar.dataset.totalH, bar.dataset.sizeH);
		} else {
			var sel = checked();
			if (!sel.length) { e.preventDefault(); window.alert(i18n.nothing); return; }
			var sum = 0;
			sel.forEach(function (c) { sum += parseInt(c.dataset.size, 10) || 0; });
			msg = fmt(i18n.confirmBulk, sel.length, bytes(sum));
		}
		if (allowUsed && allowUsed.checked) { msg += '\n\n' + i18n.confirmUsed; }
		if (!window.confirm(msg)) { e.preventDefault(); }
	});

	// Land on the list after filtering, sorting or paging (WP_List_Table links carry no hash).
	(function () {
		var target = document.getElementById('lvmu-files');
		if (!target || window.location.hash) { return; }
		if (!/[?&](file_type|file_role|usage_status|min_size|orphaned|s|orderby|order|paged)=/.test(window.location.search)) { return; }
		var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		target.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
	})();

	document.querySelectorAll('.lvmu-confirm').forEach(function (a) {
		a.addEventListener('click', function (e) {
			if (a.getAttribute('aria-disabled') === 'true') { e.preventDefault(); return; }
			if (a.hasAttribute('data-lvmu-used')) {
				if (!window.confirm(i18n.confirmOne + '\n\n' + i18n.confirmUsed)) { e.preventDefault(); return; }
				e.preventDefault();
				window.location.href = a.href + (a.href.indexOf('?') < 0 ? '?' : '&') + 'lvmu_allow_used=1';
				return;
			}
			if (!window.confirm(i18n.confirmOne)) { e.preventDefault(); }
		});
	});
})();
