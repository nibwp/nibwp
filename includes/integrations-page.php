<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integrations admin page — activate/deactivate page builder and custom field integrations.
 */

/**
 * Get the list of enabled integrations from options.
 *
 * @return array<string, bool>
 */
function nibwp_get_enabled_integrations(): array
{
    $saved = get_option('nibwp_enabled_integrations', []);
    if (!is_array($saved)) {
        return [];
    }

    // Legacy-key migration: the old `acss` key was merged into `automaticcss`.
    // If a user enabled either one previously, keep them enabled under the new
    // canonical key, then persist the cleanup once.
    if (array_key_exists('acss', $saved)) {
        if (!empty($saved['acss']) && empty($saved['automaticcss'] ?? null)) {
            $saved['automaticcss'] = true;
        }
        unset($saved['acss']);
        update_option('nibwp_enabled_integrations', $saved);
    }

    return $saved;
}

/**
 * Check if a specific integration is enabled by the user.
 */
function nibwp_is_integration_enabled(string $key): bool
{
    // Memory is always enabled.
    if ($key === 'memory') {
        return true;
    }
    $enabled = nibwp_get_enabled_integrations();
    return !empty($enabled[$key]);
}

/**
 * Check if a specific integration's plugin is installed and active.
 */
function nibwp_is_integration_available(string $key): bool
{
    return match ($key) {
        'elementor' => defined('ELEMENTOR_VERSION'),
        'bricks' => defined('BRICKS_VERSION'),
        'builderius' => function_exists('builderius_check_requirements') || post_type_exists('builderius_template'),
        'acf' => function_exists('acf_get_field_groups'),
        'jetengine' => function_exists('jet_engine'),
        'metabox' => function_exists('rwmb_meta'),
        'pods' => function_exists('pods'),
        'acpt' => defined('ACPT_PLUGIN_VERSION'),
        'ase' => defined('ASENHA_VERSION'),
        'woocommerce' => class_exists('WooCommerce'),
        'seo' => defined('WPSEO_VERSION') || class_exists('WPSEO_Options')
            || defined('RANK_MATH_VERSION') || class_exists('RankMath')
            || defined('AIOSEO_VERSION') || function_exists('aioseo'),
        'etchwp' => defined('ETCH_PLUGIN_FILE'),
        'automaticcss' => defined('ACSS_PLUGIN_FILE'),
        'forms' => class_exists('GFForms')
            || defined('WPFORMS_VERSION')
            || defined('FLUENTFORM')
            || defined('WPCF7_VERSION') || class_exists('WPCF7')
            || defined('NF_PLUGIN_VERSION') || class_exists('Ninja_Forms')
            || function_exists('load_formidable_forms') || class_exists('FrmForm')
            || defined('FORMINATOR_VERSION') || class_exists('Forminator_API')
            || defined('HAPPYFORMS_VERSION')
            || class_exists('\\Jet_Form_Builder\\Plugin'),
        'fluentcrm' => defined('FLUENTCRM'),
        'fluentcart' => defined('FLUENTCART_VERSION') || class_exists('FluentCart\\App\\App'),
        'fluentaffiliate' => defined('FLUENT_AFFILIATE_VERSION') || class_exists('FluentAffiliate\\App\\App'),
        'directorist' => defined('ATBDP_VERSION'),
        'edd' => class_exists('Easy_Digital_Downloads'),
        'events' => class_exists('Tribe__Events__Main'),
        'buddypress' => class_exists('BuddyPress'),
        'learndash' => defined('LEARNDASH_VERSION'),
        'lifterlms' => defined('LLMS_PLUGIN_FILE'),
        'memberpress' => defined('MEPR_VERSION'),
        'tutorlms' => defined('TUTOR_VERSION'),
        'givewp' => defined('GIVE_VERSION'),
        'redirection' => class_exists('Red_Item'),
        'tablepress' => class_exists('TablePress'),
        'translatepress' => defined('TRP_PLUGIN_VERSION'),
        'wpml' => defined('ICL_SITEPRESS_VERSION'),
        'wp-job-manager' => defined('JOB_MANAGER_VERSION') || class_exists('WP_Job_Manager'),
        'generatepress' => function_exists('generate_get_defaults')
            || wp_get_theme()->get_template() === 'generatepress'
            || wp_get_theme()->get_stylesheet() === 'generatepress',
        // Kadence Blocks counts, not just the Kadence theme. The blocks plugin
        // is routinely run under a different theme, and the Kadence Pro skill
        // builds Kadence BLOCKS — so a theme-only test declared the integration
        // unavailable, which silently withheld the skill's routing card AND
        // stopped its ability files from loading at all. The agent was then left
        // with no Kadence tools and reached for execute-php instead.
        'kadence' => class_exists('Kadence\\Theme')
            || function_exists('kadence')
            || defined('KADENCE_BLOCKS_VERSION')
            || class_exists('Kadence_Blocks_Frontend')
            || wp_get_theme()->get_template() === 'kadence'
            || wp_get_theme()->get_stylesheet() === 'kadence',
        'voxel' => class_exists('\\Voxel\\Post')
            || wp_get_theme()->get_template() === 'voxel'
            || wp_get_theme()->get_stylesheet() === 'voxel',
        'divi' => defined('ET_BUILDER_VERSION')
            || defined('ET_BUILDER_PLUGIN_VERSION')
            || function_exists('et_setup_theme')
            || in_array(strtolower((string) wp_get_theme()->get('Name')), ['divi', 'extra'], true)
            || in_array(strtolower((string) wp_get_theme()->get_template()), ['divi', 'extra'], true),
        'fluentcommunity' => defined('FLUENT_COMMUNITY_PLUGIN_VERSION') || function_exists('fluentCommunityApp'),
        'fluentsmtp' => defined('FLUENTMAIL_PLUGIN_VERSION') || function_exists('fluentMailGetSettings'),
        'seopress' => defined('SEOPRESS_VERSION'),
        'slimseo' => defined('SLIM_SEO_VER') || function_exists('slim_seo'),
        'surecart' => class_exists('SureCart') || defined('SURECART_PLUGIN_FILE'),
        // Figma is a cloud service — "available" means the user has connected an
        // account token (there is no local plugin to detect).
        'figma' => function_exists('nibwp_figma_is_connected')
            ? nibwp_figma_is_connected()
            : (bool) get_option('nibwp_figma_token'),
        'memory' => true,
        default => false,
    };
}

/**
 * Get all available integrations with their metadata.
 *
 * @return array<string, array{name: string, description: string, plugin_available: bool, enabled: bool, icon: string, category: string, abilities: list<string>, plugin_name: string}>
 */
function nibwp_get_integrations(): array
{
    return [
        'figma' => [
            'name' => 'Figma',
            'description' => __('Connect a Figma account to read designs — node tree, auto-layout, Variables and styles — and convert frames or components into native WordPress via the figma-pro skill. Read-only (v1); pixel-diff verified.', domain: 'nibwp'),
            'plugin_available' => function_exists('nibwp_figma_is_connected')
                ? nibwp_figma_is_connected()
                : (bool) get_option('nibwp_figma_token'),
            'enabled' => nibwp_is_integration_enabled('figma'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M8.5 24a3.5 3.5 0 0 0 3.5-3.5V17H8.5a3.5 3.5 0 1 0 0 7z"/><path d="M5 12a3.5 3.5 0 0 1 3.5-3.5H12v7H8.5A3.5 3.5 0 0 1 5 12z"/><path d="M5 4.5A3.5 3.5 0 0 1 8.5 1H12v7H8.5A3.5 3.5 0 0 1 5 4.5z"/><path d="M12 1h3.5a3.5 3.5 0 1 1 0 7H12V1z"/><path d="M19 12a3.5 3.5 0 1 1-7 0 3.5 3.5 0 0 1 7 0z"/></svg>',
            'category' => 'design',
            'abilities' => ['figma-pull', 'figma-list', 'figma-get', 'figma-pro-fetch', 'figma-pro-convert', 'figma-pro-detect-builder', 'figma-pro-analyze'],
            'plugin_name' => 'Figma',
            // Cloud service — no local plugin. Card shows Connected/Not connected
            // (+ a Connect link), never "not installed".
            'cloud' => true,
        ],
        'elementor' => [
            'name' => 'Elementor',
            'description' => __('Create pages with sections, build custom atomic widgets, manage global colors/fonts, create and manage templates.', domain: 'nibwp'),
            'plugin_available' => defined('ELEMENTOR_VERSION'),
            'enabled' => nibwp_is_integration_enabled('elementor'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zM8 17V7h2v10H8zm4 0V7h4v2h-2v2h2v2h-2v2h2v2h-4z"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['elementor-create-page', 'elementor-create-widget', 'elementor-global-styles', 'elementor-manage-templates', 'elementor-list-pages', 'elementor-get-page-structure'],
            'plugin_name' => 'Elementor',
        ],
        'bricks' => [
            'name' => 'Bricks',
            'description' => __('Create templates (header, footer, content, archive), build custom elements, manage global classes and styles.', domain: 'nibwp'),
            'plugin_available' => defined('BRICKS_VERSION'),
            'enabled' => nibwp_is_integration_enabled('bricks'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><rect x="2" y="2" width="9" height="9" rx="1"/><rect x="13" y="2" width="9" height="9" rx="1"/><rect x="2" y="13" width="9" height="9" rx="1"/><rect x="13" y="13" width="9" height="9" rx="1"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['bricks-create-template', 'bricks-create-element', 'bricks-manage-styles'],
            'plugin_name' => 'Bricks Builder',
        ],
        'builderius' => [
            'name' => 'Builderius',
            'description' => __('Read the template/version graph, components, releases and global settings; author templates, components and fragments by committing configs through Builderius\'s own git-like versioning.', domain: 'nibwp'),
            'plugin_available' => function_exists('builderius_check_requirements') || post_type_exists('builderius_template'),
            'enabled' => nibwp_is_integration_enabled('builderius'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 2 3 7v10l9 5 9-5V7l-9-5zm0 2.3 6.5 3.6L12 11.5 5.5 7.9 12 4.3zM5 9.6l6 3.3v6.8l-6-3.3V9.6zm14 0v6.8l-6 3.3v-6.8l6-3.3z"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['builderius-list-templates', 'builderius-get-template', 'builderius-list-versions', 'builderius-list-components', 'builderius-list-releases', 'builderius-list-fragments', 'builderius-list-global-settings', 'builderius-list-form-submissions', 'builderius-list-starters', 'builderius-list-modules', 'builderius-get-component', 'builderius-build-config', 'builderius-create-template', 'builderius-update-template', 'builderius-create-component', 'builderius-create-fragment', 'builderius-update-global-settings', 'builderius-create-branch', 'builderius-export-config', 'builderius-import-config', 'builderius-delete'],
            'plugin_name' => 'Builderius',
        ],
        'acf' => [
            'name' => 'ACF',
            'description' => __('CRUD field groups and fields, manage custom post types, taxonomies, options pages, and read/write field values.', domain: 'nibwp'),
            'plugin_available' => function_exists('acf_get_field_groups'),
            'enabled' => nibwp_is_integration_enabled('acf'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M3 3h18v18H3V3zm2 2v14h14V5H5zm2 2h4v2H7V7zm0 4h10v2H7v-2zm0 4h10v2H7v-2z"/></svg>',
            'category' => 'custom-fields',
            'abilities' => ['acf-manage-fields', 'acf-manage-options'],
            'plugin_name' => 'Advanced Custom Fields',
        ],
        'jetengine' => [
            'name' => 'JetEngine',
            'description' => __('Manage meta boxes, Custom Content Types (CCT), options pages, and read/write dynamic content values.', domain: 'nibwp'),
            'plugin_available' => function_exists('jet_engine'),
            'enabled' => nibwp_is_integration_enabled('jetengine'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>',
            'category' => 'custom-fields',
            'abilities' => ['jetengine-manage-fields'],
            'plugin_name' => 'JetEngine',
        ],
        'metabox' => [
            'name' => 'Meta Box',
            'description' => __('Manage field groups, register custom post types and taxonomies, create relationships, read/write meta values.', domain: 'nibwp'),
            'plugin_available' => function_exists('rwmb_meta'),
            'enabled' => nibwp_is_integration_enabled('metabox'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M4 4h16v16H4V4zm2 2v12h12V6H6zm2 2h8v2H8V8zm0 4h8v2H8v-2z"/></svg>',
            'category' => 'custom-fields',
            'abilities' => ['metabox-manage-fields'],
            'plugin_name' => 'Meta Box',
        ],
        'pods' => [
            'name' => 'Pods',
            'description' => __('Manage Pods, custom post types, advanced content types, add fields, list/create/update items.', domain: 'nibwp'),
            'plugin_available' => function_exists('pods'),
            'enabled' => nibwp_is_integration_enabled('pods'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><circle cx="12" cy="12" r="3"/><circle cx="6" cy="6" r="2"/><circle cx="18" cy="6" r="2"/><circle cx="6" cy="18" r="2"/><circle cx="18" cy="18" r="2"/></svg>',
            'category' => 'custom-fields',
            'abilities' => ['pods-manage-content'],
            'plugin_name' => 'Pods',
        ],
        'acpt' => [
            'name' => 'ACPT',
            'description' => __('Manage post types, taxonomies, meta groups, and option pages via the ACPT framework.', domain: 'nibwp'),
            'plugin_available' => defined('ACPT_PLUGIN_VERSION'),
            'enabled' => nibwp_is_integration_enabled('acpt'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6zm-1 7V3.5L18.5 9H13zM8 13h8v2H8v-2zm0 4h5v2H8v-2z"/></svg>',
            'category' => 'custom-fields',
            'abilities' => ['acpt-manage-types'],
            'plugin_name' => 'ACPT',
        ],
        'ase' => [
            'name' => 'ASE',
            'description' => __('Manage custom field groups, post types, taxonomies, and field values via Admin and Site Enhancements.', domain: 'nibwp'),
            'plugin_available' => defined('ASENHA_VERSION'),
            'enabled' => nibwp_is_integration_enabled('ase'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 15.5A3.5 3.5 0 018.5 12 3.5 3.5 0 0112 8.5a3.5 3.5 0 013.5 3.5 3.5 3.5 0 01-3.5 3.5m7.43-2.53c.04-.32.07-.64.07-.97 0-.33-.03-.66-.07-1l2.11-1.63c.19-.15.24-.42.12-.64l-2-3.46c-.12-.22-.39-.3-.61-.22l-2.49 1c-.52-.4-1.08-.73-1.69-.98l-.38-2.65C14.46 2.18 14.25 2 14 2h-4c-.25 0-.46.18-.49.42l-.38 2.65c-.61.25-1.17.59-1.69.98l-2.49-1c-.23-.09-.49 0-.61.22l-2 3.46c-.13.22-.07.49.12.64L4.57 11c-.04.34-.07.67-.07 1 0 .33.03.65.07.97l-2.11 1.66c-.19.15-.25.42-.12.64l2 3.46c.12.22.39.3.61.22l2.49-1.01c.52.4 1.08.73 1.69.98l.38 2.65c.03.24.24.42.49.42h4c.25 0 .46-.18.49-.42l.38-2.65c.61-.25 1.17-.58 1.69-.98l2.49 1.01c.22.08.49 0 .61-.22l2-3.46c.12-.22.07-.49-.12-.64l-2.11-1.66z"/></svg>',
            'category' => 'custom-fields',
            'abilities' => ['ase-manage-fields'],
            'plugin_name' => 'Admin and Site Enhancements',
        ],
        'woocommerce' => [
            'name' => 'WooCommerce',
            'description' => __('Manage products, orders, customers, coupons, and sales reports. Full store management via AI.', domain: 'nibwp'),
            'plugin_available' => class_exists('WooCommerce'),
            'enabled' => nibwp_is_integration_enabled('woocommerce'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M2 4h20a1 1 0 011 1v11a1 1 0 01-1 1h-7l2 4h-2l-2-4H11l-2 4H7l2-4H2a1 1 0 01-1-1V5a1 1 0 011-1zm5.5 4a1.5 1.5 0 00-1.49 1.32l-.8 4.68H6.5L7.3 9.5h.01a.5.5 0 01.44-.5h.5a.5.5 0 01.49.5l.56 4.5H10l-.8-4.68A1.5 1.5 0 007.5 8zm5 0a1.5 1.5 0 100 3 1.5 1.5 0 000-3zm5 0a1.5 1.5 0 00-1.49 1.32l-.8 4.68h1.29l.8-4.5h.01a.5.5 0 01.44-.5h.5a.5.5 0 01.49.5l.56 4.5H20l-.8-4.68A1.5 1.5 0 0017.5 8z"/></svg>',
            'category' => 'ecommerce',
            'abilities' => ['wc-list-products', 'wc-get-product', 'wc-create-product', 'wc-update-product', 'wc-delete-product', 'wc-list-orders', 'wc-get-order', 'wc-update-order-status', 'wc-list-customers', 'wc-list-coupons', 'wc-create-coupon', 'wc-get-reports'],
            'plugin_name' => 'WooCommerce',
        ],
        'seo' => [
            'name' => 'SEO Tools',
            'description' => __('Get and update SEO metadata, analyze content, check sitemaps. Works with Yoast, Rank Math, and AIOSEO.', domain: 'nibwp'),
            'plugin_available' => defined('WPSEO_VERSION') || class_exists('WPSEO_Options')
                || defined('RANK_MATH_VERSION') || class_exists('RankMath')
                || defined('AIOSEO_VERSION') || function_exists('aioseo'),
            'enabled' => nibwp_is_integration_enabled('seo'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0016 9.5 6.5 6.5 0 109.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>',
            'category' => 'seo',
            'abilities' => ['seo-get-post-meta', 'seo-update-post-meta', 'seo-analyze-content', 'seo-get-sitemap-info', 'seo-bulk-get-meta'],
            'plugin_name' => 'Yoast / Rank Math / AIOSEO',
        ],
        'forms' => [
            'name' => __('Forms (Universal)', domain: 'nibwp'),
            'description' => __('One ability that talks to whichever form plugin you have: Contact Form 7, WPForms, Gravity Forms, Fluent Forms, Ninja Forms, Formidable, Forminator, Happy Forms, or JetFormBuilder. List forms, read submissions, create or delete entries.', domain: 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('forms'),
            'enabled' => nibwp_is_integration_enabled('forms'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 3c1.93 0 3.5 1.57 3.5 3.5S13.93 13 12 13s-3.5-1.57-3.5-3.5S10.07 6 12 6zm7 13H5v-.23c0-.62.28-1.2.76-1.58C7.47 15.82 9.64 15 12 15s4.53.82 6.24 2.19c.48.38.76.97.76 1.58V19z"/></svg>',
            'category' => 'forms',
            'abilities' => ['forms-manage'],
            'plugin_name' => 'Contact Form 7 / WPForms / Gravity / Fluent / Ninja / Formidable / Forminator / Happy / JetForm',
        ],
        'fluentcrm' => [
            'name' => 'FluentCRM',
            'description' => __('Manage contacts, tags, lists, and campaigns. View campaign stats and automate email marketing.', domain: 'nibwp'),
            'plugin_available' => defined('FLUENTCRM'),
            'enabled' => nibwp_is_integration_enabled('fluentcrm'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z"/></svg>',
            'category' => 'crm',
            'abilities' => ['fluentcrm-manage'],
            'plugin_name' => 'FluentCRM',
        ],
        'fluentcart' => [
            'name' => 'FluentCart',
            'description' => __('Manage FluentCart products and orders.', domain: 'nibwp'),
            'plugin_available' => defined('FLUENTCART_VERSION') || class_exists('FluentCart\\App\\App'),
            'enabled' => nibwp_is_integration_enabled('fluentcart'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"/></svg>',
            'category' => 'ecommerce',
            'abilities' => ['fluentcart-manage'],
            'plugin_name' => 'FluentCart',
        ],
        'fluentaffiliate' => [
            'name' => 'FluentAffiliate',
            'description' => __('Run a full affiliate program: approve/reject affiliates, set commission rates, create groups, log referrals, batch payouts, and pull revenue & top-performer reports.', domain: 'nibwp'),
            'plugin_available' => defined('FLUENT_AFFILIATE_VERSION') || class_exists('FluentAffiliate\\App\\App'),
            'enabled' => nibwp_is_integration_enabled('fluentaffiliate'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5s-3 1.34-3 3 1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/><path d="M19 3v3h-3v2h3v3h2V8h3V6h-3V3h-2z" opacity=".5"/></svg>',
            'category' => 'affiliate',
            'abilities' => ['fluentaffiliate-manage'],
            'plugin_name' => 'FluentAffiliate',
        ],
        'directorist' => [
            'name' => 'Directorist',
            'description' => __('Manage a full business directory: listings, categories, locations, tags, reviews, orders, pricing plans, and user favorites. Publish / expire / feature listings, approve reviews, and pull directory reports.', domain: 'nibwp'),
            'plugin_available' => defined('ATBDP_VERSION'),
            'enabled' => nibwp_is_integration_enabled('directorist'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg>',
            'category' => 'directory',
            'abilities' => ['directorist-manage'],
            'plugin_name' => 'Directorist',
        ],
        'edd' => [
            'name' => 'Easy Digital Downloads',
            'description' => __('Manage digital products, payments, customers, and sales statistics.', domain: 'nibwp'),
            'plugin_available' => class_exists('Easy_Digital_Downloads'),
            'enabled' => nibwp_is_integration_enabled('edd'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M20 6H12l-2-2H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2zm-6 10H6v-2h8v2zm4-4H6V10h12v2z"/></svg>',
            'category' => 'ecommerce',
            'abilities' => ['edd-manage'],
            'plugin_name' => 'Easy Digital Downloads',
        ],
        'events' => [
            'name' => 'The Events Calendar',
            'description' => __('Manage events, venues, and organizers. Create and update event listings with dates, costs, and locations.', domain: 'nibwp'),
            'plugin_available' => class_exists('Tribe__Events__Main'),
            'enabled' => nibwp_is_integration_enabled('events'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM9 10H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2z"/></svg>',
            'category' => 'events',
            'abilities' => ['events-manage'],
            'plugin_name' => 'The Events Calendar',
        ],
        'buddypress' => [
            'name' => 'BuddyPress',
            'description' => __('Manage members, groups, and activity streams. Post activity and create community groups.', domain: 'nibwp'),
            'plugin_available' => class_exists('BuddyPress'),
            'enabled' => nibwp_is_integration_enabled('buddypress'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>',
            'category' => 'community',
            'abilities' => ['buddypress-manage'],
            'plugin_name' => 'BuddyPress / BuddyBoss',
        ],
        'learndash' => [
            'name' => 'LearnDash',
            'description' => __('Manage courses, lessons, quizzes. Enroll users and track learning progress.', domain: 'nibwp'),
            'plugin_available' => defined('LEARNDASH_VERSION'),
            'enabled' => nibwp_is_integration_enabled('learndash'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M5 13.18v4L12 21l7-3.82v-4L12 17l-7-3.82zM12 3L1 9l11 6 9-4.91V17h2V9L12 3z"/></svg>',
            'category' => 'lms',
            'abilities' => ['learndash-manage'],
            'plugin_name' => 'LearnDash',
        ],
        'lifterlms' => [
            'name' => 'LifterLMS',
            'description' => __('Manage courses, memberships, and student enrollments.', domain: 'nibwp'),
            'plugin_available' => defined('LLMS_PLUGIN_FILE'),
            'enabled' => nibwp_is_integration_enabled('lifterlms'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M5 13.18v4L12 21l7-3.82v-4L12 17l-7-3.82zM12 3L1 9l11 6 9-4.91V17h2V9L12 3z"/></svg>',
            'category' => 'lms',
            'abilities' => ['lifterlms-manage'],
            'plugin_name' => 'LifterLMS',
        ],
        'memberpress' => [
            'name' => 'MemberPress',
            'description' => __('Manage memberships, members, transactions, and subscriptions.', domain: 'nibwp'),
            'plugin_available' => defined('MEPR_VERSION'),
            'enabled' => nibwp_is_integration_enabled('memberpress'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zM12 17c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zM15.1 8H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg>',
            'category' => 'membership',
            'abilities' => ['memberpress-manage'],
            'plugin_name' => 'MemberPress',
        ],
        'tutorlms' => [
            'name' => 'Tutor LMS',
            'description' => __('Manage courses, lessons, quizzes, students, instructors, monetization, and reports.', domain: 'nibwp'),
            'plugin_available' => defined('TUTOR_VERSION'),
            'enabled' => nibwp_is_integration_enabled('tutorlms'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M5 13.18v4L12 21l7-3.82v-4L12 17l-7-3.82zM12 3L1 9l11 6 9-4.91V17h2V9L12 3z"/></svg>',
            'category' => 'lms',
            'abilities' => ['tutorlms-courses', 'tutorlms-content', 'tutorlms-students', 'tutorlms-quizzes', 'tutorlms-instructors', 'tutorlms-monetization', 'tutorlms-reports'],
            'plugin_name' => 'Tutor LMS',
        ],
        'generatepress' => [
            'name' => 'GeneratePress',
            'description' => __('Control the GeneratePress theme — settings, layout, colors + Global Colors, typography, spacing, and Premium Elements (hooks, headers, layouts).', domain: 'nibwp'),
            'plugin_available' => function_exists('generate_get_defaults') || wp_get_theme()->get_template() === 'generatepress' || wp_get_theme()->get_stylesheet() === 'generatepress',
            'enabled' => nibwp_is_integration_enabled('generatepress'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 2 2 7l10 5 10-5-10-5zm-7.79 8L12 13.5 19.79 10 22 11.1l-10 5-10-5L4.21 10zm0 5L12 18.5 19.79 15 22 16.1l-10 5-10-5L4.21 15z"/></svg>',
            'category' => 'theme',
            'abilities' => ['generatepress-info', 'generatepress-settings', 'generatepress-colors', 'generatepress-typography', 'generatepress-spacing', 'generatepress-elements'],
            'plugin_name' => 'GeneratePress',
        ],
        'kadence' => [
            'name' => 'Kadence',
            'description' => __('Control the Kadence theme — settings, layout/header rows, the Global Color Palette, typography, Kadence Blocks global settings, and Pro Elements (hooked custom content with display conditions).', domain: 'nibwp'),
            'plugin_available' => class_exists('Kadence\\Theme') || function_exists('kadence') || defined('KADENCE_BLOCKS_VERSION') || class_exists('Kadence_Blocks_Frontend') || wp_get_theme()->get_template() === 'kadence' || wp_get_theme()->get_stylesheet() === 'kadence',
            'enabled' => nibwp_is_integration_enabled('kadence'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M4 3h4v7.6L14.4 3h5L11 12.4 20 21h-5.2L8 13.7V21H4V3z"/></svg>',
            'category' => 'theme',
            'abilities' => ['kadence-info', 'kadence-settings', 'kadence-colors', 'kadence-typography', 'kadence-blocks', 'kadence-elements'],
            'plugin_name' => 'Kadence',
        ],
        'voxel' => [
            'name' => 'Voxel',
            'description' => __('Run a Voxel directory with AI — listings with every Voxel field type (location, work hours, galleries, repeaters), post type schemas, search through Voxel\'s own index, taxonomy terms, orders and bookings, membership plans, timeline, and direct messages. Voxel has no REST API; this is the programmatic surface it never shipped.', domain: 'nibwp'),
            'plugin_available' => class_exists('\\Voxel\\Post') || wp_get_theme()->get_template() === 'voxel' || wp_get_theme()->get_stylesheet() === 'voxel',
            'enabled' => nibwp_is_integration_enabled('voxel'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 2 2 7v10l10 5 10-5V7L12 2zm0 2.2 7.5 3.75L12 11.7 4.5 7.95 12 4.2zM4 9.6l7 3.5v6.6l-7-3.5V9.6zm16 6.6-7 3.5v-6.6l7-3.5v6.6z"/></svg>',
            'category' => 'theme',
            'abilities' => ['voxel-info', 'voxel-post-types', 'voxel-schema', 'voxel-posts', 'voxel-posts-write', 'voxel-terms', 'voxel-orders', 'voxel-plans', 'voxel-timeline', 'voxel-messages', 'voxel-templates', 'voxel-render', 'voxel-delete'],
            'plugin_name' => 'Voxel',
        ],
        'divi' => [
            'name' => 'Divi',
            'description' => __('Control Divi (theme or Builder plugin) — per-post builder content (Divi 4 shortcode + Divi 5 serialized), theme options, the Global Color palette, the Divi Library, the Theme Builder, global module presets, and the Role Editor.', domain: 'nibwp'),
            'plugin_available' => defined('ET_BUILDER_VERSION') || defined('ET_BUILDER_PLUGIN_VERSION') || function_exists('et_setup_theme') || in_array(strtolower((string) wp_get_theme()->get('Name')), ['divi', 'extra'], true) || in_array(strtolower((string) wp_get_theme()->get_template()), ['divi', 'extra'], true),
            'enabled' => nibwp_is_integration_enabled('divi'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M3 3h7.5c4.7 0 8.5 3.8 8.5 8.5v.5c0 4.7-3.8 8.5-8.5 8.5H3V3zm4 4v9.9h3.3c2.8 0 5-2.2 5-5v-.4c0-2.7-2.2-4.9-5-4.9H7z"/></svg>',
            'category' => 'theme',
            'abilities' => ['divi-info', 'divi-content', 'divi-theme-options', 'divi-colors', 'divi-library', 'divi-theme-builder', 'divi-presets', 'divi-roles'],
            'plugin_name' => 'Divi',
        ],
        'fluentcommunity' => [
            'name' => 'FluentCommunity',
            'description' => __('Run a FluentCommunity portal with AI — spaces, space groups and courses, members and roles, the feed, comments, reactions, and member profiles with gamification points.', domain: 'nibwp'),
            'plugin_available' => defined('FLUENT_COMMUNITY_PLUGIN_VERSION') || function_exists('fluentCommunityApp'),
            'enabled' => nibwp_is_integration_enabled('fluentcommunity'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M16 11c1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3 1.34 3 3 3zm-8 0c1.66 0 3-1.34 3-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>',
            'category' => 'community',
            'abilities' => ['fluentcommunity-info', 'fluentcommunity-spaces', 'fluentcommunity-members', 'fluentcommunity-feed', 'fluentcommunity-comments', 'fluentcommunity-reactions', 'fluentcommunity-profiles'],
            'plugin_name' => 'FluentCommunity',
        ],
        'fluentsmtp' => [
            'name' => 'FluentSMTP',
            'description' => __('Configure how WordPress sends mail — set up a custom SMTP server or any API provider (SendGrid, Mailgun, SES, Postmark, Gmail…), route senders, send real test emails, and read the delivery log.', domain: 'nibwp'),
            'plugin_available' => defined('FLUENTMAIL_PLUGIN_VERSION') || function_exists('fluentMailGetSettings'),
            'enabled' => nibwp_is_integration_enabled('fluentsmtp'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4-8 5-8-5V6l8 5 8-5v2z"/></svg>',
            'category' => 'email',
            'abilities' => ['fluentsmtp-info', 'fluentsmtp-connections', 'fluentsmtp-configure', 'fluentsmtp-send-test', 'fluentsmtp-logs', 'fluentsmtp-settings'],
            'plugin_name' => 'FluentSMTP',
        ],
        'seopress' => [
            'name' => 'SEOPress',
            'description' => __('Drive SEOPress with AI — per-post titles, meta, canonical, robots and target keywords; Open Graph + Twitter; and the global Titles, Social/Knowledge Graph, XML Sitemap, Advanced and feature-toggle settings, plus Pro redirections.', domain: 'nibwp'),
            'plugin_available' => defined('SEOPRESS_VERSION'),
            'enabled' => nibwp_is_integration_enabled('seopress'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M15.5 14h-.79l-.28-.27a6.5 6.5 0 1 0-.7.7l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0A4.5 4.5 0 1 1 14 9.5 4.49 4.49 0 0 1 9.5 14z"/></svg>',
            'category' => 'seo',
            'abilities' => ['seopress-info', 'seopress-post', 'seopress-social-post', 'seopress-titles', 'seopress-social', 'seopress-sitemap', 'seopress-advanced', 'seopress-toggles', 'seopress-redirections'],
            'plugin_name' => 'SEOPress',
        ],
        'slimseo' => [
            'name' => 'Slim SEO',
            'description' => __('Control Slim SEO + Slim SEO Schema with AI — per-post title, description, canonical, robots and social images; the global features and social defaults; and per-post structured-data overrides.', domain: 'nibwp'),
            'plugin_available' => defined('SLIM_SEO_VER') || function_exists('slim_seo'),
            'enabled' => nibwp_is_integration_enabled('slimseo'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M3 4h18v2H3V4zm2 4h14v2H5V8zm-2 4h18v2H3v-2zm2 4h14v2H5v-2zm-2 4h18v2H3v-2z"/></svg>',
            'category' => 'seo',
            'abilities' => ['slimseo-info', 'slimseo-post', 'slimseo-settings', 'slimseo-schema'],
            'plugin_name' => 'Slim SEO',
        ],
        'surecart' => [
            'name' => 'SureCart',
            'description' => __('Run a SureCart store with AI — products, prices, collections, coupons, customers, orders, the checkout forms + store pages, and a catalog of SureCart storefront blocks that the EtchWP & Bricks Pro skills use to design product pages, pricing tables and checkout.', domain: 'nibwp'),
            'plugin_available' => class_exists('SureCart') || defined('SURECART_PLUGIN_FILE'),
            'enabled' => nibwp_is_integration_enabled('surecart'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49A1 1 0 0 0 21 4H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"/></svg>',
            'category' => 'ecommerce',
            'abilities' => ['surecart-info', 'surecart-products', 'surecart-prices', 'surecart-collections', 'surecart-coupons', 'surecart-customers', 'surecart-orders', 'surecart-forms', 'surecart-blocks'],
            'plugin_name' => 'SureCart',
        ],
        'givewp' => [
            'name' => 'GiveWP',
            'description' => __('Manage donation forms, donations, donors, and fundraising statistics.', domain: 'nibwp'),
            'plugin_available' => defined('GIVE_VERSION'),
            'enabled' => nibwp_is_integration_enabled('givewp'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>',
            'category' => 'donations',
            'abilities' => ['givewp-manage'],
            'plugin_name' => 'GiveWP',
        ],
        'redirection' => [
            'name' => 'Redirection',
            'description' => __('Manage URL redirects (301/302/307), view and clear 404 error logs.', domain: 'nibwp'),
            'plugin_available' => class_exists('Red_Item'),
            'enabled' => nibwp_is_integration_enabled('redirection'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M19 15l-6 6-1.42-1.42L15.17 16H4V4h2v10h9.17l-3.59-3.58L13 9l6 6z"/></svg>',
            'category' => 'utilities',
            'abilities' => ['redirection-manage'],
            'plugin_name' => 'Redirection',
        ],
        'tablepress' => [
            'name' => 'TablePress',
            'description' => __('Create, edit, and manage data tables with rows and columns.', domain: 'nibwp'),
            'plugin_available' => class_exists('TablePress'),
            'enabled' => nibwp_is_integration_enabled('tablepress'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M3 3v18h18V3H3zm8 16H5v-6h6v6zm0-8H5V5h6v6zm8 8h-6v-6h6v6zm0-8h-6V5h6v6z"/></svg>',
            'category' => 'utilities',
            'abilities' => ['tablepress-manage'],
            'plugin_name' => 'TablePress',
        ],
        'translatepress' => [
            'name' => 'TranslatePress',
            'description' => __('Manage languages and translations for multilingual sites.', domain: 'nibwp'),
            'plugin_available' => defined('TRP_PLUGIN_VERSION'),
            'enabled' => nibwp_is_integration_enabled('translatepress'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12.87 15.07l-2.54-2.51.03-.03c1.74-1.94 2.98-4.17 3.71-6.53H17V4h-7V2H8v2H1v1.99h11.17C11.5 7.92 10.44 9.75 9 11.35 8.07 10.32 7.3 9.19 6.69 8h-2c.73 1.63 1.73 3.17 2.98 4.56l-5.09 5.02L4 19l5-5 3.11 3.11.76-2.04zM18.5 10h-2L12 22h2l1.12-3h4.75L21 22h2l-4.5-12zm-2.62 7l1.62-4.33L19.12 17h-3.24z"/></svg>',
            'category' => 'multilingual',
            'abilities' => ['translatepress-manage'],
            'plugin_name' => 'TranslatePress',
        ],
        'wpml' => [
            'name' => 'WPML',
            'description' => __('Manage languages, translations, and content duplication for multilingual WordPress.', domain: 'nibwp'),
            'plugin_available' => defined('ICL_SITEPRESS_VERSION'),
            'enabled' => nibwp_is_integration_enabled('wpml'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12.87 15.07l-2.54-2.51.03-.03c1.74-1.94 2.98-4.17 3.71-6.53H17V4h-7V2H8v2H1v1.99h11.17C11.5 7.92 10.44 9.75 9 11.35 8.07 10.32 7.3 9.19 6.69 8h-2c.73 1.63 1.73 3.17 2.98 4.56l-5.09 5.02L4 19l5-5 3.11 3.11.76-2.04zM18.5 10h-2L12 22h2l1.12-3h4.75L21 22h2l-4.5-12zm-2.62 7l1.62-4.33L19.12 17h-3.24z"/></svg>',
            'category' => 'multilingual',
            'abilities' => ['wpml-manage'],
            'plugin_name' => 'WPML',
        ],
        // Automatic.css (ACSS). Single integration covers both design-token and
        // settings/recipe management. Legacy `'acss'` key is migrated on read
        // into `'automaticcss'` so users who toggled either one stay enabled.
        'automaticcss' => [
            'name' => 'Automatic.css',
            'description' => __('Manage ACSS design tokens (colors, spacing, typography, sizing), CSS recipes, utility classes, and regenerate the stylesheet. The #1 utility framework for page builders.', domain: 'nibwp'),
            'plugin_available' => defined('ACSS_PLUGIN_FILE'),
            'enabled' => nibwp_is_integration_enabled('automaticcss'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M9.4 16.6L4.8 12l4.6-4.6L8 6l-6 6 6 6 1.4-1.4zm5.2 0l4.6-4.6-4.6-4.6L16 6l6 6-6 6-1.4-1.4z"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['acss-get-variables', 'acss-update-variables', 'acss-get-classes', 'acss-regenerate'],
            'plugin_name' => 'Automatic.css',
        ],
        'etchwp' => [
            'name' => 'EtchWP',
            'description' => __('Manage blocks, custom fields, styles, fonts, assets, and preprocessor settings via the Etch unified dev environment.', domain: 'nibwp'),
            'plugin_available' => defined('ETCH_PLUGIN_FILE'),
            'enabled' => nibwp_is_integration_enabled('etchwp'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['etchwp-manage-blocks', 'etchwp-manage-fields', 'etchwp-manage-styles', 'etchwp-manage-assets'],
            'plugin_name' => 'EtchWP',
        ],
        'content-planner' => [
            'name' => __('Content Planner', domain: 'nibwp'),
            'description' => __('Schedule posts, editorial calendar, bulk scheduling with intervals, draft manager with priority and notes, reschedule future posts.', domain: 'nibwp'),
            'plugin_available' => true,
            'enabled' => true,
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM9 10H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2z"/></svg>',
            'category' => 'built-in',
            'abilities' => ['content-schedule-post', 'content-list-scheduled', 'content-reschedule', 'content-calendar', 'content-bulk-schedule', 'content-draft-manager'],
            'plugin_name' => '',
        ],
        'content-fetcher' => [
            'name' => __('Content Fetcher', domain: 'nibwp'),
            'description' => __('Fetch content from URLs, parse RSS feeds, rewrite/transform text, import to drafts, bulk import from feeds, extract sitemap URLs.', domain: 'nibwp'),
            'plugin_available' => true,
            'enabled' => true,
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>',
            'category' => 'built-in',
            'abilities' => ['fetch-url-content', 'fetch-rss-feed', 'fetch-rewrite-content', 'fetch-to-draft', 'fetch-bulk-import', 'fetch-sitemap-urls'],
            'plugin_name' => '',
        ],
        'seo-advanced' => [
            'name' => __('SEO Advanced', domain: 'nibwp'),
            'description' => __('Image SEO audit & fix, JSON-LD schema generator, broken link checker, redirect manager, bulk meta generator, internal linking suggestions.', domain: 'nibwp'),
            'plugin_available' => true,
            'enabled' => true,
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M11 7h2v2h-2zm0 4h2v6h-2zm1-9C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8z"/></svg>',
            'category' => 'seo',
            'abilities' => ['seo-image-optimize', 'seo-schema-markup', 'seo-broken-links', 'seo-redirect-manager', 'seo-meta-bulk-generate', 'seo-internal-linking'],
            'plugin_name' => '',
        ],
        'migration' => [
            'name' => __('Migration Toolkit', domain: 'nibwp'),
            'description' => __('Export/import posts, options, menus. Clone posts with meta. Search & replace URLs across the database with serialized data support.', domain: 'nibwp'),
            'plugin_available' => true,
            'enabled' => true,
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>',
            'category' => 'built-in',
            'abilities' => ['migration-export-content', 'migration-import-content', 'migration-export-options', 'migration-import-options', 'migration-search-replace-urls', 'migration-clone-post', 'migration-export-menus', 'migration-import-menus'],
            'plugin_name' => '',
        ],
        'notifications' => [
            'name' => __('Notifications & SMS', domain: 'nibwp'),
            'description' => __('Send emails, SMS via Twilio, bulk messaging, webhooks, notification templates. Full 2-way SMS with WP Twilio Core integration.', domain: 'nibwp'),
            'plugin_available' => true,
            'enabled' => true,
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.89 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></svg>',
            'category' => 'built-in',
            'abilities' => ['notify-send-email', 'notify-send-sms', 'notify-bulk-email', 'notify-bulk-sms', 'notify-manage-templates', 'notify-configure-twilio', 'notify-send-wp-notification', 'notify-webhook'],
            'plugin_name' => '',
        ],
        'security' => [
            'name' => __('Security & Maintenance', domain: 'nibwp'),
            'description' => __('Malware scanner, core integrity checker, auto-repair, database cleanup, quarantine, file permissions, user audit, find & replace, health check, and password reset.', domain: 'nibwp'),
            'plugin_available' => true,
            'enabled' => true,
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"/></svg>',
            'category' => 'security',
            'abilities' => ['security-verify-core', 'security-repair-core', 'security-scan-malware', 'security-quarantine-file', 'security-find-replace', 'security-scan-database', 'security-db-find-replace', 'security-audit-users', 'security-health-check', 'security-cleanup', 'security-file-permissions', 'security-change-passwords'],
            'plugin_name' => '',
        ],
        'wp-job-manager' => [
            'name' => 'WP Job Manager',
            'description' => __('Manage job listings — create, update, delete, search, and filter. Handle company info, taxonomies (types/categories), location, application contact, and featured/filled states.', domain: 'nibwp'),
            'plugin_available' => defined('JOB_MANAGER_VERSION') || class_exists('WP_Job_Manager'),
            'enabled' => nibwp_is_integration_enabled('wp-job-manager'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M20 6h-4V4c0-1.11-.89-2-2-2h-4c-1.11 0-2 .89-2 2v2H4c-1.11 0-1.99.89-1.99 2L2 19c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2zm-6 0h-4V4h4v2z"/></svg>',
            'category' => 'jobs',
            'abilities' => ['wpjm-list-jobs', 'wpjm-get-job', 'wpjm-create-job', 'wpjm-update-job', 'wpjm-delete-job', 'wpjm-list-taxonomies'],
            'plugin_name' => 'WP Job Manager',
        ],
        'memory' => [
            'name' => __('Memory', domain: 'nibwp'),
            'description' => __('Store and recall project context across AI sessions. Remembers conventions, decisions, patterns, and configuration.', domain: 'nibwp'),
            'plugin_available' => true,
            'enabled' => true,
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>',
            'category' => 'built-in',
            'abilities' => ['memory-store', 'memory-recall', 'memory-delete', 'memory-list-keys'],
            'plugin_name' => '',
        ],
    ];
}

/**
 * Handle integration toggle submissions.
 */
function nibwp_handle_integration_toggle(): ?string
{
    if (!isset($_POST['nibwp_integration_toggle'])) {
        return null;
    }
    if (!current_user_can('manage_options')) {
        return null;
    }
    check_admin_referer('nibwp_integration_toggle');

    $integration_key = sanitize_key($_POST['nibwp_integration_key'] ?? '');
    $new_state = !empty($_POST['nibwp_integration_state']);

    $integrations = nibwp_get_integrations();
    if (!array_key_exists($integration_key, $integrations) || $integration_key === 'memory') {
        return null;
    }

    $enabled = nibwp_get_enabled_integrations();
    $enabled[$integration_key] = $new_state;
    update_option('nibwp_enabled_integrations', $enabled);

    return $integration_key;
}

/**
 * Handle "Activate all" / "Activate only detected" bulk submissions.
 * Returns ['mode' => 'all'|'detected', 'count' => newly-enabled] or null.
 */
function nibwp_handle_integration_bulk(): ?array
{
    if (empty($_POST['nibwp_integration_bulk'])) {
        return null;
    }
    if (!current_user_can('manage_options')) {
        return null;
    }
    check_admin_referer('nibwp_integration_toggle');

    $mode = sanitize_key((string) $_POST['nibwp_integration_bulk']);
    if (!in_array($mode, ['all', 'detected', 'none'], true)) {
        return null;
    }
    $on = $mode !== 'none';

    $integrations = nibwp_get_integrations();
    $enabled = nibwp_get_enabled_integrations();
    $count = 0;
    foreach ($integrations as $key => $info) {
        if ($key === 'memory') {
            continue; // always on
        }
        if ($on && $mode === 'detected' && empty($info['plugin_available'])) {
            continue;
        }
        if ($on && !empty($info['premium']) && !nibwp_is_pro() && !nibwp_has_entitlement('integration:' . $key)) {
            continue;
        }
        if ((bool) ($enabled[$key] ?? false) !== $on) {
            $count++;
        }
        $enabled[$key] = $on;
    }
    update_option('nibwp_enabled_integrations', $enabled);

    return ['mode' => $mode, 'count' => $count];
}

/**
 * REST: toggle an integration on/off. Used by the AJAX toggle on the
 * Integrations page (no page reload). Permission = manage_options + wp_rest
 * nonce. Memory is always on; the endpoint refuses to flip it.
 */
add_action('rest_api_init', static function (): void {
    register_rest_route('nibwp/v1', '/integrations/toggle', [
        'methods'             => 'POST',
        'permission_callback' => static function (): bool {
            return current_user_can('manage_options');
        },
        'args' => [
            'key'     => ['type' => 'string',  'required' => true],
            'enabled' => ['type' => 'boolean', 'required' => true],
        ],
        'callback' => static function (\WP_REST_Request $req): \WP_REST_Response {
            $key     = sanitize_key((string) $req->get_param('key'));
            $enabled = (bool) $req->get_param('enabled');

            $integrations = nibwp_get_integrations();
            if (!array_key_exists($key, $integrations)) {
                return new \WP_REST_Response(['ok' => false, 'message' => 'Unknown integration'], 404);
            }
            if ($key === 'memory') {
                return new \WP_REST_Response(['ok' => false, 'message' => 'Memory is always on'], 422);
            }

            // Block toggling a Pro/locked integration without a license.
            $integration = $integrations[$key];
            if (!empty($integration['premium']) && !nibwp_is_pro() && !nibwp_has_entitlement('integration:' . $key)) {
                return new \WP_REST_Response(['ok' => false, 'message' => 'This integration requires NIBWP Pro.'], 403);
            }

            $state = nibwp_get_enabled_integrations();
            $state[$key] = $enabled;
            update_option('nibwp_enabled_integrations', $state);

            // Re-read so the response reflects normalized state.
            $integrations = nibwp_get_integrations();
            $i = $integrations[$key];
            return new \WP_REST_Response([
                'ok'        => true,
                'key'       => $key,
                'enabled'   => (bool) $i['enabled'],
                'available' => (bool) $i['plugin_available'],
                'active'    => (bool) $i['plugin_available'] && (bool) $i['enabled'],
                'active_count' => (int) array_sum(array_map(
                    static fn ($it) => ($it['enabled'] && $it['plugin_available']) ? 1 : 0,
                    $integrations,
                )),
            ], 200);
        },
    ]);

    // Bulk activate: mode=all|detected. Enables every integration (or only the
    // installed ones), skipping memory + premium integrations the user can't
    // unlock. Returns a per-key state map so the page updates live (no reload).
    register_rest_route('nibwp/v1', '/integrations/bulk', [
        'methods'             => 'POST',
        'permission_callback' => static fn (): bool => current_user_can('manage_options'),
        'args' => ['mode' => ['type' => 'string', 'required' => true]],
        'callback' => static function (\WP_REST_Request $req): \WP_REST_Response {
            $mode = sanitize_key((string) $req->get_param('mode'));
            if (!in_array($mode, ['all', 'detected', 'none'], true)) {
                return new \WP_REST_Response(['ok' => false, 'message' => 'Bad mode'], 422);
            }
            $on = $mode !== 'none';
            $integrations = nibwp_get_integrations();
            $state = nibwp_get_enabled_integrations();
            foreach ($integrations as $key => $info) {
                if ($key === 'memory') {
                    continue;
                }
                if ($on && $mode === 'detected' && empty($info['plugin_available'])) {
                    continue;
                }
                if ($on && !empty($info['premium']) && !nibwp_is_pro() && !nibwp_has_entitlement('integration:' . $key)) {
                    continue; // can't unlock — don't pretend to enable
                }
                $state[$key] = $on;
            }
            update_option('nibwp_enabled_integrations', $state);

            $integrations = nibwp_get_integrations();
            $items = [];
            foreach ($integrations as $key => $i) {
                $items[$key] = [
                    'enabled'   => (bool) $i['enabled'],
                    'available' => (bool) $i['plugin_available'],
                    'active'    => (bool) $i['plugin_available'] && (bool) $i['enabled'],
                ];
            }
            return new \WP_REST_Response([
                'ok'           => true,
                'mode'         => $mode,
                'items'        => $items,
                'active_count' => (int) array_sum(array_map(static fn ($it) => $it['active'] ? 1 : 0, $items)),
            ], 200);
        },
    ]);
});

/**
 * Render the Integrations admin page.
 */
function nibwp_render_integrations_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $toggled = nibwp_handle_integration_toggle();
    $bulk = nibwp_handle_integration_bulk();
    // Re-fetch after save so toggle states are current.
    $integrations = nibwp_get_integrations();

    $categories = [
        'built-in' => __('Built-in', domain: 'nibwp'),
        'security' => __('Security & Maintenance', domain: 'nibwp'),
        'page-builders' => __('Page Builders', domain: 'nibwp'),
        'design' => __('Design', domain: 'nibwp'),
        'ecommerce' => __('E-Commerce', domain: 'nibwp'),
        'forms' => __('Forms', domain: 'nibwp'),
        'crm' => __('CRM & Marketing', domain: 'nibwp'),
        'events' => __('Events', domain: 'nibwp'),
        'community' => __('Community', domain: 'nibwp'),
        'lms' => __('Learning (LMS)', domain: 'nibwp'),
        'membership' => __('Membership', domain: 'nibwp'),
        'donations' => __('Donations', domain: 'nibwp'),
        'seo' => __('SEO', domain: 'nibwp'),
        'multilingual' => __('Multilingual', domain: 'nibwp'),
        'utilities' => __('Utilities', domain: 'nibwp'),
        'custom-fields' => __('Custom Fields & Content Types', domain: 'nibwp'),
        'jobs' => __('Jobs & Recruitment', domain: 'nibwp'),
    ];

    $active_count = count(array_filter($integrations, static fn($i) => $i['plugin_available'] && $i['enabled']));
    $total_count = count($integrations);

    ?>
    <?php nibwp_render_admin_header(); ?>
    <div class="wrap nibwp-wrap">
        <div class="nibwp-page-header nibwp-page-header--with-search">
            <div>
                <h1><?php esc_html_e('Integrations', 'nibwp'); ?></h1>
                <p class="nibwp-subtitle">
                    <span id="nw-int-stat-active"><?php echo (int) $active_count; ?></span>
                    <?php printf(
                        /* translators: %d = total integrations */
                        esc_html__('of %d integrations active.', 'nibwp'),
                        $total_count,
                    ); ?>
                </p>
            </div>
            <div class="nw-page-search-wrap">
                <div class="nw-tier-filter" role="group" aria-label="<?php esc_attr_e('Filter by tier', 'nibwp'); ?>">
                    <button type="button" class="nw-tier-pill is-active" data-tier="all"><?php esc_html_e('All', 'nibwp'); ?></button>
                    <button type="button" class="nw-tier-pill" data-tier="free"><?php esc_html_e('Free', 'nibwp'); ?></button>
                    <button type="button" class="nw-tier-pill" data-tier="premium"><?php esc_html_e('Premium', 'nibwp'); ?></button>
                </div>
                <form method="post" class="nw-tier-filter nw-bulk-acts" aria-label="<?php esc_attr_e('Bulk activate', 'nibwp'); ?>">
                    <?php wp_nonce_field('nibwp_integration_toggle'); ?>
                    <button type="submit" name="nibwp_integration_bulk" value="detected" class="nw-tier-pill"><?php esc_html_e('Activate detected', 'nibwp'); ?></button>
                    <button type="submit" name="nibwp_integration_bulk" value="all" class="nw-tier-pill nw-bulk-btn--all"><?php esc_html_e('Activate all', 'nibwp'); ?></button>
                    <button type="submit" name="nibwp_integration_bulk" value="none" class="nw-tier-pill nw-bulk-btn--off"><?php esc_html_e('Deactivate all', 'nibwp'); ?></button>
                </form>
                <div class="nw-page-search" role="search">
                    <span class="nw-page-search__icon" aria-hidden="true">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="7" cy="7" r="4.5"/><path d="M10.5 10.5L14 14"/></svg>
                    </span>
                    <label for="nw-int-search" class="screen-reader-text"><?php esc_html_e('Search integrations', 'nibwp'); ?></label>
                    <input
                        type="search"
                        id="nw-int-search"
                        class="nw-page-search__input"
                        placeholder="<?php esc_attr_e('Search integrations & press Enter', 'nibwp'); ?>"
                        autocomplete="off"
                        spellcheck="false"
                    />
                    <button type="button" class="nw-page-search__clear" id="nw-int-search-clear" aria-label="<?php esc_attr_e('Clear search', 'nibwp'); ?>" hidden>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                    </button>
                    <span class="nw-page-search__count" id="nw-int-search-count"><?php echo (int) $total_count; ?> / <?php echo (int) $total_count; ?></span>
                </div>
                <div class="nw-sort-acts">
                    <label for="nw-int-sort" class="screen-reader-text"><?php esc_html_e('Sort integrations', 'nibwp'); ?></label>
                    <select id="nw-int-sort" class="nw-sort-select" aria-label="<?php esc_attr_e('Sort integrations', 'nibwp'); ?>">
                        <option value="default"><?php esc_html_e('Default', 'nibwp'); ?></option>
                        <option value="detected"><?php esc_html_e('Per plugin detected', 'nibwp'); ?></option>
                        <option value="az"><?php esc_html_e('A–Z Sort', 'nibwp'); ?></option>
                    </select>
                </div>
                
            </div>
        </div>
        <?php // wp-header-end: tells WP admin_notices to inject BELOW the header, not inside its flex row (which squeezed the search bar). ?>
        <hr class="wp-header-end" style="border:0;margin:0;height:0">

        <?php if ($bulk !== null): ?>
            <div class="notice notice-success is-dismissible"><p><?php
                printf(
                    esc_html__('%1$d integrations %2$s (%3$s).', 'nibwp'),
                    (int) $bulk['count'],
                    $bulk['mode'] === 'none' ? esc_html__('deactivated', 'nibwp') : esc_html__('activated', 'nibwp'),
                    $bulk['mode'] === 'detected' ? esc_html__('detected only', 'nibwp') : esc_html__('all', 'nibwp'),
                );
            ?></p></div>
        <?php endif; ?>

        <?php if ($toggled !== null): ?>
            <div class="notice notice-success is-dismissible"><p><?php
                $info = $integrations[$toggled] ?? null;
                if ($info !== null) {
                    printf(
                        esc_html__('%s integration %s.', domain: 'nibwp'),
                        '<strong>' . esc_html($info['name']) . '</strong>',
                        $info['enabled']
                            ? esc_html__('activated', domain: 'nibwp')
                            : esc_html__('deactivated', domain: 'nibwp'),
                    );
                }
            ?></p></div>
        <?php endif; ?>

        <!-- Tabs (hover-to-scroll) -->
        <div class="nw-int-tabs-wrap" id="nw-int-tabs-wrap">
            <div class="nw-int-tabs" id="nw-int-tabs">
                <button type="button" class="nw-int-tab is-active" data-tab="all">
                    <?php esc_html_e('All', domain: 'nibwp'); ?>
                    <span class="nw-int-tab-count"><?php echo $total_count; ?></span>
                </button>
                <?php foreach ($categories as $cat_key => $cat_label):
                    $cat_count = count(array_filter($integrations, static fn($i) => $i['category'] === $cat_key));
                    if ($cat_count === 0) continue;
                ?>
                    <button type="button" class="nw-int-tab" data-tab="<?php echo esc_attr($cat_key); ?>">
                        <?php echo esc_html($cat_label); ?>
                        <span class="nw-int-tab-count"><?php echo $cat_count; ?></span>
                    </button>
                <?php endforeach; ?>
                <button type="button" class="nw-int-tab nw-int-tab-cta" id="nw-request-integration-btn">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;"><path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 16.8 5.8 21.3l2.4-7.4L2 9.4h7.6z"/></svg>
                    <?php esc_html_e('Need a custom Integration?', domain: 'nibwp'); ?>
                </button>
            </div>
        </div>

        <!-- Custom Integration Request Modal -->
        <div class="nw-req-modal" id="nw-req-modal" role="dialog" aria-hidden="true">
            <div class="nw-req-modal__backdrop" id="nw-req-modal-backdrop"></div>
            <div class="nw-req-modal__panel">
                <button type="button" class="nw-req-modal__close" id="nw-req-modal-close" aria-label="<?php esc_attr_e('Close', domain: 'nibwp'); ?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>

                <div class="nw-req-modal__head">
                    <div class="nw-req-modal__icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 16.8 5.8 21.3l2.4-7.4L2 9.4h7.6z"/></svg>
                    </div>
                    <div>
                        <strong><?php esc_html_e('Request a Custom Skill or Integration', domain: 'nibwp'); ?></strong>
                        <span><?php esc_html_e('Tell us what you need. We build new integrations every week.', domain: 'nibwp'); ?></span>
                    </div>
                </div>

                <!-- Step indicator -->
                <div class="nw-req-steps">
                    <div class="nw-req-step is-active" data-step="1"><span>1</span><?php esc_html_e('Type', domain: 'nibwp'); ?></div>
                    <div class="nw-req-step-divider"></div>
                    <div class="nw-req-step" data-step="2"><span>2</span><?php esc_html_e('Details', domain: 'nibwp'); ?></div>
                    <div class="nw-req-step-divider"></div>
                    <div class="nw-req-step" data-step="3"><span>3</span><?php esc_html_e('Contact', domain: 'nibwp'); ?></div>
                </div>

                <form id="nw-req-form" class="nw-req-form">
                    <!-- STEP 1: Type selector -->
                    <div class="nw-req-pane is-active" data-pane="1">
                        <div class="nw-req-pane__title"><?php esc_html_e('What do you need?', domain: 'nibwp'); ?></div>
                        <div class="nw-req-options">
                            <label class="nw-req-option">
                                <input type="radio" name="request_type" value="plugin-integration" required>
                                <div class="nw-req-option__icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 2v4m0 12v4M2 12h4m12 0h4M4.9 4.9l2.8 2.8m8.6 8.6l2.8 2.8M4.9 19.1l2.8-2.8m8.6-8.6l2.8-2.8"/></svg>
                                </div>
                                <div class="nw-req-option__text">
                                    <strong><?php esc_html_e('Plugin integration', domain: 'nibwp'); ?></strong>
                                    <span><?php esc_html_e('Add AI tools for a specific WordPress plugin', domain: 'nibwp'); ?></span>
                                </div>
                            </label>
                            <label class="nw-req-option">
                                <input type="radio" name="request_type" value="custom-skill">
                                <div class="nw-req-option__icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                </div>
                                <div class="nw-req-option__text">
                                    <strong><?php esc_html_e('Custom AI skill', domain: 'nibwp'); ?></strong>
                                    <span><?php esc_html_e('A specific MCP tool for your workflow', domain: 'nibwp'); ?></span>
                                </div>
                            </label>
                            <label class="nw-req-option">
                                <input type="radio" name="request_type" value="api-connection">
                                <div class="nw-req-option__icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/></svg>
                                </div>
                                <div class="nw-req-option__text">
                                    <strong><?php esc_html_e('Third-party API', domain: 'nibwp'); ?></strong>
                                    <span><?php esc_html_e('Connect external services (Stripe, Slack, etc.)', domain: 'nibwp'); ?></span>
                                </div>
                            </label>
                            <label class="nw-req-option">
                                <input type="radio" name="request_type" value="other">
                                <div class="nw-req-option__icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                </div>
                                <div class="nw-req-option__text">
                                    <strong><?php esc_html_e('Something else', domain: 'nibwp'); ?></strong>
                                    <span><?php esc_html_e('Tell us more in the next step', domain: 'nibwp'); ?></span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- STEP 2: Details -->
                    <div class="nw-req-pane" data-pane="2">
                        <div class="nw-req-pane__title"><?php esc_html_e('Tell us more', domain: 'nibwp'); ?></div>
                        <div class="nw-req-field">
                            <label for="nw-req-name-plugin"><?php esc_html_e('Plugin / Service name', domain: 'nibwp'); ?></label>
                            <input type="text" id="nw-req-name-plugin" name="plugin_name" placeholder="e.g. Easy Affiliate, Stripe API, custom CRM">
                        </div>
                        <div class="nw-req-field">
                            <label for="nw-req-url"><?php esc_html_e('Plugin or API URL', domain: 'nibwp'); ?> <span class="nw-req-optional"><?php esc_html_e('optional', domain: 'nibwp'); ?></span></label>
                            <input type="url" id="nw-req-url" name="plugin_url" placeholder="https://...">
                        </div>
                        <div class="nw-req-field">
                            <label for="nw-req-desc"><?php esc_html_e('What should AI agents be able to do?', domain: 'nibwp'); ?></label>
                            <textarea id="nw-req-desc" name="description" rows="4" placeholder="<?php esc_attr_e('e.g. List products, create orders, update inventory, send notifications...', domain: 'nibwp'); ?>" required></textarea>
                        </div>
                        <div class="nw-req-field">
                            <label><?php esc_html_e('Priority', domain: 'nibwp'); ?></label>
                            <div class="nw-req-priority">
                                <label><input type="radio" name="priority" value="low"><span><?php esc_html_e('Nice to have', domain: 'nibwp'); ?></span></label>
                                <label><input type="radio" name="priority" value="medium" checked><span><?php esc_html_e('Important', domain: 'nibwp'); ?></span></label>
                                <label><input type="radio" name="priority" value="high"><span><?php esc_html_e('Urgent', domain: 'nibwp'); ?></span></label>
                            </div>
                        </div>
                    </div>

                    <!-- STEP 3: Contact -->
                    <div class="nw-req-pane" data-pane="3">
                        <div class="nw-req-pane__title"><?php esc_html_e('How can we reach you?', domain: 'nibwp'); ?></div>
                        <div class="nw-req-field">
                            <label for="nw-req-name"><?php esc_html_e('Your name', domain: 'nibwp'); ?></label>
                            <input type="text" id="nw-req-name" name="user_name" value="<?php echo esc_attr(wp_get_current_user()->display_name); ?>" required>
                        </div>
                        <div class="nw-req-field">
                            <label for="nw-req-email"><?php esc_html_e('Email address', domain: 'nibwp'); ?></label>
                            <input type="email" id="nw-req-email" name="user_email" value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>" required>
                        </div>
                        <div class="nw-req-field">
                            <label class="nw-req-checkbox-row">
                                <input type="checkbox" name="newsletter" value="1" checked>
                                <span><?php esc_html_e('Notify me when this integration is built', domain: 'nibwp'); ?></span>
                            </label>
                        </div>
                    </div>

                    <!-- SUCCESS pane -->
                    <div class="nw-req-pane nw-req-pane-success" data-pane="success">
                        <div class="nw-req-success-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        </div>
                        <strong><?php esc_html_e('Request sent!', domain: 'nibwp'); ?></strong>
                        <p><?php esc_html_e('We received your request. Our team reviews every submission and prioritizes new builds based on community demand.', domain: 'nibwp'); ?></p>
                        <p style="font-size:12px;color:var(--nw-text-muted);"><?php esc_html_e("You'll hear from us within 2 business days.", domain: 'nibwp'); ?></p>
                    </div>

                    <!-- Footer with buttons -->
                    <div class="nw-req-foot">
                        <button type="button" class="nw-req-btn nw-req-btn-secondary" id="nw-req-back" disabled><?php esc_html_e('Back', domain: 'nibwp'); ?></button>
                        <button type="button" class="nw-req-btn nw-req-btn-primary" id="nw-req-next"><?php esc_html_e('Next', domain: 'nibwp'); ?>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                        </button>
                        <button type="submit" class="nw-req-btn nw-req-btn-primary" id="nw-req-submit" style="display:none;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                            <?php esc_html_e('Send Request', domain: 'nibwp'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Figma Connect Modal (same styling system as the request modal) -->
        <?php
        $fig_connected = function_exists('nibwp_figma_is_connected') && nibwp_figma_is_connected();
        $fig_handle    = (string) get_option('nibwp_figma_handle', '');
        $fig_cid       = (string) get_option('nibwp_figma_oauth_client_id', '');
        $fig_secret_ok = get_option('nibwp_figma_oauth_client_secret', '') !== '';
        $fig_cb        = function_exists('nibwp_figma_oauth_redirect_uri') ? nibwp_figma_oauth_redirect_uri() : '';
        $fig_post      = esc_url(admin_url('admin-post.php'));
        ?>
        <div class="nw-req-modal" id="nw-fig-modal" role="dialog" aria-hidden="true">
            <div class="nw-req-modal__backdrop" id="nw-fig-modal-backdrop"></div>
            <div class="nw-req-modal__panel">
                <button type="button" class="nw-req-modal__close" id="nw-fig-modal-close" aria-label="<?php esc_attr_e('Close', domain: 'nibwp'); ?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>

                <div class="nw-req-modal__head">
                    <div class="nw-req-modal__icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2H8.5a3.5 3.5 0 000 7H12zm0 0h3.5a3.5 3.5 0 110 7H12zM8.5 9a3.5 3.5 0 000 7H12V9zm0 7a3.5 3.5 0 103.5 3.5V16zm7-7a3.5 3.5 0 100 7 3.5 3.5 0 000-7z"/></svg>
                    </div>
                    <div>
                        <strong><?php esc_html_e('Connect Figma', 'nibwp'); ?></strong>
                        <span><?php esc_html_e('Read-only. Pull frames as images + CSS tokens — NibWP + AI decide what to build.', 'nibwp'); ?></span>
                    </div>
                </div>

                <style>
                    /* Figma connect modal — scoped enrichments on top of nw-req-* */
                    #nw-fig-modal .nw-req-field input { width: 100%; max-width: none; }
                    /* Action rows: buttons and button-links sit on one baseline.
                       WP admin styles anchors blue and squares their focus ring, so
                       anchors styled as buttons need explicit color + radius here. */
                    #nw-fig-modal .nw-fig-actions {
                        display: flex; align-items: center; gap: 8px;
                        flex-wrap: wrap; margin-top: 12px;
                    }
                    #nw-fig-modal .nw-fig-actions form { margin: 0; }
                    #nw-fig-modal .nw-req-btn { margin-left: 0; border-radius: 8px; }
                    #nw-fig-modal a.nw-req-btn,
                    #nw-fig-modal a.nw-req-btn:hover,
                    #nw-fig-modal a.nw-req-btn:focus,
                    #nw-fig-modal a.nw-req-btn:active { text-decoration: none; box-shadow: none; }
                    #nw-fig-modal a.nw-req-btn-primary,
                    #nw-fig-modal a.nw-req-btn-primary:hover,
                    #nw-fig-modal a.nw-req-btn-primary:focus,
                    #nw-fig-modal a.nw-req-btn-primary:active { color: #fff; }
                    #nw-fig-modal .nw-req-btn:focus-visible {
                        outline: none;
                        box-shadow: 0 0 0 3px rgba(245, 158, 11, .35);
                    }
                    /* Figma's accent is amber — keep primaries on-brand for this
                       modal instead of the default blue. */
                    #nw-fig-modal .nw-req-btn-primary {
                        background: linear-gradient(135deg, #f59e0b, #d97706);
                        border-color: #d97706;
                    }
                    #nw-fig-modal .nw-req-btn-primary:hover:not(:disabled) {
                        background: linear-gradient(135deg, #f59e0b, #b45309) !important;
                        color: #fff;
                    }
                    #nw-fig-modal .nw-fig-mask {
                        display: inline-block; margin-left: 8px; padding: 1px 6px;
                        border-radius: 5px; background: var(--nw-surface-2, #f6f7f9);
                        border: 1px solid var(--nw-border, #e2e4e7);
                        font-size: 11.5px; letter-spacing: .04em;
                        color: var(--nw-text-2, #3c434a);
                    }
                    #nw-fig-modal .nw-fig-choose {
                        margin: 0 0 10px; font-size: 12.5px; font-weight: 500;
                        color: var(--nw-text-2, #3c434a);
                    }
                    /* Method tabs */
                    #nw-fig-modal .nw-fig-tabs {
                        display: flex; gap: 8px; margin: 2px 0 16px; flex-wrap: wrap;
                    }
                    #nw-fig-modal .nw-fig-tab {
                        display: inline-flex; align-items: center; gap: 8px; cursor: pointer;
                        border: 1px solid var(--nw-border, #e2e4e7); border-radius: 999px;
                        background: var(--nw-surface, #fff); color: var(--nw-text-2, #3c434a);
                        font-size: 12.5px; font-weight: 600; padding: 7px 14px 7px 8px;
                        transition: border-color .15s, box-shadow .15s, background .15s;
                    }
                    #nw-fig-modal .nw-fig-tab:hover { border-color: #f0b25a; }
                    #nw-fig-modal .nw-fig-tab .nw-fig-tab__num {
                        width: 20px; height: 20px; border-radius: 50%; display: inline-grid; place-items: center;
                        font-size: 11px; font-weight: 700; color: #b45309;
                        background: #fff7ed; border: 1px solid #fed7aa;
                    }
                    #nw-fig-modal .nw-fig-tab.is-active {
                        background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff; border-color: #d97706;
                        box-shadow: 0 4px 12px rgba(217, 119, 6, .28);
                    }
                    #nw-fig-modal .nw-fig-tab.is-active .nw-fig-tab__num {
                        background: rgba(255,255,255,.22); border-color: rgba(255,255,255,.45); color: #fff;
                    }
                    #nw-fig-modal .nw-fig-tab small { font-weight: 500; opacity: .75; font-size: 10.5px; }
                    /* Method panes */
                    #nw-fig-modal .nw-fig-pane { display: none; }
                    #nw-fig-modal .nw-fig-pane.is-active { display: block; animation: nw-pane-in 0.2s ease; }
                    #nw-fig-modal .nw-fig-method {
                        border: 1px solid var(--nw-border, #e2e4e7); border-radius: 12px;
                        padding: 16px 18px; background: var(--nw-surface, #fff);
                    }
                    #nw-fig-modal .nw-fig-method__head { display: flex; align-items: center; gap: 10px; margin-bottom: 4px; }
                    #nw-fig-modal .nw-fig-method__num {
                        flex: 0 0 26px; width: 26px; height: 26px; border-radius: 50%;
                        display: inline-grid; place-items: center; font-size: 13px; font-weight: 700;
                        background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff;
                    }
                    #nw-fig-modal .nw-fig-method__head strong { font-size: 14px; }
                    #nw-fig-modal .nw-fig-method__head .nw-fig-tag {
                        font-size: 10.5px; font-weight: 600; color: #b45309; background: #fff7ed;
                        border: 1px solid #fed7aa; border-radius: 999px; padding: 1px 8px; margin-left: 2px;
                    }
                    #nw-fig-modal .nw-fig-steps { margin: 8px 0 12px; padding: 0 0 0 4px; list-style: none; counter-reset: figstep; }
                    #nw-fig-modal .nw-fig-steps li {
                        counter-increment: figstep; position: relative; padding: 3px 0 3px 30px;
                        font-size: 12.5px; color: var(--nw-text-2, #3c434a); line-height: 1.55;
                    }
                    #nw-fig-modal .nw-fig-steps li::before {
                        content: counter(figstep); position: absolute; left: 0; top: 4px;
                        width: 19px; height: 19px; border-radius: 50%; font-size: 11px; font-weight: 700;
                        display: grid; place-items: center; color: #b45309;
                        background: #fff7ed; border: 1px solid #fed7aa;
                    }
                    #nw-fig-modal .nw-fig-steps a { font-weight: 600; }
                    #nw-fig-modal .nw-fig-callback {
                        display: block; background: var(--nw-surface-2, #f6f7f9); border: 1px solid var(--nw-border, #e2e4e7);
                        border-radius: 8px; padding: 8px 10px; font-size: 11.5px; word-break: break-all; margin: 4px 0 2px;
                    }
                    #nw-fig-modal .nw-fig-note { font-size: 12px; color: var(--nw-text-muted, #787c82); margin: 8px 0 0; line-height: 1.55; }
                    #nw-fig-modal .nw-req-modal__panel { max-width: 640px; }
                </style>
                <div class="nw-req-form">
                    <div class="nw-req-pane is-active" data-pane="fig" style="overflow-y:auto;">
                        <?php
                        $fig_msg = isset($_GET['figma_msg']) ? sanitize_text_field((string) wp_unslash($_GET['figma_msg'])) : '';
                        $fig_err = isset($_GET['figma_err']) ? sanitize_text_field((string) wp_unslash($_GET['figma_err'])) : '';
                        if ($fig_err !== '') {
                            echo '<p style="background:#fdecec;border:1px solid #f3c2c2;color:#b32d2e;border-radius:8px;padding:8px 12px;font-size:12.5px;margin:0 0 12px;">' . esc_html($fig_err) . '</p>';
                        } elseif ($fig_msg !== '') {
                            echo '<p style="background:#e7f6ec;border:1px solid #b7e0c4;color:#137333;border-radius:8px;padding:8px 12px;font-size:12.5px;margin:0 0 12px;">' . esc_html($fig_msg) . '</p>';
                        }
                        ?>
                        <?php if ($fig_connected): ?>
                            <div class="nw-fig-method">
                                <div class="nw-fig-method__head">
                                    <span class="nw-fig-method__num" style="background:linear-gradient(135deg,#22c55e,#15803d);">✓</span>
                                    <strong><?php esc_html_e('Connected', 'nibwp'); ?><?php echo $fig_handle !== '' ? ' — ' . esc_html($fig_handle) : ''; ?></strong>
                                    <?php $fig_mask = function_exists('nibwp_figma_token_mask') ? nibwp_figma_token_mask() : ''; ?>
                                    <?php if ($fig_mask !== ''): ?>
                                        <code class="nw-fig-mask"><?php echo esc_html($fig_mask); ?></code>
                                    <?php endif; ?>
                                </div>
                                <ol class="nw-fig-steps">
                                    <li><?php esc_html_e('Open any Figma file, select a frame or section, and copy its link (Share → Copy link, or ⌘/Ctrl-L).', 'nibwp'); ?></li>
                                    <li><?php esc_html_e('Pull it into your library from the Figma page — it is cached as an image + CSS design tokens. Nothing is converted yet.', 'nibwp'); ?></li>
                                    <li><?php esc_html_e('Ask NibWP AI what to do with it — reuse its colors/typography, or build it with your page builder in any workflow.', 'nibwp'); ?></li>
                                </ol>
                                <div class="nw-fig-actions">
                                    <a class="nw-req-btn nw-req-btn-primary" href="<?php echo esc_url(admin_url('admin.php?page=nibwp-figma')); ?>"><?php esc_html_e('Open Figma library', 'nibwp'); ?></a>
                                    <form method="post" action="<?php echo $fig_post; ?>">
                                        <?php wp_nonce_field('nibwp_figma_disconnect'); ?>
                                        <input type="hidden" name="action" value="nibwp_figma_disconnect" />
                                        <input type="hidden" name="from_modal" value="1" />
                                        <button type="submit" class="nw-req-btn nw-req-btn-secondary"><?php esc_html_e('Disconnect', 'nibwp'); ?></button>
                                    </form>
                                </div>
                            </div>
                        <?php else: ?>

                            <p class="nw-fig-choose"><?php esc_html_e('Pick one method — you only need a single one to connect.', 'nibwp'); ?></p>

                            <!-- Method tabs -->
                            <div class="nw-fig-tabs" role="tablist">
                                <button type="button" class="nw-fig-tab is-active" data-fig-tab="token" role="tab">
                                    <span class="nw-fig-tab__num">1</span><?php esc_html_e('Access token', 'nibwp'); ?>&nbsp;<small><?php esc_html_e('2 min', 'nibwp'); ?></small>
                                </button>
                                <button type="button" class="nw-fig-tab" data-fig-tab="oauth" role="tab">
                                    <span class="nw-fig-tab__num">2</span><?php esc_html_e('OAuth app', 'nibwp'); ?>&nbsp;<small><?php esc_html_e('teams', 'nibwp'); ?></small>
                                </button>
                                <button type="button" class="nw-fig-tab" data-fig-tab="mcp" role="tab">
                                    <span class="nw-fig-tab__num">3</span><?php esc_html_e('Dev Mode MCP', 'nibwp'); ?>&nbsp;<small><?php esc_html_e('AI in Figma', 'nibwp'); ?></small>
                                </button>
                            </div>

                            <!-- Pane 1 · Personal access token -->
                            <div class="nw-fig-pane is-active" data-fig-pane="token">
                                <div class="nw-fig-method">
                                    <div class="nw-fig-method__head">
                                        <strong><?php esc_html_e('Personal access token', 'nibwp'); ?></strong>
                                        <span class="nw-fig-tag"><?php esc_html_e('Recommended', 'nibwp'); ?></span>
                                    </div>
                                    <ol class="nw-fig-steps">
                                        <li><?php esc_html_e('In Figma, click your profile avatar (top-left) → Settings. A settings window opens.', 'nibwp'); ?></li>
                                        <li><?php esc_html_e('Go to the Security tab → Personal access tokens → Generate new token.', 'nibwp'); ?></li>
                                        <li><?php esc_html_e('Name it NibWP, set File content to Read-only, then Generate.', 'nibwp'); ?></li>
                                        <li><?php esc_html_e('Copy the token (figd_…, shown only once) and paste it below.', 'nibwp'); ?></li>
                                    </ol>
                                    <form method="post" action="<?php echo $fig_post; ?>">
                                        <?php wp_nonce_field('nibwp_figma_save_token'); ?>
                                        <input type="hidden" name="action" value="nibwp_figma_save_token" />
                                        <input type="hidden" name="from_modal" value="1" />
                                        <div class="nw-req-field">
                                            <label for="nw-fig-token"><?php esc_html_e('Personal access token', 'nibwp'); ?></label>
                                            <input type="password" id="nw-fig-token" name="figma_token" placeholder="figd_…" autocomplete="off" />
                                        </div>
                                        <div class="nw-fig-actions">
                                            <button type="submit" class="nw-req-btn nw-req-btn-primary"><?php esc_html_e('Save & Connect', 'nibwp'); ?></button>
                                        </div>
                                    </form>
                                    <p class="nw-fig-note"><?php esc_html_e('Read-only: NibWP can read files your Figma account can open (private included) — it can never change your designs. The token is validated with Figma before it is saved.', 'nibwp'); ?></p>
                                </div>
                            </div>

                            <!-- Pane 2 · OAuth app -->
                            <div class="nw-fig-pane" data-fig-pane="oauth">
                                <div class="nw-fig-method">
                                    <div class="nw-fig-method__head">
                                        <strong><?php esc_html_e('OAuth app', 'nibwp'); ?></strong>
                                        <span class="nw-fig-tag"><?php esc_html_e('Teams & agencies', 'nibwp'); ?></span>
                                    </div>
                                    <ol class="nw-fig-steps">
                                        <li><?php printf(
                                            /* translators: %s: link to Figma developer apps */
                                            esc_html__('Create an app at %s (any name/logo).', 'nibwp'),
                                            '<a href="https://www.figma.com/developers/apps" target="_blank" rel="noopener">figma.com/developers/apps</a>'
                                        ); ?></li>
                                        <li><?php esc_html_e('Add this exact callback URL to the app:', 'nibwp'); ?>
                                            <code class="nw-fig-callback"><?php echo esc_html($fig_cb); ?></code>
                                        </li>
                                        <li><?php esc_html_e('Paste the app\'s Client ID and Client Secret below, save, then click "Connect with Figma" and approve access.', 'nibwp'); ?></li>
                                    </ol>
                                    <form method="post" action="<?php echo $fig_post; ?>">
                                        <?php wp_nonce_field('nibwp_figma_save_oauth'); ?>
                                        <input type="hidden" name="action" value="nibwp_figma_save_oauth" />
                                        <input type="hidden" name="from_modal" value="1" />
                                        <div class="nw-req-field">
                                            <label><?php esc_html_e('Client ID', 'nibwp'); ?></label>
                                            <input type="text" name="figma_client_id" value="<?php echo esc_attr($fig_cid); ?>" />
                                        </div>
                                        <div class="nw-req-field">
                                            <label><?php esc_html_e('Client Secret', 'nibwp'); ?></label>
                                            <input type="password" name="figma_client_secret" placeholder="<?php echo $fig_secret_ok ? esc_attr__('•••••• (saved — leave blank to keep)', 'nibwp') : ''; ?>" autocomplete="off" />
                                        </div>
                                        <div class="nw-fig-actions">
                                            <button type="submit" class="nw-req-btn nw-req-btn-secondary"><?php esc_html_e('Save app credentials', 'nibwp'); ?></button>
                                        </div>
                                    </form>
                                    <?php if ($fig_cid !== '' && $fig_secret_ok): ?>
                                        <form method="post" action="<?php echo $fig_post; ?>" class="nw-fig-actions" style="margin-top:8px;">
                                            <?php wp_nonce_field('nibwp_figma_oauth_start'); ?>
                                            <input type="hidden" name="action" value="nibwp_figma_oauth_start" />
                                            <button type="submit" class="nw-req-btn nw-req-btn-primary"><?php esc_html_e('Connect with Figma (OAuth)', 'nibwp'); ?></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Pane 3 · Dev Mode MCP -->
                            <div class="nw-fig-pane" data-fig-pane="mcp">
                                <div class="nw-fig-method">
                                    <div class="nw-fig-method__head">
                                        <strong><?php esc_html_e('Figma Dev Mode MCP', 'nibwp'); ?></strong>
                                        <span class="nw-fig-tag"><?php esc_html_e('AI, inside Figma', 'nibwp'); ?></span>
                                    </div>
                                    <ol class="nw-fig-steps">
                                        <li><?php printf(
                                            /* translators: %s: link to Figma desktop download */
                                            esc_html__('Install the %s (requires a Dev or Full seat).', 'nibwp'),
                                            '<a href="https://www.figma.com/downloads/" target="_blank" rel="noopener">' . esc_html__('Figma desktop app', 'nibwp') . '</a>'
                                        ); ?></li>
                                        <li><?php printf(
                                            /* translators: %s: link to Dev Mode MCP guide */
                                            esc_html__('Enable the Dev Mode MCP server — see %s.', 'nibwp'),
                                            '<a href="https://help.figma.com/hc/en-us/articles/32132100833559" target="_blank" rel="noopener">' . esc_html__('Figma\'s MCP guide', 'nibwp') . '</a>'
                                        ); ?></li>
                                        <li><?php esc_html_e('Your AI agent then reads the frame you have selected in Figma and works with NibWP directly — no token needed for that flow.', 'nibwp'); ?></li>
                                    </ol>
                                    <p class="nw-fig-note"><?php esc_html_e('Best of both: connect an access token (tab 1) so NibWP can pull frames headlessly any time, and use Dev Mode MCP when you are designing live in Figma.', 'nibwp'); ?></p>
                                </div>
                            </div>

                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Integration Cards -->
        <?php
        // Pro-only integration + toolkit keys. The Free wp.org build does not
        // ship the license client (premium code is excluded), so these keys
        // are hidden from the grid entirely — Free shows only the integrations
        // it actually supports. The standalone Pro plugin loads the license
        // client (NIBWP_LICENSE_CLIENT_LOADED), at which point the full list
        // is shown with per-card unlock state.
        $pro_integration_keys = [
            'elementor', 'bricks', 'builderius', 'etchwp', 'automaticcss',
            'acf', 'jetengine', 'metabox', 'pods', 'acpt', 'ase',
            'fluentcrm', 'fluentcart', 'fluentaffiliate', 'edd', 'forms',
            'directorist',
            'learndash', 'lifterlms', 'memberpress', 'tutorlms',
            'buddypress', 'events', 'givewp',
            'redirection', 'tablepress', 'translatepress', 'wpml',
            'seo', 'wp-job-manager',
            'generatepress', 'kadence', 'divi', 'voxel',
            'fluentcommunity', 'fluentsmtp',
            'seopress', 'slimseo', 'surecart',
        ];
        $pro_toolkit_keys = ['security', 'notifications', 'migration', 'content-fetcher', 'content-planner', 'seo-advanced'];
        $pro_keys = array_merge($pro_integration_keys, $pro_toolkit_keys);

        if (!defined('NIBWP_LICENSE_CLIENT_LOADED')) {
            // Free build: hide every premium card.
            $integrations = array_diff_key($integrations, array_flip($pro_keys));
            $pro_keys = [];
        }

        $user_is_pro = function_exists('nibwp_is_pro') && nibwp_is_pro();

        // Default order: ACTIVE integrations first, then DETECTED (plugin
        // installed but not enabled), then everything else. Stable within each
        // group (PHP 8 sort is stable) so the catalog order is preserved. This
        // becomes the JS "Default" sort baseline too (defaultOrder reads the DOM).
        uasort($integrations, static function (array $a, array $b): int {
            $rank = static function (array $i): int {
                $available = !empty($i['plugin_available']);
                if ($available && !empty($i['enabled'])) {
                    return 0; // active
                }
                return $available ? 1 : 2; // detected : not installed
            };
            return $rank($a) <=> $rank($b);
        });
        ?>
        <div class="nibwp-integration-grid" id="nw-int-grid">
            <?php foreach ($integrations as $key => $integration): ?>
                <?php
                $is_available = $integration['plugin_available'];
                $is_enabled = $integration['enabled'];
                $is_active = $is_available && $is_enabled;
                $is_memory = $key === 'memory';
                $is_pro_integration = in_array($key, $pro_keys, strict: true);
                $skill_entitlement = 'integration:' . $key;
                $unlocked_by_skill = function_exists('nibwp_has_entitlement') && nibwp_has_entitlement($skill_entitlement);
                $is_locked = $is_pro_integration && !$user_is_pro && !$unlocked_by_skill;
                ?>
                <?php
                $card_haystack = strtolower(implode(' ', array_filter([
                    (string) ($integration['name'] ?? ''),
                    (string) ($integration['plugin_name'] ?? ''),
                    (string) $key,
                    (string) ($integration['description'] ?? ''),
                    implode(' ', (array) ($integration['abilities'] ?? [])),
                    (string) ($integration['category'] ?? ''),
                ])));
                ?>
                <div class="nibwp-integration-card <?php
                    if ($is_locked) {
                        echo 'is-locked';
                    } else {
                        echo $is_active ? 'is-active' : ($is_available ? 'is-available' : 'is-unavailable');
                    }
                ?>"
                     data-key="<?php echo esc_attr((string) $key); ?>"
                     data-cat="<?php echo esc_attr($integration['category']); ?>"
                     data-tier="<?php echo $is_pro_integration ? 'premium' : 'free'; ?>"
                     data-locked="<?php echo $is_locked ? '1' : '0'; ?>"
                     data-enabled="<?php echo $is_enabled ? '1' : '0'; ?>"
                     data-available="<?php echo $is_available ? '1' : '0'; ?>"
                     data-plugin-name="<?php echo esc_attr((string) ($integration['plugin_name'] ?? '')); ?>"
                     data-search="<?php echo esc_attr($card_haystack); ?>">
                    <div class="nibwp-integration-header">
                        <div class="nibwp-integration-icon <?php echo $is_active ? 'is-active' : ''; ?>">
                            <?php echo $integration['icon']; ?>
                        </div>
                        <?php
                        // Compute the badge state ONCE so the markup below can
                        // be a sequence of independent if blocks. Premium-only
                        // states (the "locked — needs Pro" badge) sit inside
                        // build-strip markers, so the strip removes the block
                        // cleanly without leaving orphan elseifs in a chain.
                        $badge_state = 'inactive';
                        if ($is_locked) {
                            $badge_state = 'locked';
                        } elseif ($is_memory) {
                            $badge_state = 'always';
                        } elseif ($is_active) {
                            $badge_state = 'active';
                        } elseif ($is_available && !$is_enabled) {
                            $badge_state = 'ready';
                        } elseif ($is_enabled && !$is_available) {
                            $badge_state = 'missing';
                        }
                        // Cloud services (e.g. Figma) have no local plugin — the
                        // state is Connected / Not connected, not installed.
                        $is_cloud = !empty($integration['cloud']);
                        if ($is_cloud && !$is_locked) {
                            $badge_state = $is_available ? 'cloud_on' : 'cloud_off';
                        }
                        ?>
                        <div class="nibwp-integration-info">
                            <h3><?php echo esc_html($integration['name']); ?></h3>
                            <?php if ($badge_state === 'always'): ?>
                                <span class="nibwp-integration-badge is-always"><?php esc_html_e('Always on', 'nibwp'); ?></span>
                            <?php endif; ?>
                            <?php if ($badge_state === 'active'): ?>
                                <span class="nibwp-integration-badge is-active"><?php esc_html_e('Active', 'nibwp'); ?></span>
                            <?php endif; ?>
                            <?php if ($badge_state === 'ready'): ?>
                                <span class="nibwp-integration-badge is-ready"><?php esc_html_e('Ready — not activated', 'nibwp'); ?></span>
                            <?php endif; ?>
                            <?php if ($badge_state === 'missing'): ?>
                                <span class="nibwp-integration-badge is-missing"><?php printf(esc_html__('%s not detected', 'nibwp'), esc_html($integration['plugin_name'])); ?></span>
                            <?php endif; ?>
                            <?php if ($badge_state === 'inactive'): ?>
                                <span class="nibwp-integration-badge is-inactive"><?php printf(esc_html__('%s not installed', 'nibwp'), esc_html($integration['plugin_name'])); ?></span>
                            <?php endif; ?>
                            <?php if ($badge_state === 'cloud_on'): ?>
                                <span class="nibwp-integration-badge is-active"><?php esc_html_e('Connected', 'nibwp'); ?></span>
                                <a href="#" class="nw-fig-connect-open" style="margin-left:8px;font-size:12px;font-weight:600;text-decoration:none;"><?php esc_html_e('Manage', 'nibwp'); ?></a>
                            <?php endif; ?>
                            <?php if ($badge_state === 'cloud_off'): ?>
                                <span class="nibwp-integration-badge is-inactive"><?php esc_html_e('Not connected', 'nibwp'); ?></span>
                                <a href="#" class="nw-fig-connect-open" style="margin-left:8px;font-size:12px;font-weight:600;text-decoration:none;"><?php esc_html_e('Connect →', 'nibwp'); ?></a>
                            <?php endif; ?>
                        </div>
                        <?php if (!$is_locked && !$is_memory): ?>
                            <div class="nibwp-integration-toggle">
                                <form method="post" style="margin:0;">
                                    <?php wp_nonce_field('nibwp_integration_toggle'); ?>
                                    <input type="hidden" name="nibwp_integration_key" value="<?php echo esc_attr($key); ?>" />
                                    <input type="hidden" name="nibwp_integration_state" value="<?php echo $is_enabled ? '0' : '1'; ?>" />
                                    <button type="submit" name="nibwp_integration_toggle"
                                            class="nibwp-toggle-btn <?php echo $is_enabled ? 'is-on' : 'is-off'; ?>"
                                            title="<?php echo $is_enabled
                                                ? esc_attr__('Deactivate', domain: 'nibwp')
                                                : esc_attr__('Activate', domain: 'nibwp'); ?>"
                                    >
                                        <span class="nibwp-toggle-track">
                                            <span class="nibwp-toggle-thumb"></span>
                                        </span>
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                    <p class="nibwp-integration-desc"><?php echo esc_html($integration['description']); ?></p>
                    <div class="nibwp-integration-footer">
                        <span class="nibwp-ability-count">
                            <?php printf(
                                esc_html(_n('%d AI ability', '%d AI abilities', count($integration['abilities']), domain: 'nibwp')),
                                count($integration['abilities']),
                            ); ?>
                        </span>
                        <?php if (!$is_memory): ?>
                            <span class="nibwp-ability-list"><?php echo esc_html(implode(', ', $integration['abilities'])); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="nw-bulk-overlay" id="nw-bulk-overlay" hidden>
            <div class="nw-bulk-overlay__box">
                <span class="nw-bulk-spinner" aria-hidden="true"></span>
                <span class="nw-bulk-overlay__text" id="nw-bulk-overlay-text" role="status" aria-live="polite"></span>
            </div>
        </div>
    </div>

    <script>
    window.nibwpInt = {
        restRoot: '<?php echo esc_js(rest_url('nibwp/v1/')); ?>',
        nonce: '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>',
        labels: {
            activate:   '<?php echo esc_js(__('Activate', 'nibwp')); ?>',
            deactivate: '<?php echo esc_js(__('Deactivate', 'nibwp')); ?>',
            bulkAll:      '<?php echo esc_js(__('Activating all integrations…', 'nibwp')); ?>',
            bulkDetected: '<?php echo esc_js(__('Activating detected integrations…', 'nibwp')); ?>',
            bulkNone:     '<?php echo esc_js(__('Deactivating all integrations…', 'nibwp')); ?>'
        },
        badgeLabels: {
            always: '<?php echo esc_js(__('Always on', 'nibwp')); ?>',
            active: '<?php echo esc_js(__('Active', 'nibwp')); ?>',
            ready:  '<?php echo esc_js(__('Ready — not activated', 'nibwp')); ?>'
        }
    };
    </script>
    <script>
    (function(){
        var tabs   = document.querySelectorAll('#nw-int-tabs .nw-int-tab');
        var cards  = document.querySelectorAll('#nw-int-grid .nibwp-integration-card');
        var wrap   = document.getElementById('nw-int-tabs-wrap');
        var tabsContainer = document.getElementById('nw-int-tabs');
        var search = document.getElementById('nw-int-search');
        var searchClear = document.getElementById('nw-int-search-clear');
        var searchCount = document.getElementById('nw-int-search-count');
        var stateActive = document.getElementById('nw-int-stat-active');

        var currentTab = 'all';
        var currentQuery = '';
        var currentTier = 'all';

        function applyFilters(){
            var visible = 0, total = 0;
            cards.forEach(function(card){
                total++;
                var catOk = (currentTab === 'all' || card.getAttribute('data-cat') === currentTab);
                var tierOk = (currentTier === 'all' || card.getAttribute('data-tier') === currentTier);
                var hay = (card.getAttribute('data-search') || '').toLowerCase();
                var queryOk = currentQuery === '' || hay.indexOf(currentQuery) !== -1;
                var show = catOk && tierOk && queryOk;
                card.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            if (searchCount) {
                searchCount.hidden = false;
                searchCount.textContent = visible + ' / ' + total;
            }
        }

        /* Tier filter pills (Free / Premium / All) */
        document.querySelectorAll('.nw-tier-filter .nw-tier-pill').forEach(function(pill){
            pill.addEventListener('click', function(){
                document.querySelectorAll('.nw-tier-filter .nw-tier-pill').forEach(function(p){ p.classList.remove('is-active'); });
                pill.classList.add('is-active');
                currentTier = pill.getAttribute('data-tier') || 'all';
                applyFilters();
            });
        });

        /* Sort dropdown — reorders the grid in place, keeping filters/search intact.
           default  = the catalog order the page rendered in.
           detected = integrations whose plugin is installed/available float to the top
                      (stable, so order within each group is preserved).
           az       = smart, case-insensitive, numeric-aware sort of the card titles. */
        var grid = document.getElementById('nw-int-grid');
        var defaultOrder = Array.prototype.slice.call(cards);
        function cardName(c){ var h = c.querySelector('.nibwp-integration-header h3'); return h ? h.textContent.trim() : ''; }
        function isDetected(c){ return c.getAttribute('data-available') === '1' ? 0 : 1; }
        var sortSelect = document.getElementById('nw-int-sort');
        if (sortSelect && grid) {
            sortSelect.addEventListener('change', function(){
                var mode = sortSelect.value, order;
                if (mode === 'az') {
                    order = defaultOrder.slice().sort(function(a, b){
                        return cardName(a).localeCompare(cardName(b), undefined, { sensitivity: 'base', numeric: true });
                    });
                } else if (mode === 'detected') {
                    order = defaultOrder.slice().sort(function(a, b){ return isDetected(a) - isDetected(b); });
                } else {
                    order = defaultOrder;
                }
                order.forEach(function(c){ grid.appendChild(c); });
            });
        }

        /* Tab click — filter cards via composed filter */
        tabs.forEach(function(tab){
            tab.addEventListener('click', function(){
                tabs.forEach(function(t){ t.classList.remove('is-active'); });
                tab.classList.add('is-active');
                currentTab = tab.getAttribute('data-tab') || 'all';
                applyFilters();
            });
        });

        /* Search — instant filter across name, slug, plugin, description, abilities */
        if (search) {
            var debounceTimer = null;
            search.addEventListener('input', function(){
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(function(){
                    currentQuery = (search.value || '').trim().toLowerCase();
                    if (searchClear) searchClear.hidden = currentQuery === '';
                    applyFilters();
                }, 60);
            });
            search.addEventListener('keydown', function(e){
                if (e.key === 'Escape') {
                    search.value = '';
                    currentQuery = '';
                    if (searchClear) searchClear.hidden = true;
                    applyFilters();
                    search.blur();
                }
            });
        }
        if (searchClear) {
            searchClear.addEventListener('click', function(){
                search.value = '';
                currentQuery = '';
                searchClear.hidden = true;
                applyFilters();
                search.focus();
            });
        }

        /* ── AJAX toggle — intercept form submit, swap state without reload ── */
        function badgeHtmlFor(card){
            var available = card.getAttribute('data-available') === '1';
            var enabled   = card.getAttribute('data-enabled')   === '1';
            var pluginName = card.getAttribute('data-plugin-name') || '';
            var key = card.getAttribute('data-key');
            var labels = window.nibwpInt.badgeLabels || {};
            if (key === 'memory') {
                return '<span class="nibwp-integration-badge is-always">' + (labels.always || 'Always on') + '</span>';
            }
            if (available && enabled) {
                return '<span class="nibwp-integration-badge is-active">' + (labels.active || 'Active') + '</span>';
            }
            if (available && !enabled) {
                return '<span class="nibwp-integration-badge is-ready">' + (labels.ready || 'Ready — not activated') + '</span>';
            }
            if (!available && enabled) {
                return '<span class="nibwp-integration-badge is-missing">' + (pluginName + ' not detected') + '</span>';
            }
            return '<span class="nibwp-integration-badge is-inactive">' + (pluginName + ' not installed') + '</span>';
        }
        function applyCardState(card, data){
            card.setAttribute('data-enabled', data.enabled ? '1' : '0');
            card.setAttribute('data-available', data.available ? '1' : '0');
            card.classList.remove('is-active', 'is-available', 'is-unavailable');
            if (data.active) {
                card.classList.add('is-active');
            } else if (data.available) {
                card.classList.add('is-available');
            } else {
                card.classList.add('is-unavailable');
            }
            var icon = card.querySelector('.nibwp-integration-icon');
            if (icon) icon.classList.toggle('is-active', !!data.active);
            var btn = card.querySelector('.nibwp-toggle-btn');
            if (btn) {
                btn.classList.toggle('is-on', !!data.enabled);
                btn.classList.toggle('is-off', !data.enabled);
                btn.disabled = false;
                btn.title = data.enabled ? (window.nibwpInt.labels.deactivate || 'Deactivate') : (window.nibwpInt.labels.activate || 'Activate');
                /* Flip the hidden state field so a fallback form submit (no-JS) toggles correctly. */
                var stateField = btn.closest('form') && btn.closest('form').querySelector('input[name="nibwp_integration_state"]');
                if (stateField) stateField.value = data.enabled ? '0' : '1';
            }
            var badgeWrap = card.querySelector('.nibwp-integration-info');
            if (badgeWrap) {
                var oldBadge = badgeWrap.querySelector('.nibwp-integration-badge');
                if (oldBadge) {
                    oldBadge.outerHTML = badgeHtmlFor(card);
                }
            }
        }

        var grid = document.getElementById('nw-int-grid');
        if (grid) {
            grid.addEventListener('submit', function(e){
                var form = e.target;
                if (!form.matches('.nibwp-integration-toggle form')) return;
                e.preventDefault();
                var card = form.closest('.nibwp-integration-card');
                if (!card) return;
                if (card.getAttribute('data-locked') === '1') return;
                var btn = form.querySelector('.nibwp-toggle-btn');
                var key = card.getAttribute('data-key');
                var enabled = !(card.getAttribute('data-enabled') === '1');
                if (btn) btn.disabled = true;
                fetch(window.nibwpInt.restRoot + 'integrations/toggle', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': window.nibwpInt.nonce
                    },
                    body: JSON.stringify({ key: key, enabled: enabled })
                })
                .then(function(r){ return r.json().catch(function(){ return { ok: false }; }); })
                .then(function(data){
                    if (!data || !data.ok) {
                        if (btn) btn.disabled = false;
                        if (data && data.message) {
                            console.warn('NIBWP toggle:', data.message);
                        }
                        return;
                    }
                    applyCardState(card, data);
                    if (stateActive && typeof data.active_count === 'number') {
                        stateActive.textContent = data.active_count;
                    }
                })
                .catch(function(err){
                    if (btn) btn.disabled = false;
                    console.warn('NIBWP toggle failed:', err);
                });
            });
        }

        /* Bulk activate (detected / all) — AJAX, applies the response to every card. */
        var bulkForm = document.querySelector('.nw-bulk-acts');
        if (bulkForm) {
            var overlay = document.getElementById('nw-bulk-overlay');
            var overlayText = document.getElementById('nw-bulk-overlay-text');
            var L = window.nibwpInt.labels || {};
            bulkForm.addEventListener('submit', function(e){
                e.preventDefault();
                var mode = (e.submitter && e.submitter.value) || 'detected';
                var bbtns = bulkForm.querySelectorAll('button');
                bbtns.forEach(function(b){ b.disabled = true; });
                if (overlay) {
                    if (overlayText) overlayText.textContent = mode === 'none' ? (L.bulkNone || 'Deactivating…') : (mode === 'detected' ? (L.bulkDetected || 'Activating…') : (L.bulkAll || 'Activating…'));
                    overlay.hidden = false;
                }
                fetch(window.nibwpInt.restRoot + 'integrations/bulk', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.nibwpInt.nonce },
                    body: JSON.stringify({ mode: mode })
                })
                .then(function(r){ return r.json().catch(function(){ return { ok: false }; }); })
                .then(function(data){
                    bbtns.forEach(function(b){ b.disabled = false; });
                    if (overlay) overlay.hidden = true;
                    if (!data || !data.ok || !data.items) return;
                    document.querySelectorAll('#nw-int-grid .nibwp-integration-card').forEach(function(card){
                        var st = data.items[card.getAttribute('data-key')];
                        if (st) applyCardState(card, st);
                    });
                    if (stateActive && typeof data.active_count === 'number') {
                        stateActive.textContent = data.active_count;
                    }
                    applyFilters();
                })
                .catch(function(){ bbtns.forEach(function(b){ b.disabled = false; }); if (overlay) overlay.hidden = true; });
            });
        }

        /* Hover-to-scroll — mouse position determines scroll direction & speed */
        if (!wrap || !tabsContainer) return;

        var rafId = null;
        var scrollVelocity = 0;
        var DEAD_ZONE = 0.05;  /* center 10% = no scroll */
        var MAX_SPEED = 38;    /* max pixels per frame — fast! */

        function updateShadows(){
            var max = tabsContainer.scrollWidth - tabsContainer.clientWidth;
            wrap.classList.toggle('has-scroll-left', tabsContainer.scrollLeft > 2);
            wrap.classList.toggle('has-scroll-right', tabsContainer.scrollLeft < max - 2);
        }

        function animateScroll(){
            if (Math.abs(scrollVelocity) < 0.1) {
                rafId = null;
                return;
            }
            tabsContainer.scrollLeft += scrollVelocity;
            updateShadows();
            rafId = requestAnimationFrame(animateScroll);
        }

        function onMouseMove(e){
            var rect = wrap.getBoundingClientRect();
            var x = e.clientX - rect.left;
            var width = rect.width;
            /* Normalize position: -1 (far left) to +1 (far right), 0 = center */
            var offset = (x / width) * 2 - 1;

            if (Math.abs(offset) < DEAD_ZONE) {
                scrollVelocity = 0;
            } else {
                /* Easing curve: slow near center, accelerates toward edges */
                var sign = offset < 0 ? -1 : 1;
                var magnitude = (Math.abs(offset) - DEAD_ZONE) / (1 - DEAD_ZONE);
                /* Linear easing for responsive feel — closer to edges = much faster */
                scrollVelocity = sign * MAX_SPEED * magnitude;
            }

            if (scrollVelocity !== 0 && rafId === null) {
                rafId = requestAnimationFrame(animateScroll);
            }
        }

        function onMouseLeave(){
            scrollVelocity = 0;
        }

        wrap.addEventListener('mousemove', onMouseMove);
        wrap.addEventListener('mouseleave', onMouseLeave);

        /* Initial shadow state */
        updateShadows();
        window.addEventListener('resize', updateShadows);

        /* ── Figma Connect Modal (reuses the request-modal styling) ── */
        (function(){
            var fig = document.getElementById('nw-fig-modal');
            if (!fig) return;
            function figOpen(e){ if(e){e.preventDefault();} fig.setAttribute('aria-hidden','false'); fig.classList.add('is-open'); document.body.style.overflow='hidden'; }
            function figClose(){ fig.setAttribute('aria-hidden','true'); fig.classList.remove('is-open'); document.body.style.overflow=''; }
            document.querySelectorAll('.nw-fig-connect-open').forEach(function(a){ a.addEventListener('click', figOpen); });
            var c = document.getElementById('nw-fig-modal-close');
            var b = document.getElementById('nw-fig-modal-backdrop');
            if (c) c.addEventListener('click', figClose);
            if (b) b.addEventListener('click', figClose);
            document.addEventListener('keydown', function(e){ if(e.key==='Escape' && fig.classList.contains('is-open')) figClose(); });
            /* Method tabs */
            fig.querySelectorAll('.nw-fig-tab').forEach(function(tab){
                tab.addEventListener('click', function(){
                    var key = tab.getAttribute('data-fig-tab');
                    fig.querySelectorAll('.nw-fig-tab').forEach(function(t){ t.classList.toggle('is-active', t === tab); });
                    fig.querySelectorAll('.nw-fig-pane').forEach(function(p){ p.classList.toggle('is-active', p.getAttribute('data-fig-pane') === key); });
                });
            });
            /* Re-open after a connect/disconnect round-trip so the result shows in place */
            if (new URLSearchParams(location.search).has('figma_modal')) figOpen();
        })();

        /* ── Custom Integration Request Modal ── */
        var modal = document.getElementById('nw-req-modal');
        var openBtn = document.getElementById('nw-request-integration-btn');
        var closeBtn = document.getElementById('nw-req-modal-close');
        var backdrop = document.getElementById('nw-req-modal-backdrop');
        var nextBtn = document.getElementById('nw-req-next');
        var backBtn = document.getElementById('nw-req-back');
        var submitBtn = document.getElementById('nw-req-submit');
        var form = document.getElementById('nw-req-form');
        var steps = modal ? modal.querySelectorAll('.nw-req-step') : [];
        var panes = modal ? modal.querySelectorAll('.nw-req-pane') : [];
        var currentStep = 1;
        var maxStep = 3;

        function openModal(){ modal.setAttribute('aria-hidden','false'); modal.classList.add('is-open'); document.body.style.overflow = 'hidden'; }
        function closeModal(){ modal.setAttribute('aria-hidden','true'); modal.classList.remove('is-open'); document.body.style.overflow = ''; }
        function showStep(n){
            currentStep = n;
            steps.forEach(function(s){
                var stepNum = parseInt(s.getAttribute('data-step'), 10);
                s.classList.toggle('is-active', stepNum === n);
                s.classList.toggle('is-complete', stepNum < n);
            });
            panes.forEach(function(p){
                p.classList.toggle('is-active', p.getAttribute('data-pane') === String(n));
            });
            backBtn.disabled = (n <= 1);
            if(n >= maxStep){
                nextBtn.style.display = 'none';
                submitBtn.style.display = 'inline-flex';
            } else {
                nextBtn.style.display = 'inline-flex';
                submitBtn.style.display = 'none';
            }
        }
        function showSuccess(){
            steps.forEach(function(s){ s.classList.add('is-complete'); s.classList.remove('is-active'); });
            panes.forEach(function(p){ p.classList.toggle('is-active', p.getAttribute('data-pane') === 'success'); });
            nextBtn.style.display = 'none';
            submitBtn.style.display = 'none';
            backBtn.style.display = 'none';
            /* Add a close button replacement */
            var doneBtn = document.getElementById('nw-req-done');
            if(!doneBtn){
                doneBtn = document.createElement('button');
                doneBtn.type = 'button';
                doneBtn.id = 'nw-req-done';
                doneBtn.className = 'nw-req-btn nw-req-btn-primary';
                doneBtn.textContent = 'Done';
                doneBtn.addEventListener('click', closeModal);
                submitBtn.parentNode.appendChild(doneBtn);
            }
        }
        function validateStep(n){
            var pane = modal.querySelector('.nw-req-pane[data-pane="' + n + '"]');
            if(!pane) return true;
            var required = pane.querySelectorAll('[required]');
            for(var i = 0; i < required.length; i++){
                if(!required[i].checkValidity()){ required[i].reportValidity(); return false; }
            }
            if(n === 1){
                var picked = pane.querySelector('input[name="request_type"]:checked');
                if(!picked){ alert('Please choose what you need.'); return false; }
            }
            return true;
        }
        if(openBtn) openBtn.addEventListener('click', function(){ showStep(1); openModal(); });
        if(closeBtn) closeBtn.addEventListener('click', closeModal);
        if(backdrop) backdrop.addEventListener('click', closeModal);
        document.addEventListener('keydown', function(e){ if(e.key === 'Escape' && modal && modal.classList.contains('is-open')) closeModal(); });
        if(nextBtn) nextBtn.addEventListener('click', function(){ if(validateStep(currentStep)) showStep(currentStep + 1); });
        if(backBtn) backBtn.addEventListener('click', function(){ if(currentStep > 1) showStep(currentStep - 1); });
        if(form) form.addEventListener('submit', function(e){
            e.preventDefault();
            if(!validateStep(currentStep)) return;
            /* In a real implementation, send to admin-ajax.php — for now just show success */
            showSuccess();
        });
    })();
    </script>

    <?php
    nibwp_render_admin_footer();
}
