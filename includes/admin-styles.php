<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Shared admin styles for all NIBWP pages.
 *
 * CSS lives in assets/css/admin.css and is enqueued on every admin page that
 * belongs to NIBWP (page slug starts with "nibwp"). Versioned with
 * NIBWP_VERSION so updates bust the browser cache.
 */

add_action('admin_enqueue_scripts', static function (string $hook): void {
    // Enqueue on plugin pages — page slug starts with "nibwp".
    $page = isset($_GET['page']) ? (string) $_GET['page'] : '';
    $is_nibwp_page = str_starts_with($page, 'nibwp');
    if (!$is_nibwp_page) {
        return;
    }
    nibwp_enqueue_admin_stylesheet();

    // Integrations page had ~550 lines of CSS inline at the page bottom (FOUC).
    // Load it as a head stylesheet so it's render-blocking + cacheable. The
    // Workflows page reuses the same tab / tier-pill / search-wrap classes, so
    // it needs this stylesheet too.
    if (
        $page === 'nibwp-integrations' || $page === 'nibwp-workflows' || $page === 'nibwp-jobs'
        || $page === 'nibwp-figma' || $page === 'nibwp-status' || $page === 'nibwp-connect'
        // Skills reuses the same tab strip and search-wrap classes.
        || $page === 'nibwp-skills'
    ) {
        wp_enqueue_style(
            'nibwp-admin-integrations',
            NIBWP_PLUGIN_URL . 'assets/css/admin-integrations.css',
            ['nibwp-admin'],
            defined('NIBWP_VERSION') ? NIBWP_VERSION : null,
        );
    }

    // Jobs reuses the Skill-card look (icon + title + feature list) for its job
    // library, so it needs the skills stylesheet too.
    if ($page === 'nibwp-jobs') {
        wp_enqueue_style(
            'nibwp-admin-skills',
            NIBWP_PLUGIN_URL . 'assets/css/admin-skills.css',
            ['nibwp-admin'],
            defined('NIBWP_VERSION') ? NIBWP_VERSION : null,
        );
    }

    // User access reuses the Status page's copy-row + button styling.
    if ($page === 'nibwp-user-access' || $page === 'nibwp-connect') {
        wp_enqueue_style(
            'nibwp-admin-status',
            NIBWP_PLUGIN_URL . 'assets/css/admin-status.css',
            ['nibwp-admin'],
            defined('NIBWP_VERSION') ? NIBWP_VERSION : null,
        );
    }

    // Per-page stylesheets — each admin page's CSS lives in its own file
    // (extracted from the former inline <style> blocks) and loads after admin.css.
    $page_css = [
        'nibwp-dashboard' => 'admin-dashboard',
        'nibwp-how-to'    => 'admin-how-to',
        'nibwp-skills'    => 'admin-skills',
        'nibwp-workflows' => 'admin-workflows',
        'nibwp-jobs'      => 'admin-jobs',
        'nibwp-audit-log' => 'admin-audit',
        'nibwp-sandbox'   => 'admin-sandbox',
        'nibwp-settings'  => 'admin-settings',
        'nibwp-figma'     => 'admin-figma',
        'nibwp-status'    => 'admin-status',
        'nibwp-user-access' => 'admin-user-access',
        'nibwp-connect'   => 'admin-connect',
    ];
    if (isset($page_css[$page])) {
        wp_enqueue_style(
            'nibwp-' . $page_css[$page],
            NIBWP_PLUGIN_URL . 'assets/css/' . $page_css[$page] . '.css',
            ['nibwp-admin'],
            defined('NIBWP_VERSION') ? NIBWP_VERSION : null,
        );
    }
});

/**
 * Enqueue the shared admin stylesheet + pricing-grid stylesheet. Idempotent.
 *
 * `admin.css`         — global app shell (sidebar, topbar, cards, forms).
 * `admin-pricing.css` — "Choose your plan" pricing grid + foreign-notice
 *                       suppression rules (body class .nibwp-suppress-foreign-notices).
 */
function nibwp_enqueue_admin_stylesheet(): void
{
    $version = defined('NIBWP_VERSION') ? NIBWP_VERSION : null;

    $handle = 'nibwp-admin';
    if (!(wp_style_is($handle, 'enqueued') || wp_style_is($handle, 'done'))) {
        wp_enqueue_style(
            $handle,
            NIBWP_PLUGIN_URL . 'assets/css/admin.css',
            deps: [],
            ver: $version,
        );
    }

    $pricing_handle = 'nibwp-admin-pricing';
    if (!(wp_style_is($pricing_handle, 'enqueued') || wp_style_is($pricing_handle, 'done'))) {
        wp_enqueue_style(
            $pricing_handle,
            NIBWP_PLUGIN_URL . 'assets/css/admin-pricing.css',
            deps: [$handle],
            ver: $version,
        );
    }
}

/**
 * Back-compat wrapper — older callers (nibwp_render_admin_header) call this
 * mid-render. wp_enqueue_style still works late; WP will print the tag in
 * admin_footer. The admin_enqueue_scripts hook above is the primary path.
 */
function nibwp_render_admin_styles(): void
{
    nibwp_enqueue_admin_stylesheet();
}

/* ---------------------------------------------------------------------------
 * Menu order.
 *
 * Eighteen items registered in the order they happened to be written is a list
 * nobody can scan: Settings sat in the middle, Visual at the very bottom, and
 * nothing said which items belonged together. Sorting happens here, once, after
 * everything has registered — rather than by renumbering eighteen
 * add_submenu_page calls that add-ons keep adding to.
 * ------------------------------------------------------------------------- */

add_action('admin_menu', 'nibwp_order_admin_menu', 9999);

/**
 * @return array<string, array<int, string>>
 */
function nibwp_admin_menu_groups(): array
{
    return [
        '' => ['nibwp-dashboard', 'nibwp-connect', 'nibwp-visual'],
        'Build' => ['nibwp-integrations', 'nibwp', 'nibwp-skills', 'nibwp-workflows', 'nibwp-jobs', 'nibwp-figma'],
        'Data' => ['nibwp-memory', 'nibwp-audit-log', 'nibwp-sandbox'],
        'Check' => ['nibwp-status', 'nibwp-how-to'],
        'Manage' => ['nibwp-user-access', 'nibwp-license', 'nibwp-settings'],
    ];
}

function nibwp_order_admin_menu(): void
{
    global $submenu;

    if (empty($submenu['nibwp-dashboard']) || !is_array($submenu['nibwp-dashboard'])) {
        return;
    }

    $labels = [
        'Build' => __('Build', 'nibwp'),
        'Data' => __('Data', 'nibwp'),
        'Check' => __('Check', 'nibwp'),
        'Manage' => __('Manage', 'nibwp'),
    ];

    $by_slug = [];
    foreach ($submenu['nibwp-dashboard'] as $item) {
        if (is_array($item) && isset($item[2])) {
            $by_slug[(string) $item[2]] = $item;
        }
    }

    $ordered = [];

    foreach (nibwp_admin_menu_groups() as $group => $slugs) {
        $lead = true;
        foreach ($slugs as $slug) {
            if (!isset($by_slug[$slug])) {
                continue;
            }
            $item = $by_slug[$slug];
            unset($by_slug[$slug]);

            // The heading rides on the first item of the group rather than
            // being an item of its own: a separate row would be a focusable
            // link that goes nowhere, which is worse than no heading at all.
            if ($lead && $group !== '') {
                $item[0] = '<span class="nibwp-menu-group" data-nibwp-group="'
                    . esc_attr($labels[$group] ?? $group) . '">' . $item[0] . '</span>';
            }
            $lead = false;
            $ordered[] = $item;
        }
    }

    // Anything an add-on registered that this list has never heard of keeps its
    // place at the end rather than disappearing.
    foreach ($by_slug as $item) {
        $ordered[] = $item;
    }

    $submenu['nibwp-dashboard'] = array_values($ordered);
}

add_action('admin_head', 'nibwp_admin_menu_group_styles');

function nibwp_admin_menu_group_styles(): void
{
    ?>
    <style>
    #adminmenu .nibwp-menu-group {
        display: block;
        margin: 8px -12px 0;
        padding: 22px 12px 0;
        border-top: 1px solid rgba(255, 255, 255, .12);
        position: relative;
    }
    /* The workspace entry: the one item in this column where something happens
       by itself while you watch, and it should look like it. */
    #adminmenu .nibwp-menu-spark {
        display: inline-flex;
        align-items: center;
        margin-left: 7px;
        vertical-align: -2px;
        filter: drop-shadow(0 0 5px rgba(255, 145, 60, .5));
        animation: nibwp-spark-shine 4.5s ease-in-out infinite;
    }
    #adminmenu li.current .nibwp-menu-spark,
    #adminmenu a:hover .nibwp-menu-spark {
        filter: drop-shadow(0 0 8px rgba(255, 95, 158, .65)) saturate(1.15);
    }
    @keyframes nibwp-spark-shine {
        0%, 62%, 100% { transform: scale(1); }
        72% { transform: scale(1.09) rotate(-4deg); }
        84% { transform: scale(1.02) rotate(2deg); }
    }
    @media (prefers-reduced-motion: reduce) {
        #adminmenu .nibwp-menu-spark { animation: none; }
    }
    #adminmenu .nibwp-menu-spark__text { font-weight: 600; }
    #adminmenu .nibwp-menu-group::before {
        content: attr(data-nibwp-group);
        position: absolute;
        top: 6px;
        left: 12px;
        font-size: 10px;
        font-weight: 600;
        letter-spacing: .08em;
        text-transform: uppercase;
        opacity: .55;
    }
    </style>
    <?php
}

/**
 * The workspace opens in its own window, from the WordPress menu too.
 *
 * add_submenu_page() has no argument for it, and the anchor is printed by core,
 * so the attribute is set on the one link after the menu is on the page. Two
 * lines of script rather than filtering core's menu markup with a regular
 * expression, which is the other way this is usually done and the worse one.
 */
add_action('admin_footer', 'nibwp_visual_menu_opens_a_window');

function nibwp_visual_menu_opens_a_window(): void
{
    ?>
    <script>
    (function () {
        var link = document.querySelector('#adminmenu a[href$="page=nibwp-visual"]');
        if (!link) { return; }
        link.target = '_blank';
        link.rel = 'noopener';
    })();
    </script>
    <?php
}
