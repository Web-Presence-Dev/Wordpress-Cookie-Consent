/**
 * Web Presence Cookie Consent: banner, preferences dialog and unblocking.
 *
 * Ported from the webpresence theme's assets/js/modules/cookie-consent.js.
 * The head script (src/Frontend/HeadScript.php) has already set every Google
 * consent type to "denied" and re-applied any saved choice. This file:
 *
 * - shows the banner when there's no saved choice
 * - saves choices in the `wpcc_consent` cookie
 * - tells the Google tag (gtag 'consent' 'update') and the dataLayer
 *   (`consent_update` event), and the WP Consent API when it's installed
 * - unblocks scripts and embeds for the allowed categories (blocker.js)
 * - deletes known tracking cookies when consent is withdrawn
 *
 * Other scripts can listen for `wpcc:consent` on document
 * (detail: { functional, analytics, marketing }).
 *
 * Anything with the class `wpcc-open` or `js-cookie-settings` opens the
 * preferences dialog, including the [cookie_settings] shortcode.
 */
(function (w, d) {
    'use strict';

    var config = w.wpccConfig;
    var CATEGORIES = ['functional', 'analytics', 'marketing'];
    var BANNER_DELAY = 300;
    var EXIT_DURATION = 400;

    if (!config) {
        return;
    }

    function reducedMotion() {
        return w.matchMedia && w.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function afterExit(callback) {
        if (reducedMotion()) {
            callback();
            return null;
        }
        return w.setTimeout(callback, EXIT_DURATION);
    }

    function grant(value) {
        return value ? 'granted' : 'denied';
    }

    /* Cookies ------------------------------------------------------------ */

    function cookieDomain() {
        var host = w.location.hostname;
        // Share the choice between www. and the bare domain
        return host.indexOf('www.') === 0 ? '; domain=' + host.slice(3) : '';
    }

    function writeConsent(consent) {
        var secure = w.location.protocol === 'https:' ? '; Secure' : '';
        d.cookie = config.cookie + '=' + encodeURIComponent(JSON.stringify(consent))
            + '; path=/; max-age=' + (config.lifetime * 86400) + '; SameSite=Lax' + secure + cookieDomain();
    }

    function matches(name, pattern) {
        return pattern.slice(-1) === '*' ? name.indexOf(pattern.slice(0, -1)) === 0 : name === pattern;
    }

    // Matching cookies on this host and each parent domain
    function deleteCookies(patterns) {
        if (!patterns || !patterns.length) {
            return;
        }

        var names = d.cookie.split(';')
            .map(function (c) { return c.split('=')[0].trim(); })
            .filter(function (name) {
                return name && name !== config.cookie && patterns.some(function (p) { return matches(name, p); });
            });

        if (!names.length) {
            return;
        }

        var parts = w.location.hostname.split('.');
        var domains = [''];

        for (var i = 0; i < parts.length - 1; i++) {
            domains.push('; domain=.' + parts.slice(i).join('.'));
        }

        names.forEach(function (name) {
            domains.forEach(function (domain) {
                d.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/' + domain;
            });
        });
    }

    /* Placeholders for blocked embeds -------------------------------------- */

    var i18n = {};
    try {
        i18n = JSON.parse((d.getElementById('wpcc-i18n') || {}).textContent || '{}');
    } catch (e) {
        i18n = {};
    }

    function placeholderFor(frame) {
        if (!frame.isConnected || frame.wpccPlaceholder) {
            return;
        }

        var template = d.getElementById('wpcc-placeholder');
        if (!template) {
            return;
        }

        var holder = template.content.firstElementChild.cloneNode(true);
        var category = frame.getAttribute('data-wpcc');
        var label = (i18n.categories && i18n.categories[category]) || category;
        var name = frame.getAttribute('data-wpcc-name') || '';
        var message = (i18n.blocked || '%s %s').replace('%s', name).replace('%s', String(label).toLowerCase());

        holder.querySelector('.wpcc-placeholder__text').textContent = message;
        holder.querySelector('.wpcc-placeholder__allow').addEventListener('click', function () {
            var choice = current();
            choice[category] = true;
            save(choice);
        });

        // Keep the embed's size so the layout doesn't jump when it loads
        var width = frame.getAttribute('width');
        var height = frame.getAttribute('height');
        if (width && height && !/%/.test(width + height)) {
            holder.style.aspectRatio = parseInt(width, 10) + ' / ' + parseInt(height, 10);
            holder.style.maxWidth = parseInt(width, 10) + 'px';
        }

        frame.wpccPlaceholder = holder;
        frame.parentNode.insertBefore(holder, frame);
    }

    function placeholders() {
        var frames = d.querySelectorAll('iframe[data-wpcc]');
        for (var i = 0; i < frames.length; i++) {
            placeholderFor(frames[i]);
        }
    }

    d.addEventListener('wpcc:blocked', function (event) {
        var frame = event.detail.element;
        // Added by a script that may not have attached it yet
        w.setTimeout(function () { placeholderFor(frame); }, 0);
    });

    d.addEventListener('wpcc:unblocked', function (event) {
        var frame = event.detail.element;
        if (frame.wpccPlaceholder) {
            frame.wpccPlaceholder.remove();
            frame.wpccPlaceholder = null;
        }
    });

    /* Saving ----------------------------------------------------------- */

    function current() {
        var saved = w.wpccConsent || {};
        var choice = {};
        CATEGORIES.forEach(function (name) {
            choice[name] = Boolean(saved[name]);
        });
        return choice;
    }

    function tellWpConsentApi(consent) {
        if (typeof w.wp_set_consent !== 'function') {
            return;
        }
        var allow = function (v) { return v ? 'allow' : 'deny'; };
        w.wp_set_consent('functional', 'allow');
        w.wp_set_consent('preferences', allow(consent.functional));
        w.wp_set_consent('statistics', allow(consent.analytics));
        w.wp_set_consent('statistics-anonymous', allow(consent.analytics));
        w.wp_set_consent('marketing', allow(consent.marketing));
    }

    var ui = {};

    function save(choice) {
        var previous = w.wpccConsent;
        var consent = { v: config.version, t: Date.now() };

        CATEGORIES.forEach(function (name) {
            // A category the site doesn't use is never granted
            consent[name] = config.categories.indexOf(name) > -1 && Boolean(choice[name]);
        });

        writeConsent(consent);
        w.wpccConsent = consent;

        if (typeof w.gtag === 'function') {
            w.gtag('consent', 'update', {
                analytics_storage: grant(consent.analytics),
                ad_storage: grant(consent.marketing),
                ad_user_data: grant(consent.marketing),
                ad_personalization: grant(consent.marketing),
                functionality_storage: grant(consent.functional),
                personalization_storage: grant(consent.functional)
            });
        }

        if (w.dataLayer) {
            w.dataLayer.push({
                event: 'consent_update',
                consent_functional: grant(consent.functional),
                consent_analytics: grant(consent.analytics),
                consent_marketing: grant(consent.marketing)
            });
        }

        tellWpConsentApi(consent);

        if (consent.analytics && w.wpccLoadGtag) {
            // Basic mode loads the plugin's GA4 tag only now; advanced already has
            w.wpccLoadGtag();
        }

        if (w.wpccUnblock) {
            w.wpccUnblock();
        }

        var withdrawn = CATEGORIES.filter(function (name) {
            return previous && previous[name] && !consent[name];
        });

        CATEGORIES.forEach(function (name) {
            if (!consent[name]) {
                deleteCookies(config.cookies[name]);
            }
        });

        d.dispatchEvent(new CustomEvent('wpcc:consent', {
            detail: { functional: consent.functional, analytics: consent.analytics, marketing: consent.marketing }
        }));

        if (ui.hideBanner) {
            ui.hideBanner();
        }

        // Trackers that already ran keep running until the page reloads
        if (withdrawn.length) {
            w.setTimeout(function () { w.location.reload(); }, 150);
        }
    }

    /* Banner and dialog -------------------------------------------------- */

    function init() {
        var banner = d.querySelector('.wpcc-banner');
        var dialog = d.getElementById('wpcc-dialog');
        var overlay = d.querySelector('.wpcc-overlay');

        placeholders();

        if (!banner || !dialog) {
            return;
        }

        var form = dialog.querySelector('form');
        var opener = null;
        var closeTimer = null;

        function showBanner() {
            banner.hidden = false;
            if (overlay) {
                overlay.hidden = false;
            }
            d.documentElement.classList.add('wpcc-has-banner');
            w.requestAnimationFrame(function () {
                w.requestAnimationFrame(function () {
                    banner.classList.add('is-visible');
                    if (overlay) {
                        overlay.classList.add('is-visible');
                    }
                });
            });
        }

        ui.hideBanner = function () {
            if (banner.hidden) {
                return;
            }
            banner.classList.remove('is-visible');
            banner.classList.add('is-leaving');
            if (overlay) {
                overlay.classList.remove('is-visible');
            }
            d.documentElement.classList.remove('wpcc-has-banner');
            afterExit(function () {
                banner.hidden = true;
                banner.classList.remove('is-leaving');
                if (overlay) {
                    overlay.hidden = true;
                }
            });
        };

        function openDialog(trigger) {
            var choice = current();
            CATEGORIES.forEach(function (name) {
                var field = form.elements.namedItem(name);
                if (field) {
                    field.checked = choice[name];
                }
            });

            w.clearTimeout(closeTimer);
            dialog.classList.remove('is-closing');
            opener = trigger;

            if (!dialog.open) {
                dialog.showModal();
            }
            w.requestAnimationFrame(function () { dialog.classList.add('is-open'); });
        }

        function closeDialog() {
            if (!dialog.open || dialog.classList.contains('is-closing')) {
                return;
            }
            dialog.classList.remove('is-open');
            dialog.classList.add('is-closing');
            closeTimer = afterExit(function () { dialog.close(); });
        }

        dialog.addEventListener('cancel', function (event) {
            event.preventDefault();
            closeDialog();
        });

        dialog.addEventListener('close', function () {
            w.clearTimeout(closeTimer);
            dialog.classList.remove('is-open', 'is-closing');
            if (opener && opener.isConnected && opener.offsetParent !== null) {
                opener.focus({ preventScroll: true });
            }
            opener = null;
        });

        // Browsers without closedby: a backdrop click lands on the dialog itself
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) {
                closeDialog();
            }
        });

        d.addEventListener('click', function (event) {
            var target = event.target;
            if (!target.closest) {
                return;
            }

            var settings = target.closest('.wpcc-open, .js-cookie-settings, a[href="#cookie-settings"]');
            if (settings) {
                event.preventDefault();
                openDialog(settings);
                return;
            }

            var actionEl = target.closest('[data-wpcc-action]');
            var action = actionEl && actionEl.getAttribute('data-wpcc-action');
            if (!action) {
                return;
            }

            var all = {};
            if (action === 'accept') {
                CATEGORIES.forEach(function (n) { all[n] = true; });
                save(all);
            } else if (action === 'reject') {
                save(all);
            } else if (action === 'save') {
                CATEGORIES.forEach(function (n) {
                    var field = form.elements.namedItem(n);
                    all[n] = Boolean(field && field.checked);
                });
                save(all);
            }

            closeDialog();
        });

        dialog.classList.add('wpcc-animated');

        // Public API for themes: wpccOpenPreferences()
        w.wpccOpenPreferences = function () { openDialog(null); };

        if (!w.wpccConsent) {
            w.setTimeout(showBanner, reducedMotion() ? 0 : BANNER_DELAY);
        } else {
            tellWpConsentApi(w.wpccConsent);
        }
    }

    if (d.readyState === 'loading') {
        d.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // A returning visitor's allowed scripts run straight away
    if (w.wpccConsent && w.wpccUnblock) {
        w.wpccUnblock();
    }
})(window, document);
