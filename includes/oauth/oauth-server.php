<?php

declare(strict_types=1);

/**
 * OAuth 2.1 authorization server — discovery, registration, tokens.
 *
 * These routes are deliberately public. Discovery documents are meant to be read
 * by anyone; the token endpoint authenticates by proof of possession (the PKCE
 * verifier) rather than by cookie, because the client calling it is a background
 * process with no browser session.
 *
 * Metadata is served from REST rather than only from /.well-known/. Measured on
 * live sites, WordPress's canonical redirect appends a trailing slash to
 * well-known paths and the result 404s, and that prefix is also intercepted by
 * some hosts for certificate validation. The spec lets the challenge header name
 * any URL and requires clients to prefer it, so REST is the reliable path and
 * well-known is the fallback.
 */

if (!defined('ABSPATH')) {
    exit();
}

add_action('rest_api_init', 'nibwp_oauth_register_routes');

function nibwp_oauth_register_routes(): void
{
    $public = '__return_true';

    register_rest_route('nibwp/v1', '/oauth/protected-resource', [
        'methods' => 'GET',
        'permission_callback' => $public,
        'callback' => 'nibwp_oauth_protected_resource_metadata',
    ]);

    register_rest_route('nibwp/v1', '/oauth/authorization-server', [
        'methods' => 'GET',
        'permission_callback' => $public,
        'callback' => 'nibwp_oauth_authorization_server_metadata',
    ]);

    register_rest_route('nibwp/v1', '/oauth/register', [
        'methods' => 'POST',
        'permission_callback' => $public,
        'callback' => 'nibwp_oauth_register_client',
    ]);

    register_rest_route('nibwp/v1', '/oauth/token', [
        'methods' => 'POST',
        'permission_callback' => $public,
        'callback' => 'nibwp_oauth_token_endpoint',
    ]);

    register_rest_route('nibwp/v1', '/oauth/revoke', [
        'methods' => 'POST',
        'permission_callback' => $public,
        'callback' => 'nibwp_oauth_revoke_endpoint',
    ]);
}

/**
 * CORS for the OAuth routes and the MCP endpoint.
 *
 * Clients that run in a browser — web IDEs, the inspector, anything embedded —
 * preflight the token and registration calls and read WWW-Authenticate off the
 * refusal. WordPress only sends CORS headers for origins it already trusts, so
 * without this every browser-based sign-in fails on a wall of opaque CORS
 * errors. These endpoints are public by design and carry no cookie
 * authentication, so a wildcard origin gives nothing away.
 */
add_filter('rest_pre_serve_request', 'nibwp_oauth_send_cors_headers', 10, 4);

function nibwp_oauth_send_cors_headers($served, $result, $request, $server)
{
    $route = ltrim((string) $request->get_route(), '/');
    if (!str_starts_with($route, 'nibwp/v1/oauth/') && !str_starts_with($route, 'mcp/')) {
        return $served;
    }

    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Mcp-Session-Id, MCP-Protocol-Version, Last-Event-ID');
    header('Access-Control-Expose-Headers: WWW-Authenticate, Mcp-Session-Id');
    header('Access-Control-Max-Age: 3600');

    return $served;
}

/**
 * Answer the preflight itself.
 *
 * Core replies to OPTIONS with its own CORS logic, which does not know about
 * these routes; a preflight that comes back without the right Allow-Headers
 * kills the real request before it is ever sent.
 */
add_filter('rest_pre_dispatch', static function ($result, $server, $request) {
    if ($request->get_method() !== 'OPTIONS') {
        return $result;
    }

    $route = ltrim((string) $request->get_route(), '/');
    if (!str_starts_with($route, 'nibwp/v1/oauth/') && !str_starts_with($route, 'mcp/')) {
        return $result;
    }

    $response = new WP_REST_Response(null, 204);
    $response->header('Access-Control-Allow-Origin', '*');
    $response->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    $response->header('Access-Control-Allow-Headers', 'Authorization, Content-Type, Mcp-Session-Id, MCP-Protocol-Version, Last-Event-ID');
    $response->header('Access-Control-Max-Age', '3600');

    return $response;
}, 10, 3);

// ---------------------------------------------------------------------------
// Discovery
// ---------------------------------------------------------------------------

/**
 * RFC 9728 — Protected Resource Metadata.
 */
function nibwp_oauth_protected_resource_metadata(): WP_REST_Response
{
    $response = new WP_REST_Response([
        'resource' => nibwp_oauth_resource_id(),
        'authorization_servers' => [nibwp_oauth_issuer()],
        'scopes_supported' => array_keys(nibwp_oauth_scopes()),
        'bearer_methods_supported' => ['header'],
        'resource_name' => get_bloginfo('name') . ' — NibWP',
        'resource_documentation' => 'https://nibwp.com/docs',
        // Advisory. Not required by RFC 9728, but the field name is the one from
        // RFC 7591 client metadata, so a client that shows an icon beside the
        // connector has one to show. Anything else ignores it.
        // Through the helper, so the scheme follows the site rather than the
        // request: an https site publishing an http icon publishes one no
        // browser will load.
        'logo_uri' => nibwp_brand_icon_url(),
    ]);

    // Discovery documents are fetched by clients on other origins.
    $response->header('Access-Control-Allow-Origin', '*');
    $response->header('Cache-Control', 'public, max-age=3600');

    return $response;
}

/**
 * RFC 8414 — Authorization Server Metadata.
 *
 * `issuer` must be byte-identical to the URL a client used to reach this
 * document, or the client is required to reject it.
 */
function nibwp_oauth_authorization_server_metadata(): WP_REST_Response
{
    $response = new WP_REST_Response([
        'issuer' => nibwp_oauth_issuer(),
        'authorization_endpoint' => nibwp_oauth_authorize_url(),
        'token_endpoint' => rest_url('nibwp/v1/oauth/token'),
        'registration_endpoint' => rest_url('nibwp/v1/oauth/register'),
        'revocation_endpoint' => rest_url('nibwp/v1/oauth/revoke'),
        'scopes_supported' => array_keys(nibwp_oauth_scopes()),
        'response_types_supported' => ['code'],
        'grant_types_supported' => ['authorization_code', 'refresh_token'],
        // PKCE is mandatory under OAuth 2.1, and plain is not offered.
        'code_challenge_methods_supported' => ['S256'],
        'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post'],
        'client_id_metadata_document_supported' => true,
        'authorization_response_iss_parameter_supported' => true,
        'service_documentation' => 'https://nibwp.com/docs',
        // Not in RFC 8414, but several clients look for it here before they
        // fall back to drawing the first letter of the name.
        'logo_uri' => nibwp_brand_icon_url(),
    ]);

    $response->header('Access-Control-Allow-Origin', '*');
    $response->header('Cache-Control', 'public, max-age=3600');

    return $response;
}

// ---------------------------------------------------------------------------
// Client registration (RFC 7591)
// ---------------------------------------------------------------------------

/**
 * Dynamic client registration.
 *
 * Open by design — that is what the protocol requires, and it is how every
 * shipping AI client obtains an identity. Registration grants nothing on its
 * own: no data is reachable until a human approves a consent screen while logged
 * in, so the worst a flood of registrations achieves is unused option rows.
 */
/**
 * Rate limit and cap for the open registration endpoint.
 *
 * Two separate ceilings: a short window per caller, so one source cannot spray
 * registrations, and a total across the site, so a slow drip cannot either.
 * Registrations nobody ever signed in with are swept first, which is what keeps
 * the total from being reached by abandoned attempts rather than real clients.
 */
function nibwp_oauth_registration_within_limits(): bool
{
    nibwp_oauth_prune_unused_clients();

    $max_clients = (int) apply_filters('nibwp_oauth_max_clients', 200);
    if (count(nibwp_oauth_clients()) >= $max_clients) {
        return false;
    }

    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $key = 'nibwp_oauth_reg_' . md5($ip);
    $count = (int) get_transient($key);
    $per_window = (int) apply_filters('nibwp_oauth_registrations_per_hour', 20);

    if ($count >= $per_window) {
        return false;
    }

    set_transient($key, $count + 1, HOUR_IN_SECONDS);

    return true;
}

/**
 * Forget registrations that were never used to sign in.
 *
 * A client record only matters once someone approves it. One registered an hour
 * ago and never carried through is either an abandoned attempt or noise, and
 * either way it should not occupy a slot forever.
 */
function nibwp_oauth_prune_unused_clients(): void
{
    $clients = nibwp_oauth_clients();
    if ($clients === []) {
        return;
    }

    $granted = [];
    foreach (nibwp_oauth_all_granted_client_ids() as $id) {
        $granted[$id] = true;
    }

    $cutoff = time() - DAY_IN_SECONDS;
    $keep = [];
    foreach ($clients as $id => $client) {
        $created = (int) ($client['created'] ?? 0);
        $manual = !empty($client['manual']);
        if ($manual || isset($granted[$id]) || $created === 0 || $created > $cutoff) {
            $keep[$id] = $client;
        }
    }

    if (count($keep) !== count($clients)) {
        update_option(NIBWP_OAUTH_CLIENTS_OPTION, $keep, false);
    }
}

/**
 * Client ids that hold a live token for any user on this site.
 *
 * @return list<string>
 */
function nibwp_oauth_all_granted_client_ids(): array
{
    global $wpdb;

    /** @var array<int, string> $rows */
    $rows = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s",
            NIBWP_OAUTH_TOKENS_META,
        ),
    );

    $ids = [];
    foreach ($rows as $row) {
        $tokens = maybe_unserialize($row);
        if (!is_array($tokens)) {
            continue;
        }
        foreach ($tokens as $token) {
            $id = is_array($token) ? (string) ($token['client_id'] ?? '') : '';
            if ($id !== '') {
                $ids[$id] = true;
            }
        }
    }

    return array_keys($ids);
}

function nibwp_oauth_register_client(WP_REST_Request $request)
{
    // Registration has to answer unauthenticated callers — that is the point of
    // it — which makes it the one endpoint anyone on the internet can write to.
    // Unmetered, it grows an option row without limit.
    if (!nibwp_oauth_registration_within_limits()) {
        return nibwp_oauth_error(
            'temporarily_unavailable',
            'Too many registration attempts. Try again shortly.',
            429
        );
    }

    $body = $request->get_json_params();
    if (!is_array($body)) {
        $body = $request->get_params();
    }

    $redirect_uris = array_values(array_filter(array_map(
        'strval',
        (array) ($body['redirect_uris'] ?? [])
    )));

    if ($redirect_uris === []) {
        return nibwp_oauth_error('invalid_redirect_uri', 'At least one redirect_uri is required.', 400);
    }

    foreach ($redirect_uris as $uri) {
        if (!nibwp_oauth_redirect_uri_is_valid($uri)) {
            return nibwp_oauth_error(
                'invalid_redirect_uri',
                sprintf('redirect_uri must be https, a loopback address, or a custom scheme: %s', $uri),
                400
            );
        }
    }

    $client_id = 'nibwp-client-' . wp_generate_password(24, false, false);
    $now = time();

    $client = [
        'client_id' => $client_id,
        'client_name' => sanitize_text_field((string) ($body['client_name'] ?? 'Unnamed client')),
        'client_uri' => esc_url_raw((string) ($body['client_uri'] ?? '')),
        'logo_uri' => esc_url_raw((string) ($body['logo_uri'] ?? '')),
        'redirect_uris' => $redirect_uris,
        'grant_types' => ['authorization_code', 'refresh_token'],
        'response_types' => ['code'],
        'token_endpoint_auth_method' => 'none',
        'application_type' => sanitize_key((string) ($body['application_type'] ?? 'native')),
        'registered' => $now,
        'source' => 'dcr',
    ];

    nibwp_oauth_save_client($client_id, $client);

    return new WP_REST_Response([
        'client_id' => $client_id,
        'client_id_issued_at' => $now,
        'client_name' => $client['client_name'],
        'redirect_uris' => $redirect_uris,
        'grant_types' => $client['grant_types'],
        'response_types' => $client['response_types'],
        'token_endpoint_auth_method' => 'none',
    ], 201);
}

/**
 * Resolve a client id to a client record.
 *
 * An HTTPS URL is a Client ID Metadata Document: fetch it, and require the
 * document to name itself. Without that check, anyone could claim any client id
 * by hosting a document that points somewhere else.
 *
 * @return array<string, mixed>|null
 */
function nibwp_oauth_resolve_client(string $client_id): ?array
{
    $stored = nibwp_oauth_client($client_id);
    if ($stored !== null) {
        return $stored;
    }

    if (!str_starts_with($client_id, 'https://')) {
        return null;
    }

    // The URL came from an unauthenticated caller, so refuse to fetch anything
    // this server can reach but the public cannot.
    if (!nibwp_oauth_url_is_fetchable($client_id)) {
        return null;
    }

    $cache_key = 'nibwp_oauth_cimd_' . md5($client_id);
    $cached = get_transient($cache_key);
    if (is_array($cached)) {
        return $cached;
    }

    // The guard above only ever sees the URL the caller supplied. A redirect is
    // a second URL it never inspected, so this must not follow one: a public
    // host answering `302 -> http://127.0.0.1/` would otherwise walk straight
    // back through the door. A metadata document names itself at its own URL —
    // if it moved, the client id moved with it.
    $response = wp_safe_remote_get($client_id, [
        'timeout' => 10,
        'redirection' => 0,
        'headers' => ['Accept' => 'application/json'],
    ]);
    if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
        return null;
    }

    $doc = json_decode((string) wp_remote_retrieve_body($response), true);
    if (!is_array($doc)) {
        return null;
    }

    // The document must claim exactly the id it was fetched from.
    if (!isset($doc['client_id']) || !hash_equals((string) $doc['client_id'], $client_id)) {
        return null;
    }

    $redirect_uris = array_values(array_filter(array_map('strval', (array) ($doc['redirect_uris'] ?? []))));
    if ($redirect_uris === []) {
        return null;
    }

    $client = [
        'client_id' => $client_id,
        'client_name' => sanitize_text_field((string) ($doc['client_name'] ?? $client_id)),
        'client_uri' => esc_url_raw((string) ($doc['client_uri'] ?? '')),
        'logo_uri' => esc_url_raw((string) ($doc['logo_uri'] ?? '')),
        'redirect_uris' => $redirect_uris,
        'source' => 'cimd',
        'registered' => time(),
    ];

    set_transient($cache_key, $client, HOUR_IN_SECONDS);

    return $client;
}

// ---------------------------------------------------------------------------
// Token endpoint
// ---------------------------------------------------------------------------

function nibwp_oauth_token_endpoint(WP_REST_Request $request)
{
    $params = $request->get_params();
    $grant = (string) ($params['grant_type'] ?? '');

    if ($grant === 'refresh_token') {
        return nibwp_oauth_grant_refresh($params);
    }

    if ($grant === 'authorization_code') {
        return nibwp_oauth_grant_authorization_code($params);
    }

    return nibwp_oauth_error('unsupported_grant_type', 'Supported grants: authorization_code, refresh_token.', 400);
}

/**
 * @param array<string, mixed> $params
 */
function nibwp_oauth_grant_authorization_code(array $params)
{
    $code = (string) ($params['code'] ?? '');
    $verifier = (string) ($params['code_verifier'] ?? '');
    $client_id = (string) ($params['client_id'] ?? '');

    if ($code === '' || $verifier === '') {
        return nibwp_oauth_error('invalid_request', 'code and code_verifier are required.', 400);
    }

    $payload = nibwp_oauth_consume_code($code);
    if ($payload === null) {
        // Covers expiry, an unknown code, and — because consumption deletes the
        // record — any attempt to replay one that was already exchanged.
        return nibwp_oauth_error('invalid_grant', 'This authorization code is not valid. It may have expired or already been used.', 400);
    }

    if (!hash_equals((string) ($payload['client_id'] ?? ''), $client_id)) {
        return nibwp_oauth_error('invalid_grant', 'This code was issued to a different client.', 400);
    }

    // PKCE: the verifier must hash to the challenge recorded at authorize time.
    $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    if (!hash_equals((string) ($payload['code_challenge'] ?? ''), $expected)) {
        return nibwp_oauth_error('invalid_grant', 'PKCE verification failed.', 400);
    }

    if (isset($params['redirect_uri']) && !hash_equals((string) $payload['redirect_uri'], (string) $params['redirect_uri'])) {
        return nibwp_oauth_error('invalid_grant', 'redirect_uri does not match the authorization request.', 400);
    }

    $issued = nibwp_oauth_issue_token(
        (int) $payload['user_id'],
        $client_id,
        (array) $payload['scopes'],
        (string) ($payload['resource'] ?? nibwp_oauth_resource_id())
    );

    return nibwp_oauth_token_response($issued, (array) $payload['scopes']);
}

/**
 * @param array<string, mixed> $params
 */
function nibwp_oauth_grant_refresh(array $params)
{
    $refresh = (string) ($params['refresh_token'] ?? '');
    $client_id = (string) ($params['client_id'] ?? '');

    if ($refresh === '') {
        return nibwp_oauth_error('invalid_request', 'refresh_token is required.', 400);
    }

    $issued = nibwp_oauth_refresh($refresh, $client_id);
    if ($issued === null) {
        return nibwp_oauth_error('invalid_grant', 'This refresh token is not valid. Sign in again to reconnect.', 400);
    }

    $validated = nibwp_oauth_validate_token($issued['access_token']);
    $scopes = (array) ($validated['record']['scopes'] ?? []);

    return nibwp_oauth_token_response($issued, $scopes);
}

/**
 * @param array{access_token: string, refresh_token: string, expires_in: int} $issued
 * @param array<int, string> $scopes
 */
function nibwp_oauth_token_response(array $issued, array $scopes): WP_REST_Response
{
    $response = new WP_REST_Response([
        'access_token' => $issued['access_token'],
        'token_type' => 'Bearer',
        'expires_in' => $issued['expires_in'],
        'refresh_token' => $issued['refresh_token'],
        'scope' => implode(' ', $scopes),
    ]);

    // Tokens must never be cached by an intermediary.
    $response->header('Cache-Control', 'no-store');
    $response->header('Pragma', 'no-cache');

    return $response;
}

// ---------------------------------------------------------------------------
// Revocation (RFC 7009)
// ---------------------------------------------------------------------------

function nibwp_oauth_revoke_endpoint(WP_REST_Request $request): WP_REST_Response
{
    $token = (string) ($request->get_param('token') ?? '');

    // The token is the credential here — this route is unauthenticated, so
    // presenting it has to mean holding it. Parsing alone is not enough: a
    // token string carries its user id and token id in the clear, so anyone who
    // learned those from a log could otherwise hang up someone else's
    // connection by sending them back with the secret replaced.
    foreach (['nibwp_at_' => 'hash', 'nibwp_rt_' => 'refresh_hash'] as $prefix => $field) {
        $parsed = nibwp_oauth_parse_token($token, $prefix);
        if ($parsed === null) {
            continue;
        }

        $record = nibwp_oauth_user_tokens($parsed['user_id'])[$parsed['token_id']] ?? null;
        if (is_array($record) && hash_equals((string) ($record[$field] ?? ''), hash('sha256', $parsed['secret']))) {
            nibwp_oauth_revoke_token($parsed['user_id'], $parsed['token_id']);
        }
        break;
    }

    // RFC 7009: always 200, even for an unknown token — telling a caller whether
    // a token existed would make this endpoint an oracle.
    return new WP_REST_Response(null, 200);
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * An OAuth-shaped error. The spec defines the body; WP_Error would serialise
 * WordPress's own shape instead, which clients do not parse.
 */
function nibwp_oauth_error(string $code, string $description, int $status): WP_REST_Response
{
    $response = new WP_REST_Response([
        'error' => $code,
        'error_description' => $description,
    ], $status);

    $response->header('Cache-Control', 'no-store');

    return $response;
}

// ---------------------------------------------------------------------------
// .well-known fallback
// ---------------------------------------------------------------------------

/**
 * Serve the discovery documents from their well-known paths too.
 *
 * Clients must prefer the URL in the challenge header, so this is belt and
 * braces for any that skip it. `redirect_canonical` has to be suppressed first:
 * left alone it 301s these paths to a trailing slash, which then 404s — measured
 * on a live install, and silent enough that it would look like the feature
 * simply did not work.
 */
add_action('init', 'nibwp_oauth_add_rewrites');

function nibwp_oauth_add_rewrites(): void
{
    // The authorize endpoint gets a clean path of its own — see
    // nibwp_oauth_authorize_url() for why a query string there is a liability.
    add_rewrite_rule('^nibwp-oauth/authorize/?$', 'index.php?nibwp_oauth_authorize=1', 'top');
}

add_filter('query_vars', static function (array $vars): array {
    $vars[] = 'nibwp_oauth_authorize';

    return $vars;
});

/**
 * Every path a client may ask for a discovery document on.
 *
 * There is more than one because the specifications disagree about where the
 * document lives. RFC 8414 *inserts* the well-known segment between the host and
 * the issuer's path; OpenID Connect *appends* it. Both are in the wild, and the
 * insert form only reaches WordPress when it owns the domain root — so a
 * subdirectory install that served the insert form alone would be undiscoverable.
 *
 * @return array{resource: list<string>, server: list<string>}
 */
function nibwp_oauth_discovery_paths(): array
{
    $home = rtrim((string) wp_parse_url(home_url(), PHP_URL_PATH), '/');
    $resource = (string) wp_parse_url(nibwp_oauth_resource_id(), PHP_URL_PATH);

    return [
        'resource' => array_values(array_unique([
            $home . '/.well-known/oauth-protected-resource',
            '/.well-known/oauth-protected-resource' . $resource,
        ])),
        'server' => array_values(array_unique([
            // OIDC's append form works under a subdirectory, which is why it is
            // first: it is the one shape that never needs the domain root.
            $home . '/.well-known/openid-configuration',
            $home . '/.well-known/oauth-authorization-server',
            '/.well-known/oauth-authorization-server' . $home,
            '/.well-known/openid-configuration' . $home,
        ])),
    ];
}

/**
 * Every discovery URL a diagnostic should try, in the order a client would, each
 * carrying the field that proves the document is this site's and how much a miss
 * matters.
 *
 * URLs are built from the site's origin plus the computed path, never
 * home_url($path): on a subdirectory install that would prepend the
 * subdirectory a second time and probe an address nothing serves.
 *
 * `required` — the URL named in the challenge header. Nothing works without it.
 * `any`      — the authorization-server document has interchangeable forms; one
 *              answering is a pass, so a blocked form is not a failure.
 * `optional` — informational. The insert form lands on the domain root, which a
 *              subdirectory install does not own.
 *
 * @return list<array{url: string, field: string, expected: string, requirement: string, label: string}>
 */
function nibwp_oauth_discovery_probes(): array
{
    $home = home_url();
    $parts = wp_parse_url($home);
    $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '')
        . (isset($parts['port']) ? ':' . $parts['port'] : '');

    $paths = nibwp_oauth_discovery_paths();
    $resource = nibwp_oauth_resource_id();
    $issuer = nibwp_oauth_issuer();

    $probes = [
        [$paths['resource'][0], 'resource', $resource, 'required', __('Endpoint metadata', 'nibwp')],
    ];

    if (isset($paths['resource'][1])) {
        $probes[] = [$paths['resource'][1], 'resource', $resource, 'optional', __('Endpoint metadata (root form)', 'nibwp')];
    }

    $labels = [
        __('Sign-in metadata (OpenID form)', 'nibwp'),
        __('Sign-in metadata (OAuth form)', 'nibwp'),
        __('Sign-in metadata (root form)', 'nibwp'),
        __('Sign-in metadata (root OpenID form)', 'nibwp'),
    ];
    foreach ($paths['server'] as $i => $path) {
        $probes[] = [$path, 'issuer', $issuer, 'any', $labels[$i] ?? __('Sign-in metadata', 'nibwp')];
    }

    $out = [];
    $seen = [];
    foreach ($probes as [$path, $field, $expected, $requirement, $label]) {
        $url = $origin . $path;
        if (isset($seen[$url])) {
            continue;
        }
        $seen[$url] = true;
        $out[] = [
            'url' => $url,
            'field' => $field,
            'expected' => $expected,
            'requirement' => $requirement,
            'label' => $label,
        ];
    }

    return $out;
}

/**
 * Answer the discovery paths directly, before WordPress routes anything.
 *
 * Not a rewrite rule. Rules need a flush to take effect, do nothing on sites
 * using plain permalinks, and lose to WordPress's canonical redirect, which
 * appends a trailing slash to these paths and turns the result into a 404 —
 * measured on a live install, and silent enough to look like the feature simply
 * did not work. Matching the request path on `init` has none of those failure
 * modes.
 */
add_action('init', 'nibwp_oauth_serve_discovery', 1);

function nibwp_oauth_serve_discovery(): void
{
    $path = wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (!is_string($path) || !str_contains($path, '/.well-known/')) {
        return;
    }

    $path = rtrim($path, '/');
    if ($path === '') {
        return;
    }

    $paths = nibwp_oauth_discovery_paths();
    $which = null;
    if (in_array($path, $paths['resource'], true)) {
        $which = 'resource';
    } elseif (in_array($path, $paths['server'], true)) {
        $which = 'server';
    }

    if ($which === null) {
        return;
    }

    $data = $which === 'resource'
        ? nibwp_oauth_protected_resource_metadata()->get_data()
        : nibwp_oauth_authorization_server_metadata()->get_data();

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: public, max-age=3600');
    }
    echo wp_json_encode($data);
    exit;
}

/**
 * Serve the consent screen from the clean path.
 *
 * `parse_request` rather than `template_redirect`: the consent screen is not a
 * template and must not wait for a theme to load, and an unauthenticated visitor
 * has to hit auth_redirect() before WordPress decides this is a 404.
 */
add_action('parse_request', static function ($wp): void {
    if (empty($wp->query_vars['nibwp_oauth_authorize'])) {
        return;
    }

    if (!is_user_logged_in()) {
        auth_redirect();
        exit;
    }

    nibwp_oauth_handle_authorize();
    exit;
});

/**
 * Flush rewrites once, when the rules change.
 *
 * Adding a rule does nothing until the rewrite cache is rebuilt, and an existing
 * install never reactivates the plugin — so without this the well-known paths
 * would 404 for everyone who upgraded rather than installed fresh. Version-gated
 * so it costs one flush, not one per request.
 */
add_action('init', static function (): void {
    // Bumped when the rules change. '3' retires the two well-known rules the
    // init handler above replaced; leaving them cached would route those paths
    // to a query var nothing reads any more.
    $stamp = '3';
    if (get_option('nibwp_oauth_rewrite_version') === $stamp) {
        return;
    }

    flush_rewrite_rules(false);
    update_option('nibwp_oauth_rewrite_version', $stamp, false);
}, 99);
