<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Settings admin page for NIBWP plugin configuration.
 */

/**
 * Handle POST form submission for settings. Save all settings.
 *
 * @return bool|null True on save, null if no submission.
 */
function nibwp_handle_settings_save(): ?bool
{
    if (!isset($_POST['nibwp_settings_save'])) {
        return null;
    }
    if (!current_user_can('manage_options')) {
        return null;
    }
    check_admin_referer('nibwp_settings_page');

    // Security settings.
    $rate_limit = isset($_POST['nibwp_rate_limit_per_minute'])
        ? absint($_POST['nibwp_rate_limit_per_minute'])
        : 60;
    if ($rate_limit < 1) {
        $rate_limit = 1;
    }
    update_option('nibwp_rate_limit_per_minute', $rate_limit);

    $ip_whitelist = isset($_POST['nibwp_ip_whitelist'])
        ? sanitize_text_field($_POST['nibwp_ip_whitelist'])
        : '';
    update_option('nibwp_ip_whitelist', $ip_whitelist);

    // Content Safety settings.
    $force_draft = !empty($_POST['nibwp_force_draft']);
    update_option('nibwp_force_draft', $force_draft);

    $max_title_length = isset($_POST['nibwp_max_title_length'])
        ? absint($_POST['nibwp_max_title_length'])
        : 0;
    update_option('nibwp_max_title_length', $max_title_length);

    // Audit Log settings.
    $audit_enabled = !empty($_POST['nibwp_audit_log_enabled']);
    update_option('nibwp_audit_log_enabled', $audit_enabled);

    $audit_retention = isset($_POST['nibwp_audit_log_retention'])
        ? absint($_POST['nibwp_audit_log_retention'])
        : 30;
    if ($audit_retention < 1) {
        $audit_retention = 1;
    }
    update_option('nibwp_audit_log_retention', $audit_retention);

    // Tool Management settings.
    $disabled_tools = isset($_POST['nibwp_disabled_tools']) && is_array($_POST['nibwp_disabled_tools'])
        ? array_map('sanitize_text_field', $_POST['nibwp_disabled_tools'])
        : [];
    update_option('nibwp_disabled_tools', $disabled_tools);

    // Handle inline audit cleanup.
    if (!empty($_POST['nibwp_settings_cleanup'])) {
        $retention = (int) get_option('nibwp_audit_log_retention', 30);
        if (function_exists('nibwp_audit_log_cleanup')) {
            nibwp_audit_log_cleanup($retention);
        }
    }

    return true;
}

/**
 * Render the settings page.
 */
function nibwp_render_settings_page_view(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $saved = nibwp_handle_settings_save();

    // Load current values.
    $rate_limit = (int) get_option('nibwp_rate_limit_per_minute', 60);
    $ip_whitelist = (string) get_option('nibwp_ip_whitelist', '');
    $force_draft = (bool) get_option('nibwp_force_draft', false);
    $max_title_length = (int) get_option('nibwp_max_title_length', 0);
    $audit_enabled = (bool) get_option('nibwp_audit_log_enabled', true);
    $audit_retention = (int) get_option('nibwp_audit_log_retention', 30);
    $disabled_tools = get_option('nibwp_disabled_tools', []);
    if (!is_array($disabled_tools)) {
        $disabled_tools = [];
    }

    // Collect available tools for the Tool Management section.
    $available_tools = [];
    if (function_exists('nibwp_collect_public_abilities')) {
        $ability_groups = nibwp_collect_public_abilities();
        foreach ($ability_groups as $abilities) {
            foreach ($abilities as $ability) {
                $available_tools[] = $ability['name'];
            }
        }
    }

    ?>
    <?php nibwp_render_admin_header(); ?>
    <div class="wrap nibwp-wrap">
        <div class="nibwp-page-header">
            <div>
                <h1><?php esc_html_e('Settings', domain: 'nibwp'); ?></h1>
                <p class="nibwp-subtitle"><?php esc_html_e('Configure security, content safety, and audit logging for MCP tool calls.', domain: 'nibwp'); ?></p>
            </div>
        </div>

        <?php if ($saved === true): ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', domain: 'nibwp'); ?></p></div>
        <?php endif; ?>

        <form method="post" action="">
            <?php wp_nonce_field('nibwp_settings_page'); ?>


            <!-- Security Section -->
            <h2 class="nibwp-section-title"><?php esc_html_e('Security', domain: 'nibwp'); ?></h2>
            <div class="nibwp-settings-card">
                <div class="nibwp-settings-row">
                    <div class="nibwp-settings-label">
                        <label for="nibwp-rate-limit"><?php esc_html_e('Rate Limit', domain: 'nibwp'); ?></label>
                        <span class="nw-tooltip" data-tip="<?php esc_attr_e('Limits how many MCP tool calls an AI agent can make per minute. Prevents runaway agents from overloading your site. Recommended: 30-60 for development.', domain: 'nibwp'); ?>">?</span>
                    </div>
                    <div class="nibwp-settings-field">
                        <input type="number" id="nibwp-rate-limit" name="nibwp_rate_limit_per_minute"
                               value="<?php echo esc_attr((string) $rate_limit); ?>" min="1" max="1000" />
                        <span><?php esc_html_e('calls per minute', domain: 'nibwp'); ?></span>
                        <p class="description"><?php esc_html_e('Maximum number of MCP tool calls allowed per minute. Protects against runaway AI agents.', domain: 'nibwp'); ?></p>
                    </div>
                </div>
                <div class="nibwp-settings-row">
                    <div class="nibwp-settings-label">
                        <label for="nibwp-ip-whitelist"><?php esc_html_e('IP Whitelist', domain: 'nibwp'); ?></label>
                        <span class="nw-tooltip" data-tip="<?php esc_attr_e('Only these IPs can reach the MCP endpoint. Supports CIDR notation (e.g. 192.168.1.0/24). Leave empty to allow all IPs — useful for development.', domain: 'nibwp'); ?>">?</span>
                    </div>
                    <div class="nibwp-settings-field">
                        <textarea id="nibwp-ip-whitelist" name="nibwp_ip_whitelist"
                                  placeholder="e.g. 192.168.1.0/24, 10.0.0.1"><?php echo esc_textarea($ip_whitelist); ?></textarea>
                        <p class="description"><?php esc_html_e('Comma-separated list of IP addresses or CIDR ranges. Leave empty to allow all IPs. Only listed IPs will be able to use the MCP endpoint.', domain: 'nibwp'); ?></p>
                    </div>
                </div>
            </div>

            <!-- Content Safety Section -->
            <h2 class="nibwp-section-title"><?php esc_html_e('Content Safety', domain: 'nibwp'); ?></h2>
            <div class="nibwp-settings-card">
                <div class="nibwp-settings-row">
                    <div class="nibwp-settings-label">
                        <label for="nibwp-force-draft"><?php esc_html_e('Force Draft', domain: 'nibwp'); ?></label>
                        <span class="nw-tooltip" data-tip="<?php esc_attr_e('Overrides the status to &quot;draft&quot; for any post created via MCP, regardless of what the AI requests. Great safety net for production-adjacent staging sites.', domain: 'nibwp'); ?>">?</span>
                    </div>
                    <div class="nibwp-settings-field">
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                            <input type="checkbox" id="nibwp-force-draft" name="nibwp_force_draft" value="1"
                                   <?php checked($force_draft); ?> />
                            <?php esc_html_e('Force all AI-created posts to draft status', domain: 'nibwp'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('When enabled, any post created by an AI agent through MCP will be set to "draft" regardless of what the agent requests. This gives you a chance to review before publishing.', domain: 'nibwp'); ?></p>
                    </div>
                </div>
                <div class="nibwp-settings-row">
                    <div class="nibwp-settings-label">
                        <label for="nibwp-max-title"><?php esc_html_e('Max Title Length', domain: 'nibwp'); ?></label>
                        <span class="nw-tooltip" data-tip="<?php esc_attr_e('Truncates titles exceeding this length. AI models sometimes generate very long titles. Set to 0 for no limit.', domain: 'nibwp'); ?>">?</span>
                    </div>
                    <div class="nibwp-settings-field">
                        <input type="number" id="nibwp-max-title" name="nibwp_max_title_length"
                               value="<?php echo esc_attr((string) $max_title_length); ?>" min="0" max="1000" />
                        <span><?php esc_html_e('characters', domain: 'nibwp'); ?></span>
                        <p class="description"><?php esc_html_e('Maximum allowed title length for AI-created posts. Set to 0 for no limit. Helps prevent AI agents from generating excessively long titles.', domain: 'nibwp'); ?></p>
                    </div>
                </div>
            </div>

            <!-- Audit Log Section -->
            <h2 class="nibwp-section-title"><?php esc_html_e('Audit Log', domain: 'nibwp'); ?></h2>
            <div class="nibwp-settings-card">
                <div class="nibwp-settings-row">
                    <div class="nibwp-settings-label">
                        <label for="nibwp-audit-enabled"><?php esc_html_e('Enable Logging', domain: 'nibwp'); ?></label>
                        <span class="nw-tooltip" data-tip="<?php esc_attr_e('Records every MCP tool call with arguments, result, timing, user, and IP. Essential for debugging and security auditing.', domain: 'nibwp'); ?>">?</span>
                    </div>
                    <div class="nibwp-settings-field">
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                            <input type="checkbox" id="nibwp-audit-enabled" name="nibwp_audit_log_enabled" value="1"
                                   <?php checked($audit_enabled); ?> />
                            <?php esc_html_e('Record every MCP tool call in the audit log', domain: 'nibwp'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('When enabled, every MCP tool call is recorded with its arguments, result status, execution time, user, and IP address. Useful for debugging and security review.', domain: 'nibwp'); ?></p>
                    </div>
                </div>
                <div class="nibwp-settings-row">
                    <div class="nibwp-settings-label">
                        <label for="nibwp-audit-retention"><?php esc_html_e('Log Retention', domain: 'nibwp'); ?></label>
                        <span class="nw-tooltip" data-tip="<?php esc_attr_e('Entries older than this are automatically deleted by a daily cron job. Balance between storage and audit trail needs.', domain: 'nibwp'); ?>">?</span>
                    </div>
                    <div class="nibwp-settings-field">
                        <input type="number" id="nibwp-audit-retention" name="nibwp_audit_log_retention"
                               value="<?php echo esc_attr((string) $audit_retention); ?>" min="1" max="365" />
                        <span><?php esc_html_e('days', domain: 'nibwp'); ?></span>
                        <p class="description"><?php esc_html_e('Number of days to keep audit log entries before automatic cleanup. Older entries are deleted during scheduled maintenance.', domain: 'nibwp'); ?></p>
                    </div>
                </div>
                <div class="nibwp-settings-row">
                    <div class="nibwp-settings-label"><?php esc_html_e('Manual Cleanup', domain: 'nibwp'); ?></div>
                    <div class="nibwp-settings-field">
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                            <input type="checkbox" name="nibwp_settings_cleanup" value="1" />
                            <?php esc_html_e('Also run cleanup now when saving', domain: 'nibwp'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Check this box and save to immediately delete log entries older than the retention period above.', domain: 'nibwp'); ?></p>
                    </div>
                </div>
            </div>

            <!-- Tool Management Section -->
            <h2 class="nibwp-section-title"><?php esc_html_e('Tool Management', domain: 'nibwp'); ?></h2>
            <div class="nibwp-settings-card">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:4px; flex-wrap:wrap;">
                    <h3 style="margin:0;"><?php esc_html_e('Disabled Tools', domain: 'nibwp'); ?></h3>
                    <?php if ($available_tools !== []): ?>
                        <button type="button"
                                class="button button-secondary"
                                id="nibwp-disabled-tools-toggle"
                                data-label-enable="<?php esc_attr_e('Enable all', domain: 'nibwp'); ?>"
                                data-label-disable="<?php esc_attr_e('Disable all', domain: 'nibwp'); ?>">
                            <?php esc_html_e('Disable all', domain: 'nibwp'); ?>
                        </button>
                    <?php endif; ?>
                </div>
                <p class="description" style="margin:0 0 12px;"><?php esc_html_e('Check tools to disable them. Disabled tools will not be exposed to AI agents via the MCP endpoint. This is useful if you want to restrict which operations AI agents can perform.', domain: 'nibwp'); ?></p>

                <?php if ($available_tools === []): ?>
                    <p style="color:#646970; font-style:italic;"><?php esc_html_e('No tools available. Enable AI Abilities and activate integrations to see available tools here.', domain: 'nibwp'); ?></p>
                <?php else: ?>
                    <div class="nibwp-tool-checklist" id="nibwp-tool-checklist">
                        <?php foreach ($available_tools as $tool_name): ?>
                            <label>
                                <input type="checkbox" name="nibwp_disabled_tools[]"
                                       value="<?php echo esc_attr($tool_name); ?>"
                                       <?php checked(in_array($tool_name, $disabled_tools, true)); ?> />
                                <code><?php echo esc_html($tool_name); ?></code>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <script>
                    (function () {
                        var btn = document.getElementById('nibwp-disabled-tools-toggle');
                        var list = document.getElementById('nibwp-tool-checklist');
                        if (!btn || !list) return;
                        var boxes = list.querySelectorAll('input[type="checkbox"]');
                        var labels = { enable: btn.dataset.labelEnable, disable: btn.dataset.labelDisable };

                        function refresh() {
                            var allChecked = true;
                            for (var i = 0; i < boxes.length; i++) {
                                if (!boxes[i].checked) { allChecked = false; break; }
                            }
                            btn.textContent = allChecked ? labels.enable : labels.disable;
                        }

                        btn.addEventListener('click', function () {
                            var target = btn.textContent.trim() === labels.enable ? false : true;
                            for (var i = 0; i < boxes.length; i++) { boxes[i].checked = target; }
                            refresh();
                        });
                        for (var i = 0; i < boxes.length; i++) {
                            boxes[i].addEventListener('change', refresh);
                        }
                        refresh();
                    })();
                    </script>
                <?php endif; ?>
            </div>

            <?php submit_button(
                text: __('Save Settings', domain: 'nibwp'),
                type: 'primary',
                name: 'nibwp_settings_save',
            ); ?>
        </form>

        <?php nibwp_render_danger_zone(); ?>
    </div>
    <?php
    nibwp_render_admin_footer();
}
