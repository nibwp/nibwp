<?php

declare(strict_types=1);

/**
 * Danger Zone — wipe all NIBWP MCP connection state from this site.
 *
 * Purpose: when a site is cloned/migrated, the copy inherits the original's
 * MCP wiring — enabled flag, the domain lock, the application passwords AI
 * clients authenticate with, and the MCP-adapter sessions. This module gives
 * an admin a one-click, double-confirmed reset so the clone starts clean and
 * nothing from the source site can be used to reach the copy.
 *
 * Always wiped (the "connection" scope):
 *   - option  nibwp_ai_abilities_enabled  (turns the MCP endpoint off)
 *   - option  nibwp_ai_abilities_domain   (clears the domain lock)
 *   - every WP application password whose name begins with "NIBWP", for ALL users
 *   - user meta mcp_adapter_sessions       (active MCP sessions, ALL users)
 *   - NIBWP transients (preflight tokens, self-heal flags, …)
 *
 * Opt-in scopes (checkboxes):
 *   - license : clear stored license activation (nibwp_license*)
 *   - data    : memory, preferences, enabled skills/integrations, feedback —
 *               every nibwp_* option + nibwp_* user meta
 *   - audit   : truncate the {prefix}nibwp_audit_log table
 *
 * Guarded by: manage_options capability + nonce + a typed "RESET" confirmation
 * + a JS modal double-confirm warning about connection loss.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Snapshot of what a reset would affect, for the UI summary.
 *
 * @return array{passwords:int, sessions:int, enabled:bool, domain:string}
 */
function nibwp_danger_zone_counts(): array
{
    $passwords = 0;
    $sessions = 0;

    if (class_exists('WP_Application_Passwords')) {
        /** @var array<int,int> $user_ids */
        $user_ids = get_users(['fields' => 'ID']);
        foreach ($user_ids as $uid) {
            $uid = (int) $uid;
            foreach (WP_Application_Passwords::get_user_application_passwords($uid) as $pw) {
                if (str_starts_with((string) ($pw['name'] ?? ''), 'NIBWP')) {
                    $passwords++;
                }
            }
            if (get_user_meta($uid, 'mcp_adapter_sessions', true) !== '') {
                $sessions++;
            }
        }
    }

    return [
        'passwords' => $passwords,
        'sessions'  => $sessions,
        'enabled'   => function_exists('nibwp_is_enabled') ? (bool) nibwp_is_enabled() : (bool) get_option('nibwp_ai_abilities_enabled'),
        'domain'    => (string) get_option('nibwp_ai_abilities_domain', default_value: ''),
    ];
}

/**
 * Delete every transient whose name begins with the given prefix.
 *
 * Sweeps the options table (handles the DB-backed case) and always flushes the
 * object cache afterwards so a Redis/Memcached-backed transient store is cleared
 * too. Returns the number of DB-backed transients removed.
 */
function nibwp_danger_delete_transients_like(string $prefix): int
{
    global $wpdb;
    $count = 0;
    $like = $wpdb->esc_like('_transient_' . $prefix) . '%';
    /** @var array<int,string> $rows */
    $rows = $wpdb->get_col(
        $wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like),
    );
    foreach ($rows as $option_name) {
        $name = (string) preg_replace('/^_transient_/', '', $option_name);
        if (delete_transient($name)) {
            $count++;
        }
    }
    return $count;
}

/**
 * Run the reset. Returns a per-section report for the success notice.
 *
 * @param array{license?:bool, data?:bool, audit?:bool} $scopes
 * @return array{passwords_revoked:int, tokens_revoked:int, sessions_cleared:int, options_deleted:int, transients_deleted:int, user_meta_deleted:int, audit_rows:int}
 */
function nibwp_danger_run_reset(array $scopes): array
{
    global $wpdb;

    $report = [
        'passwords_revoked'  => 0,
        'tokens_revoked'     => 0,
        'sessions_cleared'   => 0,
        'options_deleted'    => 0,
        'transients_deleted' => 0,
        'user_meta_deleted'  => 0,
        'audit_rows'         => 0,
    ];

    // --- Connection scope (always) ---------------------------------------
    delete_option('nibwp_ai_abilities_enabled');
    delete_option('nibwp_ai_abilities_domain');

    if (class_exists('WP_Application_Passwords')) {
        /** @var array<int,int> $user_ids */
        $user_ids = get_users(['fields' => 'ID']);
        foreach ($user_ids as $uid) {
            $uid = (int) $uid;
            foreach (WP_Application_Passwords::get_user_application_passwords($uid) as $pw) {
                if (
                    isset($pw['name'], $pw['uuid'])
                    && str_starts_with((string) $pw['name'], 'NIBWP')
                ) {
                    $deleted = WP_Application_Passwords::delete_application_password($uid, (string) $pw['uuid']);
                    if (!is_wp_error($deleted)) {
                        $report['passwords_revoked']++;
                    }
                }
            }
            if (get_user_meta($uid, 'mcp_adapter_sessions', true) !== '') {
                delete_user_meta($uid, 'mcp_adapter_sessions');
                $report['sessions_cleared']++;
            }
        }
    }

    // OAuth grants are a connection too — leaving them alive would mean a
    // "reset connection" that still lets every signed-in AI client back in.
    if (defined('NIBWP_OAUTH_TOKENS_META')) {
        $report['tokens_revoked'] += (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s",
                NIBWP_OAUTH_TOKENS_META,
            ),
        );
        delete_option(NIBWP_OAUTH_CLIENTS_OPTION);
    }

    // The visual workspace queue, its pending results and its heartbeat are a
    // live connection too — a reset that left an agent mid-command would not
    // be one.
    $wpdb->query(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'nibwp_visual_queue_%'"
        . " OR option_name LIKE 'nibwp_visual_result_%'"
    );

    $report['transients_deleted'] += nibwp_danger_delete_transients_like('nibwp_');

    // --- License scope (opt-in) ------------------------------------------
    if (!empty($scopes['license'])) {
        /** @var array<int,string> $license_options */
        $license_options = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'nibwp\\_license%'",
        );
        foreach ($license_options as $name) {
            if (delete_option($name)) {
                $report['options_deleted']++;
            }
        }
    }

    // --- Plugin-data scope (opt-in) — every nibwp_* option + user meta ----
    if (!empty($scopes['data'])) {
        /** @var array<int,string> $names */
        $names = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'nibwp\\_%'",
        );
        foreach ($names as $name) {
            if (delete_option($name)) {
                $report['options_deleted']++;
            }
        }
        $report['user_meta_deleted'] += (int) $wpdb->query(
            "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'nibwp\\_%'",
        );
    }

    // --- Audit-log scope (opt-in) ----------------------------------------
    if (!empty($scopes['audit'])) {
        $table = $wpdb->prefix . 'nibwp_audit_log';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists === $table) {
            // Count first (TRUNCATE returns 0 affected rows).
            $report['audit_rows'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            $wpdb->query("TRUNCATE TABLE {$table}");
        }
    }

    // Flush object cache so Redis/Memcached-backed options + transients drop too.
    wp_cache_flush();

    return $report;
}

/**
 * admin_init handler — processes the Danger Zone form with a PRG redirect.
 * Registered for page=nibwp-connect alongside the other connect-page handlers.
 */
function nibwp_handle_danger_reset(): void
{
    if (($_POST['nibwp_danger_reset'] ?? null) === null) {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }

    check_admin_referer('nibwp_danger_reset');

    // Typed confirmation — the form's JS also gates this, but never trust the client.
    $typed = is_string($_POST['nibwp_danger_confirm'] ?? null) ? trim((string) $_POST['nibwp_danger_confirm']) : '';
    if ($typed !== 'RESET') {
        wp_safe_redirect(admin_url('admin.php?page=nibwp-settings&nibwp_danger=mismatch'));
        exit();
    }

    $scopes = [
        'license' => ($_POST['nibwp_danger_scope_license'] ?? null) !== null,
        'data'    => ($_POST['nibwp_danger_scope_data'] ?? null) !== null,
        'audit'   => ($_POST['nibwp_danger_scope_audit'] ?? null) !== null,
    ];

    $report = nibwp_danger_run_reset($scopes);

    $args = [
        'page'         => 'nibwp-settings',
        'nibwp_danger' => 'done',
        'pw'           => $report['passwords_revoked'],
        'to'           => $report['tokens_revoked'],
        'se'           => $report['sessions_cleared'],
        'op'           => $report['options_deleted'],
        'au'           => $report['audit_rows'],
    ];
    wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
    exit();
}

/**
 * Render the Danger Zone card at the bottom of the Configuration page.
 */
function nibwp_render_danger_zone(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $counts = nibwp_danger_zone_counts();
    $result = $_GET['nibwp_danger'] ?? null;
    ?>
    <div class="nibwp-connect-section nibwp-danger" style="border:1px solid #d63638; border-inline-start-width:4px; margin-top:32px;">
        <?php if ($result === 'done'): ?>
            <div class="notice notice-success inline" style="margin:0 0 16px;">
                <p style="margin:0;">
                    <?php
                    $nibwp_dz_pw = (int) ($_GET['pw'] ?? 0);
                    $nibwp_dz_to = (int) ($_GET['to'] ?? 0);
                    $nibwp_dz_se = (int) ($_GET['se'] ?? 0);
                    $nibwp_dz_op = (int) ($_GET['op'] ?? 0);
                    echo esc_html(implode(' ', [
                        __('Reset complete.', 'nibwp'),
                        sprintf(
                            /* translators: %s: number of application passwords revoked */
                            _n('Revoked %s application password.', 'Revoked %s application passwords.', $nibwp_dz_pw, 'nibwp'),
                            number_format_i18n($nibwp_dz_pw),
                        ),
                        sprintf(
                            /* translators: %s: number of OAuth sign-ins revoked */
                            _n('Revoked %s sign-in.', 'Revoked %s sign-ins.', $nibwp_dz_to, 'nibwp'),
                            number_format_i18n($nibwp_dz_to),
                        ),
                        sprintf(
                            /* translators: %s: number of MCP sessions cleared */
                            _n('Cleared %s MCP session.', 'Cleared %s MCP sessions.', $nibwp_dz_se, 'nibwp'),
                            number_format_i18n($nibwp_dz_se),
                        ),
                        sprintf(
                            /* translators: %s: number of options removed */
                            _n('Removed %s option.', 'Removed %s options.', $nibwp_dz_op, 'nibwp'),
                            number_format_i18n($nibwp_dz_op),
                        ),
                        __('AI clients are now disconnected — re-enable AI Abilities and reconnect from the Connect page.', 'nibwp'),
                    ]));
                    ?>
                </p>
            </div>
        <?php elseif ($result === 'mismatch'): ?>
            <div class="notice notice-error inline" style="margin:0 0 16px;">
                <p style="margin:0;"><?php esc_html_e('Reset cancelled: you must type RESET to confirm.', 'nibwp'); ?></p>
            </div>
        <?php endif; ?>

        <details class="nibwp-danger-details"<?php echo $result ? ' open' : ''; ?>>
        <summary class="nibwp-danger-summary">
            <h2 class="nibwp-step-heading" style="color:#d63638; margin:0;">
                <span class="nibwp-step-badge" style="background:#d63638;">!</span>
                <?php esc_html_e('Danger Zone — Reset MCP connection', 'nibwp'); ?>
            </h2>
            <span class="nibwp-danger-summary__chev" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span>
        </summary>

        <p class="description" style="margin:14px 0 12px;">
            <?php esc_html_e(
                'Wipes every trace of the MCP connection from this site so a cloned or migrated copy inherits nothing from the original. Use this on a fresh clone before reconnecting.',
                'nibwp',
            ); ?>
        </p>

        <div style="background:#fcf0f1; border:1px solid #f0c3c4; border-radius:4px; padding:12px 14px; margin:0 0 16px; font-size:13px;">
            <strong style="display:block; margin-bottom:6px;"><?php esc_html_e('Always removed:', 'nibwp'); ?></strong>
            <ul style="margin:0; padding-inline-start:18px; list-style:disc;">
                <li><?php esc_html_e('AI Abilities turned off and the domain lock cleared.', 'nibwp'); ?></li>
                <li>
                    <?php
                    printf(
                        /* translators: %d: number of NIBWP application passwords */
                        esc_html(_n('All NIBWP application passwords revoked (%d found).', 'All NIBWP application passwords revoked (%d found).', (int) $counts['passwords'], 'nibwp')),
                        (int) $counts['passwords'],
                    );
                    ?>
                </li>
                <li>
                    <?php
                    printf(
                        /* translators: %d: number of users with active MCP sessions */
                        esc_html(_n('All active MCP sessions cleared (%d user).', 'All active MCP sessions cleared (%d users).', (int) $counts['sessions'], 'nibwp')),
                        (int) $counts['sessions'],
                    );
                    ?>
                </li>
                <li><?php esc_html_e('NIBWP preflight tokens and temporary state flushed.', 'nibwp'); ?></li>
            </ul>
        </div>

        <form method="post" action="" id="nibwp-danger-form">
            <?php wp_nonce_field('nibwp_danger_reset'); ?>
            <input type="hidden" name="nibwp_danger_reset" value="1" />
            <input type="hidden" name="nibwp_danger_confirm" id="nibwp-danger-confirm-hidden" value="" />

            <p style="margin:0 0 8px; font-weight:600;"><?php esc_html_e('Also remove (optional):', 'nibwp'); ?></p>
            <label style="display:flex; align-items:flex-start; gap:8px; margin:0 0 8px;">
                <input type="checkbox" name="nibwp_danger_scope_license" value="1" />
                <span><?php esc_html_e('License activation — clear the stored license so the clone activates its own.', 'nibwp'); ?></span>
            </label>
            <label style="display:flex; align-items:flex-start; gap:8px; margin:0 0 8px;">
                <input type="checkbox" name="nibwp_danger_scope_data" value="1" />
                <span><?php esc_html_e('Plugin data — AI memory, preferences, enabled skills/integrations and feedback (all nibwp_* options + user meta).', 'nibwp'); ?></span>
            </label>
            <label style="display:flex; align-items:flex-start; gap:8px; margin:0 0 16px;">
                <input type="checkbox" name="nibwp_danger_scope_audit" value="1" />
                <span><?php esc_html_e('Audit log — permanently delete all recorded ability-call history.', 'nibwp'); ?></span>
            </label>

            <p style="margin:14px 0 0;">
                <button
                    type="button"
                    id="nibwp-danger-open"
                    class="button"
                    style="background:#d63638; border-color:#b32d2e; color:#fff;"
                >
                    <?php esc_html_e('Reset this site…', 'nibwp'); ?>
                </button>
            </p>
        </form>
        </details>
    </div>

    <!-- Double-confirm modal -->
    <div class="nw-confirm" id="nw-danger-confirm" role="dialog" aria-modal="true" aria-hidden="true">
        <div class="nw-confirm__backdrop"></div>
        <div class="nw-confirm__panel" role="document">
            <div class="nw-confirm__head is-danger">
                <div class="nw-confirm__icon">
                    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                </div>
                <h3 id="nw-danger-confirm-title"><?php esc_html_e('Disconnect this site?', 'nibwp'); ?></h3>
            </div>
            <div class="nw-confirm__body">
                <p><?php esc_html_e('This permanently removes the MCP connection. Every AI client connected to this site will immediately lose access, and this cannot be undone.', 'nibwp'); ?></p>
                <p style="margin-top:8px;"><?php
                    printf(
                        /* translators: 1: opening <strong> tag, 2: closing </strong> tag */
                        esc_html__('%1$sYou will lose connection with this website until you connect again%2$s — re-enable AI Abilities and generate a new application password to reconnect.', 'nibwp'),
                        '<strong>',
                        '</strong>'
                    );
                ?></p>
                <p style="margin:16px 0 6px; font-weight:600;">
                    <?php
                    /* translators: %s: the literal word RESET wrapped in <code> */
                    printf(esc_html__('Type %s to confirm:', 'nibwp'), '<code>RESET</code>');
                    ?>
                </p>
                <input
                    type="text"
                    id="nibwp-danger-confirm-input"
                    autocomplete="off"
                    placeholder="RESET"
                    aria-label="<?php esc_attr_e('Type RESET to confirm', 'nibwp'); ?>"
                    style="width:200px; font-family:monospace; letter-spacing:2px; text-transform:uppercase; padding:6px 10px;"
                />
            </div>
            <div class="nw-confirm__footer">
                <button type="button" class="button button-secondary" id="nw-danger-cancel"><?php esc_html_e('Cancel', 'nibwp'); ?></button>
                <button type="button" class="button" id="nw-danger-continue" style="background:#d63638; border-color:#b32d2e; color:#fff;" disabled><?php esc_html_e('Yes, reset and disconnect', 'nibwp'); ?></button>
            </div>
        </div>
    </div>

    <script>
    (function () {
        var form    = document.getElementById('nibwp-danger-form');
        var openBtn = document.getElementById('nibwp-danger-open');
        var hidden  = document.getElementById('nibwp-danger-confirm-hidden');
        var modal   = document.getElementById('nw-danger-confirm');
        if (!form || !openBtn || !hidden || !modal) { return; }
        var input   = document.getElementById('nibwp-danger-confirm-input');
        var btnYes  = document.getElementById('nw-danger-continue');
        var btnNo   = document.getElementById('nw-danger-cancel');
        var backdrop = modal.querySelector('.nw-confirm__backdrop');

        function valid() { return input.value.trim().toUpperCase() === 'RESET'; }
        function open()  { input.value = ''; btnYes.disabled = true; modal.classList.add('is-open'); modal.setAttribute('aria-hidden', 'false'); document.body.classList.add('nw-onb-locked'); setTimeout(function(){ input.focus(); }, 60); }
        function close() { modal.classList.remove('is-open'); modal.setAttribute('aria-hidden', 'true'); document.body.classList.remove('nw-onb-locked'); }

        // Step 1: click the page button to open the confirm modal.
        openBtn.addEventListener('click', open);
        // Step 2: inside the modal, type RESET to unlock the confirm button.
        input.addEventListener('input', function () { btnYes.disabled = !valid(); });
        input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && valid()) { e.preventDefault(); btnYes.click(); } });
        btnYes.addEventListener('click', function () { if (!valid()) { return; } hidden.value = 'RESET'; form.submit(); });
        btnNo.addEventListener('click', close);
        backdrop.addEventListener('click', close);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.classList.contains('is-open')) { close(); } });
    })();
    </script>
    <?php
}
