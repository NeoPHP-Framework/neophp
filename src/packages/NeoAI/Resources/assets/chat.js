(function (config) {
    'use strict';
    var w = window;
    var d = document;
    var errors = w.__neoAiErrors || (w.__neoAiErrors = []);

    if (config.mode === 'toolbar') {
        if (w.__neoAiToolbar) {
            return;
        }
        w.__neoAiToolbar = true;
    }

    function capture() {
        if (w.__neoAiCapture) {
            return;
        }
        w.__neoAiCapture = true;

        function push(message) {
            errors.push(String(message).slice(0, 500));
            if (errors.length > 20) {
                errors.shift();
            }
        }

        if (w.console && typeof w.console.error === 'function') {
            var original = w.console.error;
            w.console.error = function () {
                try {
                    push(Array.prototype.map.call(arguments, function (a) {
                        return a && a.message ? a.message : String(a);
                    }).join(' '));
                } catch (e) {
                    push('console.error');
                }
                return original.apply(w.console, arguments);
            };
        }
        w.addEventListener('error', function (event) {
            push((event.message || 'Error') + (event.filename ? ' (' + event.filename + ':' + event.lineno + ')' : ''));
        });
        w.addEventListener('unhandledrejection', function (event) {
            var reason = event.reason;
            push('Unhandled rejection: ' + (reason && reason.message ? reason.message : String(reason)));
        });
    }

    function el(tag, className, text) {
        var node = d.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined && text !== null) {
            node.textContent = text;
        }
        return node;
    }

    function escapeHtml(text) {
        return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function inline(text) {
        return text
            .replace(/`([^`\n]+)`/g, '<code>$1</code>')
            .replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
            .replace(/\[([^\]\n]+)\]\(((?:https?:\/\/|\/(?!\/))[^\s()<>"]*)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
    }

    function markdown(source) {
        var blocks = [];
        var text = String(source || '').replace(/\r\n?/g, '\n').replace(/```([\w-]*)[ \t]*\n([\s\S]*?)```/g, function (all, lang, code) {
            blocks.push('<pre><code data-lang="' + escapeHtml(lang) + '">' + escapeHtml(code.replace(/\n$/, '')) + '</code></pre>');
            return '\n\u0000' + (blocks.length - 1) + '\u0000\n';
        });
        var lines = escapeHtml(text).split('\n');
        var html = '';
        var list = null;
        var paragraph = [];

        function flushParagraph() {
            if (paragraph.length) {
                html += '<p>' + inline(paragraph.join('<br>')) + '</p>';
                paragraph = [];
            }
        }

        function closeList() {
            if (list) {
                html += '</' + list + '>';
                list = null;
            }
        }

        lines.forEach(function (line) {
            var block = /^\u0000(\d+)\u0000$/.exec(line);
            var bullet = /^\s*[-*+]\s+(.*)$/.exec(line);
            var ordered = /^\s*\d+[.)]\s+(.*)$/.exec(line);
            var heading = /^#{1,6}\s+(.*)$/.exec(line);
            if (block) {
                flushParagraph();
                closeList();
                html += blocks[Number(block[1])];
            } else if (bullet || ordered) {
                flushParagraph();
                var type = bullet ? 'ul' : 'ol';
                if (list !== type) {
                    closeList();
                    html += '<' + type + '>';
                    list = type;
                }
                html += '<li>' + inline((bullet || ordered)[1]) + '</li>';
            } else if (heading) {
                flushParagraph();
                closeList();
                html += '<p><strong>' + inline(heading[1]) + '</strong></p>';
            } else if (line.trim() === '') {
                flushParagraph();
                closeList();
            } else {
                closeList();
                paragraph.push(line);
            }
        });
        flushParagraph();
        closeList();
        return html;
    }

    function diffView(diff) {
        var pre = el('pre');
        var code = el('code');
        String(diff).split('\n').forEach(function (line, index) {
            var span = el('span', line.indexOf('+++') === 0 || line.indexOf('---') === 0 ? '' : (line.charAt(0) === '+' ? 'neo-ai-add' : (line.charAt(0) === '-' ? 'neo-ai-del' : (line.indexOf('@@') === 0 ? 'neo-ai-hunk' : ''))), (index ? '\n' : '') + line);
            code.appendChild(span);
        });
        pre.appendChild(code);
        return pre;
    }

    function copy(text, button) {
        function done() {
            var label = button.textContent;
            button.textContent = 'Copied';
            setTimeout(function () {
                button.textContent = label;
            }, 1200);
        }

        function fallback() {
            var area = el('textarea');
            area.value = text;
            area.style.position = 'fixed';
            area.style.opacity = '0';
            d.body.appendChild(area);
            area.select();
            try {
                d.execCommand('copy');
            } catch (e) {
                return;
            }
            d.body.removeChild(area);
            done();
        }

        if (w.navigator.clipboard && w.isSecureContext) {
            w.navigator.clipboard.writeText(text).then(done, fallback);
        } else {
            fallback();
        }
    }

    function visibleText(skip) {
        var parts = [];
        Array.prototype.forEach.call(d.body ? d.body.children : [], function (node) {
            if (skip.indexOf(node) !== -1 || /^(SCRIPT|STYLE|NOSCRIPT|TEMPLATE)$/.test(node.tagName) || (node.classList && (node.classList.contains('neo-wdt-container') || node.classList.contains('neo-ai')))) {
                return;
            }
            var text = node.innerText || '';
            if (text.trim()) {
                parts.push(text.trim());
            }
        });
        return parts.join('\n').replace(/\n{3,}/g, '\n\n').slice(0, 6000);
    }

    function pageContext(skip) {
        var headings = Array.prototype.slice.call(d.querySelectorAll('h1,h2,h3'), 0, 30).map(function (h) {
            return h.tagName + ': ' + (h.textContent || '').trim().slice(0, 120);
        });
        var forms = Array.prototype.slice.call(d.forms, 0, 10).map(function (form) {
            return {
                action: form.getAttribute('action') || '',
                method: (form.getAttribute('method') || 'GET').toUpperCase(),
                fields: Array.prototype.slice.call(form.elements, 0, 40).map(function (field) {
                    return (field.name || field.id || '?') + ':' + (field.type || field.tagName.toLowerCase());
                })
            };
        });
        var images = d.images ? d.images.length : 0;
        var withoutAlt = Array.prototype.filter.call(d.images || [], function (img) {
            return !img.hasAttribute('alt');
        }).length;
        return {
            url: w.location.href,
            title: d.title,
            headings: headings,
            forms: forms,
            text: visibleText(skip),
            errors: errors.slice(),
            dom: {
                elements: d.getElementsByTagName('*').length,
                links: d.links ? d.links.length : 0,
                images: images,
                images_without_alt: withoutAlt,
                scripts: d.scripts ? d.scripts.length : 0,
                lang: d.documentElement.getAttribute('lang') || ''
            }
        };
    }

    function storage(key, value) {
        try {
            if (value === undefined) {
                return w.sessionStorage.getItem(key);
            }
            if (value === null) {
                w.sessionStorage.removeItem(key);
            } else {
                w.sessionStorage.setItem(key, value);
            }
        } catch (e) {
            return null;
        }
        return null;
    }

    function build(root) {
        var key = 'neo-ai-conversation-' + config.mode;
        var conversation = storage(key) || '';
        var busy = false;
        var head = el('div', 'neo-ai-head');
        head.appendChild(el('span', 'neo-ai-title', 'NeoAI'));
        var meta = el('span', 'neo-ai-meta');
        meta.appendChild(d.createTextNode(config.provider + ' · ' + config.model + ' · '));
        meta.appendChild(el('span', config.remote ? 'neo-ai-remote' : '', config.remote ? 'remote' : 'local'));
        meta.title = 'Questions, attached context and tool results are sent to ' + config.provider + ' (' + config.model + ') after secret redaction.';
        head.appendChild(meta);
        var clear = el('button', 'neo-ai-btn', 'New');
        clear.type = 'button';
        clear.title = 'Start a new conversation';
        head.appendChild(clear);
        if (config.mode === 'toolbar') {
            var close = el('button', 'neo-ai-btn', '×');
            close.type = 'button';
            close.title = 'Close';
            close.addEventListener('click', function () {
                root.hidden = true;
            });
            head.appendChild(close);
        }
        var log = el('div', 'neo-ai-log');
        var privacy = el('div', 'neo-ai-privacy', 'Sent to ' + config.provider + ' / ' + config.model + (config.remote ? ' (remote server)' : ' (local)') + '. Secrets are redacted. Patches are never applied from the browser.');
        var form = el('form', 'neo-ai-form');
        var input = el('textarea');
        input.placeholder = config.placeholder || 'Ask about this page, its profile or the code... (Enter to send, Shift+Enter for a new line)';
        var row = el('div', 'neo-ai-row');
        var attachLabel = el('label');
        var attach = el('input');
        attach.type = 'checkbox';
        attach.checked = config.mode === 'toolbar';
        attachLabel.appendChild(attach);
        attachLabel.appendChild(d.createTextNode(config.mode === 'toolbar' ? ' Attach page context (URL, title, text, forms, JS errors)' : ' Attach this profile'));
        if (config.mode !== 'toolbar') {
            attach.checked = true;
        }
        var send = el('button', 'neo-ai-btn neo-ai-btn-primary', 'Send');
        send.type = 'submit';
        row.appendChild(attachLabel);
        row.appendChild(send);
        form.appendChild(input);
        form.appendChild(row);
        root.appendChild(head);
        root.appendChild(log);
        root.appendChild(privacy);
        root.appendChild(form);

        function scroll() {
            log.scrollTop = log.scrollHeight;
        }

        function suggestions() {
            var list = config.suggestions || [];
            if (!list.length) {
                return;
            }
            var box = el('div', 'neo-ai-suggest');
            list.forEach(function (text) {
                var chip = el('button', 'neo-ai-btn', text);
                chip.type = 'button';
                chip.addEventListener('click', function () {
                    input.value = text;
                    submit();
                });
                box.appendChild(chip);
            });
            log.appendChild(box);
        }

        function addUser(text) {
            log.appendChild(el('div', 'neo-ai-msg neo-ai-msg-user', text));
            scroll();
        }

        function addAssistant(data) {
            var box = el('div', 'neo-ai-msg neo-ai-msg-assistant');
            var body = el('div');
            body.innerHTML = markdown(data.reply);
            box.appendChild(body);
            (data.patches || []).forEach(function (patch, index) {
                var wrap = el('div', 'neo-ai-patch');
                var bar = el('div', 'neo-ai-patch-head');
                bar.appendChild(el('span', '', 'Patch #' + (index + 1) + ': ' + (patch.files || []).join(', ') + (patch.description ? ' — ' + patch.description : '')));
                var button = el('button', 'neo-ai-btn', 'Copy');
                button.type = 'button';
                button.addEventListener('click', function () {
                    copy(patch.diff, button);
                });
                bar.appendChild(button);
                wrap.appendChild(bar);
                wrap.appendChild(diffView(patch.diff));
                box.appendChild(wrap);
            });
            if (data.usage) {
                box.appendChild(el('div', 'neo-ai-usage', (data.model || config.model) + ' · ' + (data.usage.prompt || 0) + ' in / ' + (data.usage.completion || 0) + ' out tokens' + (data.tools && data.tools.length ? ' · tools: ' + data.tools.map(function (t) {
                    return t.tool;
                }).join(', ') : '')));
            }
            log.appendChild(box);
            scroll();
        }

        function addError(message) {
            log.appendChild(el('div', 'neo-ai-msg neo-ai-msg-error', message));
            scroll();
        }

        function submit() {
            var message = input.value.trim();
            if (!message || busy) {
                return;
            }
            busy = true;
            send.disabled = true;
            input.value = '';
            addUser(message);
            var pending = el('div', 'neo-ai-msg neo-ai-msg-pending', 'Thinking… (' + config.model + ')');
            log.appendChild(pending);
            scroll();
            var payload = {message: message, conversation_id: conversation, connection: config.connection};
            if (attach.checked) {
                payload.profile_token = config.profileToken || null;
                if (config.mode === 'toolbar') {
                    payload.page = pageContext([root]);
                }
            }
            var headers = {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            };
            headers[config.header] = config.token;
            w.fetch(config.endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: headers,
                body: JSON.stringify(payload)
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        return {ok: response.ok, status: response.status, data: data};
                    }, function () {
                        return {ok: false, status: response.status, data: {error: 'HTTP ' + response.status}};
                    });
                })
                .then(function (result) {
                    pending.parentNode.removeChild(pending);
                    if (!result.ok || result.data.error) {
                        addError(result.data.error || ('HTTP ' + result.status));
                        return;
                    }
                    conversation = result.data.conversation_id || conversation;
                    storage(key, conversation);
                    addAssistant(result.data);
                }, function (error) {
                    if (pending.parentNode) {
                        pending.parentNode.removeChild(pending);
                    }
                    addError('Network error: ' + (error && error.message ? error.message : error));
                })
                .then(function () {
                    busy = false;
                    send.disabled = false;
                    input.focus();
                });
        }

        clear.addEventListener('click', function () {
            conversation = '';
            storage(key, null);
            log.textContent = '';
            suggestions();
        });
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            submit();
        });
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                event.preventDefault();
                submit();
            }
        });
        if (config.error) {
            addError(config.error);
        }
        suggestions();
        return input;
    }

    if (config.mode === 'toolbar') {
        capture();
        var trigger = d.querySelector('.neo-wdt-item[data-name="' + config.item + '"] .neo-wdt-main');
        var panel = el('div', 'neo-ai neo-ai-floating');
        panel.hidden = true;
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', 'NeoAI assistant');
        var input = null;
        d.body.appendChild(panel);

        function toggle() {
            if (!input) {
                input = build(panel);
            }
            panel.hidden = !panel.hidden;
            if (!panel.hidden) {
                input.focus();
            }
        }

        if (trigger) {
            trigger.setAttribute('role', 'button');
            trigger.addEventListener('click', function (event) {
                event.preventDefault();
                toggle();
            });
            trigger.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    toggle();
                }
            });
        }
        return;
    }

    var mount = d.getElementById(config.mount);
    if (mount) {
        mount.classList.add('neo-ai', 'neo-ai-embedded');
        build(mount);
    }
})