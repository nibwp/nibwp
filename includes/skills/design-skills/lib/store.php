<?php

declare(strict_types=1);

/**
 * The site's remembered direction.
 *
 * A site whose second page was decided independently of its first is a site that
 * looks assembled. Remembering one direction is the cheapest thing that makes a
 * whole site read as designed — and it is why "give me a different one" has to
 * be explicit rather than accidental.
 *
 * One option, not autoloaded: read only when a page is being designed.
 */

if (!defined('ABSPATH')) {
    exit();
}

const NIBWP_DESIGN_OPTION = 'nibwp_design_direction';

// Which generation of remembered direction this code writes. Style and shape
// used to be stored but never reused, and were picked per request by a fallback
// that could choose a style its own notes ruled out. Pinning a site to one of
// those picks would make the bad choice permanent, so records from before are
// used for brand and type only, and their look is decided once more.
const NIBWP_DESIGN_SCHEMA = 2;

/**
 * @return array<string, mixed>|null
 */
function nibwp_design_remembered(): ?array
{
    $saved = get_option(NIBWP_DESIGN_OPTION, null);

    return is_array($saved) && $saved !== [] ? $saved : null;
}

/**
 * The remembered style, shape and motion, or null when there is none to reuse.
 *
 * Checked with isset rather than empty: a radius of "0" is a decision.
 *
 * @param array<string, mixed>|null $remembered
 * @return array{style: array<string, mixed>, shape: array<string, mixed>, motion: array<string, mixed>}|null
 */
function nibwp_design_remembered_look(?array $remembered): ?array
{
    if ($remembered === null || (int) ($remembered['schema'] ?? 1) < NIBWP_DESIGN_SCHEMA) {
        return null;
    }

    $style = $remembered['style'] ?? null;
    $shape = $remembered['shape'] ?? null;
    $motion = $remembered['motion'] ?? null;

    if (!is_array($style) || (string) ($style['name'] ?? '') === '' || !is_array($shape) || !isset($shape['radius']) || !is_array($motion)) {
        return null;
    }

    return ['style' => $style, 'shape' => $shape, 'motion' => $motion];
}

/**
 * @param array<string, mixed> $direction
 */
function nibwp_design_remember(array $direction): void
{
    // Only the site-wide half is kept. Layout and the rules that applied belong
    // to the page that asked, and pinning them would make every page the same
    // page — the opposite of the point.
    update_option(
        NIBWP_DESIGN_OPTION,
        [
            'brand' => $direction['brand'] ?? [],
            'type' => $direction['type'] ?? [],
            'space' => $direction['space'] ?? [],
            'shape' => $direction['shape'] ?? [],
            'motion' => $direction['motion'] ?? [],
            'style' => $direction['style'] ?? [],
            'schema' => NIBWP_DESIGN_SCHEMA,
            'decided' => time(),
        ],
        false
    );
}

function nibwp_design_forget(): void
{
    delete_option(NIBWP_DESIGN_OPTION);
}
