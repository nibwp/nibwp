<?php

declare(strict_types=1);

/**
 * Switches off a Skill add-on that this build already contains.
 *
 * Pro carries every skill in full. A standalone Skill add-on exists for people
 * on the free plugin who buy one skill on its own, and it bundles the
 * integration files that skill needs so it can work without Pro. Side by side
 * with Pro, those bundled files are a second copy of files Pro already has, at
 * a different path, and `require_once` deduplicates by path — so both load,
 * both declare the same functions, and PHP fails while compiling. The site goes
 * white and cannot be fixed from inside WordPress.
 *
 * The license client no longer creates that pairing, and both loaders now agree
 * on which copy to load. Neither helps a site where the pairing already exists
 * and the installed add-on predates the fix: its loader has no such agreement,
 * and it loads second, which is the copy that fatals.
 *
 * So the redundant add-on is deactivated here, early, before abilities
 * initialise. The deactivation is written to the database during this request
 * even if the request itself still dies, which means the very next page load is
 * clean and the site comes back on its own. Nothing is lost: the skill it
 * provided is already in this build.
 */

if (!defined('ABSPATH')) {
    exit();
}

// Priority 1: ahead of anything that might load an integration file, and early
// enough that the option write happens before a fatal later in the request.
add_action('plugins_loaded', 'nibwp_deactivate_redundant_skill_addons', 1);

const NIBWP_REDUNDANT_ADDONS_OPTION = 'nibwp_deactivated_redundant_addons';

function nibwp_deactivate_redundant_skill_addons(): void
{
    // Only a build that ships the skills can make one redundant. On Free the
    // add-on is the only copy there is, and it must keep working.
    if (!defined('NIBWP_HAS_PREMIUM_CODE') || !NIBWP_HAS_PREMIUM_CODE) {
        return;
    }

    if (!defined('NIBWP_PLUGIN_DIR') || !function_exists('get_option')) {
        return;
    }

    $redundant = nibwp_redundant_skill_addons();
    if ($redundant === []) {
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/plugin.php';

    $deactivated = [];
    foreach ($redundant as $skill_id => $plugin_file) {
        if (!is_plugin_active($plugin_file)) {
            continue;
        }
        // Silent: the add-on's own deactivation hooks have nothing useful to do
        // here, and this is a repair rather than a decision the user made.
        deactivate_plugins($plugin_file, true);
        $deactivated[] = $skill_id;
    }

    if ($deactivated !== []) {
        update_option(NIBWP_REDUNDANT_ADDONS_OPTION, $deactivated, false);
    }
}

/**
 * Add-ons that duplicate a skill this build already ships, keyed by skill id.
 *
 * The test is the presence of ability files rather than of a manifest: Free
 * ships each premium skill's manifest alone so the Skills screen can show a
 * card for something you have not bought, and that manifest is not the skill.
 *
 * @return array<string, string> skill id => plugin file
 */
function nibwp_redundant_skill_addons(): array
{
    $found = [];

    foreach (glob(NIBWP_PLUGIN_DIR . 'includes/skills/*/manifest.php') ?: [] as $manifest_path) {
        $dir = dirname($manifest_path);
        $skill_id = basename($dir);

        if ($skill_id === '_shared' || !is_dir($dir . '/abilities')) {
            continue;
        }

        $plugin_file = 'nibwp-skill-' . $skill_id . '/nibwp-skill-' . $skill_id . '.php';
        if (file_exists(WP_PLUGIN_DIR . '/' . $plugin_file)) {
            $found[$skill_id] = $plugin_file;
        }
    }

    return $found;
}

add_action('admin_notices', 'nibwp_render_redundant_addon_notice');

/**
 * Say what was switched off and why, once.
 *
 * A plugin that silently deactivates another plugin is indistinguishable from a
 * plugin that is broken, so this explains itself and then stops.
 */
function nibwp_render_redundant_addon_notice(): void
{
    if (!current_user_can('activate_plugins')) {
        return;
    }

    $deactivated = get_option(NIBWP_REDUNDANT_ADDONS_OPTION, []);
    if (!is_array($deactivated) || $deactivated === []) {
        return;
    }

    $names = implode(', ', array_map(static fn($id): string => 'NibWP ' . ucwords(str_replace('-', ' ', (string) $id)), $deactivated));

    wp_admin_notice(
        sprintf(
            /* translators: %s: comma-separated add-on names. */
            esc_html(_n(
                'NibWP switched off a duplicate add-on: %s. Your license already includes it in the main plugin, and running both copies at once can take the site down. Nothing was lost — the skill is still available under NibWP → Skills. You can delete the duplicate from your Plugins screen.',
                'NibWP switched off duplicate add-ons: %s. Your license already includes them in the main plugin, and running both copies at once can take the site down. Nothing was lost — the skills are still available under NibWP → Skills. You can delete the duplicates from your Plugins screen.',
                count($deactivated),
                'nibwp'
            )),
            '<strong>' . esc_html($names) . '</strong>'
        ),
        [
            'type' => 'warning',
            'dismissible' => true,
            'additional_classes' => ['nibwp-redundant-addon'],
        ]
    );

    delete_option(NIBWP_REDUNDANT_ADDONS_OPTION);
}
