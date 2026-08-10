<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Skill Marketplace — premium-skill showcase + license activation.
 *
 * Three sections:
 *   1. License status card (active/inactive, plan, expiry, activation form)
 *   2. Skill packs grid (premium gold-bordered cards with Buy/Activate CTA,
 *      free cards with toggle)
 *   3. FAQ block
 */

function nibwp_handle_skill_actions(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    // License activation
    if (isset($_POST['nibwp_activate_license'])) {
        check_admin_referer('nibwp_license');
        $key = isset($_POST['license_key']) ? sanitize_text_field(wp_unslash($_POST['license_key'])) : '';
        $result = nibwp_license_activate($key);

        // Detect the silent "active license but Pro files did not install"
        // case so the redirect carries an actionable signal — not just a
        // generic "activated" toast.
        $install_failed = false;
        $install_msgs   = [];
        foreach ((array) ($result['package_installs'] ?? []) as $pkg) {
            if (!empty($pkg['already_installed']) || !empty($pkg['installed']) || !empty($pkg['activated'])) {
                continue;
            }
            $install_failed = true;
            $install_msgs[] = sprintf('%s: %s', (string) $pkg['slug'], (string) $pkg['message']);
        }
        $premium_present = defined('NIBWP_HAS_PREMIUM_CODE') && NIBWP_HAS_PREMIUM_CODE;

        if ($result['ok'] && (!$premium_present && !$install_failed)) {
            // Activation succeeded, no install failure was recorded, but
            // premium files are not yet on disk for this request. They will
            // be on the NEXT request once the upgrader's write completes.
            $msg  = 'activated';
            $note = $result['message'] . ' Refresh this page to see premium features unlock.';
        } elseif ($result['ok'] && $install_failed) {
            $msg  = 'activated_install_failed';
            $note = $result['message']
                . ' BUT auto-install of Pro files failed: '
                . implode(' | ', $install_msgs)
                . ' — upload the Pro zip manually via Plugins → Add New → Upload, or use the Reinstall Pro button on the License page.';
        } else {
            $msg  = $result['ok'] ? 'activated' : 'activate_failed';
            $note = (string) $result['message'];
        }
        wp_safe_redirect(admin_url('admin.php?page=nibwp-skills&nibwp_result=' . $msg . '&nibwp_msg=' . urlencode($note)));
        exit;
    }

    // Manual "reinstall Pro" trigger — for sites where auto-install failed.
    if (isset($_POST['nibwp_reinstall_pro'])) {
        check_admin_referer('nibwp_license');
        $key = '';
        foreach (nibwp_licenses_get() as $k => $lic) {
            if (nibwp_license_is_active_for_key((string) $k)) { $key = (string) $k; break; }
        }
        if ($key === '') {
            wp_safe_redirect(admin_url('admin.php?page=nibwp-skills&nibwp_result=reinstall_failed&nibwp_msg=' . urlencode('No active license found. Activate one first.')));
            exit;
        }
        $check = nibwp_license_check($key, force: true);
        $raw   = $check['license']['raw'] ?? ($check['data'] ?? []);
        if (!is_array($raw) || empty($raw)) {
            wp_safe_redirect(admin_url('admin.php?page=nibwp-skills&nibwp_result=reinstall_failed&nibwp_msg=' . urlencode('License server did not return install metadata; try Refresh first.')));
            exit;
        }
        $results = nibwp_maybe_install_packages($raw);
        $msgs = [];
        $any_ok = false;
        foreach ($results as $r) {
            if (!empty($r['installed']) || !empty($r['already_installed']) || !empty($r['activated'])) {
                $any_ok = true;
            }
            $msgs[] = sprintf('%s: %s', (string) $r['slug'], (string) $r['message']);
        }
        $note = implode(' | ', $msgs);
        $key  = $any_ok ? 'reinstall_ok' : 'reinstall_failed';
        wp_safe_redirect(admin_url('admin.php?page=nibwp-skills&nibwp_result=' . $key . '&nibwp_msg=' . urlencode($note)));
        exit;
    }

    // License deactivation
    if (isset($_POST['nibwp_deactivate_license'])) {
        check_admin_referer('nibwp_license');
        $result = nibwp_license_deactivate();
        $note = urlencode((string) $result['message']);
        wp_safe_redirect(admin_url("admin.php?page=nibwp-skills&nibwp_result=deactivated&nibwp_msg=$note"));
        exit;
    }

    // Skill toggle
    if (isset($_POST['nibwp_toggle_skill'])) {
        check_admin_referer('nibwp_skill_toggle');
        $id = isset($_POST['skill_id']) ? sanitize_key($_POST['skill_id']) : '';
        $on = !empty($_POST['skill_state']);
        if ($id !== '') {
            nibwp_skill_set_enabled($id, $on);
        }
        wp_safe_redirect(admin_url('admin.php?page=nibwp-skills&nibwp_result=skill_toggled'));
        exit;
    }
}

function nibwp_render_skills_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $skills = nibwp_skills_discover();
    $stats = nibwp_skills_stats();
    $license = nibwp_license_get();
    $license_card = nibwp_license_status_card();

    $result_key = $_GET['nibwp_result'] ?? null;
    $result_msg = isset($_GET['nibwp_msg']) ? urldecode((string) $_GET['nibwp_msg']) : '';

    nibwp_render_admin_header();
    ?>
    <div class="wrap nibwp-wrap">
        <div class="nibwp-page-header">
            <div>
                <h1><?php esc_html_e('Skill Marketplace', 'nibwp'); ?></h1>
                <p class="nibwp-subtitle"><?php esc_html_e('Curated AI skill packs. Image → component. Figma → EtchWP. HTML → atomic. Pixel-perfect output, every time.', 'nibwp'); ?></p>
            </div>
        </div>

        <?php if ($result_key === 'activated'): ?>
            <div class="notice notice-success"><p><?php echo esc_html($result_msg !== '' ? $result_msg : 'License activated.'); ?></p></div>
        <?php elseif ($result_key === 'activate_failed'): ?>
            <div class="notice notice-error"><p><?php echo esc_html($result_msg !== '' ? $result_msg : 'Activation failed.'); ?></p></div>
        <?php elseif ($result_key === 'deactivated'): ?>
            <div class="notice notice-warning"><p><?php echo esc_html($result_msg !== '' ? $result_msg : 'License deactivated.'); ?></p></div>
        <?php elseif ($result_key === 'skill_toggled'): ?>
            <div class="notice notice-success"><p><?php esc_html_e('Skill updated.', domain: 'nibwp'); ?></p></div>
        <?php endif; ?>

        <!-- License Status Card -->
        <div class="nw-license-card nw-license-card--<?php echo esc_attr($license_card['state']); ?>">
            <div class="nw-license-card__icon">
                <?php if ($license_card['state'] === 'active'): ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
                <?php else: ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                <?php endif; ?>
            </div>
            <div class="nw-license-card__body">
                <div class="nw-license-card__title"><?php echo esc_html($license_card['title']); ?></div>
                <div class="nw-license-card__sub"><?php echo esc_html($license_card['subtitle']); ?></div>
                <?php if ($license_card['state'] === 'active'): ?>
                    <div class="nw-license-card__meta">
                        <span><strong><?php esc_html_e('Key:', domain: 'nibwp'); ?></strong> <code><?php echo esc_html(substr((string) ($license['key'] ?? ''), 0, 8)); ?>••••••••</code></span>
                        <?php if (!empty($license['allowed_sites'])): ?>
                            <span><strong><?php esc_html_e('Sites:', domain: 'nibwp'); ?></strong> <?php echo esc_html((string) $license['allowed_sites']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($license['domain'])): ?>
                            <span><strong><?php esc_html_e('Domain:', domain: 'nibwp'); ?></strong> <?php echo esc_html((string) $license['domain']); ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="nw-license-card__action">
                <?php if ($license_card['state'] === 'active'): ?>
                    <form method="post" style="margin:0;">
                        <?php wp_nonce_field('nibwp_license'); ?>
                        <button type="submit" name="nibwp_deactivate_license" class="button nibwp-btn-danger"
                                onclick="return confirm('<?php esc_attr_e('Deactivate this license? Premium skills will lock.', domain: 'nibwp'); ?>');">
                            <?php esc_html_e('Deactivate', domain: 'nibwp'); ?>
                        </button>
                    </form>
                <?php else: ?>
                    <button type="button" class="button button-primary" id="nw-open-activate"><?php echo esc_html($license_card['cta_label']); ?></button>
                <?php endif; ?>
            </div>
        </div>

        <?php
        // Recovery banner — license is active but premium files were never
        // deployed (auto-install failure, manual Free build, etc.). Give the
        // user a clear "Reinstall Pro now" affordance instead of leaving them
        // stuck on the Pricing CTA below.
        $premium_present = defined('NIBWP_HAS_PREMIUM_CODE') && NIBWP_HAS_PREMIUM_CODE;
        if ($license_card['state'] === 'active' && !$premium_present): ?>
            <div class="notice notice-warning" style="margin:12px 0;padding:12px 16px;border-left-width:4px;">
                <p style="margin:0 0 8px;font-weight:600;">
                    <?php esc_html_e('License is active, but Pro plugin files are NOT installed on this site yet.', 'nibwp'); ?>
                </p>
                <p style="margin:0 0 10px;">
                    <?php esc_html_e('Without the Pro files, every premium skill, integration, and toolkit stays locked even though your license entitles you to them. This usually happens when the auto-install step is blocked by hosting policy (DISALLOW_FILE_MODS) or by a connection issue during activation.', 'nibwp'); ?>
                </p>
                <form method="post" style="display:inline-block;margin:0;">
                    <?php wp_nonce_field('nibwp_license'); ?>
                    <button type="submit" name="nibwp_reinstall_pro" class="button button-primary">
                        <?php esc_html_e('Reinstall Pro now', 'nibwp'); ?>
                    </button>
                </form>
                <span style="margin-left:8px;color:var(--nw-text-muted);">
                    <?php esc_html_e('If this fails too, download the Pro zip from your account on nibwp.com and upload it via Plugins → Add New → Upload.', 'nibwp'); ?>
                </span>
            </div>
        <?php endif; ?>

        <!-- Activation form (hidden until clicked) -->
        <div id="nw-license-form-wrap" class="nw-license-form-wrap" hidden>
            <form method="post" class="nw-license-form">
                <?php wp_nonce_field('nibwp_license'); ?>
                <label for="nw-license-key"><?php esc_html_e('Enter your license key', domain: 'nibwp'); ?></label>
                <div class="nw-license-form__row">
                    <input type="text" id="nw-license-key" name="license_key" placeholder="NOV-XXXX-XXXX-XXXX" autocomplete="off" required>
                    <button type="submit" name="nibwp_activate_license" class="button button-primary"><?php esc_html_e('Activate', domain: 'nibwp'); ?></button>
                </div>
                <p class="description"><?php
                    printf(
                        /* translators: %1$s/%2$s = open/close <a> for skill license, %3$s/%4$s = open/close <a> for Bundle */
                        esc_html__('No license yet? %1$sBuy a skill license%2$s (EtchWP / Bricks — image → component, Figma → EtchWP) or %3$sget the NIBWP Bundle%4$s to unlock every skill, current and future.', domain: 'nibwp'),
                        '<a href="https://nibwp.com/pricing" target="_blank" rel="noopener">',
                        '</a>',
                        '<a href="https://nibwp.com/item/bundle" target="_blank" rel="noopener"><strong>',
                        '</strong></a>',
                    );
                ?></p>
            </form>
        </div>

        <!-- Featured Bundle row — hidden when (a) user already has Bundle, OR
             (b) license is active but premium files just haven't installed
             yet. The "Reinstall Pro" banner above is the actionable signal
             in that case — showing the pricing CTA would suggest the user
             needs to buy something when they already paid. -->
        <?php
        $nw_entitlements = function_exists('nibwp_entitlements') ? nibwp_entitlements() : [];
        $nw_has_bundle   = in_array('skill:*', $nw_entitlements, true);
        $nw_active_no_premium = $license_card['state'] === 'active' && !$premium_present;
        if (!$nw_has_bundle && !$nw_active_no_premium):
        ?>
        <div class="nw-bundle-hero" role="region" aria-label="<?php esc_attr_e('Upgrade to NIBWP Bundle', 'nibwp'); ?>">
            <div class="nw-bundle-hero__badge"><?php esc_html_e('BEST VALUE', 'nibwp'); ?></div>
            <div class="nw-bundle-hero__body">
                <h3 class="nw-bundle-hero__title"><?php esc_html_e('Unlock everything with the NIBWP Bundle', 'nibwp'); ?></h3>
                <p class="nw-bundle-hero__sub"><?php
                    esc_html_e('Every premium integration, every skill pack — current and future. One license, no à-la-carte upgrades. €79/yr or €179 lifetime.', 'nibwp');
                ?></p>
                <ul class="nw-bundle-hero__features">
                    <li>✓ <?php esc_html_e('All 26 premium integrations (Elementor, Bricks, EtchWP, ACF, JetEngine, ACSS, …)', 'nibwp'); ?></li>
                    <li>✓ <?php esc_html_e('All skill packs (EtchWP Pro, Bricks Pro, ACSS Pro — future skills auto-unlocked)', 'nibwp'); ?></li>
                    <li>✓ <?php esc_html_e('Toolkits: security, notifications, migration, content planner, SEO', 'nibwp'); ?></li>
                    <li>✓ <?php esc_html_e('Sandboxed PHP execution + file ops abilities', 'nibwp'); ?></li>
                </ul>
            </div>
            <div class="nw-bundle-hero__cta">
                <a class="button button-primary button-hero" href="https://nibwp.com/item/bundle" target="_blank" rel="noopener">
                    <?php esc_html_e('Get the Bundle', 'nibwp'); ?> &rarr;
                </a>
                <a class="nw-bundle-hero__alt" href="https://nibwp.com/pricing" target="_blank" rel="noopener">
                    <?php esc_html_e('Compare all tiers', 'nibwp'); ?>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Stats row -->
        <div class="nibwp-dashboard-stats" style="margin:20px 0;">
            <div class="nibwp-stat-card">
                <div class="label"><?php esc_html_e('Total skill packs', domain: 'nibwp'); ?></div>
                <div class="value"><?php echo esc_html((string) $stats['total']); ?></div>
            </div>
            <div class="nibwp-stat-card">
                <div class="label"><?php esc_html_e('Premium', domain: 'nibwp'); ?></div>
                <div class="value" style="color:#d97706;"><?php echo esc_html((string) $stats['premium']); ?></div>
            </div>
            <div class="nibwp-stat-card">
                <div class="label"><?php esc_html_e('Active', domain: 'nibwp'); ?></div>
                <div class="value" id="nw-skill-active-count" style="color:var(--nw-ok);"><?php echo esc_html((string) $stats['active']); ?></div>
            </div>
            <div class="nibwp-stat-card">
                <div class="label"><?php esc_html_e('Locked', domain: 'nibwp'); ?></div>
                <div class="value" style="color:var(--nw-text-muted);"><?php echo esc_html((string) $stats['locked']); ?></div>
            </div>
        </div>

        <!-- Skill grid -->
        <h2 class="nibwp-section-title"><?php esc_html_e('Available Skill Packs', domain: 'nibwp'); ?></h2>
        <?php if ($skills === []): ?>
            <div class="nibwp-empty-state">
                <p><?php esc_html_e('No skill packs installed yet.', domain: 'nibwp'); ?></p>
            </div>
        <?php else: ?>
            <?php
            // Free first, then paid, each group alphabetical. Someone opening
            // this page can use the free ones today; scrolling past a wall of
            // locked cards to reach them is the wrong first impression.
            usort($skills, static function (array $a, array $b): int {
                $tier = (int) !empty($a['premium']) <=> (int) !empty($b['premium']);
                return $tier !== 0
                    ? $tier
                    : strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
            });

            $free_count = count(array_filter($skills, static fn(array $s): bool => empty($s['premium'])));
            $pro_count = count($skills) - $free_count;
            ?>
            <?php // The same tab component the Integrations screen uses, with the
                  // search moved in beside it: one bar carrying both ways of
                  // narrowing the same grid, rather than a filter up in the page
                  // header and another down here. ?>
            <div class="nw-int-tabs-wrap nw-skill-bar">
                <div class="nw-int-tabs" role="tablist" aria-label="<?php esc_attr_e('Filter skills by plan', 'nibwp'); ?>">
                    <button type="button" class="nw-int-tab is-active" role="tab" aria-selected="true" data-tier="all">
                        <?php esc_html_e('All', 'nibwp'); ?>
                        <span class="nw-int-tab-count"><?php echo esc_html((string) count($skills)); ?></span>
                    </button>
                    <button type="button" class="nw-int-tab" role="tab" aria-selected="false" data-tier="free">
                        <?php esc_html_e('Free', 'nibwp'); ?>
                        <span class="nw-int-tab-count"><?php echo esc_html((string) $free_count); ?></span>
                    </button>
                    <button type="button" class="nw-int-tab" role="tab" aria-selected="false" data-tier="pro">
                        <?php esc_html_e('Pro Skills', 'nibwp'); ?>
                        <span class="nw-int-tab-count"><?php echo esc_html((string) $pro_count); ?></span>
                    </button>
                </div>

                <div class="nw-page-search nw-skill-bar__search" role="search">
                    <span class="nw-page-search__icon" aria-hidden="true">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="7" cy="7" r="4.5"/><path d="M10.5 10.5L14 14"/></svg>
                    </span>
                    <label for="nw-skill-search" class="screen-reader-text"><?php esc_html_e('Search skills', 'nibwp'); ?></label>
                    <input
                        type="search"
                        id="nw-skill-search"
                        class="nw-page-search__input"
                        placeholder="<?php esc_attr_e('Search skills…', 'nibwp'); ?>"
                        autocomplete="off"
                        spellcheck="false"
                    />
                    <button type="button" class="nw-page-search__clear" id="nw-skill-search-clear" aria-label="<?php esc_attr_e('Clear search', 'nibwp'); ?>" hidden>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                    </button>
                    <span class="nw-page-search__count" id="nw-skill-search-count" hidden></span>
                </div>

                <label for="nw-skill-sort" class="screen-reader-text"><?php esc_html_e('Sort skills', 'nibwp'); ?></label>
                <select id="nw-skill-sort" class="nw-sort-select" aria-label="<?php esc_attr_e('Sort skills', 'nibwp'); ?>">
                    <option value="default"><?php esc_html_e('Default', 'nibwp'); ?></option>
                    <option value="active"><?php esc_html_e('Switched on first', 'nibwp'); ?></option>
                    <option value="ready"><?php esc_html_e('Ready to use first', 'nibwp'); ?></option>
                    <option value="az"><?php esc_html_e('A–Z Sort', 'nibwp'); ?></option>
                </select>
            </div>

            <div class="nw-skill-grid" id="nw-skill-grid">
                <?php foreach ($skills as $skill):
                    $unlocked   = nibwp_skill_is_unlocked($skill['id']);
                    $enabled    = nibwp_skill_is_enabled($skill['id']);
                    $deps_met   = nibwp_skill_deps_met($skill);
                    $is_premium = !empty($skill['premium']);
                    $skill_hay  = strtolower(implode(' ', array_filter([
                        (string) ($skill['id']          ?? ''),
                        (string) ($skill['name']        ?? ''),
                        (string) ($skill['tagline']     ?? ''),
                        (string) ($skill['description'] ?? ''),
                        (string) ($skill['category']    ?? ''),
                        implode(' ', (array) ($skill['features'] ?? [])),
                        implode(' ', (array) ($skill['requires'] ?? [])),
                    ])));
                ?>
                    <div class="nw-skill-card <?php echo $is_premium ? 'is-premium' : ''; ?> <?php echo $enabled ? 'is-active' : ($unlocked ? 'is-unlocked' : 'is-locked'); ?>"
                         data-skill-id="<?php echo esc_attr((string) $skill['id']); ?>"
                         data-tier="<?php echo $is_premium ? 'pro' : 'free'; ?>"
                         data-name="<?php echo esc_attr((string) ($skill['name'] ?? $skill['id'])); ?>"
                         data-active="<?php echo $enabled ? '1' : '0'; ?>"
                         data-ready="<?php echo $unlocked ? '1' : '0'; ?>"
                         data-search="<?php echo esc_attr($skill_hay); ?>">
                        <?php if ($is_premium): ?>
                            <div class="nw-skill-card__premium-tag"><?php esc_html_e('PRO', domain: 'nibwp'); ?></div>
                        <?php endif; ?>

                        <div class="nw-skill-card__head">
                            <div class="nw-skill-card__icon">
                                <?php if (!empty($skill['icon'])): ?>
                                    <?php echo $skill['icon']; ?>
                                <?php else: ?>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                <?php endif; ?>
                            </div>
                            <div class="nw-skill-card__title">
                                <strong><?php echo esc_html((string) $skill['name']); ?></strong>
                                <span><?php echo esc_html((string) ($skill['tagline'] ?? '')); ?></span>
                            </div>
                        </div>

                        <?php if (!empty($skill['features'])): ?>
                            <ul class="nw-skill-card__features">
                                <?php foreach ((array) $skill['features'] as $feature): ?>
                                    <li><?php echo esc_html((string) $feature); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <div class="nw-skill-card__foot">
                            <div class="nw-skill-card__status">
                                <?php if (!$unlocked): ?>
                                    <span class="nibwp-badge is-muted">
                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-1px;margin-right:3px;"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                                        <?php esc_html_e('Locked', domain: 'nibwp'); ?>
                                    </span>
                                <?php elseif ($enabled): ?>
                                    <span class="nibwp-badge is-ok">
                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-1px;margin-right:3px;"><polyline points="20 6 9 17 4 12"/></svg>
                                        <?php esc_html_e('Active', domain: 'nibwp'); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="nibwp-badge is-brand"><?php esc_html_e('Ready', domain: 'nibwp'); ?></span>
                                <?php endif; ?>
                                <?php if (!$deps_met): ?>
                                    <span class="nibwp-badge is-warn">
                                        <?php printf(esc_html__('Requires %s', domain: 'nibwp'), esc_html(implode(', ', (array) $skill['requires']))); ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="nw-skill-card__action">
                                <?php if ($is_premium && !$unlocked): ?>
                                    <a href="https://nibwp.com/pro" target="_blank" rel="noopener" class="button button-primary">
                                        <?php
                                        $price = $skill['price'] ?? null;
                                        echo esc_html(__('Buy', 'nibwp') . ($price ? ' — €' . $price : ''));
                                        ?>
                                    </a>
                                <?php elseif ($unlocked): ?>
                                    <form method="post" class="nw-skill-toggle-form" style="margin:0;">
                                        <?php wp_nonce_field('nibwp_skill_toggle'); ?>
                                        <input type="hidden" name="skill_id" value="<?php echo esc_attr((string) $skill['id']); ?>">
                                        <input type="hidden" name="skill_state" value="<?php echo $enabled ? '0' : '1'; ?>">
                                        <button type="submit" name="nibwp_toggle_skill" class="nw-toggle-pill <?php echo $enabled ? 'is-on' : ''; ?>" aria-label="<?php esc_attr_e('Toggle skill', 'nibwp'); ?>">
                                            <span></span>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>


    <script>
    window.nibwpSkill = {
        restRoot: '<?php echo esc_js(rest_url('nibwp/v1/')); ?>',
        nonce: '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>'
    };
    </script>
    <script>
    (function(){
        /* License activate-form open */
        var openBtn = document.getElementById('nw-open-activate');
        var wrap = document.getElementById('nw-license-form-wrap');
        if (openBtn && wrap) {
            openBtn.addEventListener('click', function(){
                wrap.hidden = false;
                wrap.querySelector('input').focus();
            });
        }

        /* ── Search filter ── */
        var search      = document.getElementById('nw-skill-search');
        var searchClear = document.getElementById('nw-skill-search-clear');
        var searchCount = document.getElementById('nw-skill-search-count');
        var grid        = document.getElementById('nw-skill-grid');
        var cards       = grid ? grid.querySelectorAll('.nw-skill-card') : [];
        var currentQuery = '';
        var currentTier  = 'all';

        function applyFilter(){
            var visible = 0, total = 0;
            cards.forEach(function(card){
                // Total counts what the tab admits, so "2 / 3" means two of the
                // three on this tab rather than of everything installed.
                var tierOk = currentTier === 'all' || card.getAttribute('data-tier') === currentTier;
                if (!tierOk) { card.style.display = 'none'; return; }
                total++;
                var hay = (card.getAttribute('data-search') || '').toLowerCase();
                var show = currentQuery === '' || hay.indexOf(currentQuery) !== -1;
                card.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            if (searchCount) {
                if (currentQuery === '') {
                    searchCount.hidden = true;
                } else {
                    searchCount.hidden = false;
                    searchCount.textContent = visible + ' / ' + total;
                }
            }
        }
        if (search) {
            var debounceTimer = null;
            search.addEventListener('input', function(){
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(function(){
                    currentQuery = (search.value || '').trim().toLowerCase();
                    if (searchClear) searchClear.hidden = currentQuery === '';
                    applyFilter();
                }, 60);
            });
            search.addEventListener('keydown', function(e){
                if (e.key === 'Escape') {
                    search.value = '';
                    currentQuery = '';
                    if (searchClear) searchClear.hidden = true;
                    applyFilter();
                    search.blur();
                }
            });
        }
        /* ── Sort ──
           Reorders the grid in place, leaving the tab and the search alone.
           default = free packs first, then alphabetical, as rendered.
           active  = the ones already switched on float up.
           ready   = everything this licence unlocks, before what it does not.
           az      = plain alphabetical, numeric-aware. */
        var sortSelect = document.getElementById('nw-skill-sort');
        var defaultOrder = Array.prototype.slice.call(cards);
        function cardName(c){ return (c.getAttribute('data-name') || '').trim(); }
        function flagFirst(c, attr){ return c.getAttribute(attr) === '1' ? 0 : 1; }
        if (sortSelect && grid) {
            sortSelect.addEventListener('change', function(){
                var mode = sortSelect.value, order;
                if (mode === 'az') {
                    order = defaultOrder.slice().sort(function(a, b){
                        return cardName(a).localeCompare(cardName(b), undefined, { sensitivity: 'base', numeric: true });
                    });
                } else if (mode === 'active' || mode === 'ready') {
                    var attr = mode === 'active' ? 'data-active' : 'data-ready';
                    // Stable, so the default order survives inside each group.
                    order = defaultOrder.slice().sort(function(a, b){
                        return flagFirst(a, attr) - flagFirst(b, attr);
                    });
                } else {
                    order = defaultOrder;
                }
                order.forEach(function(c){ grid.appendChild(c); });
            });
        }

        /* ── Plan tabs ── */
        document.querySelectorAll('.nw-skill-bar .nw-int-tab').forEach(function(tab){
            tab.addEventListener('click', function(){
                document.querySelectorAll('.nw-skill-bar .nw-int-tab').forEach(function(t){
                    var on = t === tab;
                    t.classList.toggle('is-active', on);
                    t.setAttribute('aria-selected', on ? 'true' : 'false');
                });
                currentTier = tab.getAttribute('data-tier') || 'all';
                applyFilter();
            });
        });

        if (searchClear) {
            searchClear.addEventListener('click', function(){
                search.value = '';
                currentQuery = '';
                searchClear.hidden = true;
                applyFilter();
                search.focus();
            });
        }

        /* ── AJAX toggle ── */
        if (grid) {
            grid.addEventListener('submit', function(e){
                var form = e.target;
                if (!form.matches('.nw-skill-toggle-form')) return;
                e.preventDefault();
                var btn = form.querySelector('.nw-toggle-pill');
                var skillId = form.querySelector('input[name="skill_id"]').value;
                var enabled = !(btn.classList.contains('is-on'));
                if (btn) { btn.disabled = true; btn.classList.add('is-loading'); }
                fetch(window.nibwpSkill.restRoot + 'skills/toggle', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': window.nibwpSkill.nonce
                    },
                    body: JSON.stringify({ skill_id: skillId, enabled: enabled })
                })
                .then(function(r){ return r.json().catch(function(){ return { ok: false }; }); })
                .then(function(data){
                    if (btn) { btn.disabled = false; btn.classList.remove('is-loading'); }
                    if (!data || !data.ok) {
                        if (data && data.message) console.warn('NIBWP skill:', data.message);
                        return;
                    }
                    var cnt = document.getElementById('nw-skill-active-count');
                    if (cnt && typeof data.active_count === 'number') cnt.textContent = data.active_count;
                    btn.classList.toggle('is-on', !!data.enabled);
                    var stateField = form.querySelector('input[name="skill_state"]');
                    if (stateField) stateField.value = data.enabled ? '0' : '1';
                    var card = btn.closest('.nw-skill-card');
                    if (card) {
                        card.classList.toggle('is-active', !!data.enabled);
                        if (data.enabled) {
                            card.classList.remove('is-unlocked');
                        } else if (!card.classList.contains('is-locked')) {
                            card.classList.add('is-unlocked');
                        }
                    }
                })
                .catch(function(err){
                    if (btn) { btn.disabled = false; btn.classList.remove('is-loading'); }
                    console.warn('NIBWP skill toggle failed:', err);
                });
            });
        }
    })();
    </script>
    <?php
    nibwp_render_admin_footer();
}

/**
 * REST: toggle a skill on/off. Used by the AJAX toggle on the Skills page.
 */
add_action('rest_api_init', static function (): void {
    register_rest_route('nibwp/v1', '/skills/toggle', [
        'methods'             => 'POST',
        'permission_callback' => static function (): bool {
            return current_user_can('manage_options');
        },
        'args' => [
            'skill_id' => ['type' => 'string',  'required' => true],
            'enabled'  => ['type' => 'boolean', 'required' => true],
        ],
        'callback' => static function (\WP_REST_Request $req): \WP_REST_Response {
            $id = sanitize_key((string) $req->get_param('skill_id'));
            if ($id === '' || !function_exists('nibwp_skill_get')) {
                return new \WP_REST_Response(['ok' => false, 'message' => 'Unknown skill'], 404);
            }
            $skill = nibwp_skill_get($id);
            if ($skill === null) {
                return new \WP_REST_Response(['ok' => false, 'message' => 'Unknown skill'], 404);
            }
            if (!nibwp_skill_is_unlocked($id)) {
                return new \WP_REST_Response(['ok' => false, 'message' => 'Skill is locked — buy a license to unlock.'], 403);
            }
            // Deps are a warning, not a gate — the toggle reflects the user's
            // active/inactive choice regardless of whether the plugin is detected.
            $enabled = (bool) $req->get_param('enabled');
            nibwp_skill_set_enabled($id, $enabled);
            return new \WP_REST_Response([
                'ok'           => true,
                'skill_id'     => $id,
                'enabled'      => (bool) nibwp_skill_is_enabled($id),
                'active_count' => function_exists('nibwp_skills_stats') ? (int) (nibwp_skills_stats()['active'] ?? 0) : 0,
            ], 200);
        },
    ]);
});
