<?php

declare(strict_types=1);

/**
 * NIBWP Jobs — real executors.
 *
 * These do actual work using only WordPress core + PHP — no third-party APIs,
 * no LLM. Each is bounded and safe. A catalog job with an executor here runs for
 * real; jobs without one fall back to the labelled "Preview" simulator in
 * jobs.php (nibwp_jobs_local_runner).
 *
 * Registry shape: catalog_key => ['run' => callable, 'apply'? => callable]
 *   run   — executes the scan/work, writes events + report (or raises an approval)
 *   apply — for gated jobs, runs on the approval decision (nibwp_job_run_resume)
 */

if (!defined('ABSPATH')) {
    exit();
}

/** @return array<string,array{run:callable,apply?:callable}> */
function nibwp_jobs_executors(): array
{
    return [
        'fix-links'     => ['run' => 'nibwp_jobs_exec_broken_links'],
        'db-cleanup'    => ['run' => 'nibwp_jobs_exec_db_cleanup_scan', 'apply' => 'nibwp_jobs_exec_db_cleanup_apply'],
        'safe-updates'  => ['run' => 'nibwp_jobs_exec_safe_updates'],
        'security-scan' => ['run' => 'nibwp_jobs_exec_security_scan'],
    ];
}

function nibwp_jobs_has_executor(string $catalog): bool
{
    return isset(nibwp_jobs_executors()[$catalog]);
}

// ---------------------------------------------------------------------------
// Find & fix broken links — real crawl of recent content, real HTTP checks.
// Read-only (reports only). Time-boxed so the request stays responsive.
// ---------------------------------------------------------------------------

function nibwp_jobs_exec_broken_links(int $run_id, int $job_id): void
{
    nibwp_jobs_add_event($run_id, ['actor' => 'agent', 'action' => __('Collecting links from recent content', 'nibwp'), 'status' => 'running']);

    $posts = get_posts(['post_type' => ['post', 'page'], 'post_status' => 'publish', 'numberposts' => 40]);
    $links = [];
    foreach ($posts as $p) {
        if (preg_match_all('#(?:href|src)=["\']([^"\']+)["\']#i', (string) $p->post_content, $m)) {
            foreach ($m[1] as $u) {
                $u = trim($u);
                if ($u === '' || preg_match('#^(#|mailto:|tel:|data:|javascript:)#i', $u)) {
                    continue;
                }
                if (str_starts_with($u, '//')) {
                    $u = 'https:' . $u;
                } elseif (str_starts_with($u, '/')) {
                    $u = home_url($u);
                }
                if (!preg_match('#^https?://#i', $u)) {
                    continue;
                }
                $links[$u][] = (int) $p->ID;
            }
        }
    }
    $unique = array_keys($links);
    $home   = wp_parse_url(home_url(), PHP_URL_HOST);
    nibwp_jobs_add_event($run_id, ['actor' => 'agent', 'action' => sprintf(__('Checking %d links', 'nibwp'), count($unique)), 'status' => 'running']);

    $broken = [];
    $checked = 0;
    $start = time();
    $timed_out = false;
    foreach ($unique as $u) {
        if (time() - $start > 20) {
            $timed_out = true;
            break;
        }
        $checked++;
        $args = ['timeout' => 5, 'redirection' => 3, 'sslverify' => false, 'user-agent' => 'NIBWP-LinkCheck'];
        $r = wp_remote_head($u, $args);
        $code = is_wp_error($r) ? 0 : (int) wp_remote_retrieve_response_code($r);
        if (in_array($code, [0, 403, 405, 501], true)) { // HEAD often blocked — confirm with GET
            $r = wp_remote_get($u, $args);
            $code = is_wp_error($r) ? 0 : (int) wp_remote_retrieve_response_code($r);
        }
        if ($code === 0 || $code >= 400) {
            $host = wp_parse_url($u, PHP_URL_HOST);
            $broken[] = ['url' => $u, 'code' => $code ?: 'no response', 'internal' => ($host === $home)];
        }
    }

    $items = [];
    foreach ($broken as $b) {
        $items[] = sprintf('[%s]%s %s', $b['code'], $b['internal'] ? ' ' . __('(internal)', 'nibwp') : '', $b['url']);
    }
    $report = [
        'summary' => sprintf(
            __('Checked %1$d of %2$d links across %3$d recent posts and pages.%4$s', 'nibwp'),
            $checked,
            count($unique),
            count($posts),
            $timed_out ? ' ' . __('(time-boxed at 20s — run again to continue)', 'nibwp') : ''
        ),
        'items'   => $broken ? $items : [__('No broken links found — every link resolved.', 'nibwp')],
        'flags'   => [],
    ];
    if ($broken) {
        $report['flags'][] = sprintf(
            _n('%d broken link needs attention', '%d broken links need attention', count($broken), 'nibwp'),
            count($broken)
        );
    }
    nibwp_jobs_finish($run_id, $report, sprintf(__('Found %d broken links', 'nibwp'), count($broken)));
}

// ---------------------------------------------------------------------------
// Clean up the database — real scan → gated approval → real safe deletions.
// ---------------------------------------------------------------------------

function nibwp_jobs_exec_db_cleanup_scan(int $run_id, int $job_id): void
{
    global $wpdb;
    nibwp_jobs_add_event($run_id, ['actor' => 'agent', 'action' => __('Scanning the database', 'nibwp'), 'status' => 'running']);

    $plan = [
        'revisions'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'"),
        'autodrafts' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'"),
        'trashposts' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'"),
        'spam'       => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'"),
        'trashcom'   => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'"),
        'transients' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
            $wpdb->esc_like('_transient_timeout_') . '%',
            time()
        )),
        'orphanmeta' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL"),
    ];
    update_post_meta($run_id, '_nibwp_cleanup_plan', $plan);

    $labels = [
        'revisions'  => __('post revisions', 'nibwp'),
        'autodrafts' => __('auto-draft posts', 'nibwp'),
        'trashposts' => __('trashed posts', 'nibwp'),
        'spam'       => __('spam comments', 'nibwp'),
        'trashcom'   => __('trashed comments', 'nibwp'),
        'transients' => __('expired transients', 'nibwp'),
        'orphanmeta' => __('orphaned meta rows', 'nibwp'),
    ];
    $items = [];
    foreach ($plan as $k => $n) {
        if ($n > 0) {
            $items[] = number_format_i18n($n) . ' ' . $labels[$k];
        }
    }
    $total = array_sum($plan);

    if ($total === 0) {
        nibwp_jobs_finish($run_id, [
            'summary' => __('Database is already clean — nothing to remove.', 'nibwp'),
            'items'   => [__('No revisions, spam, trashed items, expired transients or orphaned rows found.', 'nibwp')],
            'flags'   => [],
        ], __('Nothing to clean', 'nibwp'));
        return;
    }

    $approval = [
        'id'      => $run_id . '-cleanup',
        'title'   => sprintf(__('Delete %s rows of database clutter?', 'nibwp'), number_format_i18n($total)),
        'detail'  => __('These are all safe to remove. Approve to delete them and optimise the tables.', 'nibwp'),
        'preview' => implode("\n", $items),
        'status'  => 'pending',
    ];
    update_post_meta($run_id, '_nibwp_run_approvals', [$approval]);
    update_post_meta($run_id, '_nibwp_run_status', 'awaiting_approval');
    update_post_meta($run_id, '_nibwp_run_report', ['summary' => sprintf(__('Found %s rows that can be safely removed.', 'nibwp'), number_format_i18n($total)), 'items' => $items, 'flags' => []]);
    nibwp_jobs_add_event($run_id, ['actor' => 'system', 'action' => __('Waiting for your approval', 'nibwp'), 'status' => 'info', 'detail' => $approval['title']]);
}

function nibwp_jobs_exec_db_cleanup_apply(int $run_id, int $job_id, bool $decision): void
{
    if (!$decision) {
        nibwp_jobs_finish($run_id, [
            'summary' => __('Cleanup skipped — you denied it. Nothing was deleted.', 'nibwp'),
            'items'   => [],
            'flags'   => [],
        ], __('Cleanup skipped', 'nibwp'));
        return;
    }
    global $wpdb;
    nibwp_jobs_add_event($run_id, ['actor' => 'agent', 'action' => __('Deleting clutter and optimising tables', 'nibwp'), 'status' => 'running']);

    $done = [];
    // Revisions.
    foreach ((array) $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision'") as $id) {
        wp_delete_post_revision((int) $id);
    }
    $done['revisions'] = (int) $wpdb->rows_affected;
    // Auto-drafts + trashed posts (force delete).
    foreach ((array) $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_status IN ('auto-draft','trash')") as $id) {
        wp_delete_post((int) $id, true);
    }
    // Spam + trashed comments.
    foreach ((array) $wpdb->get_col("SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved IN ('spam','trash')") as $id) {
        wp_delete_comment((int) $id, true);
    }
    // Expired transients (timeout rows + their value rows).
    $expired = (array) $wpdb->get_col($wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
        $wpdb->esc_like('_transient_timeout_') . '%',
        time()
    ));
    foreach ($expired as $name) {
        $key = substr((string) $name, strlen('_transient_timeout_'));
        delete_transient($key);
        delete_option('_transient_timeout_' . $key);
    }
    // Orphaned postmeta.
    $wpdb->query("DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL");
    // Optimise tables.
    foreach ([$wpdb->posts, $wpdb->postmeta, $wpdb->comments, $wpdb->commentmeta, $wpdb->options] as $t) {
        $wpdb->query("OPTIMIZE TABLE {$t}");
    }

    $plan = (array) get_post_meta($run_id, '_nibwp_cleanup_plan', true);
    $items = [];
    $labels = ['revisions' => __('revisions', 'nibwp'), 'autodrafts' => __('auto-drafts', 'nibwp'), 'trashposts' => __('trashed posts', 'nibwp'), 'spam' => __('spam comments', 'nibwp'), 'trashcom' => __('trashed comments', 'nibwp'), 'transients' => __('expired transients', 'nibwp'), 'orphanmeta' => __('orphaned meta rows', 'nibwp')];
    foreach ($plan as $k => $n) {
        if ($n > 0) {
            $items[] = sprintf(__('Removed %1$s %2$s', 'nibwp'), number_format_i18n((int) $n), $labels[$k] ?? $k);
        }
    }
    $items[] = __('Optimised the core tables', 'nibwp');
    nibwp_jobs_finish($run_id, [
        'summary' => sprintf(__('Cleanup complete — removed %s rows and optimised the database.', 'nibwp'), number_format_i18n((int) array_sum($plan))),
        'items'   => $items,
        'flags'   => [],
    ], __('Cleanup applied', 'nibwp'));
}

// ---------------------------------------------------------------------------
// Run safe updates — real list of what's pending (read-only). Uses the cached
// update transients, so no network call and no risky writes.
// ---------------------------------------------------------------------------

function nibwp_jobs_exec_safe_updates(int $run_id, int $job_id): void
{
    nibwp_jobs_add_event($run_id, ['actor' => 'agent', 'action' => __('Checking for available updates', 'nibwp'), 'status' => 'running']);

    if (!function_exists('get_plugin_data')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $items = [];
    $flags = [];

    $pt = get_site_transient('update_plugins');
    $plugins = isset($pt->response) ? (array) $pt->response : [];
    foreach ($plugins as $file => $d) {
        $name = $file;
        $path = WP_PLUGIN_DIR . '/' . $file;
        if (file_exists($path)) {
            $info = get_plugin_data($path, false, false);
            $name = ($info['Name'] ?? $file) . ' → ' . ($d->new_version ?? '?');
        }
        $items[] = __('Plugin: ', 'nibwp') . $name;
    }

    $tt = get_site_transient('update_themes');
    $themes = isset($tt->response) ? (array) $tt->response : [];
    foreach ($themes as $slug => $d) {
        $items[] = __('Theme: ', 'nibwp') . $slug . ' → ' . ($d['new_version'] ?? '?');
    }

    $ct = get_site_transient('update_core');
    $core = (isset($ct->updates[0]->response) && $ct->updates[0]->response === 'upgrade') ? $ct->updates[0]->version : '';
    if ($core) {
        $items[] = sprintf(__('WordPress core → %s', 'nibwp'), $core);
    }

    $count = count($plugins) + count($themes) + ($core ? 1 : 0);
    if ($count === 0) {
        $items = [__('Everything is up to date.', 'nibwp')];
    } else {
        $flags[] = sprintf(_n('%d update available — review and apply from Plugins/Themes.', '%d updates available — review and apply from Plugins/Themes.', $count, 'nibwp'), $count);
    }

    nibwp_jobs_finish($run_id, [
        'summary' => $count
            ? sprintf(__('%s pending. Applying is a manual step until the engine handles it — this report tells you exactly what is waiting.', 'nibwp'), sprintf(_n('%d update', '%d updates', $count, 'nibwp'), $count))
            : __('No updates pending — plugins, themes and core are current.', 'nibwp'),
        'items'   => $items,
        'flags'   => $flags,
    ], sprintf(__('%d updates pending', 'nibwp'), $count));
}

// ---------------------------------------------------------------------------
// Security scan — real, core-only checks (no external vulnerability database).
// Read-only.
// ---------------------------------------------------------------------------

function nibwp_jobs_exec_security_scan(int $run_id, int $job_id): void
{
    nibwp_jobs_add_event($run_id, ['actor' => 'agent', 'action' => __('Running security checks', 'nibwp'), 'status' => 'running']);

    $ok = [];
    $flags = [];

    // Debug display.
    if (defined('WP_DEBUG') && WP_DEBUG && (!defined('WP_DEBUG_DISPLAY') || WP_DEBUG_DISPLAY)) {
        $flags[] = __('WP_DEBUG display is on — errors can leak to visitors. Turn off WP_DEBUG_DISPLAY in production.', 'nibwp');
    } else {
        $ok[] = __('Debug output is not exposed to visitors', 'nibwp');
    }
    // debug.log present.
    if (file_exists(WP_CONTENT_DIR . '/debug.log')) {
        $flags[] = __('A wp-content/debug.log file exists — make sure it is not web-accessible.', 'nibwp');
    }
    // File editor.
    if (!defined('DISALLOW_FILE_EDIT') || !DISALLOW_FILE_EDIT) {
        $flags[] = __('The plugin/theme file editor is enabled — set DISALLOW_FILE_EDIT to true.', 'nibwp');
    } else {
        $ok[] = __('Built-in file editor is disabled', 'nibwp');
    }
    // 'admin' username.
    if (get_user_by('login', 'admin')) {
        $flags[] = __('A user named “admin” exists — rename it; it is the first guess in brute-force attacks.', 'nibwp');
    } else {
        $ok[] = __('No default “admin” username', 'nibwp');
    }
    // Open registration to a privileged role.
    if (get_option('users_can_register') && in_array(get_option('default_role'), ['administrator', 'editor'], true)) {
        $flags[] = __('Open registration defaults new users to a privileged role.', 'nibwp');
    }
    // SSL.
    if (!str_starts_with((string) get_option('siteurl'), 'https://')) {
        $flags[] = __('The site URL is not HTTPS.', 'nibwp');
    } else {
        $ok[] = __('Site runs over HTTPS', 'nibwp');
    }
    // Outdated software.
    $pt = get_site_transient('update_plugins');
    $tt = get_site_transient('update_themes');
    $ct = get_site_transient('update_core');
    $out = (isset($pt->response) ? count((array) $pt->response) : 0)
        + (isset($tt->response) ? count((array) $tt->response) : 0)
        + ((isset($ct->updates[0]->response) && $ct->updates[0]->response === 'upgrade') ? 1 : 0);
    if ($out > 0) {
        $flags[] = sprintf(_n('%d component is outdated — outdated software is the top entry point.', '%d components are outdated — outdated software is the top entry point.', $out, 'nibwp'), $out);
    } else {
        $ok[] = __('Core, plugins and themes are up to date', 'nibwp');
    }

    nibwp_jobs_finish($run_id, [
        'summary' => $flags
            ? sprintf(_n('%d issue needs attention. %d checks passed.', '%d issues need attention. %d checks passed.', count($flags), 'nibwp'), count($flags), count($ok))
            : sprintf(__('All %d checks passed — no obvious issues found.', 'nibwp'), count($ok)),
        'items'   => $ok,
        'flags'   => $flags,
    ], sprintf(__('%d issues found', 'nibwp'), count($flags)));
}

// ---------------------------------------------------------------------------
// Shared: finish a run with a report.
// ---------------------------------------------------------------------------

function nibwp_jobs_finish(int $run_id, array $report, string $event_detail = ''): void
{
    update_post_meta($run_id, '_nibwp_run_report', $report);
    update_post_meta($run_id, '_nibwp_run_status', 'done');
    update_post_meta($run_id, '_nibwp_run_finished', time());
    nibwp_jobs_add_event($run_id, ['actor' => 'system', 'action' => __('Job done', 'nibwp'), 'status' => 'done', 'detail' => $event_detail]);
}
