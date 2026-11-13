=== Web Presence Cookie Consent ===
Contributors: webpresence
Tags: cookies, consent, gdpr, google consent mode, analytics
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.1.1
License: GPLv2 or later

Cookie consent banner that finds and blocks GA4 and other tracking until visitors agree. Google Consent Mode v2 included.

== Description ==

* Blocks Google Analytics, Tag Manager, Google Ads, Meta Pixel, LinkedIn, TikTok, Clarity, Hotjar, YouTube and more until the visitor consents.
* Google Consent Mode v2 (basic or advanced).
* Site scanner that finds third-party scripts, embeds and cookies.
* Customisable banner: position, colours, font and wording.
* `[cookie_settings]` shortcode to reopen the preferences.
* Updates from GitHub releases.

== Changelog ==

= 0.1.1 =
* New: Tools tab with Export settings and Import settings, to copy every Cookie Consent setting (appearance, colours, wording, categories, services and custom rules) to another site in one step. The cookie policy page is matched by its address, and visitors aren't asked for consent again.
* Fixed: the settings preview showed "This preview is only available from the Cookie Consent settings" on sites where the front end doesn't see the admin login, e.g. when the admin and front end are on different addresses or a cache drops the login cookie. It now opens with a one-off preview key instead.
* Improved: if the front end is on a different address from the admin, the preview shows the saved settings and says why live edits can't show.

= 0.1.0 =
* First release.
