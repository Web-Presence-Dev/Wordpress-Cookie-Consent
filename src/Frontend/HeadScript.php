<?php

namespace WebPresence\CookieConsent\Frontend;

use WebPresence\CookieConsent\Plugin;
use WebPresence\CookieConsent\Services\Registry;
use WebPresence\CookieConsent\Settings;

defined('ABSPATH') || exit;

/**
 * The first thing in <head>:
 *
 * 1. Config for the browser (rules, cookie patterns, saved-choice cookie).
 * 2. Google Consent Mode v2 defaults: every type "denied" before any Google
 *    tag loads, with a saved choice re-applied straight away so returning
 *    visitors are measured from the first page view. This runs even when the
 *    plugin doesn't load GA4 itself, so a GA4 tag added by another plugin
 *    (Site Kit, MonsterInsights, a GTM container) still starts denied.
 * 3. assets/js/blocker.js, which stops trackers that other scripts add later.
 *
 * Ported from the webpresence theme's lib/Theme/consent.php.
 */
class HeadScript
{
    public function __construct()
    {
        add_action('wp_head', [$this, 'print'], -1000);
        add_action('wp_head', [$this, 'print_style'], 100);
    }

    public static function config(): array
    {
        $cookies = [];

        foreach (Registry::catalogue() as $service) {
            $overrides = (array) Settings::get('service_overrides', []);
            $category = $overrides[$service['id']] ?? 'default';
            $category = Registry::effective_category('default' === $category || 'off' === $category ? $service['category'] : $category);

            if ($category && !empty($service['cookies'])) {
                $cookies[$category] = array_values(array_unique(array_merge($cookies[$category] ?? [], $service['cookies'])));
            }
        }

        return [
            'cookie' => Plugin::CONSENT_COOKIE,
            'version' => (int) Settings::get('version'),
            'lifetime' => (int) Settings::get('lifetime_days'),
            'categories' => Settings::enabled_categories(),
            'rules' => Registry::client_rules(),
            'cookies' => $cookies,
            'google' => [
                'mode' => Settings::get('google_mode'),
                'id' => Settings::measurement_id(),
            ],
        ];
    }

    public function print(): void
    {
        if (!Plugin::is_active_request()) {
            return;
        }

        $head = 'window.wpccConfig=' . wp_json_encode(self::config()) . ';' . <<<'JS'
window.dataLayer=window.dataLayer||[];
window.gtag=window.gtag||function(){dataLayer.push(arguments);};
(function(c){
    var saved=null;
    try{
        var m=document.cookie.match(new RegExp('(?:^|; )'+c.cookie+'=([^;]*)'));
        saved=m?JSON.parse(decodeURIComponent(m[1])):null;
        if(!saved||saved.v!==c.version||Date.now()-saved.t>c.lifetime*864e5){saved=null;}
    }catch(e){saved=null;}
    window.wpccConsent=saved;

    gtag('consent','default',{
        ad_storage:'denied',
        ad_user_data:'denied',
        ad_personalization:'denied',
        analytics_storage:'denied',
        functionality_storage:'denied',
        personalization_storage:'denied',
        security_storage:'granted',
        wait_for_update:500
    });
    gtag('set','ads_data_redaction',true);
    gtag('set','url_passthrough',false);

    if(saved){
        var g=function(v){return v?'granted':'denied';};
        gtag('consent','update',{
            analytics_storage:g(saved.analytics),
            ad_storage:g(saved.marketing),
            ad_user_data:g(saved.marketing),
            ad_personalization:g(saved.marketing),
            functionality_storage:g(saved.functional),
            personalization_storage:g(saved.functional)
        });
    }

    var loaded=false;
    window.wpccLoadGtag=function(){
        if(loaded||!c.google.id){return;}
        loaded=true;
        var s=document.createElement('script');
        s.setAttribute('data-wpcc-ignore','');
        s.async=true;
        s.src='https://www.googletagmanager.com/gtag/js?id='+encodeURIComponent(c.google.id);
        document.head.appendChild(s);
    };

    if(c.google.id){
        gtag('js',new Date());
        gtag('config',c.google.id);
        if(c.google.mode==='advanced'||(saved&&saved.analytics)){
            window.wpccLoadGtag();
        }
    }
})(window.wpccConfig);
JS;

        $blocker = (string) file_get_contents(WPCC_DIR . 'assets/js/blocker.js');

        wp_print_inline_script_tag($head . "\n" . $blocker, ['id' => 'wpcc-head', 'data-wpcc-ignore' => '']);
    }

    /**
     * Colours, font and radius from the Appearance tab as custom properties.
     */
    public function print_style(): void
    {
        if (!Plugin::is_active_request()) {
            return;
        }

        echo '<style id="wpcc-vars">' . self::css_vars() . '</style>' . "\n";
    }

    public static function css_vars(?array $settings = null): string
    {
        $s = $settings ?? Settings::all();

        $vars = static function (array $colors): string {
            $css = '';
            foreach (Settings::COLOR_KEYS as $key) {
                if (!empty($colors[$key])) {
                    $css .= '--wpcc-' . str_replace('_', '-', $key) . ':' . $colors[$key] . ';';
                }
            }
            // Own hover colours replace the default brighten effect
            $hover = !empty($colors['primary_hover_bg']) || !empty($colors['primary_hover_text']);
            $css .= '--wpcc-primary-hover-filter:' . ($hover ? 'none' : 'brightness(1.12)') . ';';
            return $css;
        };

        $font = match ($s['font']) {
            'system' => 'system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif',
            'custom' => '' !== trim((string) $s['font_custom']) ? $s['font_custom'] . ',system-ui,sans-serif' : 'inherit',
            default => 'inherit',
        };

        $css = ':root{' . $vars($s['colors'])
            . '--wpcc-font:' . $font . ';'
            . '--wpcc-font-size:' . (int) $s['font_size'] . 'px;'
            . '--wpcc-radius:' . (int) $s['radius'] . 'px;'
            . '--wpcc-button-radius:' . (int) $s['button_radius'] . 'px;}';

        if (!empty($s['dark_enabled'])) {
            $css .= '@media (prefers-color-scheme:dark){:root{' . $vars($s['dark_colors']) . '}}';
        }

        // Values are sanitised on save; this stops anything closing the tag
        return str_replace(['<', '>'], '', $css);
    }
}
