<?php

namespace WebPresence\CookieConsent\Frontend;

use WebPresence\CookieConsent\Plugin;
use WebPresence\CookieConsent\Services\Registry;

defined('ABSPATH') || exit;

/**
 * Rewrites tracker tags in the page HTML before it's sent:
 *
 * - <script> (by src or inline code) → type="text/plain", so it doesn't run
 * - <iframe> / <img> pixels → src moved to data-wpcc-src, so nothing loads
 *
 * Every visitor gets the same blocked HTML; consent.js unblocks it in the
 * browser once they agree. That keeps pages safe for full-page caches.
 */
class Blocker
{
    private const JS_TYPES = ['', 'text/javascript', 'application/javascript', 'module', 'text/ecmascript', 'application/ecmascript'];

    public function __construct()
    {
        add_action('template_redirect', [$this, 'start'], 0);
    }

    public function start(): void
    {
        if (!Plugin::is_active_request() || is_feed() || is_robots() || is_trackback() || is_embed()) {
            return;
        }

        if (!apply_filters('wpcc_buffer_output', true)) {
            return;
        }

        ob_start([$this, 'filter']);
    }

    /**
     * Output-buffer callback. Only full HTML documents are touched.
     */
    public function filter(string $html): string
    {
        if ('' === $html || !preg_match('/<html[\s>]/i', substr($html, 0, 2000)) || !$this->is_html_response()) {
            return $html;
        }

        try {
            return self::process($html);
        } catch (\Throwable $e) {
            // Never break the page; the client-side blocker still runs
            return $html;
        }
    }

    public static function process(string $html): string
    {
        $tags = new \WP_HTML_Tag_Processor($html);

        while ($tags->next_tag()) {
            $tag = $tags->get_tag();

            if (!in_array($tag, ['SCRIPT', 'IFRAME', 'IMG'], true)) {
                continue;
            }

            if (null !== $tags->get_attribute('data-wpcc-ignore') || null !== $tags->get_attribute('data-wpcc')) {
                continue;
            }

            $id = (string) $tags->get_attribute('id');
            if (str_starts_with($id, 'wpcc-')) {
                continue;
            }

            $src = $tags->get_attribute('src');
            $src = is_string($src) ? $src : '';

            if ('SCRIPT' === $tag) {
                $type = strtolower(trim((string) $tags->get_attribute('type')));

                if (!in_array($type, self::JS_TYPES, true)) {
                    continue;
                }

                $service = '' !== $src
                    ? Registry::match_url($src)
                    : Registry::match_inline((string) $tags->get_modifiable_text());

                if (!$service) {
                    continue;
                }

                $tags->set_attribute('data-wpcc', $service['category']);
                $tags->set_attribute('data-wpcc-name', $service['name']);
                $tags->set_attribute('data-wpcc-type', $type);
                $tags->set_attribute('type', 'text/plain');
                continue;
            }

            $service = Registry::match_url($src);

            if (!$service) {
                continue;
            }

            $tags->set_attribute('data-wpcc', $service['category']);
            $tags->set_attribute('data-wpcc-name', $service['name']);
            $tags->set_attribute('data-wpcc-src', $src);
            $tags->remove_attribute('src');
            $tags->remove_attribute('srcset');

            if ('IFRAME' === $tag) {
                $tags->add_class('wpcc-blocked');
            }
        }

        return $tags->get_updated_html();
    }

    private function is_html_response(): bool
    {
        foreach (headers_list() as $header) {
            if (0 === stripos($header, 'content-type:')) {
                return false !== stripos($header, 'html');
            }
        }

        return true;
    }
}
