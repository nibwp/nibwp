<?php

declare(strict_types=1);

/**
 * Ability: design direction.
 *
 * Decides how a page should look, before anything builds it. Returns a small
 * object — never markup, never the knowledge base.
 */

if (!defined('ABSPATH')) {
    exit();
}

// The library is loaded from here rather than through a manifest field: this is
// the only entry point, so anything it needs is this file's business.
require_once __DIR__ . '/../lib/site-reader.php';
require_once __DIR__ . '/../lib/palette.php';
require_once __DIR__ . '/../lib/catalogue.php';
require_once __DIR__ . '/../lib/store.php';

wp_register_ability('nibwp/design-direction', [
    'label' => __('Design Direction', domain: 'nibwp'),
    'description' => __(
        'Decides how a page should look on THIS site before anything is built: colour roles with contrast already validated, a type pairing, spacing rhythm, shape language, the layout pattern for this kind of page, and the specific generic defaults to refuse. Reads the site first — ACSS variables, theme.json, the logo — and only falls back to a design catalogue for what the site does not say. Call this before building any page or section, then build to what it returns.',
        domain: 'nibwp',
    ),
    'category' => 'design',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'purpose' => [
                'type' => 'string',
                'description' => 'What is being built, in the user\'s own words — "pricing page", "homepage for a tour operator", "contact section".',
                'minLength' => 2,
            ],
            'product_type' => [
                'type' => 'string',
                'description' => 'Optional. The kind of business or product, if known — "restaurant", "saas", "dentist". Inferred from the site when omitted.',
            ],
            'mood' => [
                'type' => 'string',
                'description' => 'Optional. Any tone the user asked for — "warm", "editorial", "technical", "playful".',
            ],
            'brand_color' => [
                'type' => 'string',
                'description' => 'Optional. A hex colour to build the palette from, overriding what the site says.',
            ],
            'dark' => [
                'type' => 'boolean',
                'description' => 'Build the palette for a dark surface. Defaults to false.',
                'default' => false,
            ],
            'fresh' => [
                'type' => 'boolean',
                'description' => 'Ignore the direction this site already settled on and decide again. Use only when the user asks for something different.',
                'default' => false,
            ],
        ],
        'required' => ['purpose'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'brand' => ['type' => 'object', 'description' => 'Colour roles, contrast ratios, and any corrections that were applied.'],
            'type' => ['type' => 'object', 'description' => 'Heading font, body font, scale, and where each came from.'],
            'space' => ['type' => 'object', 'description' => 'Spacing scale and section rhythm.'],
            'shape' => ['type' => 'object', 'description' => 'Radius, shadow and border language.'],
            'layout' => ['type' => 'object', 'description' => 'Section sequence and hero shape for this page.'],
            'motion' => ['type' => 'object', 'description' => 'What may move, and what must not.'],
            'style' => ['type' => 'object', 'description' => 'The visual style this direction sits in.'],
            'rules' => ['type' => 'array', 'description' => 'The generic defaults to refuse on this page, each with its reason.'],
            'builder' => ['type' => 'object', 'description' => 'How to express this direction in the builder this site uses.'],
            'source' => ['type' => 'object', 'description' => 'Which decisions came from the site and which from the catalogue.'],
        ],
    ],
    'execute_callback' => 'nibwp_design_direction',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Call this BEFORE building any page, section or component — not after.\nBuild to what it returns: use the colour roles, the type pairing, the spacing rhythm and the layout sequence given.\nThe rules[] array lists the specific defaults to refuse on this page. Each carries a reason; follow the reason, not just the ban.\nbuilder{} says how to express the direction in the builder this site actually uses — use those tokens rather than hex values and px sizes.\nIt remembers the site-wide half of the direction — brand, type, spacing, shape — so a second page matches the first. That is the one thing it stores. Pass fresh:true only when the user asks for a different look.\nIt returns no markup, changes no content, and touches nothing a visitor can see.",
            // Not readonly: it stores the direction so the next page matches this
            // one. Small, but it is a write, and an ability that says otherwise
            // would be lying to the permission screen it is governed by.
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_design_direction(array $input): array|WP_Error
{
    $purpose = trim((string) ($input['purpose'] ?? ''));
    if ($purpose === '') {
        return new WP_Error('missing_purpose', 'Say what is being built — a page, a section, a kind of site.');
    }

    $product_type = trim((string) ($input['product_type'] ?? ''));
    $mood = trim((string) ($input['mood'] ?? ''));
    $dark = (bool) ($input['dark'] ?? false);
    $fresh = (bool) ($input['fresh'] ?? false);

    $site = nibwp_design_read_site();
    $remembered = $fresh ? null : nibwp_design_remembered();
    $from_site = [];
    $from_catalogue = [];

    // ── Colour ───────────────────────────────────────────────────────────────
    // Order of trust: what the user just said, what this site already settled
    // on, what the site's own tokens say, then the catalogue.
    $override = nibwp_design_normalize_hex((string) ($input['brand_color'] ?? ''));

    if ($override !== '') {
        $seeds = [$override];
        $from_site[] = 'brand colour given in the request';
    } elseif ($remembered !== null && !empty($remembered['brand']['seeds'])) {
        $seeds = (array) $remembered['brand']['seeds'];
        $from_site[] = 'brand colour this site already settled on';
    } else {
        $seeds = nibwp_design_seeds_from_site($site, $from_site);
    }

    if ($seeds === []) {
        $seeds = nibwp_design_palette_seeds_for($product_type !== '' ? $product_type : $purpose);
        $from_catalogue[] = 'palette (site has no brand colours)';
    }

    $palette = nibwp_design_build_palette($seeds, $dark);

    // ── Type ─────────────────────────────────────────────────────────────────
    $type = nibwp_design_resolve_type($site, $remembered, $purpose, $mood, $from_site, $from_catalogue);

    // ── Style, layout, builder, rules ────────────────────────────────────────
    $style_row = nibwp_design_style_for($purpose, $product_type);
    if ($style_row !== null) {
        $from_catalogue[] = 'visual style';
    }

    $layout_row = nibwp_design_layout_for($purpose);
    $builder_row = nibwp_design_builder_notes($site['builder']);
    $rules = nibwp_design_rules_for($purpose);
    $ux = nibwp_design_ux_rules($purpose);

    $space = nibwp_design_resolve_space($site, $layout_row, $from_site, $from_catalogue);

    $direction = [
        'brand' => [
            'seeds' => $seeds,
            'roles' => $palette['roles'],
            'contrast' => $palette['contrast'],
            'passes_aa' => $palette['passes'],
            'corrections' => $palette['corrections'],
            'dark' => $dark,
        ],
        'type' => $type,
        'space' => $space,
        'shape' => nibwp_design_resolve_shape($style_row),
        'layout' => [
            'page' => $layout_row['Page Purpose'] ?? $purpose,
            'sections' => array_values(array_filter(array_map(
                'trim',
                explode(';', (string) ($layout_row['Section Sequence'] ?? ''))
            ))),
            'hero' => (string) ($layout_row['Hero Shape'] ?? ''),
            'rhythm' => (string) ($layout_row['Rhythm'] ?? ''),
            'emphasis' => (string) ($layout_row['Emphasis'] ?? ''),
            'note' => (string) ($layout_row['Notes'] ?? ''),
        ],
        'motion' => [
            'policy' => (string) ($style_row['Effects & Animation'] ?? 'Restrained. One or two deliberate moments, nothing on every section.'),
            'reduced_motion' => 'Respect prefers-reduced-motion — no exceptions.',
        ],
        'style' => [
            'name' => (string) ($style_row['Style Category'] ?? ''),
            'keywords' => (string) ($style_row['Keywords'] ?? ''),
            'suits' => (string) ($style_row['Best For'] ?? ''),
            'avoid_for' => (string) ($style_row['Do Not Use For'] ?? ''),
        ],
        'rules' => $rules,
        'ux' => $ux,
        'builder' => [
            'name' => $site['builder'],
            'structure' => (string) ($builder_row['Structure'] ?? ''),
            'type_tokens' => (string) ($builder_row['Type Tokens'] ?? ''),
            'color_tokens' => (string) ($builder_row['Colour Tokens'] ?? ''),
            'spacing' => (string) ($builder_row['Spacing'] ?? ''),
            'do' => (string) ($builder_row['Do'] ?? ''),
            'do_not' => (string) ($builder_row['Do Not'] ?? ''),
        ],
        'source' => [
            'from_site' => $from_site,
            'from_catalogue' => $from_catalogue,
            'tokens' => $site['tokens'],
            'remembered' => $remembered !== null,
        ],
    ];

    // Remembering is what makes page two match page one. Only the site-wide half
    // is kept — see store.php.
    nibwp_design_remember($direction);

    return $direction;
}

/**
 * Brand colours the site itself already has.
 *
 * @param array<string, mixed> $site
 * @param array<int, string> $from_site
 * @return array<int, string>
 */
function nibwp_design_seeds_from_site(array $site, array &$from_site): array
{
    $seeds = [];

    foreach (['primary', 'accent', 'secondary'] as $role) {
        if (!empty($site['colors'][$role])) {
            $seeds[] = (string) $site['colors'][$role];
        }
    }

    if ($seeds !== []) {
        $from_site[] = $site['tokens'] === 'acss' ? 'ACSS colour variables' : 'theme palette';
        return $seeds;
    }

    // No named roles: the logo is the next most honest source of a brand colour.
    if (!empty($site['logo'])) {
        $from_site[] = 'colours sampled from the site logo';
        return array_slice((array) $site['logo'], 0, 2);
    }

    // Anything in the palette at all beats inventing one.
    $palette = array_values(array_filter(
        (array) $site['colors'],
        static fn(string $hex): bool => !in_array($hex, ['#ffffff', '#000000'], true)
    ));

    if ($palette !== []) {
        $from_site[] = 'theme palette';
        return array_slice($palette, 0, 2);
    }

    return [];
}

/**
 * Fonts: the site's own if it has them, a pairing from the catalogue if not.
 *
 * @param array<string, mixed> $site
 * @param array<string, mixed>|null $remembered
 * @param array<int, string> $from_site
 * @param array<int, string> $from_catalogue
 * @return array<string, mixed>
 */
function nibwp_design_resolve_type(
    array $site,
    ?array $remembered,
    string $purpose,
    string $mood,
    array &$from_site,
    array &$from_catalogue
): array {
    if ($remembered !== null && !empty($remembered['type']['heading'])) {
        $from_site[] = 'type pairing this site already settled on';
        return (array) $remembered['type'];
    }

    if ($site['fonts'] !== []) {
        $slugs = array_keys($site['fonts']);
        $heading = (string) ($site['fonts'][$slugs[0]] ?? '');
        $body = (string) ($site['fonts'][$slugs[1] ?? $slugs[0]] ?? $heading);

        $from_site[] = 'fonts registered on this site';

        return [
            'heading' => $heading,
            'body' => $body,
            'scale' => nibwp_design_scale_from_sizes($site['sizes']),
            'source' => 'site',
            'note' => 'These fonts are already loaded. Do not enqueue another family.',
        ];
    }

    $row = nibwp_design_type_for($purpose, $mood);
    $from_catalogue[] = 'font pairing';

    return [
        'heading' => (string) ($row['Heading Font'] ?? 'Inter'),
        'body' => (string) ($row['Body Font'] ?? 'Inter'),
        'pairing' => (string) ($row['Font Pairing Name'] ?? ''),
        'mood' => (string) ($row['Mood/Style Keywords'] ?? ''),
        'source_url' => (string) ($row['Google Fonts URL'] ?? ''),
        'scale' => nibwp_design_scale_from_sizes($site['sizes']),
        'source' => 'catalogue',
    ];
}

/**
 * The type scale — the site's own sizes where it has them.
 *
 * @param array<int, array{slug: string, size: string}> $sizes
 * @return array<string, string>
 */
function nibwp_design_scale_from_sizes(array $sizes): array
{
    if ($sizes === []) {
        return [
            'source' => 'default',
            'ratio' => '1.25 (major third)',
            'note' => 'No scale on this site. Use a consistent ratio and fixed sizes — never clamp() for font size.',
        ];
    }

    $out = ['source' => 'site'];
    foreach ($sizes as $entry) {
        $out[$entry['slug']] = $entry['size'];
    }
    $out['note'] = 'Reference these by slug rather than restating the values.';

    return $out;
}

/**
 * Spacing: the site's scale if it publishes one, the layout's rhythm otherwise.
 *
 * @param array<string, mixed> $site
 * @param array<string, string>|null $layout_row
 * @param array<int, string> $from_site
 * @param array<int, string> $from_catalogue
 * @return array<string, mixed>
 */
function nibwp_design_resolve_space(array $site, ?array $layout_row, array &$from_site, array &$from_catalogue): array
{
    $rhythm = (string) ($layout_row['Rhythm'] ?? 'Vary the rhythm — tighter where content is dense, generous around what matters.');

    if (!empty($site['spacing'])) {
        $from_site[] = 'spacing scale from this site';

        return [
            'scale' => array_values((array) $site['spacing']),
            'source' => 'site',
            'rhythm' => $rhythm,
            'measure' => '60-75ch for body text',
        ];
    }

    if ($site['tokens'] === 'acss') {
        $from_site[] = 'ACSS spacing tokens';

        return [
            'scale' => ['var(--section-space-s)', 'var(--section-space-m)', 'var(--section-space-l)'],
            'source' => 'acss',
            'rhythm' => $rhythm,
            'measure' => '60-75ch for body text',
        ];
    }

    $from_catalogue[] = 'spacing rhythm';

    return [
        'scale' => ['0.5rem', '1rem', '1.5rem', '2.5rem', '4rem', '6rem'],
        'source' => 'default',
        'rhythm' => $rhythm,
        'measure' => '60-75ch for body text',
    ];
}

/**
 * Radius, shadow and border language, taken from the style's own description.
 *
 * @param array<string, string>|null $style_row
 * @return array<string, string>
 */
function nibwp_design_resolve_shape(?array $style_row): array
{
    $keywords = strtolower((string) ($style_row['Keywords'] ?? ''));

    $radius = match (true) {
        str_contains($keywords, 'brutal') || str_contains($keywords, 'swiss') || str_contains($keywords, 'geometric') => '0',
        str_contains($keywords, 'soft') || str_contains($keywords, 'friendly') || str_contains($keywords, 'rounded') => '16px',
        str_contains($keywords, 'pill') => '999px',
        default => '8px',
    };

    $shadow = match (true) {
        str_contains($keywords, 'flat') || str_contains($keywords, 'brutal') => 'none — use borders and background shifts for separation',
        str_contains($keywords, 'depth') || str_contains($keywords, 'neumorph') || str_contains($keywords, 'glass') => 'one soft elevation, used sparingly',
        default => 'at most one level, on genuinely raised surfaces only',
    };

    return [
        'radius' => $radius,
        'shadow' => $shadow,
        'border' => 'Use the border role for separation before reaching for a shadow.',
    ];
}
