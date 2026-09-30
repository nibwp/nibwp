<?php

declare(strict_types=1);

/**
 * Color maths — build a role palette from a seed, and never ship a pair that
 * cannot be read.
 *
 * A palette is computed rather than picked from a list, because a computed one
 * starts from this site's brand color and a picked one starts from fashion. The
 * arithmetic is ordinary sRGB relative luminance, which is what WCAG contrast is
 * defined in, so "passes AA" here means the same thing it means in an audit.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Relative luminance, per WCAG 2.x.
 */
function nibwp_design_luminance(string $hex): float
{
    [$r, $g, $b] = nibwp_design_hex_to_rgb($hex);

    $channel = static function (int $v): float {
        $s = $v / 255;
        return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
    };

    return 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);
}

/**
 * Contrast ratio between two colors, 1 to 21.
 */
function nibwp_design_contrast(string $a, string $b): float
{
    $la = nibwp_design_luminance($a);
    $lb = nibwp_design_luminance($b);

    $hi = max($la, $lb);
    $lo = min($la, $lb);

    return round(($hi + 0.05) / ($lo + 0.05), 2);
}

/**
 * Black or white, whichever can actually be read on this color.
 */
function nibwp_design_readable_on(string $hex): string
{
    return nibwp_design_contrast($hex, '#ffffff') >= nibwp_design_contrast($hex, '#111111')
        ? '#ffffff'
        : '#111111';
}

/**
 * Move a color toward white or black by a fraction.
 */
function nibwp_design_mix(string $hex, string $toward, float $amount): string
{
    $amount = max(0.0, min(1.0, $amount));
    [$r1, $g1, $b1] = nibwp_design_hex_to_rgb($hex);
    [$r2, $g2, $b2] = nibwp_design_hex_to_rgb($toward);

    return sprintf(
        '#%02x%02x%02x',
        (int) round($r1 + ($r2 - $r1) * $amount),
        (int) round($g1 + ($g2 - $g1) * $amount),
        (int) round($b1 + ($b2 - $b1) * $amount)
    );
}

/**
 * Darken or lighten a seed until text on it can be read.
 *
 * Called when a brand color is genuinely unusable as a surface — a pale yellow
 * logo color behind white text, say. We move the surface rather than abandoning
 * the brand: the hue survives, the contrast becomes real.
 */
function nibwp_design_force_contrast(string $hex, string $against, float $target = 4.5): string
{
    if (nibwp_design_contrast($hex, $against) >= $target) {
        return $hex;
    }

    // Move away from the text color: dark text means lighten the surface.
    $toward = nibwp_design_luminance($against) > 0.5 ? '#000000' : '#ffffff';

    for ($step = 1; $step <= 20; $step++) {
        $candidate = nibwp_design_mix($hex, $toward, $step * 0.05);
        if (nibwp_design_contrast($candidate, $against) >= $target) {
            return $candidate;
        }
    }

    return $toward === '#000000' ? '#111111' : '#ffffff';
}

/**
 * A full role palette derived from one or two seed colors.
 *
 * Roles match what every builder needs to be handed: a primary and something
 * readable on it, a surface and its text, a muted pair for secondary content,
 * a border, and an accent for the one thing that should stand out.
 *
 * @param array<int, string> $seeds
 * @return array{roles: array<string, string>, contrast: array<string, float>, passes: bool, corrections: array<int, string>}
 */
function nibwp_design_build_palette(array $seeds, bool $dark = false): array
{
    $seeds = array_values(array_filter(array_map('nibwp_design_normalize_hex', $seeds)));
    $primary = $seeds[0] ?? '#1f2937';
    $accent = $seeds[1] ?? nibwp_design_rotate_hue($primary, 150);

    $corrections = [];

    $surface = $dark ? '#101216' : '#ffffff';
    $text = $dark ? '#f2f4f7' : '#14171c';

    // A brand color has to work as a button before it is allowed to be one.
    $on_primary = nibwp_design_readable_on($primary);
    if (nibwp_design_contrast($primary, $on_primary) < 4.5) {
        $fixed = nibwp_design_force_contrast($primary, $on_primary);
        $corrections[] = sprintf('primary %s adjusted to %s for readable text', $primary, $fixed);
        $primary = $fixed;
        $on_primary = nibwp_design_readable_on($primary);
    }

    $on_accent = nibwp_design_readable_on($accent);
    if (nibwp_design_contrast($accent, $on_accent) < 4.5) {
        $fixed = nibwp_design_force_contrast($accent, $on_accent);
        $corrections[] = sprintf('accent %s adjusted to %s for readable text', $accent, $fixed);
        $accent = $fixed;
        $on_accent = nibwp_design_readable_on($accent);
    }

    $roles = [
        'primary' => $primary,
        'on-primary' => $on_primary,
        'accent' => $accent,
        'on-accent' => $on_accent,
        'surface' => $surface,
        'on-surface' => $text,
        'raised' => $dark ? nibwp_design_mix($surface, '#ffffff', 0.06) : nibwp_design_mix($surface, '#000000', 0.03),
        'muted' => $dark ? nibwp_design_mix($text, $surface, 0.45) : nibwp_design_mix($text, $surface, 0.42),
        'border' => $dark ? nibwp_design_mix($surface, '#ffffff', 0.14) : nibwp_design_mix($surface, '#000000', 0.12),
    ];

    // Muted text is the pair that quietly fails on most generated pages: it is
    // chosen for looking calm, not for being readable.
    if (nibwp_design_contrast($roles['muted'], $surface) < 4.5) {
        $fixed = nibwp_design_force_contrast($roles['muted'], $surface);
        $corrections[] = sprintf('muted text %s adjusted to %s', $roles['muted'], $fixed);
        $roles['muted'] = $fixed;
    }

    $contrast = [
        'on-primary/primary' => nibwp_design_contrast($roles['on-primary'], $roles['primary']),
        'on-accent/accent' => nibwp_design_contrast($roles['on-accent'], $roles['accent']),
        'on-surface/surface' => nibwp_design_contrast($roles['on-surface'], $roles['surface']),
        'muted/surface' => nibwp_design_contrast($roles['muted'], $roles['surface']),
    ];

    $passes = true;
    foreach ($contrast as $ratio) {
        if ($ratio < 4.5) {
            $passes = false;
        }
    }

    return [
        'roles' => $roles,
        'contrast' => $contrast,
        'passes' => $passes,
        'corrections' => $corrections,
    ];
}

/**
 * Rotate a color's hue, keeping saturation and lightness.
 *
 * Used to invent an accent when the site gave us only one brand color. A
 * rotation stays in the same family of taste as the seed, where picking from a
 * list would not.
 */
function nibwp_design_rotate_hue(string $hex, int $degrees): string
{
    [$r, $g, $b] = array_map(static fn(int $v): float => $v / 255, nibwp_design_hex_to_rgb($hex));

    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $l = ($max + $min) / 2;
    $d = $max - $min;

    if ($d == 0.0) {
        $h = 0.0;
        $s = 0.0;
    } else {
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        if ($max === $r) {
            $h = (($g - $b) / $d + ($g < $b ? 6 : 0)) / 6;
        } elseif ($max === $g) {
            $h = (($b - $r) / $d + 2) / 6;
        } else {
            $h = (($r - $g) / $d + 4) / 6;
        }
    }

    $h = fmod($h + ($degrees / 360), 1.0);
    if ($h < 0) {
        $h += 1.0;
    }

    if ($s == 0.0) {
        $rgb = [$l, $l, $l];
    } else {
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        $rgb = [
            nibwp_design_hue_channel($p, $q, $h + 1 / 3),
            nibwp_design_hue_channel($p, $q, $h),
            nibwp_design_hue_channel($p, $q, $h - 1 / 3),
        ];
    }

    return sprintf(
        '#%02x%02x%02x',
        (int) round($rgb[0] * 255),
        (int) round($rgb[1] * 255),
        (int) round($rgb[2] * 255)
    );
}

function nibwp_design_hue_channel(float $p, float $q, float $t): float
{
    if ($t < 0) {
        $t += 1;
    }
    if ($t > 1) {
        $t -= 1;
    }
    if ($t < 1 / 6) {
        return $p + ($q - $p) * 6 * $t;
    }
    if ($t < 1 / 2) {
        return $q;
    }
    if ($t < 2 / 3) {
        return $p + ($q - $p) * (2 / 3 - $t) * 6;
    }

    return $p;
}
