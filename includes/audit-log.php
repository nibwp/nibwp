<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Audit logging system for MCP tool calls.
 */

/* ---------------------------------------------------------------------------
 * Live recording — wire into the MCP adapter's tool-call lifecycle.
 *
 * The adapter fires `mcp_adapter_pre_tool_call` (args, before) and
 * `mcp_adapter_tool_call_result` (result, after) around every tool call. We
 * stamp a start time on the first and write the audit row on the second. Both
 * are pass-through filters — they must return their first argument unchanged.
 * ------------------------------------------------------------------------- */

add_filter('mcp_adapter_pre_tool_call', static function ($args, $tool_name = '') {
    $tn = (string) $tool_name;
    $GLOBALS['nibwp_audit_starts'][$tn][] = microtime(true);
    return $args;
}, 10, 2);

add_filter('mcp_adapter_tool_call_result', static function ($result, $args = [], $tool_name = '') {
    if (!get_option('nibwp_audit_log_enabled', true) || !function_exists('nibwp_audit_log_record')) {
        return $result;
    }
    $tn = (string) $tool_name;
    $starts = $GLOBALS['nibwp_audit_starts'][$tn] ?? [];
    $t0 = $starts !== [] ? array_pop($starts) : null;
    $GLOBALS['nibwp_audit_starts'][$tn] = $starts;
    $ms = $t0 !== null ? (microtime(true) - $t0) * 1000 : 0.0;
    $status = (is_wp_error($result) || (is_array($result) && !empty($result['isError']))) ? 'error' : 'success';
    nibwp_audit_log_record($tn, is_array($args) ? $args : [], $status, (float) $ms);
    return $result;
}, 10, 3);

/**
 * Bumped whenever the audit table's columns change. Existing installs run
 * dbDelta once on the next admin request (see the admin_init hook below);
 * activation is not enough, because updating a plugin does not re-activate it.
 */
const NIBWP_AUDIT_LOG_SCHEMA = 2;

function nibwp_audit_log_create_table(): void
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'nibwp_audit_log';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tool_name VARCHAR(255) NOT NULL,
        arguments LONGTEXT,
        result_status VARCHAR(20) NOT NULL,
        execution_time_ms FLOAT DEFAULT 0,
        user_id BIGINT UNSIGNED DEFAULT NULL,
        client_id VARCHAR(191) DEFAULT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX idx_tool_name (tool_name),
        INDEX idx_created_at (created_at)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    update_option('nibwp_audit_log_schema', NIBWP_AUDIT_LOG_SCHEMA, false);
}

add_action('admin_init', static function (): void {
    if ((int) get_option('nibwp_audit_log_schema', 0) < NIBWP_AUDIT_LOG_SCHEMA) {
        nibwp_audit_log_create_table();
    }
});

function nibwp_audit_log_record(string $tool_name, array $arguments, string $status, float $execution_time_ms = 0): void
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'nibwp_audit_log';
    $user_id = get_current_user_id();
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;

    // Which client acted, when the call arrived on an OAuth token. Application
    // passwords carry no client identity, so this stays null for those.
    $client_id = null;
    if (function_exists('nibwp_oauth_current_token')) {
        $token = nibwp_oauth_current_token();
        if (is_array($token) && !empty($token['client_id'])) {
            $client_id = substr((string) $token['client_id'], 0, 191);
        }
    }

    $wpdb->insert(
        $table_name,
        [
            'tool_name' => $tool_name,
            'arguments' => wp_json_encode($arguments),
            'result_status' => $status,
            'execution_time_ms' => $execution_time_ms,
            'user_id' => $user_id > 0 ? $user_id : null,
            'client_id' => $client_id,
            'ip_address' => $ip_address !== null ? sanitize_text_field($ip_address) : null,
            'created_at' => current_time('mysql'),
        ],
        ['%s', '%s', '%s', '%f', '%d', '%s', '%s', '%s'],
    );
}

/**
 * Query log entries with pagination and optional filters.
 *
 * @return array{entries: list<object>, total: int, pages: int}
 */
function nibwp_audit_log_get_entries(int $page = 1, int $per_page = 50, ?string $tool_filter = null, ?string $status_filter = null): array
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'nibwp_audit_log';
    $where_clauses = [];
    $where_values = [];

    if ($tool_filter !== null && $tool_filter !== '') {
        $where_clauses[] = 'tool_name LIKE %s';
        $where_values[] = '%' . $wpdb->esc_like($tool_filter) . '%';
    }

    if ($status_filter !== null && $status_filter !== '') {
        $where_clauses[] = 'result_status = %s';
        $where_values[] = $status_filter;
    }

    $where_sql = '';
    if ($where_clauses !== []) {
        $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
    }

    $count_query = "SELECT COUNT(*) FROM $table_name $where_sql";
    if ($where_values !== []) {
        $count_query = $wpdb->prepare($count_query, ...$where_values);
    }
    $total = (int) $wpdb->get_var($count_query);

    $pages = $per_page > 0 ? (int) ceil($total / $per_page) : 1;
    $offset = ($page - 1) * $per_page;

    $query = "SELECT * FROM $table_name $where_sql ORDER BY created_at DESC LIMIT %d OFFSET %d";
    $query_values = array_merge($where_values, [$per_page, $offset]);
    $entries = $wpdb->get_results($wpdb->prepare($query, ...$query_values));

    return [
        'entries' => $entries ?: [],
        'total' => $total,
        'pages' => $pages,
    ];
}

/**
 * Entries recorded since a moment, oldest first.
 *
 * Deliberately not the sibling above. That one pages a table for a human to
 * read, so it sorts newest first; this one feeds a replay, where the only order
 * that means anything is the order things actually happened in.
 *
 * @param string      $since_local A 'Y-m-d H:i:s' timestamp in site-local time,
 *                                 matching how `created_at` is written.
 * @param int         $limit       Hard cap on rows returned.
 * @param string|null $status      'success' or 'error' to filter by outcome.
 * @param string|null $client_id   Restrict to one OAuth client, so a tape can
 *                                 carry what one assistant did and nothing else.
 * @return list<object>
 */
function nibwp_audit_log_entries_since(
    string $since_local,
    int $limit = 200,
    ?string $status = null,
    ?string $client_id = null
): array {
    global $wpdb;

    $table_name = $wpdb->prefix . 'nibwp_audit_log';
    $where = ['created_at >= %s'];
    $values = [$since_local];

    if ($status !== null && $status !== '') {
        $where[] = 'result_status = %s';
        $values[] = $status;
    }

    if ($client_id !== null && $client_id !== '') {
        $where[] = 'client_id = %s';
        $values[] = $client_id;
    }

    $values[] = $limit;
    $sql = "SELECT * FROM $table_name WHERE " . implode(' AND ', $where) . ' ORDER BY created_at ASC, id ASC LIMIT %d';

    $rows = $wpdb->get_results($wpdb->prepare($sql, ...$values));

    return $rows ?: [];
}

/**
 * Delete entries older than the given retention period.
 *
 * @return int Number of deleted rows.
 */
function nibwp_audit_log_cleanup(int $retention_days = 30): int
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'nibwp_audit_log';
    $cutoff = gmdate('Y-m-d H:i:s', time() - ($retention_days * DAY_IN_SECONDS));

    $deleted = $wpdb->query(
        $wpdb->prepare("DELETE FROM $table_name WHERE created_at < %s", $cutoff),
    );

    return $deleted !== false ? (int) $deleted : 0;
}

/**
 * Return aggregate stats for the audit log.
 *
 * @return array{total_calls: int, calls_today: int, calls_this_week: int, unique_tools_used: int, error_rate: float, most_used_tools: list<object>}
 */
function nibwp_audit_log_get_stats(): array
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'nibwp_audit_log';

    $total_calls = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name");

    $today = current_time('Y-m-d');
    $calls_today = (int) $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE DATE(created_at) = %s", $today),
    );

    $week_start = gmdate('Y-m-d', strtotime('monday this week', strtotime(current_time('Y-m-d'))));
    $calls_this_week = (int) $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE created_at >= %s", $week_start . ' 00:00:00'),
    );

    $unique_tools_used = (int) $wpdb->get_var("SELECT COUNT(DISTINCT tool_name) FROM $table_name");

    $error_count = (int) $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE result_status = %s", 'error'),
    );
    $error_rate = $total_calls > 0 ? round(($error_count / $total_calls) * 100, 1) : 0.0;

    $most_used_tools = $wpdb->get_results(
        "SELECT tool_name, COUNT(*) AS call_count FROM $table_name GROUP BY tool_name ORDER BY call_count DESC LIMIT 10",
    );

    return [
        'total_calls' => $total_calls,
        'calls_today' => $calls_today,
        'calls_this_week' => $calls_this_week,
        'unique_tools_used' => $unique_tools_used,
        'error_rate' => $error_rate,
        'most_used_tools' => $most_used_tools ?: [],
    ];
}

/**
 * Handle cleanup form submission from the audit log admin page.
 */
function nibwp_handle_audit_log_cleanup(): ?int
{
    if (!isset($_POST['nibwp_audit_cleanup'])) {
        return null;
    }
    if (!current_user_can('manage_options')) {
        return null;
    }
    check_admin_referer('nibwp_audit_cleanup');

    $retention = isset($_POST['nibwp_retention_days']) ? absint($_POST['nibwp_retention_days']) : 30;
    if ($retention < 1) {
        $retention = 1;
    }

    return nibwp_audit_log_cleanup($retention);
}

/**
 * Render the audit log admin page.
 */
function nibwp_render_audit_log_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $cleanup_result = nibwp_handle_audit_log_cleanup();
    $stats = nibwp_audit_log_get_stats();

    $current_page = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
    $tool_filter = isset($_GET['tool']) ? sanitize_text_field($_GET['tool']) : null;
    $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : null;
    $per_page = 50;

    $data = nibwp_audit_log_get_entries($current_page, $per_page, $tool_filter, $status_filter);
    $entries = $data['entries'];
    $total = $data['total'];
    $total_pages = $data['pages'];

    $dt_format = nibwp_get_datetime_format('Y-m-d H:i:s');
    $most_used = $stats['most_used_tools'][0]->tool_name ?? '---';

    ?>
    <?php nibwp_render_admin_header(); ?>
    <div class="wrap nibwp-wrap">
        <div class="nibwp-page-header">
            <div>
                <h1><?php esc_html_e('Audit Log', 'nibwp'); ?></h1>
                <p class="nibwp-subtitle"><?php esc_html_e('Every MCP tool call is recorded here for review and debugging.', 'nibwp'); ?></p>
            </div>
        </div>

        <?php if ($cleanup_result !== null): ?>
            <div class="notice notice-success is-dismissible"><p><?php
                printf(
                    /* translators: %d: number of deleted log entries */
                    esc_html(_n('%d old log entry deleted.', '%d old log entries deleted.', $cleanup_result, 'nibwp')),
                    $cleanup_result,
                );
            ?></p></div>
        <?php endif; ?>


        <!-- Stats Cards -->
        <div class="nibwp-audit-stats">
            <div class="nibwp-audit-stat">
                <div class="nibwp-audit-stat-number"><?php echo esc_html(number_format_i18n($stats['total_calls'])); ?></div>
                <div class="nibwp-audit-stat-label"><?php esc_html_e('Total Calls', 'nibwp'); ?></div>
            </div>
            <div class="nibwp-audit-stat">
                <div class="nibwp-audit-stat-number"><?php echo esc_html(number_format_i18n($stats['calls_today'])); ?></div>
                <div class="nibwp-audit-stat-label"><?php esc_html_e("Today's Calls", 'nibwp'); ?></div>
            </div>
            <div class="nibwp-audit-stat">
                <div class="nibwp-audit-stat-number <?php echo $stats['error_rate'] > 10 ? 'is-error' : ''; ?>">
                    <?php
                    /* translators: %s: error rate as a number; %% is a literal percent sign */
                    echo esc_html(sprintf(__('%s%%', 'nibwp'), number_format_i18n((float) $stats['error_rate'], fmod((float) $stats['error_rate'], 1.0) === 0.0 ? 0 : 1)));
                    ?>
                </div>
                <div class="nibwp-audit-stat-label"><?php esc_html_e('Error Rate', 'nibwp'); ?></div>
            </div>
            <div class="nibwp-audit-stat">
                <div class="nibwp-audit-stat-number" style="font-size:16px; padding-top:6px;">
                    <?php echo esc_html($most_used); ?>
                </div>
                <div class="nibwp-audit-stat-label"><?php esc_html_e('Most Used Tool', 'nibwp'); ?></div>
            </div>
        </div>

        <!-- Filters -->
        <form method="get" class="nibwp-audit-filters">
            <input type="hidden" name="page" value="nibwp-audit-log" />
            <input type="text" name="tool" value="<?php echo esc_attr($tool_filter ?? ''); ?>"
                   placeholder="<?php esc_attr_e('Filter by tool name...', 'nibwp'); ?>"
                   style="width:220px;" />
            <select name="status">
                <option value=""><?php esc_html_e('All statuses', 'nibwp'); ?></option>
                <option value="success" <?php selected($status_filter, 'success'); ?>><?php esc_html_e('Success', 'nibwp'); ?></option>
                <option value="error" <?php selected($status_filter, 'error'); ?>><?php esc_html_e('Error', 'nibwp'); ?></option>
            </select>
            <button type="submit" class="button button-primary"><?php esc_html_e('Filter', 'nibwp'); ?></button>
            <?php if ($tool_filter !== null || $status_filter !== null): ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=nibwp-audit-log')); ?>" class="button"><?php esc_html_e('Clear', 'nibwp'); ?></a>
            <?php endif; ?>
        </form>

        <!-- Log Table -->
        <?php if ($entries === []): ?>
            <div class="nibwp-empty-state">
                <div class="nibwp-empty-icon">
                    <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2" width="48" height="48">
                        <rect x="8" y="6" width="32" height="36" rx="4"/>
                        <line x1="16" y1="16" x2="32" y2="16"/>
                        <line x1="16" y1="24" x2="32" y2="24"/>
                        <line x1="16" y1="32" x2="26" y2="32"/>
                    </svg>
                </div>
                <h3><?php esc_html_e('No log entries found.', 'nibwp'); ?></h3>
                <p><?php esc_html_e('MCP tool calls will appear here once AI agents start using the server.', 'nibwp'); ?></p>
            </div>
        <?php else: ?>
            <table class="nibwp-abilities-table">
                <thead>
                    <tr>
                        <th style="width:170px;"><?php esc_html_e('Timestamp', 'nibwp'); ?></th>
                        <th><?php esc_html_e('Tool', 'nibwp'); ?></th>
                        <th style="width:90px;"><?php esc_html_e('Status', 'nibwp'); ?></th>
                        <th style="width:100px;"><?php esc_html_e('Time (ms)', 'nibwp'); ?></th>
                        <th style="width:120px;"><?php esc_html_e('User', 'nibwp'); ?></th>
                        <th style="width:130px;"><?php esc_html_e('IP Address', 'nibwp'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($entries as $entry): ?>
                        <?php
                        $user = $entry->user_id ? get_userdata((int) $entry->user_id) : null;
                        $username = $user ? $user->user_login : '---';
                        $created = wp_date($dt_format, strtotime($entry->created_at));
                        $is_error = $entry->result_status === 'error';

                        // OAuth calls name the client that acted; application
                        // passwords have no client identity to show.
                        $client_label = '';
                        $client_id = (string) ($entry->client_id ?? '');
                        if ($client_id !== '') {
                            $client = function_exists('nibwp_oauth_client') ? nibwp_oauth_client($client_id) : null;
                            $client_label = (string) ($client['client_name'] ?? '');
                            if ($client_label === '') {
                                $client_label = $client_id;
                            }
                        }
                        ?>
                        <tr>
                            <td><?php echo esc_html($created ?: $entry->created_at); ?></td>
                            <td><span class="nibwp-audit-tool-name"><?php echo esc_html($entry->tool_name); ?></span></td>
                            <td>
                                <span class="nibwp-audit-badge <?php echo $is_error ? 'is-error' : 'is-success'; ?>">
                                    <?php echo esc_html(match ((string) $entry->result_status) {
                                        'success' => __('success', 'nibwp'),
                                        'error' => __('error', 'nibwp'),
                                        default => (string) $entry->result_status,
                                    }); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html(number_format((float) $entry->execution_time_ms, 1)); ?></td>
                            <td>
                                <?php echo esc_html($username); ?>
                                <?php if ($client_label !== ''): ?>
                                    <span class="nibwp-audit-client" title="<?php echo esc_attr($client_id); ?>">
                                        <?php echo esc_html($client_label); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><code style="font-size:12px;"><?php echo esc_html($entry->ip_address ?? '---'); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="nibwp-pagination">
                    <span><?php printf(
                        /* translators: 1: first entry shown, 2: last entry shown, 3: total number of entries */
                        esc_html__('Showing %1$d-%2$d of %3$d entries', 'nibwp'),
                        (($current_page - 1) * $per_page) + 1,
                        min($current_page * $per_page, $total),
                        $total,
                    ); ?></span>
                    <div class="nibwp-pagination-links">
                        <?php
                        $base_url = admin_url('admin.php?page=nibwp-audit-log');
                        if ($tool_filter !== null) {
                            $base_url = add_query_arg('tool', $tool_filter, $base_url);
                        }
                        if ($status_filter !== null) {
                            $base_url = add_query_arg('status', $status_filter, $base_url);
                        }

                        if ($current_page > 1): ?>
                            <a href="<?php echo esc_url(add_query_arg('paged', $current_page - 1, $base_url)); ?>">&laquo;</a>
                        <?php endif;

                        $range_start = max(1, $current_page - 2);
                        $range_end = min($total_pages, $current_page + 2);

                        for ($i = $range_start; $i <= $range_end; $i++):
                            if ($i === $current_page): ?>
                                <span class="current"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a href="<?php echo esc_url(add_query_arg('paged', $i, $base_url)); ?>"><?php echo $i; ?></a>
                            <?php endif;
                        endfor;

                        if ($current_page < $total_pages): ?>
                            <a href="<?php echo esc_url(add_query_arg('paged', $current_page + 1, $base_url)); ?>">&raquo;</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Cleanup Form -->
        <form method="post" class="nibwp-audit-cleanup-form"
              onsubmit="return confirm('<?php echo esc_js(__('Delete old log entries? This cannot be undone.', 'nibwp')); ?>');">
            <?php wp_nonce_field('nibwp_audit_cleanup'); ?>
            <label for="nibwp-retention-days"><?php esc_html_e('Clear entries older than', 'nibwp'); ?></label>
            <input type="number" id="nibwp-retention-days" name="nibwp_retention_days"
                   value="30" min="1" max="365" />
            <span><?php esc_html_e('days', 'nibwp'); ?></span>
            <button type="submit" name="nibwp_audit_cleanup" class="button nibwp-btn-danger">
                <?php esc_html_e('Clear Old Logs', 'nibwp'); ?>
            </button>
        </form>
    </div>
    <?php
    nibwp_render_admin_footer();
}
