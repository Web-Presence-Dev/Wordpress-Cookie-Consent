<?php

namespace WebPresence\CookieConsent;

defined('ABSPATH') || exit;

/**
 * Plugin settings, stored as one array in the `wpcc_settings` option.
 *
 * The settings page posts one tab at a time, so sanitize() merges the posted
 * tab into what's saved rather than replacing it (unticked checkboxes are
 * simply missing from the post, so each tab lists its own checkbox keys).
 */
class Settings
{
    public const OPTION = 'wpcc_settings';

    public const CATEGORIES = ['functional', 'analytics', 'marketing'];

    public const POSITIONS = ['card-left', 'card-right', 'bar', 'modal'];

    public const COLOR_KEYS = [
        'bg', 'text', 'link', 'primary_bg', 'primary_text',
        'secondary_bg', 'secondary_text', 'secondary_border', 'switch_on', 'backdrop',
        'floating_bg', 'floating_icon', 'dialog_bg', 'dialog_text', 'dialog_link',
        'primary_hover_bg', 'primary_hover_text',
    ];

    private static ?array $cache = null;

    public static function defaults(): array
    {
        return [
            'enabled' => true,
            'google_mode' => 'basic',
            'ga4_id' => '',
            'lifetime_days' => 365,
            'version' => 1,
            'policy_page' => 0,
            'policy_url' => '',

            'categories' => [
                'functional' => [
                    'enabled' => true,
                    'label' => __('Functional', 'wpcc'),
                    'description' => __('Remember choices you make and provide extra features, such as embedded videos and maps.', 'wpcc'),
                ],
                'analytics' => [
                    'enabled' => true,
                    'label' => __('Analytics', 'wpcc'),
                    'description' => __('Count visits and show us which pages are useful, so we can improve the site. The data is aggregated.', 'wpcc'),
                ],
                'marketing' => [
                    'enabled' => true,
                    'label' => __('Marketing', 'wpcc'),
                    'description' => __('Measure our advertising and show you relevant ads on other sites.', 'wpcc'),
                ],
            ],
            'necessary_description' => __('Needed for the site to work, such as remembering your cookie choices. They do not track you.', 'wpcc'),

            'text' => [
                'banner' => __('We use cookies to understand how people use our site and to improve it. Optional cookies are only set if you accept them.', 'wpcc'),
                'title' => __('Cookie preferences', 'wpcc'),
                'intro' => __('Choose which cookies we can use. You can change this at any time from "Cookie settings".', 'wpcc'),
                'accept' => __('Accept all', 'wpcc'),
                'reject' => __('Reject all', 'wpcc'),
                'manage' => __('Manage preferences', 'wpcc'),
                'save' => __('Save choices', 'wpcc'),
                'settings' => __('Cookie settings', 'wpcc'),
                'policy' => __('Cookie policy', 'wpcc'),
                'blocked' => __('This content is hosted by %s and needs %s cookies.', 'wpcc'),
                'allow' => __('Allow and load', 'wpcc'),
            ],

            'position' => 'card-left',
            'floating_button' => false,

            'colors' => [
                'bg' => '#ffffff',
                'text' => '#1d2327',
                'link' => '#1d2327',
                'primary_bg' => '#1d2327',
                'primary_text' => '#ffffff',
                'secondary_bg' => '#ffffff',
                'secondary_text' => '#1d2327',
                'secondary_border' => '#1d2327',
                'switch_on' => '#1d2327',
                'backdrop' => 'rgba(0,0,0,0.4)',
                // Empty: follow the banner's background and text colours
                'floating_bg' => '',
                'floating_icon' => '',
                // Empty: the pop-up uses the banner's colours
                'dialog_bg' => '',
                'dialog_text' => '',
                'dialog_link' => '',
                // Empty: main buttons just brighten slightly on hover
                'primary_hover_bg' => '',
                'primary_hover_text' => '',
            ],
            'dark_enabled' => false,
            'dark_colors' => [
                'bg' => '#1d2327',
                'text' => '#f0f0f1',
                'link' => '#f0f0f1',
                'primary_bg' => '#f0f0f1',
                'primary_text' => '#1d2327',
                'secondary_bg' => '#1d2327',
                'secondary_text' => '#f0f0f1',
                'secondary_border' => '#f0f0f1',
                'switch_on' => '#f0f0f1',
                'backdrop' => 'rgba(0,0,0,0.6)',
                'floating_bg' => '',
                'floating_icon' => '',
                // Empty: the pop-up uses the banner's colours
                'dialog_bg' => '',
                'dialog_text' => '',
                'dialog_link' => '',
                // Empty: main buttons just brighten slightly on hover
                'primary_hover_bg' => '',
                'primary_hover_text' => '',
            ],

            'font' => 'inherit',
            'font_custom' => '',
            'font_size' => 15,
            'radius' => 12,
            // Accept / Reject / Save buttons; 50 makes them pills
            'button_radius' => 8,
            // Use the theme's button classes instead of the plugin's styles
            'theme_buttons' => false,
            'button_class_primary' => 'button',
            'button_class_secondary' => 'button button--outline',

            // service id => 'default' | category | 'off'
            'service_overrides' => [],
            // [ ['name' => '', 'pattern' => '', 'category' => ''] ]
            'custom_rules' => [],
        ];
    }

    public static function all(): array
    {
        if (null === self::$cache) {
            $saved = get_option(self::OPTION, []);
            self::$cache = self::merge(self::defaults(), is_array($saved) ? $saved : []);
        }

        return self::$cache;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    public static function update(array $values): void
    {
        $saved = get_option(self::OPTION, []);
        update_option(self::OPTION, array_merge(is_array($saved) ? $saved : [], $values));
        self::$cache = null;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    /**
     * Recursive merge where saved values win, but only for keys that exist in
     * the defaults (lists such as custom_rules are taken as they are).
     */
    private static function merge(array $defaults, array $saved): array
    {
        foreach ($saved as $key => $value) {
            if (is_array($value) && isset($defaults[$key]) && is_array($defaults[$key]) && !array_is_list($defaults[$key]) && [] !== $defaults[$key]) {
                $defaults[$key] = self::merge($defaults[$key], $value);
            } else {
                $defaults[$key] = $value;
            }
        }

        return $defaults;
    }

    public static function measurement_id(): string
    {
        $id = strtoupper(trim((string) self::get('ga4_id')));

        return preg_match('/^G-[A-Z0-9]{4,}$/', $id) ? $id : '';
    }

    public static function enabled_categories(): array
    {
        $categories = self::get('categories');

        return array_values(array_filter(self::CATEGORIES, static fn ($name) => !empty($categories[$name]['enabled'])));
    }

    public static function policy_url(): string
    {
        $url = (string) self::get('policy_url');

        if ('' === $url && ($page = (int) self::get('policy_page'))) {
            $url = (string) get_permalink($page);
        }

        if ('' === $url && ($privacy = (int) get_option('wp_page_for_privacy_policy'))) {
            $url = (string) get_permalink($privacy);
        }

        return $url;
    }

    /**
     * Activation: save defaults once, importing the webpresence theme's ACF
     * "Cookies & Analytics" options so a site keeps its GA4 setup.
     */
    public static function activate(): void
    {
        if (false !== get_option(self::OPTION)) {
            return;
        }

        $settings = [];

        $ga4 = get_option('options_ga4_measurement_id');
        if (is_string($ga4) && '' !== $ga4) {
            $settings['ga4_id'] = sanitize_text_field($ga4);
        }

        $mode = get_option('options_cookie_consent_mode');
        if (in_array($mode, ['basic', 'advanced'], true)) {
            $settings['google_mode'] = $mode;
        }

        $text = get_option('options_cookie_banner_text');
        if (is_string($text) && '' !== trim($text)) {
            $settings['text'] = ['banner' => sanitize_textarea_field($text)];
        }

        $policy = get_option('options_cookie_policy');
        if (is_array($policy) && !empty($policy['url'])) {
            $settings['policy_url'] = esc_url_raw($policy['url']);
        }

        add_option(self::OPTION, $settings);
    }

    /* -------------------------------------------------------------------------
     * Sanitising (register_setting callback)
     * ---------------------------------------------------------------------- */

    public static function sanitize(mixed $input): array
    {
        $current = get_option(self::OPTION, []);
        $current = is_array($current) ? $current : [];

        // Only the settings form posts a tab; anything else (update_option
        // from our own code) has already been cleaned
        if (!is_array($input) || !isset($input['_tab'])) {
            return is_array($input) ? $input : $current;
        }

        $tab = sanitize_key($input['_tab']);
        $out = $current;

        switch ($tab) {
            case 'general':
                $out['enabled'] = !empty($input['enabled']);
                $out['google_mode'] = 'advanced' === ($input['google_mode'] ?? '') ? 'advanced' : 'basic';
                $out['ga4_id'] = strtoupper(sanitize_text_field($input['ga4_id'] ?? ''));
                $out['lifetime_days'] = max(1, min(395, absint($input['lifetime_days'] ?? 365)));
                $out['policy_page'] = absint($input['policy_page'] ?? 0);
                $out['policy_url'] = esc_url_raw(trim($input['policy_url'] ?? ''));

                if ('' !== $out['ga4_id'] && !preg_match('/^G-[A-Z0-9]{4,}$/', $out['ga4_id'])) {
                    add_settings_error(self::OPTION, 'ga4', __('That GA4 Measurement ID doesn\'t look right. It should look like G-ABC123XYZ.', 'wpcc'));
                }

                if (!empty($input['bump_version'])) {
                    $out['version'] = (int) ($current['version'] ?? 1) + 1;
                    add_settings_error(self::OPTION, 'bump', __('Everyone will be asked for consent again on their next visit.', 'wpcc'), 'info');
                }
                break;

            case 'appearance':
                $defaults = self::defaults();

                foreach (array_keys($defaults['text']) as $key) {
                    if (isset($input['text'][$key])) {
                        $out['text'][$key] = 'banner' === $key || 'intro' === $key
                            ? sanitize_textarea_field($input['text'][$key])
                            : sanitize_text_field($input['text'][$key]);
                    }
                }

                $out['position'] = in_array($input['position'] ?? '', self::POSITIONS, true) ? $input['position'] : 'card-left';
                $out['floating_button'] = !empty($input['floating_button']);
                $out['dark_enabled'] = !empty($input['dark_enabled']);

                foreach (['colors', 'dark_colors'] as $set) {
                    foreach (self::COLOR_KEYS as $key) {
                        if (isset($input[$set][$key])) {
                            $out[$set][$key] = self::sanitize_color($input[$set][$key], $defaults[$set][$key]);
                        }
                    }
                }

                $out['font'] = in_array($input['font'] ?? '', ['inherit', 'system', 'custom'], true) ? $input['font'] : 'inherit';
                // Font family names only: letters, digits, spaces, quotes, commas and hyphens
                $out['font_custom'] = preg_replace('/[^A-Za-z0-9 ,\'"\-]/', '', (string) ($input['font_custom'] ?? ''));
                $out['font_size'] = max(11, min(22, absint($input['font_size'] ?? 15)));
                $out['radius'] = max(0, min(40, absint($input['radius'] ?? 12)));
                $out['button_radius'] = max(0, min(50, absint($input['button_radius'] ?? 8)));
                $out['theme_buttons'] = !empty($input['theme_buttons']);
                $out['button_class_primary'] = self::sanitize_classes($input['button_class_primary'] ?? '');
                $out['button_class_secondary'] = self::sanitize_classes($input['button_class_secondary'] ?? '');
                break;

            case 'categories':
                $defaults = self::defaults();

                foreach (self::CATEGORIES as $name) {
                    $posted = $input['categories'][$name] ?? [];
                    $out['categories'][$name] = [
                        'enabled' => !empty($posted['enabled']),
                        'label' => sanitize_text_field($posted['label'] ?? '') ?: $defaults['categories'][$name]['label'],
                        'description' => sanitize_textarea_field($posted['description'] ?? ''),
                    ];
                }

                $out['necessary_description'] = sanitize_textarea_field($input['necessary_description'] ?? '');

                $out['service_overrides'] = [];
                foreach ((array) ($input['service_overrides'] ?? []) as $id => $value) {
                    $value = sanitize_key($value);
                    if ('default' !== $value && (in_array($value, self::CATEGORIES, true) || 'off' === $value)) {
                        $out['service_overrides'][sanitize_key($id)] = $value;
                    }
                }

                $out['custom_rules'] = self::sanitize_rules($input['custom_rules'] ?? []);
                break;
        }

        self::$cache = null;

        return $out;
    }

    public static function sanitize_rules(mixed $rules): array
    {
        $clean = [];

        foreach ((array) $rules as $rule) {
            $pattern = trim(sanitize_text_field($rule['pattern'] ?? ''));
            $category = sanitize_key($rule['category'] ?? '');

            if ('' === $pattern || !in_array($category, self::CATEGORIES, true)) {
                continue;
            }

            $clean[] = [
                'name' => sanitize_text_field($rule['name'] ?? '') ?: $pattern,
                'pattern' => $pattern,
                'category' => $category,
            ];
        }

        return $clean;
    }

    /**
     * A space-separated list of CSS class names.
     */
    public static function sanitize_classes(mixed $value): string
    {
        $classes = preg_split('/\s+/', trim((string) $value)) ?: [];

        return implode(' ', array_filter(array_map('sanitize_html_class', $classes)));
    }

    private static function sanitize_color(mixed $value, string $fallback): string
    {
        $value = trim((string) $value);

        if ('' === $value) {
            return $fallback; // '' for colours that follow another one
        }

        if ($hex = sanitize_hex_color($value)) {
            return $hex;
        }

        if (preg_match('/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*(0|1|0?\.\d+)\s*)?\)$/', $value)) {
            return preg_replace('/\s+/', '', $value);
        }

        return $fallback;
    }
}
