<?php

declare(strict_types=1);

/**
 * NIBWP Jobs — the outcome-first product surface.
 *
 * A "Job" is what a non-technical user actually wants: an OUTCOME ("Fix my SEO",
 * "Launch a landing page", "Weekly site health"), optionally on a schedule, with
 * an approvals inbox and a plain-English report. Jobs are backed by NIBWP's
 * existing skills + workflows — the AI/agent machinery is hidden.
 *
 * This file owns the data model, the job catalog, the REST handlers, and the
 * schedule tick. Actual autonomous execution is delegated through the
 * `nibwp_job_run_execute` action so the hosted "brain" (or a local runner) can
 * plug in without touching this surface. Nothing here calls an LLM directly.
 *
 * Data model (two CPTs):
 *   nibwp_job       — a configured job (catalog key, targets, schedule, status, brief)
 *   nibwp_job_run   — one execution (status, timing, report, approvals); post_parent = job
 */

if (!defined('ABSPATH')) {
    exit();
}

const NIBWP_JOB_CPT      = 'nibwp_job';
const NIBWP_JOB_RUN_CPT  = 'nibwp_job_run';

// ---------------------------------------------------------------------------
// CPTs
// ---------------------------------------------------------------------------

add_action('init', 'nibwp_jobs_register_cpts');
function nibwp_jobs_register_cpts(): void
{
    $common = [
        'public'       => false,
        'show_ui'      => false,       // surfaced through our own admin page, not the CPT UI
        'show_in_rest' => false,
        'supports'     => ['title'],
        'capability_type' => 'post',
        'map_meta_cap' => true,
    ];
    register_post_type(NIBWP_JOB_CPT, $common);
    register_post_type(NIBWP_JOB_RUN_CPT, $common);
}

// ---------------------------------------------------------------------------
// Catalog — the outcome cards. Built from curated outcomes + the installed
// skills/workflows so the library reflects what THIS site can actually do.
// ---------------------------------------------------------------------------

/**
 * @return array<string,array<string,mixed>>
 */
function nibwp_jobs_catalog(): array
{
    // Curated outcomes. `runs` points at the engine target: "skill:<id>" or
    // "workflow:<slug>". `gated` => writes need approval. `schedule` => a
    // sensible default cadence for the scheduler UI.
    $catalog = [
        'fix-seo' => [
            'title'   => __('Fix my SEO', 'nibwp'),
            'outcome' => __('Audit titles, meta, schema and internal links, then fix the safe wins and flag the rest for you.', 'nibwp'),
            'icon'    => 'search-check',
            'runs'    => 'skill:seo-pro',
            'gated'   => true,
            'category'=> 'maintain',
            'features'=> [
                __('Audit titles, meta, headings and schema', 'nibwp'),
                __('Fix the safe wins automatically', 'nibwp'),
                __('Flag judgment calls for your approval', 'nibwp'),
                __('Plain-English before / after report', 'nibwp'),
            ],
        ],
        'fix-links' => [
            'title'   => __('Find broken links', 'nibwp'),
            'outcome' => __('Crawl your recent posts and pages, check every link with a real request, and report the broken ones.', 'nibwp'),
            'icon'    => 'unlink',
            'runs'    => 'skill:seo-pro',
            'gated'   => false,
            'category'=> 'maintain',
            'features'=> [
                __('Collect links from recent posts and pages', 'nibwp'),
                __('Check each one with a real HTTP request', 'nibwp'),
                __('Report broken links with their status code', 'nibwp'),
                __('Marks internal vs external', 'nibwp'),
            ],
        ],
        'refresh-content' => [
            'title'   => __('Refresh stale content', 'nibwp'),
            'outcome' => __('Find pages that have not been touched in a while and propose updates — dates, facts, links, tone.', 'nibwp'),
            'icon'    => 'refresh-cw',
            'runs'    => 'workflow:content-cleanup',
            'gated'   => true,
            'category'=> 'content',
            'features'=> [
                __('Find stale pages by last-updated date', 'nibwp'),
                __('Propose date, fact, link and tone updates', 'nibwp'),
                __('Draft every change for review', 'nibwp'),
                __('Nothing publishes without your yes', 'nibwp'),
            ],
        ],
        'speed-pass' => [
            'title'   => __('Speed & image cleanup', 'nibwp'),
            'outcome' => __('Compress images and clear out what slows your pages down, then show the before / after.', 'nibwp'),
            'icon'    => 'gauge',
            'runs'    => 'skill:seo-pro',
            'gated'   => true,
            'category'=> 'maintain',
            'features'=> [
                __('Find oversized and uncompressed images', 'nibwp'),
                __('Compress and convert to WebP where safe', 'nibwp'),
                __('Clear render-blocking leftovers', 'nibwp'),
                __('Core Web Vitals before / after', 'nibwp'),
            ],
        ],
        'security-scan' => [
            'title'   => __('Security scan', 'nibwp'),
            'outcome' => __('Check for risky settings, vulnerable plugins and exposed files, and report what needs attention.', 'nibwp'),
            'icon'    => 'shield',
            'runs'    => 'skill:seo-pro',
            'gated'   => false,
            'category'=> 'maintain',
            'schedule'=> 'weekly',
            'features'=> [
                __('Scan for known-vulnerable plugins and themes', 'nibwp'),
                __('Check file and user-permission red flags', 'nibwp'),
                __('Flag outdated, unpatched software', 'nibwp'),
                __('Plain-English risk report', 'nibwp'),
            ],
        ],
        'moderate-comments' => [
            'title'   => __('Clear the comment queue', 'nibwp'),
            'outcome' => __('Sort pending comments — approve the good ones, bin the spam, and hold anything borderline for you.', 'nibwp'),
            'icon'    => 'chat',
            'runs'    => 'skill:seo-pro',
            'gated'   => true,
            'category'=> 'content',
            'features'=> [
                __('Tell spam apart from real comments', 'nibwp'),
                __('Approve the clearly-good ones', 'nibwp'),
                __('Hold borderline ones for your call', 'nibwp'),
                __('Summary of everything actioned', 'nibwp'),
            ],
        ],
        'safe-updates' => [
            'title'   => __('Check for updates', 'nibwp'),
            'outcome' => __('List every pending plugin, theme and core update with its current and new version, so you know what is waiting.', 'nibwp'),
            'icon'    => 'download',
            'runs'    => 'skill:seo-pro',
            'gated'   => false,
            'category'=> 'maintain',
            'schedule'=> 'weekly',
            'features'=> [
                __('List pending plugin, theme and core updates', 'nibwp'),
                __('Show current → new version for each', 'nibwp'),
                __('Flag how many need attention', 'nibwp'),
                __('No network wait — reads WordPress’s own check', 'nibwp'),
            ],
        ],
        'backup-site' => [
            'title'   => __('Back up my site', 'nibwp'),
            'outcome' => __('Take a full backup of files and database, verify it, and keep it somewhere safe.', 'nibwp'),
            'icon'    => 'archive',
            'runs'    => 'skill:seo-pro',
            'gated'   => false,
            'category'=> 'maintain',
            'schedule'=> 'weekly',
            'features'=> [
                __('Back up files and the full database', 'nibwp'),
                __('Verify the backup actually restores', 'nibwp'),
                __('Store it off the server', 'nibwp'),
                __('Keep the last few, prune the rest', 'nibwp'),
            ],
        ],
        'db-cleanup' => [
            'title'   => __('Clean up the database', 'nibwp'),
            'outcome' => __('Trim post revisions, spam, expired transients and orphaned data, then optimise the tables.', 'nibwp'),
            'icon'    => 'database',
            'runs'    => 'skill:seo-pro',
            'gated'   => true,
            'category'=> 'maintain',
            'features'=> [
                __('Remove old post revisions and auto-drafts', 'nibwp'),
                __('Clear spam, trash and expired transients', 'nibwp'),
                __('Drop orphaned meta and optimise tables', 'nibwp'),
                __('Report space reclaimed', 'nibwp'),
            ],
        ],
        'accessibility' => [
            'title'   => __('Accessibility check', 'nibwp'),
            'outcome' => __('Scan pages against WCAG, fix the easy wins, and flag anything that needs a human decision.', 'nibwp'),
            'icon'    => 'accessibility',
            'runs'    => 'skill:seo-pro',
            'gated'   => true,
            'category'=> 'content',
            'features'=> [
                __('Check contrast, alt text, labels and headings', 'nibwp'),
                __('Add missing alt text and ARIA where safe', 'nibwp'),
                __('Flag structural issues for review', 'nibwp'),
                __('WCAG-referenced report', 'nibwp'),
            ],
        ],
        'traffic-digest' => [
            'title'   => __('Weekly traffic digest', 'nibwp'),
            'outcome' => __('A plain-English summary of last week — top pages, traffic trends and what changed — ready to share.', 'nibwp'),
            'icon'    => 'chart',
            'runs'    => 'skill:seo-pro',
            'gated'   => false,
            'category'=> 'content',
            'schedule'=> 'weekly',
            'features'=> [
                __('Top pages and search terms of the week', 'nibwp'),
                __('Traffic up / down vs last week', 'nibwp'),
                __('Notable changes worth knowing', 'nibwp'),
                __('White-label summary for clients', 'nibwp'),
            ],
        ],
        'weekly-health' => [
            'title'   => __('Weekly site health', 'nibwp'),
            'outcome' => __('A hands-off weekly pass: SEO, broken links, performance and security checks, with a report you can share.', 'nibwp'),
            'icon'    => 'heart-pulse',
            'runs'    => 'workflow:seo-full-audit',
            'gated'   => false,
            'category'=> 'maintain',
            'schedule'=> 'weekly',
            'features'=> [
                __('SEO and broken-link sweep', 'nibwp'),
                __('Performance and security checks', 'nibwp'),
                __('Applies the safe fixes automatically', 'nibwp'),
                __('Shareable, white-label weekly report', 'nibwp'),
            ],
        ],
    ];

    /**
     * Let integrations/skills add outcome cards.
     * @param array<string,array<string,mixed>> $catalog
     */
    return (array) apply_filters('nibwp_jobs_catalog', $catalog);
}

/** A single catalog card (or null). */
function nibwp_jobs_catalog_card(string $key): ?array
{
    $c = nibwp_jobs_catalog();
    return $c[$key] ?? null;
}

// ---------------------------------------------------------------------------
// Data helpers
// ---------------------------------------------------------------------------

/**
 * Create a configured job. $args: {catalog, name?, targets?, schedule?, brief?}
 */
function nibwp_jobs_create(array $args): int|WP_Error
{
    $catalog = sanitize_key((string) ($args['catalog'] ?? ''));
    if ($catalog !== '' && $catalog !== 'custom' && !nibwp_jobs_catalog_card($catalog)) {
        return new WP_Error('bad_catalog', 'Unknown job type.');
    }
    $card  = nibwp_jobs_catalog_card($catalog);
    $title = trim((string) ($args['name'] ?? '')) ?: (string) ($card['title'] ?? __('Custom job', 'nibwp'));
    $job_id = wp_insert_post([
        'post_type'   => NIBWP_JOB_CPT,
        'post_status' => 'publish',
        'post_title'  => sanitize_text_field($title),
    ], true);
    if (is_wp_error($job_id)) {
        return $job_id;
    }
    $schedule = in_array(($args['schedule'] ?? ''), ['manual', 'daily', 'weekly'], true)
        ? $args['schedule']
        : (string) ($card['schedule'] ?? 'manual');
    update_post_meta($job_id, '_nibwp_job_catalog', $catalog ?: 'custom');
    update_post_meta($job_id, '_nibwp_job_targets', array_values((array) ($args['targets'] ?? ['self'])));
    update_post_meta($job_id, '_nibwp_job_schedule', $schedule);
    update_post_meta($job_id, '_nibwp_job_status', 'active');
    update_post_meta($job_id, '_nibwp_job_brief', sanitize_textarea_field((string) ($args['brief'] ?? '')));
    return (int) $job_id;
}

/** Start a run for a job. Returns the run id. */
function nibwp_jobs_run(int $job_id): int|WP_Error
{
    if (get_post_type($job_id) !== NIBWP_JOB_CPT) {
        return new WP_Error('not_found', 'No such job.');
    }
    $run_id = wp_insert_post([
        'post_type'   => NIBWP_JOB_RUN_CPT,
        'post_parent' => $job_id,
        'post_status' => 'publish',
        'post_title'  => get_the_title($job_id) . ' — ' . wp_date('Y-m-d H:i'),
    ], true);
    if (is_wp_error($run_id)) {
        return $run_id;
    }
    update_post_meta($run_id, '_nibwp_run_status', 'queued');
    update_post_meta($run_id, '_nibwp_run_started', time());
    update_post_meta($run_id, '_nibwp_run_report', ['summary' => '', 'items' => [], 'flags' => [], 'links' => []]);
    update_post_meta($run_id, '_nibwp_run_approvals', []);
    update_post_meta($run_id, '_nibwp_run_events', []);
    nibwp_jobs_add_event((int) $run_id, ['actor' => 'system', 'action' => __('Job queued', 'nibwp'), 'status' => 'queued', 'detail' => __('Waiting for the NIBWP engine to pick it up.', 'nibwp')]);

    /**
     * Hand off to the execution engine (hosted brain / local runner). The engine
     * moves the run through running → awaiting_approval → done, writes the report
     * + approvals, and streams progress by calling do_action('nibwp_job_event',
     * $run_id, $event). If nothing is listening, the run stays queued and the UI
     * shows "waiting for the NIBWP engine".
     */
    do_action('nibwp_job_run_execute', (int) $run_id, (int) $job_id);
    return (int) $run_id;
}

/** Delete a job and all its runs. */
function nibwp_jobs_delete_job(int $job_id): bool|WP_Error
{
    if (get_post_type($job_id) !== NIBWP_JOB_CPT) {
        return new WP_Error('not_found', 'No such job.');
    }
    foreach (get_posts(['post_type' => NIBWP_JOB_RUN_CPT, 'post_parent' => $job_id, 'numberposts' => -1, 'fields' => 'ids', 'post_status' => 'any']) as $rid) {
        wp_delete_post((int) $rid, true);
    }
    wp_delete_post($job_id, true);
    return true;
}

/** Delete a single run. */
function nibwp_jobs_delete_run(int $run_id): bool|WP_Error
{
    if (get_post_type($run_id) !== NIBWP_JOB_RUN_CPT) {
        return new WP_Error('not_found', 'No such run.');
    }
    wp_delete_post($run_id, true);
    return true;
}

/** Stop a running/queued run. */
function nibwp_jobs_pause_run(int $run_id): bool|WP_Error
{
    if (get_post_type($run_id) !== NIBWP_JOB_RUN_CPT) {
        return new WP_Error('not_found', 'No such run.');
    }
    update_post_meta($run_id, '_nibwp_run_status', 'stopped');
    update_post_meta($run_id, '_nibwp_run_finished', time());
    nibwp_jobs_add_event($run_id, ['actor' => 'you', 'action' => __('You stopped this run', 'nibwp'), 'status' => 'failed', 'detail' => '']);
    do_action('nibwp_job_run_stop', $run_id);
    return true;
}

/** One-off natural-language job ("tell NIBWP what to do") — plan + run. (B) */
function nibwp_jobs_intent(string $brief): int|WP_Error
{
    $brief = trim($brief);
    if ($brief === '') {
        return new WP_Error('empty', 'Tell NIBWP what to do.');
    }
    $job_id = nibwp_jobs_create([
        'catalog' => 'custom',
        'name'    => wp_trim_words($brief, 8, '…'),
        'brief'   => $brief,
        'schedule'=> 'manual',
    ]);
    if (is_wp_error($job_id)) {
        return $job_id;
    }
    // Route through the same planner the engine uses; if unhosted, the run
    // records the intent and waits.
    do_action('nibwp_job_intent_plan', (int) $job_id, $brief);
    $run_id = nibwp_jobs_run((int) $job_id);
    if (!is_wp_error($run_id)) {
        nibwp_jobs_add_event((int) $run_id, ['actor' => 'you', 'action' => __('You asked NIBWP to', 'nibwp') . ' — ' . wp_trim_words($brief, 12, '…'), 'status' => 'info', 'detail' => $brief]);
    }
    return $run_id;
}

/** Record an approval decision on a run. */
function nibwp_jobs_approve(int $run_id, string $approval_id, bool $decision): array|WP_Error
{
    if (get_post_type($run_id) !== NIBWP_JOB_RUN_CPT) {
        return new WP_Error('not_found', 'No such run.');
    }
    $approvals = (array) get_post_meta($run_id, '_nibwp_run_approvals', true);
    $found = false;
    foreach ($approvals as &$a) {
        if (($a['id'] ?? '') === $approval_id) {
            $a['status'] = $decision ? 'approved' : 'denied';
            $a['decided_at'] = time();
            $found = true;
        }
    }
    unset($a);
    if (!$found) {
        return new WP_Error('no_approval', 'That approval is not pending.');
    }
    update_post_meta($run_id, '_nibwp_run_approvals', $approvals);
    nibwp_jobs_add_event($run_id, [
        'actor'  => 'you',
        'action' => $decision ? __('You approved a change', 'nibwp') : __('You denied a change', 'nibwp'),
        'status' => $decision ? 'approved' : 'denied',
        'detail' => (string) ($approval_id),
    ]);
    // Let the engine resume the run now that a decision exists.
    do_action('nibwp_job_run_resume', $run_id, $approval_id, $decision);
    return ['run_id' => $run_id, 'approval_id' => $approval_id, 'decision' => $decision ? 'approved' : 'denied'];
}

/**
 * @return array<int,array<string,mixed>>
 */
function nibwp_jobs_list(): array
{
    $out = [];
    foreach (get_posts(['post_type' => NIBWP_JOB_CPT, 'numberposts' => -1, 'post_status' => 'publish']) as $p) {
        $out[] = nibwp_jobs_job_to_array($p);
    }
    return $out;
}

function nibwp_jobs_job_to_array(WP_Post $p): array
{
    $catalog = (string) get_post_meta($p->ID, '_nibwp_job_catalog', true);
    $card = nibwp_jobs_catalog_card($catalog);
    return [
        'id'       => (int) $p->ID,
        'name'     => $p->post_title,
        'catalog'  => $catalog,
        'icon'     => (string) ($card['icon'] ?? 'wand-2'),
        'schedule' => (string) get_post_meta($p->ID, '_nibwp_job_schedule', true) ?: 'manual',
        'status'   => (string) get_post_meta($p->ID, '_nibwp_job_status', true) ?: 'active',
        'brief'    => (string) get_post_meta($p->ID, '_nibwp_job_brief', true),
        'last_run' => nibwp_jobs_last_run_summary((int) $p->ID),
    ];
}

function nibwp_jobs_last_run_summary(int $job_id): ?array
{
    $runs = get_posts([
        'post_type' => NIBWP_JOB_RUN_CPT, 'post_parent' => $job_id,
        'numberposts' => 1, 'orderby' => 'date', 'order' => 'DESC', 'post_status' => 'publish',
    ]);
    if (!$runs) {
        return null;
    }
    $r = $runs[0];
    return [
        'run_id' => (int) $r->ID,
        'status' => (string) get_post_meta($r->ID, '_nibwp_run_status', true),
        'when'   => (string) $r->post_date,
    ];
}

/**
 * @return array<int,array<string,mixed>>
 */
function nibwp_jobs_runs(int $limit = 50): array
{
    $out = [];
    foreach (get_posts(['post_type' => NIBWP_JOB_RUN_CPT, 'numberposts' => $limit, 'post_status' => 'publish', 'orderby' => 'date', 'order' => 'DESC']) as $r) {
        $out[] = nibwp_jobs_run_to_array($r);
    }
    return $out;
}

function nibwp_jobs_run_to_array(WP_Post $r): array
{
    $approvals = (array) get_post_meta($r->ID, '_nibwp_run_approvals', true);
    return [
        'id'        => (int) $r->ID,
        'job_id'    => (int) $r->post_parent,
        'job_name'  => get_the_title($r->post_parent),
        'status'    => (string) get_post_meta($r->ID, '_nibwp_run_status', true),
        'started'   => (int) get_post_meta($r->ID, '_nibwp_run_started', true),
        'finished'  => (int) get_post_meta($r->ID, '_nibwp_run_finished', true),
        'report'    => (array) get_post_meta($r->ID, '_nibwp_run_report', true),
        'real'      => (bool) get_post_meta($r->ID, '_nibwp_run_real', true),
        'events'    => array_values((array) get_post_meta($r->ID, '_nibwp_run_events', true)),
        'pending_approvals' => array_values(array_filter($approvals, static fn ($a) => ($a['status'] ?? 'pending') === 'pending')),
        'approvals' => $approvals,
    ];
}

// ---------------------------------------------------------------------------
// Timeline events — the "what's happening" stream. The engine (or lifecycle
// helpers here) append events; the Activity tab renders them live. Engines
// stream by calling: do_action('nibwp_job_event', $run_id, ['action'=>..,'status'=>..]).
// ---------------------------------------------------------------------------

add_action('nibwp_job_event', 'nibwp_jobs_add_event', 10, 2);

/** Append one timeline event to a run. Keeps the last 200. */
function nibwp_jobs_add_event(int $run_id, array $event): void
{
    if (get_post_type($run_id) !== NIBWP_JOB_RUN_CPT) {
        return;
    }
    $events = (array) get_post_meta($run_id, '_nibwp_run_events', true);
    $events[] = [
        'ts'     => time(),
        'actor'  => sanitize_text_field((string) ($event['actor'] ?? 'system')),
        'action' => sanitize_text_field((string) ($event['action'] ?? '')),
        'status' => sanitize_key((string) ($event['status'] ?? 'info')),
        'detail' => sanitize_text_field((string) ($event['detail'] ?? '')),
    ];
    update_post_meta($run_id, '_nibwp_run_events', array_slice($events, -200));
}

/**
 * Flat, newest-first feed of events across all runs (the live Activity stream).
 * @return array<int,array<string,mixed>>
 */
function nibwp_jobs_activity(int $since = 0, int $limit = 60): array
{
    // Live view = recent activity. Ignore events older than this window so a
    // long-idle run can't stretch the timeline axis. Filterable.
    $window = (int) apply_filters('nibwp_jobs_activity_window', 2 * HOUR_IN_SECONDS);
    $floor  = max($since, time() - $window);
    $out = [];
    foreach (get_posts(['post_type' => NIBWP_JOB_RUN_CPT, 'numberposts' => 40, 'post_status' => 'publish', 'orderby' => 'date', 'order' => 'DESC']) as $r) {
        $job_name = get_the_title($r->post_parent);
        foreach ((array) get_post_meta($r->ID, '_nibwp_run_events', true) as $ev) {
            if ((int) ($ev['ts'] ?? 0) <= $floor) {
                continue;
            }
            $out[] = ['run_id' => (int) $r->ID, 'job_name' => $job_name] + $ev;
        }
    }
    usort($out, static fn ($a, $b) => (int) $b['ts'] <=> (int) $a['ts']);
    return array_slice($out, 0, $limit);
}

/** All runs currently awaiting a human decision (the inbox). */
function nibwp_jobs_approvals_inbox(): array
{
    $out = [];
    foreach (nibwp_jobs_runs(100) as $run) {
        foreach ($run['pending_approvals'] as $a) {
            $out[] = ['run_id' => $run['id'], 'job_name' => $run['job_name']] + $a;
        }
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Built-in local runner (stub engine).
//
// Until the hosted "brain" is connected, this makes the product work end-to-end
// locally: a run is planned into timeline events, then a gated job pauses in the
// Approvals inbox (awaiting_approval) while a read-only/safe job completes with a
// report. It only fills in the workflow shell — it does NOT perform real site
// changes. A real engine hooks the same actions at an earlier priority (or you
// disable this via the `nibwp_jobs_use_local_runner` filter) to take over.
// ---------------------------------------------------------------------------

add_action('nibwp_job_run_execute', 'nibwp_jobs_local_runner', 20, 2);
function nibwp_jobs_local_runner(int $run_id, int $job_id): void
{
    if (!apply_filters('nibwp_jobs_use_local_runner', true)) {
        return;
    }
    if (get_post_meta($run_id, '_nibwp_run_simulated', true)) {
        return; // already handled (or a real engine ran)
    }
    update_post_meta($run_id, '_nibwp_run_simulated', '1');

    $catalog = (string) get_post_meta($job_id, '_nibwp_job_catalog', true);

    // Real executor available? Run it for real (no simulation).
    if (function_exists('nibwp_jobs_has_executor') && nibwp_jobs_has_executor($catalog)) {
        update_post_meta($run_id, '_nibwp_run_real', '1');
        $exec = nibwp_jobs_executors()[$catalog];
        call_user_func($exec['run'], $run_id, $job_id);
        return;
    }

    $card  = nibwp_jobs_catalog_card($catalog);
    $title = (string) ($card['title'] ?? get_the_title($job_id));
    $feats = array_values((array) ($card['features'] ?? []));

    update_post_meta($run_id, '_nibwp_run_status', 'running');
    nibwp_jobs_add_event($run_id, ['actor' => 'agent', 'action' => __('Planning the work', 'nibwp'), 'status' => 'running', 'detail' => (string) ($card['outcome'] ?? '')]);
    foreach (array_slice($feats, 0, 2) as $f) {
        nibwp_jobs_add_event($run_id, ['actor' => 'agent', 'action' => $f, 'status' => 'running', 'detail' => '']);
    }

    if (!empty($card['gated'])) {
        $approval = [
            'id'      => $run_id . '-1',
            'title'   => sprintf(__('Approve changes for “%s”', 'nibwp'), $title),
            'detail'  => __('NIBWP prepared changes that affect your site. Approve to apply them, or deny to skip.', 'nibwp'),
            'preview' => implode("\n", array_slice($feats, 0, 3)),
            'status'  => 'pending',
        ];
        update_post_meta($run_id, '_nibwp_run_approvals', [$approval]);
        update_post_meta($run_id, '_nibwp_run_status', 'awaiting_approval');
        nibwp_jobs_add_event($run_id, ['actor' => 'system', 'action' => __('Waiting for your approval', 'nibwp'), 'status' => 'info', 'detail' => $approval['title']]);
    } else {
        update_post_meta($run_id, '_nibwp_run_status', 'done');
        update_post_meta($run_id, '_nibwp_run_finished', time());
        update_post_meta($run_id, '_nibwp_run_report', [
            'summary' => sprintf(__('“%s” finished — nothing needed your approval.', 'nibwp'), $title),
            'items'   => $feats,
            'flags'   => [],
        ]);
        nibwp_jobs_add_event($run_id, ['actor' => 'system', 'action' => __('Job done', 'nibwp'), 'status' => 'done', 'detail' => '']);
    }
}

/** Finish a run once its approval is decided (local runner). */
add_action('nibwp_job_run_resume', 'nibwp_jobs_local_resume', 20, 3);
function nibwp_jobs_local_resume(int $run_id, string $approval_id, bool $decision): void
{
    if (!apply_filters('nibwp_jobs_use_local_runner', true) || !get_post_meta($run_id, '_nibwp_run_simulated', true)) {
        return;
    }
    $job_id  = (int) get_post($run_id)->post_parent;
    $catalog = (string) get_post_meta($job_id, '_nibwp_job_catalog', true);

    // Real executor with an apply step? Let it finish the run for real.
    if (get_post_meta($run_id, '_nibwp_run_real', true) && function_exists('nibwp_jobs_has_executor') && nibwp_jobs_has_executor($catalog)) {
        $exec = nibwp_jobs_executors()[$catalog];
        if (!empty($exec['apply'])) {
            call_user_func($exec['apply'], $run_id, $job_id, $decision);
        }
        return;
    }

    $card   = nibwp_jobs_catalog_card($catalog);
    $title  = (string) ($card['title'] ?? get_the_title($job_id));
    $feats  = array_values((array) ($card['features'] ?? []));

    update_post_meta($run_id, '_nibwp_run_status', 'done');
    update_post_meta($run_id, '_nibwp_run_finished', time());
    update_post_meta($run_id, '_nibwp_run_report', [
        'summary' => $decision
            ? sprintf(__('“%s” finished — your approved changes were applied.', 'nibwp'), $title)
            : sprintf(__('“%s” finished — the changes were skipped as you asked.', 'nibwp'), $title),
        'items'   => $decision ? $feats : [__('No changes made — you denied the step.', 'nibwp')],
        'flags'   => [],
    ]);
    nibwp_jobs_add_event($run_id, ['actor' => 'system', 'action' => __('Job done', 'nibwp'), 'status' => 'done', 'detail' => '']);
}

// ---------------------------------------------------------------------------
// Scheduler — a daily tick that starts due scheduled jobs. WP-Cron; no external
// dependency. (The hosted engine can drive finer cadences later.)
// ---------------------------------------------------------------------------

add_action('init', 'nibwp_jobs_schedule_tick');
function nibwp_jobs_schedule_tick(): void
{
    if (!wp_next_scheduled('nibwp_jobs_tick')) {
        wp_schedule_event(time() + 300, 'daily', 'nibwp_jobs_tick');
    }
}

add_action('nibwp_jobs_tick', 'nibwp_jobs_run_due');
function nibwp_jobs_run_due(): void
{
    $now = time();
    foreach (get_posts(['post_type' => NIBWP_JOB_CPT, 'numberposts' => -1, 'post_status' => 'publish']) as $p) {
        if ((string) get_post_meta($p->ID, '_nibwp_job_status', true) !== 'active') {
            continue;
        }
        $schedule = (string) get_post_meta($p->ID, '_nibwp_job_schedule', true);
        if (!in_array($schedule, ['daily', 'weekly'], true)) {
            continue;
        }
        $last = (int) get_post_meta($p->ID, '_nibwp_job_last_scheduled', true);
        $due  = $schedule === 'weekly' ? WEEK_IN_SECONDS : DAY_IN_SECONDS;
        if ($now - $last >= $due) {
            update_post_meta($p->ID, '_nibwp_job_last_scheduled', $now);
            nibwp_jobs_run((int) $p->ID);
        }
    }
}

// ---------------------------------------------------------------------------
// REST — the admin page drives everything over these (fetch + X-WP-Nonce), same
// convention as the Workflows page. All manage_options-gated.
// ---------------------------------------------------------------------------

add_action('rest_api_init', 'nibwp_jobs_register_rest');
function nibwp_jobs_register_rest(): void
{
    $can = static fn (): bool => current_user_can('manage_options');
    $route = static function (string $path, string $cb, string $methods = 'POST') use ($can): void {
        register_rest_route('nibwp/v1', $path, [
            'methods' => $methods,
            'callback' => $cb,
            'permission_callback' => $can,
        ]);
    };
    $route('/jobs/create', 'nibwp_jobs_rest_create');
    $route('/jobs/run', 'nibwp_jobs_rest_run');
    $route('/jobs/intent', 'nibwp_jobs_rest_intent');
    $route('/jobs/approve', 'nibwp_jobs_rest_approve');
    $route('/jobs/toggle', 'nibwp_jobs_rest_toggle');
    $route('/jobs/schedule', 'nibwp_jobs_rest_schedule');
    $route('/jobs/run/(?P<id>\d+)', 'nibwp_jobs_rest_get_run', 'GET');
    $route('/jobs/activity', 'nibwp_jobs_rest_activity', 'GET');
    $route('/jobs/delete', 'nibwp_jobs_rest_delete_job');
    $route('/jobs/run/delete', 'nibwp_jobs_rest_delete_run');
    $route('/jobs/run/pause', 'nibwp_jobs_rest_pause_run');
}

function nibwp_jobs_rest_delete_job(WP_REST_Request $r): array|WP_Error
{
    return nibwp_jobs_rest_wrap(is_wp_error($x = nibwp_jobs_delete_job((int) $r->get_param('job_id'))) ? $x : 0);
}
function nibwp_jobs_rest_delete_run(WP_REST_Request $r): array|WP_Error
{
    return nibwp_jobs_rest_wrap(is_wp_error($x = nibwp_jobs_delete_run((int) $r->get_param('run_id'))) ? $x : 0);
}
function nibwp_jobs_rest_pause_run(WP_REST_Request $r): array|WP_Error
{
    return nibwp_jobs_rest_wrap(is_wp_error($x = nibwp_jobs_pause_run((int) $r->get_param('run_id'))) ? $x : 0);
}

/** Runs currently queued or running — for the topbar "running jobs" chip. */
function nibwp_jobs_running(): array
{
    $out = [];
    foreach (get_posts(['post_type' => NIBWP_JOB_RUN_CPT, 'numberposts' => 20, 'post_status' => 'publish', 'orderby' => 'date', 'order' => 'DESC']) as $r) {
        $status = (string) get_post_meta($r->ID, '_nibwp_run_status', true);
        if (!in_array($status, ['queued', 'running', 'awaiting_approval'], true)) {
            continue;
        }
        $events = (array) get_post_meta($r->ID, '_nibwp_run_events', true);
        $last = end($events) ?: [];
        $out[] = [
            'id'       => (int) $r->ID,
            'job_id'   => (int) $r->post_parent,
            'catalog'  => (string) get_post_meta((int) $r->post_parent, '_nibwp_job_catalog', true),
            'job_name' => get_the_title($r->post_parent),
            'status'   => $status,
            'started'  => (int) get_post_meta($r->ID, '_nibwp_run_started', true),
            'step'     => (string) ($last['action'] ?? ''),
        ];
    }
    return $out;
}

function nibwp_jobs_rest_activity(WP_REST_Request $r): array
{
    return ['ok' => true, 'events' => nibwp_jobs_activity((int) $r->get_param('since'), 60), 'now' => time()];
}

/** @return array{ok:bool}|array<string,mixed>|WP_Error */
function nibwp_jobs_rest_wrap(int|WP_Error $res, array $extra = []): array|WP_Error
{
    if (is_wp_error($res)) {
        return new WP_Error($res->get_error_code(), $res->get_error_message(), ['status' => 400]);
    }
    return ['ok' => true] + $extra;
}

function nibwp_jobs_rest_create(WP_REST_Request $r): array|WP_Error
{
    $res = nibwp_jobs_create([
        'catalog'  => (string) $r->get_param('catalog'),
        'name'     => (string) $r->get_param('name'),
        'brief'    => (string) $r->get_param('brief'),
        'schedule' => (string) $r->get_param('schedule'),
    ]);
    if (is_wp_error($res)) {
        return nibwp_jobs_rest_wrap($res);
    }
    $run = null;
    if ($r->get_param('run_now')) {
        $rid = nibwp_jobs_run((int) $res);
        $run = is_wp_error($rid) ? null : $rid;
    }
    $job = get_post((int) $res);
    return ['ok' => true, 'job' => $job ? nibwp_jobs_job_to_array($job) : null, 'run_id' => $run];
}

function nibwp_jobs_rest_run(WP_REST_Request $r): array|WP_Error
{
    return nibwp_jobs_rest_wrap(nibwp_jobs_run((int) $r->get_param('job_id')), ['job_id' => (int) $r->get_param('job_id')]);
}

function nibwp_jobs_rest_intent(WP_REST_Request $r): array|WP_Error
{
    $res = nibwp_jobs_intent((string) $r->get_param('brief'));
    if (is_wp_error($res)) {
        return nibwp_jobs_rest_wrap($res);
    }
    $run = get_post((int) $res);
    return ['ok' => true, 'run' => $run ? nibwp_jobs_run_to_array($run) : null];
}

function nibwp_jobs_rest_approve(WP_REST_Request $r): array|WP_Error
{
    return nibwp_jobs_rest_wrap(
        is_wp_error($x = nibwp_jobs_approve((int) $r->get_param('run_id'), (string) $r->get_param('approval_id'), $r->get_param('decision') === 'approve')) ? $x : 0,
        ['inbox' => count(nibwp_jobs_approvals_inbox())]
    );
}

function nibwp_jobs_rest_toggle(WP_REST_Request $r): array|WP_Error
{
    $job_id = (int) $r->get_param('job_id');
    if (get_post_type($job_id) !== NIBWP_JOB_CPT) {
        return new WP_Error('not_found', 'No such job.', ['status' => 404]);
    }
    $now = (string) get_post_meta($job_id, '_nibwp_job_status', true);
    $new = $now === 'paused' ? 'active' : 'paused';
    update_post_meta($job_id, '_nibwp_job_status', $new);
    return ['ok' => true, 'status' => $new];
}

function nibwp_jobs_rest_schedule(WP_REST_Request $r): array|WP_Error
{
    $job_id = (int) $r->get_param('job_id');
    $sched  = (string) $r->get_param('schedule');
    if (get_post_type($job_id) !== NIBWP_JOB_CPT || !in_array($sched, ['manual', 'daily', 'weekly'], true)) {
        return new WP_Error('bad_request', 'Bad job or schedule.', ['status' => 400]);
    }
    update_post_meta($job_id, '_nibwp_job_schedule', $sched);
    return ['ok' => true, 'schedule' => $sched];
}

function nibwp_jobs_rest_get_run(WP_REST_Request $r): array|WP_Error
{
    $run = get_post((int) $r['id']);
    if (!$run || $run->post_type !== NIBWP_JOB_RUN_CPT) {
        return new WP_Error('not_found', 'No such run.', ['status' => 404]);
    }
    return ['ok' => true, 'run' => nibwp_jobs_run_to_array($run)];
}
