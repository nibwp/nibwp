<?php

declare(strict_types=1);

/**
 * The workspace side of the bus.
 *
 * The hot path runs on admin-ajax rather than REST, and that is not a style
 * preference. Every REST request fires `rest_api_init`, which boots the MCP
 * adapter and registers every ability on the site — measured at 278 of them —
 * on a request whose entire job is to ask "anything for me?". The workspace
 * polls continuously, so that cost landed several times a minute and was enough
 * to exhaust a local Apache's worker pool and return intermittent 500s.
 * admin-ajax boots WordPress without any of it.
 *
 * The REST routes stay registered so anything already pointed at them keeps
 * working; both paths share one collector, so they cannot drift.
 *
 * Cookie-authenticated throughout, because the only caller is a browser tab the
 * administrator has open. The agent never touches these — it goes through
 * abilities, which is what keeps OAuth scopes and the audit log in the path.
 */

if (!defined('ABSPATH')) {
    exit();
}

add_action('wp_ajax_nibwp_visual_poll', 'nibwp_visual_ajax_poll');
add_action('wp_ajax_nibwp_visual_result', 'nibwp_visual_ajax_result');
add_action('wp_ajax_nibwp_visual_state', 'nibwp_visual_ajax_state');
add_action('wp_ajax_nibwp_visual_check', 'nibwp_visual_ajax_check');
add_action('wp_ajax_nibwp_visual_pair', 'nibwp_visual_ajax_pair');

function nibwp_visual_ajax_guard(): void
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'forbidden'], 403);
    }

    check_ajax_referer('wp_rest', 'nonce');
}

function nibwp_visual_ajax_poll(): void
{
    nibwp_visual_ajax_guard();

    $user_id = get_current_user_id();
    $session = isset($_POST['session']) ? sanitize_key(wp_unslash($_POST['session'])) : '';

    // A tab that has been taken over stops polling rather than competing for
    // commands it would answer with whatever code it happens to be running.
    if (!nibwp_visual_holds($user_id, $session)) {
        wp_send_json(['commands' => [], 'standDown' => true]);
    }

    wp_send_json(nibwp_visual_collect($user_id));
}

function nibwp_visual_ajax_result(): void
{
    nibwp_visual_ajax_guard();

    $id = isset($_POST['id']) ? sanitize_text_field(wp_unslash($_POST['id'])) : '';
    if ($id === '') {
        wp_send_json_error(['message' => 'missing id'], 400);
    }

    $raw = isset($_POST['data']) ? (string) wp_unslash($_POST['data']) : '';
    $data = $raw !== '' ? json_decode($raw, true) : null;
    $error = isset($_POST['error']) ? sanitize_textarea_field(wp_unslash($_POST['error'])) : '';

    nibwp_visual_answer($id, is_array($data) ? $data : null, $error);
    nibwp_visual_forget(get_current_user_id(), $id);

    wp_send_json(['ok' => true]);
}

function nibwp_visual_ajax_state(): void
{
    nibwp_visual_ajax_guard();

    // Opening the workspace takes it over from any older tab — and counts as
    // being open. The heartbeat used to start only once the first poll ran, so
    // for the round trip in between the workspace was reported shut while it
    // was sitting there ready.
    if (isset($_POST['claim'])) {
        nibwp_visual_claim(get_current_user_id(), sanitize_key(wp_unslash($_POST['claim'])));
        nibwp_visual_touch(get_current_user_id());
    }

    if (isset($_POST['kind'])) {
        nibwp_visual_set_kind(get_current_user_id(), sanitize_key(wp_unslash($_POST['kind'])));
    }

    if (isset($_POST['approval'])) {
        update_option('nibwp_visual_approval', wp_unslash($_POST['approval']) === '1', false);
    }

    wp_send_json(['approval' => nibwp_visual_approval_required()]);
}

/**
 * Answer "is this thing on?" for the person looking at the screen.
 *
 * Three separate things have to be true before an agent can drive this
 * workspace, and when one is missing the symptom is identical: nothing
 * happens. So each is reported on its own rather than as one verdict.
 */
function nibwp_visual_ajax_check(): void
{
    nibwp_visual_ajax_guard();

    $state = nibwp_visual_connection_state();
    $user_id = get_current_user_id();

    wp_send_json([
        'listening' => nibwp_visual_is_open($user_id),
        'abilities' => $state['enabled'],
        'clients' => $state['clients'],
        'connected' => $state['connected'],
        'connectUrl' => admin_url('admin.php?page=nibwp-connect'),
    ]);
}

/**
 * Hold briefly for a command.
 *
 * Answering "nothing" immediately would mean a tab polling every few seconds
 * either wastes requests or adds that lag to every agent action. Waiting here
 * means a command queued a moment after the poll starts is picked up almost at
 * once, and an idle workspace still costs one request every ten seconds.
 *
 * Ten seconds, not thirty: this request occupies a PHP worker for its whole
 * life and the waiting ability occupies a second one, so a workspace costs two
 * of them per action. On a host with four workers, a longer hold is how one
 * open tab starves the site it is trying to inspect.
 *
 * @return array<string, mixed>
 */
function nibwp_visual_collect(int $user_id): array
{
    nibwp_visual_touch($user_id);

    $deadline = microtime(true) + 10;
    $commands = nibwp_visual_take($user_id);

    while ($commands === [] && microtime(true) < $deadline) {
        usleep(300000);
        nibwp_visual_touch($user_id);
        $commands = nibwp_visual_take($user_id);
    }

    return [
        'commands' => $commands,
        'approval' => nibwp_visual_approval_required(),
    ];
}

add_action('rest_api_init', static function (): void {
    $mine = static function (): bool {
        return is_user_logged_in() && current_user_can('manage_options');
    };

    register_rest_route('nibwp/v1', '/visual/poll', [
        'methods' => 'GET',
        'permission_callback' => $mine,
        'callback' => 'nibwp_visual_rest_poll',
    ]);

    register_rest_route('nibwp/v1', '/visual/result', [
        'methods' => 'POST',
        'permission_callback' => $mine,
        'callback' => 'nibwp_visual_rest_result',
    ]);

    register_rest_route('nibwp/v1', '/visual/state', [
        'methods' => 'POST',
        'permission_callback' => $mine,
        'callback' => 'nibwp_visual_rest_state',
    ]);

    register_rest_route('nibwp/v1', '/visual/session', [
        'methods' => 'POST',
        'permission_callback' => $mine,
        'callback' => 'nibwp_visual_rest_session',
    ]);
});

/* ---------------------------------------------------------------------------
 * Pairing keys.
 *
 * The session route below turns a credential into browser cookies, and that is
 * a conversion nothing should be able to make on its own. WordPress promises
 * that an application password cannot sign in to wp-admin — core only accepts
 * one on REST and XML-RPC, never on a page load — and sites lean on that: a
 * two-factor or SSO challenge lives on the login form, so a credential that can
 * never reach the login form can never be challenged by it either. A route that
 * hands out auth cookies to anyone holding an application password quietly
 * repeals both halves of that promise, and hands the holder twelve hours of
 * cookie-only wp-admin: theme and plugin file editing, exports, every screen
 * that trusts a nonce because it assumed a person was there.
 *
 * So the runner needs two things, not one. The application password proves
 * which account is asking. A pairing key proves a person signed in to wp-admin
 * and said this machine may hold a browser session for them. The key can only
 * be made from the Agent View screen, over admin-ajax, behind a real logged-in
 * cookie — the one thing no header credential produces.
 * ------------------------------------------------------------------------- */

const NIBWP_VISUAL_PASS_OPTION = 'nibwp_visual_runner_passes';

/**
 * How long a one-run key may sit unredeemed.
 *
 * Fifteen minutes is the length of "press the button, switch to the terminal,
 * paste it, start the runner" with room to look something up in between. Long
 * enough that nobody has to hurry; short enough that a key left in scrollback,
 * in a screen share or in a pasted support log is already dead by the time
 * anyone else reads it.
 */
const NIBWP_VISUAL_PASS_TTL = 15 * MINUTE_IN_SECONDS;

/**
 * How long a key meant for scheduled runs stays good.
 *
 * A nightly run cannot ask a person for a fresh key every night, so this one
 * is reusable and has to outlive a holiday. Thirty days is the compromise: it
 * covers the gap without becoming a credential nobody revisits, and it expires
 * by itself on a site whose owner has moved on.
 */
const NIBWP_VISUAL_PASS_SCHEDULED_TTL = 30 * DAY_IN_SECONDS;

/** Most keys kept at once, across every account. */
const NIBWP_VISUAL_PASS_MAX = 20;

/**
 * The stored form of a key.
 *
 * Only the fingerprint is written down. A key is a credential, and an options
 * table ends up in every backup, staging copy and support export the site ever
 * produces — none of which should be a pile of working logins.
 */
function nibwp_visual_pass_fingerprint(string $secret): string
{
    return hash_hmac('sha256', $secret, wp_salt('auth'));
}

/**
 * @return array<string, array<string, mixed>>
 */
function nibwp_visual_passes(): array
{
    $stored = get_option(NIBWP_VISUAL_PASS_OPTION, []);

    return is_array($stored) ? $stored : [];
}

/**
 * @param array<string, array<string, mixed>> $passes
 */
function nibwp_visual_save_passes(array $passes): void
{
    update_option(NIBWP_VISUAL_PASS_OPTION, $passes, false);
}

/**
 * Drop everything past its date.
 *
 * Expiry is enforced here rather than by leaving it to a transient, because a
 * scheduled key has to survive an object cache being flushed — otherwise the
 * cron job that depends on it fails at three in the morning with nobody to
 * re-pair it.
 *
 * @param array<string, array<string, mixed>> $passes
 * @return array<string, array<string, mixed>>
 */
function nibwp_visual_prune_passes(array $passes): array
{
    $now = time();

    foreach ($passes as $key => $pass) {
        if (!is_array($pass) || (int) ($pass['expires'] ?? 0) <= $now) {
            unset($passes[$key]);
        }
    }

    return $passes;
}

/**
 * Mint a key for this account. The plaintext is returned once and never stored.
 *
 * @return array{secret: string, expires: int, scheduled: bool}
 */
function nibwp_visual_issue_pass(int $user_id, bool $scheduled): array
{
    // Letters and digits only. This is pasted into a shell, a crontab or an env
    // file, and a punctuation character in any of those turns a two-minute
    // setup into an argument about quoting.
    $secret = wp_generate_password(40, false, false);
    $expires = time() + ($scheduled ? NIBWP_VISUAL_PASS_SCHEDULED_TTL : NIBWP_VISUAL_PASS_TTL);

    $passes = nibwp_visual_prune_passes(nibwp_visual_passes());

    // Nothing else prunes this list, and a key left behind by a run nobody
    // remembers starting is a credential nobody remembers holding.
    while (count($passes) >= NIBWP_VISUAL_PASS_MAX) {
        array_shift($passes);
    }

    $passes[nibwp_visual_pass_fingerprint($secret)] = [
        'user' => $user_id,
        'issued' => time(),
        'expires' => $expires,
        'scheduled' => $scheduled,
        'used' => 0,
        'tokens' => [],
    ];

    nibwp_visual_save_passes($passes);

    return ['secret' => $secret, 'expires' => $expires, 'scheduled' => $scheduled];
}

/**
 * Spend a key, and say which record was spent.
 *
 * Refuses on every count that matters: unknown, expired, belonging to another
 * account, or — for a one-run key — already redeemed. The account check is what
 * stops one administrator's key from being redeemed against another
 * administrator's application password, which would otherwise be a way to
 * launder a pairing somebody else approved into a session of your own.
 *
 * A wrong account does not consume the key. Every administrator on the site can
 * reach this route with their own credential, and burning a colleague's key on
 * sight would make "pair a runner" something anyone could keep breaking.
 *
 * @return string The stored fingerprint, or '' when the key is no good.
 */
function nibwp_visual_redeem_pass(string $secret, int $user_id): string
{
    if ($secret === '' || $user_id <= 0) {
        return '';
    }

    $passes = nibwp_visual_prune_passes(nibwp_visual_passes());
    $key = nibwp_visual_pass_fingerprint($secret);
    $pass = $passes[$key] ?? null;

    // Expired keys were dropped by the prune above, so write the shorter list
    // back whatever the verdict — a key nobody can use should not linger.
    nibwp_visual_save_passes($passes);

    if (!is_array($pass) || (int) ($pass['user'] ?? 0) !== $user_id) {
        return '';
    }

    // One run means one session. Replaying the key — from a shell history, a
    // scheduler's log, a terminal someone screen-shared — gets nothing.
    if (empty($pass['scheduled']) && (int) ($pass['used'] ?? 0) > 0) {
        return '';
    }

    return $key;
}

/**
 * Record that a key opened a session, and which one.
 *
 * The token is kept so revoking the key can end the session it opened. Without
 * that, "revoked" would only mean the key cannot open another one while the
 * session already running kept the machine signed in for the rest of the day.
 *
 * A spent one-run key is kept, not deleted, until the session it minted has
 * expired — a record nobody can redeem is what makes that session revocable.
 */
function nibwp_visual_pass_note_session(string $key, string $token, int $expiration): void
{
    $passes = nibwp_visual_passes();
    if (!isset($passes[$key]) || !is_array($passes[$key])) {
        return;
    }

    $tokens = array_values(array_filter((array) ($passes[$key]['tokens'] ?? []), 'is_string'));
    $tokens[] = $token;

    $passes[$key]['tokens'] = array_slice($tokens, -10);
    $passes[$key]['used'] = (int) ($passes[$key]['used'] ?? 0) + 1;
    $passes[$key]['last_used'] = time();
    $passes[$key]['expires'] = max((int) ($passes[$key]['expires'] ?? 0), $expiration);

    nibwp_visual_save_passes($passes);
}

/**
 * Throw away this account's keys, and the sessions they opened.
 */
function nibwp_visual_revoke_passes(int $user_id): int
{
    $passes = nibwp_visual_prune_passes(nibwp_visual_passes());
    $gone = 0;

    foreach ($passes as $key => $pass) {
        if ((int) ($pass['user'] ?? 0) !== $user_id) {
            continue;
        }

        $manager = WP_Session_Tokens::get_instance($user_id);
        foreach ((array) ($pass['tokens'] ?? []) as $token) {
            if (is_string($token) && $token !== '') {
                $manager->destroy($token);
            }
        }

        unset($passes[$key]);
        $gone++;
    }

    nibwp_visual_save_passes($passes);

    return $gone;
}

/**
 * How many keys this account has that are still good for something.
 */
function nibwp_visual_pass_count(int $user_id): int
{
    $count = 0;

    foreach (nibwp_visual_prune_passes(nibwp_visual_passes()) as $pass) {
        if ((int) ($pass['user'] ?? 0) !== $user_id) {
            continue;
        }
        if (empty($pass['scheduled']) && (int) ($pass['used'] ?? 0) > 0) {
            continue;
        }
        $count++;
    }

    return $count;
}

/**
 * Make a pairing key, or revoke this account's keys.
 *
 * On admin-ajax and not on REST, deliberately. WordPress accepts an application
 * password on REST and XML-RPC only, so a header credential cannot reach this
 * handler at all — it is not signed in here, and there is no nopriv twin to
 * fall through to. That is the property the whole guard rests on, so it is
 * checked rather than assumed: a site can widen application passwords to every
 * request by filtering `application_password_is_api_request`, and a security
 * plugin can add Basic auth of its own. Requiring a real session token means a
 * logged-in cookie was presented, which only wp-login.php sets — and wp-login
 * is where a site's two-factor or SSO challenge lives.
 */
function nibwp_visual_ajax_pair(): void
{
    nibwp_visual_ajax_guard();

    if (wp_get_session_token() === '') {
        wp_send_json_error(['message' => 'pair from wp-admin'], 403);
    }

    $user_id = get_current_user_id();

    if (isset($_POST['revoke'])) {
        $revoked = nibwp_visual_revoke_passes($user_id);

        wp_send_json(['revoked' => $revoked, 'active' => nibwp_visual_pass_count($user_id)]);
    }

    $scheduled = isset($_POST['scheduled']) && (string) wp_unslash($_POST['scheduled']) === '1';
    $issued = nibwp_visual_issue_pass($user_id, $scheduled);

    wp_send_json([
        'pass' => $issued['secret'],
        'expires' => $issued['expires'],
        'scheduled' => $issued['scheduled'],
        'active' => nibwp_visual_pass_count($user_id),
    ]);
}

/**
 * Hand the headless runner a browser session for the account it already is.
 *
 * The workspace is an admin screen behind a cookie, so a runner needed a login
 * — and a login form takes the account password, which an application password
 * cannot stand in for. That put an administrator's real password in whatever
 * env file or crontab started the runner, to do a job that is mostly reading
 * pages. Wrong trade.
 *
 * So the session is issued here instead, and the credential left on the build
 * box is a revocable application password. But authenticating as the account is
 * not on its own permission to become a browser in it: an application password
 * is the credential WordPress promises cannot sign in to wp-admin, and a route
 * that turns one into auth cookies hands its holder every cookie-only screen —
 * the file editors, the exporters, everything guarded by a nonce on the
 * assumption a person was there — without ever meeting the login challenge the
 * site puts in front of a person.
 *
 * Hence the pairing key. It is minted on the Agent View screen, behind a
 * logged-in cookie and a nonce, by a human who decided this machine may hold a
 * session for them; it is bound to that account, dies in fifteen minutes unless
 * it was made for a schedule, and a one-run key opens exactly one session. An
 * application password on its own now gets a 403, which is the whole point: the
 * REST credential can say who is asking, and nothing more.
 *
 * Short-lived on purpose. The runner is up for hours, not weeks, and a session
 * minted for an unattended process should expire well before anyone would think
 * to go looking for it.
 */
function nibwp_visual_rest_session(WP_REST_Request $request): WP_REST_Response
{
    $user_id = get_current_user_id();
    if ($user_id <= 0) {
        return new WP_REST_Response(['error' => 'no user'], 401);
    }

    // Before anything is minted. A refusal that had already created a session
    // token would be a refusal in the response body only.
    $key = nibwp_visual_redeem_pass((string) $request->get_param('pass'), $user_id);
    if ($key === '') {
        return new WP_REST_Response([
            'error' => 'no_pass',
            'message' => 'This needs a pairing key. Open NibWP -> Agent View in wp-admin, create one under "Headless runner", and pass it to the runner.',
        ], 403);
    }

    // Twelve hours, and one hour when the caller does not say. The runner's own
    // default run is a shift — eight hours — so a lower ceiling would leave a
    // normal run signed out halfway through with no way to renew; and a caller
    // that names no lifetime is a hand-made request, which wants the short one.
    // Everything above this line is what keeps the number honest: the session
    // exists because a person asked for it, and it is a real session token, so
    // Users -> Profile -> Log out everywhere ends it, as does revoking the key.
    $hours = (int) $request->get_param('hours');
    $hours = $hours > 0 ? min($hours, 12) : 1;
    $expiration = time() + $hours * HOUR_IN_SECONDS;

    $manager = WP_Session_Tokens::get_instance($user_id);
    $token = $manager->create($expiration);

    nibwp_visual_pass_note_session($key, $token, $expiration);

    $secure = is_ssl();
    $scheme = $secure ? 'secure_auth' : 'auth';
    $auth_name = $secure ? SECURE_AUTH_COOKIE : AUTH_COOKIE;

    $auth = wp_generate_auth_cookie($user_id, $expiration, $scheme, $token);
    $logged_in = wp_generate_auth_cookie($user_id, $expiration, 'logged_in', $token);

    $host = wp_parse_url(home_url(), PHP_URL_HOST);

    // Both paths for the auth cookie, or wp-admin and the plugins directory
    // disagree about whether anyone is signed in.
    $cookies = [
        ['name' => $auth_name, 'value' => $auth, 'path' => ADMIN_COOKIE_PATH],
        ['name' => $auth_name, 'value' => $auth, 'path' => PLUGINS_COOKIE_PATH],
        ['name' => LOGGED_IN_COOKIE, 'value' => $logged_in, 'path' => COOKIEPATH],
    ];

    if (SITECOOKIEPATH !== COOKIEPATH) {
        $cookies[] = ['name' => LOGGED_IN_COOKIE, 'value' => $logged_in, 'path' => SITECOOKIEPATH];
    }

    foreach ($cookies as $i => $cookie) {
        $cookies[$i] = $cookie + [
            'domain' => COOKIE_DOMAIN ?: $host,
            'secure' => $secure,
            'httpOnly' => true,
            'expires' => $expiration,
        ];
    }

    return new WP_REST_Response([
        'cookies' => $cookies,
        'expires' => $expiration,
        'expires_in' => $expiration - time(),
        'user' => wp_get_current_user()->user_login,
        'workspace_url' => admin_url('admin-post.php?action=nibwp_agent_view'),
    ]);
}

function nibwp_visual_rest_poll(WP_REST_Request $request): WP_REST_Response
{
    $user_id = get_current_user_id();

    // The poll is the request that already refreshes the heartbeat, so the kind
    // rides along with it. Sending it only on connect would leave capabilities
    // expiring under a workspace that is still there answering.
    $kind = (string) $request->get_param('kind');
    if ($kind !== '') {
        nibwp_visual_set_kind($user_id, sanitize_key($kind));
    }

    // Same stand-down as the ajax path. Without it a runner and a forgotten
    // browser tab both collect from one queue and each answers half the
    // commands, which looks like random failure from the agent's side.
    $session = (string) $request->get_param('session');
    if ($session !== '' && !nibwp_visual_holds($user_id, sanitize_key($session))) {
        return new WP_REST_Response(['commands' => [], 'standDown' => true]);
    }

    return new WP_REST_Response(nibwp_visual_collect($user_id));
}

function nibwp_visual_rest_result(WP_REST_Request $request): WP_REST_Response
{
    $id = sanitize_text_field((string) $request->get_param('id'));
    if ($id === '') {
        return new WP_REST_Response(['ok' => false], 400);
    }

    $data = $request->get_param('data');
    $error = (string) $request->get_param('error');

    nibwp_visual_answer($id, is_array($data) ? $data : null, $error);
    nibwp_visual_forget(get_current_user_id(), $id);

    return new WP_REST_Response(['ok' => true]);
}

function nibwp_visual_rest_state(WP_REST_Request $request): WP_REST_Response
{
    $user_id = get_current_user_id();

    if ($request->get_param('approval') !== null) {
        update_option('nibwp_visual_approval', (bool) $request->get_param('approval'), false);
    }

    // Claiming over REST is what lets the headless runner take the workspace
    // from a tab left open, rather than the two of them splitting the queue and
    // each answering half the commands.
    $claim = (string) $request->get_param('claim');
    if ($claim !== '') {
        nibwp_visual_claim($user_id, sanitize_key($claim));
        nibwp_visual_touch($user_id);
    }

    $kind = (string) $request->get_param('kind');
    if ($kind !== '') {
        nibwp_visual_set_kind($user_id, sanitize_key($kind));
    }

    return new WP_REST_Response([
        'approval' => nibwp_visual_approval_required(),
        'workspace' => nibwp_visual_kind($user_id),
    ]);
}
