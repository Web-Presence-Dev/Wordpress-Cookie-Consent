/**
 * Web Presence Cookie Consent: settings page behaviour.
 *
 * - Appearance: colour pickers and a live preview of the banner and dialog.
 *   The preview is a front-end page in a frame (src/Frontend/Preview.php), so
 *   it's styled by the theme; it's on the same origin, so this sets custom
 *   properties and text on its .wpcc-preview directly.
 * - Categories: add and remove custom rule rows.
 */
(function ($) {
    'use strict';

    var SYSTEM_FONT = 'system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif';

    /* Appearance --------------------------------------------------------- */

    var frame = document.querySelector('.wpcc-preview-frame');
    // .wpcc-preview inside the frame, once it has loaded
    var preview = null;
    var mode = 'light';

    // True while undo puts fields back; picker callbacks must not write then
    var undoing = false;

    function field(name) {
        return document.querySelector('[name="wpcc_settings[' + name + ']"]');
    }

    function value(name) {
        var el = field(name);
        return el ? el.value : '';
    }

    function darkEnabled() {
        var el = field('dark_enabled');
        return Boolean(el && el.checked);
    }

    function applyColours() {
        if (!preview) {
            return;
        }

        var set = mode === 'dark' && darkEnabled() ? 'dark_colors' : 'colors';
        var hint = document.querySelector('.wpcc-preview__hint');

        if (hint) {
            hint.hidden = !(mode === 'dark' && !darkEnabled());
        }

        var hover = false;
        document.querySelectorAll('[data-wpcc-set="' + set + '"]').forEach(function (input) {
            var name = '--wpcc-' + input.getAttribute('data-wpcc-var');
            if (input.value) {
                preview.style.setProperty(name, input.value);
                hover = hover || /^primary-hover/.test(input.getAttribute('data-wpcc-var'));
            } else {
                // Empty (colours that follow another one): use the fallback
                preview.style.removeProperty(name);
            }
        });
        // Own hover colours replace the default brighten effect
        preview.style.setProperty('--wpcc-primary-hover-filter', hover ? 'none' : 'brightness(1.12)');
    }

    function applyFont() {
        var font = value('font');
        var customField = field('font_custom');
        if (customField) {
            customField.closest('.wpcc-field').hidden = font !== 'custom';
        }

        if (!preview) {
            return;
        }

        var custom = value('font_custom').trim();
        var family = font === 'system' ? SYSTEM_FONT : (font === 'custom' && custom ? custom + ',system-ui,sans-serif' : 'inherit');

        preview.style.setProperty('--wpcc-font', family);
        preview.style.setProperty('--wpcc-font-size', (parseInt(value('font_size'), 10) || 15) + 'px');
        preview.style.setProperty('--wpcc-radius', (parseInt(value('radius'), 10) || 0) + 'px');
        preview.style.setProperty('--wpcc-button-radius', (parseInt(value('button_radius'), 10) || 0) + 'px');
    }

    function applyPosition() {
        var checked = document.querySelector('[data-wpcc-preview="position"]:checked');
        var banner = preview && preview.querySelector('.wpcc-banner');
        if (!checked || !banner) {
            return;
        }
        banner.className = banner.className.replace(/wpcc-banner--[\w-]+/g, '').trim() + ' wpcc-banner--' + checked.value;
    }

    // Text fields → the matching elements in the preview
    var textTargets = {
        'text.banner': function (v) { setText('.wpcc-banner__text', v, true); },
        'text.title': function (v) { setText('.wpcc-dialog__title', v); },
        'text.intro': function (v) { setText('.wpcc-dialog__intro', v, true); },
        'text.accept': function (v) { setAll('[data-wpcc-action="accept"]', v); },
        'text.reject': function (v) { setAll('[data-wpcc-action="reject"]', v); },
        'text.save': function (v) { setAll('[data-wpcc-action="save"]', v); },
        'text.manage': function (v) { setAll('.wpcc-banner .wpcc-link', v); },
        'text.policy': function (v) { setAll('.wpcc-banner__text a, .wpcc-dialog__intro a', v); }
    };

    function setAll(selector, text) {
        if (preview) {
            preview.querySelectorAll(selector).forEach(function (el) { setLabel(el, text); });
        }
    }

    // Change a button's label but keep any icon or wrapper (theme button markup)
    function setLabel(el, text) {
        var walker = el.ownerDocument.createTreeWalker(el, NodeFilter.SHOW_TEXT, {
            acceptNode: function (node) {
                return node.nodeValue.trim() ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_SKIP;
            }
        });
        var node = walker.nextNode();
        if (node) {
            node.nodeValue = text;
        } else {
            el.textContent = text;
        }
    }

    // Keep the policy link that follows the text
    function setText(selector, text, keepLink) {
        var el = preview && preview.querySelector(selector);
        if (!el) {
            return;
        }
        var link = keepLink ? el.querySelector('a') : null;
        el.textContent = text;
        if (link) {
            el.appendChild(el.ownerDocument.createTextNode(' '));
            el.appendChild(link);
        }
    }

    // Everything the form says, applied to the preview (also after it reloads)
    function syncPreview() {
        if (!preview) {
            return;
        }
        preview.setAttribute('data-mode', mode);
        applyColours();
        applyFont();
        applyPosition();
        document.querySelectorAll('[data-wpcc-preview^="text."]').forEach(function (el) {
            var key = el.getAttribute('data-wpcc-preview');
            if (textTargets[key]) {
                textTargets[key](el.value);
            }
        });
        var floating = field('floating_button');
        preview.querySelectorAll('.wpcc-floating, .wpcc-preview__label').forEach(function (el) {
            el.hidden = !(floating && floating.checked);
        });
    }

    function connectPreview() {
        var doc = null;
        try {
            doc = frame.contentDocument;
        } catch (e) {
            doc = null;
        }

        // Front end on another address: the browser keeps the frame off limits,
        // so it can only show saved settings
        var cross = document.querySelector('.wpcc-preview__cross');
        if (cross) {
            cross.hidden = Boolean(doc);
        }

        var found = doc && doc.querySelector('.wpcc-preview');
        if (!found || found === preview) {
            return;
        }
        preview = found;

        // The preview's buttons and links are for show; its switches can be toggled
        preview.addEventListener('click', function (event) {
            if (event.target.closest('button, a')) {
                event.preventDefault();
            }
        });

        syncPreview();
    }

    if (frame) {
        frame.addEventListener('load', connectPreview);
        // Already loaded before this script ran
        try {
            if (frame.contentDocument && frame.contentDocument.readyState === 'complete') {
                connectPreview();
            }
        } catch (e) {
            // Different origin; the load event reports it
        }

        // The picker's drag handles are <a href="#">; a stray click on one
        // would jump the page to the top
        document.addEventListener('click', function (event) {
            if (event.target.closest && event.target.closest('.wp-picker-container a[href="#"]')) {
                event.preventDefault();
            }
        });

        $('.wpcc-color-picker').wpColorPicker({
            change: function (event, ui) {
                if (undoing) {
                    return;
                }
                // wpColorPicker fires before the input's value updates
                event.target.value = ui.color.toString();
                applyColours();
                event.target.dispatchEvent(new Event('change', { bubbles: true }));
            },
            clear: function (event) {
                if (undoing) {
                    return;
                }
                window.setTimeout(function () {
                    applyColours();
                    var input = $(event.target).closest('.wp-picker-container').find('.wpcc-color-picker')[0];
                    if (input) {
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }, 0);
            }
        });

        document.addEventListener('input', function (event) {
            var target = event.target;
            var key = target.getAttribute && target.getAttribute('data-wpcc-preview');

            if (target.classList.contains('wpcc-rgba')) {
                applyColours();
            } else if (key && textTargets[key]) {
                textTargets[key](target.value);
            } else if (key) {
                applyFont();
            }
        });

        document.addEventListener('change', function (event) {
            var target = event.target;
            var key = target.getAttribute && target.getAttribute('data-wpcc-preview');

            if (key === 'position') {
                applyPosition();
            } else if (key) {
                applyFont();
            }

            // Settings that only matter while a switch is on (data-wpcc-show-if)
            var name = target.type === 'checkbox' && target.name && target.name.match(/^wpcc_settings\[([a-z_]+)\]$/);
            if (name) {
                document.querySelectorAll('[data-wpcc-show-if="' + name[1] + '"]').forEach(function (el) {
                    el.hidden = !target.checked;
                });
                document.querySelectorAll('[data-wpcc-hide-if="' + name[1] + '"]').forEach(function (el) {
                    el.hidden = target.checked;
                });
            }

            if (target === field('floating_button') && preview) {
                preview.querySelectorAll('.wpcc-floating, .wpcc-preview__label').forEach(function (el) {
                    el.hidden = !target.checked;
                });
            }

            if (target === field('dark_enabled')) {
                applyColours();
            }
        });

        var enableDark = document.querySelector('.wpcc-preview__enable-dark');
        if (enableDark) {
            enableDark.addEventListener('click', function () {
                var toggle = field('dark_enabled');
                toggle.checked = true;
                toggle.dispatchEvent(new Event('change', { bubbles: true }));
                document.querySelector('.wpcc-dark-colors').scrollIntoView({ behavior: 'smooth', block: 'center' });
            });
        }

        document.querySelectorAll('[data-wpcc-mode]').forEach(function (button) {
            button.addEventListener('click', function () {
                mode = button.getAttribute('data-wpcc-mode');
                document.querySelectorAll('[data-wpcc-mode]').forEach(function (b) {
                    var on = b === button;
                    b.classList.toggle('is-active', on);
                    b.setAttribute('aria-pressed', on ? 'true' : 'false');
                });
                if (preview) {
                    preview.setAttribute('data-mode', mode);
                }
                applyColours();
            });
        });

        // Hover or focus a colour group: outline that part in the preview,
        // scrolling the preview (not the page) to it if it's out of view
        var highlighted = [];
        var clearHighlight = function () {
            highlighted.forEach(function (el) { el.classList.remove('is-wpcc-highlight'); });
            highlighted = [];
        };
        var highlight = function (group) {
            clearHighlight();
            if (!preview) {
                return;
            }
            var targets = Array.from(preview.querySelectorAll(group.getAttribute('data-wpcc-target')))
                .filter(function (el) { return !el.hidden; });
            targets.forEach(function (el) { el.classList.add('is-wpcc-highlight'); });
            highlighted = targets;

            // Scroll the preview frame (not the page) to it if it's out of view
            var first = targets[0];
            var win = frame.contentWindow;
            if (first && win) {
                var rect = first.getBoundingClientRect();
                if (rect.top < 0 || rect.top > win.innerHeight - 40) {
                    win.scrollTo({ top: win.scrollY + rect.top - 16, behavior: 'smooth' });
                }
            }
        };

        document.querySelectorAll('.wpcc-color-group').forEach(function (group) {
            group.addEventListener('mouseenter', function () { highlight(group); });
            group.addEventListener('mouseleave', function () {
                if (!group.contains(document.activeElement)) {
                    clearHighlight();
                }
            });
            group.addEventListener('focusin', function () { highlight(group); });
            group.addEventListener('focusout', function (event) {
                if (!group.contains(event.relatedTarget)) {
                    clearHighlight();
                }
            });
        });

        // A picker near the card's right edge opens leftwards
        document.addEventListener('click', function (event) {
            var button = event.target.closest && event.target.closest('.wpcc-color .wp-color-result');
            if (!button) {
                return;
            }
            var color = button.closest('.wpcc-color');
            var card = color.closest('.wpcc-card');
            color.classList.toggle('is-flipped', color.getBoundingClientRect().left + 257 > card.getBoundingClientRect().right - 8);
        }, true);

        applyFont();
    }

    /* Cookie policy page search ------------------------------------------ */

    var search = document.querySelector('.wpcc-page-search');

    if (search && window.wp && window.wp.apiFetch) {
        var input = search.querySelector('.wpcc-page-search__input');
        var hidden = search.querySelector('.wpcc-page-search__id');
        var list = search.querySelector('.wpcc-page-search__results');
        var clear = search.querySelector('.wpcc-page-search__clear');
        var current = search.querySelector('.wpcc-page-search__current');
        var timer = null;
        var active = -1;
        var results = [];
        var request = 0;

        var close = function () {
            list.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
            active = -1;
        };

        var highlight = function (index) {
            var items = list.querySelectorAll('[role="option"]');
            active = Math.max(0, Math.min(index, items.length - 1));
            items.forEach(function (item, i) {
                item.setAttribute('aria-selected', i === active ? 'true' : 'false');
            });
            if (items[active]) {
                input.setAttribute('aria-activedescendant', items[active].id);
                items[active].scrollIntoView({ block: 'nearest' });
            }
        };

        var choose = function (page) {
            hidden.value = page ? page.id : '';
            input.value = page ? page.title : '';
            clear.hidden = !page;
            current.textContent = '';
            if (page) {
                var link = document.createElement('a');
                link.href = page.url;
                link.target = '_blank';
                link.rel = 'noopener';
                link.textContent = page.url;
                current.appendChild(link);
            } else {
                current.textContent = current.getAttribute('data-none');
            }
            close();
            // A programmatic change; let undo record it
            hidden.dispatchEvent(new Event('change', { bubbles: true }));
        };

        var render = function () {
            list.innerHTML = '';
            if (!results.length) {
                var empty = document.createElement('li');
                empty.className = 'is-empty';
                empty.textContent = 'No pages found.';
                list.appendChild(empty);
            }
            results.forEach(function (page, i) {
                var item = document.createElement('li');
                item.id = 'wpcc-page-option-' + i;
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', 'false');
                item.textContent = page.title || '(no title)';
                var url = document.createElement('small');
                url.textContent = page.url;
                item.appendChild(url);
                item.addEventListener('mousedown', function (event) {
                    event.preventDefault();
                    choose(page);
                });
                list.appendChild(item);
            });
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            active = -1;
        };

        var find = function () {
            var term = input.value.trim();
            var id = ++request;
            window.wp.apiFetch({
                path: '/wp/v2/search?type=post&subtype=page&per_page=10&search=' + encodeURIComponent(term)
            }).then(function (pages) {
                if (id !== request) {
                    return;
                }
                results = pages.map(function (p) {
                    var div = document.createElement('div');
                    div.innerHTML = p.title;
                    return { id: p.id, title: div.textContent, url: p.url };
                });
                render();
            });
        };

        input.addEventListener('input', function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(find, 250);
        });

        input.addEventListener('focus', find);

        input.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                if (list.hidden) {
                    find();
                } else {
                    highlight(active + 1);
                }
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                highlight(active - 1);
            } else if (event.key === 'Enter') {
                if (!list.hidden && results[active]) {
                    event.preventDefault();
                    choose(results[active]);
                }
            } else if (event.key === 'Escape') {
                close();
            }
        });

        input.addEventListener('blur', function () {
            window.setTimeout(close, 150);
            // Text cleared by hand means no page
            if (!input.value.trim() && hidden.value) {
                choose(null);
            }
        });

        clear.addEventListener('click', function () {
            choose(null);
            input.focus();
        });
    }

    /* Pin the floating preview exactly where it starts ----------------- */

    var pinned = document.querySelector('.wpcc-preview-sticky');
    var band = document.querySelector('.wpcc-admin__top');

    if (pinned && band) {
        // Where the panel sits in the page with nothing pinned (allowing for
        // notices above it), never less than just below the header
        var setPin = function () {
            pinned.style.position = 'static';
            var natural = pinned.getBoundingClientRect().top + window.scrollY;
            pinned.style.position = '';
            var below = (parseFloat(getComputedStyle(band).top) || 0) + band.offsetHeight + 20;
            document.documentElement.style.setProperty('--wpcc-pin', Math.round(Math.max(natural, below)) + 'px');

            // Everything below the preview's column (footers), so the panel
            // fits above it and isn't pushed up at the bottom of the page
            var column = pinned.parentElement.getBoundingClientRect().bottom + window.scrollY;
            var footer = document.documentElement.scrollHeight - column;
            document.documentElement.style.setProperty('--wpcc-pin-space', Math.ceil(Math.max(20, footer)) + 'px');
        };
        setPin();
        window.addEventListener('resize', setPin);
    }

    /* Custom rules ------------------------------------------------------- */

    var rules = document.querySelector('.wpcc-rules');
    var template = document.getElementById('wpcc-rule-template');

    if (rules && template) {
        var next = parseInt(rules.getAttribute('data-next-index'), 10) || 0;

        document.querySelector('.wpcc-add-rule').addEventListener('click', function () {
            var html = template.innerHTML.replace(/__i__/g, String(next++));
            rules.querySelector('tbody').insertAdjacentHTML('beforeend', html);
            var rows = rules.querySelectorAll('tbody tr');
            rows[rows.length - 1].querySelector('input').focus();
            rules.dispatchEvent(new Event('change', { bubbles: true }));
        });

        rules.addEventListener('click', function (event) {
            var remove = event.target.closest('.wpcc-remove-rule');
            if (remove) {
                remove.closest('tr').remove();
                rules.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    }

    /* Unsaved changes --------------------------------------------------- */

    var form = document.getElementById('wpcc-form');
    var leave = document.getElementById('wpcc-leave');

    if (form) {
        var snapshot = function () {
            return new URLSearchParams(new FormData(form)).toString();
        };
        var initial = snapshot();
        var leaving = false;
        var destination = null;

        var dirty = function () {
            return !leaving && snapshot() !== initial;
        };

        form.addEventListener('submit', function () {
            leaving = true;
        });

        // Closing the tab, reloading, back button: only the browser's own prompt is allowed
        window.addEventListener('beforeunload', function (event) {
            if (dirty()) {
                event.preventDefault();
                event.returnValue = '';
            }
        });

        // Links inside the admin: our own modal, which can save first
        document.addEventListener('click', function (event) {
            var link = event.target.closest && event.target.closest('a[href]');

            if (!link || !leave || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            var href = link.getAttribute('href');

            if (!href || href.charAt(0) === '#' || /^javascript:/i.test(href) || link.target === '_blank' || link.closest('.wpcc-preview, #wpcc-leave') || !dirty()) {
                return;
            }

            event.preventDefault();
            destination = link.href;
            leave.showModal();
            leave.querySelector('[data-wpcc-leave="save"]').focus();
        }, true);

        if (leave) {
            leave.addEventListener('click', function (event) {
                var action = event.target.closest('[data-wpcc-leave]');

                if (!action) {
                    // A click on the backdrop
                    if (event.target === leave) {
                        leave.close();
                    }
                    return;
                }

                action = action.getAttribute('data-wpcc-leave');

                if (action === 'stay') {
                    leave.close();
                } else if (action === 'discard') {
                    leaving = true;
                    window.location.href = destination;
                } else if (action === 'save') {
                    // options.php redirects to the referer after saving, so
                    // point it at where they were going
                    var referer = form.querySelector('[name="_wp_http_referer"]');
                    if (referer && destination) {
                        var target = new URL(destination);
                        referer.value = target.pathname + target.search;
                    }
                    leaving = true;
                    form.requestSubmit ? form.requestSubmit() : form.submit();
                }
            });
        }
    }

    /* Undo ---------------------------------------------------------------- */

    var undoButton = document.querySelector('.wpcc-admin__undo');

    if (form && undoButton) {
        var rulesBody = document.querySelector('.wpcc-rules tbody');
        var pageSearch = document.querySelector('.wpcc-page-search');
        var history = [];
        var restoring = false;
        var pending = null;
        var queued = 0;

        // Everything needed to put the tab back exactly as it was
        var capture = function () {
            var state = {
                pairs: Array.from(new FormData(form).entries()),
                rules: rulesBody ? rulesBody.innerHTML : null,
                search: null
            };
            state.key = new URLSearchParams(state.pairs).toString();
            if (pageSearch) {
                state.search = {
                    title: pageSearch.querySelector('.wpcc-page-search__input').value,
                    current: pageSearch.querySelector('.wpcc-page-search__current').innerHTML,
                    clear: pageSearch.querySelector('.wpcc-page-search__clear').hidden
                };
            }
            return state;
        };

        var last = capture();

        var update = function () {
            undoButton.disabled = !history.length;
        };

        var record = function () {
            window.clearTimeout(pending);
            pending = null;
            if (restoring) {
                return;
            }
            var now = capture();
            if (now.key !== last.key) {
                history.push(last);
                last = now;
                update();
            }
        };

        // Typing and dragging in a picker settle into one step
        var recordSoon = function () {
            if (restoring) {
                return;
            }
            window.clearTimeout(pending);
            pending = window.setTimeout(record, 400);
        };

        form.addEventListener('input', recordSoon);
        form.addEventListener('change', recordSoon);

        var restore = function (state) {
            restoring = true;
            undoing = true;

            if (rulesBody && state.rules !== null) {
                rulesBody.innerHTML = state.rules;
            }

            var values = {};
            state.pairs.forEach(function (pair) {
                (values[pair[0]] = values[pair[0]] || []).push(pair[1]);
            });

            Array.from(form.elements).forEach(function (el) {
                if (!el.name || el.name.indexOf('wpcc_settings[') !== 0 || el.name === 'wpcc_settings[_tab]') {
                    return;
                }
                var list = values[el.name] || [];
                if (el.type === 'checkbox' || el.type === 'radio') {
                    el.checked = list.indexOf(el.value) > -1;
                } else {
                    el.value = list.length ? list[0] : '';
                }
            });

            if ($.fn.wpColorPicker) {
                $('.wpcc-color-picker').each(function () {
                    var value = this.value;
                    if (value) {
                        $(this).wpColorPicker('color', value);
                        this.value = value;
                    } else {
                        // Empty (follows another colour): just clear the swatch
                        $(this).closest('.wp-picker-container').find('.wp-color-result').css('background-color', '');
                    }
                });
            }

            if (pageSearch && state.search) {
                pageSearch.querySelector('.wpcc-page-search__input').value = state.search.title;
                pageSearch.querySelector('.wpcc-page-search__current').innerHTML = state.search.current;
                pageSearch.querySelector('.wpcc-page-search__clear').hidden = state.search.clear;
            }

            // Bring the preview and show/hide rules in line
            if (preview) {
                document.querySelectorAll('[data-wpcc-preview^="text."]').forEach(function (el) {
                    var key = el.getAttribute('data-wpcc-preview');
                    if (textTargets[key]) {
                        textTargets[key](el.value);
                    }
                });
                form.querySelectorAll('input[type="checkbox"][name^="wpcc_settings["]').forEach(function (el) {
                    el.dispatchEvent(new Event('change', { bubbles: true }));
                });
                applyColours();
                applyFont();
                applyPosition();
            }

            // Pickers report back a little later; ignore those, then take the
            // restored fields as the new baseline without adding a step
            window.setTimeout(function () {
                window.clearTimeout(pending);
                pending = null;
                undoing = false;
                restoring = false;
                last = capture();
                // Presses that came in meanwhile
                if (queued > 0) {
                    queued--;
                    undo();
                }
            }, 250);
        };

        var undo = function () {
            if (restoring) {
                queued++;
                return;
            }
            if (pending) {
                record();
            }
            if (!history.length) {
                return;
            }
            last = history.pop();
            update();
            restore(last);
        };

        undoButton.addEventListener('click', undo);

        // Ctrl/Cmd+Z, except while typing in a text box (its own undo applies)
        document.addEventListener('keydown', function (event) {
            if ((event.ctrlKey || event.metaKey) && !event.shiftKey && !event.altKey && event.key.toLowerCase() === 'z') {
                var el = document.activeElement;
                var typing = el && (el.tagName === 'TEXTAREA' || (el.tagName === 'INPUT' && /^(text|search|url|number|email)$/.test(el.type)));
                if (!typing) {
                    event.preventDefault();
                    undo();
                }
            }
        });

        // Saving starts a fresh history
        form.addEventListener('submit', function () {
            history = [];
            update();
        });
    }
})(jQuery);
