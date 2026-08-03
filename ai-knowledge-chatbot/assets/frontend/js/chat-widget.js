/**
 * AI Knowledge Chatbot — front-end widget.
 *
 * Vanilla JS, no build step and no external dependencies, so the plugin
 * never requires site owners (or this codebase) to run a JS bundler.
 * Conversation history is kept in sessionStorage (not localStorage) so it
 * survives navigation within a tab but does not linger indefinitely on a
 * visitor's machine.
 */
(function () {
    'use strict';

    var config = window.aikcChat || {};
    var HISTORY_KEY = 'aikc_chat_history';
    var MAX_STORED_TURNS = 20;

    function getHistory() {
        try {
            var raw = window.sessionStorage.getItem(HISTORY_KEY);
            var parsed = raw ? JSON.parse(raw) : [];
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    }

    function saveHistory(history) {
        try {
            window.sessionStorage.setItem(HISTORY_KEY, JSON.stringify(history.slice(-MAX_STORED_TURNS)));
        } catch (e) {
            // sessionStorage unavailable (private mode, quota, etc.) — the
            // widget still works, it just won't remember history on reload.
        }
    }

    function clearHistory() {
        try {
            window.sessionStorage.removeItem(HISTORY_KEY);
        } catch (e) {
            // ignore
        }
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    /**
     * Minimal, safe markdown-to-HTML conversion. Escapes everything first,
     * then re-introduces only the handful of tags this function itself
     * generates — untrusted text can never smuggle in arbitrary HTML this
     * way, even though in practice the text here comes from our own AI
     * provider responses grounded in our own indexed content.
     */
    function renderMarkdown(text) {
        var html = escapeHtml(text);

        html = html.replace(/```([a-zA-Z0-9]*)\n?([\s\S]*?)```/g, function (match, lang, code) {
            return '<pre><code>' + code + '</code></pre>';
        });

        html = html.replace(/`([^`\n]+)`/g, '<code>$1</code>');
        html = html.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/(^|[^*])\*([^*\n]+)\*(?!\*)/g, '$1<em>$2</em>');
        html = html.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
        html = html.replace(/\n/g, '<br>');

        return html;
    }

    function buildPanel(root, mode) {
        var isFloating = mode === 'floating';

        if (isFloating) {
            var launcher = document.createElement('button');
            launcher.type = 'button';
            launcher.className = 'aikc-chat-launcher';
            launcher.setAttribute('aria-label', config.i18n && config.i18n.openLabel ? config.i18n.openLabel : 'Open chat');
            launcher.textContent = '💬';
            root.appendChild(launcher);
        }

        var panel = document.createElement('div');
        panel.className = 'aikc-chat-panel';

        if (isFloating) {
            panel.hidden = true;
        }

        panel.innerHTML =
            '<div class="aikc-chat-header">' +
                '<span class="aikc-chat-title"></span>' +
                (isFloating ? '<button type="button" class="aikc-chat-header-close" aria-label="Close">×</button>' : '') +
            '</div>' +
            '<div class="aikc-chat-messages" role="log" aria-live="polite"></div>' +
            '<div class="aikc-chat-footer">' +
                '<form class="aikc-chat-form">' +
                    '<div class="aikc-chat-input-row">' +
                        '<textarea class="aikc-chat-input" rows="1"></textarea>' +
                        '<button type="submit" class="aikc-chat-send"></button>' +
                    '</div>' +
                '</form>' +
                '<button type="button" class="aikc-chat-clear"></button>' +
            '</div>';

        root.appendChild(panel);

        panel.querySelector('.aikc-chat-title').textContent = config.title || 'Chat';
        panel.querySelector('.aikc-chat-input').placeholder = config.placeholder || '';
        panel.querySelector('.aikc-chat-send').textContent = (config.i18n && config.i18n.send) || 'Send';
        panel.querySelector('.aikc-chat-clear').textContent = (config.i18n && config.i18n.clear) || 'Clear chat';

        if (isFloating) {
            var closeBtn = panel.querySelector('.aikc-chat-header-close');
            var toggle = function () {
                panel.hidden = !panel.hidden;
                if (!panel.hidden) {
                    panel.querySelector('.aikc-chat-input').focus();
                }
            };
            root.querySelector('.aikc-chat-launcher').addEventListener('click', toggle);
            closeBtn.addEventListener('click', toggle);
        }

        return panel;
    }

    function appendMessage(messagesEl, role, text, isMarkdown) {
        var bubble = document.createElement('div');
        bubble.className = 'aikc-chat-message ' + (role === 'user' ? 'user' : 'assistant');

        var textEl = document.createElement('div');
        textEl.className = 'aikc-chat-message-text';
        textEl.innerHTML = isMarkdown ? renderMarkdown(text) : escapeHtml(text);
        bubble.appendChild(textEl);

        if (role === 'assistant') {
            var actions = document.createElement('div');
            actions.className = 'aikc-chat-message-actions';

            var copyBtn = document.createElement('button');
            copyBtn.type = 'button';
            copyBtn.className = 'aikc-chat-copy-btn';
            copyBtn.textContent = (config.i18n && config.i18n.copy) || 'Copy';
            copyBtn.addEventListener('click', function () {
                var raw = textEl.getAttribute('data-raw') || textEl.textContent;
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(raw).then(function () {
                        copyBtn.textContent = (config.i18n && config.i18n.copied) || 'Copied!';
                        setTimeout(function () {
                            copyBtn.textContent = (config.i18n && config.i18n.copy) || 'Copy';
                        }, 1500);
                    });
                }
            });

            actions.appendChild(copyBtn);
            bubble.appendChild(actions);
        }

        messagesEl.appendChild(bubble);
        messagesEl.scrollTop = messagesEl.scrollHeight;

        return textEl;
    }

    /**
     * Renders the "Sources:" list under an assistant message once its
     * sources are known. Sources always arrive after the message text
     * itself (they come with the 'done' SSE event, or alongside the full
     * JSON body in the non-streaming fallback), so this is called
     * separately from appendMessage() rather than folded into it.
     */
    function renderSources(textEl, sources) {
        if (!textEl || !sources || !sources.length) {
            return;
        }

        var bubble = textEl.parentNode;

        if (!bubble || bubble.querySelector('.aikc-chat-sources')) {
            return;
        }

        var wrap = document.createElement('div');
        wrap.className = 'aikc-chat-sources';

        var label = document.createElement('span');
        label.className = 'aikc-chat-sources-label';
        label.textContent = ((config.i18n && config.i18n.sources) || 'Sources') + ':';
        wrap.appendChild(label);

        var list = document.createElement('ul');

        sources.forEach(function (source) {
            var li = document.createElement('li');
            var title = (source && source.title) || (source && source.url) || '';

            if (source && source.url) {
                var link = document.createElement('a');
                link.href = source.url;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                link.textContent = title;
                li.appendChild(link);
            } else {
                li.textContent = title;
            }

            list.appendChild(li);
        });

        wrap.appendChild(list);
        bubble.appendChild(wrap);
    }

    function appendTypingIndicator(messagesEl) {
        var wrap = document.createElement('div');
        wrap.className = 'aikc-chat-message assistant aikc-chat-typing-wrap';
        wrap.innerHTML = '<div class="aikc-chat-typing"><span></span><span></span><span></span></div>';
        messagesEl.appendChild(wrap);
        messagesEl.scrollTop = messagesEl.scrollHeight;
        return wrap;
    }

    function initWidget(root) {
        var mode = root.getAttribute('data-aikc-mode') || 'inline';
        var panel = buildPanel(root, mode);
        var messagesEl = panel.querySelector('.aikc-chat-messages');
        var form = panel.querySelector('.aikc-chat-form');
        var input = panel.querySelector('.aikc-chat-input');
        var sendBtn = panel.querySelector('.aikc-chat-send');
        var clearBtn = panel.querySelector('.aikc-chat-clear');

        var history = getHistory();

        if (history.length === 0) {
            if (config.welcomeMessage) {
                appendMessage(messagesEl, 'assistant', config.welcomeMessage, true);
            }
        } else {
            history.forEach(function (turn) {
                appendMessage(messagesEl, turn.role, turn.content, turn.role === 'assistant');
            });
        }

        function setBusy(busy) {
            sendBtn.disabled = busy;
            input.disabled = busy;
        }

        function send(question) {
            appendMessage(messagesEl, 'user', question, false);
            history.push({ role: 'user', content: question });
            saveHistory(history);

            var typingEl = appendTypingIndicator(messagesEl);
            setBusy(true);

            var payload = {
                message: question,
                history: history.slice(0, -1),
                stream: true,
            };

            var supportsStream = typeof window.fetch === 'function' && !!window.ReadableStream;

            var finish = function (fullText, errored) {
                if (typingEl && typingEl.parentNode) {
                    typingEl.parentNode.removeChild(typingEl);
                }
                setBusy(false);

                if (fullText) {
                    history.push({ role: 'assistant', content: fullText });
                    saveHistory(history);
                }

                if (errored) {
                    appendMessage(messagesEl, 'assistant', (config.i18n && config.i18n.error) || 'Something went wrong.', false);
                }
            };

            if (!supportsStream) {
                requestOnce(payload, function (text, ok, sources) {
                    if (ok) {
                        var textEl = appendMessage(messagesEl, 'assistant', text, true);
                        renderSources(textEl, sources);
                    }
                    finish(ok ? text : '', !ok);
                });
                return;
            }

            var textEl = null;
            var fullText = '';

            fetch(config.restUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            }).then(function (response) {
                if (!response.ok || !response.body) {
                    throw new Error('bad response');
                }

                var reader = response.body.getReader();
                var decoder = new TextDecoder();
                var buffer = '';

                function pump() {
                    return reader.read().then(function (result) {
                        if (result.done) {
                            finish(fullText, false);
                            return;
                        }

                        buffer += decoder.decode(result.value, { stream: true });

                        var boundary;
                        while ((boundary = buffer.indexOf('\n\n')) !== -1) {
                            var rawEvent = buffer.slice(0, boundary);
                            buffer = buffer.slice(boundary + 2);
                            handleEvent(rawEvent);
                        }

                        return pump();
                    });
                }

                function handleEvent(rawEvent) {
                    var eventType = 'message';
                    var dataLine = '';

                    rawEvent.split('\n').forEach(function (line) {
                        line = line.trim();
                        if (line.indexOf('event:') === 0) {
                            eventType = line.slice(6).trim();
                        } else if (line.indexOf('data:') === 0) {
                            dataLine = line.slice(5).trim();
                        }
                    });

                    if (!dataLine) {
                        return;
                    }

                    var data;
                    try {
                        data = JSON.parse(dataLine);
                    } catch (e) {
                        return;
                    }

                    if (eventType === 'delta' && typeof data.text === 'string') {
                        if (!textEl) {
                            if (typingEl && typingEl.parentNode) {
                                typingEl.parentNode.removeChild(typingEl);
                                typingEl = null;
                            }
                            textEl = appendMessage(messagesEl, 'assistant', '', false);
                        }

                        fullText += data.text;
                        textEl.setAttribute('data-raw', fullText);
                        textEl.innerHTML = renderMarkdown(fullText);
                        messagesEl.scrollTop = messagesEl.scrollHeight;
                    } else if (eventType === 'done') {
                        if (textEl && data.sources) {
                            renderSources(textEl, data.sources);
                        }
                        finish(fullText, false);
                    } else if (eventType === 'error') {
                        finish(fullText, true);
                    }
                }

                return pump();
            }).catch(function () {
                finish(fullText, true);
            });
        }

        function requestOnce(payload, callback) {
            payload.stream = false;

            fetch(config.restUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            })
                .then(function (response) {
                    return response.json().then(function (json) {
                        return { ok: response.ok, json: json };
                    });
                })
                .then(function (result) {
                    if (result.ok && result.json && typeof result.json.content === 'string') {
                        callback(result.json.content, true, result.json.sources);
                    } else {
                        callback('', false, []);
                    }
                })
                .catch(function () {
                    callback('', false, []);
                });
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var question = input.value.trim();

            if (!question || sendBtn.disabled) {
                return;
            }

            input.value = '';
            send(question);
        });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                form.dispatchEvent(new Event('submit', { cancelable: true }));
            }
        });

        clearBtn.addEventListener('click', function () {
            clearHistory();
            history = [];
            messagesEl.innerHTML = '';

            if (config.welcomeMessage) {
                appendMessage(messagesEl, 'assistant', config.welcomeMessage, true);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (!config.restUrl) {
            return;
        }

        var roots = document.querySelectorAll('.aikc-chat-root');
        roots.forEach ? roots.forEach(initWidget) : Array.prototype.forEach.call(roots, initWidget);
    });
})();
