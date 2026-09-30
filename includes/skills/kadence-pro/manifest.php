<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Skill Manifest — Kadence Pro.
 *
 * HTML / URL / image → validated Kadence Blocks layout, template, or reusable
 * pattern. Kadence blocks are ordinary Gutenberg blocks (kadence/rowlayout,
 * kadence/advancedheading, …) whose styling lives in ATTRIBUTES keyed by a
 * uniqueID; Kadence generates the CSS. This skill is the synthesizer's guardrail:
 * build the block tree agent-side, then validate → score → persist through the
 * skill's own converter/validator/persister (no raw HTML into post_content).
 *
 * Same gate + pipeline shape as bricks-pro / etchwp-pro.
 */

return [
    'id'             => 'kadence-pro',
    'name'           => 'Kadence Pro',
    'tagline'        => 'Convert HTML, URLs, images, or screenshots into validated Kadence Blocks layouts, templates, and reusable patterns',
    'description'    => 'Paste raw HTML, drop a screenshot, or share a URL — the agent rebuilds it as a clean Kadence Blocks layout (rowlayout/column sections, advancedheading, singlebtn, image, infobox, iconlist, testimonials…). A hard validator rejects unknown block names, illegal nesting (column outside rowlayout, button outside advancedbtn), missing/duplicate uniqueIDs, and empty headings/buttons; a round-trip guard makes sure no block is ever dropped on save. Persist to a page, post, Kadence Element (header/footer/hook), or a reusable pattern.',
    'vendor'         => 'NIBWP',
    'version'        => '1.0.3',
    'category'       => 'page-builders',
    'premium'        => true,
    'price'          => 49,
    'required_plans' => ['pro', 'agency', 'lifetime'],
    'requires'       => ['kadence'],
    'entitlements'   => ['skill:kadence', 'skill:kadence-pro', 'integration:kadence'],
    'integration_files' => ['kadence'],
    'features'       => [
        'Turn screenshots or images into fully working Kadence Blocks layouts',
        'Convert any live website URL into a clean, editable Kadence layout',
        'Real Kadence blocks only — rowlayout, column, advancedheading, singlebtn, image, infobox, iconlist, accordion, tabs, testimonials, posts, gallery…',
        'Correct structure enforced — columns only inside rowlayout, buttons only inside advancedbtn',
        'Every block gets a unique uniqueID (Kadence keys its generated CSS off it)',
        'Styling as native Kadence attributes (padding, background, color, typography) — no separate CSS to write',
        'Dynamic kadence/posts suggested instead of 3+ repeated static cards',
        'Round-trip serialization guard — never persist a truncated page',
        'Persist to a page, post, Kadence Element (header/footer/hook), or reusable pattern',
        'Validate + score before you commit (dry-run)',
        'Refine an existing layout by written instruction',
    ],
    'ability_files' => [
        'abilities/convert.php',
        'abilities/refine.php',
        'abilities/list-blocks.php',
        'abilities/feedback.php',
    ],
    'instructions_file' => 'authoring/SKILL.md',
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 9v12"/></svg>',

    // ─── v2 routing contract ──────────────────────────────────────────────
    // What this skill OWNS, in the words a model can reason about. The
    // regexes below are a fast path; this sentence is the boundary.
    'use_when' => 'The user wants anything built, rebuilt or restyled with Kadence Blocks on this site - a section, page, pattern or template. Owns it however it is phrased. Do NOT emit core/html for things Kadence has a real block for.',

    'triggers' => [
        '/(?i)\b(?:convert|kadencify|rebuild|port|turn|make)\b[^.\n]{0,40}\bkadence\b/',
        '/(?i)\bkadence\b[^.\n]{0,40}\b(?:blocks?|layout|template|section|page|pattern|header|footer)\b/',
        '/(?i)\b(?:html|url|page|image|screenshot)\b[^.\n]{0,40}\b(?:to|into|as)\b[^.\n]{0,20}\bkadence\b/',
        '/(?i)\b(?:kadencify|html to kadence)\b/',
        // Order-agnostic build intent. The patterns above all require the
        // builder's name BEFORE the thing being built, so the most natural way
        // anyone phrases it — "create a hero section with X" — matched nothing,
        // the skill never loaded, and the agent improvised a tree with no
        // classes and no styles. That is what a customer reported.
        '/(?i)\b(?:create|build|make|design|add|generate|produce|craft|redesign|rebuild|style|restyle)\b[^.\n]{0,80}\b(?:kadence)\b/',
        '/(?i)\b(?:kadence)\b[^.\n]{0,80}\b(?:create|build|make|design|add|generate|produce|craft|redesign|rebuild|style|restyle)\b/',
    ],
    'commands' => [
        '/kadencify'        => ['description' => 'Convert HTML / URL / image to a validated Kadence Blocks layout, template, or pattern.'],
        '/kadence-convert'  => ['description' => 'Alias for /kadencify.'],
        '/kadence-refine'   => ['description' => 'Tweak an existing Kadence layout by post_id with a written instruction.'],
        '/kadence-preflight'=> ['description' => 'Re-run the per-session Kadence preflight (brand, target mode, title).'],
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
                'args_template' => ['skill_id' => 'kadence-pro'],
                'why'           => 'Resolve brand, target mode (page/post/element/pattern), and title. Mints the _preflight_token the conversion ability requires.',
            ],
            [
                'ability'       => 'nibwp/load-skill-playbook',
                'args_template' => ['skill_id' => 'kadence-pro'],
                'why'           => 'Read SKILL.md + references/kadence-blocks.md (block map, uniqueID rules, per-section checklists, anti-patterns).',
            ],
            [
                'ability'       => 'nibwp/kadence-pro-list-blocks',
                'args_template' => [],
                'why'           => 'Confirm the exact valid block names + parent/child rules before synthesizing.',
            ],
            [
                'ability'       => 'nibwp/kadence-pro-html-to-blocks',
                'args_template' => ['dry_run' => true, '_preflight_token' => '{from_preflight.token}'],
                'why'           => 'Validate the agent-built block tree. Fix every failed[] item; heed warnings (alt text, static-loop → kadence/posts).',
            ],
            [
                'ability'       => 'nibwp/kadence-pro-html-to-blocks',
                'args_template' => ['dry_run' => false, '_preflight_token' => '{from_preflight.token}'],
                'why'           => 'Persist after a clean validation pass. Then open the page once in the Kadence editor and Save to materialise static blocks.',
            ],
            [
                'ability'       => 'nibwp/kadence-pro-feedback',
                'args_template' => ['rating' => '{up|down}'],
                'why'           => 'Record thumb-up/down so future runs improve.',
            ],
        ],
        'forbidden_actions' => [
            'Write raw HTML into post_content via nibwp/wp-update-post for content this skill claims',
            'Block names not in the Kadence catalog (references/kadence-blocks.md / kadence-pro-list-blocks)',
            'kadence/column outside a kadence/rowlayout',
            'kadence/singlebtn outside a kadence/advancedbtn',
            'Reusing the same uniqueID on two blocks',
            'N static repeating cards when the content is posts and the user accepted a dynamic kadence/posts',
            'Inventing block attributes not present in Kadence Blocks',
        ],
        'before_answering' => 'Do NOT improvise raw HTML. Kadence has its own block types, attribute shapes, and structural rules — use them. Build a nested tree of real Kadence blocks and route it through nibwp/kadence-pro-html-to-blocks. Calling it without a valid _preflight_token returns requires_user_input:true.',
    ],
    'preflight_questions' => [
        [
            'key'       => 'brand_prefix',
            'prompt'    => 'Brand slug (used for feedback grouping + any custom class prefixes).',
            'detect'    => 'shared(brand_prefix)|get_option(nibwp_kadence_brand)',
            'type'      => 'string',
            'required'  => false,
            'cache_key' => 'brand_prefix',
        ],
        [
            'key'       => 'kadence_push_mode',
            'prompt'    => 'Where should it be persisted?',
            'choices'   => ['new_page', 'new_post', 'update', 'element', 'pattern'],
            'type'      => 'enum',
            'required'  => true,
            'cache_key' => 'kadence_push_mode',
        ],
        [
            'key'            => 'kadence_new_title',
            'prompt'         => 'Title for the new page/post/element/pattern?',
            'type'           => 'string',
            'conditional_on' => ['kadence_push_mode' => 'new_page'],
            'cache_key'      => 'kadence_new_title',
            'cache'          => false,
        ],
        [
            'key'            => 'kadence_target_post_id',
            'prompt'         => 'Existing post/page ID to update (only when mode = update).',
            'type'           => 'integer',
            'conditional_on' => ['kadence_push_mode' => 'update'],
            'cache_key'      => 'kadence_target_post_id',
        ],
    ],
];
