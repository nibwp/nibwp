<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Skill Manifest — SEO Pro.
 *
 * Engine-agnostic AI SEO pipeline: audit → validated fix / brand-voice meta /
 * structured data → migrate, on top of the existing SEO normalizer + the
 * SEOPress / Slim SEO integrations. Destructive abilities are dry-run + preflight
 * gated and re-validate at the persist boundary.
 */

return [
    'id'             => 'seo-pro',
    'name'           => 'SEO Pro',
    'tagline'        => 'Audit, optimize and validate SEO across any engine — Yoast, Rank Math, AIOSEO, SEOPress or Slim SEO — with an AI co-pilot.',
    'description'    => 'A guided SEO workflow, not just field access. Scan the whole site for a scored report card, generate brand-voice titles + meta descriptions that pass length + uniqueness validation, build and validate structured data, fix canonicals/robots/alt text in bulk, migrate meta between SEO plugins, and gate drafts before publish. Every write runs through a dry-run → validate → commit pipeline so nothing bad lands.',
    'vendor'         => 'NIBWP',
    'version'        => '1.0.1',
    'category'       => 'seo',
    'premium'        => true,
    'price'          => 59,
    'required_plans' => ['pro', 'agency', 'lifetime'],
    // Engine-agnostic: works with whatever SEO plugin is installed (or none for
    // the audit). No hard dependency.
    'requires'       => [],
    'entitlements'   => ['skill:seo', 'skill:seo-pro'],
    // Premium integration ability files this skill unlocks when a standalone
    // SEO Pro skill license is the only thing the user owns.
    'integration_files' => ['seo', 'seopress', 'slimseo'],
    'features'       => [
        'Site-wide SEO audit with a 0-100 score + prioritized fix queue',
        'Brand-voice SEO titles + meta descriptions (length + uniqueness validated)',
        'On-page optimization for a target keyword with before/after scoring',
        'Structured-data generation + schema.org validation, rendered on any engine',
        'Bulk fixes: canonicals, robots, missing meta, image alt text',
        'Internal-link suggestions + insertion, broken-link detection',
        '404 → 301 redirect suggestions',
        'Migrate SEO meta between Yoast / Rank Math / AIOSEO / SEOPress / Slim SEO',
        'Pre-publish SEO gate (pass/fail) before content goes live',
        'External (optional): Google Search Console, SERP intent, IndexNow',
    ],
    'ability_files' => [
        'abilities/_shared.php',
        'abilities/audit.php',
        'abilities/gate.php',
        'abilities/meta.php',
        'abilities/fix.php',
        'abilities/schema.php',
        'abilities/optimize.php',
        'abilities/alttext.php',
        'abilities/links.php',
        'abilities/redirects.php',
        'abilities/migrate.php',
        'abilities/feedback.php',
        // External (gated on optional credentials).
        'abilities/indexing.php',
        'abilities/gsc.php',
        'abilities/serp.php',
    ],
    'instructions_file' => 'SKILL.md',
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/><path d="M8 11h6M11 8v6"/></svg>',

    // ─── v2 routing contract ──────────────────────────────────────────────
    'triggers' => [
        '/(?i)\b(?:audit|improve|optimi[sz]e|fix|check)\b[^.\n]{0,30}\bseo\b/',
        '/(?i)\bseo\b[^.\n]{0,30}\b(?:audit|score|report|issues|titles?|meta descriptions?)\b/',
        '/(?i)\b(?:generate|write|rewrite)\b[^.\n]{0,30}\b(?:meta descriptions?|seo titles?|title tags?)\b/',
        '/(?i)\b(?:add|generate|build|validate)\b[^.\n]{0,30}\b(?:schema|structured data|json-?ld|rich snippets?)\b/',
        '/(?i)\b(?:migrate|move|port|switch)\b[^.\n]{0,40}\b(?:yoast|rank ?math|aioseo|seopress|slim ?seo)\b/',
        '/(?i)\b(?:404|redirects?)\b[^.\n]{0,20}\b(?:fix|301|cleanup|manage)\b/',
    ],
    'commands' => [
        '/seo-audit'    => ['description' => 'Score the site and return a prioritized SEO fix queue.'],
        '/seo-meta'     => ['description' => 'Generate brand-voice SEO titles + meta descriptions (validated).'],
        '/seo-optimize' => ['description' => 'Optimize a post for a target keyword (before/after score).'],
        '/seo-schema'   => ['description' => 'Recommend, validate and add structured data to a post.'],
        '/seo-fix'      => ['description' => 'Apply audit fixes — canonicals, robots, missing meta, alt text.'],
        '/seo-links'    => ['description' => 'Suggest + insert internal links; find broken links.'],
        '/seo-redirects'=> ['description' => 'Turn 404s into 301 redirects.'],
        '/seo-migrate'  => ['description' => 'Migrate SEO meta between SEO plugins.'],
        '/seo-gate'     => ['description' => 'Validate a draft against the SEO checklist before publish.'],
    ],
    'mandatory_routing' => [
        'preflight_required' => true,
        'preflight_ability'  => 'nibwp/skill-preflight',
        'pipeline' => [
            [
                'ability'       => 'nibwp/skill-preflight',
                'args_template' => ['skill_id' => 'seo-pro'],
                'why'           => 'Resolve the active SEO engine, brand voice, target post types, length limits and which external API keys exist. Mints the _preflight_token the write abilities require.',
            ],
            [
                'ability'       => 'nibwp/load-skill-playbook',
                'args_template' => ['skill_id' => 'seo-pro'],
                'why'           => 'Read SKILL.md: SEO rules, length limits, per-type schema requirements, brand-voice synthesis guidance.',
            ],
            [
                'ability'       => 'nibwp/seo-pro-audit',
                'args_template' => [],
                'why'           => 'Score the target scope and return the prioritized fix_queue that drives the rest of the run.',
            ],
            [
                'ability'       => 'nibwp/seo-pro-meta',
                'args_template' => ['dry_run' => true, '_preflight_token' => '{from_preflight.token}'],
                'why'           => 'Validate agent-generated titles/descriptions (length + uniqueness). Also applies to seo-pro-fix / seo-pro-schema / seo-pro-optimize.',
            ],
            [
                'ability'       => 'nibwp/seo-pro-meta',
                'args_template' => ['dry_run' => false, '_preflight_token' => '{from_preflight.token}'],
                'why'           => 'Persist after a clean dry-run + user confirmation. The persister re-validates before writing.',
            ],
            [
                'ability'       => 'nibwp/seo-pro-feedback',
                'args_template' => ['rating' => 'up'],
                'why'           => 'Record outcome so future runs improve.',
            ],
        ],
        'forbidden_actions' => [
            'Writing SEO title/description/robots via nibwp/wp-update-post or raw post meta instead of seo-pro-meta / seo-pro-fix',
            'Calling a write ability with dry_run:false before a dry_run:true pass returned all_ok:true',
            'Producing SEO titles/descriptions that exceed the configured length limits',
            'Inventing schema.org properties, or shipping a schema type missing its required fields',
            'Setting noindex on the front page, or bulk-applying noindex without explicit user confirmation',
            'Duplicating an existing post’s SEO title (keyword cannibalization)',
        ],
        'before_answering' => 'Do NOT improvise SEO edits or write meta directly. The user paid for this skill: run seo-pro-audit first, synthesize copy that respects the playbook length limits + brand voice, validate with dry_run:true, and only persist with dry_run:false after the dry-run passes. Calling a write ability without a valid _preflight_token returns an error until you call skill-preflight.',
    ],
    'preflight_questions' => [
        [
            'key'       => 'brand_voice',
            'prompt'    => 'Describe the brand voice for SEO copy (tone, person, do/don’t), or paste 2-3 example titles to imitate.',
            'detect'    => 'get_option(nibwp_seo_pro_brand_voice)',
            'type'      => 'string',
            'required'  => true,
            'cache_key' => 'seo_brand_voice',
        ],
        [
            'key'       => 'target_post_types',
            'prompt'    => 'Which post types are in scope? (comma-separated, e.g. post,page,product)',
            'type'      => 'string',
            'required'  => false,
            'cache_key' => 'seo_target_post_types',
        ],
        [
            'key'       => 'market_locale',
            'prompt'    => 'Primary language/market for tone + SERP (e.g. en-US)? Leave blank for the site default.',
            'type'      => 'string',
            'required'  => false,
            'cache_key' => 'seo_market_locale',
        ],
    ],
];
