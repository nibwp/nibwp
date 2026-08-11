<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Skill Manifest — ACSS Pro (Automatic.css configuration generator).
 *
 * Converts a screenshot / HTML+CSS / target-URL into a working ACSS config:
 *   - colors (primary/secondary + light/dark variants + neutral ramp)
 *   - type ramp (heading family + body family + modular scale)
 *   - space ramp (consistent ratio)
 *   - radius / shadows / breakpoints
 *
 * Entry-tier skill: cheapest premium price (€19/1, €39/5, €49/25, €69/100,
 * LTD 39/89/129/179). Pairs with EtchWP Pro so var(--text-*) / var(--space-*)
 * tokens in generated EtchWP components actually resolve.
 */

return [
    'id'             => 'acss-pro',
    'name'           => 'ACSS Pro',
    'tagline'        => 'Generate a working ACSS configuration from a screenshot, HTML+CSS, or live URL',
    'description'    => 'Feed in a design source (screenshot, HTML+CSS, or a live URL) and the agent proposes a complete ACSS configuration — palette, type ramp, space ramp, radius, shadows, breakpoints — validated for WCAG contrast, sane modular scale ratios, and consistent neutral-ramp luminance. Persists to the ACSS settings option after explicit user approval. Pairs with EtchWP Pro: tokens generated here are exactly the ones EtchWP Pro emits in components.',
    'vendor'         => 'NIBWP',
    'version'        => '1.0.1',
    'category'       => 'design-system',
    'premium'        => true,
    'price'          => 19,
    'required_plans' => ['pro', 'agency', 'lifetime'],
    'requires'       => ['automaticcss'],
    'entitlements'   => ['skill:acss', 'skill:acss-pro', 'integration:acss', 'integration:automaticcss'],
    'integration_files' => ['automaticcss'],
    'features'       => [
        'Generate ACSS palette + type ramp + space ramp from a screenshot',
        'Generate from raw HTML+CSS or a live target URL',
        'WCAG AA contrast validation on heading/body pairs',
        'Modular scale ratio sanity (1.067–1.618 only)',
        'Neutral ramp luminance gap enforcement (light/dark ≥ 80%)',
        'Brand color delta-E check to prevent indistinguishable palettes',
        'Audit existing rendered CSS for drift from ACSS tokens',
        'Persist to ACSS settings option (with explicit user approval)',
    ],
    'ability_files' => [
        'abilities/detect.php',
        'abilities/from-design.php',
        'abilities/validate-config.php',
        'abilities/update-variables.php',
        'abilities/audit-site.php',
    ],
    'instructions_file' => 'authoring/SKILL.md',
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>',

    'triggers' => [
        '/(?i)\b(?:generate|create|build|extract|derive)\b[^.\n]{0,40}\b(?:acss|automatic\.?css|design\s*system|design\s*tokens|palette)\b/',
        '/(?i)\b(?:acss|automatic\.?css)\b[^.\n]{0,30}\b(?:config|configuration|palette|tokens|setup)\b/',
        '/(?i)\b(?:screenshot|image|html|url|figma)\b[^.\n]{0,30}\b(?:to|into|as)\b[^.\n]{0,20}\b(?:acss|tokens|design\s*system|palette)\b/',
    ],
    'commands' => [
        '/acss-from-design'  => ['description' => 'Generate ACSS config from a screenshot, HTML+CSS, or live URL.'],
        '/acss-detect'       => ['description' => 'Probe current ACSS install + version + settings.'],
        '/acss-audit'        => ['description' => 'Audit a rendered URL against the current ACSS tokens and report drift.'],
        '/acss-preflight'    => ['description' => 'Re-run the ACSS preflight (target settings group, palette decision).'],
    ],
    'mandatory_routing' => [
        'preflight_required' => true,
        'preflight_ability'  => 'nibwp/skill-preflight',
        'pipeline' => [
            [
                'ability'       => 'nibwp/skill-preflight',
                'args_template' => ['skill_id' => 'acss-pro'],
                'why'           => 'Resolve ACSS install + settings group + brand seed + palette decision.',
            ],
            [
                'ability'       => 'nibwp/load-skill-playbook',
                'args_template' => ['skill_id' => 'acss-pro'],
                'why'           => 'Read SKILL.md + token taxonomy + validator rules.',
            ],
            [
                'ability'       => 'nibwp/acss-pro-from-design',
                'args_template' => ['source' => '{user_input}', '_preflight_token' => '{from_preflight.token}'],
                'why'           => 'Agent extracts palette + scales from the source; server validates contrast/ratios/luminance.',
            ],
            [
                'ability'       => 'nibwp/acss-pro-update-variables',
                'args_template' => ['_preflight_token' => '{from_preflight.token}', 'config' => '{from_previous}'],
                'why'           => 'Persist the validated config to the ACSS settings option after user approval.',
            ],
        ],
        'forbidden_actions' => [
            'Persist a palette without running validate-config first',
            'Submit a contrast-failing heading-on-background pair',
            'Use a modular scale ratio outside [1.067, 1.618]',
            'Skip the user-approval step before update-variables',
        ],
        'before_answering' => 'Do NOT improvise palette/scale values. Run preflight → load-skill-playbook → from-design → user-approval → update-variables. The validator hard-rejects bad ratios/contrast/luminance.',
    ],
    'preflight_questions' => [
        [
            'key'        => 'target_settings_group',
            'prompt'     => 'Which ACSS settings group? (palette / typography / space / radius / all)',
            'choices'    => ['palette', 'typography', 'space', 'radius', 'all'],
            'type'       => 'enum',
            'required'   => true,
            'cache_key'  => 'acss_target_settings_group',
        ],
        [
            'key'        => 'palette_decision',
            'prompt'     => 'Preserve existing ACSS palette or overwrite with extracted values?',
            'choices'    => ['preserve_keep_existing', 'overwrite_with_extracted', 'merge_only_new_keys'],
            'type'       => 'enum',
            'required'   => true,
            'cache_key'  => 'acss_palette_decision',
        ],
        [
            'key'        => 'brand_seed',
            'prompt'     => 'Optional brand color seed (#hex) to anchor the palette extraction.',
            'type'       => 'string',
            'cache_key'  => 'acss_brand_seed',
        ],
    ],
];
