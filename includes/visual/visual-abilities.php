<?php

declare(strict_types=1);

/**
 * What the agent can do inside the workspace.
 *
 * Registered as ordinary abilities, which is the point: they travel down the
 * same MCP endpoint as everything else, obey the same OAuth scopes, and land in
 * the same audit log. Driving a browser is not a side channel.
 *
 * Annotations are load-bearing here. `readonly` marks the ones that only look;
 * everything else is `destructive: false` but still gated by the approval
 * prompt, because a click can be a Delete button.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Turn a bus reply into what an ability should return.
 *
 * @param array<string, mixed>|WP_Error $result
 * @return array<string, mixed>|WP_Error
 */
function nibwp_visual_result($result)
{
    return $result;
}

/**
 * Fill in the attributes a block needs to be editable once it lands.
 *
 * Inserting a block here goes straight to the editor: any block name, any
 * attributes, no skill in the way. That is the point — it is how an agent works
 * a page it can see — but it means a block can be inserted in a state its own
 * editor UI treats as unconfigured.
 *
 * kadence/rowlayout is the one that bites. Its `colLayout` defaults to "" and
 * the editor reads that as "never set up", replacing the whole row with its
 * layout picker; `columns` defaults to 2 regardless of how many columns are
 * actually there. The result renders on the front end and cannot be edited,
 * which is the worst way for this to fail — it looks like it worked.
 *
 * The same rule lives in the Kadence Pro converter for the skill path. It is
 * repeated rather than shared because that file is a premium skill's, pruned
 * out of the Free build, while this path exists on every install.
 *
 * @param array<string, mixed> $block
 * @return array<string, mixed>
 */
function nibwp_visual_normalize_block(array $block): array
{
    $name = (string) ($block['blockName'] ?? '');
    $attrs = is_array($block['attributes'] ?? null) ? $block['attributes'] : [];
    $inner = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];

    foreach ($inner as $i => $child) {
        if (is_array($child)) {
            $inner[$i] = nibwp_visual_normalize_block($child + ['blockName' => (string) ($child['block_name'] ?? '')]);
        }
    }

    if ($name === 'kadence/rowlayout') {
        $columns = 0;
        foreach ($inner as $child) {
            $child_name = is_array($child) ? (string) ($child['blockName'] ?? $child['block_name'] ?? '') : '';
            if ($child_name === 'kadence/column') {
                $columns++;
            }
        }
        $columns = max(1, $columns);
        $attrs['columns'] = $columns;
        $layout = (string) ($attrs['colLayout'] ?? '');
        if ($layout === '' || (function_exists('nibwp_kadence_col_layout_valid') && !nibwp_kadence_col_layout_valid($layout, $columns))) {
            $attrs['colLayout'] = 'equal';
        }
    }

    $block['attributes'] = $attrs;
    $block['innerBlocks'] = $inner;

    return $block;
}

/**
 * Keep a batch to the commands that exist, and make each step approval-gated
 * on its own terms — a batch must not become a way around the prompt.
 *
 * @param mixed $steps
 * @return array<int, array<string, mixed>>
 */
function nibwp_visual_sanitize_steps($steps): array
{
    // Must stay identical to the `command` enum in the batch schema below.
    // The schema is what actually rejects an unknown step, so a name listed
    // here but not there is only ever a trap for the next person to widen one
    // and not the other. Block editing is deliberately absent: batch resolves
    // to mcp:write, and block-delete is mcp:manage — letting it ride inside a
    // batch would hand out the deleting scope to anyone holding the writing one.
    $known = ['open', 'read', 'click', 'fill', 'viewport', 'audit', 'console', 'tabs'];
    $out = [];

    foreach ((array) $steps as $step) {
        if (!is_array($step)) {
            continue;
        }
        $command = sanitize_key((string) ($step['command'] ?? ''));
        if (!in_array($command, $known, true)) {
            continue;
        }
        $out[] = [
            'command' => $command,
            'payload' => is_array($step['payload'] ?? null) ? $step['payload'] : [],
        ];
        if (count($out) >= 20) {
            break;
        }
    }

    return $out;
}

function nibwp_visual_register_abilities(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }

    $target = [
        'type' => 'string',
        'description' => 'CSS selector for the element, as seen in the page the agent last read. Prefer ids and data attributes over long descendant chains.',
    ];

    wp_register_ability('nibwp/visual-open', [
        'label' => __('Open a page in the workspace', domain: 'nibwp'),
        'description' => 'Opens a URL on this site in a new workspace tab and returns the page once it has loaded. Only URLs on this site can be opened. Use this before reading or interacting with a page.',
        'category' => 'visual',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'description' => 'A URL on this site. Relative paths such as /wp-admin/ are resolved against the site address.'],
                'title' => ['type' => 'string', 'description' => 'Optional label for the tab.'],
            ],
            'required' => ['url'],
        ],
        // The block editor can take well over the default to finish loading,
        // and timing out on it means the agent never learns the page opened.
        'execute_callback' => static fn(array $input) => nibwp_visual_dispatch('open', [
            'url' => (string) ($input['url'] ?? ''),
            'title' => (string) ($input['title'] ?? ''),
        ], 45),
        'permission_callback' => 'nibwp_permission_callback',
        // Opening a page changes nothing on the site: it points a tab the user
        // is already looking at somewhere they could have clicked to anyway.
        // Charging content-write for that meant a read-only connection could
        // not so much as show them a page.
        'meta' => ['mcp' => ['public' => true], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
    ]);

    wp_register_ability('nibwp/visual-read', [
        'label' => __('Read the current page', domain: 'nibwp'),
        'description' => 'Returns the visible text, headings, links, form fields and interactive elements of the active workspace tab, each with a selector the interaction abilities accept. This is how the agent sees the page.',
        'category' => 'visual',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'selector' => ['type' => 'string', 'description' => 'Limit the read to one region of the page. Defaults to the whole document.'],
                'max_elements' => ['type' => 'integer', 'description' => 'Cap on interactive elements returned. Default 150.'],
            ],
        ],
        'execute_callback' => static fn(array $input) => nibwp_visual_dispatch('read', [
            'selector' => (string) ($input['selector'] ?? ''),
            'maxElements' => (int) ($input['max_elements'] ?? 150),
        ]),
        'permission_callback' => 'nibwp_permission_callback',
        'meta' => ['mcp' => ['public' => true], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
    ]);

    wp_register_ability('nibwp/visual-click', [
        'label' => __('Click an element', domain: 'nibwp'),
        'description' => 'Clicks an element in the active workspace tab and returns what changed. The element is scrolled into view and highlighted first, so the person watching sees what is about to happen.',
        'category' => 'visual',
        'input_schema' => [
            'type' => 'object',
            'properties' => ['selector' => $target],
            'required' => ['selector'],
        ],
        'execute_callback' => static fn(array $input) => nibwp_visual_dispatch('click', [
            'selector' => (string) ($input['selector'] ?? ''),
        ]),
        'permission_callback' => 'nibwp_permission_callback',
        'meta' => ['mcp' => ['public' => true], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
    ]);

    wp_register_ability('nibwp/visual-fill', [
        'label' => __('Fill a form field', domain: 'nibwp'),
        'description' => 'Types a value into an input, textarea or select in the active workspace tab, firing the events a real keystroke would so scripts on the page react normally.',
        'category' => 'visual',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'selector' => $target,
                'value' => ['type' => 'string', 'description' => 'The value to enter.'],
                'submit' => ['type' => 'boolean', 'description' => 'Submit the containing form afterwards. Default false.'],
            ],
            'required' => ['selector', 'value'],
        ],
        'execute_callback' => static fn(array $input) => nibwp_visual_dispatch('fill', [
            'selector' => (string) ($input['selector'] ?? ''),
            'value' => (string) ($input['value'] ?? ''),
            'submit' => !empty($input['submit']),
        ]),
        'permission_callback' => 'nibwp_permission_callback',
        'meta' => ['mcp' => ['public' => true], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
    ]);

    wp_register_ability('nibwp/visual-viewport', [
        'label' => __('Resize the viewport', domain: 'nibwp'),
        'description' => 'Resizes the workspace viewport to a named breakpoint or an exact width, so the agent can check how a page behaves on phones and tablets without leaving the session.',
        'category' => 'visual',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'preset' => ['type' => 'string', 'enum' => ['mobile', 'tablet', 'laptop', 'desktop', 'full'], 'description' => 'A named breakpoint. mobile 390, tablet 768, laptop 1280, desktop 1600, full fills the window.'],
                'width' => ['type' => 'integer', 'description' => 'Exact width in pixels, used instead of a preset.'],
            ],
        ],
        'execute_callback' => static fn(array $input) => nibwp_visual_dispatch('viewport', [
            'preset' => (string) ($input['preset'] ?? ''),
            'width' => (int) ($input['width'] ?? 0),
        ]),
        'permission_callback' => 'nibwp_permission_callback',
        'meta' => ['mcp' => ['public' => true], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
    ]);

    wp_register_ability('nibwp/visual-audit', [
        'label' => __('Check the page for problems', domain: 'nibwp'),
        'description' => 'Runs accessibility and layout checks against the live rendered page: colour contrast below the readable threshold, images with no alt text, form fields with no label, heading levels that skip, and anything overflowing the viewport horizontally. Reports what it found with selectors.',
        'category' => 'visual',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'checks' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => ['contrast', 'alt', 'labels', 'headings', 'overflow']],
                    'description' => 'Which checks to run. Defaults to all of them.',
                ],
            ],
        ],
        'execute_callback' => static fn(array $input) => nibwp_visual_dispatch('audit', [
            'checks' => is_array($input['checks'] ?? null) ? array_map('strval', $input['checks']) : [],
        ], 40),
        'permission_callback' => 'nibwp_permission_callback',
        'meta' => ['mcp' => ['public' => true], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
    ]);

    wp_register_ability('nibwp/visual-console', [
        'label' => __('Read errors from the page', domain: 'nibwp'),
        'description' => 'Returns JavaScript errors, console warnings and failed network requests recorded in the active workspace tab since it loaded. Use this when something on the page did not behave as expected.',
        'category' => 'visual',
        'input_schema' => ['type' => 'object', 'properties' => new stdClass()],
        'execute_callback' => static fn(array $input) => nibwp_visual_dispatch('console', []),
        'permission_callback' => 'nibwp_permission_callback',
        'meta' => ['mcp' => ['public' => true], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
    ]);

    // Block editor. The editor is a same-origin frame inside the workspace, so
    // these reach its own wp.data store rather than scraping rendered markup —
    // the agent sees blocks, attributes and clientIds, and edits through the
    // same API the editor uses on itself. Nothing is loaded on anybody else's
    // editor screen to make this work.
    wp_register_ability('nibwp/visual-blocks', [
        'label' => __('Read the blocks on the open page', domain: 'nibwp'),
        'description' => 'Lists the blocks in the block editor open in the workspace: name, clientId, attributes and a text preview, nested to the requested depth. The clientId is what the other block abilities accept. Needs a block editor screen open in the workspace.',
        'category' => 'visual',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'depth' => ['type' => 'integer', 'description' => 'How far to recurse into nested blocks. Default 3.'],
                'text' => ['type' => 'boolean', 'description' => 'Include a short text preview of each block. Default true.'],
                'name' => ['type' => 'string', 'description' => 'Only blocks whose name contains this, e.g. "heading".'],
            ],
        ],
        'execute_callback' => static fn(array $input) => nibwp_visual_dispatch('blocks', [
            'depth' => (int) ($input['depth'] ?? 3),
            'text' => !isset($input['text']) || !empty($input['text']),
            'name' => (string) ($input['name'] ?? ''),
        ]),
        'permission_callback' => 'nibwp_permission_callback',
        'meta' => ['mcp' => ['public' => true], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
    ]);

    wp_register_ability('nibwp/visual-block-insert', [
        'label' => __('Insert a block', domain: 'nibwp'),
        'description' => 'Inserts a block into the editor open in the workspace. Give the block name (core/paragraph, core/heading, core/image and so on), its attributes, and optionally a parent clientId and a position among its siblings. Nested children can be given inline.',
        'category' => 'visual',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'block_name' => ['type' => 'string', 'description' => 'Fully qualified block name, e.g. core/heading.'],
                'attributes' => ['type' => 'object', 'description' => 'Initial attributes. Use visual-block-schema to discover what a block accepts.'],
                'inner_blocks' => ['type' => 'array', 'description' => 'Child blocks, each with block_name, attributes and inner_blocks.', 'items' => ['type' => 'object']],
                'parent' => ['type' => 'string', 'description' => 'clientId of the parent to insert into. Omit for the document root.'],
                'position' => ['type' => 'integer', 'description' => 'Zero-based position among siblings. Omit to append.'],
            ],
            'required' => ['block_name'],
        ],
        'execute_callback' => static fn(array $input) => nibwp_visual_dispatch('block-insert', nibwp_visual_normalize_block([
            'blockName' => (string) ($input['block_name'] ?? ''),
            'attributes' => is_array($input['attributes'] ?? null) ? $input['attributes'] : [],
            'innerBlocks' => is_array($input['inner_blocks'] ?? null) ? $input['inner_blocks'] : [],
        ]) + [
            'parent' => (string) ($input['parent'] ?? ''),
            'position' => isset($input['position']) ? (int) $input['position'] : -1,
        ]),
        'permission_callback' => 'nibwp_permission_callback',
        'meta' => ['mcp' => ['public' => true], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
    ]);

    wp_register_ability('nibwp/visual-block-update', [
        'label' => __('Change a block', domain: 'nibwp'),
        'description' => 'Updates the attributes of one block in the editor open in the workspace, found by its clientId. Only the attributes given are changed.',
        'category' => 'visual',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'client_id' => ['type' => 'string', 'description' => 'clientId from visual-blocks.'],
                'attributes' => ['type' => 'object', 'description' => 'Attributes to change.'],
            ],
            'required' => ['client_id', 'attributes'],
        ],
        'execute_callback' => static fn(array $input) => nibwp_visual_dispatch('block-update', [
            'clientId' => (string) ($input['client_id'] ?? ''),
            'attributes' => is_array($input['attributes'] ?? null) ? $input['attributes'] : [],
        ]),
        'permission_callback' => 'nibwp_permission_callback',
        'meta' => ['mcp' => ['public' => true], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
    ]);

    wp_register_ability('nibwp/visual-block-delete', [
        'label' => __('Remove a block', domain: 'nibwp'),
        'description' => 'Removes one block, and everything inside it, from the editor open in the workspace. Destructive: the block is gone once the post is saved.',
        'category' => 'visual',
        'input_schema' => [
            'type' => 'object',
            'properties' => ['client_id' => ['type' => 'string', 'description' => 'clientId from visual-blocks.']],
            'required' => ['client_id'],
        ],
        'execute_callback' => static fn(array $input) => nibwp_visual_dispatch('block-delete', [
            'clientId' => (string) ($input['client_id'] ?? ''),
        ]),
        'permission_callback' => 'nibwp_permission_callback',
        'meta' => ['mcp' => ['public' => true], 'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false]],
    ]);

    wp_register_ability('nibwp/visual-block-schema', [
        'label' => __('What attributes does a block accept', domain: 'nibwp'),
        'description' => 'Returns the attribute schema for a block type as the editor open in the workspace understands it, or lists every block registered on this site. Use it before inserting or changing a block rather than guessing attribute names.',
        'category' => 'visual',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'block_name' => ['type' => 'string', 'description' => 'A block name. Omit to list every block registered on this site.'],
                'search' => ['type' => 'string', 'description' => 'Filter the list by name or title.'],
            ],
        ],
        'execute_callback' => static fn(array $input) => nibwp_visual_dispatch('block-schema', [
            'blockName' => (string) ($input['block_name'] ?? ''),
            'search' => (string) ($input['search'] ?? ''),
        ]),
        'permission_callback' => 'nibwp_permission_callback',
        'meta' => ['mcp' => ['public' => true], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
    ]);

    wp_register_ability('nibwp/visual-batch', [
        'label' => __('Run several workspace steps at once', domain: 'nibwp'),
        'description' => 'Runs a sequence of workspace steps in one call: open a page, read it, click something, read it again. Each step is the name of a visual ability without the prefix (open, read, click, fill, viewport, audit, console, tabs) plus its arguments. The sequence stops at the first failure, because a later step usually assumes the earlier one worked. Use this instead of several separate calls when the steps are known in advance — it is one round trip rather than one per step.',
        'category' => 'visual',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'steps' => [
                    'type' => 'array',
                    'description' => 'The steps, in order.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'command' => ['type' => 'string', 'enum' => ['open', 'read', 'click', 'fill', 'viewport', 'audit', 'console', 'tabs']],
                            'payload' => ['type' => 'object', 'description' => 'Arguments for that step, same shape as the matching ability.'],
                        ],
                        'required' => ['command'],
                    ],
                ],
            ],
            'required' => ['steps'],
        ],
        'execute_callback' => static fn(array $input) => nibwp_visual_dispatch('batch', [
            'steps' => nibwp_visual_sanitize_steps($input['steps'] ?? []),
        ], 60),
        'permission_callback' => 'nibwp_permission_callback',
        'meta' => ['mcp' => ['public' => true], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
    ]);

    wp_register_ability('nibwp/visual-tabs', [
        'label' => __('List and switch workspace tabs', domain: 'nibwp'),
        'description' => 'Lists the tabs currently open in the workspace. Pass a tab id to focus it, or to close it, so the agent can work across several pages at once.',
        'category' => 'visual',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'focus' => ['type' => 'string', 'description' => 'Id of a tab to bring to the front.'],
                'close' => ['type' => 'string', 'description' => 'Id of a tab to close.'],
                'reload' => ['type' => 'boolean', 'description' => 'Reload the active tab.'],
            ],
        ],
        'execute_callback' => static fn(array $input) => nibwp_visual_dispatch('tabs', [
            'focus' => (string) ($input['focus'] ?? ''),
            'close' => (string) ($input['close'] ?? ''),
            'reload' => !empty($input['reload']),
        ]),
        'permission_callback' => 'nibwp_permission_callback',
        'meta' => ['mcp' => ['public' => true], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => false]],
    ]);
}
