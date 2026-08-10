<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Plain PHP helpers around the single nibwp_user_defaults option store.
 *
 * Single source of truth for user-scoped preferences (brand color, fonts,
 * preferred form plugin, ACSS choices, skill preflight answers, etc.).
 *
 * The MCP-callable nibwp/preferences-get + nibwp/preferences-set abilities
 * (includes/abilities/preferences.php) delegate to these helpers; new
 * code paths (skill-preflight, validators) call them DIRECTLY rather than
 * routing through the ability layer — that lets them read/write without
 * needing the MCP execute_callback signature.
 *
 * Storage:
 *   wp_options['nibwp_user_defaults'] = array<string,scalar|array<scalar>>
 *   autoload: false
 *
 * Key convention: kebab/snake-case lowercase, scoped where meaningful:
 *   - brand_color, brand_color_2, brand_font_heading, brand_font_body
 *   - preferred_form_plugin, target_builder, content_tone
 *   - etchwp_brand, etchwp_brand_color, etchwp_brand_color_2, etchwp_push_mode
 *   - acss_palette_decision, acss_target_setting_group, acss_brand_seed
 */

if (!defined('NIBWP_USER_DEFAULTS_OPTION')) {
    define('NIBWP_USER_DEFAULTS_OPTION', 'nibwp_user_defaults');
}

/**
 * Read every stored default. Returned as an associative array.
 *
 * @return array<string,mixed>
 */
function nibwp_user_defaults_all(): array
{
    return (array) get_option(NIBWP_USER_DEFAULTS_OPTION, []);
}

/**
 * Read a single default. Returns $default when the key is unset or empty.
 *
 * @param string|int|float|bool|null $default
 * @return mixed
 */
function nibwp_user_default_get(string $key, $default = null)
{
    $all = nibwp_user_defaults_all();
    $key = sanitize_key($key);
    if ($key === '' || !array_key_exists($key, $all)) {
        return $default;
    }
    $val = $all[$key];
    if ($val === '' || $val === [] || $val === null) {
        return $default;
    }
    return $val;
}

/**
 * Merge an associative array of defaults into the store.
 *
 * Keys are sanitised (sanitize_key); string values are sanitised
 * (sanitize_text_field); arrays are sanitised per-element. Non-scalar /
 * non-array values are dropped.
 *
 * @param array<string,mixed> $kv
 * @return array<string,mixed> The full merged set after the write.
 */
function nibwp_user_default_set(array $kv): array
{
    $sanitised = [];
    foreach ($kv as $k => $v) {
        $key = sanitize_key((string) $k);
        if ($key === '') {
            continue;
        }
        if (is_scalar($v) || $v === null) {
            $sanitised[$key] = is_string($v) ? sanitize_text_field($v) : $v;
        } elseif (is_array($v)) {
            $sanitised[$key] = array_map(
                static fn ($x) => is_string($x) ? sanitize_text_field($x) : $x,
                $v
            );
        }
    }
    if ($sanitised === []) {
        return nibwp_user_defaults_all();
    }
    $merged = array_merge(nibwp_user_defaults_all(), $sanitised);
    update_option(NIBWP_USER_DEFAULTS_OPTION, $merged, false);
    return $merged;
}

/**
 * Delete one or more keys from the store. Pass a single key or an array.
 *
 * @param string|array<int,string> $keys
 */
function nibwp_user_default_delete($keys): void
{
    $list = is_array($keys) ? $keys : [$keys];
    $all  = nibwp_user_defaults_all();
    foreach ($list as $k) {
        $key = sanitize_key((string) $k);
        if ($key !== '' && array_key_exists($key, $all)) {
            unset($all[$key]);
        }
    }
    update_option(NIBWP_USER_DEFAULTS_OPTION, $all, false);
}
