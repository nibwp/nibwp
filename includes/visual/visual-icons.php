<?php

declare(strict_types=1);

/**
 * The workspace's own icons.
 *
 * Inlined rather than sprited or fetched: this page renders outside the admin
 * shell with two stylesheets and one script, and a third request for six
 * outlines would be the slowest thing on it. Every icon is decorative — each
 * control carries its own label — so they are all aria-hidden.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * @return array<string, string>
 */
function nibwp_visual_icon_paths(): array
{
    return [
        'mobile' => '<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/>',
        'tablet' => '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M11 18h2"/>',
        'laptop' => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M2 20h20"/>',
        'desktop' => '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>',
        'full' => '<path d="M3 8V5a2 2 0 0 1 2-2h3M16 3h3a2 2 0 0 1 2 2v3M21 16v3a2 2 0 0 1-2 2h-3M8 21H5a2 2 0 0 1-2-2v-3"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon' => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
        'activity' => '<path d="M3 12h4l3 8 4-16 3 8h4"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'chevron' => '<polyline points="6 9 12 15 18 9"/>',
        'external' => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6M10 14 21 3"/>',
        'close' => '<path d="M18 6 6 18M6 6l12 12"/>',
        'lock' => '<rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'plug' => '<path d="M9 2v6M15 2v6M6 8h12v3a6 6 0 0 1-12 0V8zM12 17v5"/>',
        'check' => '<polyline points="20 6 9 17 4 12"/>',
        // Section marks, drawn to match the ones in the NibWP sidebar so the
        // two navigations read as one product.
        'spark' => '<path d="M12 3.5 13.6 8 18 9.5 13.6 11 12 15.5 10.4 11 6 9.5 10.4 8 12 3.5Z"/><path d="M19 4v3M17.5 5.5h3M5 17v3M3.5 18.5h3"/>',
        'flow' => '<path d="m3 6 2 2 4-4"/><path d="m3 14 2 2 4-4"/><path d="M13 6h8"/><path d="M13 14h8"/><path d="M13 20h8"/><path d="M3 20h2"/>',
        'bolt' => '<path d="M13 2 4.5 13.5H11l-1 8.5 8.5-11.5H12l1-8.5z"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5M12 15V3"/>',
        'refresh' => '<path d="M21 12a9 9 0 1 1-2.6-6.4"/><path d="M21 3v6h-6"/>',
        'copy' => '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
    ];
}

function nibwp_visual_icon(string $name, int $size = 16): string
{
    $paths = nibwp_visual_icon_paths();
    if (!isset($paths[$name])) {
        return '';
    }

    return sprintf(
        '<svg class="nw-vs-i" width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            . ' stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%2$s</svg>',
        $size,
        $paths[$name]
    );
}

/**
 * Places worth jumping to, opened inside the workspace rather than away from it.
 *
 * Leaving the workspace to look at the Pages list and coming back is three
 * navigations for one glance. These load in a tab beside whatever the agent is
 * doing, and the same-origin rule that lets the agent drive a page lets these
 * be driven too.
 *
 * @return array<int, array{label: string, items: array<int, array{label: string, url: string}>}>
 */
/**
 * Remember this site's admin menu, exactly as WordPress built it.
 *
 * The workspace renders on admin-post.php, which never fires `admin_menu`, so
 * it cannot ask what the menu contains — and firing that action ourselves would
 * run every plugin's registration code for a list of links. Instead this rides
 * along on ordinary admin page loads and keeps a snapshot, which stays current
 * because it is rewritten on every one of them.
 */
add_action('admin_menu', 'nibwp_visual_capture_menu', 99999);

function nibwp_visual_capture_menu(): void
{
    global $menu, $submenu;

    if (!is_array($menu)) {
        return;
    }

    $strip = static function ($title): string {
        // Counts and screen-reader text live in spans: "Comments <span
        // class=awaiting-mod><span>0</span><span class=screen-reader-text>0
        // Comments in moderation</span></span>". Stripping tags alone glued all
        // of that into the label — dropping the spans whole is what was meant.
        $text = preg_replace('#<span.*$#is', '', (string) $title) ?? (string) $title;
        $text = wp_strip_all_tags($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s*\d+\s*$/', '', $text) ?? $text);
    };

    $snapshot = [];

    foreach ($menu as $top) {
        if (!is_array($top) || count($top) < 3) {
            continue;
        }

        $label = $strip($top[0] ?? '');
        $slug = (string) ($top[2] ?? '');
        $cap = (string) ($top[1] ?? 'read');

        // Separators have no title and no destination.
        if ($label === '' || $slug === '' || str_starts_with($slug, 'separator')) {
            continue;
        }

        $children = [];
        foreach ((array) ($submenu[$slug] ?? []) as $sub) {
            if (!is_array($sub) || count($sub) < 3) {
                continue;
            }
            $child_label = $strip($sub[0] ?? '');
            $child_slug = (string) ($sub[2] ?? '');
            if ($child_label === '' || $child_slug === '' || $child_slug === '#') {
                continue;
            }
            $children[] = [
                'label' => $child_label,
                'slug' => $child_slug,
                'cap' => (string) ($sub[1] ?? $cap),
            ];
            if (count($children) >= 20) {
                break;
            }
        }

        $snapshot[] = ['label' => $label, 'slug' => $slug, 'cap' => $cap, 'items' => $children];

        if (count($snapshot) >= 40) {
            break;
        }
    }

    if ($snapshot === []) {
        return;
    }

    // Only write when it has actually changed. This runs on every admin page.
    if (get_option('nibwp_visual_menu_map') !== $snapshot) {
        update_option('nibwp_visual_menu_map', $snapshot, false);
    }
}

/**
 * A menu slug turned into somewhere to go.
 *
 * WordPress accepts three shapes here: a bare file (`edit.php`), a file with a
 * query (`edit.php?post_type=page`), and a page slug that belongs to admin.php.
 */
function nibwp_visual_menu_url(string $slug): string
{
    if (preg_match('#^https?://#i', $slug)) {
        return $slug;
    }

    $file = strtok($slug, '?');
    if (is_string($file) && str_ends_with($file, '.php')) {
        return admin_url($slug);
    }

    return admin_url('admin.php?page=' . $slug);
}

/**
 * The site's real admin menu, if one has been seen, filtered to what this
 * account may open.
 *
 * @return array<int, array{label: string, items: array<int, array{label: string, url: string}>}>
 */
/**
 * WordPress's own screens, and ours. Nothing else.
 *
 * A busy site puts twenty plugins in this menu, and a jump list that offers
 * SureCart's coupons beside NibWP's workflows is a plugin directory, not a way
 * around the site. Exact slugs rather than a file match: `edit.php` is Posts,
 * but `edit.php?post_type=…` is whatever a plugin registered.
 */
function nibwp_visual_menu_is_ours(string $slug): bool
{
    $core = [
        'index.php',
        'edit.php',
        'edit.php?post_type=page',
        'upload.php',
        'edit-comments.php',
        'link-manager.php',
        'themes.php',
        'plugins.php',
        'users.php',
        'profile.php',
        'tools.php',
        'options-general.php',
    ];

    if (in_array($slug, $core, true)) {
        return true;
    }

    /**
     * Filters whether a top-level admin menu belongs in the workspace's jump
     * list. Third-party menus are excluded by default.
     *
     * @param bool   $ours Whether to offer it.
     * @param string $slug The menu slug.
     */
    return (bool) apply_filters('nibwp_visual_menu_is_ours', str_starts_with($slug, 'nibwp'), $slug);
}

function nibwp_visual_menu_groups(): array
{
    $snapshot = get_option('nibwp_visual_menu_map', []);
    if (!is_array($snapshot) || $snapshot === []) {
        return [];
    }

    $groups = [];

    foreach ($snapshot as $top) {
        if (!is_array($top) || empty($top['label']) || empty($top['slug'])) {
            continue;
        }
        if (!nibwp_visual_menu_is_ours((string) $top['slug'])) {
            continue;
        }
        // The snapshot was taken by whoever last loaded an admin page, who may
        // have had more rights than whoever is reading it now.
        if (!current_user_can((string) ($top['cap'] ?? 'read'))) {
            continue;
        }

        $clean = static function (string $text): string {
            // Snapshots taken before the capture learned to drop count markup
            // still carry "Comments 00 Comments in moderation". Rewriting the
            // option only helps sites whose menu happens to change afterwards.
            $text = preg_replace('/\s*\d+\s+Comments? in moderation$/i', '', $text) ?? $text;
            $text = preg_replace('/\s+\d+$/', '', $text) ?? $text;

            return trim($text);
        };

        $items = [];
        foreach ((array) ($top['items'] ?? []) as $child) {
            if (empty($child['label']) || empty($child['slug'])) {
                continue;
            }
            if (!current_user_can((string) ($child['cap'] ?? 'read'))) {
                continue;
            }
            $items[] = [
                'label' => $clean((string) $child['label']),
                'url' => nibwp_visual_menu_url((string) $child['slug']),
            ];
        }

        // A top-level with no children of its own is still a destination.
        if ($items === []) {
            $items[] = [
                'label' => $clean((string) $top['label']),
                'url' => nibwp_visual_menu_url((string) $top['slug']),
            ];
        }

        $groups[] = ['label' => $clean((string) $top['label']), 'items' => $items];
    }

    return $groups;
}

function nibwp_visual_shortcuts(): array
{
    $site = [
        ['label' => __('Front page', 'nibwp'), 'url' => home_url('/')],
        ['label' => __('Dashboard', 'nibwp'), 'url' => admin_url()],
        ['label' => __('Pages', 'nibwp'), 'url' => admin_url('edit.php?post_type=page')],
        ['label' => __('Posts', 'nibwp'), 'url' => admin_url('edit.php')],
        ['label' => __('New page', 'nibwp'), 'url' => admin_url('post-new.php?post_type=page')],
        ['label' => __('Media', 'nibwp'), 'url' => admin_url('upload.php')],
        ['label' => __('Menus', 'nibwp'), 'url' => admin_url('nav-menus.php')],
        ['label' => __('Plugins', 'nibwp'), 'url' => admin_url('plugins.php')],
    ];

    // The site editor only exists on a block theme; offering it on a classic
    // theme opens a screen that redirects and looks broken.
    if (function_exists('wp_is_block_theme') && wp_is_block_theme()) {
        $site[] = ['label' => __('Site editor', 'nibwp'), 'url' => admin_url('site-editor.php')];
    } else {
        $site[] = ['label' => __('Customizer', 'nibwp'), 'url' => admin_url('customize.php')];
    }

    $brand = function_exists('nibwp_branding_display_name') ? nibwp_branding_display_name() : 'NibWP';

    $own = [
        ['label' => __('Dashboard', 'nibwp'), 'url' => admin_url('admin.php?page=nibwp-dashboard')],
        ['label' => __('Connect', 'nibwp'), 'url' => admin_url('admin.php?page=nibwp-connect')],
        // The abilities screen is the bare 'nibwp' slug, not 'nibwp-abilities'.
        ['label' => __('AI Abilities', 'nibwp'), 'url' => admin_url('admin.php?page=nibwp')],
        ['label' => __('Workflows', 'nibwp'), 'url' => admin_url('admin.php?page=nibwp-workflows')],
        ['label' => __('Skills', 'nibwp'), 'url' => admin_url('admin.php?page=nibwp-skills')],
        ['label' => __('Integrations', 'nibwp'), 'url' => admin_url('admin.php?page=nibwp-integrations')],
        ['label' => __('Status', 'nibwp'), 'url' => admin_url('admin.php?page=nibwp-status')],
        ['label' => __('Activity log', 'nibwp'), 'url' => admin_url('admin.php?page=nibwp-audit-log')],
    ];

    // The site's own menu, whatever plugins put in it, after the handful of
    // places nearly everyone wants. A list that stops at Pages and Posts is a
    // list that sends people back to wp-admin the moment they need anything
    // else — which is the entire thing this was built to avoid.
    $real = nibwp_visual_menu_groups();

    if ($real !== []) {
        return array_merge(
            [['label' => __('Jump to', 'nibwp'), 'items' => $site]],
            $real
        );
    }

    return [
        ['label' => __('This site', 'nibwp'), 'items' => $site],
        ['label' => $brand, 'items' => $own],
    ];
}
