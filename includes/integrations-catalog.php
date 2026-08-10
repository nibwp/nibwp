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
