<?php

namespace WebPresence\CookieConsent\Integrations;

defined('ABSPATH') || exit;

/**
 * WP Consent API (https://wordpress.org/plugins/wp-consent-api/).
 *
 * When that plugin is installed, other plugins (WooCommerce, Site Kit, etc.)
 * ask it for consent instead of reading our cookie. Tell it we're the consent
 * manager and that the site is opt-in; consent.js sets each category with
 * wp_set_consent() whenever the visitor chooses.
 */
class WpConsentApi
{
    public function __construct()
    {
        add_filter('wp_consent_api_registered_' . WPCC_BASENAME, '__return_true');
        add_filter('wp_get_consent_type', static fn () => 'optin', 20);
    }
}
