<?php

declare(strict_types=1);

/**
 * The workspace: a full-screen page holding the tabs the agent drives.
 *
 * It renders outside the admin shell on purpose. This screen is a window onto
 * the site, and a sidebar wrapped around a sidebar helps nobody.
 */

if (!defined('ABSPATH')) {
    exit();
}

add_action('admin_menu', 'nibwp_visual_menu', 80);
add_action('admin_post_nibwp_agent_view', 'nibwp_visual_render');
add_action('admin_post_nopriv_nibwp_agent_view', static function (): void {
    auth_redirect();
});

// The page was `nibwp_visual` before it was called Agent View. Anything already
// bookmarked or linked keeps working instead of landing on a blank admin-post
// response.
add_action('admin_post_nibwp_visual', static function (): void {
    wp_safe_redirect(nibwp_visual_url());
    exit;
});
add_action('admin_post_nopriv_nibwp_visual', static function (): void {
    auth_redirect();
});

/**
 * Cache-bust the workspace assets on file change, not just on a version bump.
 *
 * Versioning by NIBWP_VERSION alone means any fix that ships without bumping
 * the version — a release candidate, a hotfix, anything during development —
 * leaves browsers running the old JavaScript. That cost me an hour chasing a
 * bug that was already fixed on disk.
 */
function nibwp_visual_asset_version(string $relative): string
{
    $version = defined('NIBWP_VERSION') ? (string) NIBWP_VERSION : '1';
    $file = NIBWP_PLUGIN_DIR . $relative;
    $stamp = file_exists($file) ? filemtime($file) : false;

    return $stamp === false ? $version : $version . '.' . $stamp;
}

function nibwp_visual_url(): string
{
    return admin_url('admin-post.php?action=nibwp_agent_view');
}

/**
 * The mark beside the workspace entry.
 *
 * A sparkle rather than a screen or a play button: what this menu item leads to
 * is the one place in the plugin where something happens by itself while you
 * watch, and it should read as the lively one in a column of settings pages.
 */
function nibwp_visual_spark_svg(int $size = 13, string $class = ''): string
{
    // Each copy needs its own gradient id. Both menus render on the same admin
    // page, and two elements sharing an id means one of the two sparkles paints
    // with the other's gradient — or with nothing at all.
    static $seq = 0;
    $id = 'nibwp-spark-' . (++$seq);

    $paths = '<path d="M11.2 2.6a.85.85 0 0 1 1.6 0l1.5 4.1a.85.85 0 0 0 .5.5l4.1 1.5a.85.85 0 0 1 0 1.6l-4.1 1.5a.85.85 0 0 0-.5.5l-1.5 4.1a.85.85 0 0 1-1.6 0l-1.5-4.1a.85.85 0 0 0-.5-.5l-4.1-1.5a.85.85 0 0 1 0-1.6l4.1-1.5a.85.85 0 0 0 .5-.5l1.5-4.1z"/>'
        . '<path d="M18.4 15.1a.5.5 0 0 1 .95 0l.62 1.7a.5.5 0 0 0 .3.3l1.7.62a.5.5 0 0 1 0 .95l-1.7.62a.5.5 0 0 0-.3.3l-.62 1.7a.5.5 0 0 1-.95 0l-.62-1.7a.5.5 0 0 0-.3-.3l-1.7-.62a.5.5 0 0 1 0-.95l1.7-.62a.5.5 0 0 0 .3-.3l.62-1.7z"/>';

    return sprintf(
        '<svg class="%4$s" width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="url(#%2$s)" focusable="false" aria-hidden="true">'
            . '<defs><linearGradient id="%2$s" x1="0" y1="0" x2="1" y2="1">'
            . '<stop offset="0%%" stop-color="#ffd166"/>'
            . '<stop offset="38%%" stop-color="#ffa62b"/>'
            . '<stop offset="70%%" stop-color="#ff5f9e"/>'
            . '<stop offset="100%%" stop-color="#8b5cf6"/>'
            . '</linearGradient></defs>%3$s</svg>',
        $size,
        $id,
        $paths,
        esc_attr(trim('nibwp-spark ' . $class))
    );
}

function nibwp_visual_menu_mark(): string
{
    return '<span class="nibwp-menu-spark" aria-hidden="true">' . nibwp_visual_spark_svg(13) . '</span>';
}

function nibwp_visual_menu(): void
{
    // The mark is part of the title because WordPress gives a submenu item no
    // other place to put one — there is no icon argument below the top level.
    $label = '<span class="nibwp-menu-spark__text">' . esc_html__('Agent View', 'nibwp')
        . '</span>' . nibwp_visual_menu_mark();

    $hook = add_submenu_page(
        parent_slug: 'nibwp-dashboard',
        page_title: __('Agent View', domain: 'nibwp'),
        menu_title: $label,
        capability: 'manage_options',
        menu_slug: 'nibwp-visual',
        callback: '__return_null',
    );

    if ($hook === false) {
        return;
    }

    // The menu entry is a doorway, not a page: land straight in the workspace.
    add_action('load-' . $hook, static function (): void {
        wp_safe_redirect(nibwp_visual_url());
        exit;
    });
}

function nibwp_visual_render(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(
            esc_html__('You do not have permission to open the workspace.', 'nibwp'),
            esc_html__('Agent View', 'nibwp'),
            ['response' => 403]
        );
    }

    $boot = [
        'rest' => esc_url_raw(rest_url('nibwp/v1/visual/')),
        'ajax' => esc_url_raw(admin_url('admin-ajax.php')),
        'nonce' => wp_create_nonce('wp_rest'),
        'home' => esc_url_raw(home_url('/')),
        'admin' => esc_url_raw(admin_url()),
        'siteName' => (string) get_bloginfo('name'),
        'approval' => nibwp_visual_approval_required(),
        'connected' => function_exists('nibwp_visual_connection_state')
            ? (bool) nibwp_visual_connection_state()['connected']
            : false,
        'enabled' => function_exists('nibwp_is_enabled') ? (bool) nibwp_is_enabled() : true,
        'brand' => function_exists('nibwp_branding_display_name') ? nibwp_branding_display_name() : 'NIBWP',
        'locked' => function_exists('nibwp_visual_upsell_data') ? nibwp_visual_upsell_data() : [],
        'catalogue' => function_exists('nibwp_visual_catalogue') ? nibwp_visual_catalogue() : [],
    ];

    status_header(200);
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html(sprintf(
    /* translators: %s: site name */
    __('Agent View — %s', 'nibwp'),
    get_bloginfo('name')
)); ?></title>
<link rel="stylesheet" href="<?php echo esc_url(NIBWP_PLUGIN_URL . 'assets/css/admin-visual.css?v=' . nibwp_visual_asset_version('assets/css/admin-visual.css')); ?>">
<script>
// Before the first paint, and before the stylesheet has anything to react to.
// Reading this later means a dark workspace flashes white on every load, which
// is the one thing worse than being in the wrong theme.
(function () {
    try {
        var t = window.localStorage.getItem('nibwp-visual-theme');
        if (t === 'light' || t === 'dark') { document.documentElement.setAttribute('data-theme', t); }
    } catch (e) { /* private mode: the system preference still applies */ }
})();
</script>
</head>
<body class="nw-vs-body">
<div class="nw-vs" id="nw-vs">
    <header class="nw-vs-bar">
        <?php // The panel is for getting started; once a page is open the site
              // is the point, so this puts it away and brings it back. ?>
        <button type="button" class="nw-vs-toggle" id="nw-vs-toggle"
                aria-expanded="true" aria-controls="nw-vs-side"
                data-tip="<?php esc_attr_e('Show or hide the panel', 'nibwp'); ?>">
            <span class="nw-vs-toggle__bars" aria-hidden="true"></span>
            <span class="screen-reader-text"><?php esc_html_e('Show or hide the panel', 'nibwp'); ?></span>
        </button>

        <span class="nw-vs-brand">
            <img src="<?php echo esc_url(NIBWP_PLUGIN_URL . 'assets/nibwp-icon.svg'); ?>" alt="">
            <span><?php echo esc_html($boot['brand']); ?></span>
        </span>

        <?php $nw_vs_ready = $boot['connected'] && $boot['enabled'] ?? false; ?>
        <span class="nw-vs-status<?php echo $nw_vs_ready ? ' is-ready' : ' is-off'; ?>" id="nw-vs-status" role="status" aria-live="polite">
            <span class="nw-vs-dot" aria-hidden="true"></span>
            <span class="nw-vs-status__text"><?php
                echo $nw_vs_ready
                    ? esc_html__('Ready', 'nibwp')
                    : esc_html__('No AI client connected', 'nibwp');
            ?></span>
        </span>

        <?php // The way back to the screen that explains connecting. It is the
              // first thing you see and then never again, which is wrong the
              // moment a connection breaks or a second assistant turns up. ?>
        <button type="button" class="nw-vs-ibtn" id="nw-vs-reconnect" aria-expanded="false" aria-controls="nw-vs-start"
                data-tip="<?php esc_attr_e('Connect an assistant', 'nibwp'); ?>" aria-label="<?php esc_attr_e('Connect an assistant', 'nibwp'); ?>">
            <?php echo nibwp_visual_icon('plug'); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?>
        </button>

        <?php // The bar reads its state from the page it was rendered with, so a
              // connection made after that keeps showing as absent until
              // something reloads. Rather than have people wonder whether the
              // screen or the connection is broken, offer the reload. ?>
        <?php if (!$nw_vs_ready): ?>
            <button type="button" class="nw-vs-ibtn nw-vs-recheck" id="nw-vs-recheck"
                    data-tip="<?php esc_attr_e('Already connected? Load this screen again', 'nibwp'); ?>">
                <?php echo nibwp_visual_icon('refresh', 14); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?>
                <span class="nw-vs-ibtn__text"><?php esc_html_e('It is connected', 'nibwp'); ?></span>
            </button>
        <?php endif; ?>

        <?php // Jump anywhere on the site without leaving the workspace: each of
              // these opens as a tab beside whatever the agent is doing. ?>
        <div class="nw-vs-menu" id="nw-vs-menu">
            <button type="button" class="nw-vs-ibtn" id="nw-vs-menu-btn"
                    data-tip="<?php esc_attr_e('Go to any screen on this site', 'nibwp'); ?>"
                    aria-expanded="false" aria-haspopup="true" aria-controls="nw-vs-menu-list">
                <?php echo nibwp_visual_icon('grid'); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?>
                <span class="nw-vs-ibtn__text"><?php esc_html_e('Go to', 'nibwp'); ?></span>
                <?php echo nibwp_visual_icon('chevron', 13); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?>
            </button>

            <div class="nw-vs-menu__list" id="nw-vs-menu-list" role="menu" hidden>
                <?php // The whole admin menu can run to two hundred entries on a
                      // busy site, which is a scroll, not a menu. ?>
                <input type="search" class="nw-vs-menu__find" id="nw-vs-menu-find"
                       autocomplete="off" spellcheck="false"
                       placeholder="<?php esc_attr_e('Filter…', 'nibwp'); ?>"
                       aria-label="<?php esc_attr_e('Filter the list', 'nibwp'); ?>">
                <p class="nw-vs-menu__none" id="nw-vs-menu-none" hidden><?php esc_html_e('Nothing matches.', 'nibwp'); ?></p>

                <?php foreach (nibwp_visual_shortcuts() as $group): ?>
                    <p class="nw-vs-menu__h"><?php echo esc_html($group['label']); ?></p>
                    <?php foreach ($group['items'] as $item): ?>
                        <button type="button" class="nw-vs-menu__item" role="menuitem"
                                data-vs-goto="<?php echo esc_url($item['url']); ?>"
                                data-vs-title="<?php echo esc_attr($item['label']); ?>">
                            <?php echo esc_html($item['label']); ?>
                        </button>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <p class="nw-vs-menu__h"><?php esc_html_e('Leave', 'nibwp'); ?></p>
                <a class="nw-vs-menu__item" role="menuitem" href="<?php echo esc_url(admin_url()); ?>" target="_blank" rel="noopener">
                    <?php esc_html_e('WP Admin in a new tab', 'nibwp'); ?>
                    <?php echo nibwp_visual_icon('external', 13); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?>
                </a>
            </div>
        </div>

        <?php // Icons, not words: four widths in the space two labels used to take,
              // and the shape says which is which in any language. ?>
        <div class="nw-vs-widths" role="group" aria-label="<?php esc_attr_e('Viewport width', 'nibwp'); ?>">
            <?php
            $presets = [
                'mobile' => [__('Mobile', 'nibwp'), 'mobile'],
                'tablet' => [__('Tablet', 'nibwp'), 'tablet'],
                'laptop' => [__('Laptop', 'nibwp'), 'laptop'],
                'desktop' => [__('Desktop', 'nibwp'), 'desktop'],
                'full' => [__('Fill the window', 'nibwp'), 'full'],
            ];
            foreach ($presets as $key => [$label, $icon]) :
                ?>
                <button type="button" class="nw-vs-width<?php echo $key === 'full' ? ' is-active' : ''; ?>"
                        data-vs-width="<?php echo esc_attr($key); ?>"
                        data-tip="<?php echo esc_attr($label); ?>" aria-label="<?php echo esc_attr($label); ?>">
                    <?php echo nibwp_visual_icon($icon); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?>
                </button>
            <?php endforeach; ?>
        </div>

        <button type="button" class="nw-vs-ibtn" id="nw-vs-dock-btn" aria-expanded="false" aria-controls="nw-vs-dock"
                data-tip="<?php esc_attr_e('Activity', 'nibwp'); ?>" aria-label="<?php esc_attr_e('Activity', 'nibwp'); ?>">
            <?php echo nibwp_visual_icon('activity'); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?>
            <span class="nw-vs-ibtn__count" id="nw-vs-dock-count" hidden>0</span>
        </button>

        <?php // A switch, not a tickbox. Every other control in this bar is a
              // 30px button with a border; a bare checkbox beside them read as
              // something the browser had left behind. ?>
        <label class="nw-vs-approval" data-tip="<?php esc_attr_e('Ask before anything that changes the site', 'nibwp'); ?>">
            <input type="checkbox" id="nw-vs-approval" <?php checked($boot['approval']); ?>>
            <span class="nw-vs-approval__track" aria-hidden="true"><span class="nw-vs-approval__knob"></span></span>
            <span class="nw-vs-approval__text"><?php esc_html_e('Ask first', 'nibwp'); ?></span>
        </label>

        <button type="button" class="nw-vs-ibtn" id="nw-vs-theme"
                data-tip="<?php esc_attr_e('Light or dark', 'nibwp'); ?>" aria-label="<?php esc_attr_e('Light or dark', 'nibwp'); ?>">
            <span class="nw-vs-theme__sun"><?php echo nibwp_visual_icon('sun'); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></span>
            <span class="nw-vs-theme__moon"><?php echo nibwp_visual_icon('moon'); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></span>
        </button>

        <a class="nw-vs-ibtn" href="<?php echo esc_url(admin_url('admin.php?page=nibwp-dashboard')); ?>"
           data-tip="<?php esc_attr_e('Close the workspace', 'nibwp'); ?>" aria-label="<?php esc_attr_e('Close the workspace', 'nibwp'); ?>">
            <?php echo nibwp_visual_icon('close'); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?>
        </a>
    </header>

    <div class="nw-vs-split">
        <?php nibwp_visual_render_panel(); ?>

        <div class="nw-vs-main">
            <div class="nw-vs-tabs" id="nw-vs-tabs" role="tablist"></div>
            <main class="nw-vs-stage" id="nw-vs-stage">
                <?php nibwp_visual_render_stage_empty(); ?>
            </main>
        </div>
    </div>

    <?php // The log, docked along the bottom like an inspector: full width, out
          // of the way until asked for, and never competing with the panel for
          // the narrow column the panel needs. ?>
    <section class="nw-vs-dock" id="nw-vs-dock" hidden aria-label="<?php esc_attr_e('Activity', 'nibwp'); ?>">
        <div class="nw-vs-dock__grip" id="nw-vs-dock-grip" role="separator" aria-orientation="horizontal"
             aria-label="<?php esc_attr_e('Resize the activity panel', 'nibwp'); ?>" tabindex="0"></div>
        <div class="nw-vs-dock__bar">
            <span class="nw-vs-dock__h">
                <?php echo nibwp_visual_icon('activity', 14); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?>
                <?php esc_html_e('Activity', 'nibwp'); ?>
            </span>
            <span class="nw-vs-dock__who" id="nw-vs-dock-who"></span>
            <button type="button" class="nw-vs-dock__act" id="nw-vs-log-clear"><?php esc_html_e('Clear', 'nibwp'); ?></button>
            <button type="button" class="nw-vs-dock__act" id="nw-vs-dock-close" aria-label="<?php esc_attr_e('Hide the activity panel', 'nibwp'); ?>">
                <?php echo nibwp_visual_icon('close', 14); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?>
            </button>
        </div>
        <div class="nw-vs-log" id="nw-vs-log" aria-label="<?php esc_attr_e('What the agent did', 'nibwp'); ?>"></div>
    </section>

    <?php // Sits above everything: an action waiting on a person cannot be missed. ?>
    <div class="nw-vs-ask" id="nw-vs-ask" hidden>
        <div class="nw-vs-ask__card" role="alertdialog" aria-labelledby="nw-vs-ask-title" aria-describedby="nw-vs-ask-what">
            <h2 id="nw-vs-ask-title"><?php esc_html_e('The agent wants to do this', 'nibwp'); ?></h2>
            <p class="nw-vs-ask__what" id="nw-vs-ask-what"></p>
            <div class="nw-vs-ask__actions">
                <button type="button" class="nw-vs-btn nw-vs-btn--go" id="nw-vs-allow"><?php esc_html_e('Allow', 'nibwp'); ?></button>
                <button type="button" class="nw-vs-btn" id="nw-vs-deny"><?php esc_html_e('Refuse', 'nibwp'); ?></button>
            </div>
            <?php // Being asked once is reassuring; being asked forty times is why
                  // people turn safety off entirely. This stops the asking without
                  // hiding that it has stopped — the bar switch shows the state. ?>
            <div class="nw-vs-ask__more">
                <button type="button" class="nw-vs-ask__all" id="nw-vs-allow-all">
                    <?php esc_html_e('Allow, and stop asking', 'nibwp'); ?>
                </button>
            </div>
        </div>
    </div>

    <?php nibwp_visual_render_upsell(); ?>

    <?php // This screen is new, and the version matters when someone reports
          // that something on it did not work. ?>
    <footer class="nw-vs-foot">
        <span class="nw-vs-foot__left">
            <span class="nw-vs-foot__beta"><?php esc_html_e('Beta', 'nibwp'); ?></span>
            <?php echo esc_html(sprintf(
                /* translators: %s: product name */
                __('%s Agent View', 'nibwp'),
                $boot['brand']
            )); ?>
            <span class="nw-vs-foot__v"><?php echo esc_html(defined('NIBWP_VERSION') ? 'v' . NIBWP_VERSION : ''); ?></span>
        </span>
        <span class="nw-vs-foot__right">
            <?php echo esc_html(sprintf(
                /* translators: 1: year, 2: product name */
                __('© %1$s %2$s. All rights reserved.', 'nibwp'),
                gmdate('Y'),
                $boot['brand']
            )); ?>
        </span>
    </footer>

</div>

<script>window.nibwpVisual = <?php echo wp_json_encode($boot); ?>;</script>
<script src="<?php echo esc_url(NIBWP_PLUGIN_URL . 'assets/js/visual-workspace.js?v=' . nibwp_visual_asset_version('assets/js/visual-workspace.js')); ?>"></script>
</body>
</html>
    <?php
    exit;
}
