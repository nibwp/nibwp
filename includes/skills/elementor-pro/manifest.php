<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Skill Manifest — Elementor Pro.
 *
 * HTML / URL / image → a native, editable Elementor page. Elementor pages are a
 * recursive JSON tree of containers + widgets stored (wp_slash'd) in the
 * `_elementor_data` post meta; styling lives in widget/container CONTROLS, not
 * custom CSS. This skill is the synthesizer's guardrail: the agent builds the
 * element tree, then it is validated against the LIVE widget registry (real
 * widgetTypes + control ids for THIS install — never guessed), scored, and
 * persisted atomically (wp_slash + edit-mode/version/template meta + CSS regen +
 * round-trip guard + backup) so the page actually renders.
 *
 * Same gate + pipeline shape as kadence-pro / bricks-pro / etchwp-pro.
 */

return [
    'id'             => 'elementor-pro',
    'name'           => 'Elementor Pro',
    'tagline'        => 'Convert HTML, URLs, images, or screenshots into native, editable Elementor pages — containers and widgets, not raw HTML',
    'description'    => 'Paste raw HTML, drop a screenshot, or share a URL — the agent rebuilds it as a clean, native Elementor page: modern flexbox containers first, then real widgets (heading, text-editor, image, button, icon-box, image-box, video, tabs, accordion, and any Pro/add-on widget your site actually has). Every widget type and control id is checked against the LIVE Elementor registry on your site, so nothing is invented. Styling maps to Elementor controls (padding, gap, background, typography, colors) — not a wall of custom CSS. A hard validator rejects unknown widget types, invalid control names, broken hierarchy and duplicate ids; the persister writes the data the way Elementor needs it (correctly slashed, edit-mode enabled, CSS regenerated) with a round-trip guard so a page is never saved truncated.',
    'vendor'         => 'NIBWP',
    'version'        => '1.0.2',
    'category'       => 'page-builders',
    'premium'        => true,
    'price'          => 49,
    'required_plans' => ['pro', 'agency', 'lifetime'],
    'requires'       => ['elementor'],
    'entitlements'   => ['skill:elementor', 'skill:elementor-pro', 'integration:elementor'],
    'features'       => [
        'Turn screenshots or images into fully working Elementor pages',
        'Convert any live website URL or raw HTML into a clean, editable Elementor layout',
        'Native elements only — flexbox containers, heading, text-editor, image, button, icon-box, image-box, video, tabs, accordion, and your installed Pro / add-on widgets',
        'Container-first structure — sections and columns become nested containers, widgets go inside',
        'Widget types + control ids validated against the LIVE Elementor registry on your site (nothing guessed, Pro-aware)',
        'Styling as Elementor controls (padding, gap, background, typography, color) — minimal custom CSS',
        'Responsive by default — desktop / tablet / mobile values, not desktop-only',
        'Media sideloaded into your Media Library with real attachment IDs — no hotlinked images',
        'Persisted the way Elementor needs it — correctly slashed data, edit-mode on, CSS regenerated, so the page renders immediately',
        'Round-trip guard — never saves a truncated page',
        'Validate + score before you commit (dry-run); read an existing page structure; refine by instruction',
    ],
    'ability_files' => [
        'abilities/schema.php',
        'abilities/convert.php',
        'abilities/structure.php',
        'abilities/feedback.php',
    ],
    'instructions_file' => 'authoring/SKILL.md',
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M7 8h10M7 12h10M7 16h6"/></svg>',

    // ─── v2 routing contract ──────────────────────────────────────────────
    // What this skill OWNS, in the words a model can reason about. The
    // regexes below are a fast path; this sentence is the boundary.
    'use_when' => 'The user wants anything built, rebuilt or restyled in Elementor on this site - a section, page or template - including V4 atomic elements. Owns it however it is phrased. Always read the live widget registry before authoring; never guess a widget name or control id.',

    'triggers' => [
        '/(?i)\b(?:convert|rebuild|port|turn|make|build)\b[^.\n]{0,40}\belementor\b/',
        '/(?i)\belementor\b[^.\n]{0,40}\b(?:page|layout|template|section|container|widget|landing)\b/',
        '/(?i)\b(?:html|url|page|image|screenshot|figma)\b[^.\n]{0,40}\b(?:to|into|as)\b[^.\n]{0,20}\belementor\b/',
        '/(?i)\b(?:elementorify|html to elementor)\b/',
        // Order-agnostic build intent. The patterns above all require the
        // builder's name BEFORE the thing being built, so the most natural way
        // anyone phrases it — "create a hero section with X" — matched nothing,
        // the skill never loaded, and the agent improvised a tree with no
        // classes and no styles. That is what a customer reported.
        '/(?i)\b(?:create|build|make|design|add|generate|produce|craft|redesign|rebuild|style|restyle)\b[^.\n]{0,80}\b(?:elementor)\b/',
        '/(?i)\b(?:elementor)\b[^.\n]{0,80}\b(?:create|build|make|design|add|generate|produce|craft|redesign|rebuild|style|restyle)\b/',
    ],
    'commands' => [
        '/elementorify'      => ['description' => 'Convert HTML / URL / image to a validated, native Elementor page.'],
        '/elementor-convert' => ['description' => 'Alias for /elementorify.'],
        '/elementor-refine'  => ['description' => 'Tweak an existing Elementor page by post_id with a written instruction.'],
        '/elementor-schema'  => ['description' => 'Show the live widget registry (real widget types + control ids) for this site.'],
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
                'ability'       => 'nibwp/skill-preflight',
                'args_template' => ['skill_id' => 'elementor-pro'],
                'why'           => 'Resolve target mode (new_page / update), title, and whether Elementor Pro is available. Mints the _preflight_token the conversion ability requires.',
            ],
            [
                'ability'       => 'nibwp/load-skill-playbook',
                'args_template' => ['skill_id' => 'elementor-pro'],
                'why'           => 'Read SKILL.md + references (architecture, widget map, controls, conversion rules, anti-patterns).',
            ],
            [
                'ability'       => 'nibwp/elementor-pro-list-widgets',
                'args_template' => [],
                'why'           => 'Get the real widget types available on THIS site (core + Pro + add-ons). Never guess a widgetType.',
            ],
            [
                'ability'       => 'nibwp/elementor-pro-widget-schema',
                'args_template' => ['widget' => '{each widget you will use}'],
                'why'           => 'Get the real control ids + types + responsive flags for each widget before setting any settings. A wrong control id renders nothing.',
            ],
            [
                'ability'       => 'nibwp/elementor-pro-html-to-page',
                'args_template' => ['dry_run' => true, '_preflight_token' => '{from_preflight.token}'],
                'why'           => 'Validate the agent-built container/widget tree. Fix every failed[] item; heed warnings (missing alt, non-responsive, custom-css-over-controls).',
            ],
            [
                'ability'       => 'nibwp/elementor-pro-html-to-page',
                'args_template' => ['dry_run' => false, '_preflight_token' => '{from_preflight.token}'],
                'why'           => 'Persist after a clean validation pass. Data is slashed + CSS regenerated, so the front end renders immediately.',
            ],
            [
                'ability'       => 'nibwp/elementor-pro-feedback',
                'args_template' => ['rating' => '{up|down}'],
                'why'           => 'Record thumb-up/down so future runs improve.',
            ],
        ],
        'forbidden_actions' => [
            'Write raw HTML into post_content, or a core/html-style HTML widget, for content Elementor has a native widget for',
            'widgetType values not present in the live registry (nibwp/elementor-pro-list-widgets)',
            'Control ids not present in a widget’s live schema (nibwp/elementor-pro-widget-schema)',
            'Legacy section/column for NEW layouts — use flexbox containers',
            'Reusing the same element id on two elements',
            'Hotlinking external image URLs instead of sideloading to real attachment IDs',
            'Pouring layout into Custom CSS when a container/widget control exists',
            'Offering Pro-only widgets (form, posts, loop, woo, theme-builder) when Elementor Pro is not active',
        ],
        'before_answering' => 'Do NOT improvise raw HTML. Elementor is a structured tree of containers + widgets whose styling lives in controls. Build a nested tree of real Elementor elements, validate widget types + control ids against the LIVE registry, and route it through nibwp/elementor-pro-html-to-page. Calling it without a valid _preflight_token returns requires_user_input:true.',
    ],
    'preflight_questions' => [
        [
            'key'       => 'elementor_push_mode',
            'prompt'    => 'Where should it be persisted?',
            'choices'   => ['new_page', 'new_post', 'update'],
            'type'      => 'enum',
            'required'  => true,
            'cache_key' => 'elementor_push_mode',
        ],
        [
            'key'            => 'elementor_new_title',
            'prompt'         => 'Title for the new page/post?',
            'type'           => 'string',
            'conditional_on' => ['elementor_push_mode' => 'new_page'],
            'cache_key'      => 'elementor_new_title',
            'cache'          => false,
        ],
        [
            'key'            => 'elementor_target_post_id',
            'prompt'         => 'Existing post/page ID to update (only when mode = update).',
            'type'           => 'integer',
            'conditional_on' => ['elementor_push_mode' => 'update'],
            'cache_key'      => 'elementor_target_post_id',
        ],
        [
            'key'       => 'elementor_template',
            'prompt'    => 'Page template — elementor_canvas (blank), elementor_header_footer (with theme header/footer), or default.',
            'choices'   => ['elementor_canvas', 'elementor_header_footer', 'default'],
            'type'      => 'enum',
            'required'  => false,
            'cache_key' => 'elementor_template',
        ],
    ],
];
