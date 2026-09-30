<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Main dashboard page for NIBWP — overview, quick connect, IDE config generator.
 */

/**
 * Render the main NIBWP dashboard page.
 */
function nibwp_render_dashboard_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $is_enabled = nibwp_is_enabled();
    $current_user = wp_get_current_user();
    $username = $current_user->user_login;
    $rest_url = rest_url('mcp/nibwp');
    $mcp_passwords = nibwp_get_mcp_passwords();
    $has_password = $mcp_passwords !== [];

    // Stats data.
    $integrations = function_exists('nibwp_get_integrations') ? nibwp_get_integrations() : [];
    $active_integrations = array_filter($integrations, static fn($i) => $i['plugin_available'] && $i['enabled']);
    $active_integrations_count = count($active_integrations);

    $available_tools = 0;
    if (function_exists('nibwp_collect_public_abilities')) {
        foreach (nibwp_collect_public_abilities() as $abilities) {
            $available_tools += count($abilities);
        }
    }

    $memory_count = 0;
    if (function_exists('nibwp_admin_memory_get_all')) {
        $memory_count = count(nibwp_admin_memory_get_all());
    } elseif (function_exists('nibwp_memory_get_all')) {
        $memory_count = count(nibwp_memory_get_all());
    } else {
        $mem_data = get_option('nibwp_memory_store', []);
        $memory_count = is_array($mem_data) ? count($mem_data) : 0;
    }

    $workflow_count = (function_exists('nibwp_workflows_unlocked') && nibwp_workflows_unlocked() && function_exists('nibwp_workflows_posts'))
        ? count(nibwp_workflows_posts())
        : 0;
    $skills_active = function_exists('nibwp_skills_stats') ? (int) (nibwp_skills_stats()['active'] ?? 0) : 0;

    // Recent audit log entries.
    $recent_entries = [];
    if (function_exists('nibwp_audit_log_get_entries')) {
        $log_data = nibwp_audit_log_get_entries(1, 5);
        $recent_entries = $log_data['entries'];
    }

    // IDE config generator data.
    $display_password = 'YOUR-APP-PASSWORD';
    $default_name = nibwp_get_mcp_server_name_default();
    $name_placeholder = '__NIBWP_MCP_NAME__';
    $pw_slot = '__NIBWP_PW_SLOT__';

    $configs = nibwp_build_configs($rest_url, $username, $display_password, $name_placeholder);
    $configs_json = (string) wp_json_encode($configs);

    $paste_paragraph_template = nibwp_build_paste_to_agent_paragraph(
        $rest_url,
        $username,
        $display_password,
        $name_placeholder,
        $pw_slot,
    );

    $clients = [
        'claude-desktop' => 'Claude Desktop',
        'claude-code' => 'Claude Code',
        'cursor' => 'Cursor',
        'vscode' => 'VS Code',
        'windsurf' => 'Windsurf',
        'cline' => 'Cline',
        'gemini-cli' => 'Gemini CLI',
        'github-copilot' => 'GitHub Copilot',
        'roo-code' => 'Roo Code',
        'amazon-q' => 'Amazon Q',
        'codex' => 'Codex',
        'zed' => 'Zed',
        'kilo-code' => 'Kilo Code',
        'opencode' => 'OpenCode',
        'antigravity' => 'Antigravity',
    ];

    $copied_label = esc_js(__('Copied!', 'nibwp'));

    // Getting Started checklist.
    $step1_done = $is_enabled;
    $step2_done = $has_password;
    $step3_done = $step1_done && $step2_done;

    ?>
    <?php nibwp_render_admin_header(); ?>
    <div class="wrap nibwp-wrap">


        <div class="nibwp-page-header">
            <div>
                <h1><?php esc_html_e('Dashboard', 'nibwp'); ?></h1>
                <p class="nibwp-subtitle"><?php esc_html_e('Overview and quick access to connect AI agents to your WordPress site.', 'nibwp'); ?></p>
            </div>
        </div>

        <?php
        // Premium module — absent from the Free build, so guard the call.
        if (function_exists('nibwp_render_figma_promo')) {
            nibwp_render_figma_promo();
        }
        ?>

        <!-- Stats Row -->
        <div class="nibwp-dash-stats">
            <div class="nibwp-dash-stat">
                <div class="nibwp-dash-stat-icon <?php echo $is_enabled ? 'is-on' : 'is-off'; ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                </div>
                <div class="nibwp-dash-stat-info">
                    <div class="nibwp-dash-stat-value"><?php echo $is_enabled
                        ? esc_html__('ON', 'nibwp')
                        : esc_html__('OFF', 'nibwp'); ?></div>
                    <div class="nibwp-dash-stat-label"><?php esc_html_e('MCP Status', 'nibwp'); ?></div>
                </div>
            </div>
            <div class="nibwp-dash-stat">
                <div class="nibwp-dash-stat-icon is-blue">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 18l6-6-6-6M8 6l-6 6 6 6"/></svg>
                </div>
                <div class="nibwp-dash-stat-info">
                    <div class="nibwp-dash-stat-value"><?php echo esc_html((string) $active_integrations_count); ?></div>
                    <div class="nibwp-dash-stat-label"><?php esc_html_e('Integrations', 'nibwp'); ?></div>
                </div>
            </div>
            <div class="nibwp-dash-stat">
                <div class="nibwp-dash-stat-icon is-purple">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>
                </div>
                <div class="nibwp-dash-stat-info">
                    <div class="nibwp-dash-stat-value"><?php echo esc_html((string) $available_tools); ?></div>
                    <div class="nibwp-dash-stat-label"><?php esc_html_e('Available Tools', 'nibwp'); ?></div>
                </div>
            </div>
            <div class="nibwp-dash-stat">
                <div class="nibwp-dash-stat-icon is-blue">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a10 10 0 100 20 10 10 0 000-20z"/><path d="M12 6v6l4 2"/></svg>
                </div>
                <div class="nibwp-dash-stat-info">
                    <div class="nibwp-dash-stat-value"><?php echo esc_html((string) $memory_count); ?></div>
                    <div class="nibwp-dash-stat-label"><?php esc_html_e('Memory Entries', 'nibwp'); ?></div>
                </div>
            </div>
            <div class="nibwp-dash-stat">
                <div class="nibwp-dash-stat-icon is-purple">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 6 2 2 4-4"/><path d="m3 14 2 2 4-4"/><path d="M13 6h8"/><path d="M13 14h8"/><path d="M13 20h8"/><path d="M3 20h2"/></svg>
                </div>
                <div class="nibwp-dash-stat-info">
                    <div class="nibwp-dash-stat-value"><?php echo esc_html((string) $workflow_count); ?></div>
                    <div class="nibwp-dash-stat-label"><?php esc_html_e('Workflows', 'nibwp'); ?></div>
                </div>
            </div>
            <div class="nibwp-dash-stat">
                <div class="nibwp-dash-stat-icon is-on">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                </div>
                <div class="nibwp-dash-stat-info">
                    <div class="nibwp-dash-stat-value"><?php echo esc_html((string) $skills_active); ?></div>
                    <div class="nibwp-dash-stat-label"><?php esc_html_e('Skills', 'nibwp'); ?></div>
                </div>
            </div>
        </div>

        <!-- Two Column Layout -->
        <div class="nibwp-dashboard-grid">

            <!-- Left Column -->
            <div>

                <!-- Quick Connect Card -->
                <div class="nibwp-dash-card">
                    <div class="nibwp-dash-card-title">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/></svg>
                        <?php esc_html_e('Quick Connect', 'nibwp'); ?>
                    </div>
                    <div class="nibwp-connect-row">
                        <span class="nibwp-connect-label"><?php esc_html_e('Server URL', 'nibwp'); ?></span>
                        <span class="nibwp-connect-value" id="nibwp-dash-url"><?php echo esc_html($rest_url); ?>
                            <button type="button" class="button-link" onclick="nibwpDashCopy('nibwp-dash-url', this)"><?php esc_html_e('Copy', 'nibwp'); ?></button>
                        </span>
                    </div>
                    <div class="nibwp-connect-row">
                        <span class="nibwp-connect-label"><?php esc_html_e('Username', 'nibwp'); ?></span>
                        <span class="nibwp-connect-value"><?php echo esc_html($username); ?></span>
                    </div>
                    <div class="nibwp-connect-row">
                        <span class="nibwp-connect-label"><?php esc_html_e('Password', 'nibwp'); ?></span>
                        <span class="nibwp-connect-value" style="font-family:inherit;">
                            <?php if ($has_password): ?>
                                <span style="color:#00a32a; font-weight:600;">
                                    <?php printf(
                                        /* translators: %s: number of application passwords */
                                        esc_html(_n('%s app password ready', '%s app passwords ready', count($mcp_passwords), 'nibwp')),
                                        esc_html(number_format_i18n(count($mcp_passwords))),
                                    ); ?>
                                </span>
                            <?php else: ?>
                                <span style="color:#dba617; font-weight:600;"><?php esc_html_e('Not created yet', 'nibwp'); ?></span>
                                &mdash;
                                <a href="<?php echo esc_url(admin_url('admin.php?page=nibwp-connect')); ?>"><?php esc_html_e('Create one', 'nibwp'); ?></a>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>

                <!-- IDE Config Generator Card -->
                <div class="nibwp-dash-card" id="nibwp-ide-config-card">
                    <div class="nibwp-dash-card-title">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                        <?php esc_html_e('IDE Config Generator', 'nibwp'); ?>
                    </div>
                    <p style="margin:0 0 14px; font-size:13px; color:#646970;">
                        <?php esc_html_e('Select your AI client to get the ready-to-use MCP config snippet.', 'nibwp'); ?>
                    </p>

                    <!-- Server Name -->
                    <div style="margin:0 0 14px; display:flex; align-items:center; gap:8px;">
                        <label for="nibwp-dash-mcp-name" style="font-size:12px; font-weight:600; color:#646970; text-transform:uppercase; letter-spacing:0.3px;"><?php esc_html_e('Server Name', 'nibwp'); ?></label>
                        <input type="text" id="nibwp-dash-mcp-name"
                               value="<?php echo esc_attr($default_name); ?>"
                               placeholder="<?php echo esc_attr($default_name); ?>"
                               maxlength="25"
                               style="width:200px; padding:4px 8px; border:1px solid #c3c4c7; border-radius:4px; font-size:13px;"
                               oninput="nibwpDashUpdateName(this.value)" />
                    </div>

                    <!-- Client Tabs -->
                    <div class="nibwp-client-tabs-wrap" id="nibwp-dash-tabs-wrap">
                    <div class="nibwp-client-tabs" id="nibwp-dash-tabs">
                        <?php foreach ($clients as $key => $label): ?>
                            <button type="button"
                                    class="nibwp-client-tab<?php echo $key === 'claude-desktop' ? ' active' : ''; ?>"
                                    onclick="nibwpDashSetClient('<?php echo esc_js($key); ?>', this)"
                            ><?php echo esc_html($label); ?></button>
                        <?php endforeach; ?>
                    </div>
                    </div>

                    <!-- Config Output -->
                    <div class="nibwp-tab-content" style="border-radius:6px;">
                        <div class="nibwp-config-block">
                            <pre id="nibwp-dash-config-code" style="min-height:80px;"></pre>
                            <button type="button" class="button nibwp-copy-btn" onclick="nibwpDashCopyConfig(this)"><?php esc_html_e('Copy', 'nibwp'); ?></button>
                        </div>
                        <div id="nibwp-dash-config-footer" style="font-size:13px; color:#666; border-top:1px solid #c3c4c7;">
                            <div id="nibwp-dash-config-hint" style="padding:10px 16px;"></div>
                            <div id="nibwp-dash-config-paths" style="padding:0 16px 10px;"></div>
                        </div>
                    </div>

                    <!-- Paste Prompt -->
                    <div style="margin:14px 0 0;">
                        <div class="nibwp-paste-block">
                            <div class="nibwp-paste-content" id="nibwp-dash-paste-content">
                                <pre id="nibwp-dash-paste-text"></pre>
                            </div>
                            <div class="nibwp-paste-actions">
                                <button type="button" class="button-link" id="nibwp-dash-paste-expand"
                                        onclick="nibwpDashToggleExpandPaste(this)"
                                        aria-expanded="false"
                                        aria-controls="nibwp-dash-paste-content"
                                ><?php esc_html_e('Show full text', 'nibwp'); ?></button>
                                <button type="button" class="button button-primary" onclick="nibwpDashCopyPaste(this)">
                                    <?php esc_html_e('Copy prompt to paste in AI chat', 'nibwp'); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Active Integrations Card -->
                <div class="nibwp-dash-card">
                    <div class="nibwp-dash-card-title">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                        <?php esc_html_e('Active Integrations', 'nibwp'); ?>
                    </div>
                    <?php if ($integrations === []): ?>
                        <p style="color:#646970; font-size:13px; margin:0;"><?php esc_html_e('No integrations configured.', 'nibwp'); ?></p>
                    <?php else: ?>
                        <div class="nibwp-dash-integrations">
                            <?php foreach ($integrations as $key => $integration):
                                $is_active = $integration['plugin_available'] && $integration['enabled'];
                            ?>
                                <div class="nibwp-dash-integration">
                                    <span class="nibwp-dash-int-dot <?php echo $is_active ? 'is-active' : 'is-inactive'; ?>"></span>
                                    <?php echo esc_html($integration['name']); ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p style="margin:12px 0 0;">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=nibwp-integrations')); ?>">
                                <?php esc_html_e('Manage Integrations', 'nibwp'); ?> <span class="nw-rtl-flip" aria-hidden="true">→</span>
                            </a>
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Column -->
            <div class="nibwp-dash-sidebar">

                <!-- Getting Started Card -->
                <div class="nibwp-dash-card">
                    <div class="nibwp-dash-card-title">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        <?php esc_html_e('Getting Started', 'nibwp'); ?>
                    </div>

                    <!-- Step 1 -->
                    <div class="nibwp-checklist-item">
                        <div class="nibwp-check-icon <?php echo $step1_done ? 'is-done' : 'is-pending'; ?>">
                            <?php if ($step1_done): ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                            <?php else: ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/></svg>
                            <?php endif; ?>
                        </div>
                        <div class="nibwp-checklist-text">
                            <strong><?php esc_html_e('Enable AI Abilities', 'nibwp'); ?></strong>
                            <?php if ($step1_done): ?>
                                <span style="color:#00a32a;"><?php esc_html_e('Active', 'nibwp'); ?></span>
                            <?php else: ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=nibwp-connect')); ?>"><?php esc_html_e('Go to Configuration', 'nibwp'); ?></a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Step 2 -->
                    <div class="nibwp-checklist-item">
                        <div class="nibwp-check-icon <?php echo $step2_done ? 'is-done' : 'is-pending'; ?>">
                            <?php if ($step2_done): ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                            <?php else: ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/></svg>
                            <?php endif; ?>
                        </div>
                        <div class="nibwp-checklist-text">
                            <strong><?php esc_html_e('Create App Password', 'nibwp'); ?></strong>
                            <?php if ($step2_done): ?>
                                <span style="color:#00a32a;"><?php printf(
                                    /* translators: %s: number of application passwords */
                                    esc_html(_n('%s password created', '%s passwords created', count($mcp_passwords), 'nibwp')),
                                    esc_html(number_format_i18n(count($mcp_passwords))),
                                ); ?></span>
                            <?php else: ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=nibwp-connect')); ?>"><?php esc_html_e('Create password', 'nibwp'); ?></a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Step 3 -->
                    <div class="nibwp-checklist-item">
                        <div class="nibwp-check-icon <?php echo $step3_done ? 'is-done' : 'is-pending'; ?>">
                            <?php if ($step3_done): ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                            <?php else: ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/></svg>
                            <?php endif; ?>
                        </div>
                        <div class="nibwp-checklist-text">
                            <strong><?php esc_html_e('Connect your AI client', 'nibwp'); ?></strong>
                            <?php if ($step3_done): ?>
                                <span><?php esc_html_e('Use the IDE Config Generator on the left', 'nibwp'); ?></span>
                            <?php else: ?>
                                <span><?php esc_html_e('Complete steps 1 and 2 first', 'nibwp'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity Card -->
                <div class="nibwp-dash-card">
                    <div class="nibwp-dash-card-title">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                        <?php esc_html_e('Recent Activity', 'nibwp'); ?>
                    </div>

                    <?php if ($recent_entries === []): ?>
                        <p style="color:#a7aaad; font-size:13px; margin:0; text-align:center; padding:16px 0;">
                            <?php esc_html_e('No activity yet. Tool calls will appear here.', 'nibwp'); ?>
                        </p>
                    <?php else: ?>
                        <?php foreach ($recent_entries as $entry):
                            $is_error = $entry->result_status === 'error';
                            $time_ago = human_time_diff(strtotime($entry->created_at), current_time('timestamp'));
                        ?>
                            <div class="nibwp-activity-item">
                                <span class="nibwp-activity-dot <?php echo $is_error ? 'is-error' : 'is-success'; ?>"></span>
                                <span class="nibwp-activity-name"><?php echo esc_html($entry->tool_name); ?></span>
                                <span class="nibwp-activity-time"><?php printf(
                                    /* translators: %s: human-readable time difference, e.g. "5 mins" */
                                    esc_html__('%s ago', 'nibwp'),
                                    esc_html($time_ago),
                                ); ?></span>
                            </div>
                        <?php endforeach; ?>
                        <p style="margin:10px 0 0;">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=nibwp-audit-log')); ?>">
                                <?php esc_html_e('View full audit log', 'nibwp'); ?> <span class="nw-rtl-flip" aria-hidden="true">→</span>
                            </a>
                        </p>
                    <?php endif; ?>
                </div>

                <!-- Community Banner -->
                <div class="nibwp-dash-community">
                    <div class="nibwp-dash-community__head">
                        <span class="nibwp-dash-community__icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        </span>
                        <div>
                            <strong><?php esc_html_e('Join the community', 'nibwp'); ?></strong>
                            <span><?php esc_html_e('Share workflows, get help, see what others build with NIBWP.', 'nibwp'); ?></span>
                        </div>
                    </div>
                    <div class="nibwp-dash-community__actions">
                        <a class="nibwp-dash-community__btn is-fb" href="https://www.facebook.com/groups/nibwp" target="_blank" rel="noopener">
                            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M22 12a10 10 0 1 0-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.49-3.89 3.78-3.89 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.44 2.89h-2.34v6.99A10 10 0 0 0 22 12z"/></svg>
                            <?php esc_html_e('Facebook Group', 'nibwp'); ?>
                        </a>
                        <a class="nibwp-dash-community__btn" href="https://community.nibwp.com" target="_blank" rel="noopener">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                            community.nibwp.com
                        </a>
                    </div>
                </div>

            </div>
        </div>

    </div>

    <script>
    (function () {
        var configs = <?php echo $configs_json; ?>;
        var client = 'claude-desktop';
        var defaultName = <?php echo wp_json_encode($default_name); ?>;
        var pasteTemplate = <?php echo wp_json_encode($paste_paragraph_template); ?>;
        var mcpName = <?php echo wp_json_encode($default_name); ?>;
        var namePlaceholder = <?php echo wp_json_encode($name_placeholder); ?>;
        var passwordSentinel = <?php echo wp_json_encode($pw_slot); ?>;
        var passwordValue = <?php echo wp_json_encode($display_password); ?>;
        var passwordIsPlaceholder = true;

        function renderPaste() {
            var text = pasteTemplate.split(namePlaceholder).join(mcpName);
            var container = document.getElementById('nibwp-dash-paste-text');
            container.textContent = '';
            var idx = text.indexOf(passwordSentinel);
            if (idx === -1) {
                container.appendChild(document.createTextNode(text));
                return;
            }
            container.appendChild(document.createTextNode(text.substring(0, idx)));
            if (passwordIsPlaceholder) {
                var span = document.createElement('span');
                span.className = 'nibwp-placeholder';
                span.textContent = 'YOUR-APP-PASSWORD';
                container.appendChild(span);
            } else {
                container.appendChild(document.createTextNode(passwordValue));
            }
            container.appendChild(document.createTextNode(text.substring(idx + passwordSentinel.length)));
        }

        function renderConfig() {
            var cfg = configs[client];
            if (!cfg) return;

            var code = cfg.code.split(namePlaceholder).join(mcpName);
            var codeEl = document.getElementById('nibwp-dash-config-code');
            codeEl.textContent = code;
            if (code.indexOf('YOUR-APP-PASSWORD') !== -1) {
                codeEl.innerHTML = codeEl.innerHTML.replace(
                    /YOUR-APP-PASSWORD/g,
                    '<span class="nibwp-placeholder">YOUR-APP-PASSWORD</span>'
                );
            }
            document.getElementById('nibwp-dash-config-hint').innerHTML = cfg.hint;

            var pathsEl = document.getElementById('nibwp-dash-config-paths');
            var keys = Object.keys(cfg.paths);
            if (keys.length > 0) {
                var html = '<ul style="margin:4px 0 0; padding-inline-start:20px;">';
                keys.forEach(function (label) {
                    html += '<li><strong>' + label + '</strong>: <code>' + cfg.paths[label] + '</code></li>';
                });
                html += '</ul>';
                pathsEl.innerHTML = html;
                pathsEl.style.display = '';
            } else {
                pathsEl.innerHTML = '';
                pathsEl.style.display = 'none';
            }
        }

        function render() {
            renderConfig();
            renderPaste();
        }

        window.nibwpDashSetClient = function (key, btn) {
            client = key;
            document.querySelectorAll('#nibwp-dash-tabs .nibwp-client-tab').forEach(function (t) {
                t.classList.remove('active');
            });
            btn.classList.add('active');
            renderConfig();
        };

        window.nibwpDashUpdateName = function (value) {
            mcpName = value.trim() || defaultName;
            render();
        };

        window.nibwpDashToggleExpandPaste = function (btn) {
            var content = document.getElementById('nibwp-dash-paste-content');
            var expanded = btn.getAttribute('aria-expanded') === 'true';
            if (expanded) {
                content.classList.remove('is-expanded');
                btn.setAttribute('aria-expanded', 'false');
                btn.textContent = <?php echo wp_json_encode(__('Show full text', 'nibwp')); ?>;
            } else {
                content.classList.add('is-expanded');
                btn.setAttribute('aria-expanded', 'true');
                btn.textContent = <?php echo wp_json_encode(__('Show less', 'nibwp')); ?>;
            }
        };

        window.nibwpDashCopyPaste = function (btn) {
            navigator.clipboard.writeText(document.getElementById('nibwp-dash-paste-text').textContent).then(function () {
                var orig = btn.textContent;
                btn.textContent = '<?php echo $copied_label; ?>';
                setTimeout(function () { btn.textContent = orig; }, 2000);
            });
        };

        window.nibwpDashCopyConfig = function (btn) {
            navigator.clipboard.writeText(document.getElementById('nibwp-dash-config-code').textContent).then(function () {
                var orig = btn.textContent;
                btn.textContent = '<?php echo $copied_label; ?>';
                setTimeout(function () { btn.textContent = orig; }, 1500);
            });
        };

        window.nibwpDashCopy = function (id, btn) {
            var el = document.getElementById(id);
            var text = el.childNodes[0] ? el.childNodes[0].textContent.trim() : el.textContent.trim();
            navigator.clipboard.writeText(text).then(function () {
                var orig = btn.textContent;
                btn.textContent = '<?php echo $copied_label; ?>';
                setTimeout(function () { btn.textContent = orig; }, 1500);
            });
        };

        /* Client tabs: single-line strip with hover-to-scroll (same as Connect). */
        (function () {
            var wrap = document.getElementById('nibwp-dash-tabs-wrap');
            var strip = document.getElementById('nibwp-dash-tabs');
            if (!wrap || !strip) { return; }
            var rafId = null, velocity = 0;
            var DEAD_ZONE = 0.05, MAX_SPEED = 32;
            function updateShadows() {
                var max = strip.scrollWidth - strip.clientWidth;
                // Right to left, scrollLeft runs from 0 at the start down to -max at
                // the end, and the RTL stylesheet swaps the fades, so the distance
                // from the start is what both classes need.
                wrap.classList.toggle('has-scroll-left', Math.abs(strip.scrollLeft) > 2);
                wrap.classList.toggle('has-scroll-right', Math.abs(strip.scrollLeft) < max - 2);
            }
            function animate() {
                if (Math.abs(velocity) < 0.1) { rafId = null; return; }
                strip.scrollLeft += velocity;
                updateShadows();
                rafId = requestAnimationFrame(animate);
            }
            wrap.addEventListener('mousemove', function (e) {
                var rect = wrap.getBoundingClientRect();
                var offset = ((e.clientX - rect.left) / rect.width) * 2 - 1;
                if (Math.abs(offset) < DEAD_ZONE) { velocity = 0; }
                else {
                    var sign = offset < 0 ? -1 : 1;
                    var mag = (Math.abs(offset) - DEAD_ZONE) / (1 - DEAD_ZONE);
                    velocity = sign * MAX_SPEED * mag;
                }
                if (velocity !== 0 && rafId === null) { rafId = requestAnimationFrame(animate); }
            });
            wrap.addEventListener('mouseleave', function () { velocity = 0; });
            updateShadows();
            window.addEventListener('resize', updateShadows);
        }());

        render();
    }());
    </script>
    <?php
    nibwp_render_admin_footer();
}
