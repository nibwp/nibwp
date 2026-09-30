<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Skill Manifest — NibWP Design.
 *
 * Every assistant builds the same page: centred hero, three cards, purple
 * gradient, uniform padding, text at full width. The builder skills already emit
 * clean output — what nothing decided was how the site should LOOK before they
 * started, so the model fell back on its own defaults, which are everybody's.
 *
 * This decides that, and hands it over. It reads the site first (ACSS variables,
 * theme.json, the logo) and consults a catalogue only for what the site does not
 * say, so the answer is specific to the install rather than to fashion.
 *
 * It never emits markup. It has no persist ability, which makes that boundary
 * structural rather than a rule somebody remembers.
 *
 * Free, always. It has to improve the output of people who have not paid, or it
 * is not doing its job.
 */

return [
    'id'             => 'design-skills',
    'name'           => 'NibWP Design',
    'tagline'        => 'Decide how this site should look before anything gets built — so pages stop looking machine-made',
    'description'    => 'Reads what this site already has — ACSS variables, theme.json palette and type scale, the logo — and turns it into a design direction: color roles with contrast already validated to WCAG AA, a type pairing, spacing rhythm, shape language, the layout sequence that suits this kind of page, and the specific generic defaults to refuse. Where the site says nothing, it falls back to a design catalogue seeded per site, so two installs never start from the same place. The direction is remembered, so page two matches page one. Free, on by default, and it never writes markup — it tells whichever builder skill is doing the work how the work should look.',
    'vendor'         => 'NIBWP',
    'version'        => '1.0.0',
    'category'       => 'design',
    'premium'        => false,
    'features'       => [
        'Direction derived from THIS site — ACSS variables, theme.json, logo colors — not from a template',
        'Color roles computed and corrected to WCAG AA before they reach a page',
        'The anti-generic list: centred hero, three cards, purple gradient, uniform padding, full-width text — each refused with the reason why',
        'Layout sequences for 20 kinds of WordPress page, from local trade to pricing to case study',
        'Speaks your builder — Etch, Bricks, Elementor, Kadence or core blocks, with the right tokens for each',
        'Remembers the direction, so a whole site reads as designed rather than assembled',
        'Never writes markup — it directs the builder skills rather than competing with them',
        'Free, on by default, on every install',
    ],
    'ability_files'  => [
        'abilities/direction.php',
    ],
    'instructions_file' => 'SKILL.md',
    'icon'           => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="13.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="14" r="2.5"/><circle cx="8.5" cy="7.5" r="2.5"/><circle cx="6.5" cy="15" r="2.5"/><path d="M12 22a10 10 0 1 1 10-10c0 2-1.5 3-3 3h-1.5a2 2 0 0 0-1.4 3.4A2 2 0 0 1 14.5 22z"/></svg>',

    // ─── routing contract ─────────────────────────────────────────────────
    'triggers' => [
        '/(?i)\b(?:design|style|look|theme)\b[^.\n]{0,40}\b(?:page|site|section|hero|layout|landing)\b/',
        '/(?i)\b(?:build|create|make|generate)\b[^.\n]{0,40}\b(?:landing|home ?page|pricing|about|contact|portfolio)\b/',
        '/(?i)\b(?:not|don\'t|stop)\b[^.\n]{0,30}\b(?:generic|ai|template|boring|same)\b/',
        '/(?i)\b(?:brand|palette|colou?rs?|typography|fonts?)\b[^.\n]{0,30}\b(?:for|of|on)\b[^.\n]{0,20}\b(?:site|page|this)\b/',
        '/(?i)\b(?:art direction|design direction|design system|look and feel|house style)\b/',
    ],
    'commands' => [
        '/direction' => ['description' => 'Decide how a page should look on this site, before building it.'],
        '/design'    => ['description' => 'Alias for /direction.'],
        '/redesign'  => ['description' => 'Decide again, ignoring the direction this site already settled on.'],
    ],
    'mandatory_routing' => [
        'preflight_required' => false,
        'pipeline' => [
            [
                'ability'       => 'nibwp/design-direction',
                'args_template' => ['purpose' => '{what the user asked for, in their words}'],
                'why'           => 'Decide the direction from what this site already has, before any markup exists. Returns color roles, type, spacing, layout sequence and the defaults to refuse.',
            ],
            [
                'ability'       => 'nibwp/load-skill-playbook',
                'args_template' => ['skill_id' => 'design-skills'],
                'why'           => 'Read SKILL.md for how to apply a direction in this site\'s builder.',
            ],
        ],
        'forbidden_actions' => [
            'Building a page, section or component before calling nibwp/design-direction',
            'Ignoring the returned rules[] and shipping the default it names',
            'Hard-coding hex colors or px font sizes when the direction gives tokens to use',
            'clamp() for font size — use the site\'s own text tokens with a px or em fallback',
            'Centred hero over centred subhead over two centred buttons, unless the page genuinely calls for it',
            'Three equal feature cards in a row as the default way to present anything',
            'Body text running the full width of a wide container',
        ],
        'before_answering' => 'Do NOT start building and do NOT ask the user what colors they want. Call nibwp/design-direction first: it reads the site and answers both. Then build to what it returns, in the builder it names.',
    ],
];
