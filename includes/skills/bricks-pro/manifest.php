<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Skill Manifest — Bricks Pro.
 *
 * HTML / URL / image / Figma → validated Bricks template or section, with:
 *   - Element whitelist (only real Bricks core elements)
 *   - Global classes (Bricks' built-in pattern, BEM-prefixed)
 *   - ACSS / Bricks design-system tokens (var(--*) with fallback)
 *   - Query loops backed by CPT + ACF (no static repetition)
 *   - Dynamic data tags ({post_title}, {acf:field}, etc.) when CPT context
 *   - Native Bricks form / video / nav-menu elements (no raw iframe, no raw <form>)
 *   - Per-breakpoint settings (Bricks' built-in editor), no inline @media
 *
 * Persistence delegates to the existing `nibwp/bricks-create-template`
 * ability (in includes/premium/integrations/bricks.php), so this skill is
 * pure validator + recommender + orchestrator. Same gate model as
 * etchwp-pro.
 */

return [
    'id'             => 'bricks-pro',
    'name'           => 'Bricks Pro',
    'tagline'        => 'Convert HTML, URLs, images, screenshots, or Figma frames into validated Bricks templates with ACSS tokens, global classes, query loops, and dynamic data',
    'description'    => 'Paste raw HTML, drop a screenshot, share a URL, or attach a Figma frame — the agent rebuilds it as a clean Bricks template or section. Loop detection turns repeated cards into a Bricks Query Loop + CPT + ACF fields. Native Bricks elements (form, video, nav-menu) replace raw <form> / <iframe> / nav HTML. Hard validator rejects unknown element names, inline styles, hardcoded colors / font-sizes outside var() fallback, missing global classes, and static text where dynamic data is available.',
    'vendor'         => 'NIBWP',
    'version'        => '1.0.3',
    'category'       => 'page-builders',
    'premium'        => true,
    'price'          => 49,
    'required_plans' => ['pro', 'agency', 'lifetime'],
    'requires'       => ['bricks'],
    'entitlements'   => ['skill:bricks', 'skill:bricks-pro', 'integration:bricks'],
    'integration_files' => ['bricks', 'automaticcss', 'surecart'],
    'features'       => [
        'Turn screenshots or images into fully working Bricks templates',
        'Convert any live website URL into a clean, editable Bricks template',
        'Pixel-perfect recreation that stays faithful to the original design',
        'Element whitelist — only real Bricks core elements (section, container, div, heading, text, button, image, icon, video, form, nav-menu, ...)',
        'Global classes (Bricks-native) — BEM-prefixed, brand-scoped',
        'ACSS / Bricks design-token output with fallback values',
        'Query loops backed by CPT + ACF — no static repetition',
        'Dynamic data tags ({post_title}, {acf:field}, etc.) when CPT context',
        'Native form element (or shortcode wrap) — never raw <form>',
        'Native Bricks video element — never raw YouTube/Vimeo iframe',
        'Per-breakpoint settings via Bricks built-in editor — never inline @media',
        'Per-element checklist enforced server-side before persist',
        'Reusable section templates — not one-off paste',
    ],
    'ability_files' => [
        'abilities/html-to-component.php',
        'abilities/image-to-component.php',
        'abilities/url-to-component.php',
        'abilities/figma-to-component.php',
        'abilities/refine-component.php',
        'abilities/surecart-page.php',
        'abilities/feedback.php',
    ],
    'instructions_file' => 'authoring/SKILL.md',
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>',

    // ─── v2 routing contract ──────────────────────────────────────────────
    // What this skill OWNS, in the words a model can reason about. The
    // regexes below are a fast path; this sentence is the boundary.
    'use_when' => 'The user wants anything built, rebuilt, styled or restyled in Bricks on this site - a section, page, template, header or footer - from a description, an image, a URL, HTML or a Figma frame. Owns it however it is phrased. Do NOT write _bricks_page_* meta yourself; for a change to ONE existing element use nibwp/bricks-update-element instead of rebuilding.',

    'triggers' => [
        '/(?i)\b(?:convert|brickify|rebuild|port|turn|make)\b[^.\n]{0,40}\b(?:bricks|brickbuilder)\b/',
        '/(?i)\b(?:bricks|brickbuilder)\b[^.\n]{0,40}\b(?:template|section|component|page|element|header|footer|archive)\b/',
        '/(?i)\b(?:html|url|page|file|image|screenshot|figma|sketch)\b[^.\n]{0,40}\b(?:to|into|as)\b[^.\n]{0,20}\b(?:bricks)\b/',
        '/(?i)\b(?:brickify|bricks this|html to bricks)\b/',
        '/(?i)\b(?:create|build|generate)\b[^.\n]{0,20}\bbricks[^.\n]{0,30}\b(?:template|section|page|component|element|header|footer)\b/',
        // Order-agnostic build intent. The patterns above all require the
        // builder's name BEFORE the thing being built, so the most natural way
        // anyone phrases it — "create a hero section with X" — matched nothing,
        // the skill never loaded, and the agent improvised a tree with no
        // classes and no styles. That is what a customer reported.
        '/(?i)\b(?:create|build|make|design|add|generate|produce|craft|redesign|rebuild|style|restyle)\b[^.\n]{0,80}\b(?:bricks)\b/',
        '/(?i)\b(?:bricks)\b[^.\n]{0,80}\b(?:create|build|make|design|add|generate|produce|craft|redesign|rebuild|style|restyle)\b/',
    ],
    'commands' => [
        '/brickify' => [
            'description' => 'Convert HTML / URL / image / Figma to a validated Bricks template or section.',
        ],
        '/bricks-convert' => [
            'description' => 'Alias for /brickify.',
        ],
        '/bricks-refine' => [
            'description' => 'Tweak an existing Bricks template by template_id with a written instruction.',
        ],
        '/bricks-preflight' => [
            'description' => 'Re-run the per-session Bricks preflight (brand prefix, template type, target post, ACSS decision).',
        ],
    ],
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
                'args_template'  => ['skill_id' => 'bricks-pro'],
                'why'            => 'Resolve ACSS presence, brand prefix, target template type, push mode. Mints the _preflight_token the conversion ability requires.',
            ],
            [
                'ability'        => 'nibwp/load-skill-playbook',
                'args_template'  => ['skill_id' => 'bricks-pro', 'brand' => '{from_preflight.brand_prefix}', 'element_type' => '{detected}'],
                'why'            => 'Read SKILL.md + element catalog + dynamic-data tags + per-element checklist + lessons-learned.',
            ],
            [
                'ability'        => 'nibwp/bricks-pro-html-to-component',
                'args_template'  => ['dry_run' => true, '_preflight_token' => '{from_preflight.token}'],
                'why'            => 'Validate the agent-built payload. Surface recommendations[] (loop→Query Loop+CPT+ACF, iframe→Bricks Video, raw form→Bricks Form, alt-less images) to the user.',
            ],
            [
                'ability'        => 'nibwp/bricks-pro-html-to-component',
                'args_template'  => ['dry_run' => false, '_preflight_token' => '{from_preflight.token}'],
                'why'            => 'Persist as a Bricks template after a clean validation pass and user-confirmed recommendations. Delegates to nibwp/bricks-create-template.',
            ],
            [
                'ability'        => 'nibwp/bricks-pro-feedback',
                'args_template'  => ['skill_id' => 'bricks-pro'],
                'why'            => 'Record thumb-up/down so future runs improve.',
            ],
        ],
        'forbidden_actions' => [
            'Call nibwp/wp-create-post with raw HTML for content this skill claims',
            'Inline style="..." attribute on Bricks elements (use settings dict + globalClasses)',
            'Hardcoded color or font-size literals outside var() fallback',
            'Class names without the {brand}- prefix on global classes',
            'Inline @media queries inside element CSS (use Bricks breakpoint settings instead)',
            'Persist N static repeating cards when the user accepted dynamic Query Loop + CPT + ACF conversion',
            'Raw <form> HTML when nibwp/forms-manage is available',
            'Raw YouTube/Vimeo <iframe> instead of Bricks "video" element',
            'Element names not in the canonical Bricks element catalog (references/bricks-elements.md)',
        ],
        'before_answering' => 'Do NOT improvise. Do NOT inline CSS into element settings.style. Bricks has its own element types, settings shape, global classes, breakpoints, and dynamic-data tags — use them. The user paid for this skill: route through the pipeline. Calling html-to-component without a valid _preflight_token returns requires_user_input:true.',
    ],
    'preflight_questions' => [
        [
            'key'        => 'brand_prefix',
            'prompt'     => 'BEM brand prefix? (every global class will be {prefix}-...)',
            'detect'     => 'shared(brand_prefix)|get_option(nibwp_bricks_brand)|scan(bricks_global_classes)',
            'type'       => 'string',
            'required'   => true,
            // Shared cache key — same brand_prefix across etchwp / bricks / acss.
            'cache_key'  => 'brand_prefix',
        ],
        [
            'key'        => 'template_type',
            'prompt'     => 'Which Bricks template type?',
            'choices'    => ['content', 'section', 'header', 'footer', 'archive', 'error', 'popup'],
            'type'       => 'enum',
            'required'   => true,
            'cache_key'  => 'bricks_template_type',
        ],
        [
            'key'        => 'push_mode',
            'prompt'     => 'How should it be persisted?',
            'choices'    => ['new_template', 'replace_template'],
            'type'       => 'enum',
            'required'   => true,
            'cache_key'  => 'bricks_push_mode',
            // Per build, not per site: a remembered mode or target was offered
            // to the next task as a settled answer and could overwrite the
            // previous task's template.
            'cache'      => false,
        ],
        [
            'key'           => 'target_template_id',
            'prompt'        => 'Existing template ID to update (only when push_mode is replace_template)',
            'type'          => 'integer',
            'conditional_on'=> ['push_mode' => 'replace_template'],
            'cache_key'     => 'bricks_target_template_id',
            'cache'         => false,
        ],
        [
            'key'           => 'new_template_title',
            'prompt'        => 'Title for the new template?',
            'type'          => 'string',
            'conditional_on'=> ['push_mode' => 'new_template'],
            'cache_key'     => 'bricks_new_template_title',
            'cache'         => false,
        ],
        [
            'key'        => 'acss_decision',
            'prompt'     => 'ACSS not detected. How should tokens resolve?',
            'detect'     => 'acss_active',
            'choices'    => ['install_acss', 'bake_fallbacks', 'bricks_design_system'],
            'type'       => 'enum',
            'conditional_on' => ['acss_active' => false],
            'cache_key'  => 'bricks_acss_decision',
        ],
        [
            'key'        => 'dynamic_data_decision',
            'prompt'     => 'Detected repeating cards. Should they bind to a CPT + ACF (dynamic), or stay static?',
            'choices'    => ['dynamic_cpt_acf', 'static_repeat', 'reuse_existing_cpt'],
            'type'       => 'enum',
            'conditional_on' => ['has_loop_candidate' => true],
            'cache_key'  => 'bricks_dynamic_data_decision',
        ],
    ],
];
