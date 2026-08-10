<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * NIBWP Workflows — admin panel (Pro).
 *
 * Card grid of saved workflows (operating playbooks) + a markdown editor. Mirrors
 * the Skills / Integrations panels. CRUD: create, edit, duplicate, delete, and an
 * AJAX Active toggle (REST nibwp/v1/workflows/activate). Non-Pro sees an upsell.
 */

/** Process POST actions early (before any output) so redirects work. */
add_action('admin_init', static function (): void {
    if (!current_user_can('manage_options')) {
        return;
    }
    // Delete via styled-confirm link (.nw-confirm-delete → global #nw-confirm modal).
    if (($_GET['nibwp_wf_action'] ?? '') === 'delete' && isset($_GET['workflow_id'])) {
        $id = (int) $_GET['workflow_id'];
        check_admin_referer('nibwp_wf_delete_' . $id);
        if (nibwp_workflows_unlocked()) {
            $post = $id ? nibwp_workflow_find($id) : null;
            if ($post) {
                wp_trash_post($post->ID);
            }
        }
        wp_safe_redirect(admin_url('admin.php?page=nibwp-workflows&wf_msg=deleted'));
        exit;
    }
    if (($_GET['page'] ?? '') !== 'nibwp-workflows' && ($_POST['nibwp_wf_action'] ?? '') === '') {
        return;
    }
    $action = sanitize_key((string) ($_POST['nibwp_wf_action'] ?? ''));
    if ($action === '') {
        return;
    }
    check_admin_referer('nibwp_workflow');
    if (!nibwp_workflows_unlocked()) {
        wp_safe_redirect(admin_url('admin.php?page=nibwp-workflows&wf_msg=locked'));
        exit;
    }

    if ($action === 'save') {
        $save = [
            'id'      => (int) ($_POST['workflow_id'] ?? 0),
            'title'   => wp_unslash((string) ($_POST['wf_title'] ?? '')),
            'summary' => wp_unslash((string) ($_POST['wf_summary'] ?? '')),
            'when'    => wp_unslash((string) ($_POST['wf_when'] ?? '')),
            'tools'   => array_filter(array_map('trim', explode(',', (string) ($_POST['wf_tools'] ?? '')))),
            'body'    => wp_unslash((string) ($_POST['wf_body'] ?? '')),
        ];
        // Only the owner may set sharing/attribution. Editing a built-in or
        // imported (non-owned) workflow leaves its visibility + creator untouched.
        $existing_owned = true;
        if ((int) ($_POST['workflow_id'] ?? 0) > 0) {
            $ep = nibwp_workflow_find((int) $_POST['workflow_id']);
            $existing_owned = $ep ? nibwp_workflow_is_owned(nibwp_workflow_to_array($ep, false)) : true;
        }
        if ($existing_owned) {
            $save['visibility'] = (isset($_POST['wf_visibility']) && is_array($_POST['wf_visibility']))
                ? array_map('sanitize_key', array_map('strval', (array) wp_unslash($_POST['wf_visibility'])))
                : ['private'];
            $wf_creator = trim(wp_unslash((string) ($_POST['wf_creator'] ?? '')));
            if ($wf_creator !== '') {
                $save['creator'] = $wf_creator;
            }
        }
        if (isset($_POST['wf_category'])) {
            if ($_POST['wf_category'] === '__new__' && !empty($_POST['wf_category_new'])) {
                $save['category_new'] = wp_unslash((string) $_POST['wf_category_new']);
            } else {
                $save['category'] = sanitize_key((string) $_POST['wf_category']);
            }
        }
        $id = nibwp_workflow_save($save);
        $msg = is_wp_error($id) ? 'error' : 'saved';
        wp_safe_redirect(admin_url('admin.php?page=nibwp-workflows&wf_msg=' . $msg));
        exit;
    }
    if ($action === 'delete') {
        $id = (int) ($_POST['workflow_id'] ?? 0);
        $post = $id ? nibwp_workflow_find($id) : null;
        if ($post) {
            wp_trash_post($post->ID);
        }
        wp_safe_redirect(admin_url('admin.php?page=nibwp-workflows&wf_msg=deleted'));
        exit;
    }
    if ($action === 'restore_defaults') {
        $n = function_exists('nibwp_workflows_restore_defaults') ? nibwp_workflows_restore_defaults() : 0;
        wp_safe_redirect(admin_url('admin.php?page=nibwp-workflows&wf_msg=restored&n=' . (int) $n));
        exit;
    }
    if ($action === 'duplicate') {
        $post = nibwp_workflow_find((int) ($_POST['workflow_id'] ?? 0));
        if ($post) {
            $new = nibwp_workflow_save([
                'title'   => $post->post_title . ' (copy)',
                'summary' => $post->post_excerpt,
                'when'    => (string) get_post_meta($post->ID, '_nibwp_wf_when', true),
                'tools'   => (array) get_post_meta($post->ID, '_nibwp_wf_tools', true),
                'body'    => $post->post_content,
                'source'  => 'admin',
            ]);
            $edit = is_wp_error($new) ? '' : '&edit=' . (int) $new;
            wp_safe_redirect(admin_url('admin.php?page=nibwp-workflows&wf_msg=duplicated' . $edit));
            exit;
        }
        wp_safe_redirect(admin_url('admin.php?page=nibwp-workflows'));
        exit;
    }
});

function nibwp_render_workflows_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }
    nibwp_render_admin_header();
    $locked = !nibwp_workflows_unlocked();
    $workflows = $locked ? [] : nibwp_workflows_posts();
    $wf_total = count($workflows);
    $wf_active = 0;
    foreach ($workflows as $wfp) { if (get_post_meta($wfp->ID, '_nibwp_wf_active', true)) { $wf_active++; } }
    ?>
    <div class="wrap nibwp-wrap">
        <div class="nibwp-page-header nibwp-page-header--with-search">
            <div>
                <h1><?php esc_html_e('Workflows', 'nibwp'); ?></h1>
                <p class="nibwp-subtitle">
                    <?php if ($locked): ?>
                        <?php esc_html_e('Reusable operating playbooks — your rules, process, and standards, applied to every build. Unlock Pro to activate, edit, and let your AI create its own.', 'nibwp'); ?>
                    <?php else: ?>
                        <?php esc_html_e('Your AI auto-follows the workflow that matches each task —', 'nibwp'); ?>
                        <span id="nw-wf-stat-active"><?php echo (int) $wf_active; ?></span> <?php printf(esc_html__('of %d pinned as always-on.', 'nibwp'), (int) $wf_total); ?>
                    <?php endif; ?>
                </p>
            </div>
            <div class="nw-page-search-wrap">
                <?php if ($locked): ?>
                    <a class="button button-primary" href="<?php echo esc_url(function_exists('nibwp_item_url') ? nibwp_item_url('pro') : 'https://nibwp.com/item/pro'); ?>" target="_blank" rel="noopener"><?php esc_html_e('Unlock with Pro', 'nibwp'); ?> &rarr;</a>
                <?php else: ?>
                    <div class="nw-tier-filter" role="group" aria-label="<?php esc_attr_e('Filter by state', 'nibwp'); ?>">
                        <button type="button" class="nw-tier-pill is-active" data-filter="all"><?php esc_html_e('All', 'nibwp'); ?></button>
                        <button type="button" class="nw-tier-pill" data-filter="pinned"><?php esc_html_e('Pinned', 'nibwp'); ?></button>
                    </div>
                    <div class="nw-tier-filter" role="group" aria-label="<?php esc_attr_e('Filter by visibility', 'nibwp'); ?>">
                        <button type="button" class="nw-tier-pill nw-scope-pill is-active" data-scope="all"><?php esc_html_e('All', 'nibwp'); ?></button>
                        <button type="button" class="nw-tier-pill nw-scope-pill" data-scope="private"><?php esc_html_e('Private', 'nibwp'); ?></button>
                        <button type="button" class="nw-tier-pill nw-scope-pill" data-scope="license"><?php esc_html_e('License', 'nibwp'); ?></button>
                        <button type="button" class="nw-tier-pill nw-scope-pill" data-scope="community"><?php esc_html_e('Community', 'nibwp'); ?></button>
                    </div>
                    <div class="nw-page-search" role="search">
                        <span class="nw-page-search__icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="7" cy="7" r="4.5"/><path d="M10.5 10.5L14 14"/></svg></span>
                        <label for="nw-wf-search" class="screen-reader-text"><?php esc_html_e('Search workflows', 'nibwp'); ?></label>
                        <input type="search" id="nw-wf-search" class="nw-page-search__input" placeholder="<?php esc_attr_e('Search workflows…', 'nibwp'); ?>" autocomplete="off" spellcheck="false">
                        <button type="button" class="nw-page-search__clear" id="nw-wf-search-clear" aria-label="<?php esc_attr_e('Clear search', 'nibwp'); ?>" hidden><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                        <span class="nw-page-search__count" id="nw-wf-search-count" hidden></span>
                    </div>
                    <form method="post" style="display:inline">
                        <?php wp_nonce_field('nibwp_workflow'); ?>
                        <input type="hidden" name="nibwp_wf_action" value="restore_defaults">
                        <button type="submit" class="button" title="<?php esc_attr_e('Re-add any NIBWP.COM default workflows you have deleted', 'nibwp'); ?>"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px;"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg><?php esc_html_e('Restore defaults', 'nibwp'); ?></button>
                    </form>
                    <button type="button" class="button button-primary" id="nw-wf-new"><?php esc_html_e('Add workflow', 'nibwp'); ?></button>
                <?php endif; ?>
            </div>
        </div>

        <?php
        $msg = $locked ? '' : sanitize_key((string) ($_GET['wf_msg'] ?? ''));
        if ($msg !== '') {
            $map = [
                'saved' => ['success', __('Workflow saved.', 'nibwp')],
                'deleted' => ['warning', __('Workflow deleted.', 'nibwp')],
                'duplicated' => ['success', __('Workflow duplicated.', 'nibwp')],
                'error' => ['error', __('Could not save the workflow.', 'nibwp')],
                'locked' => ['error', __('Workflows is a Pro feature.', 'nibwp')],
                'restored' => ['success', sprintf(
                    /* translators: %d: number of default workflows restored */
                    _n('Restored %d default workflow.', 'Restored %d default workflows.', (int) ($_GET['n'] ?? 0), 'nibwp'),
                    (int) ($_GET['n'] ?? 0)
                )],
            ];
            if (isset($map[$msg])) {
                printf('<div class="notice notice-%s"><p>%s</p></div>', esc_attr($map[$msg][0]), esc_html($map[$msg][1]));
            }
        }

        // Editor view (Pro only).
        $edit_id = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
        if (!$locked && ($edit_id > 0 || isset($_GET['new']))) {
            nibwp_render_workflow_editor($edit_id);
            echo '</div>';
            nibwp_render_admin_footer();
            return;
        }

        // Category tabs (workflows + counts already resolved in the header section).
        if (!$locked && $workflows !== []) {
            $cat_counts = ['all' => count($workflows)];
            foreach ($workflows as $wfp) {
                $c = (string) (get_post_meta($wfp->ID, '_nibwp_wf_category', true) ?: 'custom');
                $cat_counts[$c] = ($cat_counts[$c] ?? 0) + 1;
            }
            ?>
            <div class="nw-int-tabs-wrap" id="nw-wf-tabs-wrap">
                <div class="nw-int-tabs" id="nw-wf-tabs">
                    <button type="button" class="nw-int-tab is-active" data-tab="all"><?php esc_html_e('All', 'nibwp'); ?> <span class="nw-int-tab-count"><?php echo (int) $cat_counts['all']; ?></span></button>
                    <?php foreach (nibwp_workflow_categories() as $cslug => $clabel): if (empty($cat_counts[$cslug])) { continue; } ?>
                        <button type="button" class="nw-int-tab" data-tab="<?php echo esc_attr($cslug); ?>"><?php echo esc_html($clabel); ?> <span class="nw-int-tab-count"><?php echo (int) $cat_counts[$cslug]; ?></span></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php
        }
        ?>

        <?php if ($locked): ?>
            <div class="nw-wf-banner nw-wf-banner--locked">
                <div class="nw-wf-banner__main">
                    <strong><?php esc_html_e('Make your AI follow your standards — every time.', 'nibwp'); ?></strong>
                    <p><?php esc_html_e('Workflows are saved operating playbooks — your rules, process, and standards. Consistent builds, and you never repeat yourself. Preview the fine-tuned starters below.', 'nibwp'); ?></p>
                    <ul class="nw-wf-banner__modes">
                        <li><strong><?php esc_html_e('Auto-route', 'nibwp'); ?></strong> — <?php esc_html_e('the AI loads the matching playbook by its “when to use”.', 'nibwp'); ?></li>
                        <li><strong><?php esc_html_e('Pin', 'nibwp'); ?></strong> — <?php esc_html_e('make a house-rule playbook always-on for every task.', 'nibwp'); ?></li>
                        <li><strong><?php esc_html_e('Copy prompt', 'nibwp'); ?></strong> — <?php esc_html_e('run any workflow on demand.', 'nibwp'); ?></li>
                    </ul>
                </div>
                <div class="nw-wf-banner__cta">
                    <a class="button button-primary button-hero" href="<?php echo esc_url(function_exists('nibwp_item_url') ? nibwp_item_url('pro') : 'https://nibwp.com/item/pro'); ?>" target="_blank" rel="noopener"><?php esc_html_e('Get Pro — €49/yr', 'nibwp'); ?></a>
                    <a class="button" href="<?php echo esc_url(function_exists('nibwp_item_url') ? nibwp_item_url('bundle') : 'https://nibwp.com/item/bundle'); ?>" target="_blank" rel="noopener"><?php esc_html_e('Get the Bundle', 'nibwp'); ?> &rarr;</a>
                    <span class="nw-wf-banner__note"><?php esc_html_e('Unlocks editing, pinning, sharing & AI-authored workflows.', 'nibwp'); ?></span>
                </div>
            </div>
            <?php nibwp_render_workflow_locked_grid(); ?>
        <?php elseif ($workflows === []): ?>
            <div class="nibwp-empty-state"><p><?php esc_html_e('No workflows yet. Create one, or ask your AI to "save this as a workflow".', 'nibwp'); ?></p></div>
        <?php else: ?>
            <div class="nw-wf-grid" id="nw-wf-grid">
                <?php foreach ($workflows as $post):
                    $wf = nibwp_workflow_to_array($post, false);
                    $wf_hay = strtolower(trim($wf['title'] . ' ' . $wf['summary'] . ' ' . $wf['when'] . ' ' . implode(' ', array_map(static fn ($t) => $t['key'], $wf['tools'])) . ' ' . $wf['source'] . ' ' . $wf['category']));
                    $wf_cmd = sprintf('Use my "%s" NIBWP workflow (slug: %s) — load it with nibwp/get-workflow and follow it strictly.', $wf['title'], $wf['slug']);
                    $wf_vis = $wf['visibility'] ?? ['private'];
                    $wf_shared = !in_array('private', $wf_vis, true);
                    if (in_array('community', $wf_vis, true)) {
                        $wf_vicon = '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>';
                    } elseif (in_array('license', $wf_vis, true)) {
                        $wf_vicon = '<circle cx="8" cy="15" r="4"/><line x1="10.85" y1="12.15" x2="19" y2="4"/><line x1="18" y1="5" x2="20" y2="7"/><line x1="15" y1="8" x2="17" y2="10"/>';
                    } else {
                        $wf_vicon = '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>';
                    }
                    $wf_vmap    = ['private' => __('Private', 'nibwp'), 'license' => __('License Circle', 'nibwp'), 'community' => __('Community', 'nibwp')];
                    $wf_vlabels = array_map(static fn ($k) => $wf_vmap[$k] ?? $k, $wf_vis);
                    $wf_vtip    = sprintf(__('Visibility: %s', 'nibwp'), implode(' + ', $wf_vlabels));

                    // Say whether it actually reached the other sites. A tick in
                    // the box is an intention; this is what came of it.
                    $wf_share = function_exists('nibwp_workflow_share_status')
                        ? nibwp_workflow_share_status((int) $wf['id'])
                        : ['state' => 'local', 'message' => ''];
                    if ($wf_shared && $wf_share['state'] !== 'shared') {
                        $wf_state_label = [
                            'queued'          => __('Sharing — not sent yet', 'nibwp'),
                            'pending_review'  => __('Shared — community copy awaiting review', 'nibwp'),
                            'hub_unavailable' => __('Not shared yet — the hub cannot accept it', 'nibwp'),
                            'needs_license'   => __('Not shared — needs an active licence', 'nibwp'),
                            'not_owned'       => __('Not shared — this workflow came from elsewhere', 'nibwp'),
                            'error'           => __('Not shared — the last attempt failed', 'nibwp'),
                            'withdrawn'       => __('No longer shared', 'nibwp'),
                        ][$wf_share['state']] ?? '';
                        if ($wf_state_label !== '') {
                            $wf_vtip .= ' — ' . $wf_state_label
                                . ($wf_share['message'] !== '' ? ' (' . $wf_share['message'] . ')' : '');
                        }
                    }
                ?>
                    <div class="nw-wf-card <?php echo $wf['active'] ? 'is-active' : ''; ?>" data-id="<?php echo (int) $wf['id']; ?>" data-cat="<?php echo esc_attr($wf['category']); ?>" data-vis="<?php echo esc_attr(implode(' ', $wf_vis)); ?>" data-cmd="<?php echo esc_attr($wf_cmd); ?>" data-search="<?php echo esc_attr($wf_hay); ?>">
                        <div class="nw-wf-card__head">
                            <strong class="nw-wf-card__title"><?php echo esc_html($wf['title']); ?></strong>
                            <?php if ($wf['source'] === 'ai'): ?><span class="nw-wf-tag is-ai">AI</span><?php endif; ?>
                            <span class="nw-wf-scope nw-btip <?php echo ($wf_shared && in_array($wf_share['state'], ['shared', 'pending_review'], true)) ? 'is-shared' : ''; ?>" data-tip="<?php echo esc_attr($wf_vtip); ?>" aria-label="<?php echo esc_attr(implode(', ', $wf_vlabels)); ?>"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?php echo $wf_vicon; ?></svg></span>
                        </div>
                        <?php if (($wf['creator'] ?? '') !== '' || ($wf['vote_key'] ?? '') !== ''): ?>
                        <div class="nw-wf-card__meta">
                            <?php if (($wf['creator'] ?? '') !== ''): ?><span class="nw-wf-card__by"><?php esc_html_e('by', 'nibwp'); ?> <span><?php echo esc_html($wf['creator']); ?></span></span><?php endif; ?>
                            <?php if (($wf['vote_key'] ?? '') !== ''): ?>
                            <button type="button" class="nw-wf-upvote" data-vote-key="<?php echo esc_attr($wf['vote_key']); ?>" data-mine="0" aria-label="<?php esc_attr_e('Upvote', 'nibwp'); ?>"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 10v12"/><path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88z"/></svg><span class="nw-wf-upvote__n">·</span></button>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($wf['summary'] !== ''): ?><p class="nw-wf-card__sum"><?php echo esc_html($wf['summary']); ?></p><?php endif; ?>
                        <?php if ($wf['when'] !== ''): ?><p class="nw-wf-card__when"><span><?php esc_html_e('When', 'nibwp'); ?></span> <?php echo esc_html($wf['when']); ?></p><?php endif; ?>

                        <?php if ($wf['tools'] !== []): ?>
                            <div class="nw-wf-card__tools">
                                <?php foreach ($wf['tools'] as $t):
                                    $cls = $t['status']; // active | available | missing
                                    $sym = $cls === 'active' ? '✓' : ($cls === 'available' ? '•' : '✗');
                                ?>
                                    <span class="nw-wf-chip is-<?php echo esc_attr($cls); ?>" title="<?php echo esc_attr($cls); ?>"><?php echo esc_html($sym . ' ' . $t['key']); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div class="nw-wf-card__foot">
                            <button type="button" class="nw-wf-pin nw-btip <?php echo $wf['active'] ? 'is-pinned' : ''; ?>" data-id="<?php echo (int) $wf['id']; ?>" data-tip="<?php esc_attr_e('Pin Workflow', 'nibwp'); ?>" aria-label="<?php esc_attr_e('Pin', 'nibwp'); ?>">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14l-5-4.87 6.91-1.01L12 2z"/></svg>
                                <span class="nw-wf-pin-label"><?php echo $wf['active'] ? esc_html__('Pinned', 'nibwp') : esc_html__('Pin', 'nibwp'); ?></span>
                            </button>
                            <span class="nw-wf-card__actions">
                                <button type="button" class="nw-wf-copy nw-btip" data-tip="<?php esc_attr_e('Copy a prompt to paste to your AI to run this workflow.', 'nibwp'); ?>" aria-label="<?php esc_attr_e('Copy Prompt', 'nibwp'); ?>"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg><span class="nw-wf-copy-label"><?php esc_html_e('Copy Prompt', 'nibwp'); ?></span></button>
                                <a class="nw-wf-iconbtn nw-wf-edit nw-btip" data-id="<?php echo (int) $wf['id']; ?>" href="<?php echo esc_url(admin_url('admin.php?page=nibwp-workflows&edit=' . (int) $wf['id'])); ?>" data-tip="<?php esc_attr_e('View / edit this workflow', 'nibwp'); ?>" aria-label="<?php esc_attr_e('View', 'nibwp'); ?>">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
                                </a>
                                <button type="button" class="nw-wf-iconbtn nw-wf-dup nw-btip" data-id="<?php echo (int) $wf['id']; ?>" data-tip="<?php esc_attr_e('Duplicate this workflow', 'nibwp'); ?>" aria-label="<?php esc_attr_e('Duplicate', 'nibwp'); ?>"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg></button>
                                <button type="button" class="nw-wf-iconbtn nw-wf-iconbtn--danger nw-btip nw-wf-del" data-id="<?php echo (int) $wf['id']; ?>" data-name="<?php echo esc_attr($wf['title']); ?>" data-tip="<?php esc_attr_e('Delete this workflow', 'nibwp'); ?>" aria-label="<?php esc_attr_e('Delete', 'nibwp'); ?>"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M6 6v14a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V6"/></svg></button>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div id="nw-wf-more" class="nw-wf-more" hidden>
                <button type="button" class="nw-wf-more__btn" id="nw-wf-more-btn">
                    <span class="nw-wf-more__spin" aria-hidden="true"></span>
                    <span class="nw-wf-more__label"><?php esc_html_e('Load more', 'nibwp'); ?></span>
                </button>
            </div>

            <section id="nw-wf-discover" class="nw-wf-discover" hidden>
                <div class="nw-wf-discover__head">
                    <h3><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></svg> <?php esc_html_e('Discover from the community', 'nibwp'); ?></h3>
                    <p><?php esc_html_e('Curated and community-shared workflows from NIBWP.COM. Import a copy to use and customise.', 'nibwp'); ?></p>
                </div>
                <div class="nw-wf-discover__grid" id="nw-wf-discover-grid"></div>
            </section>
        <?php endif; ?>

        <?php if (!$locked): ?>
        <div class="nw-modal" id="nw-wf-modal" hidden>
            <div class="nw-modal__backdrop" data-wf-close></div>
            <div class="nw-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="nw-wf-modal-title">
                <div class="nw-modal__head">
                    <h2 id="nw-wf-modal-title"><?php esc_html_e('New workflow', 'nibwp'); ?></h2>
                    <div class="nw-modal__head-actions">
                        <button type="button" class="nw-wf-import-btn" id="nw-wf-import-modal"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg><?php esc_html_e('Import .md', 'nibwp'); ?></button>
                        <button type="button" class="nw-modal__close" data-wf-close aria-label="<?php esc_attr_e('Close', 'nibwp'); ?>">&times;</button>
                    </div>
                </div>
                <form method="post" class="nw-wf-editor nw-modal__body" id="nw-wf-form">
                    <?php wp_nonce_field('nibwp_workflow'); ?>
                    <input type="hidden" name="nibwp_wf_action" value="save">
                    <input type="hidden" name="workflow_id" id="wf_id" value="0">
                    <div>
                        <label for="m_wf_title"><?php esc_html_e('Name', 'nibwp'); ?><span class="nw-fieldtip" data-tip="<?php esc_attr_e('Short, memorable name — shown on the card and how you refer to it in chat.', 'nibwp'); ?>">?</span></label>
                        <input type="text" id="m_wf_title" name="wf_title" required placeholder="<?php esc_attr_e('e.g. Bricks + ACF build standard', 'nibwp'); ?>">
                    </div>
                    <div>
                        <label for="m_wf_summary"><?php esc_html_e('One-line summary', 'nibwp'); ?><span class="nw-fieldtip" data-tip="<?php esc_attr_e('One short line shown on the card under the name.', 'nibwp'); ?>">?</span></label>
                        <input type="text" id="m_wf_summary" name="wf_summary">
                    </div>
                    <div>
                        <label for="m_wf_creator"><?php esc_html_e('Creator', 'nibwp'); ?><span class="nw-fieldtip" data-tip="<?php esc_attr_e('Attribution name shown on the card (and wherever it is shared). Defaults to your display name.', 'nibwp'); ?>">?</span></label>
                        <input type="text" id="m_wf_creator" name="wf_creator" placeholder="<?php echo esc_attr(wp_get_current_user()->display_name); ?>">
                    </div>
                    <div class="nw-wf-row2">
                        <div>
                            <label for="m_wf_category"><?php esc_html_e('Category', 'nibwp'); ?><span class="nw-fieldtip" data-tip="<?php esc_attr_e('Groups the workflow under a tab. Pick one or choose “+ New category…” to name your own.', 'nibwp'); ?>">?</span></label>
                            <select id="m_wf_category" name="wf_category">
                                <?php foreach (nibwp_workflow_categories() as $cslug => $clabel): ?>
                                    <option value="<?php echo esc_attr($cslug); ?>"><?php echo esc_html($clabel); ?></option>
                                <?php endforeach; ?>
                                <option value="__new__"><?php esc_html_e('+ New category…', 'nibwp'); ?></option>
                            </select>
                            <input type="text" id="m_wf_category_new" name="wf_category_new" placeholder="<?php esc_attr_e('New category name', 'nibwp'); ?>" hidden style="margin-top:8px;">
                        </div>
                        <div>
                            <label for="m_wf_when"><?php esc_html_e('When to use (drives auto-routing)', 'nibwp'); ?><span class="nw-fieldtip" data-tip="<?php esc_attr_e('The trigger. Your AI auto-loads this workflow when a task matches — be specific, e.g. “Building a Bricks landing page”.', 'nibwp'); ?>">?</span></label>
                            <input type="text" id="m_wf_when" name="wf_when" placeholder="<?php esc_attr_e('e.g. Building a Bricks landing page', 'nibwp'); ?>">
                        </div>
                    </div>
                    <div>
                        <label for="m_wf_tools_input"><?php esc_html_e('Tools', 'nibwp'); ?><span class="nw-fieldtip nw-fieldtip--html">?<span class="nw-fieldtip__pop"><span class="nw-fieldtip__lead"><?php esc_html_e('What this workflow targets — pick a detected one or type your own.', 'nibwp'); ?></span><span class="nw-tool-legend"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg><?php esc_html_e('Plugin', 'nibwp'); ?></span><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m9.06 11.9 8.07-8.06a2.85 2.85 0 1 1 4.03 4.03l-8.06 8.08"/><path d="M7.07 14.94c-1.66 0-3 1.35-3 3.02 0 1.33-2.5 1.52-2 2.02 1.08 1.1 2.49 2.02 4 2.02 2.2 0 4-1.8 4-4.04a3.01 3.01 0 0 0-3-3.02z"/></svg><?php esc_html_e('Theme', 'nibwp'); ?></span><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3z"/></svg><?php esc_html_e('Skill', 'nibwp'); ?></span><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11V5a2 2 0 0 1 2-2h6l9 9a2 2 0 0 1 0 2.8l-5.2 5.2a2 2 0 0 1-2.8 0z"/><path d="M7.5 7.5h.01"/></svg><?php esc_html_e('Custom', 'nibwp'); ?></span></span></span></span></label>
                        <?php
                        $wf_tool_meta = [];
                        if (function_exists('nibwp_get_integrations')) {
                            foreach (nibwp_get_integrations() as $nw_ik => $nw_iv) {
                                $wf_tool_meta[(string) $nw_ik] = ['label' => (string) ($nw_iv['name'] ?? $nw_ik), 'type' => 'plugin'];
                            }
                        }
                        if (function_exists('nibwp_skills_discover')) {
                            foreach (nibwp_skills_discover() as $nw_sid => $nw_sk) {
                                $wf_tool_meta[(string) $nw_sid] = ['label' => (string) ($nw_sk['name'] ?? $nw_sid), 'type' => 'skill'];
                            }
                        }
                        if (function_exists('wp_get_themes')) {
                            foreach (wp_get_themes() as $nw_slug => $nw_theme) {
                                $wf_tool_meta[(string) $nw_slug] = ['label' => (string) $nw_theme->get('Name'), 'type' => 'theme'];
                            }
                        }
                        foreach ($wf_tool_meta as $nw_tk => &$nw_tm) {
                            $nw_tm['status'] = function_exists('nibwp_workflow_tool_status') ? nibwp_workflow_tool_status((string) $nw_tk) : 'missing';
                        }
                        unset($nw_tm);
                        uasort($wf_tool_meta, static fn ($a, $b) => strcasecmp((string) $a['label'], (string) $b['label']));
                        ?>
                        <div class="nw-wf-tagfield-wrap">
                            <div class="nw-wf-tagfield" id="m_wf_tags">
                                <input type="text" id="m_wf_tools_input" class="nw-wf-tagfield__input" placeholder="<?php esc_attr_e('Type or pick a plugin / theme / skill…', 'nibwp'); ?>" autocomplete="off">
                            </div>
                            <div class="nw-wf-tagsug" id="m_wf_tools_sug" hidden></div>
                        </div>
                        <input type="hidden" id="m_wf_tools" name="wf_tools" value="">
                        <script>window.nwWfToolMeta = <?php echo wp_json_encode($wf_tool_meta); ?>;</script>
                    </div>
                    <div id="nw-wf-vis-field">
                        <label><?php esc_html_e('Visibility', 'nibwp'); ?><span class="nw-fieldtip nw-fieldtip--html">?<span class="nw-fieldtip__pop"><span class="nw-vis-legend"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg><span><strong><?php esc_html_e('Private', 'nibwp'); ?></strong> — <?php esc_html_e('this site only', 'nibwp'); ?></span></span><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="15" r="4"/><line x1="10.85" y1="12.15" x2="19" y2="4"/><line x1="18" y1="5" x2="20" y2="7"/><line x1="15" y1="8" x2="17" y2="10"/></svg><span><strong><?php esc_html_e('License Circle', 'nibwp'); ?></strong> — <?php esc_html_e('every site on your license', 'nibwp'); ?></span></span><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg><span><strong><?php esc_html_e('Community', 'nibwp'); ?></strong> — <?php esc_html_e('public pool, reviewed first', 'nibwp'); ?></span></span></span><span class="nw-fieldtip__note"><?php esc_html_e('Private is exclusive.', 'nibwp'); ?></span></span></span></label>
                        <div class="nw-wf-vis" id="m_wf_visibility">
                            <label class="nw-wf-vis__opt"><input type="checkbox" name="wf_visibility[]" value="private" checked> <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> <?php esc_html_e('Private', 'nibwp'); ?></span></label>
                            <label class="nw-wf-vis__opt"><input type="checkbox" name="wf_visibility[]" value="license"> <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="15" r="4"/><line x1="10.85" y1="12.15" x2="19" y2="4"/><line x1="18" y1="5" x2="20" y2="7"/><line x1="15" y1="8" x2="17" y2="10"/></svg> <?php esc_html_e('License Circle', 'nibwp'); ?></span></label>
                            <label class="nw-wf-vis__opt"><input type="checkbox" name="wf_visibility[]" value="community"> <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg> <?php esc_html_e('Community', 'nibwp'); ?></span></label>
                        </div>
                        <p class="description"><?php esc_html_e('Sharing is coming soon — your choice is saved now and syncs once the distribution backend is live.', 'nibwp'); ?></p>
                    </div>
                    <p class="description nw-wf-vis-locked" id="nw-wf-vis-locked" hidden><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px;"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg><?php esc_html_e('This workflow belongs to its creator — duplicate it to make your own private copy you can edit and re-share.', 'nibwp'); ?></p>
                    <div>
                        <label for="m_wf_body"><?php esc_html_e('Playbook (markdown)', 'nibwp'); ?><span class="nw-fieldtip" data-tip="<?php esc_attr_e('The instructions your AI follows strictly — principles, process, rules, reporting, patterns. Markdown.', 'nibwp'); ?>">?</span></label>
                        <input type="file" id="nw-wf-file" accept=".md,.markdown,text/markdown,text/plain" hidden>
                        <textarea id="m_wf_body" name="wf_body" spellcheck="false"></textarea>
                        <p class="description"><?php esc_html_e('Principles, process, strict rules, reporting format, patterns. Your AI auto-loads this when a task matches its "when to use"; pin it to make it always-on. Or import an existing .md playbook.', 'nibwp'); ?></p>
                    </div>
                    <div class="nw-modal__foot">
                        <button type="button" class="button" data-wf-close><?php esc_html_e('Cancel', 'nibwp'); ?></button>
                        <button type="submit" class="button button-primary"><?php esc_html_e('Save workflow', 'nibwp'); ?></button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
    (function(){
        var REST  = '<?php echo esc_js(rest_url('nibwp/v1/workflows/')); ?>';
        var NONCE = '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>';

        /* Delete (AJAX) — styled confirm, smooth card removal, no page reload.
           Fully delegated + the #nw-confirm modal is looked up at click time: it's
           printed later by the admin footer, so it doesn't exist when this runs. */
        (function(){
            var pending = null;
            document.addEventListener('click', function(e){
                var del = e.target.closest('.nw-wf-del');
                if (del) {
                    e.preventDefault();
                    var cm = document.getElementById('nw-confirm');
                    if (!cm) return;
                    var msg = document.getElementById('nw-confirm-msg');
                    var ok  = document.getElementById('nw-confirm-ok');
                    var name = (del.getAttribute('data-name') || '').replace(/</g, '&lt;');
                    pending = { id: parseInt(del.getAttribute('data-id'), 10), card: del.closest('.nw-wf-card'), btn: del };
                    if (msg) msg.innerHTML = '<?php echo esc_js(__('This will permanently delete', 'nibwp')); ?> ' + (name ? '<code>' + name + '</code>' : '<?php echo esc_js(__('this workflow', 'nibwp')); ?>') + '. <?php echo esc_js(__('This cannot be undone.', 'nibwp')); ?>';
                    if (ok) ok.href = '#';
                    cm.classList.add('is-open');
                    return;
                }
                if (e.target.closest('#nw-confirm-cancel') || e.target.closest('.nw-confirm__backdrop')) { pending = null; return; }
                var okBtn = e.target.closest('#nw-confirm-ok');
                if (okBtn && pending) {
                    e.preventDefault();
                    var p = pending; pending = null;
                    var cm = document.getElementById('nw-confirm');
                    if (cm) cm.classList.remove('is-open');           // close modal at once
                    if (p.btn) p.btn.classList.add('is-loading');     // spinner inside the trash icon
                    fetch(REST + 'delete', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce':NONCE}, body: JSON.stringify({ id: p.id }) })
                        .then(function(r){ return r.json(); })
                        .then(function(d){
                            if (d && d.ok && p.card) {
                                p.card.style.transition = 'opacity .18s ease, transform .18s ease';
                                p.card.style.opacity = '0';
                                p.card.style.transform = 'scale(.96)';
                                setTimeout(function(){ p.card.remove(); if (typeof refreshWfTabs === 'function') refreshWfTabs(); if (typeof applyFilter === 'function') applyFilter(); }, 180);
                            } else if (p.btn) {
                                p.btn.classList.remove('is-loading');
                            }
                        })
                        .catch(function(){ if (p.btn) p.btn.classList.remove('is-loading'); });
                }
            });
        })();

        /* Pin / always-on toggle (delegated). */
        document.addEventListener('click', function(e){
            var btn = e.target.closest('.nw-wf-pin');
            if (!btn) return;
            var id = btn.getAttribute('data-id');
            var on = !btn.classList.contains('is-pinned');
            btn.disabled = true;
            btn.classList.add('is-loading');
            fetch(REST + 'activate', {
                method:'POST', credentials:'same-origin',
                headers:{'Content-Type':'application/json','X-WP-Nonce':NONCE},
                body: JSON.stringify({ id: parseInt(id,10), active: on })
            }).then(function(r){return r.json();}).then(function(d){
                btn.disabled = false;
                btn.classList.remove('is-loading');
                if (!d || !d.ok) return;
                btn.classList.toggle('is-pinned', !!d.active);
                var card = btn.closest('.nw-wf-card');
                if (card) card.classList.toggle('is-active', !!d.active);
                var lbl = btn.querySelector('.nw-wf-pin-label');
                if (lbl) lbl.textContent = d.active ? '<?php echo esc_js(__('Pinned', 'nibwp')); ?>' : '<?php echo esc_js(__('Pin', 'nibwp')); ?>';
                var stat = document.getElementById('nw-wf-stat-active');
                if (stat) stat.textContent = document.querySelectorAll('.nw-wf-card.is-active').length;
                if (typeof applyFilter === 'function') applyFilter();
            }).catch(function(){ btn.disabled = false; btn.classList.remove('is-loading'); });
        });

        /* Instant search filter over the cards. */
        var search = document.getElementById('nw-wf-search');
        var clear  = document.getElementById('nw-wf-search-clear');
        var count  = document.getElementById('nw-wf-search-count');
        var currentFilter = 'all';
        var currentScope = 'all';
        var currentCat = 'all';
        var INITIAL = 18, PAGE = 18;    // ~3 rows; Load more only appears beyond this
        var pageLimit = INITIAL;
        var moreWrap = document.getElementById('nw-wf-more');
        var moreBtn  = document.getElementById('nw-wf-more-btn');
        function applyFilter(){
            var q = (search && search.value || '').trim().toLowerCase();
            var cards = document.querySelectorAll('.nw-wf-card');
            var matchedTotal = 0, shown = 0;
            cards.forEach(function(c){
                var matchSearch = q === '' || (c.getAttribute('data-search') || '').indexOf(q) !== -1;
                var isPinned = c.classList.contains('is-active');
                var scope = ' ' + (c.getAttribute('data-vis') || '') + ' ';
                var matchState = currentFilter === 'all' || (currentFilter === 'pinned' && isPinned);
                var matchScope = currentScope === 'all' || scope.indexOf(' ' + currentScope + ' ') !== -1;
                var matchCat = currentCat === 'all' || (c.getAttribute('data-cat') || '') === currentCat;
                var match = matchSearch && matchState && matchScope && matchCat;
                if (match) {
                    matchedTotal++;
                    if (shown < pageLimit) { c.style.display = ''; shown++; }
                    else { c.style.display = 'none'; }
                } else {
                    c.style.display = 'none';
                }
            });
            if (moreWrap) { moreWrap.hidden = matchedTotal <= shown; }
            if (clear) clear.hidden = q === '';
            if (count) { if (q === '') { count.hidden = true; } else { count.hidden = false; count.textContent = matchedTotal + ' / ' + cards.length; } }
        }
        function resetAndFilter(){ pageLimit = INITIAL; applyFilter(); }
        /* Recount category tabs from the remaining cards; drop tabs that hit 0. */
        function refreshWfTabs(){
            var cards = document.querySelectorAll('.nw-wf-card');
            var counts = { all: cards.length };
            cards.forEach(function(c){ var cat = c.getAttribute('data-cat') || 'custom'; counts[cat] = (counts[cat] || 0) + 1; });
            document.querySelectorAll('#nw-wf-tabs .nw-int-tab').forEach(function(tab){
                var t = tab.getAttribute('data-tab');
                var cnt = tab.querySelector('.nw-int-tab-count');
                if (t === 'all') { if (cnt) { cnt.textContent = counts.all; } return; }
                var n = counts[t] || 0;
                if (n === 0) {
                    if (tab.classList.contains('is-active')) {
                        var allTab = document.querySelector('#nw-wf-tabs .nw-int-tab[data-tab="all"]');
                        if (allTab) { allTab.classList.add('is-active'); }
                        currentCat = 'all';
                    }
                    tab.remove();
                } else if (cnt) { cnt.textContent = n; }
            });
        }
        /* Explicit "Load more" — the whole list is already rendered, so this
           reveals the next page. The short delay lets the spinner read as work. */
        if (moreBtn) {
            moreBtn.addEventListener('click', function(){
                if (moreBtn.classList.contains('is-loading')) { return; }
                moreBtn.classList.add('is-loading');
                setTimeout(function(){
                    pageLimit += PAGE;
                    applyFilter();
                    moreBtn.classList.remove('is-loading');
                }, 220);
            });
        }
        if (search) {
            var t;
            search.addEventListener('input', function(){ clearTimeout(t); t = setTimeout(resetAndFilter, 60); });
            search.addEventListener('keydown', function(e){ if (e.key === 'Escape') { search.value=''; resetAndFilter(); search.blur(); } });
        }
        if (clear) clear.addEventListener('click', function(){ search.value=''; resetAndFilter(); search.focus(); });
        document.querySelectorAll('.nw-tier-pill[data-filter]').forEach(function(b){
            b.addEventListener('click', function(){
                document.querySelectorAll('.nw-tier-pill[data-filter]').forEach(function(x){ x.classList.remove('is-active'); });
                b.classList.add('is-active');
                currentFilter = b.getAttribute('data-filter') || 'all';
                resetAndFilter();
            });
        });
        document.querySelectorAll('.nw-scope-pill[data-scope]').forEach(function(b){
            b.addEventListener('click', function(){
                document.querySelectorAll('.nw-scope-pill[data-scope]').forEach(function(x){ x.classList.remove('is-active'); });
                b.classList.add('is-active');
                currentScope = b.getAttribute('data-scope') || 'all';
                resetAndFilter();
            });
        });
        document.querySelectorAll('#nw-wf-tabs .nw-int-tab').forEach(function(b){
            b.addEventListener('click', function(){
                document.querySelectorAll('#nw-wf-tabs .nw-int-tab').forEach(function(x){ x.classList.remove('is-active'); });
                b.classList.add('is-active');
                currentCat = b.getAttribute('data-tab') || 'all';
                resetAndFilter();
            });
        });
        applyFilter();   // initial paint — applies the 12-card cap

        /* Workflow upvotes — centralized popularity counter (one per install). */
        (function(){
            var btns = Array.prototype.slice.call(document.querySelectorAll('.nw-wf-upvote[data-vote-key]'));
            if (!btns.length) { return; }
            function paint(b, ups, mine){
                b.querySelector('.nw-wf-upvote__n').textContent = ups | 0;
                b.classList.toggle('is-on', mine === 1);
                b.setAttribute('data-mine', mine);
            }
            var keys = btns.map(function(b){ return b.getAttribute('data-vote-key'); });
            fetch(REST + 'votes?keys=' + encodeURIComponent(keys.join(',')), { credentials:'same-origin', headers:{'X-WP-Nonce':NONCE} })
                .then(function(r){ return r.json(); })
                .then(function(d){
                    if (!d || !d.ok || !d.votes) { return; }
                    btns.forEach(function(b){ var v = d.votes[b.getAttribute('data-vote-key')] || { ups:0, mine:0 }; paint(b, v.ups, v.mine); });
                }).catch(function(){});
            document.addEventListener('click', function(e){
                var b = e.target.closest('.nw-wf-upvote');
                if (!b || b.classList.contains('is-voting')) { return; }
                var key  = b.getAttribute('data-vote-key');
                var pMine = parseInt(b.getAttribute('data-mine') || '0', 10);
                var pN    = parseInt(b.querySelector('.nw-wf-upvote__n').textContent, 10) || 0;
                var up    = pMine !== 1;
                paint(b, pN + (up ? 1 : -1), up ? 1 : 0);   // optimistic — instant
                b.classList.add('is-voting');
                fetch(REST + 'vote', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce':NONCE}, body: JSON.stringify({ key: key, vote: up ? 'up' : 'clear' }) })
                    .then(function(r){ return r.json(); })
                    .then(function(d){ b.classList.remove('is-voting'); paint(b, (d && d.ok) ? d.ups : pN, (d && d.ok) ? d.mine : pMine); })
                    .catch(function(){ b.classList.remove('is-voting'); paint(b, pN, pMine); });   // revert
            });
        })();

        /* Discover — pull community + curated workflows from the NibWP Library hub. */
        (function(){
            var sec = document.getElementById('nw-wf-discover'), grid = document.getElementById('nw-wf-discover-grid');
            if (!sec || !grid) { return; }
            function esc(s){ var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
            var IMPORT = '<?php echo esc_js(__('Import', 'nibwp')); ?>', IMPORTED = '<?php echo esc_js(__('Imported', 'nibwp')); ?>';
            fetch(REST + 'discover', { credentials:'same-origin', headers:{'X-WP-Nonce':NONCE} })
                .then(function(r){ return r.json(); })
                .then(function(d){
                    if (!d || !d.ok || !d.assets || !d.assets.length) { return; }
                    grid.innerHTML = d.assets.map(function(a){
                        var tools = (a.tools || []).map(function(t){ return '<span class="nw-wf-chip">' + esc(t) + '</span>'; }).join('');
                        var ups = a.ups ? '<span class="nw-wf-disc__ups">▲ ' + (a.ups | 0) + '</span>' : '';
                        var badge = a.channel === 'license'
                            ? '<span class="nw-wf-disc__src is-license"><?php echo esc_js(__('Your licence', 'nibwp')); ?></span>'
                            : (a.channel === 'community' ? '<span class="nw-wf-disc__src is-community">Community</span>' : '<span class="nw-wf-disc__src">NIBWP.COM</span>');
                        var star = a.featured ? '<span class="nw-wf-disc__star" title="<?php echo esc_js(__('Featured by NIBWP.COM', 'nibwp')); ?>">★ <?php echo esc_js(__('Featured', 'nibwp')); ?></span>' : '';
                        return '<div class="nw-wf-disc-card' + (a.featured ? ' is-featured' : '') + '" data-slug="' + esc(a.slug) + '">' +
                            '<div class="nw-wf-disc-card__top">' + badge + star + ups + '</div>' +
                            '<strong>' + esc(a.title) + '</strong>' +
                            '<p>' + esc(a.summary) + '</p>' +
                            (tools ? '<div class="nw-wf-card__tools">' + tools + '</div>' : '') +
                            '<div class="nw-wf-disc-card__foot"><span class="nw-wf-card__by">' + esc('by ' + (a.author || 'Community')) + '</span>' +
                            '<button type="button" class="button button-primary nw-wf-disc-import">' + IMPORT + '</button></div>' +
                            '</div>';
                    }).join('');
                    sec.hidden = false;
                }).catch(function(){});
            grid.addEventListener('click', function(e){
                var btn = e.target.closest('.nw-wf-disc-import'); if (!btn) { return; }
                var card = btn.closest('.nw-wf-disc-card'); if (!card || btn.disabled) { return; }
                btn.disabled = true; btn.textContent = '…';
                fetch(REST + 'import', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce':NONCE}, body: JSON.stringify({ slug: card.getAttribute('data-slug') }) })
                    .then(function(r){ return r.json(); })
                    .then(function(d){
                        if (d && d.ok) { btn.textContent = IMPORTED; card.classList.add('is-imported'); setTimeout(function(){ window.location.reload(); }, 700); }
                        else { btn.disabled = false; btn.textContent = IMPORT; }
                    }).catch(function(){ btn.disabled = false; btn.textContent = IMPORT; });
            });
        })();
        /* Copy the AI command for a workflow. */
        document.addEventListener('click', function(e){
            var cp = e.target.closest('.nw-wf-copy');
            if (!cp) return;
            var card = cp.closest('.nw-wf-card');
            var cmd = card ? (card.getAttribute('data-cmd') || '') : '';
            if (!cmd) return;
            var done = function(){ cp.classList.add('is-copied'); var lbl = cp.querySelector('.nw-wf-copy-label'); var orig = lbl ? lbl.textContent : ''; if (lbl) { lbl.textContent = '<?php echo esc_js(__('Copied!', 'nibwp')); ?>'; } setTimeout(function(){ cp.classList.remove('is-copied'); if (lbl) { lbl.textContent = orig; } }, 1200); };
            if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(cmd).then(done, done); }
            else { var ta = document.createElement('textarea'); ta.value = cmd; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); } catch (_) {} document.body.removeChild(ta); done(); }
        });

        /* New / Edit modal. */
        var modal = document.getElementById('nw-wf-modal');
        if (modal) {
            var form    = document.getElementById('nw-wf-form');
            var titleEl = document.getElementById('nw-wf-modal-title');
            var lastFocus = null;
            var dupCreated = false;

            /* Tools — styled chip multi-select: typed dropdown (plugin/theme/skill) + free entry. */
            var tagWrap   = document.getElementById('m_wf_tags');
            var tagInput  = document.getElementById('m_wf_tools_input');
            var tagHidden = document.getElementById('m_wf_tools');
            var tagSug    = document.getElementById('m_wf_tools_sug');
            var tags = [];
            var toolMeta = window.nwWfToolMeta || {};
            var TOOL_ICONS = {
                plugin: '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><path d="m3.3 7 8.7 5 8.7-5"></path><path d="M12 22V12"></path>',
                theme:  '<path d="m9.06 11.9 8.07-8.06a2.85 2.85 0 1 1 4.03 4.03l-8.06 8.08"></path><path d="M7.07 14.94c-1.66 0-3 1.35-3 3.02 0 1.33-2.5 1.52-2 2.02 1.08 1.1 2.49 2.02 4 2.02 2.2 0 4-1.8 4-4.04a3.01 3.01 0 0 0-3-3.02z"></path>',
                skill:  '<path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3z"></path>',
                custom: '<path d="M3 11V5a2 2 0 0 1 2-2h6l9 9a2 2 0 0 1 0 2.8l-5.2 5.2a2 2 0 0 1-2.8 0z"></path><path d="M7.5 7.5h.01"></path>'
            };
            function toolIcon(type){
                return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' + (TOOL_ICONS[type] || TOOL_ICONS.custom) + '</svg>';
            }
            function esc(s){ return String(s).replace(/[&<>"]/g, function(c){ return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]; }); }
            function renderTags(){
                if (!tagWrap) return;
                tagWrap.querySelectorAll('.nw-wf-tag').forEach(function(c){ c.remove(); });
                tags.forEach(function(t){
                    var m = toolMeta[t] || { type: 'custom', status: 'missing' };
                    var chip = document.createElement('span');
                    chip.className = 'nw-wf-tag is-' + (m.status || 'missing');
                    chip.innerHTML = toolIcon(m.type);
                    var lbl = document.createElement('span'); lbl.className = 'lbl'; lbl.textContent = t; chip.appendChild(lbl);
                    var x = document.createElement('button'); x.type = 'button'; x.setAttribute('aria-label', 'remove'); x.textContent = '×';
                    x.addEventListener('click', function(ev){ ev.stopPropagation(); tags = tags.filter(function(z){ return z !== t; }); renderTags(); });
                    chip.appendChild(x);
                    tagWrap.insertBefore(chip, tagInput);
                });
                if (tagHidden) tagHidden.value = tags.join(', ');
            }
            function addTag(v){
                v = (v || '').trim().toLowerCase().replace(/[^a-z0-9_\-]+/g, '-').replace(/^-+|-+$/g, '');
                if (!v || tags.indexOf(v) !== -1) { return; }
                tags.push(v); renderTags();
            }
            function setTags(csv){
                tags = String(csv || '').split(',').map(function(s){ return s.trim(); }).filter(Boolean);
                renderTags();
            }
            function closeSug(){ if (tagSug) { tagSug.hidden = true; tagSug.innerHTML = ''; } }
            function buildSug(){
                if (!tagSug) return;
                var q = (tagInput.value || '').trim().toLowerCase();
                var rows = '', shown = 0;
                Object.keys(toolMeta).forEach(function(k){
                    if (shown >= 40 || tags.indexOf(k) !== -1) { return; }
                    var m = toolMeta[k];
                    if (q && (k + ' ' + (m.label || '')).toLowerCase().indexOf(q) === -1) { return; }
                    shown++;
                    rows += '<button type="button" class="nw-wf-sug is-' + (m.status || 'missing') + '" data-key="' + esc(k) + '">' + toolIcon(m.type) + '<span class="nw-wf-sug__label">' + esc(m.label || k) + '</span><span class="nw-wf-sug__key">' + esc(k) + '</span></button>';
                });
                if (q && shown === 0) {
                    rows = '<div class="nw-wf-sug__none">' + '<?php echo esc_js(__('No match — press Enter to add', 'nibwp')); ?> “' + esc(q) + '”</div>';
                }
                tagSug.innerHTML = rows;
                tagSug.hidden = (rows === '');
            }
            if (tagInput) {
                tagInput.addEventListener('input', buildSug);
                tagInput.addEventListener('focus', buildSug);
                tagInput.addEventListener('keydown', function(e){
                    if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); addTag(tagInput.value); tagInput.value = ''; buildSug(); }
                    else if (e.key === 'Escape') { closeSug(); }
                    else if (e.key === 'Backspace' && tagInput.value === '' && tags.length) { tags.pop(); renderTags(); buildSug(); }
                });
                tagInput.addEventListener('blur', function(){ setTimeout(closeSug, 150); });
                if (tagSug) tagSug.addEventListener('mousedown', function(e){
                    var b = e.target.closest('.nw-wf-sug');
                    if (!b) { return; }
                    e.preventDefault();   // keep input focus so blur doesn't close first
                    addTag(b.getAttribute('data-key')); tagInput.value = ''; buildSug(); tagInput.focus();
                });
                if (tagWrap) tagWrap.addEventListener('click', function(e){ if (e.target === tagWrap) { tagInput.focus(); } });
            }

            function open(){ lastFocus = document.activeElement; modal.hidden = false; document.body.style.overflow = 'hidden'; var f = document.getElementById('m_wf_title'); if (f) f.focus(); }
            function close(){ if (dupCreated) { window.location.reload(); return; } modal.hidden = true; document.body.style.overflow = ''; if (lastFocus) lastFocus.focus(); }
            function fill(d){
                d = d || {};
                document.getElementById('wf_id').value      = d.id || 0;
                document.getElementById('m_wf_title').value  = d.title || '';
                document.getElementById('m_wf_summary').value= d.summary || '';
                document.getElementById('m_wf_when').value   = d.when || '';
                setTags(d.tools || '');
                document.getElementById('m_wf_body').value   = d.body || '';
                var creatorEl = document.getElementById('m_wf_creator');
                if (creatorEl) creatorEl.value = d.creator || '';
                var vis = (d.visibility && d.visibility.length) ? d.visibility : ['private'];
                document.querySelectorAll('#m_wf_visibility input[type=checkbox]').forEach(function(cb){ cb.checked = vis.indexOf(cb.value) !== -1; });
                var owned = (d.owned !== false);
                var visField = document.getElementById('nw-wf-vis-field');
                var visLocked = document.getElementById('nw-wf-vis-locked');
                if (visField) visField.hidden = !owned;
                if (visLocked) visLocked.hidden = owned;
                var cat = document.getElementById('m_wf_category');
                if (cat) cat.value = d.category || 'custom';
                var catNew = document.getElementById('m_wf_category_new');
                if (catNew) { catNew.hidden = true; catNew.value = ''; }
            }
            var newBtn = document.getElementById('nw-wf-new');
            if (newBtn) newBtn.addEventListener('click', function(){ fill({}); dupCreated = false; titleEl.textContent = '<?php echo esc_js(__('New workflow', 'nibwp')); ?>'; open(); });

            /* Duplicate (AJAX) → instantly clone, then open the copy in the editor. */
            document.addEventListener('click', function(e){
                var dup = e.target.closest('.nw-wf-dup');
                if (!dup) { return; }
                e.preventDefault();
                dup.classList.add('is-loading');
                fetch(REST + 'duplicate', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce':NONCE}, body: JSON.stringify({ id: parseInt(dup.getAttribute('data-id'), 10) }) })
                    .then(function(r){ return r.json(); })
                    .then(function(d){ dup.classList.remove('is-loading'); if (d && d.ok) { fill(d.workflow); dupCreated = true; titleEl.textContent = d.workflow.title || '<?php echo esc_js(__('Workflow', 'nibwp')); ?>'; open(); } })
                    .catch(function(){ dup.classList.remove('is-loading'); });
            });

            /* Reveal the custom-category name field when "+ New category…" is picked. */
            var catSel = document.getElementById('m_wf_category');
            var catNewInput = document.getElementById('m_wf_category_new');
            if (catSel && catNewInput) {
                catSel.addEventListener('change', function(){
                    var isNew = catSel.value === '__new__';
                    catNewInput.hidden = !isNew;
                    if (isNew) { catNewInput.focus(); }
                });
            }

            /* Visibility — Private is exclusive; never empty. */
            var visBox = document.getElementById('m_wf_visibility');
            if (visBox) {
                visBox.addEventListener('change', function(e){
                    var cb = e.target;
                    if (!cb || cb.type !== 'checkbox') { return; }
                    var boxes = visBox.querySelectorAll('input[type=checkbox]');
                    if (cb.checked && cb.value === 'private') {
                        boxes.forEach(function(b){ if (b.value !== 'private') { b.checked = false; } });
                    } else if (cb.checked) {
                        boxes.forEach(function(b){ if (b.value === 'private') { b.checked = false; } });
                    }
                    var any = Array.prototype.some.call(boxes, function(b){ return b.checked; });
                    if (!any) { boxes.forEach(function(b){ if (b.value === 'private') { b.checked = true; } }); }
                });
            }

            /* Import an .md playbook file (client-side → body, title from first # or filename). */
            var fileInput = document.getElementById('nw-wf-file');
            function pickFile(){ if (fileInput) fileInput.click(); }
            var importModalBtn = document.getElementById('nw-wf-import-modal');
            if (importModalBtn) importModalBtn.addEventListener('click', pickFile);
            if (fileInput) fileInput.addEventListener('change', function(){
                var f = fileInput.files && fileInput.files[0];
                if (!f) return;
                if (f.size > 512 * 1024) { alert('<?php echo esc_js(__('File too large (max 512 KB).', 'nibwp')); ?>'); fileInput.value = ''; return; }
                var reader = new FileReader();
                reader.onload = function(){
                    var text = String(reader.result || '');
                    document.getElementById('m_wf_body').value = text;
                    var tf = document.getElementById('m_wf_title');
                    if (!tf.value) {
                        var m = text.match(/^\s*#\s+(.+)$/m);
                        tf.value = m ? m[1].trim() : f.name.replace(/\.(md|markdown|txt)$/i, '').replace(/[-_]+/g, ' ').trim();
                    }
                    fileInput.value = '';
                };
                reader.readAsText(f);
            });

            document.addEventListener('click', function(e){
                var ed = e.target.closest('.nw-wf-edit');
                if (ed) {
                    e.preventDefault();
                    dupCreated = false;
                    ed.classList.add('is-loading');
                    fetch(REST + 'get?id=' + encodeURIComponent(ed.getAttribute('data-id')), { credentials:'same-origin', headers:{'X-WP-Nonce':NONCE} })
                        .then(function(r){ return r.json(); })
                        .then(function(d){ ed.classList.remove('is-loading'); if (d && d.ok) { fill(d.workflow); titleEl.textContent = d.workflow.title || '<?php echo esc_js(__('Workflow', 'nibwp')); ?>'; open(); } else { window.location = ed.href; } })
                        .catch(function(){ ed.classList.remove('is-loading'); window.location = ed.href; });
                    return;
                }
                var ti = e.target.closest('.nw-wf-card__title');
                if (ti) {
                    var lnk = ti.closest('.nw-wf-card');
                    lnk = lnk && lnk.querySelector('.nw-wf-edit');
                    if (lnk) { lnk.click(); return; }
                }
                if (e.target.closest('[data-wf-close]')) { close(); }
            });
            document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && !modal.hidden) close(); });
        }
    })();
    </script>
    <?php
    nibwp_render_admin_footer();
}

/** The create/edit form. */
function nibwp_render_workflow_editor(int $id): void
{
    $post = $id ? nibwp_workflow_find($id) : null;
    $wf = $post ? nibwp_workflow_to_array($post, true) : ['id' => 0, 'title' => '', 'summary' => '', 'when' => '', 'tools' => [], 'body' => ''];
    $tools_csv = is_array($wf['tools']) ? implode(', ', array_map(static fn ($t) => is_array($t) ? $t['key'] : $t, $wf['tools'])) : '';
    ?>
    <p><a href="<?php echo esc_url(admin_url('admin.php?page=nibwp-workflows')); ?>">&larr; <?php esc_html_e('All workflows', 'nibwp'); ?></a></p>
    <form method="post" class="nw-wf-editor">
        <?php wp_nonce_field('nibwp_workflow'); ?>
        <input type="hidden" name="nibwp_wf_action" value="save">
        <input type="hidden" name="workflow_id" value="<?php echo (int) $wf['id']; ?>">
        <div>
            <label for="wf_title"><?php esc_html_e('Name', 'nibwp'); ?></label>
            <input type="text" id="wf_title" name="wf_title" value="<?php echo esc_attr((string) $wf['title']); ?>" placeholder="<?php esc_attr_e('e.g. Bricks + ACF build standard', 'nibwp'); ?>" required>
        </div>
        <div>
            <label for="wf_summary"><?php esc_html_e('One-line summary', 'nibwp'); ?></label>
            <input type="text" id="wf_summary" name="wf_summary" value="<?php echo esc_attr((string) $wf['summary']); ?>">
        </div>
        <div class="nw-wf-row2">
            <div>
                <label for="wf_when"><?php esc_html_e('When to use', 'nibwp'); ?></label>
                <input type="text" id="wf_when" name="wf_when" value="<?php echo esc_attr((string) $wf['when']); ?>">
            </div>
            <div>
                <label for="wf_tools"><?php esc_html_e('Tools (comma-separated keys)', 'nibwp'); ?></label>
                <input type="text" id="wf_tools" name="wf_tools" value="<?php echo esc_attr($tools_csv); ?>" placeholder="bricks, acf, etchwp-pro">
                <p class="description"><?php esc_html_e('Integration or skill keys — shown as detection chips on the card.', 'nibwp'); ?></p>
            </div>
        </div>
        <div>
            <label for="wf_body"><?php esc_html_e('Playbook (markdown)', 'nibwp'); ?></label>
            <textarea id="wf_body" name="wf_body" spellcheck="false"><?php echo esc_textarea((string) $wf['body']); ?></textarea>
            <p class="description"><?php esc_html_e('Principles, process, strict rules, reporting format, patterns, project notes. The active workflow is injected into your AI as strict context.', 'nibwp'); ?></p>
        </div>
        <div>
            <button type="submit" class="button button-primary"><?php esc_html_e('Save workflow', 'nibwp'); ?></button>
        </div>
    </form>
    <?php
}

/**
 * Free-build preview: render the shipped starter playbooks as locked PRO cards
 * (same gold "PRO" tag pattern as the Skills / Integrations panels). Tool chips
 * still show real detection so the value is visible before purchase.
 */
function nibwp_render_workflow_locked_grid(): void
{
    ?>
    <div class="nw-wf-grid">
        <?php foreach (nibwp_workflows_starters() as $meta):
            $hay = strtolower(trim($meta['title'] . ' ' . $meta['summary'] . ' ' . $meta['when'] . ' ' . implode(' ', $meta['tools'])));
        ?>
            <div class="nw-wf-card is-locked" data-search="<?php echo esc_attr($hay); ?>">
                <div class="nw-wf-card__head">
                    <strong class="nw-wf-card__title"><?php echo esc_html($meta['title']); ?></strong>
                    <span class="nw-wf-tag is-pro">PRO</span>
                </div>
                <?php if ($meta['summary'] !== ''): ?><p class="nw-wf-card__sum"><?php echo esc_html($meta['summary']); ?></p><?php endif; ?>
                <?php if ($meta['when'] !== ''): ?><p class="nw-wf-card__when"><span><?php esc_html_e('When', 'nibwp'); ?></span> <?php echo esc_html($meta['when']); ?></p><?php endif; ?>
                <?php if (!empty($meta['tools'])): ?>
                    <div class="nw-wf-card__tools">
                        <?php foreach ($meta['tools'] as $key):
                            $cls = nibwp_workflow_tool_status($key);
                            $sym = $cls === 'active' ? '✓' : ($cls === 'available' ? '•' : '✗');
                        ?>
                            <span class="nw-wf-chip is-<?php echo esc_attr($cls); ?>"><?php echo esc_html($sym . ' ' . $key); ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="nw-wf-card__foot">
                    <span class="nw-wf-card__lock">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                        <?php esc_html_e('Pro feature', 'nibwp'); ?>
                    </span>
                    <span class="nw-wf-card__actions">
                        <a href="<?php echo esc_url(function_exists('nibwp_item_url') ? nibwp_item_url('pro') : 'https://nibwp.com/item/pro'); ?>" target="_blank" rel="noopener"><?php esc_html_e('Unlock', 'nibwp'); ?></a>
                    </span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
}

/** Pro upsell for non-licensed sites. */
function nibwp_render_workflows_upsell(): void
{
    ?>
    <div class="nibwp-empty-state" style="text-align:center;padding:40px 24px;">
        <h2 style="margin-top:0;"><?php esc_html_e('Workflows is a Pro feature', 'nibwp'); ?></h2>
        <p style="max-width:560px;margin:0 auto 18px;color:var(--nw-text-muted);">
            <?php esc_html_e('Save reusable operating playbooks — rules, process, and standards your AI follows strictly. Ships with fine-tuned starters (Bricks+ACF, Etch+ACSS, SEO, audits, safe-changes, contact forms). Your AI can create and update them too.', 'nibwp'); ?>
        </p>
        <a class="button button-primary button-hero" href="https://nibwp.com/pricing" target="_blank" rel="noopener"><?php esc_html_e('Unlock with Pro', 'nibwp'); ?> &rarr;</a>
    </div>
    <?php
}

/** REST: toggle a workflow active. */
add_action('rest_api_init', static function (): void {
    register_rest_route('nibwp/v1', '/workflows/get', [
        'methods'             => 'GET',
        'permission_callback' => static fn (): bool => current_user_can('manage_options'),
        'args' => ['id' => ['type' => 'integer', 'required' => true]],
        'callback' => static function (\WP_REST_Request $req): \WP_REST_Response {
            if (!nibwp_workflows_unlocked()) {
                return new \WP_REST_Response(['ok' => false], 402);
            }
            $post = nibwp_workflow_find((int) $req->get_param('id'));
            if (!$post) {
                return new \WP_REST_Response(['ok' => false], 404);
            }
            $a = nibwp_workflow_to_array($post, true);
            return new \WP_REST_Response(['ok' => true, 'workflow' => [
                'id'      => $a['id'],
                'title'   => $a['title'],
                'summary' => $a['summary'],
                'when'    => $a['when'],
                'category'=> $a['category'],
                'creator' => $a['creator'],
                'visibility' => $a['visibility'],
                'owned'   => $a['owned'],
                'tools'   => implode(', ', array_map(static fn ($t) => $t['key'], $a['tools'])),
                'body'    => $a['body'],
            ]], 200);
        },
    ]);
    register_rest_route('nibwp/v1', '/workflows/duplicate', [
        'methods'             => 'POST',
        'permission_callback' => static fn (): bool => current_user_can('manage_options'),
        'args' => ['id' => ['type' => 'integer', 'required' => true]],
        'callback' => static function (\WP_REST_Request $req): \WP_REST_Response {
            if (!nibwp_workflows_unlocked()) {
                return new \WP_REST_Response(['ok' => false, 'message' => 'Pro feature'], 402);
            }
            $post = nibwp_workflow_find((int) $req->get_param('id'));
            if (!$post) {
                return new \WP_REST_Response(['ok' => false, 'message' => 'Not found'], 404);
            }
            $new = nibwp_workflow_save([
                'title'    => $post->post_title . ' (copy)',
                'summary'  => $post->post_excerpt,
                'when'     => (string) get_post_meta($post->ID, '_nibwp_wf_when', true),
                'tools'    => (array) get_post_meta($post->ID, '_nibwp_wf_tools', true),
                'category' => (string) get_post_meta($post->ID, '_nibwp_wf_category', true),
                'body'     => $post->post_content,
                'source'   => 'admin',
            ]);
            if (is_wp_error($new)) {
                return new \WP_REST_Response(['ok' => false, 'message' => $new->get_error_message()], 500);
            }
            $a = nibwp_workflow_to_array(get_post($new), true);
            return new \WP_REST_Response(['ok' => true, 'workflow' => [
                'id'      => $a['id'],
                'title'   => $a['title'],
                'summary' => $a['summary'],
                'when'    => $a['when'],
                'category'=> $a['category'],
                'creator' => $a['creator'],
                'visibility' => $a['visibility'],
                'owned'   => $a['owned'],
                'tools'   => implode(', ', array_map(static fn ($t) => $t['key'], $a['tools'])),
                'body'    => $a['body'],
            ]], 200);
        },
    ]);
    register_rest_route('nibwp/v1', '/workflows/delete', [
        'methods'             => 'POST',
        'permission_callback' => static fn (): bool => current_user_can('manage_options'),
        'args' => ['id' => ['type' => 'integer', 'required' => true]],
        'callback' => static function (\WP_REST_Request $req): \WP_REST_Response {
            if (!nibwp_workflows_unlocked()) {
                return new \WP_REST_Response(['ok' => false, 'message' => 'Pro feature'], 402);
            }
            $post = nibwp_workflow_find((int) $req->get_param('id'));
            if (!$post) {
                return new \WP_REST_Response(['ok' => false, 'message' => 'Not found'], 404);
            }
            wp_trash_post($post->ID);
            return new \WP_REST_Response(['ok' => true, 'id' => $post->ID], 200);
        },
    ]);
    // Votes — proxy to the centralized counter on nibwp.com (any install votes once).
    register_rest_route('nibwp/v1', '/workflows/votes', [
        'methods'             => 'GET',
        'permission_callback' => static fn (): bool => current_user_can('manage_options'),
        'args' => ['keys' => ['type' => 'string', 'required' => true]],
        'callback' => static function (\WP_REST_Request $req): \WP_REST_Response {
            $keys = sanitize_text_field((string) $req->get_param('keys'));
            if ($keys === '') {
                return new \WP_REST_Response(['ok' => true, 'votes' => []], 200);
            }
            $ck = 'nibwp_wf_votes_' . md5($keys);
            $cached = get_transient($ck);
            if (is_array($cached)) {
                return new \WP_REST_Response(['ok' => true, 'votes' => $cached, 'cached' => true], 200);
            }
            $url = nibwp_votes_api_base() . '/votes?' . http_build_query(['keys' => $keys, 'voter' => nibwp_workflow_voter_hash()]);
            $resp = wp_remote_get($url, ['timeout' => 8]);
            if (is_wp_error($resp)) {
                return new \WP_REST_Response(['ok' => false, 'votes' => []], 200);
            }
            $body  = json_decode((string) wp_remote_retrieve_body($resp), true);
            $votes = is_array($body['votes'] ?? null) ? $body['votes'] : [];
            set_transient($ck, $votes, 5 * MINUTE_IN_SECONDS);
            return new \WP_REST_Response(['ok' => true, 'votes' => $votes], 200);
        },
    ]);
    register_rest_route('nibwp/v1', '/workflows/vote', [
        'methods'             => 'POST',
        'permission_callback' => static fn (): bool => current_user_can('manage_options'),
        'args' => ['key' => ['type' => 'string', 'required' => true], 'vote' => ['type' => 'string', 'required' => true]],
        'callback' => static function (\WP_REST_Request $req): \WP_REST_Response {
            $key  = sanitize_text_field((string) $req->get_param('key'));
            $vote = sanitize_key((string) $req->get_param('vote'));
            if ($key === '' || !in_array($vote, ['up', 'down', 'clear'], true)) {
                return new \WP_REST_Response(['ok' => false], 400);
            }
            $resp = wp_remote_post(nibwp_votes_api_base() . '/votes/cast', [
                'timeout' => 8,
                'body'    => ['key' => $key, 'voter' => nibwp_workflow_voter_hash(), 'vote' => $vote],
            ]);
            if (is_wp_error($resp)) {
                return new \WP_REST_Response(['ok' => false, 'message' => 'unreachable'], 502);
            }
            $body = json_decode((string) wp_remote_retrieve_body($resp), true);
            return new \WP_REST_Response(is_array($body) ? $body : ['ok' => false], 200);
        },
    ]);
    // Discover — community + curated workflows from the NibWP Library hub (nibwp.com).
    register_rest_route('nibwp/v1', '/workflows/discover', [
        'methods'             => 'GET',
        'permission_callback' => static fn (): bool => current_user_can('manage_options'),
        'callback' => static function (\WP_REST_Request $req): \WP_REST_Response {
            $cached = get_transient('nibwp_wf_discover');
            if (!is_array($cached)) {
                $url  = nibwp_library_api_base() . '/assets?' . http_build_query(['type' => 'workflow', 'per_page' => 60, 'voter' => nibwp_workflow_voter_hash()]);
                $resp = wp_remote_get($url, ['timeout' => 8]);
                $cached = [];
                if (!is_wp_error($resp)) {
                    $b = json_decode((string) wp_remote_retrieve_body($resp), true);
                    $cached = is_array($b['assets'] ?? null) ? $b['assets'] : [];
                }
                set_transient('nibwp_wf_discover', $cached, 10 * MINUTE_IN_SECONDS);
            }
            // Workflows the other sites on this licence have shared. Listed
            // first: they are the ones this user's own team wrote.
            $circle = function_exists('nibwp_workflow_circle_assets') ? nibwp_workflow_circle_assets() : [];
            foreach ($circle as $i => $a) {
                $circle[$i]['channel'] = 'license';
            }

            $have = nibwp_workflows_local_slugs();
            $seen = [];
            $out  = [];
            foreach (array_merge($circle, $cached) as $a) {
                $slug = (string) ($a['slug'] ?? '');
                if ($slug === '' || !empty($have[$slug]) || isset($seen[$slug])) {
                    continue;
                }
                $seen[$slug] = true;
                $out[] = $a;
            }
            return new \WP_REST_Response(['ok' => true, 'assets' => $out], 200);
        },
    ]);
    register_rest_route('nibwp/v1', '/workflows/import', [
        'methods'             => 'POST',
        'permission_callback' => static fn (): bool => current_user_can('manage_options'),
        'args' => ['slug' => ['type' => 'string', 'required' => true]],
        'callback' => static function (\WP_REST_Request $req): \WP_REST_Response {
            $slug = sanitize_title((string) $req->get_param('slug'));
            if ($slug === '') {
                return new \WP_REST_Response(['ok' => false], 400);
            }
            // A workflow shared with this licence is private to it, so the
            // circle has to travel with both the search and the body fetch —
            // without it the hub answers 404, which is the point.
            $circles = function_exists('nibwp_workflow_circle_hashes') ? nibwp_workflow_circle_hashes() : [];
            $ident   = $circles === [] ? [] : ['circles' => implode(',', $circles)];

            $match = null;
            foreach ([['channel' => 'license'], []] as $scope) {
                if ($scope === ['channel' => 'license'] && $circles === []) {
                    continue;
                }
                $resp = wp_remote_get(nibwp_library_api_base() . '/assets?' . http_build_query(array_merge(
                    ['type' => 'workflow', 'search' => $slug, 'per_page' => 20],
                    $scope,
                    $ident
                )), ['timeout' => 8]);
                if (is_wp_error($resp)) {
                    return new \WP_REST_Response(['ok' => false, 'message' => 'unreachable'], 502);
                }
                $b = json_decode((string) wp_remote_retrieve_body($resp), true);
                foreach (($b['assets'] ?? []) as $a) {
                    if (($a['slug'] ?? '') === $slug) { $match = $a; break 2; }
                }
            }
            if (!$match) {
                return new \WP_REST_Response(['ok' => false, 'message' => 'not found'], 404);
            }
            $body = '';
            $br = wp_remote_get(nibwp_library_api_base() . '/assets/' . (int) $match['id'] . ($ident === [] ? '' : '?' . http_build_query($ident)), ['timeout' => 8]);
            if (!is_wp_error($br)) {
                $bd = json_decode((string) wp_remote_retrieve_body($br), true);
                $body = (string) ($bd['asset']['body'] ?? '');
            }
            $id = nibwp_workflow_save([
                'title'    => $match['title'] ?? $slug,
                'summary'  => $match['summary'] ?? '',
                'when'     => $match['when'] ?? '',
                'tools'    => $match['tools'] ?? [],
                'category' => $match['category'] ?? 'custom',
                'icon'     => $match['icon'] ?? '',
                'body'     => $body,
                'creator'  => $match['author'] ?? 'Community',
                'source'   => ($match['channel'] ?? '') === 'community' ? 'community' : 'license',
            ]);
            if (is_wp_error($id)) {
                return new \WP_REST_Response(['ok' => false], 500);
            }
            // Bind the hub slug so it dedupes from Discover and shares ratings.
            update_post_meta((int) $id, '_nibwp_wf_starter_slug', $slug);
            return new \WP_REST_Response(['ok' => true, 'id' => (int) $id], 201);
        },
    ]);
    register_rest_route('nibwp/v1', '/workflows/activate', [
        'methods'             => 'POST',
        'permission_callback' => static fn (): bool => current_user_can('manage_options'),
        'args' => ['id' => ['type' => 'integer', 'required' => true], 'active' => ['type' => 'boolean', 'required' => true]],
        'callback' => static function (\WP_REST_Request $req): \WP_REST_Response {
            if (!nibwp_workflows_unlocked()) {
                return new \WP_REST_Response(['ok' => false, 'message' => 'Pro feature'], 402);
            }
            $post = nibwp_workflow_find((int) $req->get_param('id'));
            if (!$post) {
                return new \WP_REST_Response(['ok' => false, 'message' => 'Not found'], 404);
            }
            $active = (bool) $req->get_param('active');
            nibwp_workflow_set_active($post->ID, $active);
            return new \WP_REST_Response(['ok' => true, 'id' => $post->ID, 'active' => $active], 200);
        },
    ]);
});
