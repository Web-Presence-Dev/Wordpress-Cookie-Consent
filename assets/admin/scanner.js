/**
 * Web Presence Cookie Consent: site scanner (Scanner tab).
 *
 * Runs in the admin's browser, not PHP (see src/Admin/Scanner.php for why).
 * For each URL it fetches the HTML with blocking switched off, then loads the
 * page in a hidden iframe to catch cookies and requests that only happen when
 * scripts run. The server identifies what was found and saves the report.
 */
(function (wp) {
    'use strict';

    var data = window.wpccScan || {};
    var i18n = data.i18n || {};
    var root = document.querySelector('.wpcc-scan__results');
    var status = document.querySelector('.wpcc-scan__status');
    var startButton = document.querySelector('.wpcc-scan__start');
    var frames = document.querySelector('.wpcc-scan__frames');

    var RUN_TIME = 5000;
    var LOAD_TIMEOUT = 20000;
    var INLINE_LIMIT = 4000;

    if (!root || !startButton) {
        return;
    }

    function sprintf(text) {
        var args = Array.prototype.slice.call(arguments, 1);
        var i = 0;
        return String(text).replace(/%(\d+\$)?[sd]/g, function (match, position) {
            return position ? args[parseInt(position, 10) - 1] : args[i++];
        });
    }

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (key) {
            if (key === 'text') {
                node.textContent = attrs[key];
            } else {
                node.setAttribute(key, attrs[key]);
            }
        });
        (children || []).forEach(function (child) {
            if (child) {
                node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
            }
        });
        return node;
    }

    function cookieNames() {
        return document.cookie.split(';').map(function (c) { return c.split('=')[0].trim(); }).filter(Boolean);
    }

    function withToken(url, token) {
        var u = new URL(url, window.location.href);
        u.searchParams.set('wpcc_scan', token);
        return u.toString();
    }

    function absolute(src, base) {
        try {
            return new URL(src, base).toString();
        } catch (e) {
            return '';
        }
    }

    /* 1. Markup ------------------------------------------------------------ */

    function scanMarkup(url, token, findings) {
        return fetch(withToken(url, token), { credentials: 'same-origin' })
            .then(function (response) { return response.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');

                doc.querySelectorAll('script').forEach(function (script) {
                    var type = (script.getAttribute('type') || '').toLowerCase();
                    if (type && ['text/javascript', 'application/javascript', 'module'].indexOf(type) < 0) {
                        return;
                    }
                    if (script.getAttribute('src')) {
                        findings.push({ type: 'script', value: absolute(script.getAttribute('src'), url), page: url });
                    } else if (script.textContent.trim()) {
                        findings.push({ type: 'inline', value: script.textContent.slice(0, INLINE_LIMIT), page: url });
                    }
                });

                doc.querySelectorAll('iframe[src]').forEach(function (frame) {
                    findings.push({ type: 'iframe', value: absolute(frame.getAttribute('src'), url), page: url });
                });

                doc.querySelectorAll('img[src]').forEach(function (img) {
                    findings.push({ type: 'img', value: absolute(img.getAttribute('src'), url), page: url });
                });
            });
    }

    /* 2. Runtime ----------------------------------------------------------- */

    function scanRuntime(url, token, findings) {
        return new Promise(function (resolve) {
            var frame = el('iframe', {
                src: withToken(url, token),
                width: '1280',
                height: '800',
                tabindex: '-1',
                'aria-hidden': 'true'
            });
            var finished = false;

            function collect() {
                if (finished) {
                    return;
                }
                finished = true;

                try {
                    var win = frame.contentWindow;
                    win.performance.getEntriesByType('resource').forEach(function (entry) {
                        findings.push({ type: 'request', value: entry.name.split('?')[0], page: url });
                    });
                    win.document.querySelectorAll('iframe[src]').forEach(function (inner) {
                        findings.push({ type: 'iframe', value: absolute(inner.getAttribute('src'), url), page: url });
                    });
                } catch (e) {
                    // Different origin (the site and admin on different domains)
                }

                frame.remove();
                resolve();
            }

            var timeout = window.setTimeout(collect, LOAD_TIMEOUT);

            frame.addEventListener('load', function () {
                window.clearTimeout(timeout);
                window.setTimeout(collect, RUN_TIME);
            });

            frames.appendChild(frame);
        });
    }

    /* Clean up cookies the scan set in the admin's browser ------------------- */

    function deleteCookies(names) {
        var parts = window.location.hostname.split('.');
        var domains = [''];
        for (var i = 0; i < parts.length - 1; i++) {
            domains.push('; domain=.' + parts.slice(i).join('.'));
        }
        names.forEach(function (name) {
            domains.forEach(function (domain) {
                document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/' + domain;
            });
        });
    }

    function isWordPressCookie(name) {
        return /^(wordpress_|wp-settings|wp_lang|wpcc_|comment_author|PHPSESSID)/.test(name);
    }

    /* Scan ------------------------------------------------------------------ */

    function scan() {
        var before = cookieNames();
        var findings = [];
        var token;
        var urls;

        startButton.disabled = true;
        status.textContent = '';

        wp.apiFetch({ path: '/wpcc/v1/scan/start', method: 'POST' })
            .then(function (response) {
                token = response.token;
                urls = response.urls;

                return urls.reduce(function (chain, url, i) {
                    return chain.then(function () {
                        status.textContent = sprintf(i18n.scanning, i + 1, urls.length, url);
                        return scanMarkup(url, token, findings)
                            .catch(function () { /* keep going */ })
                            .then(function () { return scanRuntime(url, token, findings); });
                    });
                }, Promise.resolve());
            })
            .then(function () {
                status.textContent = i18n.saving;

                var after = cookieNames();
                var cookies = after.map(function (name) { return { name: name, page: urls[0] }; });
                var added = after.filter(function (name) { return before.indexOf(name) < 0 && !isWordPressCookie(name); });

                deleteCookies(added);

                return wp.apiFetch({
                    path: '/wpcc/v1/scan/finish',
                    method: 'POST',
                    data: { token: token, urls: urls, findings: dedupe(findings), cookies: cookies }
                });
            })
            .then(function (result) {
                status.textContent = i18n.done;
                render(result);
            })
            .catch(function (error) {
                status.textContent = sprintf(i18n.error, (error && error.message) || error);
            })
            .then(function () {
                startButton.disabled = false;
            });
    }

    function dedupe(findings) {
        var seen = {};
        return findings.filter(function (f) {
            var key = f.type + '|' + f.value + '|' + f.page;
            if (seen[key] || !f.value) {
                return false;
            }
            seen[key] = true;
            return true;
        });
    }

    /* Results --------------------------------------------------------------- */

    function render(result) {
        root.innerHTML = '';

        if (!result || !result.items) {
            return;
        }

        var when = new Date(result.time * 1000).toLocaleString();
        root.appendChild(el('p', { class: 'description', text: sprintf(i18n.lastScan, when) }));

        if (!result.items.length) {
            root.appendChild(el('p', { text: i18n.none }));
            return;
        }

        var counts = {};
        result.items.forEach(function (item) { counts[item.status] = (counts[item.status] || 0) + 1; });

        root.appendChild(el('p', { class: 'wpcc-scan__summary' }, Object.keys(counts).map(function (key) {
            return el('span', { class: 'wpcc-status wpcc-status--' + key, text: i18n.status[key] + ': ' + counts[key] });
        })));

        var rows = result.items.map(function (item) {
            var category = item.category ? (data.categories[item.category] || item.category) : '—';
            var pages = item.pages || [];

            return el('tr', {}, [
                el('td', {}, [el('span', { class: 'wpcc-status wpcc-status--' + item.status, text: i18n.status[item.status] })]),
                el('th', { scope: 'row' }, [
                    item.name,
                    item.name !== item.value ? el('div', {}, [el('code', { text: item.value })]) : null
                ]),
                el('td', { text: i18n.types[item.type] || item.type }),
                el('td', { text: category }),
                el('td', { class: 'wpcc-scan__pages', title: pages.join('\n'), text: pages.length + ' × ' + (pages[0] ? new URL(pages[0]).pathname : '') }),
                el('td', {}, [item.status === 'unknown' ? assign(item) : null])
            ]);
        });

        root.appendChild(el('table', { class: 'widefat striped wpcc-table' }, [
            el('thead', {}, [el('tr', {}, [
                el('th', { scope: 'col', text: 'Status' }),
                el('th', { scope: 'col', text: 'Found' }),
                el('th', { scope: 'col', text: 'Type' }),
                el('th', { scope: 'col', text: 'Category' }),
                el('th', { scope: 'col', text: 'Pages' }),
                el('th', { scope: 'col', text: 'Action' })
            ])]),
            el('tbody', {}, rows)
        ]));
    }

    // An unknown host gets a category (custom rule); an unknown cookie can only
    // be marked necessary, because cookies are blocked through their scripts
    function assign(item) {
        var select = el('select', { 'aria-label': item.value });
        select.appendChild(el('option', { value: '', text: i18n.choose }));

        if (item.type !== 'cookie') {
            Object.keys(data.categories).forEach(function (key) {
                select.appendChild(el('option', { value: key, text: data.categories[key] }));
            });
        }
        select.appendChild(el('option', { value: 'necessary', text: i18n.necessary }));

        var button = el('button', { type: 'button', class: 'button', text: 'Save' });

        button.addEventListener('click', function () {
            if (!select.value) {
                select.focus();
                return;
            }
            button.disabled = true;
            wp.apiFetch({
                path: '/wpcc/v1/rules',
                method: 'POST',
                data: { pattern: item.value, category: select.value, name: item.name }
            }).then(function (result) {
                status.textContent = i18n.saved;
                render(result);
            }).catch(function (error) {
                button.disabled = false;
                status.textContent = sprintf(i18n.error, (error && error.message) || error);
            });
        });

        return el('div', { class: 'wpcc-scan__assign' }, [select, button]);
    }

    startButton.addEventListener('click', scan);
    render(data.result);
})(window.wp);
