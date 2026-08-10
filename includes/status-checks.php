<?php

declare(strict_types=1);

/**
 * Connection diagnostics engine.
 *
 * "It won't connect" is almost never NIBWP itself — it is a security plugin that
 * turned off Application Passwords, a server that strips the Authorization
 * header before PHP sees it, a firewall answering 403 to /wp-json, or a site
 * that was cloned to a new domain. Each of those produces the same useless
 * symptom in the client: "connection failed".
 *
 * This file turns that symptom into a named cause. Every check reports what it
 * looked at, what it found, and what to do about it, and the engine ranks the
 * failures so the operator is told the ONE thing to fix first rather than
 * handed a wall of red.
 */

if (!defined('ABSPATH')) {
    exit();
}

const NIBWP_STATUS_PASS = 'pass';
const NIBWP_STATUS_WARN = 'warn';
const NIBWP_STATUS_FAIL = 'fail';
const NIBWP_STATUS_INFO = 'info';

/**
 * Groups, in the order they are rendered. Connection first: it is what people
 * came here for.
 *
 * @return array<string, string>
 */
function nibwp_status_groups(): array
{
    return [
        'connection' => __('Connection', 'nibwp'),
        'auth' => __('Authentication', 'nibwp'),
        'environment' => __('WordPress & server', 'nibwp'),
        'interference' => __('Plugins, themes & firewalls', 'nibwp'),
        'extensions' => __('Sandbox & extensions', 'nibwp'),
    ];
}

/**
 * Build one check result.
 *
 * @param string        $id      Stable identifier.
 * @param string        $group   Group key from nibwp_status_groups().
 * @param string        $label   Short human label.
 * @param string        $status  One of the NIBWP_STATUS_* constants.
 * @param string        $summary One-line finding.
 * @param string        $detail  Optional longer explanation.
 * @param array<int,string> $fix Optional ordered remediation steps.
 * @param bool          $blocker Whether this failing alone prevents connecting.
 * @param array<int,string> $depends_on Ids this check needs in order to mean anything.
 * @return array<string, mixed>
 */
function nibwp_status_result(
    string $id,
    string $group,
    string $label,
    string $status,
    string $summary,
    string $detail = '',
    array $fix = [],
    bool $blocker = false,
    array $depends_on = []
): array {
    return [
        'id' => $id,
        'group' => $group,
        'label' => $label,
        'status' => $status,
        'summary' => $summary,
        'detail' => $detail,
        'fix' => $fix,
        'blocker' => $blocker,
        'depends_on' => $depends_on,
        // Filled in by nibwp_status_summarize(): the upstream check whose
        // failure explains this one.
        'consequence_of' => null,
    ];
}

// ---------------------------------------------------------------------------
// Loopback self-test
// ---------------------------------------------------------------------------

/**
 * Issue (and cache) the short-lived token the self-test route expects.
 *
 * The route has to answer unauthenticated requests — a loopback carries no admin
 * cookie — so a token is what stops it from being a free probe endpoint for
 * anyone who finds the URL.
 */
function nibwp_status_selftest_token(bool $regenerate = false): string
{
    $token = get_transient('nibwp_status_selftest_token');
    if ($regenerate || !is_string($token) || $token === '') {
        $token = wp_generate_password(32, false, false);
        set_transient('nibwp_status_selftest_token', $token, 5 * MINUTE_IN_SECONDS);
    }

    return $token;
}

/**
 * The self-test endpoint. Reports only booleans about the request it received —
 * never site data — so the worst a leaked token buys is knowing whether this
 * server forwards an Authorization header.
 */
add_action('rest_api_init', static function (): void {
    register_rest_route('nibwp/v1', '/connection-selftest', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => static function (WP_REST_Request $request) {
            $expected = get_transient('nibwp_status_selftest_token');
            $given = (string) $request->get_param('token');
            if (!is_string($expected) || $expected === '' || !hash_equals($expected, $given)) {
                return new WP_Error(
                    'nibwp_selftest_forbidden',
                    __('Invalid or expired self-test token.', 'nibwp'),
                    ['status' => 403]
                );
            }

            // Read the header the way WordPress itself does, so we are testing
            // the same path Application Password auth depends on.
            $auth = $request->get_header('authorization');
            if ($auth === null || $auth === '') {
                $auth = $request->get_header('x-nibwp-echo-auth');
            }

            return [
                'ok' => true,
                'auth_header_seen' => is_string($auth) && $auth !== '',
                'mcp_route_registered' => nibwp_status_mcp_route_registered(),
                'is_ssl' => is_ssl(),
            ];
        },
    ]);
});

/**
 * Whether the MCP route is present in the REST route table.
 */
function nibwp_status_mcp_route_registered(): bool
{
    if (!function_exists('rest_get_server')) {
        return false;
    }

    foreach (array_keys(rest_get_server()->get_routes()) as $route) {
        if (strpos((string) $route, '/mcp/') === 0) {
            return true;
        }
    }

    return false;
}

/**
 * Run the loopback probes and cache the outcome.
 *
 * Cached because the page renders every check on load and nobody wants three
 * HTTP round trips on every refresh; the Re-run button passes $force.
 *
 * @return array<string, mixed>
 */
function nibwp_status_probe(bool $force = false): array
{
    $cached = get_transient('nibwp_status_probe');
    if (!$force && is_array($cached)) {
        return $cached;
    }

    $token = nibwp_status_selftest_token($force);
    $url = add_query_arg('token', $token, rest_url('nibwp/v1/connection-selftest'));

    $args = [
        'timeout' => 10,
        // A firewall that rejects the loopback for having no user agent would
        // give a false positive, so send a real one.
        'user-agent' => 'NIBWP-Diagnostics/' . (defined('NIBWP_VERSION') ? NIBWP_VERSION : '0'),
        'headers' => [
            // The probe value is meaningless on purpose: we are testing whether
            // the header survives the trip, not what it contains.
            'Authorization' => 'Bearer nibwp-connection-selftest',
            'X-Nibwp-Echo-Auth' => '',
        ],
        // Self-signed certificates are common on staging and would otherwise
        // read as "REST is down" when REST is fine.
        'sslverify' => false,
    ];

    $result = [
        'ran' => true,
        'time' => time(),
        'rest_ok' => false,
        'rest_code' => 0,
        'rest_error' => '',
        'rest_body' => '',
        'auth_header_seen' => null,
        'mcp_code' => 0,
        'mcp_error' => '',
        'mcp_challenge' => '',
        'oauth_probes' => [],
        'endpoint' => rest_url('mcp/nibwp'),
    ];

    $response = wp_remote_get($url, $args);
    if (is_wp_error($response)) {
        $result['rest_error'] = $response->get_error_message();
    } else {
        $result['rest_code'] = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $result['rest_body'] = substr(trim(wp_strip_all_tags($body)), 0, 300);
        $decoded = json_decode($body, true);
        if ($result['rest_code'] === 200 && is_array($decoded) && !empty($decoded['ok'])) {
            $result['rest_ok'] = true;
            $result['auth_header_seen'] = (bool) ($decoded['auth_header_seen'] ?? false);
        }
    }

    // Hit the MCP endpoint itself. Unauthenticated it should be refused
    // (401/403/405) — a 404 means the route never registered, which is a
    // different and much worse problem.
    $mcp = wp_remote_get(rest_url('mcp/nibwp'), [
        'timeout' => 10,
        'sslverify' => false,
        'user-agent' => $args['user-agent'],
    ]);
    if (is_wp_error($mcp)) {
        $result['mcp_error'] = $mcp->get_error_message();
    } else {
        $result['mcp_code'] = (int) wp_remote_retrieve_response_code($mcp);
        // The challenge header is how a client discovers where to sign in. If
        // the refusal carries no header, OAuth cannot start at all.
        $result['mcp_challenge'] = (string) wp_remote_retrieve_header($mcp, 'www-authenticate');
        // Whether the site lets itself be framed decides whether the visual
        // workspace can show anything at all.
        $result['visual_frame_options'] = (string) wp_remote_retrieve_header($mcp, 'x-frame-options');
    }

    // Sign-in discovery. Each documented URL is tried on its own, because
    // "discovery failed" is useless next to "this address returns 404" — and a
    // client walks these in order, so which one broke is the whole diagnosis.
    if (function_exists('nibwp_oauth_discovery_probes')) {
        $satisfied_any = false;
        foreach (nibwp_oauth_discovery_probes() as $probe) {
            // The interchangeable forms stop being interesting once one answers.
            if ($probe['requirement'] === 'any' && $satisfied_any) {
                $result['oauth_probes'][] = $probe + ['code' => 0, 'ok' => true, 'skipped' => true, 'error' => ''];
                continue;
            }

            $response = wp_remote_get($probe['url'], [
                'timeout' => 5,
                'sslverify' => false,
                'redirection' => 0,
                'user-agent' => $args['user-agent'],
            ]);

            $code = 0;
            $ok = false;
            $error = '';
            if (is_wp_error($response)) {
                $error = $response->get_error_message();
            } else {
                $code = (int) wp_remote_retrieve_response_code($response);
                $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
                $ok = $code === 200
                    && is_array($decoded)
                    && (string) ($decoded[$probe['field']] ?? '') === $probe['expected'];
            }

            if ($ok && $probe['requirement'] === 'any') {
                $satisfied_any = true;
            }

            $result['oauth_probes'][] = $probe + [
                'code' => $code,
                'ok' => $ok,
                'skipped' => false,
                'error' => $error,
            ];
        }
    }

    set_transient('nibwp_status_probe', $result, 2 * MINUTE_IN_SECONDS);

    return $result;
}

// ---------------------------------------------------------------------------
// Known third-party software that commonly sits in front of the REST API
// ---------------------------------------------------------------------------

/**
 * Plugins that can block or restrict the REST API / Application Passwords.
 *
 * Presence is not guilt — these are all legitimate and widely used. They are
 * listed so that when something IS blocked, the operator knows which settings
 * screen to open instead of guessing.
 *
 * @return array<string, array{name: string, note: string}>
 */
function nibwp_status_known_security_plugins(): array
{
    return [
        'wordfence/wordfence.php' => [
            'name' => 'Wordfence Security',
            'note' => __('Firewall → Blocking, and "Disable Application Passwords" under Login Security.', 'nibwp'),
        ],
        'better-wp-security/better-wp-security.php' => [
            'name' => 'Solid Security',
            'note' => __('Advanced → WordPress Tweaks → REST API, and the Application Passwords toggle.', 'nibwp'),
        ],
        'all-in-one-wp-security-and-firewall/wp-security.php' => [
            'name' => 'All-In-One Security (AIOS)',
            'note' => __('Firewall → REST API settings.', 'nibwp'),
        ],
        'sucuri-scanner/sucuri.php' => [
            'name' => 'Sucuri Security',
            'note' => __('Firewall rules may filter /wp-json before WordPress runs.', 'nibwp'),
        ],
        'wp-cerber/wp-cerber.php' => [
            'name' => 'WP Cerber Security',
            'note' => __('Hardening → REST API, and Application Passwords restrictions.', 'nibwp'),
        ],
        'ninjafirewall/ninjafirewall.php' => [
            'name' => 'NinjaFirewall',
            'note' => __('Firewall policies can reject REST requests early.', 'nibwp'),
        ],
        'disable-json-api/disable-json-api.php' => [
            'name' => 'Disable REST API',
            'note' => __('This plugin blocks the REST API outright. NIBWP cannot work while it is active.', 'nibwp'),
        ],
        'disable-wp-rest-api/disable-wp-rest-api.php' => [
            'name' => 'Disable WP REST API',
            'note' => __('This plugin blocks the REST API outright. NIBWP cannot work while it is active.', 'nibwp'),
        ],
        'wps-hide-login/wps-hide-login.php' => [
            'name' => 'WPS Hide Login',
            'note' => __('Changes the login URL; harmless for MCP but worth knowing during setup.', 'nibwp'),
        ],
        'cloudflare/cloudflare.php' => [
            'name' => 'Cloudflare',
            'note' => __('WAF managed rules can challenge or block API requests before they reach WordPress.', 'nibwp'),
        ],
    ];
}

/**
 * Plugins whose whole purpose is to switch the REST API off.
 *
 * @return array<int, string>
 */
function nibwp_status_rest_killers(): array
{
    return [
        'disable-json-api/disable-json-api.php',
        'disable-wp-rest-api/disable-wp-rest-api.php',
    ];
}

/**
 * Active plugins, keyed by file, with their names.
 *
 * @return array<string, string>
 */
function nibwp_status_active_plugins(): array
{
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $all = get_plugins();
    $active = (array) get_option('active_plugins', []);
    if (is_multisite()) {
        $active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', [])));
    }

    $out = [];
    foreach ($active as $file) {
        $file = (string) $file;
        $out[$file] = isset($all[$file]['Name']) ? (string) $all[$file]['Name'] : $file;
    }

    return $out;
}

// ---------------------------------------------------------------------------
// The checks
// ---------------------------------------------------------------------------

/**
 * Run every diagnostic and return results plus a ranked verdict.
 *
 * @param bool $force Re-run the loopback probes instead of using the cache.
 * @return array<string, mixed>
 */
function nibwp_status_run(bool $force = false): array
{
    $probe = nibwp_status_probe($force);
    $checks = [];

    foreach (
        [
            'nibwp_status_check_dependency',
            'nibwp_status_check_enabled',
            'nibwp_status_check_domain_lock',
            'nibwp_status_check_rest',
            'nibwp_status_check_mcp_endpoint',
            'nibwp_status_check_auth_header',
            'nibwp_status_check_oauth',
            'nibwp_status_check_app_passwords',
            'nibwp_status_check_app_password_constant',
            'nibwp_status_check_user_credentials',
            'nibwp_status_check_https',
            'nibwp_status_check_permalinks',
            'nibwp_status_check_php',
            'nibwp_status_check_wp',
            'nibwp_status_check_memory',
            'nibwp_status_check_rest_killers',
            'nibwp_status_check_security_plugins',
            'nibwp_status_check_rest_filters',
            'nibwp_status_check_maintenance',
            'nibwp_status_check_sandbox',
            'nibwp_status_check_mu_plugins',
            'nibwp_status_check_theme',
            'nibwp_status_check_visual',
        ] as $callback
    ) {
        $result = $callback($probe);
        if ($result !== null) {
            $checks[] = $result;
        }
    }

    return nibwp_status_summarize($checks, $probe);
}

/**
 * Rank results into a single verdict.
 *
 * The point of the ranking is that check order equals dependency order: if the
 * MCP adapter never loaded, telling someone their PHP memory limit is low is
 * noise. The first blocking failure is the culprit; everything after it may
 * simply be a consequence.
 *
 * @param array<int, array<string, mixed>> $checks
 * @param array<string, mixed>             $probe
 * @return array<string, mixed>
 */
function nibwp_status_summarize(array $checks, array $probe): array
{
    $counts = [NIBWP_STATUS_PASS => 0, NIBWP_STATUS_WARN => 0, NIBWP_STATUS_FAIL => 0, NIBWP_STATUS_INFO => 0];
    $culprit = null;

    // A check that only failed because something it depends on failed is not a
    // second problem to solve. Attribute it to the upstream cause so the screen
    // shows one thing to fix instead of a column of red that all clears at once.
    $labels = [];
    $failed = [];
    foreach ($checks as $check) {
        $labels[(string) $check['id']] = (string) $check['label'];
        if ((string) $check['status'] === NIBWP_STATUS_FAIL) {
            $failed[(string) $check['id']] = true;
        }
    }

    foreach ($checks as $i => $check) {
        $status = (string) $check['status'];
        if ($status === NIBWP_STATUS_FAIL) {
            foreach ((array) ($check['depends_on'] ?? []) as $dependency) {
                if (isset($failed[$dependency])) {
                    $checks[$i]['consequence_of'] = $labels[$dependency] ?? $dependency;
                    break;
                }
            }
        }

        if (isset($counts[$status])) {
            $counts[$status]++;
        }
        if (
            $culprit === null
            && $status === NIBWP_STATUS_FAIL
            && !empty($check['blocker'])
            && $checks[$i]['consequence_of'] === null
        ) {
            $culprit = $checks[$i];
        }
    }

    if ($culprit !== null) {
        $verdict = [
            'status' => 'blocked',
            'headline' => __('Connection is blocked', 'nibwp'),
            'sub' => (string) $culprit['summary'],
            'culprit' => $culprit,
        ];
    } elseif ($counts[NIBWP_STATUS_FAIL] > 0 || $counts[NIBWP_STATUS_WARN] > 0) {
        $verdict = [
            'status' => 'degraded',
            'headline' => __('Ready, with warnings', 'nibwp'),
            'sub' => __('Nothing is stopping a connection, but the items below can cause intermittent failures.', 'nibwp'),
            'culprit' => null,
        ];
    } else {
        $verdict = [
            'status' => 'ready',
            'headline' => __('Ready to connect', 'nibwp'),
            'sub' => __('Every check passed. If a client still cannot connect, the cause is on the client side — recheck the endpoint URL, username and application password.', 'nibwp'),
            'culprit' => null,
        ];
    }

    nibwp_status_store_nav_state($counts);

    $groups = [];
    foreach (array_keys(nibwp_status_groups()) as $key) {
        $groups[$key] = [];
    }
    foreach ($checks as $check) {
        $groups[(string) $check['group']][] = $check;
    }

    return [
        'checks' => $checks,
        'groups' => array_filter($groups),
        'counts' => $counts,
        'verdict' => $verdict,
        'probe' => $probe,
        'endpoint' => rest_url('mcp/nibwp'),
        'generated' => time(),
    ];
}

// ---------------------------------------------------------------------------
// Sidebar indicator
// ---------------------------------------------------------------------------

/**
 * Worst status worth flagging in the sidebar: '' (nothing), 'warn' or 'fail'.
 *
 * The nav renders on every NIBWP screen, so this must never touch the network.
 * Two layers keep it free: a full run() stores its own verdict here, and if that
 * is missing we fall back to the checks that read options and constants only —
 * no loopback, no REST, no HTTP. Both are cached.
 */
function nibwp_status_nav_state(): string
{
    $cached = get_transient('nibwp_status_nav_state');
    if (is_string($cached)) {
        return $cached;
    }

    $state = '';

    // Anything here means a client genuinely cannot connect right now.
    $blocked = (function_exists('nibwp_get_mcp_dependency_error') && nibwp_get_mcp_dependency_error() instanceof WP_Error)
        || (function_exists('nibwp_is_enabled') && !nibwp_is_enabled())
        || (function_exists('nibwp_is_domain_mismatch') && nibwp_is_domain_mismatch())
        || (function_exists('nibwp_app_passwords_status') && empty(nibwp_app_passwords_status()['available']));

    if (!$blocked) {
        // get_plugins() reads the filesystem, so it stays behind the cheaper
        // checks above and behind the transient.
        $active = nibwp_status_active_plugins();
        foreach (nibwp_status_rest_killers() as $file) {
            if (isset($active[$file])) {
                $blocked = true;
                break;
            }
        }
    }

    if ($blocked) {
        $state = NIBWP_STATUS_FAIL;
    } elseif (function_exists('nibwp_sandbox_crash_report') && nibwp_sandbox_crash_report() !== null) {
        $state = NIBWP_STATUS_WARN;
    }

    set_transient('nibwp_status_nav_state', $state, 10 * MINUTE_IN_SECONDS);

    return $state;
}

// Flipping the master switch is the most common way someone clears (or causes)
// a blocked state. Drop the cache so the dot reacts on the next page load
// instead of lingering for the rest of the TTL.
add_action('update_option_nibwp_ai_abilities_enabled', static function (): void {
    delete_transient('nibwp_status_nav_state');
});
add_action('add_option_nibwp_ai_abilities_enabled', static function (): void {
    delete_transient('nibwp_status_nav_state');
});

/**
 * Record the authoritative state after a full diagnostic, so the sidebar agrees
 * with what the Status screen just showed.
 */
function nibwp_status_store_nav_state(array $counts): void
{
    $state = '';
    if (($counts[NIBWP_STATUS_FAIL] ?? 0) > 0) {
        $state = NIBWP_STATUS_FAIL;
    } elseif (($counts[NIBWP_STATUS_WARN] ?? 0) > 0) {
        $state = NIBWP_STATUS_WARN;
    }

    set_transient('nibwp_status_nav_state', $state, 10 * MINUTE_IN_SECONDS);
}

/**
 * @param array<string, mixed> $probe
 * @return array<string, mixed>
 */
function nibwp_status_check_dependency(array $probe): array
{
    $error = function_exists('nibwp_get_mcp_dependency_error') ? nibwp_get_mcp_dependency_error() : null;
    if ($error instanceof WP_Error) {
        return nibwp_status_result(
            'dependency',
            'connection',
            __('MCP adapter loaded', 'nibwp'),
            NIBWP_STATUS_FAIL,
            __('NIBWP could not load the MCP adapter, so no endpoint exists to connect to.', 'nibwp'),
            $error->get_error_message(),
            [
                __('This almost always means the GitHub source ZIP was installed instead of the release build.', 'nibwp'),
                __('Download the release ZIP from your account, then Plugins → Add New → Upload and overwrite the current copy.', 'nibwp'),
                __('Deactivating first is not required; uploading over the top keeps your settings.', 'nibwp'),
            ],
            true
        );
    }

    return nibwp_status_result(
        'dependency',
        'connection',
        __('MCP adapter loaded', 'nibwp'),
        NIBWP_STATUS_PASS,
        __('The bundled MCP adapter is present and initialised.', 'nibwp')
    );
}

/**
 * @param array<string, mixed> $probe
 * @return array<string, mixed>
 */
function nibwp_status_check_enabled(array $probe): array
{
    $enabled = function_exists('nibwp_is_enabled') && nibwp_is_enabled();
    if ($enabled) {
        return nibwp_status_result(
            'enabled',
            'connection',
            __('AI abilities enabled', 'nibwp'),
            NIBWP_STATUS_PASS,
            __('The master switch is on, so the MCP endpoint is being served.', 'nibwp')
        );
    }

    return nibwp_status_result(
        'enabled',
        'connection',
        __('AI abilities enabled', 'nibwp'),
        NIBWP_STATUS_FAIL,
        __('AI abilities are switched off, so the MCP endpoint is not served at all.', 'nibwp'),
        __('This is the master switch. While it is off, every client will report a connection failure no matter how it is configured.', 'nibwp'),
        [
            __('Open NIBWP → Connect.', 'nibwp'),
            __('Turn on "Enable AI abilities".', 'nibwp'),
            __('Return here and re-run the diagnostic.', 'nibwp'),
        ],
        true
    );
}

/**
 * @param array<string, mixed> $probe
 * @return array<string, mixed>|null
 */
function nibwp_status_check_domain_lock(array $probe): ?array
{
    if (!function_exists('nibwp_is_domain_mismatch')) {
        return null;
    }

    if (!nibwp_is_domain_mismatch()) {
        return nibwp_status_result(
            'domain_lock',
            'connection',
            __('Site address matches activation', 'nibwp'),
            NIBWP_STATUS_PASS,
            __('Abilities were enabled for this exact domain.', 'nibwp')
        );
    }

    $locked = (string) get_option('nibwp_ai_abilities_domain', '');
    $current = (string) wp_parse_url(home_url(), PHP_URL_HOST);

    return nibwp_status_result(
        'domain_lock',
        'connection',
        __('Site address matches activation', 'nibwp'),
        NIBWP_STATUS_FAIL,
        sprintf(
            /* translators: 1: domain abilities were enabled on, 2: current domain */
            __('Abilities were enabled on %1$s but this site now answers on %2$s, so they are inactive.', 'nibwp'),
            $locked !== '' ? $locked : __('(not recorded)', 'nibwp'),
            $current
        ),
        __('This is the expected result after cloning, migrating, or moving between staging and production. It is a safety catch, not an error — it stops a copied database from exposing an endpoint on a domain you did not intend.', 'nibwp'),
        [
            __('Open NIBWP → Connect.', 'nibwp'),
            __('Switch AI abilities off and on again to re-lock them to this domain.', 'nibwp'),
            __('Issue a fresh application password afterwards, as the old client config still points at the previous domain.', 'nibwp'),
        ],
        true
    );
}

/**
 * @param array<string, mixed> $probe
 * @return array<string, mixed>
 */
function nibwp_status_check_rest(array $probe): array
{
    if (!empty($probe['rest_ok'])) {
        return nibwp_status_result(
            'rest',
            'connection',
            __('REST API reachable', 'nibwp'),
            NIBWP_STATUS_PASS,
            __('This site answered its own REST request successfully.', 'nibwp')
        );
    }

    $code = (int) ($probe['rest_code'] ?? 0);
    $error = (string) ($probe['rest_error'] ?? '');
    $body = (string) ($probe['rest_body'] ?? '');

    if ($error !== '') {
        return nibwp_status_result(
            'rest',
            'connection',
            __('REST API reachable', 'nibwp'),
            NIBWP_STATUS_FAIL,
            __('This site cannot reach its own REST API over HTTP.', 'nibwp'),
            sprintf(
                /* translators: %s: HTTP client error message */
                __('The loopback request failed before it got a response: %s', 'nibwp'),
                $error
            ),
            [
                __('Ask your host whether outbound HTTP requests from the server to its own domain are permitted (loopback).', 'nibwp'),
                __('If the site sits behind a WAF or proxy, allow the server\'s own IP address.', 'nibwp'),
                __('On staging with a self-signed certificate this can be a false alarm; a client on the open internet may still connect fine.', 'nibwp'),
            ],
            true,
            ['php', 'wp']
        );
    }

    $detail = sprintf(
        /* translators: %d: HTTP status code */
        __('The REST API answered with HTTP %d instead of a normal response.', 'nibwp'),
        $code
    );
    if ($body !== '') {
        $detail .= "\n\n" . sprintf(
            /* translators: %s: first part of the HTTP response body */
            __('Response began: %s', 'nibwp'),
            $body
        );
    }

    $fix = [
        __('A 401 or 403 here usually means a security plugin or firewall is filtering /wp-json for logged-out requests.', 'nibwp'),
        __('Check the plugins listed under "Plugins, themes & firewalls" below and allow the REST API.', 'nibwp'),
        __('If the response mentions a specific product, that product is the one blocking it.', 'nibwp'),
    ];
    if ($code === 404) {
        $fix = [
            __('A 404 means the REST route is not registered. Go to Settings → Permalinks and click Save to flush the rules.', 'nibwp'),
            __('If it persists, another plugin is removing REST routes.', 'nibwp'),
        ];
    }

    return nibwp_status_result(
        'rest',
        'connection',
        __('REST API reachable', 'nibwp'),
        NIBWP_STATUS_FAIL,
        __('Something is intercepting requests to the REST API.', 'nibwp'),
        $detail,
        $fix,
        true
    );
}

/**
 * @param array<string, mixed> $probe
 * @return array<string, mixed>
 */
function nibwp_status_check_mcp_endpoint(array $probe): array
{
    $registered = nibwp_status_mcp_route_registered();
    $code = (int) ($probe['mcp_code'] ?? 0);

    if (!$registered) {
        return nibwp_status_result(
            'mcp_endpoint',
            'connection',
            __('MCP endpoint registered', 'nibwp'),
            NIBWP_STATUS_FAIL,
            __('No MCP route is registered on this site.', 'nibwp'),
            __('The endpoint your client points at does not exist yet. This follows from either the adapter failing to load or abilities being switched off — fix those first and this clears itself.', 'nibwp'),
            [
                __('Resolve any failure above this one, then re-run the diagnostic.', 'nibwp'),
                __('If everything above passes, go to Settings → Permalinks and click Save to rebuild the route table.', 'nibwp'),
            ],
            true,
            ['dependency', 'enabled', 'domain_lock', 'php', 'wp']
        );
    }

    // Unauthenticated the endpoint SHOULD refuse us. That refusal is proof it is
    // alive and guarded; a 200 would be the surprising answer.
    if ($code === 0) {
        return nibwp_status_result(
            'mcp_endpoint',
            'connection',
            __('MCP endpoint registered', 'nibwp'),
            NIBWP_STATUS_WARN,
            __('The MCP route exists, but this site could not call it over HTTP to confirm.', 'nibwp'),
            (string) ($probe['mcp_error'] ?? '')
        );
    }

    if (in_array($code, [401, 403], true)) {
        return nibwp_status_result(
            'mcp_endpoint',
            'connection',
            __('MCP endpoint registered', 'nibwp'),
            NIBWP_STATUS_PASS,
            sprintf(
                /* translators: %d: HTTP status code */
                __('The endpoint is live and correctly refused an unauthenticated request (HTTP %d).', 'nibwp'),
                $code
            )
        );
    }

    if ($code === 404) {
        return nibwp_status_result(
            'mcp_endpoint',
            'connection',
            __('MCP endpoint registered', 'nibwp'),
            NIBWP_STATUS_FAIL,
            __('The MCP route is registered inside WordPress but returns 404 over HTTP.', 'nibwp'),
            __('Something between the browser and WordPress is rewriting or swallowing the request — commonly stale permalink rules or a caching layer.', 'nibwp'),
            [
                __('Go to Settings → Permalinks and click Save Changes (no edit needed) to flush rewrite rules.', 'nibwp'),
                __('Purge any page or edge cache, then re-run this diagnostic.', 'nibwp'),
            ],
            true,
            ['rest']
        );
    }

    return nibwp_status_result(
        'mcp_endpoint',
        'connection',
        __('MCP endpoint registered', 'nibwp'),
        NIBWP_STATUS_PASS,
        sprintf(
            /* translators: %d: HTTP status code */
            __('The endpoint is live and answering (HTTP %d).', 'nibwp'),
            $code
        )
    );
}

/**
 * The single most common invisible cause of "wrong username or password".
 *
 * @param array<string, mixed> $probe
 * @return array<string, mixed>
 */
function nibwp_status_check_auth_header(array $probe): array
{
    $seen = $probe['auth_header_seen'] ?? null;

    if ($seen === null) {
        return nibwp_status_result(
            'auth_header',
            'auth',
            __('Authorization header reaches PHP', 'nibwp'),
            NIBWP_STATUS_WARN,
            __('Could not be tested because the self-test request did not complete.', 'nibwp'),
            __('Resolve the REST API failure above and this test will run.', 'nibwp')
        );
    }

    if ($seen === true) {
        return nibwp_status_result(
            'auth_header',
            'auth',
            __('Authorization header reaches PHP', 'nibwp'),
            NIBWP_STATUS_PASS,
            __('The Authorization header survives the trip to PHP, so application passwords can be verified.', 'nibwp')
        );
    }

    return nibwp_status_result(
        'auth_header',
        'auth',
        __('Authorization header reaches PHP', 'nibwp'),
        NIBWP_STATUS_FAIL,
        __('This server strips the Authorization header before PHP sees it.', 'nibwp'),
        __('The client sends correct credentials, the server discards them, and WordPress reports the request as logged out. It looks exactly like a wrong password, which is why this one costs people hours. Typical on CGI and FastCGI setups.', 'nibwp'),
        [
            __('Apache: add "CGIPassAuth On" to .htaccess, or SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1', 'nibwp'),
            __('Nginx + PHP-FPM: add fastcgi_param HTTP_AUTHORIZATION $http_authorization; to the PHP location block.', 'nibwp'),
            __('LiteSpeed: enable "CGI Set ENV" / rewrite the header in .htaccess the same way as Apache.', 'nibwp'),
            __('On managed hosting, send your host this line: "PHP is not receiving the HTTP Authorization header on REST API requests."', 'nibwp'),
        ],
        true
    );
}

/**
 * Whether an AI client can discover how to sign in.
 *
 * Sign-in is optional — application passwords still work without it — so nothing
 * here blocks connecting. It warns instead, because a client that finds no
 * challenge header simply never offers the sign-in button and the user is left
 * wondering why the flow they read about does not appear.
 *
 * @param array<string, mixed> $probe
 * @return array<string, mixed>|null
 */
function nibwp_status_check_oauth(array $probe): ?array
{
    if (!function_exists('nibwp_oauth_discovery_probes')) {
        return null;
    }

    $label = __('Sign-in discovery', 'nibwp');
    $probes = (array) ($probe['oauth_probes'] ?? []);
    $challenge = (string) ($probe['mcp_challenge'] ?? '');
    $https = str_starts_with(strtolower(rest_url()), 'https://');

    if (!$https) {
        return nibwp_status_result(
            'oauth_discovery',
            'auth',
            $label,
            NIBWP_STATUS_WARN,
            __('This site is not served over HTTPS, so sign-in is unavailable.', 'nibwp'),
            __('Tokens would travel in clear text, so AI clients refuse to sign in over plain HTTP. Connecting with an application password still works.', 'nibwp'),
            [
                __('Install an SSL certificate and set both the WordPress Address and Site Address to https://.', 'nibwp'),
            ],
            false,
            ['https']
        );
    }

    if ($probes === []) {
        return nibwp_status_result(
            'oauth_discovery',
            'auth',
            $label,
            NIBWP_STATUS_WARN,
            __('This site could not test its own sign-in addresses.', 'nibwp'),
            '',
            [],
            false,
            ['rest']
        );
    }

    // One line per address, so the report names the thing that is broken rather
    // than the thing that depends on it.
    $lines = [];
    $required_failed = null;
    $any_ok = false;
    $any_seen = false;
    foreach ($probes as $p) {
        $requirement = (string) ($p['requirement'] ?? 'optional');
        $ok = !empty($p['ok']);
        if ($requirement === 'any') {
            $any_seen = true;
            if ($ok) {
                $any_ok = true;
            }
        }
        if ($requirement === 'required' && !$ok && $required_failed === null) {
            $required_failed = $p;
        }

        if (!empty($p['skipped'])) {
            $state = __('not needed', 'nibwp');
        } elseif ($ok) {
            $state = __('answers', 'nibwp');
        } elseif (($p['error'] ?? '') !== '') {
            $state = (string) $p['error'];
        } elseif ((int) ($p['code'] ?? 0) === 200) {
            $state = __('answered, but not with the document this site serves', 'nibwp');
        } else {
            $state = sprintf(
                /* translators: %d: HTTP status code */
                __('HTTP %d', 'nibwp'),
                (int) ($p['code'] ?? 0)
            );
        }

        $lines[] = sprintf('%s — %s: %s', (string) $p['label'], $state, (string) $p['url']);
    }

    $detail = implode("
", $lines);

    if ($required_failed !== null) {
        return nibwp_status_result(
            'oauth_discovery',
            'auth',
            $label,
            NIBWP_STATUS_WARN,
            __('The address AI clients are pointed at for sign-in does not answer.', 'nibwp'),
            $detail,
            [
                __('Purge any page, object or edge cache, then re-run this diagnostic.', 'nibwp'),
                __('Check whether a security plugin, firewall or CDN blocks /.well-known/ paths.', 'nibwp'),
                __('On Apache, a real .well-known directory on disk is served before WordPress ever sees the request.', 'nibwp'),
            ],
            false,
            ['rest']
        );
    }

    if ($any_seen && !$any_ok) {
        return nibwp_status_result(
            'oauth_discovery',
            'auth',
            $label,
            NIBWP_STATUS_WARN,
            __('Clients can find the endpoint, but not where to sign in.', 'nibwp'),
            $detail,
            [
                __('Every listed address failed; one of them has to answer for sign-in to start.', 'nibwp'),
                __('Check whether a security plugin, firewall or CDN blocks /.well-known/ paths.', 'nibwp'),
            ],
            false,
            ['rest']
        );
    }

    if ($challenge !== '' && !str_contains($challenge, 'resource_metadata')) {
        return nibwp_status_result(
            'oauth_discovery',
            'auth',
            $label,
            NIBWP_STATUS_WARN,
            __('Something is rewriting the authentication header the endpoint sends back.', 'nibwp'),
            __('The refusal reached the client without the pointer to the sign-in metadata, which is how clients decide to offer the sign-in button. A security plugin or proxy that strips response headers is the usual cause.', 'nibwp') . "
" . $detail,
            [
                __('Check any firewall, proxy or CDN in front of the site for response-header filtering.', 'nibwp'),
            ],
            false,
            ['mcp_endpoint']
        );
    }

    return nibwp_status_result(
        'oauth_discovery',
        'auth',
        $label,
        NIBWP_STATUS_PASS,
        __('AI clients can discover sign-in on this site.', 'nibwp'),
        $detail
    );
}

/**
 * @param array<string, mixed> $probe
 * @return array<string, mixed>
 */
function nibwp_status_check_app_passwords(array $probe): array
{
    if (!function_exists('nibwp_app_passwords_status')) {
        return nibwp_status_result(
            'app_passwords',
            'auth',
            __('Application Passwords available', 'nibwp'),
            NIBWP_STATUS_INFO,
            __('Could not be determined on this install.', 'nibwp')
        );
    }

    $status = nibwp_app_passwords_status();
    if (!empty($status['available'])) {
        return nibwp_status_result(
            'app_passwords',
            'auth',
            __('Application Passwords available', 'nibwp'),
            NIBWP_STATUS_PASS,
            __('WordPress will issue application passwords, which is how clients authenticate.', 'nibwp')
        );
    }

    if (($status['reason'] ?? '') === 'unsupported') {
        return nibwp_status_result(
            'app_passwords',
            'auth',
            __('Application Passwords available', 'nibwp'),
            NIBWP_STATUS_FAIL,
            __('WordPress will not issue application passwords on this site.', 'nibwp'),
            (string) ($status['message'] ?? ''),
            [
                __('Serve the site over HTTPS — this is the usual cause and worth fixing regardless.', 'nibwp'),
                __('For a genuinely local site, set WP_ENVIRONMENT_TYPE to "local" in wp-config.php.', 'nibwp'),
            ],
            true
        );
    }

    return nibwp_status_result(
        'app_passwords',
        'auth',
        __('Application Passwords available', 'nibwp'),
        NIBWP_STATUS_FAIL,
        __('Application passwords have been switched off by something on this site.', 'nibwp'),
        (string) ($status['message'] ?? ''),
        [
            __('Check the security plugins listed further down and re-enable application passwords there.', 'nibwp'),
            __('Look in wp-config.php for a WP_APPLICATION_PASSWORDS constant set to false.', 'nibwp'),
            __('Check any custom snippet plugin or mu-plugin for a wp_is_application_passwords_available filter.', 'nibwp'),
        ],
        true
    );
}

/**
 * @param array<string, mixed> $probe
 * @return array<string, mixed>|null
 */
function nibwp_status_check_app_password_constant(array $probe): ?array
{
    if (!defined('WP_APPLICATION_PASSWORDS')) {
        return null;
    }

    if (constant('WP_APPLICATION_PASSWORDS')) {
        return null;
    }

    return nibwp_status_result(
        'app_password_constant',
        'auth',
        __('WP_APPLICATION_PASSWORDS constant', 'nibwp'),
        NIBWP_STATUS_FAIL,
        __('wp-config.php explicitly disables application passwords.', 'nibwp'),
        __('The constant WP_APPLICATION_PASSWORDS is defined as false. Nothing in the WordPress admin can override it.', 'nibwp'),
        [
            __('Edit wp-config.php and remove the line defining WP_APPLICATION_PASSWORDS, or set it to true.', 'nibwp'),
        ],
        true
    );
}

/**
 * @param array<string, mixed> $probe
 * @return array<string, mixed>
 */
function nibwp_status_check_user_credentials(array $probe): array
{
    $user_id = get_current_user_id();
    $passwords = class_exists('WP_Application_Passwords')
        ? WP_Application_Passwords::get_user_application_passwords($user_id)
        : [];
    $count = is_array($passwords) ? count($passwords) : 0;

    if ($count === 0) {
        return nibwp_status_result(
            'credentials',
            'auth',
            __('Application password issued', 'nibwp'),
            NIBWP_STATUS_WARN,
            __('This account has no application password yet, so no client can sign in as you.', 'nibwp'),
            __('Nothing is broken — the credential simply has not been created.', 'nibwp'),
            [
                __('Open NIBWP → Connect and generate an application password.', 'nibwp'),
                __('Copy it immediately; WordPress shows it once and never again.', 'nibwp'),
            ]
        );
    }

    return nibwp_status_result(
        'credentials',
        'auth',
        __('Application password issued', 'nibwp'),
        NIBWP_STATUS_PASS,
        sprintf(
            /* translators: %d: number of application passwords */
            _n('%d application password exists for your account.', '%d application passwords exist for your account.', $count, 'nibwp'),
            $count
        )
    );
}

/**
 * @param array<string, mixed> $probe
 * @return array<string, mixed>
 */
function nibwp_status_check_https(array $probe): array
{
    $scheme = (string) wp_parse_url(home_url(), PHP_URL_SCHEME);
    if ($scheme === 'https') {
        return nibwp_status_result(
            'https',
            'environment',
            __('Site served over HTTPS', 'nibwp'),
            NIBWP_STATUS_PASS,
            __('Credentials travel encrypted, and application passwords are permitted.', 'nibwp')
        );
    }

    $is_local = function_exists('wp_get_environment_type') && wp_get_environment_type() === 'local';

    return nibwp_status_result(
        'https',
        'environment',
        __('Site served over HTTPS', 'nibwp'),
        $is_local ? NIBWP_STATUS_INFO : NIBWP_STATUS_WARN,
        $is_local
            ? __('This site runs on plain HTTP, which is expected for a local environment.', 'nibwp')
            : __('This site runs on plain HTTP. WordPress refuses to issue application passwords without HTTPS.', 'nibwp'),
        __('Application passwords are sent on every request. Over HTTP they cross the network in the clear.', 'nibwp'),
        $is_local ? [] : [
            __('Install a certificate and move the site to HTTPS.', 'nibwp'),
            __('Update WordPress Address and Site Address under Settings → General to the https:// form.', 'nibwp'),
        ]
    );
}

/**
 * @param array<string, mixed> $probe
 * @return array<string, mixed>
 */
function nibwp_status_check_permalinks(array $probe): array
{
    $structure = (string) get_option('permalink_structure', '');
    if ($structure !== '') {
        return nibwp_status_result(
            'permalinks',
            'environment',
            __('Pretty permalinks enabled', 'nibwp'),
            NIBWP_STATUS_PASS,
            __('REST routes resolve at /wp-json/, which is the URL form clients expect.', 'nibwp')
        );
    }

    return nibwp_status_result(
        'permalinks',
        'environment',
        __('Pretty permalinks enabled', 'nibwp'),
        NIBWP_STATUS_WARN,
        __('Permalinks are set to Plain, so the endpoint URL is the fallback query-string form.', 'nibwp'),
        __('WordPress still serves REST this way, but the URL looks different and some clients will not accept it.', 'nibwp'),
        [
            __('Go to Settings → Permalinks and choose any option other than Plain.', 'nibwp'),
            __('Copy the endpoint again from NIBWP → Connect afterwards, because it will have changed.', 'nibwp'),
        ]
    );
}

/**
 * @param array<string, mixed> $probe
 * @return array<string, mixed>
 */
function nibwp_status_check_php(array $probe): array
{
    $required = '8.0';
    $current = PHP_VERSION;

    if (version_compare($current, $required, '>=')) {
        return nibwp_status_result(
            'php',
            'environment',
            __('PHP version', 'nibwp'),
            NIBWP_STATUS_PASS,
            sprintf(
                /* translators: %s: PHP version */
                __('Running PHP %s.', 'nibwp'),
                $current
            )
        );
    }

    return nibwp_status_result(
        'php',
        'environment',
        __('PHP version', 'nibwp'),
        NIBWP_STATUS_FAIL,
        sprintf(
            /* translators: 1: current PHP version, 2: required PHP version */
            __('PHP %1$s is below the %2$s NIBWP requires.', 'nibwp'),
            $current,
            $required
        ),
        __('Below this version parts of the plugin cannot even parse, which produces fatal errors rather than clean failures.', 'nibwp'),
        [
            __('Raise the PHP version in your hosting control panel, then re-run this diagnostic.', 'nibwp'),
        ],
        true
    );
}

/**
 * @param array<string, mixed> $probe
 * @return array<string, mixed>
 */
function nibwp_status_check_wp(array $probe): array
{
    global $wp_version;
    $required = '6.5';
    $current = (string) $wp_version;

    if (version_compare($current, $required, '>=')) {
        return nibwp_status_result(
            'wp',
            'environment',
            __('WordPress version', 'nibwp'),
            NIBWP_STATUS_PASS,
            sprintf(
                /* translators: %s: WordPress version */
                __('Running WordPress %s.', 'nibwp'),
                $current
            )
        );
    }

    return nibwp_status_result(
        'wp',
        'environment',
        __('WordPress version', 'nibwp'),
        NIBWP_STATUS_FAIL,
        sprintf(
            /* translators: 1: current WordPress version, 2: required version */
            __('WordPress %1$s is below the %2$s NIBWP requires.', 'nibwp'),
            $current,
            $required
        ),
        __('Older versions lack REST and Application Password behaviour NIBWP depends on.', 'nibwp'),
        [
            __('Update WordPress from Dashboard → Updates, then re-run this diagnostic.', 'nibwp'),
        ],
        true
    );
}

/**
 * @param array<string, mixed> $probe
 * @return array<string, mixed>
 */
function nibwp_status_check_memory(array $probe): array
{
    $limit = (string) ini_get('memory_limit');
    $bytes = wp_convert_hr_to_bytes($limit);

    if ($bytes <= 0 || $bytes >= 128 * MB_IN_BYTES) {
        return nibwp_status_result(
            'memory',
            'environment',
            __('PHP memory limit', 'nibwp'),
            NIBWP_STATUS_PASS,
            sprintf(
                /* translators: %s: memory limit */
                __('Memory limit is %s.', 'nibwp'),
                $bytes <= 0 ? __('unlimited', 'nibwp') : $limit
            )
        );
    }

    return nibwp_status_result(
        'memory',
        'environment',
        __('PHP memory limit', 'nibwp'),
        NIBWP_STATUS_WARN,
        sprintf(
            /* translators: %s: memory limit */
            __('Memory limit is %s, which is tight for larger tool calls.', 'nibwp'),
            $limit
        ),
        __('Requests that exhaust memory die mid-response. To a client that looks like a dropped connection rather than an error.', 'nibwp'),
        [
            __('Add define(\'WP_MEMORY_LIMIT\', \'256M\'); to wp-config.php, or raise the limit with your host.', 'nibwp'),
        ]
    );
}

/**
 * @param array<string, mixed> $probe
 * @return array<string, mixed>|null
 */
function nibwp_status_check_rest_killers(array $probe): ?array
{
    $active = nibwp_status_active_plugins();
    $found = [];
    foreach (nibwp_status_rest_killers() as $file) {
        if (isset($active[$file])) {
            $found[] = $active[$file];
        }
    }

    if ($found === []) {
        return null;
    }

    return nibwp_status_result(
        'rest_killers',
        'interference',
        __('REST API disabled by a plugin', 'nibwp'),
        NIBWP_STATUS_FAIL,
        sprintf(
            /* translators: %s: comma-separated plugin names */
            __('%s is active and its purpose is to switch off the REST API.', 'nibwp'),
            implode(', ', $found)
        ),
        __('NIBWP is built on the REST API. While this plugin is active there is nothing to connect to.', 'nibwp'),
        [
            __('Deactivate the plugin, or configure it to allow the REST API for authenticated requests.', 'nibwp'),
            __('Re-run this diagnostic afterwards.', 'nibwp'),
        ],
        true
    );
}

/**
 * @param array<string, mixed> $probe
 * @return array<string, mixed>
 */
function nibwp_status_check_security_plugins(array $probe): array
{
    $active = nibwp_status_active_plugins();
    $known = nibwp_status_known_security_plugins();
    $killers = nibwp_status_rest_killers();

    $found = [];
    foreach ($known as $file => $info) {
        if (isset($active[$file]) && !in_array($file, $killers, true)) {
            $found[$info['name']] = (string) $info['note'];
        }
    }

    if ($found === []) {
        return nibwp_status_result(
            'security_plugins',
            'interference',
            __('Security & firewall plugins', 'nibwp'),
            NIBWP_STATUS_PASS,
            __('No plugin known to filter the REST API is active.', 'nibwp')
        );
    }

    $rest_broken = empty($probe['rest_ok']) || ($probe['auth_header_seen'] === false);
    $lines = [];
    foreach ($found as $name => $note) {
        $lines[] = $name . ' — ' . $note;
    }

    return nibwp_status_result(
        'security_plugins',
        'interference',
        __('Security & firewall plugins', 'nibwp'),
        $rest_broken ? NIBWP_STATUS_WARN : NIBWP_STATUS_INFO,
        sprintf(
            /* translators: %s: comma-separated plugin names */
            __('Active and able to affect API access: %s', 'nibwp'),
            implode(', ', array_keys($found))
        ),
        $rest_broken
            ? __('Something above is failing and these are the most likely places to look. Where to check in each:', 'nibwp')
            : __('These are not causing a problem right now. Listed so you know where to look if one starts. Where to check in each:', 'nibwp'),
        $lines
    );
}

/**
 * @param array<string, mixed> $probe
 * @return array<string, mixed>|null
 */
function nibwp_status_check_rest_filters(array $probe): ?array
{
    if (!has_filter('rest_authentication_errors')) {
        return null;
    }

    global $wp_filter;
    $callbacks = [];
    if (isset($wp_filter['rest_authentication_errors'])) {
        foreach ($wp_filter['rest_authentication_errors'] as $priority => $hooks) {
            foreach ($hooks as $hook) {
                $callbacks[] = nibwp_status_describe_callback($hook['function'] ?? null, (int) $priority);
            }
        }
    }

    // WordPress core registers its own; anything beyond that is third-party.
    $foreign = array_values(array_filter($callbacks, static fn($c) => strpos($c, 'rest_cookie_check_errors') === false));
    if ($foreign === []) {
        return null;
    }

    $blocking = empty($probe['rest_ok']);

    return nibwp_status_result(
        'rest_filters',
        'interference',
        __('Custom REST authentication rules', 'nibwp'),
        $blocking ? NIBWP_STATUS_WARN : NIBWP_STATUS_INFO,
        sprintf(
            /* translators: %d: number of callbacks */
            _n(
                '%d plugin or snippet is hooked into REST authentication.',
                '%d plugins or snippets are hooked into REST authentication.',
                count($foreign),
                'nibwp'
            ),
            count($foreign)
        ),
        __('Code on this hook can reject a request before NIBWP is ever consulted. If authentication fails for no visible reason, this is where to look.', 'nibwp'),
        $foreign
    );
}

/**
 * Describe a hook callback in a form that is useful in a bug report.
 *
 * @param mixed $function
 */
function nibwp_status_describe_callback($function, int $priority): string
{
    $name = __('closure', 'nibwp');

    if (is_string($function)) {
        $name = $function;
    } elseif (is_array($function) && count($function) === 2) {
        $class = is_object($function[0]) ? get_class($function[0]) : (string) $function[0];
        $name = $class . '::' . (string) $function[1];
    } elseif ($function instanceof Closure) {
        try {
            $ref = new ReflectionFunction($function);
            $file = (string) $ref->getFileName();
            if ($file !== '') {
                // A closure is anonymous, so the file it lives in is the only
                // thing that identifies which plugin registered it.
                $name = sprintf(
                    /* translators: 1: file path, 2: line number */
                    __('closure in %1$s:%2$d', 'nibwp'),
                    str_replace(WP_CONTENT_DIR, 'wp-content', $file),
                    $ref->getStartLine()
                );
            }
        } catch (ReflectionException $e) {
            $name = __('closure', 'nibwp');
        }
    }

    return sprintf(
        /* translators: 1: callback name, 2: hook priority */
        __('%1$s (priority %2$d)', 'nibwp'),
        $name,
        $priority
    );
}

/**
 * @param array<string, mixed> $probe
 * @return array<string, mixed>|null
 */
function nibwp_status_check_maintenance(array $probe): ?array
{
    $reasons = [];

    if (file_exists(ABSPATH . '.maintenance')) {
        $reasons[] = __('WordPress is in maintenance mode (a .maintenance file exists).', 'nibwp');
    }
    if (defined('WP_INSTALLING') && constant('WP_INSTALLING')) {
        $reasons[] = __('WordPress reports itself as installing or updating.', 'nibwp');
    }

    $active = nibwp_status_active_plugins();
    foreach ($active as $file => $name) {
        if (preg_match('#(coming-soon|maintenance|under-construction)#i', (string) $file)) {
            $reasons[] = sprintf(
                /* translators: %s: plugin name */
                __('%s is active and may be gating the whole site.', 'nibwp'),
                $name
            );
        }
    }

    if ($reasons === []) {
        return null;
    }

    return nibwp_status_result(
        'maintenance',
        'interference',
        __('Maintenance / coming-soon mode', 'nibwp'),
        NIBWP_STATUS_WARN,
        __('Something is gating public access to this site.', 'nibwp'),
        __('Maintenance and coming-soon screens usually answer every request, including API requests, with a holding page. A client reads that as a broken endpoint.', 'nibwp'),
        array_merge($reasons, [
            __('Exclude the REST API from the holding page, or disable it while you connect.', 'nibwp'),
        ])
    );
}

/**
 * @param array<string, mixed> $probe
 * @return array<string, mixed>
 */
function nibwp_status_check_sandbox(array $probe): array
{
    $report = function_exists('nibwp_sandbox_crash_report') ? nibwp_sandbox_crash_report() : null;

    if ($report === null) {
        return nibwp_status_result(
            'sandbox',
            'extensions',
            __('Sandbox safe mode', 'nibwp'),
            NIBWP_STATUS_PASS,
            __('Safe mode is off; no fatal error has been recorded.', 'nibwp')
        );
    }

    $where = (string) ($report['file'] ?? '');
    $summary = !empty($report['is_external'])
        ? __('Safe mode is on after a fatal error thrown from outside the sandbox.', 'nibwp')
        : __('Safe mode is on after a fatal error during startup.', 'nibwp');

    $detail = (string) ($report['message'] ?? '');
    if ($where !== '') {
        $detail .= "\n" . $where . ':' . (int) ($report['line'] ?? 0);
    }
    if (!empty($report['sandbox_file'])) {
        $detail .= "\n" . sprintf(
            /* translators: %s: sandbox file name */
            __('Loading at the time: %s', 'nibwp'),
            (string) $report['sandbox_file']
        );
    }

    return nibwp_status_result(
        'sandbox',
        'extensions',
        __('Sandbox safe mode', 'nibwp'),
        NIBWP_STATUS_WARN,
        $summary,
        trim($detail),
        [
            !empty($report['is_external'])
                ? __('The error came from outside the sandbox, so look at the file named above rather than at your sandbox plugins.', 'nibwp')
                : __('The error came from inside the sandbox. Fix or disable the file named above.', 'nibwp'),
            __('Then open NIBWP → Sandbox and choose Exit Safe Mode.', 'nibwp'),
        ]
    );
}

/**
 * @param array<string, mixed> $probe
 * @return array<string, mixed>|null
 */
function nibwp_status_check_mu_plugins(array $probe): ?array
{
    if (!function_exists('get_mu_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $mu = get_mu_plugins();
    if ($mu === []) {
        return null;
    }

    $names = [];
    foreach ($mu as $data) {
        $names[] = isset($data['Name']) ? (string) $data['Name'] : __('(unnamed)', 'nibwp');
    }

    return nibwp_status_result(
        'mu_plugins',
        'extensions',
        __('Must-use plugins', 'nibwp'),
        NIBWP_STATUS_INFO,
        sprintf(
            /* translators: %d: number of must-use plugins */
            _n('%d must-use plugin is loaded.', '%d must-use plugins are loaded.', count($names), 'nibwp'),
            count($names)
        ),
        __('Must-use plugins load before everything else and cannot be deactivated from the Plugins screen. Hosts add them silently, and they are a common home for REST or login restrictions.', 'nibwp'),
        $names
    );
}

/**
 * The visual workspace, which fails in a way nothing else here would catch.
 *
 * A site that refuses to be framed leaves the workspace showing an empty panel
 * and no clue why. This never blocks connecting — everything else in NibWP
 * works without the workspace — so it warns rather than fails.
 *
 * @param array<string, mixed> $probe
 * @return array<string, mixed>|null
 */
function nibwp_status_check_visual(array $probe): ?array
{
    if (!function_exists('nibwp_visual_url')) {
        return null;
    }

    $label = __('Visual workspace', 'nibwp');
    $frame = trim((string) ($probe['visual_frame_options'] ?? ''));

    if (stripos($frame, 'deny') !== false) {
        return nibwp_status_result(
            'visual',
            'extensions',
            $label,
            NIBWP_STATUS_WARN,
            __('This site refuses to be shown inside a frame, so the workspace cannot display it.', 'nibwp'),
            sprintf(
                /* translators: %s: the X-Frame-Options header value */
                __('The site sends X-Frame-Options: %s. A security plugin is usually responsible. Same-origin framing has to be allowed for the workspace to show anything; the rest of NibWP is unaffected.', 'nibwp'),
                $frame
            ),
            [
                __('Find the setting that sends X-Frame-Options and change DENY to SAMEORIGIN.', 'nibwp'),
                __('On Cloudflare or a similar proxy, check the response-header transform rules.', 'nibwp'),
                __('A Content-Security-Policy frame-ancestors rule can do the same thing.', 'nibwp'),
            ],
            false
        );
    }

    return nibwp_status_result(
        'visual',
        'extensions',
        $label,
        NIBWP_STATUS_PASS,
        __('The workspace can show this site in a frame.', 'nibwp'),
        $frame !== ''
            ? sprintf(
                /* translators: %s: the X-Frame-Options header value */
                __('The site sends X-Frame-Options: %s, which permits it.', 'nibwp'),
                $frame
            )
            : ''
    );
}

/**
 * @param array<string, mixed> $probe
 * @return array<string, mixed>
 */
function nibwp_status_check_theme(array $probe): array
{
    $theme = wp_get_theme();
    $name = $theme->get('Name') . ' ' . $theme->get('Version');
    $parent = $theme->parent();

    return nibwp_status_result(
        'theme',
        'extensions',
        __('Active theme', 'nibwp'),
        NIBWP_STATUS_INFO,
        $parent
            ? sprintf(
                /* translators: 1: child theme name, 2: parent theme name */
                __('%1$s (child of %2$s)', 'nibwp'),
                $name,
                $parent->get('Name')
            )
            : $name,
        __('Recorded for support. A theme only affects MCP if its functions.php hooks into REST or authentication.', 'nibwp')
    );
}

// ---------------------------------------------------------------------------
// Plain-text report
// ---------------------------------------------------------------------------

/**
 * Render the diagnostic as plain text for pasting into a support ticket.
 *
 * @param array<string, mixed> $run Output of nibwp_status_run().
 */
function nibwp_status_report_text(array $run): string
{
    global $wp_version;

    $labels = [
        NIBWP_STATUS_PASS => 'PASS',
        NIBWP_STATUS_WARN => 'WARN',
        NIBWP_STATUS_FAIL => 'FAIL',
        NIBWP_STATUS_INFO => 'INFO',
    ];

    $lines = [];
    $lines[] = 'NIBWP connection diagnostic';
    $lines[] = 'Generated: ' . gmdate('Y-m-d H:i:s', (int) $run['generated']) . ' UTC';
    $lines[] = 'Site: ' . home_url();
    $lines[] = 'Endpoint: ' . (string) $run['endpoint'];
    $lines[] = 'NIBWP: ' . (defined('NIBWP_VERSION') ? NIBWP_VERSION : 'unknown')
        . ' | WordPress: ' . (string) $wp_version
        . ' | PHP: ' . PHP_VERSION;
    $lines[] = 'Verdict: ' . (string) $run['verdict']['headline'] . ' — ' . (string) $run['verdict']['sub'];
    $lines[] = '';

    foreach (nibwp_status_groups() as $key => $title) {
        if (empty($run['groups'][$key])) {
            continue;
        }
        $lines[] = strtoupper($title);
        foreach ($run['groups'][$key] as $check) {
            $lines[] = sprintf(
                '  [%s] %s — %s',
                ($check['consequence_of'] ?? null) !== null ? 'KNOCK-ON' : ($labels[(string) $check['status']] ?? '????'),
                (string) $check['label'],
                (string) $check['summary']
            );
            if (($check['consequence_of'] ?? null) !== null) {
                $lines[] = '        caused by: ' . (string) $check['consequence_of'];
            }
            if ((string) $check['detail'] !== '') {
                foreach (explode("\n", (string) $check['detail']) as $line) {
                    if (trim($line) !== '') {
                        $lines[] = '        ' . trim($line);
                    }
                }
            }
            foreach ((array) $check['fix'] as $step) {
                $lines[] = '        - ' . (string) $step;
            }
        }
        $lines[] = '';
    }

    return implode("\n", $lines);
}
