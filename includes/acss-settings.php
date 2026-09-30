<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Automatic.css — one place that knows where ACSS actually keeps its settings.
 *
 * Three different option names were hardcoded across the ACSS abilities, and
 * on ACSS 4.x none of them is the live one: the store is `automatic_css_settings`.
 * A read against the wrong name returns an empty array, which is indistinguishable
 * from "ACSS is installed but unconfigured" — so an agent could conclude a site
 * had no palette and offer to build one over the top of a real production theme.
 *
 * ACSS ships its own settings model, so prefer that over any option name at all:
 *
 *   Automatic_CSS\Model\Database_Settings::get_instance()
 *       ->get_vars()                       // the live var map
 *       ->save_settings( $vars, true );    // writes + recompiles the CSS
 *
 * The option names stay only as a fallback for installs where the class is
 * missing, and are probed rather than assumed.
 */

/**
 * Option names ACSS has stored its settings under, current first.
 *
 * @return array<int,string>
 */
function nibwp_acss_known_option_names(): array
{
    return [
        'automatic_css_settings', // ACSS 4.x — the live one
        'automaticcss_settings',
        'acss_global_setting',
    ];
}

/**
 * The option name this site actually stores ACSS settings under.
 *
 * Probes the known names and returns the first that holds a non-empty array,
 * so a stale leftover row cannot shadow the real one. Falls back to the current
 * name when nothing is stored yet, which is the right target for a first write.
 */
function nibwp_acss_settings_option_name(): string
{
    $names = nibwp_acss_known_option_names();

    $resolved = $names[0];
    foreach ($names as $name) {
        $value = get_option($name, null);
        if (is_array($value) && $value !== []) {
            $resolved = $name;
            break;
        }
    }

    return (string) apply_filters('nibwp_acss_settings_option_name', $resolved);
}

/**
 * ACSS's own settings model, or null when the plugin isn't providing one.
 *
 * @return object|null
 */
function nibwp_acss_db_settings()
{
    $class = 'Automatic_CSS\\Model\\Database_Settings';
    if (!class_exists($class) || !method_exists($class, 'get_instance')) {
        return null;
    }

    try {
        $instance = $class::get_instance();
    } catch (\Throwable $e) {
        return null;
    }

    return is_object($instance) && method_exists($instance, 'get_vars') ? $instance : null;
}

/**
 * Is the ACSS plugin present?
 *
 * Presence only — say nothing here about whether it has been configured.
 */
function nibwp_acss_is_active(): bool
{
    return defined('ACSS_PLUGIN_FILE')
        || defined('ACSS_VERSION')
        || class_exists('\\Automatic_CSS\\Plugin')
        || nibwp_acss_db_settings() !== null;
}

/**
 * The installed ACSS version.
 *
 * 4.x defines no version constant, so the plugin header is the only source.
 * Returns '' when ACSS isn't installed.
 */
function nibwp_acss_version(): string
{
    if (defined('ACSS_VERSION')) {
        return (string) ACSS_VERSION;
    }
    if (defined('ACSS_PLUGIN_VERSION')) {
        return (string) ACSS_PLUGIN_VERSION;
    }
    if (!defined('ACSS_PLUGIN_FILE') || !is_readable((string) ACSS_PLUGIN_FILE)) {
        return '';
    }

    if (!function_exists('get_plugin_data')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $data = get_plugin_data((string) ACSS_PLUGIN_FILE, false, false);

    return (string) ($data['Version'] ?? '');
}

/**
 * The live ACSS settings map.
 *
 * ACSS's own model first; the resolved option only when the model is absent.
 *
 * @return array<string,mixed>
 */
function nibwp_acss_read_settings(): array
{
    $db = nibwp_acss_db_settings();
    if ($db !== null) {
        $vars = $db->get_vars();
        if (is_array($vars) && $vars !== []) {
            return $vars;
        }
    }

    $stored = get_option(nibwp_acss_settings_option_name(), []);

    return is_array($stored) ? $stored : [];
}

/**
 * Has this install been configured, as opposed to merely having ACSS present?
 *
 * This is the question an agent actually needs answered before offering to
 * generate a palette, and the one the old empty-array read got wrong.
 */
function nibwp_acss_is_configured(): bool
{
    return nibwp_acss_read_settings() !== [];
}

/**
 * Write the full settings map back, recompiling the CSS.
 *
 * @param array<string,mixed> $vars       Complete map, not a patch.
 * @param bool                $regenerate Recompile the CSS after saving.
 * @return array{via:string,regenerated:bool}|WP_Error
 */
function nibwp_acss_write_settings(array $vars, bool $regenerate = true)
{
    if ($vars === []) {
        return new WP_Error('acss_empty_write', 'Refusing to write an empty ACSS settings map.');
    }

    $db = nibwp_acss_db_settings();
    if ($db !== null && method_exists($db, 'save_settings')) {
        try {
            $db->save_settings($vars, $regenerate);
        } catch (\Throwable $e) {
            return new WP_Error('acss_save_failed', 'ACSS rejected the settings write: ' . $e->getMessage());
        }

        return ['via' => 'Automatic_CSS\\Model\\Database_Settings::save_settings', 'regenerated' => $regenerate];
    }

    update_option(nibwp_acss_settings_option_name(), $vars, false);

    return ['via' => nibwp_acss_settings_option_name(), 'regenerated' => false];
}

/**
 * Recompile ACSS's stylesheets.
 *
 * Saving the current map with the regenerate flag is ACSS's own recompile path,
 * so it needs no separate entry point and no guessing at engine internals.
 *
 * @return array{regenerated:bool,via:string}|WP_Error
 */
function nibwp_acss_regenerate_css()
{
    $vars = nibwp_acss_read_settings();
    if ($vars === []) {
        return new WP_Error('acss_not_configured', 'ACSS has no stored settings to regenerate from.');
    }

    $written = nibwp_acss_write_settings($vars, true);
    if (is_wp_error($written)) {
        return $written;
    }

    if (!$written['regenerated']) {
        // No settings model to drive the compiler — let anything listening try.
        do_action('acss_settings_updated', $vars);

        return ['regenerated' => false, 'via' => 'acss_settings_updated action'];
    }

    return ['regenerated' => true, 'via' => $written['via']];
}

/**
 * Directory ACSS compiles its stylesheets into.
 */
function nibwp_acss_css_dir(): string
{
    $uploads = wp_get_upload_dir();
    $base    = (string) ($uploads['basedir'] ?? '');

    return $base === '' ? '' : $base . '/automatic-css';
}

/**
 * The utility classes this install actually ships, read from the compiled CSS.
 *
 * Class names are read rather than listed, because a hardcoded list drifts from
 * the framework silently — and a wrong class name is invisible until the page
 * renders unstyled. Returns [] when ACSS has not compiled anything yet.
 *
 * @return array<int,string>
 */
function nibwp_acss_classes(): array
{
    $dir = nibwp_acss_css_dir();
    if ($dir === '' || !is_dir($dir)) {
        return [];
    }

    $files = glob($dir . '/*.css');
    if (!is_array($files) || $files === []) {
        return [];
    }

    $found = [];
    foreach ($files as $file) {
        $css = file_get_contents($file);
        if (!is_string($css) || $css === '') {
            continue;
        }
        // A class selector never follows a word character — without that guard
        // the tail of every decimal (.5rem, .25em) is scraped up as a class.
        if (!preg_match_all('/(?<![0-9A-Za-z_-])\.([a-z][a-z0-9_-]*)/', $css, $matches)) {
            continue;
        }
        foreach ($matches[1] as $class) {
            $found[$class] = true;
        }
    }

    $classes = array_keys($found);
    sort($classes);

    return $classes;
}

/**
 * Sort real class names into categories an agent can browse.
 *
 * Prefix rules, first match wins — order matters, since ACSS's color modifiers
 * (`text--dark`) share a prefix with its type scale (`text--l`).
 *
 * @param array<int,string> $classes
 * @return array<string,array<int,string>>
 */
function nibwp_acss_group_classes(array $classes): array
{
    $rules = [
        'colors'        => ['/^bg--/', '/^scheme--/', '/^is-bg$/', '/^overlay$/', '/^text--(dark|light)(-muted)?$/'],
        'typography'    => ['/^h[1-6]$/', '/^text--/', '/^header--/'],
        'buttons'       => ['/^btn--/'],
        'icons'         => ['/^icon--/', '/^icon-list$/'],
        'forms'         => ['/^form--/', '/^wsf-/'],
        'sizing'        => ['/^width--/'],
        'spacing'       => ['/^section--/', '/^smart-spacing/'],
        'layout'        => ['/^content-/', '/^content--/', '/^sticky$/', '/^unrelate$/'],
        'accessibility' => ['/^hidden-accessible$/', '/^skip-link$/'],
    ];

    $groups = array_fill_keys(array_keys($rules), []);
    $groups['other'] = [];

    foreach ($classes as $class) {
        $placed = false;
        foreach ($rules as $group => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $class)) {
                    $groups[$group][] = $class;
                    $placed = true;
                    break 2;
                }
            }
        }
        if (!$placed) {
            $groups['other'][] = $class;
        }
    }

    return array_filter($groups, static fn(array $g): bool => $g !== []);
}
