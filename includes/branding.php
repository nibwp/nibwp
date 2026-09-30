<?php

declare(strict_types=1);

/**
 * Renaming — how the plugin presents itself inside this one wp-admin.
 *
 * An agency hands a finished site to a client. The client opens Plugins and
 * finds a tool with a vendor's name on it, and that is a support call and a
 * lead to somebody else's product. This lets the owner put their own name on
 * the row and on the menu.
 *
 * It changes what is *displayed*, and only that. Every mechanism that keeps the
 * plugin working keys off the folder and file name — nibwp/nibwp.php — which
 * this never touches:
 *
 *   - update checks match on that path, so updates keep arriving
 *   - the license keys off the site and the license key, not the plugin name
 *   - option names, hooks, capabilities and REST routes are untouched
 *   - deactivating this feature restores the real name with nothing to undo
 *
 * The rename is also withheld from the update pipeline deliberately: see
 * nibwp_branding_should_apply(). Sending a renamed plugin to an update API is
 * how a white-label feature turns into a broken updater, and it is the one
 * mistake here that would be hard for a site owner to diagnose.
 */

if (!defined('ABSPATH')) {
    exit();
}

const NIBWP_BRANDING_OPTION = 'nibwp_branding';

/**
 * The stock values, read from the plugin header so they can never drift.
 *
 * @return array{name: string, description: string, author: string, author_uri: string, plugin_uri: string, menu: string}
 */
function nibwp_branding_defaults(): array
{
    static $defaults = null;
    if ($defaults !== null) {
        return $defaults;
    }

    $header = [];
    if (defined('NIBWP_PLUGIN_FILE')) {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        // markup=false, translate=false: these are compared against stored
        // values, so they have to be the raw header every time.
        $header = get_plugin_data(NIBWP_PLUGIN_FILE, false, false);
    }

    $defaults = [
        'name' => (string) ($header['Name'] ?? 'NIBWP'),
        'description' => (string) ($header['Description'] ?? ''),
        'author' => (string) ($header['Author'] ?? 'NIBWP'),
        'author_uri' => (string) ($header['AuthorURI'] ?? 'https://www.nibwp.com'),
        'plugin_uri' => (string) ($header['PluginURI'] ?? 'https://www.nibwp.com'),
        'menu' => 'NIBWP',
    ];

    return $defaults;
}

/**
 * Stored overrides. Empty strings mean "use the default", so a half-filled form
 * renames only what it named.
 *
 * @return array{enabled: bool, name: string, description: string, author: string, author_uri: string, plugin_uri: string, menu: string, keep_icon: bool}
 */
function nibwp_branding_config(): array
{
    $raw = get_option(NIBWP_BRANDING_OPTION, []);
    if (!is_array($raw)) {
        $raw = [];
    }

    return [
        'enabled' => !empty($raw['enabled']),
        'name' => (string) ($raw['name'] ?? ''),
        'description' => (string) ($raw['description'] ?? ''),
        'author' => (string) ($raw['author'] ?? ''),
        'author_uri' => (string) ($raw['author_uri'] ?? ''),
        'plugin_uri' => (string) ($raw['plugin_uri'] ?? ''),
        'menu' => (string) ($raw['menu'] ?? ''),
        'keep_icon' => !isset($raw['keep_icon']) || !empty($raw['keep_icon']),
    ];
}

/**
 * @param array<string, mixed> $config
 */
function nibwp_branding_save(array $config): void
{
    update_option(NIBWP_BRANDING_OPTION, [
        'enabled' => !empty($config['enabled']),
        'name' => sanitize_text_field((string) ($config['name'] ?? '')),
        'description' => sanitize_textarea_field((string) ($config['description'] ?? '')),
        'author' => sanitize_text_field((string) ($config['author'] ?? '')),
        'author_uri' => esc_url_raw((string) ($config['author_uri'] ?? '')),
        'plugin_uri' => esc_url_raw((string) ($config['plugin_uri'] ?? '')),
        'menu' => sanitize_text_field((string) ($config['menu'] ?? '')),
        'keep_icon' => !empty($config['keep_icon']),
    ], false);
}

function nibwp_branding_reset(): void
{
    delete_option(NIBWP_BRANDING_OPTION);
}

/**
 * The values actually shown, defaults filling every gap.
 *
 * `{site}` stands in for the site title — an agency setting one site up the same
 * way as thirty others should not have to retype the client's name each time.
 *
 * @return array{name: string, description: string, author: string, author_uri: string, plugin_uri: string, menu: string}
 */
function nibwp_branding_resolved(): array
{
    $config = nibwp_branding_config();
    $defaults = nibwp_branding_defaults();

    $out = [];
    foreach ($defaults as $key => $default) {
        $value = trim((string) ($config[$key] ?? ''));
        $out[$key] = $value !== '' ? $value : $default;
    }

    $site = get_bloginfo('name');
    foreach (['name', 'description', 'author', 'menu'] as $key) {
        $out[$key] = str_replace('{site}', $site, $out[$key]);
    }

    return $out;
}

/**
 * Whether renaming is switched on and licensed.
 *
 * Sharing User Access's gate deliberately: both are agency tools, and an
 * unlicensed site quietly showing a stranger's plugin name would be worse than
 * showing ours.
 */
function nibwp_branding_active(): bool
{
    if (!function_exists('nibwp_user_access_available') || !nibwp_user_access_available()) {
        return false;
    }

    return nibwp_branding_config()['enabled'];
}

/**
 * Whether to rename *this* request.
 *
 * Renaming is a display concern, so it applies while an admin screen is being
 * drawn and nowhere else. The exclusions matter more than the rule:
 *
 *   - cron and the update check read get_plugins() to decide what to offer
 *     updates for, and would post a renamed plugin to the update API
 *   - WP-CLI and REST are automation; a renamed row there breaks tooling that
 *     matches on the real name
 *
 * The result is that "does this plugin still update" has the same answer with
 * renaming on as off, which is the whole promise being made on the screen.
 */
function nibwp_branding_should_apply(): bool
{
    if (!nibwp_branding_active()) {
        return false;
    }

    if (wp_doing_cron() || wp_doing_ajax()) {
        return false;
    }

    if (defined('WP_CLI') && WP_CLI) {
        return false;
    }

    if (defined('REST_REQUEST') && REST_REQUEST) {
        return false;
    }

    if (doing_action('wp_update_plugins') || doing_filter('pre_set_site_transient_update_plugins')) {
        return false;
    }

    return is_admin();
}

/**
 * Rename the row in Plugins.
 *
 * `all_plugins` is a display filter over get_plugins(). The array key — the
 * plugin's path — is what everything else keys off, and it is left alone.
 *
 * @param array<string, array<string, mixed>> $plugins
 * @return array<string, array<string, mixed>>
 */
function nibwp_branding_filter_plugins_list(array $plugins): array
{
    if (!nibwp_branding_should_apply()) {
        return $plugins;
    }

    $basename = defined('NIBWP_PLUGIN_FILE') ? plugin_basename(NIBWP_PLUGIN_FILE) : 'nibwp/nibwp.php';
    if (!isset($plugins[$basename])) {
        return $plugins;
    }

    $brand = nibwp_branding_resolved();

    $plugins[$basename]['Name'] = $brand['name'];
    $plugins[$basename]['Title'] = $brand['name'];
    $plugins[$basename]['Description'] = $brand['description'];
    $plugins[$basename]['Author'] = $brand['author'];
    $plugins[$basename]['AuthorName'] = $brand['author'];
    $plugins[$basename]['AuthorURI'] = $brand['author_uri'];
    $plugins[$basename]['PluginURI'] = $brand['plugin_uri'];

    return $plugins;
}
add_filter('all_plugins', 'nibwp_branding_filter_plugins_list');

/**
 * Drop the row links that give the real identity away.
 *
 * Renaming the row is pointless while "View details" sits under it: that link
 * opens a modal served from the update API, carrying the original name, author,
 * banner and readme. The same goes for anything pointing at the plugin's own
 * listing. Version and the (already renamed) author line stay, because a row
 * with no version at all looks broken rather than rebranded.
 *
 * @param array<int, string> $meta
 * @return array<int, string>
 */
function nibwp_branding_filter_row_meta(array $meta, string $plugin_file): array
{
    if (!nibwp_branding_should_apply()) {
        return $meta;
    }

    $basename = defined('NIBWP_PLUGIN_FILE') ? plugin_basename(NIBWP_PLUGIN_FILE) : 'nibwp/nibwp.php';
    if ($plugin_file !== $basename) {
        return $meta;
    }

    // An allowlist, not a denylist. Naming the links to remove means the next
    // link anyone adds leaks by default; keeping only the owner's own means it
    // does not. Plain text (the version) has no identity in it and stays.
    //
    // Read from the stored config rather than the resolved values: an owner who
    // left the link fields empty falls back to the real vendor URL, and matching
    // on that would allowlist exactly the links this is meant to remove.
    $config = nibwp_branding_config();
    $mine = array_filter([
        trim((string) $config['author_uri']),
        trim((string) $config['plugin_uri']),
    ]);

    return array_values(array_filter($meta, static function ($item) use ($mine): bool {
        $item = (string) $item;

        if (stripos($item, '<a ') === false) {
            return true;
        }

        foreach ($mine as $url) {
            if ($url !== '' && stripos($item, (string) $url) !== false) {
                return true;
            }
        }

        return false;
    }));
}
add_filter('plugin_row_meta', 'nibwp_branding_filter_row_meta', 100, 2);

/**
 * The admin menu label.
 *
 * Late on admin_menu so it runs after the menu is registered, and it edits the
 * label in place rather than re-registering anything — the slug stays
 * nibwp-dashboard, so every bookmark, redirect and capability check still works.
 */
add_action('admin_menu', 'nibwp_branding_rename_menu', 999);

function nibwp_branding_rename_menu(): void
{
    if (!nibwp_branding_should_apply()) {
        return;
    }

    $brand = nibwp_branding_resolved();
    if ($brand['menu'] === nibwp_branding_defaults()['menu']) {
        return;
    }

    global $menu;
    if (!is_array($menu)) {
        return;
    }

    foreach ($menu as $position => $item) {
        if (isset($item[2]) && $item[2] === 'nibwp-dashboard') {
            $menu[$position][0] = $brand['menu'];
            break;
        }
    }
}

/**
 * The name used by the plugin's own screens.
 *
 * Anywhere that says "NIBWP" to the person looking at it should say whatever
 * the owner chose instead.
 */
function nibwp_branding_display_name(): string
{
    if (!nibwp_branding_active()) {
        return nibwp_branding_defaults()['menu'];
    }

    return nibwp_branding_resolved()['menu'];
}
