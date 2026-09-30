<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Ability: nibwp/load-skill-playbook.
 *
 * Lazy loader for skill playbooks. Replaces the old behavior where every
 * enabled skill's full SKILL.md was concatenated into the discover-abilities
 * response. Now discover returns a short skills index and agents call this
 * ability once they have decided to start a conversion.
 *
 * Returns:
 *   - skill_md         — the SKILL.md body for the requested skill
 *   - sections         — { "<rel-path>": "<file-contents>" } for each requested reference
 *   - lessons_learned  — markdown rendering of (brand, element_type) thumb-feedback
 *
 * Element types not in the core 6 (button, hero, card-grid, form, navbar,
 * footer) automatically fall back to checklists/generic.md.
 */

// Register at priority 15 (after the main require_once dispatch at prio 10)
// so this hook fires WITHIN the current wp_abilities_api_init iteration even
// when this file is first required from inside another prio-10 callback.
add_action('wp_abilities_api_init', 'nibwp_load_skill_playbook_register_ability', 15);

function nibwp_load_skill_playbook_register_ability(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }
    if (nibwp_has_ability('nibwp/load-skill-playbook')) {
        return;
    }
    wp_register_ability('nibwp/load-skill-playbook', nibwp_load_skill_playbook_ability_args());
}

function nibwp_load_skill_playbook_ability_args(): array
{
    return [
    'label'       => __('Load skill playbook on demand', 'nibwp'),
    'description' => 'Load the full SKILL.md and selected references for an installed skill (e.g. etchwp-pro). Call this once you have decided to start a conversion — keeps the discover response small. When brand + element_type are given, injects aggregated thumb-down lessons-learned for that pair.',
    'category'    => 'nibwp',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'skill_id' => [
                'type' => 'string',
                'description' => 'The skill id (e.g. "etchwp-pro"). Must be discoverable + enabled + unlocked.',
            ],
            'sections' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Optional list of reference paths to inline, relative to the playbook\'s own `references/` dir. Examples: ["acss-tokens","anti-patterns","checklists/hero"]. The "references/" prefix and ".md" suffix are optional; names that match no file come back in unknown_sections.',
            ],
            'element_type' => [
                'type' => 'string',
                'description' => 'If given, inlines references/checklists/{element_type}.md (or generic.md as fallback) and the (brand, element_type) thumb-down lessons-learned.',
            ],
            'brand' => [
                'type' => 'string',
                'description' => 'Brand slug used to look up lessons-learned.',
            ],
        ],
        'required' => ['skill_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'skill_md'           => ['type' => 'string'],
            'sections'           => ['type' => 'object'],
            'lessons_learned'    => ['type' => 'string'],
            'unknown_sections'   => ['type' => 'array', 'items' => ['type' => 'string']],
            'available_sections' => ['type' => 'array', 'items' => ['type' => 'string']],
            'site_tokens'        => ['type' => 'object', 'description' => 'Automatic.css token names this site defines, grouped by family. Present when ACSS is active.'],
        ],
    ],
    'execute_callback'    => 'nibwp_load_skill_playbook',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => [
            'instructions' => "Call before starting a conversion. Pass skill_id and (when known) brand + element_type. Use sections[] to pull additional reference files (anti-patterns, acss-tokens, json-schema, examples, etc.) only as needed.\nWhen Automatic.css is active the response carries site_tokens: the token names this site defines. Write var() names from that list; the validator flags any name the site does not define.",
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
    ];
}

function nibwp_load_skill_playbook(array $input): array|WP_Error
{
    $skill_id = sanitize_key((string) ($input['skill_id'] ?? ''));
    if ($skill_id === '') {
        return new WP_Error('missing_skill_id', 'skill_id is required.');
    }
    if (!function_exists('nibwp_skill_get')) {
        return new WP_Error('skills_unavailable', 'Skills registry is not loaded.');
    }

    $skill = nibwp_skill_get($skill_id);
    if (!$skill) {
        return new WP_Error('skill_not_found', sprintf('Skill "%s" not found.', $skill_id));
    }
    if (function_exists('nibwp_skill_gate')) {
        $gate = nibwp_skill_gate($skill_id);
        if (is_wp_error($gate)) {
            return $gate;
        }
    }

    $base = rtrim((string) $skill['path'], '/\\') . DIRECTORY_SEPARATOR;

    // Where the playbook lives is the manifest's business, not this loader's.
    // A skill's folder name used to be hard-coded here, so a skill that
    // organised itself differently loaded its SKILL.md through the fallback
    // and then looked for references in the wrong directory — the playbook
    // arrived with none of its checklists inlined, and nothing said so.
    // Deriving the references directory from wherever SKILL.md actually
    // resolved keeps the two together whatever the layout.
    $skill_md_path = $base . ltrim((string) ($skill['instructions_file'] ?? 'SKILL.md'), '/\\');
    if (!file_exists($skill_md_path)) {
        $skill_md_path = $base . 'authoring' . DIRECTORY_SEPARATOR . 'SKILL.md';
    }
    if (!file_exists($skill_md_path)) {
        $skill_md_path = $base . 'SKILL.md';
    }
    $references_dir = dirname($skill_md_path) . DIRECTORY_SEPARATOR . 'references' . DIRECTORY_SEPARATOR;

    $skill_md = file_exists($skill_md_path) ? (string) file_get_contents($skill_md_path) : '';

    // Inline requested sections.
    $sections_requested = (array) ($input['sections'] ?? []);
    $element_type = sanitize_key((string) ($input['element_type'] ?? ''));
    if ($element_type !== '') {
        // Always include the matching checklist (or generic.md fallback).
        $checklist_slug = 'checklists/' . $element_type;
        if (!in_array($checklist_slug, $sections_requested, true)) {
            $sections_requested[] = $checklist_slug;
        }
    }

    $core_checklists = ['button', 'hero', 'card-grid', 'form', 'navbar', 'footer', 'generic'];

    $sections_out = [];
    $unknown_sections = [];
    foreach ($sections_requested as $sect) {
        $sect = trim((string) $sect, '/\\ ');
        // SKILL.md's reference index links these as "references/json-schema.md",
        // so that is the form an agent tries first. It used to match nothing
        // and was dropped without a word.
        $sect = (string) preg_replace('/\.md$/i', '', (string) preg_replace('#^references/#i', '', $sect));
        if ($sect === '' || str_contains($sect, '..')) {
            continue; // Path traversal guard.
        }
        $candidate = $references_dir . str_replace('/', DIRECTORY_SEPARATOR, $sect) . '.md';
        // Fallback: checklists/<unknown-type>.md → checklists/generic.md
        if (!file_exists($candidate) && str_starts_with($sect, 'checklists/')) {
            $type = substr($sect, strlen('checklists/'));
            if (!in_array($type, $core_checklists, true)) {
                $candidate = $references_dir . 'checklists' . DIRECTORY_SEPARATOR . 'generic.md';
            }
        }
        if (file_exists($candidate)) {
            $sections_out[$sect] = (string) file_get_contents($candidate);
        } else {
            $unknown_sections[] = $sect;
        }
    }

    // Name what does exist when something did not, so the next call is right.
    $available_sections = [];
    if ($unknown_sections !== []) {
        foreach (array_merge(glob($references_dir . '*.md') ?: [], glob($references_dir . 'checklists' . DIRECTORY_SEPARATOR . '*.md') ?: []) as $file) {
            $available_sections[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($references_dir), -3));
        }
    }

    // The token names this site actually defines, so a payload is written with
    // real names the first time instead of learning them one rejection at a time.
    $site_tokens = [];
    $acss_on = defined('ACSS_PLUGIN_FILE') || defined('ACSS_VERSION') || class_exists('\\Automatic_CSS\\Plugin');
    if ($acss_on && function_exists('nibwp_acss_site_tokens')) {
        foreach (nibwp_acss_site_tokens() as $name => $value) {
            // Colour channels and generated alpha variants are noise here.
            if (preg_match('/-(hex|hsl|rgb)$|-trans-\d0$/', $name) || preg_match('/^\d+(\.\d+)?%?$/', trim((string) $value))) {
                continue;
            }
            $site_tokens[(string) preg_replace('/^--([a-z]+).*$/', '$1', $name)][] = $name;
        }
        $site_tokens = array_map(static fn(array $names): string => implode(' ', $names), $site_tokens);
    }

    // Render lessons-learned for (brand, element_type).
    $brand = sanitize_key((string) ($input['brand'] ?? ''));
    $lessons_learned = '';
    if ($brand !== '' && $element_type !== '') {
        $lessons_learned = nibwp_etchwp_render_lessons_learned($brand, $element_type, $skill_id);
        // Substitute {{INJECTED_FEEDBACK}} placeholders in the loaded checklists.
        foreach ($sections_out as $slug => $body) {
            $sections_out[$slug] = str_replace('{{INJECTED_FEEDBACK}}', $lessons_learned !== '' ? $lessons_learned : 'No prior feedback recorded.', $body);
        }
    } else {
        foreach ($sections_out as $slug => $body) {
            $sections_out[$slug] = str_replace('{{INJECTED_FEEDBACK}}', 'No prior feedback recorded.', $body);
        }
    }

    return [
        'skill_md'        => $skill_md,
        'sections'           => $sections_out,
        'lessons_learned'    => $lessons_learned,
        'unknown_sections'   => $unknown_sections,
        'available_sections' => $available_sections,
        'site_tokens'        => $site_tokens,
    ];
}

/**
 * Render the (brand, element_type) thumb-feedback block as markdown.
 *
 * Each skill keeps its own feedback option, so pass the skill id to read the
 * right one. Without it — or when a skill has no option of its own — this
 * falls back to the EtchWP store, which is where this started.
 */
function nibwp_etchwp_render_lessons_learned(string $brand, string $element_type, string $skill_id = ''): string
{
    $option = 'nibwp_etchwp_feedback';
    if ($skill_id !== '') {
        $own = 'nibwp_' . str_replace('-', '_', sanitize_key($skill_id)) . '_feedback';
        if (get_option($own, null) !== null) {
            $option = $own;
        }
    }
    $all = (array) get_option($option, []);
    $key = $brand . '::' . $element_type;
    $row = $all[$key] ?? null;
    if (!is_array($row) || empty($row['recent_down_reasons'])) {
        return '';
    }

    $lines = [];
    $lines[] = sprintf('## Lessons learned for %s::%s', $brand, $element_type);
    $lines[] = '';
    $lines[] = sprintf('Aggregated: 👍 %d, 👎 %d.', (int) ($row['up'] ?? 0), (int) ($row['down'] ?? 0));
    $lines[] = '';
    $lines[] = 'Recent thumb-down reasons (latest first):';
    $lines[] = '';
    foreach ((array) $row['recent_down_reasons'] as $entry) {
        $ts     = (int) ($entry['ts'] ?? 0);
        $reason = (string) ($entry['reason'] ?? '');
        $cid    = (string) ($entry['component_id'] ?? '');
        $date   = $ts > 0 ? gmdate('Y-m-d', $ts) : 'unknown';
        $short_cid = $cid !== '' ? substr($cid, 0, 7) : '';
        $lines[] = sprintf('- %s — "%s"%s', $date, $reason, $short_cid !== '' ? ' (component: ' . $short_cid . ')' : '');
    }
    return implode("\n", $lines);
}
