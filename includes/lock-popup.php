<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Smart lock-popup component — opened when the user clicks a locked Pro
 * integration or skill card. Lets them activate a key (paste or via email
 * lookup) without leaving the page, or click through to checkout.
 *
 * Usage from the locked card markup (any page):
 *
 *     <button class="nw-lock-trigger"
 *             data-context="integration:elementor"
 *             data-product="pro"
 *             data-label="Elementor"
 *             data-price="€49/yr">Unlock</button>
 *
 * The popup is rendered ONCE per page (singleton) via
 * `nibwp_render_lock_popup()` — call it from the admin footer hook so it sits
 * outside any flex/grid container.
 */

add_action('nibwp_render_admin_footer_after', 'nibwp_render_lock_popup');
add_action('admin_footer', 'nibwp_render_lock_popup');

function nibwp_render_lock_popup(): void
{
    static $rendered = false;
    if ($rendered) {
        return;
    }
    // Only render on NIBWP admin pages.
    $page = isset($_GET['page']) ? (string) $_GET['page'] : '';
    if (!str_starts_with($page, 'nibwp')) {
        return;
    }
    $rendered = true;

    $rest_nonce = wp_create_nonce('wp_rest');
    $pricing_url = nibwp_pricing_url();
    ?>
    <div class="nw-lock-popup" id="nw-lock-popup" role="dialog" aria-modal="true" aria-hidden="true"
         data-rest-nonce="<?php echo esc_attr($rest_nonce); ?>"
         data-rest-root="<?php echo esc_attr(esc_url_raw(rest_url('nibwp/v1/'))); ?>"
         data-pricing-url="<?php echo esc_attr($pricing_url); ?>"
         data-item-url="<?php echo esc_attr(nibwp_item_url()); ?>">

        <div class="nw-lock-popup__backdrop" id="nw-lock-popup-backdrop"></div>

        <div class="nw-lock-popup__panel" role="document">
            <button type="button" class="nw-lock-popup__close" id="nw-lock-popup-close" aria-label="<?php esc_attr_e('Close', 'nibwp'); ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>

            <div class="nw-lock-popup__head">
                <div class="nw-lock-popup__icon">
                    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                </div>
                <h3 id="nw-lock-popup-title"><?php esc_html_e('This is a Pro feature', 'nibwp'); ?></h3>
                <p id="nw-lock-popup-subtitle"><?php esc_html_e('Activate a license or unlock with NIBWP Pro / Bundle.', 'nibwp'); ?></p>
            </div>

            <div class="nw-lock-popup__body">
                <!-- Already bought block -->
                <div class="nw-lock-popup__section">
                    <h4><?php esc_html_e('Already bought a license?', 'nibwp'); ?></h4>
                    <div class="nw-lock-popup__paste">
                        <input type="text"
                               id="nw-lock-key"
                               placeholder="<?php esc_attr_e('Paste your license key', 'nibwp'); ?>"
                               spellcheck="false"
                               autocomplete="off" />
                        <button type="button" class="button button-primary" id="nw-lock-activate">
                            <?php esc_html_e('Activate', 'nibwp'); ?>
                        </button>
                    </div>
                    <p class="nw-lock-popup__or"><?php esc_html_e('— or —', 'nibwp'); ?></p>
                    <a class="button button-secondary" id="nw-lock-find-licenses"
                       href="<?php echo esc_url(admin_url('admin.php?page=nibwp-license')); ?>">
                        <?php esc_html_e('Find my licenses by email', 'nibwp'); ?>
                    </a>
                    <p class="nw-lock-popup__msg" id="nw-lock-msg" hidden></p>
                </div>

                <!-- Buy CTA block — left/right cards swap based on context (integration vs skill) -->
                <div class="nw-lock-popup__section is-buy">
                    <h4><?php esc_html_e('Don\'t have one yet?', 'nibwp'); ?></h4>
                    <div class="nw-lock-popup__pricing">
                        <a class="nw-lock-price" id="nw-lock-buy-primary" href="<?php echo esc_url(nibwp_item_url('pro')); ?>" target="_blank" rel="noopener">
                            <span class="nw-lock-price__tier" id="nw-lock-buy-primary-tier"><?php esc_html_e('NIBWP Pro', 'nibwp'); ?></span>
                            <span class="nw-lock-price__amount" id="nw-lock-buy-primary-amount">&euro;49<small><?php echo esc_html_x('/yr', 'price per year', 'nibwp'); ?></small></span>
                            <span class="nw-lock-price__meta" id="nw-lock-buy-primary-meta"><?php esc_html_e('All premium integrations', 'nibwp'); ?></span>
                        </a>
                        <a class="nw-lock-price is-featured" id="nw-lock-buy-bundle" href="<?php echo esc_url(nibwp_item_url('bundle')); ?>" target="_blank" rel="noopener">
                            <span class="nw-lock-price__badge"><?php esc_html_e('Best value', 'nibwp'); ?></span>
                            <span class="nw-lock-price__tier"><?php esc_html_e('NIBWP Bundle', 'nibwp'); ?></span>
                            <span class="nw-lock-price__amount">&euro;79<small><?php echo esc_html_x('/yr', 'price per year', 'nibwp'); ?></small></span>
                            <span class="nw-lock-price__meta"><?php esc_html_e('Pro + every skill (current + future)', 'nibwp'); ?></span>
                        </a>
                    </div>
                    <a class="nw-lock-popup__compare" href="<?php echo esc_url($pricing_url); ?>" target="_blank" rel="noopener">
                        <?php esc_html_e('Compare all plans (LTD, skills, agency)', 'nibwp'); ?> <span class="nw-rtl-flip" aria-hidden="true">→</span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function () {
        var popup = document.getElementById('nw-lock-popup');
        if (!popup) return;
        var backdrop = document.getElementById('nw-lock-popup-backdrop');
        var closeBtn = document.getElementById('nw-lock-popup-close');
        var titleEl = document.getElementById('nw-lock-popup-title');
        var subEl = document.getElementById('nw-lock-popup-subtitle');
        var keyInput = document.getElementById('nw-lock-key');
        var activateBtn = document.getElementById('nw-lock-activate');
        var msgEl = document.getElementById('nw-lock-msg');
        var buyPrimary = document.getElementById('nw-lock-buy-primary');
        var buyPrimaryTier = document.getElementById('nw-lock-buy-primary-tier');
        var buyPrimaryAmount = document.getElementById('nw-lock-buy-primary-amount');
        var buyPrimaryMeta = document.getElementById('nw-lock-buy-primary-meta');
        var buyBundle = document.getElementById('nw-lock-buy-bundle');
        var pricingBase = popup.dataset.pricingUrl;
        var itemBase = popup.dataset.itemUrl || pricingBase;
        var nonce = popup.dataset.restNonce;
        var apiRoot = popup.dataset.restRoot;
        var nibwpLockPopupI18n = <?php echo wp_json_encode([
            'this_feature'      => __('this feature', 'nibwp'),
            /* translators: %s: skill name */
            'skill_title'       => __('%s — Skill feature', 'nibwp'),
            /* translators: %s: skill name */
            'skill_subtitle'    => __('Activate the %s Skill license or the Bundle to unlock it.', 'nibwp'),
            /* translators: %s: skill name */
            'skill_tier'        => __('%s Skill', 'nibwp'),
            /* translators: %s: skill name */
            'skill_meta'        => __('Only this skill (%s)', 'nibwp'),
            /* translators: %s: integration or feature name */
            'pro_title'         => __('%s — Pro feature', 'nibwp'),
            /* translators: %s: integration or feature name */
            'pro_subtitle'      => __('Activate a Pro or Bundle license to unlock %s.', 'nibwp'),
            'pro_tier'          => __('NIBWP Pro', 'nibwp'),
            'pro_meta'          => __('All premium integrations', 'nibwp'),
            'per_year'          => _x('/yr', 'price per year', 'nibwp'),
            'paste_key_first'   => __('Paste your license key first.', 'nibwp'),
            'activating'        => __('Activating…', 'nibwp'),
            'activated'         => __('Activated. Reloading…', 'nibwp'),
            'activation_failed' => __('Activation failed.', 'nibwp'),
        ]); ?>;

        // Fill a translated "%s" string. A function replacement, so a "$" in
        // the name is never read as a replacement pattern.
        function fill(text, value) {
            return text.replace('%s', function () { return value; });
        }

        function escHtml(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

        function api(path, body) {
            return fetch(apiRoot + path, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                body: JSON.stringify(body || {}),
            }).then(function (r) { return r.json(); });
        }

        function open(ctx) {
            // ctx: { context: 'integration:elementor' | 'skill:bricks', label, product, price }
            var label = ctx.label || nibwpLockPopupI18n.this_feature;
            var product = ctx.product || 'pro';
            var ctxSlug = (ctx.context || '').replace('skill:', '').replace('integration:', '');

            if (product === 'skill') {
                titleEl.textContent = fill(nibwpLockPopupI18n.skill_title, label);
                subEl.textContent = fill(nibwpLockPopupI18n.skill_subtitle, label);
                // Primary card becomes the specific Skill license (NOT Pro — Pro
                // doesn't unlock skills).
                if (buyPrimary && ctxSlug) {
                    buyPrimary.href = itemBase + '/' + ctxSlug + '-skill';
                }
                if (buyPrimaryTier) buyPrimaryTier.textContent = fill(nibwpLockPopupI18n.skill_tier, label);
                if (buyPrimaryAmount) buyPrimaryAmount.innerHTML = '€49<small>' + escHtml(nibwpLockPopupI18n.per_year) + '</small>';
                if (buyPrimaryMeta) buyPrimaryMeta.textContent = fill(nibwpLockPopupI18n.skill_meta, label);
            } else {
                titleEl.textContent = fill(nibwpLockPopupI18n.pro_title, label);
                subEl.textContent = fill(nibwpLockPopupI18n.pro_subtitle, label);
                if (buyPrimary) buyPrimary.href = itemBase + '/pro';
                if (buyPrimaryTier) buyPrimaryTier.textContent = nibwpLockPopupI18n.pro_tier;
                if (buyPrimaryAmount) buyPrimaryAmount.innerHTML = '€49<small>' + escHtml(nibwpLockPopupI18n.per_year) + '</small>';
                if (buyPrimaryMeta) buyPrimaryMeta.textContent = nibwpLockPopupI18n.pro_meta;
            }
            if (buyBundle) buyBundle.href = itemBase + '/bundle';
            popup.classList.add('is-open');
            popup.setAttribute('aria-hidden', 'false');
            document.body.classList.add('nw-onb-locked');
            setTimeout(function () { if (keyInput) keyInput.focus(); }, 50);
        }
        function close() {
            popup.classList.remove('is-open');
            popup.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('nw-onb-locked');
            if (msgEl) { msgEl.hidden = true; msgEl.textContent = ''; }
            if (keyInput) keyInput.value = '';
        }

        closeBtn.addEventListener('click', close);
        backdrop.addEventListener('click', close);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && popup.classList.contains('is-open')) close();
        });

        // Trigger from any element with .nw-lock-trigger anywhere on the page.
        document.addEventListener('click', function (e) {
            var trigger = e.target.closest('.nw-lock-trigger');
            if (!trigger) return;
            e.preventDefault();
            open({
                context: trigger.dataset.context || '',
                product: trigger.dataset.product || 'pro',
                label:   trigger.dataset.label || '',
                price:   trigger.dataset.price || '',
            });
        });

        activateBtn.addEventListener('click', function () {
            var key = (keyInput.value || '').trim();
            if (!key) { msgEl.hidden = false; msgEl.textContent = nibwpLockPopupI18n.paste_key_first; msgEl.className = 'nw-lock-popup__msg is-error'; return; }
            activateBtn.disabled = true;
            msgEl.hidden = false;
            msgEl.textContent = nibwpLockPopupI18n.activating;
            msgEl.className = 'nw-lock-popup__msg is-info';
            api('license/activate', { key: key }).then(function (res) {
                activateBtn.disabled = false;
                if (res.ok) {
                    msgEl.textContent = '✓ ' + (res.message || nibwpLockPopupI18n.activated);
                    msgEl.className = 'nw-lock-popup__msg is-success';
                    setTimeout(function () { window.location.reload(); }, 800);
                } else {
                    msgEl.textContent = res.message || nibwpLockPopupI18n.activation_failed;
                    msgEl.className = 'nw-lock-popup__msg is-error';
                }
            });
        });

        // Expose for programmatic open if a page wants to call it directly.
        window.nibwpLockPopupOpen = open;
    })();
    </script>
    <?php
}
