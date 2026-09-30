<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Skill Manifest — EtchWP Pro
 *
 * Drop your full instructions.md + ability files into this folder and the
 * registry will auto-load them when the user has an active Pro/Agency/Lifetime
 * license AND the EtchWP integration is active.
 */

return [
    'id'             => 'etchwp-pro',
    'name'           => 'EtchWP Pro',
    'tagline'        => 'Convert HTML, URLs, images, screenshots, or Figma frames into validated EtchWP components',
    'description'    => 'Paste raw HTML, drop a screenshot, share a URL, or attach a Figma frame — the agent rebuilds it as a clean, brand-consistent EtchWP component. Loop detection turns repeated cards into CPT + ACF + Etch loop blocks automatically. The validator rejects clamp() font-size, hardcoded colors, raw <style> tags, and missing brand prefixes before anything persists.',
    'vendor'         => 'NIBWP',
    'version'        => '1.1.5',
    'category'       => 'page-builders',
    'premium'        => true,
    'price'          => 49,
    'required_plans' => ['pro', 'agency', 'lifetime'],
    'requires'       => ['etchwp'],
    // FluentCart product entitlement strings that unlock this skill. The
    // registry also accepts the implicit `skill:<id>` (skill:etchwp-pro) and
    // the Bundle wildcard `skill:*` — listing aliases here keeps legacy
    // product stampings ("skill:etchwp,integration:etchwp") working.
    'entitlements'   => ['skill:etchwp', 'skill:etchwp-pro', 'integration:etchwp'],
    // Premium integration ability files this skill bundles + activates when
    // the skill license is the only thing the user has paid for (no Pro).
    // Paths are relative to includes/premium/integrations/ in the monorepo.
    // build/build-skill.sh copies these into the skill zip, and the skill
    // entry file requires them on wp_abilities_api_init when the skill is
    // unlocked. EtchWP authoring leans on ACSS heavily, so we ship both.
    'integration_files' => ['etchwp', 'automaticcss', 'surecart'],
    'features'       => [
            'Turn screenshots or images into fully working EtchWP components',
            'Convert any live website URL into a clean, editable component',
            'Pixel-perfect recreation that stays faithful to the original design',
            'Automatically matches spacing, layout, colors, and typography',
            'ACSS-ready output — mapped to tokens with safe fallback values',
            'Fully responsive by default — optimized for mobile, tablet, and desktop',
            'Production-grade BEM structure for clean, scalable architecture',
            'Reusable component system — not one-off builds',
            'Smart component props for flexible variants (size, style, visibility)',
            'Conditional rendering built-in for dynamic UI behavior',
            'Loop support for dynamic lists, cards, and repeating content',
            'Preserves interactions like hover states, animations, and accordions',
            'Outputs structured, JSON-ready components for direct integration',
    ],
    'ability_files' => [
        'abilities/image-to-component.php',
        'abilities/html-to-component.php',
        'abilities/figma-to-component.php',
        'abilities/refine-component.php',
        'abilities/repair.php',
        'abilities/surecart-page.php',
        'abilities/audit.php',
        'abilities/feedback.php',
    ],
    'instructions_file' => 'authoring/SKILL.md',
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>',

    // ─── v2 routing contract (consumed by nibwp_skills_skill_cards) ───────
    // Trigger regex set. Discover emits these so the agent can pattern-match
    // user prose deterministically instead of substring-hunting in a tagline.
    // What this skill OWNS, in the words a model can reason about. The
    // regexes below are a fast path; this sentence is the boundary.
    'use_when' => 'The user wants anything built, rebuilt, styled or restyled in Etch / EtchWP on this site - a hero, section, page, component, template or layout - from a description, an image, a URL, HTML or a Figma frame. Owns it however it is phrased, including "a hero with columns and a heading on etch and acss" or "we use etch here, can you do a hero". Do NOT hand-write Etch blocks, post meta or the etch_styles option yourself.',

    'triggers' => [
        '/(?i)\b(?:convert|etchify|rebuild|port|turn|make)\b[^.\n]{0,40}\b(?:etch|etchwp|etchedy)\b/',
        '/(?i)\b(?:etch|etchwp|etchedy)\b[^.\n]{0,40}\b(?:component|section|page|block|element)\b/',
        '/(?i)\b(?:html|url|page|file|image|screenshot|figma|sketch)\b[^.\n]{0,40}\b(?:to|into|as)\b[^.\n]{0,20}\b(?:etch|etchwp)\b/',
        '/(?i)\b(?:etchify|etch this|html to etch)\b/',
        '/(?i)\b(?:create|build|generate)\b[^.\n]{0,20}\betch[^.\n]{0,30}\b(?:page|section|component|element)\b/',
        // Order-agnostic build intent. The patterns above all require the
        // builder's name BEFORE the thing being built, so the most natural way
        // anyone phrases it — "create a hero section with X" — matched nothing,
        // the skill never loaded, and the agent improvised a tree with no
        // classes and no styles. That is what a customer reported.
        '/(?i)\b(?:create|build|make|design|add|generate|produce|craft|redesign|rebuild|style|restyle)\b[^.\n]{0,80}\b(?:etch|etchwp|etchedy)\b/',
        '/(?i)\b(?:etch|etchwp|etchedy)\b[^.\n]{0,80}\b(?:create|build|make|design|add|generate|produce|craft|redesign|rebuild|style|restyle)\b/',
    ],
    // Slash-command map. MCP clients with command palettes expose these
    // directly; chat agents trigger them on a "/etchify ..." user message.
    'commands' => [
        '/etchify' => [
            'description' => 'Convert HTML / URL / image / Figma to a validated EtchWP component.',
        ],
        '/etch-convert' => [
            'description' => 'Alias for /etchify.',
        ],
        '/etch-refine' => [
            'description' => 'Tweak an existing component by component_id with a written instruction.',
        ],
        '/etch-preflight' => [
            'description' => 'Re-run the per-session EtchWP preflight (brand prefix, target post, push mode, ACSS decision).',
        ],
    ],
    // Mandatory routing — the contract the agent MUST follow on a trigger
    // match. discover-abilities surfaces this so improvisation is structurally
    // ruled out: the listed pipeline runs in order, the listed actions are
    // forbidden, and preflight is required before the destructive ability.
    'mandatory_routing' => [
        'preflight_required' => true,
        'preflight_ability'  => 'nibwp/skill-preflight',
        'pipeline' => [
            [
                'ability'       => 'nibwp/design-direction',
                'args_template' => ['purpose' => '{what the user asked for, in their words}'],
                'why'           => 'Decide how this site should look before building: color roles with contrast already checked, type, spacing rhythm, layout sequence, and the generic defaults to refuse. Skip only if the Design Skills skill is switched off.',
            ],
            [
                'ability'        => 'nibwp/skill-preflight',
                'args_template'  => ['skill_id' => 'etchwp-pro'],
                'why'            => 'Resolve ACSS presence, brand prefix, target post, push mode. Mints the _preflight_token the conversion ability requires.',
            ],
            [
                'ability'        => 'nibwp/load-skill-playbook',
                'args_template'  => ['skill_id' => 'etchwp-pro', 'brand' => '{from_preflight.brand_prefix}', 'element_type' => '{detected}'],
                'why'            => 'Read SKILL.md + per-element checklist + lessons-learned. Returns environment block.',
            ],
            [
                'ability'        => 'nibwp/etchwp-pro-html-to-component',
                'args_template'  => ['dry_run' => true, '_preflight_token' => '{from_preflight.token}'],
                'why'            => 'Validate the agent-built payload. Surface recommendations[] (loop→CPT+ACF, iframe→etch/embed, etc.) to the user.',
            ],
            [
                'ability'        => 'nibwp/etchwp-pro-html-to-component',
                'args_template'  => ['dry_run' => false, '_preflight_token' => '{from_preflight.token}'],
                'why'            => 'Persist after a clean validation pass and user-confirmed recommendations.',
            ],
            [
                'ability'        => 'nibwp/etchwp-pro-feedback',
                'args_template'  => ['skill_id' => 'etchwp-pro'],
                'why'            => 'Record thumb-up/down so future runs improve.',
            ],
        ],
        'forbidden_actions' => [
            'Call nibwp/wp-create-post with raw HTML for content this skill claims',
            'Inline <style> or <link rel="stylesheet"> inside any block',
            'clamp() on font-size',
            'Hardcoded font-size or color literals outside var() fallback',
            'Class names without the {brand}- prefix (utility hooks like is-active are exempt)',
            'Persist N static repeating cards when the user accepted dynamic CPT+ACF conversion',
            'Raw <form> HTML when nibwp/forms-manage is available',
            'Raw YouTube/Vimeo <iframe> instead of etch/embed',
        ],
        'before_answering' => 'Do NOT improvise. Do NOT write CSS or font-sizes yourself. The user paid for this skill: route through the pipeline. Calling html-to-component without a valid _preflight_token returns requires_user_input:true and you will be looping until you call skill-preflight first.',
    ],
    // Preflight questions — what nibwp/skill-preflight asks the user (one
    // time per session). Server auto-detects ACSS presence + EtchWP version
    // + existing brand prefixes; only the questions whose detected_value is
    // missing get surfaced to the user.
    'preflight_questions' => [
        [
            'key'        => 'brand_prefix',
            'prompt'     => 'BEM brand prefix? (every class will be {prefix}-...)',
            'detect'     => 'shared(brand_prefix)|get_option(nibwp_etchwp_brand)|scan(etch_styles)',
            'type'       => 'string',
            'required'   => true,
            // Shared cache key — etchwp + bricks + acss all read/write the same
            // `brand_prefix` user default so the user answers ONCE per site.
            'cache_key'  => 'brand_prefix',
        ],
        [
            'key'        => 'target_post_id',
            'prompt'     => 'Which page/post should the component be inserted into?',
            'detect'     => 'list(recent_posts,20)',
            'type'       => 'integer|new',
            'required'   => true,
            'cache_key'  => 'etchwp_target_post_id',
            // Where this build goes is true of this build only. Remembered, it
            // was offered to the next task as a settled answer, and the
            // persister overrides the payload's target with it.
            'cache'      => false,
        ],
        [
            'key'        => 'push_mode',
            'prompt'     => 'How should it be inserted?',
            'choices'    => ['append', 'replace_section', 'new_page'],
            'type'       => 'enum',
            'required'   => true,
            'cache_key'  => 'etchwp_push_mode',
            'cache'      => false,
        ],
        [
            'key'           => 'new_page_title',
            'prompt'        => 'Title for the new page?',
            'type'          => 'string',
            'conditional_on'=> ['push_mode' => 'new_page'],
            'cache_key'     => 'etchwp_new_page_title',
            'cache'         => false,
        ],
        [
            'key'        => 'acss_decision',
            'prompt'     => 'ACSS not detected. How should tokens resolve?',
            'detect'     => 'acss_active',
            'choices'    => ['install_acss', 'bake_fallbacks', 'brand_stylesheet'],
            'type'       => 'enum',
            'conditional_on' => ['acss_active' => false],
            'cache_key'  => 'etchwp_acss_decision',
        ],
    ],
];
