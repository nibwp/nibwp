<?php

declare(strict_types=1);

/**
 * What this site already looks like.
 *
 * The point of the whole skill: a direction derived from the site cannot look
 * like every other AI page, because no two sites start from the same place. So
 * before any catalogue is consulted, we read what is actually here — the token
 * layer if there is one, the theme's own palette and type scale, the logo, the
 * builder in use.
 *
 * Everything here fails soft. A site with no ACSS, no theme.json palette and no
 * logo is normal, and the answer is simply "nothing found" rather than an error
 * — the caller falls back to the catalogue for whatever is missing.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Everything we can learn about how this site already looks.
 *
 * @return array{
 *   builder: string,
 *   tokens: string,
 *   colors: array<string, string>,
 *   fonts: array<string, string>,
 *   sizes: array<int, array{slug: string, size: string}>,
 *   spacing: array<int, string>,
 *   logo: array<int, string>,
 *   found: array<int, string>
 * }
 */
function nibwp_design_read_site(): array
{
    $site = [
        'builder' => nibwp_design_active_builder(),
        'tokens' => 'none',
        'colors' => [],
        'fonts' => [],
        'sizes' => [],
        'spacing' => [],
        'logo' => [],
        'found' => [],
    ];

    // ACSS is the strongest signal: a site running it has a deliberate token
    // system, and building anything that ignores it produces a page that looks
    // foreign on its own site.
    $acss = nibwp_design_read_acss();
    if ($acss !== []) {
        $site['tokens'] = 'acss';
        $site['colors'] = $acss;
        $site['found'][] = 'acss-variables';
    }

    $theme = nibwp_design_read_theme_json();
    if ($theme['colors'] !== []) {
        // theme.json fills gaps rather than overwriting: ACSS is the layer the
        // site's own CSS is built on, so where both exist ACSS wins.
        $site['colors'] = $site['colors'] + $theme['colors'];
        $site['found'][] = 'theme-json-palette';
        if ($site['tokens'] === 'none') {
            $site['tokens'] = 'theme-json';
        }
    }
    if ($theme['fonts'] !== []) {
        $site['fonts'] = $theme['fonts'];
        $site['found'][] = 'theme-json-fonts';
    }
    if ($theme['sizes'] !== []) {
        $site['sizes'] = $theme['sizes'];
        $site['found'][] = 'theme-json-sizes';
    }
    if ($theme['spacing'] !== []) {
        $site['spacing'] = $theme['spacing'];
        $site['found'][] = 'theme-json-spacing';
    }

    $logo = nibwp_design_read_logo_colors();
    if ($logo !== []) {
        $site['logo'] = $logo;
        $site['found'][] = 'logo-colors';
    }

    return $site;
}

/**
 * Which builder this site actually uses.
 *
 * Decides how a direction has to be expressed — an Etch site and a Kadence site
 * need the same decision described in different vocabularies.
 */
function nibwp_design_active_builder(): string
{
    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    foreach ([
        'etch/etch.php' => 'etch',
        'etchwp/etchwp.php' => 'etch',
        'bricks/bricks.php' => 'bricks',
        'elementor/elementor.php' => 'elementor',
        'kadence-blocks/kadence-blocks.php' => 'kadence',
    ] as $file => $key) {
        if (is_plugin_active($file)) {
            return $key;
        }
    }

    // Bricks ships as a theme rather than a plugin on many installs.
    $theme = wp_get_theme();
    if (stripos((string) $theme->get('Name'), 'bricks') !== false) {
        return 'bricks';
    }

    return 'gutenberg';
}

/**
 * ACSS color variables, as the site has them configured.
 *
 * Read from the plugin's own settings rather than by parsing generated CSS: the
 * settings are what the user set, the CSS is a build artefact that may be stale.
 *
 * Goes through nibwp_acss_read_settings() rather than reading an option here.
 * This function used to name the option itself, with `acss_settings` as its
 * fallback — a spelling ACSS has never used — which is the same class of bug as
 * the one that made the ACSS abilities return empty on every configured 4.x
 * site. One resolver knows the names; nothing else should guess at them.
 *
 * @return array<string, string>
 */
function nibwp_design_read_acss(): array
{
    if (!function_exists('nibwp_acss_read_settings')) {
        return [];
    }

    $raw = nibwp_acss_read_settings();
    if ($raw === []) {
        return [];
    }

    $out = [];
    foreach (['primary', 'secondary', 'accent', 'base', 'shade'] as $role) {
        foreach (["{$role}-color", "{$role}", "color-{$role}"] as $key) {
            if (!empty($raw[$key]) && is_string($raw[$key])) {
                $hex = nibwp_design_normalize_hex($raw[$key]);
                if ($hex !== '') {
                    $out[$role] = $hex;
                    break;
                }
            }
        }
    }

    return $out;
}

/**
 * The theme's own palette, fonts, sizes and spacing.
 *
 * Uses WP_Theme_JSON_Resolver so a child theme, a block theme and a user's
 * Global Styles customizations all resolve the way the front end sees them —
 * reading theme.json off disk would miss everything the user changed in the
 * editor.
 *
 * @return array{colors: array<string, string>, fonts: array<string, string>, sizes: array<int, array{slug: string, size: string}>, spacing: array<int, string>}
 */
function nibwp_design_read_theme_json(): array
{
    $empty = ['colors' => [], 'fonts' => [], 'sizes' => [], 'spacing' => []];

    if (!class_exists('WP_Theme_JSON_Resolver')) {
        return $empty;
    }

    try {
        $data = WP_Theme_JSON_Resolver::get_merged_data()->get_settings();
    } catch (Throwable $e) {
        return $empty;
    }

    if (!is_array($data)) {
        return $empty;
    }

    $out = $empty;

    foreach ((array) ($data['color']['palette'] ?? []) as $origin) {
        foreach ((array) $origin as $entry) {
            if (!is_array($entry) || empty($entry['slug']) || empty($entry['color'])) {
                continue;
            }
            $hex = nibwp_design_normalize_hex((string) $entry['color']);
            if ($hex !== '') {
                $out['colors'][(string) $entry['slug']] = $hex;
            }
        }
    }

    foreach ((array) ($data['typography']['fontFamilies'] ?? []) as $origin) {
        foreach ((array) $origin as $entry) {
            if (!is_array($entry) || empty($entry['slug']) || empty($entry['fontFamily'])) {
                continue;
            }
            $out['fonts'][(string) $entry['slug']] = (string) $entry['fontFamily'];
        }
    }

    foreach ((array) ($data['typography']['fontSizes'] ?? []) as $origin) {
        foreach ((array) $origin as $entry) {
            if (!is_array($entry) || empty($entry['slug']) || empty($entry['size'])) {
                continue;
            }
            $out['sizes'][] = ['slug' => (string) $entry['slug'], 'size' => (string) $entry['size']];
        }
    }

    foreach ((array) ($data['spacing']['spacingSizes'] ?? []) as $origin) {
        foreach ((array) $origin as $entry) {
            if (is_array($entry) && !empty($entry['size'])) {
                $out['spacing'][] = (string) $entry['size'];
            }
        }
    }

    return $out;
}

/**
 * The colors in the site's logo.
 *
 * A brand's real colors are in its mark far more reliably than in whatever the
 * theme shipped with. Sampled coarsely — we want the two or three colors that
 * dominate, not a histogram.
 *
 * @return array<int, string>
 */
function nibwp_design_read_logo_colors(): array
{
    $id = (int) get_theme_mod('custom_logo');
    if ($id <= 0 || !function_exists('wp_get_original_image_path')) {
        return [];
    }

    $path = get_attached_file($id);
    if (!is_string($path) || $path === '' || !file_exists($path)) {
        return [];
    }

    // SVG logos are text: pull the hex values straight out rather than trying to
    // rasterise something GD cannot open.
    if (str_ends_with(strtolower($path), '.svg')) {
        $svg = (string) file_get_contents($path);
        if (preg_match_all('/#([0-9a-fA-F]{6}|[0-9a-fA-F]{3})\b/', $svg, $m)) {
            $hexes = array_values(array_unique(array_map(
                static fn(string $h): string => nibwp_design_normalize_hex('#' . $h),
                $m[1]
            )));
            return array_slice(array_filter($hexes), 0, 4);
        }
        return [];
    }

    if (!function_exists('imagecreatefromstring')) {
        return [];
    }

    $blob = @file_get_contents($path);
    if ($blob === false) {
        return [];
    }

    $img = @imagecreatefromstring($blob);
    if ($img === false) {
        return [];
    }

    $w = imagesx($img);
    $h = imagesy($img);
    $counts = [];

    // A 12x12 grid: enough to find the dominant colors in a mark, cheap enough
    // to run on every request without thinking about it.
    for ($x = 0; $x < 12; $x++) {
        for ($y = 0; $y < 12; $y++) {
            $px = imagecolorat($img, (int) ($w * ($x + 0.5) / 12), (int) ($h * ($y + 0.5) / 12));
            $alpha = ($px >> 24) & 0x7F;
            if ($alpha > 64) {
                continue; // transparent — the background of the mark, not the mark
            }
            $r = ($px >> 16) & 0xFF;
            $g = ($px >> 8) & 0xFF;
            $b = $px & 0xFF;

            // Round to a 32-step grid so near-identical pixels count together.
            $key = sprintf('#%02x%02x%02x', ($r >> 5) << 5, ($g >> 5) << 5, ($b >> 5) << 5);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
    }

    imagedestroy($img);

    if ($counts === []) {
        return [];
    }

    arsort($counts);
    $out = [];
    foreach (array_keys($counts) as $hex) {
        // Skip near-white and near-black: they are the paper and the ink, not
        // the brand.
        [$r, $g, $b] = nibwp_design_hex_to_rgb($hex);
        $light = ($r + $g + $b) / 3;
        if ($light > 235 || $light < 20) {
            continue;
        }
        $out[] = $hex;
        if (count($out) >= 3) {
            break;
        }
    }

    return $out;
}

/**
 * Any color notation we might meet, as #rrggbb — or '' when it is not a color
 * we can use (a var(), a gradient, a named color we would only be guessing at).
 */
function nibwp_design_normalize_hex(string $value): string
{
    $value = trim($value);

    if (preg_match('/^#([0-9a-fA-F]{3})$/', $value, $m)) {
        $c = $m[1];
        return strtolower('#' . $c[0] . $c[0] . $c[1] . $c[1] . $c[2] . $c[2]);
    }

    if (preg_match('/^#([0-9a-fA-F]{6})$/', $value)) {
        return strtolower($value);
    }

    if (preg_match('/rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)/i', $value, $m)) {
        return sprintf('#%02x%02x%02x', (int) $m[1], (int) $m[2], (int) $m[3]);
    }

    return '';
}

/**
 * @return array{0: int, 1: int, 2: int}
 */
function nibwp_design_hex_to_rgb(string $hex): array
{
    $hex = ltrim(nibwp_design_normalize_hex($hex) ?: '#000000', '#');

    return [
        (int) hexdec(substr($hex, 0, 2)),
        (int) hexdec(substr($hex, 2, 2)),
        (int) hexdec(substr($hex, 4, 2)),
    ];
}
