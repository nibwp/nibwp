<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Enforcement for the four Settings controls that were saved but never read.
 *
 * The Security section offered an IP whitelist, a per-minute rate limit, a
 * disabled-tools list and "force draft". All four stored their value and
 * nothing ever consulted it, so the page described protections the site did
 * not have — the worst kind of security UI, because it is the kind people
 * rely on.
 *
 * Everything hangs off `mcp_adapter_pre_tool_call`, which is the one place
 * every agent call passes through, carries the tool name, and short-circuits
 * on a WP_Error. Admin screens and ordinary REST traffic never reach it, so
 * these rules govern agents without touching the site's own UI.
 *
 * Order matters: the whitelist is a statement about who may talk to the site
 * at all, so it runs before anything about what they asked for.
 */

/** Runs after the OAuth scope check (5) and before audit logging (10). */
add_filter('mcp_adapter_pre_tool_call', 'nibwp_guardrails_enforce', 6, 3);

/**
 * @param mixed  $args
 * @param string $tool_name
 * @param mixed  $mcp_tool
 * @return mixed|WP_Error
 */
function nibwp_guardrails_enforce($args, $tool_name = '', $mcp_tool = null)
{
    $blocked = nibwp_guardrails_check_ip();
    if (is_wp_error($blocked)) {
        return $blocked;
    }

    $abilities = function_exists('nibwp_oauth_abilities_behind_call')
        ? nibwp_oauth_abilities_behind_call((string) $tool_name, $mcp_tool, (array) $args)
        : [(string) $tool_name];

    $blocked = nibwp_guardrails_check_disabled($abilities);
    if (is_wp_error($blocked)) {
        return $blocked;
    }

    $blocked = nibwp_guardrails_check_rate();
    if (is_wp_error($blocked)) {
        return $blocked;
    }

    // Not a gate: this one arms a filter that rewrites the status on the way
    // into the database, so it covers every path a post can be written by,
    // not only the abilities that happen to take a post_status argument.
    nibwp_guardrails_arm_force_draft();

    return $args;
}

/* ------------------------------------------------------------------ IP -- */

/**
 * The address this request actually came from.
 *
 * REMOTE_ADDR only. X-Forwarded-For is caller-supplied and trivially forged,
 * so honouring it by default would turn the whitelist into a formality. Sites
 * genuinely behind a proxy can opt in through the filter, which is a decision
 * their operator makes knowingly.
 */
function nibwp_guardrails_client_ip(): string
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';

    return (string) apply_filters('nibwp_guardrails_client_ip', $ip);
}

/**
 * Does an address fall inside a whitelist entry?
 *
 * Accepts a plain address or CIDR notation. Anything unparseable is treated
 * as no match rather than as a wildcard — a typo must never widen access.
 */
function nibwp_guardrails_ip_matches(string $ip, string $rule): bool
{
    $rule = trim($rule);
    if ($rule === '' || $ip === '') {
        return false;
    }

    if (!str_contains($rule, '/')) {
        return inet_pton($rule) !== false && inet_pton($rule) === inet_pton($ip);
    }

    [$subnet, $bits] = array_pad(explode('/', $rule, 2), 2, '');
    $subnet_packed = inet_pton(trim($subnet));
    $ip_packed = inet_pton($ip);

    if ($subnet_packed === false || $ip_packed === false) {
        return false;
    }
    // Comparing a v4 address against a v6 range (or the reverse) is a mismatch,
    // not a match on the bytes that happen to line up.
    if (strlen($subnet_packed) !== strlen($ip_packed)) {
        return false;
    }

    $bits = (int) $bits;
    $max = strlen($ip_packed) * 8;
    if ($bits < 0 || $bits > $max) {
        return false;
    }
    if ($bits === 0) {
        // /0 is every address. Written deliberately it is a wildcard; it is
        // still an explicit entry, so it is honoured.
        return true;
    }

    $whole = intdiv($bits, 8);
    $rest = $bits % 8;

    if ($whole > 0 && strncmp($subnet_packed, $ip_packed, $whole) !== 0) {
        return false;
    }
    if ($rest === 0) {
        return true;
    }

    $mask = ~((1 << (8 - $rest)) - 1) & 0xFF;

    return (ord($subnet_packed[$whole]) & $mask) === (ord($ip_packed[$whole]) & $mask);
}

/**
 * @return true|WP_Error
 */
function nibwp_guardrails_check_ip()
{
    $raw = trim((string) get_option('nibwp_ip_whitelist', ''));

    // An empty list is "no restriction". Reading it as "allow nobody" would
    // lock every site that never opened this setting out of its own agent.
    if ($raw === '') {
        return true;
    }

    $rules = array_filter(array_map('trim', preg_split('/[\s,]+/', $raw) ?: []));
    if ($rules === []) {
        return true;
    }

    $ip = nibwp_guardrails_client_ip();

    foreach ($rules as $rule) {
        if (nibwp_guardrails_ip_matches($ip, $rule)) {
            return true;
        }
    }

    return new WP_Error(
        'nibwp_ip_not_allowed',
        sprintf(
            /* translators: %s: the address the request came from */
            __('Refused: this site only accepts agent calls from the addresses on its whitelist, and %s is not one of them. This is a setting on the site (NibWP → Settings → Security), not something the connection can change. Ask the site owner to add this address or clear the whitelist. Retrying will fail identically.', 'nibwp'),
            $ip === '' ? __('an unknown address', 'nibwp') : $ip
        ),
        ['status' => 403]
    );
}

/* ------------------------------------------------------- disabled tools -- */

/**
 * @param array<int,string> $abilities
 * @return true|WP_Error
 */
function nibwp_guardrails_check_disabled(array $abilities)
{
    $disabled = (array) get_option('nibwp_disabled_tools', []);
    if ($disabled === []) {
        return true;
    }

    foreach ($abilities as $ability) {
        $ability = (string) $ability;
        // Stored with and without the namespace across versions of the page.
        $bare = str_contains($ability, '/') ? substr($ability, strpos($ability, '/') + 1) : $ability;

        if (!in_array($ability, $disabled, true) && !in_array($bare, $disabled, true)) {
            continue;
        }

        return new WP_Error(
            'nibwp_tool_disabled',
            sprintf(
                /* translators: %s: the ability that was refused */
                __('Refused: %s has been switched off for this site in NibWP → Settings → Security. This is the owner\'s choice, not a permission the connection can be granted. Do not retry it, and do not look for another tool that does the same thing — if the work genuinely needs it, say so and let them decide.', 'nibwp'),
                $ability
            ),
            ['status' => 403]
        );
    }

    return true;
}

/* ----------------------------------------------------------- rate limit -- */

/**
 * @return true|WP_Error
 */
function nibwp_guardrails_check_rate()
{
    $limit = (int) get_option('nibwp_rate_limit_per_minute', 60);
    if ($limit < 1) {
        return true;
    }

    $user_id = get_current_user_id();
    // The window is a fixed minute rather than a rolling one: a rolling window
    // needs every timestamp kept, and this runs on every call.
    $key = 'nibwp_rate_' . $user_id . '_' . (int) floor(time() / 60);

    $count = (int) get_transient($key);
    if ($count >= $limit) {
        return new WP_Error(
            'nibwp_rate_limited',
            sprintf(
                /* translators: 1: the configured limit, 2: seconds until the window resets */
                __('Refused: this site allows %1$d agent calls a minute and that has been reached. Wait %2$d seconds and continue — do not retry immediately, and do not work around it by batching the same work into a different tool. If the limit is genuinely too low for the task, say so.', 'nibwp'),
                $limit,
                60 - (int) (time() % 60)
            ),
            ['status' => 429]
        );
    }

    // Two minutes, so the row outlives its window and cannot be resurrected by
    // a late write landing after the window it counted has passed.
    set_transient($key, $count + 1, 120);

    return true;
}

/* ---------------------------------------------------------- force draft -- */

/**
 * Stop an agent publishing while the setting is on.
 *
 * Deliberately not "set everything to draft": unpublishing a live page because
 * an agent edited a typo on it would be a far worse outcome than the one this
 * setting exists to prevent. Only a transition *to* published is held back.
 */
function nibwp_guardrails_arm_force_draft(): void
{
    if (!get_option('nibwp_force_draft', false)) {
        return;
    }
    if (has_filter('wp_insert_post_data', 'nibwp_guardrails_force_draft')) {
        return;
    }

    add_filter('wp_insert_post_data', 'nibwp_guardrails_force_draft', 99, 4);
}

/**
 * @param array<string,mixed> $data
 * @param array<string,mixed> $postarr
 * @param array<string,mixed> $unsanitized
 * @param bool                $update
 * @return array<string,mixed>
 */
function nibwp_guardrails_force_draft($data, $postarr = [], $unsanitized = [], $update = false)
{
    if (!is_array($data) || ($data['post_status'] ?? '') !== 'publish') {
        return $data;
    }

    // Editing something already public leaves it public. Only a post arriving
    // at "published" for the first time is held.
    if ($update && !empty($postarr['ID']) && get_post_status((int) $postarr['ID']) === 'publish') {
        return $data;
    }

    $data['post_status'] = 'draft';

    return $data;
}
