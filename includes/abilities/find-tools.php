<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Ability: find the tools for a subject.
 *
 * The routing contract is delivered once, at connection, before anyone has said
 * anything. By the time the user names a builder — "convert this to Etch",
 * "pull the Figma frame", "build a Kadence page" — that contract is far behind
 * in the conversation, competing with 336 ability descriptions. The agent then
 * reasons from whatever it happens to remember, which is how a request lands on
 * raw PHP while the right skill sits unused.
 *
 * This closes that gap: name the subject, get back everything this site has for
 * it — the skill and its pipeline, the integration, the matching abilities, and
 * plainly whether each is usable here or why not.
 */
wp_register_ability('nibwp/find-tools', [
    'label'       => __('Find the tools for a subject', 'nibwp'),
    'description' => __(
        'Call this the moment the user names a builder, plugin, theme or design tool — Etch, Figma, Kadence, Bricks, Elementor, Voxel, SureCart, ACSS, WooCommerce, Tutor LMS and so on. '
        . 'Returns everything this site actually has for that subject: the skill that claims it and the exact pipeline to run, any saved workflow covering it, the integration, every matching ability, and whether each is ready, locked, or unavailable and why. '
        . 'When nothing owns the subject it says so and clears you to build it with nibwp/execute-php, so a miss here is never a reason to decline the work. '
        . 'Cheaper and far more reliable than recalling the routing contract from the start of the conversation, and it reflects THIS site rather than what NibWP offers in general.',
        'nibwp'
    ),
    'category'    => 'nibwp',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'subject' => [
                'type' => 'string',
                'description' => 'What the user named: "etch", "figma", "kadence", "voxel", "bricks", "elementor", "acss", "seo", "woocommerce"… Free text; partial names work.',
                'minLength' => 2,
            ],
        ],
        'required' => ['subject'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'subject'      => ['type' => 'string'],
            'matched_on'   => ['type' => 'array', 'description' => 'The words the subject was reduced to before searching. Check these if a result looks unrelated.', 'items' => ['type' => 'string']],
            'skills'       => ['type' => 'array', 'description' => 'Skills claiming this subject, with the pipeline to run and whether it can run here.', 'items' => ['type' => 'object']],
            'workflows'    => ['type' => 'array', 'description' => 'Saved workflows on this site that cover this subject — a written procedure to follow.', 'items' => ['type' => 'object']],
            'integrations' => ['type' => 'array', 'description' => 'Matching integrations and whether the plugin/theme was detected.', 'items' => ['type' => 'object']],
            'abilities'    => ['type' => 'array', 'description' => 'Matching abilities registered on this site.', 'items' => ['type' => 'object']],
            'do_this'      => ['type' => 'string', 'description' => 'The recommended next step in one sentence.'],
        ],
    ],
    'execute_callback'    => 'nibwp_find_tools',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function nibwp_find_tools(array $input): array
{
    $subject = strtolower(trim((string) ($input['subject'] ?? '')));
    $needles = nibwp_find_tools_needles($subject);
    if ($needles === []) {
        return ['subject' => $subject, 'matched_on' => [], 'skills' => [], 'workflows' => [], 'integrations' => [], 'abilities' => [], 'do_this' => __('Name a builder, plugin, theme or design tool — or paste the link you are working from.', 'nibwp')];
    }

    $matches = static function (string $haystack) use ($needles): bool {
        $haystack = strtolower($haystack);
        foreach ($needles as $n) {
            if ($n !== '' && str_contains($haystack, (string) $n)) {
                return true;
            }
        }
        return false;
    };

    $skills = [];
    if (function_exists('nibwp_skills_discover')) {
        foreach (nibwp_skills_discover() as $skill) {
            $id   = (string) $skill['id'];
            $hay  = $id . ' ' . (string) ($skill['name'] ?? '') . ' ' . (string) ($skill['tagline'] ?? '')
                  . ' ' . implode(' ', (array) ($skill['requires'] ?? []));
            if (!$matches($hay)) {
                continue;
            }
            $unlocked = !function_exists('nibwp_skill_is_unlocked') || nibwp_skill_is_unlocked($id);
            $enabled  = !function_exists('nibwp_skill_is_enabled') || nibwp_skill_is_enabled($id);
            $missing  = function_exists('nibwp_skill_missing_deps') ? nibwp_skill_missing_deps($skill) : [];

            $state = 'ready';
            $why   = '';
            if (!$unlocked) {
                $state = 'locked';
                $why   = __('Not included in this licence.', 'nibwp');
            } elseif (!$enabled) {
                $state = 'switched_off';
                $why   = __('Owned, but switched off in NibWP → Skills.', 'nibwp');
            } elseif ($missing !== []) {
                $state = 'unavailable';
                $why   = sprintf(
                    /* translators: %s: comma-separated plugin/theme names */
                    __('Needs %s, which is not installed here. Say so rather than building it another way.', 'nibwp'),
                    implode(', ', $missing)
                );
            }

            $pipeline = [];
            foreach ((array) ($skill['mandatory_routing']['pipeline'] ?? []) as $step) {
                $pipeline[] = is_array($step) ? (string) ($step['ability'] ?? '') : (string) $step;
            }

            $skills[] = [
                'skill_id' => $id,
                'name'     => (string) ($skill['name'] ?? $id),
                'tagline'  => (string) ($skill['tagline'] ?? ''),
                'state'    => $state,
                'why'      => $why,
                'pipeline' => array_values(array_filter($pipeline)),
                '_rank'    => nibwp_find_tools_rank($id, (array) ($skill['requires'] ?? []), $needles),
            ];
        }
    }

    // Closest match first. Asking about "figma" matched figma-pro, etchwp-pro
    // and bricks-pro — all of them mention Figma in their taglines — and the
    // advice then named whichever happened to come first alphabetically. The
    // skill whose own name is the thing you asked about should win.
    usort($skills, static fn(array $a, array $b): int => $b['_rank'] <=> $a['_rank']);
    foreach ($skills as &$s) {
        unset($s['_rank']);
    }
    unset($s);

    // Workflows are the third thing this site can own, and the router was blind
    // to them: a site can hold a written procedure for exactly the job being
    // asked about and never have it offered, because nothing looked.
    $workflows = [];
    if (function_exists('nibwp_workflows_posts') && (!function_exists('nibwp_workflows_unlocked') || nibwp_workflows_unlocked())) {
        foreach (nibwp_workflows_posts() as $post) {
            $wf = nibwp_workflow_to_array($post, false);
            $tools = array_column((array) $wf['tools'], 'key');
            if (!$matches($wf['title'] . ' ' . $wf['summary'] . ' ' . $wf['when'] . ' ' . $wf['slug'] . ' ' . implode(' ', $tools))) {
                continue;
            }
            $workflows[] = [
                'slug'    => (string) $wf['slug'],
                'title'   => (string) $wf['title'],
                'summary' => (string) $wf['summary'],
                'when'    => (string) $wf['when'],
                'active'  => (bool) $wf['active'],
                'tools'   => $tools,
            ];
            if (count($workflows) >= 5) {
                break;
            }
        }
    }

    $integrations = [];
    if (function_exists('nibwp_get_integrations')) {
        foreach (nibwp_get_integrations() as $key => $meta) {
            if (!$matches($key . ' ' . (string) ($meta['name'] ?? '') . ' ' . (string) ($meta['description'] ?? ''))) {
                continue;
            }
            $integrations[] = [
                'key'       => (string) $key,
                'name'      => (string) ($meta['name'] ?? $key),
                'detected'  => (bool) ($meta['plugin_available'] ?? false),
                'enabled'   => (bool) ($meta['enabled'] ?? false),
                'abilities' => array_values((array) ($meta['abilities'] ?? [])),
            ];
        }
    }

    $abilities = [];
    foreach (wp_get_abilities() as $name => $ability) {
        $meta = $ability->get_meta();
        if (!($meta['mcp']['public'] ?? false)) {
            continue;
        }
        if (!$matches((string) $name . ' ' . (string) $ability->get_label())) {
            continue;
        }
        $abilities[] = [
            'name'        => (string) $name,
            'label'       => (string) $ability->get_label(),
            'description' => (string) $ability->get_description(),
        ];
    }

    return [
        'subject'      => $subject,
        // What the subject was actually reduced to. A pasted link or a full
        // sentence can match for a reason that is not obvious from the input,
        // and an agent that can see the words can tell a real hit from a
        // coincidence instead of trusting the list blindly.
        'matched_on'   => $needles,
        'skills'       => $skills,
        'workflows'    => $workflows,
        'integrations' => $integrations,
        'abilities'    => $abilities,
        'do_this'      => nibwp_find_tools_advice($skills, $abilities, $workflows),
    ];
}

/**
 * Turn what someone typed into the words worth searching for.
 *
 * People do not type "figma". They paste a link, or say "convert this to etch",
 * or "build a kadence landing page". Three things went wrong with treating that
 * as one blob:
 *
 * Punctuation was deleted rather than split on, so a pasted Figma URL became
 * one unmatchable run of letters and routed to nothing — the single most likely
 * way anyone names a Figma frame.
 *
 * Every word of two letters or more became a needle, so "to" and "in" — which
 * appear inside eight and eleven skill taglines — dragged in most of the
 * catalogue. "convert this to etch" returned nine skills and seventy abilities.
 *
 * And "pro" matched every skill id, because they all end in it.
 *
 * @return list<string> Longest first, so the most specific match wins.
 */
function nibwp_find_tools_needles(string $subject): array
{
    // Words that carry no routing signal. "pro" and "wp" are here because they
    // are in almost every skill id; the URL parts because a pasted link would
    // otherwise match on "com".
    static $stop = [
        'the', 'this', 'that', 'and', 'for', 'with', 'from', 'into', 'onto', 'our',
        'you', 'your', 'its', 'are', 'was', 'has', 'have', 'not', 'can', 'all',
        'convert', 'build', 'make', 'create', 'turn', 'add', 'new', 'use', 'using',
        'want', 'need', 'please', 'help', 'site', 'website', 'page', 'pages',
        'post', 'posts', 'thing', 'stuff', 'something', 'anything',
        'pro', 'wordpress', 'http', 'https', 'www', 'com', 'net', 'org', 'html',
        'php', 'file', 'files', 'link', 'url',
    ];

    // Brand names that reach us wearing a different hat — a domain, a nickname,
    // the long form of a short one.
    static $alias = [
        'figmacom'      => 'figma',
        'automaticcss'  => 'acss',
        'automatic'     => 'acss',
        'tutor'         => 'tutorlms',
        'woo'           => 'woocommerce',
        'gutenberg'     => 'blocks',
        'blockeditor'   => 'blocks',
        'etchwp'        => 'etch',
        'bricksbuilder' => 'bricks',
    ];

    // Split on punctuation instead of deleting it. A URL becomes its parts,
    // which is what makes "figma" findable inside a figma.com link.
    $flat = preg_replace('/[^a-z0-9]+/', ' ', $subject) ?? $subject;
    $flat = trim(preg_replace('/\s+/', ' ', $flat) ?? $flat);
    if ($flat === '') {
        return [];
    }

    $words = [];
    foreach (explode(' ', $flat) as $w) {
        if (strlen($w) < 3 || in_array($w, $stop, true) || ctype_digit($w)) {
            continue;
        }
        $words[] = $alias[$w] ?? $w;
    }
    if ($words === []) {
        return [];
    }

    // The whole phrase in both spellings, so "elementor pro" reaches the skill
    // whose id is "elementor-pro", then the individual words.
    $needles = [];
    if (count($words) > 1) {
        $joined = implode(' ', $words);
        $needles[] = $joined;
        $needles[] = str_replace(' ', '-', $joined);
    }
    foreach ($words as $w) {
        $needles[] = $w;
    }

    $needles = array_values(array_unique($needles));
    usort($needles, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

    return $needles;
}

/**
 * How closely does a skill answer to the name that was typed?
 *
 * Highest when the skill IS the subject (figma → figma-pro), then when it
 * depends on it (etchwp-pro requires etchwp), then a mention anywhere else.
 *
 * @param array<int,string> $requires
 * @param array<int,string> $needles
 */
function nibwp_find_tools_rank(string $id, array $requires, array $needles): int
{
    $rank = 0;
    $id_l = strtolower($id);
    $req  = array_map('strtolower', array_map('strval', $requires));
    foreach ($needles as $n) {
        $n = strtolower((string) $n);
        if ($n === '') {
            continue;
        }
        if ($id_l === $n || $id_l === $n . '-pro') {
            $rank = max($rank, 100);          // figma → figma-pro
        } elseif (str_starts_with($id_l, $n)) {
            $rank = max($rank, 80);           // etch → etchwp-pro
        } elseif (in_array($n, $req, true)) {
            $rank = max($rank, 60);           // depends on the named thing
        } elseif (str_contains($id_l, $n)) {
            $rank = max($rank, 40);
        } else {
            foreach ($req as $r) {
                if (str_starts_with($r, $n)) {
                    $rank = max($rank, 50);
                }
            }
        }
    }
    return $rank;
}

/**
 * One sentence telling the agent what to do with what it just found.
 *
 * The last branch is the one that matters most. "Nothing matches" is not a
 * refusal — most of what anyone asks a site to do is owned by no skill at all,
 * and raw PHP is the right tool for it. Sending the agent away empty-handed
 * there taught it to decline ordinary work; the point of routing is to name a
 * skill where one exists, not to pretend nothing else is allowed.
 *
 * @param array<int,array<string,mixed>> $skills
 * @param array<int,array<string,mixed>> $abilities
 * @param array<int,array<string,mixed>> $workflows
 */
function nibwp_find_tools_advice(array $skills, array $abilities, array $workflows = []): string
{
    $and_workflow = $workflows === [] ? '' : sprintf(
        /* translators: %s: workflow title */
        __(' This site also has a saved workflow for it, "%s" — read it first; it may set the order.', 'nibwp'),
        (string) $workflows[0]['title']
    );

    foreach ($skills as $s) {
        if ($s['state'] === 'ready') {
            return sprintf(
                /* translators: 1: skill name, 2: first ability in its pipeline */
                __('%1$s claims this. Run its pipeline in order, starting with %2$s.', 'nibwp'),
                (string) $s['name'],
                (string) ($s['pipeline'][0] ?? 'nibwp/skill-preflight')
            ) . $and_workflow;
        }
    }
    foreach ($skills as $s) {
        if ($s['state'] === 'unavailable' || $s['state'] === 'switched_off') {
            return sprintf(
                /* translators: 1: skill name, 2: the reason it cannot run */
                __('%1$s would handle this but cannot run: %2$s Tell the user rather than building it another way.', 'nibwp'),
                (string) $s['name'],
                (string) $s['why']
            );
        }
    }
    foreach ($skills as $s) {
        if ($s['state'] === 'locked') {
            return sprintf(
                /* translators: 1: skill name, 2: what to do instead */
                __('%1$s covers this but is not in the current licence. Mention it, then %2$s', 'nibwp'),
                (string) $s['name'],
                $abilities !== []
                    ? __('use the abilities below.', 'nibwp')
                    : __('carry on with nibwp/execute-php — nothing else here owns the job.', 'nibwp')
            ) . $and_workflow;
        }
    }
    if ($workflows !== []) {
        return sprintf(
            /* translators: 1: workflow title, 2: number of matching abilities */
            __('No skill claims this, but this site has a saved workflow for it: "%1$s". Read it and follow it; %2$d matching abilities are available, and nibwp/execute-php covers anything they do not.', 'nibwp'),
            (string) $workflows[0]['title'],
            count($abilities)
        );
    }
    if ($abilities !== []) {
        return sprintf(
            /* translators: %d: number of abilities */
            __('No skill claims this. %d matching abilities are available — use them directly, and nibwp/execute-php for anything they do not cover.', 'nibwp'),
            count($abilities)
        );
    }
    return __('No skill, workflow or ability owns this — which makes nibwp/execute-php the right tool, not a last resort. Go ahead and build it in PHP. (Its last-resort warning applies only to writing builder content a skill owns; that is not this.)', 'nibwp');
}
