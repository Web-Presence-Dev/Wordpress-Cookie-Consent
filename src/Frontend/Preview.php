<?php

namespace WebPresence\CookieConsent\Frontend;

use WebPresence\CookieConsent\Settings;

defined('ABSPATH') || exit;

/**
 * The settings page's live preview, as a real front-end page.
 *
 * The Appearance tab shows this in an iframe, so the banner is rendered by
 * the site's own theme: its CSS, fonts and (with "Use my theme's buttons")
 * its button markup, exactly as visitors see them. The page holds only the
 * banner, the preferences pop-up and the floating button, laid out side by
 * side (assets/css/preview.css). The admin page updates it live through the
 * frame (same origin), so unsaved changes show straight away.
 *
 * Opened with a one-off key from the settings page: /?wpcc_preview=<key>.
 * The key is checked instead of the login, because some sites don't see the
 * admin's login on the front end (admin and front end on different
 * addresses, or a cache or proxy that drops the login cookie). The page only
 * shows the banner with the site's own settings, and the plugin's own scripts
 * don't run here, so nothing is saved or unblocked.
 */
class Preview
{
    public const QUERY = 'wpcc_preview';

    private const KEY_TTL = 2 * HOUR_IN_SECONDS;

    public function __construct()
    {
        if (!self::is_request()) {
            return;
        }

        // Decided before the admin bar is set up (init)
        add_filter('show_admin_bar', '__return_false');
        add_action('template_redirect', [$this, 'render'], 0);
    }

    public static function is_request(): bool
    {
        return isset($_GET[self::QUERY]) && !is_admin(); // phpcs:ignore WordPress.Security.NonceVerification
    }

    /**
     * A preview address with a fresh key, for the settings page (admins only).
     */
    public static function url(): string
    {
        $key = strtolower(wp_generate_password(32, false, false));
        set_transient('wpcc_preview_' . $key, get_current_user_id(), self::KEY_TTL);

        return add_query_arg(self::QUERY, $key, home_url('/'));
    }

    public function render(): void
    {
        $key = sanitize_key(wp_unslash($_GET[self::QUERY] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification

        if (32 !== strlen($key) || false === get_transient('wpcc_preview_' . $key)) {
            wp_die(
                esc_html__('This preview has expired. Reload the Cookie Consent settings page to see it again.', 'wpcc'),
                esc_html__('Preview expired', 'wpcc'),
                ['response' => 403]
            );
        }

        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow');

        add_action('wp_enqueue_scripts', static function (): void {
            wp_enqueue_style('wpcc-banner', WPCC_URL . 'assets/css/banner.css', [], \WebPresence\CookieConsent\Plugin::asset_version('assets/css/banner.css'));
            wp_enqueue_style('wpcc-preview', WPCC_URL . 'assets/css/preview.css', ['wpcc-banner'], \WebPresence\CookieConsent\Plugin::asset_version('assets/css/preview.css'));
        }, 99);

        $s = Settings::all();
        ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <?php wp_head(); ?>
    <style id="wpcc-preview-vars"><?php echo str_replace(':root', '.wpcc-preview', HeadScript::css_vars(array_merge($s, ['dark_enabled' => false]))); // phpcs:ignore -- sanitised values ?></style>
</head>
<body <?php body_class('wpcc-preview-body'); ?>>
    <div class="wpcc-preview" data-mode="light">
        <?php echo self::markup($s); // phpcs:ignore -- escaped in Banner::markup ?>
    </div>
    <?php wp_footer(); ?>
</body>
</html>
        <?php
        exit;
    }

    /**
     * The banner markup, adjusted to sit statically on the preview page.
     */
    public static function markup(array $s): string
    {
        // Always render the floating button; the admin shows it when its setting is on
        $html = Banner::markup(array_merge($s, ['floating_button' => true]), true);

        // Show one switch on, so the "on" colours can be seen
        $html = preg_replace('/<input class="wpcc-switch"/', '<input class="wpcc-switch" checked', $html, 1);

        $html = str_replace(
            '<button type="button" class="wpcc-floating wpcc-open"',
            '<p class="wpcc-preview__label"' . ($s['floating_button'] ? '' : ' hidden') . '>' . esc_html__('Floating button, shown after a choice:', 'wpcc') . '</p>'
            . '<button type="button" class="wpcc-floating wpcc-open"' . ($s['floating_button'] ? '' : ' hidden'),
            $html
        );

        // A static, non-modal dialog; closedby="any" would light-dismiss it on any click
        $html = str_replace('<dialog class="wpcc-dialog"', '<dialog open class="wpcc-dialog"', $html);
        $html = str_replace(' closedby="any"', '', $html);
        $html = str_replace(['<form class="wpcc-dialog__inner" method="dialog">', '</form>'], ['<div class="wpcc-dialog__inner">', '</div>'], $html);

        // Preview switches aren't settings
        $html = preg_replace('/(<input class="wpcc-switch"[^>]*?) name="[^"]*"/', '$1', $html);

        return str_replace(['id="wpcc-root"', 'id="wpcc-placeholder"', 'id="wpcc-i18n"'], '', $html);
    }
}
