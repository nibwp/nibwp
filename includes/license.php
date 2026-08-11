<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

// Idempotency guard. The license client is embedded in every paid plugin
// (nibwp-pro, nibwp-skill-*) AND in the monorepo dev source tree under Free.
// Multiple plugins requiring this file at the same time would redeclare every
// function. First loader wins; later ones short-circuit here.
if (defined('NIBWP_LICENSE_CLIENT_LOADED')) {
    return;
}
define('NIBWP_LICENSE_CLIENT_LOADED', '2.0.0');

/**
 * Multi-key license client for NIBWP Premium.
 *
 * Each customer can paste one or more license keys (Pro, Bundle, EtchWP Skill,
 * Bricks Skill, future skills). The union of every active license's
 * entitlements determines what the plugin unlocks at runtime.
 *
 * Server is a FluentCart store running the License Manager addon. Default URL
 * is https://nibwp.com — override via the `nibwp_license_server` filter for
 * staging.
 *
 * Storage (option `nibwp_licenses`):
 *
 *     [
 *         'KEY-ABC-123' => [
 *             'key'           => 'KEY-ABC-123',
 *             'activation_id' => '...',
 *             'product'       => 'pro',
 *             'status'        => 'active',
 *             'entitlements'  => ['pro'],
 *             'expires_at'    => 'YYYY-MM-DD' | 'lifetime',
 *             'allowed_sites' => 1,
 *             'domain'        => 'example.com',
 *             'last_check'    => 1717834567,
 *             'message'       => '',
 *             'raw'           => [...],
 *         ],
 *         ...
 *     ]
 *
 * Backwards compatibility: the legacy `nibwp_license` option (single license,
 * pre-multi-key) is migrated on first read into `nibwp_licenses`.
 */

const NIBWP_LICENSES_OPTION = 'nibwp_licenses';
const NIBWP_LICENSE_LEGACY_OPTION = 'nibwp_license';
const NIBWP_LICENSE_CHECK_TTL = 12 * HOUR_IN_SECONDS;

/**
 * Filterable license server URL.
 */
function nibwp_license_server(): string
{
    return (string) apply_filters('nibwp_license_server', 'https://nibwp.com');
}

/**
 * Filterable checkout / pricing page URL used by "Upgrade" CTAs.
 */
function nibwp_pricing_url(): string
{
    return (string) apply_filters('nibwp_pricing_url', 'https://nibwp.com/pricing');
}

/** Product (checkout) URL for a tier/skill slug, e.g. nibwp_item_url('pro'). */
function nibwp_item_url(string $slug = ''): string
{
    $base = (string) apply_filters('nibwp_item_url_base', 'https://nibwp.com/item');
    $slug = trim($slug, '/');
    return $slug === '' ? $base : $base . '/' . $slug;
}

/**
 * Return all stored licenses (keyed by license key), migrating legacy single-
 * license storage to the new array shape on first access.
 *
 * @return array<string, array<string, mixed>>
 */
function nibwp_licenses_get(): array
{
    $stored = get_option(NIBWP_LICENSES_OPTION, null);
    if (is_array($stored)) {
        return $stored;
    }

    // Migrate legacy single-license option, if present.
    $legacy = get_option(NIBWP_LICENSE_LEGACY_OPTION, null);
    if (is_array($legacy) && !empty($legacy['key'])) {
        $key = (string) $legacy['key'];
        $legacy['entitlements'] = $legacy['entitlements']
            ?? ['pro'];
        $legacy['product'] = $legacy['product'] ?? (string) ($legacy['plan'] ?? 'pro');
        $migrated = [$key => $legacy];
        update_option(NIBWP_LICENSES_OPTION, $migrated, autoload: false);
        delete_option(NIBWP_LICENSE_LEGACY_OPTION);
        return $migrated;
    }

    return [];
}

/**
 * Persist the licenses array.
 *
 * @param array<string, array<string, mixed>> $licenses
 */
function nibwp_licenses_save(array $licenses): void
{
    update_option(NIBWP_LICENSES_OPTION, $licenses, autoload: false);
    // Any license-state mutation invalidates the cached updater answer.
    // Without this, the License page can show 'Active' while the Plugins
    // update screen still says No update because the 12h cache hasn't aged.
    delete_transient('nibwp_update_info');
    delete_site_transient('update_plugins');
    do_action('nibwp_license_state_changed');
}

/**
 * Wipe all stored licenses (used by uninstall / debug "reset license" button).
 */
function nibwp_licenses_clear_all(): void
{
    delete_option(NIBWP_LICENSES_OPTION);
}

/**
 * Lookup a single license by key. If `$key` is null, returns the first stored
 * license (back-compat with the pre-multi-key API).
 *
 * @return array<string, mixed>|null
 */
function nibwp_license_get(?string $key = null): ?array
{
    $all = nibwp_licenses_get();
    if ($key === null) {
        $first = reset($all);
        return is_array($first) ? $first : null;
    }
    return $all[$key] ?? null;
}

/**
 * Remove one license from local storage. Does NOT call the deactivate endpoint.
 */
function nibwp_license_forget(string $key): void
{
    $all = nibwp_licenses_get();
    unset($all[$key]);
    nibwp_licenses_save($all);
}

/**
 * Is this specific license currently active?
 *
 * Policy: a license is active when (a) the local row exists with status='active',
 * AND (b) the paid window hasn't lapsed. Domain mismatch is NOT a deactivation
 * trigger — server-side licensing already binds the activation to a site_url
 * at activate time, and client-side strict host equality breaks every common
 * canonical / www / scheme variation (`www.x.com` vs `x.com`, http→https
 * upgrades, CDN-front rewrites). Removing the strict client-side host check
 * means agents using www. and admins using bare host both see Active.
 *
 * Only the user (Deactivate button) — or a true expiry — flips a license to
 * inactive. Server-side check failures NEVER mutate local status; see
 * nibwp_license_check() for the corresponding write-side policy.
 */
function nibwp_license_is_active_for_key(string $key): bool
{
    $lic = nibwp_license_get($key);
    if ($lic === null || empty($lic['key']) || ($lic['status'] ?? '') !== 'active') {
        return false;
    }

    if (!empty($lic['expires_at']) && $lic['expires_at'] !== 'lifetime') {
        $expires = strtotime((string) $lic['expires_at']);
        if ($expires !== false && $expires < time()) {
            return false;
        }
    }

    return true;
}

/**
 * Normalize a hostname so canonical/www/scheme variants compare equal.
 *
 * Used only by telemetry helpers — the active-check no longer relies on this.
 */
function nibwp_license_normalize_host(string $host): string
{
    $h = strtolower(trim($host));
    if (str_starts_with($h, 'www.')) {
        $h = substr($h, 4);
    }
    return $h;
}

/**
 * Is at least ONE license active for this site? (Pro / Bundle / any skill.)
 *
 * NOT the same as "Pro is unlocked" — for that, call `nibwp_is_pro()`.
 */
function nibwp_any_license_active(): bool
{
    foreach (nibwp_licenses_get() as $key => $_) {
        if (nibwp_license_is_active_for_key((string) $key)) {
            return true;
        }
    }
    return false;
}

/**
 * Flat list of all entitlements unioned from every currently active license.
 *
 * Example return:
 *   ['pro', 'skill:etchwp', 'integration:etchwp', 'skill:bricks']
 *
 * The Bundle product returns `'skill:*'` which wildcards every skill.
 *
 * @return string[]
 */
function nibwp_entitlements(): array
{
    global $nibwp_entitlements_cache;
    if (is_array($nibwp_entitlements_cache)) {
        return $nibwp_entitlements_cache;
    }

    $out = [];
    foreach (nibwp_licenses_get() as $key => $lic) {
        if (!nibwp_license_is_active_for_key((string) $key)) {
            continue;
        }
        foreach ((array) ($lic['entitlements'] ?? []) as $code) {
            $out[] = (string) $code;
        }
    }
    $out = array_values(array_unique($out));

    // Filter point — lets includes/trial.php inject `pro` + `skill:*` during
    // an active 7-day trial, or future addons synthesize entitlements
    // (e.g. a quota-based grant). The cache stores the post-filter result so
    // the trial check fires once per request, not once per ability gate.
    $out = (array) apply_filters('nibwp_entitlements', $out);
    $out = array_values(array_unique(array_map('strval', $out)));

    $nibwp_entitlements_cache = $out;
    return $nibwp_entitlements_cache;
}

/**
 * Reset the internal entitlements cache after a license is added or removed.
 *
 * Using a request-scoped global (rather than a function-static) means we can
 * actually invalidate it from this call site. The previous implementation
 * cleared only the options-group cache, leaving a stale static frozen for
 * the remainder of the request — which is why Pro abilities sometimes
 * appeared in one discover call and vanished in the next when the entitlement
 * vector flipped mid-request.
 */
function nibwp_entitlements_reset(): void
{
    global $nibwp_entitlements_cache;
    $nibwp_entitlements_cache = null;
    wp_cache_delete(NIBWP_LICENSES_OPTION, 'options');
    if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
        $bt = function_exists('wp_debug_backtrace_summary')
            ? (string) wp_debug_backtrace_summary(__FUNCTION__, 0, true)
            : '';
        error_log('[NIBWP] entitlements cache reset' . ($bt !== '' ? ' at ' . $bt : ''));
    }
}

/**
 * Map a raw entitlement code (`pro`, `skill:*`, `skill:etchwp`,
 * `integration:bricks`) to a human-readable label.
 *
 * Filterable via `nibwp_entitlement_label` so future skills/integrations can
 * register their own labels without touching this file.
 */
function nibwp_entitlement_label(string $code): string
{
    static $map = null;
    if ($map === null) {
        $map = (array) apply_filters('nibwp_entitlement_label_map', [
            'pro'                  => __('Pro', 'nibwp'),
            'skill:*'              => __('All Skills', 'nibwp'),
            'skill:etchwp'         => __('EtchWP Skill', 'nibwp'),
            'skill:bricks'         => __('Bricks Skill', 'nibwp'),
            'integration:etchwp'   => __('EtchWP Integration', 'nibwp'),
            'integration:bricks'   => __('Bricks Integration', 'nibwp'),
        ]);
    }
    if (isset($map[$code])) {
        return (string) $map[$code];
    }
    // Pattern fallbacks for slugs we don't have an explicit label for.
    if (strncmp($code, 'skill:', 6) === 0) {
        $slug = substr($code, 6);
        if ($slug === '*') {
            return (string) __('All Skills', 'nibwp');
        }
        return sprintf(/* translators: %s = skill name */ __('%s Skill', 'nibwp'), ucfirst($slug));
    }
    if (strncmp($code, 'integration:', 12) === 0) {
        $slug = substr($code, 12);
        return sprintf(/* translators: %s = integration name */ __('%s Integration', 'nibwp'), ucfirst($slug));
    }
    return $code;
}

/**
 * Generic entitlement check. Supports literal codes and the `'skill:*'`
 * Bundle wildcard.
 *
 * DEV BYPASS: define `NIBWP_DEV_UNLOCK_PRO` (true) in wp-config.php to grant
 * every entitlement locally without an active license. Intended for local
 * development only — do NOT define on the production wp-config.
 */
function nibwp_has_entitlement(string $code): bool
{
    if (defined('NIBWP_DEV_UNLOCK_PRO') && NIBWP_DEV_UNLOCK_PRO === true) {
        return true;
    }

    $entitlements = nibwp_entitlements();
    if (in_array($code, $entitlements, strict: true)) {
        return true;
    }
    // Bundle wildcard for skills: 'skill:*' unlocks any 'skill:foo'.
    if (str_starts_with($code, 'skill:') && in_array('skill:*', $entitlements, strict: true)) {
        return true;
    }
    return false;
}

/**
 * Convenience: does the user have Pro-tier access? (Pro license OR Bundle.)
 *
 * Honors NIBWP_DEV_UNLOCK_PRO dev bypass (see nibwp_has_entitlement).
 */
function nibwp_is_pro(): bool
{
    return nibwp_has_entitlement('pro');
}

/**
 * Convenience: highest-tier plan label for UI badges.
 * Returns 'bundle' | 'pro' | 'skill' | '' (none).
 */
function nibwp_license_plan_label(): string
{
    $entitlements = nibwp_entitlements();
    if (in_array('skill:*', $entitlements, strict: true)) {
        return 'bundle';
    }
    if (in_array('pro', $entitlements, strict: true)) {
        return 'pro';
    }
    foreach ($entitlements as $e) {
        if (str_starts_with($e, 'skill:')) {
            return 'skill';
        }
    }
    return '';
}

/**
 * Send a request to the FluentCart license endpoint and decode JSON.
 *
 * @return array{ok: bool, data: array<string, mixed>, message: string}
 */
function nibwp_license_request(string $endpoint, array $body): array
{
    $url = trailingslashit(nibwp_license_server()) . 'wp-json/fluent-cart/v2/license/' . ltrim($endpoint, '/');
    $body['site_url'] = home_url();
    $body['domain'] = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    $body['plugin'] = 'nibwp';
    $body['plugin_version'] = defined('NIBWP_VERSION') ? NIBWP_VERSION : 'unknown';

    $response = wp_remote_post($url, [
        'timeout'     => 20,
        'redirection' => 3,
        'headers'     => [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ],
        'body' => wp_json_encode($body),
    ]);

    if (is_wp_error($response)) {
        return ['ok' => false, 'data' => [], 'message' => $response->get_error_message()];
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $decoded = json_decode(wp_remote_retrieve_body($response), true);
    $decoded = is_array($decoded) ? $decoded : [];

    if ($code >= 200 && $code < 300 && empty($decoded['error'])) {
        return ['ok' => true, 'data' => $decoded, 'message' => (string) ($decoded['message'] ?? '')];
    }

    $message = (string) ($decoded['message'] ?? $decoded['error'] ?? "HTTP $code");
    return ['ok' => false, 'data' => $decoded, 'message' => $message];
}

/**
 * Activate a license key. Server response is expected to include an
 * `entitlements` array describing what this key unlocks. Older server
 * versions that return only a `plan` string are converted to a single-entry
 * entitlement (`['pro']` etc).
 *
 * @return array{ok: bool, message: string, license?: array<string, mixed>}
 */
function nibwp_license_activate(string $license_key): array
{
    $license_key = trim($license_key);
    if ($license_key === '') {
        return ['ok' => false, 'message' => 'License key is required.'];
    }

    $result = nibwp_license_request('activate', [
        'license_key' => $license_key,
    ]);

    if (!$result['ok']) {
        return ['ok' => false, 'message' => $result['message']];
    }

    $data = $result['data'];
    $entitlements = $data['entitlements'] ?? null;
    if (!is_array($entitlements)) {
        // Back-compat: derive entitlements from product / plan string.
        $product = (string) ($data['product'] ?? $data['plan'] ?? 'pro');
        $entitlements = match ($product) {
            'bundle'        => ['pro', 'skill:*'],
            'etchwp-skill'  => ['skill:etchwp', 'integration:etchwp'],
            'bricks-skill'  => ['skill:bricks', 'integration:bricks'],
            default         => ['pro'],
        };
    }

    // Allowed-sites field name varies by license server vendor:
    //   FluentCart License Manager → `activation_limit`
    //   Easy Digital Downloads SL  → `site_count` / `license_limit`
    //   AppSero / other DIY        → `allowed_sites` / `site_limit`
    // Try them in order so an upstream rename never silently degrades to "1".
    $allowed_sites = (int) ($data['allowed_sites']
        ?? $data['site_limit']
        ?? $data['activation_limit']
        ?? $data['license_limit']
        ?? $data['site_count']
        ?? 1);

    $license = [
        'key'           => $license_key,
        'activation_id' => (string) ($data['activation_id'] ?? $data['activation_hash'] ?? ''),
        'product'       => (string) ($data['product'] ?? $data['plan'] ?? 'pro'),
        'status'        => 'active',
        'entitlements'  => array_values(array_map('strval', $entitlements)),
        'expires_at'    => (string) ($data['expires_at'] ?? $data['expiration_date'] ?? ''),
        'allowed_sites' => $allowed_sites,
        'domain'        => (string) wp_parse_url(home_url(), PHP_URL_HOST),
        'last_check'    => time(),
        'last_remote_status'       => 'active',
        'last_remote_check_failed' => 0,
        'message'       => (string) ($result['message'] ?? ''),
        'raw'           => $data,
    ];

    $all = nibwp_licenses_get();
    $all[$license_key] = $license;
    nibwp_licenses_save($all);

    // Auto-install every package the server lists (Pro + any Skill plugins
    // covered by this license). Soft-fail — license stays active either way.
    $package_installs = nibwp_maybe_install_packages($data);

    return [
        'ok'               => true,
        'message'          => $license['message'] !== '' ? $license['message'] : 'License activated.',
        'license'          => $license,
        'pro_install'      => $package_installs[0] ?? null, // back-compat alias
        'package_installs' => $package_installs,
    ];
}

/**
 * Install every package the server's `packages[]` response advertises (Plan B
 * — per-product FluentCart signed URLs). Returns one result entry per package.
 * Falls back to the legacy single `pro_package_url` field when the server
 * hasn't been upgraded.
 *
 * @return array<int, array{slug:string, version?:string, installed:bool, activated:bool, already_installed:bool, message:string}>
 */
function nibwp_maybe_install_packages(array $response): array
{
    $packages = [];
    if (!empty($response['packages']) && is_array($response['packages'])) {
        foreach ($response['packages'] as $pkg) {
            if (!is_array($pkg) || empty($pkg['slug']) || empty($pkg['url'])) {
                continue;
            }
            $packages[] = [
                'slug'    => (string) $pkg['slug'],
                'url'     => (string) $pkg['url'],
                'version' => (string) ($pkg['version'] ?? ''),
            ];
        }
    }
    // Legacy single-package response.
    if (!$packages && !empty($response['pro_package_url'])) {
        $packages[] = [
            'slug'    => (string) ($response['pro_plugin_slug'] ?? 'nibwp-pro'),
            'url'     => (string) $response['pro_package_url'],
            'version' => '',
        ];
    }

    $results = [];
    foreach ($packages as $pkg) {
        $results[] = nibwp_maybe_install_one_package($pkg['slug'], $pkg['url'], $pkg['version'], $response);
    }
    return $results;
}

/**
 * Install + activate one plugin package via WP's core Plugin_Upgrader.
 * Idempotent. Skips entirely when the monorepo build is already loaded for
 * the same slug (prevents redeclare with bundled premium code).
 */
/**
 * Does the plugin we are running already contain this skill, in full?
 *
 * Free ships each premium skill's `manifest.php` alone, so the Skills screen
 * can render a card for something you have not bought. That manifest is not
 * the skill, so its presence must not count — the test is whether the ability
 * files are here, which only the Pro build has.
 *
 * @param string $slug A package slug such as `nibwp-skill-seo-pro`.
 */
function nibwp_build_already_ships_skill(string $slug): bool
{
    if (strncmp($slug, 'nibwp-skill-', 12) !== 0) {
        return false;
    }

    if (!defined('NIBWP_PLUGIN_DIR')) {
        return false;
    }

    $skill_id = substr($slug, 12);
    if ($skill_id === '') {
        return false;
    }

    $dir = NIBWP_PLUGIN_DIR . 'includes/skills/' . $skill_id . '/';

    return file_exists($dir . 'manifest.php') && is_dir($dir . 'abilities');
}

function nibwp_maybe_install_one_package(string $slug, string $package_url, string $version, array $response): array
{
    $result = [
        'slug'              => $slug,
        'version'           => $version,
        'installed'         => false,
        'activated'         => false,
        'already_installed' => false,
        'message'           => '',
    ];

    // Monorepo bundle: when this file is running from a tree that already has
    // includes/premium/ baked in (dev install or a freshly-installed Pro zip),
    // skip the auto-install entirely — the same nibwp/ folder is already the
    // Pro build.
    if ($slug === 'nibwp-pro' && (defined('NIBWP_PREMIUM_AVAILABLE') || (defined('NIBWP_HAS_PREMIUM_CODE') && NIBWP_HAS_PREMIUM_CODE))) {
        $result['already_installed'] = true;
        $result['activated']         = true;
        $result['message']           = sprintf('%s ships with this build — auto-install skipped.', $slug);
        return $result;
    }

    // Same reasoning for skills, which the Pro build also bundles in full.
    //
    // Installing a standalone Skill add-on beside a Pro build puts a second
    // copy of that skill's integration files on disk. They load from a
    // different path, so require_once does not see the duplicate, and the
    // repeated function declarations are a fatal compile error — the site
    // goes white with no way to recover from inside WordPress.
    //
    // Activating a Pro or Bundle license used to do exactly this: the license
    // server lists every entitled package, and the client installed all of
    // them without asking whether the running build already had them.
    if (nibwp_build_already_ships_skill($slug)) {
        $result['already_installed'] = true;
        $result['activated']         = true;
        $result['message']           = sprintf('%s is included in this build — auto-install skipped.', $slug);
        return $result;
    }

    if (!current_user_can('install_plugins') || !current_user_can('activate_plugins')) {
        $result['message'] = sprintf('Current user cannot install plugins; install %s manually.', $slug);
        return $result;
    }

    // Load every wp-admin include this function may touch BEFORE the first
    // call into them. License-activate runs through the REST API, which does
    // NOT auto-load wp-admin/includes/* — calling is_plugin_active() before
    // the require would fatal ("Call to undefined function") when the target
    // plugin folder already exists on disk.
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

    // OVERLAY case — every release of NIBWP Pro ships as a drop-in replacement
    // for the Free plugin: the zip extracts to the SAME `nibwp/` folder and
    // the SAME `nibwp.php` main file. Detect this and route the install through
    // an overwrite path that re-activates the current plugin (not a fictional
    // `nibwp-pro/nibwp-pro.php`).
    $current_dir = basename(NIBWP_PLUGIN_DIR ?? plugin_dir_path(WP_PLUGIN_DIR . '/nibwp/nibwp.php'));
    $is_overlay  = ($slug === 'nibwp-pro');

    if ($is_overlay) {
        $package_url = (string) apply_filters('nibwp_pro_install_package_url', $package_url, $response);

        $upgrader = new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());
        // overwrite_package=true so WP replaces our running folder instead of
        // erroring "destination folder already exists".
        $install = $upgrader->install($package_url, ['overwrite_package' => true]);
        if (is_wp_error($install)) {
            $result['message'] = sprintf('%s install failed: %s', $slug, $install->get_error_message());
            return $result;
        }
        if ($install === false) {
            $result['message'] = sprintf('%s install failed: WordPress refused the package.', $slug);
            return $result;
        }
        $result['installed'] = true;

        // The new files now live in the SAME folder we are running from. The
        // plugin is already active (it activated us as Free), so there is no
        // second activate_plugin() call to make — the next request will load
        // the new files (including includes/premium/) and flip nibwp_is_pro()
        // to true automatically.
        $result['activated'] = true;
        $result['message']   = sprintf('%s installed in-place — refresh to load Pro features.', $slug);

        // Ensure the entitlements cache is clean so the next request reads
        // fresh state instead of the pre-install "Free" snapshot.
        if (function_exists('nibwp_entitlements_reset')) {
            nibwp_entitlements_reset();
        }

        do_action('nibwp_package_auto_installed', $result, $response);
        return $result;
    }

    // SEPARATE-FOLDER case — skills + future add-ons each get their own
    // wp-content/plugins/<slug>/ directory.
    $plugin_file = "{$slug}/{$slug}.php";
    if (file_exists(WP_PLUGIN_DIR . '/' . $plugin_file)) {
        $result['already_installed'] = true;
        if (!is_plugin_active($plugin_file)) {
            $activate = activate_plugin($plugin_file, '', false, true);
            $result['activated'] = !is_wp_error($activate);
            $result['message']   = $result['activated']
                ? sprintf('%s was already installed — activated.', $slug)
                : sprintf('%s is installed but could not be activated automatically.', $slug);
        } else {
            $result['activated'] = true;
            $result['message']   = sprintf('%s already installed and active.', $slug);
        }
        return $result;
    }

    $package_url = (string) apply_filters('nibwp_pro_install_package_url', $package_url, $response);

    $upgrader = new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());
    $install  = $upgrader->install($package_url);

    if (is_wp_error($install)) {
        $result['message'] = sprintf('%s install failed: %s', $slug, $install->get_error_message());
        return $result;
    }
    if ($install === false) {
        $result['message'] = sprintf('%s install failed: WordPress refused the package.', $slug);
        return $result;
    }

    $result['installed'] = true;

    if (!file_exists(WP_PLUGIN_DIR . '/' . $plugin_file)) {
        $candidates = glob(WP_PLUGIN_DIR . "/{$slug}/*.php") ?: [];
        foreach ($candidates as $candidate) {
            $headers = get_plugin_data($candidate, false, false);
            if (stripos($headers['Name'] ?? '', 'NIBWP') !== false) {
                $plugin_file = $slug . '/' . basename($candidate);
                break;
            }
        }
    }

    $activate = activate_plugin($plugin_file, '', false, true);
    $result['activated'] = !is_wp_error($activate);
    $result['message']   = $result['activated']
        ? sprintf('%s installed and activated.', $slug)
        : sprintf('%s installed; activation failed — activate manually from the Plugins screen.', $slug);

    do_action('nibwp_package_auto_installed', $result, $response);
    return $result;
}

/**
 * Back-compat shim. Delegates to nibwp_maybe_install_packages() and returns
 * just the first install result (or a no-op shape). Existing external code
 * that called nibwp_maybe_install_pro_plugin() keeps working.
 *
 * @param array<string, mixed> $response
 * @return array<string, mixed>
 */
function nibwp_maybe_install_pro_plugin(array $response): array
{
    $results = nibwp_maybe_install_packages($response);
    if (!empty($results)) {
        return $results[0];
    }
    return [
        'installed'         => false,
        'activated'         => false,
        'already_installed' => false,
        'slug'              => (string) ($response['pro_plugin_slug'] ?? 'nibwp-pro'),
        'message'           => 'No packages advertised by the server.',
    ];
}

/**
 * Legacy single-package installer kept for reference / direct callers. New
 * code should use nibwp_maybe_install_packages() instead.
 *
 * @param array<string, mixed> $response
 * @return array<string, mixed>
 */
function nibwp_maybe_install_pro_plugin_legacy(array $response): array
{
    $result = [
        'installed'         => false,
        'activated'         => false,
        'already_installed' => false,
        'slug'              => (string) ($response['pro_plugin_slug'] ?? 'nibwp-pro'),
        'message'           => '',
    ];

    // Monorepo build (premium/ ships inside Free) already has every Pro file
    // loaded. Installing the standalone nibwp-pro plugin alongside would
    // double-load premium/bootstrap.php and redeclare every premium function.
    if (defined('NIBWP_PREMIUM_AVAILABLE') || defined('NIBWP_HAS_PREMIUM_CODE') && NIBWP_HAS_PREMIUM_CODE) {
        $result['already_installed'] = true;
        $result['activated']         = true;
        $result['message']           = 'Pro features ship with this build — no separate nibwp-pro plugin needed.';
        return $result;
    }

    $package_url = (string) ($response['pro_package_url'] ?? '');
    if ($package_url === '') {
        $result['message'] = 'Server did not provide a Pro package URL — skipping auto-install.';
        return $result;
    }

    if (!current_user_can('install_plugins') || !current_user_can('activate_plugins')) {
        $result['message'] = 'Current user cannot install plugins. Have a site admin install nibwp-pro manually.';
        return $result;
    }

    $slug = $result['slug'];
    $plugin_file = "{$slug}/{$slug}.php";

    // Pull in WP's installer stack. Front-end contexts (REST callback) usually
    // haven't loaded these, so do it lazily here — BEFORE any call into
    // is_plugin_active() or activate_plugin(), which both live in
    // wp-admin/includes/plugin.php. Calling them before the require would
    // fatal on REST routes ("Call to undefined function") when the target
    // plugin folder already exists on disk.
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

    // Already installed? Just make sure it's active.
    if (file_exists(WP_PLUGIN_DIR . '/' . $plugin_file)) {
        $result['already_installed'] = true;
        if (!is_plugin_active($plugin_file)) {
            $activate = activate_plugin($plugin_file, '', false, true);
            $result['activated'] = !is_wp_error($activate);
            $result['message'] = $result['activated']
                ? 'NIBWP Pro was already installed — activated.'
                : 'NIBWP Pro is installed but could not be activated automatically.';
        } else {
            $result['activated'] = true;
            $result['message'] = 'NIBWP Pro already installed and active.';
        }
        return $result;
    }

    $package_url = (string) apply_filters('nibwp_pro_install_package_url', $package_url, $response);

    $upgrader = new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());
    $install  = $upgrader->install($package_url);

    if (is_wp_error($install)) {
        $result['message']     = 'Pro install failed: ' . $install->get_error_message();
        $result['package_url'] = $package_url;
        return $result;
    }

    if ($install === false) {
        $result['message']     = 'Pro install failed: WordPress refused the package.';
        $result['package_url'] = $package_url;
        return $result;
    }

    $result['installed'] = true;

    // Plugin_Upgrader may install under a different basename than expected.
    // Re-discover by slug to be safe.
    if (!file_exists(WP_PLUGIN_DIR . '/' . $plugin_file)) {
        $candidates = glob(WP_PLUGIN_DIR . "/{$slug}/*.php") ?: [];
        foreach ($candidates as $candidate) {
            $headers = get_plugin_data($candidate, false, false);
            if (($headers['Name'] ?? '') === 'NIBWP Pro') {
                $plugin_file = $slug . '/' . basename($candidate);
                break;
            }
        }
    }

    $activate = activate_plugin($plugin_file, '', false, true);
    $result['activated'] = !is_wp_error($activate);
    $result['message']   = $result['activated']
        ? 'NIBWP Pro installed and activated.'
        : 'NIBWP Pro installed; activation failed — activate manually from the Plugins screen.';

    do_action('nibwp_pro_auto_installed', $result, $response);

    return $result;
}

/**
 * Deactivate a license against the server, then remove it locally. The local
 * copy is always wiped even if the server returns 4xx — the user wants out.
 *
 * If `$license_key` is null, deactivates the first stored license (back-compat
 * with the pre-multi-key API).
 */
function nibwp_license_deactivate(?string $license_key = null): array
{
    if ($license_key === null) {
        $first = nibwp_license_get();
        if ($first === null) {
            return ['ok' => true, 'message' => 'No license to deactivate.'];
        }
        $license_key = (string) $first['key'];
    }

    $lic = nibwp_license_get($license_key);
    if ($lic === null) {
        return ['ok' => true, 'message' => 'License not found locally.'];
    }

    $result = nibwp_license_request('deactivate', [
        'license_key'   => $lic['key'],
        'activation_id' => $lic['activation_id'] ?? '',
    ]);

    nibwp_license_forget($license_key);

    return [
        'ok'      => true,
        'message' => $result['ok']
            ? 'License deactivated.'
            : 'Local copy cleared. Remote: ' . $result['message'],
    ];
}

/**
 * Re-validate one license with the server. Throttled to NIBWP_LICENSE_CHECK_TTL
 * unless `$force` is true.
 */
function nibwp_license_check(string $license_key, bool $force = false): array
{
    $lic = nibwp_license_get($license_key);
    if ($lic === null) {
        return ['ok' => false, 'message' => 'License not found.'];
    }

    if (!$force && !empty($lic['last_check']) && (time() - (int) $lic['last_check']) < NIBWP_LICENSE_CHECK_TTL) {
        return ['ok' => true, 'cached' => true, 'license' => $lic];
    }

    $result = nibwp_license_request('check', [
        'license_key'   => $lic['key'],
        'activation_id' => $lic['activation_id'] ?? '',
    ]);

    // POLICY — only the user (Deactivate button) flips a license to inactive.
    // A server-side check that returns ok:false (transient network errors,
    // license server outages, server admin resets, race conditions in the
    // FC license manager) MUST NOT mutate the local status. We record the
    // remote signal for telemetry + UI surfacing, but the row remains
    // whatever the user last set it to.
    if (!$result['ok']) {
        $lic['last_remote_status']      = (string) ($result['data']['status'] ?? 'unknown');
        $lic['last_remote_message']     = (string) ($result['message'] ?? '');
        $lic['last_remote_check_failed']= time();
        $all = nibwp_licenses_get();
        $all[$license_key] = $lic;
        nibwp_licenses_save($all);
        return ['ok' => false, 'message' => $result['message'], 'license' => $lic];
    }

    $data = $result['data'];
    $entitlements = is_array($data['entitlements'] ?? null) ? $data['entitlements'] : ($lic['entitlements'] ?? []);

    // Metadata refresh only. Status field NEVER overwritten from server check —
    // when the server says "expired" but the customer believes the license is
    // valid, we trust the customer's local state until they explicitly act.
    // Activation (nibwp_license_activate) is the only path that re-asserts
    // status='active' from a server response, because the user explicitly
    // initiated the bind.
    $lic['product']       = (string) ($data['product'] ?? $lic['product'] ?? 'pro');
    $lic['entitlements']  = array_values(array_map('strval', (array) $entitlements));
    if (!empty($data['expires_at'])) {
        $lic['expires_at'] = (string) $data['expires_at'];
    }
    // FluentCart License Manager returns `activation_limit`, not `allowed_sites`.
    $lic['allowed_sites'] = (int) ($data['allowed_sites']
        ?? $data['site_limit']
        ?? $data['activation_limit']
        ?? $data['license_limit']
        ?? $data['site_count']
        ?? $lic['allowed_sites']
        ?? 1);
    $lic['last_check']         = time();
    $lic['last_remote_status'] = (string) ($data['status'] ?? '');
    $lic['last_remote_check_failed'] = 0;
    $lic['raw']                = $data;

    $all = nibwp_licenses_get();
    $all[$license_key] = $lic;
    nibwp_licenses_save($all);

    return ['ok' => true, 'license' => $lic];
}

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * Email-discovery (OTP) flow.
 *
 * Convenience layer on top of multi-key licensing. User enters their nibwp.com
 * purchase email, server emails a 6-digit OTP, user verifies, server returns
 * the array of licenses tied to that email. Plugin pre-fills the activation
 * list — user clicks "Activate all" or activates individually.
 *
 * Server endpoints expected on nibwp.com (FluentCart + small custom plugin):
 *   POST /wp-json/fluent-cart/v2/account/request-otp
 *        { email }                       → 200 { sent: true }
 *   POST /wp-json/fluent-cart/v2/account/verify-otp
 *        { email, code }                 → 200 { token, licenses: [...] }
 *
 * `licenses` shape per row:
 *   { key, product, entitlements: [...], expires_at, allowed_sites, status }
 * ─────────────────────────────────────────────────────────────────────────────
 */

const NIBWP_ACCOUNT_TOKEN_OPTION = 'nibwp_account_token';

/**
 * Generic request to an account endpoint on the license server.
 *
 * Mirrors `nibwp_license_request()` but hits the `/account/` namespace and
 * does NOT inject site_url / domain into the body (account flow is
 * site-agnostic — the same email may have many sites).
 *
 * @return array{ok: bool, data: array<string, mixed>, message: string}
 */
function nibwp_account_request(string $endpoint, array $body): array
{
    $url = trailingslashit(nibwp_license_server()) . 'wp-json/fluent-cart/v2/account/' . ltrim($endpoint, '/');
    $body['plugin'] = 'nibwp';
    $body['plugin_version'] = defined('NIBWP_VERSION') ? NIBWP_VERSION : 'unknown';

    $response = wp_remote_post($url, [
        'timeout'     => 20,
        'redirection' => 3,
        'headers'     => [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ],
        'body' => wp_json_encode($body),
    ]);

    if (is_wp_error($response)) {
        return ['ok' => false, 'data' => [], 'message' => $response->get_error_message()];
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $decoded = json_decode(wp_remote_retrieve_body($response), true);
    $decoded = is_array($decoded) ? $decoded : [];

    if ($code >= 200 && $code < 300 && empty($decoded['error'])) {
        return ['ok' => true, 'data' => $decoded, 'message' => (string) ($decoded['message'] ?? '')];
    }

    $message = (string) ($decoded['message'] ?? $decoded['error'] ?? "HTTP $code");
    return ['ok' => false, 'data' => $decoded, 'message' => $message];
}

/**
 * Step 1 of email discovery: ask the server to mail an OTP code to `$email`.
 *
 * Server is responsible for rate-limiting per email to prevent abuse.
 *
 * @return array{ok: bool, message: string}
 */
function nibwp_account_request_otp(string $email): array
{
    $email = sanitize_email(trim($email));
    if (!is_email($email)) {
        return ['ok' => false, 'message' => 'Please enter a valid email address.'];
    }
    $result = nibwp_account_request('request-otp', ['email' => $email]);
    return [
        'ok'      => $result['ok'],
        'message' => $result['message'] !== ''
            ? $result['message']
            : ($result['ok'] ? 'Verification code sent. Check your inbox.' : 'Could not send code.'),
    ];
}

/**
 * Step 2 of email discovery: verify the OTP and pull the list of licenses
 * owned by this email.
 *
 * On success the masked email + account token are persisted so the License
 * panel can display "Connected as you@e****.com" without re-prompting.
 *
 * Licenses returned by the server are NOT auto-activated — the user must click
 * "Activate" per row (or "Activate all"). This gives them control over which
 * licenses bind to which site (important when allowed_sites < owned_sites).
 *
 * @return array{ok: bool, message: string, licenses?: array<int, array<string, mixed>>}
 */
function nibwp_account_verify_otp(string $email, string $code): array
{
    $email = sanitize_email(trim($email));
    $code = preg_replace('/\D+/', '', trim($code));
    if (!is_email($email)) {
        return ['ok' => false, 'message' => 'Invalid email.'];
    }
    if (strlen($code) < 4) {
        return ['ok' => false, 'message' => 'Enter the verification code from your email.'];
    }

    $result = nibwp_account_request('verify-otp', ['email' => $email, 'code' => $code]);
    if (!$result['ok']) {
        return ['ok' => false, 'message' => $result['message']];
    }

    $data = $result['data'];
    $token = (string) ($data['token'] ?? '');
    $licenses = is_array($data['licenses'] ?? null) ? $data['licenses'] : [];

    if ($token !== '') {
        update_option(NIBWP_ACCOUNT_TOKEN_OPTION, [
            'email'       => $email,
            'masked'      => nibwp_account_mask_email($email),
            'token'       => $token,
            'connected_at' => time(),
        ], autoload: false);
    }

    return [
        'ok'       => true,
        'message'  => 'Verified. ' . count($licenses) . ' license(s) found.',
        'licenses' => array_values($licenses),
    ];
}

/**
 * Clear the connected-account token (e.g. when user clicks Disconnect on the
 * License panel). Does NOT deactivate any stored licenses — those remain.
 */
function nibwp_account_disconnect(): void
{
    delete_option(NIBWP_ACCOUNT_TOKEN_OPTION);
}

/**
 * Return the connected-account record, or null when no email is connected.
 *
 * @return array{email: string, masked: string, token: string, connected_at: int}|null
 */
function nibwp_account_get(): ?array
{
    $stored = get_option(NIBWP_ACCOUNT_TOKEN_OPTION, null);
    return is_array($stored) && !empty($stored['email']) ? $stored : null;
}

/**
 * Mask an email for display: 'someone@example.com' → 'so****@e****.com'.
 */
function nibwp_account_mask_email(string $email): string
{
    $at = strpos($email, '@');
    if ($at === false) {
        return $email;
    }
    $local = substr($email, 0, $at);
    $domain = substr($email, $at + 1);

    $mask_local = strlen($local) <= 2
        ? $local
        : substr($local, 0, 2) . str_repeat('*', max(1, strlen($local) - 2));

    $dot = strpos($domain, '.');
    if ($dot === false) {
        $mask_domain = substr($domain, 0, 1) . str_repeat('*', max(1, strlen($domain) - 1));
    } else {
        $domain_head = substr($domain, 0, $dot);
        $domain_tld = substr($domain, $dot);
        $mask_domain = substr($domain_head, 0, 1) . str_repeat('*', max(1, strlen($domain_head) - 1)) . $domain_tld;
    }

    return $mask_local . '@' . $mask_domain;
}

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * REST endpoints — consumed by the License page UI and the lock-popup
 * component on Integrations / Skills cards. All routes require manage_options
 * + the standard wp_rest nonce (X-WP-Nonce header).
 * ─────────────────────────────────────────────────────────────────────────────
 */

add_action('rest_api_init', static function (): void {
    $perm = static function (): bool { return current_user_can('manage_options'); };

    register_rest_route('nibwp/v1', '/license/list', [
        'methods'             => 'GET',
        'permission_callback' => $perm,
        'callback'            => static function (): array {
            return [
                'licenses' => nibwp_license_cards(),
                'account'  => nibwp_account_get(),
                'is_pro'   => nibwp_is_pro(),
                'entitlements' => nibwp_entitlements(),
            ];
        },
    ]);

    register_rest_route('nibwp/v1', '/license/activate', [
        'methods'             => 'POST',
        'permission_callback' => $perm,
        'args'                => [
            'key' => ['type' => 'string', 'required' => true],
        ],
        'callback'            => static function (WP_REST_Request $req): array {
            $result = nibwp_license_activate((string) $req->get_param('key'));
            $result['cards'] = nibwp_license_cards();
            $result['is_pro'] = nibwp_is_pro();
            $result['entitlements'] = nibwp_entitlements();
            return $result;
        },
    ]);

    register_rest_route('nibwp/v1', '/license/deactivate', [
        'methods'             => 'POST',
        'permission_callback' => $perm,
        'args'                => [
            'key' => ['type' => 'string', 'required' => true],
        ],
        'callback'            => static function (WP_REST_Request $req): array {
            $result = nibwp_license_deactivate((string) $req->get_param('key'));
            $result['cards'] = nibwp_license_cards();
            $result['is_pro'] = nibwp_is_pro();
            $result['entitlements'] = nibwp_entitlements();
            return $result;
        },
    ]);

    register_rest_route('nibwp/v1', '/license/check', [
        'methods'             => 'POST',
        'permission_callback' => $perm,
        'args'                => [
            'key'   => ['type' => 'string', 'required' => true],
            'force' => ['type' => 'boolean', 'default' => true],
        ],
        'callback'            => static function (WP_REST_Request $req): array {
            $result = nibwp_license_check(
                (string) $req->get_param('key'),
                (bool) $req->get_param('force'),
            );
            $result['cards'] = nibwp_license_cards();
            return $result;
        },
    ]);

    register_rest_route('nibwp/v1', '/account/request-otp', [
        'methods'             => 'POST',
        'permission_callback' => $perm,
        'args'                => [
            'email' => ['type' => 'string', 'required' => true],
        ],
        'callback'            => static function (WP_REST_Request $req): array {
            return nibwp_account_request_otp((string) $req->get_param('email'));
        },
    ]);

    register_rest_route('nibwp/v1', '/account/verify-otp', [
        'methods'             => 'POST',
        'permission_callback' => $perm,
        'args'                => [
            'email' => ['type' => 'string', 'required' => true],
            'code'  => ['type' => 'string', 'required' => true],
        ],
        'callback'            => static function (WP_REST_Request $req): array {
            return nibwp_account_verify_otp(
                (string) $req->get_param('email'),
                (string) $req->get_param('code'),
            );
        },
    ]);

    register_rest_route('nibwp/v1', '/account/disconnect', [
        'methods'             => 'POST',
        'permission_callback' => $perm,
        'callback'            => static function (): array {
            nibwp_account_disconnect();
            return ['ok' => true];
        },
    ]);
});

/**
 * Daily background check of every stored license.
 */
add_action('init', static function (): void {
    if (!wp_next_scheduled('nibwp_license_daily_check')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'nibwp_license_daily_check');
    }
});
add_action('nibwp_license_daily_check', static function (): void {
    foreach (nibwp_licenses_get() as $key => $_) {
        nibwp_license_check((string) $key, force: true);
    }
});

/**
 * Self-heal: a common failure mode of the activation flow is that
 * nibwp_license_activate() runs through the REST API, the license row is
 * persisted as active, but the bundled Plugin_Upgrader call that pulls the
 * Pro zip silently fails (hosting policy DISALLOW_FILE_MODS, missing FTP
 * creds, network blip, etc.). The site ends up "license active, premium
 * code absent" — pricings still visible, all Pro abilities locked.
 *
 * This hook detects that exact state on every admin page load (throttled to
 * once per 6 hours) and retries the package install transparently. If it
 * succeeds, the next request loads premium/bootstrap.php and the site
 * unlocks without any further user action.
 */
add_action('admin_init', static function (): void {
    if (wp_doing_ajax() || wp_doing_cron()) return;
    if (defined('NIBWP_HAS_PREMIUM_CODE') && NIBWP_HAS_PREMIUM_CODE) return;
    if (!current_user_can('manage_options')) return;
    if (!current_user_can('install_plugins')) return;
    if (!nibwp_any_license_active()) return;
    if (get_transient('nibwp_premium_self_heal') === '1') return;
    set_transient('nibwp_premium_self_heal', '1', 6 * HOUR_IN_SECONDS);

    $key = '';
    foreach (nibwp_licenses_get() as $k => $_) {
        if (nibwp_license_is_active_for_key((string) $k)) { $key = (string) $k; break; }
    }
    if ($key === '') return;

    $check = nibwp_license_check($key, force: true);
    $raw   = $check['license']['raw'] ?? null;
    if (is_array($raw) && !empty($raw)) {
        nibwp_maybe_install_packages($raw);
    }
});

/**
 * UI summary cards — returns one entry per stored license + an "add new" CTA
 * row that the Skills / License page can render in a list.
 *
 * @return array<int, array<string, mixed>>
 */
function nibwp_license_cards(): array
{
    $cards = [];
    foreach (nibwp_licenses_get() as $key => $lic) {
        $active = nibwp_license_is_active_for_key((string) $key);
        $expires = (string) ($lic['expires_at'] ?? '');
        $expires_human = $expires === '' || $expires === 'lifetime'
            ? __('Lifetime', domain: 'nibwp')
            : date_i18n(get_option('date_format'), strtotime($expires));

        $cards[] = [
            'key'           => (string) $key,
            'masked_key'    => nibwp_license_mask_key((string) $key),
            'product'       => (string) ($lic['product'] ?? ''),
            'entitlements'  => (array) ($lic['entitlements'] ?? []),
            'state'         => $active ? 'active' : 'invalid',
            'expires'       => $expires_human,
            'allowed_sites' => (int) ($lic['allowed_sites'] ?? 1),
        ];
    }
    return $cards;
}

/**
 * Format a license key for display (NIB-****-1234 style).
 */
function nibwp_license_mask_key(string $key): string
{
    $len = strlen($key);
    if ($len <= 8) {
        return str_repeat('*', max(0, $len - 4)) . substr($key, -4);
    }
    return substr($key, 0, 4) . str_repeat('*', $len - 8) . substr($key, -4);
}

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * Back-compat wrappers — keep older callers (skills-page.php, marketing copy)
 * working while the multi-key UI is being built out. These will be removed
 * once skills-page.php is fully migrated to the multi-key API.
 * ─────────────────────────────────────────────────────────────────────────────
 */

/**
 * Legacy: returns the first stored license (or empty array). New callers
 * should use `nibwp_licenses_get()` directly.
 *
 * @return array<string, mixed>
 */
if (!function_exists('nibwp_license_is_active')) {
    function nibwp_license_is_active(): bool
    {
        return nibwp_any_license_active();
    }
}

if (!function_exists('nibwp_license_plan')) {
    function nibwp_license_plan(): string
    {
        return nibwp_license_plan_label();
    }
}

if (!function_exists('nibwp_license_status_card')) {
    function nibwp_license_status_card(): array
    {
        if (!nibwp_any_license_active()) {
            $any = !empty(nibwp_licenses_get());
            return [
                'state'     => $any ? 'invalid' : 'none',
                'title'     => $any
                    ? __('License inactive', domain: 'nibwp')
                    : __('No license active', domain: 'nibwp'),
                'subtitle'  => $any
                    ? __('Domain mismatch or expired. Reactivate to restore premium features.', domain: 'nibwp')
                    : __('Activate a Pro or Bundle license to unlock premium integrations, skills, and toolkits.', domain: 'nibwp'),
                'cta_label' => $any
                    ? __('Reactivate', domain: 'nibwp')
                    : __('Activate License', domain: 'nibwp'),
            ];
        }

        // Build a card from the highest-tier active license.
        $plan = nibwp_license_plan_label();
        $title = match ($plan) {
            'bundle' => __('NIBWP Bundle — Active', domain: 'nibwp'),
            'pro'    => __('NIBWP Pro — Active', domain: 'nibwp'),
            'skill'  => __('NIBWP Skill — Active', domain: 'nibwp'),
            default  => __('NIBWP — Active', domain: 'nibwp'),
        };

        // Earliest expiry across active licenses, for the subtitle.
        $earliest = null;
        foreach (nibwp_licenses_get() as $k => $lic) {
            if (!nibwp_license_is_active_for_key((string) $k)) continue;
            $exp = (string) ($lic['expires_at'] ?? '');
            if ($exp === '' || $exp === 'lifetime') continue;
            $t = strtotime($exp);
            if ($t !== false && ($earliest === null || $t < $earliest)) {
                $earliest = $t;
            }
        }
        $subtitle = $earliest === null
            ? __('Lifetime — never expires', domain: 'nibwp')
            : sprintf(__('Earliest expiry: %s', domain: 'nibwp'), date_i18n(get_option('date_format'), $earliest));

        return [
            'state'     => 'active',
            'title'     => $title,
            'subtitle'  => $subtitle,
            'cta_label' => __('Manage Licenses', domain: 'nibwp'),
        ];
    }
}

/**
 * Legacy: `nibwp_license_get()` with no arg returned the single stored license
 * pre-multi-key. New callers must pass a key. When called without an arg, we
 * return the first stored license for back-compat with skills-page.php.
 *
 * Wrap in function_exists so the multi-key version above (with `string $key`
 * required parameter) wins for callers that pass an arg.
 */
if (!function_exists('nibwp_legacy_license_get_first')) {
    function nibwp_legacy_license_get_first(): array
    {
        $all = nibwp_licenses_get();
        $first = reset($all);
        return is_array($first) ? $first : [];
    }
}
