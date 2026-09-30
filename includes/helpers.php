<?php

declare(strict_types=1);

/**
 * Shared helper functions for NIBWP.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Whether an ability is registered — without WP 6.9's "called incorrectly"
 * notice. wp_get_ability()/wp_get_ability_category() warn when the target is
 * missing, so every "does it exist?" guard must use the has_* helpers. Falls
 * back to the get_* check on older WP where wp_has_ability() is absent.
 */
function nibwp_has_ability(string $name): bool
{
    if (function_exists('wp_has_ability')) {
        return wp_has_ability($name);
    }
    return function_exists('wp_get_ability') && wp_get_ability($name) !== null;
}

/** Whether an ability category is registered (notice-free). See nibwp_has_ability(). */
function nibwp_has_ability_category(string $slug): bool
{
    if (function_exists('wp_has_ability_category')) {
        return wp_has_ability_category($slug);
    }
    return function_exists('wp_get_ability_category') && wp_get_ability_category($slug) !== null;
}

/**
 * Is this path already absolute?
 *
 * A leading slash is only half the question on Windows, where an absolute path
 * starts with a drive letter ("D:\site") or a UNC prefix. Treating those as
 * relative prepended ABSPATH to them and produced nonsense of the shape
 * "D:\site/D:\site\wp-content\…", which then failed every containment check for
 * entirely the wrong reason — including writes to the sandbox itself.
 */
function nibwp_path_is_absolute(string $path): bool
{
    return str_starts_with($path, '/')
        || str_starts_with($path, '\\')
        || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
}

/**
 * Is $path the same as, or inside, $base?
 *
 * str_starts_with answers a different question — "does this string begin with
 * that string" — and "/var/www/html-evil" begins with "/var/www/html", just as
 * "nibwp-sandbox-evil" begins with "nibwp-sandbox". Both are siblings, both are
 * served over HTTP like any other directory, and both satisfied the guard.
 * Comparing with the separator attached is what was meant, with equality
 * allowed so the directory itself still counts as inside.
 */
/**
 * Resolve `.` and `..` in a path without touching the filesystem.
 *
 * realpath() only works on a path that already exists, and every containment
 * check here falls back to the raw string when it does not. A path like
 * `…/nibwp-sandbox/new-dir/../../../evil.php` therefore kept its `..` segments
 * through the guards, passed them — the string does start with the sandbox —
 * and was then resolved by the operating system at write time, outside it.
 * Collapsing the segments first is what makes the comparison mean anything.
 */
function nibwp_path_normalize(string $path): string
{
    $sep    = DIRECTORY_SEPARATOR;
    $native = str_replace(['/', '\\'], $sep, $path);

    // Keep whatever the path opens with: a drive, a UNC prefix, or a slash.
    $prefix = '';
    if (preg_match('/^([a-zA-Z]:)?' . preg_quote($sep, '/') . '{1,2}/', $native, $m) === 1) {
        $prefix = $m[0];
        $native = substr($native, strlen($prefix));
    }

    $out = [];
    foreach (explode($sep, $native) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            if ($out !== [] && end($out) !== '..') {
                array_pop($out);
            } elseif ($prefix === '') {
                $out[] = '..'; // Relative path with nothing to climb out of.
            }
            continue;
        }
        $out[] = $segment;
    }

    return $prefix . implode($sep, $out);
}

function nibwp_path_within(string $path, string $base): bool
{
    // Compare what the paths mean, not how they are spelled.
    $path = nibwp_path_normalize($path);
    $base = nibwp_path_normalize($base);

    $path = rtrim($path, '/\\');
    $base = rtrim($base, '/\\');

    // Windows treats paths case-insensitively and mixes separators freely.
    if (DIRECTORY_SEPARATOR === '\\') {
        $path = strtolower(str_replace('/', '\\', $path));
        $base = strtolower(str_replace('/', '\\', $base));
    }

    return $path === $base || str_starts_with($path, $base . DIRECTORY_SEPARATOR);
}

/**
 * Resolve a filesystem path, ensuring it stays within the allowed base directory.
 *
 * @param string $path       The path to resolve. Relative paths are prepended with ABSPATH.
 * @param bool   $must_exist Whether the path must already exist.
 * @return string|WP_Error   The resolved absolute path, or WP_Error on failure.
 */
function nibwp_resolve_path($path, $must_exist = false)
{
    // Prepend ABSPATH to relative paths.
    if (!nibwp_path_is_absolute((string) $path)) {
        $path = ABSPATH . $path;
    }

    /**
     * Filter the base directory for filesystem operations.
     * Return false to disable the base directory restriction entirely.
     *
     * @param string $base_dir The base directory. Defaults to ABSPATH.
     */
    /** @var string|false $base_dir */
    $base_dir = apply_filters('nibwp_filesystem_base_dir', ABSPATH);

    // Resolve path that may not exist yet via parent directory.
    // When the parent does not exist yet there is nothing to resolve against,
    // so collapse the path ourselves rather than carrying `..` into the checks
    // below and on into the write.
    $path = nibwp_path_normalize((string) $path);
    $resolved_parent = realpath(dirname($path));
    $resolved = $resolved_parent !== false ? $resolved_parent . DIRECTORY_SEPARATOR . basename($path) : $path;

    // For paths that must exist, override with realpath.
    if ($must_exist) {
        $resolved = realpath($path);
        if ($resolved === false) {
            return new WP_Error('path_not_found', sprintf(
                /* translators: %s: filesystem path */
                __('Path does not exist: %s', 'nibwp'),
                $path,
            ));
        }
    } elseif (is_link($resolved) || file_exists($resolved)) {
        // Only the parent was resolved above; the last segment was still the
        // name the caller typed. A link sitting at the end of the path
        // therefore passed the containment check on the strength of where it
        // *lives* rather than where it *points*, and the operating system
        // followed it straight afterwards — write-file wrote through it, and
        // delete-file with recursive=true emptied the directory on the far side
        // of it. Resolving the last segment too makes the check judge the file
        // that is actually about to be touched.
        $real_self = realpath($resolved);
        if ($real_self === false) {
            // A link whose target does not exist resolves to nothing, so there
            // is no way to prove where it lands — and a write would still
            // follow it and create the target wherever it points. Refusing is
            // the only answer that cannot be wrong; falling back to the link's
            // own location would re-open exactly the hole above.
            return new WP_Error('path_unresolvable', sprintf(
                /* translators: %s: filesystem path */
                __('Path "%s" is a link whose target cannot be resolved.', 'nibwp'),
                $resolved,
            ));
        }
        $resolved = $real_self;
    }

    // Enforce base directory restriction.
    if ($base_dir !== false) {
        $real_base = realpath($base_dir);
        if ($real_base === false) {
            $real_base = rtrim($base_dir, characters: '/\\');
        }

        if (!nibwp_path_within($resolved, $real_base)) {
            return new WP_Error('path_outside_base', sprintf(
                /* translators: 1: requested filesystem path, 2: allowed base directory */
                __('Path "%1$s" is outside the allowed base directory "%2$s".', 'nibwp'),
                $resolved,
                $real_base,
            ));
        }
    }

    // Inside the base directory is not the same as fair game. The credential
    // files live inside it, so this check has to come after the containment one
    // rather than instead of it.
    if (nibwp_is_protected_file($resolved)) {
        return new WP_Error('protected_file', sprintf(
            /* translators: %s: filesystem path */
            __('Path "%s" holds site credentials and cannot be read or written through NIBWP.', 'nibwp'),
            $resolved,
        ));
    }

    return $resolved;
}

/**
 * Files no ability may open, whatever the caller's role.
 *
 * wp-config.php holds the database password and every authentication salt, and
 * a .env or .htpasswd beside it holds the same class of secret. Whoever reads
 * one can sign a cookie for any user on the site and connect to the database
 * directly, which is a long way past "administer this site" — and on Multisite
 * it is every sibling site on the install as well. Writing one is worse still:
 * a single line appended to wp-config.php then runs on every request.
 *
 * The match is on the file name, because that is what the secret is kept in
 * wherever an install chooses to put it, and the same name is used by hosts
 * that split the config into wp-config-local.php or wp-config-staging.php.
 *
 * This is deliberately not an authorisation decision — it does not ask who is
 * calling. It removes a low-friction credential dump from the file abilities
 * for everyone. Work that genuinely has to change site configuration goes
 * through execute-php, which is gated on network authority and audited.
 *
 * @param string $path Absolute path, already resolved.
 * @return bool
 */
function nibwp_is_protected_file(string $path): bool
{
    $name = strtolower(basename($path));

    if (
        $name === 'wp-config.php'
        // wp-config-local.php, wp-config-staging.php, wp-config-sample.php …
        || str_starts_with($name, 'wp-config-')
        || $name === '.env'
        // .env.local, .env.production …
        || str_starts_with($name, '.env.')
        || $name === '.htpasswd'
    ) {
        return true;
    }

    /**
     * Filter extra file names to protect.
     *
     * Names are added to the built-in list, never subtracted from it: a filter
     * that could unprotect wp-config.php would be the hole this closes.
     *
     * @param string[] $extra Additional file names (basename only).
     */
    $extra = (array) apply_filters('nibwp_protected_files', []);
    foreach ($extra as $candidate) {
        if (is_string($candidate) && $candidate !== '' && strtolower($candidate) === $name) {
            return true;
        }
    }

    return false;
}

/**
 * Get the sandbox directory path for AI-written PHP plugins.
 *
 * @param bool $ensure_exists Whether to create the directory if it doesn't exist.
 * @return string Absolute path to the sandbox directory (with trailing slash).
 */
function nibwp_get_sandbox_dir($ensure_exists = false)
{
    if ($ensure_exists && !is_dir(NIBWP_SANDBOX_DIR)) {
        wp_mkdir_p(NIBWP_SANDBOX_DIR);
    }

    return NIBWP_SANDBOX_DIR;
}

/**
 * Read the recorded details of the fatal error that put the sandbox into safe mode.
 *
 * The crash marker stores what error_get_last() reported plus the sandbox file that
 * happened to be loading at the time. Those are two different things: the loader
 * suspends the sandbox whenever a request dies mid-load, but the error itself can
 * come from anywhere in the call chain — another plugin's hook callback, or a memory
 * limit that a different plugin exhausted first. Reporting only the sandbox file
 * would accuse it of something it may not have done, so surface both and say which
 * side of the fence the failure actually landed on.
 *
 * @return array|null Null when safe mode is not active or the marker is unreadable.
 */
function nibwp_sandbox_crash_report()
{
    $marker = nibwp_get_sandbox_dir() . '.crashed';
    if (!file_exists($marker)) {
        return null;
    }

    $raw = file_get_contents($marker);
    $data = $raw === false ? null : json_decode($raw, true);
    if (!is_array($data)) {
        // A marker we cannot parse still means safe mode is on; say so without detail
        // rather than pretending nothing happened.
        return ['message' => '', 'file' => '', 'line' => 0, 'sandbox_file' => '', 'is_external' => false];
    }

    $file = isset($data['file']) ? (string) $data['file'] : '';
    $sandbox_dir = nibwp_get_sandbox_dir();
    $real_sandbox = realpath($sandbox_dir);
    $real_file = $file !== '' ? realpath($file) : false;
    if ($real_file === false) {
        $real_file = $file;
    }

    // The fatal is somebody else's when it was thrown from a file outside the sandbox.
    // Resource ceilings (memory, execution time) report no usable file at all, so they
    // are not attributed either way.
    $is_external = $file !== ''
        && $real_sandbox !== false
        && !str_starts_with($real_file, $real_sandbox . DIRECTORY_SEPARATOR);

    return [
        'message' => isset($data['message']) ? (string) $data['message'] : '',
        'file' => $file,
        'line' => isset($data['line']) ? (int) $data['line'] : 0,
        'sandbox_file' => isset($data['sandbox_file']) ? basename((string) $data['sandbox_file']) : '',
        'is_external' => $is_external,
    ];
}

/**
 * Validate that a resolved path is inside the sandbox directory.
 *
 * @param string $resolved The resolved absolute path to check.
 * @return true|WP_Error True if inside the sandbox, WP_Error otherwise.
 */
function nibwp_validate_sandbox_path($resolved)
{
    $sandbox_dir = nibwp_get_sandbox_dir();
    $real_sandbox = realpath($sandbox_dir);
    if ($real_sandbox === false) {
        return new WP_Error('sandbox_not_found', __('The sandbox directory does not exist.', 'nibwp'));
    }

    $real_resolved = realpath($resolved);
    if ($real_resolved === false) {
        $real_resolved = $resolved;
    }

    if (!str_starts_with($real_resolved, $real_sandbox . DIRECTORY_SEPARATOR)) {
        return new WP_Error('outside_sandbox', sprintf(
            /* translators: %s: sandbox directory path */
            __('Only files inside the sandbox (%s) can be modified.', 'nibwp'),
            $sandbox_dir,
        ));
    }

    return true;
}

/**
 * Check that a resolved PHP file path is inside the sandbox directory.
 *
 * @param string $resolved Absolute resolved path to the PHP file.
 * @return bool|WP_Error True if valid, WP_Error if outside sandbox.
 */
function nibwp_check_php_sandbox(string $resolved): bool|WP_Error
{
    $sandbox_dir = nibwp_get_sandbox_dir(ensure_exists: false);
    $real_sandbox = realpath($sandbox_dir);
    $parent_dir = realpath(dirname($resolved));

    // If sandbox doesn't exist yet, compare normalized paths.
    if ($real_sandbox === false) {
        $real_sandbox = rtrim(string: $sandbox_dir, characters: '/\\');
    }
    if ($parent_dir === false) {
        $parent_dir = dirname($resolved);
    }

    if (!nibwp_path_within($parent_dir, $real_sandbox)) {
        return new WP_Error('php_sandbox_required', sprintf(
            'PHP files can only be written to the sandbox directory: %s. Use a path like "wp-content/nibwp-sandbox/my-feature.php".',
            $sandbox_dir,
        ));
    }

    return true;
}

/**
 * Create a parent directory and return the list of directories that were created.
 *
 * @param string $parent_dir Absolute path to the parent directory.
 * @return array|WP_Error List of directories created, or WP_Error on failure.
 */
function nibwp_ensure_parent_dir(string $parent_dir): array|WP_Error
{
    if (is_dir($parent_dir)) {
        return [];
    }

    // Collect which directories will be created.
    $dir_to_check = $parent_dir;
    $dirs_to_create = [];
    while (!is_dir($dir_to_check)) {
        $dirs_to_create[] = $dir_to_check;
        $dir_to_check = dirname($dir_to_check);
    }
    $directories_created = array_reverse($dirs_to_create);

    if (!mkdir(directory: $parent_dir, permissions: 0755, recursive: true)) {
        return new WP_Error('mkdir_failed', sprintf('Failed to create directory: %s', $parent_dir));
    }

    return $directories_created;
}

/**
 * Check whether a filename ends with the ".disabled" suffix.
 *
 * @param string $path File path to check.
 * @return bool
 */
function nibwp_is_disabled_file($path)
{
    return str_ends_with($path, '.disabled');
}

/**
 * Check whether the AI abilities are enabled via the settings option.
 *
 * @return bool
 */
function nibwp_is_enabled()
{
    /** @var mixed $value */
    $value = get_option('nibwp_ai_abilities_enabled', default_value: false);
    if ($value !== '1' && $value !== true) {
        return false;
    }

    // Abilities are locked to the domain they were enabled on.
    /** @var string $locked_domain */
    $locked_domain = get_option('nibwp_ai_abilities_domain', default_value: '');
    $current_domain = (string) wp_parse_url(home_url(), PHP_URL_HOST);

    return $locked_domain === $current_domain;
}

/**
 * Heuristic: does this site look like a production environment?
 *
 * Default to production when in doubt — the warning's job is to prompt the user to think
 * twice before enabling AI Abilities on something live. Hostnames and `wp_get_environment_type()`
 * results that strongly suggest staging/dev/local short-circuit to `false`.
 *
 * @return bool
 */
function nibwp_looks_like_production(): bool
{
    $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    $host = strtolower($host);
    if ($host === '') {
        return true;
    }

    // Strip an eventual port suffix.
    $colon_pos = strpos(haystack: $host, needle: ':');
    if ($colon_pos !== false) {
        $host = substr($host, offset: 0, length: $colon_pos);
    }

    // No dot at all (e.g. "localhost", "wordpress") → not production.
    if (!str_contains($host, '.')) {
        return false;
    }

    // IP literals → not production.
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return false;
    }

    $segments = explode('.', $host);
    $tld = (string) end($segments);

    /** @var array<int, string> $non_prod_tlds */
    $non_prod_tlds = apply_filters('nibwp_non_production_tlds', [
        'dev',
        'local',
        'staging',
        'test',
        'example',
        'invalid',
        'backup',
    ]);

    if (in_array($tld, $non_prod_tlds, strict: true)) {
        return false;
    }

    /** @var array<int, string> $non_prod_subdomain_segments */
    $non_prod_subdomain_segments = apply_filters('nibwp_non_production_subdomain_segments', [
        'dev',
        'local',
        'test',
        'staging',
        'stage',
        'stg',
        'wp-staging',
        'wpstaging',
        'development',
        'wptest',
        'backup',
        'preview',
        'preprod',
        'qa',
        'uat',
        'sandbox',
        'demo',
        'beta',
        'mirror',
    ]);

    foreach ($segments as $segment) {
        if (in_array($segment, $non_prod_subdomain_segments, strict: true)) {
            return false;
        }
    }

    /** @var array<int, string> $non_prod_keyword_regex_words */
    $non_prod_keyword_regex_words = apply_filters('nibwp_non_production_keyword_words', [
        'test',
        'dev',
        'staging',
        'stage',
        'stg',
        'local',
        'wp-staging',
        'development',
        'wptest',
        'backup',
        'preview',
        'preprod',
        'sandbox',
        'demo',
        'beta',
    ]);

    $alternation = implode('|', array_map(static fn(string $w): string => preg_quote(
        str: $w,
        delimiter: '/',
    ), $non_prod_keyword_regex_words));
    if ($alternation !== '' && preg_match('/\b(?:' . $alternation . ')[0-9]*\b/i', $host) === 1) {
        return false;
    }

    /** @var array<int, string> $non_prod_host_suffixes */
    $non_prod_host_suffixes = apply_filters('nibwp_production_host_patterns', [
        'wpengine.com',
        'wpenginepowered.com',
        'sg-host.com',
        'cloudwaysapps.com',
        'closte.com',
        'runcloud.link',
        'kinsta.cloud',
        'pantheonsite.io',
        'onrocket.site',
        'pressdns.com',
        'bigscoots-staging.com',
        'flywheelstaging.com',
        'wpstage.net',
        'wpserveur.net',
        'myftpupload.com',
        'myraidbox.de',
        'elementor.cloud',
        'lndo.site',
        'ddev.site',
    ]);

    foreach ($non_prod_host_suffixes as $suffix) {
        if ($suffix !== '' && str_ends_with($host, $suffix)) {
            return false;
        }
    }

    if (function_exists('wp_get_environment_type')) {
        $env = wp_get_environment_type();
        if (in_array($env, ['staging', 'development', 'local'], strict: true)) {
            return false;
        }
    }

    return true;
}

/**
 * Heuristic: is this site likely served over plain HTTP on a local hostname?
 *
 * WordPress core blocks Application Passwords on HTTP unless `WP_ENVIRONMENT_TYPE` is set to
 * 'local'. Detecting this lets us surface the exact wp-config snippet the user needs.
 */
function nibwp_likely_local_http(): bool
{
    $home = home_url();
    if (!str_starts_with(strtolower($home), 'http://')) {
        return false;
    }

    $host = strtolower((string) wp_parse_url($home, PHP_URL_HOST));
    if ($host === '') {
        return false;
    }

    /** @var array<int, string> $local_substrings */
    $local_substrings = apply_filters('nibwp_self_signed_host_patterns', [
        '.local',
        '.test',
        'localhost',
        '.lndo.site',
        '.ddev.site',
    ]);

    foreach ($local_substrings as $needle) {
        if ($needle !== '' && str_contains($host, $needle)) {
            return true;
        }
    }

    return false;
}

/**
 * Heuristic: is this site likely served from an HTTPS endpoint with a self-signed certificate?
 *
 * LocalWP, DDEV, Lando and similar dev tools commonly serve `.local` / `.test` hostnames over
 * HTTPS with self-signed certs. The @automattic/mcp-wordpress-remote npx package rejects such
 * certs by default, so the MCP client cannot connect unless `NODE_TLS_REJECT_UNAUTHORIZED=0` is
 * passed in the env. Detecting this lets us inject that env var and warn the user about the trade.
 */
function nibwp_likely_self_signed_https(): bool
{
    $home = home_url();
    if (!str_starts_with(strtolower($home), 'https://')) {
        return false;
    }

    $host = strtolower((string) wp_parse_url($home, PHP_URL_HOST));
    if ($host === '') {
        return false;
    }

    /** @var array<int, string> $self_signed_substrings */
    $self_signed_substrings = apply_filters('nibwp_self_signed_host_patterns', [
        '.local',
        '.test',
        'localhost',
        '.lndo.site',
        '.ddev.site',
    ]);

    foreach ($self_signed_substrings as $needle) {
        if ($needle !== '' && str_contains($host, $needle)) {
            return true;
        }
    }

    return false;
}

/**
 * Has the current user dismissed the production warning?
 */
function nibwp_production_warning_dismissed(): bool
{
    /** @var mixed $value */
    $value = get_user_meta(get_current_user_id(), key: 'nibwp_production_warning_dismissed', single: true);
    return $value === '1' || $value === 1 || $value === true;
}

/**
 * Handle the dismiss-production-warning form submission. Called from admin_init.
 */
function nibwp_handle_dismiss_production_warning(): void
{
    if (($_POST['nibwp_dismiss_production_warning'] ?? null) === null) {
        return;
    }

    if (!current_user_can('manage_options')) {
        return;
    }

    check_admin_referer('nibwp_dismiss_production_warning');

    update_user_meta(get_current_user_id(), meta_key: 'nibwp_production_warning_dismissed', meta_value: '1');

    wp_safe_redirect(admin_url('admin.php?page=nibwp-connect'));
    exit();
}

/**
 * Check whether abilities are nominally enabled but inactive due to a domain mismatch.
 *
 * @return bool
 */
function nibwp_is_domain_mismatch()
{
    /** @var mixed $value */
    $value = get_option('nibwp_ai_abilities_enabled', default_value: false);
    if ($value !== '1' && $value !== true) {
        return false;
    }

    /** @var string $locked_domain */
    $locked_domain = get_option('nibwp_ai_abilities_domain', default_value: '');
    $current_domain = (string) wp_parse_url(home_url(), PHP_URL_HOST);

    return $locked_domain !== $current_domain;
}

/**
 * Re-lock abilities to the address the site now answers on.
 *
 * The domain lock is a safety catch for cloned databases, but a site that has
 * simply moved trips it too — and until now the only way out was to find the
 * Connect page and toggle a switch off and on again. Hosts that hand out
 * temporary URLs trip it on a schedule.
 *
 * Deliberately its own action rather than a link to the toggle: the intent
 * ("I moved this site, that was me") is worth capturing directly, and a
 * nonce-checked POST from an administrator is the only thing that can do it.
 */
function nibwp_relock_domain(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to do that.', 'nibwp'), 403);
    }

    check_admin_referer('nibwp_relock_domain');

    update_option('nibwp_ai_abilities_enabled', '1');
    update_option('nibwp_ai_abilities_domain', (string) wp_parse_url(home_url(), PHP_URL_HOST));

    wp_safe_redirect(add_query_arg('nibwp-relocked', '1', admin_url('admin.php?page=nibwp-connect')));
    exit();
}
add_action('admin_post_nibwp_relock_domain', 'nibwp_relock_domain');

/**
 * The one-click way out of a domain mismatch, as a form so it can carry a nonce.
 */
function nibwp_relock_domain_button(string $label = ''): string
{
    if ($label === '') {
        $label = __('Enable for this address', 'nibwp');
    }

    return sprintf(
        '<form method="post" action="%1$s" style="display:inline">%2$s<input type="hidden" name="action" value="nibwp_relock_domain" /><button type="submit" class="button button-primary">%3$s</button></form>',
        esc_url(admin_url('admin-post.php')),
        wp_nonce_field('nibwp_relock_domain', '_wpnonce', true, false),
        esc_html($label)
    );
}

/**
 * Report whether WordPress Application Passwords are available, and why not if not.
 *
 * Distinguishes between the HTTPS/local-env requirement (`wp_is_application_passwords_supported()`)
 * and a filter-based override (typical of security plugins hooking `wp_is_application_passwords_available`).
 *
 * @return array{available: bool, reason: 'available'|'unsupported'|'filtered', message: string}
 */
function nibwp_app_passwords_status(): array
{
    if (wp_is_application_passwords_available()) {
        return ['available' => true, 'reason' => 'available', 'message' => ''];
    }

    if (!wp_is_application_passwords_supported()) {
        return [
            'available' => false,
            'reason' => 'unsupported',
            'message' => __(
                'Application Passwords require HTTPS or WP_ENVIRONMENT_TYPE set to "local".',
                'nibwp',
            ),
        ];
    }

    return [
        'available' => false,
        'reason' => 'filtered',
        'message' => __(
            'Application Passwords have been disabled on this site, likely by a security plugin. Check your security plugin settings (e.g. Solid Security, Wordfence, All In One WP Security) and re-enable Application Passwords to continue.',
            'nibwp',
        ),
    ];
}

/**
 * Build a combined date/time format string from WordPress settings.
 *
 * Falls back to 'Y-m-d H:i:s' if either format is empty.
 *
 * @param string $fallback Optional fallback format.
 * @return string
 */
function nibwp_get_datetime_format($fallback = 'Y-m-d H:i:s')
{
    $date_format = (string) get_option('date_format');
    $time_format = (string) get_option('time_format');

    if ($date_format === '' || $time_format === '') {
        return $fallback;
    }

    return $date_format . ' ' . $time_format;
}

/**
 * Permission callback: requires manage_options capability.
 *
 * @return bool
 */
function nibwp_permission_callback()
{
    return current_user_can('manage_options');
}

/**
 * Permission callback for the abilities that reach the server filesystem, and
 * for raw PHP execution.
 *
 * manage_options is the right bar on a single site. An administrator there can
 * already install a plugin, and a plugin is arbitrary code running as the web
 * server on the same box, so reading and writing files grants nothing the role
 * did not already carry.
 *
 * On Multisite it is the wrong bar. A site administrator there runs one site
 * inside an install somebody else owns: the filesystem, the database and every
 * sibling site belong to the network, not to them. WordPress core draws the
 * same line — map_meta_cap() refuses edit_files, edit_plugins, edit_themes and
 * install_plugins to anyone who is not a Super Admin on Multisite — and these
 * abilities are that same authority arriving by another road. Left on
 * manage_options, a site administrator could read wp-config.php over the
 * abilities REST route or MCP, take the database credentials and the
 * authentication salts, and from there reach every other site on the network.
 *
 * @return bool
 */
function nibwp_filesystem_permission_callback()
{
    return nibwp_user_can_filesystem(get_current_user_id());
}

/**
 * The same rule as nibwp_filesystem_permission_callback(), asked about a named
 * user rather than the current one.
 *
 * A signed upload link is redeemed by an anonymous request, so there is no
 * current user to ask at the moment the bytes land — only the id the link was
 * minted for. Both callers share this one function so the two can never drift
 * into disagreeing about who may write to the server.
 *
 * @param int $user_id
 * @return bool
 */
function nibwp_user_can_filesystem(int $user_id): bool
{
    if ($user_id <= 0 || !user_can($user_id, 'manage_options')) {
        return false;
    }

    if (!is_multisite()) {
        return true;
    }

    // Either spelling of "runs this network": the capability, or the Super
    // Admin flag it is derived from, so an install that filters capabilities
    // unusually still resolves to the same small set of people.
    return user_can($user_id, 'manage_network_options') || is_super_admin($user_id);
}

/**
 * Detect active languages from multilingual plugins (WPML, Polylang, TranslatePress).
 *
 * @return array{plugin: string, languages: string[]}|null Plugin name and language codes, or null if no multilingual plugin is active.
 */
function nibwp_get_active_languages()
{
    // WPML.
    if (function_exists('icl_get_languages')) {
        /** @var array<string, array{language_code: string}>|false $wpml_languages */
        $wpml_languages = icl_get_languages('skip_missing=0');
        if (is_array($wpml_languages)) {
            return ['plugin' => 'WPML', 'languages' => array_column($wpml_languages, 'language_code')];
        }
    }

    // Polylang.
    if (function_exists('pll_languages_list')) {
        /** @var string[]|false $languages */
        $languages = pll_languages_list();
        if (is_array($languages)) {
            return ['plugin' => 'Polylang', 'languages' => $languages];
        }
    }

    // TranslatePress.
    if (class_exists('TRP_Translate_Press')) {
        /** @var array{translation-languages?: string[]} $trp_settings */
        $trp_settings = get_option('trp_settings', default_value: []);
        return ['plugin' => 'TranslatePress', 'languages' => $trp_settings['translation-languages'] ?? []];
    }

    return null;
}

/**
 * Build the MCP server instructions sent to AI agents during initialization.
 *
 * Includes environment info (PHP/WP versions, plugins) and guidance on using
 * WordPress-native features instead of hardcoding data in PHP.
 *
 * @return string
 */
function nibwp_build_server_instructions()
{
    $lines = [
        'NIBWP gives you unrestricted control over this WordPress installation.',
        '',
        '## Environment',
        '',
        'WordPress ' . get_bloginfo('version') . ' — PHP ' . PHP_VERSION . ' — Locale: ' . get_locale(),
    ];

    // Detect active languages from multilingual plugins.
    $multilingual = nibwp_get_active_languages();
    if ($multilingual !== null && $multilingual['languages'] !== []) {
        $lines[] = 'Multilingual (' . $multilingual['plugin'] . '): ' . implode(', ', $multilingual['languages']);
    }

    $lines[] = '';

    if (function_exists('get_plugins')) {
        /** @var array<string, array{Name?: string, Version?: string}> $all_plugins */
        $all_plugins = get_plugins();
        if ($all_plugins !== []) {
            // Active plugins get a line each with a version — they decide what
            // the agent should build with. Inactive ones only answer "is it
            // here at all", and a site with a big add-on suite installed but
            // switched off was spending a fifth of this brief listing things
            // nothing can call. Names on one line answer that question just as
            // well.
            $active_lines = [];
            $inactive_names = [];
            foreach ($all_plugins as $plugin_file => $plugin_data) {
                $name = $plugin_data['Name'] ?? $plugin_file;
                if (!is_plugin_active($plugin_file)) {
                    $inactive_names[] = $name;
                    continue;
                }
                $version = $plugin_data['Version'] ?? '';
                $active_lines[] = '- ' . $name . ($version !== '' ? ' v' . $version : '');
            }

            if ($active_lines !== []) {
                $lines[] = 'Active plugins:';
                $lines = array_merge($lines, $active_lines);
                $lines[] = '';
            }
            if ($inactive_names !== []) {
                $lines[] = 'Installed but inactive (nothing here can call these until someone activates them): '
                    . implode(', ', array_unique($inactive_names));
                $lines[] = '';
            }
        }
    }

    // Tell the agent the workspace exists. Without this it has no reason to
    // reach for the visual abilities over the ordinary ones, and the person
    // watching an empty workspace has no idea why nothing is happening.
    if (function_exists('nibwp_visual_url')) {
        // Whether someone is watching *right now*. A standing instruction to
        // work visually is noise on a site with no workspace open, and the one
        // thing that matters on a site with one.
        $watching = function_exists('nibwp_visual_is_open')
            && function_exists('nibwp_visual_user_id')
            && nibwp_visual_is_open(nibwp_visual_user_id());

        if ($watching) {
            $lines = array_merge($lines, [
                '## Someone is watching you work',
                '',
                '**NibWP Agent View is open in this user\'s browser right now.**',
                'They can see the pages of this site, and every tool you call is being',
                'listed on their screen as you call it. Work accordingly:',
                '',
                '- Open the page you are about to change, with nibwp/visual-open, BEFORE',
                '  you change it. They should watch it happen, not read about it after.',
                '- Keep it open while you work on it. Do not close and reopen tabs.',
                '- Check your own work with nibwp/visual-read or nibwp/visual-audit rather',
                '  than assuming the edit landed.',
                '- Do not narrate in chat what the screen already shows.',
                '',
                'This is not conditional on being asked. If a workspace is open, showing',
                'the work in it is the default way to work on this site.',
                '',
            ]);
        }

        $lines = array_merge($lines, [
            '## Agent View',
            '',
            'This site has a workspace the user can watch you work in: a browser tab',
            'showing real pages of the site, which you drive through the nibwp/visual-*',
            'abilities. Whenever it is open — and always when the user says "in the',
            'workspace", "show me", "let me watch", or asks for something visual — use',
            'those abilities rather than editing content blind:',
            '',
            '- nibwp/visual-open opens a page of this site in the workspace',
            '- nibwp/visual-read returns what is on it, with a selector for every element',
            '- nibwp/visual-click and nibwp/visual-fill drive it',
            '- nibwp/visual-blocks and nibwp/visual-block-* edit the block editor it has open',
            '- nibwp/visual-audit checks contrast, alt text, labels, headings and overflow',
            '',
            'Two things happen without you asking for them, so do not work around',
            'them: any post saved while the workspace is open is brought up on screen,',
            'whichever ability or skill wrote it; and every tool you call is listed in',
            'the workspace as you call it. The user can therefore see the steps',
            'themselves — what they cannot see, unless you open it, is the page.',
            '',
            'The workspace has to be open in the browser, under the same WordPress',
            'account this connection uses. If an ability answers that no workspace is',
            'open, say so plainly and point the user at NibWP -> Visual rather than',
            'quietly falling back to editing without it.',
            '',
        ]);
    }

    $lines = array_merge($lines, nibwp_server_inventory_lines());

    $lines = array_merge($lines, [
        '## WordPress-native development',
        '',
        'IMPORTANT: Prefer WordPress-native features to store and manage data.',
        'Do not hardcode content in PHP arrays when WordPress has a better mechanism:',
        '- Custom post types (register_post_type) for structured content (unless a data-modeling plugin owns it — see below)',
        '- Taxonomies (register_taxonomy) for categorization (same caveat)',
        '- Post meta / custom fields (update_post_meta) for additional data on posts (same caveat)',
        '- Options API (update_option) for settings and configuration',
        '- Custom database tables via $wpdb only when the above are insufficient',
        '',
        'Take advantage of active plugins. If a data-modeling plugin is in the',
        'active-plugins list above (ACF / ACF Pro, JetEngine, Pods, ACPT,',
        'Meta Box, Toolset, Custom Post Type UI, WooCommerce, etc.), use it for the',
        'task it owns — never write a custom register_post_type / register_taxonomy /',
        'register_meta call in PHP for content the active plugin can model through its',
        'own UI/API. Splitting the source of truth between custom PHP and a plugin UI',
        'produces broken slugs, labels, and capabilities the next time the user touches',
        'either side, and that recovery is hard. If two or more such plugins are active,',
        'ask the user which one to use before persisting anything.',
        '',
        'Use WordPress hooks (actions/filters), template hierarchy, and REST API',
        'conventions. Write code that integrates with WordPress, not code that ignores it.',
    ]);

    return implode("\n", $lines);
}

/**
 * The skills this site can actually use right now: installed, licensed, on.
 *
 * Locked and switched-off skills are left out on purpose. A capability that
 * cannot be used is worse than one that does not exist, because the plan gets
 * built around it. Read live — every one of these is a toggle someone flips
 * mid-conversation.
 *
 * @return array<int, array{id: string, label: string, tagline: string}>
 */
function nibwp_enabled_skills(): array
{
    if (!function_exists('nibwp_skills_discover') || !function_exists('nibwp_skill_is_enabled')) {
        return [];
    }

    $out = [];
    foreach (nibwp_skills_discover() as $id => $manifest) {
        $id = (string) $id;
        $unlocked = !function_exists('nibwp_skill_is_unlocked') || nibwp_skill_is_unlocked($id);
        if (!$unlocked || !nibwp_skill_is_enabled($id)) {
            continue;
        }

        // Owned and switched on is not the same as usable. A skill whose
        // builder is not installed here belongs in the "cannot run" section,
        // which states what is missing — listing it again under "switched on"
        // told the agent the opposite thing a hundred lines later, and the
        // later, friendlier claim is the one it would act on.
        if (function_exists('nibwp_skill_missing_deps') && nibwp_skill_missing_deps($manifest) !== []) {
            continue;
        }

        $out[] = [
            'id' => $id,
            'label' => (string) ($manifest['name'] ?? $id),
            // The tagline, never the description — the long one runs to a
            // paragraph per skill, and both callers show every skill at once.
            'tagline' => (string) ($manifest['tagline'] ?? ''),
        ];
    }

    return $out;
}

/**
 * What is switched on for this connection, right now.
 *
 * @return array<int, string>
 */
function nibwp_server_inventory_lines(): array
{
    $lines = [];

    // The skills list lives in the routing block at the top of the brief, which
    // gives each one its trigger and the call that starts it. Repeating the
    // same ten names and taglines down here spent a fifth of the brief saying
    // what had already been said — and, while it was built from a different
    // list, said it differently enough to contradict it.

    // Workflows: saved prompts the user already trusts for this site. Naming a
    // few is worth more than a count — a count tells an agent nothing it can act on.
    //
    // Only when they can actually be run. Workflows are a Pro feature, and the
    // ability that reads them answers a locked site with a payment error — so
    // listing them on Free advertised nineteen recipes and then refused every
    // one, which is the same trap the skills list avoids by leaving out what is
    // locked.
    $workflows_usable = !function_exists('nibwp_workflows_unlocked') || nibwp_workflows_unlocked();

    if ($workflows_usable && function_exists('nibwp_workflows_posts')) {
        $titles = [];
        foreach (nibwp_workflows_posts() as $post) {
            $titles[] = get_the_title($post);
            if (count($titles) >= 12) {
                break;
            }
        }

        if ($titles !== []) {
            $total = count($titles);
            $lines[] = '## Saved workflows';
            $lines[] = '';
            $lines[] = 'Recipes this site already keeps, each a sequence someone here settled on:';
            $lines[] = '';
            foreach ($titles as $title) {
                $lines[] = '- ' . $title;
            }
            if ($total >= 12) {
                $lines[] = '- …and more';
            }
            $lines[] = '';
            $lines[] = 'nibwp/list-workflows returns all of them, nibwp/get-workflow the steps of';
            $lines[] = 'one. When a request matches a saved workflow, follow it rather than';
            $lines[] = 'improvising a different route to the same place.';
            $lines[] = '';
        }
    }

    $lines = array_merge($lines, nibwp_builder_output_lines());

    // ponytail: builders are already in the installed-plugins inventory above,
    // so no separate integrations section — one list, not two that can disagree.
    return $lines;
}

/**
 * How to build on a site that has a page builder.
 *
 * Raw markup is the tempting shortcut and the wrong answer: an HTML block looks
 * right on the front end and is dead in the editor. Nobody can restyle it, the
 * builder's classes and tokens do not reach it, and the person who asked for a
 * page ends up with something only an agent can edit. The builder skills exist
 * to emit real elements, which is why this sits next to the list of them.
 *
 * @return array<int, string>
 */
function nibwp_builder_output_lines(): array
{
    // These instructions are built during a REST request, where wp-admin's
    // plugin functions are not loaded unless something else happened to pull
    // them in. Depending on that meant the section appeared or vanished
    // according to what other plugins had done first.
    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    // Matched on the plugin's own path, so a builder that is installed but
    // switched off does not attract work it cannot do anything with.
    $builders = [
        'etch/etch.php' => ['Etch', 'etchwp-pro'],
        'etchwp/etchwp.php' => ['Etch', 'etchwp-pro'],
        'bricks/bricks.php' => ['Bricks', 'bricks-pro'],
        'elementor/elementor.php' => ['Elementor', 'elementor-pro'],
        'kadence-blocks/kadence-blocks.php' => ['Kadence Blocks', 'kadence-pro'],
    ];

    $found = [];
    foreach ($builders as $file => $pair) {
        if (is_plugin_active($file) && !isset($found[$pair[0]])) {
            $found[$pair[0]] = $pair[1];
        }
    }

    if ($found === []) {
        return [];
    }

    $names = array_keys($found);
    $skills = array_values(array_filter($found, static function (string $id): bool {
        foreach (nibwp_enabled_skills() as $skill) {
            if ($skill['id'] === $id) {
                return true;
            }
        }
        return false;
    }));

    $lines = [
        '## Build with the builder, not with HTML',
        '',
        'This site builds pages with ' . implode(' and ', $names) . '. Use its own',
        'elements — every heading, section, image and button as a real element the',
        'editor understands, carrying the classes and design tokens this site already',
        'uses.',
        '',
        'Raw HTML is a last resort, not a shortcut. A page pasted in as markup renders',
        'correctly and is dead in the editor: it cannot be restyled, the builder\'s',
        'classes never reach it, and whoever asked for the page is left with something',
        'only an agent can change. Reach for an HTML or custom-code element only where',
        'the builder genuinely has no equivalent — an embed, a third-party script — and',
        'say so when you do.',
        '',
    ];

    if ($skills !== []) {
        $lines[] = 'The skill for this is switched on here: ' . implode(', ', array_map(
            static fn(string $id): string => 'nibwp/' . $id,
            $skills
        )) . '. Load it with nibwp/get-skill before building — it';
        $lines[] = 'carries the element shapes and validation that keep the output editable.';
        $lines[] = '';
    }

    return $lines;
}

/**
 * STUDIO nav items — per-integration workspaces (Figma today; other design /
 * content sources later). An entry appears only once its integration is both
 * licensed and activated, so the section stays empty (and hidden) otherwise.
 *
 * @return array<int, array{slug:string,label:string,icon:string}>
 */
function nibwp_studio_nav_items(): array
{
    $items = [];

    // The workspace leads this section. It is the one screen here where the
    // work happens in front of you rather than being configured, and it carries
    // the same mark it has in the WordPress menu so the two read as one thing.
    if (function_exists('nibwp_visual_url')) {
        $items[] = [
            'slug'  => 'nibwp-visual',
            'label' => __('Agent View', 'nibwp'),
            'icon'  => nibwp_visual_spark_svg(18, 'nibwp-nav-spark'),
        ];
    }

    $figma_on = function_exists('nibwp_figma_unlocked') && nibwp_figma_unlocked()
        && function_exists('nibwp_is_integration_enabled') && nibwp_is_integration_enabled('figma');
    if ($figma_on) {
        $items[] = [
            'slug'  => 'nibwp-figma',
            'label' => __('Figma', 'nibwp'),
            'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2H8.5a3.5 3.5 0 000 7H12z"/><path d="M12 2h3.5a3.5 3.5 0 110 7H12z"/><path d="M12 9H8.5a3.5 3.5 0 000 7H12z"/><path d="M12 16H8.5A3.5 3.5 0 1012 19.5z"/><circle cx="15.5" cy="12.5" r="3.5"/></svg>',
        ];
    }

    /**
     * Let other integrations add their own workspace screen to STUDIO.
     *
     * @param array<int, array{slug:string,label:string,icon:string}> $items
     */
    return (array) apply_filters('nibwp_studio_nav_items', $items);
}

/**
 * Render the full custom app shell: sidebar + topbar + content area opener.
 * Every admin page calls this at the top, then renders content, then calls nibwp_render_admin_footer().
 */
/**
 * Where anything a user writes to us goes.
 *
 * One function rather than the same apply_filters() copied at each call site,
 * so changing the address changes it everywhere instead of everywhere someone
 * remembered to look.
 */
function nibwp_support_email(): string
{
    return (string) apply_filters('nibwp_support_email', 'support@nibwp.com');
}

function nibwp_render_admin_header(): void
{
    nibwp_render_admin_styles();
    $is_enabled = nibwp_is_enabled();
    $current_page = $_GET['page'] ?? '';

    // Count integrations + tools + memory for topbar stats
    $integrations = function_exists('nibwp_get_integrations') ? nibwp_get_integrations() : [];
    $active_integrations = count(array_filter($integrations, static fn($i) => ($i['plugin_available'] ?? false) && ($i['enabled'] ?? false)));
    $active_integration_list = array_values(array_filter($integrations, static fn($i) => ($i['plugin_available'] ?? false) && ($i['enabled'] ?? false)));
    usort($active_integration_list, static fn($a, $b) => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
    $memory_count = count(function_exists('nibwp_admin_memory_get_all') ? nibwp_admin_memory_get_all() : (is_array(get_option('nibwp_memory_store', [])) ? get_option('nibwp_memory_store', []) : []));

    $nav_items = [
        [
            'section' => '',
            'items' => [
                ['slug' => 'nibwp-dashboard', 'label' => __('Dashboard', 'nibwp'), 'icon' => '<svg width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="2" width="5.5" height="5.5" rx="1.2"/><rect x="10.5" y="2" width="5.5" height="5.5" rx="1.2"/><rect x="2" y="10.5" width="5.5" height="5.5" rx="1.2"/><rect x="10.5" y="10.5" width="5.5" height="5.5" rx="1.2"/></svg>'],
                ['slug' => 'nibwp-connect', 'label' => __('Connect', 'nibwp'), 'icon' => '<svg width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 2v4M5.5 4.5L7 6m4 0l1.5-1.5M3 9h12"/><path d="M5 10a4 4 0 008 0"/></svg>'],
                ['slug' => 'nibwp-integrations', 'label' => __('Integrations', 'nibwp'), 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><path d="M14 17.5h7"/><path d="M17.5 14v7"/></svg>'],
                ['slug' => 'nibwp', 'label' => __('AI Abilities', 'nibwp'), 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3.5 13.6 8 18 9.5 13.6 11 12 15.5 10.4 11 6 9.5 10.4 8 12 3.5Z"/><path d="M19 4v3M17.5 5.5h3M5 17v3M3.5 18.5h3"/></svg>'],
                ['slug' => 'nibwp-skills', 'label' => __('Skills', 'nibwp'), 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12l3 6-9 12L3 9l3-6z"/><path d="M3 9h18"/><path d="m10 3-2 6 4 12 4-12-2-6"/></svg>'],
                ['slug' => 'nibwp-workflows', 'label' => __('Workflows', 'nibwp'), 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="m3 6 2 2 4-4"/><path d="m3 14 2 2 4-4"/><path d="M13 6h8"/><path d="M13 14h8"/><path d="M13 20h8"/><path d="M3 20h2"/></svg>'],
                ['slug' => 'nibwp-jobs', 'label' => __('Jobs', 'nibwp'), 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="18" height="14" rx="2"/><path d="M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2"/><path d="M3 12h18"/></svg>'],
            ],
        ],
        // STUDIO — per-integration workspaces (design/content sources NibWP pulls
        // from). Figma today; Miro, Notion, Drive and friends land here next. The
        // section only renders when at least one such integration is activated.
        [
            'section' => __('STUDIO', 'nibwp'),
            'items' => nibwp_studio_nav_items(),
        ],
        [
            'section' => __('DATA', 'nibwp'),
            'items' => [
                ['slug' => 'nibwp-memory', 'label' => __('Memory', 'nibwp'), 'icon' => '<svg width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="2" width="12" height="14" rx="1.5"/><path d="M6 2v14M12 2v14M3 9h12"/></svg>'],
                ['slug' => 'nibwp-audit-log', 'label' => __('Audit Log', 'nibwp'), 'icon' => '<svg width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M5 2h8a1.5 1.5 0 011.5 1.5v11a1.5 1.5 0 01-1.5 1.5H5a1.5 1.5 0 01-1.5-1.5v-11A1.5 1.5 0 015 2z"/><path d="M7 6h4M7 9h4M7 12h2"/></svg>'],
                ['slug' => 'nibwp-sandbox', 'label' => __('Sandbox', 'nibwp'), 'icon' => '<svg width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="12" height="12" rx="1.5"/><rect x="6.5" y="6.5" width="5" height="5" rx="0.5"/></svg>'],
            ],
        ],
        [
            'section' => __('CONFIGURATION', 'nibwp'),
            'items' => [
                ['slug' => 'nibwp-license', 'label' => __('License', 'nibwp'), 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>'],
                ['slug' => 'nibwp-user-access', 'label' => __('User access', 'nibwp'), 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 11h-6"/></svg>'],
                ['slug' => 'nibwp-status', 'label' => __('Status', 'nibwp'), 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>', 'dot' => function_exists('nibwp_status_nav_state') ? nibwp_status_nav_state() : ''],
                ['slug' => 'nibwp-settings', 'label' => __('Settings', 'nibwp'), 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 00-2 2v.18a2 2 0 01-1 1.73l-.43.25a2 2 0 01-2 0l-.15-.08a2 2 0 00-2.73.73l-.22.38a2 2 0 00.73 2.73l.15.1a2 2 0 011 1.72v.51a2 2 0 01-1 1.74l-.15.09a2 2 0 00-.73 2.73l.22.38a2 2 0 002.73.73l.15-.08a2 2 0 012 0l.43.25a2 2 0 011 1.73V20a2 2 0 002 2h.44a2 2 0 002-2v-.18a2 2 0 011-1.73l.43-.25a2 2 0 012 0l.15.08a2 2 0 002.73-.73l.22-.39a2 2 0 00-.73-2.73l-.15-.08a2 2 0 01-1-1.74v-.5a2 2 0 011-1.74l.15-.09a2 2 0 00.73-2.73l-.22-.38a2 2 0 00-2.73-.73l-.15.08a2 2 0 01-2 0l-.43-.25a2 2 0 01-1-1.73V4a2 2 0 00-2-2z"/><circle cx="12" cy="12" r="3"/></svg>'],
            ],
        ],
    ];

    // Affiliate program — last item under CONFIGURATION, below Settings.
    // Appended rather than written inline because it is conditional: hidden
    // for anyone who dismissed it, and when the program is paused.
    if (function_exists('nibwp_affiliate_visible') && nibwp_affiliate_visible()) {
        $nav_items[count($nav_items) - 1]['items'][] = [
            'slug'  => 'nibwp-affiliate',
            'label' => nibwp_affiliate_menu_label(),
            'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M15 9l-6 6"/><circle cx="9.5" cy="9.5" r="1.2"/><circle cx="14.5" cy="14.5" r="1.2"/></svg>',
        ];
    }
    // Menu visibility (includes/user-access.php). The WP sidebar is already
    // filtered at registration; this keeps NIBWP's own sidebar consistent for a
    // restricted administrator who arrived on a shared link.
    if (function_exists('nibwp_user_access_visible_slugs')) {
        $nibwp_visible = nibwp_user_access_visible_slugs();
        if ($nibwp_visible !== null) {
            foreach ($nav_items as $group_index => $group) {
                $nav_items[$group_index]['items'] = array_values(array_filter(
                    (array) ($group['items'] ?? []),
                    static fn(array $item): bool => in_array($item['slug'] ?? '', $nibwp_visible, true),
                ));
            }
        }
    }

    ?>
    <script>/* Tag notices present before NIBWP content renders (WP core + other plugins) so they can be hoisted out of the panel. NIBWP's own notices render later, inside .nibwp-wrap, untagged. */(function(){var b=document.getElementById('wpbody-content');if(b){b.querySelectorAll('.notice,.updated,.error,.update-nag').forEach(function(n){if(!n.classList.contains('nibwp-keep')&&!n.classList.contains('nibwp-pro-notice'))n.setAttribute('data-nibwp-foreign','1');});}})();</script>
    <div class="nw-app">
        <!-- Sidebar -->
        <aside class="nw-sidebar" id="nw-sidebar">
            <div class="nw-sidebar__inner">
                <a class="nw-sidebar__logo" href="<?php echo esc_url(admin_url('admin.php?page=nibwp-dashboard')); ?>">
                    <div class="nw-logo-row">
                        <div class="nw-logo-icon">
                            <img src="<?php echo esc_url((string) NIBWP_PLUGIN_URL . 'assets/nibwp-logo.svg'); ?>" alt="NIBWP">
                        </div>
                        <span class="nw-sidebar__version">v<?php echo esc_html(NIBWP_VERSION); ?></span>
                    </div>
                    <div class="nw-logo-sub"><?php esc_html_e('AI-POWERED WORDPRESS — A NEW ERA', 'nibwp'); ?></div>
                </a>
                <nav class="nw-nav" aria-label="<?php esc_attr_e('NIBWP navigation', 'nibwp'); ?>">
                    <?php foreach ($nav_items as $group): ?>
                        <?php if (empty($group['items'])) { continue; } // skip empty sections (e.g. STUDIO with no integration active) ?>
                        <?php if ($group['section'] !== ''): ?>
                            <div class="nw-nav-section"><?php echo esc_html($group['section']); ?></div>
                        <?php endif; ?>
                        <?php foreach ($group['items'] as $item): ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=' . $item['slug'])); ?>"
                               <?php // The workspace is a place you watch while working elsewhere;
                                     // taking over the tab you launched it from is the one thing it
                                     // must not do. ?>
                               <?php echo $item['slug'] === 'nibwp-visual' ? 'target="_blank" rel="noopener"' : ''; ?>
                               class="nw-nav-item <?php echo $current_page === $item['slug'] ? 'is-active' : ''; ?>">
                                <span class="nw-nav-item__icon"><?php echo $item['icon']; ?></span>
                                <span class="nw-nav-item__label"><?php echo esc_html($item['label']); ?></span>
                                <?php if (!empty($item['dot'])): ?>
                                    <?php
                                    $dot_title = $item['dot'] === 'fail'
                                        ? __('Something is stopping connections', 'nibwp')
                                        : __('Something needs a look', 'nibwp');
                                    ?>
                                    <span class="nw-nav-dot is-<?php echo esc_attr($item['dot']); ?>"
                                          role="img"
                                          aria-label="<?php echo esc_attr($dot_title); ?>"
                                          title="<?php echo esc_attr($dot_title); ?>"></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </nav>
                <div class="nw-sidebar__footer">
                    <a href="https://www.nibwp.com/docs" target="_blank" rel="noopener">
                        <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.3"><path d="M4 2h6a1 1 0 011 1v8a1 1 0 01-1 1H4a1 1 0 01-1-1V3a1 1 0 011-1z"/><path d="M5 5h4M5 7h4M5 9h2"/></svg>
                        <?php esc_html_e('Documentation', 'nibwp'); ?>
                    </a>
                    <a href="https://www.nibwp.com/support" target="_blank" rel="noopener">
                        <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.3"><circle cx="7" cy="7" r="6"/><path d="M5 5.5a2 2 0 013.5 1.5c0 1-1.5 1.5-1.5 1.5"/><circle cx="7" cy="10.5" r="0.5" fill="currentColor"/></svg>
                        <?php esc_html_e('Support', 'nibwp'); ?>
                    </a>
                    <div class="nw-sidebar__credits <?php echo $is_enabled ? '' : 'off'; ?>">
                        <span class="lbl"><span class="dot"></span><?php esc_html_e('TOOLS', 'nibwp'); ?></span>
                        <span class="num"><?php
                            $ability_groups = function_exists('nibwp_collect_public_abilities') ? nibwp_collect_public_abilities() : [];
                            $tool_count = 0;
                            foreach ($ability_groups as $abilities) { $tool_count += count($abilities); }
                            echo esc_html((string) $tool_count);
                        ?></span>
                    </div>
                    <div class="nw-sidebar__rating">
                        <span class="nw-rating-label"><?php esc_html_e('Enjoying NIBWP?', 'nibwp'); ?></span>
                        <div class="nw-rating-stars">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <a href="https://wordpress.org/support/plugin/nibwp/reviews/#new-post" target="_blank" rel="noopener" aria-label="<?php
                                    printf(
                                        /* translators: %d: star rating from 1 to 5 */
                                        esc_attr__('Rate %d of 5', 'nibwp'),
                                        (int) $i
                                    );
                                ?>">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1.5"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                                </a>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Main area -->
        <div class="nw-main">
            <!-- Topbar -->
            <header class="nw-topbar">
                <button type="button" class="nw-icon-btn nw-topbar__menu-btn" id="nw-menu-toggle" aria-label="<?php esc_attr_e('Toggle navigation', 'nibwp'); ?>">
                    <svg width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M3 5h12M3 9h12M3 13h12"/></svg>
                </button>

                <button type="button" class="nw-search-trigger" id="nw-search-trigger">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="7" cy="7" r="4.5"/><path d="M10.5 10.5L14 14"/></svg>
                    <span class="nw-search-trigger__label"><?php esc_html_e('Search pages, actions, settings...', 'nibwp'); ?></span>
                    <kbd class="nw-kbd">&#8984;K</kbd>
                </button>

                <div class="nw-topbar__actions">

                    <span class="nw-topbar-status <?php echo $is_enabled ? 'is-on' : 'is-off'; ?>">
                        <?php echo $is_enabled
                            ? esc_html__('NIBWP ON', 'nibwp')
                            : esc_html__('NIBWP OFF', 'nibwp'); ?>
                    </span>

                    <span class="nw-topbar-chipwrap">
                        <span class="nw-topbar-chip nw-topbar-chip--menu" tabindex="0" aria-haspopup="true">
                            <?php esc_html_e('Integrations:', 'nibwp'); ?>
                            <strong><?php echo esc_html((string) $active_integrations); ?></strong>
                            <svg class="nw-chip-chev" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                        </span>
                        <div class="nw-topbar-menu" role="menu">
                            <div class="nw-topbar-menu__list">
                            <?php if ($active_integration_list === []): ?>
                                <div class="nw-topbar-menu__empty"><?php esc_html_e('No active integrations yet.', 'nibwp'); ?></div>
                            <?php else: ?>
                                <div class="nw-topbar-menu__head"><?php esc_html_e('Connected', 'nibwp'); ?></div>
                                <?php foreach ($active_integration_list as $nw_intg): ?>
                                    <span class="nw-topbar-menu__item" role="menuitem"><span class="dot"></span><?php echo esc_html((string) ($nw_intg['name'] ?? '')); ?></span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </div>
                            <a class="nw-topbar-menu__foot" href="<?php echo esc_url(admin_url('admin.php?page=nibwp-integrations')); ?>">
                                <?php esc_html_e('Manage integrations', 'nibwp'); ?>
                                <svg class="nw-rtl-flip" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                            </a>
                        </div>
                    </span>

                    <?php if (function_exists('nibwp_jobs_running')): $nw_running = nibwp_jobs_running(); $nw_run_n = count($nw_running); ?>
                    <span class="nw-topbar-chipwrap nw-jobs-chipwrap">
                        <a class="nw-topbar-chip nw-topbar-chip--menu nw-jobs-chip<?php echo $nw_run_n ? ' is-live' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=nibwp-jobs&tab=activity')); ?>" aria-haspopup="true">
                            <?php if ($nw_run_n): ?><span class="nw-jobs-chip__pulse" aria-hidden="true"></span><?php endif; ?>
                            <?php esc_html_e('Jobs:', 'nibwp'); ?>
                            <strong><?php echo esc_html((string) $nw_run_n); ?></strong>
                            <svg class="nw-chip-chev" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                        </a>
                        <div class="nw-topbar-menu nw-jobs-menu" role="menu">
                            <div class="nw-topbar-menu__list nw-jobs-menu__scroll">
                            <?php if (!$nw_running): ?>
                                <div class="nw-jobs-menu__empty">
                                    <span class="nw-jobs-menu__empty-ic" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span>
                                    <?php esc_html_e('No jobs running right now.', 'nibwp'); ?>
                                </div>
                            <?php else: ?>
                                <div class="nw-jobs-menu__head"><?php
                                    printf(
                                        /* translators: %s: number of jobs running now */
                                        esc_html(_n('%s job running', '%s jobs running', $nw_run_n, 'nibwp')),
                                        esc_html(number_format_i18n($nw_run_n))
                                    );
                                ?></div>
                                <?php foreach ($nw_running as $nw_run):
                                    $nw_st = (string) $nw_run['status'];
                                    $nw_rs = ['queued' => __('Queued', 'nibwp'), 'running' => __('Running', 'nibwp'), 'awaiting_approval' => __('Needs you', 'nibwp')][$nw_st] ?? $nw_st;
                                    $nw_card  = function_exists('nibwp_jobs_catalog_card') ? nibwp_jobs_catalog_card((string) ($nw_run['catalog'] ?? '')) : null;
                                    $nw_glyph = ($nw_card && function_exists('nibwp_jobs_icon')) ? nibwp_jobs_icon((string) ($nw_card['icon'] ?? '')) : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="18" height="14" rx="2"/><path d="M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>'; ?>
                                    <a class="nw-topbar-menu__item nw-jobs-menu__item nw-jm--<?php echo esc_attr($nw_st); ?>" href="<?php echo esc_url(admin_url('admin.php?page=nibwp-jobs&tab=activity')); ?>" role="menuitem">
                                        <span class="nw-jobs-menu__ic" aria-hidden="true"><?php echo $nw_glyph; ?></span>
                                        <span class="nw-jobs-menu__body">
                                            <strong><?php echo esc_html($nw_run['job_name']); ?></strong>
                                            <small><?php echo esc_html($nw_run['step'] ?: $nw_rs); ?></small>
                                        </span>
                                        <span class="nw-jobs-menu__stat"><?php if ($nw_st === 'running'): ?><span class="nw-jm-spin" aria-hidden="true"></span><?php endif; ?><?php echo esc_html($nw_rs); ?></span>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </div>
                            <a class="nw-topbar-menu__foot nw-jobs-menu__foot" href="<?php echo esc_url(admin_url('admin.php?page=nibwp-jobs')); ?>">
                                <?php esc_html_e('See all Jobs', 'nibwp'); ?>
                                <svg class="nw-rtl-flip" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                            </a>
                        </div>
                    </span>
                    <style>
                    .nw-jobs-chip{text-decoration:none;position:relative;}
                    .nw-jobs-chip.is-live strong{color:var(--nw-ok);}
                    .nw-jobs-chip__pulse{width:7px;height:7px;border-radius:50%;background:var(--nw-ok);margin-inline-end:2px;box-shadow:0 0 0 0 rgba(22,163,74,.5);animation:nw-jobs-chip-pulse 1.7s infinite;}
                    @keyframes nw-jobs-chip-pulse{0%{box-shadow:0 0 0 0 rgba(22,163,74,.45);}70%{box-shadow:0 0 0 6px rgba(22,163,74,0);}100%{box-shadow:0 0 0 0 rgba(22,163,74,0);}}
                    /* scrollable list + sticky footer (scoped to Jobs menu) */
                    .nw-jobs-chipwrap .nw-jobs-menu{display:flex;flex-direction:column;max-height:440px;min-width:320px;padding:0;overflow:hidden;}
                    .nw-jobs-chipwrap .nw-jobs-menu__scroll{flex:1 1 auto;overflow-y:auto;padding:6px;scrollbar-width:thin;}
                    .nw-jobs-chipwrap .nw-jobs-menu__scroll::-webkit-scrollbar{width:8px;}
                    .nw-jobs-chipwrap .nw-jobs-menu__scroll::-webkit-scrollbar-thumb{background:var(--nw-border-strong);border-radius:99px;}
                    .nw-jobs-menu__head{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--nw-text-muted-2);padding:8px 10px 6px;}
                    .nw-jobs-menu__item{display:flex;align-items:center;gap:11px;padding:8px 10px;border-radius:9px;text-decoration:none;transition:background .12s;}
                    .nw-jobs-menu__item:hover{background:var(--nw-surface-2);}
                    .nw-jobs-menu__ic{flex-shrink:0;width:32px;height:32px;border-radius:9px;display:grid;place-items:center;background:var(--nw-surface-3);color:var(--nw-text-muted);}
                    .nw-jobs-menu__ic svg{width:16px;height:16px;}
                    .nw-jm--running .nw-jobs-menu__ic{background:var(--nw-brand-soft);color:var(--nw-brand);}
                    .nw-jm--queued .nw-jobs-menu__ic{background:var(--nw-surface-3);color:var(--nw-text-muted);}
                    .nw-jm--awaiting_approval .nw-jobs-menu__ic{background:var(--nw-warn-soft);color:var(--nw-warn);}
                    .nw-jobs-menu__body{display:flex;flex-direction:column;line-height:1.35;min-width:0;flex:1;}
                    .nw-jobs-menu__body strong{font-size:13px;font-weight:600;color:var(--nw-text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
                    .nw-jobs-menu__body small{font-size:11.5px;color:var(--nw-text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
                    .nw-jobs-menu__stat{flex-shrink:0;display:inline-flex;align-items:center;gap:5px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.02em;padding:3px 8px;border-radius:99px;background:var(--nw-surface-3);color:var(--nw-text-muted);}
                    .nw-jm--running .nw-jobs-menu__stat{background:var(--nw-brand-soft);color:var(--nw-brand);}
                    .nw-jm--awaiting_approval .nw-jobs-menu__stat{background:var(--nw-warn-soft);color:var(--nw-warn);}
                    .nw-jm-spin{width:9px;height:9px;border:2px solid currentColor;border-top-color:transparent;border-radius:50%;display:inline-block;animation:nw-jobs-chip-spin .6s linear infinite;}
                    @keyframes nw-jobs-chip-spin{to{transform:rotate(360deg);}}
                    .nw-jobs-menu__empty{display:flex;flex-direction:column;align-items:center;gap:8px;padding:28px 20px;color:var(--nw-text-muted);font-size:12.5px;text-align:center;}
                    .nw-jobs-menu__empty-ic{color:var(--nw-border-strong);}
                    .nw-jobs-chipwrap .nw-jobs-menu__foot{position:sticky;bottom:0;flex-shrink:0;display:flex;align-items:center;justify-content:space-between;gap:7px;padding:11px 15px;font-weight:600;font-size:12.5px;color:var(--nw-brand);background:var(--nw-surface);border-top:1px solid var(--nw-border-2);border-radius:0 0 var(--nw-radius,10px) var(--nw-radius,10px);text-decoration:none;}
                    .nw-jobs-chipwrap .nw-jobs-menu__foot:hover{background:var(--nw-brand-soft);}
                    </style>
                    <?php endif; ?>

                    <button type="button" class="nw-cta-btn" id="nw-run-setup" aria-label="<?php esc_attr_e('Run Setup', 'nibwp'); ?>">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                        <span><?php esc_html_e('Run Setup', 'nibwp'); ?></span>
                    </button>

                    <a class="nw-icon-btn" href="<?php echo esc_url(admin_url('admin.php?page=nibwp-how-to')); ?>" aria-label="<?php esc_attr_e('How To', 'nibwp'); ?>" title="<?php esc_attr_e('How To', 'nibwp'); ?>">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    </a>

                    <button type="button" class="nw-icon-btn" id="nw-theme-toggle" aria-label="<?php esc_attr_e('Toggle dark mode', 'nibwp'); ?>">
                        <svg class="nw-sun" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                        <svg class="nw-moon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
                    </button>
                </div>
            </header>

            <?php nibwp_render_onboarder_modal(); ?>

            <!-- Content -->
            <div class="nw-content">
    <?php
}

/**
 * Register REST endpoints used by the Run Setup onboarder modal.
 */
add_action('rest_api_init', function () {
    register_rest_route('nibwp/v1', '/onboarder/enable', [
        'methods' => 'POST',
        'callback' => function (WP_REST_Request $req) {
            $enable = (bool) $req->get_param('enable');
            update_option('nibwp_ai_abilities_enabled', $enable ? '1' : '');
            if ($enable) {
                update_option('nibwp_ai_abilities_domain', (string) wp_parse_url(home_url(), PHP_URL_HOST));
            }
            return [
                'enabled' => function_exists('nibwp_is_enabled') ? (bool) nibwp_is_enabled() : $enable,
            ];
        },
        'permission_callback' => function () {
            return current_user_can('manage_options');
        },
    ]);
});

/**
 * Render the Run Setup onboarder modal — full-screen lock + stepper with inline actions.
 */
function nibwp_render_onboarder_modal(): void
{
    $is_enabled = function_exists('nibwp_is_enabled') ? (bool) nibwp_is_enabled() : (bool) get_option('nibwp_ai_abilities_enabled', false);
    $rest_root = esc_url_raw(rest_url());
    $mcp_url = esc_url_raw(rest_url('mcp/nibwp'));
    $user = wp_get_current_user();
    $username = $user->user_login;
    $user_id = (int) $user->ID;
    $rest_nonce = wp_create_nonce('wp_rest');
    $app_pass_supported = class_exists('WP_Application_Passwords') && wp_is_application_passwords_available();
    $connect_url = admin_url('admin.php?page=nibwp-connect');
    $integrations_url = admin_url('admin.php?page=nibwp-integrations');

    // Build per-client config snippets (reuse connect-page builder).
    $pw_slot = '__NIBWP_PW_SLOT__';
    $mcp_name = function_exists('nibwp_get_mcp_server_name_default') ? nibwp_get_mcp_server_name_default() : 'nibwp';
    $client_configs = function_exists('nibwp_build_configs')
        ? nibwp_build_configs($mcp_url, $username, $pw_slot, $mcp_name)
        : [];
    $client_labels = [
        'claude-desktop' => 'Claude Desktop',
        'cursor' => 'Cursor',
        'vscode' => 'VS Code',
        'windsurf' => 'Windsurf',
        'cline' => 'Cline',
        'roo-code' => 'Roo Code',
        'github-copilot' => 'GitHub Copilot',
        'claude-code' => __('Claude Code (terminal)', 'nibwp'),
        'gemini-cli' => __('Gemini CLI (terminal)', 'nibwp'),
        'codex' => __('Codex (terminal)', 'nibwp'),
        'zed' => 'Zed',
        'opencode' => 'OpenCode',
        'amazon-q' => 'Amazon Q',
        'kilo-code' => 'Kilo Code',
        'antigravity' => 'Antigravity',
    ];

    // Plain-English, non-techy step-by-step per client.
    $client_instructions = [
        'claude-desktop' => [
            __('Open the <strong>Claude Desktop</strong> app on your computer.', 'nibwp'),
            __('Click your name in the bottom-left corner, then choose <strong>Settings</strong>.', 'nibwp'),
            __('In Settings, click the <strong>Developer</strong> tab on the left.', 'nibwp'),
            __('Click the <strong>Edit Config</strong> button — your text editor opens automatically.', 'nibwp'),
            __('Paste the snippet above into that file, then <strong>save</strong> and <strong>fully restart Claude Desktop</strong>.', 'nibwp'),
        ],
        'cursor' => [
            __('Open <strong>Cursor</strong>.', 'nibwp'),
            __('Press <kbd>Cmd</kbd>+<kbd>,</kbd> (Mac) or <kbd>Ctrl</kbd>+<kbd>,</kbd> (Windows/Linux) to open Settings.', 'nibwp'),
            __('In the search box, type <strong>MCP</strong>, then click <strong>Add new global MCP server</strong>.', 'nibwp'),
            __('Paste the snippet above into the file that opens. Save and reload Cursor.', 'nibwp'),
        ],
        'vscode' => [
            __('Open <strong>VS Code</strong>.', 'nibwp'),
            __('Press <kbd>Cmd</kbd>+<kbd>Shift</kbd>+<kbd>P</kbd> (Mac) or <kbd>Ctrl</kbd>+<kbd>Shift</kbd>+<kbd>P</kbd> (Windows/Linux).', 'nibwp'),
            __('Type <strong>MCP: Open User Configuration</strong> and press Enter.', 'nibwp'),
            __('Paste the snippet above into the file that opens. Save and reload VS Code.', 'nibwp'),
        ],
        'windsurf' => [
            __('Open <strong>Windsurf</strong>.', 'nibwp'),
            __('Open the Cascade panel on the right side.', 'nibwp'),
            __('Click the hammer/tools icon, then choose <strong>Configure MCP</strong>.', 'nibwp'),
            __('Paste the snippet above. Save and restart Windsurf.', 'nibwp'),
        ],
        'cline' => [
            __('In VS Code, click the <strong>Cline</strong> icon in the sidebar.', 'nibwp'),
            __('At the top of Cline panel, click the ≡ menu and choose <strong>MCP Servers</strong>.', 'nibwp'),
            __('Click <strong>Configure MCP Servers</strong> — a JSON file opens.', 'nibwp'),
            __('Paste the snippet above. Save the file.', 'nibwp'),
        ],
        'roo-code' => [
            __('In VS Code, click the <strong>Roo Code</strong> icon in the sidebar.', 'nibwp'),
            __('Click the ≡ menu at the top, choose <strong>MCP Servers</strong>.', 'nibwp'),
            __('Click <strong>Edit MCP Settings</strong>. Paste the snippet. Save.', 'nibwp'),
        ],
        'github-copilot' => [
            __('Open your project in <strong>VS Code</strong>.', 'nibwp'),
            __('Create a folder called <code>.github/copilot</code> in your project if it doesn\'t exist.', 'nibwp'),
            __('Inside it, create a file named <code>mcp.json</code>.', 'nibwp'),
            __('Paste the snippet above into that file. Save and reload Copilot.', 'nibwp'),
        ],
        'claude-code' => [
            __('Open your <strong>Terminal</strong> (Mac) or <strong>PowerShell</strong> (Windows).', 'nibwp'),
            __('Make sure <strong>Claude Code</strong> is installed (<code>npm install -g @anthropic-ai/claude-code</code>).', 'nibwp'),
            __('Paste and run the command shown above. It registers the MCP server globally.', 'nibwp'),
            __('Open Claude Code — the NIBWP tools are now available.', 'nibwp'),
        ],
        'gemini-cli' => [
            __('Open your <strong>Terminal</strong>.', 'nibwp'),
            __('Edit the file at <code>~/.gemini/settings.json</code> (create it if missing).', 'nibwp'),
            __('Paste the snippet above. Save the file. Restart Gemini CLI.', 'nibwp'),
        ],
        'codex' => [
            __('Open your <strong>Terminal</strong>.', 'nibwp'),
            __('Edit the file at <code>~/.codex/config.toml</code> (create it if missing).', 'nibwp'),
            __('Paste the snippet above. Save the file.', 'nibwp'),
        ],
        'zed' => [
            __('Open <strong>Zed</strong>.', 'nibwp'),
            __('Open Settings: <kbd>Cmd</kbd>+<kbd>,</kbd> (Mac) / <kbd>Ctrl</kbd>+<kbd>,</kbd> (Linux).', 'nibwp'),
            __('Merge the snippet above into your <code>settings.json</code>. Save.', 'nibwp'),
        ],
        'opencode' => [
            __('In your project root, create a file called <code>opencode.json</code>.', 'nibwp'),
            __('Paste the snippet above into that file. Save.', 'nibwp'),
        ],
        'amazon-q' => [
            __('Open <strong>Amazon Q</strong> in your IDE.', 'nibwp'),
            __('Open the MCP settings panel.', 'nibwp'),
            __('Paste the snippet above and save.', 'nibwp'),
        ],
        'kilo-code' => [
            __('In VS Code, click the <strong>Kilo Code</strong> sidebar icon.', 'nibwp'),
            __('Choose <strong>MCP Servers → Configure MCP Servers</strong>.', 'nibwp'),
            __('Paste the snippet. Save.', 'nibwp'),
        ],
        'antigravity' => [
            __('Open <strong>Antigravity</strong>.', 'nibwp'),
            __('Open MCP config from settings.', 'nibwp'),
            __('Paste the snippet above. Save and restart.', 'nibwp'),
        ],
    ];

    // Optional "Help me find it" deep-link/help URL per client.
    $client_help_urls = [
        'claude-desktop' => 'https://modelcontextprotocol.io/quickstart/user',
        'cursor' => 'https://docs.cursor.com/context/model-context-protocol',
        'vscode' => 'https://code.visualstudio.com/docs/copilot/chat/mcp-servers',
        'windsurf' => 'https://docs.windsurf.com/windsurf/cascade/mcp',
        'cline' => 'https://docs.cline.bot/mcp/configuring-mcp-servers',
        'claude-code' => 'https://docs.claude.com/en/docs/claude-code/mcp',
    ];
    ?>
    <div class="nw-onboarder" id="nw-onboarder" role="dialog" aria-modal="true" aria-hidden="true">
        <div class="nw-onboarder__backdrop"></div>
        <div class="nw-onboarder__panel" role="document">
            <button type="button" class="nw-onboarder__close" id="nw-onboarder-close" aria-label="<?php esc_attr_e('Close', 'nibwp'); ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>

            <div class="nw-onboarder__progress" id="nw-onboarder-progress"></div>

            <div class="nw-onboarder__body">
                <!-- Step 1 -->
                <section class="nw-onb-step" data-step="0">
                    <div class="nw-onb-step__icon">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M18.36 6.64a9 9 0 11-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
                    </div>
                    <h2><?php esc_html_e('Enable AI Abilities', 'nibwp'); ?></h2>
                    <p><?php esc_html_e('Turns on the MCP endpoint so AI agents can connect to this WordPress site. Required first step.', 'nibwp'); ?></p>
                    <div class="nw-onb-status nw-onb-status--<?php echo $is_enabled ? 'ok' : 'warn'; ?>" id="nw-onb-enable-status">
                        <?php echo $is_enabled
                            ? esc_html__('✓ Already enabled — you can continue.', 'nibwp')
                            : esc_html__('Currently OFF. Click below to enable now.', 'nibwp'); ?>
                    </div>
                    <button type="button" class="nw-onb-test-btn" id="nw-onb-enable-btn" <?php echo $is_enabled ? 'hidden' : ''; ?>>
                        <?php esc_html_e('Enable AI Abilities now', 'nibwp'); ?>
                    </button>
                </section>

                <!-- Step 2 -->
                <section class="nw-onb-step" data-step="1" hidden>
                    <div class="nw-onb-step__icon">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                    </div>
                    <h2><?php esc_html_e('Create an App Password', 'nibwp'); ?></h2>
                    <p><?php printf(
                        /* translators: %s: WordPress username */
                        esc_html__('Generates a WordPress Application Password for %s. AI clients use this to authenticate.', 'nibwp'),
                        '<strong>' . esc_html($username) . '</strong>'
                    ); ?></p>
                    <?php if (!$app_pass_supported): ?>
                        <div class="nw-onb-status nw-onb-status--warn">
                            <?php esc_html_e('Application Passwords are disabled on this site. Enable them in WordPress core settings or contact your host.', 'nibwp'); ?>
                        </div>
                    <?php else: ?>
                        <button type="button" class="nw-onb-test-btn" id="nw-onb-pass-btn">
                            <?php esc_html_e('Generate App Password now', 'nibwp'); ?>
                        </button>
                        <div class="nw-onb-test-result" id="nw-onb-pass-result" hidden></div>
                    <?php endif; ?>
                </section>

                <!-- Step 3 -->
                <section class="nw-onb-step" data-step="2" hidden>
                    <div class="nw-onb-step__icon">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                    </div>
                    <h2><?php esc_html_e('Configure your AI client', 'nibwp'); ?></h2>
                    <p><?php esc_html_e('Pick your client. Copy the snippet below and paste it where indicated.', 'nibwp'); ?></p>

                    <label class="nw-onb-client-label">
                        <?php esc_html_e('AI client', 'nibwp'); ?>
                        <select id="nw-onb-client-select" class="nw-onb-client-select">
                            <?php foreach ($client_labels as $key => $label):
                                if (!isset($client_configs[$key])) { continue; } ?>
                                <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <div class="nw-onb-config-box">
                        <div class="nw-onb-config-header">
                            <span id="nw-onb-config-hint"></span>
                            <button type="button" class="nw-onb-copy-btn" id="nw-onb-config-copy-btn">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-inline-end:4px;"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                                <?php esc_html_e('Copy', 'nibwp'); ?>
                            </button>
                        </div>
                        <pre id="nw-onb-config-code"></pre>
                    </div>

                    <div class="nw-onb-howto" id="nw-onb-howto"></div>

                    <p class="nw-onb-save-hint" id="nw-onb-pass-warn" hidden>
                        ⚠ <?php esc_html_e('Generate the App Password in step 2 first — the snippet contains a placeholder until then.', 'nibwp'); ?>
                    </p>
                </section>

                <!-- Step 4 -->
                <section class="nw-onb-step" data-step="3" hidden>
                    <div class="nw-onb-step__icon">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <h2><?php esc_html_e('Test the connection', 'nibwp'); ?></h2>
                    <p><?php esc_html_e('Verify the MCP endpoint is reachable from this browser. This proves the route is live.', 'nibwp'); ?></p>
                    <button type="button" class="nw-onb-test-btn" id="nw-onb-test-btn">
                        <?php esc_html_e('Run test now', 'nibwp'); ?>
                    </button>
                    <div class="nw-onb-test-result" id="nw-onb-test-result" hidden></div>
                </section>

                <!-- Step 5 -->
                <section class="nw-onb-step" data-step="4" hidden>
                    <div class="nw-onb-step__icon nw-onb-step__icon--success">
                        <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="9 12 12 15 16 10"/></svg>
                    </div>
                    <h2><?php esc_html_e('You\'re all set!', 'nibwp'); ?></h2>
                    <p><?php esc_html_e('NIBWP is ready. AI agents can now read files, execute PHP, and manage your WordPress site. Activate plugin integrations next to unlock more abilities.', 'nibwp'); ?></p>
                    <a class="nw-onb-cta-link" href="<?php echo esc_url($integrations_url); ?>">
                        <?php esc_html_e('Activate integrations', 'nibwp'); ?> <span class="nw-rtl-flip" aria-hidden="true">→</span>
                    </a>
                </section>
            </div>

            <div class="nw-onboarder__footer">
                <button type="button" class="nw-onb-btn nw-onb-btn--ghost" id="nw-onb-back" disabled>
                    <?php esc_html_e('Back', 'nibwp'); ?>
                </button>
                <span class="nw-onb-count" id="nw-onb-count">1 / 5</span>
                <button type="button" class="nw-onb-btn nw-onb-btn--primary" id="nw-onb-next">
                    <?php esc_html_e('Next', 'nibwp'); ?>
                </button>
            </div>
        </div>
    </div>

    <script>
    (function () {
        var modal = document.getElementById('nw-onboarder');
        var trigger = document.getElementById('nw-run-setup');
        if (!modal || !trigger) return;

        var steps = modal.querySelectorAll('.nw-onb-step');
        var totalSteps = steps.length;
        var current = 0;
        var backBtn = document.getElementById('nw-onb-back');
        var nextBtn = document.getElementById('nw-onb-next');
        var countEl = document.getElementById('nw-onb-count');
        var progressEl = document.getElementById('nw-onboarder-progress');
        var closeBtn = document.getElementById('nw-onboarder-close');
        var nextLabel = <?php echo wp_json_encode(__('Next', 'nibwp')); ?>;
        var doneLabel = <?php echo wp_json_encode(__('Done', 'nibwp')); ?>;
        var restRoot = <?php echo wp_json_encode($rest_root); ?>;
        var restNonce = <?php echo wp_json_encode($rest_nonce); ?>;
        var enableEndpoint = <?php echo wp_json_encode(rest_url('nibwp/v1/onboarder/enable')); ?>;
        var appPassEndpoint = <?php echo wp_json_encode(rest_url('wp/v2/users/' . $user_id . '/application-passwords')); ?>;
        var mcpUrl = <?php echo wp_json_encode($mcp_url); ?>;
        var username = <?php echo wp_json_encode($username); ?>;
        var clientConfigs = <?php echo wp_json_encode($client_configs); ?>;
        var clientInstructions = <?php echo wp_json_encode($client_instructions); ?>;
        var clientHelpUrls = <?php echo wp_json_encode($client_help_urls); ?>;
        var mcpName = <?php echo wp_json_encode($mcp_name); ?>;
        var pwSlot = '__NIBWP_PW_SLOT__';
        var nameSlot = '__NIBWP_MCP_NAME__';
        var generatedPassword = '';

        // Build progress dots
        for (var i = 0; i < totalSteps; i++) {
            var dot = document.createElement('span');
            dot.className = 'nw-onb-dot';
            dot.dataset.idx = i;
            progressEl.appendChild(dot);
        }
        var dots = progressEl.querySelectorAll('.nw-onb-dot');

        function show(idx) {
            current = Math.max(0, Math.min(totalSteps - 1, idx));
            for (var i = 0; i < steps.length; i++) {
                steps[i].hidden = (i !== current);
            }
            for (var j = 0; j < dots.length; j++) {
                dots[j].classList.toggle('is-active', j === current);
                dots[j].classList.toggle('is-done', j < current);
            }
            backBtn.disabled = (current === 0);
            nextBtn.textContent = (current === totalSteps - 1) ? doneLabel : nextLabel;
            countEl.textContent = (current + 1) + ' / ' + totalSteps;
        }

        function open() {
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('nw-onb-locked');
            show(0);
        }
        function close() {
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('nw-onb-locked');
        }

        trigger.addEventListener('click', open);
        closeBtn.addEventListener('click', close);
        backBtn.addEventListener('click', function () { show(current - 1); });
        nextBtn.addEventListener('click', function () {
            if (current === totalSteps - 1) { close(); return; }
            show(current + 1);
        });

        // ESC key closes
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('is-open')) close();
        });

        // --- Step 1: Enable AI Abilities inline ---
        var enableBtn = document.getElementById('nw-onb-enable-btn');
        var enableStatus = document.getElementById('nw-onb-enable-status');
        if (enableBtn && enableStatus) {
            enableBtn.addEventListener('click', function () {
                enableBtn.disabled = true;
                enableBtn.textContent = <?php echo wp_json_encode(__('Enabling…', 'nibwp')); ?>;
                fetch(enableEndpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': restNonce },
                    body: JSON.stringify({ enable: true }),
                })
                    .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); })
                    .then(function (res) {
                        if (res.ok && res.body && res.body.enabled) {
                            enableStatus.className = 'nw-onb-status nw-onb-status--ok';
                            enableStatus.textContent = <?php echo wp_json_encode(__('✓ AI Abilities are now enabled.', 'nibwp')); ?>;
                            enableBtn.hidden = true;
                        } else {
                            enableStatus.className = 'nw-onb-status nw-onb-status--warn';
                            enableStatus.textContent = (res.body && res.body.message) || <?php echo wp_json_encode(__('Could not enable. Try the Connect page manually.', 'nibwp')); ?>;
                            enableBtn.disabled = false;
                            enableBtn.textContent = <?php echo wp_json_encode(__('Try again', 'nibwp')); ?>;
                        }
                    })
                    .catch(function (err) {
                        enableStatus.className = 'nw-onb-status nw-onb-status--warn';
                        enableStatus.textContent = <?php /* translators: %s: error message */ echo wp_json_encode(__('Network error: %s', 'nibwp')); ?>.replace('%s', err && err.message ? err.message : err);
                        enableBtn.disabled = false;
                        enableBtn.textContent = <?php echo wp_json_encode(__('Try again', 'nibwp')); ?>;
                    });
            });
        }

        // --- Step 2: Generate App Password inline ---
        var passBtn = document.getElementById('nw-onb-pass-btn');
        var passResult = document.getElementById('nw-onb-pass-result');
        var passDisplay = document.getElementById('nw-onb-pass-display');
        var passCopyBtn = document.getElementById('nw-onb-pass-copy');
        if (passBtn && passResult) {
            passBtn.addEventListener('click', function () {
                passBtn.disabled = true;
                passBtn.textContent = <?php echo wp_json_encode(__('Generating…', 'nibwp')); ?>;
                passResult.hidden = false;
                passResult.className = 'nw-onb-test-result is-loading';
                passResult.textContent = <?php echo wp_json_encode(__('Creating application password…', 'nibwp')); ?>;

                var appName = 'NIBWP Onboarder ' + new Date().toISOString().slice(0, 16).replace('T', ' ');
                fetch(appPassEndpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': restNonce },
                    body: JSON.stringify({ name: appName }),
                })
                    .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); })
                    .then(function (res) {
                        if (res.ok && res.body && res.body.password) {
                            generatedPassword = res.body.password;
                            passResult.className = 'nw-onb-test-result is-success';
                            var saveMsg = <?php echo wp_json_encode(__('Password created. SAVE IT NOW — it cannot be shown again.', 'nibwp')); ?>;
                            var copyLabel = <?php echo wp_json_encode(__('Copy', 'nibwp')); ?>;
                            passResult.innerHTML = '<strong>✓ ' + saveMsg + '</strong>'
                                + '<div class="nw-onb-pass-box">'
                                +   '<code id="nw-onb-pass-value">' + generatedPassword + '</code>'
                                +   '<button type="button" class="nw-onb-copy-btn" data-copy="nw-onb-pass-value" id="nw-onb-pass-copy-inline">'
                                +     '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-inline-end:4px;"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>'
                                +     copyLabel
                                +   '</button>'
                                + '</div>'
                                + '<p class="nw-onb-save-hint">⚠ ' + <?php echo wp_json_encode(__('Store this in your password manager before continuing. WordPress will not show it again.', 'nibwp')); ?> + '</p>';
                            passBtn.textContent = <?php echo wp_json_encode(__('Generate another', 'nibwp')); ?>;
                            passBtn.disabled = false;
                            // Wire the inline copy button (created above)
                            var inlineCopy = document.getElementById('nw-onb-pass-copy-inline');
                            if (inlineCopy) {
                                inlineCopy.addEventListener('click', function () {
                                    if (navigator.clipboard) {
                                        navigator.clipboard.writeText(generatedPassword).then(function () { flashCopied(inlineCopy); });
                                    } else {
                                        var ta = document.createElement('textarea');
                                        ta.value = generatedPassword; document.body.appendChild(ta); ta.select();
                                        try { document.execCommand('copy'); flashCopied(inlineCopy); } catch (e) {}
                                        document.body.removeChild(ta);
                                    }
                                });
                            }
                            // Populate step 3 + refresh config snippet
                            if (passDisplay) { passDisplay.textContent = generatedPassword; }
                            if (passCopyBtn) { passCopyBtn.disabled = false; }
                            if (typeof refreshClientConfig === 'function') { refreshClientConfig(); }
                        } else {
                            passResult.className = 'nw-onb-test-result is-error';
                            passResult.innerHTML = '<strong>✗ ' + <?php echo wp_json_encode(__('Failed.', 'nibwp')); ?> + '</strong> ' + ((res.body && res.body.message) || <?php echo wp_json_encode(__('Could not create application password.', 'nibwp')); ?>);
                            passBtn.disabled = false;
                            passBtn.textContent = <?php echo wp_json_encode(__('Try again', 'nibwp')); ?>;
                        }
                    })
                    .catch(function (err) {
                        passResult.className = 'nw-onb-test-result is-error';
                        passResult.textContent = <?php /* translators: %s: error message */ echo wp_json_encode(__('Network error: %s', 'nibwp')); ?>.replace('%s', err && err.message ? err.message : err);
                        passBtn.disabled = false;
                        passBtn.textContent = <?php echo wp_json_encode(__('Try again', 'nibwp')); ?>;
                    });
            });
        }

        // --- Step 3: Copy buttons (URL/user/password + full JSON config) ---
        function flashCopied(btn) {
            var orig = btn.textContent;
            btn.textContent = <?php echo wp_json_encode(__('Copied!', 'nibwp')); ?>;
            setTimeout(function () { btn.textContent = orig; }, 1200);
        }
        var copyBtns = modal.querySelectorAll('.nw-onb-copy-btn');
        for (var c = 0; c < copyBtns.length; c++) {
            copyBtns[c].addEventListener('click', function (e) {
                var btn = e.currentTarget;
                if (btn.disabled) return;
                var target = document.getElementById(btn.dataset.copy);
                if (!target) return;
                var text = target.textContent;
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(text).then(function () { flashCopied(btn); });
                } else {
                    var ta = document.createElement('textarea');
                    ta.value = text; document.body.appendChild(ta); ta.select();
                    try { document.execCommand('copy'); flashCopied(btn); } catch (e) {}
                    document.body.removeChild(ta);
                }
            });
        }
        // --- Step 3: client selector → generates per-client config ---
        var clientSelect = document.getElementById('nw-onb-client-select');
        var configCodeEl = document.getElementById('nw-onb-config-code');
        var configHintEl = document.getElementById('nw-onb-config-hint');
        var configPathsEl = document.getElementById('nw-onb-paths');
        var configCopyBtn = document.getElementById('nw-onb-config-copy-btn');
        var passWarn = document.getElementById('nw-onb-pass-warn');

        function renderConfigSnippet(rawCode) {
            var pw = generatedPassword || 'YOUR-APP-PASSWORD';
            return String(rawCode).split(pwSlot).join(pw).split(nameSlot).join(mcpName);
        }

        var howtoEl = document.getElementById('nw-onb-howto');

        function refreshClientConfig() {
            if (!clientSelect || !configCodeEl) return;
            var key = clientSelect.value;
            var cfg = clientConfigs[key];
            if (!cfg) { configCodeEl.textContent = ''; return; }
            configCodeEl.textContent = renderConfigSnippet(cfg.code);
            if (configHintEl) {
                configHintEl.textContent = cfg.isShell
                    ? <?php echo wp_json_encode(__('Terminal command', 'nibwp')); ?>
                    : <?php echo wp_json_encode(__('Config snippet', 'nibwp')); ?>;
            }
            if (howtoEl) {
                var steps = clientInstructions[key] || [];
                var helpUrl = clientHelpUrls[key] || '';
                var html = '<div class="nw-onb-howto__title">' + <?php echo wp_json_encode(__('How to add it:', 'nibwp')); ?> + '</div>';
                if (steps.length) {
                    html += '<ol class="nw-onb-howto__list">';
                    steps.forEach(function (s) { html += '<li>' + s + '</li>'; });
                    html += '</ol>';
                } else {
                    html += '<p>' + <?php echo wp_json_encode(__('Open your client\'s MCP settings and paste the snippet above.', 'nibwp')); ?> + '</p>';
                }
                if (helpUrl) {
                    html += '<a class="nw-onb-howto__help" href="' + helpUrl + '" target="_blank" rel="noopener">'
                          + <?php echo wp_json_encode(__('Need help? Open official docs', 'nibwp')); ?> + ' <span class="nw-rtl-flip" aria-hidden="true">\u2192</span>'
                          + '</a>';
                }
                howtoEl.innerHTML = html;
            }
            if (passWarn) { passWarn.hidden = !!generatedPassword; }
        }

        if (clientSelect) {
            clientSelect.addEventListener('change', refreshClientConfig);
            refreshClientConfig();
        }
        if (configCopyBtn && configCodeEl) {
            configCopyBtn.addEventListener('click', function () {
                var text = configCodeEl.textContent;
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(text).then(function () { flashCopied(configCopyBtn); });
                } else {
                    var ta = document.createElement('textarea');
                    ta.value = text; document.body.appendChild(ta); ta.select();
                    try { document.execCommand('copy'); flashCopied(configCopyBtn); } catch (e) {}
                    document.body.removeChild(ta);
                }
            });
        }

        // Test connection (step 4)
        var testBtn = document.getElementById('nw-onb-test-btn');
        var testResult = document.getElementById('nw-onb-test-result');
        if (testBtn && testResult) {
            testBtn.addEventListener('click', function () {
                testBtn.disabled = true;
                testBtn.textContent = <?php echo wp_json_encode(__('Testing…', 'nibwp')); ?>;
                testResult.hidden = false;
                testResult.className = 'nw-onb-test-result is-loading';
                testResult.textContent = <?php echo wp_json_encode(__('Checking MCP endpoint…', 'nibwp')); ?>;

                fetch(restRoot, { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        var routes = data && data.routes ? data.routes : {};
                        var hit = Object.keys(routes).some(function (k) {
                            return k === '/mcp/nibwp' || k.indexOf('/mcp/nibwp') === 0;
                        });
                        if (hit) {
                            testResult.className = 'nw-onb-test-result is-success';
                            testResult.innerHTML = '<strong>✓ ' + <?php echo wp_json_encode(__('Success!', 'nibwp')); ?> + '</strong> ' + <?php echo wp_json_encode(__('MCP endpoint is live at /mcp/nibwp.', 'nibwp')); ?>;
                        } else {
                            testResult.className = 'nw-onb-test-result is-error';
                            testResult.innerHTML = '<strong>✗ ' + <?php echo wp_json_encode(__('Not found.', 'nibwp')); ?> + '</strong> ' + <?php echo wp_json_encode(__('Enable AI Abilities on the Connect page first.', 'nibwp')); ?>;
                        }
                    })
                    .catch(function (err) {
                        testResult.className = 'nw-onb-test-result is-error';
                        testResult.textContent = <?php /* translators: %s: error message */ echo wp_json_encode(__('Network error: %s', 'nibwp')); ?>.replace('%s', err && err.message ? err.message : err);
                    })
                    .then(function () {
                        testBtn.disabled = false;
                        testBtn.textContent = <?php echo wp_json_encode(__('Run test again', 'nibwp')); ?>;
                    });
            });
        }
    })();
    </script>
    <?php
}

/**
 * Close the app shell opened by nibwp_render_admin_header().
 */
function nibwp_render_admin_footer(): void
{
    // Build search items — icon is an SVG string for each item
    $icon = static fn(string $d) => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $d . '</svg>';
    $search_items = [
        ['label' => __('Dashboard', 'nibwp'), 'desc' => __('Overview, stats, IDE configs', 'nibwp'), 'url' => admin_url('admin.php?page=nibwp-dashboard'), 'icon' => $icon('<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>')],
        ['label' => __('How To', 'nibwp'), 'desc' => __('Interactive walkthrough, IDE setup, sample prompts', 'nibwp'), 'url' => admin_url('admin.php?page=nibwp-how-to'), 'icon' => $icon('<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>')],
        ['label' => __('Skills Marketplace', 'nibwp'), 'desc' => __('Premium skill packs, license activation, image to component', 'nibwp'), 'url' => admin_url('admin.php?page=nibwp-skills'), 'icon' => $icon('<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>')],
        ['label' => __('Activate License', 'nibwp'), 'desc' => __('Enter license key, unlock Pro skills, FluentCart', 'nibwp'), 'url' => admin_url('admin.php?page=nibwp-skills'), 'icon' => $icon('<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/>')],
        ['label' => __('Connect', 'nibwp'), 'desc' => __('Enable MCP, passwords, client setup', 'nibwp'), 'url' => admin_url('admin.php?page=nibwp-connect'), 'icon' => $icon('<path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/>')],
        ['label' => __('Integrations', 'nibwp'), 'desc' => __('Activate page builders, custom fields, WooCommerce, EtchWP', 'nibwp'), 'url' => admin_url('admin.php?page=nibwp-integrations'), 'icon' => $icon('<circle cx="12" cy="12" r="3"/><path d="M12 2v4m0 12v4M2 12h4m12 0h4M4.9 4.9l2.8 2.8m8.6 8.6l2.8 2.8M4.9 19.1l2.8-2.8m8.6-8.6l2.8-2.8"/>')],
        ['label' => __('AI Abilities', 'nibwp'), 'desc' => __('All registered MCP tools list', 'nibwp'), 'url' => admin_url('admin.php?page=nibwp'), 'icon' => $icon('<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>')],
        ['label' => __('Jobs', 'nibwp'), 'desc' => __('Outcome jobs, run now or schedule, approvals inbox, plain-English reports', 'nibwp'), 'url' => admin_url('admin.php?page=nibwp-jobs'), 'icon' => $icon('<rect x="3" y="6" width="18" height="14" rx="2"/><path d="M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2"/><path d="M3 12h18"/>')],
        ['label' => __('Memory', 'nibwp'), 'desc' => __('Cross-session AI memory entries, store recall', 'nibwp'), 'url' => admin_url('admin.php?page=nibwp-memory'), 'icon' => $icon('<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M8 2v20M16 2v20M4 12h16"/>')],
        ['label' => __('Audit Log', 'nibwp'), 'desc' => __('Tool call history stats debug', 'nibwp'), 'url' => admin_url('admin.php?page=nibwp-audit-log'), 'icon' => $icon('<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/>')],
        ['label' => __('Sandbox', 'nibwp'), 'desc' => __('AI-generated PHP files manage', 'nibwp'), 'url' => admin_url('admin.php?page=nibwp-sandbox'), 'icon' => $icon('<rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/>')],
        ['label' => __('Settings', 'nibwp'), 'desc' => __('Rate limits security content safety audit', 'nibwp'), 'url' => admin_url('admin.php?page=nibwp-settings'), 'icon' => $icon('<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/>')],
        ['label' => __('Enable MCP', 'nibwp'), 'desc' => __('Turn on AI Abilities activate', 'nibwp'), 'url' => admin_url('admin.php?page=nibwp-connect'), 'icon' => $icon('<path d="M18.36 6.64a9 9 0 11-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/>')],
        ['label' => __('Generate App Password', 'nibwp'), 'desc' => __('Create credentials for AI clients authentication', 'nibwp'), 'url' => admin_url('admin.php?page=nibwp-connect'), 'icon' => $icon('<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/>')],
        ['label' => __('WordPress Plugins', 'nibwp'), 'desc' => __('Manage installed plugins activate deactivate', 'nibwp'), 'url' => admin_url('plugins.php'), 'icon' => $icon('<path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>')],
        ['label' => __('WP Dashboard', 'nibwp'), 'desc' => __('Return to WordPress admin home', 'nibwp'), 'url' => admin_url(), 'icon' => $icon('<path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>')],
    ];
    ?>
            </div><!-- .nw-content -->
        </div><!-- .nw-main -->
    </div><!-- .nw-app -->

    <!-- Command Palette -->
    <div class="nw-palette" id="nw-palette" role="dialog" aria-hidden="true">
        <div class="nw-palette__backdrop" id="nw-palette-backdrop"></div>
        <div class="nw-palette__panel">
            <div class="nw-palette__input-wrap">
                <svg width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="5"/><path d="M12 12l4 4"/></svg>
                <input type="text" class="nw-palette__input" id="nw-palette-input" placeholder="<?php esc_attr_e('Search pages, actions, settings...', 'nibwp'); ?>" autocomplete="off">
            </div>
            <div class="nw-palette__body" id="nw-palette-body">
                <?php foreach ($search_items as $item): ?>
                    <a class="nw-palette__item" href="<?php echo esc_url($item['url']); ?>" data-label="<?php echo esc_attr(strtolower($item['label'])); ?>" data-desc="<?php echo esc_attr(strtolower($item['desc'])); ?>">
                        <div class="nw-palette__item-icon"><?php echo $item['icon']; ?></div>
                        <div class="nw-palette__item-text">
                            <div class="nw-palette__item-label"><?php echo esc_html($item['label']); ?></div>
                            <div class="nw-palette__item-desc"><?php echo esc_html($item['desc']); ?></div>
                        </div>
                    </a>
                <?php endforeach; ?>
                <div class="nw-palette__empty" hidden><?php esc_html_e('No results found.', 'nibwp'); ?></div>
            </div>
        </div>
    </div>

    <script>
    (function(){
        /* Dark mode */
        var STORAGE='nibwpTheme',stored=null;
        try{stored=localStorage.getItem(STORAGE)}catch(e){}
        if(stored==='dark'){document.documentElement.classList.add('nw-dark');document.body.classList.add('nw-dark')}
        var btn=document.getElementById('nw-theme-toggle');
        if(btn)btn.addEventListener('click',function(){
            var d=!document.body.classList.contains('nw-dark');
            document.body.classList.toggle('nw-dark',d);
            document.documentElement.classList.toggle('nw-dark',d);
            try{localStorage.setItem(STORAGE,d?'dark':'light')}catch(e){}
        });

        /* Mobile sidebar */
        var mb=document.getElementById('nw-menu-toggle');
        if(mb)mb.addEventListener('click',function(){
            var s=document.getElementById('nw-sidebar');
            if(s)s.classList.toggle('is-open');
        });

        /* Command Palette */
        var palette=document.getElementById('nw-palette');
        var input=document.getElementById('nw-palette-input');
        var body=document.getElementById('nw-palette-body');
        var items=body?body.querySelectorAll('.nw-palette__item'):[];
        var empty=body?body.querySelector('.nw-palette__empty'):null;

        function openPalette(){
            palette.classList.add('is-open');
            palette.setAttribute('aria-hidden','false');
            setTimeout(function(){input.value='';input.focus();filter('')},10);
        }
        function closePalette(){
            palette.classList.remove('is-open');
            palette.setAttribute('aria-hidden','true');
        }
        /* Fuzzy character match: chars of query appear in order in target */
        function fuzzyMatch(query, target){
            if(target.indexOf(query) !== -1) return true; /* substring = match */
            var qi = 0;
            for(var ti = 0; ti < target.length && qi < query.length; ti++){
                if(target.charAt(ti) === query.charAt(qi)) qi++;
            }
            return qi === query.length;
        }
        function filter(q){
            q = (q || '').trim().toLowerCase();
            var any = false;
            var scored = [];
            items.forEach(function(el){
                if(!q){ el.hidden = false; any = true; return; }
                var label = (el.getAttribute('data-label') || '').toLowerCase();
                var desc = (el.getAttribute('data-desc') || '').toLowerCase();
                var hay = label + ' ' + desc;
                var words = q.split(/\s+/).filter(Boolean);
                /* Each word must fuzzy-match somewhere in label+desc */
                var allMatch = words.every(function(w){ return fuzzyMatch(w, hay); });
                if(allMatch){
                    var score = 0;
                    if(label === q) score += 1000;
                    else if(label.indexOf(q) === 0) score += 500;
                    else if(label.indexOf(q) !== -1) score += 200;
                    else if(hay.indexOf(q) !== -1) score += 100;
                    words.forEach(function(w){
                        if(label.indexOf(w) !== -1) score += 50;
                        else if(desc.indexOf(w) !== -1) score += 10;
                    });
                    el.hidden = false;
                    any = true;
                    scored.push({el: el, score: score});
                } else {
                    el.hidden = true;
                }
            });
            /* Sort visible items by score (highest first) */
            if(scored.length > 0 && q){
                scored.sort(function(a, b){ return b.score - a.score; });
                scored.forEach(function(s){ body.appendChild(s.el); });
                items.forEach(function(el){ el.classList.remove('is-active'); });
                scored[0].el.classList.add('is-active');
            }
            if(empty) empty.hidden = any;
        }

        /* Trigger */
        var trigger=document.getElementById('nw-search-trigger');
        if(trigger)trigger.addEventListener('click',function(e){e.preventDefault();openPalette()});

        /* Backdrop close */
        var bd=document.getElementById('nw-palette-backdrop');
        if(bd)bd.addEventListener('click',closePalette);

        /* Keyboard: Cmd+K / Ctrl+K, Escape, arrows, enter */
        document.addEventListener('keydown',function(e){
            var isMac=/Mac|iPod|iPhone|iPad/.test(navigator.platform);
            var mod=isMac?e.metaKey:e.ctrlKey;
            if(mod&&e.key&&e.key.toLowerCase()==='k'){
                e.preventDefault();
                palette.classList.contains('is-open')?closePalette():openPalette();
            }
            if(palette.classList.contains('is-open')){
                if(e.key==='Escape'){e.preventDefault();closePalette()}
                if(e.key==='ArrowDown'||e.key==='ArrowUp'){
                    e.preventDefault();
                    var visible=Array.from(items).filter(function(el){return!el.hidden});
                    var idx=visible.findIndex(function(el){return el.classList.contains('is-active')});
                    visible.forEach(function(el){el.classList.remove('is-active')});
                    if(e.key==='ArrowDown')idx=idx<visible.length-1?idx+1:0;
                    else idx=idx>0?idx-1:visible.length-1;
                    if(visible[idx]){visible[idx].classList.add('is-active');visible[idx].scrollIntoView({block:'nearest'})}
                }
                if(e.key==='Enter'){
                    var active=body.querySelector('.nw-palette__item.is-active:not([hidden])');
                    if(active){e.preventDefault();window.location.href=active.href}
                }
            }
        });

        /* Filter on input */
        if(input)input.addEventListener('input',function(){filter(this.value)});

        /* Click item → navigate immediately */
        items.forEach(function(el){
            el.addEventListener('click',function(e){
                e.preventDefault();
                closePalette();
                window.location.href=el.href;
            });
        });
    })();
    </script>

    <?php
    // ── Sticky Help Button ──
    $current_page = $_GET['page'] ?? '';
    // How long a step or an article takes, as shown beside it.
    $nw_min = static function (int $n): string {
        /* translators: %d: number of minutes */
        return sprintf(_n('%d min', '%d min', $n, 'nibwp'), $n);
    };
    $nw_sec = static function (int $n): string {
        /* translators: %d: number of seconds */
        return sprintf(_n('%d sec', '%d sec', $n, 'nibwp'), $n);
    };
    $help = match ($current_page) {
        'nibwp-dashboard' => [
            'title' => __('Reading the dashboard', 'nibwp'),
            'desc' => __('Stats, IDE configs, and quick access to all features.', 'nibwp'),
            'steps' => [
                ['title' => __('Enable MCP in Connect', 'nibwp'), 'desc' => __('Turn on AI Abilities to activate the MCP server.', 'nibwp'), 'time' => $nw_min(1)],
                ['title' => __('Generate an App Password', 'nibwp'), 'desc' => __('Create credentials for your AI client to authenticate.', 'nibwp'), 'time' => $nw_min(1)],
                ['title' => __('Copy IDE config snippet', 'nibwp'), 'desc' => __('Select your client tab and paste the config.', 'nibwp'), 'time' => $nw_min(2)],
            ],
            'articles' => [
                ['title' => __('Connecting Claude Desktop', 'nibwp'), 'url' => 'https://www.nibwp.com/docs/claude-desktop', 'time' => $nw_min(3)],
                ['title' => __('Connecting Cursor / VS Code', 'nibwp'), 'url' => 'https://www.nibwp.com/docs/cursor', 'time' => $nw_min(2)],
            ],
            'related' => [
                ['label' => __('Quick start', 'nibwp'), 'sub' => __('5-minute setup', 'nibwp'), 'url' => 'https://www.nibwp.com/docs/getting-started', 'icon' => '<path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/>'],
                ['label' => __('Glossary', 'nibwp'), 'sub' => __('Every term', 'nibwp'), 'url' => 'https://www.nibwp.com/docs/glossary', 'icon' => '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/>'],
            ],
        ],
        'nibwp-connect' => [
            'title' => __('Setting up MCP connection', 'nibwp'),
            'desc' => __('Enable MCP, create credentials, and connect your AI client.', 'nibwp'),
            'steps' => [
                ['title' => __('Toggle AI Abilities ON', 'nibwp'), 'desc' => __('Activates the MCP endpoint on this site.', 'nibwp'), 'time' => $nw_sec(30)],
                ['title' => __('Generate App Password', 'nibwp'), 'desc' => __('Not your login password — a separate credential for AI.', 'nibwp'), 'time' => $nw_sec(30)],
                ['title' => __('Paste config or prompt', 'nibwp'), 'desc' => __('Copy the JSON config or paste the prompt into your AI chat.', 'nibwp'), 'time' => $nw_min(1)],
            ],
            'articles' => [
                ['title' => __('Supported AI clients list', 'nibwp'), 'url' => 'https://www.nibwp.com/docs/clients', 'time' => $nw_min(2)],
                ['title' => __('Troubleshooting connections', 'nibwp'), 'url' => 'https://www.nibwp.com/docs/troubleshooting', 'time' => $nw_min(4)],
            ],
            'related' => [],
        ],
        'nibwp-integrations' => [
            'title' => __('Managing integrations', 'nibwp'),
            'desc' => __('Activate plugin integrations to expose their AI abilities.', 'nibwp'),
            'steps' => [
                ['title' => __('Install the WordPress plugin', 'nibwp'), 'desc' => __('The integration detects when the plugin is active.', 'nibwp')],
                ['title' => __('Toggle the integration ON', 'nibwp'), 'desc' => __('This loads the MCP tools for that plugin.', 'nibwp')],
                ['title' => __('Verify in AI Abilities', 'nibwp'), 'desc' => __('Check the AI Abilities page to confirm tools are registered.', 'nibwp')],
            ],
            'articles' => [
                ['title' => __('All available integrations', 'nibwp'), 'url' => 'https://www.nibwp.com/docs/integrations', 'time' => $nw_min(5)],
            ],
            'related' => [],
        ],
        'nibwp-figma' => [
            'title' => __('Figma → WordPress', 'nibwp'),
            'desc' => __('Pull designs into a local library, then ask the AI agent to build them.', 'nibwp'),
            'steps' => [
                ['title' => __('Connect Figma', 'nibwp'), 'desc' => __('Connection tab → paste a personal access token (File content: read-only). OAuth and Dev Mode MCP are alternatives — pick one.', 'nibwp'), 'time' => $nw_min(2)],
                ['title' => __('Pull a frame', 'nibwp'), 'desc' => __('In Figma, select a frame and copy its link. Paste it in the Pull tab. NibWP caches a 2× image plus the color palette and type ramp.', 'nibwp'), 'time' => $nw_min(1)],
                ['title' => __('Pull in bulk (optional)', 'nibwp'), 'desc' => __('A file link pulls every frame in it; a team or project link walks every file. Progress is live and you can stop between frames.', 'nibwp')],
                ['title' => __('Copy the handle', 'nibwp'), 'desc' => __('Each frame gets a name like @figma/hero-section. Click it in the Library to copy.', 'nibwp')],
                ['title' => __('Ask the agent to build it', 'nibwp'), 'desc' => __('Say what you want in plain English. NibWP picks the builder your site runs and saves a draft — it never overwrites a live page.', 'nibwp'), 'time' => $nw_min(2)],
            ],
            'articles' => [
                ['title' => __('Figma integration overview', 'nibwp'), 'url' => 'https://www.nibwp.com/docs/figma', 'time' => $nw_min(4)],
                ['title' => __('Connecting your Figma account', 'nibwp'), 'url' => 'https://www.nibwp.com/docs/figma-connect', 'time' => $nw_min(2)],
                ['title' => __('Pulling frames into the library', 'nibwp'), 'url' => 'https://www.nibwp.com/docs/figma-pull', 'time' => $nw_min(3)],
                ['title' => __('Converting a design to a page', 'nibwp'), 'url' => 'https://www.nibwp.com/docs/figma-convert', 'time' => $nw_min(4)],
                ['title' => __('Figma rate limits explained', 'nibwp'), 'url' => 'https://www.nibwp.com/docs/figma-rate-limits', 'time' => $nw_min(2)],
            ],
            'related' => [],
        ],
        'nibwp' => [
            'title' => __('Understanding AI Abilities', 'nibwp'),
            'desc' => __('Every tool your AI agents can call via MCP.', 'nibwp'),
            'steps' => [
                ['title' => __('Filter by type', 'nibwp'), 'desc' => __('Use Read-Only, Write, or Destructive filters to find safe vs dangerous tools.', 'nibwp')],
                ['title' => __('Disable risky tools', 'nibwp'), 'desc' => __('Go to Settings to prevent specific tools from being exposed.', 'nibwp')],
                ['title' => __('Export the list', 'nibwp'), 'desc' => __('Click Export JSON to download the full tool inventory.', 'nibwp')],
            ],
            'articles' => [
                ['title' => __('Tool permission model', 'nibwp'), 'url' => 'https://www.nibwp.com/docs/tools', 'time' => $nw_min(3)],
            ],
            'related' => [],
        ],
        'nibwp-sandbox' => [
            'title' => __('Using the Sandbox', 'nibwp'),
            'desc' => __('AI-generated PHP files that auto-load on every request.', 'nibwp'),
            'steps' => [
                ['title' => __('View before enabling', 'nibwp'), 'desc' => __('Click View to inspect code before it runs.', 'nibwp')],
                ['title' => __('Disable to troubleshoot', 'nibwp'), 'desc' => __('Adds .disabled extension — file stays but doesn\'t load.', 'nibwp')],
                ['title' => __('Safe mode auto-activates', 'nibwp'), 'desc' => __('If a file causes a fatal error, all files are suspended.', 'nibwp')],
            ],
            'articles' => [],
            'related' => [],
        ],
        'nibwp-settings' => [
            'title' => __('Configuring settings', 'nibwp'),
            'desc' => __('Security, content safety, and audit configuration.', 'nibwp'),
            'steps' => [
                ['title' => __('Set rate limits', 'nibwp'), 'desc' => __('Prevents runaway AI agents. 30-60 calls/min recommended.', 'nibwp')],
                ['title' => __('Enable Force Draft', 'nibwp'), 'desc' => __('All AI-created posts become drafts for review.', 'nibwp')],
                ['title' => __('Configure audit retention', 'nibwp'), 'desc' => __('How long to keep tool call logs. Default: 30 days.', 'nibwp')],
            ],
            'articles' => [],
            'related' => [],
        ],
        'nibwp-skills' => [
            'title' => __('Skill packs', 'nibwp'),
            'desc' => __('Premium AI skill packs — image → component, HTML → builder, SEO, course builders, and more.', 'nibwp'),
            'steps' => [
                ['title' => __('Activate a license', 'nibwp'), 'desc' => __('Paste your key to unlock a skill — or the Bundle for every skill, current and future.', 'nibwp')],
                ['title' => __('Toggle a skill ON', 'nibwp'), 'desc' => __('An enabled, unlocked skill exposes its MCP abilities + playbook to your AI.', 'nibwp')],
                ['title' => __('Trigger it in chat', 'nibwp'), 'desc' => __('Say e.g. “convert this to Etch” — the skill routes through its validated preflight → build → check pipeline.', 'nibwp')],
            ],
            'articles' => [
                ['title' => __('How skills work', 'nibwp'), 'url' => 'https://www.nibwp.com/docs/skills', 'time' => $nw_min(3)],
            ],
            'related' => [],
        ],
        'nibwp-memory' => [
            'title' => __('AI Memory store', 'nibwp'),
            'desc' => __('A namespaced key/value store your AI reads and writes to remember things across sessions.', 'nibwp'),
            'steps' => [
                ['title' => __('Persists across sessions', 'nibwp'), 'desc' => __('The AI saves facts (project notes, preferences, IDs) here and recalls them in later chats.', 'nibwp')],
                ['title' => __('Namespaced keys', 'nibwp'), 'desc' => __('Group entries by namespace so different projects or topics stay separate.', 'nibwp')],
                ['title' => __('Review + clear here', 'nibwp'), 'desc' => __('Inspect, edit, or delete what the AI stored — it reads/writes via the memory abilities.', 'nibwp')],
            ],
            'articles' => [
                ['title' => __('Using AI memory', 'nibwp'), 'url' => 'https://www.nibwp.com/docs/memory', 'time' => $nw_min(2)],
            ],
            'related' => [],
        ],
        'nibwp-workflows' => [
            'title' => __('Working with Workflows', 'nibwp'),
            'desc' => __('Saved operating playbooks — your rules, process, and standards — that your AI follows on this site.', 'nibwp'),
            'steps' => [
                ['title' => __('Auto-routing (default)', 'nibwp'), 'desc' => __('Your AI sees every workflow plus its “when to use” and loads the matching one automatically — no toggling. Write a clear, specific “when to use”.', 'nibwp')],
                ['title' => __('Pin = always-on', 'nibwp'), 'desc' => __('A pinned workflow is injected on every request and governs all work here. Pin 0–1 (your house rules); leave the rest unpinned so the AI picks the right one per task.', 'nibwp')],
                ['title' => __('Copy Prompt = force it', 'nibwp'), 'desc' => __('Run any workflow on demand: copy its prompt and paste it to your AI.', 'nibwp')],
                ['title' => __('Capture a good session', 'nibwp'), 'desc' => __('After a result you like, tell your AI “save this as a workflow” — it writes the playbook and you can refine it here.', 'nibwp')],
            ],
            'articles' => [
                ['title' => __('Workflows: pin vs auto-route', 'nibwp'), 'url' => 'https://www.nibwp.com/docs/workflows', 'time' => $nw_min(3)],
                ['title' => __('Writing a good “when to use”', 'nibwp'), 'url' => 'https://www.nibwp.com/docs/workflows-routing', 'time' => $nw_min(2)],
            ],
            'related' => [],
        ],
        'nibwp-user-access' => [
            'title' => __('Who sees NIBWP', 'nibwp'),
            'desc' => __('Decide, per administrator, which NIBWP screens appear in their menu.', 'nibwp'),
            'steps' => [
                ['title' => __('It hides menus, it does not lock pages', 'nibwp'), 'desc' => __('A hidden screen is still reachable by anyone holding its direct URL. That is the point — the client gets a clean admin, you keep a link that always works.', 'nibwp')],
                ['title' => __('Tick what each administrator sees', 'nibwp'), 'desc' => __('Every other administrator on the site gets their own row. Untick a screen and it leaves their menu on their next page load.', 'nibwp'), 'time' => $nw_min(1)],
                ['title' => __('Three screens stay with you', 'nibwp'), 'desc' => __('Settings, License and this page are never handed over — leaving a route back in would let someone undo the whole configuration.', 'nibwp')],
                ['title' => __('It fails open, on purpose', 'nibwp'), 'desc' => __('If your account goes, the license lapses, or the configuration resolves to nothing, every menu comes back. Nobody gets locked out of a site they own.', 'nibwp')],
            ],
            'articles' => [
                ['title' => __('Client-friendly access for agencies', 'nibwp'), 'url' => 'https://www.nibwp.com/docs/user-access', 'time' => $nw_min(3)],
            ],
            'related' => [],
        ],
        'nibwp-status' => [
            'title' => __('Reading Status', 'nibwp'),
            'desc' => __('Every check this site can answer about why a connection works, or does not.', 'nibwp'),
            'steps' => [
                ['title' => __('Read the verdict, not the list', 'nibwp'), 'desc' => __('The banner at the top says whether anything is actually stopping a connection. Warnings below it are worth fixing, but they are not what is blocking you.', 'nibwp')],
                ['title' => __('Fix the first failure', 'nibwp'), 'desc' => __('Failures are ordered by what breaks a connection soonest. Each names the one thing to change rather than describing the subsystem.', 'nibwp')],
                ['title' => __('Site moved? Re-enable it', 'nibwp'), 'desc' => __('Abilities are pinned to the address they were switched on for, so a clone cannot inherit them. A host that hands out new URLs trips this routinely — the check offers the button.', 'nibwp')],
                ['title' => __('Copy the report for support', 'nibwp'), 'desc' => __('The report carries versions, environment and check results — never a password or a token.', 'nibwp'), 'time' => $nw_sec(30)],
            ],
            'articles' => [
                ['title' => __('Troubleshooting connections', 'nibwp'), 'url' => 'https://www.nibwp.com/docs/troubleshooting', 'time' => $nw_min(4)],
                ['title' => __('Why the REST API gets blocked', 'nibwp'), 'url' => 'https://www.nibwp.com/docs/rest-api', 'time' => $nw_min(3)],
            ],
            'related' => [],
        ],
        default => [
            'title' => __('Help', 'nibwp'),
            'desc' => __('Context-aware help for the current page.', 'nibwp'),
            'steps' => [],
            'articles' => [
                ['title' => __('NIBWP documentation', 'nibwp'), 'url' => 'https://www.nibwp.com/docs', 'time' => ''],
            ],
            'related' => [
                ['label' => __('Quick start', 'nibwp'), 'sub' => __('5-minute setup', 'nibwp'), 'url' => 'https://www.nibwp.com/docs/getting-started', 'icon' => '<path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/>'],
                ['label' => __('Glossary', 'nibwp'), 'sub' => __('Every term', 'nibwp'), 'url' => 'https://www.nibwp.com/docs/glossary', 'icon' => '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/>'],
            ],
        ],
    };
    ?>

    <!-- NIBWP own notices → slot above the panel title -->
    <script>
    /* Move NIBWP's own admin notices (saved / deleted / etc.) into a slot at the
       top of the panel, before the page title. Foreign notices (data-nibwp-foreign)
       are left for the suppression CSS. Runs once + watches for late notices. */
    (function () {
        var wrap = document.querySelector('.nibwp-wrap');
        if (!wrap) { return; }
        var slot = document.getElementById('nibwp-notices');
        if (!slot) {
            slot = document.createElement('div');
            slot.id = 'nibwp-notices';
            slot.className = 'nibwp-notices';
            wrap.insertBefore(slot, wrap.firstChild);
        }
        function collect() {
            wrap.querySelectorAll('.notice, .updated, .error').forEach(function (n) {
                if (n.hasAttribute('data-nibwp-foreign') || slot.contains(n)) { return; }
                slot.appendChild(n);
            });
        }
        collect();
        new MutationObserver(collect).observe(wrap, { childList: true, subtree: true });
    }());
    </script>

    <!-- Confirmation Modal (global) -->
    <div class="nw-confirm" id="nw-confirm">
        <div class="nw-confirm__backdrop"></div>
        <div class="nw-confirm__panel">
            <div class="nw-confirm__icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
            </div>
            <div class="nw-confirm__title"><?php esc_html_e('Are you sure?', 'nibwp'); ?></div>
            <div class="nw-confirm__msg" id="nw-confirm-msg"><?php esc_html_e('This action cannot be undone.', 'nibwp'); ?></div>
            <div class="nw-confirm__actions">
                <button type="button" class="button" id="nw-confirm-cancel"><?php esc_html_e('Cancel', 'nibwp'); ?></button>
                <a href="#" class="button nibwp-btn-danger" id="nw-confirm-ok" style="background:var(--nw-danger)!important;color:#fff!important;border-color:var(--nw-danger)!important;"><?php esc_html_e('Delete', 'nibwp'); ?></a>
            </div>
        </div>
    </div>
    <script>
    (function(){
        var modal=document.getElementById('nw-confirm');
        var msg=document.getElementById('nw-confirm-msg');
        var title=modal.querySelector('.nw-confirm__title');
        var ok=document.getElementById('nw-confirm-ok');
        var cancel=document.getElementById('nw-confirm-cancel');
        var backdrop=modal.querySelector('.nw-confirm__backdrop');
        var defaults={
            title: title ? title.textContent : '',
            confirmLabel: ok.textContent
        };
        var pending=null;   /* callback for the non-navigating form */
        var lastFocus=null;

        function close(){
            modal.classList.remove('is-open');
            pending=null;
            if(lastFocus && lastFocus.focus){ lastFocus.focus(); }
        }
        cancel.addEventListener('click', close);
        backdrop.addEventListener('click', close);
        document.addEventListener('keydown', function(e){ if(e.key==='Escape' && modal.classList.contains('is-open')) close(); });

        /* The OK control is a link so the original delete-by-URL flow still
           navigates. When a caller supplies a callback instead, the same button
           has to stay put and run it. */
        ok.addEventListener('click', function(e){
            if(!pending) return;          /* href mode — let the browser follow it */
            e.preventDefault();
            var run=pending;
            close();
            run();
        });

        /**
         * Ask the question in NibWP's own dialog rather than the browser's.
         *
         * Pass `url` to navigate on confirm, or `onConfirm` for anything that
         * happens in place — an AJAX delete has nowhere to navigate to, which
         * is why those had been falling back to window.confirm and looking like
         * a different product.
         */
        window.nibwpConfirm=function(opts){
            opts=opts||{};
            lastFocus=document.activeElement;
            if(title){ title.textContent=opts.title||defaults.title; }
            if(opts.message){ msg.innerHTML=opts.message; }
            ok.textContent=opts.confirmLabel||defaults.confirmLabel;
            if(opts.url){ ok.href=opts.url; pending=null; }
            else { ok.href='#'; pending=(typeof opts.onConfirm==='function')?opts.onConfirm:null; }
            modal.classList.add('is-open');
            ok.focus();
        };

        /* Hook all .nw-confirm-delete links */
        document.addEventListener('click', function(e){
            var btn = e.target.closest('.nw-confirm-delete');
            if(!btn) return;
            e.preventDefault();
            var name = btn.getAttribute('data-name') || '';
            window.nibwpConfirm({
                url: btn.getAttribute('data-url') || btn.href,
                message: <?php
                    /* translators: %s: name of the item being deleted, or "this item" */
                    echo wp_json_encode(__('This will permanently delete %s. This cannot be undone.', 'nibwp'));
                ?>.replace('%s', name ? '<code>' + name + '</code>' : <?php echo wp_json_encode(__('this item', 'nibwp')); ?>)
            });
        });
    })();
    </script>

    <!-- Help Tab (sticky right edge) -->
    <button type="button" class="nw-help-tab" id="nw-help-tab">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        <?php esc_html_e('Help', 'nibwp'); ?>
    </button>

    <!-- Help Drawer Backdrop -->
    <div class="nw-help-backdrop" id="nw-help-backdrop"></div>

    <?php
    // ── All topics for "Browse All" tab ──
    $all_topics = [
        'getting-started' => [
            'label' => __('Getting Started', 'nibwp'),
            'icon' => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
            'topics' => [
                ['title' => __('What is NIBWP?', 'nibwp'), 'desc' => __('AI-powered WordPress via MCP protocol', 'nibwp'), 'page' => 'nibwp-dashboard'],
                ['title' => __('5-minute quickstart', 'nibwp'), 'desc' => __('Enable MCP → password → connect client', 'nibwp'), 'page' => 'nibwp-connect'],
                ['title' => __('Supported AI clients', 'nibwp'), 'desc' => __('Claude, ChatGPT, Cursor, VS Code, more', 'nibwp'), 'page' => 'nibwp-dashboard'],
            ],
        ],
        'connecting' => [
            'label' => __('Connecting AI Clients', 'nibwp'),
            'icon' => '<path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/>',
            'topics' => [
                ['title' => __('Claude Desktop setup', 'nibwp'), 'desc' => __('Add MCP server to Claude config', 'nibwp'), 'page' => 'nibwp-connect'],
                ['title' => __('Cursor / VS Code', 'nibwp'), 'desc' => __('mcp.json configuration', 'nibwp'), 'page' => 'nibwp-connect'],
                ['title' => __('Generate App Password', 'nibwp'), 'desc' => __('WordPress credentials for AI', 'nibwp'), 'page' => 'nibwp-connect'],
                ['title' => __('Connection troubleshooting', 'nibwp'), 'desc' => __('Common errors and fixes', 'nibwp'), 'page' => 'nibwp-connect'],
            ],
        ],
        'integrations' => [
            'label' => __('Integrations', 'nibwp'),
            'icon' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v4m0 12v4M2 12h4m12 0h4M4.9 4.9l2.8 2.8m8.6 8.6l2.8 2.8M4.9 19.1l2.8-2.8m8.6-8.6l2.8-2.8"/>',
            'topics' => [
                ['title' => __('Activate a plugin integration', 'nibwp'), 'desc' => __('Toggle ON in Integrations page', 'nibwp'), 'page' => 'nibwp-integrations'],
                ['title' => __('Page builders', 'nibwp'), 'desc' => __('Elementor, Bricks, EtchWP, ACSS', 'nibwp'), 'page' => 'nibwp-integrations'],
                ['title' => __('E-commerce', 'nibwp'), 'desc' => __('WooCommerce, FluentCart, EDD', 'nibwp'), 'page' => 'nibwp-integrations'],
                ['title' => __('Custom fields', 'nibwp'), 'desc' => __('ACF, Meta Box, JetEngine, Pods', 'nibwp'), 'page' => 'nibwp-integrations'],
                ['title' => __('Forms & CRM', 'nibwp'), 'desc' => __('Gravity, WPForms, FluentCRM', 'nibwp'), 'page' => 'nibwp-integrations'],
                ['title' => __('LMS & memberships', 'nibwp'), 'desc' => __('LearnDash, LifterLMS, MemberPress', 'nibwp'), 'page' => 'nibwp-integrations'],
            ],
        ],
        'security' => [
            'label' => __('Security & Maintenance', 'nibwp'),
            'icon' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
            'topics' => [
                ['title' => __('Verify WordPress core', 'nibwp'), 'desc' => __('Detect modified or injected files', 'nibwp'), 'page' => 'nibwp'],
                ['title' => __('Scan for malware', 'nibwp'), 'desc' => __('20+ malware pattern detection', 'nibwp'), 'page' => 'nibwp'],
                ['title' => __('Database injection scan', 'nibwp'), 'desc' => __('Find injected scripts in posts/options', 'nibwp'), 'page' => 'nibwp'],
                ['title' => __('Audit user accounts', 'nibwp'), 'desc' => __('Detect rogue admin accounts', 'nibwp'), 'page' => 'nibwp'],
                ['title' => __('Site cleanup', 'nibwp'), 'desc' => __('Revisions, transients, spam, trash', 'nibwp'), 'page' => 'nibwp'],
                ['title' => __('File permissions check', 'nibwp'), 'desc' => __('Audit and fix 644/755/440', 'nibwp'), 'page' => 'nibwp'],
            ],
        ],
        'content' => [
            'label' => __('Content Workflow', 'nibwp'),
            'icon' => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
            'topics' => [
                ['title' => __('Schedule posts', 'nibwp'), 'desc' => __('Set future dates with timezone support', 'nibwp'), 'page' => 'nibwp'],
                ['title' => __('Bulk content import', 'nibwp'), 'desc' => __('Import from RSS, URLs, sitemaps', 'nibwp'), 'page' => 'nibwp'],
                ['title' => __('Content rewriter', 'nibwp'), 'desc' => __('Summarize, expand, bullet points', 'nibwp'), 'page' => 'nibwp'],
                ['title' => __('Editorial calendar', 'nibwp'), 'desc' => __('View scheduled posts by date', 'nibwp'), 'page' => 'nibwp'],
                ['title' => __('Draft manager', 'nibwp'), 'desc' => __('Priority and editorial notes', 'nibwp'), 'page' => 'nibwp'],
            ],
        ],
        'seo' => [
            'label' => __('SEO Tools', 'nibwp'),
            'icon' => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
            'topics' => [
                ['title' => __('Image SEO audit', 'nibwp'), 'desc' => __('Alt text, dimensions, lazy loading', 'nibwp'), 'page' => 'nibwp'],
                ['title' => __('Schema markup', 'nibwp'), 'desc' => __('JSON-LD for Article, Product, FAQ', 'nibwp'), 'page' => 'nibwp'],
                ['title' => __('Broken link checker', 'nibwp'), 'desc' => __('Scan posts for 404/410/500', 'nibwp'), 'page' => 'nibwp'],
                ['title' => __('Redirect manager', 'nibwp'), 'desc' => __('Create 301/302/307 redirects', 'nibwp'), 'page' => 'nibwp'],
                ['title' => __('Internal linking', 'nibwp'), 'desc' => __('AI-suggested link opportunities', 'nibwp'), 'page' => 'nibwp'],
            ],
        ],
        'memory' => [
            'label' => __('Memory & Audit', 'nibwp'),
            'icon' => '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M8 2v20M16 2v20M4 12h16"/>',
            'topics' => [
                ['title' => __('How AI Memory works', 'nibwp'), 'desc' => __('Cross-session context for agents', 'nibwp'), 'page' => 'nibwp-memory'],
                ['title' => __('Storing project conventions', 'nibwp'), 'desc' => __('Save coding style, palette, APIs', 'nibwp'), 'page' => 'nibwp-memory'],
                ['title' => __('Audit log explained', 'nibwp'), 'desc' => __('Every MCP tool call recorded', 'nibwp'), 'page' => 'nibwp-audit-log'],
                ['title' => __('Log retention policy', 'nibwp'), 'desc' => __('Configure cleanup schedule', 'nibwp'), 'page' => 'nibwp-settings'],
            ],
        ],
        'sandbox' => [
            'label' => __('Sandbox', 'nibwp'),
            'icon' => '<rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/>',
            'topics' => [
                ['title' => __('What is the sandbox?', 'nibwp'), 'desc' => __('AI-generated PHP that auto-loads', 'nibwp'), 'page' => 'nibwp-sandbox'],
                ['title' => __('Safe mode', 'nibwp'), 'desc' => __('Auto-suspend on fatal errors', 'nibwp'), 'page' => 'nibwp-sandbox'],
                ['title' => __('Disable vs delete', 'nibwp'), 'desc' => __('When to use each action', 'nibwp'), 'page' => 'nibwp-sandbox'],
            ],
        ],
        'settings' => [
            'label' => __('Settings & Safety', 'nibwp'),
            'icon' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 11-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06A1.65 1.65 0 004.6 15a1.65 1.65 0 00-1.51-1H3a2 2 0 110-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06A1.65 1.65 0 009 4.6a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06A1.65 1.65 0 0019.4 9c0 .85.5 1.6 1.51 1H21a2 2 0 110 4h-.09a1.65 1.65 0 00-1.51 1z"/>',
            'topics' => [
                ['title' => __('Rate limiting', 'nibwp'), 'desc' => __('Cap MCP calls per minute', 'nibwp'), 'page' => 'nibwp-settings'],
                ['title' => __('IP whitelist', 'nibwp'), 'desc' => __('Restrict MCP to specific IPs', 'nibwp'), 'page' => 'nibwp-settings'],
                ['title' => __('Force Draft', 'nibwp'), 'desc' => __('All AI posts become drafts', 'nibwp'), 'page' => 'nibwp-settings'],
                ['title' => __('Disable specific tools', 'nibwp'), 'desc' => __('Hide risky abilities from AI', 'nibwp'), 'page' => 'nibwp-settings'],
            ],
        ],
    ];
    ?>

    <!-- Help Drawer -->
    <div class="nw-help-drawer" id="nw-help-drawer">
        <div class="nw-help-drawer__head">
            <button type="button" class="nw-help-drawer__close" id="nw-help-close">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
            <div class="nw-help-drawer__badge">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <?php esc_html_e('HELP CENTER', 'nibwp'); ?>
            </div>
            <div class="nw-help-drawer__title"><?php esc_html_e('How to use NIBWP', 'nibwp'); ?></div>
            <div class="nw-help-drawer__desc"><?php esc_html_e('Step-by-step guides for every feature.', 'nibwp'); ?></div>

            <!-- Tabs -->
            <div class="nw-help-tabs" role="tablist">
                <button type="button" class="nw-help-tab-btn is-active" data-tab="current">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 1v6m0 6v6"/></svg>
                    <?php esc_html_e('This Page', 'nibwp'); ?>
                </button>
                <button type="button" class="nw-help-tab-btn" data-tab="browse">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                    <?php esc_html_e('All Topics', 'nibwp'); ?>
                </button>
                <button type="button" class="nw-help-tab-btn" data-tab="actions">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                    <?php esc_html_e('Quick Actions', 'nibwp'); ?>
                </button>
            </div>

            <!-- Search -->
            <div class="nw-help-search">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="search" id="nw-help-search-input" placeholder="<?php esc_attr_e('Search how-tos, topics, actions...', 'nibwp'); ?>" autocomplete="off">
            </div>
        </div>

        <div class="nw-help-drawer__body">

            <!-- TAB 1: Current Page -->
            <div class="nw-help-tab-panel is-active" data-panel="current">
                <div class="nw-help-current-header">
                    <div class="nw-help-section-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></svg>
                    </div>
                    <div>
                        <strong><?php echo esc_html($help['title']); ?></strong>
                        <span><?php echo esc_html($help['desc'] ?? ''); ?></span>
                    </div>
                </div>

                <?php if (!empty($help['steps'])): ?>
                    <div class="nw-help-section">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                        <?php esc_html_e('How to', 'nibwp'); ?>
                    </div>
                    <?php foreach ($help['steps'] as $i => $step): ?>
                        <div class="nw-help-step nw-searchable" data-search="<?php echo esc_attr(strtolower(($step['title'] ?? '') . ' ' . ($step['desc'] ?? ''))); ?>">
                            <div class="nw-help-step__num"><?php echo $i + 1; ?></div>
                            <div class="nw-help-step__text">
                                <strong><?php echo esc_html($step['title']); ?></strong>
                                <span><?php echo esc_html($step['desc'] ?? ''); ?></span>
                            </div>
                            <?php if (!empty($step['time'])): ?>
                                <span class="nw-help-step__time"><?php echo esc_html($step['time']); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if (!empty($help['articles'])): ?>
                    <div class="nw-help-section">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/></svg>
                        <?php esc_html_e('Docs articles', 'nibwp'); ?>
                    </div>
                    <?php foreach ($help['articles'] as $article): ?>
                        <a class="nw-help-article nw-searchable" data-search="<?php echo esc_attr(strtolower($article['title'])); ?>" href="<?php echo esc_url($article['url']); ?>" target="_blank" rel="noopener">
                            <?php echo esc_html($article['title']); ?>
                            <?php if (!empty($article['time'])): ?>
                                <span class="nw-help-article__time"><?php echo esc_html($article['time']); ?></span>
                            <?php endif; ?>
                            <span class="nw-help-article__arrow">↗</span>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- TAB 2: All Topics (Accordion) -->
            <div class="nw-help-tab-panel" data-panel="browse">
                <?php foreach ($all_topics as $cat_key => $cat): ?>
                    <details class="nw-help-accordion" <?php echo $cat_key === 'getting-started' ? 'open' : ''; ?>>
                        <summary class="nw-help-accordion__head">
                            <div class="nw-help-section-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><?php echo $cat['icon']; ?></svg>
                            </div>
                            <div class="nw-help-accordion__title">
                                <strong><?php echo esc_html($cat['label']); ?></strong>
                                <span><?php
                                    /* translators: %d: number of guides in this help topic */
                                    printf(esc_html(_n('%d guide', '%d guides', count($cat['topics']), 'nibwp')), count($cat['topics']));
                                ?></span>
                            </div>
                            <svg class="nw-help-accordion__chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                        </summary>
                        <div class="nw-help-accordion__body">
                            <?php foreach ($cat['topics'] as $topic): ?>
                                <a class="nw-help-topic nw-searchable" data-search="<?php echo esc_attr(strtolower($topic['title'] . ' ' . $topic['desc'])); ?>" href="<?php echo esc_url(admin_url('admin.php?page=' . $topic['page'])); ?>">
                                    <div class="nw-help-topic__dot"></div>
                                    <div class="nw-help-topic__text">
                                        <strong><?php echo esc_html($topic['title']); ?></strong>
                                        <span><?php echo esc_html($topic['desc']); ?></span>
                                    </div>
                                    <svg class="nw-help-topic__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>

            <!-- TAB 3: Quick Actions -->
            <div class="nw-help-tab-panel" data-panel="actions">
                <div class="nw-help-section">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                    <?php esc_html_e('Quick Actions', 'nibwp'); ?>
                </div>
                <a class="nw-help-action nw-searchable" data-search="<?php /* translators: search keywords for a help-drawer quick action, lowercase, space-separated */ echo esc_attr(__('enable mcp activate ai abilities', 'nibwp')); ?>" href="<?php echo esc_url(admin_url('admin.php?page=nibwp-connect')); ?>">
                    <div class="nw-help-action__icon" style="background:var(--nw-ok-soft); color:var(--nw-ok);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18.36 6.64a9 9 0 11-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
                    </div>
                    <div class="nw-help-action__text">
                        <strong><?php esc_html_e('Enable MCP Server', 'nibwp'); ?></strong>
                        <span><?php esc_html_e('Turn on AI Abilities for this site', 'nibwp'); ?></span>
                    </div>
                    <svg class="nw-help-topic__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </a>
                <a class="nw-help-action nw-searchable" data-search="<?php /* translators: search keywords for a help-drawer quick action, lowercase, space-separated */ echo esc_attr(__('generate password app credentials', 'nibwp')); ?>" href="<?php echo esc_url(admin_url('admin.php?page=nibwp-connect')); ?>">
                    <div class="nw-help-action__icon" style="background:var(--nw-brand-soft); color:var(--nw-brand);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                    </div>
                    <div class="nw-help-action__text">
                        <strong><?php esc_html_e('Generate App Password', 'nibwp'); ?></strong>
                        <span><?php esc_html_e('Create credentials for AI clients', 'nibwp'); ?></span>
                    </div>
                    <svg class="nw-help-topic__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </a>
                <a class="nw-help-action nw-searchable" data-search="<?php /* translators: search keywords for a help-drawer quick action, lowercase, space-separated */ echo esc_attr(__('security scan malware verify core', 'nibwp')); ?>" href="<?php echo esc_url(admin_url('admin.php?page=nibwp')); ?>">
                    <div class="nw-help-action__icon" style="background:var(--nw-danger-soft); color:var(--nw-danger);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    </div>
                    <div class="nw-help-action__text">
                        <strong><?php esc_html_e('Run Security Scan', 'nibwp'); ?></strong>
                        <span><?php esc_html_e('Detect malware & verify core files', 'nibwp'); ?></span>
                    </div>
                    <svg class="nw-help-topic__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </a>
                <a class="nw-help-action nw-searchable" data-search="<?php /* translators: search keywords for a help-drawer quick action, lowercase, space-separated */ echo esc_attr(__('activate integration plugin builder', 'nibwp')); ?>" href="<?php echo esc_url(admin_url('admin.php?page=nibwp-integrations')); ?>">
                    <div class="nw-help-action__icon" style="background:var(--nw-warn-soft); color:var(--nw-warn);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 1v6m0 10v6m-7-11h6m4 0h6"/></svg>
                    </div>
                    <div class="nw-help-action__text">
                        <strong><?php esc_html_e('Activate Integrations', 'nibwp'); ?></strong>
                        <span><?php esc_html_e('Page builders, e-commerce, forms', 'nibwp'); ?></span>
                    </div>
                    <svg class="nw-help-topic__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </a>
                <a class="nw-help-action nw-searchable" data-search="<?php /* translators: search keywords for a help-drawer quick action, lowercase, space-separated */ echo esc_attr(__('audit log history calls', 'nibwp')); ?>" href="<?php echo esc_url(admin_url('admin.php?page=nibwp-audit-log')); ?>">
                    <div class="nw-help-action__icon" style="background:var(--nw-surface-3); color:var(--nw-text-muted);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </div>
                    <div class="nw-help-action__text">
                        <strong><?php esc_html_e('View Audit Log', 'nibwp'); ?></strong>
                        <span><?php esc_html_e('See every tool call and result', 'nibwp'); ?></span>
                    </div>
                    <svg class="nw-help-topic__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </a>
                <a class="nw-help-action nw-searchable" data-search="<?php /* translators: search keywords for a help-drawer quick action, lowercase, space-separated */ echo esc_attr(__('settings configure rate limit', 'nibwp')); ?>" href="<?php echo esc_url(admin_url('admin.php?page=nibwp-settings')); ?>">
                    <div class="nw-help-action__icon" style="background:var(--nw-surface-3); color:var(--nw-text-muted);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 1v6m0 10v6m-7-11h6m4 0h6"/></svg>
                    </div>
                    <div class="nw-help-action__text">
                        <strong><?php esc_html_e('Configure Settings', 'nibwp'); ?></strong>
                        <span><?php esc_html_e('Rate limits, IP whitelist, safety', 'nibwp'); ?></span>
                    </div>
                    <svg class="nw-help-topic__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </a>
            </div>

            <!-- No results -->
            <div class="nw-help-no-results" id="nw-help-no-results" hidden>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <p><?php esc_html_e('No matching topics found.', 'nibwp'); ?></p>
            </div>
        </div>

        <div class="nw-help-drawer__foot">
            <span><?php esc_html_e("Need more help?", 'nibwp'); ?></span>
            <a href="https://www.nibwp.com/support" target="_blank" rel="noopener">
                <?php esc_html_e('Chat with support', 'nibwp'); ?>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
            </a>
        </div>
    </div>

    <script>
    (function(){
        var tab=document.getElementById('nw-help-tab');
        var drawer=document.getElementById('nw-help-drawer');
        var backdrop=document.getElementById('nw-help-backdrop');
        var close=document.getElementById('nw-help-close');
        var tabBtns=drawer.querySelectorAll('.nw-help-tab-btn');
        var panels=drawer.querySelectorAll('.nw-help-tab-panel');
        var searchInput=document.getElementById('nw-help-search-input');
        var noResults=document.getElementById('nw-help-no-results');

        function open(){ drawer.classList.add('is-open'); backdrop.classList.add('is-open'); tab.style.display='none'; setTimeout(function(){searchInput.focus();}, 320); }
        function shut(){ drawer.classList.remove('is-open'); backdrop.classList.remove('is-open'); setTimeout(function(){tab.style.display='';},300); }

        tab.addEventListener('click', open);
        close.addEventListener('click', shut);
        backdrop.addEventListener('click', shut);
        document.addEventListener('keydown', function(e){ if(e.key==='Escape' && drawer.classList.contains('is-open')) shut(); });

        /* Tab switching */
        tabBtns.forEach(function(btn){
            btn.addEventListener('click', function(){
                tabBtns.forEach(function(b){ b.classList.remove('is-active'); });
                btn.classList.add('is-active');
                var target=btn.getAttribute('data-tab');
                panels.forEach(function(p){
                    p.classList.toggle('is-active', p.getAttribute('data-panel') === target);
                });
                if(searchInput.value) doSearch(searchInput.value);
            });
        });

        /* Live search across all panels */
        function doSearch(q){
            q = (q || '').trim().toLowerCase();
            var visiblePanel = drawer.querySelector('.nw-help-tab-panel.is-active');
            if(!visiblePanel) return;
            var items = visiblePanel.querySelectorAll('.nw-searchable');
            var matchCount = 0;

            if(!q){
                items.forEach(function(item){ item.style.display = ''; });
                /* Restore accordion default state */
                visiblePanel.querySelectorAll('.nw-help-accordion').forEach(function(acc){
                    if(!acc.dataset.userToggled) acc.open = (acc === visiblePanel.querySelector('.nw-help-accordion'));
                });
                noResults.hidden = true;
                return;
            }

            items.forEach(function(item){
                var hay = (item.getAttribute('data-search') || '').toLowerCase();
                var match = hay.indexOf(q) !== -1;
                /* Fuzzy: each word in query must appear */
                if(!match){
                    var words = q.split(/\s+/).filter(Boolean);
                    match = words.every(function(w){ return hay.indexOf(w) !== -1; });
                }
                item.style.display = match ? '' : 'none';
                if(match) matchCount++;
            });

            /* Open all accordions in browse tab when searching */
            visiblePanel.querySelectorAll('.nw-help-accordion').forEach(function(acc){
                acc.open = true;
            });

            noResults.hidden = matchCount > 0;
        }

        searchInput.addEventListener('input', function(){ doSearch(this.value); });

        /* Track manual accordion toggles so they don't reset on tab switch */
        drawer.querySelectorAll('.nw-help-accordion').forEach(function(acc){
            acc.addEventListener('toggle', function(){ acc.dataset.userToggled = '1'; });
        });
    })();
    </script>
    <?php
}

/**
 * Hand post content to wp_insert_post()/wp_update_post() the way core does.
 *
 * Both functions run wp_unslash() over the array they are given, so content
 * passed to them must arrive slashed. This was passed through wp_kses_post()
 * and nothing else, which is wrong twice over:
 *
 *   1. Unslashed content loses a backslash on the way in. Block markup is full
 *      of them — serialize_block_attributes() escapes `&` as & and `--`
 *      as -- so the block-delimiter comment stays valid HTML — so a
 *      heading of "Verdict & recommendations" was stored as
 *      "Verdict u0026amp; recommendations" and a class of `cell--name` as
 *      `cellu002du002dname`, breaking the styling that depended on it. A
 *      customer hit both and had to route writes around this ability.
 *
 *   2. wp_kses_post() re-encodes the `&` inside those attributes, turning
 *      & into &amp; — a second, quieter corruption of the same
 *      content. Applied to correctly-slashed markup it is worse still: kses
 *      reads the escaped comment as malformed and drops the block attributes
 *      wholesale, leaving `<!-- wp:heading -->` with no settings at all.
 *
 * Slashing and passing it on is what the block editor and the core REST API
 * both do. Sanitising stays with core: wp_insert_post() applies the
 * `content_save_pre` filters, which include wp_filter_post_kses for any user
 * without `unfiltered_html` — verified against a contributor, whose <script>
 * is still stripped. Users who do hold the capability get what core gives
 * them, which is the same content they could save from the editor.
 */
function nibwp_wp_prepare_post_content($content): string {
    return wp_slash((string) $content);
}

/**
 * The CSS custom properties Automatic.css actually defines on this site,
 * name => first declared value.
 *
 * Token names differ between ACSS versions, and a guessed name with a var()
 * fallback renders the fallback without complaint: a build that validated
 * could still paint invisible borders and brand-tinted greys. ACSS's own
 * generated file is the one source that cannot drift from the site.
 *
 * Empty when ACSS files cannot be read. Callers treat empty as "cannot
 * check", never as "this site defines nothing".
 *
 * @return array<string,string>
 */
function nibwp_acss_site_tokens(): array
{
    static $tokens = null;
    if ($tokens !== null) {
        return $tokens;
    }

    $tokens = [];
    if (!function_exists('wp_upload_dir')) {
        return $tokens;
    }

    $uploads = wp_upload_dir(null, false);
    $dir = rtrim((string) ($uploads['basedir'] ?? ''), '/\\') . '/automatic-css/';

    // ACSS 3 writes a variables file; newer versions split tokens out. The full
    // stylesheet is the last resort because it is by far the largest.
    foreach (['automatic-variables.css', 'automatic-tokens.css', 'automatic.css'] as $file) {
        $css = is_readable($dir . $file) ? (string) file_get_contents($dir . $file) : '';
        if ($css === '' || !preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;{}]+)/i', $css, $declarations, PREG_SET_ORDER)) {
            continue;
        }
        foreach ($declarations as $declaration) {
            $name = strtolower($declaration[1]);
            if (!isset($tokens[$name])) {
                $tokens[$name] = trim($declaration[2]);
            }
        }
        if (count($tokens) >= 50) {
            break;
        }
    }

    // A handful of names is a partial read, not a design system; checking a
    // payload against it would reject tokens that are perfectly real.
    if (count($tokens) < 50) {
        $tokens = [];
    }

    return $tokens;
}
