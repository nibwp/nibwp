<?php

declare(strict_types=1);

/**
 * Collects every public MCP tool ability registered on the site, grouped by source.
 *
 * The source label is resolved per-ability via the `nibwp_ability_source_label`
 * filter (default: "NIBWP"), so add-ons can contribute rows under their own
 * heading. Within a group, rows are sorted by category then name. Groups are
 * returned with the default source first, other sources sorted alphabetically.
 *
 * @return array<string, list<array{name: string, category: string, description: string}>>
 */
function nibwp_collect_public_abilities(): array
{
    $default_source = __('NIBWP', 'nibwp');
    $groups = [];
    foreach (wp_get_abilities() as $ability) {
        $name = $ability->get_name();
        if (!str_starts_with($name, 'nibwp/')) {
            continue;
        }
        $meta = $ability->get_meta();
        if (!($meta['mcp']['public'] ?? false)) {
            continue;
        }
        if (($meta['mcp']['type'] ?? 'tool') !== 'tool') {
            continue;
        }
        $category_slug = $ability->get_category();
        $category = ($category_slug !== '' && nibwp_has_ability_category($category_slug)) ? wp_get_ability_category($category_slug) : null;
        /** @var string $source */
        $source = apply_filters('nibwp_ability_source_label', $default_source, $ability);
        $groups[$source] ??= [];
        $groups[$source][] = [
            'name' => $name,
            'category' => $category !== null ? $category->get_label() : $category_slug,
            'description' => $ability->get_description(),
        ];
    }
    foreach ($groups as $source => $rows) {
        usort(
            $rows,
            static fn(array $a, array $b): int => [$a['category'], $a['name']] <=> [$b['category'], $b['name']],
        );
        $groups[$source] = $rows;
    }

    $sorted = [];
    if (array_key_exists($default_source, $groups)) {
        $sorted[$default_source] = $groups[$default_source];
        unset($groups[$default_source]);
    }
    ksort($groups);
    return $sorted + $groups;
}

function nibwp_handle_sandbox_actions()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $action = $_GET['action'] ?? null;
    $file_param = $_GET['file'] ?? null;

    if (!is_string($action) || !is_string($file_param)) {
        return;
    }

    $file = basename($file_param);
    if (!check_admin_referer('nibwp_manage_file_' . $file)) {
        return;
    }

    $path = nibwp_get_sandbox_dir(true) . $file;
    if (!file_exists($path)) {
        return;
    }

    $result = match ($action) {
        'delete' => unlink($path),
        'disable' => str_ends_with($file, '.php') && rename($path, $path . '.disabled'),
        'enable' => str_ends_with($file, '.disabled') && rename($path, substr($path, offset: 0, length: -9)),
        'exit_safe_mode' => $file === '.crashed' && unlink($path),
        default => false,
    };

    if ($result) {
        wp_safe_redirect(admin_url('admin.php?page=nibwp-sandbox&nibwp_result=' . $action));
        exit();
    }
}

function nibwp_render_sandbox_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $result_message = match ($_GET['nibwp_result'] ?? null) {
        'delete' => __('File deleted.', 'nibwp'),
        'disable' => __('File disabled.', 'nibwp'),
        'enable' => __('File enabled.', 'nibwp'),
        'exit_safe_mode' => __('Safe mode deactivated. Sandbox files will load on the next request.', 'nibwp'),
        default => null,
    };

    $sandbox_dir = nibwp_get_sandbox_dir(true);
    $is_crashed = file_exists($sandbox_dir . '.crashed');
    $scanned_files = is_dir($sandbox_dir) ? scandir($sandbox_dir) : false;
    $files = $scanned_files !== false ? array_diff($scanned_files, ['.', '..', '.loading', '.crashed']) : [];
    $file_data = [];
    $total_size = 0;
    $enabled_count = 0;
    $disabled_count = 0;

    foreach ($files as $file) {
        if (is_dir($sandbox_dir . $file)) {
            continue;
        }
        $path = $sandbox_dir . $file;
        $is_disabled = str_ends_with($file, '.disabled');
        $size = filesize($path);
        $total_size += $size;
        if ($is_disabled) { $disabled_count++; } else { $enabled_count++; }

        $file_data[] = [
            'name' => $file,
            'path' => $path,
            'is_disabled' => $is_disabled,
            'size' => $size,
            'mtime' => filemtime($path),
            'lines' => substr_count((string) file_get_contents($path, length: 50000), "\n") + 1,
        ];
    }

    $format = nibwp_get_datetime_format();
    $base_url = admin_url('admin.php?page=nibwp-sandbox');

    ?>
    <?php nibwp_render_admin_header(); ?>
    <div class="wrap nibwp-wrap">
        <div class="nibwp-page-header">
            <div>
                <h1><?php esc_html_e('Sandbox Files', 'nibwp'); ?></h1>
                <p class="nibwp-subtitle"><?php printf(
                    /* translators: %s: sandbox directory path */
                    esc_html__('AI-generated PHP files in %s. Loaded automatically on every request.', 'nibwp'),
                    '<code>wp-content/nibwp-sandbox/</code>',
                ); ?></p>
            </div>
        </div>

        <?php if ($result_message !== null): ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html($result_message); ?></p></div>
        <?php endif; ?>

        <?php if ($is_crashed): ?>
            <?php $crash = function_exists('nibwp_sandbox_crash_report') ? nibwp_sandbox_crash_report() : null; ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e('Safe mode is active.', 'nibwp'); ?></strong>
                    <?php if ($crash && $crash['is_external']): ?>
                        <?php esc_html_e('A fatal error during startup suspended every sandbox file. The error came from outside the sandbox, so another plugin is the likely cause.', 'nibwp'); ?>
                    <?php else: ?>
                        <?php esc_html_e('A fatal error during startup suspended every sandbox file.', 'nibwp'); ?>
                    <?php endif; ?>
                </p>
                <?php if ($crash && $crash['message'] !== ''): ?>
                    <p>
                        <code><?php echo esc_html(trim($crash['message'] . ($crash['file'] !== '' ? ' ' . $crash['file'] . ':' . $crash['line'] : ''))); ?></code>
                    </p>
                <?php endif; ?>
                <?php if ($crash && $crash['sandbox_file'] !== ''): ?>
                    <p>
                        <small><?php printf(
                            /* translators: %s: sandbox file name */
                            esc_html__('Loading at the time: %s', 'nibwp'),
                            '<code>' . esc_html($crash['sandbox_file']) . '</code>',
                        ); ?></small>
                    </p>
                <?php endif; ?>
                <p>
                    <?php $exit_url = wp_nonce_url($base_url . '&action=exit_safe_mode&file=.crashed', 'nibwp_manage_file_.crashed'); ?>
                    <a href="<?php echo esc_url($exit_url); ?>" class="button button-primary"><?php esc_html_e('Exit Safe Mode', 'nibwp'); ?></a>
                </p>
            </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="nibwp-dashboard-stats" style="margin-bottom:20px;">
            <div class="nibwp-stat-card">
                <div class="label"><?php esc_html_e('Total Files', 'nibwp'); ?></div>
                <div class="value"><?php echo count($file_data); ?></div>
            </div>
            <div class="nibwp-stat-card">
                <div class="label"><?php esc_html_e('Enabled', 'nibwp'); ?></div>
                <div class="value" style="color:var(--nw-ok);"><?php echo $enabled_count; ?></div>
            </div>
            <div class="nibwp-stat-card">
                <div class="label"><?php esc_html_e('Disabled', 'nibwp'); ?></div>
                <div class="value"><?php echo $disabled_count; ?></div>
            </div>
            <div class="nibwp-stat-card">
                <div class="label"><?php esc_html_e('Total Size', 'nibwp'); ?></div>
                <div class="value"><?php echo esc_html(size_format($total_size)); ?></div>
            </div>
        </div>

        <?php if ($file_data === []): ?>
            <div class="nibwp-empty-state">
                <div class="nibwp-empty-icon">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/></svg>
                </div>
                <h3><?php esc_html_e('No sandbox files yet', 'nibwp'); ?></h3>
                <p><?php esc_html_e('AI agents will create PHP files here when they need persistent server-side code.', 'nibwp'); ?></p>
            </div>
        <?php else: ?>
            <div class="nw-sandbox-list">
                <?php foreach ($file_data as $fd):
                    $file = $fd['name'];
                    $wp_date = $fd['mtime'] !== false ? wp_date($format, $fd['mtime']) : __('Unknown', 'nibwp');
                    $toggle_action = $fd['is_disabled'] ? 'enable' : 'disable';
                    $toggle_url = wp_nonce_url($base_url . '&action=' . $toggle_action . '&file=' . urlencode($file), 'nibwp_manage_file_' . $file);
                    $delete_url = wp_nonce_url($base_url . '&action=delete&file=' . urlencode($file), 'nibwp_manage_file_' . $file);
                ?>
                    <div class="nw-sandbox-row <?php echo $fd['is_disabled'] ? 'is-disabled' : ($is_crashed ? 'is-suspended' : 'is-enabled'); ?>">
                        <!-- File icon -->
                        <div class="nw-sandbox-row__icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
                                <polyline points="14 2 14 8 20 8"/>
                                <path d="M10 13l-2 2 2 2M14 13l2 2-2 2"/>
                            </svg>
                        </div>

                        <!-- File info -->
                        <div class="nw-sandbox-row__main">
                            <div class="nw-sandbox-row__name">
                                <code><?php echo esc_html($file); ?></code>
                                <?php if ($fd['is_disabled']): ?>
                                    <span class="nibwp-badge is-muted"><?php esc_html_e('Disabled', 'nibwp'); ?></span>
                                <?php elseif ($is_crashed): ?>
                                    <span class="nibwp-badge is-danger"><?php esc_html_e('Suspended', 'nibwp'); ?></span>
                                <?php else: ?>
                                    <span class="nibwp-badge is-ok"><?php esc_html_e('Active', 'nibwp'); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="nw-sandbox-row__meta">
                                <span title="<?php esc_attr_e('File size', 'nibwp'); ?>">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                    <?php echo esc_html(size_format($fd['size'])); ?>
                                </span>
                                <span title="<?php esc_attr_e('Lines of code', 'nibwp'); ?>">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                                    <?php printf(
                                        /* translators: %d: number of lines in the file */
                                        esc_html(_n('%d line', '%d lines', (int) $fd['lines'], 'nibwp')),
                                        (int) $fd['lines']
                                    ); ?>
                                </span>
                                <span title="<?php esc_attr_e('Last modified', 'nibwp'); ?>">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    <?php echo esc_html($wp_date); ?>
                                </span>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="nw-sandbox-row__actions">
                            <a href="<?php echo esc_url($toggle_url); ?>" class="nw-action-btn <?php echo $fd['is_disabled'] ? 'is-success' : ''; ?>" title="<?php echo $fd['is_disabled'] ? esc_attr__('Enable file', 'nibwp') : esc_attr__('Disable file', 'nibwp'); ?>">
                                <?php if ($fd['is_disabled']): ?>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                    <span><?php esc_html_e('Enable', 'nibwp'); ?></span>
                                <?php else: ?>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
                                    <span><?php esc_html_e('Disable', 'nibwp'); ?></span>
                                <?php endif; ?>
                            </a>
                            <button type="button" class="nw-action-btn nw-sandbox-view" data-file="<?php echo esc_attr($file); ?>" title="<?php esc_attr_e('View file contents', 'nibwp'); ?>">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                <span><?php esc_html_e('View', 'nibwp'); ?></span>
                            </button>
                            <a href="#" class="nw-action-btn is-danger nw-confirm-delete" data-url="<?php echo esc_url($delete_url); ?>" data-name="<?php echo esc_attr($file); ?>" title="<?php esc_attr_e('Delete file permanently', 'nibwp'); ?>">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                                <span><?php esc_html_e('Delete', 'nibwp'); ?></span>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>


            <!-- Code viewer modal -->
            <div class="nw-palette" id="nw-sandbox-viewer" style="display:none;">
                <div class="nw-palette__backdrop" onclick="document.getElementById('nw-sandbox-viewer').style.display='none'"></div>
                <div class="nw-palette__panel" style="max-width:800px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; padding:14px 18px; border-bottom:1px solid var(--nw-border);">
                        <strong id="nw-sandbox-viewer-title" style="font-size:14px;"></strong>
                        <button type="button" class="button button-small" onclick="document.getElementById('nw-sandbox-viewer').style.display='none'">&times;</button>
                    </div>
                    <div style="max-height:60vh; overflow:auto;">
                        <pre id="nw-sandbox-viewer-code" style="background:var(--nw-surface-2); color:var(--nw-text); padding:16px; margin:0; font-family:var(--nw-mono); font-size:12px; line-height:1.6; white-space:pre-wrap; word-break:break-all;"></pre>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
    (function(){
        /* File code viewer */
        var fileContents = <?php
            $contents = [];
            foreach ($file_data as $fd) {
                $raw = file_get_contents($fd['path']);
                $contents[$fd['name']] = $raw !== false ? $raw : __('(Could not read file)', 'nibwp');
            }
            echo wp_json_encode($contents);
        ?>;

        document.querySelectorAll('.nw-sandbox-view').forEach(function(btn){
            btn.addEventListener('click', function(){
                var file = btn.getAttribute('data-file');
                var viewer = document.getElementById('nw-sandbox-viewer');
                document.getElementById('nw-sandbox-viewer-title').textContent = file;
                document.getElementById('nw-sandbox-viewer-code').textContent = fileContents[file] || '';
                viewer.style.display = 'flex';
            });
        });
    })();
    </script>
    <?php
    nibwp_render_admin_footer();
}

function nibwp_render_settings_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $ability_groups = nibwp_collect_public_abilities();
    $total_abilities = 0;
    $all_categories = [];
    $all_sources = [];
    $all_abilities_flat = [];
    $disabled_tools = (array) get_option('nibwp_disabled_tools', []);

    foreach ($ability_groups as $source => $abilities) {
        $total_abilities += count($abilities);
        $all_sources[$source] = count($abilities);
        foreach ($abilities as $ability) {
            $cat = $ability['category'] ?: _x('Other', 'ability category', 'nibwp');
            $all_categories[$cat] = ($all_categories[$cat] ?? 0) + 1;

            // Get annotations from the registered ability for badges.
            $registered = nibwp_has_ability((string) $ability['name']) ? wp_get_ability((string) $ability['name']) : null;
            $meta = $registered !== null ? $registered->get_meta() : [];
            $annotations = $meta['mcp']['annotations'] ?? ($meta['annotations'] ?? []);

            $all_abilities_flat[] = array_merge($ability, [
                'source' => $source,
                'readonly' => !empty($annotations['readonly']),
                'destructive' => !empty($annotations['destructive']),
                'disabled' => in_array($ability['name'], $disabled_tools, true),
            ]);
        }
    }
    ksort($all_categories);
    $group_count = count($ability_groups);
    $readonly_count = count(array_filter($all_abilities_flat, static fn($a) => $a['readonly']));
    $destructive_count = count(array_filter($all_abilities_flat, static fn($a) => $a['destructive']));
    $disabled_count = count(array_filter($all_abilities_flat, static fn($a) => $a['disabled']));
    ?>
    <?php nibwp_render_admin_header(); ?>
    <div class="wrap nibwp-wrap">
        <div class="nibwp-page-header">
            <div>
                <h1><?php esc_html_e('AI Abilities', 'nibwp'); ?></h1>
                <p class="nibwp-subtitle"><?php printf(
                    /* translators: 1: number of tools, 2: number of categories */
                    esc_html__('%1$d tools across %2$d categories exposed to AI agents via MCP.', 'nibwp'),
                    $total_abilities,
                    count($all_categories),
                ); ?></p>
            </div>
            <div class="nibwp-page-actions">
                <button type="button" class="button button-primary" id="nw-abilities-export" title="<?php esc_attr_e('Export tools list as JSON', 'nibwp'); ?>">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px; margin-inline-end:4px;"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    <?php esc_html_e('Export JSON', 'nibwp'); ?>
                </button>
            </div>
        </div>

        <!-- Stats row -->
        <div class="nibwp-dashboard-stats" style="margin-bottom:20px;">
            <div class="nibwp-stat-card">
                <div class="label"><?php esc_html_e('Total Tools', 'nibwp'); ?></div>
                <div class="value"><?php echo esc_html((string) $total_abilities); ?></div>
            </div>
            <div class="nibwp-stat-card">
                <div class="label"><?php esc_html_e('Read-Only', 'nibwp'); ?></div>
                <div class="value" style="color:var(--nw-ok);"><?php echo esc_html((string) $readonly_count); ?></div>
                <div class="sub"><?php esc_html_e('Safe to run anytime', 'nibwp'); ?></div>
            </div>
            <div class="nibwp-stat-card">
                <div class="label"><?php esc_html_e('Destructive', 'nibwp'); ?></div>
                <div class="value" style="color:var(--nw-danger);"><?php echo esc_html((string) $destructive_count); ?></div>
                <div class="sub"><?php esc_html_e('Require confirmation', 'nibwp'); ?></div>
            </div>
            <div class="nibwp-stat-card">
                <div class="label"><?php esc_html_e('Disabled', 'nibwp'); ?></div>
                <div class="value"><?php echo esc_html((string) $disabled_count); ?></div>
                <div class="sub"><a href="<?php echo esc_url(admin_url('admin.php?page=nibwp-settings')); ?>"><?php esc_html_e('Manage in Settings', 'nibwp'); ?></a></div>
            </div>
        </div>

        <!-- Filter bar -->
        <div style="display:flex; gap:10px; align-items:center; margin-bottom:16px; flex-wrap:wrap;">
            <input type="search" id="nw-abilities-search" placeholder="<?php esc_attr_e('Search tools...', 'nibwp'); ?>" style="flex:1; min-width:200px; max-width:360px;" class="nibwp-search-input" />
            <select id="nw-abilities-category">
                <option value=""><?php esc_html_e('All Categories', 'nibwp'); ?></option>
                <?php foreach ($all_categories as $cat => $count): ?>
                    <option value="<?php echo esc_attr(strtolower($cat)); ?>"><?php echo esc_html($cat); ?> (<?php echo $count; ?>)</option>
                <?php endforeach; ?>
            </select>
            <select id="nw-abilities-source">
                <option value=""><?php esc_html_e('All Sources', 'nibwp'); ?></option>
                <?php foreach ($all_sources as $src => $count): ?>
                    <option value="<?php echo esc_attr(strtolower($src)); ?>"><?php echo esc_html($src); ?> (<?php echo $count; ?>)</option>
                <?php endforeach; ?>
            </select>
            <select id="nw-abilities-type">
                <option value=""><?php esc_html_e('All Types', 'nibwp'); ?></option>
                <option value="readonly"><?php esc_html_e('Read-Only', 'nibwp'); ?></option>
                <option value="write"><?php esc_html_e('Write', 'nibwp'); ?></option>
                <option value="destructive"><?php esc_html_e('Destructive', 'nibwp'); ?></option>
            </select>
            <select id="nw-abilities-status">
                <option value=""><?php esc_html_e('All Statuses', 'nibwp'); ?></option>
                <option value="enabled"><?php esc_html_e('Enabled', 'nibwp'); ?></option>
                <option value="disabled"><?php esc_html_e('Disabled', 'nibwp'); ?></option>
            </select>
            <span id="nw-abilities-count" style="font-size:12px; color:var(--nw-text-muted); margin-inline-start:auto;">
                <?php printf(
                    /* translators: 1: number of tools shown, 2: total number of tools */
                    esc_html__('Showing %1$d of %2$d', 'nibwp'),
                    $total_abilities,
                    $total_abilities
                ); ?>
            </span>
        </div>


        <!-- Table -->
        <table class="nibwp-abilities-table" id="nw-abilities-table">
            <thead>
                <tr>
                    <th style="width:300px;"><?php esc_html_e('Tool', 'nibwp'); ?></th>
                    <th style="width:160px;"><?php esc_html_e('Category', 'nibwp'); ?></th>
                    <th style="width:90px;"><?php esc_html_e('Type', 'nibwp'); ?></th>
                    <th style="width:110px;"><?php esc_html_e('Status', 'nibwp'); ?></th>
                    <th><?php esc_html_e('Description', 'nibwp'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($all_abilities_flat as $ability): ?>
                    <tr data-name="<?php echo esc_attr(strtolower($ability['name'])); ?>"
                        data-cat="<?php echo esc_attr(strtolower($ability['category'])); ?>"
                        data-src="<?php echo esc_attr(strtolower($ability['source'])); ?>"
                        data-desc="<?php echo esc_attr(strtolower($ability['description'])); ?>"
                        data-readonly="<?php echo $ability['readonly'] ? '1' : '0'; ?>"
                        data-destructive="<?php echo $ability['destructive'] ? '1' : '0'; ?>"
                        data-status="<?php echo $ability['disabled'] ? 'disabled' : 'enabled'; ?>"
                        <?php echo $ability['disabled'] ? 'class="is-disabled"' : ''; ?>>
                        <td><code><?php echo esc_html($ability['name']); ?></code></td>
                        <td><span class="nibwp-badge is-brand" style="font-size:11.5px; padding:4px 10px;"><?php echo esc_html($ability['category']); ?></span></td>
                        <td>
                            <?php if ($ability['readonly']): ?>
                                <span class="nibwp-badge is-ok" style="font-size:10px;"><?php esc_html_e('Read', 'nibwp'); ?></span>
                            <?php elseif ($ability['destructive']): ?>
                                <span class="nibwp-badge is-danger" style="font-size:10px;"><?php esc_html_e('Destructive', 'nibwp'); ?></span>
                            <?php else: ?>
                                <span class="nibwp-badge is-warn" style="font-size:10px;"><?php esc_html_e('Write', 'nibwp'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($ability['disabled']): ?>
                                <span class="nibwp-badge is-muted" style="font-size:11px; padding:3px 10px;">
                                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-1px; margin-inline-end:3px;"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
                                    <?php esc_html_e('Disabled', 'nibwp'); ?>
                                </span>
                            <?php else: ?>
                                <span class="nibwp-badge is-ok" style="font-size:11px; padding:3px 10px;">
                                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-1px; margin-inline-end:3px;"><polyline points="20 6 9 17 4 12"/></svg>
                                    <?php esc_html_e('Enabled', 'nibwp'); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12.5px; line-height:1.5; color:var(--nw-text-2);"><?php echo esc_html($ability['description']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <script>
    (function(){
        var search = document.getElementById('nw-abilities-search');
        var catSelect = document.getElementById('nw-abilities-category');
        var srcSelect = document.getElementById('nw-abilities-source');
        var typeSelect = document.getElementById('nw-abilities-type');
        var statusSelect = document.getElementById('nw-abilities-status');
        var rows = document.querySelectorAll('#nw-abilities-table tbody tr');
        var countEl = document.getElementById('nw-abilities-count');
        var total = rows.length;
        var nibwpAbilitiesI18n = <?php echo wp_json_encode([
            /* translators: 1: number of tools shown, 2: total number of tools */
            'showing' => __('Showing %1$d of %2$d', 'nibwp'),
        ]); ?>;

        function filter(){
            var q = (search.value||'').trim().toLowerCase();
            var cat = catSelect.value;
            var src = srcSelect.value;
            var type = typeSelect.value;
            var status = statusSelect ? statusSelect.value : '';
            var visible = 0;
            var words = q ? q.split(/\s+/) : [];

            rows.forEach(function(row){
                var name = row.getAttribute('data-name')||'';
                var rcat = row.getAttribute('data-cat')||'';
                var rsrc = row.getAttribute('data-src')||'';
                var desc = row.getAttribute('data-desc')||'';
                var ro = row.getAttribute('data-readonly') === '1';
                var dest = row.getAttribute('data-destructive') === '1';
                var rstatus = row.getAttribute('data-status')||'enabled';
                var hay = name + ' ' + rcat + ' ' + rsrc + ' ' + desc;

                var matchQ = !q || words.every(function(w){ return hay.indexOf(w) !== -1; });
                var matchCat = !cat || rcat === cat;
                var matchSrc = !src || rsrc === src;
                var matchType = !type
                    || (type === 'readonly' && ro)
                    || (type === 'write' && !ro && !dest)
                    || (type === 'destructive' && dest);
                var matchStatus = !status || rstatus === status;
                var show = matchQ && matchCat && matchSrc && matchType && matchStatus;
                row.style.display = show ? '' : 'none';
                if(show) visible++;
            });

            countEl.textContent = nibwpAbilitiesI18n.showing.replace('%1$d', visible).replace('%2$d', total);
        }

        search.addEventListener('input', filter);
        catSelect.addEventListener('change', filter);
        srcSelect.addEventListener('change', filter);
        typeSelect.addEventListener('change', filter);
        if(statusSelect) statusSelect.addEventListener('change', filter);

        /* Export JSON */
        document.getElementById('nw-abilities-export').addEventListener('click', function(){
            var data = [];
            rows.forEach(function(row){
                data.push({
                    name: row.getAttribute('data-name'),
                    category: row.getAttribute('data-cat'),
                    source: row.getAttribute('data-src'),
                    readonly: row.getAttribute('data-readonly') === '1',
                    destructive: row.getAttribute('data-destructive') === '1',
                    status: row.getAttribute('data-status') || 'enabled',
                    description: row.getAttribute('data-desc')
                });
            });
            var blob = new Blob([JSON.stringify(data, null, 2)], {type:'application/json'});
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'nibwp-abilities.json';
            a.click();
        });
    })();
    </script>
    <?php
    nibwp_render_admin_footer();
}
