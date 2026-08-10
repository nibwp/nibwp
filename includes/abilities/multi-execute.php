<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * NIBWP — Multi-execute (batch).
 *
 * Run N abilities in one HTTP round-trip. Drastically cuts agent latency for
 * "create a page with hero + 3 cards + form" style prompts.
 *
 * Steps run sequentially by default. Each step's output is available to
 * subsequent steps under `$prev[step_index]` via the `{{prev.N.path}}`
 * placeholder substitution.
 *
 * Limits:
 *   - max 25 steps per batch
 *   - stops at first failure unless `continue_on_error` is true
 *   - total exec time soft-capped at 30s (each ability still subject to its
 *     own permission_callback + gating)
 */

add_action('wp_abilities_api_init', 'nibwp_multi_execute_register_ability', 15);

function nibwp_multi_execute_register_ability(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }
    if (nibwp_has_ability('nibwp/multi-execute')) {
        return;
    }
    wp_register_ability('nibwp/multi-execute', [
        'label'       => __('Multi-Execute (batch abilities)', 'nibwp'),
        'description' => __('Run up to 25 NIBWP abilities in one round-trip. Each step is { ability, parameters }. Pass `continue_on_error: true` to keep going past failures. Use `{{prev.N.path.to.value}}` in any parameter string to reference earlier step output. Returns per-step results array.', 'nibwp'),
        'category'    => 'nibwp',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'steps' => [
                    'type' => 'array',
                    'maxItems' => 25,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'ability'    => ['type' => 'string'],
                            'parameters' => ['type' => 'object'],
                            'label'      => ['type' => 'string'],
                        ],
                        'required' => ['ability'],
                    ],
                ],
                'continue_on_error' => ['type' => 'boolean', 'default' => false],
            ],
            'required' => ['steps'],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'success' => ['type' => 'boolean'],
                'results' => ['type' => 'array'],
                'failed_at' => ['type' => 'integer'],
            ],
        ],
        'execute_callback'    => 'nibwp_multi_execute',
        'permission_callback' => 'nibwp_permission_callback',
        'meta' => [
            'show_in_rest' => true,
            'mcp'          => ['public' => true, 'type' => 'tool'],
            'annotations'  => [
                'instructions' => 'Use to combine multiple abilities in one call. Example: create ACF group + create post + attach featured image, in one round-trip.',
                'readonly'    => false,
                'destructive' => false,
                'idempotent'  => false,
            ],
        ],
    ]);
}

function nibwp_multi_execute(array $input): array|WP_Error
{
    $steps = (array) ($input['steps'] ?? []);
    $continue = !empty($input['continue_on_error']);
    if (count($steps) === 0) {
        return new WP_Error('no_steps', 'steps[] is required.');
    }
    if (count($steps) > 25) {
        return new WP_Error('too_many_steps', 'Max 25 steps per batch.');
    }

    $results = [];
    $start = microtime(true);
    foreach ($steps as $i => $step) {
        if ((microtime(true) - $start) > 30.0) {
            $results[$i] = ['ok' => false, 'error' => 'timeout', 'ability' => $step['ability'] ?? '?'];
            if (!$continue) {
                return ['success' => false, 'results' => $results, 'failed_at' => $i];
            }
            continue;
        }
        $name = (string) ($step['ability'] ?? '');
        $params = (array) ($step['parameters'] ?? []);
        $params = nibwp_multi_execute_resolve_refs($params, $results);

        $ability = nibwp_has_ability($name) ? wp_get_ability($name) : null;
        if ($ability === null) {
            $results[$i] = ['ok' => false, 'error' => 'ability_not_found', 'ability' => $name];
            if (!$continue) {
                return ['success' => false, 'results' => $results, 'failed_at' => $i];
            }
            continue;
        }

        try {
            $out = $ability->execute($params);
        } catch (Throwable $e) {
            $results[$i] = ['ok' => false, 'error' => $e->getMessage(), 'ability' => $name];
            if (!$continue) {
                return ['success' => false, 'results' => $results, 'failed_at' => $i];
            }
            continue;
        }

        if (is_wp_error($out)) {
            $results[$i] = [
                'ok' => false,
                'error' => $out->get_error_message(),
                'code' => $out->get_error_code(),
                'ability' => $name,
            ];
            if (!$continue) {
                return ['success' => false, 'results' => $results, 'failed_at' => $i];
            }
            continue;
        }

        $results[$i] = ['ok' => true, 'ability' => $name, 'output' => $out, 'label' => $step['label'] ?? null];
    }

    return ['success' => true, 'results' => $results, 'failed_at' => -1];
}

/**
 * Walk $params recursively and replace `{{prev.N.path}}` placeholders with
 * values from earlier step outputs.
 */
function nibwp_multi_execute_resolve_refs(mixed $value, array $results): mixed
{
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = nibwp_multi_execute_resolve_refs($v, $results);
        }
        return $out;
    }
    if (!is_string($value) || !str_contains($value, '{{prev.')) {
        return $value;
    }
    return preg_replace_callback(
        '/\{\{prev\.(\d+)((?:\.[a-zA-Z0-9_\-]+)*)\}\}/',
        static function ($m) use ($results) {
            $idx = (int) $m[1];
            if (!isset($results[$idx]['output'])) {
                return '';
            }
            $val = $results[$idx]['output'];
            $segs = array_filter(explode('.', trim($m[2], '.')));
            foreach ($segs as $s) {
                if (is_array($val) && array_key_exists($s, $val)) {
                    $val = $val[$s];
                } else {
                    return '';
                }
            }
            return is_scalar($val) ? (string) $val : wp_json_encode($val);
        },
        $value,
    );
}
