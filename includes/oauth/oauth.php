<?php

declare(strict_types=1);

/**
 * OAuth 2.1 — storage and token lifecycle.
 *
 * The site is its own authorization server. Nothing here talks to nibwp.com:
 * tokens are minted, stored and validated entirely on the customer's install,
 * so this works on an intranet, behind a VPN, and without trusting us with
 * access to anyone's site.
 *
 * Access tokens are stored as SHA-256 hashes, exactly like WordPress stores
 * application passwords — a database dump does not yield working credentials.
 * They are opaque random strings rather than JWTs: no signing keys to manage or
 * rotate, and revocation takes effect on the very next request instead of
 * whenever some expiry elapses.
 */

if (!defined('ABSPATH')) {
    exit();
}

const NIBWP_OAUTH_CLIENTS_OPTION = 'nibwp_oauth_clients';
const NIBWP_OAUTH_TOKENS_META = 'nibwp_oauth_tokens';
const NIBWP_OAUTH_CODE_PREFIX = 'nibwp_oauth_code_';

/** Authorization codes are single-use and short-lived; 60s is ample for a redirect. */
const NIBWP_OAUTH_CODE_TTL = 60;

/** Access tokens expire so a leaked one has a bounded blast radius. */
const NIBWP_OAUTH_TOKEN_TTL = 30 * DAY_IN_SECONDS;
const NIBWP_OAUTH_REFRESH_TTL = 180 * DAY_IN_SECONDS;

// ---------------------------------------------------------------------------
// Identity of this authorization server
// ---------------------------------------------------------------------------

/**
 * rest_url() that also works before WordPress has built $wp_rewrite.
 *
 * `determine_current_user` is not a late hook. It fires the first time anything
 * asks who is logged in, and plugins ask during `plugins_loaded` — Elementor
 * Pro calls is_user_logged_in() inside its own constructor. That is 41 lines of
 * wp-settings.php BEFORE `$GLOBALS['wp_rewrite']` is created.
 *
 * core's get_rest_url() dereferences that global unguarded whenever a site has
 * pretty permalinks:
 *
 *     if ( get_option( 'permalink_structure' ) ) {
 *         global $wp_rewrite;
 *         if ( $wp_rewrite->using_index_permalinks() ) {   // <- null at plugins_loaded
 *
 * so our audience check fataled the whole request with "Call to a member
 * function using_index_permalinks() on null", and every authenticated MCP call
 * came back as a 500. A customer read that as NibWP and Elementor Pro being
 * incompatible and worked around it by deactivating one of them. It needed all
 * three of: Elementor Pro (or any plugin that resolves the user that early),
 * pretty permalinks, and a bearer token on the request — which is exactly a
 * real tool call after sign-in, and never the connection test before it.
 *
 * Bailing out early instead is not an option: _wp_get_current_user() caches
 * whatever the filter chain returns, so declining to resolve here would cache
 * "logged out" for the rest of the request and leave the call unauthenticated.
 * The URL has to be answerable at any point in the load, so this reproduces
 * core's own construction without the global, and hands off to core once it
 * exists.
 */
function nibwp_oauth_rest_url(string $path): string
{
    $structure     = (string) get_option('permalink_structure');
    $rewrite_ready = isset($GLOBALS['wp_rewrite']) && $GLOBALS['wp_rewrite'] instanceof WP_Rewrite;

    // core only reaches for $wp_rewrite on a site with pretty permalinks, so
    // every other case is already safe and stays with core. Reproducing the URL
    // ourselves is a last resort, not a replacement: the string is compared
    // against the `resource` recorded on a token, and two constructions that
    // drift apart would reject a perfectly good one.
    if ($structure === '' || $rewrite_ready) {
        return rest_url($path);
    }

    $path  = '/' . ltrim($path, '/');
    // using_index_permalinks() is just this test against the same option.
    $index = str_contains($structure, 'index.php') ? 'index.php/' : '';
    $url   = home_url('/' . $index . rest_get_url_prefix() . $path, 'rest');

    if (is_ssl() && isset($_SERVER['SERVER_NAME'])
        && wp_parse_url(home_url(), PHP_URL_HOST) === $_SERVER['SERVER_NAME']
    ) {
        $url = set_url_scheme($url, 'https');
    }

    /** Part of the URL's identity — a site that moves its REST root does it here, and skipping it would return a different string than rest_url() does. */
    return apply_filters('rest_url', $url, $path, null, 'rest');
}

/**
 * The canonical resource identifier for this site's MCP endpoint.
 *
 * Tokens are bound to this value (RFC 8707). A token minted for one site must
 * never be accepted by another, which is what stops a malicious server that a
 * user also connected to from replaying their token here.
 */
function nibwp_oauth_resource_id(): string
{
    return untrailingslashit(nibwp_oauth_rest_url('mcp/nibwp'));
}

/** Every endpoint this token is valid for — the primary route and its legacy alias. */
function nibwp_oauth_valid_audiences(): array
{
    return [
        untrailingslashit(nibwp_oauth_rest_url('mcp/nibwp')),
        untrailingslashit(nibwp_oauth_rest_url('mcp/mcp-adapter-default-server')),
    ];
}

/**
 * Reduce a URL to what actually identifies the endpoint it points at.
 *
 * Scheme and `www.` are dropped, the host is lowercased, a default port is
 * removed, and every address WordPress can serve the same REST route on is
 * folded to one path — the pretty form, the index.php form, and both
 * ?rest_route= forms are the same endpoint reached four ways.
 *
 * @return array{host:string,route:string}|null Null when the URL is unusable.
 */
function nibwp_oauth_url_identity(string $url): ?array
{
    $parts = wp_parse_url(trim($url));
    if (!is_array($parts) || empty($parts['host'])) {
        return null;
    }

    $host = strtolower(trim((string) $parts['host'], '[]'));
    // www is not a different site. Treating it as one is how a customer who
    // pasted the address with the prefix their browser shows got refused.
    $host = preg_replace('/^www\./', '', $host) ?? $host;

    // A WordPress in a subfolder is a different site from one in a sibling
    // folder on the same host, so the install root belongs to the identity of
    // the site rather than to the route.
    $home = wp_parse_url(home_url());
    $base = rtrim((string) ($home['path'] ?? ''), '/');
    $path = '/' . ltrim((string) ($parts['path'] ?? ''), '/');

    // Is this URL actually inside our install? Claiming our folder for a URL
    // that is not under it would fold a WordPress at the root of the same host
    // onto ours — a different site, running a different MCP server.
    $under = $base === '' || $path === $base || str_starts_with($path, $base . '/');
    $root  = $under ? $base : '';

    // ?rest_route=/mcp/nibwp names the route in the query instead of the path,
    // and names it bare — there is nothing to strip off it.
    parse_str((string) ($parts['query'] ?? ''), $query);
    if (isset($query['rest_route']) && is_string($query['rest_route'])) {
        $route = $query['rest_route'];
    } else {
        $route = $path;

        // Order matters: the install root comes off first, or the prefixes
        // below never match on a subfolder install.
        if ($root !== '') {
            $route = substr($route, strlen($root));
        }

        // What is left is addressing, not identity.
        $prefix = trim((string) rest_get_url_prefix(), '/');
        $route  = (string) preg_replace('#^/index\.php#', '', $route);
        $route  = (string) preg_replace('#^/' . preg_quote($prefix, '#') . '(?=/|$)#', '', $route);
    }

    return [
        'host'  => $host . $root,
        'route' => '/' . trim($route, '/'),
    ];
}

/**
 * Is this `resource` one of our own MCP endpoints?
 *
 * RFC 8707 binds a token to the resource it was issued for, and that binding is
 * what stops a hostile server the user also connected to from replaying their
 * token here. It has to keep doing that — but it was implemented as byte
 * equality against one string, and a client sends whatever address its user
 * typed. `www.`, `https` where WordPress records `http` (a proxy terminating
 * TLS upstream), and the ?rest_route= form of the very same route were all
 * refused with `invalid_target`, which reaches the customer as nothing more
 * than "couldn't connect".
 *
 * Matching on identity rather than spelling accepts every address that reaches
 * THIS site's MCP endpoint and still rejects every address that does not: the
 * host must be ours, so another origin never matches however it is written.
 */
function nibwp_oauth_audience_matches(string $resource): bool
{
    $asked = nibwp_oauth_url_identity($resource);
    if ($asked === null) {
        return false;
    }

    foreach (nibwp_oauth_valid_audiences() as $audience) {
        $ours = nibwp_oauth_url_identity($audience);
        if ($ours !== null && $ours['host'] === $asked['host'] && $ours['route'] === $asked['route']) {
            return true;
        }
    }

    return false;
}

/** Issuer identifier. Must match the URL its metadata document is fetched from. */
function nibwp_oauth_issuer(): string
{
    return untrailingslashit(home_url());
}

function nibwp_oauth_metadata_url(): string
{
    return nibwp_oauth_rest_url('nibwp/v1/oauth/protected-resource');
}

/**
 * Where a client sends the user to approve access.
 *
 * A path with no query of its own. RFC 6749 permits a query component and
 * requires clients to merge their parameters into it, but clients that append
 * `?response_type=…` regardless are common enough that the endpoint used to be
 * unreachable for them — the second `?` turned `action` into nonsense and
 * admin-post.php answered with a blank page. Nothing to merge, nothing to break.
 *
 * Falls back to the admin-post form when rewrites are unavailable (plain
 * permalinks), which is the only case where the query string is unavoidable.
 */
function nibwp_oauth_authorize_url(): string
{
    // Set only by the sign-in probe, on proof that the pretty path answers
    // 404/403 while the admin-post handler works — a WAF or server rule
    // blocking /nibwp-oauth/* outright. The metadata document is fetched at
    // every sign-in, so switching what this returns heals every client on
    // its next attempt without anyone touching anything. Cleared by the same
    // probe the moment the pretty path answers again, because the query-string
    // form has its own cost (see the docblock above).
    if (get_option('nibwp_oauth_authorize_fallback')) {
        return admin_url('admin-post.php?action=nibwp_oauth_authorize');
    }

    if (get_option('permalink_structure')) {
        return home_url('/nibwp-oauth/authorize');
    }

    return admin_url('admin-post.php?action=nibwp_oauth_authorize');
}

// ---------------------------------------------------------------------------
// Clients
// ---------------------------------------------------------------------------

/**
 * @return array<string, array<string, mixed>>
 */
function nibwp_oauth_clients(): array
{
    $raw = get_option(NIBWP_OAUTH_CLIENTS_OPTION, []);

    return is_array($raw) ? $raw : [];
}

function nibwp_oauth_client(string $client_id): ?array
{
    $clients = nibwp_oauth_clients();

    return isset($clients[$client_id]) && is_array($clients[$client_id])
        ? $clients[$client_id]
        : null;
}

/**
 * Persist a client record.
 *
 * @param array<string, mixed> $client
 */
function nibwp_oauth_save_client(string $client_id, array $client): void
{
    $clients = nibwp_oauth_clients();
    $clients[$client_id] = $client;
    update_option(NIBWP_OAUTH_CLIENTS_OPTION, $clients, false);
}

function nibwp_oauth_forget_client(string $client_id): void
{
    $clients = nibwp_oauth_clients();
    unset($clients[$client_id]);
    update_option(NIBWP_OAUTH_CLIENTS_OPTION, $clients, false);
}

/**
 * Whether a redirect URI is acceptable for a client.
 *
 * Exact string match against the registered set — no prefix or wildcard
 * matching. A loose comparison here is the classic open-redirect that lets an
 * attacker walk off with an authorization code.
 *
 * @param array<int, string> $registered
 */
function nibwp_oauth_redirect_uri_allowed(string $candidate, array $registered): bool
{
    foreach ($registered as $uri) {
        if (hash_equals((string) $uri, $candidate)) {
            return true;
        }
    }

    return false;
}

/**
 * Whether a redirect URI is safe to register at all.
 *
 * HTTPS everywhere, except loopback — desktop and CLI clients legitimately
 * listen on 127.0.0.1, and OAuth 2.1 permits exactly that.
 */
function nibwp_oauth_redirect_uri_is_valid(string $uri): bool
{
    $parts = wp_parse_url($uri);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return false;
    }
    if (!empty($parts['fragment'])) {
        return false;
    }

    $scheme = strtolower((string) $parts['scheme']);
    // An IPv6 host arrives bracketed, as the URL syntax requires — http://[::1]:5000.
    // Comparing that against '::1' fails, which quietly refused registration to any
    // client that happened to bind IPv6 loopback. It is the same machine either way.
    $host = strtolower(trim((string) $parts['host'], '[]'));

    if ($scheme === 'https') {
        return true;
    }

    if ($scheme === 'http' && in_array($host, ['127.0.0.1', '::1', 'localhost'], true)) {
        return true;
    }

    // Custom schemes (myapp://callback) are how many native clients register.
    return $scheme !== 'http' && $scheme !== 'https' && str_contains($uri, '://');
}

/**
 * Whether this site may fetch the given URL on a stranger's say-so.
 *
 * Client ID Metadata Documents are fetched server-side from a URL an
 * unauthenticated caller chose, which is a request-forgery primitive unless the
 * destination is checked: `https://169.254.169.254/…` reaches cloud instance
 * metadata, and a private address reaches whatever else shares the network. Both
 * the hostname and every address it resolves to are checked, so a name that
 * points at a private address is refused too.
 */
function nibwp_oauth_url_is_fetchable(string $url): bool
{
    $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
    if ($host === '') {
        return false;
    }

    if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
        return false;
    }

    $literal = trim($host, '[]');
    $addresses = filter_var($literal, FILTER_VALIDATE_IP) ? [$literal] : nibwp_oauth_resolve_host($host);

    // A name that will not resolve here is not one worth fetching either.
    if ($addresses === []) {
        return false;
    }

    foreach ($addresses as $ip) {
        $public = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );
        if ($public === false) {
            return false;
        }
    }

    return true;
}

/**
 * @return list<string>
 */
function nibwp_oauth_resolve_host(string $host): array
{
    $records = @dns_get_record($host, DNS_A | DNS_AAAA);
    if (!is_array($records)) {
        return [];
    }

    $ips = [];
    foreach ($records as $record) {
        $ip = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
        if ($ip !== '') {
            $ips[] = $ip;
        }
    }

    return $ips;
}

// ---------------------------------------------------------------------------
// Authorization codes
// ---------------------------------------------------------------------------

/**
 * Store a pending authorization and return its code.
 *
 * @param array<string, mixed> $payload
 */
function nibwp_oauth_issue_code(array $payload): string
{
    $code = wp_generate_password(48, false, false);
    set_transient(NIBWP_OAUTH_CODE_PREFIX . hash('sha256', $code), $payload, NIBWP_OAUTH_CODE_TTL);

    return $code;
}

/**
 * Redeem an authorization code. Single use: the record is deleted on read, so a
 * replayed code finds nothing even if it is still within its TTL.
 *
 * @return array<string, mixed>|null
 */
function nibwp_oauth_consume_code(string $code): ?array
{
    $key = NIBWP_OAUTH_CODE_PREFIX . hash('sha256', $code);
    $payload = get_transient($key);
    delete_transient($key);

    return is_array($payload) ? $payload : null;
}

// ---------------------------------------------------------------------------
// Tokens
// ---------------------------------------------------------------------------

/**
 * Mint an access token (and refresh token) for a user.
 *
 * The user id is carried in the token string itself so validation is a single
 * user-meta read rather than a scan of every user on the site. The id is not a
 * secret — Basic auth already puts the username on the wire — and it is useless
 * without the random half, which is all that is ever compared.
 *
 * @param array<int, string> $scopes
 * @return array{access_token: string, refresh_token: string, expires_in: int, token_id: string}
 */
/**
 * The best name we have for a client, and never an empty one.
 *
 * In order: what it registered as, what its metadata document calls it, the
 * host it lives on, and finally the identifier itself. "Unknown application" is
 * what a list says when it has stopped trying.
 */
function nibwp_oauth_client_display_name(string $client_id): string
{
    if ($client_id === '') {
        return __('An application', 'nibwp');
    }

    $client = nibwp_oauth_client($client_id);
    if ($client === null && function_exists('nibwp_oauth_resolve_client')) {
        // Reaches the metadata-document path, which may still be cached.
        $client = nibwp_oauth_resolve_client($client_id);
    }

    $name = trim((string) ($client['client_name'] ?? ''));
    if ($name !== '' && $name !== 'Unnamed client') {
        return $name;
    }

    // A client id that is a URL names its own owner well enough to be useful.
    $host = (string) wp_parse_url($client_id, PHP_URL_HOST);
    if ($host !== '') {
        return $host;
    }

    return $client_id;
}

function nibwp_oauth_issue_token(int $user_id, string $client_id, array $scopes, string $resource): array
{
    $token_id = wp_generate_password(12, false, false);
    $secret = wp_generate_password(48, false, false);
    $refresh_secret = wp_generate_password(48, false, false);
    $now = time();

    $record = [
        'id' => $token_id,
        'hash' => hash('sha256', $secret),
        'refresh_hash' => hash('sha256', $refresh_secret),
        'client_id' => $client_id,
        // The name is recorded with the token, not looked up when the list is
        // drawn. A client registered through its metadata document is cached in
        // a transient and never stored, so once that expired the connection had
        // nothing left to be called and showed as "Unknown application".
        'client_name' => nibwp_oauth_client_display_name($client_id),
        'scopes' => array_values($scopes),
        'resource' => $resource,
        'created' => $now,
        'expires' => $now + NIBWP_OAUTH_TOKEN_TTL,
        'refresh_expires' => $now + NIBWP_OAUTH_REFRESH_TTL,
        'last_used' => 0,
        'last_ip' => '',
    ];

    $tokens = nibwp_oauth_user_tokens($user_id);
    $tokens[$token_id] = $record;
    nibwp_oauth_save_user_tokens($user_id, $tokens);

    return [
        'access_token' => 'nibwp_at_' . $user_id . '.' . $token_id . '.' . $secret,
        'refresh_token' => 'nibwp_rt_' . $user_id . '.' . $token_id . '.' . $refresh_secret,
        'expires_in' => NIBWP_OAUTH_TOKEN_TTL,
        'token_id' => $token_id,
    ];
}

/**
 * @return array<string, array<string, mixed>>
 */
function nibwp_oauth_user_tokens(int $user_id): array
{
    $raw = get_user_meta($user_id, NIBWP_OAUTH_TOKENS_META, true);

    return is_array($raw) ? $raw : [];
}

/**
 * @param array<string, array<string, mixed>> $tokens
 */
function nibwp_oauth_save_user_tokens(int $user_id, array $tokens): void
{
    if ($tokens === []) {
        delete_user_meta($user_id, NIBWP_OAUTH_TOKENS_META);

        return;
    }

    update_user_meta($user_id, NIBWP_OAUTH_TOKENS_META, $tokens);
}

/**
 * Split a token string into its parts without trusting any of them.
 *
 * @return array{user_id: int, token_id: string, secret: string}|null
 */
function nibwp_oauth_parse_token(string $token, string $prefix): ?array
{
    if (!str_starts_with($token, $prefix)) {
        return null;
    }

    $parts = explode('.', substr($token, strlen($prefix)), 3);
    if (count($parts) !== 3) {
        return null;
    }

    $user_id = (int) $parts[0];
    if ($user_id <= 0 || $parts[1] === '' || $parts[2] === '') {
        return null;
    }

    return ['user_id' => $user_id, 'token_id' => $parts[1], 'secret' => $parts[2]];
}

/**
 * Validate an access token.
 *
 * @return array{user_id: int, record: array<string, mixed>}|null
 */
function nibwp_oauth_validate_token(string $token): ?array
{
    $parsed = nibwp_oauth_parse_token($token, 'nibwp_at_');
    if ($parsed === null) {
        return null;
    }

    $tokens = nibwp_oauth_user_tokens($parsed['user_id']);
    $record = $tokens[$parsed['token_id']] ?? null;
    if (!is_array($record)) {
        return null;
    }

    // Constant-time: a timing difference here would let an attacker recover a
    // valid token one byte at a time.
    if (!hash_equals((string) ($record['hash'] ?? ''), hash('sha256', $parsed['secret']))) {
        return null;
    }

    if ((int) ($record['expires'] ?? 0) < time()) {
        return null;
    }

    // The account may have been deleted or demoted since the token was granted.
    $user = get_userdata($parsed['user_id']);
    if (!$user) {
        return null;
    }

    return ['user_id' => $parsed['user_id'], 'record' => $record];
}

/**
 * Record that a token was used. Throttled — this runs on every MCP call and is
 * not worth a database write each time.
 */
function nibwp_oauth_touch_token(int $user_id, string $token_id): void
{
    $tokens = nibwp_oauth_user_tokens($user_id);
    if (!isset($tokens[$token_id])) {
        return;
    }

    $now = time();
    if ($now - (int) ($tokens[$token_id]['last_used'] ?? 0) < 60) {
        return;
    }

    $tokens[$token_id]['last_used'] = $now;
    $tokens[$token_id]['last_ip'] = isset($_SERVER['REMOTE_ADDR'])
        ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
        : '';
    nibwp_oauth_save_user_tokens($user_id, $tokens);
}

function nibwp_oauth_revoke_token(int $user_id, string $token_id): bool
{
    $tokens = nibwp_oauth_user_tokens($user_id);
    if (!isset($tokens[$token_id])) {
        return false;
    }

    unset($tokens[$token_id]);
    nibwp_oauth_save_user_tokens($user_id, $tokens);

    return true;
}

/**
 * Exchange a refresh token for a new access token, rotating both.
 *
 * Rotation matters: if a refresh token leaks and the attacker uses it, the
 * legitimate client's next refresh fails, which surfaces the compromise instead
 * of letting it run indefinitely.
 *
 * @return array{access_token: string, refresh_token: string, expires_in: int, token_id: string}|null
 */
function nibwp_oauth_refresh(string $refresh_token, string $client_id): ?array
{
    $parsed = nibwp_oauth_parse_token($refresh_token, 'nibwp_rt_');
    if ($parsed === null) {
        return null;
    }

    $tokens = nibwp_oauth_user_tokens($parsed['user_id']);
    $record = $tokens[$parsed['token_id']] ?? null;
    if (!is_array($record)) {
        return null;
    }

    if (!hash_equals((string) ($record['refresh_hash'] ?? ''), hash('sha256', $parsed['secret']))) {
        return null;
    }
    if ((int) ($record['refresh_expires'] ?? 0) < time()) {
        return null;
    }
    // A refresh token is bound to the client it was issued to.
    if (!hash_equals((string) ($record['client_id'] ?? ''), $client_id)) {
        return null;
    }

    nibwp_oauth_revoke_token($parsed['user_id'], $parsed['token_id']);

    return nibwp_oauth_issue_token(
        $parsed['user_id'],
        $client_id,
        (array) ($record['scopes'] ?? []),
        (string) ($record['resource'] ?? nibwp_oauth_resource_id())
    );
}

/**
 * Drop tokens that can no longer be used, so the connections list stays honest.
 */
function nibwp_oauth_prune_tokens(int $user_id): void
{
    $tokens = nibwp_oauth_user_tokens($user_id);
    $now = time();
    $kept = [];
    foreach ($tokens as $id => $record) {
        if ((int) ($record['refresh_expires'] ?? 0) >= $now || (int) ($record['expires'] ?? 0) >= $now) {
            $kept[$id] = $record;
        }
    }

    if (count($kept) !== count($tokens)) {
        nibwp_oauth_save_user_tokens($user_id, $kept);
    }
}

// ---------------------------------------------------------------------------
// Request-scoped state
// ---------------------------------------------------------------------------

/**
 * The token backing the current request, if it was authenticated by bearer.
 *
 * Set once by the resolver and read by scope enforcement, the audit log and the
 * connections UI. Null means this request came in some other way — a browser
 * cookie or an application password — and OAuth rules do not apply to it.
 *
 * @param array<string, mixed>|null $record
 * @return array<string, mixed>|null
 */
function nibwp_oauth_current_token(?array $record = null): ?array
{
    static $current = null;

    if ($record !== null) {
        $current = $record;
    }

    return $current;
}

/**
 * Whether the current request is OAuth-authenticated.
 */
function nibwp_oauth_is_oauth_request(): bool
{
    return nibwp_oauth_current_token() !== null;
}

/**
 * Is this request addressed to an MCP endpoint?
 *
 * Read from the URL rather than from REST state, because
 * `determine_current_user` runs before the REST server has parsed a route.
 * Everything the tokens are for lives under the `mcp/` namespace; the OAuth
 * routes themselves authenticate by their own means and never by bearer.
 */
function nibwp_oauth_request_is_mcp(): bool
{
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
    $path = (string) wp_parse_url($uri, PHP_URL_PATH);
    if ($path === '') {
        return false;
    }
    $path = '/' . ltrim($path, '/');

    // Pretty permalinks: /wp-json/mcp/… — and the plain form, ?rest_route=/mcp/…
    $prefix = '/' . trim((string) rest_get_url_prefix(), '/') . '/';
    if (str_starts_with($path, $prefix . 'mcp/')) {
        return true;
    }
    $route = isset($_GET['rest_route']) ? (string) wp_unslash($_GET['rest_route']) : '';
    if ($route !== '' && str_starts_with('/' . ltrim($route, '/'), '/mcp/')) {
        return true;
    }

    /**
     * Allow another MCP transport to accept these tokens.
     *
     * @param bool   $is_mcp Whether this request targets an MCP endpoint.
     * @param string $path   The request path.
     */
    return (bool) apply_filters('nibwp_oauth_request_is_mcp', false, $path);
}

/**
 * Read the bearer token from the request, if any.
 *
 * Checks the redirected header first: Apache with CGI/FastCGI drops the real
 * Authorization header, and .htaccess rules commonly re-expose it under
 * REDIRECT_HTTP_AUTHORIZATION. Missing that fallback is why "it works on my
 * machine" bearer auth so often fails on shared hosting.
 */
function nibwp_oauth_bearer_from_request(): string
{
    $header = '';
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
        if (!empty($_SERVER[$key])) {
            $header = (string) wp_unslash($_SERVER[$key]);
            break;
        }
    }

    if ($header === '' && function_exists('getallheaders')) {
        foreach ((array) getallheaders() as $name => $value) {
            if (strtolower((string) $name) === 'authorization') {
                $header = (string) $value;
                break;
            }
        }
    }

    if ($header === '' || stripos($header, 'bearer ') !== 0) {
        return '';
    }

    return trim(substr($header, 7));
}

// ---------------------------------------------------------------------------
// Request authentication
// ---------------------------------------------------------------------------

/**
 * Resolve a bearer token to a WordPress user.
 *
 * Hooked to `determine_current_user` beside core's application-password
 * validator. Resolving here rather than inside the MCP transport means every
 * layer downstream keeps working untouched: the transport's capability check,
 * the adapter's session manager, the audit log's user id, and each ability's own
 * permission callback all simply see a logged-in user.
 *
 * @param int|false $user_id
 * @return int|false
 */
function nibwp_oauth_determine_current_user($user_id)
{
    // Something already authenticated this request — a cookie or an application
    // password. Never override it.
    if (!empty($user_id)) {
        return $user_id;
    }

    $token = nibwp_oauth_bearer_from_request();
    if ($token === '') {
        return $user_id;
    }

    // A token is only good for the MCP endpoints it was granted for.
    //
    // Scopes are enforced on the tool call, so anything reached by another
    // route never meets them: a connection approved for reading would have
    // authenticated as the consenting administrator against the whole REST
    // API — enough to install a plugin — and the approval screen would have
    // been describing a limit that did not exist.
    if (!nibwp_oauth_request_is_mcp()) {
        return $user_id;
    }

    $valid = nibwp_oauth_validate_token($token);
    if ($valid === null) {
        return $user_id;
    }

    // Audience binding (RFC 8707). A token minted for another site must not be
    // usable here even if it is otherwise valid — this is what stops a hostile
    // server the user also connected to from replaying their token against us.
    $bound = (string) ($valid['record']['resource'] ?? '');
    if ($bound !== '' && !nibwp_oauth_audience_matches($bound)) {
        return $user_id;
    }

    nibwp_oauth_current_token($valid['record']);
    nibwp_oauth_touch_token($valid['user_id'], (string) $valid['record']['id']);

    return $valid['user_id'];
}
add_filter('determine_current_user', 'nibwp_oauth_determine_current_user', 20);

/**
 * Build the challenge header that tells a client where to authenticate.
 *
 * This is the primary discovery path. Clients must prefer the metadata URL given
 * here over probing well-known locations, which matters because those paths are
 * rewritten or intercepted on a lot of real hosting.
 */
function nibwp_oauth_challenge_header(?string $error = null, ?string $scope = null): string
{
    $parts = ['Bearer resource_metadata="' . esc_url_raw(nibwp_oauth_metadata_url()) . '"'];

    if ($error !== null) {
        $parts[] = 'error="' . $error . '"';
    }

    // Advertise everything this server understands, not the two we would tick
    // by default. Clients echo this list straight into their authorization
    // request, so naming a subset here is how a connection ends up unable to
    // ask for the permissions it will need — before anyone has seen a screen.
    // A refusal names the one scope that was missing instead.
    $parts[] = 'scope="' . ($scope ?? implode(' ', array_keys(nibwp_oauth_scopes()))) . '"';

    return implode(', ', $parts);
}

/**
 * Attach the challenge to unauthorised MCP responses.
 *
 * Neither WordPress nor the MCP adapter emits `WWW-Authenticate` on a REST 401,
 * so without this a client has no way to discover that OAuth is even available
 * and simply reports a failed connection.
 *
 * @param mixed $result
 * @return mixed
 */
function nibwp_oauth_add_challenge_header($result, $server, $request)
{
    if (!$result instanceof WP_REST_Response || !$request instanceof WP_REST_Request) {
        return $result;
    }

    if (!str_starts_with(ltrim((string) $request->get_route(), '/'), 'mcp/')) {
        return $result;
    }

    $status = $result->get_status();

    // Without these a browser-based client cannot read the challenge at all:
    // WWW-Authenticate is not on the CORS safelist, so fetch() hides it and the
    // client sees a bare 401 with no idea that signing in is even possible.
    $result->header('Access-Control-Allow-Origin', '*');
    $result->header('Access-Control-Expose-Headers', 'WWW-Authenticate, Mcp-Session-Id');

    if ($status === 401) {
        $result->header('WWW-Authenticate', nibwp_oauth_challenge_header());

        return $result;
    }

    // A scope refusal names the missing scope in a header a client can read —
    // but it must NOT turn the response into a 403 with an auth challenge.
    //
    // Measured on a live site: a tool the token had no scope for produced a 403
    // + WWW-Authenticate, the client read that as "not signed in", ran the whole
    // authorization dance again, got the same grant, tried the same tool, and
    // failed identically. A browser window per tool call, forever. The tool call
    // itself already failed with an explanation inside a perfectly good 200, and
    // that is what the client should be reading.
    $needed = function_exists('nibwp_oauth_flag_insufficient_scope')
        ? nibwp_oauth_flag_insufficient_scope()
        : null;

    if ($needed !== null) {
        $result->header('X-NibWP-Insufficient-Scope', $needed);
    }

    return $result;
}
add_filter('rest_post_dispatch', 'nibwp_oauth_add_challenge_header', 10, 3);

// Scope enforcement sits on the adapter's pre-call filter, which short-circuits
// on WP_Error. Registered here so it is active for every transport.
add_filter('mcp_adapter_pre_tool_call', 'nibwp_oauth_enforce_tool_scope', 5, 3);

/**
 * A public, same-scheme URL for the plugin's own mark.
 *
 * Two things kept this from ever being displayed. The plugin URL is built from
 * whatever scheme the request arrived on, so a site reached over https could
 * publish an http icon — mixed content, which a browser refuses to load. And a
 * client cannot show what it has not been told about, so the same URL has to be
 * offered everywhere a client might look for it.
 */
function nibwp_brand_icon_url(): string
{
    $url = NIBWP_PLUGIN_URL . 'assets/nibwp-icon.svg';

    // Follow the site's own scheme rather than the current request's.
    if (str_starts_with((string) home_url(), 'https://')) {
        $url = set_url_scheme($url, 'https');
    }

    /**
     * Filters the icon this site advertises to AI clients.
     *
     * @param string $url Absolute URL to an SVG or PNG.
     */
    return (string) apply_filters('nibwp_brand_icon_url', $url);
}

/**
 * Tell a connecting client what this server is called and what it looks like.
 *
 * A connector with no icon is drawn as the first letter of its name, so NibWP
 * appeared in client UIs as a grey "N". The handshake is where a client looks
 * for this — the icons field was added to the MCP schema for exactly this — and
 * it costs one filter to answer.
 *
 * @param mixed $result
 * @return mixed
 */
function nibwp_mcp_describe_server($result, $mcp = null)
{
    if (!is_object($result) || !method_exists($result, 'toArray')) {
        return $result;
    }

    $data = $result->toArray();
    if (!is_array($data) || !isset($data['serverInfo']) || !is_array($data['serverInfo'])) {
        return $result;
    }

    $brand = function_exists('nibwp_branding_display_name') ? nibwp_branding_display_name() : 'NibWP';
    $site = trim((string) get_bloginfo('name'));

    $data['serverInfo']['title'] = $site !== '' ? $brand . ' — ' . $site : $brand;
    $data['serverInfo']['websiteUrl'] = home_url('/');
    $data['serverInfo']['icons'] = [
        [
            'src' => nibwp_brand_icon_url(),
            'mimeType' => 'image/svg+xml',
            'sizes' => ['any'],
        ],
    ];

    try {
        return $result::fromArray($data);
    } catch (\Throwable $e) {
        // A schema that will not take the extra fields is not worth failing the
        // handshake over: the connection matters, the icon does not.
        return $result;
    }
}
add_filter('mcp_adapter_initialize_response', 'nibwp_mcp_describe_server', 10, 2);
