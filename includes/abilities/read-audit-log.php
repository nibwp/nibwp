<?php

declare(strict_types=1);

/**
 * Ability: read this site's own record of what assistants have done.
 *
 * The audit log has always existed for a human to read after the fact. Exposing
 * it as an ability makes it something to act on: the command-line client turns a
 * run of calls into a tape it can replay against another site, which is how a
 * change an assistant reasoned out once becomes a change that happens the same
 * way on forty sites without a model in the loop.
 *
 * Entries come back oldest first. A replay in any other order is not a replay.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('nibwp/read-audit-log', [
    'label' => __('Read Audit Log', 'nibwp'),
    'description' => 'Returns this site\'s log of assistant tool calls, oldest first, with the arguments each was given. Use it to see what was actually done and in what order, or to capture a sequence of calls for replay elsewhere. Note that recorded arguments include whatever was passed — file contents and PHP source among them — so this reads as widely as the calls it describes.',
    'category' => 'nibwp',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'since' => [
                'type' => 'string',
                'description' => 'How far back to look. A relative span ("45m", "2h", "7d") or a "Y-m-d H:i:s" timestamp in the site\'s own timezone. Defaults to the last hour.',
                'default' => '1h',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum entries to return.',
                'default' => 200,
                'minimum' => 1,
                'maximum' => 1000,
            ],
            'status' => [
                'type' => 'string',
                'description' => 'Keep only entries with this outcome: "success" or "error". Omit for both.',
                'enum' => ['success', 'error'],
            ],
            'client_id' => [
                'type' => 'string',
                'description' => 'Restrict to one connected client, so the result covers what a single assistant did rather than everything that touched the site.',
            ],
            'ability' => [
                'type' => 'string',
                'description' => 'Keep only calls to this ability, e.g. "nibwp/edit-file". Matches the ability name inside the recorded arguments, not the transport tool.',
            ],
            'mutations_only' => [
                'type' => 'boolean',
                'description' => 'Drop calls that only read. A replay of discovery and describe calls repeats nothing, so this defaults to false but is what a capture usually wants.',
                'default' => false,
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'entries' => [
                'type' => 'array',
                'description' => 'Matching calls, oldest first.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'tool_name' => ['type' => 'string', 'description' => 'The transport tool that carried the call.'],
                        'ability' => ['type' => 'string', 'description' => 'The ability actually invoked, where the call carried one.'],
                        'parameters' => ['type' => 'object', 'description' => 'Arguments the ability was given.'],
                        'status' => ['type' => 'string'],
                        'execution_time_ms' => ['type' => 'number'],
                        'client_id' => ['type' => 'string'],
                        'created_at' => ['type' => 'string'],
                    ],
                ],
            ],
            'total' => ['type' => 'integer', 'description' => 'Entries returned after filtering.'],
            'since' => ['type' => 'string', 'description' => 'The resolved lower bound, in site-local time.'],
            'site_url' => ['type' => 'string', 'description' => 'This site\'s address, so a caller can recognize its own URLs inside the arguments.'],
            'truncated' => ['type' => 'boolean', 'description' => 'Whether the limit cut the result short.'],
        ],
    ],
    'execute_callback' => 'nibwp_read_audit_log',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * Transport tools that wrap a real ability, and where the name of it lives.
 *
 * The log records the tool the transport called, which for everything routed
 * through the adapter is the same generic executor. Without unwrapping, every
 * entry would read `mcp-adapter-execute-ability` and say nothing.
 */
const NIBWP_AUDIT_ABILITY_KEYS = ['ability_name', 'ability', 'name'];

/**
 * Reads that are worth logging and never worth replaying.
 *
 * Matched on the ability name, so a site that adds its own read-only ability is
 * covered by the annotation check below rather than by this list.
 */
const NIBWP_AUDIT_READ_ABILITIES = [
    'mcp-adapter/discover-abilities',
    'mcp-adapter/get-ability-info',
    'nibwp/find-tools',
    'nibwp/read-file',
    'nibwp/list-directory',
    'nibwp/read-audit-log',
    'nibwp/memory-recall',
    'nibwp/memory-list-keys',
    'nibwp/preferences-get',
    'nibwp/load-skill-playbook',
    'nibwp/load-integration-playbook',
    'nibwp/list-workflows',
    'nibwp/get-workflow',
    'nibwp/templates-list',
];

/**
 * @param array $input
 * @return array|WP_Error
 */
function nibwp_read_audit_log(array $input = [])
{
    if (!function_exists('nibwp_audit_log_entries_since')) {
        return new WP_Error('audit_log_unavailable', 'The audit log is not available on this site.');
    }

    if (!get_option('nibwp_audit_log_enabled', true)) {
        return new WP_Error(
            'audit_log_disabled',
            'The audit log is switched off, so there is nothing recorded to read. Turn it on under NibWP → Settings.'
        );
    }

    $since_input = (string) ($input['since'] ?? '1h');
    $since_local = nibwp_audit_log_resolve_since($since_input);
    if (is_wp_error($since_local)) {
        return $since_local;
    }

    $limit = max(1, min(1000, (int) ($input['limit'] ?? 200)));
    $status = isset($input['status']) ? (string) $input['status'] : null;
    $client_id = isset($input['client_id']) ? (string) $input['client_id'] : null;
    $ability_filter = isset($input['ability']) ? (string) $input['ability'] : null;
    $mutations_only = ($input['mutations_only'] ?? false) === true;

    // One extra row, purely to know whether the limit cut anything off.
    $rows = nibwp_audit_log_entries_since($since_local, $limit + 1, $status, $client_id);
    $truncated = count($rows) > $limit;
    if ($truncated) {
        array_pop($rows);
    }

    $entries = [];
    foreach ($rows as $row) {
        $entry = nibwp_audit_log_shape_entry($row);

        if ($ability_filter !== null && $ability_filter !== '' && $entry['ability'] !== $ability_filter) {
            continue;
        }

        if ($mutations_only && nibwp_audit_log_entry_is_read($entry['ability'])) {
            continue;
        }

        $entries[] = $entry;
    }

    return [
        'entries' => $entries,
        'total' => count($entries),
        'since' => $since_local,
        'site_url' => untrailingslashit(home_url()),
        'truncated' => $truncated,
    ];
}

/**
 * Turn a stored row into something a caller can act on.
 *
 * @param object $row
 * @return array{id: int, tool_name: string, ability: string, parameters: array, status: string, execution_time_ms: float, client_id: string, created_at: string}
 */
function nibwp_audit_log_shape_entry(object $row): array
{
    $arguments = [];
    $raw = (string) ($row->arguments ?? '');
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $arguments = $decoded;
        }
    }

    $tool_name = (string) ($row->tool_name ?? '');
    $ability = $tool_name;
    $parameters = $arguments;

    foreach (NIBWP_AUDIT_ABILITY_KEYS as $key) {
        if (isset($arguments[$key]) && is_string($arguments[$key]) && $arguments[$key] !== '') {
            $ability = $arguments[$key];
            // The executor nests the real arguments; anything else passed them flat.
            $nested = $arguments['parameters'] ?? $arguments['arguments'] ?? null;
            $parameters = is_array($nested) ? $nested : [];
            break;
        }
    }

    return [
        'id' => (int) ($row->id ?? 0),
        'tool_name' => $tool_name,
        'ability' => $ability,
        'parameters' => $parameters,
        'status' => (string) ($row->result_status ?? ''),
        'execution_time_ms' => (float) ($row->execution_time_ms ?? 0),
        'client_id' => (string) ($row->client_id ?? ''),
        'created_at' => (string) ($row->created_at ?? ''),
    ];
}

/**
 * Whether an ability only reads.
 *
 * Asks the ability itself first — a site with its own registrations should not
 * need this file edited — and falls back to the list for anything that has been
 * unregistered since it was logged.
 */
function nibwp_audit_log_entry_is_read(string $ability): bool
{
    if (function_exists('wp_get_ability')) {
        $registered = wp_get_ability($ability);
        if ($registered !== null && method_exists($registered, 'get_meta')) {
            $meta = $registered->get_meta();
            $annotations = is_array($meta) ? ($meta['annotations'] ?? []) : [];
            if (is_array($annotations) && array_key_exists('readonly', $annotations)) {
                return $annotations['readonly'] === true;
            }
        }
    }

    return in_array($ability, NIBWP_AUDIT_READ_ABILITIES, true);
}

/**
 * Resolve "45m" or a timestamp into site-local 'Y-m-d H:i:s'.
 *
 * Site-local because that is how `created_at` is written — comparing it against
 * UTC silently returns the wrong hour's worth of history on every site that is
 * not on UTC, which is most of them.
 *
 * @return string|WP_Error
 */
function nibwp_audit_log_resolve_since(string $since)
{
    $since = trim($since);
    if ($since === '') {
        $since = '1h';
    }

    if (preg_match('/^(\d+)\s*([mhdw])$/i', $since, $matches) === 1) {
        $amount = (int) $matches[1];
        $unit = strtolower($matches[2]);
        $seconds = match ($unit) {
            'm' => $amount * MINUTE_IN_SECONDS,
            'h' => $amount * HOUR_IN_SECONDS,
            'd' => $amount * DAY_IN_SECONDS,
            'w' => $amount * WEEK_IN_SECONDS,
        };

        // current_time('timestamp') already carries the site's offset, so
        // formatting it with gmdate yields a local-looking string, which is
        // exactly what the column holds.
        return gmdate('Y-m-d H:i:s', current_time('timestamp') - $seconds);
    }

    $parsed = strtotime($since);
    if ($parsed === false) {
        return new WP_Error(
            'invalid_since',
            sprintf('Could not read "%s" as a time. Use a span like "45m", "2h", "7d", or a "Y-m-d H:i:s" timestamp.', $since)
        );
    }

    return gmdate('Y-m-d H:i:s', $parsed);
}
