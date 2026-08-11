<?php

declare(strict_types=1);

/**
 * Plugin Name: NIBWP
 * Plugin URI: https://www.nibwp.com
 * Description: Turns your WordPress site into a Model Context Protocol (MCP) server so AI agents like Claude Code and ChatGPT can read posts, terms, users, media, options, search, and a key-value memory store through a standard, permissioned interface.
 * Version: 1.2.0
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Author: NIBWP
 * Author URI: https://www.nibwp.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: nibwp
 * Domain Path: /languages
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2, as
 * published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, see <https://www.gnu.org/licenses/gpl-2.0.html>.
 */

if (!defined('ABSPATH')) {
    exit();
}

define(constant_name: 'NIBWP_VERSION', value: '1.2.0');
define(constant_name: 'NIBWP_MAX_EXECUTION_TIME', value: 30);
define('NIBWP_PLUGIN_FILE', __FILE__);
define('NIBWP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('NIBWP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('NIBWP_SANDBOX_DIR', WP_CONTENT_DIR . '/nibwp-sandbox/');
define(constant_name: 'NIBWP_VENDOR_AUTOLOAD', value: __DIR__ . '/vendor/autoload_packages.php');
define(constant_name: 'NIBWP_MCP_ADAPTER_CLASS', value: 'WP\\MCP\\Core\\McpAdapter');

/**
 * Load translations. Text domain `nibwp` is used across the Free plugin and
 * every paid add-on (nibwp-pro, nibwp-skill-*) so translators only maintain
 * one .pot file. The `init` hook is the earliest WordPress-approved point to
 * call load_plugin_textdomain() (see WP 6.7 deprecation of "just-in-time"
 * textdomain loading).
 */
add_action('init', static function (): void {
    load_plugin_textdomain('nibwp', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

// Premium build detection. The Free wp.org build ships without the
// includes/premium/ directory or the license client; nibwp_is_pro() therefore
// always returns false on a Free install. The standalone Pro plugin
// (nibwp-pro) and Skill plugins (nibwp-skill-*) ship their own license client
// + premium content. In the monorepo dev tree, premium/bootstrap.php is loaded
// from here so local development sees the full Pro experience.
define('NIBWP_PREMIUM_DIR', __DIR__ . '/includes/premium/');
define('NIBWP_HAS_PREMIUM_CODE', file_exists(NIBWP_PREMIUM_DIR . 'bootstrap.php'));

// In the monorepo dev tree the real license client lives at
// includes/license.php. Load it BEFORE the stubs below so the real functions
// register first. In the Free wp.org build, license.php is stripped at build
// time — this require is a no-op there and the stubs become live fallbacks.
$nibwp_license_client_path = __DIR__ . '/includes/license.php';
if (file_exists($nibwp_license_client_path)) {
    require_once $nibwp_license_client_path;
}
unset($nibwp_license_client_path);

if (NIBWP_HAS_PREMIUM_CODE) {
    require_once NIBWP_PREMIUM_DIR . 'bootstrap.php';
}

/**
 * Free-build stubs for license APIs.
 *
 * In the Free wp.org build the license client (includes/license.php) is
 * stripped at build time, so functions like nibwp_is_pro() are never defined.
 * Declare safe no-op stubs here, guarded with function_exists() so the real
 * implementations (shipped by the standalone Pro plugin / Skill plugins, or
 * loaded above in monorepo mode) win when present.
 */
if (!function_exists('nibwp_is_pro')) {
    function nibwp_is_pro(): bool { return false; }
}
if (!function_exists('nibwp_has_entitlement')) {
    function nibwp_has_entitlement(string $code): bool { return false; }
}
if (!function_exists('nibwp_entitlements')) {
    function nibwp_entitlements(): array { return []; }
}
if (!function_exists('nibwp_any_license_active')) {
    function nibwp_any_license_active(): bool { return false; }
}
if (!function_exists('nibwp_pricing_url')) {
    function nibwp_pricing_url(): string { return 'https://nibwp.com/pricing'; }
}
if (!function_exists('nibwp_item_url')) {
    function nibwp_item_url(string $slug = ''): string { return 'https://nibwp.com/item' . ($slug !== '' ? '/' . ltrim($slug, '/') : ''); }
}
if (!function_exists('nibwp_license_plan_label')) {
    function nibwp_license_plan_label(): string { return ''; }
}
if (!function_exists('nibwp_licenses_get')) {
    function nibwp_licenses_get(): array { return []; }
}
if (!function_exists('nibwp_license_server')) {
    function nibwp_license_server(): string { return 'https://nibwp.com'; }
}

/**
 * Render the pricing plans grid — two-tier (Pro vs Bundle) with full feature
 * lists. Auto-hides when the user already has the top tier (Bundle). Pro
 * holders see only the Bundle upgrade column.
 *
 * Shipped in Free + Pro so the License admin page can render upgrade CTAs.
 * The wp.org submission build (build-wporg.sh) writes its own minimal entry
 * file and never includes this nibwp.php, so the function does not need to
 * be premium-stripped here.
 */
if (!function_exists('nibwp_render_pricing_grid')) {
    function nibwp_render_pricing_grid(): void
    {
        $entitlements = function_exists('nibwp_entitlements') ? nibwp_entitlements() : [];
        $has_pro    = in_array('pro', $entitlements, true);
        $has_bundle = $has_pro && in_array('skill:*', $entitlements, true);

        // Bundle = top tier. No upgrade possible.
        if ($has_bundle) {
            return;
        }

        $pricing_url = nibwp_pricing_url();

        // Per-tier price matrix [pro|bundle][yr|ltd][1|5|25|100].
        $prices = [
            // Yearly: user-defined per-tier rates (all end in 9 for price psychology).
            // Lifetime: ~yearly × 2.5, rounded UP to nearest 9-ending integer.
            'pro' => [
                'yr'  => [1 => 49,  5 => 89,  25 => 119, 100 => 149],
                'ltd' => [1 => 99,  5 => 179, 25 => 239, 100 => 299],
            ],
            'bundle' => [
                'yr'  => [1 => 79,  5 => 149, 25 => 199, 100 => 249],
                'ltd' => [1 => 179, 5 => 299, 25 => 399, 100 => 499],
            ],
        ];
        $prices_json = wp_json_encode($prices);

        $pro_features = [
            __('26 premium integrations across 10 categories',                                                          'nibwp'),
            __('Page builders — Elementor, Bricks, EtchWP, ACSS',                                                        'nibwp'),
            __('Forms — Gravity / WPForms / Fluent Forms / Contact Form 7 / Ninja / Formidable & many others', 'nibwp'),
            __('Custom fields & content types — ACF, JetEngine, Meta Box, Pods, ACPT, ASE',                              'nibwp'),
            __('E-commerce & CRM — FluentCart, EDD, FluentCRM',                                                          'nibwp'),
            __('LMS & memberships — LearnDash, LifterLMS, MemberPress',                                                  'nibwp'),
            __('Community & events — BuddyPress, The Events Calendar, GiveWP',                                           'nibwp'),
            __('Utilities — Redirection, TablePress, WPML, TranslatePress, WP Job Manager',                              'nibwp'),
            __('Toolkits — Security, Notifications, Migration, Content Planner, SEO Advanced',                           'nibwp'),
            __('Sandboxed PHP execution + file ops + full audit log',                                                    'nibwp'),
        ];
        $bundle_features = [
            __('Everything in NIBWP Pro',                                 'nibwp'),
            __('EtchWP Pro skill — image / HTML / Figma → components',    'nibwp'),
            __('Bricks Pro skill — vision → Bricks + ACSS recipes',       'nibwp'),
            __('Every future skill pack auto-unlocked',                   'nibwp'),
            __('Curated AI playbooks for every integration',              'nibwp'),
            __('Priority support + early access',                         'nibwp'),
        ];
        $check_svg = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>';
        ?>
        <div class="nw-pricing"
             data-prices='<?php echo esc_attr($prices_json); ?>'
             data-period="yr"
             data-sites="1">
            <header class="nw-pricing__head">
                <h2 class="nw-pricing__title">
                    <?php echo $has_pro
                        ? esc_html__('Upgrade to NIBWP Bundle', 'nibwp')
                        : esc_html__('Choose your plan', 'nibwp'); ?>
                </h2>
                <p class="nw-pricing__sub">
                    <?php echo $has_pro
                        ? esc_html__('Unlock every skill — current and future — for a single price. Cancel anytime.', 'nibwp')
                        : esc_html__('Per-site licensing, lifetime options, every integration future-proofed. Cancel anytime.', 'nibwp'); ?>
                </p>

                <div class="nw-pricing__controls">
                    <div class="nw-pricing__seg" role="tablist" aria-label="<?php esc_attr_e('Billing period', 'nibwp'); ?>">
                        <button type="button" class="nw-pricing__seg-btn is-on" data-period="yr" role="tab" aria-selected="true">
                            <?php esc_html_e('Yearly', 'nibwp'); ?>
                        </button>
                        <button type="button" class="nw-pricing__seg-btn" data-period="ltd" role="tab" aria-selected="false">
                            <?php esc_html_e('Lifetime', 'nibwp'); ?>
                            <span class="nw-pricing__seg-chip"><?php esc_html_e('Pay once', 'nibwp'); ?></span>
                        </button>
                    </div>

                    <div class="nw-pricing__sites" role="radiogroup" aria-label="<?php esc_attr_e('Sites', 'nibwp'); ?>">
                        <span class="nw-pricing__sites-label"><?php esc_html_e('Sites', 'nibwp'); ?></span>
                        <?php foreach ([1, 5, 25, 100] as $n): ?>
                            <button type="button"
                                    class="nw-pricing__sites-btn<?php echo $n === 1 ? ' is-on' : ''; ?>"
                                    data-sites="<?php echo (int) $n; ?>"
                                    role="radio"
                                    aria-checked="<?php echo $n === 1 ? 'true' : 'false'; ?>">
                                <?php echo (int) $n; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </header>

            <div class="nw-pricing__grid<?php echo $has_pro ? ' is-single' : ''; ?>">
                <?php if (!$has_pro): ?>
                <article class="nw-plan" data-plan="pro">
                    <header class="nw-plan__head">
                        <span class="nw-plan__tier"><?php esc_html_e('NIBWP Pro', 'nibwp'); ?></span>
                        <p class="nw-plan__pitch"><?php esc_html_e('Every premium integration + every toolkit. Single per-site license.', 'nibwp'); ?></p>
                    </header>
                    <div class="nw-plan__price">
                        <span class="nw-plan__amt">&euro;<span data-price="pro">49</span></span>
                        <span class="nw-plan__per"><span class="nw-plan__per-period" data-period-label-yr><?php esc_html_e('per year', 'nibwp'); ?></span><span class="nw-plan__per-period" data-period-label-ltd hidden><?php esc_html_e('one-time payment', 'nibwp'); ?></span><br><span class="nw-plan__per-sites"><span data-sites-label>1</span> <?php esc_html_e('site', 'nibwp'); ?></span></span>
                    </div>
                    <p class="nw-plan__per-site" data-plan-per-site="pro">
                        <strong>&euro;<span data-per-site="pro">4.08</span></strong>
                        <span class="nw-plan__per-site-label" data-per-site-label-yr><?php esc_html_e('per site / month', 'nibwp'); ?></span>
                        <span class="nw-plan__per-site-label" data-per-site-label-ltd hidden><?php esc_html_e('per site, lifetime', 'nibwp'); ?></span>
                        <span class="nw-plan__per-site-save" data-save="pro" hidden></span>
                    </p>
                    <ul class="nw-plan__features">
                        <?php foreach ($pro_features as $feature): ?>
                            <li><span class="nw-plan__check"><?php echo $check_svg; ?></span><?php echo esc_html($feature); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <a class="nw-plan__cta" href="<?php echo esc_url(nibwp_item_url('pro')); ?>" target="_blank" rel="noopener">
                        <?php esc_html_e('Get NIBWP Pro', 'nibwp'); ?>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                    </a>
                    <p class="nw-plan__foot"><?php esc_html_e('15-day refund · No setup fee', 'nibwp'); ?></p>
                </article>
                <?php endif; ?>

                <article class="nw-plan is-featured" data-plan="bundle">
                    <span class="nw-plan__ribbon"><?php esc_html_e('★ Most popular', 'nibwp'); ?></span>
                    <header class="nw-plan__head">
                        <span class="nw-plan__tier"><?php esc_html_e('NIBWP Bundle', 'nibwp'); ?></span>
                        <p class="nw-plan__pitch"><?php esc_html_e('Pro + every skill pack — current and future — under one license.', 'nibwp'); ?></p>
                    </header>
                    <div class="nw-plan__price">
                        <span class="nw-plan__amt">&euro;<span data-price="bundle">79</span></span>
                        <span class="nw-plan__per"><span class="nw-plan__per-period" data-period-label-yr><?php esc_html_e('per year', 'nibwp'); ?></span><span class="nw-plan__per-period" data-period-label-ltd hidden><?php esc_html_e('one-time payment', 'nibwp'); ?></span><br><span class="nw-plan__per-sites"><span data-sites-label>1</span> <?php esc_html_e('site', 'nibwp'); ?></span></span>
                    </div>
                    <p class="nw-plan__per-site is-featured" data-plan-per-site="bundle">
                        <strong>&euro;<span data-per-site="bundle">6.58</span></strong>
                        <span class="nw-plan__per-site-label" data-per-site-label-yr><?php esc_html_e('per site / month', 'nibwp'); ?></span>
                        <span class="nw-plan__per-site-label" data-per-site-label-ltd hidden><?php esc_html_e('per site, lifetime', 'nibwp'); ?></span>
                        <span class="nw-plan__per-site-save" data-save="bundle" hidden></span>
                    </p>
                    <ul class="nw-plan__features">
                        <?php foreach ($bundle_features as $feature): ?>
                            <li><span class="nw-plan__check is-gold"><?php echo $check_svg; ?></span><?php echo esc_html($feature); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <a class="nw-plan__cta is-featured" href="<?php echo esc_url(nibwp_item_url('bundle')); ?>" target="_blank" rel="noopener">
                        <?php $has_pro
                            ? esc_html_e('Upgrade to Bundle', 'nibwp')
                            : esc_html_e('Get the Bundle', 'nibwp'); ?>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                    </a>
                    <p class="nw-plan__foot"><?php esc_html_e('15-day refund · Priority support', 'nibwp'); ?></p>
                </article>
            </div>

            <ul class="nw-pricing__trust" aria-label="<?php esc_attr_e('Trust signals', 'nibwp'); ?>">
                <li><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg> <?php esc_html_e('Domain-locked licenses — your sites only', 'nibwp'); ?></li>
                <li><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> <?php esc_html_e('Instant activation', 'nibwp'); ?></li>
                <li><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l3-9 4 18 3-9h4"/></svg> <?php esc_html_e('Auto-updates from nibwp.com', 'nibwp'); ?></li>
                <li><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> <?php esc_html_e('Cancel anytime', 'nibwp'); ?></li>
            </ul>
        </div>

        <?php
        // Pricing CSS lives in assets/css/admin-pricing.css and is enqueued
        // by includes/admin-styles.php on every NIBWP admin page. Nothing
        // inline here — keeps view free of style noise.
        ?>

        <script>
        (function(){
            var root = document.currentScript ? document.currentScript.previousElementSibling : null;
            // Find the most recently rendered pricing block.
            var blocks = document.querySelectorAll('.nw-pricing:not([data-bound])');
            blocks.forEach(function(box){
                box.setAttribute('data-bound', '1');
                var prices = {};
                try { prices = JSON.parse(box.dataset.prices || '{}'); } catch(e){}
                var period = 'yr';
                var sites  = 1;

                function fmt(n){
                    // Up to 2 decimals when needed, else integer. Thin grouping.
                    var rounded = Math.round(n * 100) / 100;
                    return rounded.toLocaleString('en-US', {
                        minimumFractionDigits: rounded % 1 === 0 ? 0 : 2,
                        maximumFractionDigits: 2
                    });
                }
                function refresh(){
                    box.querySelectorAll('[data-price]').forEach(function(el){
                        var plan = el.dataset.price;
                        var p = (prices[plan] || {})[period] || {};
                        var v = p[sites];
                        if (typeof v === 'number') el.textContent = v.toLocaleString('en-US');
                    });

                    // Per-site rate.
                    //   yearly  → price / sites / 12  ("per site / month")
                    //   lifetime → price / sites      ("per site, lifetime")
                    box.querySelectorAll('[data-per-site]').forEach(function(el){
                        var plan = el.dataset.perSite;
                        var p = (prices[plan] || {})[period] || {};
                        var total = p[sites] || 0;
                        var base1 = (prices[plan] || {})[period] && (prices[plan])[period][1] || total;
                        var perSite = period === 'yr' ? (total / sites / 12) : (total / sites);
                        var base    = period === 'yr' ? (base1 / 12)        : base1;
                        el.textContent = fmt(perSite);

                        // Save badge — discount vs 1-site rate.
                        var save = Math.round((1 - perSite / base) * 100);
                        var badge = box.querySelector('[data-save="' + plan + '"]');
                        if (badge) {
                            if (save > 0 && sites > 1) {
                                badge.textContent = 'Save ' + save + '%';
                                badge.hidden = false;
                            } else {
                                badge.hidden = true;
                                badge.textContent = '';
                            }
                        }
                    });

                    box.querySelectorAll('[data-sites-label]').forEach(function(el){ el.textContent = sites; });
                    box.querySelectorAll('[data-period-label-yr]').forEach(function(el){ el.hidden = (period !== 'yr'); });
                    box.querySelectorAll('[data-period-label-ltd]').forEach(function(el){ el.hidden = (period !== 'ltd'); });
                    box.querySelectorAll('[data-per-site-label-yr]').forEach(function(el){ el.hidden = (period !== 'yr'); });
                    box.querySelectorAll('[data-per-site-label-ltd]').forEach(function(el){ el.hidden = (period !== 'ltd'); });
                }
                refresh();

                box.querySelectorAll('.nw-pricing__seg-btn').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        period = btn.dataset.period;
                        box.querySelectorAll('.nw-pricing__seg-btn').forEach(function(b){
                            var on = (b === btn);
                            b.classList.toggle('is-on', on);
                            b.setAttribute('aria-selected', on ? 'true' : 'false');
                        });
                        refresh();
                    });
                });
                box.querySelectorAll('.nw-pricing__sites-btn').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        sites = parseInt(btn.dataset.sites, 10) || 1;
                        box.querySelectorAll('.nw-pricing__sites-btn').forEach(function(b){
                            var on = (b === btn);
                            b.classList.toggle('is-on', on);
                            b.setAttribute('aria-checked', on ? 'true' : 'false');
                        });
                        refresh();
                    });
                });
            });
        })();
        </script>
        <?php
    }
}

/**
 * Load bundled Composer dependencies and report the common source-ZIP install mistake clearly.
 *
 * @return WP_Error|null
 */
function nibwp_load_bundled_dependencies()
{
    if (!file_exists(NIBWP_VENDOR_AUTOLOAD)) {
        return new WP_Error('nibwp_missing_vendor', __(
            'NIBWP is installed without its bundled vendor directory. This usually means the GitHub/source ZIP was installed instead of the NIBWP release build ZIP. The MCP Adapter cannot load, so NIBWP will not register an MCP endpoint. Install the NIBWP release build ZIP before using NIBWP.',
            domain: 'nibwp',
        ));
    }

    try {
        require_once NIBWP_VENDOR_AUTOLOAD;
    } catch (\Throwable $e) {
        return new WP_Error('nibwp_autoload_failed', sprintf(
            __(
                'NIBWP could not load its bundled Composer dependencies. The MCP Adapter cannot load, so NIBWP will not register an MCP endpoint. Reinstall the NIBWP release build ZIP. Error: %s',
                domain: 'nibwp',
            ),
            $e->getMessage(),
        ));
    }

    if (!class_exists(NIBWP_MCP_ADAPTER_CLASS)) {
        return new WP_Error('nibwp_mcp_adapter_missing', sprintf(
            __(
                'NIBWP loaded its Composer autoloader, but the MCP Adapter class (%s) is not available. NIBWP will not register an MCP endpoint. Reinstall the NIBWP release build ZIP.',
                domain: 'nibwp',
            ),
            NIBWP_MCP_ADAPTER_CLASS,
        ));
    }

    return null;
}

/**
 * Store a runtime MCP dependency error.
 */
function nibwp_set_mcp_dependency_error(WP_Error $error): void
{
    nibwp_mcp_dependency_error($error);
}

/**
 * Return the current MCP dependency error, if any.
 *
 * @return WP_Error|null
 */
function nibwp_get_mcp_dependency_error()
{
    return nibwp_mcp_dependency_error();
}

/**
 * Shared storage for the current MCP dependency error.
 *
 * @return WP_Error|null
 */
function nibwp_mcp_dependency_error(?WP_Error $error = null)
{
    static $current = null;

    if ($error !== null) {
        $current = $error;
    }

    return $current;
}

/**
 * Whether the bundled MCP Adapter is available for NIBWP to initialize.
 */
function nibwp_is_mcp_adapter_available(): bool
{
    return nibwp_get_mcp_dependency_error() === null && class_exists(NIBWP_MCP_ADAPTER_CLASS);
}

/**
 * Block activation when the distributable dependencies are missing.
 */
register_activation_hook(__FILE__, 'nibwp_user_access_capture_owner');

function nibwp_activation_check(): void
{
    $error = nibwp_get_mcp_dependency_error();
    if ($error === null) {
        return;
    }

    if (function_exists('deactivate_plugins')) {
        deactivate_plugins(plugin_basename(__FILE__));
    }

    wp_die(
        '<p>' . esc_html($error->get_error_message()) . '</p>',
        esc_html__('NIBWP installation is incomplete', domain: 'nibwp'),
        ['back_link' => true],
    );
}

/**
 * Show a persistent admin error when NIBWP cannot expose MCP.
 */
function nibwp_render_mcp_dependency_notice(): void
{
    if (function_exists('nibwp_user_access_shows_notices') && !nibwp_user_access_shows_notices()) {
        return;
    }

    if (!current_user_can('manage_options')) {
        return;
    }

    $page = $_GET['page'] ?? null;
    if (is_string($page) && in_array($page, ['nibwp-connect', 'nibwp', 'nibwp-sandbox'], strict: true)) {
        return;
    }

    $error = nibwp_get_mcp_dependency_error();
    if ($error === null) {
        return;
    }

    wp_admin_notice(esc_html($error->get_error_message()), [
        'type' => 'error',
        'dismissible' => false,
    ]);
}

/**
 * Return a clear REST error at the MCP endpoint when the adapter cannot register its own route.
 */
function nibwp_register_missing_mcp_endpoint(): void
{
    $error = nibwp_get_mcp_dependency_error();
    if ($error === null) {
        return;
    }

    $routes = rest_get_server()->get_routes();
    $callback = static fn() => new WP_Error('nibwp_mcp_adapter_unavailable', $error->get_error_message(), [
        'status' => 500,
    ]);

    foreach (['nibwp', 'mcp-adapter-default-server'] as $route_slug) {
        if (array_key_exists('/mcp/' . $route_slug, $routes)) {
            continue;
        }
        register_rest_route('mcp', '/' . $route_slug, [
            'methods' => WP_REST_Server::ALLMETHODS,
            'callback' => $callback,
            'permission_callback' => '__return_true',
        ]);
    }
}

/**
 * Initialize the MCP Adapter and convert runtime failures into visible admin notices.
 */
function nibwp_initialize_mcp_adapter(): bool
{
    if (!nibwp_is_mcp_adapter_available()) {
        return false;
    }

    try {
        \WP\MCP\Core\McpAdapter::instance();
        return true;
    } catch (\Throwable $e) {
        nibwp_set_mcp_dependency_error(
            new WP_Error('nibwp_mcp_adapter_init_failed', sprintf(
                __(
                    'NIBWP found the MCP Adapter, but it failed during initialization. NIBWP will not register an MCP endpoint. Error: %s',
                    domain: 'nibwp',
                ),
                $e->getMessage(),
            )),
        );
        return false;
    }
}

$nibwp_dependency_error = nibwp_load_bundled_dependencies();
if ($nibwp_dependency_error !== null) {
    nibwp_set_mcp_dependency_error($nibwp_dependency_error);
}

register_activation_hook(__FILE__, callback: 'nibwp_activation_check');
add_action('admin_notices', callback: 'nibwp_render_mcp_dependency_notice');
add_action('network_admin_notices', callback: 'nibwp_render_mcp_dependency_notice');
add_action('rest_api_init', callback: 'nibwp_register_missing_mcp_endpoint', priority: 999);

require_once __DIR__ . '/includes/admin-styles.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/render-check.php';
require_once __DIR__ . '/includes/oauth/oauth-scopes.php';
require_once __DIR__ . '/includes/oauth/oauth.php';
require_once __DIR__ . '/includes/oauth/oauth-server.php';
require_once __DIR__ . '/includes/oauth/oauth-consent.php';
require_once __DIR__ . '/includes/oauth/oauth-connect-tab.php';
require_once __DIR__ . '/includes/addon-conflicts.php';
require_once __DIR__ . '/includes/cli-info.php';
require_once __DIR__ . '/includes/user-access.php';
require_once __DIR__ . '/includes/branding.php';
require_once __DIR__ . '/includes/visual/visual-bus.php';
require_once __DIR__ . '/includes/visual/visual-rest.php';
require_once __DIR__ . '/includes/visual/visual-abilities.php';
require_once __DIR__ . '/includes/visual/visual-icons.php';
require_once __DIR__ . '/includes/visual/visual-upgrade.php';
require_once __DIR__ . '/includes/visual/visual-onboarding.php';
require_once __DIR__ . '/includes/visual/visual-workspace.php';
require_once __DIR__ . '/includes/user-defaults.php';
require_once __DIR__ . '/includes/integrations-catalog.php';
// License UI (admin page, lock modal, upsell). Paid-only — stripped from
// the Free wp.org build. Source-tree files live alongside the license client
// (license.php is already loaded near the top of this file). The require is
// done here, AFTER helpers.php, because the UI files reference admin helpers.
foreach ([
    __DIR__ . '/includes/license-page.php',
    __DIR__ . '/includes/lock-popup.php',
    __DIR__ . '/includes/pro-upsell.php',
] as $nibwp_optional_paid_file) {
    if (file_exists($nibwp_optional_paid_file)) {
        require_once $nibwp_optional_paid_file;
    }
}
unset($nibwp_optional_paid_file);
require_once __DIR__ . '/includes/updater.php';
require_once __DIR__ . '/includes/admin-page.php';
require_once __DIR__ . '/includes/connect-page.php';
require_once __DIR__ . '/includes/danger-zone.php';
require_once __DIR__ . '/includes/upload-link.php';
require_once __DIR__ . '/includes/integrations-page.php';
require_once __DIR__ . '/includes/memory-page.php';
require_once __DIR__ . '/includes/how-to-page.php';
require_once __DIR__ . '/includes/audit-log.php';
require_once __DIR__ . '/includes/settings-page.php';
require_once __DIR__ . '/includes/status-checks.php';
require_once __DIR__ . '/includes/status-page.php';
require_once __DIR__ . '/includes/user-access-page.php';
require_once __DIR__ . '/includes/dashboard-page.php';
require_once __DIR__ . '/includes/demo-data.php';
require_once __DIR__ . '/includes/workflows.php';
require_once __DIR__ . '/includes/workflow-sharing.php';
require_once __DIR__ . '/includes/workflows-page.php';
require_once __DIR__ . '/includes/jobs.php';
require_once __DIR__ . '/includes/jobs-executors.php';
require_once __DIR__ . '/includes/jobs-page.php';
require_once __DIR__ . '/includes/skills/registry.php';
// Figma integration — connect (token / OAuth / MCP) + local Figma→WP converter.
// skills-page.php is the premium-skill marketplace UI — stripped from the
// Free wp.org build. The standalone Pro plugin ships it. The skills registry
// (skills/registry.php) stays in Free so skill plugins can hook
// `nibwp_skills_roots` without depending on Pro.
if (file_exists(__DIR__ . '/includes/skills-page.php')) {
    require_once __DIR__ . '/includes/skills-page.php';
}

// Seed demo data on first admin load.
add_action('admin_init', 'nibwp_seed_demo_data');

// Dependency check: Abilities API must be active.
if (!class_exists('WP_Ability')) {
    add_action('admin_notices', static function () {
        if (function_exists('nibwp_user_access_shows_notices') && !nibwp_user_access_shows_notices()) {
            return;
        }

        wp_admin_notice(
            esc_html__('NIBWP requires the Abilities API plugin to be installed and activated.', domain: 'nibwp'),
            [
                'type' => 'error',
                'dismissible' => false,
            ],
        );
    });
    return;
}

// Add "Community" link to the plugin row meta on the Plugins page.
add_filter(
    'plugin_row_meta',
    /** @param string[] $plugin_meta */
    static function (array $plugin_meta, string $plugin_file): array {
        if ($plugin_file === plugin_basename(__FILE__)) {
            $plugin_meta[] =
                '<a href="https://www.facebook.com/groups/nibwp" target="_blank" rel="noopener noreferrer">'
                . esc_html__('Community', domain: 'nibwp')
                . '</a>';
        }
        return $plugin_meta;
    },
    priority: 10,
    accepted_args: 2,
);

// Suppress noisy admin notices on NIBWP pages: tag <body> with the helper
// class .nibwp-suppress-foreign-notices and the rule in admin-pricing.css
// hides every non-NIBWP notice. Cheap + side-effect free, unlike iterating
// $wp_filter with Reflection (which causes memory blow-ups when Query
// Monitor captures every remove_action).
add_filter('admin_body_class', static function (string $classes): string {
    if (!in_array($_GET['page'] ?? null, nibwp_admin_page_slugs(), strict: true)) {
        return $classes;
    }
    return $classes . ' nibwp-suppress-foreign-notices';
});

/** Slugs of NIBWP's own admin pages (shared by the body-class + notice-hoist hooks). */
function nibwp_admin_page_slugs(): array
{
    return ['nibwp-dashboard', 'nibwp-how-to', 'nibwp-connect', 'nibwp', 'nibwp-sandbox', 'nibwp-integrations', 'nibwp-workflows', 'nibwp-memory', 'nibwp-audit-log', 'nibwp-settings', 'nibwp-skills', 'nibwp-license', 'nibwp-status', 'nibwp-user-access'];
}

/**
 * Hoist WordPress + third-party admin notices OUT of the NIBWP UI panel into a
 * slot above the app shell, so the panel only shows NIBWP's own notices (saved,
 * updated, etc.). Foreign notices are tagged data-nibwp-foreign early (see
 * nibwp_render_admin_header) + caught here even after WP's common.js relocates
 * them below the page <h1> (which sits inside the panel). NIBWP notices render
 * inside .nibwp-wrap untagged, or carry .nibwp-keep / .nibwp-pro-notice, and stay.
 */
add_action('admin_footer', static function (): void {
    if (!in_array($_GET['page'] ?? null, nibwp_admin_page_slugs(), strict: true)) {
        return;
    }
    ?>
    <script>
    (function(){
        var body = document.getElementById('wpbody-content');
        if (!body) { return; }
        var slot = document.getElementById('nibwp-foreign-notices');
        if (!slot) { slot = document.createElement('div'); slot.id = 'nibwp-foreign-notices'; body.insertBefore(slot, body.firstChild); }
        function isKeep(n){ return n.classList.contains('nibwp-keep') || n.classList.contains('nibwp-pro-notice'); }
        function relocate(){
            document.querySelectorAll('[data-nibwp-foreign]').forEach(function(n){ if (!isKeep(n) && n.parentNode !== slot) { slot.appendChild(n); } });
            Array.prototype.slice.call(body.children).forEach(function(n){
                if (n === slot) { return; }
                if (n.matches && n.matches('.notice, .updated, .error, .update-nag') && !isKeep(n)) { n.setAttribute('data-nibwp-foreign', '1'); slot.appendChild(n); }
            });
        }
        relocate();
        window.addEventListener('load', relocate);
        setTimeout(relocate, 50); setTimeout(relocate, 400);
        try { new MutationObserver(function(){ relocate(); }).observe(body, { childList: true, subtree: true }); } catch (e) {}
    })();
    </script>
    <?php
}, 100);

// Handle form actions early (before headers are sent) for PRG redirect.
add_action('admin_init', static function () {
    $page = $_GET['page'] ?? null;
    if ($page === 'nibwp-sandbox') {
        nibwp_handle_sandbox_actions();
    }
    if ($page === 'nibwp-connect') {
        nibwp_handle_revoke_password();
        nibwp_handle_dismiss_production_warning();
    }
    if ($page === 'nibwp-memory') {
        nibwp_handle_memory_actions();
    }
    if ($page === 'nibwp-user-access' && function_exists('nibwp_handle_user_access_save')) {
        nibwp_handle_user_access_save();
    }

    if ($page === 'nibwp-settings') {
        nibwp_handle_settings_save();
        nibwp_handle_danger_reset();
    }
    if ($page === 'nibwp-skills') {
        nibwp_handle_skill_actions();
    }
});

// Create audit log table on activation.
register_activation_hook(__FILE__, static function () {
    if (function_exists('nibwp_audit_log_create_table')) {
        nibwp_audit_log_create_table();
    }
});

// Schedule daily audit log cleanup.
add_action('init', static function () {
    if (!wp_next_scheduled('nibwp_audit_log_daily_cleanup')) {
        wp_schedule_event(time(), 'daily', 'nibwp_audit_log_daily_cleanup');
    }
});
add_action('nibwp_audit_log_daily_cleanup', static function () {
    if (function_exists('nibwp_audit_log_cleanup')) {
        $retention = (int) get_option('nibwp_audit_log_retention', 30);
        nibwp_audit_log_cleanup($retention);
    }
});

// Register admin menus.
add_action('admin_menu', static function () {
    // Menu visibility (includes/user-access.php). Null means "no restriction",
    // which is the answer for the owner, for unlicensed sites, and whenever the
    // configuration is unusable — so the default path below is unchanged.
    //
    // A restricted page is registered with an empty parent rather than skipped:
    // that drops the row from the sidebar while keeping `admin.php?page=...`
    // routable, which is the whole point — the agency keeps its link. Skipping
    // registration instead would 404 the owner's own bookmarks.
    $nibwp_visible = function_exists('nibwp_user_access_visible_slugs')
        ? nibwp_user_access_visible_slugs()
        : null;
    $nibwp_parent = static function (string $slug) use ($nibwp_visible): string {
        return ($nibwp_visible === null || in_array($slug, $nibwp_visible, true))
            ? 'nibwp-dashboard'
            : '';
    };
    $nibwp_show_root = $nibwp_parent('nibwp-dashboard') !== '';

    // Top-level menu item — Dashboard is the main landing page.
    if ($nibwp_show_root) {
        add_menu_page(
            page_title: __('Dashboard', domain: 'nibwp'),
            menu_title: 'NIBWP',
            capability: 'manage_options',
            menu_slug: 'nibwp-dashboard',
            callback: 'nibwp_render_dashboard_page',
            icon_url: 'data:image/svg+xml;base64,' . base64_encode((string) file_get_contents(__DIR__ . '/assets/nibwp-icon.svg')),
            position: 3,
        );

        // Dashboard (rename auto-created first submenu).
        add_submenu_page(
            parent_slug: 'nibwp-dashboard',
            page_title: __('Dashboard', domain: 'nibwp'),
            menu_title: __('Dashboard', domain: 'nibwp'),
            capability: 'manage_options',
            menu_slug: 'nibwp-dashboard',
            callback: 'nibwp_render_dashboard_page',
        );
    } else {
        // No top-level row, but the page still has to resolve for anyone
        // arriving on a shared link.
        add_submenu_page(
            parent_slug: '',
            page_title: __('Dashboard', domain: 'nibwp'),
            menu_title: __('Dashboard', domain: 'nibwp'),
            capability: 'manage_options',
            menu_slug: 'nibwp-dashboard',
            callback: 'nibwp_render_dashboard_page',
        );
    }

    // Connect / Configuration.
    add_submenu_page(
        parent_slug: $nibwp_parent('nibwp-connect'),
        page_title: __('Connect', domain: 'nibwp'),
        menu_title: __('Connect', domain: 'nibwp'),
        capability: 'manage_options',
        menu_slug: 'nibwp-connect',
        callback: 'nibwp_render_connect_page',
    );

    // Integrations & Skills.
    add_submenu_page(
        parent_slug: $nibwp_parent('nibwp-integrations'),
        page_title: __('Integrations', domain: 'nibwp'),
        menu_title: __('Integrations', domain: 'nibwp'),
        capability: 'manage_options',
        menu_slug: 'nibwp-integrations',
        callback: 'nibwp_render_integrations_page',
    );

    // AI Abilities.
    add_submenu_page(
        parent_slug: $nibwp_parent('nibwp'),
        page_title: __('AI Abilities', domain: 'nibwp'),
        menu_title: __('AI Abilities', domain: 'nibwp'),
        capability: 'manage_options',
        menu_slug: 'nibwp',
        callback: 'nibwp_render_settings_page',
    );

    // Skills — sits directly under AI Abilities. Only registers when the
    // skills-page renderer is present (shipped by the standalone Pro plugin)
    // OR at least one skill manifest is discovered (skill plugins register
    // additional roots via `nibwp_skills_roots`).
    if (function_exists('nibwp_render_skills_page')) {
        add_submenu_page(
            parent_slug: $nibwp_parent('nibwp-skills'),
            page_title: __('Skills', 'nibwp'),
            menu_title: __('Skills', 'nibwp'),
            capability: 'manage_options',
            menu_slug: 'nibwp-skills',
            callback: 'nibwp_render_skills_page',
        );
    }

    // Workflows — user/AI-authored operating playbooks. Always visible: Free /
    // unlicensed sites get a locked teaser (preview cards + how-it-works banner +
    // Pro/Bundle CTAs); the page self-gates the editing/abilities.
    if (function_exists('nibwp_render_workflows_page')) {
        add_submenu_page(
            parent_slug: $nibwp_parent('nibwp-workflows'),
            page_title: __('Workflows', 'nibwp'),
            menu_title: __('Workflows', 'nibwp'),
            capability: 'manage_options',
            menu_slug: 'nibwp-workflows',
            callback: 'nibwp_render_workflows_page',
        );
    }

    // Jobs — the outcome-first surface. Tell NIBWP what you want done (a job),
    // run it now or on a schedule, approve any risky steps, read a plain-English
    // report. Backed by skills + workflows; the AI machinery stays hidden.
    if (function_exists('nibwp_render_jobs_page')) {
        add_submenu_page(
            parent_slug: $nibwp_parent('nibwp-jobs'),
            page_title: __('Jobs', 'nibwp'),
            menu_title: __('Jobs', 'nibwp'),
            capability: 'manage_options',
            menu_slug: 'nibwp-jobs',
            callback: 'nibwp_render_jobs_page',
        );
    }


    // Memory.
    add_submenu_page(
        parent_slug: $nibwp_parent('nibwp-memory'),
        page_title: __('Memory', domain: 'nibwp'),
        menu_title: __('Memory', domain: 'nibwp'),
        capability: 'manage_options',
        menu_slug: 'nibwp-memory',
        callback: 'nibwp_render_memory_page',
    );

    // Audit Log.
    add_submenu_page(
        parent_slug: $nibwp_parent('nibwp-audit-log'),
        page_title: __('Audit Log', domain: 'nibwp'),
        menu_title: __('Audit Log', domain: 'nibwp'),
        capability: 'manage_options',
        menu_slug: 'nibwp-audit-log',
        callback: 'nibwp_render_audit_log_page',
    );

    // Sandbox.
    add_submenu_page(
        parent_slug: $nibwp_parent('nibwp-sandbox'),
        page_title: __('Sandbox', domain: 'nibwp'),
        menu_title: __('Sandbox', domain: 'nibwp'),
        capability: 'manage_options',
        menu_slug: 'nibwp-sandbox',
        callback: 'nibwp_render_sandbox_page',
    );

    // How To (interactive walkthrough) — hidden from WP submenu; reachable via topbar button.
    add_submenu_page(
        parent_slug: null,
        page_title: __('How To', domain: 'nibwp'),
        menu_title: __('How To', domain: 'nibwp'),
        capability: 'manage_options',
        menu_slug: 'nibwp-how-to',
        callback: 'nibwp_render_how_to_page',
    );

    // Status & diagnostics.
    add_submenu_page(
        parent_slug: $nibwp_parent('nibwp-status'),
        page_title: __('Status', domain: 'nibwp'),
        menu_title: __('Status', domain: 'nibwp'),
        capability: 'manage_options',
        menu_slug: 'nibwp-status',
        callback: 'nibwp_render_status_page',
    );

    // User access — who sees NIBWP in wp-admin. Pro-only, and registered on the
    // same terms as License so the Free build never shows it.
    if (
        defined('NIBWP_LICENSE_CLIENT_LOADED')
        && function_exists('nibwp_render_user_access_page')
        && function_exists('nibwp_user_access_available')
        && nibwp_user_access_available()
    ) {
        add_submenu_page(
            parent_slug: $nibwp_parent('nibwp-user-access'),
            page_title: __('User access', 'nibwp'),
            menu_title: __('User access', 'nibwp'),
            capability: 'manage_options',
            menu_slug: 'nibwp-user-access',
            callback: 'nibwp_render_user_access_page',
        );
    }

    // Settings.
    add_submenu_page(
        parent_slug: $nibwp_parent('nibwp-settings'),
        page_title: __('Settings', domain: 'nibwp'),
        menu_title: __('Settings', domain: 'nibwp'),
        capability: 'manage_options',
        menu_slug: 'nibwp-settings',
        callback: 'nibwp_render_settings_page_view',
    );

    // License submenu — registered only when the license client is loaded.
    // In the Free wp.org build, the license client + UI are stripped, so this
    // menu does not appear. The standalone Pro plugin (and Skill plugins)
    // each ship the license client and re-register this menu.
    if (defined('NIBWP_LICENSE_CLIENT_LOADED') && function_exists('nibwp_render_license_page')) {
        add_submenu_page(
            parent_slug: $nibwp_parent('nibwp-license'),
            page_title: __('License', 'nibwp'),
            menu_title: function_exists('nibwp_is_pro') && nibwp_is_pro()
                ? __('License', 'nibwp')
                : __('License', 'nibwp'),
            capability: 'manage_options',
            menu_slug: 'nibwp-license',
            callback: 'nibwp_render_license_page',
        );
    }
});


$is_enabled = nibwp_is_enabled();

if (!$is_enabled && nibwp_is_domain_mismatch()) {
    add_action('admin_notices', static function () {
        if (function_exists('nibwp_user_access_shows_notices') && !nibwp_user_access_shows_notices()) {
            return;
        }

        /** @var string $locked */
        $locked = get_option('nibwp_ai_abilities_domain', default_value: '');

        // A notice that only describes the problem leaves the reader hunting
        // for a switch. The button is the whole point: moving a site is the
        // ordinary cause, and confirming it was you is one click.
        $message = '<p>' . sprintf(
            esc_html__(
                'NIBWP AI abilities are switched off because this site has moved. They were enabled on %1$s and it now answers on %2$s. Connected assistants can still sign in, but nothing they ask for will run until abilities are on for this address.',
                domain: 'nibwp',
            ),
            '<code>' . esc_html($locked !== '' ? $locked : __('another address', 'nibwp')) . '</code>',
            '<code>' . esc_html((string) wp_parse_url(home_url(), PHP_URL_HOST)) . '</code>',
        ) . '</p><p>' . nibwp_relock_domain_button() . '</p>';

        wp_admin_notice(
            $message,
            ['type' => 'warning', 'dismissible' => true, 'paragraph_wrap' => false],
        );
    });
}

if ($is_enabled) {
    // Brand the default MCP server. Usage instructions are returned from the
    // discover-abilities tool instead of the initialize handshake.
    add_filter('mcp_adapter_default_server_config', static function (mixed $config): mixed {
        if (!is_array($config)) {
            return $config;
        }
        $config['server_id'] = 'nibwp';
        $config['server_route'] = 'nibwp';
        $config['server_name'] = 'NIBWP';
        return $config;
    });

    // Register a legacy alias server at the old slug so configs that still point at
    // /wp-json/mcp/mcp-adapter-default-server keep working after the rename.
    add_action('mcp_adapter_init', callback: 'nibwp_register_legacy_mcp_server', priority: 20);

    // Initialize bundled MCP Adapter — its default server exposes our abilities automatically.
    if (!nibwp_initialize_mcp_adapter()) {
        $is_enabled = false;
    }
}

/**
 * Register a legacy alias of the canonical NIBWP MCP server at the pre-rename slug.
 *
 * The canonical server is registered under `/mcp/nibwp`. Older client configs may still
 * point at `/mcp/mcp-adapter-default-server` from before the rename — this alias keeps them
 * working with identical behavior (same tools, same auto-discovered resources and prompts).
 */
function nibwp_register_legacy_mcp_server(mixed $adapter): void
{
    if (!$adapter instanceof \WP\MCP\Core\McpAdapter) {
        return;
    }

    if ($adapter->get_server('nibwp') === null) {
        return;
    }

    $adapter->create_server(
        'mcp-adapter-default-server',
        'mcp',
        'mcp-adapter-default-server',
        'NIBWP (legacy alias)',
        'Legacy alias for the NIBWP MCP server. New client configurations should use /wp-json/mcp/nibwp.',
        'v1.0.0',
        [\WP\MCP\Transport\HttpTransport::class],
        \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
        \WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
        [
            'mcp-adapter/discover-abilities',
            'mcp-adapter/get-ability-info',
            'mcp-adapter/execute-ability',
        ],
        nibwp_discover_public_abilities('resource'),
        nibwp_discover_public_abilities('prompt'),
    );
}

/**
 * Replicate DefaultServerFactory::discover_abilities_by_type for reuse on the legacy alias.
 *
 * @return list<string>
 */
function nibwp_discover_public_abilities(string $type): array
{
    if (!function_exists('wp_get_abilities')) {
        return [];
    }

    $abilities = wp_get_abilities();
    $filtered = [];
    foreach ($abilities as $ability) {
        $meta = $ability->get_meta();
        if (!($meta['mcp']['public'] ?? false)) {
            continue;
        }
        $ability_type = (string) ($meta['mcp']['type'] ?? 'tool');
        if ($ability_type !== $type) {
            continue;
        }
        $filtered[] = $ability->get_name();
    }

    return $filtered;
}

if ($is_enabled) {
    // The `mcp-adapter/execute-ability` dispatcher wraps every ability return in
    // `{ success: true, data: <inner> }`. When the inner value is itself
    // `{ success: false, error: "..." }` the outer `success: true` masks a real
    // logical failure, and agents that check the top-level flag — a very
    // reasonable default — silently march past the error. Unwrap that shape
    // here so the adapter's backward-compat path (ToolsHandler) turns it into a
    // proper `isError: true` CallToolResult.
    //
    // ToolsHandler::create_error_result flattens the response to a bare
    // `content: [text(error)], structuredContent: null, isError: true` — every
    // sibling field on the ability's return is discarded. Validators attach
    // structured repair hints (`invalid_values`, `unknown_properties`,
    // `collision_paths`, `suggested_name`, `failed_paths`, `overwritten_paths`,
    // `errors`, `schemas`, `style_errors`, `dynamic_tag_errors`, `dropped_keys`,
    // `schema`, …) that the agent needs to self-correct without a
    // round-trip — so embed whatever else the ability returned as a JSON
    // suffix on the error message. The suffix rides inside the string and
    // survives the downstream flatten.
    add_filter(
        'mcp_adapter_tool_call_result',
        static function (mixed $result, array $args, string $tool_name): mixed {
            // Tool names are MCP-sanitized from ability slugs — `/` becomes `-`.
            if ($tool_name !== 'mcp-adapter-execute-ability') {
                return $result;
            }
            if (!is_array($result) || ($result['success'] ?? null) !== true) {
                return $result;
            }
            /** @var array<array-key, mixed>|null $data */
            $data = $result['data'] ?? null;
            if (!is_array($data) || ($data['success'] ?? null) !== false) {
                return $result;
            }
            /** @var string|null $error */
            $error = $data['error'] ?? null;
            if (!is_string($error) || trim($error) === '') {
                return $result;
            }

            $detail = $data;
            unset($detail['success'], $detail['error']);
            if ($detail !== []) {
                $encoded = wp_json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (is_string($encoded)) {
                    $data['error'] = $error . "\n\nStructured detail (JSON):\n" . $encoded;
                }
            }

            return $data;
        },
        priority: 10,
        accepted_args: 3,
    );

    // Fix empty "properties" in JSON Schema: PHP json_encode outputs [] instead of {}.
    // MCP clients reject tools with invalid schemas, so we fix this in the REST response.
    add_filter('rest_pre_echo_response', static function (mixed $result): mixed {
        if (!is_array($result)) {
            return $result;
        }
        /** @var \stdClass|null $resultObj */
        $resultObj = $result['result'] ?? null;
        if (!$resultObj instanceof \stdClass) {
            return $result;
        }
        /** @var list<array<string, mixed>>|null $tools */
        $tools = $resultObj->tools ?? null;
        if (!is_array($tools)) {
            return $result;
        }
        foreach ($tools as &$tool) {
            foreach (['inputSchema', 'outputSchema'] as $key) {
                /** @var array<string, mixed>|null $schema */
                $schema = $tool[$key] ?? null;
                if (!is_array($schema) || ($schema['properties'] ?? null) !== []) {
                    continue;
                }
                $schema['properties'] = new \stdClass();
                $tool[$key] = $schema;
            }
        }
        $resultObj->tools = $tools;
        return $result;
    });

    // Admin bar indicator when MCP is active.
    add_action(
        'admin_bar_menu',
        static function (\WP_Admin_Bar $wp_admin_bar) {
            if (!current_user_can('manage_options')) {
                return;
            }
            if (function_exists('nibwp_user_access_shows_chip') && !nibwp_user_access_shows_chip()) {
                return;
            }

            $wp_admin_bar->add_node([
                'id' => 'nibwp-mcp-status',
                'title' => esc_html__('NIBWP ON', domain: 'nibwp'),
                'href' => admin_url('admin.php?page=nibwp-dashboard'),
                'meta' => ['class' => 'nibwp-mcp-on'],
            ]);
        },
        priority: 999,
    );

    add_action('admin_head', static function () {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (function_exists('nibwp_user_access_shows_chip') && !nibwp_user_access_shows_chip()) {
            return;
        }

        echo
            '<style>#wp-admin-bar-nibwp-mcp-status > .ab-item { background:#ff904d !important; color:#fff !important; }</style>'
        ;
    });

    add_action('wp_head', static function () {
        if (function_exists('nibwp_user_access_shows_chip') && !nibwp_user_access_shows_chip()) {
            return;
        }
        if (current_user_can('manage_options') && is_admin_bar_showing()) {
            echo
                '<style>#wp-admin-bar-nibwp-mcp-status > .ab-item { background:#ff904d !important; color:#fff !important; }</style>'
            ;
        }
    });

    // Info notice if the standalone MCP Adapter plugin is still active.
    if (function_exists('is_plugin_active') && is_plugin_active('mcp-adapter/mcp-adapter.php')) {
        add_action('admin_notices', static function () {
            if (function_exists('nibwp_user_access_shows_notices') && !nibwp_user_access_shows_notices()) {
                return;
            }

            wp_admin_notice(
                esc_html__(
                    'NIBWP bundles the MCP Adapter. You can safely deactivate the standalone MCP Adapter plugin.',
                    domain: 'nibwp',
                ),
                [
                    'type' => 'info',
                    'dismissible' => true,
                ],
            );
        });
    }

    // Register ability categories.
    add_action('wp_abilities_api_categories_init', static function () {
        wp_register_ability_category('code-execution', [
            'label' => __('Code Execution', domain: 'nibwp'),
            'description' => __('Abilities that execute code on the WordPress server.', domain: 'nibwp'),
        ]);

        wp_register_ability_category('filesystem', [
            'label' => __('Filesystem', domain: 'nibwp'),
            'description' => __('Server filesystem operations.', domain: 'nibwp'),
        ]);

        wp_register_ability_category('visual', [
            'label' => __('Visual workspace', domain: 'nibwp'),
            'description' => __('Driving and reading real pages in a browser tab the user is watching.', domain: 'nibwp'),
        ]);

        wp_register_ability_category('design', [
            'label' => __('Design', domain: 'nibwp'),
            'description' => __('Deciding how this site should look, before anything is built.', domain: 'nibwp'),
        ]);

        if (! nibwp_has_ability_category('mcp-adapter')) {
            wp_register_ability_category('mcp-adapter', [
                'label' => __('MCP Adapter', domain: 'nibwp'),
                'description' => __('Meta-abilities for MCP protocol bridging.', domain: 'nibwp'),
            ]);
        }

        wp_register_ability_category('elementor', [
            'label' => __('Elementor', domain: 'nibwp'),
            'description' => __('Elementor page builder abilities.', domain: 'nibwp'),
        ]);

        wp_register_ability_category('bricks', [
            'label' => __('Bricks', domain: 'nibwp'),
            'description' => __('Bricks builder abilities.', domain: 'nibwp'),
        ]);

        wp_register_ability_category('custom-fields', [
            'label' => __('Custom Fields', domain: 'nibwp'),
            'description' => __('Custom field and content type management.', domain: 'nibwp'),
        ]);

        wp_register_ability_category('memory', [
            'label' => __('Memory', domain: 'nibwp'),
            'description' => __('Cross-session memory for AI agents.', domain: 'nibwp'),
        ]);

        wp_register_ability_category('wordpress', [
            'label' => __('WordPress', domain: 'nibwp'),
            'description' => __('Core WordPress content and site management.', domain: 'nibwp'),
        ]);

        wp_register_ability_category('woocommerce', [
            'label' => __('WooCommerce', domain: 'nibwp'),
            'description' => __('WooCommerce store management abilities.', domain: 'nibwp'),
        ]);

        wp_register_ability_category('seo', [
            'label' => __('SEO', domain: 'nibwp'),
            'description' => __('SEO plugin integrations (Yoast, Rank Math, AIOSEO).', domain: 'nibwp'),
        ]);

        wp_register_ability_category('acss', [
            'label' => __('AutomaticCSS', domain: 'nibwp'),
            'description' => __('AutomaticCSS framework variables, classes, and CSS regeneration.', domain: 'nibwp'),
        ]);

        wp_register_ability_category('content', [
            'label' => __('Content', domain: 'nibwp'),
            'description' => __('Content planning, scheduling, fetching, and rewriting.', domain: 'nibwp'),
        ]);

        wp_register_ability_category('automaticcss', [
            'label' => __('Automatic.css', domain: 'nibwp'),
            'description' => __('AutomaticCSS utility framework management.', domain: 'nibwp'),
        ]);

        wp_register_ability_category('etchwp', [
            'label' => __('EtchWP', domain: 'nibwp'),
            'description' => __('EtchWP unified development environment abilities.', domain: 'nibwp'),
        ]);

        wp_register_ability_category('etchwp-pro', [
            'label' => __('EtchWP Pro', domain: 'nibwp'),
            'description' => __('Premium skill pack — image / HTML / Figma → EtchWP atomic components.', domain: 'nibwp'),
        ]);

        wp_register_ability_category('acss-pro', [
            'label' => __('ACSS Pro', domain: 'nibwp'),
            'description' => __('Premium skill pack — screenshot / HTML / URL → working Automatic.css configuration (palette, type ramp, space ramp, breakpoints) with WCAG contrast + modular scale validation.', domain: 'nibwp'),
        ]);

        wp_register_ability_category('bricks-pro', [
            'label' => __('Bricks Pro', domain: 'nibwp'),
            'description' => __('Premium skill pack — HTML / URL / image / Figma → validated Bricks template with element whitelist, global classes, ACSS tokens, query loops, dynamic data, native form / video elements, and per-breakpoint settings.', domain: 'nibwp'),
        ]);

        wp_register_ability_category('security', [
            'label' => __('Security & Maintenance', domain: 'nibwp'),
            'description' => __('Security scanning, malware detection, file repair, database cleanup, and site hardening.', domain: 'nibwp'),
        ]);

        wp_register_ability_category('migration', [
            'label' => __('Migration', domain: 'nibwp'),
            'description' => __('Export, import, clone, and migrate WordPress content and settings.', domain: 'nibwp'),
        ]);

        wp_register_ability_category('notifications', [
            'label' => __('Notifications', domain: 'nibwp'),
            'description' => __('Email, SMS, webhooks, and notification management with Twilio integration.', domain: 'nibwp'),
        ]);

        // NIBWP meta abilities — skill playbook loader, marketplace helpers,
        // anything that doesn't belong to a specific integration / toolkit
        // category lives here. WP rejects ability registration with an
        // unregistered category, so this MUST exist before any ability under
        // category 'nibwp' tries to register.
        wp_register_ability_category('nibwp', [
            'label' => __('NIBWP', domain: 'nibwp'),
            'description' => __('NIBWP meta abilities — skills marketplace, playbook loader, feedback.', domain: 'nibwp'),
        ]);

        // Premium integration categories. Each integration ability file in
        // includes/premium/integrations/ declares a `'category'` on its
        // wp_register_ability() calls — without the matching category
        // registered here, WP rejects the ability silently. Add new
        // categories below whenever a new integration ships.
        foreach ([
            'forms'         => ['Forms', 'Form plugins (Gravity, WPForms, Fluent, CF7, Ninja, Formidable, Forminator, Happy, JetFormBuilder).'],
            'crm'           => ['CRM', 'CRM plugins (FluentCRM, Groundhogg, Mailchimp connector, etc.).'],
            'ecommerce'     => ['E-commerce', 'WooCommerce add-ons, FluentCart, Easy Digital Downloads.'],
            'affiliate'     => ['Affiliate', 'Affiliate program managers (FluentAffiliate, AffiliateWP).'],
            'directory'     => ['Directory', 'Business directory + classifieds plugins (Directorist, GeoDirectory).'],
            'events'        => ['Events', 'Event calendar / booking integrations.'],
            'community'     => ['Community', 'BuddyPress / bbPress / membership community.'],
            'lms'           => ['LMS', 'Learning management systems (LearnDash, LifterLMS, TutorLMS).'],
            'membership'    => ['Membership', 'Paid memberships (MemberPress, Restrict Content Pro).'],
            'donations'     => ['Donations', 'GiveWP and other donation managers.'],
            'wp-job-manager'=> ['WP Job Manager', 'WP Job Manager jobs / resumes / applications.'],
            'multilingual'  => ['Multilingual', 'Translation plugins (TranslatePress, WPML, Polylang).'],
            'utilities'     => ['Utilities', 'Generic utility plugins (Redirection, TablePress, etc.).'],
            'email'         => ['Email', 'Email delivery / SMTP (FluentSMTP and friends).'],
            'divi'          => ['Divi', 'Divi theme + Builder.'],
            'generatepress' => ['GeneratePress', 'GeneratePress theme + GP Premium.'],
            'kadence'       => ['Kadence', 'Kadence theme + Kadence Blocks.'],
            'voxel'         => ['Voxel', 'Voxel theme — listings, directories, commerce, community.'],
        ] as $slug => [$label, $desc]) {
            if (! nibwp_has_ability_category($slug)) {
                wp_register_ability_category($slug, [
                    'label'       => $label,
                    'description' => $desc,
                ]);
            }
        }

        // Skill packs use their skill id as the ability category (e.g.
        // 'tutorlms-builder', 'seo-pro'). Register one category per discovered
        // skill so any new skill works without editing this list.
        if (function_exists('nibwp_skills_discover')) {
            foreach (nibwp_skills_discover() as $skill_id => $manifest) {
                if (! nibwp_has_ability_category((string) $skill_id)) {
                    wp_register_ability_category((string) $skill_id, [
                        'label'       => (string) ($manifest['name'] ?? $skill_id),
                        'description' => (string) ($manifest['tagline'] ?? ($manifest['description'] ?? '')),
                    ]);
                }
            }
        }
    });

    // Register abilities.
    //
    // The Free build loads only the abilities that ship in includes/abilities/:
    // core file ops, execute-php, memory, wordpress-core, and WooCommerce.
    // ACF and every other plugin integration is Pro — see includes/premium/
    // bootstrap.php, which hooks the same `wp_abilities_api_init` action at
    // priority 20 and loads premium integrations + toolkits when both:
    //   • the premium/ directory ships (Pro build only)
    //   • the user has an active Pro / Bundle / matching skill license
    add_action('wp_abilities_api_init', static function () {
        $dir = __DIR__ . '/includes/abilities/';

        // Safe read-only abilities (Free, wp.org-shipped).
        require_once $dir . 'read-file.php';
        require_once $dir . 'list-directory.php';
        require_once $dir . 'discover-abilities.php';
        require_once $dir . 'find-tools.php';
        require_once $dir . 'load-skill-playbook.php';
        require_once $dir . 'load-integration-playbook.php';
        require_once $dir . 'preferences.php';
        require_once $dir . 'templates.php';
        require_once $dir . 'skill-preflight.php';
        require_once $dir . 'multi-execute.php';
        require_once $dir . 'read-audit-log.php';

        // Memory — Free, always available.
        require_once $dir . 'memory.php';

        // Visual workspace — the agent drives a browser tab the user is watching.
        if (function_exists('nibwp_visual_register_abilities')) {
            nibwp_visual_register_abilities();
        }

        // WordPress core abilities (posts/terms/users/media/options/menus CRUD) — Free.
        require_once $dir . 'wordpress-core.php';

        // WooCommerce — only third-party integration that ships in Free.
        if (
            class_exists('WooCommerce')
            && function_exists('nibwp_is_integration_enabled')
            && nibwp_is_integration_enabled('woocommerce')
        ) {
            require_once $dir . 'woocommerce.php';
        }

        // Skill packs (auto-discover; gated by entitlements via skills/registry.php).
        if (function_exists('nibwp_skills_load_abilities')) {
            nibwp_skills_load_abilities();
        }
    });
}

// Sandbox + sandbox-loader live in the Pro plugin (nibwp-pro). When Pro is
// installed alongside, it owns NIBWP_SANDBOX_DIR creation and sandbox loading.
