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
                <h1><?php esc_html_e('License', domain: 'nibwp'); ?></h1>
                <p class="nibwp-subtitle"><?php esc_html_e('Activate, manage, and refresh your NIBWP Pro, Bundle, or Skill licenses.', domain: 'nibwp'); ?></p>
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
                        echo esc_html(sprintf(__('NIBWP %s — Active', domain: 'nibwp'), ucfirst($plan ?: 'Pro')));
                    ?></strong>
                    <span><?php esc_html_e('Premium integrations, toolkits, and skills are unlocked.', domain: 'nibwp'); ?></span>
                <?php else: ?>
                    <strong><?php esc_html_e('Have a license? Activate it.', domain: 'nibwp'); ?></strong>
                    <span>
                        <?php if ($cards): ?>
                            <?php esc_html_e('Stored license is inactive (domain mismatch or expired) — re-paste or refresh below.', domain: 'nibwp'); ?>
                        <?php else: ?>
                            <?php esc_html_e('Paste your key below to unlock NIBWP Pro on this site.', domain: 'nibwp'); ?>
                        <?php endif; ?>
                    </span>
                <?php endif; ?>
            </div>

            <div class="nibwp-license-hero__form">
                <label for="nibwp-license-paste-input" class="screen-reader-text">
                    <?php esc_html_e('License key', domain: 'nibwp'); ?>
                </label>
                <input type="text"
                       id="nibwp-license-paste-input"
                       class="nibwp-license-hero__input"
                       placeholder="<?php esc_attr_e('Paste your license key here', domain: 'nibwp'); ?>"
                       spellcheck="false"
                       autocomplete="off" />
                <button type="button"
                        class="button button-primary nibwp-license-hero__btn"
                        id="nibwp-license-paste-activate">
                    <?php esc_html_e('Activate', domain: 'nibwp'); ?>
                </button>
            </div>
            <p class="nibwp-license-paste-msg" id="nibwp-license-paste-msg" hidden></p>

            <p class="nibwp-license-hero__hint">
                <?php esc_html_e('Don\'t have a license yet?', domain: 'nibwp'); ?>
                <a href="<?php echo esc_url($pricing_url); ?>" target="_blank" rel="noopener">
                    <?php esc_html_e('Get NIBWP Pro from €49/yr →', domain: 'nibwp'); ?>
                </a>
                &nbsp;·&nbsp;
                <a href="#" id="nibwp-toggle-email-lookup">
                    <?php esc_html_e('Find my license by email', domain: 'nibwp'); ?>
                </a>
            </p>
        </div>

        <!-- Stored licenses list (only when at least one stored) -->
        <?php if (!empty($cards)): ?>
            <details class="nibwp-license-card" id="nibwp-managed-licenses" <?php echo count($cards) > 1 ? 'open' : ''; ?>>
                <summary class="nibwp-license-card__summary">
                    <h4>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg>
                        <?php esc_html_e('Manage stored licenses', domain: 'nibwp'); ?>
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
                    <?php esc_html_e('Find license by email', domain: 'nibwp'); ?>
                    <?php if ($account !== null): ?>
                        <span class="nibwp-badge is-ok"><?php echo esc_html($account['masked']); ?></span>
                    <?php endif; ?>
                </h4>
            </summary>
            <div class="nibwp-license-card__body">
                <p class="nibwp-license-card__desc">
                    <?php esc_html_e('Forgot your key? Enter the email you used at checkout. If a license exists for that email, we send a 6-digit verification code (check your spam folder too). Once verified, every license tied to that email is listed below and can be activated with one click.', domain: 'nibwp'); ?>
                </p>

                <div class="nibwp-account-block" id="nibwp-account-block">
                    <?php if ($account !== null): ?>
                        <div class="nibwp-account-connected">
                            <div>
                                <span class="nibwp-badge is-ok"><?php esc_html_e('Connected', domain: 'nibwp'); ?></span>
                                <strong><?php echo esc_html($account['masked']); ?></strong>
                            </div>
                            <button type="button" class="button-link" id="nibwp-account-disconnect">
                                <?php esc_html_e('Disconnect', domain: 'nibwp'); ?>
                            </button>
                        </div>
                        <button type="button" class="button button-secondary" id="nibwp-account-relookup">
                            <?php esc_html_e('Find my licenses again', domain: 'nibwp'); ?>
                        </button>
                    <?php else: ?>
                        <div class="nibwp-account-step" data-step="email">
                            <label for="nibwp-account-email" class="screen-reader-text"><?php esc_html_e('Email', domain: 'nibwp'); ?></label>
                            <input type="email"
                                   id="nibwp-account-email"
                                   placeholder="<?php esc_attr_e('your-email@example.com', domain: 'nibwp'); ?>"
                                   autocomplete="email" />
                            <button type="button" class="button button-primary" id="nibwp-account-send-otp">
                                <?php esc_html_e('Send code', domain: 'nibwp'); ?>
                            </button>
                        </div>
                        <div class="nibwp-account-step" data-step="otp" hidden>
                            <p class="nibwp-account-sent-to"></p>
                            <label for="nibwp-account-otp" class="screen-reader-text"><?php esc_html_e('Verification code', domain: 'nibwp'); ?></label>
                            <input type="text"
                                   id="nibwp-account-otp"
                                   inputmode="numeric"
                                   maxlength="6"
                                   placeholder="000000" />
                            <button type="button" class="button button-primary" id="nibwp-account-verify">
                                <?php esc_html_e('Verify', domain: 'nibwp'); ?>
                            </button>
                            <button type="button" class="button-link" id="nibwp-account-resend">
                                <?php esc_html_e('Resend code', domain: 'nibwp'); ?>
                            </button>
                        </div>
                    <?php endif; ?>
                    <p class="nibwp-account-message" id="nibwp-account-message" hidden></p>
                </div>

                <div class="nibwp-discovered-licenses" id="nibwp-discovered-licenses" hidden>
                    <h5><?php esc_html_e('Licenses found for this email:', domain: 'nibwp'); ?></h5>
                    <ul id="nibwp-discovered-list"></ul>
                    <button type="button" class="button button-primary" id="nibwp-discovered-activate-all">
                        <?php esc_html_e('Activate all on this site', domain: 'nibwp'); ?>
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
                        ? esc_html__('Active', domain: 'nibwp')
                        : esc_html__('Inactive', domain: 'nibwp'); ?>
                </span>
            </div>
            <div class="nibwp-license-row__meta">
                <strong><?php echo esc_html(ucfirst((string) $card['product'])); ?></strong>
                <span class="nibwp-license-row__expires"><?php echo esc_html((string) $card['expires']); ?></span>
                <span class="nibwp-license-row__sites"><?php
                    printf(
                        esc_html__('%d site(s) allowed', domain: 'nibwp'),
                        (int) ($card['allowed_sites'] ?? 1),
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
            if (!email) { setMsg(msgEl, 'Enter your email first.', 'error'); return; }
            sendBtn.disabled = true;
            setMsg(msgEl, 'Sending verification code…', 'info');
            api('account/request-otp', { email: email }).then(function (res) {
                sendBtn.disabled = false;
                if (res.ok) {
                    emailStep.hidden = true;
                    otpStep.hidden = false;
                    var sentTo = otpStep.querySelector('.nibwp-account-sent-to');
                    if (sentTo) {
                        sentTo.innerHTML = 'If a license exists for <strong>' + email + '</strong>, a 6-digit code is on its way. '
                            + 'Check your inbox <em>and your spam folder</em>. The code expires in 5 minutes.';
                    }
                    setMsg(msgEl, 'Code sent. Enter it below to see your licenses.', 'success');
                    if (otpInput) otpInput.focus();
                } else {
                    setMsg(msgEl, res.message || 'Could not send code.', 'error');
                }
            });
        }
        if (sendBtn) sendBtn.addEventListener('click', requestOtp);
        if (resendBtn) resendBtn.addEventListener('click', requestOtp);

        if (verifyBtn) {
            verifyBtn.addEventListener('click', function () {
                var email = (emailInput.value || '').trim();
                var code = (otpInput.value || '').trim();
                if (!code) { setMsg(msgEl, 'Enter the code from your email.', 'error'); return; }
                verifyBtn.disabled = true;
                setMsg(msgEl, 'Verifying…', 'info');
                api('account/verify-otp', { email: email, code: code }).then(function (res) {
                    verifyBtn.disabled = false;
                    if (res.ok) {
                        setMsg(msgEl, res.message || 'Verified.', 'success');
                        renderDiscovered(res.licenses || []);
                    } else {
                        setMsg(msgEl, res.message || 'Invalid code.', 'error');
                    }
                });
            });
        }

        function renderDiscovered(licenses) {
            if (!discovered || !discoveredList) return;
            if (!licenses.length) {
                discovered.hidden = false;
                discoveredList.innerHTML = '<li>No licenses found for this email yet.</li>';
                if (discoveredActivateAll) discoveredActivateAll.hidden = true;
                return;
            }
            discoveredList.innerHTML = '';
            licenses.forEach(function (lic) {
                var li = document.createElement('li');
                li.dataset.key = lic.key;
                li.innerHTML = '<div><strong>' + (lic.product || 'license') + '</strong> '
                    + '<code>' + (lic.key || '') + '</code> '
                    + '<span class="nibwp-license-row__expires">' + (lic.expires_at || 'Lifetime') + '</span></div>'
                    + '<button type="button" class="button button-secondary nibwp-discovered-activate">Activate on this site</button>';
                var btn = li.querySelector('.nibwp-discovered-activate');
                btn.addEventListener('click', function () { activateKey(lic.key, btn); });
                discoveredList.appendChild(li);
            });
            discovered.hidden = false;
        }

        function activateKey(key, btn) {
            if (btn) { btn.disabled = true; btn.textContent = 'Activating…'; }
            api('license/activate', { key: key }).then(function (res) {
                if (res.ok) { reloadList(); }
                else if (btn) { btn.disabled = false; btn.textContent = 'Retry'; setMsg(msgEl, res.message || 'Activation failed.', 'error'); }
            });
        }

        if (discoveredActivateAll) {
            discoveredActivateAll.addEventListener('click', function () {
                var rows = discoveredList.querySelectorAll('li[data-key]');
                var keys = Array.prototype.map.call(rows, function (li) { return li.dataset.key; });
                if (!keys.length) return;
                discoveredActivateAll.disabled = true;
                discoveredActivateAll.textContent = 'Activating…';
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
                if (!key) { setMsg(pasteMsg, 'Paste your license key.', 'error'); return; }
                pasteBtn.disabled = true;
                setMsg(pasteMsg, 'Activating…', 'info');
                api('license/activate', { key: key }).then(function (res) {
                    pasteBtn.disabled = false;
                    if (res.ok) { reloadList(); }
                    else { setMsg(pasteMsg, res.message || 'Activation failed.', 'error'); }
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
                btn.querySelector('span').textContent = action === 'reactivate' ? 'Reactivating…' : (action === 'deactivate' ? 'Deactivating…' : 'Refreshing…');
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
                        msg.textContent = res.message || 'Operation failed. Check the license key and try again.';
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
                    msg.textContent = 'Request failed — check your network and retry.';
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
