<?php

declare(strict_types=1);

/**
 * Dashboard connect page — creates application passwords and shows MCP config samples.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Handle the enable/disable AI Abilities toggle submission.
 * Returns true on save, null when no submission.
 */
function nibwp_handle_toggle_enabled(): ?bool
{
    // Gate on the settings nonce field rather than the submit button name, because
    // programmatic form.submit() calls (from the production-warning modal) do not
    // include the submit button's name/value in the POST payload.
    if (empty($_POST['_wpnonce']) || !wp_verify_nonce((string) $_POST['_wpnonce'], 'nibwp_settings')) {
        return null;
    }
    if (!current_user_can('manage_options')) {
        return null;
    }

    $enabled = ($_POST['nibwp_ai_abilities_enabled'] ?? null) !== null;
    if (
        $enabled
        && function_exists('nibwp_get_mcp_dependency_error')
        && nibwp_get_mcp_dependency_error() !== null
    ) {
        return false;
    }

    // Store as string '1' / '' to match the REST onboarder writer
    // (nibwp_is_enabled() checks `$value !== '1' && $value !== true`).
    update_option('nibwp_ai_abilities_enabled', $enabled ? '1' : '');
    if ($enabled) {
        update_option('nibwp_ai_abilities_domain', (string) wp_parse_url(home_url(), PHP_URL_HOST));
        return true;
    }
    delete_option('nibwp_ai_abilities_domain');
    return true;
}

function nibwp_render_enable_toggle(): void
{
    $enabled = nibwp_is_enabled();
    $dependency_error = function_exists('nibwp_get_mcp_dependency_error')
        ? nibwp_get_mcp_dependency_error()
        : null;
    $toggle_disabled = $dependency_error !== null && !$enabled;
    $submit_attributes = $toggle_disabled ? ['disabled' => 'disabled'] : [];
    $looks_production = nibwp_looks_like_production();
    ?>
    <h2 class="nibwp-step-heading">
        <span class="nibwp-step-badge">1</span>
        <?php esc_html_e('Enable AI Abilities', domain: 'nibwp'); ?>
        <span class="nw-tooltip" data-tip="<?php esc_attr_e('Activates the MCP server endpoint so AI agents (Claude, ChatGPT, Cursor, etc.) can connect and control WordPress.', domain: 'nibwp'); ?>">?</span>
    </h2>
    <form method="post" action="" id="nibwp-settings-form" style="margin: 16px 0 0;">
        <?php wp_nonce_field('nibwp_settings'); ?>
        <label style="display:flex; align-items:center; gap:10px; font-size:16px; font-weight:600; color:var(--nw-text); margin:0 0 12px;">
            <input type="checkbox" name="nibwp_ai_abilities_enabled" value="1" id="nibwp-enable-checkbox" <?php checked(
                checked: $enabled,
                current: true,
            ); ?> <?php disabled($toggle_disabled); ?> />
            <span><?php esc_html_e('Turn on AI Abilities for this site', domain: 'nibwp'); ?></span>
        </label>
        <p class="description" style="margin:0 0 8px;">
            <strong style="color:#d63638;"><?php esc_html_e('Security note:', domain: 'nibwp'); ?></strong>
            <?php esc_html_e(
                'When enabled, AI agents can execute PHP code and perform filesystem operations on this site. Always keep backups.',
                domain: 'nibwp',
            ); ?>
        </p>
        <p class="description" style="margin:0 0 14px;">
            <?php esc_html_e(
                'Use NIBWP with a capable AI model and set your client to ask for confirmation before every action. Read what the agent is about to do before approving.',
                domain: 'nibwp',
            ); ?>
        </p>
        <?php submit_button(
            text: __('Save Settings', domain: 'nibwp'),
            type: 'primary',
            name: 'nibwp_submit',
            wrap: false,
            other_attributes: $submit_attributes,
        ); ?>
    </form>
    <!-- Custom styled confirm modal (replaces native confirm()) -->
    <div class="nw-confirm" id="nw-enable-confirm" role="dialog" aria-modal="true" aria-hidden="true">
        <div class="nw-confirm__backdrop"></div>
        <div class="nw-confirm__panel" role="document">
            <div class="nw-confirm__head is-<?php echo $looks_production ? 'danger' : 'warn'; ?>">
                <div class="nw-confirm__icon">
                    <?php if ($looks_production): ?>
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    <?php else: ?>
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    <?php endif; ?>
                </div>
                <h3 id="nw-enable-confirm-title">
                    <?php echo $looks_production
                        ? esc_html__('This looks like a production site', domain: 'nibwp')
                        : esc_html__('Heads up — read this before enabling', domain: 'nibwp'); ?>
                </h3>
            </div>
            <div class="nw-confirm__body">
                <?php if ($looks_production): ?>
                    <p>
                        <?php esc_html_e('The plugin can stay installed here, but AI Abilities are not meant for live sites. Enable them only on a staging or development copy of this site.', domain: 'nibwp'); ?>
                    </p>
                    <ul class="nw-confirm__bullets">
                        <li><?php esc_html_e('Recommended: enable on a staging clone, make changes there, then deploy normally.', domain: 'nibwp'); ?></li>
                        <li><?php esc_html_e('AI agents can execute PHP and modify files — irreversible damage is possible.', domain: 'nibwp'); ?></li>
                        <li><?php esc_html_e('Keep AI Abilities OFF on production servers.', domain: 'nibwp'); ?></li>
                    </ul>
                <?php else: ?>
                    <p>
                        <?php esc_html_e('AI agents will be able to execute PHP code and access the filesystem. Always keep recent backups.', domain: 'nibwp'); ?>
                    </p>
                <?php endif; ?>
            </div>
            <div class="nw-confirm__footer">
                <button type="button" class="button button-secondary" id="nw-enable-confirm-cancel">
                    <?php esc_html_e('Cancel', domain: 'nibwp'); ?>
                </button>
                <button type="button" class="button button-primary nw-confirm__continue" id="nw-enable-confirm-continue">
                    <?php echo $looks_production
                        ? esc_html__('Continue anyway', domain: 'nibwp')
                        : esc_html__('Yes, enable it', domain: 'nibwp'); ?>
                </button>
            </div>
        </div>
    </div>

    <script>
    (function () {
        var form    = document.getElementById('nibwp-settings-form');
        var cb      = document.getElementById('nibwp-enable-checkbox');
        var modal   = document.getElementById('nw-enable-confirm');
        var btnYes  = document.getElementById('nw-enable-confirm-continue');
        var btnNo   = document.getElementById('nw-enable-confirm-cancel');
        var backdrop = modal.querySelector('.nw-confirm__backdrop');
        if (!form || !cb || !modal) return;
        var confirmed = false;

        function open()  { modal.classList.add('is-open');    modal.setAttribute('aria-hidden', 'false'); document.body.classList.add('nw-onb-locked'); setTimeout(function(){ btnNo && btnNo.focus(); }, 60); }
        function close() { modal.classList.remove('is-open'); modal.setAttribute('aria-hidden', 'true');  document.body.classList.remove('nw-onb-locked'); }

        form.addEventListener('submit', function (e) {
            if (!cb.checked || cb.defaultChecked || confirmed) return;
            e.preventDefault();
            open();
        });
        btnYes.addEventListener('click', function () {
            confirmed = true;
            close();
            // Hidden submit-button marker (defensive — the server doesn't
            // require it, but keeping it covers legacy handler variants).
            if (!form.querySelector('input[name="nibwp_submit"]')) {
                var h1 = document.createElement('input');
                h1.type = 'hidden'; h1.name = 'nibwp_submit'; h1.value = '1';
                form.appendChild(h1);
            }
            // CRITICAL: programmatic form.submit() in some browsers / under
            // some events DOES NOT include the checked checkbox value in the
            // serialized POST when the checkbox is the only carrier of that
            // field. Inject a hidden mirror so POST always has the value.
            if (!form.querySelector('input[type="hidden"][name="nibwp_ai_abilities_enabled"]')) {
                var h2 = document.createElement('input');
                h2.type = 'hidden';
                h2.name = 'nibwp_ai_abilities_enabled';
                h2.value = '1';
                form.appendChild(h2);
            }
            form.submit();
        });
        btnNo.addEventListener('click', close);
        backdrop.addEventListener('click', close);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('is-open')) close();
        });
    })();
    </script>
    <?php
}

/**
 * Render the production-site warning banner above the enable toggle.
 *
 * Shown only when: AI Abilities are currently enabled AND the site looks like production
 * AND the current user has not dismissed the warning.
 */
function nibwp_render_production_warning(): void
{
    if (!nibwp_is_enabled()) {
        return;
    }
    if (!nibwp_looks_like_production()) {
        return;
    }
    if (nibwp_production_warning_dismissed()) {
        return;
    }
    ?>
    <div class="nibwp-production-warning" role="alert">
        <svg class="nibwp-production-warning__icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
            <line x1="12" y1="9" x2="12" y2="13"/>
            <line x1="12" y1="17" x2="12.01" y2="17"/>
        </svg>
        <div class="nibwp-production-warning__body">
            <strong class="nibwp-production-warning__title"><?php esc_html_e('This looks like a production site.', domain: 'nibwp'); ?></strong>
            <span class="nibwp-production-warning__desc"><?php esc_html_e(
                'Keeping the plugin installed here is fine, but AI Abilities should only be active on a staging or development copy. Make your changes there, then deploy the result the regular way. On production, keep AI Abilities off.',
                domain: 'nibwp',
            ); ?></span>
        </div>
        <form method="post" class="nibwp-production-warning__form">
            <?php wp_nonce_field('nibwp_dismiss_production_warning'); ?>
            <button type="submit"
                    name="nibwp_dismiss_production_warning"
                    class="nibwp-production-warning__dismiss">
                <?php esc_html_e('Dismiss', domain: 'nibwp'); ?>
            </button>
        </form>
    </div>
    <?php
}

/**
 * Compute the default MCP server name from the current site host.
 *
 * Capped at 25 characters total ("nibwp-" prefix + up to 16 chars of host slug)
 * because some MCP clients reject longer server names. Used as the placeholder default
 * when no name has been saved by the user.
 */
function nibwp_get_mcp_server_name_default(): string
{
    /** @var string $site_host */
    $site_host = wp_parse_url(home_url(), PHP_URL_HOST) ?? 'wordpress';
    $site_slug = (string) preg_replace(pattern: '/^www\./', replacement: '', subject: $site_host);
    $site_slug = (string) preg_replace(pattern: '/[^a-z0-9-]+/', replacement: '-', subject: strtolower($site_slug));
    $site_slug = trim($site_slug, characters: '-');
    $site_slug = substr($site_slug, offset: 0, length: 16);
    $site_slug = rtrim($site_slug, characters: '-');
    return 'nibwp-' . $site_slug;
}

/**
 * Handle the "use existing password" form submission.
 *
 * Returns the pasted plaintext value (only for the current request — never persisted),
 * a WP_Error on validation failure, or null when no submission.
 *
 * @return string|WP_Error|null
 */
function nibwp_handle_use_existing_password()
{
    if (($_POST['nibwp_use_existing_password'] ?? null) === null) {
        return null;
    }

    if (!current_user_can('manage_options')) {
        return new WP_Error('forbidden', __(
            'You do not have permission to use application passwords.',
            domain: 'nibwp',
        ));
    }

    check_admin_referer('nibwp_use_existing_password');

    $raw = $_POST['nibwp_existing_password'] ?? '';
    $value = is_string($raw) ? trim($raw) : '';
    if ($value === '') {
        return new WP_Error('empty', __('Paste the application password value before submitting.', domain: 'nibwp'));
    }
    if (strlen($value) < 16) {
        return new WP_Error('too_short', __(
            'That does not look like an application password. WordPress application passwords are at least 16 characters long.',
            domain: 'nibwp',
        ));
    }
    return $value;
}

/**
 * Handle the create-password form submission.
 * Returns the plaintext password on success, a WP_Error on failure, or null when no submission.
 *
 * @return string|WP_Error|null
 */
function nibwp_handle_create_password()
{
    if (($_POST['nibwp_create_password'] ?? null) === null) {
        return null;
    }

    if (!current_user_can('manage_options')) {
        return new WP_Error('forbidden', __(
            'You do not have permission to create application passwords.',
            domain: 'nibwp',
        ));
    }

    check_admin_referer('nibwp_create_password');

    $status = nibwp_app_passwords_status();
    if (!$status['available']) {
        return new WP_Error('not_available', $status['message']);
    }

    $user_id = get_current_user_id();
    $raw_name = $_POST['nibwp_password_name'] ?? '';
    $input_name = is_string($raw_name) ? trim($raw_name) : '';
    $app_name = $input_name !== '' ? 'NIBWP: ' . $input_name : 'NIBWP';

    // Avoid duplicate names — append a counter if one already exists.
    $existing = WP_Application_Passwords::get_user_application_passwords($user_id);
    $names = array_column($existing, 'name');
    if (in_array(needle: $app_name, haystack: $names, strict: true)) {
        $i = 2;
        while (in_array(needle: $app_name . ' ' . $i, haystack: $names, strict: true)) {
            $i++;
        }
        $app_name = $app_name . ' ' . $i;
    }

    $result = WP_Application_Passwords::create_new_application_password($user_id, ['name' => $app_name]);

    if (is_wp_error($result)) {
        return $result;
    }

    // $result[0] is the plaintext password.
    return $result[0];
}

/**
 * Handle the revoke-password form submission. Redirects on success.
 * Called from admin_init so headers have not been sent yet.
 */
function nibwp_handle_revoke_password(): void
{
    if (($_POST['nibwp_revoke_password'] ?? null) === null) {
        return;
    }

    if (!current_user_can('manage_options')) {
        return;
    }

    $uuid = $_POST['nibwp_revoke_uuid'] ?? '';
    if (!is_string($uuid) || $uuid === '') {
        return;
    }

    check_admin_referer('nibwp_revoke_password_' . $uuid);

    $user_id = get_current_user_id();
    WP_Application_Passwords::delete_application_password($user_id, $uuid);

    wp_safe_redirect(admin_url('admin.php?page=nibwp-connect&nibwp_result=revoked'));
    exit();
}

/**
 * Return all application passwords for the current user whose name begins with "NIBWP".
 *
 * @return array<int, array<string, mixed>>
 */
function nibwp_get_mcp_passwords(): array
{
    $user_id = get_current_user_id();
    $all = WP_Application_Passwords::get_user_application_passwords($user_id);
    return array_values(array_filter($all, static fn($item) => str_starts_with($item['name'], 'NIBWP')));
}

/**
 * Render a single password row for the passwords table.
 *
 * @param array<string, mixed> $pw        Password item from WP_Application_Passwords.
 * @param string               $dt_format Date/time format string.
 */
function nibwp_render_password_row(array $pw, string $dt_format): void
{
    $uuid = (string) ($pw['uuid'] ?? '');
    $name = (string) ($pw['name'] ?? '');
    $created_date = ($pw['created'] ?? null) !== null ? wp_date($dt_format, (int) $pw['created']) : false;
    $created = $created_date !== false ? $created_date : __('Unknown', domain: 'nibwp');
    $last_used_date = ($pw['last_used'] ?? null) !== null ? wp_date($dt_format, (int) $pw['last_used']) : false;
    $last_used = $last_used_date !== false ? $last_used_date : __('Never', domain: 'nibwp');
    $revoke_nonce = (string) wp_create_nonce('nibwp_revoke_password_' . $uuid);
    ?>
    <tr>
        <td><strong><?php echo esc_html($name); ?></strong></td>
        <td><?php echo esc_html($created); ?></td>
        <td><?php echo esc_html($last_used); ?></td>
        <td>
            <form method="post" style="margin:0;" onsubmit="return confirm('<?php echo
                esc_js(__('Revoke this password? Any clients using it will lose access.', domain: 'nibwp'))
            ; ?>');">
                <input type="hidden" name="nibwp_revoke_uuid" value="<?php echo esc_attr($uuid); ?>" />
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($revoke_nonce); ?>" />
                <button type="submit" name="nibwp_revoke_password" class="button button-small nibwp-revoke-btn">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-1px;margin-right:4px;"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
                    <?php esc_html_e('Revoke', 'nibwp'); ?>
                </button>
            </form>
        </td>
    </tr>
    <?php
}

/**
 * Render the "Step 2 — Application Password" card.
 *
 * Just the generate button (with a collapsible name input) and a success notice after generation.
 * The list of existing passwords lives in the separate manage section at the bottom of the page.
 */
function nibwp_render_password_step(
    ?string $new_password,
    ?string $existing_password = null,
    ?WP_Error $existing_error = null,
): void {
    $pw_status = nibwp_app_passwords_status();
    $has_existing = nibwp_get_mcp_passwords() !== [];
    $existing_section_open = $existing_password !== null || $existing_error !== null;
    ?>
    <?php // No step heading here either: the tabs above name this method, and
          // the step is numbered once, above them. ?>
    <p class="description" style="margin:0 0 12px;">
        <?php esc_html_e(
            'Generate an application password that your AI client will use to authenticate with WordPress. The password is embedded into the connection text in step 3.',
            domain: 'nibwp',
        ); ?>
    </p>

    <?php if (!$pw_status['available']): ?>
        <div class="notice notice-error inline nibwp-apppass" style="margin:12px 0 16px;">
            <style>
                .nibwp-apppass__wrap { flex: 1 1 auto; min-width: 0; }
                .nibwp-apppass__msg { margin: 0; }
                .nibwp-apppass-why { margin: 6px 0 0; }
                .nibwp-apppass-why > summary { display: inline-flex; align-items: center; gap: 5px; cursor: pointer; color: #2271b1; font-weight: 600; list-style: none; }
                .nibwp-apppass-why > summary::-webkit-details-marker { display: none; }
                .nibwp-apppass-why > summary::after { content: "\25be"; font-size: 11px; line-height: 1; }
                .nibwp-apppass-why[open] > summary::after { content: "\25b4"; }
                .nibwp-apppass-why > summary:hover { text-decoration: underline; }
                .nibwp-apppass-why[open] .nibwp-apppass__on-closed { display: none; }
                .nibwp-apppass-why:not([open]) .nibwp-apppass__on-open { display: none; }
                .nibwp-apppass-why[open] { display: flex; flex-direction: column; }
                .nibwp-apppass-why[open] > summary { order: 2; margin-top: 8px; }
                .nibwp-apppass__body { margin: 10px 0 0; font-weight: 400; }
                .nibwp-apppass__body p { margin: 0 0 8px; }
                .nibwp-apppass__body p:last-child { margin-bottom: 0; }
                .nibwp-apppass__body ul { margin: 4px 0 8px; padding-left: 20px; list-style: disc outside; }
                .nibwp-apppass__body li { margin: 0 0 4px; padding-left: 2px; }
                .nibwp-apppass__body pre { background: #f6f7f7; border: 1px solid #c3c4c7; padding: 8px 12px; margin: 6px 0 8px; font-size: 13px; border-radius: 3px; }
            </style>
            <div class="nibwp-apppass__wrap">
            <p class="nibwp-apppass__msg"><strong><?php echo esc_html($pw_status['message']); ?></strong></p>
            <details class="nibwp-apppass-why">
                <summary>
                    <span class="nibwp-apppass__on-closed"><?php esc_html_e('Why is this happening?', domain: 'nibwp'); ?></span>
                    <span class="nibwp-apppass__on-open"><?php esc_html_e('Show less', domain: 'nibwp'); ?></span>
                </summary>
                <div class="nibwp-apppass__body">
                    <?php if ($pw_status['reason'] === 'unsupported'): ?>
                        <?php if (nibwp_likely_local_http()): ?>
                            <p><?php esc_html_e(
                                'This site is on a local hostname over HTTP. Add this line to your wp-config.php (above the "/* That\'s all" comment), then reload:',
                                domain: 'nibwp',
                            ); ?></p>
                            <pre>define('WP_ENVIRONMENT_TYPE', 'local');</pre>
                        <?php endif; ?>
                        <p><?php esc_html_e(
                            'An Application Password is a permanent credential your AI client sends on every request. WordPress core refuses to issue one over an insecure connection, because anyone able to read the traffic would capture it. So the feature stays off until the site is served over HTTPS.',
                            domain: 'nibwp',
                        ); ?></p>
                        <p><strong><?php esc_html_e('To enable:', domain: 'nibwp'); ?></strong></p>
                        <ul>
                            <li><?php esc_html_e('Serve this site over HTTPS (install an SSL certificate — most hosts offer free Let\'s Encrypt).', domain: 'nibwp'); ?></li>
                            <li><?php esc_html_e('Local / staging box on http:// on purpose? Add the line above to wp-config.php to mark the site as a local environment.', domain: 'nibwp'); ?></li>
                        </ul>
                    <?php else: ?>
                        <p><?php esc_html_e(
                            'This site supports Application Passwords, but something is switching them off on purpose — almost always a security plugin, or a line of code that filters the feature to "disabled". WordPress will not issue a password while that override is active.',
                            domain: 'nibwp',
                        ); ?></p>
                        <p><strong><?php esc_html_e('Where to re-enable it:', domain: 'nibwp'); ?></strong></p>
                        <ul>
                            <li><?php esc_html_e('Wordfence → Login Security → Settings → uncheck "Disable WordPress application passwords".', domain: 'nibwp'); ?></li>
                            <li><?php esc_html_e('Solid Security (iThemes) → WordPress Tweaks → allow Application Passwords.', domain: 'nibwp'); ?></li>
                            <li><?php esc_html_e('All-In-One WP Security → find the Application Passwords toggle and turn it on.', domain: 'nibwp'); ?></li>
                            <li><?php esc_html_e('Custom code / must-use plugin: remove any wp_is_application_passwords_available filter or the WP_APPLICATION_PASSWORDS constant set to false.', domain: 'nibwp'); ?></li>
                        </ul>
                        <p><?php esc_html_e(
                            'Quick check: WordPress admin → Users → your profile → the "Application Passwords" section. If it is missing or blocked there too, the override is site-wide (not just NIBWP).',
                            domain: 'nibwp',
                        ); ?></p>
                    <?php endif; ?>
                </div>
            </details>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($new_password !== null): ?>
        <div class="notice notice-success inline" style="margin:8px 0 16px;">
            <p style="margin:0 0 8px;"><?php esc_html_e(
                'Application password generated. It is now embedded in the connection text in step 3. Save it somewhere safe: it will not be shown in full again.',
                domain: 'nibwp',
            ); ?></p>
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <code id="nibwp-new-pw-value" style="font-size:14px; font-weight:600; padding:6px 10px; background:#fff; border:1px solid #c3c4c7; border-radius:3px;"><?php echo
                    esc_html($new_password)
                ; ?></code>
                <button type="button" class="nibwp-btn-ghost" onclick="nibwpCopy('nibwp-new-pw-value', this)">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                    <span class="nibwp-btn__label"><?php esc_html_e('Copy password only', domain: 'nibwp'); ?></span>
                </button>
            </div>
        </div>
    <?php elseif ($existing_password !== null): ?>
        <div class="notice notice-success inline" style="margin:8px 0 16px;">
            <p style="margin:0;"><?php esc_html_e(
                'Password accepted. It is now embedded in the connection text in step 3.',
                domain: 'nibwp',
            ); ?></p>
        </div>
    <?php endif; ?>

    <form method="post" style="margin: 0;">
        <?php wp_nonce_field('nibwp_create_password'); ?>
        <?php if (!$has_existing): ?>
            <p style="margin:0 0 10px;">
                <button
                    type="button"
                    class="nibwp-btn-ghost"
                    id="nibwp-password-name-toggle"
                    aria-expanded="false"
                    aria-controls="nibwp-password-name-field"
                    onclick="nibwpTogglePasswordName(this)"
                ><?php esc_html_e('Customize password name (optional)', domain: 'nibwp'); ?></button>
            </p>
        <?php endif; ?>
        <div
            id="nibwp-password-name-field"
            <?php echo $has_existing ? '' : 'hidden'; ?>
            style="margin: 0 0 12px; <?php echo $has_existing ? '' : 'display:none;'; ?>"
        >
            <label for="nibwp-password-name" style="display:block; margin-bottom:4px;">
                <strong><?php esc_html_e('Name', domain: 'nibwp'); ?></strong>
            </label>
            <input
                type="text"
                id="nibwp-password-name"
                name="nibwp_password_name"
                placeholder="<?php esc_attr_e('e.g. Cursor on laptop, Claude Desktop', domain: 'nibwp'); ?>"
                style="width:300px;"
                class="regular-text"
                maxlength="70"
            />
            <p class="description" style="margin-top:4px;">
                <?php esc_html_e(
                    'A label to identify this credential later. Leave blank to use "NIBWP".',
                    domain: 'nibwp',
                ); ?>
            </p>
        </div>
        <button
            type="submit"
            name="nibwp_create_password"
            class="button button-primary"
            <?php echo !$pw_status['available'] ? 'disabled' : ''; ?>>
            <?php echo
                $has_existing
                    ? esc_html__('Generate another application password', domain: 'nibwp')
                    : esc_html__('Generate application password', domain: 'nibwp')
            ; ?>
        </button>
    </form>

    <p style="margin:14px 0 4px;">
        <button
            type="button"
            class="nibwp-btn-ghost nibwp-btn-ghost--small"
            id="nibwp-use-existing-toggle"
            aria-expanded="<?php echo $existing_section_open ? 'true' : 'false'; ?>"
            aria-controls="nibwp-use-existing-field"
            onclick="nibwpToggleUseExisting(this)"
        ><?php esc_html_e('I already have an application password', domain: 'nibwp'); ?></button>
    </p>
    <div
        id="nibwp-use-existing-field"
        <?php echo $existing_section_open ? '' : 'hidden'; ?>
        style="margin:6px 0 0; <?php echo $existing_section_open ? '' : 'display:none;'; ?>"
    >
        <form method="post" style="margin:0;">
            <?php wp_nonce_field('nibwp_use_existing_password'); ?>
            <label for="nibwp-existing-password" style="display:block; margin-bottom:4px;">
                <strong><?php esc_html_e('Paste the password value', domain: 'nibwp'); ?></strong>
            </label>
            <input
                type="text"
                id="nibwp-existing-password"
                name="nibwp_existing_password"
                placeholder="xxxx xxxx xxxx xxxx xxxx xxxx"
                style="width:340px; font-family:monospace;"
                class="regular-text"
                autocomplete="off"
            />
            <button type="submit" name="nibwp_use_existing_password" class="button">
                <?php esc_html_e('Use this password', domain: 'nibwp'); ?>
            </button>
            <?php if ($existing_error !== null): ?>
                <div class="notice notice-error inline" style="margin:8px 0 0;">
                    <p style="margin:0;"><?php echo esc_html($existing_error->get_error_message()); ?></p>
                </div>
            <?php endif; ?>
            <p class="description" style="margin-top:4px;">
                <?php esc_html_e(
                    'For reusing an application password you already saved (e.g. from a password manager). It is used only to fill the connection text and never stored on this site.',
                    domain: 'nibwp',
                ); ?>
            </p>
        </form>
    </div>
    <?php
}

/**
 * Render the "Manage existing application passwords" collapsible section at the bottom of the page.
 *
 * Only meaningful when at least one NIBWP-tagged password exists. Hosts the list with revoke
 * buttons. Used both when AI Abilities are enabled (revoke + create lives elsewhere) and when
 * disabled (revoke only).
 */
function nibwp_render_manage_passwords_section(bool $allow_create_hint = true): void
{
    $mcp_passwords = nibwp_get_mcp_passwords();
    if ($mcp_passwords === []) {
        return;
    }

    $dt_format = nibwp_get_datetime_format('Y-m-d H:i');
    $count = count($mcp_passwords);
    $open_by_default = $count <= 3;
    /* translators: %d: count of existing application passwords */
    $summary = sprintf(
        _n(
            single: 'Manage existing application password (%d)',
            plural: 'Manage existing application passwords (%d)',
            number: $count,
            domain: 'nibwp',
        ),
        $count,
    );
    ?>
    <details class="nibwp-manage-passwords"<?php echo $open_by_default ? ' open' : ''; ?>>
        <summary class="nibwp-manage-passwords-summary">
            <?php echo esc_html($summary); ?>
        </summary>
        <div class="nibwp-manage-passwords-body">
            <?php if (!$allow_create_hint): ?>
                <p class="description" style="margin:0 0 12px;">
                    <?php esc_html_e(
                        'AI Abilities are disabled. These credentials remain valid for WordPress authentication, but the NIBWP MCP endpoint will reject requests until AI Abilities are turned back on.',
                        domain: 'nibwp',
                    ); ?>
                </p>
            <?php endif; ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Name', domain: 'nibwp'); ?></th>
                        <th style="width:180px;"><?php esc_html_e('Created', domain: 'nibwp'); ?></th>
                        <th style="width:180px;"><?php esc_html_e('Last Used', domain: 'nibwp'); ?></th>
                        <th style="width:140px;"><?php esc_html_e('Actions', domain: 'nibwp'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mcp_passwords as $pw): ?>
                        <?php nibwp_render_password_row($pw, $dt_format); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </details>
    <?php
}

/**
 * Build the paste-to-agent paragraph displayed in Option A of the Connect section.
 *
 * Returns a plain-text block the user can copy and paste into their AI client / agent.
 * The MCP server name uses the same placeholder as the JSON snippets so the live JS
 * preview can swap it in without re-rendering the page.
 */
function nibwp_build_paste_to_agent_paragraph(
    string $rest_url,
    string $username,
    string $display_password,
    string $name_placeholder = '__NIBWP_MCP_NAME__',
    ?string $password_placeholder = null,
): string {
    $password_value = $password_placeholder ?? $display_password;
    $lines = [
        'I want to add this WordPress site as an MCP server to this AI client.',
        '',
        'Connection details:',
        '- Server URL: ' . $rest_url,
        '- Username: ' . $username,
        '- Application password: ' . $password_value,
        '- Server name to use in the config: ' . $name_placeholder,
        '- Transport: @automattic/mcp-wordpress-remote via npx',
        '',
        'Setup rules:',
        '- Pass credentials ONLY as env vars: WP_API_URL, WP_API_USERNAME, WP_API_PASSWORD. Do NOT use CLI flags like --url or --password (the package ignores them).',
        '- args array must be exactly ["-y", "@automattic/mcp-wordpress-remote@latest"].'
            . (
                nibwp_likely_self_signed_https()
                    ? "\n"
                    . '- Also set NODE_TLS_REJECT_UNAUTHORIZED="0" in env (this site uses a local self-signed TLS certificate).'
                    : ''
            ),
        '',
        'Don\'t ask me to confirm choices already specified above. After writing the config, restart or reload the MCP session (most clients require it), then verify by listing the server\'s tools. If it fails, show me the stderr from the npx process before proposing changes.',
        '',
        'If you cannot modify the config of this AI client from here, tell me to expand "Need the JSON config for a specific client?" on the NIBWP Configuration page and copy the snippet manually.',
    ];

    return implode("\n", $lines);
}

/**
 * Build the npx server config array shared across multiple MCP clients.
 *
 * @param string $rest_url        MCP REST endpoint URL.
 * @param string $username        Current WordPress username.
 * @param string $display_password Plaintext password or placeholder.
 * @return array{command: string, args: list<string>, env: array<string, string>}
 */
function nibwp_build_npx_server(string $rest_url, string $username, string $display_password): array
{
    $env = [
        'WP_API_URL' => $rest_url,
        'WP_API_USERNAME' => $username,
        'WP_API_PASSWORD' => $display_password,
    ];
    if (nibwp_likely_self_signed_https()) {
        $env['NODE_TLS_REJECT_UNAUTHORIZED'] = '0';
    }
    return [
        'command' => 'npx',
        'args' => ['-y', '@automattic/mcp-wordpress-remote@latest'],
        'env' => $env,
    ];
}

/**
 * Build a minimal STORE (uncompressed) zip from [name => contents]. No ext-zip
 * required — uses crc32() + pack(), so the .mcpb download works on every host.
 * ponytail: store-only + no zip64; fine for our 2 tiny text files.
 *
 * @param array<string, string> $files
 */
function nibwp_zip_store_bytes(array $files): string
{
    $local = '';
    $central = '';
    $offset = 0;
    foreach ($files as $name => $data) {
        $crc = crc32($data) & 0xFFFFFFFF;
        $len = strlen($data);
        $nameLen = strlen($name);
        $lfh = "PK\x03\x04" . pack('v', 20) . pack('v', 0) . pack('v', 0)
            . pack('v', 0) . pack('v', 0)                       // mod time / date = 0
            . pack('V', $crc) . pack('V', $len) . pack('V', $len)
            . pack('v', $nameLen) . pack('v', 0) . $name;
        $local .= $lfh . $data;
        $central .= "PK\x01\x02" . pack('v', 20) . pack('v', 20) . pack('v', 0) . pack('v', 0)
            . pack('v', 0) . pack('v', 0)
            . pack('V', $crc) . pack('V', $len) . pack('V', $len)
            . pack('v', $nameLen) . pack('v', 0) . pack('v', 0)
            . pack('v', 0) . pack('v', 0) . pack('V', 0)
            . pack('V', $offset) . $name;
        $offset += strlen($lfh) + $len;
    }
    $count = count($files);
    return $local . $central . "PK\x05\x06" . pack('v', 0) . pack('v', 0)
        . pack('v', $count) . pack('v', $count)
        . pack('V', strlen($central)) . pack('V', strlen($local)) . pack('v', 0);
}

/**
 * Build a Claude Desktop .mcpb bundle (a zip with manifest.json) for THIS site,
 * with the user's credentials embedded, returned as a base64 data URI so the
 * download needs no server endpoint and the password never round-trips.
 * Empty string only when the password is still the placeholder.
 */
function nibwp_build_mcpb_data_uri(string $rest_url, string $username, string $display_password): string
{
    if (hash_equals('YOUR-APP-PASSWORD', $display_password)) {
        return '';
    }
    $server = nibwp_build_npx_server($rest_url, $username, $display_password);
    $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    $manifest = [
        'manifest_version' => '0.3',
        'name'             => 'nibwp-' . preg_replace('/[^a-z0-9.-]+/i', '-', $host),
        'display_name'     => 'NIBWP — ' . $host,
        'version'          => '1.0.0',
        'description'      => 'Control ' . $host . ' with your AI assistant through NIBWP (Model Context Protocol).',
        'author'           => ['name' => 'NIBWP', 'url' => 'https://nibwp.com'],
        'server'           => [
            'type'        => 'node',
            'entry_point' => 'server/index.js',
            'mcp_config'  => [
                'command' => $server['command'],
                'args'    => $server['args'],
                'env'     => $server['env'],
            ],
        ],
    ];

    $bytes = nibwp_zip_store_bytes([
        'manifest.json'   => (string) wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        'server/index.js' => "// Placeholder entry point. NIBWP launches @automattic/mcp-wordpress-remote via npx\n// using the manifest's server.mcp_config (command/args/env). Nothing runs from here.\n",
    ]);
    return 'data:application/octet-stream;base64,' . base64_encode($bytes);
}

/** @param array<string, mixed> $npx_server */
function nibwp_build_zed_json(string $mcp_name, array $npx_server, int $opts): string
{
    return (string) json_encode([
        'context_servers' => [
            $mcp_name => array_merge([
                'source' => 'custom',
                'enabled' => true,
            ], $npx_server),
        ],
    ], $opts);
}

function nibwp_build_opencode_json(
    string $mcp_name,
    string $rest_url,
    string $username,
    string $display_password,
    int $opts,
): string {
    $environment = [
        'WP_API_URL' => $rest_url,
        'WP_API_USERNAME' => $username,
        'WP_API_PASSWORD' => $display_password,
    ];
    if (nibwp_likely_self_signed_https()) {
        $environment['NODE_TLS_REJECT_UNAUTHORIZED'] = '0';
    }
    return (string) json_encode([
        'mcp' => [
            $mcp_name => [
                'type' => 'local',
                'command' => ['npx', '-y', '@automattic/mcp-wordpress-remote@latest'],
                'environment' => $environment,
            ],
        ],
    ], $opts);
}

function nibwp_build_codex_toml(
    string $mcp_name,
    string $rest_url,
    string $username,
    string $display_password,
): string {
    $esc = static fn(string $v): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';

    $lines = [
        '[mcp_servers.' . $mcp_name . ']',
        'command = "npx"',
        'args = ["-y", "@automattic/mcp-wordpress-remote@latest"]',
        '',
        '[mcp_servers.' . $mcp_name . '.env]',
        'WP_API_URL = ' . $esc($rest_url),
        'WP_API_USERNAME = ' . $esc($username),
        'WP_API_PASSWORD = ' . $esc($display_password),
    ];
    if (nibwp_likely_self_signed_https()) {
        $lines[] = 'NODE_TLS_REJECT_UNAUTHORIZED = "0"';
    }
    return implode("\n", $lines);
}

function nibwp_build_claude_code_cmd(
    string $mcp_name,
    string $rest_url,
    string $username,
    string $display_password,
): string {
    $sq = static fn(string $v): string => "'" . str_replace(search: "'", replace: "'\\''", subject: $v) . "'";

    $parts = [
        'claude mcp add ' . $sq($mcp_name),
        '--env WP_API_URL=' . $sq($rest_url),
        '--env WP_API_USERNAME=' . $sq($username),
        '--env WP_API_PASSWORD=' . $sq($display_password),
    ];
    if (nibwp_likely_self_signed_https()) {
        $parts[] = '--env NODE_TLS_REJECT_UNAUTHORIZED=' . $sq('0');
    }
    $parts[] = '-- npx -y @automattic/mcp-wordpress-remote@latest';

    return implode(" \\\n  ", $parts);
}

/**
 * Build all per-client, per-transport config entries.
 *
 * @param string $rest_url        MCP REST endpoint URL.
 * @param string $username        Current WordPress username.
 * @param string $display_password Plaintext password or placeholder.
 * @param string $mcp_name        MCP server name used as the config key.
 * @return array<string, array{code: string, hint: string, paths: array<string, string>, isShell: bool}>
 */
function nibwp_build_configs(string $rest_url, string $username, string $display_password, string $mcp_name): array
{
    $opts = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
    $npx_server = nibwp_build_npx_server($rest_url, $username, $display_password);
    $mcp_servers_json = (string) json_encode(['mcpServers' => [$mcp_name => $npx_server]], $opts);
    $vscode_servers_json = (string) json_encode(['servers' => [$mcp_name => $npx_server]], $opts);

    /* translators: %s: config file name wrapped in <code> tags */
    $add_to = __('Add to %s.', domain: 'nibwp');

    $special = [
        'claude-code' => [
            'code' => nibwp_build_claude_code_cmd($mcp_name, $rest_url, $username, $display_password),
            'hint' => __('Run in your terminal.', domain: 'nibwp'),
            'paths' => [],
            'isShell' => true,
        ],
        'codex' => [
            'code' => nibwp_build_codex_toml($mcp_name, $rest_url, $username, $display_password),
            'hint' => sprintf($add_to, '<code>config.toml</code>'),
            'paths' => [
                'macOS / Linux' => '~/.codex/config.toml',
                'Windows' => '%USERPROFILE%\\.codex\\config.toml',
            ],
            'isShell' => false,
        ],
        'zed' => [
            'code' => nibwp_build_zed_json($mcp_name, $npx_server, $opts),
            'hint' => sprintf($add_to, '<code>settings.json</code>'),
            'paths' => ['macOS / Linux' => '~/.config/zed/settings.json'],
            'isShell' => false,
        ],
        'opencode' => [
            'code' => nibwp_build_opencode_json($mcp_name, $rest_url, $username, $display_password, $opts),
            'hint' => sprintf($add_to, '<code>opencode.json</code>'),
            'paths' => [
                __('Project', domain: 'nibwp') => 'opencode.json',
                __('Global', domain: 'nibwp') => '~/.config/opencode/opencode.json',
            ],
            'isShell' => false,
        ],
    ];

    return array_merge(nibwp_build_standard_configs($mcp_servers_json, $vscode_servers_json), $special);
}

/**
 * Build per-client config entries that reuse the standard mcpServers/servers JSON payloads.
 *
 * @return array<string, array{code: string, hint: string, paths: array<string, string>, isShell: bool}>
 */
function nibwp_build_standard_configs(string $mcp_servers_json, string $vscode_servers_json): array
{
    /* translators: %s: config file name wrapped in <code> tags */
    $add_to = __('Add to %s.', domain: 'nibwp');

    return [
        'claude-desktop' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>claude_desktop_config.json</code>'),
            'paths' => [
                'macOS' => '~/Library/Application Support/Claude/claude_desktop_config.json',
                'Windows' => '%APPDATA%\\Claude\\claude_desktop_config.json',
            ],
            'isShell' => false,
        ],
        'cursor' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>mcp.json</code>'),
            'paths' => [
                __('Global', domain: 'nibwp') => '~/.cursor/mcp.json',
                __('Project', domain: 'nibwp') => '.cursor/mcp.json',
            ],
            'isShell' => false,
        ],
        'vscode' => [
            'code' => $vscode_servers_json,
            'hint' => sprintf($add_to, '<code>mcp.json</code>'),
            'paths' => [
                __('Workspace', domain: 'nibwp') => '.vscode/mcp.json',
                __('User', domain: 'nibwp') => __(
                    'Run: MCP: Open User Configuration (command palette)',
                    domain: 'nibwp',
                ),
            ],
            'isShell' => false,
        ],
        'windsurf' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>mcp_config.json</code>'),
            'paths' => [
                'macOS / Linux' => '~/.codeium/windsurf/mcp_config.json',
                'Windows' => '%USERPROFILE%\\.codeium\\windsurf\\mcp_config.json',
            ],
            'isShell' => false,
        ],
        'cline' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>cline_mcp_settings.json</code>'),
            'paths' => [
                __('Via UI', domain: 'nibwp') => __(
                    'Cline sidebar â†’ MCP Servers â†’ Configure MCP Servers',
                    domain: 'nibwp',
                ),
            ],
            'isShell' => false,
        ],
        'roo-code' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>mcp.json</code>'),
            'paths' => [
                __('Project', domain: 'nibwp') => '.roo/mcp.json',
                __('Via UI', domain: 'nibwp') => __(
                    'Roo Code sidebar â†’ MCP Servers â†’ Configure MCP Servers',
                    domain: 'nibwp',
                ),
            ],
            'isShell' => false,
        ],
        'kilo-code' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>mcp.json</code>'),
            'paths' => [
                __('Project', domain: 'nibwp') => '.kilocode/mcp.json',
                __('Via UI', domain: 'nibwp') => __(
                    'Kilo Code sidebar â†’ MCP Servers â†’ Configure MCP Servers',
                    domain: 'nibwp',
                ),
            ],
            'isShell' => false,
        ],
        'github-copilot' => [
            'code' => $vscode_servers_json,
            'hint' => sprintf($add_to, '<code>mcp.json</code>'),
            'paths' => [
                __('Project', domain: 'nibwp') => '.github/copilot/mcp.json',
            ],
            'isShell' => false,
        ],
        'amazon-q' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>mcp.json</code>'),
            'paths' => [
                __('Global', domain: 'nibwp') => '~/.aws/amazonq/mcp.json',
                __('Project', domain: 'nibwp') => '.amazonq/mcp.json',
            ],
            'isShell' => false,
        ],
        'gemini-cli' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>settings.json</code>'),
            'paths' => [
                __('Global', domain: 'nibwp') => '~/.gemini/settings.json',
                __('Project', domain: 'nibwp') => '.gemini/settings.json',
            ],
            'isShell' => false,
        ],
        'antigravity' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>mcp_config.json</code>'),
            'paths' => [
                'macOS / Linux' => '~/.gemini/antigravity/mcp_config.json',
                'Windows' => '%USERPROFILE%\\.gemini\\antigravity\\mcp_config.json',
            ],
            'isShell' => false,
        ],
    ];
}

/**
 * Render the tabbed MCP client config section.
 *
 * @param string $rest_url        MCP REST endpoint URL.
 * @param string $username        Current WordPress username.
 * @param string $display_password Plaintext password or placeholder.
 */
function nibwp_render_config_section(string $rest_url, string $username, string $display_password): void
{
    $default_name = nibwp_get_mcp_server_name_default();
    $name_placeholder = '__NIBWP_MCP_NAME__';
    $pw_slot = '__NIBWP_PW_SLOT__';
    $password_is_placeholder = hash_equals('YOUR-APP-PASSWORD', $display_password);
    $configs = nibwp_build_configs($rest_url, $username, $display_password, $name_placeholder);
    $configs_json = (string) wp_json_encode($configs);
    $mcpb_uri = nibwp_build_mcpb_data_uri($rest_url, $username, $display_password);
    $mcpb_filename = 'nibwp-' . sanitize_file_name((string) wp_parse_url(home_url(), PHP_URL_HOST)) . '.mcpb';

    $clients = [
        'claude-code' => 'Claude Code',
        'claude-desktop' => 'Claude Desktop',
        'codex' => 'Codex',
        'antigravity' => 'Antigravity',
        'cursor' => 'Cursor',
        'vscode' => 'VS Code',
        'github-copilot' => 'GitHub Copilot',
        'windsurf' => 'Windsurf',
        'cline' => 'Cline',
        'gemini-cli' => 'Gemini CLI',
        'roo-code' => 'Roo Code',
        'amazon-q' => 'Amazon Q',
        'zed' => 'Zed',
        'kilo-code' => 'Kilo Code',
        'opencode' => 'OpenCode',
    ];

    $copied_label = esc_js(__('Copied!', domain: 'nibwp'));
    $paste_paragraph_initial = nibwp_build_paste_to_agent_paragraph(
        $rest_url,
        $username,
        $display_password,
        $default_name,
    );
    $paste_paragraph_template = nibwp_build_paste_to_agent_paragraph(
        $rest_url,
        $username,
        $display_password,
        $name_placeholder,
        $pw_slot,
    );
    ?>
    <h2 class="nibwp-step-heading" id="nibwp-connect-client" style="margin-top:32px;">
        <span class="nibwp-step-badge">3</span>
        <?php esc_html_e('Connect Your AI Client', domain: 'nibwp'); ?>
    </h2>
    <?php // No instruction line: a code block with a Copy button next to it is
          // already telling you to copy it. ?>

    <?php
    // This section renders only in the request that produced a credential, so
    // its presence is the signal that something just happened. The password and
    // the config sit below two long sections the reader has already finished
    // with — landing them at the top of the page means scrolling back down to
    // find the thing they came for.
    ?>
    <script>
    (function () {
        var target = document.getElementById('nibwp-connect-client');
        if (!target || !target.scrollIntoView) { return; }
        // After paint, so the position is the one the reader will actually see.
        window.requestAnimationFrame(function () {
            target.scrollIntoView({ block: 'start', behavior: 'smooth' });
        });
    })();
    </script>

    <?php if (nibwp_likely_self_signed_https()): ?>
        <div class="notice notice-warning inline" style="margin:0 0 12px;">
            <p style="margin:0;">
                <strong><?php esc_html_e('Local HTTPS detected.', domain: 'nibwp'); ?></strong>
                <?php esc_html_e(
                    'Your site uses HTTPS with a certificate that is not publicly trusted (normal for local development). The snippets below include a small flag so your AI client can connect anyway.',
                    domain: 'nibwp',
                ); ?>
            </p>
        </div>
    <?php endif; ?>

    <div class="nibwp-prompt-label">
        <span class="nibwp-prompt-label__badge">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
            <?php esc_html_e('Prompt for your AI agent', domain: 'nibwp'); ?>
        </span>
        <span class="nibwp-prompt-label__hint"><?php esc_html_e('Paste this into Claude, ChatGPT, Cursor — the agent will set up the connection for you.', domain: 'nibwp'); ?></span>
    </div>

    <div class="nibwp-paste-block">
        <div class="nibwp-paste-content" id="nibwp-paste-content">
            <pre id="nibwp-paste-text"><?php echo esc_html($paste_paragraph_initial); ?></pre>
        </div>
        <div class="nibwp-paste-actions">
            <button
                type="button"
                class="nibwp-btn-ghost"
                id="nibwp-paste-expand"
                onclick="nibwpToggleExpandPaste(this)"
                aria-expanded="false"
                aria-controls="nibwp-paste-content"
            ><?php esc_html_e('Show full text', domain: 'nibwp'); ?></button>
            <button
                type="button"
                class="nibwp-btn-ghost nibwp-paste-actions__copy"
                onclick="nibwpManualSetup()"
            ><?php esc_html_e('Manual setup', domain: 'nibwp'); ?></button>
            <button
                type="button"
                class="nibwp-btn-primary"
                onclick="nibwpCopyPaste(this)"
            >
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                <span class="nibwp-btn__label"><?php esc_html_e('Copy prompt', domain: 'nibwp'); ?></span></button>
            <p
                id="nibwp-paste-copied-warning"
                style="display:none; margin:0; color:#d63638; font-size:13px; font-weight:600;"
            >
                <?php esc_html_e(
                    "Don't share with anyone: it contains an application password that grants access to this WordPress site.",
                    domain: 'nibwp',
                ); ?>
            </p>
        </div>
    </div>

    <?php // Everything past this point is for people who want to edit JSON by
          // hand or rename the server. The prompt above already connects the
          // client, so this stays shut until someone asks for it. ?>
    <details class="nibwp-optional" id="nibwp-optional">
        <summary class="nibwp-optional__summary">
            <span class="nibwp-optional__chev" aria-hidden="true">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
            </span>
            <span class="nibwp-optional__title"><?php esc_html_e('Optional', domain: 'nibwp'); ?></span>
        </summary>

    <p style="margin:14px 0 4px;">
        <button
            type="button"
            class="nibwp-btn-ghost"
            id="nibwp-server-name-toggle"
            aria-expanded="false"
            aria-controls="nibwp-server-name-field"
            onclick="nibwpToggleServerName(this)"
        ><?php esc_html_e('Change server name', domain: 'nibwp'); ?></button>
    </p>
    <div id="nibwp-server-name-field" hidden style="display:none; margin: 6px 0 14px;">
        <input
            type="text"
            id="nibwp-mcp-name"
            value="<?php echo esc_attr($default_name); ?>"
            placeholder="<?php echo esc_attr($default_name); ?>"
            maxlength="25"
            style="width:220px;"
            oninput="nibwpUpdateName(this.value)"
        >
        <p class="description" style="margin:6px 0 0;">
            <?php esc_html_e(
                'Editing here updates the connection text and JSON snippets below in real time. Each AI client config keeps its own name once saved on its side.',
                domain: 'nibwp',
            ); ?>
        </p>
        <div id="nibwp-name-warning" class="notice notice-warning inline" style="display:none; margin:8px 0 0;">
            <p style="margin:0;">
                <?php esc_html_e(
                    'Maximum 25 characters reached. Required for client compatibility.',
                    domain: 'nibwp',
                ); ?>
            </p>
        </div>
        <div id="nibwp-name-suggestion" class="notice notice-warning inline" style="display:none; margin:8px 0 0;">
            <p style="margin:0;">
                <?php esc_html_e(
                    'Tip: keep "nibwp" in the name so you (and your AI agent) can tell this MCP server apart from others.',
                    domain: 'nibwp',
                ); ?>
            </p>
        </div>
    </div>

    <h3 style="margin:20px 0 8px; font-size:15px; font-weight:600; color:var(--nw-text);">
        <?php esc_html_e('IDE / Client Config Snippets', domain: 'nibwp'); ?>
    </h3>
    <p class="description" style="margin:0 0 12px;">
        <?php esc_html_e(
            'Select your AI client below to get the ready-to-use MCP config snippet. Copy and paste it into your client\'s configuration file.',
            domain: 'nibwp',
        ); ?>
    </p>

    <div id="nibwp-manual-config">

        <div class="nibwp-client-tabs-wrap" id="nibwp-client-tabs-wrap">
        <div class="nibwp-client-tabs" id="nibwp-client-tabs">
        <?php foreach ($clients as $key => $label): ?>
            <button
                type="button"
                class="nibwp-client-tab<?php echo $key === 'claude-code' ? ' active' : ''; ?>"
                onclick="nibwpSetClient('<?php echo esc_js($key); ?>', this)"
            ><?php echo esc_html($label); ?></button>
        <?php endforeach; ?>
        </div>
    </div>

    <div class="nibwp-tab-content" style="border-radius:4px;">
        <div id="nibwp-client-actions" style="display:none;"></div>
        <div class="nibwp-config-block">
            <pre id="nibwp-config-code"></pre>
            <button type="button" class="button nibwp-copy-btn" onclick="nibwpCopyConfig(this)"><?php esc_html_e(
                'Copy',
                domain: 'nibwp',
            ); ?></button>
        </div>
        <div id="nibwp-config-footer" style="font-size:13px; color:var(--nw-text-muted); border-top: 1px solid #c3c4c7;">
            <div id="nibwp-config-hint" style="padding: 10px 16px;"></div>
            <div id="nibwp-config-paths" style="padding: 0 16px 10px;"></div>
        </div>
    </div>
    </div>
    </details>

    <script>
    (function () {
        var configs = <?php echo $configs_json; ?>;
        var client = 'claude-code';
        var defaultName = <?php echo wp_json_encode($default_name); ?>;
        var pasteTemplate = <?php echo wp_json_encode($paste_paragraph_template); ?>;
        var mcpName = <?php echo wp_json_encode($default_name); ?>;
        var namePlaceholder = <?php echo wp_json_encode($name_placeholder); ?>;
        var passwordSentinel = <?php echo wp_json_encode($pw_slot); ?>;
        var passwordValue = <?php echo wp_json_encode($display_password); ?>;
        var passwordIsPlaceholder = <?php echo wp_json_encode($password_is_placeholder); ?>;
        var mcpbHref = <?php echo wp_json_encode($mcpb_uri); ?>;
        var mcpbName = <?php echo wp_json_encode($mcpb_filename); ?>;
        var mcpbLabel = <?php echo wp_json_encode(__('Download .mcpb bundle', 'nibwp')); ?>;
        var mcpbNote = <?php echo wp_json_encode(__('You download the file, open it with Claude Desktop, and the MCP server installs with your credentials already embedded. No JSON to edit, no config file to locate.', 'nibwp')); ?>;
        var mcpbManualLabel = <?php echo wp_json_encode(__('Manual setup', 'nibwp')); ?>;
        var mcpbManualNote = <?php echo wp_json_encode(__('Prefer to edit JSON yourself? Use the configuration below.', 'nibwp')); ?>;

        function renderPaste() {
            var text = pasteTemplate.split(namePlaceholder).join(mcpName);
            var container = document.getElementById('nibwp-paste-text');
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

        function render() {
            renderConfig();
            renderPaste();
        }

        function renderConfig() {
            var cfg = configs[client];
            if (!cfg) { return; }

            var code = cfg.code.split(namePlaceholder).join(mcpName);
            var codeEl = document.getElementById('nibwp-config-code');
            codeEl.textContent = code;
            if (code.indexOf('YOUR-APP-PASSWORD') !== -1) {
                codeEl.innerHTML = codeEl.innerHTML.replace(
                    /YOUR-APP-PASSWORD/g,
                    '<span class="nibwp-placeholder">YOUR-APP-PASSWORD</span>'
                );
            }
            document.getElementById('nibwp-config-hint').innerHTML = cfg.hint;

            var pathsEl = document.getElementById('nibwp-config-paths');
            var keys = Object.keys(cfg.paths);
            if (keys.length > 0) {
                var html = '<ul style="margin:4px 0 0; padding-left:20px;">';
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

            // Claude Desktop gets a one-click .mcpb bundle (credentials embedded);
            // the JSON below stays as the manual fallback.
            var actions = document.getElementById('nibwp-client-actions');
            if (actions) {
                if (client === 'claude-desktop' && mcpbHref) {
                    actions.innerHTML =
                        '<div class="nibwp-mcpb__row">'
                        + '<a class="nibwp-btn-primary nibwp-mcpb__btn" download="' + mcpbName + '" href="' + mcpbHref + '">'
                        + '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>'
                        + '<span>' + mcpbLabel + '</span></a>'
                        + '<button type="button" class="nibwp-btn-ghost" onclick="nibwpManualSetup()">' + mcpbManualLabel + '</button>'
                        + '</div>'
                        + '<p class="nibwp-mcpb__note">' + mcpbNote + ' ' + mcpbManualNote + '</p>';
                    actions.style.display = '';
                } else {
                    actions.innerHTML = '';
                    actions.style.display = 'none';
                }
            }
        }

        window.nibwpSetClient = function (key, btn) {
            client = key;
            document.querySelectorAll('.nibwp-client-tab').forEach(function (t) { t.classList.remove('active'); });
            btn.classList.add('active');
            renderConfig();
        };

        // The manual config lives inside the collapsed Optional section now, so
        // anything that sends you there has to open it first — otherwise the
        // button scrolls to something nobody can see.
        window.nibwpOpenOptional = function () {
            var box = document.getElementById('nibwp-optional');
            if (box && !box.open) { box.open = true; }
            return box;
        };

        window.nibwpManualSetup = function () {
            nibwpOpenOptional();
            var section = document.getElementById('nibwp-manual-config');
            var tab = document.querySelector('.nibwp-client-tab'); // first tab = Claude Code
            if (tab) { nibwpSetClient('claude-code', tab); }
            if (section && section.scrollIntoView) {
                section.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        };

        function updateNameWarning(value) {
            var warning = document.getElementById('nibwp-name-warning');
            warning.style.display = value.length >= 25 ? 'block' : 'none';

            var suggestion = document.getElementById('nibwp-name-suggestion');
            var trimmed = value.trim();
            var missingNIBWP = trimmed.length > 0 && trimmed.toLowerCase().indexOf('nibwp') === -1;
            suggestion.style.display = missingNIBWP ? 'block' : 'none';
        }

        window.nibwpUpdateName = function (value) {
            mcpName = value.trim() || defaultName;
            updateNameWarning(value);
            render();
        };

        window.nibwpToggleServerName = function (btn) {
            var field = document.getElementById('nibwp-server-name-field');
            var expanded = btn.getAttribute('aria-expanded') === 'true';
            if (expanded) {
                field.style.display = 'none';
                field.hidden = true;
                btn.setAttribute('aria-expanded', 'false');
            } else {
                field.style.display = 'block';
                field.hidden = false;
                btn.setAttribute('aria-expanded', 'true');
                var input = document.getElementById('nibwp-mcp-name');
                if (input) { input.focus(); }
            }
        };

        window.nibwpToggleManualConfig = function (btn) {
            var panel = document.getElementById('nibwp-manual-config');
            var expanded = btn.getAttribute('aria-expanded') === 'true';
            if (expanded) {
                panel.style.display = 'none';
                panel.hidden = true;
                btn.setAttribute('aria-expanded', 'false');
            } else {
                panel.style.display = '';
                panel.hidden = false;
                btn.setAttribute('aria-expanded', 'true');
            }
        };

        window.nibwpToggleExpandPaste = function (btn) {
            var content = document.getElementById('nibwp-paste-content');
            var expanded = btn.getAttribute('aria-expanded') === 'true';
            if (expanded) {
                content.classList.remove('is-expanded');
                btn.setAttribute('aria-expanded', 'false');
                btn.textContent = <?php echo wp_json_encode(__('Show full text', domain: 'nibwp')); ?>;
            } else {
                content.classList.add('is-expanded');
                btn.setAttribute('aria-expanded', 'true');
                btn.textContent = <?php echo wp_json_encode(__('Show less', domain: 'nibwp')); ?>;
            }
        };

        window.nibwpCopyPaste = function (btn) {
            navigator.clipboard.writeText(document.getElementById('nibwp-paste-text').textContent).then(function () {
                var lbl = btn.querySelector('.nibwp-btn__label') || btn;
                var orig = lbl.textContent;
                lbl.textContent = '<?php echo $copied_label; ?>';
                var warning = document.getElementById('nibwp-paste-copied-warning');
                if (warning) { warning.style.display = 'block'; }
                setTimeout(function () {
                    lbl.textContent = orig;
                    if (warning) { warning.style.display = 'none'; }
                }, 4000);
            });
        };

        window.nibwpCopyConfig = function (btn) {
            navigator.clipboard.writeText(document.getElementById('nibwp-config-code').textContent).then(function () {
                var orig = btn.textContent;
                btn.textContent = '<?php echo $copied_label; ?>';
                setTimeout(function () { btn.textContent = orig; }, 1500);
            });
        };

        render();

        /* Client tabs: single-line strip with hover-to-scroll (mouse X drives
           scroll speed, faster toward the edges) — same as the integrations page. */
        (function () {
            var wrap = document.getElementById('nibwp-client-tabs-wrap');
            var strip = document.getElementById('nibwp-client-tabs');
            if (!wrap || !strip) { return; }
            var rafId = null, velocity = 0;
            var DEAD_ZONE = 0.05, MAX_SPEED = 32;

            function updateShadows() {
                var max = strip.scrollWidth - strip.clientWidth;
                wrap.classList.toggle('has-scroll-left', strip.scrollLeft > 2);
                wrap.classList.toggle('has-scroll-right', strip.scrollLeft < max - 2);
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
                if (Math.abs(offset) < DEAD_ZONE) {
                    velocity = 0;
                } else {
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
    }());
    </script>
    <?php
}

function nibwp_render_mcp_dependency_inline_notice(?WP_Error $dependency_error): void
{
    if ($dependency_error === null) {
        return;
    }

    ?>
    <div class="nibwp-mcp-error-panel" role="alert">
        <h2><?php esc_html_e('NIBWP cannot expose MCP', domain: 'nibwp'); ?></h2>
        <p><?php echo esc_html($dependency_error->get_error_message()); ?></p>
    </div>
    <?php
}

function nibwp_render_enable_prompt(?WP_Error $dependency_error): void
{
    if (nibwp_is_enabled() || $dependency_error !== null) {
        return;
    }

    ?>
    <p style="color:var(--nw-text-muted); font-size:14px;">
        <?php esc_html_e(
            'Enable AI Abilities above to create application passwords and connect an MCP client.',
            domain: 'nibwp',
        ); ?>
    </p>
    <?php
}

/**
 * Render the connect / setup dashboard page.
 */
// @mago-expect lint:cyclomatic-complexity
function nibwp_render_connect_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $mcp_dependency_error = nibwp_get_mcp_dependency_error();
    $toggle_saved = nibwp_handle_toggle_enabled();
    $enabled = nibwp_is_enabled();
    $mcp_ready = $enabled && $mcp_dependency_error === null;

    $password_result = $mcp_ready ? nibwp_handle_create_password() : null;
    $create_error = is_wp_error($password_result) ? $password_result : null;
    $new_password = is_string($password_result) ? $password_result : null;

    $existing_result = $mcp_ready ? nibwp_handle_use_existing_password() : null;
    $existing_error = is_wp_error($existing_result) ? $existing_result : null;
    $existing_password = is_string($existing_result) ? $existing_result : null;

    $result_message = match ($_GET['nibwp_result'] ?? null) {
        'disconnected' => __('Application disconnected. It can no longer reach this site.', domain: 'nibwp'),
        'revoked' => __('Application password revoked.', domain: 'nibwp'),
        default => null,
    };

    $current_user = wp_get_current_user();
    $username = $current_user->user_login;
    $rest_url = rest_url('mcp/nibwp');
    $display_password = $new_password ?? $existing_password ?? 'YOUR-APP-PASSWORD';

    $copied_label = esc_js(__('Copied!', domain: 'nibwp'));

    ?>
    <?php nibwp_render_admin_header(); ?>
    <div class="wrap nibwp-wrap">
        <div class="nibwp-page-header">
            <div>
                <h1><?php esc_html_e('Configuration', domain: 'nibwp'); ?></h1>
                <p class="nibwp-subtitle"><?php esc_html_e('Connect AI agents to your WordPress site via MCP.', domain: 'nibwp'); ?></p>
            </div>
        </div>

        <?php nibwp_render_mcp_dependency_inline_notice($mcp_dependency_error); ?>

        <?php if ($toggle_saved === true): ?>
            <div class="notice notice-success is-dismissible"><p><?php

            esc_html_e('Settings saved.', domain: 'nibwp');
            ?></p></div>
        <?php endif; ?>

        <?php nibwp_render_production_warning(); ?>

        <details class="nibwp-howto">
            <summary class="nibwp-howto__summary">
                <span class="nibwp-howto__eyebrow"><?php esc_html_e('How it works', domain: 'nibwp'); ?></span>
                <span class="nibwp-howto__chev" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span>
            </summary>
            <p class="nibwp-howto__lead"><?php esc_html_e('NIBWP gives your AI assistant (Claude, ChatGPT, Cursor…) a secure key to read and manage this site for you. Three quick steps:', domain: 'nibwp'); ?></p>
            <ol class="nibwp-howto__steps">
                <li><span class="nibwp-howto__n">1</span><?php esc_html_e('Turn on AI Abilities below.', domain: 'nibwp'); ?></li>
                <li><span class="nibwp-howto__n">2</span><?php esc_html_e('Create a secure connection key.', domain: 'nibwp'); ?></li>
                <li><span class="nibwp-howto__n">3</span><?php esc_html_e('Paste the ready-made prompt into your AI — it connects itself.', domain: 'nibwp'); ?></li>
            </ol>
        </details>

        <div class="nibwp-connect-section">
            <?php nibwp_render_enable_toggle(); ?>
        </div>

        <?php nibwp_render_enable_prompt($mcp_dependency_error); ?>
        <?php if ($mcp_ready): ?>
            <?php if ($create_error !== null): ?>
                <div class="notice notice-error"><p><?php

                echo esc_html($create_error->get_error_message());
                ?></p></div>
            <?php endif; ?>

            <?php if ($result_message !== null): ?>
                <div class="notice notice-success is-dismissible"><p><?php

                echo esc_html($result_message);
                ?></p></div>
            <?php endif; ?>

            <div class="nibwp-connect-section">
                <?php
                // Two ways in. The sign-in pane needs no credential, so it must
                // not sit behind the "did this request just mint a password?"
                // gate Step 3 uses — it would vanish on reload.
                nibwp_oauth_render_connect_tabs(
                    $rest_url,
                    nibwp_get_mcp_server_name_default(),
                    static function () use ($new_password, $existing_password, $existing_error, $rest_url, $username, $display_password): void {
                        nibwp_render_password_step($new_password, $existing_password, $existing_error);
                        nibwp_render_manage_passwords_section(allow_create_hint: true);

                        if ($new_password !== null || $existing_password !== null) {
                            nibwp_render_config_section($rest_url, $username, $display_password);
                        }
                    },
                    // Anything that just happened on the password form keeps the
                    // page on the password form — a new password, a pasted one,
                    // or an error explaining why neither worked.
                    ($new_password !== null || $existing_password !== null
                        || $create_error !== null || $existing_error !== null)
                        ? 'password'
                        : 'signin'
                );
                ?>
            </div>

            <?php nibwp_oauth_render_connections(); ?>
            <?php nibwp_oauth_render_manual_clients(); ?>
        <?php elseif (nibwp_get_mcp_passwords() !== []): ?>
            <div class="nibwp-connect-section">
                <h2 class="nibwp-step-heading">
                    <span class="nibwp-step-badge">2</span>
                    <?php esc_html_e('Application Password', domain: 'nibwp'); ?>
                </h2>
                <?php nibwp_render_manage_passwords_section(allow_create_hint: false); ?>
            </div>
        <?php endif; ?>

    </div>

    <script>
    function nibwpCopy(id, btn) {
        var text = document.getElementById(id).textContent;
        navigator.clipboard.writeText(text).then(function() {
            var lbl = btn.querySelector('.nibwp-btn__label') || btn;
            var orig = lbl.textContent;
            lbl.textContent = '<?php echo $copied_label; ?>';
            setTimeout(function() { lbl.textContent = orig; }, 1500);
        });
    }
    function nibwpTogglePasswordName(btn) {
        var field = document.getElementById('nibwp-password-name-field');
        var expanded = btn.getAttribute('aria-expanded') === 'true';
        if (expanded) {
            field.style.display = 'none';
            field.hidden = true;
            btn.setAttribute('aria-expanded', 'false');
        } else {
            field.style.display = 'block';
            field.hidden = false;
            btn.setAttribute('aria-expanded', 'true');
            var input = document.getElementById('nibwp-password-name');
            if (input) { input.focus(); }
        }
    }
    function nibwpToggleUseExisting(btn) {
        var field = document.getElementById('nibwp-use-existing-field');
        var expanded = btn.getAttribute('aria-expanded') === 'true';
        if (expanded) {
            field.style.display = 'none';
            field.hidden = true;
            btn.setAttribute('aria-expanded', 'false');
        } else {
            field.style.display = 'block';
            field.hidden = false;
            btn.setAttribute('aria-expanded', 'true');
            var input = document.getElementById('nibwp-existing-password');
            if (input) { input.focus(); }
        }
    }
    </script>
    <?php
    nibwp_render_admin_footer();
}
