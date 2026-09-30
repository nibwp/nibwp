<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Which design system this site actually runs, and its token vocabulary.
 *
 * Converters kept emitting `#e0481d` and `font-size: 13.5px` onto sites with a
 * fully configured token layer sitting unused, because nothing ever asked what
 * that layer was. Every conversion begins here now: one call, one normalised
 * answer, whatever the system underneath.
 *
 * Normalised on purpose. A converter should never learn ACSS's key names, or
 * Core Framework's, or theme.json's shape — that knowledge belongs in one file,
 * and this is it.
 */

/**
 * The systems we can read, in the order they win.
 *
 * Configured beats merely active: ACSS installed with defaults untouched is not
 * a source of truth worth snapping a design to, and treating it as one produces
 * a build tokenised against values nobody chose.
 *
 * @return array<int, string>
 */
function nibwp_design_systems(): array
{
    return ['acss', 'core-framework', 'theme-json'];
}

/**
 * What is active and configured here, with its tokens.
 *
 * @return array{system: string, configured: bool, candidates: array<int, string>, base_font_size: float, tokens: array<string, array<string, string>>}
 */
function nibwp_design_system_detect(): array
{
    $empty = ['colors' => [], 'type_scale' => [], 'spacing' => [], 'radius' => [], 'shadow' => []];

    $candidates = [];
    foreach (nibwp_design_systems() as $system) {
        if (nibwp_design_system_is_active($system)) {
            $candidates[] = $system;
        }
    }

    // First active system that is also configured. Falling through to an active
    // but unconfigured one would be worse than reporting none: it looks like a
    // vocabulary and is really just defaults.
    foreach ($candidates as $system) {
        $tokens = nibwp_design_system_tokens($system);
        if (nibwp_design_tokens_are_populated($tokens)) {
            return [
                'system'         => $system,
                'configured'     => true,
                'candidates'     => $candidates,
                'base_font_size' => nibwp_design_base_font_size(),
                'tokens'         => $tokens + $empty,
            ];
        }
    }

    return [
        'system'         => $candidates[0] ?? 'none',
        'configured'     => false,
        'candidates'     => $candidates,
        'base_font_size' => nibwp_design_base_font_size(),
        'tokens'         => $empty,
    ];
}

function nibwp_design_system_is_active(string $system): bool
{
    return match ($system) {
        'acss'           => function_exists('nibwp_acss_is_active') && nibwp_acss_is_active(),
        'core-framework' => defined('CORE_FRAMEWORK_VERSION') || is_array(get_option('core_framework_settings', null)),
        'theme-json'     => class_exists('WP_Theme_JSON_Resolver'),
        default          => false,
    };
}

/**
 * A system's tokens, in this plugin's shape rather than the system's own.
 *
 * @return array<string, array<string, string>>
 */
function nibwp_design_system_tokens(string $system): array
{
    return match ($system) {
        'acss'           => nibwp_design_tokens_from_acss(),
        'core-framework' => nibwp_design_tokens_from_core_framework(),
        'theme-json'     => nibwp_design_tokens_from_theme_json(),
        default          => [],
    };
}

function nibwp_design_tokens_are_populated(array $tokens): bool
{
    foreach (['colors', 'type_scale'] as $key) {
        if (!empty($tokens[$key])) {
            return true;
        }
    }

    return false;
}

/**
 * ACSS, read through the resolver — never through get_option() here.
 *
 * ACSS has stored its settings under three different option names across
 * versions, and every place that hard-coded one of them has been a bug. The
 * resolver owns that list.
 */
function nibwp_design_tokens_from_acss(): array
{
    if (!function_exists('nibwp_acss_read_settings')) {
        return [];
    }

    $raw = nibwp_acss_read_settings();
    if ($raw === []) {
        return [];
    }

    $out = ['colors' => [], 'type_scale' => [], 'spacing' => [], 'radius' => [], 'shadow' => []];

    foreach ($raw as $key => $value) {
        if (!is_scalar($value)) {
            continue;
        }
        $key   = (string) $key;
        $value = trim((string) $value);
        if ($value === '') {
            continue;
        }

        // ACSS keeps its colour channels split (-h-oklch, -s, -l) and stores
        // them as bare numbers, so the colour test already excludes them. No
        // key-name rule here: one that never changes an outcome is a rule
        // nobody can test, and it reads as protection that is not there.
        if (nibwp_design_looks_like_color($value)) {
            $out['colors']['--' . $key] = $value;
            continue;
        }

        // ACSS 4.x has no resolved `text-l` key. The scale is authored as
        // fluid `text-l-min` / `text-l-max` pairs, either of which may be
        // blank to mean "use the computed default". The max is the size a
        // desktop design was drawn at, so that is what a design value should
        // be snapped against; min covers the pair where only it is set.
        if (preg_match('/^text-(xs|s|m|l|xl|xxl|xxxl|\d+)-(min|max)$/', $key, $step)) {
            $name = '--text-' . $step[1];
            if ($step[2] === 'max' || !isset($out['type_scale'][$name])) {
                $out['type_scale'][$name] = $value;
            }
            continue;
        }
        if (str_starts_with($key, 'space-')) {
            $out['spacing']['--' . $key] = $value;
            continue;
        }
        if (str_starts_with($key, 'radius')) {
            $out['radius']['--' . $key] = $value;
            continue;
        }
        if (preg_match('/^(box-)?shadow/', $key)) {
            $out['shadow']['--' . $key] = $value;
        }
    }

    return $out;
}

function nibwp_design_tokens_from_core_framework(): array
{
    $raw = get_option('core_framework_settings', []);
    if (!is_array($raw) || $raw === []) {
        return [];
    }

    $out = ['colors' => [], 'type_scale' => [], 'spacing' => [], 'radius' => [], 'shadow' => []];

    foreach ($raw as $key => $value) {
        if (!is_scalar($value)) {
            continue;
        }
        $value = trim((string) $value);
        if ($value !== '' && nibwp_design_looks_like_color($value)) {
            $out['colors']['--' . $key] = $value;
        }
    }

    return $out;
}

/**
 * theme.json, resolved the way the front end sees it.
 *
 * Through WP_Theme_JSON_Resolver rather than the file on disk, so a child theme
 * and anything changed in Global Styles are both included.
 */
function nibwp_design_tokens_from_theme_json(): array
{
    if (!class_exists('WP_Theme_JSON_Resolver')) {
        return [];
    }

    try {
        $settings = WP_Theme_JSON_Resolver::get_merged_data()->get_settings();
    } catch (Throwable $e) {
        return [];
    }
    if (!is_array($settings)) {
        return [];
    }

    $out = ['colors' => [], 'type_scale' => [], 'spacing' => [], 'radius' => [], 'shadow' => []];

    foreach ((array) ($settings['color']['palette'] ?? []) as $origin) {
        foreach ((array) $origin as $entry) {
            if (!empty($entry['slug']) && !empty($entry['color'])) {
                $out['colors']['--wp--preset--color--' . $entry['slug']] = (string) $entry['color'];
            }
        }
    }

    foreach ((array) ($settings['typography']['fontSizes'] ?? []) as $origin) {
        foreach ((array) $origin as $entry) {
            if (!empty($entry['slug']) && !empty($entry['size'])) {
                $out['type_scale']['--wp--preset--font-size--' . $entry['slug']] = (string) $entry['size'];
            }
        }
    }

    foreach ((array) ($settings['spacing']['spacingSizes'] ?? []) as $origin) {
        foreach ((array) $origin as $entry) {
            if (!empty($entry['slug']) && !empty($entry['size'])) {
                $out['spacing']['--wp--preset--spacing--' . $entry['slug']] = (string) $entry['size'];
            }
        }
    }

    return $out;
}

function nibwp_design_looks_like_color(string $value): bool
{
    return (bool) preg_match('/^(#[0-9a-f]{3,8}|(rgb|hsl|oklch|lab|color)a?\()/i', $value);
}

/**
 * The root font size, needed to turn a px design value into a scale position.
 */
function nibwp_design_base_font_size(): float
{
    if (!function_exists('nibwp_acss_read_settings')) {
        return 16.0;
    }

    $raw = nibwp_acss_read_settings();

    foreach (['root-font-size', 'base-font-size'] as $key) {
        $value = trim((string) ($raw[$key] ?? ''));
        if ($value === '') {
            continue;
        }

        // ACSS stores this as a percentage of the browser default — `100`
        // meaning 100%, not 100px. Reading the number and trusting it makes
        // every rem token 6.25x too large, which snaps every size in a design
        // to the wrong end of the scale.
        if (preg_match('/^([\d.]+)\s*%?$/', $value, $m) && !str_contains($value, 'px') && !str_contains($value, 'rem')) {
            $pct = (float) $m[1];
            return $pct > 0 ? 16.0 * ($pct / 100) : 16.0;
        }

        $px = nibwp_design_to_px($value, 16.0);
        if ($px !== null && $px > 0) {
            return $px;
        }
    }

    return 16.0;
}

// =============================================================================
// Snapping a design value to the nearest token
// =============================================================================

/**
 * The nearest token to a raw design value, or nothing when nothing is near.
 *
 * One function, called by every converter. Three converters each with their own
 * idea of "near" is three different builds from the same design, and no way to
 * explain any of them.
 *
 * Returns the token and why, or null with a reason — the caller is expected to
 * report what it could not snap rather than quietly emit a literal. Silence is
 * the actual defect: a converter that emits three literals and says so is fine,
 * one that emits seven hundred and says nothing is not.
 *
 * @param 'color'|'size' $kind
 * @return array{token: ?string, value: ?string, distance: float, reason: string}
 */
function nibwp_design_snap_value(string $value, array $tokens, string $kind = 'color', float $tolerance = 0.0): array
{
    $value = trim($value);
    $miss  = ['token' => null, 'value' => null, 'distance' => INF, 'reason' => 'no token near'];

    if ($value === '' || $tokens === []) {
        return ['token' => null, 'value' => null, 'distance' => INF, 'reason' => 'no tokens to snap to'];
    }

    if ($kind === 'color') {
        // Chebyshev distance over 8-bit RGB. Crude next to a perceptual metric,
        // but the job is catching near-duplicates of one intended colour, and
        // at these distances the two agree.
        $tolerance = $tolerance > 0 ? $tolerance : 6.0;
        $target    = nibwp_design_parse_hex($value);
        if ($target === null) {
            return ['token' => null, 'value' => null, 'distance' => INF, 'reason' => 'not a plain hex colour'];
        }

        $best = $miss;
        foreach ($tokens as $name => $candidate) {
            $rgb = nibwp_design_parse_hex((string) $candidate);
            if ($rgb === null) {
                continue;
            }
            $d = (float) max(abs($rgb[0] - $target[0]), abs($rgb[1] - $target[1]), abs($rgb[2] - $target[2]));
            if ($d < $best['distance']) {
                $best = [
                    'token'    => 'var(' . $name . ')',
                    'value'    => (string) $candidate,
                    'distance' => $d,
                    'reason'   => $d === 0.0 ? 'exact' : 'nearest',
                ];
            }
        }

        return $best['distance'] <= $tolerance ? $best : $miss;
    }

    // Sizes compare in px, so rem tokens and px design values are comparable.
    $tolerance = $tolerance > 0 ? $tolerance : 2.0;
    $base      = nibwp_design_base_font_size();
    $target_px = nibwp_design_to_px($value, $base);
    if ($target_px === null) {
        return ['token' => null, 'value' => null, 'distance' => INF, 'reason' => 'not a length'];
    }

    $best = $miss;
    foreach ($tokens as $name => $candidate) {
        $px = nibwp_design_to_px((string) $candidate, $base);
        if ($px === null) {
            continue;
        }
        $d = abs($px - $target_px);
        if ($d < $best['distance']) {
            $best = [
                'token'    => 'var(' . $name . ')',
                'value'    => (string) $candidate,
                'distance' => $d,
                'reason'   => $d === 0.0 ? 'exact' : 'nearest',
            ];
        }
    }

    return $best['distance'] <= $tolerance ? $best : $miss;
}

/**
 * A hex colour as RGB, or null when the string is not one.
 *
 * Deliberately not nibwp_design_hex_to_rgb(): design-skills/lib/site-reader.php
 * already owns that name, and its version answers a different question — it
 * coerces anything unparseable to black so a palette always gets three numbers.
 * The snapper needs the opposite. Feeding it black for `inherit` would snap
 * every unparseable value onto whatever token sits nearest black.
 *
 * @return array{0: int, 1: int, 2: int}|null
 */
function nibwp_design_parse_hex(string $value): ?array
{
    $value = ltrim(trim($value), '#');

    if (preg_match('/^[0-9a-f]{3}$/i', $value)) {
        $value = $value[0] . $value[0] . $value[1] . $value[1] . $value[2] . $value[2];
    }
    if (!preg_match('/^[0-9a-f]{6}([0-9a-f]{2})?$/i', $value)) {
        return null;
    }

    return [
        (int) hexdec(substr($value, 0, 2)),
        (int) hexdec(substr($value, 2, 2)),
        (int) hexdec(substr($value, 4, 2)),
    ];
}

/**
 * A CSS length in px, or null when it is not one we can compare.
 *
 * em resolves against the root here, not the parent: a converter looks at a
 * value in isolation and has no cascade to consult. Close enough to place a
 * value on a scale, and wrong enough to be worth saying so.
 */
function nibwp_design_to_px(string $value, float $base): ?float
{
    $value = trim(strtolower($value));

    if (!preg_match('/^(-?[\d.]+)\s*(px|rem|em|pt)?$/', $value, $m)) {
        return null;
    }

    $n    = (float) $m[1];
    $unit = $m[2] ?? 'px';

    return match ($unit) {
        'rem', 'em' => $n * $base,
        'pt'        => $n * (96 / 72),
        default     => $n,
    };
}

/**
 * The nibwp/design-system-detect ability.
 *
 * Adds counts to the raw detection because the first question an agent asks of
 * this is "is there enough here to build against", and counting 897 colours is
 * not something to make it do by reading 897 colours.
 */
function nibwp_design_system_detect_ability(array $input): array
{
    $found = nibwp_design_system_detect();

    $counts = [];
    foreach ($found['tokens'] as $group => $tokens) {
        $counts[$group] = count($tokens);
    }

    $out = [
        'system'         => $found['system'],
        'configured'     => $found['configured'],
        'candidates'     => $found['candidates'],
        'base_font_size' => $found['base_font_size'],
        'counts'         => $counts,
    ];

    // The full vocabulary is thousands of entries on a configured ACSS site,
    // which is more than most callers want back in one response.
    if (($input['include_tokens'] ?? true) !== false) {
        $out['tokens'] = $found['tokens'];
    }

    return $out;
}
