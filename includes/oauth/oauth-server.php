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
/**
 * The bucket a registration attempt counts against.
 *
 * REMOTE_ADDR alone was the whole key, and behind Cloudflare or any reverse
 * proxy REMOTE_ADDR is the proxy — so every AI client registering through it
 * shared one 20-per-hour bucket, and the twenty-first person on the site that
 * hour got an unexplained refusal inside their own AI client. That is the
 * customer-reported "could not register with the sign-in service".
 *
 * The claimed client IP (CF-Connecting-IP, or the last X-Forwarded-For hop —
 * the one appended by the nearest proxy, hardest for the origin caller to
 * choose) is CONCATENATED onto REMOTE_ADDR, never substituted for it. That is
 * the entire spoofing analysis: a forged claim can only subdivide the
 * forger's own transport bucket, it can never land in anyone else's. The
 * house rule (agent-guardrails.php) that forwarded headers must not identify
 * a caller still holds — here they only partition one.
 */
function nibwp_oauth_registration_bucket(): string
{
    $transport = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    $claimed = '';
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $claimed = trim((string) $_SERVER['HTTP_CF_CONNECTING_IP']);
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $hops = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
        $claimed = trim((string) end($hops));
    }

    // A claim that is not a public IP partitions nothing — collapse to the
    // plain transport bucket rather than letting garbage mint fresh buckets.
    if ($claimed !== '' && !filter_var($claimed, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        $claimed = '';
    }

    return $claimed === '' ? $transport : $transport . '|' . $claimed;
}

/**
 * @return 'ok'|'window'|'cap' Why a registration may or may not proceed.
 */
function nibwp_oauth_registration_within_limits(): string
{
    nibwp_oauth_prune_unused_clients();

    $max_clients = (int) apply_filters('nibwp_oauth_max_clients', 200);
    if (count(nibwp_oauth_clients()) >= $max_clients) {
        // Under cap pressure, unconsented hour-old registrations lose their
        // seat. DCR's contract is that an unused client may simply register
        // again, so an hour of patience is all it is owed — while the 24-hour
        // sweep alone let a flood of junk registrations lock every legitimate
        // client out for a day.
        nibwp_oauth_prune_unused_clients(HOUR_IN_SECONDS);
        if (count(nibwp_oauth_clients()) >= $max_clients) {
            return 'cap';
        }
    }

    $bucket = nibwp_oauth_registration_bucket();
    $key = 'nibwp_oauth_reg_' . md5($bucket);
    $count = (int) get_transient($key);
    $per_window = (int) apply_filters('nibwp_oauth_registrations_per_hour', 20);

    if ($count >= $per_window) {
        return 'window';
    }

    // The coarser ceiling on the transport address alone is what makes the
    // per-bucket limit unspoofable in aggregate: without it, rotating claimed
    // IPs would mint unlimited fresh buckets from a single connection.
    $transport = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $transport_key = 'nibwp_oauth_regt_' . md5($transport);
    $transport_count = (int) get_transient($transport_key);
    $per_transport = (int) apply_filters('nibwp_oauth_registrations_per_hour_per_transport', 60);

    if ($transport_count >= $per_transport) {
        return 'window';
    }

    set_transient($key, $count + 1, HOUR_IN_SECONDS);
    set_transient($transport_key, $transport_count + 1, HOUR_IN_SECONDS);

    return 'ok';
}

/**
 * Forget registrations that were never used to sign in.
 *
 * A client record only matters once someone approves it. One registered an hour
 * ago and never carried through is either an abandoned attempt or noise, and
 * either way it should not occupy a slot forever.
 */
function nibwp_oauth_prune_unused_clients(int $max_age = DAY_IN_SECONDS): void
{
    $clients = nibwp_oauth_clients();
    if ($clients === []) {
        return;
    }

    $granted = [];
    foreach (nibwp_oauth_all_granted_client_ids() as $id) {
        $granted[$id] = true;
    }

    $now = time();
    $cutoff = $now - $max_age;
    $keep = [];
    $dated = false;
    foreach ($clients as $id => $client) {
        // A record made by hand is waiting to be pasted into an AI client, and
        // one that holds a token is in use by somebody. Neither is an abandoned
        // attempt, whatever its age, so neither is ever swept.
        if (!empty($client['manual']) || isset($granted[$id])) {
            $keep[$id] = $client;
            continue;
        }

        // Two spellings of one fact: the manual form writes `created`, dynamic
        // registration writes `registered`. Reading only `created` dated every
        // dynamic registration to zero — and zero was then treated as "keep
        // forever". The one kind of record an anonymous caller can create was
        // the one kind this sweep never removed, so a patient or distributed
        // caller could fill the 200-client cap with registrations nobody had
        // consented to and every legitimate AI client was refused with 429 from
        // then on, permanently. Reading both keys is what lets the cap recover.
        $age_from = (int) ($client['created'] ?? 0);
        if ($age_from === 0) {
            $age_from = (int) ($client['registered'] ?? 0);
        }

        if ($age_from === 0) {
            // Undatable, so there is no age to compare against the cutoff.
            // Stamping it on first sight rather than keeping it forever is the
            // difference between a record that ages out one window late and one
            // that holds a seat against the cap until an administrator notices
            // and deletes it by hand. Deleting it outright is the wrong call in
            // the other direction: a record we cannot date is not evidence of
            // abuse, and it may be a connection somebody is mid-way through.
            $client['registered'] = $now;
            $keep[$id] = $client;
            $dated = true;
            continue;
        }

        if ($age_from > $cutoff) {
            $keep[$id] = $client;
        }
    }

    if ($dated || count($keep) !== count($clients)) {
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
    $limit = nibwp_oauth_registration_within_limits();
    if ($limit !== 'ok') {
        // Retry-After turns an opaque refusal into one the AI client can
        // schedule around, and the two causes get their own sentence because
        // only one of them is something the site owner can act on.
        $response = nibwp_oauth_error(
            'temporarily_unavailable',
            $limit === 'cap'
                ? 'This site has reached its connected-client limit. A site administrator can remove unused clients under NibWP - Connect - Manage connections.'
                : 'This site has received too many client registrations from your network in the past hour. Wait a while and try again.',
            429
        );
        $response->header('Retry-After', '3600');

        return $response;
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
 * The resource document has a form that carries the resource path, and that one
 * belongs to this server alone. The bare form does not: there is one of it per
 * site, and whichever plugin answers first owns it for every other plugin
 * installed. Reported from the field — a second OAuth-enabled MCP server on the
 * same install was undiscoverable because we answered its clients with our
 * document. So the bare form is claimed only where it is the sole form that
 * reaches WordPress, and is filterable for installs that want it back.
 *
 * @return array{resource: list<string>, server: list<string>}
 */
function nibwp_oauth_discovery_paths(): array
{
    $home = rtrim((string) wp_parse_url(home_url(), PHP_URL_PATH), '/');
    $resource = (string) wp_parse_url(nibwp_oauth_resource_id(), PHP_URL_PATH);

    // Degenerate under plain permalinks: the REST route lives in a query string,
    // so there is no path to carry and the scoped form collapses onto the bare
    // one. Under a subdirectory install the scoped form lands on the domain
    // root, which WordPress does not own. Either way the bare form is then the
    // only address a client can reach, and refusing to serve it would trade one
    // site's conflict for another site's broken sign-in.
    $bare = '/.well-known/oauth-protected-resource';
    $scoped = rtrim($bare . $resource, '/');

    $resource_paths = [];
    if ($scoped !== $bare) {
        $resource_paths[] = $scoped;
    }
    if ((bool) apply_filters('nibwp_oauth_serve_bare_wellknown', $resource_paths === [] || $home !== '')) {
        $resource_paths[] = $home . $bare;
    }

    return [
        'resource' => array_values(array_unique($resource_paths)),
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
 * `optional` — informational. Anything past the first resource form is a
 *              fallback we may not even be serving, so a miss is not a failure.
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

    // The first form is the one the challenge header names, so it is the one
    // that has to answer. A filter can leave this list empty; that is a choice
    // to rely on the REST metadata alone, not a fault to report.
    $probes = [];
    foreach ($paths['resource'] as $i => $path) {
        $probes[] = [
            $path,
            'resource',
            $resource,
            $i === 0 ? 'required' : 'optional',
            $i === 0 ? __('Endpoint metadata', 'nibwp') : __('Endpoint metadata (root form)', 'nibwp'),
        ];
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

    // The client-derived shapes the matcher now answers beyond the canonical
    // list — some clients suffix the server document with the resource path.
    // Optional: their absence breaks nothing, their presence is worth showing.
    $resource_path = rtrim((string) wp_parse_url($resource, PHP_URL_PATH), '/');
    if ($resource_path !== '') {
        $probes[] = [
            '/.well-known/oauth-authorization-server' . $resource_path,
            'issuer',
            $issuer,
            'optional',
            __('Sign-in metadata (resource-suffixed form)', 'nibwp'),
        ];
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
/**
 * Which discovery document a well-known path is asking for, if any.
 *
 * The old exact-path list answered only the variants the RFCs strictly
 * require, and real clients ask for more: ChatGPT and Claude derive extra
 * shapes — the authorization-server document suffixed with the resource path,
 * the bare protected-resource form — and every unanswered shape fell through
 * to the theme's 404 page, which on some hosts is slow enough that the client
 * gives up before trying the next shape. Reproduced live on two production
 * installs.
 *
 * So: any recognisable variant of the SERVER document is answered, because
 * that document has one owner per issuer and answering it can take nothing
 * from anyone. The RESOURCE document is different — the bare form is shared
 * ground. A second OAuth-enabled MCP plugin on the same site publishes its own
 * resource document, and a field bug taught us what happens when we answer for
 * it. Its suffixed forms are answered only for OUR resource path; every other
 * suffix falls through to whoever owns it, and the bare form stays delegated
 * to nibwp_oauth_discovery_paths(), the one place that owns that decision.
 *
 * @return 'resource'|'server'|null
 */
function nibwp_oauth_match_discovery_path(string $path): ?string
{
    $marker = '/.well-known/';
    $at = strpos($path, $marker);
    if ($at === false) {
        return null;
    }

    $prefix = rtrim(substr($path, 0, $at), '/');
    $rest = substr($path, $at + strlen($marker));

    $home = rtrim((string) wp_parse_url(home_url(), PHP_URL_PATH), '/');
    if ($prefix !== '' && $prefix !== $home) {
        return null;
    }

    $resource = rtrim((string) wp_parse_url(nibwp_oauth_resource_id(), PHP_URL_PATH), '/');

    foreach (['oauth-authorization-server', 'openid-configuration'] as $doc) {
        if ($rest !== $doc && !str_starts_with($rest, $doc . '/')) {
            continue;
        }
        $suffix = rtrim(substr($rest, strlen($doc)), '/');
        if (in_array($suffix, ['', $home, $resource], true)) {
            return 'server';
        }

        return null;
    }

    if ($rest === 'oauth-protected-resource' || str_starts_with($rest, 'oauth-protected-resource/')) {
        $suffix = rtrim(substr($rest, strlen('oauth-protected-resource')), '/');
        if ($suffix !== '' && $suffix === $resource) {
            return 'resource';
        }
        if ($suffix === '' && in_array($path, nibwp_oauth_discovery_paths()['resource'], true)) {
            return 'resource';
        }
    }

    return null;
}

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

    // Follow the master switch, like the endpoint itself does. With abilities
    // off there is no `mcp/nibwp` route to describe, and these paths are shared
    // with every other plugin on the site — answering on them while serving
    // nothing is how a switched-off NibWP still took discovery away from a
    // second OAuth server.
    if (function_exists('nibwp_is_enabled') && !nibwp_is_enabled()) {
        return;
    }

    $which = nibwp_oauth_match_discovery_path($path);
    if ($which === null) {
        return;
    }

    // Browsers preflight cross-origin fetches of these documents. The old
    // handler answered OPTIONS with the JSON body and no method headers, which
    // reads as a failed preflight and a blocked fetch.
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
        if (!headers_sent()) {
            status_header(204);
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, OPTIONS');
            header('Access-Control-Allow-Headers: Authorization, Content-Type, MCP-Protocol-Version');
            header('Access-Control-Max-Age: 3600');
        }
        exit;
    }

    $data = $which === 'resource'
        ? nibwp_oauth_protected_resource_metadata()->get_data()
        : nibwp_oauth_authorization_server_metadata()->get_data();

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, MCP-Protocol-Version');
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
add_action('parse_request', 'nibwp_oauth_maybe_serve_authorize');

function nibwp_oauth_maybe_serve_authorize($wp): void
{
    // The query var only exists when the rewrite rule matched, and rewrite
    // rules go stale: a failed flush, a migrated database, a host that
    // regenerates permalinks without our rule. The advertised URL then 404s
    // with nothing anywhere saying why. $wp->request is the raw path from
    // REQUEST_URI, set before any rule runs — matching it directly makes the
    // sign-in URL independent of rewrite state altogether.
    if (empty($wp->query_vars['nibwp_oauth_authorize'])
        && trim((string) ($wp->request ?? ''), '/') !== 'nibwp-oauth/authorize') {
        return;
    }

    if (!is_user_logged_in()) {
        auth_redirect();
        exit;
    }

    nibwp_oauth_handle_authorize();
    exit;
}

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
