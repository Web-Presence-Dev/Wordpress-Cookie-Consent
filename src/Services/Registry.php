<?php

namespace WebPresence\CookieConsent\Services;

use WebPresence\CookieConsent\Settings;

defined('ABSPATH') || exit;

/**
 * Known trackers (data/services.json) plus the site's custom rules, with the
 * category each one is blocked under after settings are applied.
 *
 * Matching is plain case-insensitive substring matching on script/iframe URLs
 * and inline script text, so the same rules run in PHP (Blocker) and in the
 * browser (assets/js/blocker.js).
 */
class Registry
{
    private static ?array $catalogue = null;

    private static ?array $active = null;

    /**
     * Everything in services.json, untouched by settings.
     */
    public static function catalogue(): array
    {
        if (null === self::$catalogue) {
            $json = file_get_contents(WPCC_DIR . 'data/services.json');
            $data = json_decode((string) $json, true);
            self::$catalogue = is_array($data) ? $data : [];
        }

        return self::$catalogue;
    }

    /**
     * Services to block on this site, each with its effective category.
     * Services switched off in settings, and Google services in advanced
     * Consent Mode (Google's own consent handling covers those), are left out.
     */
    public static function active(): array
    {
        if (null !== self::$active) {
            return self::$active;
        }

        $overrides = (array) Settings::get('service_overrides', []);
        $advanced = 'advanced' === Settings::get('google_mode');
        $services = [];

        foreach (self::catalogue() as $service) {
            $override = $overrides[$service['id']] ?? 'default';

            if ('off' === $override) {
                continue;
            }

            if ($advanced && !empty($service['consent_mode']) && 'default' === $override) {
                continue;
            }

            // Things most sites need (fonts, avatars): recognised, but only
            // blocked if a category is chosen for them in settings
            if (!empty($service['essential']) && 'default' === $override) {
                continue;
            }

            $service['category'] = 'default' === $override ? $service['category'] : $override;
            $services[] = $service;
        }

        foreach ((array) Settings::get('custom_rules', []) as $i => $rule) {
            $pattern = strtolower(trim((string) $rule['pattern']));

            $services[] = [
                'id' => 'custom-' . $i,
                'name' => $rule['name'],
                'category' => $rule['category'],
                'custom' => true,
                'src' => [$pattern],
                'inline' => [$pattern],
                'cookies' => [],
            ];
        }

        /**
         * Add, remove or change the services the plugin blocks.
         *
         * @param array $services Each: id, name, category, src[], inline[], cookies[].
         */
        $services = (array) apply_filters('wpcc_services', $services);

        foreach ($services as &$service) {
            $service['category'] = self::effective_category((string) ($service['category'] ?? 'marketing'));
        }
        unset($service);

        // A category nobody can opt in to would block forever; those return null
        self::$active = array_values(array_filter($services, static fn ($s) => null !== $s['category']));

        return self::$active;
    }

    /**
     * A disabled category's services move to the strictest enabled one, so
     * turning off "Functional" doesn't silently let embeds through.
     */
    public static function effective_category(string $category): ?string
    {
        $enabled = Settings::enabled_categories();

        if (in_array($category, $enabled, true)) {
            return $category;
        }

        foreach (['marketing', 'analytics', 'functional'] as $fallback) {
            if (in_array($fallback, $enabled, true)) {
                return $fallback;
            }
        }

        return null;
    }

    public static function reset(): void
    {
        self::$active = null;
    }

    public static function match_url(string $url): ?array
    {
        $url = strtolower($url);

        if ('' === $url) {
            return null;
        }

        foreach (self::active() as $service) {
            foreach ($service['src'] as $pattern) {
                if ('' !== $pattern && str_contains($url, strtolower($pattern))) {
                    return $service;
                }
            }
        }

        return null;
    }

    public static function match_inline(string $code): ?array
    {
        if ('' === trim($code)) {
            return null;
        }

        $code = strtolower($code);

        foreach (self::active() as $service) {
            foreach ($service['inline'] as $pattern) {
                if ('' !== $pattern && str_contains($code, strtolower($pattern))) {
                    return $service;
                }
            }
        }

        return null;
    }

    /**
     * Find a catalogue service (ignoring settings) for a URL, host or cookie,
     * for the scanner's report.
     */
    public static function identify(string $value, string $type = 'url'): ?array
    {
        $value = strtolower($value);

        foreach (self::catalogue() as $service) {
            if ('cookie' === $type) {
                foreach ($service['cookies'] as $pattern) {
                    if (self::cookie_matches($value, strtolower($pattern))) {
                        return $service;
                    }
                }
                continue;
            }

            foreach ($service['src'] as $pattern) {
                $pattern = strtolower($pattern);
                $host = strtolower((string) wp_parse_url('https://' . ltrim($pattern, '/'), PHP_URL_HOST));

                if (str_contains($value, $pattern) || ('host' === $type && '' !== $host && str_contains($pattern, '.') && str_ends_with($value, $host))) {
                    return $service;
                }
            }
        }

        foreach ((array) Settings::get('custom_rules', []) as $rule) {
            if ('cookie' !== $type && str_contains($value, strtolower($rule['pattern']))) {
                return ['id' => 'custom', 'name' => $rule['name'], 'category' => $rule['category'], 'custom' => true];
            }
        }

        return null;
    }

    public static function cookie_matches(string $name, string $pattern): bool
    {
        return str_ends_with($pattern, '*')
            ? str_starts_with($name, rtrim($pattern, '*'))
            : $name === $pattern;
    }

    /**
     * The compact rule set handed to the browser.
     */
    public static function client_rules(): array
    {
        return array_map(static fn ($s) => [
            'n' => $s['name'],
            'c' => $s['category'],
            's' => array_values(array_map('strtolower', $s['src'])),
            'i' => array_values(array_map('strtolower', $s['inline'])),
            'k' => array_values($s['cookies']),
        ], self::active());
    }
}
