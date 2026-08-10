<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Seed demo data for audit log and memory. Runs once per install.
 */
function nibwp_seed_demo_data(): void
{
    // OFF by default. The sample audit rows + memories made the Audit Log and
    // Memory panels look populated with activity that never happened. Opt in
    // with: add_filter('nibwp_enable_demo_data', '__return_true').
    if (!apply_filters('nibwp_enable_demo_data', false)) {
        return;
    }
    if (get_option('nibwp_demo_data_seeded')) {
        return;
    }

    nibwp_seed_audit_log();
    nibwp_seed_memory();

    update_option('nibwp_demo_data_seeded', true, autoload: false);
}

function nibwp_seed_audit_log(): void
{
    global $wpdb;
    $table = $wpdb->prefix . 'nibwp_audit_log';

    // Create table if not exists.
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
        if (function_exists('nibwp_audit_log_create_table')) {
            nibwp_audit_log_create_table();
        }
    }

    $user_id = get_current_user_id();
    $ip = '127.0.0.1';
    $now = time();

    $entries = [
        ['nibwp/execute-php', '{"code":"return phpversion();"}', 'success', 12.3, $now - 120],
        ['nibwp/wp-list-posts', '{"post_type":"post","per_page":10}', 'success', 45.8, $now - 300],
        ['nibwp/wp-create-post', '{"title":"Summer Tour Deals","status":"draft"}', 'success', 89.2, $now - 600],
        ['nibwp/memory-store', '{"key":"project-conventions","value":"Use BEM for CSS classes"}', 'success', 8.1, $now - 900],
        ['nibwp/wc-list-products', '{"per_page":5}', 'success', 156.4, $now - 1200],
        ['nibwp/read-file', '{"path":"wp-config.php"}', 'success', 5.2, $now - 1800],
        ['nibwp/wp-get-site-info', '{}', 'success', 22.7, $now - 2400],
        ['nibwp/write-file', '{"path":"nibwp-sandbox/test.php"}', 'success', 14.9, $now - 3000],
        ['nibwp/wp-search', '{"query":"tour deals rome"}', 'success', 67.3, $now - 3600],
        ['nibwp/seo-analyze-content', '{"post_id":1,"focus_keyword":"rome tours"}', 'error', 234.1, $now - 4200],
        ['nibwp/wp-update-post', '{"post_id":42,"title":"Updated Tour"}', 'success', 31.6, $now - 5400],
        ['nibwp/list-directory', '{"path":"wp-content/plugins"}', 'success', 18.4, $now - 7200],
        ['nibwp/elementor-create-page', '{"page_title":"Landing Page"}', 'error', 445.2, $now - 10800],
        ['nibwp/wp-list-users', '{"per_page":5}', 'success', 28.9, $now - 14400],
        ['nibwp/memory-recall', '{"search":"conventions"}', 'success', 3.7, $now - 18000],
    ];

    foreach ($entries as $entry) {
        $wpdb->insert($table, [
            'tool_name' => $entry[0],
            'arguments' => $entry[1],
            'result_status' => $entry[2],
            'execution_time_ms' => $entry[3],
            'user_id' => $user_id,
            'ip_address' => $ip,
            'created_at' => gmdate('Y-m-d H:i:s', $entry[4]),
        ], ['%s', '%s', '%s', '%f', '%d', '%s', '%s']);
    }
}

function nibwp_seed_memory(): void
{
    $existing = get_option('nibwp_memory_store', []);
    if (!empty($existing)) {
        return;
    }

    $now = gmdate('c');
    // Two starter memories so the AI Memory page is never empty on first
    // install. They demonstrate the two patterns we recommend: project-wide
    // conventions (used as system context) and a quick brand-style snapshot.
    $memories = [
        [
            'key'        => 'project-conventions',
            'value'      => "- Use BEM naming for CSS classes\n- Always escape output with esc_html() / wp_kses_post()\n- Custom post types prefixed with the project slug\n- All REST endpoints under /your-namespace/v1/\n- Image sizes: 800x600 cards, 1920x800 heroes",
            'tags'       => ['conventions', 'starter'],
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'key'        => 'brand-style',
            'value'      => "Primary: #2563eb\nAccent: #f59e0b\nText: #1e293b on #f8fafc\nFonts: Inter (body) / Playfair Display (headings)\nButton radius: 8px\nSection padding: 96px desktop, 56px mobile",
            'tags'       => ['design', 'starter'],
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ];

    update_option('nibwp_memory_store', $memories, autoload: false);
}
