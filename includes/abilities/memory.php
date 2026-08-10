<?php

declare(strict_types=1);

/**
 * Abilities: Memory system for AI agent context persistence.
 */

if (!defined('ABSPATH')) {
    exit();
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Retrieve all stored memory entries.
 *
 * @return array<int, array<string, mixed>>
 */
function nibwp_memory_get_all(): array
{
    $data = get_option('nibwp_memory_store', []);
    return is_array($data) ? $data : [];
}

/**
 * Persist the full memory store.
 *
 * @param array<int, array<string, mixed>> $memories
 */
function nibwp_memory_save_all(array $memories): void
{
    update_option('nibwp_memory_store', $memories, autoload: false);
}

/**
 * Find the index of a memory entry by key.
 *
 * @return int|null Index or null if not found.
 */
function nibwp_memory_find_index(array $memories, string $key): ?int
{
    foreach ($memories as $index => $entry) {
        if (($entry['key'] ?? '') === $key) {
            return $index;
        }
    }
    return null;
}

// ---------------------------------------------------------------------------
// Ability 1: memory-store
// ---------------------------------------------------------------------------

wp_register_ability('nibwp/memory-store', [
    'label' => __('Memory Store', domain: 'nibwp'),
    'description' => __(
        'Store or update a memory entry. AI agents use this to persist project conventions, decisions, patterns, and context across sessions. Each entry is a key-value pair with optional tags.',
        domain: 'nibwp',
    ),
    'category' => 'memory',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'key' => [
                'type' => 'string',
                'description' => 'Unique identifier for the memory entry (e.g. "project-conventions", "color-palette").',
                'minLength' => 1,
                'maxLength' => 100,
            ],
            'value' => [
                'type' => 'string',
                'description' => 'The content to store. Supports plain text, bullet points, code snippets, configuration values.',
                'minLength' => 1,
                'maxLength' => 10000,
            ],
            'tags' => [
                'type' => 'array',
                'description' => 'Categorization tags for easy retrieval.',
                'items' => ['type' => 'string', 'maxLength' => 50],
                'default' => [],
            ],
        ],
        'required' => ['key', 'value'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'entry' => [
                'type' => 'object',
                'properties' => [
                    'key' => ['type' => 'string'],
                    'value' => ['type' => 'string'],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'created_at' => ['type' => 'string'],
                    'updated_at' => ['type' => 'string'],
                ],
            ],
            'total_memories' => ['type' => 'integer'],
            'warning' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => 'nibwp_memory_store',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Use this to remember project conventions, decisions, patterns, and context between sessions. Good memory keys: "project-conventions", "color-palette", "api-endpoints", "component-patterns", "deployment-notes", "known-issues". Store structured information - bullet points, code snippets, configuration values. Tag memories for easy retrieval: ["design", "backend", "config", "conventions"].',
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

/**
 * Store or update a memory entry.
 *
 * @param array $input Input with 'key', 'value', optional 'tags'.
 * @return array|WP_Error
 */
function nibwp_memory_store(array $input): array|WP_Error
{
    $key = trim((string) ($input['key'] ?? ''));
    $value = (string) ($input['value'] ?? '');
    $tags = $input['tags'] ?? [];

    if ($key === '' || strlen($key) > 100) {
        return new WP_Error('invalid_key', 'Key must be between 1 and 100 characters.');
    }

    if ($value === '' || strlen($value) > 10000) {
        return new WP_Error('invalid_value', 'Value must be between 1 and 10,000 characters.');
    }

    if (!is_array($tags)) {
        $tags = [];
    }

    // Sanitize tags.
    $tags = array_values(array_unique(array_filter(
        array_map(static fn($t) => substr(trim((string) $t), 0, 50), $tags),
        static fn($t) => $t !== '',
    )));

    $memories = nibwp_memory_get_all();
    $now = gmdate('c');
    $existing_index = nibwp_memory_find_index($memories, $key);

    if ($existing_index !== null) {
        // Update existing entry, preserving created_at.
        $memories[$existing_index]['value'] = $value;
        $memories[$existing_index]['tags'] = $tags;
        $memories[$existing_index]['updated_at'] = $now;
        $entry = $memories[$existing_index];
    } else {
        // Check capacity before creating.
        if (count($memories) >= 100) {
            return new WP_Error(
                'memory_limit_reached',
                'Memory store is full (100 entries). Delete some entries before adding new ones.',
            );
        }

        $entry = [
            'key' => $key,
            'value' => $value,
            'tags' => $tags,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $memories[] = $entry;
    }

    nibwp_memory_save_all($memories);

    $total = count($memories);
    $result = [
        'entry' => $entry,
        'total_memories' => $total,
    ];

    if ($total >= 80) {
        $result['warning'] = sprintf(
            'Memory store is at %d/100 entries. Consider removing outdated memories.',
            $total,
        );
    }

    return $result;
}

// ---------------------------------------------------------------------------
// Ability 2: memory-recall
// ---------------------------------------------------------------------------

wp_register_ability('nibwp/memory-recall', [
    'label' => __('Memory Recall', domain: 'nibwp'),
    'description' => __(
        'Recall one or more stored memory entries. Filter by key, tags, or search term. Returns all entries when no filters are provided.',
        domain: 'nibwp',
    ),
    'category' => 'memory',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'key' => [
                'type' => 'string',
                'description' => 'Retrieve a specific memory entry by its key.',
            ],
            'tags' => [
                'type' => 'array',
                'description' => 'Filter entries matching ANY of these tags.',
                'items' => ['type' => 'string'],
            ],
            'search' => [
                'type' => 'string',
                'description' => 'Search term to match against keys and values (case-insensitive).',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'entries' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'key' => ['type' => 'string'],
                        'value' => ['type' => 'string'],
                        'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'created_at' => ['type' => 'string'],
                        'updated_at' => ['type' => 'string'],
                    ],
                ],
            ],
            'count' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => 'nibwp_memory_recall',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Recall stored project context before starting work. Check for relevant memories at the beginning of each session. Use search to find memories by keyword. Use tags to find related memories. Always check memory before asking the user questions they may have already answered.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * Recall memory entries with optional filtering.
 *
 * @param array $input Input with optional 'key', 'tags', 'search'.
 * @return array|WP_Error
 */
function nibwp_memory_recall(array $input): array|WP_Error
{
    $memories = nibwp_memory_get_all();

    $key = isset($input['key']) ? trim((string) $input['key']) : null;
    $tags = isset($input['tags']) && is_array($input['tags']) ? $input['tags'] : null;
    $search = isset($input['search']) ? trim((string) $input['search']) : null;

    // Specific key lookup.
    if ($key !== null && $key !== '') {
        $index = nibwp_memory_find_index($memories, $key);
        if ($index === null) {
            return [
                'entries' => [],
                'count' => 0,
            ];
        }
        return [
            'entries' => [$memories[$index]],
            'count' => 1,
        ];
    }

    $results = $memories;

    // Filter by tags (match ANY).
    if ($tags !== null && count($tags) > 0) {
        $tag_set = array_map('strtolower', $tags);
        $results = array_filter($results, static function (array $entry) use ($tag_set): bool {
            $entry_tags = array_map('strtolower', (array) ($entry['tags'] ?? []));
            return count(array_intersect($entry_tags, $tag_set)) > 0;
        });
    }

    // Filter by search term.
    if ($search !== null && $search !== '') {
        $needle = mb_strtolower($search, 'UTF-8');
        $results = array_filter($results, static function (array $entry) use ($needle): bool {
            $haystack_key = mb_strtolower((string) ($entry['key'] ?? ''), 'UTF-8');
            $haystack_value = mb_strtolower((string) ($entry['value'] ?? ''), 'UTF-8');
            return str_contains($haystack_key, $needle) || str_contains($haystack_value, $needle);
        });
    }

    $results = array_values($results);

    return [
        'entries' => $results,
        'count' => count($results),
    ];
}

// ---------------------------------------------------------------------------
// Ability 3: memory-delete
// ---------------------------------------------------------------------------

wp_register_ability('nibwp/memory-delete', [
    'label' => __('Memory Delete', domain: 'nibwp'),
    'description' => __(
        'Delete one or all memory entries. Provide a key to delete a specific entry, or set clear_all to true to remove everything.',
        domain: 'nibwp',
    ),
    'category' => 'memory',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'key' => [
                'type' => 'string',
                'description' => 'Key of the memory entry to delete.',
            ],
            'clear_all' => [
                'type' => 'boolean',
                'description' => 'Set to true to delete all memory entries.',
                'default' => false,
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'deleted_count' => ['type' => 'integer'],
            'remaining_count' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => 'nibwp_memory_delete',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Remove outdated memories when project context changes. Use clear_all only when starting fresh.',
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

/**
 * Delete memory entries.
 *
 * @param array $input Input with optional 'key' or 'clear_all'.
 * @return array|WP_Error
 */
function nibwp_memory_delete(array $input): array|WP_Error
{
    $key = isset($input['key']) ? trim((string) $input['key']) : null;
    $clear_all = ($input['clear_all'] ?? false) === true;

    if (($key === null || $key === '') && !$clear_all) {
        return new WP_Error(
            'missing_parameter',
            'Provide either a "key" to delete a specific entry or set "clear_all" to true.',
        );
    }

    $memories = nibwp_memory_get_all();

    if ($clear_all) {
        $deleted = count($memories);
        nibwp_memory_save_all([]);
        return [
            'deleted_count' => $deleted,
            'remaining_count' => 0,
        ];
    }

    // Delete by key.
    $index = nibwp_memory_find_index($memories, $key);
    if ($index === null) {
        return new WP_Error(
            'not_found',
            sprintf('No memory entry found with key "%s".', $key),
        );
    }

    array_splice($memories, $index, 1);
    nibwp_memory_save_all($memories);

    return [
        'deleted_count' => 1,
        'remaining_count' => count($memories),
    ];
}

// ---------------------------------------------------------------------------
// Ability 4: memory-list-keys
// ---------------------------------------------------------------------------

wp_register_ability('nibwp/memory-list-keys', [
    'label' => __('Memory List Keys', domain: 'nibwp'),
    'description' => __(
        'Quick overview of all stored memory keys with their tags and last updated timestamp. Does not return full values.',
        domain: 'nibwp',
    ),
    'category' => 'memory',
    'input_schema' => [
        'type' => 'object',
        'properties' => (object) [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'keys' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'key' => ['type' => 'string'],
                        'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'updated_at' => ['type' => 'string'],
                    ],
                ],
            ],
            'total' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => 'nibwp_memory_list_keys',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Quick overview of what has been remembered. Use this to decide which memories to recall in full.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * List all memory keys with tags and updated timestamps.
 *
 * @param array $input Unused (no input required).
 * @return array
 */
function nibwp_memory_list_keys(array $input): array
{
    $memories = nibwp_memory_get_all();

    $keys = array_map(static fn(array $entry): array => [
        'key' => $entry['key'] ?? '',
        'tags' => $entry['tags'] ?? [],
        'updated_at' => $entry['updated_at'] ?? '',
    ], $memories);

    return [
        'keys' => $keys,
        'total' => count($keys),
    ];
}
