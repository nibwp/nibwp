<?php

declare(strict_types=1);

/**
 * User access — who sees NIBWP in wp-admin.
 *
 * An agency installs NIBWP on a client's site and does not want the client
 * staring at AI machinery they did not buy and cannot support. This lets the
 * person who installed the plugin decide, per other administrator, which NIBWP
 * screens appear in their menu.
 *
 * It hides menus. It is NOT an access-control system, and the UI says so: a
 * hidden page is still reachable by anyone holding its URL, which is the point
 * — the agency keeps a link, the client sees a clean admin. Nothing here gates
 * abilities, REST or MCP; those stay on `manage_options` exactly as before.
 *
 * Everything fails open. If the owner's account is gone, the license lapses, or
 * the configuration resolves to nothing, menus come back rather than a site
 * where nobody can reach the plugin.
 */

if (!defined('ABSPATH')) {
    exit();
}

const NIBWP_USER_ACCESS_OPTION = 'nibwp_user_access';

/**
 * Slugs the owner keeps to themselves whenever restriction is on.
 *
 * Leaving another administrator a route back into Settings, License or this
 * screen would let them undo the configuration, so these are never offered as
 * choices and never survive into anyone else's visible set.
 *
 * @return array<int, string>
 */
function nibwp_user_access_owner_only_slugs(): array
{
    return ['nibwp-user-access', 'nibwp-settings', 'nibwp-license'];
}

/**
 * Read the stored configuration, normalised.
 *
 * @return array{owner_id: int, enabled: bool, users: array<int, string|array<int, string>>}
 */
function nibwp_user_access_config(): array
{
    $raw = get_option(NIBWP_USER_ACCESS_OPTION, []);
    if (!is_array($raw)) {
        $raw = [];
    }

    $users = [];
    foreach ((array) ($raw['users'] ?? []) as $user_id => $value) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            continue;
        }

        $users[$user_id] = nibwp_user_access_normalize_entry($value);
    }

    return [
        'owner_id' => (int) ($raw['owner_id'] ?? 0),
        'enabled' => !empty($raw['enabled']),
        'users' => $users,
    ];
}

/**
 * Normalise one user's entry.
 *
 * Menu, toolbar badge and notices are separate answers: someone can keep the
 * menu and lose the badge, or lose everything. Entries written before those
 * were separable are a bare 'all' / 'none' / slug list, so they are read as the
 * menu answer with the other two following it — which is what they meant.
 *
 * @param mixed $value
 * @return array{pages: string|array<int, string>, chip: bool, notices: bool}
 */
function nibwp_user_access_normalize_entry($value): array
{
    $is_new_shape = is_array($value) && array_key_exists('pages', $value);
    $pages = $is_new_shape ? $value['pages'] : $value;

    if (is_array($pages)) {
        $pages = array_unique(array_filter(array_map('strval', $pages)));
        sort($pages);
        $pages = array_values($pages);
    } else {
        $pages = (string) $pages;
        if ($pages !== 'all' && $pages !== 'none') {
            $pages = 'all';
        }
    }

    $hidden = $pages === 'none' || $pages === [];

    return [
        'pages' => $pages,
        'chip' => $is_new_shape ? !empty($value['chip']) : !$hidden,
        'notices' => $is_new_shape ? !empty($value['notices']) : !$hidden,
    ];
}

/**
 * What an administrator gets when the owner has not said anything about them.
 *
 * Deny by default, except for the owner. An agency turning this on wants the
 * client's admin clean, and an "everything unless configured" default would
 * quietly undo that the moment the client added a new administrator — the one
 * account nobody thought to configure.
 *
 * @return array{pages: string|array<int, string>, chip: bool, notices: bool}
 */
function nibwp_user_access_default_entry(int $user_id): array
{
    if ($user_id === nibwp_user_access_owner_id()) {
        return ['pages' => 'all', 'chip' => true, 'notices' => true];
    }

    return ['pages' => 'none', 'chip' => false, 'notices' => false];
}

/**
 * Read one user's entry, falling back to the default for that user.
 *
 * @return array{pages: string|array<int, string>, chip: bool, notices: bool}
 */
function nibwp_user_access_entry(int $user_id): array
{
    $config = nibwp_user_access_config();

    return $config['users'][$user_id] ?? nibwp_user_access_default_entry($user_id);
}

/**
 * Persist the configuration.
 *
 * Autoloaded on purpose: it is consulted on every admin request, and it is a
 * handful of integers.
 *
 * @param array{owner_id: int, enabled: bool, users: array<int, string|array<int, string>>} $config
 */
function nibwp_user_access_save(array $config): void
{
    update_option(
        NIBWP_USER_ACCESS_OPTION,
        [
            'owner_id' => (int) ($config['owner_id'] ?? 0),
            'enabled' => !empty($config['enabled']),
            'users' => (array) ($config['users'] ?? []),
        ],
        true
    );

    nibwp_user_access_flush_cache();
}

/**
 * The owner's user ID, or 0 when there is no usable owner.
 *
 * Validated rather than trusted: an owner who was deleted or demoted must not
 * leave the site in a state nobody can administer, so a stale ID reads as
 * unclaimed and restriction stops applying.
 */
function nibwp_user_access_owner_id(): int
{
    static $resolved = null;
    static $generation = -1;

    $current_generation = nibwp_user_access_cache_generation();
    if ($resolved !== null && $generation === $current_generation) {
        return $resolved;
    }

    $config = nibwp_user_access_config();
    $owner_id = (int) $config['owner_id'];

    if ($owner_id > 0 && (!get_userdata($owner_id) || !user_can($owner_id, 'manage_options'))) {
        $owner_id = 0;
    }

    $resolved = $owner_id;
    $generation = $current_generation;

    return $resolved;
}

/**
 * Whether the given user (default: current) owns the configuration.
 */
function nibwp_user_access_is_owner(?int $user_id = null): bool
{
    $owner_id = nibwp_user_access_owner_id();
    if ($owner_id === 0) {
        return false;
    }

    $user_id = $user_id ?? get_current_user_id();

    return $user_id === $owner_id;
}

/**
 * Whether the feature is available at all on this site.
 *
 * Tier gate. Delete this function's license check to un-tier the feature; the
 * Free build's `nibwp_is_pro()` stub returns false, so Free neutralises itself
 * without a second code path.
 */
function nibwp_user_access_available(): bool
{
    return function_exists('nibwp_is_pro') && nibwp_is_pro();
}

/**
 * Whether menu restriction is actually in force right now.
 */
function nibwp_user_access_enforced(): bool
{
    if (!nibwp_user_access_available()) {
        return false;
    }

    if (nibwp_user_access_owner_id() === 0) {
        return false;
    }

    return nibwp_user_access_config()['enabled'];
}

/**
 * Every NIBWP page the owner can grant, as slug => label.
 *
 * Mirrors the conditionals in the admin_menu registration: a page that is not
 * registered on this install must not appear as a choice. Dashboard is listed
 * first because it anchors the top-level menu.
 *
 * @param bool $include_owner_only Offer Settings / License / User access too.
 *                                 Only ever true for the owner's own row: for
 *                                 anyone else these are what stops the setup
 *                                 being undone by the people it applies to.
 * @return array<string, string>
 */
function nibwp_user_access_page_choices(bool $include_owner_only = false): array
{
    $choices = [
        'nibwp-dashboard' => __('Dashboard', 'nibwp'),
        'nibwp-connect' => __('Connect', 'nibwp'),
        'nibwp-integrations' => __('Integrations', 'nibwp'),
        'nibwp' => __('AI Abilities', 'nibwp'),
    ];

    if (function_exists('nibwp_render_skills_page')) {
        $choices['nibwp-skills'] = __('Skills', 'nibwp');
    }
    if (function_exists('nibwp_render_workflows_page')) {
        $choices['nibwp-workflows'] = __('Workflows', 'nibwp');
    }
    if (function_exists('nibwp_render_jobs_page')) {
        $choices['nibwp-jobs'] = __('Jobs', 'nibwp');
    }
    if (function_exists('nibwp_figma_render_page')) {
        $choices['nibwp-figma'] = __('Figma', 'nibwp');
    }

    $choices['nibwp-memory'] = __('Memory', 'nibwp');
    $choices['nibwp-audit-log'] = __('Audit Log', 'nibwp');
    $choices['nibwp-sandbox'] = __('Sandbox', 'nibwp');
    $choices['nibwp-status'] = __('Status', 'nibwp');

    if ($include_owner_only) {
        $choices['nibwp-user-access'] = __('User access', 'nibwp');
        $choices['nibwp-settings'] = __('Settings', 'nibwp');
        if (defined('NIBWP_LICENSE_CLIENT_LOADED')) {
            $choices['nibwp-license'] = __('License', 'nibwp');
        }
    }

    return $choices;
}

/**
 * Which NIBWP slugs a user may see in their menu.
 *
 * @return array<int, string>|null Null means "no restriction" — show everything,
 *                                 which is also the answer whenever anything is
 *                                 unusual (no owner, no license, no user).
 */
function nibwp_user_access_visible_slugs(?int $user_id = null): ?array
{
    $user_id = $user_id ?? get_current_user_id();
    if ($user_id <= 0) {
        return null;
    }

    static $cache = [];
    static $generation = -1;

    $current_generation = nibwp_user_access_cache_generation();
    if ($generation !== $current_generation) {
        $cache = [];
        $generation = $current_generation;
    }

    if (array_key_exists($user_id, $cache)) {
        return $cache[$user_id];
    }

    $cache[$user_id] = nibwp_user_access_resolve_slugs($user_id);

    return $cache[$user_id];
}

/**
 * Uncached resolution behind nibwp_user_access_visible_slugs().
 *
 * @return array<int, string>|null
 */
function nibwp_user_access_resolve_slugs(int $user_id): ?array
{
    if (!nibwp_user_access_enforced()) {
        return null;
    }

    $is_owner = $user_id === nibwp_user_access_owner_id();
    $choices = nibwp_user_access_page_choices($is_owner);
    $setting = nibwp_user_access_entry($user_id)['pages'];

    // The owner may hide NIBWP from themselves completely. The way back is the
    // saved link, which is the premise of the whole feature — a page dropped
    // from the menu is still routable. Forcing screens to stay would contradict
    // the one thing the owner asked for.
    if ($is_owner && $setting === 'all') {
        return null;
    }

    if ($setting === 'none') {
        return [];
    }

    if ($setting === 'all') {
        $slugs = array_keys($choices);
    } else {
        // Intersect with what actually exists: a slug stored before Figma was
        // switched off would otherwise linger as a dead entry.
        $slugs = array_values(array_intersect((array) $setting, array_keys($choices)));

        // A custom set still needs the top-level landing page to hang from.
        if ($slugs !== [] && !in_array('nibwp-dashboard', $slugs, true)) {
            array_unshift($slugs, 'nibwp-dashboard');
        }
    }

    // The owner keeps whatever they picked, including the configuration
    // screens; everyone else has those stripped so the setup cannot be undone
    // by the people it applies to.
    if ($is_owner) {
        return array_values(array_unique($slugs));
    }

    return array_values(array_diff($slugs, nibwp_user_access_owner_only_slugs()));
}

/**
 * Whether the whole NIBWP menu is hidden from this user.
 */
function nibwp_user_access_fully_hidden(?int $user_id = null): bool
{
    return nibwp_user_access_visible_slugs($user_id) === [];
}

/**
 * Whether this user sees the "NIBWP ON" badge in the WordPress toolbar.
 */
function nibwp_user_access_shows_chip(?int $user_id = null): bool
{
    return nibwp_user_access_shows('chip', $user_id);
}

/**
 * Whether this user sees NIBWP's site-wide admin notices.
 */
function nibwp_user_access_shows_notices(?int $user_id = null): bool
{
    return nibwp_user_access_shows('notices', $user_id);
}

/**
 * Shared lookup behind the two accessors above.
 */
function nibwp_user_access_shows(string $key, ?int $user_id = null): bool
{
    $user_id = $user_id ?? get_current_user_id();
    if ($user_id <= 0 || !nibwp_user_access_enforced()) {
        return true;
    }

    return !empty(nibwp_user_access_entry($user_id)[$key]);
}

/**
 * Version counter the per-request caches key themselves on.
 *
 * The screen saves and then immediately re-renders within the same request, so
 * the caches have to be invalidated mid-request or the owner would be shown the
 * state they just replaced.
 */
function nibwp_user_access_cache_generation(bool $bump = false): int
{
    static $generation = 0;

    if ($bump) {
        $generation++;
    }

    return $generation;
}

/**
 * Invalidate the per-request caches after a save.
 */
function nibwp_user_access_flush_cache(): void
{
    nibwp_user_access_cache_generation(true);
}

/**
 * Record the installer as owner.
 *
 * Runs on activation. Only claims when there is no usable owner already, so
 * deactivating and reactivating cannot transfer ownership to whoever happened
 * to click the button. WP-CLI activations have no current user, hence the ID
 * check — those sites claim on first save instead.
 */
function nibwp_user_access_capture_owner(): void
{
    $config = nibwp_user_access_config();
    $owner_id = (int) $config['owner_id'];

    if ($owner_id > 0 && get_userdata($owner_id) && user_can($owner_id, 'manage_options')) {
        return;
    }

    $current = get_current_user_id();
    if ($current <= 0 || !current_user_can('manage_options')) {
        return;
    }

    $config['owner_id'] = $current;
    nibwp_user_access_save($config);
}
