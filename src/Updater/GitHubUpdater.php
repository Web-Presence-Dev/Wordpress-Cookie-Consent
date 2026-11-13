<?php

namespace WebPresence\CookieConsent\Updater;

defined('ABSPATH') || exit;

/**
 * Updates from GitHub releases. The repo is private, so the updater needs a
 * read-only GitHub token: define WPCC_GITHUB_TOKEN in wp-config.php, or set it
 * in the environment (.env). It's never stored in the database or shown.
 *
 * Downloads go through the GitHub API with the token. GitHub answers with a
 * redirect to a short-lived signed link, which is fetched without the token
 * (sending it on would break the download); see pre_download().
 *
 * The plugin header's `Update URI: https://github.com/...` makes WordPress ask
 * the `update_plugins_github.com` filter about this plugin, instead of
 * wordpress.org. That stops a wordpress.org plugin with the same folder name
 * from ever being offered as an "update".
 *
 * Release process: bump the version, push a `vX.Y.Z` tag, and
 * .github/workflows/release.yml attaches webpresence-cookie-consent.zip to the
 * release. Without that asset, the source zipball is used and its folder is
 * renamed on install.
 */
class GitHubUpdater
{
    private const CACHE_KEY = 'wpcc_github_release';

    private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

    private const ASSET = 'webpresence-cookie-consent.zip';

    public function __construct()
    {
        add_filter('update_plugins_github.com', [$this, 'update'], 10, 4);
        add_filter('plugins_api', [$this, 'details'], 20, 3);
        add_filter('upgrader_source_selection', [$this, 'fix_folder'], 10, 4);
        add_action('upgrader_process_complete', [$this, 'after_upgrade'], 10, 2);
        add_filter('upgrader_pre_download', [$this, 'pre_download'], 10, 3);
    }

    /**
     * The GitHub token, from wp-config.php or the environment.
     */
    public static function token(): string
    {
        if (defined('WPCC_GITHUB_TOKEN') && is_string(WPCC_GITHUB_TOKEN)) {
            return trim(WPCC_GITHUB_TOKEN);
        }

        $env = $_ENV['WPCC_GITHUB_TOKEN'] ?? $_SERVER['WPCC_GITHUB_TOKEN'] ?? getenv('WPCC_GITHUB_TOKEN');

        return is_string($env) ? trim($env) : '';
    }

    private static function headers(string $accept = 'application/vnd.github+json'): array
    {
        $headers = [
            'Accept' => $accept,
            'User-Agent' => 'WordPress-Plugin-Updater',
            'X-GitHub-Api-Version' => '2022-11-28',
        ];

        if ('' !== ($token = self::token())) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $headers;
    }

    /**
     * Why the last check failed, for the Updates tab: '' | no-token | denied | no-release | unreachable
     */
    public static function last_error(): string
    {
        $cached = get_site_transient(self::CACHE_KEY);

        return is_array($cached) && empty($cached['version']) ? (string) ($cached['error'] ?? 'unreachable') : '';
    }

    /**
     * A Git checkout or symlinked folder (local development): never offer
     * updates, or WordPress would overwrite the working copy.
     */
    public static function is_dev_copy(): bool
    {
        if (defined('WPCC_DEV_ALLOW_UPDATES') && WPCC_DEV_ALLOW_UPDATES) {
            return false;
        }

        $dir = untrailingslashit(WPCC_DIR);
        $installed = WP_PLUGIN_DIR . '/' . dirname(WPCC_BASENAME);

        return is_dir($dir . '/.git') || is_link($installed) || realpath($installed) !== realpath($dir);
    }

    /**
     * The latest release: version, package, published, body, url. Cached.
     */
    public static function latest(bool $refresh = false): ?array
    {
        $cached = $refresh ? false : get_site_transient(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached['version'] ? $cached : null;
        }

        $response = wp_remote_get('https://api.github.com/repos/' . WPCC_REPO . '/releases/latest', [
            'timeout' => 10,
            'headers' => self::headers(),
        ]);

        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);

        if (is_wp_error($response) || 200 !== $code || !is_array($data) || empty($data['tag_name'])) {
            // A private repo answers 404 to anyone without access
            $error = match (true) {
                is_wp_error($response) => 'unreachable',
                in_array($code, [401, 403, 404], true) && '' === self::token() => 'no-token',
                in_array($code, [401, 403], true) => 'denied',
                404 === $code => 'no-release',
                default => 'unreachable',
            };

            // Remember the failure for an hour so a GitHub outage or rate
            // limit doesn't slow every admin page down
            set_site_transient(self::CACHE_KEY, ['version' => null, 'error' => $error], HOUR_IN_SECONDS);
            return null;
        }

        // API URLs (not browser links), so the token can be used to download
        $package = $data['zipball_url'] ?? '';
        foreach ((array) ($data['assets'] ?? []) as $asset) {
            if (($asset['name'] ?? '') === self::ASSET && !empty($asset['url'])) {
                $package = $asset['url'];
                break;
            }
        }

        $release = [
            'version' => ltrim((string) $data['tag_name'], 'vV'),
            'package' => $package,
            'published' => (string) ($data['published_at'] ?? ''),
            'body' => (string) ($data['body'] ?? ''),
            'url' => (string) ($data['html_url'] ?? ''),
        ];

        set_site_transient(self::CACHE_KEY, $release, self::CACHE_TTL);

        return $release;
    }

    public static function flush(): void
    {
        delete_site_transient(self::CACHE_KEY);
    }

    /**
     * update_plugins_{hostname}: tell WordPress about a newer release.
     */
    public function update(mixed $update, array $plugin_data, string $plugin_file, array $locales): mixed
    {
        if (WPCC_BASENAME !== $plugin_file || self::is_dev_copy()) {
            return $update;
        }

        $release = self::latest();

        if (!$release || empty($release['package'])) {
            return $update;
        }

        return [
            'id' => 'github.com/' . WPCC_REPO,
            'slug' => dirname(WPCC_BASENAME),
            'plugin' => WPCC_BASENAME,
            'version' => $release['version'],
            'new_version' => $release['version'],
            'url' => 'https://github.com/' . WPCC_REPO,
            'package' => $release['package'],
            'tested' => get_bloginfo('version'),
            'requires_php' => '8.1',
            'icons' => ['svg' => WPCC_URL . 'assets/img/menu-icon.svg'],
        ];
    }

    /**
     * The "View details" popup on the Plugins and Updates screens.
     */
    public function details(mixed $result, string $action, object $args): mixed
    {
        if ('plugin_information' !== $action || ($args->slug ?? '') !== dirname(WPCC_BASENAME)) {
            return $result;
        }

        $release = self::latest();
        $readme = self::readme_changelog();

        return (object) [
            'name' => 'Web Presence Cookie Consent',
            'slug' => dirname(WPCC_BASENAME),
            'version' => $release['version'] ?? WPCC_VERSION,
            'author' => '<a href="https://webpresence.digital/">Web Presence</a>',
            'homepage' => 'https://github.com/' . WPCC_REPO,
            'requires' => '6.5',
            'requires_php' => '8.1',
            'tested' => get_bloginfo('version'),
            'last_updated' => $release['published'] ?? '',
            'download_link' => $release['package'] ?? '',
            'sections' => array_filter([
                'description' => '<p>' . esc_html__('Cookie consent banner that finds and blocks GA4 and other tracking until visitors agree, with Google Consent Mode v2.', 'wpcc') . '</p>',
                'changelog' => $release && '' !== $release['body']
                    ? wp_kses_post(self::markdown($release['body'])) . ($readme ? '<hr>' . $readme : '')
                    : $readme,
            ]),
            'banners' => [],
        ];
    }

    /**
     * GitHub zipballs unpack to "Owner-Repo-<sha>/"; WordPress needs the
     * plugin's own folder name or it installs a second copy.
     */
    public function fix_folder(mixed $source, string $remote_source, object $upgrader, array $hook_extra = []): mixed
    {
        global $wp_filesystem;

        if (is_wp_error($source) || ($hook_extra['plugin'] ?? '') !== WPCC_BASENAME) {
            return $source;
        }

        $wanted = trailingslashit($remote_source) . dirname(WPCC_BASENAME) . '/';

        if (untrailingslashit($source) === untrailingslashit($wanted)) {
            return $source;
        }

        if ($wp_filesystem && $wp_filesystem->move($source, $wanted, true)) {
            return $wanted;
        }

        return new \WP_Error('wpcc_rename', __('Couldn\'t rename the downloaded plugin folder.', 'wpcc'));
    }

    /**
     * Download a package from the private repo: ask the API with the token,
     * then fetch the signed link it redirects to without the token.
     */
    public function pre_download(mixed $reply, string $package, object $upgrader): mixed
    {
        $api = 'https://api.github.com/repos/' . WPCC_REPO . '/';

        if (false !== $reply || !str_starts_with($package, $api)) {
            return $reply;
        }

        $is_asset = str_contains($package, '/releases/assets/');

        $response = wp_remote_get($package, [
            'timeout' => 30,
            'redirection' => 0,
            'headers' => self::headers($is_asset ? 'application/octet-stream' : 'application/vnd.github+json'),
        ]);

        $code = (int) wp_remote_retrieve_response_code($response);
        $location = (string) wp_remote_retrieve_header($response, 'location');

        if (is_wp_error($response)) {
            return $response;
        }

        if ($code < 300 || $code > 399 || '' === $location) {
            return new \WP_Error('wpcc_download', sprintf(
                /* translators: %d: HTTP status code */
                __('GitHub refused the download (HTTP %d). Check WPCC_GITHUB_TOKEN can read the repository.', 'wpcc'),
                $code
            ));
        }

        // Not shown on screen: the signed link carries a temporary access key
        require_once ABSPATH . 'wp-admin/includes/file.php';

        return download_url($location, 300);
    }

    public function after_upgrade(object $upgrader, array $options): void
    {
        if (($options['type'] ?? '') === 'plugin' && in_array(WPCC_BASENAME, (array) ($options['plugins'] ?? []), true)) {
            self::flush();
        }
    }

    /**
     * Just enough Markdown for GitHub release notes: headings, lists, bold,
     * inline code and links. The result is passed through wp_kses_post.
     */
    public static function markdown(string $md): string
    {
        $html = '';
        $in_list = false;

        foreach (preg_split('/\R/', esc_html($md)) as $line) {
            $line = rtrim($line);

            $line = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $line);
            $line = preg_replace('/`([^`]+)`/', '<code>$1</code>', $line);
            $line = preg_replace('/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/', '<a href="$2">$1</a>', $line);

            if (preg_match('/^\s*[-*]\s+(.*)$/', $line, $m)) {
                if (!$in_list) {
                    $html .= '<ul>';
                    $in_list = true;
                }
                $html .= '<li>' . $m[1] . '</li>';
                continue;
            }

            if ($in_list) {
                $html .= '</ul>';
                $in_list = false;
            }

            if (preg_match('/^(#{1,4})\s+(.*)$/', $line, $m)) {
                $level = min(4, strlen($m[1]) + 2);
                $html .= "<h{$level}>{$m[2]}</h{$level}>";
            } elseif ('' !== $line) {
                $html .= '<p>' . $line . '</p>';
            }
        }

        return $html . ($in_list ? '</ul>' : '');
    }

    private static function readme_changelog(): string
    {
        $readme = (string) @file_get_contents(WPCC_DIR . 'readme.txt'); // phpcs:ignore

        if (!preg_match('/==\s*Changelog\s*==(.*)$/is', $readme, $m)) {
            return '';
        }

        $md = preg_replace('/^=\s*(.+?)\s*=$/m', '#### $1', trim($m[1]));

        return wp_kses_post(self::markdown((string) $md));
    }
}
