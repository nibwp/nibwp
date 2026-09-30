<?php

declare(strict_types=1);

/**
 * The command bus between an AI client and an open workspace tab.
 *
 * The agent is somewhere else entirely — another machine, or somebody's cloud.
 * The browser is where the site actually renders. This carries one across to
 * the other without either needing to reach the other directly:
 *
 *   agent calls an ability  ->  command queued here
 *                               workspace tab polls, runs it, posts a result
 *   ability returns         <-  result read back out
 *
 * Everything is per-user. Two administrators can each have a workspace open and
 * neither will see the other's commands.
 *
 * There is no local bridge to install because there is nothing to bridge: the
 * queue lives in WordPress, which both ends already talk to.
 */

if (!defined('ABSPATH')) {
    exit();
}

const NIBWP_VISUAL_QUEUE_META = 'nibwp_visual_queue';
const NIBWP_VISUAL_RESULT_PREFIX = 'nibwp_visual_result_';
const NIBWP_VISUAL_HEARTBEAT_PREFIX = 'nibwp_visual_alive_';

/**
 * How long a workspace counts as open after its last poll.
 *
 * Generous on purpose. A browser allows only a handful of connections per
 * host, and opening something heavy in the frame — the block editor pulls
 * dozens of scripts — pushes the next poll to the back of that queue. At 45
 * seconds the workspace was declared shut while it was sitting there working.
 */
const NIBWP_VISUAL_HEARTBEAT_TTL = 180;

/** Longest an ability will wait for the browser before giving up. */
const NIBWP_VISUAL_WAIT_SECONDS = 25;

/** How often the waiting ability re-reads the result store, in microseconds. */
const NIBWP_VISUAL_POLL_INTERVAL = 250000;

/**
 * Which user's workspace a command belongs to.
 *
 * An OAuth-authenticated agent is acting as a specific WordPress user, and that
 * is the workspace it drives — the same account that approved the connection.
 */
function nibwp_visual_user_id(): int
{
    return get_current_user_id();
}

const NIBWP_VISUAL_CLAIM_PREFIX = 'nibwp_visual_claim_';

/**
 * Which workspace tab owns this user right now.
 *
 * Commands are taken off the queue by whoever polls first, so two open
 * workspaces silently split the work between them and each answers half of it
 * — including a tab left open on an older version of the page, which answers
 * with code that no longer exists. The newest tab claims the workspace and the
 * rest are told to stand down.
 */
function nibwp_visual_claim(int $user_id, string $session): void
{
    set_transient(NIBWP_VISUAL_CLAIM_PREFIX . $user_id, $session, HOUR_IN_SECONDS);
}

function nibwp_visual_holder(int $user_id): string
{
    $held = get_transient(NIBWP_VISUAL_CLAIM_PREFIX . $user_id);

    return is_string($held) ? $held : '';
}

/**
 * Whether this tab may take commands. An unclaimed workspace is claimed by the
 * first tab to ask, so a single open tab never has to think about any of this.
 */
function nibwp_visual_holds(int $user_id, string $session): bool
{
    $held = nibwp_visual_holder($user_id);

    // A tab too old to send a session is exactly the tab this exists to stop:
    // it answers with code that has since been replaced. It may keep working
    // only while nobody else has claimed the workspace.
    if ($session === '') {
        return $held === '';
    }

    if ($held === '') {
        nibwp_visual_claim($user_id, $session);

        return true;
    }

    return hash_equals($held, $session);
}

/**
 * Mark a workspace as open. Called by the poll endpoint on every pass.
 */
function nibwp_visual_touch(int $user_id): void
{
    set_transient(NIBWP_VISUAL_HEARTBEAT_PREFIX . $user_id, time(), NIBWP_VISUAL_HEARTBEAT_TTL);
}

const NIBWP_VISUAL_KIND_PREFIX = 'nibwp_visual_kind_';

/**
 * What sort of workspace is listening.
 *
 * A browser tab and the headless runner speak the same protocol but cannot do
 * the same things — a tab cannot photograph itself. Without this the difference
 * only shows up as a failed capture partway through a QA run, which is the
 * expensive place to discover it.
 *
 * Kept on the same clock as the heartbeat, so a workspace that stops answering
 * stops claiming capabilities at the same moment.
 */
function nibwp_visual_set_kind(int $user_id, string $kind): void
{
    $kind = $kind === 'runner' ? 'runner' : 'browser';
    set_transient(NIBWP_VISUAL_KIND_PREFIX . $user_id, $kind, NIBWP_VISUAL_HEARTBEAT_TTL);
}

function nibwp_visual_kind(int $user_id): string
{
    if (!nibwp_visual_is_open($user_id)) {
        return '';
    }

    $kind = get_transient(NIBWP_VISUAL_KIND_PREFIX . $user_id);

    // A workspace that never said is a browser tab: the runner always does, and
    // guessing the more capable of the two is how you promise a screenshot that
    // never arrives.
    return is_string($kind) && $kind === 'runner' ? 'runner' : 'browser';
}

/**
 * Whether a workspace tab is currently open for this user.
 *
 * Worth checking before queueing: a command nobody will ever collect would
 * otherwise sit there until it timed out, and the agent would be told "no
 * response" when the real answer is "nothing is listening".
 */
function nibwp_visual_is_open(int $user_id): bool
{
    return (int) get_transient(NIBWP_VISUAL_HEARTBEAT_PREFIX . $user_id) > 0;
}

/**
 * Read an option straight from the database, past every cache.
 *
 * Both sides of this bus poll in a loop inside a single request, and
 * WordPress memoises option reads — including misses, via notoptions. Using
 * get_option here means the first miss is the only answer the loop ever gets,
 * so a command posted a moment later is never seen and every call times out.
 * Measured: the browser executed the command correctly and the waiting request
 * still gave up after 25 seconds.
 *
 * @return mixed
 */
function nibwp_visual_read(string $name)
{
    global $wpdb;

    $raw = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
        $name
    ));

    return $raw === null ? null : maybe_unserialize($raw);
}

function nibwp_visual_queue_key(int $user_id): string
{
    return NIBWP_VISUAL_QUEUE_META . '_' . $user_id;
}

/**
 * @return array<int, array<string, mixed>>
 */
function nibwp_visual_queue(int $user_id): array
{
    $raw = nibwp_visual_read(nibwp_visual_queue_key($user_id));

    return is_array($raw) ? $raw : [];
}

/**
 * @param array<int, array<string, mixed>> $queue
 */
function nibwp_visual_save_queue(int $user_id, array $queue): void
{
    if ($queue === []) {
        delete_option(nibwp_visual_queue_key($user_id));

        return;
    }

    // Never let a queue nobody is draining grow without bound.
    if (count($queue) > 50) {
        $queue = array_slice($queue, -50);
    }

    update_option(nibwp_visual_queue_key($user_id), $queue, false);
}

/**
 * Queue a command and wait for the browser to answer it.
 *
 * The wait is a poll rather than a push because the thing being waited on is a
 * browser tab, and there is no way to hold a socket open to it from PHP. The
 * ceiling matters: a request that blocks a worker indefinitely is how one stuck
 * tab takes down a site.
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>|WP_Error
 */
/**
 * Accounts other than this one that have a workspace open right now.
 *
 * @return array<int, string>
 */
function nibwp_visual_open_elsewhere(int $except): array
{
    global $wpdb;

    $like = $wpdb->esc_like('_transient_' . NIBWP_VISUAL_HEARTBEAT_PREFIX) . '%';
    $names = $wpdb->get_col($wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
        $like
    ));

    $out = [];
    foreach ($names as $name) {
        $id = (int) substr((string) $name, strlen('_transient_' . NIBWP_VISUAL_HEARTBEAT_PREFIX));
        if ($id > 0 && $id !== $except && nibwp_visual_is_open($id)) {
            $out[] = nibwp_visual_login($id);
        }
    }

    return $out;
}

function nibwp_visual_login(int $user_id): string
{
    $user = $user_id > 0 ? get_userdata($user_id) : null;

    return $user ? $user->user_login : sprintf(
        /* translators: %d: a WordPress user id */
        __('user %d', 'nibwp'),
        $user_id
    );
}

const NIBWP_VISUAL_ATTEMPTS_OPTION = 'nibwp_visual_attempts';

/**
 * Record that an agent asked for something, and what came of it.
 *
 * Site-wide on purpose, not per user. The failure this exists to expose is a
 * command sent by an agent authenticated as one account while the workspace is
 * open as another: the queue is per user, so the workspace simply never hears
 * about it, and both sides look fine while nothing happens. Recording every
 * attempt where anyone can see it is what turns that into a visible fact.
 */
function nibwp_visual_note_attempt(string $command, int $user_id, string $outcome): void
{
    $log = nibwp_visual_read(NIBWP_VISUAL_ATTEMPTS_OPTION);
    $log = is_array($log) ? $log : [];

    $user = $user_id > 0 ? get_userdata($user_id) : null;
    $log[] = [
        'at' => time(),
        'command' => $command,
        'user' => $user ? $user->user_login : (string) $user_id,
        'user_id' => $user_id,
        'outcome' => $outcome,
    ];

    update_option(NIBWP_VISUAL_ATTEMPTS_OPTION, array_slice($log, -25), false);
}

/**
 * @return array<int, array<string, mixed>>
 */
function nibwp_visual_attempts(): array
{
    $log = nibwp_visual_read(NIBWP_VISUAL_ATTEMPTS_OPTION);

    return is_array($log) ? array_reverse($log) : [];
}

function nibwp_visual_dispatch(string $command, array $payload = [], ?int $timeout = null)
{
    $user_id = nibwp_visual_user_id();
    if ($user_id <= 0) {
        return new WP_Error(
            'nibwp_visual_no_user',
            __('The workspace runs as a signed-in administrator, and this request has no user.', 'nibwp'),
            ['status' => 401]
        );
    }

    if (!nibwp_visual_is_open($user_id)) {
        nibwp_visual_note_attempt($command, $user_id, 'no-workspace');

        $others = nibwp_visual_open_elsewhere($user_id);

        return new WP_Error(
            'nibwp_visual_closed',
            $others === []
                ? __('No workspace is open. Ask the person you are working with to open NibWP → Agent View, then try again.', 'nibwp')
                : sprintf(
                    /* translators: 1: the account the agent is signed in as, 2: accounts with a workspace open */
                    __('No workspace is open for %1$s, which is the account this connection signs in as. A workspace IS open for %2$s. The workspace only receives commands from an agent connected as the same WordPress user, so reconnect the AI client as that account, or open the workspace while signed in as %1$s.', 'nibwp'),
                    nibwp_visual_login($user_id),
                    implode(', ', $others)
                ),
            ['status' => 409]
        );
    }

    $id = wp_generate_password(20, false, false);
    $queue = nibwp_visual_queue($user_id);
    $queue[] = [
        'id' => $id,
        'command' => $command,
        'payload' => $payload,
        'queued' => time(),
        // Set by the workspace when a human has to approve it first.
        'needs_approval' => nibwp_visual_needs_approval($command, $payload),
    ];
    nibwp_visual_save_queue($user_id, $queue);
    nibwp_visual_note_attempt($command, $user_id, 'queued');

    return nibwp_visual_await($user_id, $id, $timeout ?? NIBWP_VISUAL_WAIT_SECONDS);
}

/**
 * Queue a command without waiting for it.
 *
 * For things the workspace should react to rather than answer — a page that
 * has just been written, and should now be on screen. Waiting would block the
 * save that triggered it for as long as the browser took to notice, which is
 * an absurd price for a refresh.
 *
 * @param array<string, mixed> $payload
 */
function nibwp_visual_push(string $command, array $payload = []): bool
{
    $user_id = nibwp_visual_user_id();
    if ($user_id <= 0 || !nibwp_visual_is_open($user_id)) {
        return false;
    }

    $queue = nibwp_visual_queue($user_id);

    // One pending refresh is as good as five. A skill that writes a page in
    // several passes would otherwise queue a reload for every one of them.
    foreach ($queue as $item) {
        if (($item['command'] ?? '') === $command && ($item['payload'] ?? []) === $payload) {
            return true;
        }
    }

    $queue[] = [
        'id' => wp_generate_password(20, false, false),
        'command' => $command,
        'payload' => $payload,
        'queued' => time(),
        // Nobody is waiting on it, and showing the user their own page is not
        // an action that needs approving.
        'needs_approval' => false,
        'fireAndForget' => true,
    ];
    nibwp_visual_save_queue($user_id, $queue);

    return true;
}

/**
 * Follow the work: when a post is written while a workspace is open, show it.
 *
 * The agent does not have to remember to do this, and with the builder skills
 * it generally would not — those write through their own abilities and never
 * touch the workspace, so the page appeared everywhere except the screen the
 * user was watching.
 */
add_action('save_post', 'nibwp_visual_follow_save', 20, 3);

function nibwp_visual_follow_save(int $post_id, $post, bool $update): void
{
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }
    if (!$post instanceof WP_Post || $post->post_status === 'auto-draft') {
        return;
    }
    if (!post_type_exists($post->post_type) || !is_post_type_viewable($post->post_type)) {
        return;
    }

    nibwp_visual_push('follow', [
        'postId' => $post_id,
        'title' => (string) $post->post_title,
        'view' => (string) get_permalink($post_id),
        'edit' => (string) get_edit_post_link($post_id, 'raw'),
        'status' => (string) $post->post_status,
    ]);
}

/* ---------------------------------------------------------------------------
 * Narration.
 *
 * Asking an agent to show its work only ever works when the agent chooses to.
 * This does not ask: while a workspace is open, every tool the agent runs is
 * announced to it, whatever the tool was and whoever wrote it. The person
 * watching sees the same sequence of steps the audit log records, as it
 * happens, instead of a still page and a paragraph afterwards.
 * ------------------------------------------------------------------------- */

add_filter('mcp_adapter_pre_tool_call', 'nibwp_visual_narrate_start', 20, 2);
add_filter('mcp_adapter_tool_call_result', 'nibwp_visual_narrate_end', 20, 3);

/**
 * @param mixed $args
 * @return mixed
 */
function nibwp_visual_narrate_start($args, $tool_name = '')
{
    nibwp_visual_narrate((string) $tool_name, is_array($args) ? $args : [], null);

    return $args;
}

/**
 * @param mixed $result
 * @param mixed $args
 * @return mixed
 */
function nibwp_visual_narrate_end($result, $args = [], $tool_name = '')
{
    $failed = is_wp_error($result) || (is_array($result) && !empty($result['isError']));

    // Only failures are worth a second line. Announcing every success twice
    // turns a readable sequence of steps into a wall the user stops reading.
    if ($failed) {
        nibwp_visual_narrate((string) $tool_name, is_array($args) ? $args : [], false);
    }

    return $result;
}

/**
 * Announce one tool call to the workspace, if one is open for this account.
 */
function nibwp_visual_narrate(string $tool_name, array $args, ?bool $ok): void
{
    if ($tool_name === '') {
        return;
    }

    $short = str_contains($tool_name, '/') ? substr(strrchr($tool_name, '/'), 1) : $tool_name;

    // The visual abilities already write their own line the moment the browser
    // runs them, with the real result in it. Narrating them as well would
    // double every step and say less about each one.
    if (str_starts_with($short, 'visual-')) {
        return;
    }

    nibwp_visual_push('note', [
        'label' => $short,
        'detail' => nibwp_visual_narrate_detail($args),
        'tone' => $ok === false ? 'bad' : '',
        // Two identical calls are two steps, and the queue collapses payloads
        // that match exactly. This keeps them apart.
        'at' => round(microtime(true), 3),
    ]);
}

/**
 * The one line worth showing about a call: what it was aimed at.
 *
 * Arguments can be an entire page of block markup, so this looks for the few
 * keys that name a thing and ignores everything else rather than truncating a
 * payload into meaningless soup.
 */
function nibwp_visual_narrate_detail(array $args): string
{
    $interesting = ['title', 'post_title', 'name', 'url', 'path', 'file', 'slug', 'selector', 'query', 'skill_id', 'post_id', 'id'];
    $parts = [];

    foreach ($interesting as $key) {
        if (!isset($args[$key]) || is_array($args[$key]) || is_object($args[$key])) {
            continue;
        }
        $value = trim((string) $args[$key]);
        if ($value === '') {
            continue;
        }
        if (mb_strlen($value) > 60) {
            $value = mb_substr($value, 0, 57) . '…';
        }
        $parts[] = $value;
        if (count($parts) >= 2) {
            break;
        }
    }

    return implode(' · ', $parts);
}

/**
 * Block until the workspace posts a result for this command, or time out.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_visual_await(int $user_id, string $id, int $timeout)
{
    $deadline = microtime(true) + max(1, $timeout);
    $key = NIBWP_VISUAL_RESULT_PREFIX . $id;

    while (microtime(true) < $deadline) {
        $result = nibwp_visual_read($key);
        if (is_array($result)) {
            delete_option($key);

            if (!empty($result['error'])) {
                return new WP_Error(
                    'nibwp_visual_failed',
                    (string) $result['error'],
                    ['status' => 400]
                );
            }

            return is_array($result['data'] ?? null) ? $result['data'] : ['ok' => true];
        }

        usleep(NIBWP_VISUAL_POLL_INTERVAL);
    }

    nibwp_visual_forget($user_id, $id);

    return new WP_Error(
        'nibwp_visual_timeout',
        sprintf(
            /* translators: %d: number of seconds waited */
            __('The workspace did not answer within %d seconds. It may be waiting for someone to approve the action, or the page may be busy.', 'nibwp'),
            $timeout
        ),
        ['status' => 504]
    );
}

/**
 * Drop a command from the queue — it timed out, so nobody should run it later.
 */
function nibwp_visual_forget(int $user_id, string $id): void
{
    $queue = array_values(array_filter(
        nibwp_visual_queue($user_id),
        static fn(array $item): bool => ($item['id'] ?? '') !== $id
    ));

    nibwp_visual_save_queue($user_id, $queue);
}

/**
 * Hand the queued commands to the workspace and clear them.
 *
 * @return array<int, array<string, mixed>>
 */
function nibwp_visual_take(int $user_id): array
{
    $queue = nibwp_visual_queue($user_id);
    if ($queue !== []) {
        nibwp_visual_save_queue($user_id, []);
    }

    return $queue;
}

/**
 * Record what the browser made of a command.
 *
 * @param array<string, mixed>|null $data
 */
function nibwp_visual_answer(string $id, ?array $data, string $error = ''): void
{
    // An option rather than a transient, for the same reason the queue is one:
    // the waiting request reads in a loop and must see a value written by another
    // request. Swept by nibwp_visual_sweep() rather than by a transient TTL.
    update_option(
        NIBWP_VISUAL_RESULT_PREFIX . $id,
        ['data' => $data, 'error' => $error, 'at' => time()],
        false
    );
}

/**
 * Clear results nobody collected — a request that died mid-wait leaves one.
 */
function nibwp_visual_sweep(): void
{
    global $wpdb;

    $like = $wpdb->esc_like(NIBWP_VISUAL_RESULT_PREFIX) . '%';
    $names = $wpdb->get_col($wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
        $like
    ));

    foreach ($names as $name) {
        $value = nibwp_visual_read((string) $name);
        $at = is_array($value) ? (int) ($value['at'] ?? 0) : 0;
        if ($at === 0 || $at < time() - (5 * MINUTE_IN_SECONDS)) {
            delete_option((string) $name);
        }
    }
}
add_action('nibwp_visual_sweep', 'nibwp_visual_sweep');

add_action('init', static function (): void {
    if (!wp_next_scheduled('nibwp_visual_sweep')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'nibwp_visual_sweep');
    }
});

/**
 * Commands that change something a person would mind losing.
 *
 * Reading a page is not one of them. Clicking is, because a click can be
 * Delete. When approval is switched on, these stop at the workspace and wait
 * for the human watching to allow them.
 *
 * @param array<string, mixed> $payload
 */
function nibwp_visual_needs_approval(string $command, array $payload = []): bool
{
    if (!nibwp_visual_approval_required()) {
        return false;
    }

    // Reading blocks is reading. Inserting, changing or deleting one is not.
    // hover and screenshot observe and leave nothing behind, so they belong
    // here — and them being here is what lets an unattended run do visual QA
    // while click and fill still stop for a person.
    $safe = ['open', 'focus', 'close', 'reload', 'read', 'hover', 'screenshot', 'capture', 'viewport', 'audit', 'console', 'tabs', 'blocks', 'block-schema'];

    // A batch is only as safe as the steps inside it. Judging it as one opaque
    // action would make batching a way around the prompt — ask if any step
    // would have been asked about on its own, and stay quiet if none would.
    if ($command === 'batch') {
        foreach ((array) ($payload['steps'] ?? []) as $step) {
            $inner = is_array($step) ? (string) ($step['command'] ?? '') : '';
            if (!in_array($inner, $safe, true)) {
                return true;
            }
        }

        return false;
    }

    return !in_array($command, $safe, true);
}

/**
 * Whether the human-in-the-loop gate is on. Defaults to on: the first time an
 * agent drives someone's admin screens, the safe surprise is being asked.
 */
function nibwp_visual_approval_required(): bool
{
    return (bool) apply_filters('nibwp_visual_approval_required', get_option('nibwp_visual_approval', true));
}
