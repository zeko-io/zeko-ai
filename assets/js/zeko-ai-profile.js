/* Zeko AI member profile hub: instant tab switching (no reload) + copy-link. */
(function () {
	'use strict';

	function onReady(fn) {
		if (document.readyState !== 'loading') {
			fn();
		} else {
			document.addEventListener('DOMContentLoaded', fn);
		}
	}

	function activate(root, key) {
		root.querySelectorAll('.zeko-ai-profile-tab[data-zeko-tab]').forEach(function (tab) {
			var on = tab.getAttribute('data-zeko-tab') === key;
			tab.classList.toggle('is-active', on);
			tab.setAttribute('aria-selected', on ? 'true' : 'false');
		});
		root.querySelectorAll('[data-zeko-tabpanel]').forEach(function (panel) {
			var on = panel.getAttribute('data-zeko-tabpanel') === key;
			panel.hidden = !on;
			panel.classList.toggle('is-active', on);
		});
	}

	function initTabs(root) {
		root.querySelectorAll('.zeko-ai-profile-tab[data-zeko-tab]').forEach(function (tab) {
			tab.addEventListener('click', function (e) {
				e.preventDefault();
				activate(root, tab.getAttribute('data-zeko-tab'));
				history.replaceState(null, '', tab.getAttribute('href'));
			});
		});
	}

	function copyText(text, done) {
		function fallback() {
			var input = document.createElement('textarea');
			input.value = text;
			input.setAttribute('readonly', '');
			input.style.position = 'fixed';
			input.style.left = '-9999px';
			document.body.appendChild(input);
			input.select();
			try {
				document.execCommand('copy');
				done();
			} catch (err) {
				/* clipboard unavailable; nothing else to do */
			}
			document.body.removeChild(input);
		}
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(done, fallback);
		} else {
			fallback();
		}
	}

	function initShare(root) {
		var btn = root.querySelector('.zeko-ai-profile-share[data-zeko-share]');
		if (!btn) {
			return;
		}
		var copiedLabel = btn.getAttribute('data-zeko-share-copied') || 'Copied!';
		var original = btn.textContent;
		btn.addEventListener('click', function () {
			copyText(btn.getAttribute('data-zeko-share'), function () {
				btn.textContent = copiedLabel;
				window.setTimeout(function () {
					btn.textContent = original;
				}, 2000);
			});
		});
	}

	onReady(function () {
		document.querySelectorAll('.zeko-ai-profile').forEach(function (root) {
			initTabs(root);
			initShare(root);
		});
	});
})();
