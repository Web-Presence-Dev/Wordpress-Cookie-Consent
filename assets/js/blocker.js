/*
 * Web Presence Cookie Consent: client-side blocker.
 *
 * Printed inline as the first script in <head> (src/Frontend/HeadScript.php),
 * after window.wpccConfig and window.wpccConsent are set. The server already
 * rewrites matching tags in the HTML (src/Frontend/Blocker.php). This catches
 * the ones other scripts add later, e.g. GTM loading the Meta Pixel:
 *
 * - document.createElement is wrapped so a <script>'s src/type, or an
 *   <iframe>/<img> src, is checked as it's set, before the element can load.
 * - A MutationObserver neutralises anything that still gets into the page
 *   (parser-inserted tags when output buffering was skipped, innerHTML).
 *
 * window.wpccUnblock() (called by consent.js after a choice) brings blocked
 * elements back in document order.
 */
(function (w, d) {
    var C = w.wpccConfig;

    if (!C || w.wpccUnblock) {
        return;
    }

    var BLOCKED = 'text/plain';
    var JS_TYPES = ['', 'text/javascript', 'application/javascript', 'module', 'text/ecmascript', 'application/ecmascript'];

    function allowed(category) {
        var c = w.wpccConsent;
        return !!(c && c[category]);
    }

    function find(value, key) {
        value = String(value || '').toLowerCase();

        if (!value) {
            return null;
        }

        for (var i = 0; i < C.rules.length; i++) {
            var list = C.rules[i][key];
            for (var j = 0; j < list.length; j++) {
                if (list[j] && value.indexOf(list[j]) > -1) {
                    return C.rules[i];
                }
            }
        }

        return null;
    }

    function ruleFor(el) {
        if (!el || el.nodeType !== 1 || el.hasAttribute('data-wpcc-ignore') || el.hasAttribute('data-wpcc')) {
            return null;
        }

        var tag = el.tagName, rule = null;

        if (tag === 'SCRIPT') {
            var type = (el.getAttribute('type') || '').toLowerCase();
            if (JS_TYPES.indexOf(type) < 0) {
                return null;
            }
            rule = el.getAttribute('src') ? find(el.getAttribute('src'), 's') : find(el.textContent, 'i');
        } else if (tag === 'IFRAME' || tag === 'IMG') {
            rule = find(el.getAttribute('src'), 's');
        }

        return rule && !allowed(rule.c) ? rule : null;
    }

    // src: the URL being set, for elements caught before their src is applied
    function neutralise(el, rule, src) {
        el.setAttribute('data-wpcc', rule.c);
        el.setAttribute('data-wpcc-name', rule.n);

        if (el.tagName === 'SCRIPT') {
            el.setAttribute('data-wpcc-type', el.getAttribute('type') || '');
            el.setAttribute('type', BLOCKED);
        } else {
            el.setAttribute('data-wpcc-src', src === undefined ? el.getAttribute('src') : src);
            el.removeAttribute('src');
            if (el.tagName === 'IFRAME') {
                el.classList.add('wpcc-blocked');
            }
        }
    }

    // Check a src before it's applied to an element from createElement
    function early(el, value) {
        if (el.hasAttribute('data-wpcc-ignore') || el.hasAttribute('data-wpcc')) {
            return null;
        }
        if (el.tagName === 'SCRIPT' && JS_TYPES.indexOf((el.getAttribute('type') || '').toLowerCase()) < 0) {
            return null;
        }
        var rule = find(value, 's');
        return rule && !allowed(rule.c) ? rule : null;
    }

    function guard(el) {
        var rule = ruleFor(el);
        if (rule) {
            neutralise(el, rule);
        }
    }

    // Elements from createElement: check src/type as they're set
    var create = d.createElement;
    var proto = {
        SCRIPT: HTMLScriptElement.prototype,
        IFRAME: HTMLIFrameElement.prototype,
        IMG: HTMLImageElement.prototype
    };

    function nativeProp(p, name) {
        return Object.getOwnPropertyDescriptor(p, name);
    }

    d.createElement = function () {
        var el = create.apply(d, arguments);
        var tag = el.tagName;

        if (!proto[tag]) {
            return el;
        }

        var src = nativeProp(proto[tag], 'src');
        var setAttr = el.setAttribute;

        try {
            Object.defineProperty(el, 'src', {
                configurable: true,
                get: function () { return src.get.call(el); },
                set: function (value) {
                    if (el.hasAttribute('data-wpcc-src')) {
                        setAttr.call(el, 'data-wpcc-src', value);
                        return;
                    }
                    // Images fetch as soon as src is set, so check first
                    var rule = early(el, value);
                    if (rule && tag !== 'SCRIPT') {
                        neutralise(el, rule, value);
                        return;
                    }
                    src.set.call(el, value);
                    if (rule) {
                        neutralise(el, rule);
                    }
                }
            });

            if (tag === 'SCRIPT') {
                var type = nativeProp(proto.SCRIPT, 'type');
                Object.defineProperty(el, 'type', {
                    configurable: true,
                    get: function () { return type.get.call(el); },
                    set: function (value) {
                        // Keep a blocked script inert until it's unblocked
                        if (el.getAttribute('type') === BLOCKED && el.hasAttribute('data-wpcc')) {
                            setAttr.call(el, 'data-wpcc-type', value);
                            return;
                        }
                        type.set.call(el, value);
                    }
                });
            }

            el.setAttribute = function (name, value) {
                name = String(name).toLowerCase();
                if (name === 'src' && el.hasAttribute('data-wpcc-src')) {
                    return setAttr.call(el, 'data-wpcc-src', value);
                }
                if (name === 'type' && tag === 'SCRIPT' && el.getAttribute('type') === BLOCKED && el.hasAttribute('data-wpcc')) {
                    return setAttr.call(el, 'data-wpcc-type', value);
                }
                var rule = name === 'src' ? early(el, value) : null;
                if (rule && tag !== 'SCRIPT') {
                    return neutralise(el, rule, value);
                }
                setAttr.call(el, name, value);
                if (rule) {
                    neutralise(el, rule);
                }
            };
        } catch (e) {
            // Older browsers: the MutationObserver below still catches it
        }

        return el;
    };

    // Inline scripts built with createElement get their code after src checks,
    // so check them again as they're added to the page
    ['appendChild', 'insertBefore'].forEach(function (method) {
        var original = Node.prototype[method];
        Node.prototype[method] = function (node) {
            if (node && node.tagName === 'SCRIPT') {
                guard(node);
            }
            return original.apply(this, arguments);
        };
    });

    new MutationObserver(function (mutations) {
        for (var i = 0; i < mutations.length; i++) {
            var nodes = mutations[i].addedNodes;
            for (var j = 0; j < nodes.length; j++) {
                var node = nodes[j];
                if (node.nodeType !== 1) {
                    continue;
                }
                guard(node);
                var inner = node.querySelectorAll ? node.querySelectorAll('script,iframe,img') : [];
                for (var k = 0; k < inner.length; k++) {
                    guard(inner[k]);
                }
                // consent.js puts a placeholder over blocked embeds as they arrive
                var frames = node.tagName === 'IFRAME' ? [node] : (node.querySelectorAll ? node.querySelectorAll('iframe') : []);
                for (var f = 0; f < frames.length; f++) {
                    if (frames[f].hasAttribute('data-wpcc')) {
                        d.dispatchEvent(new CustomEvent('wpcc:blocked', { detail: { element: frames[f] } }));
                    }
                }
            }
        }
    }).observe(d.documentElement, { childList: true, subtree: true });

    // Firefox runs parser-inserted scripts before the observer sees them
    d.addEventListener('beforescriptexecute', function (event) {
        if (event.target.getAttribute('type') === BLOCKED) {
            event.preventDefault();
        }
    }, true);

    /**
     * Restore blocked elements whose category is now allowed. Scripts are
     * re-created in document order; a non-async external script finishes
     * loading before the next one runs, so inline code that depends on it works.
     */
    w.wpccUnblock = function () {
        var els = d.querySelectorAll('[data-wpcc]');
        var scripts = [];

        for (var i = 0; i < els.length; i++) {
            var el = els[i];

            if (!allowed(el.getAttribute('data-wpcc'))) {
                continue;
            }

            if (el.tagName === 'SCRIPT') {
                scripts.push(el);
            } else {
                var src = el.getAttribute('data-wpcc-src');
                el.removeAttribute('data-wpcc');
                el.removeAttribute('data-wpcc-src');
                el.removeAttribute('data-wpcc-name');
                el.classList.remove('wpcc-blocked');
                if (src) {
                    el.setAttribute('src', src);
                }
                d.dispatchEvent(new CustomEvent('wpcc:unblocked', { detail: { element: el } }));
            }
        }

        (function next() {
            var old = scripts.shift();

            if (!old) {
                return;
            }

            var s = create.call(d, 'script');

            for (var a = 0; a < old.attributes.length; a++) {
                var attr = old.attributes[a];
                if (attr.name !== 'type' && attr.name.indexOf('data-wpcc') !== 0) {
                    s.setAttribute(attr.name, attr.value);
                }
            }

            var type = old.getAttribute('data-wpcc-type');
            if (type) {
                s.setAttribute('type', type);
            }

            s.setAttribute('data-wpcc-ignore', '');

            var wait = s.hasAttribute('src') && !s.hasAttribute('async') && !s.hasAttribute('defer');

            if (!s.hasAttribute('src')) {
                s.text = old.text;
            } else if (!old.hasAttribute('async')) {
                // Dynamic scripts are async by default; keep the original order
                s.async = false;
            }

            if (wait) {
                s.onload = s.onerror = next;
            }

            old.parentNode.replaceChild(s, old);

            if (!wait) {
                next();
            }
        })();
    };
})(window, document);
