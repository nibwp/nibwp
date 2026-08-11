<?php

declare(strict_types=1);

/**
 * What this site tells a command-line client about itself, before anyone signs in.
 *
 * The point of this route is to fail early and say why. Without it a CLI has to
 * open a browser, walk someone through consent, and only then discover that the
 * plugin is too old or that AI Abilities were never switched on — by which time
 * the person has approved a connection that cannot work.
 *
 * It is public because it has to be answerable before there is a token, so it
 * carries nothing a stranger could not already learn. Exact WordPress and PHP
 * versions are deliberately reported as compatibility booleans rather than
 * version strings: a client needs to know whether it will work, and an
 * unauthenticated caller does not need a shopping list of what to exploit.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The command-line contract this build speaks.
 *
 * Bumped only when a client written against the previous number would
 * misbehave, not when abilities are added — abilities are discovered at runtime
 * and need no version negotiation.
 */
const NIBWP_CLI_CONTRACT = 1;

add_action('rest_api_init', 'nibwp_cli_register_info_route');

function nibwp_cli_register_info_route(): void
{
    register_rest_route('nibwp/v1', '/cli/info', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => 'nibwp_cli_info',
    ]);
}

function nibwp_cli_info(): WP_REST_Response
{
    $mcp_url = function_exists('nibwp_oauth_resource_id')
        ? nibwp_oauth_resource_id()
        : untrailingslashit(rest_url('mcp/nibwp'));

    $scopes = function_exists('nibwp_oauth_scopes') ? array_keys(nibwp_oauth_scopes()) : [];

    $response = new WP_REST_Response([
        'plugin_version' => NIBWP_VERSION,
        'cli_contract' => NIBWP_CLI_CONTRACT,
        'mcp_url' => $mcp_url,
        'scopes_supported' => $scopes,
        'features' => nibwp_cli_features(),
        'tier' => nibwp_is_pro() ? 'pro' : 'free',
        // The one thing that most often explains "it connected but there are no
        // tools": the switch on the Connect screen is off.
        'abilities_enabled' => function_exists('nibwp_is_enabled') ? nibwp_is_enabled() : false,
        // The plugin header's own minimums, so a client and the plugin never
        // disagree about what this site supports.
        'wp_supported' => version_compare(get_bloginfo('version'), '6.5', '>='),
        'php_supported' => version_compare(PHP_VERSION, '8.0', '>='),
    ]);

    // Same treatment as the OAuth discovery documents: read by clients from
    // anywhere, cached briefly so a doctor run does not hammer the site.
    $response->header('Access-Control-Allow-Origin', '*');
    $response->header('Cache-Control', 'public, max-age=300');

    return $response;
}

/**
 * Capabilities a client may rely on, named rather than inferred from a version.
 *
 * A client that checks for `upload` here does the right thing on a site that
 * has the ability and degrades with a clear message on one that does not. A
 * client that instead compared version numbers would be wrong the first time a
 * feature was backported or a build shipped without the premium directory.
 *
 * @return array<int, string>
 */
function nibwp_cli_features(): array
{
    $features = ['oauth', 'abilities', 'bridge'];

    foreach (
        [
            'workflows' => 'nibwp/list-workflows',
            'upload' => 'nibwp/create-upload-link',
            'find-tools' => 'nibwp/find-tools',
            'skills' => 'nibwp/load-skill-playbook',
            // Reading files is free; writing them is not. A client that assumes
            // both from one "files" flag tells someone to edit a theme locally
            // and then fails at the last step, after the work is done.
            'files-read' => 'nibwp/read-file',
            'files-write' => 'nibwp/write-file',
            'audit-log' => 'nibwp/read-audit-log',
            'snapshot' => 'nibwp/migration-export-content',
        ] as $feature => $ability
    ) {
        if (nibwp_cli_has_ability($ability)) {
            $features[] = $feature;
        }
    }

    return $features;
}

/**
 * Whether an ability is registered on this request.
 *
 * Abilities register on `wp_abilities_api_init`, which has already run by the
 * time a REST callback executes, so this reflects what a client would actually
 * be able to call.
 */
function nibwp_cli_has_ability(string $name): bool
{
    if (function_exists('nibwp_has_ability')) {
        return nibwp_has_ability($name);
    }

    return function_exists('wp_get_ability') && wp_get_ability($name) !== null;
}
