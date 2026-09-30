<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Skill Manifest — Voxel Pro.
 *
 * The Voxel integration gave agents the data layer: listings, fields, schema,
 * orders, community. This skill is the frontend layer — the templates that
 * decide what a Voxel site LOOKS like: preview cards, single/archive layouts,
 * header/footer, search pages, style kits.
 *
 * A Voxel template is an Elementor document whose power comes from three
 * things ordinary Elementor knows nothing about: Voxel's own `ts-*` widgets,
 * `@tags()` dynamic tags, and — the part that catches everyone — the
 * search↔feed↔map wiring, which lives in post meta `_voxel_page_settings` and
 * not in the element tree at all.
 *
 * Synthesis happens agent-side against SKILL.md + references. These abilities
 * are the vocabulary, the validator and the writer.
 */

return [
    'id'             => 'voxel-pro',
    'name'           => 'Voxel Pro',
    'tagline'        => 'Design and build Voxel templates — preview cards, single posts, archives, search pages, headers and style kits',
    'description'    => 'Build the templates a Voxel site is made of. The agent composes an Elementor document using Voxel\'s own widgets — search form, post feed, map, gallery, work hours, review stats, actions — binds it to real listing data with @tags() dynamic tags, and this skill validates every piece before it is written: widget names against the live registry, filter and field keys against the site\'s own configuration, and every dynamic tag rendered against a real post so a typo can never ship as literal text on the page. Search forms, feeds and maps are wired together automatically, because that wiring lives in post meta and is positional — hand-written paths break the moment anything moves. Assign the result as a post type\'s main card, a named alternate, a single or archive layout, a header, or a site-wide style kit.',
    'vendor'         => 'NIBWP',
    'version'        => '1.0.1',
    'category'       => 'page-builders',
    'premium'        => true,
    'price'          => 29,
    'required_plans' => ['pro', 'agency', 'lifetime'],
    'requires'       => ['voxel'],
    'entitlements'   => ['skill:voxel', 'skill:voxel-pro', 'integration:voxel'],
    'integration_files' => ['voxel'],
    'features'       => [
        'Build every Voxel template kind — preview card, single post, archive, term, header, footer, search page, style kit',
        'Voxel\'s own widgets, used properly — search form, post feed, map, create post, gallery, work hours, review stats, actions, navbar, user bar',
        'Search form, feed and map wired together for you (the wiring lives in post meta, and its paths are positional)',
        'Every @tags() dynamic tag rendered against a real listing before the template is saved — no tag ships as literal text',
        'Filter, field and post type keys checked against the site\'s own Voxel configuration, not guessed',
        'Assign as a post type\'s main card, a named alternate card, single, archive, header, footer, or a site-wide style kit',
        'Style kits (popup and timeline) require an explicit confirmation — they restyle the whole site',
        'Refine an existing template surgically: change one setting, add a visibility rule, insert or move an element',
        'Every overwrite keeps a restorable backup of the previous document',
        'Validate and score before you commit (dry-run)',
    ],
    'ability_files' => [
        'abilities/catalog.php',
        'abilities/build.php',
        'abilities/refine.php',
        'abilities/feedback.php',
    ],
    'instructions_file' => 'SKILL.md',
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>',

    // ─── v2 routing contract ──────────────────────────────────────────────
    'triggers' => [
        '/(?i)\bvoxel\b[^.\n]{0,60}\b(?:card|template|archive|single|search page|header|footer|style kit|popup|timeline|layout|design|page)\b/',
        '/(?i)\b(?:design|build|create|customize|customize|style|restyle|lay ?out)\b[^.\n]{0,50}\bvoxel\b/',
        '/(?i)\bvoxel (?:pro|frontend|front-end|builder|templates?)\b/',
    ],
    'commands' => [
        '/voxel-build'     => ['description' => 'Build a Voxel template — card, single, archive, search page, header, footer, or style kit.'],
        '/voxel-card'      => ['description' => 'Design a preview card for a Voxel post type and assign it.'],
        '/voxel-refine'    => ['description' => 'Change an existing Voxel template by template_id — settings, elements, visibility, dynamic tags.'],
        '/voxel-preflight' => ['description' => 'Re-run the per-session Voxel preflight (brand, what to build, which post type, title).'],
    ],
    'mandatory_routing' => [
        'preflight_required' => true,
        'preflight_ability'  => 'nibwp/skill-preflight',
        'pipeline' => [
            [
                'ability'       => 'nibwp/voxel-info',
                'args_template' => [],
                'why'           => 'Read this site\'s Voxel configuration first: post type keys, which modules are on, index health. Every template you build names keys that must exist here.',
            ],
            [
                'ability'       => 'nibwp/design-direction',
                'args_template' => ['purpose' => '{what the user asked for, in their words}'],
                'why'           => 'Decide how this site should look before building: color roles with contrast checked, type, spacing rhythm, layout sequence, and the generic defaults to refuse. Skip only if NibWP Design is switched off.',
            ],
            [
                'ability'       => 'nibwp/skill-preflight',
                'args_template' => ['skill_id' => 'voxel-pro'],
                'why'           => 'Resolve brand, what kind of template to build, which post type it belongs to, and its title. Mints the _preflight_token the build ability requires.',
            ],
            [
                'ability'       => 'nibwp/load-skill-playbook',
                'args_template' => ['skill_id' => 'voxel-pro', 'element_type' => '{kind}'],
                'why'           => 'Read SKILL.md plus the checklist for this template kind — element shapes, dynamic tag syntax, and the wiring rule.',
            ],
            [
                'ability'       => 'nibwp/voxel-pro-catalog',
                'args_template' => ['topic' => 'widgets'],
                'why'           => 'Confirm the exact widget names and their required settings before composing. Pass topic:"widget" for one widget\'s full control list, read live from this site.',
            ],
            [
                'ability'       => 'nibwp/voxel-pro-build',
                'args_template' => ['dry_run' => true, '_preflight_token' => '{from_preflight.token}'],
                'why'           => 'Validate the composed document. Fix every failed[] item; read the warnings too.',
            ],
            [
                'ability'       => 'nibwp/voxel-pro-build',
                'args_template' => ['dry_run' => false, '_preflight_token' => '{from_preflight.token}'],
                'why'           => 'Write it after a clean validation pass, and assign it if the user asked for that.',
            ],
            [
                'ability'       => 'nibwp/voxel-pro-feedback',
                'args_template' => ['rating' => '{up|down}'],
                'why'           => 'Record thumb-up/down so future runs on this site improve.',
            ],
        ],
        'forbidden_actions' => [
            'Writing _elementor_data or _voxel_page_settings through a generic post-meta ability',
            'Widget types that are not in nibwp/voxel-pro-catalog',
            'Hand-written leftPath / rightPath values in relations — they are positional and are always recomputed',
            'A search page whose feed or map is not wired to its search form',
            'Assigning templates.kit_popups or templates.kit_timeline without confirm_kit:true — it restyles the whole site',
            'Writing voxel: options directly to assign a template — use the assign block, or nibwp/voxel-templates',
            'Field, filter, taxonomy or post type keys that were not read from this site',
        ],
        'before_answering' => 'A Voxel template is not ordinary Elementor. It uses Voxel\'s own ts-* widgets, binds data with @tags() dynamic tags, and — for search pages — carries its search-to-feed wiring in post meta rather than in the element tree. Compose the document agent-side from the playbook and catalog, then route it through nibwp/voxel-pro-build. Calling that without a valid _preflight_token returns requires_user_input:true.',
    ],
    'preflight_questions' => [
        [
            'key'       => 'brand_prefix',
            'prompt'    => 'Brand slug (used for feedback grouping).',
            'detect'    => 'shared(brand_prefix)|get_option(nibwp_etchwp_brand)|scan(etch_styles)',
            'type'      => 'string',
            'required'  => false,
            'cache_key' => 'brand_prefix',
        ],
        [
            'key'       => 'voxel_build_kind',
            'prompt'    => 'What are we building?',
            'choices'   => ['card', 'single-post', 'archive', 'search-page', 'header', 'footer', 'single-term', 'style-kit-popup', 'style-kit-timeline', 'page'],
            'type'      => 'enum',
            'required'  => true,
            'cache_key' => 'voxel_build_kind',
        ],
        // Everything below waits until we know what is being built. Asked on a
        // cold start they are noise — a footer has no post type, and a rebuild
        // target is meaningless before anyone has said what to rebuild.
        [
            'key'            => 'voxel_post_type',
            'prompt'         => 'Which Voxel post type is it for? (cards, single posts, archives and search pages belong to one.)',
            'detect'         => 'list(voxel_post_types)',
            'type'           => 'string',
            'required'       => false,
            'cache_key'      => 'voxel_post_type',
            'conditional_on' => ['voxel_build_kind' => ['card', 'single-post', 'archive', 'search-page']],
        ],
        [
            'key'            => 'voxel_new_title',
            'prompt'         => 'Title for the new template?',
            'type'           => 'string',
            'required'       => false,
            'cache_key'      => 'voxel_new_title',
            'cache'          => false,
            'conditional_on' => ['voxel_build_kind' => true],
        ],
        [
            'key'            => 'voxel_target_template_id',
            'prompt'         => 'Existing template ID to rebuild (leave empty to create a new one).',
            'type'           => 'integer',
            'required'       => false,
            'cache_key'      => 'voxel_target_template_id',
            'cache'          => false,
            'conditional_on' => ['voxel_build_kind' => true],
        ],
    ],
];
