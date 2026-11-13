<?php

namespace WebPresence\CookieConsent;

use WebPresence\CookieConsent\Admin\Scanner;
use WebPresence\CookieConsent\Admin\SettingsPage;
use WebPresence\CookieConsent\Frontend\Banner;
use WebPresence\CookieConsent\Frontend\Blocker;
use WebPresence\CookieConsent\Frontend\HeadScript;
use WebPresence\CookieConsent\Frontend\Preview;
use WebPresence\CookieConsent\Integrations\WpConsentApi;
use WebPresence\CookieConsent\Updater\GitHubUpdater;

defined('ABSPATH') || exit;

final class Plugin
{
    // First-party cookie holding the visitor's choice (strictly necessary)
    public const CONSENT_COOKIE = 'wpcc_consent';

    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        add_action('init', static function (): void {
            load_plugin_textdomain('wpcc', false, dirname(WPCC_BASENAME) . '/languages');
        });

        add_action('init', [$this, 'register_setting']);

        // The webpresence theme ships its own GA4 banner; this plugin replaces it
        add_filter('webpresence_consent_enabled', '__return_false', 99);

        new Preview();
        new HeadScript();
        new Blocker();
        new Banner();
        new WpConsentApi();
        new GitHubUpdater();
        new Scanner();

        if (is_admin()) {
            new SettingsPage();
        }
    }

    /**
     * Version string for an asset: the plugin version, plus the file's
     * modified time so edits always load fresh (local development).
     */
    public static function asset_version(string $path): string
    {
        $time = @filemtime(WPCC_DIR . $path); // phpcs:ignore

        return WPCC_VERSION . ($time ? '.' . $time : '');
    }

    public function register_setting(): void
    {
        register_setting('wpcc', Settings::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [Settings::class, 'sanitize'],
            'show_in_rest' => false,
            'default' => [],
        ]);
    }

    /**
     * Whether the banner, head script and blocker run on this request.
     */
    public static function is_active_request(): bool
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST) || (defined('WP_CLI') && WP_CLI)) {
            return false;
        }

        // The scanner's page loads and the settings preview page run without it
        if (Scanner::is_scan_request() || Preview::is_request()) {
            return false;
        }

        /**
         * Turn the plugin off for a request, e.g. on a landing page builder's preview.
         */
        return (bool) apply_filters('wpcc_enabled', (bool) Settings::get('enabled'));
    }
}
