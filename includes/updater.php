<?php

declare(strict_types=1);

/**
 * Self-hosted plugin update checker.
 *
 * Pulls update metadata from the NIBWP license server's POST /wp-json/fluent-cart/v2/pro/info
 * endpoint (see nibwp-license-server plugin). The endpoint requires the active
 * license_key — it returns the resolved package URL, version, sections, etc.
 * for the slug the client requested (default: nibwp-pro).
 *
 * If no license key is present (free build, license deactivated, license
 * expired) the call is skipped and WordPress falls back to whatever it knows
 * from wp.org for the free build.
 */

if (!defined('ABSPATH')) {
    exit();
}

add_filter('site_transient_update_plugins', callback: 'nibwp_check_for_updates');
add_filter('plugins_api', callback: 'nibwp_plugins_api', priority: 10, accepted_args: 3);

/**
 * Inject update data into the plugins update transient.
 *
 * @param mixed $transient The update_plugins transient value.
 * @return mixed
 */
function nibwp_check_for_updates($transient)
{
    if (!is_object($transient)) {
        return $transient;
    }

    /** @var object{response: array<string, object>, no_update: array<string, object>, checked?: array<string, string>} $transient */

    $remote = nibwp_fetch_update_info();
    $plugin_file = plugin_basename(dirname(__DIR__) . '/nibwp.php');

    if ($remote === null || !version_compare(NIBWP_VERSION, $remote['version'], operator: '<')) {
        // Anything already queued for this plugin came from somewhere else —
        // in practice wordpress.org, which carries the same slug but the free
        // build. Installing it would replace a licensed copy with a smaller
        // one, so the entry goes. The Update URI header in nibwp.php stops
        // WordPress asking wordpress.org at all; this is what catches an entry
        // left in the transient from before the header existed.
        unset($transient->response[$plugin_file]);

        $transient->no_update[$plugin_file] = (object) [
            'id' => $plugin_file,
            'slug' => 'nibwp',
            'plugin' => $plugin_file,
            'new_version' => NIBWP_VERSION,
            'url' => '',
            'package' => '',
        ];

        return $transient;
    }

    $update_data = [
        'id' => $plugin_file,
        'slug' => 'nibwp',
        'plugin' => $plugin_file,
        'new_version' => $remote['version'],
        'url' => $remote['homepage'],
        'package' => $remote['download_url'],
        'tested' => $remote['tested'],
        'requires_php' => $remote['requires_php'],
        'requires' => $remote['requires'],
        'icons' => $remote['icons'],
        'banners' => $remote['banners'],
    ];
    $transient->response[$plugin_file] = (object) $update_data;

    return $transient;
}

/**
 * Supply plugin info for the "View Details" popup.
 *
 * @param false|object|array $result
 * @param string             $action
 * @param object             $args
 * @return false|object|array
 */
function nibwp_plugins_api($result, $action, $args)
{
    if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'nibwp') {
        return $result;
    }

    $remote = nibwp_fetch_update_info();
    if ($remote === null) {
        return $result;
    }

    return (object) [
        'name' => $remote['name'],
        'slug' => 'nibwp',
        'version' => $remote['version'],
        'author' => $remote['author'],
        'author_profile' => $remote['author_homepage'],
        'homepage' => $remote['homepage'],
        'requires' => $remote['requires'],
        'requires_php' => $remote['requires_php'],
        'tested' => $remote['tested'],
        'last_updated' => $remote['last_updated'],
        'sections' => $remote['sections'],
        'icons' => $remote['icons'],
        'banners' => $remote['banners'],
        'download_link' => $remote['download_url'],
    ];
}

/**
 * Fetch plugin info from the license server, with transient caching.
 *
 * @return array{name: string, version: string, author: string, author_homepage: string, homepage: string, requires: string, requires_php: string, tested: string, last_updated: string, sections: array<string, string>, icons: array<string, string>, banners: array<string, string>, download_url: string}|null
 */
function nibwp_fetch_update_info()
{
    $cache_key = 'nibwp_update_info';
    /** @var array{name: string, version: string, author: string, author_homepage: string, homepage: string, requires: string, requires_php: string, tested: string, last_updated: string, sections: array<string, string>, icons: array<string, string>, banners: array<string, string>, download_url: string}|string|false $cached */
    $cached = get_transient($cache_key);

    if ($cached === 'error') {
        return null;
    }
    if (is_array($cached)) {
        return $cached;
    }

    $raw = nibwp_request_update_info();
    if ($raw === null) {
        // Short error TTL (5 minutes, not an hour) — license-server hiccups
        // and transient network failures should not block the user from
        // seeing the Update button shortly after they fix the underlying
        // issue. Trades a few extra polls per day for far better UX.
        set_transient($cache_key, value: 'error', expiration: 5 * MINUTE_IN_SECONDS);
        return null;
    }

    $data = nibwp_normalize_update_response($raw);
    set_transient($cache_key, value: $data, expiration: 6 * HOUR_IN_SECONDS);
    return $data;
}

/**
 * Force the next plugin-update poll to fetch fresh data from the license
 * server AND ask WordPress to rebuild its plugins-update transient. Called
 * after every state change that could affect the answer — license
 * activate / reactivate / refresh / deactivate / package install.
 */
function nibwp_updater_bust_caches(): void
{
    delete_transient('nibwp_update_info');
    delete_site_transient('update_plugins');
}
add_action('nibwp_license_activated',   'nibwp_updater_bust_caches');
add_action('nibwp_license_reactivated', 'nibwp_updater_bust_caches');
add_action('nibwp_license_deactivated', 'nibwp_updater_bust_caches');
add_action('nibwp_package_auto_installed', 'nibwp_updater_bust_caches');

/**
 * Return the first active license key, or '' if none.
 */
function nibwp_updater_active_license_key(): string
{
    if (!function_exists('nibwp_licenses_get') || !function_exists('nibwp_license_is_active_for_key')) {
        return '';
    }
    foreach (nibwp_licenses_get() as $key => $_lic) {
        if (nibwp_license_is_active_for_key((string) $key)) {
            return (string) $key;
        }
    }
    return '';
}

/**
 * POST to the license server's /pro/info endpoint and return the raw payload.
 *
 * @return array<string, mixed>|null Raw decoded response, or null on failure.
 */
function nibwp_request_update_info()
{
    $license_key = nibwp_updater_active_license_key();
    if ($license_key === '') {
        return null;
    }

    $base = function_exists('nibwp_license_server') ? nibwp_license_server() : 'https://nibwp.com';
    $url  = trailingslashit($base) . 'wp-json/fluent-cart/v2/pro/info';

    $response = wp_remote_post($url, [
        'timeout'     => 10,
        'redirection' => 3,
        'headers'     => [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ],
        'body' => wp_json_encode([
            'license_key' => $license_key,
            'slug'        => 'nibwp-pro',
            'site_url'    => home_url(),
            'plugin_version' => NIBWP_VERSION,
        ]),
    ]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return null;
    }

    /** @var array<string, mixed>|null $raw */
    $raw = json_decode(wp_remote_retrieve_body($response), associative: true);

    if (!is_array($raw) || !is_string($raw['version'] ?? null) || $raw['version'] === '') {
        return null;
    }

    return $raw;
}

/**
 * Normalize the raw API response into a typed array.
 *
 * @param array<string, mixed> $raw Raw decoded API response.
 * @return array{name: string, version: string, author: string, author_homepage: string, homepage: string, requires: string, requires_php: string, tested: string, last_updated: string, sections: array<string, string>, icons: array<string, string>, banners: array<string, string>, download_url: string}
 */
function nibwp_normalize_update_response($raw)
{
    /** @var array<string, string> $sections */
    $sections = is_array($raw['sections'] ?? null) ? $raw['sections'] : [];
    /** @var array<string, string> $icons */
    $icons = is_array($raw['icons'] ?? null) ? $raw['icons'] : [];
    /** @var array<string, string> $banners */
    $banners = is_array($raw['banners'] ?? null) ? $raw['banners'] : [];

    // License server returns `package_url` (singular). Legacy `download_url`
    // is honored as a fallback.
    $download_url = (string) ($raw['package_url'] ?? $raw['download_url'] ?? '');

    return [
        'name' => (string) ($raw['name'] ?? 'NIBWP'),
        'version' => (string) $raw['version'],
        'author' => (string) ($raw['author'] ?? ''),
        'author_homepage' => (string) ($raw['author_homepage'] ?? ''),
        'homepage' => (string) ($raw['homepage'] ?? ''),
        'requires' => (string) ($raw['requires'] ?? ''),
        'requires_php' => (string) ($raw['requires_php'] ?? ''),
        'tested' => (string) ($raw['tested'] ?? ''),
        'last_updated' => (string) ($raw['last_updated'] ?? ''),
        'sections' => $sections,
        'icons' => $icons,
        'banners' => $banners,
        'download_url' => $download_url,
    ];
}

/**
 * Re-poll the license server when the user explicitly clicks "Check Again" on
 * Dashboard → Updates (?force-check=1). Without this our 6-hour cache makes
 * WordPress's own forced check return stale data — a freshly pushed release
 * would not appear until the cache expires.
 */
add_action('load-update-core.php', static function (): void {
    if (isset($_GET['force-check'])) {
        delete_transient('nibwp_update_info');
    }
});

/**
 * Build the nonce'd "update now" URL for the NIBWP plugin row.
 */
function nibwp_updater_upgrade_url(): string
{
    $plugin_file = plugin_basename(dirname(__DIR__) . '/nibwp.php');
    return wp_nonce_url(
        self_admin_url('update.php?action=upgrade-plugin&plugin=' . rawurlencode($plugin_file)),
        'upgrade-plugin_' . $plugin_file,
    );
}

/**
 * Manual "Check for updates now" action (NIBWP dashboard button). Busts our
 * cache + WordPress's plugin-update transient, forces a fresh poll, and
 * redirects back with a flag so the result can be surfaced in a notice.
 */
add_action('admin_post_nibwp_check_updates', static function (): void {
    if (!current_user_can('update_plugins')) {
        wp_die(esc_html__('You are not allowed to check for updates.', 'nibwp'));
    }
    check_admin_referer('nibwp_check_updates');

    nibwp_updater_bust_caches();
    if (function_exists('wp_update_plugins')) {
        wp_update_plugins(); // rebuild update_plugins → our filter refetches fresh.
    }

    $back = wp_get_referer() ?: admin_url('admin.php?page=nibwp-dashboard');
    wp_safe_redirect(add_query_arg('nibwp_checked', '1', remove_query_arg('nibwp_checked', $back)));
    exit();
});

/**
 * Surface update status inside the NIBWP admin screens so a pushed release is
 * noticed without visiting the Plugins list, and provide a manual re-check
 * button. Only meaningful for licensed builds — the free build updates from
 * wp.org and needs no custom signal here.
 */
add_action('admin_notices', 'nibwp_updater_admin_notice');
function nibwp_updater_admin_notice(): void
{
    if (!current_user_can('update_plugins')) {
        return;
    }
    if (nibwp_updater_active_license_key() === '') {
        return; // free / unlicensed build → wp.org handles updates.
    }
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ($screen === null || strpos((string) $screen->id, 'nibwp') === false) {
        return; // NIBWP admin pages only.
    }

    $info = nibwp_fetch_update_info();
    $has_update = is_array($info) && version_compare(NIBWP_VERSION, $info['version'], operator: '<');
    $check_url = wp_nonce_url(admin_url('admin-post.php?action=nibwp_check_updates'), 'nibwp_check_updates');

    if ($has_update) {
        printf(
            '<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s" class="button button-primary button-small" style="margin-inline-start:6px;">%4$s</a> <a href="%5$s" style="margin-inline-start:8px;">%6$s</a></p></div>',
            esc_html(sprintf(/* translators: %s: new version */ __('NIBWP %s is available.', 'nibwp'), $info['version'])),
            esc_html(sprintf(/* translators: %s: installed version */ __('You have %s.', 'nibwp'), NIBWP_VERSION)),
            esc_url(nibwp_updater_upgrade_url()),
            esc_html__('Update now', 'nibwp'),
            esc_url($check_url),
            esc_html__('Re-check', 'nibwp'),
        );
        return;
    }

    // Up to date: confirm right after a manual check; otherwise offer the
    // button on the dashboard so the control is discoverable.
    if (isset($_GET['nibwp_checked'])) {
        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html(sprintf(/* translators: %s: installed version */ __('NIBWP is up to date (v%s).', 'nibwp'), NIBWP_VERSION)),
        );
    } elseif (str_contains((string) $screen->id, 'nibwp-dashboard')) {
        printf(
            '<div class="notice notice-info"><p>%1$s <a href="%2$s" class="button button-small" style="margin-inline-start:6px;">%3$s</a></p></div>',
            esc_html(sprintf(/* translators: %s: installed version */ __('NIBWP v%s installed.', 'nibwp'), NIBWP_VERSION)),
            esc_url($check_url),
            esc_html__('Check for updates', 'nibwp'),
        );
    }
}

/**
 * Email the site admin once when a new NIBWP version becomes available.
 *
 * Off until the site owner turns it on (NIBWP → Settings → Notifications,
 * option `nibwp_update_email_enabled`). Nobody asked to be emailed by default,
 * and an unrequested message about a plugin is the kind people mark as spam.
 * The `nibwp_update_email_enabled` filter still has the last word, for hosts
 * that manage this in code; change the recipient with
 * `nibwp_update_email_recipient`.
 *
 * De-duplicated per version via the nibwp_update_last_emailed option, so each
 * release notifies at most once. Runs on admin page loads AND on the plugin
 * update cron (wp_update_plugins), so unattended sites are still notified.
 * Licensed builds only.
 */
add_action('admin_init', 'nibwp_updater_maybe_email');
add_action('wp_update_plugins', 'nibwp_updater_maybe_email');

/**
 * admin_init runs this on every wp-admin load. A throw here would fatal the
 * whole admin (lock everyone out), so the notifier body is isolated and any
 * Throwable degrades to "no notification" instead of a site-wide outage.
 */
function nibwp_updater_maybe_email(): void
{
    try {
        nibwp_updater_maybe_email_run();
    } catch (\Throwable $e) {
        if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
            error_log('[NIBWP] update-email notice skipped: ' . $e->getMessage());
        }
    }
}

function nibwp_updater_maybe_email_run(): void
{
    if (!apply_filters('nibwp_update_email_enabled', (bool) get_option('nibwp_update_email_enabled', false))) {
        return;
    }
    if (nibwp_updater_active_license_key() === '') {
        return; // free / unlicensed build → wp.org handles updates.
    }

    $info = nibwp_fetch_update_info();
    if (!is_array($info) || !version_compare(NIBWP_VERSION, $info['version'], operator: '<')) {
        return; // no newer version available.
    }

    $new_version = (string) $info['version'];
    if ((string) get_option('nibwp_update_last_emailed', default_value: '') === $new_version) {
        return; // already notified for this version.
    }

    /** @var string $recipient */
    $recipient = (string) apply_filters('nibwp_update_email_recipient', (string) get_option('admin_email'));
    if ($recipient === '' || !is_email($recipient)) {
        return;
    }

    // Positional arg: WP core's 2nd param is $quote_style, not $flags. Passing
    // it by a wrong name (flags:) fatals on PHP 8 ("Unknown named parameter").
    $site_name = wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES);
    $subject = sprintf(
        /* translators: 1: site name, 2: new version */
        __('[%1$s] NIBWP %2$s is available', 'nibwp'),
        $site_name,
        $new_version,
    );

    $lines = [
        /* translators: %s: site URL */
        sprintf(__('A new version of NIBWP is available for %s.', 'nibwp'), home_url()),
        '',
        /* translators: %s: installed version */
        sprintf(__('Installed version: %s', 'nibwp'), NIBWP_VERSION),
        /* translators: %s: new version */
        sprintf(__('New version: %s', 'nibwp'), $new_version),
        '',
        /* translators: %s: plugins screen URL */
        sprintf(__('Update now: %s', 'nibwp'), admin_url('plugins.php')),
    ];
    if (!empty($info['homepage'])) {
        /* translators: %s: changelog / homepage URL */
        $lines[] = sprintf(__('Details: %s', 'nibwp'), (string) $info['homepage']);
    }

    // Record the version BEFORE sending so a slow/looping mailer cannot fire
    // the same notice twice; only re-arm (clear) if the send actually fails.
    update_option('nibwp_update_last_emailed', $new_version, autoload: false);
    if (!wp_mail($recipient, $subject, implode("\n", $lines))) {
        delete_option('nibwp_update_last_emailed');
    }
}
