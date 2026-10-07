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
	function poll() {
		var body = new URLSearchParams({ action: 'lvmu_status', nonce: cfg.nonce });
		fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (!res || !res.success) { return; }
				var d = res.data;
				if (d.status === 'running') {
					var bar = box.querySelector('.lvmu-bar');
					document.getElementById('lvmu-progress-text').textContent = d.text;
					bar.setAttribute('aria-valuenow', d.percent);
					bar.firstElementChild.style.width = d.percent + '%';
					setTimeout(poll, cfg.interval || 3000);
				} else {
					document.getElementById('lvmu-progress-text').textContent = i18n.done || '';
					window.location.href = window.location.pathname + '?page=lvmu';
				}
			})
			.catch(function () { setTimeout(poll, (cfg.interval || 3000) * 2); });
	}
	if (box && cfg.running) { setTimeout(poll, 1500); }

	/* ---------- list table ---------- */
	var form = document.getElementById('lvmu-bulk-form');
	if (!form) { return; }
	var selectAll = document.getElementById('lvmu-select-all');
	var bar = document.getElementById('lvmu-select-all-bar');
	var allowUsed = document.getElementById('lvmu-allow-used');
	var warning = document.getElementById('lvmu-used-warning');

	function rows() { return form.querySelectorAll('tbody input[name="file[]"]'); }
	function checked() { return form.querySelectorAll('tbody input[name="file[]"]:checked'); }
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
			setTimeout(updateBar, 0);
		}
	});

	if (allowUsed && warning) {
		allowUsed.addEventListener('change', function () { warning.hidden = !allowUsed.checked; });
	}

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

	document.querySelectorAll('.lvmu-confirm').forEach(function (a) {
		a.addEventListener('click', function (e) { if (!window.confirm(i18n.confirmOne)) { e.preventDefault(); } });
	});
})();
