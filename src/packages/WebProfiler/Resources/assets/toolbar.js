(function (config) {
    'use strict';
    var w = window;
    if (w.__neoWdt) {
        return;
    }
    var state = {ajax: [], root: null};
    w.__neoWdt = state;
    var storageKey = 'neo-wdt-collapsed';
    var nativeFetch = w.fetch ? w.fetch.bind(w) : null;

    function ignored(url) {
        url = String(url || '');
        return url.indexOf(config.toolbarPath) !== -1 || url.indexOf(config.profilerPath + '/') !== -1;
    }

    function track(method, url) {
        if (ignored(url)) {
            return null;
        }
        var entry = {
            method: String(method || 'GET').toUpperCase(),
            url: String(url),
            status: null,
            token: null,
            start: w.performance ? performance.now() : Date.now(),
            duration: null
        };
        state.ajax.unshift(entry);
        if (state.ajax.length > config.ajaxLimit) {
            state.ajax.pop();
        }
        render();
        return entry;
    }

    function finish(entry, status, token) {
        if (!entry) {
            return;
        }
        entry.status = status;
        entry.token = token || null;
        entry.duration = Math.round((w.performance ? performance.now() : Date.now()) - entry.start);
        render();
    }

    if (nativeFetch) {
        w.fetch = function (input, init) {
            var method = (init && init.method) || (input && typeof input === 'object' && input.method) || 'GET';
            var url = typeof input === 'string' ? input : (input && input.url) || String(input);
            var entry = track(method, url);
            return nativeFetch.apply(w, arguments).then(function (response) {
                finish(entry, response.status, response.headers.get('X-Debug-Token'));
                return response;
            }, function (error) {
                finish(entry, 'error', null);
                throw error;
            });
        };
    }

    if (w.XMLHttpRequest) {
        var open = XMLHttpRequest.prototype.open;
        var send = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.open = function (method, url) {
            this.__neoWdt = {method: method, url: url};
            return open.apply(this, arguments);
        };
        XMLHttpRequest.prototype.send = function () {
            var xhr = this;
            var info = xhr.__neoWdt;
            if (info && !info.internal) {
                var entry = track(info.method, info.url);
                xhr.addEventListener('loadend', function () {
                    var token = null;
                    try {
                        token = xhr.getResponseHeader('X-Debug-Token');
                    } catch (e) {
                        token = null;
                    }
                    finish(entry, xhr.status || 'error', token);
                });
            }
            return send.apply(this, arguments);
        };
    }

    function statusClass(status) {
        if (status === null) {
            return 'default';
        }
        if (status === 'error' || status >= 500) {
            return 'danger';
        }
        if (status >= 400) {
            return 'warning';
        }
        return 'success';
    }

    function cell(row, text, tag) {
        var node = document.createElement(tag || 'td');
        node.textContent = text;
        row.appendChild(node);
        return node;
    }

    function render() {
        var root = state.root;
        if (!root) {
            return;
        }
        var item = root.querySelector('[data-neo-wdt-ajax]');
        if (!item) {
            return;
        }
        item.hidden = state.ajax.length === 0;
        item.className = 'neo-wdt-item neo-wdt-' + (state.ajax.some(function (e) {
            return statusClass(e.status) === 'danger';
        }) ? 'danger' : (state.ajax.some(function (e) {
            return e.status === null;
        }) ? 'info' : 'default'));
        item.querySelector('.neo-wdt-value').textContent = String(state.ajax.length);
        var body = item.querySelector('tbody');
        body.textContent = '';
        state.ajax.forEach(function (entry) {
            var row = document.createElement('tr');
            cell(row, entry.method, 'th');
            var status = cell(row, entry.status === null ? '...' : String(entry.status));
            status.style.color = 'var(--wdt-' + statusClass(entry.status) + ', inherit)';
            cell(row, entry.url);
            cell(row, entry.duration === null ? '' : entry.duration + ' ms');
            var link = cell(row, '');
            if (entry.token) {
                var a = document.createElement('a');
                a.href = config.profilerPath + '/' + encodeURIComponent(entry.token);
                a.textContent = entry.token;
                link.appendChild(a);
            }
            body.appendChild(row);
        });
    }

    function remember(collapsed) {
        try {
            w.localStorage.setItem(storageKey, collapsed ? '1' : '0');
        } catch (e) {
            return;
        }
    }

    function collapsedByDefault() {
        try {
            return w.localStorage.getItem(storageKey) === '1';
        } catch (e) {
            return false;
        }
    }

    function init(container) {
        state.root = container;
        var bar = container.querySelector('.neo-wdt');
        var mini = container.querySelector('.neo-wdt-mini');
        if (!bar || !mini) {
            return;
        }

        function toggle(collapsed) {
            bar.hidden = collapsed;
            mini.hidden = !collapsed;
            remember(collapsed);
        }

        toggle(collapsedByDefault());
        container.querySelector('[data-neo-wdt-hide]').addEventListener('click', function () {
            toggle(true);
        });
        mini.addEventListener('click', function () {
            toggle(false);
        });
        render();
    }

    function load() {
        var container = document.getElementById(config.id);
        if (!container) {
            return;
        }
        var xhr = new XMLHttpRequest();
        xhr.open('GET', config.toolbarUrl);
        xhr.__neoWdt = {internal: true};
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onload = function () {
            if (xhr.status === 200) {
                container.innerHTML = xhr.responseText;
                init(container);
            }
        };
        xhr.send();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', load);
    } else {
        load();
    }
})