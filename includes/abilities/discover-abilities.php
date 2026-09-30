<?php

declare(strict_types=1);

/**
 * Ability: Discover Abilities (NIBWP replacement).
 *
 * Replaces the MCP Adapter's bundled discover-abilities tool so the response
 * includes NIBWP's environment/usage instructions alongside the list of
 * abilities. These used to be sent via the MCP initialize handshake's
 * server_description, but some clients drop that; returning them here
 * guarantees the agent sees them on first tool discovery.
 */

if (!defined('ABSPATH')) {
    exit();
}

if (nibwp_has_ability('mcp-adapter/discover-abilities')) {
    wp_unregister_ability('mcp-adapter/discover-abilities');
}

if (nibwp_has_ability('mcp-adapter/discover-abilities')) {
    return;
}

wp_register_ability('mcp-adapter/discover-abilities', [
    'label' => __('Discover Abilities', 'nibwp'),
    'description' => 'Discover all available WordPress abilities in the system. Returns a list of all registered abilities with their basic information, plus NIBWP environment instructions.',
    'category' => 'mcp-adapter',
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'mandatory_routing' => [
                'type' => 'array',
                'description' => 'Routing contract per UNLOCKED skill. Decide with `use_when`, which says in plain language what the skill owns: if the user is asking for that work, the skill OWNS it and you MUST run its pipeline in order, however the request is phrased. `triggers` are regular expressions offered as a fast path only - they are NOT the boundary, and a request matching none of them can still belong to the skill. Improvisation is not allowed for work a skill owns. Read this BEFORE handling the user message. Empty array when no premium skills are unlocked.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'skill_id'           => ['type' => 'string'],
                        'name'               => ['type' => 'string'],
                        'tagline'            => ['type' => 'string'],
                        'use_when'           => ['type' => 'string', 'description' => 'Plain-language statement of what this skill owns. Route on this, not on the regexes.'],
                        'triggers'           => ['type' => 'array', 'items' => ['type' => 'string']],
                        'commands'           => ['type' => 'object'],
                        'pipeline'           => ['type' => 'array', 'items' => ['type' => 'object']],
                        'forbidden_actions'  => ['type' => 'array', 'items' => ['type' => 'string']],
                        'preflight_required' => ['type' => 'boolean'],
                        'preflight_ability'  => ['type' => 'string'],
                        'preflight_questions'=> ['type' => 'array', 'items' => ['type' => 'object']],
                        'before_answering'   => ['type' => 'string'],
                    ],
                ],
            ],
            'nibwp_instructions' => [
                'type' => 'string',
                'description' => 'NIBWP environment and usage guidance for the agent.',
            ],
            'abilities' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'label' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                    ],
                    'required' => ['name', 'label', 'description'],
                ],
            ],
            'unavailable_skills' => [
                'type' => 'array',
                'description' => 'Skills the user owns and has switched on, which cannot run on this site because a required plugin or theme was not detected. If the user asks for something one of these covers, TELL THEM it is unavailable and name the missing dependency. Do NOT substitute execute-php or hand-written HTML for a skill listed here.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'skill_id'      => ['type' => 'string'],
                        'name'          => ['type' => 'string'],
                        'reason'        => ['type' => 'string'],
                        'requires'      => ['type' => 'array', 'items' => ['type' => 'string']],
                        'tell_the_user' => ['type' => 'string'],
                    ],
                ],
            ],
            'locked_abilities' => [
                'type' => 'array',
                'description' => 'Premium abilities the user could unlock by purchasing NIBWP Pro or a Skill license. AI clients should mention these by name + unlock_url when the user asks for capabilities that match a locked entry.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'label' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'unlock_url' => ['type' => 'string'],
                        'tier' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
        'required' => ['nibwp_instructions', 'abilities'],
    ],
    'permission_callback' => static function (): bool|\WP_Error {
        if (!is_user_logged_in()) {
            return new \WP_Error('authentication_required', 'User must be authenticated to access this ability');
        }
        /** @var string $cap */
        $cap = apply_filters('mcp_adapter_discover_abilities_capability', value: 'read');
        if (!current_user_can($cap)) {
            return new \WP_Error('insufficient_capability', sprintf('User lacks required capability: %s', $cap));
        }
        return true;
    },
    'execute_callback' => static function (): array {
        $ability_list = [];
        foreach (wp_get_abilities() as $ability) {
            $meta = $ability->get_meta();
            if (!($meta['mcp']['public'] ?? false)) {
                continue;
            }
            if (($meta['mcp']['type'] ?? 'tool') !== 'tool') {
                continue;
            }
            $ability_list[] = [
                'name' => $ability->get_name(),
                'label' => $ability->get_label(),
                'description' => $ability->get_description(),
            ];
        }

        // Put the tools a live skill's pipeline names at the front.
        //
        // Order, not truncation. Descriptions run to a median of 163 bytes and
        // are what an agent uses to choose between 336 tools — cutting them to
        // save space would buy a smaller payload with worse choices. What we
        // can fix for free is position: the abilities a skill actually needs
        // were scattered through the list (execute-php sat at 183), so the
        // routing contract named steps the agent then had to hunt for.
        $pipeline_first = [];
        foreach (function_exists('nibwp_skills_skill_cards') ? nibwp_skills_skill_cards() : [] as $card) {
            foreach ((array) ($card['pipeline'] ?? []) as $step) {
                $name = is_array($step) ? (string) ($step['ability'] ?? '') : (string) $step;
                if ($name !== '') {
                    $pipeline_first[$name] = true;
                }
            }
        }
        if ($pipeline_first !== []) {
            usort($ability_list, static function (array $a, array $b) use ($pipeline_first): int {
                $a_first = isset($pipeline_first[$a['name']]) ? 0 : 1;
                $b_first = isset($pipeline_first[$b['name']]) ? 0 : 1;
                return $a_first === $b_first ? 0 : $a_first <=> $b_first;
            });
        }

        // Locked-abilities catalog — Pro abilities the user does NOT currently
        // have access to, with an unlock URL the agent can surface to the user
        // when they ask for capabilities that match. Cross-sell at the moment
        // of intent: the most effective conversion channel for AI-driven UX.
        $locked = [];
        $is_pro = function_exists('nibwp_is_pro') && nibwp_is_pro();
        $has_bundle = false;
        if (function_exists('nibwp_entitlements')) {
            $has_bundle = in_array('skill:*', (array) nibwp_entitlements(), true);
        }
        $pricing_url = function_exists('nibwp_pricing_url') ? nibwp_pricing_url() : 'https://nibwp.com/pricing';

        if (!$is_pro || !$has_bundle) {
            // Premium integration manager-style abilities (per nibwp_premium_integrations()).
            $integration_abilities = [
                'nibwp/elementor-create-page'        => ['Elementor — create page',         'Build a new Elementor page from a template or section list.', 'pro'],
                'nibwp/bricks-create-template'       => ['Bricks — create template',         'Generate a Bricks header/footer/content/archive template.',   'pro'],
                'nibwp/etchwp-manage-blocks'         => ['EtchWP — manage blocks',           'List / create / update / delete EtchWP custom blocks.',       'skill:etchwp-pro'],
                'nibwp/etchwp-pro-html-to-component' => ['EtchWP Pro — HTML → component',    'Convert pasted HTML / image / Figma into a clean EtchWP component (BEM, ACSS tokens).', 'skill:etchwp-pro'],
                'nibwp/acss-update-variables'        => ['ACSS — update CSS variables',      'Edit AutomaticCSS colors, spacing, typography tokens.',       'pro'],
                'nibwp/acf-manage-fields'            => ['ACF — manage field groups',        'CRUD ACF field groups + read/write field values.',            'pro'],
                'nibwp/forms-manage'                 => ['Forms — universal manager',        'Create a contact form / newsletter signup in any installed form plugin in one call.', 'pro'],
                'nibwp/fluentcrm-manage'             => ['FluentCRM — contacts + campaigns', 'Add subscribers, tag, build segments, trigger campaigns.',    'pro'],
                'nibwp/fluentcart-manage'            => ['FluentCart — products + orders',   'Create products + variations, list orders, manage subscriptions.', 'pro'],
                'nibwp/fluentaffiliate-manage'       => ['FluentAffiliate — affiliate program', 'Affiliates, groups, referrals, payouts, settings, and reports.', 'pro'],
                'nibwp/directorist-manage'           => ['Directorist — directory manager',     'Listings, categories, locations, tags, reviews, orders, pricing plans, favorites, and reports.', 'pro'],
                'nibwp/seo-schema-markup'            => ['SEO — schema markup',              'Generate JSON-LD structured data for any post.',              'pro'],
                'nibwp/execute-php'                  => ['Execute PHP code',                 'Run arbitrary PHP on the server with full WordPress + plugin runtime access.', 'pro'],
                'nibwp/write-file'                   => ['Write file',                       'Write files to the WordPress filesystem (sandboxed for PHP).', 'pro'],
            ];
            foreach ($integration_abilities as $name => [$label, $desc, $tier]) {
                // Skip entries the user actually HAS unlocked.
                $tier_url = $tier === 'pro'
                    ? nibwp_item_url('pro')
                    : ($tier === 'skill:etchwp-pro' ? nibwp_item_url('etchwp-skill') : $pricing_url);
                if (nibwp_has_ability($name)) {
                    continue;
                }
                $locked[] = [
                    'name'       => $name,
                    'label'      => $label,
                    'description'=> $desc,
                    'unlock_url' => $tier_url,
                    'tier'       => $tier,
                ];
            }
        }

        $skill_cards = function_exists('nibwp_skills_skill_cards') ? nibwp_skills_skill_cards() : [];

        $response = [
            // Top-level — agents MUST read this BEFORE handling the user's message.
            'mandatory_routing'  => $skill_cards,
            'nibwp_instructions' => apply_filters(
                'nibwp_discover_abilities_instructions',
                nibwp_build_server_instructions(),
            ),
            'abilities'        => $ability_list,
            'locked_abilities' => $locked,
            // Owned + switched on, but cannot run here. Named so the agent can
            // explain the gap instead of quietly routing around it.
            'unavailable_skills' => function_exists('nibwp_skills_unavailable') ? nibwp_skills_unavailable() : [],
        ];

        return $response;
    },
    'meta' => [
        'annotations' => [
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
        'mcp' => [
            'public' => true,
            'type' => 'tool',
        ],
    ],
]);
