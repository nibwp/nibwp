<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Memory admin page — view and manage AI session memories.
 */

/**
 * Get all memory entries (standalone helper for admin page).
 */
function nibwp_admin_memory_get_all(): array
{
    if (function_exists('nibwp_memory_get_all')) {
        return nibwp_memory_get_all();
    }
    $data = get_option('nibwp_memory_store', []);
    return is_array($data) ? $data : [];
}

/**
 * Save all memory entries (standalone helper for admin page).
 */
function nibwp_admin_memory_save_all(array $memories): void
{
    if (function_exists('nibwp_memory_save_all')) {
        nibwp_memory_save_all($memories);
        return;
    }
    update_option('nibwp_memory_store', $memories, autoload: false);
}

/**
 * Handle memory deletion from admin.
 */
function nibwp_handle_memory_actions(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $action = $_GET['nibwp_memory_action'] ?? null;
    if (!is_string($action)) {
        return;
    }

    if ($action === 'delete' && isset($_GET['memory_key'])) {
        $key = sanitize_text_field($_GET['memory_key']);
        if (!check_admin_referer('nibwp_delete_memory_' . $key)) {
            return;
        }
        $memories = nibwp_admin_memory_get_all();
        $memories = array_values(array_filter($memories, static fn($m) => ($m['key'] ?? '') !== $key));
        nibwp_admin_memory_save_all($memories);
        wp_safe_redirect(admin_url('admin.php?page=nibwp-memory&nibwp_result=deleted'));
        exit();
    }

    if ($action === 'clear_all') {
        if (!check_admin_referer('nibwp_clear_all_memory')) {
            return;
        }
        nibwp_admin_memory_save_all([]);
        wp_safe_redirect(admin_url('admin.php?page=nibwp-memory&nibwp_result=cleared'));
        exit();
    }
}

/**
 * Render the Memory admin page.
 */
function nibwp_render_memory_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $memories = nibwp_admin_memory_get_all();

    $result_message = match ($_GET['nibwp_result'] ?? null) {
        'deleted' => __('Memory entry deleted.', 'nibwp'),
        'cleared' => __('All memories cleared.', 'nibwp'),
        default => null,
    };

    $search = sanitize_text_field($_GET['s'] ?? '');
    if ($search !== '') {
        $search_lower = strtolower($search);
        $memories = array_filter($memories, static function ($m) use ($search_lower) {
            return str_contains(strtolower($m['key'] ?? ''), $search_lower)
                || str_contains(strtolower($m['value'] ?? ''), $search_lower)
                || array_filter($m['tags'] ?? [], static fn($t) => str_contains(strtolower($t), $search_lower)) !== [];
        });
    }

    $total = count(nibwp_admin_memory_get_all());
    $dt_format = nibwp_get_datetime_format('Y-m-d H:i');

    ?>
    <?php nibwp_render_admin_header(); ?>
    <div class="wrap nibwp-wrap">
        <div class="nibwp-page-header">
            <div>
                <h1><?php esc_html_e('AI Memory', 'nibwp'); ?></h1>
                <p class="nibwp-subtitle"><?php printf(
                    /* translators: %d: number of stored memory entries */
                    esc_html(_n(
                        '%d memory stored. AI agents use these to retain context across sessions.',
                        '%d memories stored. AI agents use these to retain context across sessions.',
                        $total,
                        'nibwp'
                    )),
                    $total,
                ); ?></p>
            </div>
            <?php if ($total > 0): ?>
                <div class="nibwp-page-actions">
                    <?php $clear_url = wp_nonce_url(
                        admin_url('admin.php?page=nibwp-memory&nibwp_memory_action=clear_all'),
                        'nibwp_clear_all_memory',
                    ); ?>
                    <a href="#"
                       class="button nibwp-btn-danger nw-confirm-delete"
                       data-name="<?php esc_attr_e('all memories', 'nibwp'); ?>"
                       data-url="<?php echo esc_url($clear_url); ?>"
                    ><?php esc_html_e('Clear All', 'nibwp'); ?></a>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($result_message !== null): ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html($result_message); ?></p></div>
        <?php endif; ?>

        <?php if ($total > 5): ?>
            <form method="get" class="nibwp-search-form">
                <input type="hidden" name="page" value="nibwp-memory" />
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>"
                       placeholder="<?php esc_attr_e('Search memories...', 'nibwp'); ?>"
                       class="nibwp-search-input" />
                <button type="submit" class="button"><?php esc_html_e('Search', 'nibwp'); ?></button>
                <?php if ($search !== ''): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=nibwp-memory')); ?>" class="button"><?php esc_html_e('Clear', 'nibwp'); ?></a>
                <?php endif; ?>
            </form>
        <?php endif; ?>

        <?php if ($memories === []): ?>
            <div class="nibwp-empty-state">
                <div class="nibwp-empty-icon">
                    <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2" width="48" height="48">
                        <circle cx="24" cy="24" r="20"/>
                        <path d="M24 14v12M18 20h12"/>
                    </svg>
                </div>
                <h3><?php echo $search !== ''
                    ? esc_html__('No memories match your search.', 'nibwp')
                    : esc_html__('No memories yet.', 'nibwp'); ?></h3>
                <p><?php esc_html_e('AI agents will store project context here automatically during sessions.', 'nibwp'); ?></p>
            </div>
        <?php else: ?>
            <div class="nibwp-memory-grid">
                <?php foreach ($memories as $memory): ?>
                    <?php
                    $key = esc_html($memory['key'] ?? '');
                    $value = esc_html($memory['value'] ?? '');
                    $tags = $memory['tags'] ?? [];
                    $updated = ($memory['updated_at'] ?? null) !== null
                        ? wp_date($dt_format, strtotime($memory['updated_at']))
                        : __('Unknown', 'nibwp');
                    $delete_url = wp_nonce_url(
                        admin_url('admin.php?page=nibwp-memory&nibwp_memory_action=delete&memory_key=' . urlencode($memory['key'] ?? '')),
                        'nibwp_delete_memory_' . ($memory['key'] ?? ''),
                    );
                    ?>
                    <div class="nibwp-memory-card">
                        <div class="nibwp-memory-header">
                            <code class="nibwp-memory-key"><?php echo $key; ?></code>
                            <a href="<?php echo esc_url($delete_url); ?>"
                               class="nibwp-memory-delete"
                               title="<?php esc_attr_e('Delete', 'nibwp'); ?>"
                               class="nw-confirm-delete" data-name="<?php echo esc_attr($memory['key'] ?? ''); ?>" data-url="<?php echo esc_url($delete_url); ?>"
                            >&times;</a>
                        </div>
                        <div class="nibwp-memory-value"><?php echo nl2br($value); ?></div>
                        <div class="nibwp-memory-meta">
                            <?php if ($tags !== []): ?>
                                <div class="nibwp-memory-tags">
                                    <?php foreach ($tags as $tag): ?>
                                        <span class="nibwp-tag"><?php echo esc_html($tag); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <span class="nibwp-memory-date"><?php echo esc_html($updated); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    nibwp_render_admin_footer();
}

