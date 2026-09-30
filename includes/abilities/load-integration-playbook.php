<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * NIBWP — Integration playbook loader.
 *
 * Mirrors `nibwp/load-skill-playbook` but for premium integrations. Each
 * integration exposes a tight playbook that tells the agent which actions
 * exist, their preferred params, common patterns, and the per-site feedback
 * loop's recent thumb-down reasons.
 *
 * Lookup order for the playbook body:
 *   1. includes/premium/integrations/playbooks/<integration>.md  (canonical)
 *   2. inline-default built from the integration's ability descriptions      (fallback)
 *
 * Feedback storage: `nibwp_integration_feedback` option, shape
 *   "<integration>" => { up:int, down:int, recent_down_reasons:[…] (cap 10) }
 */

add_action('wp_abilities_api_init', 'nibwp_load_integration_playbook_register', 15);

function nibwp_load_integration_playbook_register(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }
    if (! nibwp_has_ability('nibwp/load-integration-playbook')) {
        wp_register_ability('nibwp/load-integration-playbook', [
            'label'       => __('Load integration playbook on demand', 'nibwp'),
            'description' => 'Read the curated playbook for an integration (forms, acf, etchwp, automaticcss, elementor, bricks, fluentcrm, fluentcart, fluentaffiliate, directorist, seo, …). Returns: available abilities, common actions, preferred parameter shapes, and aggregated thumb-down lessons-learned for this site. Call BEFORE invoking unfamiliar integration abilities.',
            'category'    => 'nibwp',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'integration' => ['type' => 'string', 'description' => 'Integration key, e.g. forms, acf, etchwp, fluentcrm.'],
                ],
                'required' => ['integration'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'integration'     => ['type' => 'string'],
                    'playbook_md'     => ['type' => 'string'],
                    'abilities'       => ['type' => 'array'],
                    'lessons_learned' => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => 'nibwp_load_integration_playbook',
            'permission_callback' => 'nibwp_permission_callback',
            'meta' => [
                'show_in_rest' => true,
                'mcp'          => ['public' => true, 'type' => 'tool'],
                'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
        ]);
    }
    if (! nibwp_has_ability('nibwp/integration-feedback')) {
        wp_register_ability('nibwp/integration-feedback', [
            'label'       => __('Integration — Record feedback', 'nibwp'),
            'description' => 'Record 👍 / 👎 on the most recent integration operation. Thumb-down reasons feed into the next load-integration-playbook call so future runs learn from prior misses.',
            'category'    => 'nibwp',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'integration' => ['type' => 'string'],
                    'rating'      => ['enum' => ['up', 'down']],
                    'reason'      => ['type' => 'string'],
                ],
                'required' => ['integration', 'rating'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'recorded'   => ['type' => 'boolean'],
                    'aggregated' => ['type' => 'object'],
                ],
            ],
            'execute_callback'    => 'nibwp_integration_record_feedback',
            'permission_callback' => 'nibwp_permission_callback',
            'meta' => [
                'show_in_rest' => true,
                'mcp'          => ['public' => true, 'type' => 'tool'],
                'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
            ],
        ]);
    }
}

function nibwp_load_integration_playbook(array $input): array|WP_Error
{
    $key = sanitize_key((string) ($input['integration'] ?? ''));
    if ($key === '') {
        return new WP_Error('missing_integration', 'integration is required.');
    }

    // Discover abilities tied to this integration. Heuristic: names starting
    // with `nibwp/<integration>-` OR the integration's own ability name itself.
    $abilities = [];
    foreach (wp_get_abilities() as $a) {
        $n = $a->get_name();
        if (str_starts_with($n, 'nibwp/' . $key . '-') || $n === 'nibwp/' . $key) {
            $abilities[] = [
                'name'        => $n,
                'label'       => $a->get_label(),
                'description' => $a->get_description(),
            ];
        }
    }

    // Playbook body — file first, inline-default fallback.
    $candidates = [
        WP_PLUGIN_DIR . '/nibwp/includes/premium/integrations/playbooks/' . $key . '.md',
        WP_PLUGIN_DIR . '/nibwp/includes/abilities/playbooks/' . $key . '.md',
    ];
    $body = '';
    foreach ($candidates as $c) {
        if (file_exists($c)) {
            $body = (string) file_get_contents($c);
            break;
        }
    }
    if ($body === '') {
        $body = nibwp_integration_default_playbook($key, $abilities);
    }

    // Aggregated feedback.
    $lessons = nibwp_integration_render_lessons($key);
    $body = str_replace('{{INJECTED_FEEDBACK}}', $lessons !== '' ? $lessons : 'No prior feedback recorded.', $body);

    return [
        'integration'     => $key,
        'playbook_md'     => $body,
        'abilities'       => $abilities,
        'lessons_learned' => $lessons,
    ];
}

function nibwp_integration_default_playbook(string $key, array $abilities): string
{
    $out  = "# {$key} — playbook\n\n";
    $out .= "Curated guidance for the `{$key}` integration. Call any of the abilities listed below.\n\n";
    if ($abilities === []) {
        $out .= "**No abilities registered.** Either the host plugin isn't active or the integration is locked. Check `nibwp_is_integration_available('{$key}')`.\n\n";
    } else {
        $out .= "## Abilities\n\n";
        foreach ($abilities as $a) {
            $out .= sprintf("- **`%s`** — %s\n", $a['name'], $a['description']);
        }
        $out .= "\n";
    }
    $out .= "## Defaults the agent should respect\n\n"
          . "- Read `nibwp/preferences-get` once per conversation — apply brand color, fonts, preferred form plugin, etc.\n"
          . "- Use `nibwp/templates-apply` if a matching starter template exists — saves manual schema building.\n"
          . "- Batch multiple steps via `nibwp/multi-execute` to avoid round-trip latency.\n\n"
          . "## Lessons learned (auto-injected)\n\n"
          . '{{INJECTED_FEEDBACK}}'
          . "\n";
    return $out;
}

function nibwp_integration_render_lessons(string $key): string
{
    $all = (array) get_option('nibwp_integration_feedback', []);
    $row = $all[$key] ?? null;
    if (!is_array($row) || empty($row['recent_down_reasons'])) {
        return '';
    }
    $lines = [];
    $lines[] = sprintf('Aggregated: 👍 %d, 👎 %d.', (int) ($row['up'] ?? 0), (int) ($row['down'] ?? 0));
    $lines[] = '';
    $lines[] = 'Recent thumb-down reasons (latest first):';
    foreach ((array) $row['recent_down_reasons'] as $entry) {
        $ts = (int) ($entry['ts'] ?? 0);
        $date = $ts > 0 ? gmdate('Y-m-d', $ts) : 'unknown';
        $lines[] = sprintf('- %s — "%s"', $date, (string) ($entry['reason'] ?? ''));
    }
    return implode("\n", $lines);
}

function nibwp_integration_record_feedback(array $in): array|WP_Error
{
    $key = sanitize_key((string) ($in['integration'] ?? ''));
    $rating = (string) ($in['rating'] ?? '');
    if (!in_array($rating, ['up', 'down'], true) || $key === '') {
        return new WP_Error('invalid', 'integration + rating required.');
    }
    $all = (array) get_option('nibwp_integration_feedback', []);
    $row = (array) ($all[$key] ?? ['up' => 0, 'down' => 0, 'recent_down_reasons' => []]);
    if ($rating === 'up') {
        $row['up'] = (int) ($row['up'] ?? 0) + 1;
    } else {
        $row['down'] = (int) ($row['down'] ?? 0) + 1;
        $reasons = (array) ($row['recent_down_reasons'] ?? []);
        array_unshift($reasons, [
            'ts' => time(),
            'reason' => sanitize_text_field((string) ($in['reason'] ?? '')),
        ]);
        $row['recent_down_reasons'] = array_slice($reasons, 0, 10);
    }
    $all[$key] = $row;
    update_option('nibwp_integration_feedback', $all, autoload: false);
    return ['recorded' => true, 'aggregated' => $row];
}
