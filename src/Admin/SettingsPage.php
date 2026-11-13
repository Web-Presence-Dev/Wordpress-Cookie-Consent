<?php

namespace WebPresence\CookieConsent\Admin;

use WebPresence\CookieConsent\Frontend\Banner;
use WebPresence\CookieConsent\Frontend\HeadScript;
use WebPresence\CookieConsent\Services\Registry;
use WebPresence\CookieConsent\Plugin;
use WebPresence\CookieConsent\Settings;
use WebPresence\CookieConsent\Updater\GitHubUpdater;

defined('ABSPATH') || exit;

/**
 * Settings > Cookie Consent: the Web Presence branded settings page.
 */
class SettingsPage
{
    public const SLUG = 'wpcc';

    // Tabs with a settings form (and so a Save button in the header)
    private const FORM_TABS = ['general', 'appearance', 'categories'];

    private string $hook = '';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('admin_post_wpcc_check_updates', [$this, 'check_updates']);
        add_filter('plugin_action_links_' . WPCC_BASENAME, [$this, 'action_links']);
    }

    public static function tabs(): array
    {
        return [
            'general' => __('General', 'wpcc'),
            'appearance' => __('Appearance', 'wpcc'),
            'categories' => __('Categories & services', 'wpcc'),
            'scanner' => __('Scanner', 'wpcc'),
            'updates' => __('Updates', 'wpcc'),
        ];
    }

    public static function url(string $tab = 'general'): string
    {
        return admin_url('admin.php?page=' . self::SLUG . '&tab=' . $tab);
    }

    public function menu(): void
    {
        $icon = 'data:image/svg+xml;base64,' . base64_encode((string) file_get_contents(WPCC_DIR . 'assets/img/menu-icon.svg')); // phpcs:ignore

        $this->hook = (string) add_menu_page(
            __('Cookie Consent', 'wpcc'),
            __('Cookie Consent', 'wpcc'),
            'manage_options',
            self::SLUG,
            [$this, 'render'],
            $icon,
            81
        );
    }

    public function action_links(array $links): array
    {
        array_unshift($links, '<a href="' . esc_url(self::url()) . '">' . esc_html__('Settings', 'wpcc') . '</a>');

        return $links;
    }

    public function enqueue(string $hook): void
    {
        if ($hook !== $this->hook) {
            return;
        }

        $tab = self::current_tab();

        wp_enqueue_style('wpcc-admin', WPCC_URL . 'assets/admin/admin.css', [], Plugin::asset_version('assets/admin/admin.css'));

        if ('appearance' === $tab) {
            wp_enqueue_style('wp-color-picker');
            wp_enqueue_style('wpcc-banner', WPCC_URL . 'assets/css/banner.css', [], Plugin::asset_version('assets/css/banner.css'));
        }

        // Every tab: pinned header, page search, unsaved-changes guard, preview
        $deps = ['jquery', 'wp-api-fetch'];
        if ('appearance' === $tab) {
            $deps[] = 'wp-color-picker';
        }
        wp_enqueue_script('wpcc-admin', WPCC_URL . 'assets/admin/admin.js', $deps, Plugin::asset_version('assets/admin/admin.js'), true);

        if ('scanner' === $tab) {
            Scanner::refresh_saved_result();
            wp_enqueue_script('wpcc-scanner', WPCC_URL . 'assets/admin/scanner.js', ['wp-api-fetch'], Plugin::asset_version('assets/admin/scanner.js'), true);
            wp_add_inline_script('wpcc-scanner', 'window.wpccScan=' . wp_json_encode([
                'result' => get_option(Scanner::RESULT_OPTION, null),
                'categories' => self::category_labels(),
                'cookies' => array_values(array_unique(array_merge(...array_map(static fn ($s) => $s['cookies'], Registry::catalogue())))),
                'i18n' => [
                    'scanning' => __('Scanning %1$d of %2$d: %3$s', 'wpcc'),
                    'saving' => __('Identifying what was found…', 'wpcc'),
                    'done' => __('Scan finished.', 'wpcc'),
                    'error' => __('The scan failed: %s', 'wpcc'),
                    'none' => __('No third-party scripts, embeds or cookies were found.', 'wpcc'),
                    'lastScan' => __('Last scan: %s', 'wpcc'),
                    'status' => [
                        'blocked' => __('Blocked until consent', 'wpcc'),
                        'allowed' => __('Allowed by settings', 'wpcc'),
                        'necessary' => __('Necessary', 'wpcc'),
                        'essential' => __('Functional, not blocked', 'wpcc'),
                        'unknown' => __('Unknown', 'wpcc'),
                    ],
                    'types' => [
                        'script' => __('Script', 'wpcc'),
                        'inline' => __('Inline code', 'wpcc'),
                        'iframe' => __('Embed', 'wpcc'),
                        'img' => __('Pixel', 'wpcc'),
                        'request' => __('Request', 'wpcc'),
                        'cookie' => __('Cookie', 'wpcc'),
                    ],
                    'choose' => __('Choose…', 'wpcc'),
                    'necessary' => __('Necessary (don\'t block)', 'wpcc'),
                    'saved' => __('Saved', 'wpcc'),
                ],
            ]) . ';', 'before');
        }
    }

    public static function current_tab(): string
    {
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification

        return array_key_exists($tab, self::tabs()) ? $tab : 'general';
    }

    public static function category_labels(): array
    {
        $categories = Settings::get('categories');
        $labels = [];

        foreach (Settings::CATEGORIES as $name) {
            $labels[$name] = $categories[$name]['label'] ?: ucfirst($name);
        }

        return $labels;
    }

    public function check_updates(): void
    {
        check_admin_referer('wpcc_check_updates');

        if (!current_user_can('update_plugins') && !current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to do that.', 'wpcc'));
        }

        GitHubUpdater::flush();
        delete_site_transient('update_plugins');
        wp_update_plugins();

        wp_safe_redirect(add_query_arg('checked', '1', self::url('updates')));
        exit;
    }

    /* -------------------------------------------------------------------------
     * Page
     * ---------------------------------------------------------------------- */

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $tab = self::current_tab();
        $logo = (string) file_get_contents(WPCC_DIR . 'assets/img/logo.svg');
        ?>
        <div class="wrap wpcc-admin">
            <div class="wpcc-admin__top">
            <header class="wpcc-admin__header">
                <a class="wpcc-admin__brand" href="https://webpresence.digital/" target="_blank" rel="noopener">
                    <?php echo $logo; // phpcs:ignore -- our own file ?>
                    <span class="screen-reader-text"><?php esc_html_e('Web Presence (opens in a new tab)', 'wpcc'); ?></span>
                </a>
                <div class="wpcc-admin__title">
                    <h1><?php esc_html_e('Cookie Consent', 'wpcc'); ?></h1>
                    <span class="wpcc-admin__version">v<?php echo esc_html(WPCC_VERSION); ?></span>
                </div>
                <span class="wpcc-admin__status <?php echo Settings::get('enabled') ? 'is-on' : 'is-off'; ?>">
                    <?php echo Settings::get('enabled') ? esc_html__('Active on site', 'wpcc') : esc_html__('Switched off', 'wpcc'); ?>
                </span>
                <?php if (in_array($tab, self::FORM_TABS, true)) : ?>
                    <?php /* Submits the tab's form (form="wpcc-form") from outside it */ ?>
                    <button type="button" class="button wpcc-admin__undo" disabled aria-keyshortcuts="Control+Z Meta+Z" title="<?php esc_attr_e('Undo (Ctrl+Z)', 'wpcc'); ?>">
                        <svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 14 4 9l5-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                        <?php esc_html_e('Undo', 'wpcc'); ?>
                    </button>
                    <button type="submit" form="wpcc-form" class="button button-primary wpcc-button-primary wpcc-admin__save"><?php esc_html_e('Save changes', 'wpcc'); ?></button>
                <?php endif; ?>
            </header>

            <nav class="wpcc-admin__tabs" aria-label="<?php esc_attr_e('Cookie Consent settings', 'wpcc'); ?>">
                <?php foreach (self::tabs() as $key => $label) : ?>
                    <a href="<?php echo esc_url(self::url($key)); ?>" class="wpcc-admin__tab<?php echo $key === $tab ? ' is-active' : ''; ?>" <?php echo $key === $tab ? 'aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>
            </div>

            <?php /* WordPress moves notices to the first .wp-header-end */ ?>
            <hr class="wp-header-end">
            <?php settings_errors(); ?>

            <main class="wpcc-admin__body">
                <?php
                match ($tab) {
                    'appearance' => $this->tab_appearance(),
                    'categories' => $this->tab_categories(),
                    'scanner' => $this->tab_scanner(),
                    'updates' => $this->tab_updates(),
                    default => $this->tab_general(),
                };
                ?>
            </main>
        </div>
        <?php
    }

    private function form_open(string $tab): void
    {
        echo '<form method="post" action="options.php" class="wpcc-form" id="wpcc-form">';
        settings_fields('wpcc');
        echo '<input type="hidden" name="' . esc_attr(Settings::OPTION) . '[_tab]" value="' . esc_attr($tab) . '">';
    }

    private function form_close(): void
    {
        echo '</form>';
        $this->leave_dialog();
    }

    /**
     * Shown when leaving a tab with unsaved changes (admin.js).
     */
    private function leave_dialog(): void
    {
        ?>
        <dialog class="wpcc-leave" id="wpcc-leave" aria-labelledby="wpcc-leave-title" aria-describedby="wpcc-leave-text">
            <h2 id="wpcc-leave-title"><?php esc_html_e('You have unsaved changes', 'wpcc'); ?></h2>
            <p id="wpcc-leave-text"><?php esc_html_e('Your changes on this tab haven\'t been saved. Save them before you go?', 'wpcc'); ?></p>
            <div class="wpcc-leave__actions">
                <button type="button" class="button button-primary wpcc-button-primary" data-wpcc-leave="save"><?php esc_html_e('Save changes', 'wpcc'); ?></button>
                <button type="button" class="button" data-wpcc-leave="discard"><?php esc_html_e('Leave without saving', 'wpcc'); ?></button>
                <button type="button" class="button-link" data-wpcc-leave="stay"><?php esc_html_e('Stay on this page', 'wpcc'); ?></button>
            </div>
        </dialog>
        <?php
    }

    private static function name(string ...$keys): string
    {
        return Settings::OPTION . '[' . implode('][', $keys) . ']';
    }

    private function card(string $title, string $description = ''): void
    {
        echo '<section class="wpcc-card"><h2 class="wpcc-card__title">' . esc_html($title) . '</h2>';
        if ($description) {
            echo '<p class="wpcc-card__intro">' . wp_kses_post($description) . '</p>';
        }
    }

    private function card_end(): void
    {
        echo '</section>';
    }

    private function toggle(string $name, bool $checked, string $label, string $help = ''): void
    {
        $id = sanitize_html_class(str_replace(['[', ']'], '-', $name));
        ?>
        <div class="wpcc-field wpcc-field--toggle">
            <input type="checkbox" class="wpcc-toggle" role="switch" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" value="1" <?php checked($checked); ?>>
            <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label>
            <?php if ($help) : ?>
                <p class="description"><?php echo wp_kses_post($help); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function text(string $name, string $value, string $label, string $help = '', string $type = 'text', array $attrs = []): void
    {
        $id = sanitize_html_class(str_replace(['[', ']'], '-', $name));
        $class = $attrs['class'] ?? ('textarea' === $type ? 'large-text' : 'regular-text');
        unset($attrs['class']);
        $extra = '';
        foreach ($attrs as $k => $v) {
            $extra .= ' ' . esc_attr($k) . '="' . esc_attr($v) . '"';
        }
        ?>
        <div class="wpcc-field">
            <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label>
            <?php if ('textarea' === $type) : ?>
                <textarea id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" rows="3" class="<?php echo esc_attr($class); ?>"<?php echo $extra; // phpcs:ignore ?>><?php echo esc_textarea($value); ?></textarea>
            <?php else : ?>
                <input type="<?php echo esc_attr($type); ?>" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>" class="<?php echo esc_attr($class); ?>"<?php echo $extra; // phpcs:ignore ?>>
            <?php endif; ?>
            <?php if ($help) : ?>
                <p class="description"><?php echo wp_kses_post($help); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /* -------------------------------------------------------------------------
     * Tabs
     * ---------------------------------------------------------------------- */

    private function tab_general(): void
    {
        $s = Settings::all();
        $this->form_open('general');

        $this->card(__('Consent banner', 'wpcc'));
        $this->toggle(self::name('enabled'), (bool) $s['enabled'], __('Show the banner and block tracking until visitors consent', 'wpcc'));
        $this->card_end();

        $this->card(__('Google Analytics & Consent Mode v2', 'wpcc'), __('Google tags always start with every consent type denied. A tag added by another plugin or by Tag Manager follows the visitor\'s choice too.', 'wpcc'));
        $this->text(self::name('ga4_id'), (string) $s['ga4_id'], __('GA4 Measurement ID', 'wpcc'), __('Optional. Let this plugin add GA4 to the site, e.g. <code>G-ABC123XYZ</code>. Leave it empty if GA4 is added another way.', 'wpcc'), 'text', ['placeholder' => 'G-XXXXXXXXXX', 'pattern' => '[Gg]-[A-Za-z0-9]{4,}']);
        ?>
        <fieldset class="wpcc-field">
            <legend><?php esc_html_e('Google mode', 'wpcc'); ?></legend>
            <label class="wpcc-radio">
                <input type="radio" name="<?php echo esc_attr(self::name('google_mode')); ?>" value="basic" <?php checked('basic', $s['google_mode']); ?>>
                <span><strong><?php esc_html_e('Basic (recommended)', 'wpcc'); ?></strong> — <?php esc_html_e('Google tags are blocked completely until the visitor accepts. Nothing is sent to Google before that.', 'wpcc'); ?></span>
            </label>
            <label class="wpcc-radio">
                <input type="radio" name="<?php echo esc_attr(self::name('google_mode')); ?>" value="advanced" <?php checked('advanced', $s['google_mode']); ?>>
                <span><strong><?php esc_html_e('Advanced', 'wpcc'); ?></strong> — <?php esc_html_e('Google tags load straight away but set no cookies until consent. Before that they send cookieless pings, which Google uses for conversion modelling.', 'wpcc'); ?></span>
            </label>
        </fieldset>
        <?php
        $this->card_end();

        $this->card(__('Consent', 'wpcc'));
        $this->text(self::name('lifetime_days'), (string) $s['lifetime_days'], __('Remember a choice for (days)', 'wpcc'), __('The ICO and most EU regulators expect no more than 12 months (365 days).', 'wpcc'), 'number', ['min' => 1, 'max' => 395, 'class' => 'small-text']);
        ?>
        <?php $this->page_search(self::name('policy_page'), (int) $s['policy_page'], __('Cookie policy page', 'wpcc')); ?>
        <?php
        $this->text(self::name('policy_url'), (string) $s['policy_url'], __('…or a cookie policy URL', 'wpcc'), __('Used instead of the page above when it\'s filled in.', 'wpcc'), 'url');
        ?>
        <div class="wpcc-field wpcc-field--inline">
            <input type="checkbox" id="wpcc-bump" name="<?php echo esc_attr(self::name('bump_version')); ?>" value="1">
            <label for="wpcc-bump"><?php esc_html_e('Ask everyone again', 'wpcc'); ?></label>
            <p class="description">
                <?php
                printf(
                    /* translators: %d: consent version number */
                    esc_html__('Do this after adding new tracking or changing what cookies are for. Every visitor sees the banner again. (Current consent version: %d)', 'wpcc'),
                    (int) $s['version']
                );
                ?>
            </p>
        </div>
        <?php
        $this->card_end();

        $this->card(__('Opening the preferences', 'wpcc'), __('Visitors must be able to change their mind at any time. Add the shortcode below to the footer or cookie policy page, or turn on the floating button under Appearance.', 'wpcc'));
        ?>
        <ul class="wpcc-help-list">
            <li><code>[cookie_settings]</code> — <?php esc_html_e('a "Cookie settings" button', 'wpcc'); ?></li>
            <li><code>[cookie_settings type="link" text="Change cookie settings"]</code> — <?php esc_html_e('looks like a text link', 'wpcc'); ?></li>
            <li><code>[cookie_settings class="footer__link"]</code> — <?php esc_html_e('uses your theme\'s styles instead of the plugin\'s', 'wpcc'); ?></li>
            <li><?php echo wp_kses_post(__('Or any link to <code>#cookie-settings</code>, or any element with the class <code>wpcc-open</code> (works in menus).', 'wpcc')); ?></li>
        </ul>
        <?php
        $this->card_end();

        $this->form_close();
    }

    /**
     * A page picker that searches pages as you type (the REST search
     * endpoint the block editor's link picker uses), see admin.js.
     */
    private function page_search(string $name, int $page_id, string $label): void
    {
        $title = $page_id ? get_the_title($page_id) : '';
        $privacy = (int) get_option('wp_page_for_privacy_policy');
        ?>
        <div class="wpcc-field wpcc-page-search">
            <label for="wpcc-page-search"><?php echo esc_html($label); ?></label>
            <input type="hidden" class="wpcc-page-search__id" name="<?php echo esc_attr($name); ?>" value="<?php echo $page_id ?: ''; ?>">
            <div class="wpcc-page-search__box">
                <input type="search" id="wpcc-page-search" class="regular-text wpcc-page-search__input" value="<?php echo esc_attr($title); ?>"
                    placeholder="<?php esc_attr_e('Search pages…', 'wpcc'); ?>" autocomplete="off"
                    role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="wpcc-page-search-results">
                <button type="button" class="button wpcc-page-search__clear"<?php echo $page_id ? '' : ' hidden'; ?>><?php esc_html_e('Clear', 'wpcc'); ?></button>
            </div>
            <ul class="wpcc-page-search__results" id="wpcc-page-search-results" role="listbox" hidden></ul>
            <p class="description wpcc-page-search__current" data-none="<?php echo esc_attr($privacy ? sprintf(
                /* translators: %s: page title */
                __('None chosen, so the privacy policy page (%s) is used.', 'wpcc'),
                get_the_title($privacy)
            ) : __('None chosen.', 'wpcc')); ?>">
                <?php if ($page_id) : ?>
                    <a href="<?php echo esc_url((string) get_permalink($page_id)); ?>" target="_blank" rel="noopener"><?php echo esc_html((string) get_permalink($page_id)); ?></a>
                <?php elseif ($privacy) : ?>
                    <?php
                    /* translators: %s: page title */
                    printf(esc_html__('None chosen, so the privacy policy page (%s) is used.', 'wpcc'), esc_html(get_the_title($privacy)));
                    ?>
                <?php else : ?>
                    <?php esc_html_e('None chosen.', 'wpcc'); ?>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }

    private function tab_appearance(): void
    {
        $s = Settings::all();
        $text = $s['text'];
        $this->form_open('appearance');
        ?>
        <div class="wpcc-split">
            <div class="wpcc-split__main">
                <?php
                $this->card(__('Layout', 'wpcc'));
                ?>
                <fieldset class="wpcc-field">
                    <legend><?php esc_html_e('Banner position', 'wpcc'); ?></legend>
                    <div class="wpcc-positions">
                        <?php foreach ([
                            'card-left' => __('Card, bottom left', 'wpcc'),
                            'card-right' => __('Card, bottom right', 'wpcc'),
                            'bar' => __('Full-width bar', 'wpcc'),
                            'modal' => __('Centred pop-up', 'wpcc'),
                        ] as $value => $label) : ?>
                            <label class="wpcc-position wpcc-position--<?php echo esc_attr($value); ?>">
                                <input type="radio" name="<?php echo esc_attr(self::name('position')); ?>" value="<?php echo esc_attr($value); ?>" <?php checked($value, $s['position']); ?> data-wpcc-preview="position">
                                <span class="wpcc-position__shape" aria-hidden="true"><i></i></span>
                                <span><?php echo esc_html($label); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <?php
                $this->toggle(self::name('floating_button'), (bool) $s['floating_button'], __('Show a floating "Cookie settings" button after a choice is made', 'wpcc'), __('Not needed if you use the shortcode in your footer. Shown at the bottom of the preview.', 'wpcc'));
                $this->card_end();

                $this->card(__('Font & shape', 'wpcc'));
                ?>
                <div class="wpcc-grid wpcc-grid--font">
                <div class="wpcc-field">
                    <label for="wpcc-font"><?php esc_html_e('Font', 'wpcc'); ?></label>
                    <select id="wpcc-font" name="<?php echo esc_attr(self::name('font')); ?>" data-wpcc-preview="font">
                        <option value="inherit" <?php selected('inherit', $s['font']); ?>><?php esc_html_e('Same as the theme', 'wpcc'); ?></option>
                        <option value="system" <?php selected('system', $s['font']); ?>><?php esc_html_e('System font', 'wpcc'); ?></option>
                        <option value="custom" <?php selected('custom', $s['font']); ?>><?php esc_html_e('Custom font family', 'wpcc'); ?></option>
                    </select>
                </div>
                <?php
                $this->text(self::name('font_custom'), (string) $s['font_custom'], __('Custom font family', 'wpcc'), __('A font the theme already loads, e.g. <code>"Albert Sans", Arial</code>. The plugin doesn\'t load fonts itself.', 'wpcc'), 'text', ['data-wpcc-preview' => 'font_custom', 'class' => 'wpcc-full']);
                $this->text(self::name('font_size'), (string) $s['font_size'], __('Text size (px)', 'wpcc'), '', 'number', ['min' => 11, 'max' => 22, 'class' => 'small-text', 'data-wpcc-preview' => 'font_size']);
                $this->text(self::name('radius'), (string) $s['radius'], __('Banner & pop-up corners (px)', 'wpcc'), '', 'number', ['min' => 0, 'max' => 40, 'class' => 'small-text', 'data-wpcc-preview' => 'radius']);
                echo '</div>';
                $this->card_end();

                $this->card(__('Buttons', 'wpcc'), __('Accept, Reject, Save and "Allow and load". Use the plugin\'s own style, set with the options below and the Buttons colours, or use your theme\'s buttons so they match the rest of the site.', 'wpcc'));
                $this->toggle(self::name('theme_buttons'), (bool) $s['theme_buttons'], __('Use my theme\'s buttons', 'wpcc'), __('The plugin\'s button colours and corners are then ignored, and your theme styles the buttons.', 'wpcc'));
                ?>
                <div class="wpcc-grid" data-wpcc-show-if="theme_buttons"<?php echo $s['theme_buttons'] ? '' : ' hidden'; ?>>
                    <?php
                    $this->text(self::name('button_class_primary'), (string) $s['button_class_primary'], __('Main button classes', 'wpcc'), __('e.g. <code>button</code> or <code>btn btn-primary</code>', 'wpcc'), 'text', ['class' => 'wpcc-full code']);
                    $this->text(self::name('button_class_secondary'), (string) $s['button_class_secondary'], __('Outline button classes', 'wpcc'), __('e.g. <code>button button--outline</code>', 'wpcc'), 'text', ['class' => 'wpcc-full code']);
                    ?>
                </div>
                <div class="notice notice-info inline wpcc-theme-buttons-note" data-wpcc-show-if="theme_buttons"<?php echo $s['theme_buttons'] ? '' : ' hidden'; ?>>
                    <p><?php esc_html_e('The preview can\'t load your theme\'s styles, so check the buttons on the site itself.', 'wpcc'); ?>
                    <?php echo wp_kses_post(__('If your theme\'s buttons need extra markup, such as an icon, a developer can use the <code>wpcc_button_html</code> filter; see the plugin README.', 'wpcc')); ?></p>
                </div>
                <div class="wpcc-grid" data-wpcc-hide-if="theme_buttons"<?php echo $s['theme_buttons'] ? ' hidden' : ''; ?>>
                    <?php $this->text(self::name('button_radius'), (string) $s['button_radius'], __('Button corners (px)', 'wpcc'), __('0 is square, 50 is fully rounded.', 'wpcc'), 'number', ['min' => 0, 'max' => 50, 'class' => 'small-text', 'data-wpcc-preview' => 'button_radius']); ?>
                </div>
                <?php
                $this->card_end();

                $this->card(__('Colours', 'wpcc'), __('Grouped by the part of the banner they change; hover over a group to see that part outlined in the preview. Check that text and buttons stand out clearly against their background (at least 4.5:1 contrast).', 'wpcc'));
                $this->color_groups('colors', $s['colors']);

                echo '<div class="wpcc-dark-toggle">';
                $this->toggle(self::name('dark_enabled'), (bool) $s['dark_enabled'], __('Use different colours when the visitor\'s device is in dark mode', 'wpcc'));
                echo '</div>';
                echo '<div class="wpcc-dark-colors" data-wpcc-show-if="dark_enabled"' . ($s['dark_enabled'] ? '' : ' hidden') . '>';
                echo '<p class="wpcc-dark-colors__title">' . esc_html__('Dark mode colours', 'wpcc') . '</p>';
                $this->color_groups('dark_colors', $s['dark_colors']);
                echo '</div>';
                $this->card_end();

                $this->card(__('Wording', 'wpcc'));
                $this->text(self::name('text', 'banner'), $text['banner'], __('Banner text', 'wpcc'), '', 'textarea', ['data-wpcc-preview' => 'text.banner']);
                echo '<div class="wpcc-grid">';
                foreach ([
                    'accept' => __('Accept button', 'wpcc'),
                    'reject' => __('Reject button', 'wpcc'),
                    'manage' => __('Manage link', 'wpcc'),
                    'save' => __('Save button', 'wpcc'),
                    'settings' => __('"Cookie settings" label', 'wpcc'),
                    'policy' => __('Policy link text', 'wpcc'),
                    'title' => __('Preferences title', 'wpcc'),
                    'allow' => __('Blocked embed button', 'wpcc'),
                ] as $key => $label) {
                    $this->text(self::name('text', $key), $text[$key], $label, '', 'text', ['data-wpcc-preview' => 'text.' . $key]);
                }
                echo '</div>';
                $this->text(self::name('text', 'intro'), $text['intro'], __('Preferences intro', 'wpcc'), '', 'textarea', ['data-wpcc-preview' => 'text.intro']);
                $this->text(self::name('text', 'blocked'), $text['blocked'], __('Blocked embed message', 'wpcc'), __('<code>%s</code> is replaced with the service name, then the category.', 'wpcc'));
                $this->card_end();
                ?>
            </div>

            <aside class="wpcc-split__preview" aria-label="<?php esc_attr_e('Preview', 'wpcc'); ?>">
                <div class="wpcc-preview-sticky">
                    <div class="wpcc-preview__head">
                        <h2><?php esc_html_e('Preview', 'wpcc'); ?></h2>
                        <div class="wpcc-preview__modes" role="group" aria-label="<?php esc_attr_e('Preview colours', 'wpcc'); ?>">
                            <button type="button" class="button is-active" data-wpcc-mode="light" aria-pressed="true"><?php esc_html_e('Light', 'wpcc'); ?></button>
                            <button type="button" class="button" data-wpcc-mode="dark" aria-pressed="false"><?php esc_html_e('Dark', 'wpcc'); ?></button>
                        </div>
                    </div>
                    <p class="wpcc-preview__hint" hidden>
                        <?php esc_html_e('Dark mode colours are off, so visitors in dark mode see the light colours.', 'wpcc'); ?>
                        <button type="button" class="button-link wpcc-preview__enable-dark"><?php esc_html_e('Turn them on', 'wpcc'); ?></button>
                    </p>
                    <style id="wpcc-preview-vars"><?php echo str_replace(':root', '.wpcc-preview', HeadScript::css_vars(array_merge($s, ['dark_enabled' => false]))); // phpcs:ignore -- sanitised values ?></style>
                    <div class="wpcc-preview" data-mode="light">
                        <?php echo $this->preview_markup($s); // phpcs:ignore -- escaped in Banner::markup ?>
                    </div>
                </div>
            </aside>
        </div>
        <?php
        $this->form_close();
    }

    private function preview_markup(array $s): string
    {
        // Always render the floating button; admin.js shows it when its setting is on
        $html = Banner::markup(array_merge($s, ['floating_button' => true]), true);

        // Show one switch on, so the "on" colours can be seen
        $html = preg_replace('/<input class="wpcc-switch"/', '<input class="wpcc-switch" checked', $html, 1);

        $html = str_replace(
            '<button type="button" class="wpcc-floating wpcc-open"',
            '<p class="wpcc-preview__label"' . ($s['floating_button'] ? '' : ' hidden') . '>' . esc_html__('Floating button, shown after a choice:', 'wpcc') . '</p>'
            . '<button type="button" class="wpcc-floating wpcc-open"' . ($s['floating_button'] ? '' : ' hidden'),
            $html
        );

        // A static, non-modal dialog in the preview
        $html = str_replace('<dialog class="wpcc-dialog"', '<dialog open class="wpcc-dialog"', $html);
        // closedby="any" would light-dismiss the preview on any click on the page
        $html = str_replace(' closedby="any"', '', $html);
        // It sits inside the settings <form>, where a nested <form> is dropped
        $html = str_replace(['<form class="wpcc-dialog__inner" method="dialog">', '</form>'], ['<div class="wpcc-dialog__inner">', '</div>'], $html);
        // Preview switches aren't settings
        $html = preg_replace('/(<input class="wpcc-switch"[^>]*?) name="[^"]*"/', '$1', $html);

        $html = str_replace(['id="wpcc-dialog"', 'id="wpcc-root"', 'id="wpcc-placeholder"', 'id="wpcc-i18n"'], ['id="wpcc-preview-dialog"', '', '', ''], $html);

        return $html;
    }

    /**
     * Colour settings grouped by the part of the banner they change. `target`
     * is what admin.js outlines in the preview while a group is hovered.
     */
    public static function color_group_defs(): array
    {
        $same = __('Empty: same as the banner', 'wpcc');

        return [
            'banner' => [
                'title' => __('Banner', 'wpcc'),
                'intro' => __('The bar or card visitors see first. Also used for blocked-embed placeholders.', 'wpcc'),
                'target' => '.wpcc-banner',
                'fields' => [
                    'bg' => [__('Background', 'wpcc')],
                    'text' => [__('Text', 'wpcc')],
                    'link' => [__('Links', 'wpcc')],
                ],
            ],
            'buttons' => [
                'title' => __('Buttons', 'wpcc'),
                'intro' => __('Accept, Reject and Save, on the banner and in the pop-up. Hover over them in the preview to check hover colours.', 'wpcc'),
                'target' => '.wpcc-button, .wpcc-btn',
                // The theme styles its own buttons
                'hide_if' => 'theme_buttons',
                'fields' => [
                    'primary_bg' => [__('Main button', 'wpcc')],
                    'primary_text' => [__('Main button text', 'wpcc')],
                    'primary_hover_bg' => [__('Main button (hover)', 'wpcc'), __('Empty: brightens slightly', 'wpcc')],
                    'primary_hover_text' => [__('Main button text (hover)', 'wpcc'), __('Empty: same as main button text', 'wpcc')],
                    'secondary_bg' => [__('Outline button', 'wpcc')],
                    'secondary_text' => [__('Outline button text', 'wpcc')],
                    'secondary_border' => [__('Outline button border', 'wpcc')],
                ],
            ],
            'dialog' => [
                'title' => __('Preferences pop-up', 'wpcc'),
                'intro' => __('The window that opens from "Manage preferences" and "Cookie settings".', 'wpcc'),
                'target' => '.wpcc-dialog',
                'fields' => [
                    'dialog_bg' => [__('Background', 'wpcc'), $same],
                    'dialog_text' => [__('Text', 'wpcc'), $same],
                    'dialog_link' => [__('Links', 'wpcc'), $same],
                    'switch_on' => [__('Switches (on)', 'wpcc')],
                    'backdrop' => [__('Backdrop behind it', 'wpcc'), __('e.g. rgba(0,0,0,0.4)', 'wpcc')],
                ],
            ],
            'floating' => [
                'title' => __('Floating button', 'wpcc'),
                'intro' => __('The round "Cookie settings" button, if it\'s switched on.', 'wpcc'),
                'target' => '.wpcc-floating',
                // Only while the floating button is switched on
                'show_if' => 'floating_button',
                'fields' => [
                    'floating_bg' => [__('Background', 'wpcc'), $same],
                    'floating_icon' => [__('Icon', 'wpcc'), __('Empty: same as the banner text', 'wpcc')],
                ],
            ],
        ];
    }

    private function color_groups(string $set, array $values): void
    {
        $settings = Settings::all();

        foreach (self::color_group_defs() as $name => $group) {
            $heading = 'wpcc-' . $set . '-group-' . $name;
            $show_if = $group['show_if'] ?? '';
            $hide_if = $group['hide_if'] ?? '';
            ?>
            <section class="wpcc-color-group" role="group" aria-labelledby="<?php echo esc_attr($heading); ?>" data-wpcc-target="<?php echo esc_attr($group['target']); ?>"
                <?php if ($show_if) : ?>data-wpcc-show-if="<?php echo esc_attr($show_if); ?>"<?php echo empty($settings[$show_if]) ? ' hidden' : ''; ?><?php endif; ?>
                <?php if ($hide_if) : ?>data-wpcc-hide-if="<?php echo esc_attr($hide_if); ?>"<?php echo !empty($settings[$hide_if]) ? ' hidden' : ''; ?><?php endif; ?>>
                <div class="wpcc-color-group__head">
                    <span class="wpcc-color-group__icon wpcc-color-group__icon--<?php echo esc_attr($name); ?>" aria-hidden="true"><i></i><i></i></span>
                    <div>
                        <h3 class="wpcc-color-group__title" id="<?php echo esc_attr($heading); ?>"><?php echo esc_html($group['title']); ?></h3>
                        <p class="wpcc-color-group__intro"><?php echo esc_html($group['intro']); ?></p>
                    </div>
                </div>
                <div class="wpcc-colors">
                    <?php foreach ($group['fields'] as $key => $field) {
                        $this->color_field($set, $key, $field[0], (string) ($values[$key] ?? ''), $field[1] ?? '');
                    } ?>
                </div>
            </section>
            <?php
        }
    }

    private function color_field(string $set, string $key, string $label, string $value, string $help = ''): void
    {
        $id = 'wpcc-' . $set . '-' . $key;
        $is_rgba = 'backdrop' === $key;
        ?>
        <div class="wpcc-color">
            <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label>
            <input type="text" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr(self::name($set, $key)); ?>" value="<?php echo esc_attr($value); ?>"
                class="<?php echo $is_rgba ? 'regular-text wpcc-rgba' : 'wpcc-color-picker'; ?>"
                data-default-color="<?php echo esc_attr(Settings::defaults()[$set][$key]); ?>"
                data-wpcc-var="<?php echo esc_attr(str_replace('_', '-', $key)); ?>"
                data-wpcc-set="<?php echo esc_attr($set); ?>">
            <?php if ($help) : ?>
                <p class="description"><?php echo esc_html($help); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function tab_categories(): void
    {
        $s = Settings::all();
        $labels = self::category_labels();
        $overrides = (array) $s['service_overrides'];

        $this->form_open('categories');

        $this->card(__('Categories', 'wpcc'), __('Visitors can switch each category on or off. Switching off a category here removes it from the banner, and its services are blocked under the strictest remaining category.', 'wpcc'));
        $this->text(self::name('necessary_description'), (string) $s['necessary_description'], __('Strictly necessary — description', 'wpcc'), '', 'textarea');

        foreach (Settings::CATEGORIES as $name) {
            $category = $s['categories'][$name];
            echo '<div class="wpcc-category">';
            $this->toggle(self::name('categories', $name, 'enabled'), (bool) $category['enabled'], sprintf(
                /* translators: %s: category name */
                __('Use the "%s" category', 'wpcc'),
                $labels[$name]
            ));
            echo '<div class="wpcc-grid">';
            $this->text(self::name('categories', $name, 'label'), (string) $category['label'], __('Label', 'wpcc'));
            echo '</div>';
            $this->text(self::name('categories', $name, 'description'), (string) $category['description'], __('Description', 'wpcc'), '', 'textarea');
            echo '</div>';
        }
        $this->card_end();

        $this->card(__('Known services', 'wpcc'), __('These are found and blocked automatically. Change the category a service is blocked under, or choose "Don\'t block" for one the site needs to work.', 'wpcc'));
        ?>
        <table class="widefat striped wpcc-table">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Service', 'wpcc'); ?></th>
                    <th scope="col"><?php esc_html_e('Cookies', 'wpcc'); ?></th>
                    <th scope="col"><?php esc_html_e('Blocked under', 'wpcc'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (Registry::catalogue() as $service) :
                    $value = $overrides[$service['id']] ?? 'default';
                    $field = 'wpcc-service-' . $service['id'];
                    ?>
                    <tr>
                        <th scope="row">
                            <label for="<?php echo esc_attr($field); ?>"><?php echo esc_html($service['name']); ?></label>
                            <?php if (!empty($service['consent_mode'])) : ?>
                                <span class="wpcc-pill"><?php esc_html_e('Consent Mode', 'wpcc'); ?></span>
                            <?php endif; ?>
                        </th>
                        <td><code><?php echo esc_html(implode(', ', $service['cookies']) ?: '—'); ?></code></td>
                        <td>
                            <select id="<?php echo esc_attr($field); ?>" name="<?php echo esc_attr(self::name('service_overrides', $service['id'])); ?>">
                                <option value="default" <?php selected('default', $value); ?>>
                                    <?php
                                    if (!empty($service['essential'])) {
                                        /* translators: %s: category name */
                                        printf(esc_html__('Default (%s, not blocked)', 'wpcc'), esc_html($labels[$service['category']] ?? $service['category']));
                                    } else {
                                        /* translators: %s: category name */
                                        printf(esc_html__('Default (%s)', 'wpcc'), esc_html($labels[$service['category']] ?? $service['category']));
                                    }
                                    ?>
                                </option>
                                <?php foreach ($labels as $name => $label) : ?>
                                    <option value="<?php echo esc_attr($name); ?>" <?php selected($name, $value); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                                <option value="off" <?php selected('off', $value); ?>><?php esc_html_e('Don\'t block', 'wpcc'); ?></option>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        $this->card_end();

        $this->card(__('Custom rules', 'wpcc'), __('Block anything else whose script address, embed address or inline code contains the text you enter, e.g. <code>cdn.example-tracker.com</code>. The Scanner adds rules here too.', 'wpcc'));
        $rules = (array) $s['custom_rules'];
        ?>
        <table class="widefat wpcc-table wpcc-rules" data-next-index="<?php echo count($rules); ?>">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Name', 'wpcc'); ?></th>
                    <th scope="col"><?php esc_html_e('Matches text', 'wpcc'); ?></th>
                    <th scope="col"><?php esc_html_e('Category', 'wpcc'); ?></th>
                    <th scope="col"><span class="screen-reader-text"><?php esc_html_e('Remove', 'wpcc'); ?></span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rules as $i => $rule) {
                    $this->rule_row((string) $i, $rule, $labels);
                } ?>
            </tbody>
        </table>
        <template id="wpcc-rule-template"><?php $this->rule_row('__i__', ['name' => '', 'pattern' => '', 'category' => 'marketing'], $labels); ?></template>
        <p><button type="button" class="button wpcc-add-rule"><?php esc_html_e('Add rule', 'wpcc'); ?></button></p>
        <?php
        $this->card_end();

        $this->form_close();
    }

    private function rule_row(string $i, array $rule, array $labels): void
    {
        ?>
        <tr>
            <td><input type="text" class="regular-text" name="<?php echo esc_attr(self::name('custom_rules', $i, 'name')); ?>" value="<?php echo esc_attr($rule['name']); ?>" aria-label="<?php esc_attr_e('Name', 'wpcc'); ?>"></td>
            <td><input type="text" class="regular-text code" name="<?php echo esc_attr(self::name('custom_rules', $i, 'pattern')); ?>" value="<?php echo esc_attr($rule['pattern']); ?>" aria-label="<?php esc_attr_e('Matches text', 'wpcc'); ?>" placeholder="tracker.example.com"></td>
            <td>
                <select name="<?php echo esc_attr(self::name('custom_rules', $i, 'category')); ?>" aria-label="<?php esc_attr_e('Category', 'wpcc'); ?>">
                    <?php foreach ($labels as $name => $label) : ?>
                        <option value="<?php echo esc_attr($name); ?>" <?php selected($name, $rule['category']); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td><button type="button" class="button-link wpcc-remove-rule"><?php esc_html_e('Remove', 'wpcc'); ?></button></td>
        </tr>
        <?php
    }

    private function tab_scanner(): void
    {
        $this->card(__('Scan the site', 'wpcc'), __('Loads the home page and a few recent pages and posts with blocking switched off. It lists every third-party script, embed, pixel and cookie it finds and whether it\'s blocked. Give anything marked <strong>Unknown</strong> a category so it\'s blocked too, or mark it as necessary.', 'wpcc'));
        ?>
        <p class="wpcc-scan__controls">
            <button type="button" class="button button-primary wpcc-button-primary wpcc-scan__start"><?php esc_html_e('Scan site', 'wpcc'); ?></button>
            <span class="wpcc-scan__status" role="status" aria-live="polite"></span>
        </p>
        <p class="description"><?php esc_html_e('The scan runs trackers in your own browser for a few seconds. It deletes the tracking cookies they set once it\'s finished.', 'wpcc'); ?></p>
        <div class="wpcc-scan__frames" aria-hidden="true"></div>
        <div class="wpcc-scan__results"></div>
        <?php
        $this->card_end();
    }

    private function tab_updates(): void
    {
        $release = GitHubUpdater::latest();
        $dev = GitHubUpdater::is_dev_copy();
        $latest = $release['version'] ?? null;
        $has_update = $latest && version_compare($latest, WPCC_VERSION, '>');
        $has_token = '' !== GitHubUpdater::token();
        $error = $latest ? '' : GitHubUpdater::last_error();

        $this->card(__('Updates', 'wpcc'), __('New versions are published as releases on the private GitHub repository. With an access token set, they appear on the Plugins screen like any other update, and auto-updates can be switched on there.', 'wpcc'));
        ?>
        <dl class="wpcc-meta">
            <dt><?php esc_html_e('Installed version', 'wpcc'); ?></dt>
            <dd><?php echo esc_html(WPCC_VERSION); ?></dd>
            <dt><?php esc_html_e('Latest release', 'wpcc'); ?></dt>
            <dd>
                <?php if ($latest) : ?>
                    <?php echo esc_html($latest); ?>
                    <?php if (!empty($release['published'])) : ?>
                        <span class="description">(<?php echo esc_html(date_i18n(get_option('date_format'), strtotime($release['published']))); ?>)</span>
                    <?php endif; ?>
                <?php else : ?>
                    <?php
                    echo esc_html(match ($error) {
                        'no-token' => __('Not available: no access token is set (the repository is private).', 'wpcc'),
                        'denied' => __('Not available: GitHub refused the access token.', 'wpcc'),
                        'no-release' => __('No releases have been published yet.', 'wpcc'),
                        default => __('Couldn\'t reach GitHub. It will try again within the hour.', 'wpcc'),
                    });
                    ?>
                <?php endif; ?>
            </dd>
            <dt><?php esc_html_e('Access token', 'wpcc'); ?></dt>
            <dd>
                <?php if ($has_token) : ?>
                    <span class="wpcc-status wpcc-status--blocked"><?php esc_html_e('Set', 'wpcc'); ?></span>
                    <span class="description"><?php echo wp_kses_post(__('from <code>WPCC_GITHUB_TOKEN</code>', 'wpcc')); ?></span>
                <?php else : ?>
                    <span class="wpcc-status wpcc-status--unknown"><?php esc_html_e('Not set', 'wpcc'); ?></span>
                <?php endif; ?>
            </dd>
            <dt><?php esc_html_e('Source', 'wpcc'); ?></dt>
            <dd><a href="<?php echo esc_url('https://github.com/' . WPCC_REPO . '/releases'); ?>" target="_blank" rel="noopener">github.com/<?php echo esc_html(WPCC_REPO); ?></a> <span class="description"><?php esc_html_e('(private)', 'wpcc'); ?></span></dd>
        </dl>

        <?php if (!$has_token || 'denied' === $error) : ?>
            <div class="notice notice-warning inline">
                <p><strong><?php esc_html_e('Updates need a GitHub access token.', 'wpcc'); ?></strong>
                <?php esc_html_e('The repository is private. Create a fine-grained token on GitHub with read-only access to this repository\'s contents, then add it to wp-config.php:', 'wpcc'); ?></p>
                <p><code>define('WPCC_GITHUB_TOKEN', 'github_pat_…');</code></p>
                <p><?php echo wp_kses_post(__('Or set <code>WPCC_GITHUB_TOKEN</code> in the site\'s <code>.env</code>. Sites deployed with Composer don\'t need this; they get updates through Composer instead (see the README).', 'wpcc')); ?></p>
            </div>
        <?php endif; ?>

        <?php if ($dev) : ?>
            <div class="notice notice-info inline"><p>
                <strong><?php esc_html_e('Development copy — updates disabled.', 'wpcc'); ?></strong>
                <?php esc_html_e('This plugin is a Git checkout or a symlink, so WordPress won\'t overwrite it with a release. Pull from Git instead.', 'wpcc'); ?>
            </p></div>
        <?php elseif ($has_update) : ?>
            <div class="notice notice-warning inline"><p>
                <?php
                printf(
                    /* translators: %s: version number */
                    esc_html__('Version %s is available.', 'wpcc'),
                    esc_html($latest)
                );
                ?>
                <a href="<?php echo esc_url(self_admin_url('plugins.php?plugin_status=upgrade')); ?>"><?php esc_html_e('Go to Plugins to update', 'wpcc'); ?></a>
            </p></div>
        <?php elseif ($latest) : ?>
            <div class="notice notice-success inline"><p><?php esc_html_e('You have the latest version.', 'wpcc'); ?></p></div>
        <?php endif; ?>

        <?php if (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS) : ?>
            <div class="notice notice-warning inline"><p><?php echo wp_kses_post(__('<code>DISALLOW_FILE_MODS</code> is on for this site, so WordPress can\'t install updates from the dashboard. Update through Composer or your deployment instead.', 'wpcc')); ?></p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="wpcc_check_updates">
            <?php wp_nonce_field('wpcc_check_updates'); ?>
            <?php submit_button(__('Check for updates now', 'wpcc'), 'secondary', 'submit', false); ?>
            <?php if (isset($_GET['checked'])) : // phpcs:ignore ?>
                <span class="wpcc-checked"><?php esc_html_e('Checked just now.', 'wpcc'); ?></span>
            <?php endif; ?>
        </form>

        <?php if (!empty($release['body'])) : ?>
            <h3><?php esc_html_e('Release notes', 'wpcc'); ?></h3>
            <div class="wpcc-release-notes"><?php echo wp_kses_post(GitHubUpdater::markdown($release['body'])); ?></div>
        <?php endif; ?>

        <p class="wpcc-credit">
            <?php
            printf(
                /* translators: %s: link to webpresence.digital */
                esc_html__('Built by Web Presence · %s', 'wpcc'),
                '<a href="https://webpresence.digital/" target="_blank" rel="noopener">webpresence.digital</a>'
            );
            ?>
        </p>
        <?php
        $this->card_end();
    }
}
