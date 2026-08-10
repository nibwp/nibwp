<?php

declare(strict_types=1);

/**
 * NIBWP Pro upsell: submenu entry, Connect-page card, dismissible welcome notice.
 */

if (!defined('ABSPATH')) {
    exit();
}

// The standalone nibwp-pro plugin's entry file also defines NIBWP_PRO_URL
// (as `plugin_dir_url(__FILE__)`). If that plugin is active alongside, the
// `const` declaration below would warn + clobber. Guard with `defined()`.
if (!defined('NIBWP_PRO_URL')) {
    define('NIBWP_PRO_URL', 'https://www.nibwp.com/pro/');
}

if (!defined('NIBWP_PRO_DISMISS_PREFIX')) {
    define('NIBWP_PRO_DISMISS_PREFIX', 'nibwp_pro_dismissed_');
}

if (!defined('NIBWP_PRO_WELCOME_KEY')) {
    define('NIBWP_PRO_WELCOME_KEY', 'welcome');
}

/**
 * True when the NIBWP Pro plugin is active.
 * All Pro features are now built-in, so this always returns true.
 */
function nibwp_pro_is_active(): bool
{
    return true;
}

/**
 * Append a "Get Pro" submenu entry that links out to nibwp.com/pro/.
 * Uses the $submenu global because add_submenu_page() doesn't accept external URLs.
 */
add_action(
    'admin_menu',
    static function (): void {
        if (nibwp_pro_is_active()) {
            return;
        }
        // @mago-expect lint:no-global
        global $submenu;
        if (!is_array($submenu) || !array_key_exists('nibwp-dashboard', $submenu)) {
            return;
        }
        // @mago-expect analysis:mixed-array-assignment
        $submenu['nibwp-dashboard'][] = [
            '<span style="color:#f8ca50;font-weight:600;">' . esc_html__('Get Pro', domain: 'nibwp') . '</span>',
            'manage_options',
            esc_url(NIBWP_PRO_URL . '?utm_source=plugin&utm_medium=submenu'),
        ];
    },
    priority: 99,
);

/**
 * Add a "Get Pro" action link on the Plugins page row for NIBWP Free.
 */
add_filter(
    'plugin_action_links_' . plugin_basename(dirname(__DIR__) . '/nibwp.php'),
    static function (array $links): array {
        if (nibwp_pro_is_active()) {
            return $links;
        }
        $url = esc_url(NIBWP_PRO_URL . '?utm_source=plugin&utm_medium=plugins_row');
        $links[] =
            '<a href="'
            . $url
            . '" target="_blank" rel="noopener">'
            . esc_html__('Get Pro', domain: 'nibwp')
            . '</a>';
        return $links;
    },
);

add_action('admin_footer', static function (): void {
    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <script>
    (function() {
        var links = document.querySelectorAll('#toplevel_page_nibwp-dashboard .wp-submenu a');
        for (var i = 0; i < links.length; i++) {
            if (links[i].href.indexOf('nibwp.com/pro') !== -1) {
                links[i].target = '_blank';
                links[i].rel = 'noopener';
            }
        }
    })();
    </script>
    <?php
});

/**
 * Flag the welcome notice on first activation.
 */
register_activation_hook(dirname(__DIR__) . '/nibwp.php', callback: 'nibwp_pro_upsell_on_activate');
function nibwp_pro_upsell_on_activate(): void
{
    if (get_option('nibwp_pro_upsell_installed_at') === false) {
        update_option('nibwp_pro_upsell_installed_at', time(), autoload: false);
    }
}

/**
 * Handle dismiss requests (AJAX GET from the notice).
 */
add_action('admin_init', static function (): void {
    $key = $_GET['nibwp_pro_dismiss'] ?? null;
    if (!is_string($key) || $key === '') {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }
    $key = sanitize_key($key);
    update_user_meta(get_current_user_id(), NIBWP_PRO_DISMISS_PREFIX . $key, meta_value: 1);
    wp_die('Dismissed', title: 'Dismissed', args: ['response' => 200]);
});

/**
 * Render the one-time welcome notice until dismissed.
 */
add_action('admin_notices', 'nibwp_render_pro_welcome_notice');

function nibwp_render_pro_welcome_notice(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }
    if (nibwp_pro_is_active()) {
        return;
    }
    $user_id = get_current_user_id();
    if (get_user_meta($user_id, NIBWP_PRO_DISMISS_PREFIX . NIBWP_PRO_WELCOME_KEY, single: true)) {
        return;
    }
    // Don't show on the Pro page itself or irrelevant screens outside NIBWP admin.
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $on_nibwp =
        $screen
        && (
            str_starts_with($screen->id, 'toplevel_page_nibwp')
            || str_starts_with($screen->id, 'nibwp_page_')
            || $screen->id === 'dashboard'
            || $screen->id === 'plugins'
        );
    if (!$on_nibwp) {
        return;
    }

    $dismiss_url = add_query_arg(['nibwp_pro_dismiss' => NIBWP_PRO_WELCOME_KEY], admin_url());
    $pro_url = esc_url(NIBWP_PRO_URL . '?utm_source=plugin&utm_medium=welcome_notice');
    ?>
    <div class="notice notice-info is-dismissible nibwp-pro-notice" data-dismiss-url="<?php echo
        esc_url($dismiss_url)
    ; ?>" style="border-left-color:#f8ca50;">
        <p style="font-size:14px;margin:10px 0;">
            <strong><?php esc_html_e('NIBWP Pro is here.', domain: 'nibwp'); ?></strong>
            <?php esc_html_e(
                'Elementor and Bricks abilities and memory between sessions, on top of NIBWP.',
                domain: 'nibwp',
            ); ?>
            &nbsp;
            <a href="<?php echo
                $pro_url
            ; ?>" target="_blank" rel="noopener" class="button button-primary" style="background:#f8ca50;border-color:#f8ca50;color:#1a1a1a;">
                <?php esc_html_e('Discover more', domain: 'nibwp'); ?>
            </a>
        </p>
    </div>
    <script>
    (function() {
        var notices = document.querySelectorAll('.nibwp-pro-notice');
        for (var i = 0; i < notices.length; i++) {
            notices[i].addEventListener('click', function(e) {
                if (e.target && e.target.classList.contains('notice-dismiss')) {
                    var url = this.getAttribute('data-dismiss-url');
                    if (url) { fetch(url, {credentials: 'same-origin'}); }
                }
            });
        }
    })();
    </script>
    <?php
}

/**
 * Render a Pro upsell card — called from the Connect page.
 */
function nibwp_render_pro_upsell_card(): void
{
    if (nibwp_pro_is_active()) {
        return;
    }
    $pro_url = esc_url(NIBWP_PRO_URL . '?utm_source=plugin&utm_medium=connect_card');
    ?>
    <div class="nibwp-pro-card" style="margin:24px 0;padding:20px 24px;border:1px solid #e0e0e0;border-left:4px solid #f8ca50;border-radius:4px;background:#fffdf5;">
        <h2 style="margin:0 0 6px;font-size:16px;">
            <?php esc_html_e('NIBWP Pro', domain: 'nibwp'); ?>
            <span style="display:inline-block;margin-left:6px;padding:1px 8px;font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;background:#f8ca50;color:#1a1a1a;border-radius:3px;vertical-align:middle;">
                <?php esc_html_e('Beta', domain: 'nibwp'); ?>
            </span>
        </h2>
        <p style="margin:0 0 12px;color:#50575e;">
            <?php esc_html_e(
                'Ready-made abilities for Elementor and Bricks, plus memory between sessions.',
                domain: 'nibwp',
            ); ?>
        </p>
        <a href="<?php echo
            $pro_url
        ; ?>" target="_blank" rel="noopener" class="button button-primary" style="background:#f8ca50;border-color:#f8ca50;color:#1a1a1a;">
            <?php esc_html_e('Get NIBWP Pro', domain: 'nibwp'); ?>
        </a>
    </div>
    <?php
}
