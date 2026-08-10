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

/**
 * @return array<string, mixed>|null
 */
function nibwp_design_remembered(): ?array
{
    $saved = get_option(NIBWP_DESIGN_OPTION, null);

    return is_array($saved) && $saved !== [] ? $saved : null;
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
            'decided' => time(),
        ],
        false
    );
}

function nibwp_design_forget(): void
{
    delete_option(NIBWP_DESIGN_OPTION);
}
