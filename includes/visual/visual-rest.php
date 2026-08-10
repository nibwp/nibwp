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
});

function nibwp_visual_rest_poll(WP_REST_Request $request): WP_REST_Response
{
    return new WP_REST_Response(nibwp_visual_collect(get_current_user_id()));
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
    if ($request->get_param('approval') !== null) {
        update_option('nibwp_visual_approval', (bool) $request->get_param('approval'), false);
    }

    return new WP_REST_Response(['approval' => nibwp_visual_approval_required()]);
}
