<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Standalone License admin page (sidebar item).
 *
 * Pro build: full activation UI (email OTP lookup + manual paste + per-license
 * row management + pricing CTAs).
 * Free build: registered by nibwp.php fallback — renders pricing-only panel.
 *
 * UX:
 *   1. Connected-account block — email lookup + OTP verification.
 *      Server returns all licenses for that email; user activates per row.
 *   2. Licenses list — one row per stored license (Pro / Bundle / each skill).
 *      Each row: masked key, product label, entitlements badges, expiry,
 *      activate/deactivate/refresh actions.
 *   3. Manual paste — single input + Activate button for power users / agencies.
 *   4. Buy CTAs — pricing tiers with links to nibwp.com.
 */

/**
 * Full license page render — admin shell + body + footer.
 */
function nibwp_render_license_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    nibwp_render_admin_header();
    ?>
    <div class="wrap nibwp-wrap">
        <div class="nibwp-page-header">
            <div>
                <h1><?php esc_html_e('License', 'nibwp'); ?></h1>
                <p class="nibwp-subtitle"><?php esc_html_e('Activate, manage, and refresh your NIBWP Pro, Bundle, or Skill licenses.', 'nibwp'); ?></p>
            </div>
        </div>

        <?php nibwp_render_license_panel_body(); ?>
    </div>
    <?php
    nibwp_render_admin_footer();
}

/**
 * License panel body — the part that lives inside the page wrap. Kept separate
 * so it can be re-rendered in future contexts (e.g. modals, dashboard widget)
 * without duplicating the markup.
 */
function nibwp_render_license_panel_body(): void
{
    $account = nibwp_account_get();
    $cards = nibwp_license_cards();
    $is_pro = nibwp_is_pro();
    $rest_nonce = wp_create_nonce('wp_rest');
    $pricing_url = nibwp_pricing_url();

    // The hero already branches on this state for its status line and its CSS
    // class, but the field and its hint were written once for the unlicensed
    // case and never followed. On an activated site that left the box saying
    // "paste your license key" and the line under it asking "don't have a
    // license yet?" — directly below a heading confirming the license is
    // active. Keeping the three strings together is what stops one of them
    // drifting out of step with the status again.
    $copy = $is_pro
        ? [
            'placeholder' => __('Paste another license key', 'nibwp'),
            'button'      => __('Add license', 'nibwp'),
        ]
        : [
            'placeholder' => __('Paste your license key here', 'nibwp'),
            'button'      => __('Activate', 'nibwp'),
        ];
    ?>
    <div class="nibwp-license-tab"
         data-rest-nonce="<?php echo esc_attr($rest_nonce); ?>"
         data-rest-root="<?php echo esc_attr(esc_url_raw(rest_url('nibwp/v1/'))); ?>">

        <!-- HERO: primary activation card -->
        <div class="nibwp-license-hero is-<?php echo $is_pro ? 'pro' : ($cards ? 'partial' : 'none'); ?>">
            <div class="nibwp-license-hero__status">
                <span class="nibwp-license-hero__dot"></span>
                <?php if ($is_pro): ?>
                    <strong><?php
                        $plan = nibwp_license_plan_label();
                        /* translators: %s: plan name (Pro, Bundle or Skill) */
                        echo esc_html(sprintf(__('NIBWP %s — Active', 'nibwp'), ucfirst($plan ?: 'Pro')));
                    ?></strong>
                    <span><?php esc_html_e('Premium integrations, toolkits, and skills are unlocked.', 'nibwp'); ?></span>
                <?php else: ?>
                    <strong><?php esc_html_e('Have a license? Activate it.', 'nibwp'); ?></strong>
                    <span>
                        <?php if ($cards): ?>
                            <?php esc_html_e('Stored license is inactive (domain mismatch or expired) — re-paste or refresh below.', 'nibwp'); ?>
                        <?php else: ?>
                            <?php esc_html_e('Paste your key below to unlock NIBWP Pro on this site.', 'nibwp'); ?>
                        <?php endif; ?>
                    </span>
                <?php endif; ?>
            </div>

            <div class="nibwp-license-hero__form">
                <label for="nibwp-license-paste-input" class="screen-reader-text">
                    <?php esc_html_e('License key', 'nibwp'); ?>
                </label>
                <input type="text"
                       id="nibwp-license-paste-input"
                       class="nibwp-license-hero__input"
                       placeholder="<?php echo esc_attr($copy['placeholder']); ?>"
                       spellcheck="false"
                       autocomplete="off" />
                <button type="button"
                        class="button button-primary nibwp-license-hero__btn"
                        id="nibwp-license-paste-activate">
                    <?php echo esc_html($copy['button']); ?>
                </button>
            </div>
            <p class="nibwp-license-paste-msg" id="nibwp-license-paste-msg" hidden></p>

            <p class="nibwp-license-hero__hint">
                <?php if ($is_pro): ?>
                    <?php
                    // One sentence with the link as a placeholder, so a translation can
                    // put the link wherever its grammar needs it. Link and full stop
                    // sit in the same string: whitespace between them renders as a gap.
                    printf(
                        /* translators: %s: link that opens the find-by-email panel */
                        esc_html__('Have another license — a Skill or a Bundle? Enter it above, or %s.', 'nibwp'),
                        '<a href="#" id="nibwp-toggle-email-lookup">' . esc_html__('find my licenses by email', 'nibwp') . '</a>'
                    );
                    ?>
                <?php else: ?>
                    <?php esc_html_e('Don\'t have a license yet?', 'nibwp'); ?>
                    <a href="<?php echo esc_url($pricing_url); ?>" target="_blank" rel="noopener">
                        <?php esc_html_e('Get NIBWP Pro from €49/yr', 'nibwp'); ?> <span class="nw-rtl-flip" aria-hidden="true">→</span>
                    </a>
                    &nbsp;·&nbsp;
                    <a href="#" id="nibwp-toggle-email-lookup">
                        <?php esc_html_e('Find my license by email', 'nibwp'); ?>
                    </a>
                <?php endif; ?>
            </p>
        </div>

        <!-- Stored licenses list (only when at least one stored) -->
        <?php if (!empty($cards)): ?>
            <details class="nibwp-license-card" id="nibwp-managed-licenses" <?php echo count($cards) > 1 ? 'open' : ''; ?>>
                <summary class="nibwp-license-card__summary">
                    <h4>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg>
                        <?php esc_html_e('Manage stored licenses', 'nibwp'); ?>
                        <span class="nibwp-license-count"><?php echo count($cards); ?></span>
                    </h4>
                </summary>
                <div class="nibwp-license-list" id="nibwp-license-list">
                    <?php foreach ($cards as $card): ?>
                        <?php nibwp_render_license_row($card); ?>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endif; ?>

        <!-- Email discovery (collapsible, hidden by default) -->
        <details class="nibwp-license-card" id="nibwp-email-lookup-card" <?php echo $account !== null ? 'open' : ''; ?>>
            <summary class="nibwp-license-card__summary">
                <h4>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22 6 12 13 2 6"/></svg>
                    <?php esc_html_e('Find license by email', 'nibwp'); ?>
                    <?php if ($account !== null): ?>
                        <span class="nibwp-badge is-ok"><?php echo esc_html($account['masked']); ?></span>
                    <?php endif; ?>
                </h4>
            </summary>
            <div class="nibwp-license-card__body">
                <p class="nibwp-license-card__desc">
                    <?php esc_html_e('Forgot your key? Enter the email you used at checkout. If a license exists for that email, we send a 6-digit verification code (check your spam folder too). Once verified, every license tied to that email is listed below and can be activated with one click.', 'nibwp'); ?>
                </p>

                <div class="nibwp-account-block" id="nibwp-account-block">
                    <?php if ($account !== null): ?>
                        <div class="nibwp-account-connected">
                            <div>
                                <span class="nibwp-badge is-ok"><?php esc_html_e('Connected', 'nibwp'); ?></span>
                                <strong><?php echo esc_html($account['masked']); ?></strong>
                            </div>
                            <button type="button" class="button-link" id="nibwp-account-disconnect">
                                <?php esc_html_e('Disconnect', 'nibwp'); ?>
                            </button>
                        </div>
                        <button type="button" class="button button-secondary" id="nibwp-account-relookup">
                            <?php esc_html_e('Find my licenses again', 'nibwp'); ?>
                        </button>
                    <?php else: ?>
                        <div class="nibwp-account-step" data-step="email">
                            <label for="nibwp-account-email" class="screen-reader-text"><?php esc_html_e('Email', 'nibwp'); ?></label>
                            <input type="email"
                                   id="nibwp-account-email"
                                   placeholder="<?php esc_attr_e('your-email@example.com', 'nibwp'); ?>"
                                   autocomplete="email" />
                            <button type="button" class="button button-primary" id="nibwp-account-send-otp">
                                <?php esc_html_e('Send code', 'nibwp'); ?>
                            </button>
                        </div>
                        <div class="nibwp-account-step" data-step="otp" hidden>
                            <p class="nibwp-account-sent-to"></p>
                            <label for="nibwp-account-otp" class="screen-reader-text"><?php esc_html_e('Verification code', 'nibwp'); ?></label>
                            <input type="text"
                                   id="nibwp-account-otp"
                                   inputmode="numeric"
                                   maxlength="6"
                                   placeholder="000000" />
                            <button type="button" class="button button-primary" id="nibwp-account-verify">
                                <?php esc_html_e('Verify', 'nibwp'); ?>
                            </button>
                            <button type="button" class="button-link" id="nibwp-account-resend">
                                <?php esc_html_e('Resend code', 'nibwp'); ?>
                            </button>
                        </div>
                    <?php endif; ?>
                    <p class="nibwp-account-message" id="nibwp-account-message" hidden></p>
                </div>

                <div class="nibwp-discovered-licenses" id="nibwp-discovered-licenses" hidden>
                    <h5><?php esc_html_e('Licenses found for this email:', 'nibwp'); ?></h5>
                    <ul id="nibwp-discovered-list"></ul>
                    <button type="button" class="button button-primary" id="nibwp-discovered-activate-all">
                        <?php esc_html_e('Activate all on this site', 'nibwp'); ?>
                    </button>
                </div>
            </div>
        </details>

        <!-- Pricing / upgrade CTA — helper auto-hides on Bundle tier, picks
             recommended upgrade based on current entitlements, and includes a
             dedicated Lifetime Deals row. Guarded so the wp.org-safe Free build
             (which strips the premium-marked definition out of nibwp.php) does
             not fatal here on first paint. -->
        <?php if (function_exists('nibwp_render_pricing_grid')) { nibwp_render_pricing_grid(); } ?>
    </div>

    <?php nibwp_render_license_page_script(); ?>
    <?php
}

/**
 * Render a single license row inside the stored-licenses list.
 *
 * @param array<string, mixed> $card
 */
function nibwp_render_license_row(array $card): void
{
    $state = (string) ($card['state'] ?? 'invalid');
    ?>
    <div class="nibwp-license-row is-<?php echo esc_attr($state); ?>" data-key="<?php echo esc_attr((string) $card['key']); ?>">
        <div class="nibwp-license-row__main">
            <div class="nibwp-license-row__key">
                <code><?php echo esc_html((string) $card['masked_key']); ?></code>
                <span class="nibwp-badge is-<?php echo $state === 'active' ? 'ok' : 'warn'; ?>">
                    <?php echo $state === 'active'
                        ? esc_html__('Active', 'nibwp')
                        : esc_html__('Inactive', 'nibwp'); ?>
                </span>
            </div>
            <div class="nibwp-license-row__meta">
                <strong><?php echo esc_html(ucfirst((string) $card['product'])); ?></strong>
                <span class="nibwp-license-row__expires"><?php echo esc_html((string) $card['expires']); ?></span>
                <span class="nibwp-license-row__sites"><?php
                    $allowed_sites = (int) ($card['allowed_sites'] ?? 1);
                    printf(
                        /* translators: %s: number of sites the license can be activated on */
                        esc_html(_n('%s site allowed', '%s sites allowed', $allowed_sites, 'nibwp')),
                        esc_html(number_format_i18n($allowed_sites)),
                    );
                ?></span>
            </div>
            <?php if (!empty($card['entitlements'])): ?>
                <div class="nibwp-license-row__entitlements">
                    <?php foreach ((array) $card['entitlements'] as $ent): ?>
                        <?php
                        $code  = (string) $ent;
                        $label = function_exists('nibwp_entitlement_label')
                            ? nibwp_entitlement_label($code)
                            : $code;
                        ?>
                        <span class="nibwp-entitlement-pill" title="<?php echo esc_attr($code); ?>">
                            <?php echo esc_html($label); ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="nibwp-license-row__actions">
            <?php if ($state !== 'active'): ?>
                <button type="button" class="button button-primary nibwp-license-reactivate" data-action="reactivate">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="16" r="1"/><path d="M7 11V7a5 5 0 019.9-1"/><rect x="5" y="11" width="14" height="10" rx="2"/></svg>
                    <span><?php esc_html_e('Reactivate', 'nibwp'); ?></span>
                </button>
            <?php endif; ?>
            <button type="button" class="button-link nibwp-license-refresh" data-action="refresh">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/></svg>
                <span><?php esc_html_e('Refresh', 'nibwp'); ?></span>
            </button>
            <button type="button" class="button-link is-danger nibwp-license-remove" data-action="deactivate">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
                <span><?php esc_html_e('Deactivate', 'nibwp'); ?></span>
            </button>
        </div>
        <div class="nibwp-license-row__msg" hidden></div>
    </div>
    <?php
}

/**
 * Inline JS for the License page interactions: account OTP, paste-activate,
 * per-row deactivate / refresh, discovered-license activation.
 */
function nibwp_render_license_page_script(): void
{
    ?>
    <script>
    (function () {
        var root = document.querySelector('.nibwp-license-tab');
        if (!root) return;
        var nonce = root.dataset.restNonce;
        var apiRoot = root.dataset.restRoot;
        var nibwpLicenseI18n = <?php echo wp_json_encode([
            'enter_email'       => __('Enter your email first.', 'nibwp'),
            'sending_code'      => __('Sending verification code…', 'nibwp'),
            /* translators: %s: the email address the code was sent to */
            'code_on_its_way'   => __('If a license exists for %s, a 6-digit code is on its way. Check your inbox <em>and your spam folder</em>. The code expires in 5 minutes.', 'nibwp'),
            'code_sent'         => __('Code sent. Enter it below to see your licenses.', 'nibwp'),
            'send_failed'       => __('Could not send code.', 'nibwp'),
            'enter_code'        => __('Enter the code from your email.', 'nibwp'),
            'verifying'         => __('Verifying…', 'nibwp'),
            'verified'          => __('Verified.', 'nibwp'),
            'invalid_code'      => __('Invalid code.', 'nibwp'),
            'no_licenses'       => __('No licenses found for this email yet.', 'nibwp'),
            'license'           => __('license', 'nibwp'),
            'lifetime'          => __('Lifetime', 'nibwp'),
            'activate_here'     => __('Activate on this site', 'nibwp'),
            'activating'        => __('Activating…', 'nibwp'),
            'retry'             => __('Retry', 'nibwp'),
            'activation_failed' => __('Activation failed.', 'nibwp'),
            'paste_key'         => __('Paste your license key.', 'nibwp'),
            'reactivating'      => __('Reactivating…', 'nibwp'),
            'deactivating'      => __('Deactivating…', 'nibwp'),
            'refreshing'        => __('Refreshing…', 'nibwp'),
            'operation_failed'  => __('Operation failed. Check the license key and try again.', 'nibwp'),
            'request_failed'    => __('Request failed — check your network and retry.', 'nibwp'),
        ]); ?>;

        function escHtml(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

        function api(path, body, method) {
            method = method || 'POST';
            return fetch(apiRoot + path, {
                method: method,
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                body: method === 'GET' ? undefined : JSON.stringify(body || {}),
            }).then(function (r) { return r.json().then(function (j) { j.__status = r.status; return j; }); });
        }

        function setMsg(el, text, kind) {
            if (!el) return;
            el.hidden = false;
            el.textContent = text;
            el.className = 'nibwp-account-message is-' + (kind || 'info');
        }

        function reloadList() { window.location.reload(); }

        // --- Account OTP flow ---
        var emailStep = root.querySelector('[data-step="email"]');
        var otpStep = root.querySelector('[data-step="otp"]');
        var msgEl = document.getElementById('nibwp-account-message');
        var sendBtn = document.getElementById('nibwp-account-send-otp');
        var verifyBtn = document.getElementById('nibwp-account-verify');
        var emailInput = document.getElementById('nibwp-account-email');
        var otpInput = document.getElementById('nibwp-account-otp');
        var disconnectBtn = document.getElementById('nibwp-account-disconnect');
        var relookupBtn = document.getElementById('nibwp-account-relookup');
        var resendBtn = document.getElementById('nibwp-account-resend');
        var discovered = document.getElementById('nibwp-discovered-licenses');
        var discoveredList = document.getElementById('nibwp-discovered-list');
        var discoveredActivateAll = document.getElementById('nibwp-discovered-activate-all');

        function requestOtp() {
            var email = (emailInput.value || '').trim();
            if (!email) { setMsg(msgEl, nibwpLicenseI18n.enter_email, 'error'); return; }
            sendBtn.disabled = true;
            setMsg(msgEl, nibwpLicenseI18n.sending_code, 'info');
            api('account/request-otp', { email: email }).then(function (res) {
                sendBtn.disabled = false;
                if (res.ok) {
                    emailStep.hidden = true;
                    otpStep.hidden = false;
                    var sentTo = otpStep.querySelector('.nibwp-account-sent-to');
                    if (sentTo) {
                        // A function replacement: a "$" in the address must not be
                        // read as a replacement pattern.
                        sentTo.innerHTML = nibwpLicenseI18n.code_on_its_way.replace('%s', function () {
                            return '<strong>' + escHtml(email) + '</strong>';
                        });
                    }
                    setMsg(msgEl, nibwpLicenseI18n.code_sent, 'success');
                    if (otpInput) otpInput.focus();
                } else {
                    setMsg(msgEl, res.message || nibwpLicenseI18n.send_failed, 'error');
                }
            });
        }
        if (sendBtn) sendBtn.addEventListener('click', requestOtp);
        if (resendBtn) resendBtn.addEventListener('click', requestOtp);

        if (verifyBtn) {
            verifyBtn.addEventListener('click', function () {
                var email = (emailInput.value || '').trim();
                var code = (otpInput.value || '').trim();
                if (!code) { setMsg(msgEl, nibwpLicenseI18n.enter_code, 'error'); return; }
                verifyBtn.disabled = true;
                setMsg(msgEl, nibwpLicenseI18n.verifying, 'info');
                api('account/verify-otp', { email: email, code: code }).then(function (res) {
                    verifyBtn.disabled = false;
                    if (res.ok) {
                        setMsg(msgEl, res.message || nibwpLicenseI18n.verified, 'success');
                        renderDiscovered(res.licenses || []);
                    } else {
                        setMsg(msgEl, res.message || nibwpLicenseI18n.invalid_code, 'error');
                    }
                });
            });
        }

        function renderDiscovered(licenses) {
            if (!discovered || !discoveredList) return;
            if (!licenses.length) {
                discovered.hidden = false;
                discoveredList.innerHTML = '<li>' + escHtml(nibwpLicenseI18n.no_licenses) + '</li>';
                if (discoveredActivateAll) discoveredActivateAll.hidden = true;
                return;
            }
            discoveredList.innerHTML = '';
            licenses.forEach(function (lic) {
                var li = document.createElement('li');
                li.dataset.key = lic.key;
                li.innerHTML = '<div><strong>' + (lic.product || escHtml(nibwpLicenseI18n.license)) + '</strong> '
                    + '<code>' + (lic.key || '') + '</code> '
                    + '<span class="nibwp-license-row__expires">' + (lic.expires_at || escHtml(nibwpLicenseI18n.lifetime)) + '</span></div>'
                    + '<button type="button" class="button button-secondary nibwp-discovered-activate">' + escHtml(nibwpLicenseI18n.activate_here) + '</button>';
                var btn = li.querySelector('.nibwp-discovered-activate');
                btn.addEventListener('click', function () { activateKey(lic.key, btn); });
                discoveredList.appendChild(li);
            });
            discovered.hidden = false;
        }

        function activateKey(key, btn) {
            if (btn) { btn.disabled = true; btn.textContent = nibwpLicenseI18n.activating; }
            api('license/activate', { key: key }).then(function (res) {
                if (res.ok) { reloadList(); }
                else if (btn) { btn.disabled = false; btn.textContent = nibwpLicenseI18n.retry; setMsg(msgEl, res.message || nibwpLicenseI18n.activation_failed, 'error'); }
            });
        }

        if (discoveredActivateAll) {
            discoveredActivateAll.addEventListener('click', function () {
                var rows = discoveredList.querySelectorAll('li[data-key]');
                var keys = Array.prototype.map.call(rows, function (li) { return li.dataset.key; });
                if (!keys.length) return;
                discoveredActivateAll.disabled = true;
                discoveredActivateAll.textContent = nibwpLicenseI18n.activating;
                Promise.all(keys.map(function (k) { return api('license/activate', { key: k }); }))
                    .then(reloadList);
            });
        }

        if (disconnectBtn) {
            disconnectBtn.addEventListener('click', function () {
                api('account/disconnect', {}).then(reloadList);
            });
        }
        if (relookupBtn) {
            relookupBtn.addEventListener('click', function () {
                api('account/disconnect', {}).then(reloadList);
            });
        }

        // --- Manual paste activate ---
        var pasteBtn = document.getElementById('nibwp-license-paste-activate');
        var pasteInput = document.getElementById('nibwp-license-paste-input');
        var pasteMsg = document.getElementById('nibwp-license-paste-msg');
        if (pasteBtn && pasteInput) {
            pasteBtn.addEventListener('click', function () {
                var key = (pasteInput.value || '').trim();
                if (!key) { setMsg(pasteMsg, nibwpLicenseI18n.paste_key, 'error'); return; }
                pasteBtn.disabled = true;
                setMsg(pasteMsg, nibwpLicenseI18n.activating, 'info');
                api('license/activate', { key: key }).then(function (res) {
                    pasteBtn.disabled = false;
                    if (res.ok) { reloadList(); }
                    else { setMsg(pasteMsg, res.message || nibwpLicenseI18n.activation_failed, 'error'); }
                });
            });
        }

        // --- Per-row actions (deactivate / refresh / reactivate) ---
        root.addEventListener('click', function (e) {
            var btn = e.target.closest('button[data-action]');
            if (!btn) return;
            var row = btn.closest('.nibwp-license-row');
            if (!row) return;
            var key = row.dataset.key;
            var action = btn.dataset.action;
            var msg = row.querySelector('.nibwp-license-row__msg');
            var origLabel = btn.querySelector('span') ? btn.querySelector('span').textContent : btn.textContent;
            btn.disabled = true;
            if (btn.querySelector('span')) {
                btn.querySelector('span').textContent = action === 'reactivate' ? nibwpLicenseI18n.reactivating : (action === 'deactivate' ? nibwpLicenseI18n.deactivating : nibwpLicenseI18n.refreshing);
            }
            var endpoint;
            switch (action) {
                case 'reactivate': endpoint = 'license/activate'; break;
                case 'deactivate': endpoint = 'license/deactivate'; break;
                default:           endpoint = 'license/check'; break;
            }
            api(endpoint, { key: key, force: true }).then(function (res) {
                if (res && res.ok === false) {
                    if (msg) {
                        msg.hidden = false;
                        msg.textContent = res.message || nibwpLicenseI18n.operation_failed;
                        msg.className = 'nibwp-license-row__msg is-error';
                    }
                    btn.disabled = false;
                    if (btn.querySelector('span')) btn.querySelector('span').textContent = origLabel;
                    return;
                }
                reloadList();
            }).catch(function () {
                if (msg) {
                    msg.hidden = false;
                    msg.textContent = nibwpLicenseI18n.request_failed;
                    msg.className = 'nibwp-license-row__msg is-error';
                }
                btn.disabled = false;
                if (btn.querySelector('span')) btn.querySelector('span').textContent = origLabel;
            });
        });

        // --- "Find my license by email" hero link → expand details + scroll ---
        var emailLookupToggle = document.getElementById('nibwp-toggle-email-lookup');
        var emailLookupCard = document.getElementById('nibwp-email-lookup-card');
        if (emailLookupToggle && emailLookupCard) {
            emailLookupToggle.addEventListener('click', function (e) {
                e.preventDefault();
                emailLookupCard.open = true;
                emailLookupCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
                var input = document.getElementById('nibwp-account-email');
                if (input) setTimeout(function () { input.focus(); }, 350);
            });
        }
    })();
    </script>
    <?php
}
