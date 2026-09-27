/* Zeko AI front-end behaviors: assistant chat, search, recommendations, writer. */
(function () {
	'use strict';

	var cfg = window.ZekoAI || {};

	function post(action, data) {
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', cfg.nonce || '');
		Object.keys(data || {}).forEach(function (key) {
			if (key === 'values') {
				var values = data[key];
				Object.keys(values).forEach(function (vk) {
					body.append('values[' + vk + ']', values[vk]);
				});
				return;
			}
			body.append(key, data[key]);
		});
		return fetch(cfg.ajaxUrl || '/wp-admin/admin-ajax.php', {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		}).then(function (res) {
			return res.json();
		});
	}

	function esc(str) {
		var div = document.createElement('div');
		div.textContent = String(str == null ? '' : str);
		return div.innerHTML;
	}

	document.addEventListener('DOMContentLoaded', function () {
		initChat();
		initSearch();
		initRecommendations();
		initWriter();
		initInlineWriters();
	});

	function initChat() {
		var shells = document.querySelectorAll('[data-zeko-ai-chat]');
		shells.forEach(function (shell) {
			if (shell.dataset.zekoAiWidget !== undefined) {
				buildWidget(shell);
				return;
			}
			wirePageChat(shell);
		});
	}

	function buildWidget(shell) {
		shell.innerHTML =
			'<button type="button" class="zeko-ai-widget-toggle" aria-expanded="false">' +
			esc(shell.dataset.zekoAiWidget || 'Zeko') +
			'</button>' +
			'<div class="zeko-ai-widget-panel">' +
			'<div class="zeko-ai-widget-head">' + esc(shell.dataset.zekoAiWidget || 'Zeko') +
			' <button type="button" class="zeko-ai-widget-close" aria-label="Close">&times;</button></div>' +
			'<div class="zeko-ai-chat-log"></div>' +
			'<div class="zeko-ai-chat-compose">' +
			'<textarea class="zeko-ai-chat-input" rows="1" placeholder="' + esc(cfg.i18n.typeHere || 'Ask Zeko…') + '"></textarea>' +
			'<button type="button" class="zeko-ai-chat-send btn">Send</button>' +
			'</div>' +
			'</div>';
		wireSend(shell);
		var toggle = shell.querySelector('.zeko-ai-widget-toggle');
		toggle.addEventListener('click', function () {
			var panel = shell.querySelector('.zeko-ai-widget-panel');
			var open = panel.classList.toggle('is-open');
			toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
			if (open) {
				panel.querySelector('.zeko-ai-chat-input').focus();
			}
		});
		shell.querySelector('.zeko-ai-widget-close').addEventListener('click', function () {
			shell.querySelector('.zeko-ai-widget-panel').classList.remove('is-open');
			toggle.setAttribute('aria-expanded', 'false');
		});
	}

	function wirePageChat(shell) {
		var log = shell.querySelector('.zeko-ai-chat-log');
		var height = shell.dataset.zekoAiHeight;
		if (height) {
			log.style.maxHeight = height;
		}

		var newBtn = document.createElement('button');
		newBtn.type = 'button';
		newBtn.className = 'zeko-ai-chat-new btn btn-secondary';
		newBtn.textContent = shell.dataset.zekoAiNewLabel || 'New chat';
		newBtn.style.marginBottom = '10px';
		shell.insertBefore(newBtn, log);
		newBtn.addEventListener('click', function () {
			shell.dataset.conversationId = '';
			log.innerHTML = '';
		});

		wireSend(shell);
		loadConversations(shell);
	}

	function loadConversations(shell) {
		var log = shell.querySelector('.zeko-ai-chat-log');
		var row = document.createElement('div');
		row.className = 'zeko-ai-conversation-row';
		var select = document.createElement('select');
		select.className = 'zeko-ai-conversation-picker';
		select.innerHTML = '<option value="">' + esc(shell.dataset.zekoAiNewLabel || 'New chat') + '</option>';
		var del = document.createElement('button');
		del.type = 'button';
		del.className = 'zeko-ai-chat-delete';
		del.textContent = shell.dataset.zekoAiDeleteLabel || 'Delete';
		del.style.display = 'none';

		del.addEventListener('click', function () {
			if (!select.value) {
				return;
			}
			if (!window.confirm(shell.dataset.zekoAiDeleteConfirm || 'Delete this conversation?')) {
				return;
			}
			post('zeko_ai_delete_conversation', { conversation_id: select.value }).then(function (r) {
				if (!r || !r.success) {
					return;
				}
				var opt = select.querySelector('option[value="' + select.value + '"]');
				if (opt) {
					opt.remove();
				}
				select.value = '';
				shell.dataset.conversationId = '';
				log.innerHTML = '';
				del.style.display = 'none';
			});
		});

		post('zeko_ai_conversations').then(function (res) {
			if (!res || !res.success) {
				return;
			}
			(res.conversations || []).forEach(function (c) {
				var opt = document.createElement('option');
				opt.value = c.id;
				opt.textContent = c.title;
				select.appendChild(opt);
			});
			select.addEventListener('change', function () {
				del.style.display = select.value ? 'inline-block' : 'none';
				if (!select.value) {
					shell.dataset.conversationId = '';
					log.innerHTML = '';
					return;
				}
				post('zeko_ai_messages', { conversation_id: select.value }).then(function (r) {
					if (!r || !r.success) {
						return;
					}
					shell.dataset.conversationId = select.value;
					log.innerHTML = '';
					(r.messages || []).forEach(function (m) {
						appendMessage(log, m.role, m.content);
					});
					log.scrollTop = log.scrollHeight;
				});
			});
		});
		row.appendChild(select);
		row.appendChild(del);
		shell.insertBefore(row, log);
	}

	function wireSend(shell) {
		var input = shell.querySelector('.zeko-ai-chat-input');
		var send = shell.querySelector('.zeko-ai-chat-send');

		function sendMessage() {
			var message = input.value.trim();
			if (!message) {
				return;
			}
			input.value = '';
			sendText(shell, message);
		}

		send.addEventListener('click', sendMessage);
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && !e.shiftKey) {
				e.preventDefault();
				sendMessage();
			}
		});
	}

	function appendMessage(log, role, text, knowledgeId) {
		var row = document.createElement('div');
		row.className = 'zeko-ai-msg zeko-ai-msg-' + role;
		if (role === 'assistant') {
			row.innerHTML = mdToHtml(text);
		} else {
			row.innerHTML = esc(text).replace(/\n/g, '<br>');
		}
		log.appendChild(row);
		if (role === 'assistant' && knowledgeId) {
			appendVoteButtons(log, knowledgeId);
		}
	}

	/* Minimal safe markdown: escape everything first, then convert tokens. */
	function mdToHtml(text) {
		var t = esc(String(text == null ? '' : text));

		t = t.replace(/```([\s\S]*?)```/g, function (m, code) {
			return '<pre class="zeko-ai-md-pre"><code>' + code + '</code></pre>';
		});
		t = t.replace(/`([^`\n]+)`/g, '<code>$1</code>');
		t = t.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
		t = t.replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>');
		t = t.replace(/\[([^\]\n]+)\]\(([^)\s]+)\)/g, function (m, label, url) {
			if (/^(https?:|mailto:|#)/.test(url)) {
				return '<a href="' + url + '" target="_blank" rel="noopener">' + label + '</a>';
			}
			return m;
		});
		t = t.replace(/^###\s+(.+)$/gm, '<h4 class="zeko-ai-md-h">$1</h4>');
		t = t.replace(/^##\s+(.+)$/gm, '<h4 class="zeko-ai-md-h">$1</h4>');
		t = t.replace(/(?:^|\n)((?:[-*•]\s+[^\n]+)(?:\n[-*•]\s+[^\n]+)*)/g, function (m, block) {
			var items = block.trim().split('\n').map(function (line) {
				return '<li>' + line.replace(/^[-*•]\s+/, '') + '</li>';
			}).join('');
			return '\n<ul>' + items + '</ul>';
		});
		t = t.replace(/(?:^|\n)((?:\d+\.\s+[^\n]+)(?:\n\d+\.\s+[^\n]+)*)/g, function (m, block) {
			var items = block.trim().split('\n').map(function (line) {
				return '<li>' + line.replace(/^\d+\.\s+/, '') + '</li>';
			}).join('');
			return '\n<ol>' + items + '</ol>';
		});
		t = t.split(/\n{2,}/).map(function (para) {
			para = para.trim();
			if (!para) {
				return '';
			}
			if (/^<(ul|ol|pre|h4)/.test(para)) {
				return para;
			}
			return '<p>' + para.replace(/\n/g, '<br>') + '</p>';
		}).join('');

		return t;
	}

	/* Send one chat message. Streams token deltas when enabled and supported,
	 * falling back to the buffered JSON response otherwise. */
	function sendText(shell, message) {
		var log = shell.querySelector('.zeko-ai-chat-log');
		appendMessage(log, 'user', message);
		var busy = document.createElement('div');
		busy.className = 'zeko-ai-chat-busy';
		busy.textContent = cfg.i18n.sending || 'Thinking…';
		log.appendChild(busy);
		log.scrollTop = log.scrollHeight;

		var convId = shell.dataset.conversationId || '';

		if (!cfg.streaming || typeof ReadableStream === 'undefined' || typeof TextDecoder === 'undefined') {
			post('zeko_ai_chat', {
				message: message,
				conversation_id: convId
			}).then(function (res) {
				busy.remove();
				finishJson(shell, log, res, message);
			}).catch(function () {
				busy.remove();
				appendMessage(log, 'assistant', cfg.i18n.error || 'Error');
			});
			return;
		}

		streamChat('zeko_ai_chat', {
			message: message,
			conversation_id: convId,
			stream: '1'
		}, {
			onStart: function () {
				var row = document.createElement('div');
				row.className = 'zeko-ai-msg zeko-ai-msg-assistant';
				busy.replaceWith(row);
				log.scrollTop = log.scrollHeight;
				return row;
			},
			onDelta: function (row, text) {
				row.textContent += text;
				log.scrollTop = log.scrollHeight;
			},
			onDone: function (row, res) {
				if (!res || !res.success) {
					if (row && row.parentNode) {
						row.remove();
					}
					appendMessage(log, 'assistant', res && res.error ? res.error : (cfg.i18n.error || 'Error'));
					return;
				}
				shell.dataset.conversationId = res.conversation_id;
				row.innerHTML = mdToHtml(res.reply);
				if (res.knowledge_id) {
					appendVoteButtons(log, res.knowledge_id);
				}
				appendSuggestions(shell, log, res);
				updatePicker(shell, res, message);
				log.scrollTop = log.scrollHeight;
			},
			onError: function (row) {
				if (row && row.parentNode) {
					row.remove();
				}
				appendMessage(log, 'assistant', cfg.i18n.error || 'Error');
			}
		});
	}

	/* Render a buffered (non-streaming) chat response. */
	function finishJson(shell, log, res, seed) {
		if (!res || !res.success) {
			appendMessage(log, 'assistant', res && res.error ? res.error : (cfg.i18n.error || 'Error'));
			return;
		}
		shell.dataset.conversationId = res.conversation_id;
		appendMessage(log, 'assistant', res.reply, res.knowledge_id || 0);
		appendSuggestions(shell, log, res);
		updatePicker(shell, res, seed);
	}

	/* Add the new conversation to the picker when it is still on "New chat". */
	function updatePicker(shell, res, seed) {
		var picker = shell.querySelector('.zeko-ai-conversation-picker');
		if (!picker || picker.value !== '') {
			return;
		}
		var opt = document.createElement('option');
		opt.value = res.conversation_id;
		opt.textContent = (seed || (res.reply || '')).slice(0, 60);
		opt.selected = true;
		picker.appendChild(opt);
	}

	/* Consume an SSE endpoint over fetch + ReadableStream. Emits delta events
	 * to onDelta as they arrive and onDone with the final 'done' event; onError
	 * fires on network/server failures. When the response is not a stream
	 * (e.g. provider buffered), parses the JSON body through onDone instead. */
	function streamChat(action, data, handlers) {
		var msgRow = handlers.onStart ? handlers.onStart() : null;
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', cfg.nonce || '');
		Object.keys(data || {}).forEach(function (key) {
			body.append(key, data[key]);
		});

		fetch(cfg.ajaxUrl || '/wp-admin/admin-ajax.php', {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		}).then(function (res) {
			var ctype = res.headers.get('content-type') || '';
			if (!res.body || ctype.indexOf('text/event-stream') === -1) {
				return res.json().then(function (r) {
					if (handlers.onDone) {
						handlers.onDone(msgRow, r);
					}
				});
			}

			var reader = res.body.getReader();
			var decoder = new TextDecoder();
			var buffer = '';

			function handleEvent(evt) {
				if (evt.type === 'delta' && evt.text) {
					if (handlers.onDelta) {
						handlers.onDelta(msgRow, evt.text);
					}
				} else if (evt.type === 'done') {
					if (handlers.onDone) {
						handlers.onDone(msgRow, evt);
					}
				} else if (evt.type === 'error') {
					if (handlers.onError) {
						handlers.onError(msgRow, evt);
					}
				}
			}

			function handleBlock(block) {
				block.split('\n').forEach(function (line) {
					if (line.indexOf('data:') !== 0) {
						return;
					}
					var raw = line.slice(5).trim();
					if (!raw || raw === '[DONE]') {
						return;
					}
					var evt = null;
					try {
						evt = JSON.parse(raw);
					} catch (ignored) {
						return;
					}
					handleEvent(evt);
				});
			}

			return reader.read().then(function pump(r) {
				if (r.done) {
					return;
				}
				buffer += decoder.decode(r.value, { stream: true });
				var idx;
				while ((idx = buffer.indexOf('\n\n')) !== -1) {
					handleBlock(buffer.slice(0, idx));
					buffer = buffer.slice(idx + 2);
				}
				return reader.read().then(pump);
			}).catch(function (err) {
				if (handlers.onError) {
					handlers.onError(msgRow, err);
				}
			});
		}).catch(function (err) {
			if (handlers.onError) {
				handlers.onError(msgRow, err);
			}
		});
	}

	/* Action buttons (deep links) + follow-up question chips under a reply. */
	function appendSuggestions(shell, log, res) {
		var actions = res.actions || [];
		var suggestions = res.suggestions || [];
		if (!actions.length && !suggestions.length) {
			return;
		}
		var box = document.createElement('div');
		box.className = 'zeko-ai-chips';
		actions.forEach(function (a) {
			var link = document.createElement('a');
			link.className = 'zeko-ai-chip zeko-ai-chip-action';
			link.href = a.url || '#';
			link.target = '_blank';
			link.rel = 'noopener';
			link.textContent = a.label || a.key;
			box.appendChild(link);
		});
		suggestions.forEach(function (s) {
			var chip = document.createElement('button');
			chip.type = 'button';
			chip.className = 'zeko-ai-chip';
			chip.textContent = s;
			chip.addEventListener('click', function () {
				var input = shell.querySelector('.zeko-ai-chat-input');
				if (input) {
					input.value = '';
				}
				sendText(shell, s);
			});
			box.appendChild(chip);
		});
		log.appendChild(box);
	}

	function appendVoteButtons(log, knowledgeId) {
		var votes = document.createElement('div');
		votes.className = 'zeko-ai-vote';
		votes.innerHTML =
			'<span class="zeko-ai-vote-label">Helpful?</span>' +
			'<button type="button" data-vote="positive">Yes</button>' +
			'<button type="button" data-vote="negative">No</button>';
		log.appendChild(votes);
		votes.addEventListener('click', function (e) {
			var btn = e.target.closest('button');
			if (!btn) {
				return;
			}
			votes.classList.add('is-voted');
			post('zeko_ai_vote', { knowledge_id: knowledgeId, vote: btn.dataset.vote }).then(function () {
			}).catch(function () {
			});
		});
	}

	function initSearch() {
		var shell = document.querySelector('[data-zeko-ai-search]');
		if (!shell) {
			return;
		}
		shell.innerHTML =
			'<div class="zeko-ai-search-form">' +
			'<input type="search" class="zeko-ai-search-input" placeholder="' + esc(shell.dataset.zekoAiPlaceholder || 'Search…') + '" />' +
			'<button type="button" class="zeko-ai-search-go btn">Search</button>' +
			'</div>' +
			'<div class="zeko-ai-search-results"></div>';

		var input = shell.querySelector('.zeko-ai-search-input');
		var go = shell.querySelector('.zeko-ai-search-go');
		var results = shell.querySelector('.zeko-ai-search-results');

		function run() {
			var term = input.value.trim();
			if (term.length < 2) {
				return;
			}
			results.innerHTML = '<p class="zeko-ai-busy">' + esc(cfg.i18n.sending || 'Searching…') + '</p>';
			post('zeko_ai_search', { term: term }).then(function (res) {
				if (!res || !res.success) {
					results.innerHTML = '<p class="zeko-ai-error">' + esc(res && res.error ? res.error : (cfg.i18n.error || 'Error')) + '</p>';
					return;
				}
				if (!res.total) {
					results.innerHTML = '<p>No results for “' + esc(term) + '”.</p>';
					return;
				}
				var html = '<div class="zeko-ai-search-count">' + res.total + ' results</div>';
				Object.keys(res.by_type || {}).forEach(function (type) {
					var heading = type === 'web' ? 'Web' : type;
					html += '<h4 class="zeko-ai-search-type">' + esc(heading) + '</h4>';
					(res.by_type[type] || []).forEach(function (item) {
						html += '<div class="zeko-ai-search-item"><a href="' + esc(item.url) + '">' + esc(item.title) + '</a>'
							+ (item.excerpt ? '<div class="zeko-ai-search-excerpt">' + esc(item.excerpt) + '</div>' : '')
							+ '</div>';
					});
				});
				results.innerHTML = html;
			}).catch(function () {
				results.innerHTML = '<p class="zeko-ai-error">' + esc(cfg.i18n.error || 'Error') + '</p>';
			});
		}

		go.addEventListener('click', run);
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				run();
			}
		});
	}

	function initRecommendations() {
		var shell = document.querySelector('[data-zeko-ai-recommendations]');
		if (!shell) {
			return;
		}
		shell.innerHTML =
			'<button type="button" class="zeko-ai-recs-refresh btn">' + esc(shell.dataset.zekoAiRefreshLabel || 'Refresh recommendations') + '</button>' +
			'<div class="zeko-ai-recs-list"></div>';

		var btn = shell.querySelector('.zeko-ai-recs-refresh');
		var list = shell.querySelector('.zeko-ai-recs-list');

		function refresh() {
			btn.disabled = true;
			btn.textContent = cfg.i18n.sending || 'Working…';
			list.innerHTML = '';
			post('zeko_ai_recommend').then(function (res) {
				btn.disabled = false;
				btn.textContent = shell.dataset.zekoAiRefreshLabel || 'Refresh recommendations';
				if (!res || !res.success) {
					list.innerHTML = '<p class="zeko-ai-error">' + esc(res && res.error ? res.error : (cfg.i18n.error || 'Error')) + '</p>';
					return;
				}
				if (!res.recommendations.length) {
					list.innerHTML = '<p>No recommendations yet. Keep exploring the ecosystem and refresh again.</p>';
					return;
				}
				var html = '<p class="zeko-ai-search-count">' + res.recommendations.length + ' recommendations</p><ul>';
				res.recommendations.forEach(function (r) {
					html += '<li class="zeko-ai-recs-item">'
						+ '<a href="' + (r.url || '') + '">' + esc(r.title || (r.item_type + ' #' + r.item_id)) + '</a>'
						+ ' <span class="zeko-ai-recs-type">' + esc(r.item_type) + '</span>'
						+ (r.reason ? '<div class="zeko-ai-recs-reason">' + esc(r.reason) + '</div>' : '')
						+ '</li>';
				});
				html += '</ul>';
				list.innerHTML = html;
			}).catch(function () {
				btn.disabled = false;
				list.innerHTML = '<p class="zeko-ai-error">' + esc(cfg.i18n.error || 'Error') + '</p>';
			});
		}

		btn.addEventListener('click', refresh);
		refresh();
	}

	function initWriter() {
		var shell = document.querySelector('[data-zeko-ai-writer]');
		if (!shell) {
			return;
		}

		var form = document.createElement('div');
		form.className = 'zeko-ai-writer';
		form.innerHTML = '<div class="zeko-ai-writer-controls"></div><div class="zeko-ai-writer-out"></div>';
		shell.appendChild(form);

		var controls = form.querySelector('.zeko-ai-writer-controls');
		var out = form.querySelector('.zeko-ai-writer-out');

		post('zeko_ai_presets').then(function (res) {
			if (!res || !res.success || !res.presets.length) {
				controls.innerHTML = '<p class="zeko-ai-error">' + esc(cfg.i18n.error || 'Error') + '</p>';
				return;
			}

			var html = '<label>Preset <select class="zeko-ai-writer-preset">';
			res.presets.forEach(function (p) {
				html += '<option value="' + esc(p.id) + '">' + esc(p.label) + '</option>';
			});
			html += '</select></label>';
			html += '<div class="zeko-ai-writer-fields"></div>';
			html += '<button type="button" class="zeko-ai-writer-go btn">' + esc(shell.dataset.zekoAiGenerate || 'Generate') + '</button>';
			html += '<button type="button" class="zeko-ai-writer-copy btn btn-secondary">Copy</button>';
			controls.innerHTML = html;

			var presets = {};
			res.presets.forEach(function (p) {
				presets[p.id] = p;
			});

			var presetSel = controls.querySelector('.zeko-ai-writer-preset');
			var fieldsEl = controls.querySelector('.zeko-ai-writer-fields');
			var go = controls.querySelector('.zeko-ai-writer-go');
			var copy = controls.querySelector('.zeko-ai-writer-copy');

			function renderFields() {
				var p = presets[presetSel.value];
				if (!p) {
					return;
				}
				var fh = '';
				(p.fields || []).forEach(function (field) {
					var label = field.label || field.key;
					var type = field.type === 'textarea' ? 'textarea rows="3"' : 'input type="text"';
					fh += '<label>' + esc(label) + ' <' + type + ' class="zeko-ai-writer-field" data-key="' + esc(field.key) + '" /></label>';
				});
				fieldsEl.innerHTML = fh;
			}

			presetSel.addEventListener('change', renderFields);
			renderFields();

			go.addEventListener('click', function () {
				go.disabled = true;
				go.textContent = cfg.i18n.generating || 'Generating…';
				var values = {};
				fieldsEl.querySelectorAll('.zeko-ai-writer-field').forEach(function (f) {
					values[f.dataset.key] = f.value;
				});
				post('zeko_ai_generate', {
					preset_id: presetSel.value,
					values: values
				}).then(function (r) {
					go.disabled = false;
					go.textContent = shell.dataset.zekoAiGenerate || 'Generate';
					if (!r || !r.success) {
						out.innerHTML = '<p class="zeko-ai-error">' + esc(r && r.error ? r.error : (cfg.i18n.error || 'Error')) + '</p>';
						return;
					}
					out.innerHTML = '<textarea class="zeko-ai-writer-output" rows="8">' + esc(r.content) + '</textarea>';
				}).catch(function () {
					go.disabled = false;
					go.textContent = shell.dataset.zekoAiGenerate || 'Generate';
					out.innerHTML = '<p class="zeko-ai-error">' + esc(cfg.i18n.error || 'Error') + '</p>';
				});
			});

			copy.addEventListener('click', function () {
				var ta = out.querySelector('.zeko-ai-writer-output');
				if (!ta) {
					return;
				}
				ta.select();
				if (navigator.clipboard) {
					navigator.clipboard.writeText(ta.value);
				}
			});
		});
	}

	function initInlineWriters() {
		var wrappers = document.querySelectorAll('[data-zeko-ai-preset]');
		if (!wrappers.length) {
			return;
		}

		var presetsCache = null;
		function getPresets() {
			if (presetsCache) {
				return Promise.resolve(presetsCache);
			}
			return post('zeko_ai_presets').then(function (res) {
				if (!res || !res.success) {
					throw new Error('presets unavailable');
				}
				var map = {};
				(res.presets || []).forEach(function (p) {
					map[p.id] = p;
				});
				presetsCache = map;
				return map;
			});
		}

		[].forEach.call(wrappers, function (wrapper) {
			var go = wrapper.querySelector('.zeko-ai-writer-inline-go');
			if (!go) {
				return;
			}
			var body = wrapper.querySelector('.zeko-ai-writer-inline-body');
			var status = wrapper.querySelector('.zeko-ai-writer-inline-status');
			var presetId = wrapper.getAttribute('data-zeko-ai-preset');
			var target = wrapper.getAttribute('data-zeko-ai-target') || '';

			go.addEventListener('click', function () {
				if (body.getAttribute('data-built')) {
					body.hidden = !body.hidden;
					return;
				}
				body.setAttribute('data-built', '1');
				getPresets().then(function (map) {
					var preset = map[presetId];
					if (!preset) {
						setStatus(esc(cfg.i18n.error || 'Error'), true);
						return;
					}
					var html = '<div class="zeko-ai-writer-inline-fields">';
					(preset.fields || []).forEach(function (field) {
						var label = field.label || field.key;
						var key = esc(field.key);
						if (field.type === 'textarea') {
							html += '<label>' + esc(label) + '<textarea rows="3" class="zeko-ai-writer-inline-field" data-key="' + key + '"></textarea></label>';
						} else {
							html += '<label>' + esc(label) + '<input type="text" class="zeko-ai-writer-inline-field" data-key="' + key + '" /></label>';
						}
					});
					html += '</div>';
					html += '<div class="zeko-ai-writer-inline-actions">'
						+ '<button type="button" class="zeko-ai-writer-inline-generate">' + esc(cfg.i18n.inlineGenerate || 'Generate draft') + '</button>'
						+ '<button type="button" class="zeko-ai-writer-inline-insert" hidden>' + esc(cfg.i18n.inlineInsert || 'Insert into field') + '</button>'
						+ '</div>';
					html += '<textarea class="zeko-ai-writer-output zeko-ai-writer-inline-out" rows="6" hidden></textarea>';
					body.innerHTML = html;
					body.hidden = false;

					var gen = body.querySelector('.zeko-ai-writer-inline-generate');
					var ins = body.querySelector('.zeko-ai-writer-inline-insert');
					var out = body.querySelector('.zeko-ai-writer-inline-out');

					gen.addEventListener('click', function () {
						var values = {};
						[].forEach.call(body.querySelectorAll('.zeko-ai-writer-inline-field'), function (f) {
							values[f.getAttribute('data-key')] = f.value;
						});
						gen.disabled = true;
						gen.textContent = cfg.i18n.generating || 'Generating…';
						status.textContent = '';
						post('zeko_ai_generate', {
							preset_id: preset.id,
							values: values
						}).then(function (r) {
							gen.disabled = false;
							gen.textContent = cfg.i18n.inlineGenerate || 'Generate draft';
							if (!r || !r.success) {
								setStatus(esc(r && r.error ? r.error : (cfg.i18n.error || 'Error')), true);
								return;
							}
							out.value = r.content;
							out.hidden = false;
							ins.hidden = false;
						}).catch(function () {
							gen.disabled = false;
							gen.textContent = cfg.i18n.inlineGenerate || 'Generate draft';
							setStatus(esc(cfg.i18n.error || 'Error'), true);
						});
					});

					ins.addEventListener('click', function () {
						insertDraft(target, out.value);
					});
				}).catch(function () {
					setStatus(esc(cfg.i18n.error || 'Error'), true);
				});
			});

			function setStatus(text, isError) {
				status.textContent = text || '';
				status.classList.toggle('zeko-ai-error', !!isError);
			}

			function insertDraft(targetSel, value) {
				var nodes = [];
				try {
					nodes = document.querySelectorAll(targetSel);
				} catch (ignored) {
					nodes = [];
				}
				if (!nodes.length) {
					setStatus(esc(cfg.i18n.error || 'Error'), true);
					return;
				}

				var quillBound = false;
				[].forEach.call(nodes, function (node) {
					if (!node) {
						return;
					}
					if (node.tagName === 'TEXTAREA' || node.tagName === 'INPUT') {
						node.value = value;
						node.dispatchEvent(new Event('input', { bubbles: true }));
						node.dispatchEvent(new Event('change', { bubbles: true }));
					} else {
						node.innerHTML = value;
					}

					var walker = node;
					while (walker && walker.parentElement) {
						walker = walker.parentElement;
						if (walker.querySelector && walker.querySelector('.ql-editor')) {
							quillBound = true;
							break;
						}
					}
				});

				[].forEach.call(document.querySelectorAll('[contenteditable="true"][data-name]'), function (ed) {
					var name = ed.getAttribute('data-name');
					if (name && targetSel.indexOf('name="' + name + '"') !== -1) {
						ed.innerHTML = value;
					}
				});

				if (quillBound) {
					copyText(value);
					setStatus(esc(cfg.i18n.inlineCopied || 'Inserted and copied to clipboard — paste it into the editor.'));
				} else {
					setStatus(esc(cfg.i18n.inlineInserted || 'Inserted into the field.'));
				}
			}

			function copyText(text) {
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(text).catch(function () {
						execCopy(text);
					});
					return;
				}
				execCopy(text);
			}

			function execCopy(text) {
				var ta = document.createElement('textarea');
				ta.value = text;
				ta.setAttribute('readonly', '');
				ta.style.position = 'fixed';
				ta.style.opacity = '0';
				document.body.appendChild(ta);
				ta.select();
				try {
					document.execCommand('copy');
				} catch (ignored) {}
				document.body.removeChild(ta);
			}
		});
	}
})();
