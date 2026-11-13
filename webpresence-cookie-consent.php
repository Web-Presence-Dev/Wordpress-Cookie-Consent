<?php
/**
 * Plugin Name:       Web Presence Cookie Consent
 * Plugin URI:        https://github.com/Web-Presence-Dev/Wordpress-Cookie-Consent
 * Description:       Cookie consent banner that finds and blocks GA4 and other tracking until visitors agree. Google Consent Mode v2 included.
 * Version:           0.1.1
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Web Presence
 * Author URI:        https://webpresence.digital/
 * License:           GPL-2.0-or-later
 * Text Domain:       wpcc
 * Domain Path:       /languages
 * Update URI:        https://github.com/Web-Presence-Dev/Wordpress-Cookie-Consent
 */

defined('ABSPATH') || exit;

define('WPCC_VERSION', '0.1.1');
define('WPCC_FILE', __FILE__);
define('WPCC_DIR', plugin_dir_path(__FILE__));
define('WPCC_URL', plugin_dir_url(__FILE__));
define('WPCC_BASENAME', plugin_basename(__FILE__));
define('WPCC_SLUG', 'webpresence-cookie-consent');
define('WPCC_REPO', 'Web-Presence-Dev/Wordpress-Cookie-Consent');

spl_autoload_register(static function (string $class): void {
    $prefix = 'WebPresence\\CookieConsent\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = WPCC_DIR . 'src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_readable($file)) {
        require $file;
    }
});

register_activation_hook(__FILE__, [WebPresence\CookieConsent\Settings::class, 'activate']);

add_action('plugins_loaded', static function (): void {
    WebPresence\CookieConsent\Plugin::instance();
});
