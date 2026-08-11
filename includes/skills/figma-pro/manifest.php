<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Skill Manifest — Figma Pro.
 *
 * Figma frame / component → a native, maintainable WordPress build. figma-pro is
 * an ORCHESTRATOR: it reads the REAL Figma document (node tree, auto-layout,
 * constraints, Variables) via the Figma integration — not a screenshot — parses it
 * into the NibWP Design Object (NDO), establishes a design-token system, drives the
 * site's active builder skill (EtchWP / Bricks / Elementor / Gutenberg) to build
 * natively, and verifies the result by image-diffing the rendered page against
 * Figma's own export. Composes active enhancer skills (acss-pro, seo-pro) automatically.
 *
 * Requires the Figma integration (a connected Figma account). Same gate + pipeline
 * shape as elementor-pro / bricks-pro / etchwp-pro.
 *
 * NOTE (2026-07-23): design + knowledge base authored (authoring/**); the ability
 * PHP (integration read client + figma-pro-* abilities) is NOT implemented yet —
 * ability_files is intentionally empty so nothing fatals. This manifest exists so
 * the skill is VISIBLE on admin.php?page=nibwp-skills during local build-out.
 */

return [
    'id'             => 'figma-pro',
    'name'           => 'Figma Pro',
    'tagline'        => 'Convert Figma frames & components into native, maintainable WordPress — reads the real design (node tree + Variables), not a screenshot',
    'description'    => 'Point NibWP at a Figma URL, frame, or component and get a native WordPress build in your site\'s own builder. figma-pro reads the actual Figma document — auto-layout, constraints, Variables, styles — parses it into an internal design object, establishes a real design-token system (Variables → ACSS tokens), dedupes repeated components into reusable blocks with dynamic data, then drives the active builder skill (EtchWP / Bricks / Elementor / Gutenberg) to build it. The result is verified by rendering the page and image-diffing it against Figma\'s own export — pixel-perfect, not "close enough". Read-only in v1 (write-back to Figma is a later phase). Composes active enhancer skills automatically: acss-pro for a native token system, seo-pro for semantics/meta.',
    'vendor'         => 'NIBWP',
    'version'        => '1.0.1',
    'category'       => 'design',
    'premium'        => true,
    'price'          => 49,
    'required_plans' => ['pro', 'agency', 'lifetime'],
    'requires'       => ['figma'],
    'entitlements'   => ['skill:figma', 'skill:figma-pro', 'integration:figma'],
    'features'       => [
        'Convert any Figma frame → a WordPress page, or a component → a reusable block',
        'Reads the real Figma node tree + Variables (not a screenshot) — structure and tokens are derived, not guessed',
        'Establishes a design-token system first: Figma Variables → ACSS var(--token) + a type ramp',
        'Auto-layout → flexbox/grid, constraints → responsive, effects → box-shadow — mapped natively',
        'Detects repeated components and builds ONE reusable component with dynamic data — never duplicated markup',
        'Builds into your site\'s active builder — EtchWP, Bricks, Elementor, or Gutenberg core (auto-detected, override anytime)',
        'Composes enhancer skills automatically when active — acss-pro (native tokens), seo-pro (semantics/meta)',
        'Pixel-diff verified — renders the built page and compares it to Figma\'s export, then iterates',
        'Assets sideloaded to your Media Library (2× images, inline SVG icons)',
        'Reads private Figma files via your own account token; read-only, persisted as a draft with a backup',
        'Two read sources: Figma REST (headless default) or Figma Dev Mode MCP (live in-app context)',
    ],
    // MCP tools: detect-builder, analyze, convert (reuse the integration pipeline).
    'ability_files' => ['abilities/convert.php'],
    'integration_files' => ['figma'],
    'instructions_file' => 'authoring/SKILL.md',
    'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M8.5 24a3.5 3.5 0 0 0 3.5-3.5V17H8.5a3.5 3.5 0 1 0 0 7z"/><path d="M5 12a3.5 3.5 0 0 1 3.5-3.5H12v7H8.5A3.5 3.5 0 0 1 5 12z"/><path d="M5 4.5A3.5 3.5 0 0 1 8.5 1H12v7H8.5A3.5 3.5 0 0 1 5 4.5z"/><path d="M12 1h3.5a3.5 3.5 0 1 1 0 7H12V1z"/><path d="M19 12a3.5 3.5 0 1 1-7 0 3.5 3.5 0 0 1 7 0z"/></svg>',

    // ─── v2 routing contract ──────────────────────────────────────────────
    'triggers' => [
        '/(?i)\b(?:convert|rebuild|turn|make|build|import)\b[^.\n]{0,40}\bfigma\b/',
        '/(?i)\bfigma\b[^.\n]{0,40}\b(?:to|into|as)\b[^.\n]{0,20}\b(?:wordpress|wp|etch|bricks|elementor|page|site|block)\b/',
        '/(?i)\bfigma(?:\.com)?\/(?:file|design)\/[a-z0-9]+/i',
        '/(?i)\b(?:figma to wordpress|figmatowp)\b/',
    ],
    'commands' => [
        '/figma-analyze'    => ['description' => 'Read a Figma file: pages, frames, sections, components, and tokens (read-only report).'],
        '/figma-convert'    => ['description' => 'Convert a Figma frame or component into a native WordPress page/block.'],
        '/figma-tokens'     => ['description' => 'Extract the design-token system (Variables → ACSS) without building.'],
        '/figma-components' => ['description' => 'Build the reusable component set detected in the design.'],
        '/figma-sync'       => ['description' => 'Sync Figma Variables → the builder\'s global colors / fonts / classes.'],
    ],
    'mandatory_routing' => [
        'preflight_required' => true,
        'preflight_ability'  => 'nibwp/skill-preflight',
        'pipeline' => [
            [
                'ability'       => 'nibwp/skill-preflight',
                'args_template' => ['skill_id' => 'figma-pro'],
                'why'           => 'Resolve the Figma target (file/node), conversion mode, and persist target. Mints the _preflight_token the convert ability requires.',
            ],
            [
                'ability'       => 'nibwp/load-skill-playbook',
                'args_template' => ['skill_id' => 'figma-pro'],
                'why'           => 'Read SKILL.md + core/ (parser, NDO schema, tokens, components), the builder adapter, and the composition rule.',
            ],
            [
                'ability'       => 'nibwp/figma-pro-detect-builder',
                'args_template' => [],
                'why'           => 'Report the site\'s active builder to target + the active enhancer skills (acss-pro, seo-pro) to auto-fold.',
            ],
            [
                'ability'       => 'nibwp/figma-pro-convert',
                'args_template' => ['dry_run' => true, '_preflight_token' => '{from_preflight.token}'],
                'why'           => 'Fetch the node tree + export + Variables, build the NDO, delegate to the builder, and return the pixel-diff score. Fix every failed[] item.',
            ],
            [
                'ability'       => 'nibwp/figma-pro-convert',
                'args_template' => ['dry_run' => false, '_preflight_token' => '{from_preflight.token}'],
                'why'           => 'Persist after a clean pass — as a DRAFT with a backup. Never overwrites live content.',
            ],
        ],
        'forbidden_actions' => [
            'Recreating a Figma design from a screenshot instead of reading the node tree + Variables',
            'Writing one giant HTML block instead of native builder elements',
            'Duplicating repeated components instead of one reusable component with dynamic data',
            'Hardcoding colors/sizes that have a matching Figma Variable / token',
            'Using clamp() for font-size (the Etch/ACSS validator rejects it)',
            'Writing builder meta directly (e.g. _elementor_data) instead of going through the builder skill\'s persister',
            'Composing two builders onto the SAME output (incompatible formats) — enhancers only',
            'Overwriting published content — always persist a draft with a backup',
        ],
        'before_answering' => 'Do NOT recreate from a screenshot. Read the real Figma document via the Figma integration, parse it into the NDO (tokens first), dedupe components, auto-detect the builder + fold in active enhancers, then route through nibwp/figma-pro-convert. Calling it without a valid _preflight_token returns requires_user_input:true.',
    ],
    'preflight_questions' => [
        [
            'key'       => 'figma_target',
            'prompt'    => 'Figma file URL or node link to convert (e.g. https://figma.com/design/KEY/…?node-id=1-234).',
            'type'      => 'string',
            'required'  => true,
            'cache_key' => 'figma_target',
        ],
        [
            'key'       => 'figma_conversion_mode',
            'prompt'    => 'Conversion mode — native (reusable components, recommended), exact_clone (pixel-faithful one-off), or design_system (tokens + component library).',
            'choices'   => ['native', 'exact_clone', 'design_system'],
            'type'      => 'enum',
            'required'  => false,
            'cache_key' => 'figma_conversion_mode',
        ],
        [
            'key'       => 'figma_builder',
            'prompt'    => 'Target builder — auto (detect the active builder), or force etchwp / bricks / elementor / gutenberg.',
            'choices'   => ['auto', 'etchwp', 'bricks', 'elementor', 'gutenberg'],
            'type'      => 'enum',
            'required'  => false,
            'cache_key' => 'figma_builder',
        ],
        [
            'key'            => 'figma_push_mode',
            'prompt'         => 'Persist as a new_page, new_post, or update an existing post?',
            'choices'        => ['new_page', 'new_post', 'update'],
            'type'           => 'enum',
            'required'       => false,
            'cache_key'      => 'figma_push_mode',
        ],
        [
            'key'            => 'figma_target_post_id',
            'prompt'         => 'Existing post/page ID to update (only when mode = update).',
            'type'           => 'integer',
            'conditional_on' => ['figma_push_mode' => 'update'],
            'cache_key'      => 'figma_target_post_id',
        ],
    ],
];
