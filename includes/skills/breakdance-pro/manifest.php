<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Skill Manifest — Breakdance Pro.
 *
 * HTML / URL / image / Figma → validated Breakdance page, template, header,
 * footer, popup or global block.
 *
 * What makes this one different from the other builder skills: Breakdance
 * publishes its element registry at runtime. Every other builder skill here
 * ships a written element reference that drifts with each release of the
 * builder; this one reads `get_elements_for_builder()` and validates against
 * the schema of the install it is actually building on. An unknown slug or a
 * mistyped property path is caught before anything is written, rather than
 * rendering as a blank section nobody can explain.
 *
 * Persistence delegates to the Breakdance integration
 * (includes/premium/integrations/breakdance.php), so this skill stays a
 * validator, recommender and orchestrator.
 *
 * Covers Oxygen 6 unchanged — it is the same codebase under a different brand,
 * and every post type and meta key is resolved through Breakdance's own
 * constants rather than hardcoded.
 */

return [
    'id'             => 'breakdance-pro',
    'name'           => 'Breakdance Pro',
    'tagline'        => 'Convert HTML, URLs, screenshots or Figma frames into validated Breakdance pages and templates — checked against your own element registry and design tokens',
    'description'    => 'Paste HTML, drop a screenshot, share a URL or attach a Figma frame, and the agent rebuilds it as a real Breakdance page, template, header, footer, popup or global block. Every element slug and property path is checked against the registry of your install, not a documentation file — so a typo is an error message rather than a blank section. Literal colors and sizes that match variables you already defined are reported so the section follows your palette. Repeated cards are detected and turned into a loop bound to a post type. Templates are created with their display conditions, because a template with none is invisible. Existing pages can be audited against the same rules and refined one node at a time, without rebuilding work someone already did by hand. Figma frames are read from the file — node tree, auto-layout and Variables — rather than looked at as a picture, and Figma Variables are matched against yours by value so a frame adopts your design system instead of pasting hex codes; that path needs a Figma connection, and the skill says so plainly rather than quietly producing screenshot-quality output. Works on Oxygen 6 too.',
    'vendor'         => 'NIBWP',
    'version'        => '1.0.1',
    'category'       => 'page-builders',
    'premium'        => true,
    'price'          => 29,
    'required_plans' => ['pro', 'agency', 'lifetime'],
    'requires'       => ['breakdance'],
    'entitlements'   => ['skill:breakdance', 'skill:breakdance-pro', 'integration:breakdance'],
    'integration_files' => ['breakdance'],
    'features'       => [
        'Turn screenshots and images into working Breakdance pages',
        'Figma frames read as structure — node tree, auto-layout and Variables, not a picture (needs a Figma connection)',
        'Figma Variables matched against your own variables by value, so a frame adopts your design system',
        'Convert any live URL into a clean, editable Breakdance template',
        'Element validation against YOUR registry — not a static list that goes stale',
        '"Did you mean" suggestions when an element slug is wrong',
        'Property paths checked against each element\'s real control schema',
        'Catches the namespace backslash that silently breaks every slug in JSON',
        'Literal colors and sizes matched against variables you already defined',
        'Repeated cards detected and planned as a loop bound to a post type',
        'Headers, footers, popups and templates built WITH their display conditions',
        'Audit any existing Breakdance page against the same rules',
        'Refine one node at a time — never rebuild a page to change a heading',
        'WooCommerce element catalogue for store templates',
        'Design-library parts listed so new sections match what is already approved',
        'Works on Oxygen 6 — same codebase, different brand',
    ],
    'ability_files' => [
        'abilities/convert.php',
        'abilities/inspect.php',
        'abilities/build.php',
        'abilities/refine.php',
    ],
    'instructions_file' => 'authoring/SKILL.md',
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 3 7.5 12 12l9-4.5L12 3z"/><path d="m3 12 9 4.5L21 12"/><path d="m3 16.5 9 4.5 9-4.5"/></svg>',

    // ─── v2 routing contract ──────────────────────────────────────────────
    // What this skill OWNS, in the words a model can reason about. The
    // regexes below are a fast path; this sentence is the boundary.
    'use_when' => 'The user wants anything built, rebuilt or restyled in Breakdance on this site. Owns it however it is phrased. A Breakdance page is a node tree in post meta, not post_content - do not write HTML into the post body.',

    'triggers' => [
        '/(?i)\b(?:convert|rebuild|port|turn|make|build)\b[^.\n]{0,40}\b(?:breakdance|oxygen)\b/',
        '/(?i)\b(?:breakdance|oxygen)\b[^.\n]{0,40}\b(?:template|section|component|page|element|header|footer|popup|block)\b/',
        '/(?i)\b(?:html|url|page|file|image|screenshot|figma|mockup)\b[^.\n]{0,40}\b(?:to|into|as)\b[^.\n]{0,20}\b(?:breakdance|oxygen)\b/',
        '/(?i)\b(?:breakdance this|html to breakdance|breakdancify)\b/',
        '/(?i)\b(?:audit|check|review)\b[^.\n]{0,30}\bbreakdance\b/',
        // Order-agnostic build intent. The patterns above all require the
        // builder's name BEFORE the thing being built, so the most natural way
        // anyone phrases it — "create a hero section with X" — matched nothing,
        // the skill never loaded, and the agent improvised a tree with no
        // classes and no styles. That is what a customer reported.
        '/(?i)\b(?:create|build|make|design|add|generate|produce|craft|redesign|rebuild|style|restyle)\b[^.\n]{0,80}\b(?:breakdance)\b/',
        '/(?i)\b(?:breakdance)\b[^.\n]{0,80}\b(?:create|build|make|design|add|generate|produce|craft|redesign|rebuild|style|restyle)\b/',
    ],
    'commands' => [
        '/breakdance' => [
            'description' => 'Convert HTML / URL / image / Figma into a validated Breakdance page or template.',
        ],
        '/breakdance-audit' => [
            'description' => 'Audit an existing Breakdance page against the Breakdance Pro rules.',
        ],
        '/breakdance-refine' => [
            'description' => 'Change specific nodes on an existing Breakdance page.',
        ],
        '/breakdance-template' => [
            'description' => 'Build a header, footer, popup or template together with its display conditions.',
        ],
    ],
    'mandatory_routing' => [
        'preflight_required' => false,
        'pipeline' => [
            [
                'ability'       => 'nibwp/design-direction',
                'args_template' => ['purpose' => '{what the user asked for, in their words}'],
                'why'           => 'Decide how this site should look before building. Skip only if the Design Skills skill is switched off.',
            ],
            [
                'ability'       => 'nibwp/breakdance-info',
                'args_template' => [],
                'why'           => 'Confirm Breakdance is active and learn which brand mode it runs in — the post type slugs differ between Breakdance and Oxygen.',
            ],
            [
                'ability'       => 'nibwp/breakdance-pro-elements',
                'args_template' => ['action' => 'list'],
                'why'           => 'Read the element vocabulary of THIS site. Never assume a slug exists; the set varies with license, subplugins and third-party packs.',
            ],
            [
                'ability'       => 'nibwp/breakdance-pro-tokens',
                'args_template' => ['action' => 'all'],
                'why'           => 'Read the variables, selectors and presets already defined, so the new work matches the site rather than inventing a second design system.',
            ],
            [
                'ability'       => 'nibwp/breakdance-pro-html-to-section',
                'args_template' => ['dry_run' => true],
                'why'           => 'Validate the payload and surface recommendations — token matches, loop candidates, missing alt text — before anything is written.',
            ],
            [
                'ability'       => 'nibwp/breakdance-pro-html-to-section',
                'args_template' => ['dry_run' => false],
                'why'           => 'Write it, once the validation is clean and the user has seen the recommendations.',
            ],
            [
                'ability'       => 'nibwp/breakdance-pro-feedback',
                'args_template' => ['rating' => '{up|down}'],
                'why'           => 'Record how it went so the skill improves.',
            ],
        ],
        'forbidden_actions' => [
            'Writing a Breakdance page through nibwp/wp-create-post or post_content — a Breakdance page is a node tree in post meta and post_content is ignored',
            'Element slugs written with a single backslash in JSON (EssentialElements\\Heading must be EssentialElements\\\\Heading)',
            'Element types not present in this site\'s registry — check with nibwp/breakdance-pro-elements first',
            'Inline @media inside element properties — Breakdance stores per-breakpoint values on the control',
            'Hardcoded colors or sizes that exactly match a variable the site already defines',
            'Replacing a whole tree to change one node — use nibwp/breakdance-pro-refine',
            'Creating a header, footer or template without telling the user it has no display conditions and will not appear',
            'Persisting repeated static cards after the user accepted the loop recommendation',
            'Raw <form> or video <iframe> markup where Breakdance has an element for it',
            'Converting a Figma frame from a rendered image when the site can read the file — read it with nibwp/figma-pro-fetch and pass the node tree, auto-layout and Variables',
            'Creating variables on the site to match unmatched Figma Variables — report them and let the user decide',
        ],
        'before_answering' => 'Do NOT improvise element slugs or property paths. Breakdance publishes both at runtime — read them. A wrong slug does not error, it renders nothing, and the user is left looking at a blank section. Run every conversion with dry_run true first.',
    ],
    'preflight_questions' => [
        [
            'key'       => 'target_role',
            'prompt'    => 'What is being built — a page, or a template that applies in several places?',
            'choices'   => ['page', 'template', 'header', 'footer', 'popup', 'block'],
            'type'      => 'enum',
            'required'  => true,
            'cache_key' => 'breakdance_target_role',
        ],
        [
            'key'       => 'push_mode',
            'prompt'    => 'Write into an existing page, or create a new one?',
            'choices'   => ['new', 'replace', 'append'],
            'type'      => 'enum',
            'required'  => true,
            'cache_key' => 'breakdance_push_mode',
            // Per build, not per site: a remembered mode or target was offered
            // to the next task as a settled answer and could overwrite the
            // previous task's page.
            'cache'     => false,
        ],
        [
            'key'            => 'target_post_id',
            'prompt'         => 'Which existing post should it be written into?',
            'type'           => 'integer',
            'conditional_on' => ['push_mode' => 'replace'],
            'cache_key'      => 'breakdance_target_post_id',
            'cache'          => false,
        ],
        [
            'key'       => 'token_policy',
            'prompt'    => 'This site defines variables. Should generated sections use them wherever they match?',
            'detect'    => 'breakdance_has_token_layer',
            'choices'   => ['use_tokens', 'literals_only'],
            'type'      => 'enum',
            'cache_key' => 'breakdance_token_policy',
        ],
        [
            'key'       => 'loop_policy',
            'prompt'    => 'Repeated cards found. Bind them to a post type as a loop, or leave them static?',
            'choices'   => ['loop', 'static'],
            'type'      => 'enum',
            'cache_key' => 'breakdance_loop_policy',
        ],
    ],
];
