<?php

namespace WebPresence\CookieConsent\Frontend;

use WebPresence\CookieConsent\Plugin;
use WebPresence\CookieConsent\Settings;

defined('ABSPATH') || exit;

/**
 * Cookie banner and preferences dialog, plus the [cookie_settings] shortcode.
 *
 * Ported from the webpresence theme's components/modules/cookie-consent.php.
 * The banner is printed at the top of <body> (wp_body_open) so keyboard and
 * screen reader users reach it first; it shows fixed on screen without
 * blocking the page. Themes without wp_body_open get it in the footer.
 */
class Banner
{
    private bool $printed = false;

    public function __construct()
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_action('wp_body_open', [$this, 'print'], 5);
        add_action('wp_footer', [$this, 'print'], 5);

        add_shortcode('cookie_settings', [$this, 'shortcode']);
        add_shortcode('wpcc_preferences', [$this, 'shortcode']);
    }

    public function enqueue(): void
    {
        if (!Plugin::is_active_request()) {
            return;
        }

        wp_enqueue_style('wpcc-banner', WPCC_URL . 'assets/css/banner.css', [], Plugin::asset_version('assets/css/banner.css'));
        wp_enqueue_script('wpcc-consent', WPCC_URL . 'assets/js/consent.js', [], Plugin::asset_version('assets/js/consent.js'), ['strategy' => 'defer', 'in_footer' => false]);
    }

    /**
     * [cookie_settings text="Cookie settings" type="button|link" class=""]
     *
     * A button that opens the preferences dialog. With `class`, the plugin's
     * own button styles are left off so the theme can style it.
     */
    /**
     * Every Accept / Reject / Save / "Allow and load" button goes through here.
     *
     * $variant is 'primary' (main) or 'secondary' (outline). Plugin styles use
     * .wpcc-button; with "Use my theme's buttons" on, the theme's classes from
     * settings are used instead. Themes can replace the whole button with the
     * `wpcc_button_html` filter, e.g. to add an icon (see the README).
     *
     * $attributes must stay on the button: the plugin's script finds buttons by
     * them (data-wpcc-action, or the placeholder's class).
     */
    public static function button(string $action, string $label, string $variant = 'primary', ?array $s = null, array $extra_classes = []): string
    {
        $s ??= Settings::all();
        $variant = 'secondary' === $variant ? 'secondary' : 'primary';

        if (!empty($s['theme_buttons'])) {
            $theme = (string) ($s['button_class_' . $variant] ?? '');
            $classes = array_merge(['wpcc-btn', 'wpcc-btn--' . $variant], preg_split('/\s+/', trim($theme)) ?: [], $extra_classes);
        } else {
            $classes = array_merge(['wpcc-button', 'wpcc-button--' . $variant], $extra_classes);
        }

        $class = implode(' ', array_filter(array_map('sanitize_html_class', $classes)));
        $attributes = 'type="button"' . ('' !== $action ? ' data-wpcc-action="' . esc_attr($action) . '"' : '');

        $html = sprintf('<button %s class="%s">%s</button>', $attributes, esc_attr($class), esc_html($label));

        /**
         * Replace a consent button's markup, e.g. to use the theme's button
         * component. Keep $args['attributes'] and $args['class'] on the
         * element, or the button will stop working.
         *
         * @param string $html Default markup.
         * @param array  $args {
         *     @type string $action     accept | reject | save | '' (placeholder "Allow and load").
         *     @type string $label      Button text (not escaped).
         *     @type string $variant    primary (main) | secondary (outline).
         *     @type string $attributes Escaped attributes the plugin needs.
         *     @type string $class      Escaped class list (plugin + settings).
         * }
         */
        return (string) apply_filters('wpcc_button_html', $html, [
            'action' => $action,
            'label' => $label,
            'variant' => $variant,
            'attributes' => $attributes,
            'class' => esc_attr($class),
        ]);
    }

    public function shortcode(array|string $atts = []): string
    {
        if (!Plugin::is_active_request()) {
            return '';
        }

        $text = Settings::get('text');
        $atts = shortcode_atts([
            'text' => $text['settings'],
            'type' => 'button',
            'class' => '',
        ], (array) $atts, 'cookie_settings');

        $classes = ['wpcc-open'];

        if ('' !== trim($atts['class'])) {
            $classes = array_merge($classes, preg_split('/\s+/', trim($atts['class'])));
        } else {
            if ('link' === $atts['type']) {
                $classes[] = 'wpcc-link';
            } elseif (Settings::get('theme_buttons')) {
                $classes = array_merge($classes, preg_split('/\s+/', (string) Settings::get('button_class_primary')) ?: []);
            } else {
                array_push($classes, 'wpcc-button', 'wpcc-button--primary');
            }
        }

        return sprintf(
            '<button type="button" class="%s" aria-haspopup="dialog" aria-controls="wpcc-dialog">%s</button>',
            esc_attr(implode(' ', array_map('sanitize_html_class', $classes))),
            esc_html($atts['text'])
        );
    }

    public function print(): void
    {
        if ($this->printed || !Plugin::is_active_request()) {
            return;
        }

        $this->printed = true;

        echo self::markup(Settings::all()); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in markup()
    }

    /**
     * The banner and dialog HTML. Also used by the settings page preview.
     */
    public static function markup(array $s, bool $preview = false): string
    {
        $text = $s['text'];
        $policy_url = $preview ? '#' : Settings::policy_url();
        $policy = '' !== $policy_url
            ? ' <a href="' . esc_url($policy_url) . '">' . esc_html($text['policy']) . '</a>'
            : '';

        $position = in_array($s['position'], Settings::POSITIONS, true) ? $s['position'] : 'card-left';
        $button = static fn (string $action, string $label, string $variant) => self::button($action, $label, $variant, $s);

        ob_start();
        ?>
<div class="wpcc-root" id="wpcc-root" data-wpcc-ignore>
    <?php if ('modal' === $position) : ?>
        <div class="wpcc-overlay" hidden></div>
    <?php endif; ?>

    <section class="wpcc-banner wpcc-banner--<?php echo esc_attr($position); ?>" aria-label="<?php esc_attr_e('Cookie consent', 'wpcc'); ?>" <?php echo $preview ? '' : 'hidden'; ?>>
        <p class="wpcc-banner__text"><?php echo esc_html($text['banner']); ?><?php echo $policy; // phpcs:ignore ?></p>

        <div class="wpcc-banner__actions">
            <?php echo $button('accept', $text['accept'], 'primary'); // phpcs:ignore ?>
            <?php echo $button('reject', $text['reject'], 'primary'); // phpcs:ignore ?>
            <button type="button" class="wpcc-link wpcc-open" aria-haspopup="dialog" aria-controls="wpcc-dialog"><?php echo esc_html($text['manage']); ?></button>
        </div>
    </section>

    <dialog class="wpcc-dialog" id="wpcc-dialog" aria-labelledby="wpcc-dialog-title" closedby="any">
        <form class="wpcc-dialog__inner" method="dialog">
            <h2 class="wpcc-dialog__title" id="wpcc-dialog-title"><?php echo esc_html($text['title']); ?></h2>

            <p class="wpcc-dialog__intro"><?php echo esc_html($text['intro']); ?><?php echo $policy; // phpcs:ignore ?></p>

            <ul class="wpcc-dialog__list">
                <li class="wpcc-dialog__item">
                    <div class="wpcc-dialog__row">
                        <h3 class="wpcc-dialog__label"><?php esc_html_e('Strictly necessary', 'wpcc'); ?></h3>
                        <span class="wpcc-dialog__always"><?php esc_html_e('Always on', 'wpcc'); ?></span>
                    </div>
                    <p class="wpcc-dialog__description"><?php echo esc_html($s['necessary_description']); ?></p>
                </li>

                <?php foreach (Settings::CATEGORIES as $name) :
                    $category = $s['categories'][$name] ?? [];
                    if (empty($category['enabled'])) {
                        continue;
                    }
                    $id = 'wpcc-category-' . $name;
                    ?>
                    <li class="wpcc-dialog__item">
                        <div class="wpcc-dialog__row">
                            <label class="wpcc-dialog__label" for="<?php echo esc_attr($id); ?>"><?php echo esc_html($category['label']); ?></label>
                            <input class="wpcc-switch" type="checkbox" role="switch" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" aria-describedby="<?php echo esc_attr($id); ?>-description">
                        </div>
                        <p class="wpcc-dialog__description" id="<?php echo esc_attr($id); ?>-description"><?php echo esc_html($category['description']); ?></p>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="wpcc-dialog__actions">
                <?php echo $button('save', $text['save'], 'primary'); // phpcs:ignore ?>
                <?php echo $button('accept', $text['accept'], 'secondary'); // phpcs:ignore ?>
                <?php echo $button('reject', $text['reject'], 'secondary'); // phpcs:ignore ?>
            </div>
        </form>

        <button type="button" class="wpcc-dialog__close" data-wpcc-action="close" aria-label="<?php esc_attr_e('Close cookie preferences', 'wpcc'); ?>">
            <svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        </button>
    </dialog>

    <?php if (!empty($s['floating_button'])) : ?>
        <button type="button" class="wpcc-floating wpcc-open" aria-haspopup="dialog" aria-controls="wpcc-dialog" aria-label="<?php echo esc_attr($text['settings']); ?>" title="<?php echo esc_attr($text['settings']); ?>">
            <svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M21.6 11.2a3 3 0 0 1-3.3-2.6 3 3 0 0 1-2.9-3.3A3 3 0 0 1 13 2.1 10 10 0 1 0 21.9 11a3 3 0 0 1-.3.2ZM8 10.5A1.5 1.5 0 1 1 8 7.5a1.5 1.5 0 0 1 0 3Zm1 6a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm6 1a1 1 0 1 1 0-2 1 1 0 0 1 0 2Zm1.5-5a1 1 0 1 1 0-2 1 1 0 0 1 0 2Z"/></svg>
        </button>
    <?php endif; ?>

    <template id="wpcc-placeholder">
        <div class="wpcc-placeholder" data-wpcc-ignore>
            <p class="wpcc-placeholder__text"></p>
            <?php echo self::button('', $text['allow'], 'primary', $s, ['wpcc-placeholder__allow']); // phpcs:ignore -- escaped in button() ?>
            <button type="button" class="wpcc-link wpcc-open" aria-haspopup="dialog" aria-controls="wpcc-dialog"><?php echo esc_html($text['settings']); ?></button>
        </div>
    </template>
</div>
<script type="application/json" id="wpcc-i18n" data-wpcc-ignore><?php
        echo wp_json_encode([
            'blocked' => $text['blocked'],
            'categories' => array_map(static fn ($c) => $c['label'], $s['categories']),
        ], JSON_HEX_TAG | JSON_HEX_AMP);
        ?></script>
        <?php

        return (string) ob_get_clean();
    }
}
