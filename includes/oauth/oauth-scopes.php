<?php

declare(strict_types=1);

/**
 * OAuth scopes — what a connected client is actually allowed to do.
 *
 * An application password hands an AI client everything the WordPress user can
 * do, invisibly. Scopes are the reason to prefer signing in: the user sees what
 * is being asked for and can grant a subset.
 *
 * Scopes are derived from annotations the abilities already carry, rather than
 * from a hand-maintained list. A new ability is therefore governed the moment it
 * is registered — there is no second place to remember to update, and no way for
 * an ability to quietly end up ungoverned.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The scope catalogue, in the order the consent screen shows them.
 *
 * `danger` scopes are separated in the UI and never implied by anything else.
 *
 * @return array<string, array{label: string, description: string, danger: bool}>
 */
function nibwp_oauth_scopes(): array
{
    return [
        'mcp:read' => [
            'label' => __('Read your site', 'nibwp'),
            'description' => __('View posts, pages, media, settings and site information. Changes nothing.', 'nibwp'),
            'danger' => false,
        ],
        'mcp:write' => [
            'label' => __('Create and edit content', 'nibwp'),
            'description' => __('Add and update posts, pages, products and settings. Cannot delete.', 'nibwp'),
            'danger' => false,
        ],
        'mcp:manage' => [
            'label' => __('Delete and reorganise', 'nibwp'),
            'description' => __('Delete posts, pages, users and media, and run bulk changes. Deletions are not reversible from here.', 'nibwp'),
            'danger' => true,
        ],
        'mcp:files' => [
            'label' => __('Read and write files', 'nibwp'),
            'description' => __('Open and save files on the server: theme and plugin files, uploads, configuration.', 'nibwp'),
            'danger' => true,
        ],
        'mcp:code' => [
            'label' => __('Run code and build features', 'nibwp'),
            'description' => __('Write and run PHP in the sandbox — how the agent builds features rather than only editing content. It is the widest permission here.', 'nibwp'),
            'danger' => true,
        ],
    ];
}

/**
 * Scopes offered by default on the consent screen.
 *
 * Read and write only. Anything destructive starts unticked — the user has to
 * make a deliberate choice to hand it over.
 *
 * @return array<int, string>
 */
function nibwp_oauth_default_scopes(): array
{
    return ['mcp:read', 'mcp:write'];
}

/**
 * Keep only recognised scopes, in catalogue order.
 *
 * @param array<int, string>|string $requested
 * @return array<int, string>
 */
function nibwp_oauth_sanitize_scopes($requested): array
{
    if (is_string($requested)) {
        $requested = preg_split('/[\s,]+/', trim($requested)) ?: [];
    }
    if (!is_array($requested)) {
        return [];
    }

    $known = array_keys(nibwp_oauth_scopes());
    $clean = array_values(array_intersect($known, array_map('strval', $requested)));

    return $clean;
}

/**
 * Abilities whose names alone mark them as code execution, whatever their
 * annotations say. Belt and braces around the highest-consequence surface.
 *
 * @return array<int, string>
 */
function nibwp_oauth_code_abilities(): array
{
    return [
        'nibwp/execute-php',
        'nibwp/write-file',
        'nibwp/enable-file',
        'nibwp/disable-file',
    ];
}

/**
 * Which single scope governs an ability.
 *
 * Resolution order is deliberate — most dangerous wins, so an ability that is
 * both destructive and filesystem-touching is governed by the stricter of the
 * two rather than the looser.
 */
function nibwp_oauth_scope_for_ability(string $ability_name): string
{
    static $cache = [];

    if (isset($cache[$ability_name])) {
        return $cache[$ability_name];
    }

    $scope = nibwp_oauth_resolve_scope($ability_name);
    $cache[$ability_name] = $scope;

    return $scope;
}

/**
 * Uncached resolution behind nibwp_oauth_scope_for_ability().
 */
function nibwp_oauth_resolve_scope(string $ability_name): string
{
    if (in_array($ability_name, nibwp_oauth_code_abilities(), true)) {
        return 'mcp:code';
    }

    $ability = nibwp_oauth_find_ability($ability_name);

    // An ability we cannot inspect is governed by the strictest scope. Failing
    // closed here means a registration quirk cannot silently widen access.
    if ($ability === null) {
        return 'mcp:code';
    }

    $category = (string) $ability->get_category();
    if ($category === 'filesystem') {
        return 'mcp:files';
    }
    if ($category === 'sandbox') {
        return 'mcp:code';
    }

    $meta = $ability->get_meta();
    $annotations = is_array($meta['annotations'] ?? null) ? $meta['annotations'] : [];

    if (!empty($annotations['destructive'])) {
        return 'mcp:manage';
    }
    if (!empty($annotations['readonly'])) {
        return 'mcp:read';
    }

    return 'mcp:write';
}

/**
 * Look up a registered ability by name.
 */
function nibwp_oauth_find_ability(string $name): ?WP_Ability
{
    static $map = null;

    if ($map === null) {
        $map = [];
        if (function_exists('wp_get_abilities')) {
            foreach (wp_get_abilities() as $ability) {
                $map[$ability->get_name()] = $ability;
            }
        }
    }

    return $map[$name] ?? null;
}

/**
 * Whether the granted set covers an ability.
 *
 * @param array<int, string> $granted
 */
function nibwp_oauth_ability_allowed(string $ability_name, array $granted): bool
{
    return in_array(nibwp_oauth_scope_for_ability($ability_name), $granted, true);
}

// ---------------------------------------------------------------------------
// Enforcement
// ---------------------------------------------------------------------------

/**
 * Block tool calls the current token was not granted.
 *
 * Runs on `mcp_adapter_pre_tool_call`, which short-circuits on WP_Error. Only
 * applies to bearer-authenticated requests: an application password or a browser
 * session is unscoped by definition and keeps behaving exactly as before.
 *
 * @param mixed  $args
 * @param string $tool_name
 * @param mixed  $mcp_tool
 * @return mixed
 */
function nibwp_oauth_enforce_tool_scope($args, $tool_name, $mcp_tool = null)
{
    $token = nibwp_oauth_current_token();
    if ($token === null) {
        return $args;
    }

    $granted = (array) ($token['scopes'] ?? []);

    foreach (nibwp_oauth_abilities_behind_call($tool_name, $mcp_tool, (array) $args) as $ability_name) {
        if (nibwp_oauth_ability_allowed($ability_name, $granted)) {
            continue;
        }

        $needed = nibwp_oauth_scope_for_ability($ability_name);
        $catalogue = nibwp_oauth_scopes();

        nibwp_oauth_flag_insufficient_scope($needed);

        return new WP_Error(
            'nibwp_insufficient_scope',
            sprintf(
                /* translators: 1: the ability that was refused, 2: the permission label shown on the approval screen, 3: the scope slug, 4: the scopes this connection holds */
                __('Refused: %1$s needs the "%2$s" permission (%3$s), which this connection was not given. It holds: %4$s. If this permission is wider than the work calls for, say so rather than asking for it — that mismatch is worth reporting. If it is genuinely needed, the user can reconnect from NibWP → Connect and tick "%2$s" alone; nothing else needs granting. Do not retry this tool until they have; retrying will fail identically.', 'nibwp'),
                $ability_name,
                (string) ($catalogue[$needed]['label'] ?? $needed),
                $needed,
                $granted === [] ? __('nothing', 'nibwp') : implode(', ', $granted)
            ),
            ['status' => 403, 'scope' => $needed]
        );
    }

    return $args;
}

/**
 * Every ability a tool call will actually reach.
 *
 * Usually one. The exception is the adapter's own `execute-ability` tool, which
 * takes the real target as an argument — without unwrapping it, a token holding
 * only `mcp:read` could call any ability on the site through that one tool and
 * scopes would mean nothing.
 *
 * @param array<string, mixed> $args
 * @return array<int, string>
 */
function nibwp_oauth_abilities_behind_call(string $tool_name, $mcp_tool, array $args): array
{
    $names = [];
    $is_proxy = str_contains($tool_name, 'execute-ability');

    if (is_object($mcp_tool) && method_exists($mcp_tool, 'get_observability_context')) {
        $context = (array) $mcp_tool->get_observability_context();
        $reported = (string) ($context['ability_name'] ?? '');

        // The adapter's own abilities are the road, not the destination. Its
        // proxy declares itself destructive — correct for a tool that can reach
        // anything — but that is a statement about the road. Governing on it
        // meant every call arrived asking for delete rights, whatever it was
        // actually for, and a connection holding read and write could do
        // nothing at all: everything routes through that one tool.
        if ($reported !== '' && !str_starts_with($reported, 'mcp-adapter/')) {
            $names[] = $reported;
        }
    }

    // The proxy's target is in the payload. It is what the call really does,
    // and therefore the only thing worth checking.
    if ($is_proxy) {
        $target = '';
        foreach (['ability', 'ability_name', 'name'] as $key) {
            if (!empty($args[$key]) && is_string($args[$key])) {
                $target = $args[$key];
                break;
            }
        }

        if ($target !== '') {
            $names[] = $target;
        } elseif ($names === []) {
            // A proxy call naming no target reaches nothing and the adapter
            // will reject it, but a permission check that waves through the one
            // tool that can call everything is not a check. Fail closed.
            $names[] = 'mcp-adapter/execute-ability';
        }
    }

    // Last resort: the tool's own name. A caller that hands us no tool object
    // would otherwise reach every ability on the site unscoped — the whole
    // check quietly becoming a no-op, which is the worst way for a permission
    // system to fail. MCP tool names are ability names with the slash swapped
    // for a hyphen, so the name alone is enough to resolve one.
    // Only when it resolves to an ability that exists. The adapter's own tools
    // — discover-abilities, execute-ability — are not abilities themselves, and
    // an unresolvable guess fails closed into mcp:code, which would refuse the
    // very call a client makes to find out what it can do.
    if ($names === []) {
        $guess = preg_replace('/-/', '/', $tool_name, 1);
        if (is_string($guess) && str_contains($guess, '/') && nibwp_oauth_find_ability($guess) !== null) {
            $names[] = $guess;
        }
    }

    return array_values(array_unique($names));
}

/**
 * Remember that this request failed on scope, so the response can carry the
 * correct challenge header rather than a generic error.
 */
function nibwp_oauth_flag_insufficient_scope(?string $scope = null): ?string
{
    static $needed = null;

    if ($scope !== null) {
        $needed = $scope;
    }

    return $needed;
}
