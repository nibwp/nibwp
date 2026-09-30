<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * NIBWP — User preferences abilities.
 *
 * Stores per-site defaults the agent should reuse across every create/build
 * call so users don't have to repeat themselves. Examples: preferred form
 * plugin, brand color palette, default ACF field types, content tone, target
 * CSS framework.
 *
 * Storage: wp_options['nibwp_user_defaults'] (autoload: false).
 *
 * The agent calls `nibwp/preferences-get` once at the start of a conversation
 * (or on a per-create-call basis) so subsequent create_* calls inherit sane
 * defaults without further prompting.
 */

add_action('wp_abilities_api_init', 'nibwp_preferences_register_abilities', 15);

function nibwp_preferences_register_abilities(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }

    if (! nibwp_has_ability('nibwp/preferences-get')) {
        wp_register_ability('nibwp/preferences-get', [
            'label'       => __('Preferences — Get', 'nibwp'),
            'description' => 'Get the user\'s stored defaults: brand color, fonts, preferred form plugin, content tone, target builder, etc. Read these BEFORE any create_* call so generated assets match the user\'s established style without re-prompting.',
            'category'    => 'nibwp',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'keys' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string'],
                        'description' => 'Optional subset of preference keys to return. Omit to return all.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'preferences' => ['type' => 'object'],
                    'known_keys'  => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ],
            'execute_callback'    => 'nibwp_preferences_get',
            'permission_callback' => 'nibwp_permission_callback',
            'meta' => [
                'show_in_rest' => true,
                'mcp'          => ['public' => true, 'type' => 'tool'],
                'annotations'  => [
                    'instructions' => 'Call once per conversation to inherit user-established defaults.',
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ],
            ],
        ]);
    }

    if (! nibwp_has_ability('nibwp/preferences-set')) {
        wp_register_ability('nibwp/preferences-set', [
            'label'       => __('Preferences — Set', 'nibwp'),
            'description' => 'Store user defaults: brand color, fonts, preferred form plugin, default field types, content tone. Persists across sessions. Merges into existing preferences — pass only the keys you want to change.',
            'category'    => 'nibwp',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'preferences' => [
                        'type' => 'object',
                        'description' => 'Key/value pairs to merge. Known keys: brand_color, brand_color_2, brand_font_heading, brand_font_body, preferred_form_plugin (fluentform|gravityforms|wpforms|cf7|ninjaforms|formidable|forminator|happyforms|jetform), content_tone (professional|friendly|playful|technical), target_builder (etchwp|elementor|bricks|gutenberg), default_acf_location (post|page|product), default_section_width (1160|1366|1440), preferred_image_size (4:3|16:9|1:1), default_button_radius_px.',
                    ],
                ],
                'required' => ['preferences'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'saved'         => ['type' => 'boolean'],
                    'merged_keys'   => ['type' => 'array', 'items' => ['type' => 'string']],
                    'preferences'   => ['type' => 'object'],
                ],
            ],
            'execute_callback'    => 'nibwp_preferences_set',
            'permission_callback' => 'nibwp_permission_callback',
            'meta' => [
                'show_in_rest' => true,
                'mcp'          => ['public' => true, 'type' => 'tool'],
                'annotations'  => [
                    'instructions' => 'Use to remember user choices so future calls don\'t need to ask again.',
                    'readonly'    => false,
                    'destructive' => false,
                    'idempotent'  => true,
                ],
            ],
        ]);
    }
}

function nibwp_preferences_get(array $input): array
{
    $all = (array) get_option('nibwp_user_defaults', []);
    $known = [
        'brand_color', 'brand_color_2',
        'brand_font_heading', 'brand_font_body',
        'preferred_form_plugin',
        'content_tone',
        'target_builder',
        'default_acf_location',
        'default_section_width',
        'preferred_image_size',
        'default_button_radius_px',
    ];
    $keys = (array) ($input['keys'] ?? []);
    if ($keys) {
        $all = array_intersect_key($all, array_flip(array_map('sanitize_key', $keys)));
    }
    return ['preferences' => $all, 'known_keys' => $known];
}

function nibwp_preferences_set(array $input): array
{
    $incoming = (array) ($input['preferences'] ?? []);
    $sanitized = [];
    foreach ($incoming as $k => $v) {
        $key = sanitize_key((string) $k);
        if ($key === '') {
            continue;
        }
        if (is_scalar($v)) {
            $sanitized[$key] = is_string($v) ? sanitize_text_field($v) : $v;
        } elseif (is_array($v)) {
            $sanitized[$key] = array_map(
                static fn ($x) => is_string($x) ? sanitize_text_field($x) : $x,
                $v,
            );
        }
    }
    $existing = (array) get_option('nibwp_user_defaults', []);
    $merged = array_merge($existing, $sanitized);
    update_option('nibwp_user_defaults', $merged, autoload: false);
    return [
        'saved'       => true,
        'merged_keys' => array_keys($sanitized),
        'preferences' => $merged,
    ];
}
