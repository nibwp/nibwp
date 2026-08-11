<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * NIBWP — Premium integrations catalog.
 *
 * Static list of integration keys gated behind a Pro / Bundle license OR a
 * matching standalone skill license (e.g. an etchwp skill license unlocks the
 * etchwp integration). The list lives here so BOTH Free and Pro can read it:
 *
 *   • Free uses it to render the locked / "Pro" badge on Integrations cards
 *     and route purchase CTAs to the right pricing URL.
 *   • Pro uses it inside premium/bootstrap.php when deciding which ability
 *     files to require during wp_abilities_api_init.
 *
 * Adding a new integration: append its key here. The integration's ability
 * file (e.g. includes/premium/integrations/<key>.php) only runs on Pro; Free
 * just sees the metadata.
 */

/**
 * Claim the right to load one integration file, once per request.
 *
 * Two plugins can ship the same integration file: the Pro build carries
 * includes/premium/integrations/<key>.php, and a standalone Skill add-on
 * bundles a copy so it works without Pro. `require_once` deduplicates by
 * absolute path, so two copies at two paths both load, both declare the same
 * functions, and PHP raises E_COMPILE_ERROR — a white screen on the whole
 * site, not a caught error, because compilation fails before anything can
 * handle it.
 *
 * A runtime `function_exists()` guard inside the file cannot prevent that:
 * function declarations at the top level of an included file are bound when
 * the file is compiled, before a single statement runs. The guard has to live
 * at the point of the require, which is what this is.
 *
 * First caller wins. Whichever copy loads is immaterial — they are the same
 * file — so the loser skipping silently is the correct outcome.
 *
 * Lives here rather than in premium/ because both trees need it and this file
 * ships in Free and Pro alike.
 */
if (!function_exists('nibwp_integration_claim')) {
    /**
     * @param string      $key  Integration name, unique per file.
     * @param string|null $file Absolute path of the copy about to be required.
     *                          Given it, the claim can also detect a copy some
     *                          other plugin already loaded without claiming.
     */
    function nibwp_integration_claim(string $key, ?string $file = null): bool
    {
        static $claimed = [];

        if (isset($claimed[$key])) {
            return false;
        }

        // An add-on built before the claim existed loads its copy without
        // asking anyone. Nothing marks that in the registry, so the only
        // evidence is the functions it declared. Ask the file we are about to
        // load what it would declare, and if that already exists, someone got
        // there first.
        if ($file !== null) {
            $sentinel = nibwp_integration_sentinel($file);
            if ($sentinel !== null && function_exists($sentinel)) {
                $claimed[$key] = true;

                return false;
            }
        }

        $claimed[$key] = true;

        return true;
    }
}

if (!function_exists('nibwp_integration_sentinel')) {
    /**
     * The first function an integration file declares at the top level.
     *
     * Read from the file rather than from a table, so it stays correct when
     * someone renames a function, and costs one small read for the handful of
     * integrations a request actually loads.
     */
    function nibwp_integration_sentinel(string $file): ?string
    {
        static $cache = [];

        if (array_key_exists($file, $cache)) {
            return $cache[$file];
        }

        $cache[$file] = null;

        if (!is_readable($file)) {
            return null;
        }

        $source = (string) file_get_contents($file);
        // Column zero only: a nested declaration is bound at runtime and cannot
        // be the thing that fatals, so it is no evidence of anything.
        if (preg_match('/^function\s+([a-zA-Z_]\w*)\s*\(/m', $source, $matches) === 1) {
            $cache[$file] = $matches[1];
        }

        return $cache[$file];
    }
}

if (!function_exists('nibwp_premium_integrations')) {
    function nibwp_premium_integrations(): array
    {
        return [
            // Page builders & frameworks.
            'elementor', 'bricks', 'builderius', 'etchwp', 'automaticcss',
            // Design tools.
            'figma',
            // Custom fields & content types.
            'acf', 'jetengine', 'metabox', 'pods', 'acpt', 'ase',
            // CRM / e-commerce add-ons (WooCommerce stays free).
            'fluentcrm', 'fluentcart', 'fluentaffiliate', 'edd', 'surecart',
            // Community & email delivery.
            'fluentcommunity', 'fluentsmtp',
            // Directory / classifieds.
            'directorist',
            // Forms.
            'forms',
            // Membership / LMS.
            'learndash', 'lifterlms', 'memberpress', 'tutorlms',
            // Community / events.
            'buddypress', 'events',
            // Donations.
            'givewp',
            // Utilities.
            'redirection', 'tablepress', 'translatepress', 'wpml',
            // SEO.
            'seo', 'seopress', 'slimseo',
            // Recruitment.
            'wp-job-manager',
            // Themes.
            'generatepress', 'kadence', 'voxel',
            // Page builders (theme-coupled).
            'divi',
        ];
    }
}
