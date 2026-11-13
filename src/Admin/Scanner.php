<?php

namespace WebPresence\CookieConsent\Admin;

use WebPresence\CookieConsent\Services\Registry;
use WebPresence\CookieConsent\Settings;

defined('ABSPATH') || exit;

/**
 * Site scanner.
 *
 * The admin page (assets/admin/scanner.js) does the fetching in the browser,
 * not PHP. A loopback request from PHP deadlocks on single-threaded servers
 * such as `php -S`, and the browser can also run the pages to catch cookies
 * that only appear once scripts run. For each URL it:
 *
 * 1. fetches the HTML with ?wpcc_scan=<token> (blocking off) and lists
 *    third-party scripts, iframes, pixels and inline tracker code;
 * 2. loads the page in a hidden iframe for a few seconds and records the
 *    cookies it set and the hosts it requested.
 *
 * This class hands out the token, then identifies everything found against
 * the service catalogue and saves the report.
 */
class Scanner
{
    public const RESULT_OPTION = 'wpcc_scan_result';

    private const TOKEN_TTL = 10 * MINUTE_IN_SECONDS;

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    /**
     * A page request from the scanner: no banner, no blocking.
     */
    public static function is_scan_request(): bool
    {
        static $result = null;

        if (null !== $result) {
            return $result;
        }

        $token = isset($_GET['wpcc_scan']) ? sanitize_key(wp_unslash($_GET['wpcc_scan'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        return $result = ('' !== $token && 32 === strlen($token) && false !== get_transient('wpcc_scan_' . $token));
    }

    public function routes(): void
    {
        $admin = static fn () => current_user_can('manage_options');

        register_rest_route('wpcc/v1', '/scan/start', [
            'methods' => 'POST',
            'callback' => [$this, 'start'],
            'permission_callback' => $admin,
        ]);

        register_rest_route('wpcc/v1', '/scan/finish', [
            'methods' => 'POST',
            'callback' => [$this, 'finish'],
            'permission_callback' => $admin,
        ]);

        register_rest_route('wpcc/v1', '/rules', [
            'methods' => 'POST',
            'callback' => [$this, 'add_rule'],
            'permission_callback' => $admin,
        ]);
    }

    public function start(): \WP_REST_Response
    {
        $token = strtolower(wp_generate_password(32, false, false));
        set_transient('wpcc_scan_' . $token, get_current_user_id(), self::TOKEN_TTL);

        return new \WP_REST_Response([
            'token' => $token,
            'urls' => self::urls(),
            'home' => home_url('/'),
        ]);
    }

    /**
     * Home page plus a couple of recent items from each public post type.
     */
    public static function urls(): array
    {
        $urls = [home_url('/')];

        if ($page = (int) get_option('page_for_posts')) {
            $urls[] = get_permalink($page);
        }

        foreach (get_post_types(['public' => true], 'names') as $type) {
            if ('attachment' === $type) {
                continue;
            }

            $ids = get_posts([
                'post_type' => $type,
                'post_status' => 'publish',
                'numberposts' => 2,
                'fields' => 'ids',
                'no_found_rows' => true,
            ]);

            foreach ($ids as $id) {
                $urls[] = get_permalink($id);
            }
        }

        $urls = array_values(array_unique(array_filter($urls)));

        return array_slice((array) apply_filters('wpcc_scan_urls', $urls), 0, 12);
    }

    /**
     * Body: { token, findings: [{ type, value, page }], cookies: [{ name, page }] }
     * type: script | inline | iframe | img | request
     */
    public function finish(\WP_REST_Request $request): \WP_REST_Response
    {
        $token = sanitize_key((string) $request->get_param('token'));
        delete_transient('wpcc_scan_' . $token);

        $site_host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        $items = [];

        foreach ((array) $request->get_param('findings') as $finding) {
            $type = sanitize_key($finding['type'] ?? '');
            $value = sanitize_text_field((string) ($finding['value'] ?? ''));
            $page = esc_url_raw((string) ($finding['page'] ?? ''));

            if ('' === $value || !in_array($type, ['script', 'inline', 'iframe', 'img', 'request'], true)) {
                continue;
            }

            if ('inline' === $type) {
                $service = self::identify_inline($value);
                if (!$service) {
                    continue;
                }
                $key = 'inline:' . $service['id'];
                $value = $service['name'];
            } else {
                $host = strtolower((string) wp_parse_url($value, PHP_URL_HOST));

                // Same-site files aren't third-party
                if ('' === $host || $host === $site_host || str_ends_with($host, '.' . $site_host)) {
                    continue;
                }

                $service = Registry::identify($value) ?? Registry::identify($host, 'host');

                // Group by host for unknowns; one row per service for known ones
                $key = $service ? 'service:' . $service['id'] : 'host:' . $host;
                $value = $service ? $value : $host;

                // Images are mostly harmless CDN content; only report known pixels
                if ('img' === $type && !$service) {
                    continue;
                }
            }

            $items[$key] ??= self::item($service, $type, $value);
            $items[$key]['pages'][$page] = true;
        }

        foreach ((array) $request->get_param('cookies') as $cookie) {
            $name = sanitize_text_field((string) ($cookie['name'] ?? ''));
            $page = esc_url_raw((string) ($cookie['page'] ?? ''));

            if ('' === $name || self::is_own_cookie($name)) {
                continue;
            }

            $service = Registry::identify($name, 'cookie');
            $key = 'cookie:' . $name;

            $items[$key] ??= self::item($service, 'cookie', $name);
            $items[$key]['pages'][$page] = true;
        }

        $ignored = (array) get_option('wpcc_scan_ignored', []);

        foreach ($items as &$item) {
            $item['pages'] = array_keys($item['pages']);
            $item['status'] = self::status($item, $ignored);
        }
        unset($item);

        // Unknowns first, then by name
        uasort($items, static fn ($a, $b) => [('unknown' !== $a['status']), $a['name']] <=> [('unknown' !== $b['status']), $b['name']]);

        $result = [
            'time' => time(),
            'urls' => array_values(array_unique(array_map('esc_url_raw', (array) $request->get_param('urls')))),
            'items' => array_values($items),
        ];

        update_option(self::RESULT_OPTION, $result, false);

        return new \WP_REST_Response($result);
    }

    /**
     * Body: { pattern, category, name }. category "necessary" = don't block,
     * just stop reporting it as unknown.
     */
    public function add_rule(\WP_REST_Request $request): \WP_REST_Response
    {
        $pattern = strtolower(trim(sanitize_text_field((string) $request->get_param('pattern'))));
        $category = sanitize_key((string) $request->get_param('category'));
        $name = sanitize_text_field((string) $request->get_param('name')) ?: $pattern;

        if ('' === $pattern) {
            return new \WP_REST_Response(['message' => __('Pattern is required.', 'wpcc')], 400);
        }

        if ('necessary' === $category) {
            $ignored = (array) get_option('wpcc_scan_ignored', []);
            $ignored[] = $pattern;
            update_option('wpcc_scan_ignored', array_values(array_unique($ignored)), false);
        } else {
            $rules = (array) Settings::get('custom_rules', []);
            $rules = array_values(array_filter($rules, static fn ($r) => $r['pattern'] !== $pattern));
            $rules[] = ['name' => $name, 'pattern' => $pattern, 'category' => $category];
            Settings::update(['custom_rules' => Settings::sanitize_rules($rules)]);
        }

        Registry::reset();
        self::refresh_saved_result();

        return new \WP_REST_Response(get_option(self::RESULT_OPTION, []));
    }

    /**
     * Re-check a saved report against the current rules and catalogue, so it
     * stays right after settings change or the plugin learns new services.
     */
    public static function refresh_saved_result(): void
    {
        $result = get_option(self::RESULT_OPTION);

        if (!is_array($result) || empty($result['items'])) {
            return;
        }

        $ignored = (array) get_option('wpcc_scan_ignored', []);

        foreach ($result['items'] as &$item) {
            if (empty($item['service_id'])) {
                $service = Registry::identify($item['value'], 'cookie' === $item['type'] ? 'cookie' : 'host');
                if ($service) {
                    $item['name'] = $service['name'];
                    $item['category'] = $service['category'];
                    $item['service_id'] = $service['id'];
                }
            }
            $item['status'] = self::status($item, $ignored);
        }
        unset($item);

        update_option(self::RESULT_OPTION, $result, false);
    }

    private static function item(?array $service, string $type, string $value): array
    {
        return [
            'type' => $type,
            'value' => $value,
            'name' => $service['name'] ?? $value,
            'category' => $service['category'] ?? '',
            'service_id' => $service['id'] ?? '',
            'pages' => [],
        ];
    }

    /**
     * blocked: a rule blocks it until consent
     * allowed: known, but switched off in settings (or Google in advanced mode)
     * necessary: marked as necessary by an admin
     * unknown: nothing matches; needs a decision
     */
    private static function status(array $item, array $ignored): string
    {
        foreach ($ignored as $pattern) {
            if ('' !== $pattern && str_contains(strtolower($item['value']), $pattern)) {
                return 'necessary';
            }
        }

        if ('cookie' === $item['type']) {
            return $item['service_id'] ? 'blocked' : 'unknown';
        }

        if ('custom' === $item['service_id']) {
            return 'blocked';
        }

        if (!$item['service_id']) {
            return 'unknown';
        }

        foreach (Registry::active() as $service) {
            if ($service['id'] === $item['service_id']) {
                return 'blocked';
            }
        }

        foreach (Registry::catalogue() as $service) {
            if ($service['id'] === $item['service_id'] && !empty($service['essential'])) {
                return 'essential';
            }
        }

        return 'allowed';
    }

    private static function identify_inline(string $code): ?array
    {
        $code = strtolower($code);

        foreach (Registry::catalogue() as $service) {
            foreach ($service['inline'] as $pattern) {
                if (str_contains($code, strtolower($pattern))) {
                    return $service;
                }
            }
        }

        return null;
    }

    /**
     * WordPress, this plugin and common necessary cookies.
     */
    private static function is_own_cookie(string $name): bool
    {
        $prefixes = ['wordpress_', 'wp-settings', 'wp_lang', 'wpcc_', 'comment_author', 'wp-saving', 'PHPSESSID', 'woocommerce_', 'wp_woocommerce_session'];

        foreach ($prefixes as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
