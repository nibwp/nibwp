<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Workflow sharing — publish a workflow to the sites that share your license.
 *
 * Ticking "License Circle" used to write a post meta value and stop there, so a
 * workflow marked as shared never left the site it was made on. This is the
 * missing half on the plugin side: when the scopes on a workflow change, the
 * difference is sent to the Library hub — newly ticked scopes are published,
 * newly unticked ones withdrawn.
 *
 * Two rules shape everything here:
 *
 *   Saving a workflow must never wait for the network, and must never fail
 *   because of it. The upload runs on a scheduled single event, so the save
 *   itself is exactly as fast and as reliable as it was before.
 *
 *   The circle is identified by a one-way hash of the license key, never the
 *   key. Nothing in this file reads, writes or changes license state — it only
 *   asks which licenses are currently active.
 */

/** Meta keys owned by this file. */
const NIBWP_WF_PUBLISHED_SCOPES = '_nibwp_wf_published_scopes';
const NIBWP_WF_SHARE_STATUS     = '_nibwp_wf_share_status';
const NIBWP_WF_REMOTE_ID        = '_nibwp_wf_remote_id';

/**
 * One-way ids for every license currently active on this site.
 *
 * The hub matches installs to each other by this and stores nothing else about
 * the license. Read-only: this asks the license layer a question and changes
 * none of its state.
 *
 * @return array<int,string>
 */
function nibwp_workflow_circle_hashes(): array
{
    if (!function_exists('nibwp_licenses_get') || !function_exists('nibwp_license_is_active_for_key')) {
        return [];
    }
    $out = [];
    foreach (nibwp_licenses_get() as $key => $_) {
        $key = (string) $key;
        if ($key === '' || !nibwp_license_is_active_for_key($key)) {
            continue;
        }
        $out[] = hash('sha256', 'nibwp-circle|' . $key);
    }
    return array_values(array_unique($out));
}

/** Everything a receiving site needs to rebuild this workflow, and nothing else. */
function nibwp_workflow_share_payload(int $post_id): array
{
    $wf = nibwp_workflow_to_array(get_post($post_id));
    return [
        'type'      => 'workflow',
        'slug'      => (string) ($wf['slug'] ?? ''),
        'title'     => (string) ($wf['title'] ?? ''),
        'summary'   => (string) ($wf['summary'] ?? ''),
        'when'      => (string) ($wf['when'] ?? ''),
        'body'      => (string) ($wf['body'] ?? ''),
        'category'  => (string) ($wf['category'] ?? 'custom'),
        'tools'     => (array) ($wf['tools'] ?? []),
        'icon'      => (string) ($wf['icon'] ?? ''),
        'author'    => (string) ($wf['creator'] ?? ''),
        'origin'    => nibwp_install_id(),
        'remote_id' => (int) get_post_meta($post_id, NIBWP_WF_REMOTE_ID, true),
    ];
}

/**
 * Record what happened, so the card can say something true.
 *
 * @param array<string,mixed> $status
 */
function nibwp_workflow_share_set_status(int $post_id, string $state, string $message = '', array $extra = []): void
{
    update_post_meta($post_id, NIBWP_WF_SHARE_STATUS, array_merge([
        'state'   => $state,
        'message' => $message,
        'at'      => time(),
    ], $extra));
}

/**
 * @return array{state:string,message:string,at:int}
 */
function nibwp_workflow_share_status(int $post_id): array
{
    $s = get_post_meta($post_id, NIBWP_WF_SHARE_STATUS, true);
    if (!is_array($s)) {
        return ['state' => 'local', 'message' => '', 'at' => 0];
    }
    return [
        'state'   => (string) ($s['state'] ?? 'local'),
        'message' => (string) ($s['message'] ?? ''),
        'at'      => (int) ($s['at'] ?? 0),
    ];
}

/**
 * Queue a sync for this workflow. Called from the save path; returns instantly.
 */
function nibwp_workflow_sharing_queue(int $post_id): void
{
    if ($post_id <= 0) {
        return;
    }
    $scopes    = nibwp_workflow_sanitize_visibility(get_post_meta($post_id, '_nibwp_wf_visibility', true));
    $published = (array) get_post_meta($post_id, NIBWP_WF_PUBLISHED_SCOPES, true);
    sort($scopes);
    sort($published);
    if ($scopes === $published) {
        return; // Nothing changed; nothing to say to the hub.
    }

    // Private-only and never published means there is nothing to withdraw either.
    if ($scopes === ['private'] && $published === []) {
        return;
    }

    if (!wp_next_scheduled('nibwp_workflow_sharing_sync', [$post_id])) {
        wp_schedule_single_event(time() + 5, 'nibwp_workflow_sharing_sync', [$post_id]);
    }
    nibwp_workflow_share_set_status($post_id, 'queued', __('Waiting to sync with the hub.', 'nibwp'));
}

add_action('nibwp_workflow_sharing_sync', 'nibwp_workflow_sharing_sync');

/**
 * Send the difference between what is ticked and what is published.
 *
 * Everything is caught: a hub that is down, slow or older than this plugin
 * leaves the workflow exactly as it was, with a status the user can read.
 */
function nibwp_workflow_sharing_sync(int $post_id): void
{
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'nibwp_workflow') {
        return;
    }

    $wf = nibwp_workflow_to_array($post);
    if (!nibwp_workflow_is_owned($wf)) {
        // A starter or an imported copy is somebody else's to share.
        nibwp_workflow_share_set_status($post_id, 'not_owned', __('Only a workflow made on this site can be shared from it.', 'nibwp'));
        return;
    }

    $scopes    = nibwp_workflow_sanitize_visibility(get_post_meta($post_id, '_nibwp_wf_visibility', true));
    $published = array_values((array) get_post_meta($post_id, NIBWP_WF_PUBLISHED_SCOPES, true));
    $wanted    = array_values(array_diff($scopes, ['private']));

    $circles = nibwp_workflow_circle_hashes();
    if ($wanted !== [] && $circles === []) {
        nibwp_workflow_share_set_status(
            $post_id,
            'needs_license',
            __('Sharing needs an active license — that is what identifies the sites you are sharing with.', 'nibwp')
        );
        return;
    }

    $add    = array_values(array_diff($wanted, $published));
    $remove = array_values(array_diff($published, $wanted));

    try {
        if ($remove !== []) {
            $res = nibwp_workflow_sharing_call('unpublish', [
                'origin'    => nibwp_install_id(),
                'circles'   => $circles,
                'scopes'    => $remove,
                'remote_id' => (int) get_post_meta($post_id, NIBWP_WF_REMOTE_ID, true),
                'slug'      => (string) ($wf['slug'] ?? ''),
            ]);
            if (is_wp_error($res)) {
                nibwp_workflow_share_set_status($post_id, nibwp_workflow_share_state_for($res), $res->get_error_message());
                return;
            }
        }

        if ($add !== []) {
            $res = nibwp_workflow_sharing_call('publish', array_merge(nibwp_workflow_share_payload($post_id), [
                'circles' => $circles,
                'scopes'  => $add,
            ]));
            if (is_wp_error($res)) {
                nibwp_workflow_share_set_status($post_id, nibwp_workflow_share_state_for($res), $res->get_error_message());
                return;
            }
            $remote_id = (int) ($res['id'] ?? ($res['asset']['id'] ?? 0));
            if ($remote_id > 0) {
                update_post_meta($post_id, NIBWP_WF_REMOTE_ID, $remote_id);
            }
        }
    } catch (\Throwable $e) {
        nibwp_workflow_share_set_status($post_id, 'error', $e->getMessage());
        return;
    }

    update_post_meta($post_id, NIBWP_WF_PUBLISHED_SCOPES, $wanted);

    if ($wanted === []) {
        delete_post_meta($post_id, NIBWP_WF_REMOTE_ID);
        nibwp_workflow_share_set_status($post_id, 'withdrawn', __('No longer shared.', 'nibwp'));
        return;
    }

    $pending_review = in_array('community', $wanted, true);
    nibwp_workflow_share_set_status(
        $post_id,
        $pending_review ? 'pending_review' : 'shared',
        $pending_review
            ? __('Shared. Community copies are reviewed before they appear publicly.', 'nibwp')
            : __('Shared with the sites on your license.', 'nibwp')
    );
}

/** A 404 from the hub means the routes are not deployed yet, not that we failed. */
function nibwp_workflow_share_state_for(WP_Error $e): string
{
    return $e->get_error_code() === 'hub_no_route' ? 'hub_unavailable' : 'error';
}

/**
 * One call to the Library hub.
 *
 * @return array<string,mixed>|WP_Error
 */
function nibwp_workflow_sharing_call(string $route, array $body)
{
    $url = nibwp_library_api_base() . '/' . ltrim($route, '/');
    $res = wp_remote_post($url, [
        'timeout' => 12,
        'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
        'body'    => (string) wp_json_encode($body),
    ]);
    if (is_wp_error($res)) {
        return new WP_Error('hub_unreachable', sprintf(
            /* translators: %s: the error the HTTP request returned */
            __('Could not reach the workflow hub: %s', 'nibwp'),
            $res->get_error_message()
        ));
    }

    $code = (int) wp_remote_retrieve_response_code($res);
    $json = json_decode((string) wp_remote_retrieve_body($res), true);

    if ($code === 404) {
        return new WP_Error('hub_no_route', __('The hub does not offer workflow sharing yet. Nothing was sent; this will publish itself once the hub is updated.', 'nibwp'));
    }
    if ($code < 200 || $code >= 300) {
        $msg = is_array($json) ? (string) ($json['message'] ?? '') : '';
        if ($msg === '') {
            return new WP_Error('hub_refused', sprintf(
                /* translators: %d: HTTP status code */
                __('The hub refused the workflow (HTTP %d).', 'nibwp'),
                $code
            ));
        }
        return new WP_Error('hub_refused', sprintf(
            /* translators: 1: HTTP status code, 2: message from the hub */
            __('The hub refused the workflow (HTTP %1$d): %2$s', 'nibwp'),
            $code,
            $msg
        ));
    }
    // A 200 that is not JSON is not a success. A parked domain, a maintenance
    // page or a proxy notice all answer 200 with HTML, and treating that as
    // "published" would tell the user their workflow is shared when it is
    // sitting nowhere.
    if (!is_array($json)) {
        return new WP_Error('hub_not_json', __('The workflow hub answered with something other than a result. Nothing was shared.', 'nibwp'));
    }
    if (array_key_exists('ok', $json) && !$json['ok']) {
        return new WP_Error('hub_refused', (string) ($json['message'] ?? __('The hub refused the workflow.', 'nibwp')));
    }
    return $json;
}

/**
 * Retry anything the hub could not take yet.
 *
 * A workflow shared before the hub supported sharing should not need the user
 * to remember it. This picks those up on the next admin load, at most one an
 * hour, so a hub that is still not ready costs one request rather than many.
 */
function nibwp_workflow_sharing_retry_pending(): void
{
    if (get_transient('nibwp_wf_share_retry') !== false) {
        return;
    }
    set_transient('nibwp_wf_share_retry', 1, HOUR_IN_SECONDS);

    foreach (nibwp_workflows_posts() as $p) {
        $status = nibwp_workflow_share_status($p->ID);
        if (!in_array($status['state'], ['hub_unavailable', 'error', 'queued'], true)) {
            continue;
        }
        if (!wp_next_scheduled('nibwp_workflow_sharing_sync', [$p->ID])) {
            wp_schedule_single_event(time() + 10, 'nibwp_workflow_sharing_sync', [$p->ID]);
        }
    }
}
add_action('admin_init', 'nibwp_workflow_sharing_retry_pending', 20);

/**
 * Workflows shared with this site's license circle, for the Discover panel.
 *
 * @return array<int,array<string,mixed>>
 */
function nibwp_workflow_circle_assets(): array
{
    $circles = nibwp_workflow_circle_hashes();
    if ($circles === []) {
        return [];
    }
    $cached = get_transient('nibwp_wf_circle_assets');
    if (is_array($cached)) {
        return $cached;
    }

    $url = nibwp_library_api_base() . '/assets?' . http_build_query([
        'type'     => 'workflow',
        'channel'  => 'license',
        'circles'  => implode(',', $circles),
        'origin'   => nibwp_install_id(),
        'per_page' => 60,
    ]);
    $res = wp_remote_get($url, ['timeout' => 10]);
    if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
        set_transient('nibwp_wf_circle_assets', [], 5 * MINUTE_IN_SECONDS);
        return [];
    }
    $json   = json_decode((string) wp_remote_retrieve_body($res), true);
    $assets = is_array($json) ? (array) ($json['assets'] ?? []) : [];

    // Never offer this site its own workflow back.
    $mine   = nibwp_install_id();
    $assets = array_values(array_filter($assets, static fn($a): bool => (string) (((array) $a)['origin'] ?? '') !== $mine));

    set_transient('nibwp_wf_circle_assets', $assets, 15 * MINUTE_IN_SECONDS);
    return $assets;
}
