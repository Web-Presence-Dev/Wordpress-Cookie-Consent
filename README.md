# Web Presence Cookie Consent

A WordPress plugin that shows a cookie consent banner and **blocks GA4 and other tracking until the visitor agrees**. It recognises the common trackers automatically, includes Google Consent Mode v2, and has a site scanner for anything else. Updates come from this **private** repo's GitHub releases, through Composer or the plugin's own updater (see [Updates and releases](#updates-and-releases)).

## Quick start

1. **Install.** Composer sites: see [Installing](#installing). Otherwise, download `webpresence-cookie-consent.zip` from the [latest release](https://github.com/Web-Presence-Dev/Wordpress-Cookie-Consent/releases/latest) (you need access to the repo). In WordPress, go to **Plugins → Add New → Upload Plugin**, choose the zip, then **Install Now → Activate**. To get updates in the dashboard, also add a [GitHub access token](#access-token-for-dashboard-updates).
2. **Set up Google.** Open **Cookie Consent** in the admin menu. On **General**:
   - Add your GA4 Measurement ID if you want the plugin to add GA4. Leave it empty if GA4 is added another way.
   - Keep Google mode on **Basic**.
   - Choose your cookie policy page.
   - Save.
3. **Match the brand.** On **Appearance**, pick the banner position, colours and font, and check the wording. The preview updates as you go. Save.
4. **Find everything else.** On **Scanner**, click **Scan site**. Anything marked **Unknown** needs a category (so it's blocked) or **Necessary** (so it isn't).
5. **Let visitors change their mind.** Add `[cookie_settings]` to the footer or the cookie policy page. You can also add a menu link to `#cookie-settings`, or switch on the floating button under Appearance.
6. **Check it.** Open the site in a private window. The banner shows, and nothing from Google, Meta and so on loads until you accept (see [Checking it works](#checking-it-works)).

That's it. Known trackers are blocked automatically, and updates show on the Plugins screen when a new release is published.

## Contents

- [Quick start](#quick-start)
- [Requirements](#requirements)
- [Installing](#installing)
- [Settings](#settings)
- [Opening the preferences: shortcode and classes](#opening-the-preferences)
- [Styling: using your theme's buttons and CSS](#styling-using-your-themes-buttons-and-css)
- [How blocking works](#how-blocking-works)
- [The scanner](#the-scanner)
- [For developers: events, filters and the JS API](#for-developers)
- [Updates and releases](#updates-and-releases)
- [Local development](#local-development)
- [Moving a site off the webpresence theme's banner](#moving-a-site-off-the-webpresence-themes-banner)

## Requirements

- WordPress 6.5+
- PHP 8.1+

No other plugins are needed. ACF isn't required.

## Installing

The repo is **private**, so every install route needs GitHub access: your own login to download a zip, or a read-only [access token](#creating-the-access-token) for servers.

### With Composer (the webpresence site, and any site deployed from Git)

Use this for sites where `DISALLOW_FILE_MODS` is on (Tesla sets it on production), because WordPress can't install updates on those.

1. Add the repo and the package to the site's `composer.json`:

   ```json
   "repositories": {
       "cookie-consent": {
           "type": "vcs",
           "url": "https://github.com/Web-Presence-Dev/Wordpress-Cookie-Consent"
       }
   },
   "require": {
       "web-presence-dev/wordpress-cookie-consent": "^0.1"
   }
   ```

   The webpresence site already has a `repositories` object (Satispress, WP Packagist); add `cookie-consent` beside those. The package installs to `wp-content/plugins/webpresence-cookie-consent/`, which that site's `.gitignore` already ignores.
2. Give Composer the token. On each machine that runs `composer install` (the Forge server, CI, your laptop):

   ```bash
   composer config --global --auth github-oauth.github.com github_pat_…
   ```

   That writes it to Composer's own `auth.json`, the same file Satispress's credentials live in on the server. Never commit it.
3. `composer update web-presence-dev/wordpress-cookie-consent`, commit `composer.lock`, and deploy as usual.

**Getting an update onto the site:** after a new `vX.Y.Z` tag is released, run `composer update web-presence-dev/wordpress-cookie-consent` locally, commit `composer.lock` and deploy. The deploy script's `composer install` picks up the new version. With `^0.1` you get every `0.1.x`; change the constraint to move to `0.2`.

> **Local development:** if the plugin is symlinked into the local site (see [Local development](#local-development)), `composer install` there replaces the link with the released copy, so you'd stop seeing your edits. Re-create the link after running Composer locally.

### From a zip (sites managed in the dashboard)

1. Download `webpresence-cookie-consent.zip` from [Releases](https://github.com/Web-Presence-Dev/Wordpress-Cookie-Consent/releases) (signed in to GitHub with access to the repo).
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, upload it and activate it.
3. Add an [access token](#access-token-for-dashboard-updates) so the site can see and install updates.

## Settings

The **Cookie Consent** menu has six tabs.

### General

| Setting | What it does |
| --- | --- |
| Show the banner and block tracking | Master switch. When off, nothing is blocked and no banner shows. |
| GA4 Measurement ID | Optional. Set it to let the plugin add GA4 itself. Leave it empty if GA4 is added another way (Site Kit, GTM, the theme). It's blocked either way. |
| Google mode | **Basic** (default): Google tags don't load at all until consent. **Advanced**: Google tags load but set no cookies until consent, and send cookieless pings for modelling. |
| Remember a choice for | Days before the banner asks again (max 395; 365 is the usual). |
| Cookie policy page / URL | Linked from the banner and dialog. Falls back to the privacy policy page. |
| Ask everyone again | Bumps the consent version, so every visitor sees the banner again. Do this after adding new tracking. |

### Appearance

Everything here is applied as CSS custom properties, so there's no theme CSS to edit.

- **Position**: card bottom-left, card bottom-right, full-width bar, or centred pop-up.
- **Floating button**: an optional round "Cookie settings" button shown after a choice is made.
- **Font**: same as the theme (default), the system font, or a custom family. A custom family must be a font the theme already loads; the plugin doesn't load fonts.
- **Text size** and **corner radius** for the banner and pop-up.
- **Buttons**: the plugin's own style (colours below, plus a corner setting from square to pill), or **Use my theme's buttons** with your theme's classes. See [Styling](#styling-using-your-themes-buttons-and-css).
- **Colours**: background, text, links, main buttons, outline buttons, the switch's "on" colour and the dialog backdrop. Optionally set a second **dark mode** palette, used when the visitor's device is in dark mode.
- **Wording**: every piece of text on the banner, dialog and blocked-embed placeholders.

A live preview on the right updates as you change things, before you save. It's rendered by the site's theme, so fonts and theme buttons look exactly as they will on the site. Use its Light/Dark buttons to check both palettes.

### Categories & services

- **Categories**: Functional, Analytics and Marketing, each with an editable label and description. "Strictly necessary" is always on. If you switch a category off, it disappears from the banner and its services are blocked under the strictest category that's left.
- **Known services**: everything the plugin recognises. You can change the category a service is blocked under, or choose **Don't block** for one the site needs to work.
- **Custom rules**: block anything whose script URL, embed URL or inline code contains some text, e.g. `cdn.some-tracker.com`.

### Scanner

See [The scanner](#the-scanner).

### Tools

**Copy settings to another site.** **Export settings** downloads a `.json` file of every setting: appearance, colours, wording, categories, services and custom rules. **Import settings** applies that file on another site, so it's set up the same way.

- Every value goes through the same checks as the settings forms.
- The cookie policy page is matched by its address (e.g. `/cookies/`). If there's no such page, you're told to choose one on the General tab.
- The site's consent version is kept, so visitors aren't asked again.

### Updates

Shows the installed and latest versions and the release notes. **Check for updates now** clears the 12-hour cache.

## Opening the preferences

Visitors must be able to change their mind at any time, so put a way to reopen the preferences somewhere on every page. The footer is the usual place.

### Shortcode

```text
[cookie_settings]
```

Attributes:

| Attribute | Default | |
| --- | --- | --- |
| `text` | "Cookie settings" (from Appearance → Wording) | Button text |
| `type` | `button` | `link` makes it look like a text link |
| `class` | — | Your own classes. When set, the plugin's button styles are left off, so the theme can style it |

Examples:

```text
[cookie_settings]
[cookie_settings type="link" text="Change cookie settings"]
[cookie_settings class="footer__link"]
```

In a PHP template: `<?php echo do_shortcode('[cookie_settings class="footer__link"]'); ?>`

`[wpcc_preferences]` is an alias.

### Without the shortcode

Any of these opens the dialog too:

- a link to `#cookie-settings`, which works in WordPress menus (add a Custom Link with URL `#cookie-settings`)
- any element with the class `wpcc-open`
- any element with the class `js-cookie-settings`, which keeps the webpresence theme's existing footer button working
- `window.wpccOpenPreferences()` from JavaScript

## Styling: using your theme's buttons and CSS

Most sites only need **Appearance** in the settings. When the banner has to match a theme exactly, there are three levels, from simplest to most flexible.

### 1. Use your theme's button classes (no code)

**Cookie Consent → Appearance → Buttons → Use my theme's buttons**, then enter the classes your theme uses:

| Setting | Example |
| --- | --- |
| Main button classes | `button` (or `btn btn-primary`) |
| Outline button classes | `button button--outline` |

The plugin then drops its own button styles: the Buttons colours and the button-corner setting are hidden and ignored. Main buttons are Accept all, Reject all, Save choices, "Allow and load" and the `[cookie_settings]` button; outline buttons are Accept all and Reject all in the preferences pop-up.

The settings preview is rendered by your theme (it's a small front-end page shown in a frame), so it shows the buttons, fonts and colours exactly as visitors will see them.

### 2. Replace the button markup (PHP filter)

Some theme buttons need more than a class, such as an icon or a label wrapper. The `wpcc_button_html` filter lets the theme supply the whole button:

```php
add_filter('wpcc_button_html', function (string $html, array $args): string {
    // $args: action, label, variant ('primary' | 'secondary'), attributes, class
    return sprintf(
        '<button %s class="%s">%s</button>',
        $args['attributes'], // required: type="button" and data-wpcc-action
        $args['class'],      // required: the plugin's classes + your setting's classes
        esc_html($args['label'])
    );
}, 10, 2);
```

Keep `$args['attributes']` and `$args['class']` on the element. The plugin's script uses them to find each button, and without them the button stops working. `$args['label']` isn't escaped, so escape it.

#### Example: the Web Presence theme

The webpresence theme's buttons are `.button` / `.button--outline`, with a label span and an arrow icon. To use them:

1. Settings: switch on **Use my theme's buttons**, with main classes `button` and outline classes `button button--outline`.
2. In the theme, e.g. `lib/Theme/consent.php` (which replaces the old built-in banner code):

   ```php
   use Turing\Theme\Markup;

   add_filter('wpcc_button_html', function (string $html, array $args): string {
       return sprintf(
           '<button %s class="%s"><span class="button__label">%s</span><span class="button__icon">%s</span></button>',
           $args['attributes'],
           $args['class'],
           esc_html($args['label']),
           Markup::svg('arrow')
       );
   }, 10, 2);
   ```

This is the same markup the theme's own `Markup::button()` produces, so the banner's buttons pick up the theme's hover animation and arrow.

### 3. CSS overrides

The theme's stylesheet can restyle anything. The plugin's selectors:

| Part | Selector |
| --- | --- |
| Banner | `.wpcc-banner` (plus `.wpcc-banner--card-left`, `--card-right`, `--bar`, `--modal`) |
| Preferences pop-up | `.wpcc-dialog`, inner `.wpcc-dialog__inner` |
| Plugin buttons | `.wpcc-button`, `.wpcc-button--primary`, `.wpcc-button--secondary` |
| Theme buttons (setting on) | `.wpcc-btn`, `.wpcc-btn--primary`, `.wpcc-btn--secondary` |
| "Manage preferences" link | `.wpcc-link` |
| Category switches | `.wpcc-switch` |
| Floating button | `.wpcc-floating` |
| Blocked-embed placeholder | `.wpcc-placeholder` |

The settings are CSS custom properties, so the theme can also set them directly, e.g. to follow its own dark mode:

```css
:root {
    --wpcc-primary-bg: var(--color-orange);
    --wpcc-primary-text: #fff;
    --wpcc-button-radius: 999px;
}

[data-theme="dark"] {
    --wpcc-bg: var(--color-navy);
    --wpcc-text: var(--color-cream);
}
```

The full list: `--wpcc-bg`, `--wpcc-text`, `--wpcc-link`, `--wpcc-primary-bg`, `--wpcc-primary-text`, `--wpcc-primary-hover-bg`, `--wpcc-primary-hover-text`, `--wpcc-secondary-bg`, `--wpcc-secondary-text`, `--wpcc-secondary-border`, `--wpcc-dialog-bg`, `--wpcc-dialog-text`, `--wpcc-dialog-link`, `--wpcc-switch-on`, `--wpcc-backdrop`, `--wpcc-floating-bg`, `--wpcc-floating-icon`, `--wpcc-font`, `--wpcc-font-size`, `--wpcc-radius`, `--wpcc-button-radius`.

The plugin prints its values on `:root` in the page `<head>`. To win over them, use a more specific selector (`html:root`, or a class on `<html>`), or load your CSS after the plugin's.

## How blocking works

1. **In the HTML (server).** An output buffer rewrites matching tags before the page is sent:
   - `<script>` tags (matched by `src` or inline code) get `type="text/plain"` so they don't run.
   - Matching `<iframe>` and tracking-pixel `<img>` tags have their `src` moved to `data-wpcc-src`.

   Every visitor gets the same HTML, so **full-page caching is safe**.
2. **In the browser.** A small script, printed first in `<head>`, catches trackers other scripts add later, e.g. Tag Manager loading the Meta Pixel. It wraps `document.createElement` and `appendChild`/`insertBefore`, and backs that up with a `MutationObserver`.
3. **On consent.** Blocked scripts are re-created in their original order, and embeds get their `src` back. No reload is needed.
4. **On withdrawal.** That category's known cookies are deleted, and the page reloads so running trackers stop.

**Google Consent Mode v2** defaults (everything denied) are always set before any Google tag. A GA4 tag added by another plugin still starts denied, and updates when the visitor chooses.

Blocked YouTube, Vimeo and Maps embeds show a placeholder with an **Allow and load** button.

**Exclude a tag** from blocking by adding `data-wpcc-ignore`:

```html
<script data-wpcc-ignore src="https://example.com/needed.js"></script>
```

### Recognised automatically

Google Analytics (GA4), Google Tag Manager, Google Ads & DoubleClick, Meta Pixel, LinkedIn Insight, TikTok Pixel, Microsoft Clarity, Microsoft Ads (UET), Hotjar, Pinterest, X (Twitter) Pixel, Snap Pixel, HubSpot, Matomo Cloud, YouTube, Vimeo and Google Maps.

The list lives in [data/services.json](data/services.json). Each entry has the script/embed URL fragments (`src`), inline code fragments (`inline`) and cookie names (`cookies`, where `*` is a wildcard).

## The scanner

**Cookie Consent → Scanner → Scan site** loads the home page and a couple of recent items of each post type with blocking switched off. It works in two passes:

1. It reads each page's HTML for third-party scripts, embeds, pixels and inline tracker code.
2. It loads each page in a hidden frame for a few seconds, to record the cookies set and the hosts contacted.

Each result is marked:

- **Blocked until consent**: a rule covers it.
- **Allowed by settings**: known, but set to "Don't block", or Google in advanced mode.
- **Necessary**: an admin marked it as needed.
- **Unknown**: needs a decision. Pick a category to create a custom rule and block it, or mark it necessary.

The scan runs trackers in your own browser, then deletes the cookies they set.

It uses a 10-minute token that only an admin can create. Pages fetched with that token skip the plugin, so the raw tags show.

## For developers

### Events

```js
// Fires after every choice
document.addEventListener('wpcc:consent', (e) => {
    console.log(e.detail); // { functional: true, analytics: false, marketing: false }
});
```

Each choice also pushes this to the `dataLayer`, for Tag Manager triggers:

```js
{ event: 'consent_update', consent_functional: 'granted', consent_analytics: 'denied', consent_marketing: 'denied' }
```

`window.wpccConsent` holds the current choice, or `null` before one is made.

The choice is stored in the `wpcc_consent` first-party cookie as JSON: `{ v, t, functional, analytics, marketing }`.

When the [WP Consent API](https://wordpress.org/plugins/wp-consent-api/) plugin is active, every choice is passed to `wp_set_consent()` too.

### PHP filters

| Filter | Use |
| --- | --- |
| `wpcc_enabled` | `bool`. Turn the plugin off for a request, e.g. a page builder's preview. |
| `wpcc_services` | `array`. Add, remove or change the services being blocked. |
| `wpcc_buffer_output` | `bool`. Turn off server-side HTML rewriting (the browser blocker still runs). |
| `wpcc_scan_urls` | `array`. The URLs the scanner visits. |

```php
add_filter('wpcc_services', function (array $services) {
    $services[] = [
        'id' => 'acme-analytics',
        'name' => 'Acme Analytics',
        'category' => 'analytics',
        'src' => ['cdn.acme-analytics.com'],
        'inline' => ['acme.track('],
        'cookies' => ['_acme*'],
    ];
    return $services;
});
```

## Updates and releases

New versions are published as **GitHub releases** on this private repo. Sites get them one of two ways:

| Site | How it updates |
| --- | --- |
| Deployed with Composer (the webpresence site) | `composer update`, commit the lock file, deploy. See [With Composer](#with-composer-the-webpresence-site-and-any-site-deployed-from-git). |
| Managed in the dashboard | The update shows on the Plugins screen like any other plugin; auto-updates can be switched on. Needs an access token, below. |

The plugin header has `Update URI: https://github.com/Web-Presence-Dev/Wordpress-Cookie-Consent`. Because of that, WordPress asks only this plugin's updater about updates, never wordpress.org.

### Access token for dashboard updates

Add the token to the site's `wp-config.php`:

```php
define('WPCC_GITHUB_TOKEN', 'github_pat_…');
```

Or put `WPCC_GITHUB_TOKEN="github_pat_…"` in the site's `.env`, if it loads one (the webpresence site does).

The token is only read from there. It's never saved in the database, shown in the admin, or sent anywhere but `api.github.com`. **Cookie Consent → Updates** shows whether it's set, and explains why a check failed (no token, token refused, no releases yet).

How the download works: the updater asks the GitHub API for the release zip with the token. GitHub replies with a short-lived signed link, which is downloaded without the token.

### Creating the access token

1. On GitHub: **Settings → Developer settings → Personal access tokens → Fine-grained tokens → Generate new token**.
2. **Resource owner:** Web-Presence-Dev (the organisation may need to approve it).
3. **Repository access:** *Only select repositories* → `Wordpress-Cookie-Consent`.
4. **Permissions:** Repository → **Contents: Read-only**. Nothing else.
5. Pick an expiry, and note the date: when the token expires, updates stop until it's replaced. The Updates tab shows "GitHub refused the access token" when that happens.

One token can be shared by all your sites and servers. It can only read this one repo.

### Releasing a new version

1. Update the version in **three** places: the `Version:` header and `WPCC_VERSION` in `webpresence-cookie-consent.php`, and `Stable tag:` in `readme.txt`.
2. Add a `= x.y.z =` section to the changelog in `readme.txt`.
3. Commit, then tag and push:

   ```bash
   git tag v0.2.0
   git push origin main --tags
   ```
4. The [Release workflow](.github/workflows/release.yml) then:
   - checks that the three versions match the tag
   - lints the PHP
   - builds `webpresence-cookie-consent.zip` (leaving out everything in `.distignore`)
   - publishes the GitHub release, with that version's changelog as the notes

Sites see the update within 12 hours, or straight away with **Cookie Consent → Updates → Check for updates now**.

> **`DISALLOW_FILE_MODS`** (set by the Tesla mu-plugin on production) stops WordPress from installing any update from the dashboard, token or not. Update those sites [with Composer](#with-composer-the-webpresence-site-and-any-site-deployed-from-git).

## Local development

Develop against the local **webpresence** site, which uses this repo directly through a symlink. Every edit here is live on refresh, with no copying or build step.

```bash
# Once: link the repo into the local site's plugins folder
ln -s "/home/darren/Dev/Plugins/Cookie Consent" \
      /home/darren/Dev/Research/figma-mcp/wp-content/plugins/webpresence-cookie-consent

# Run the site
cd /home/darren/Dev/Research/figma-mcp && php -S localhost:8000 router.php
```

Then activate **Web Presence Cookie Consent** at <http://localhost:8000/wordpress/wp-admin/plugins.php>.

- The webpresence repo's `.gitignore` already ignores `wp-content/plugins/`, so the link is never committed there.
- No theme changes are needed. While the plugin is active it switches off the theme's own GA4 banner (through the theme's `webpresence_consent_enabled` filter), so the two can't clash.
- **Updates are disabled for development copies.** A symlinked folder or Git checkout is never offered an update, so WordPress can't overwrite your working copy. The Updates tab says so. To test the updater itself, install a release zip as a normal plugin on a test site, or define `WPCC_DEV_ALLOW_UPDATES` as `true` on a throwaway copy.
- To see the banner as a new visitor again, delete the `wpcc_consent` cookie or use a private window.

### Checking it works

1. In a private window, open the site with DevTools → Network.
2. Before choosing, there should be no requests to `google-analytics.com`, `googletagmanager.com` (in basic mode), `facebook.net` and so on. `document.cookie` should have no `_ga` or `_fbp`.
3. View source: tracker scripts show as `type="text/plain" data-wpcc="analytics"`.
4. Accept analytics → GA loads and `_ga` appears. Reopen the preferences and switch it off → `_ga` is deleted and the page reloads.

## Moving a site off the webpresence theme's banner

The plugin replaces the theme's built-in consent code. When the plugin is active, the theme's version is switched off automatically. On activation, the plugin also copies the theme's **Cookies & Analytics** settings across (GA4 ID, mode, banner text, policy link).

To tidy up, remove from the webpresence theme:

- `lib/Theme/consent.php`, and its `require` in `functions.php`
- `components/modules/cookie-consent.php`, and its `get_template_part` in `header.php`
- `assets/js/modules/cookie-consent.js`, and its import and `initCookieConsent()` call in `main.js`
- `assets/scss/components/_cookie-consent.scss`
- the "Options: Cookies & Analytics" ACF field group

The footer's "Cookie settings" button (`.js-cookie-settings`) can stay, because the plugin opens its dialog. Its `Consent::is_enabled()` check must go when `consent.php` is removed, or swap the button for `[cookie_settings class="footer__cookie-settings"]`.
