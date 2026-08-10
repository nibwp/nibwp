<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Generic per-skill preflight gate.
 *
 * The first ability a v2-routed skill calls. Server probes the site for
 * facts the agent can't know (ACSS installed? brand prefixes already used
 * in etch_styles? installed form plugins?), reads previously cached user
 * answers via the nibwp_user_default_* helpers, and ASKS the user only for
 * the still-missing answers. When all required answers are present, mints
 * a 1-hour transient preflight_token that the destructive skill ability
 * requires (and validates user_id-bound — token replay protection).
 *
 * Capabilities & security:
 *   - permission_callback = nibwp_permission_callback (manage_options).
 *     This is the same gate as every other NIBWP ability; we never widen
 *     to 'read' or any lower cap (Plugin Check / security audit fix B4).
 *   - token storage: hash('sha256', $raw_token) used as the transient KEY,
 *     payload stores user_id + skill_id + answers + attempts + expires_at.
 *     Returning the raw token to the caller ONCE at mint time means an
 *     object-cache reader who later sees the transient value cannot
 *     replay it without the raw token (sha-preimage protection).
 *   - One-shot intent: destructive ability path clears the transient on
 *     a successful persist (caller's responsibility). dry_run keeps it
 *     alive so the agent can iterate without re-running preflight.
 *
 * @see includes/skills/etchwp-pro/abilities/html-to-component.php — token consumer.
 */

if (!defined('NIBWP_PREFLIGHT_TOKEN_TTL')) {
    define('NIBWP_PREFLIGHT_TOKEN_TTL', HOUR_IN_SECONDS);
}
if (!defined('NIBWP_PREFLIGHT_MAX_ATTEMPTS')) {
    define('NIBWP_PREFLIGHT_MAX_ATTEMPTS', 3);
}

if (function_exists('wp_register_ability')) {
    wp_register_ability('nibwp/skill-preflight', [
        'label'       => __('Skill Preflight', 'nibwp'),
        'description' => __('Generic per-skill preflight gate. Probes server-detectable facts (ACSS active, EtchWP version, existing brand prefixes, candidate target posts, installed form plugins), reads cached user answers via nibwp_user_default_get, asks the user only the still-unanswered questions from the skill manifest, and mints a 1-hour preflight_token the downstream conversion ability requires. Call this FIRST for any skill whose mandatory_routing.preflight_required = true.', 'nibwp'),
        'category'    => 'mcp-adapter',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'skill_id' => [
                    'type'        => 'string',
                    'description' => 'The skill the preflight runs for (e.g. "etchwp-pro").',
                ],
                'answers' => [
                    'type'        => 'object',
                    'description' => 'Map of preflight-question key → user answer. Submit on the second call once the user has answered the questions returned by the first call.',
                ],
                'user_intent_hint' => [
                    'type'        => 'string',
                    'description' => 'Optional one-line summary of what the user is trying to do — recorded for telemetry, never used for routing.',
                ],
            ],
            'required'             => ['skill_id'],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'success'             => ['type' => 'boolean'],
                'requires_user_input' => ['type' => 'boolean'],
                'environment'         => ['type' => 'object'],
                'questions'           => ['type' => 'array'],
                'cached_answers'      => ['type' => 'object'],
                'preflight_token'     => ['type' => 'string', 'description' => 'Raw token returned ONCE at mint time. Pass as _preflight_token to the downstream destructive ability. Server stores only sha256(token).'],
                'expires_at'          => ['type' => 'integer'],
            ],
        ],
        'execute_callback'    => 'nibwp_skill_preflight_execute',
        'permission_callback' => 'nibwp_permission_callback',
        'meta' => [
            'show_in_rest' => true,
            'mcp'          => ['public' => true, 'type' => 'tool'],
            'annotations'  => [
                'instructions' => 'Call BEFORE the first destructive ability of any v2-routed skill. First call returns { requires_user_input: true, environment, questions[], cached_answers }. Ask the user the questions, then re-call with { answers: {key: value, ...} }. Second call mints preflight_token. Pass that token to the downstream ability as _preflight_token.',
                'readonly'    => false,
                'destructive' => false,
                'idempotent'  => false,
            ],
        ],
    ]);
}

function nibwp_skill_preflight_execute(array $input): array|WP_Error
{
    $skill_id = sanitize_key((string) ($input['skill_id'] ?? ''));
    if ($skill_id === '') {
        return new WP_Error('preflight_no_skill', 'skill_id is required.');
    }

    if (!function_exists('nibwp_skill_get')) {
        return new WP_Error('preflight_no_registry', 'Skills registry not loaded.');
    }
    $skill = nibwp_skill_get($skill_id);
    if (!$skill) {
        return new WP_Error('preflight_unknown_skill', sprintf('Unknown skill: %s', $skill_id));
    }
    if (function_exists('nibwp_skill_gate')) {
        $gate = nibwp_skill_gate($skill_id);
        if (is_wp_error($gate)) {
            return $gate;
        }
    }

    require_once __DIR__ . '/../user-defaults.php';

    $environment = nibwp_skill_preflight_environment($skill_id);
    $question_defs = (array) ($skill['preflight_questions'] ?? []);
    $answers_in = is_array($input['answers'] ?? null) ? (array) $input['answers'] : [];

    // Resolve each question: detected_value from server probe, cached value
    // from nibwp_user_defaults, then the user-submitted answer (priority
    // order: user answer > cached value > detected value).
    $resolved = [];
    $missing  = [];
    foreach ($question_defs as $q) {
        if (!is_array($q) || empty($q['key'])) {
            continue;
        }
        $key       = sanitize_key((string) $q['key']);
        $cache_key = sanitize_key((string) ($q['cache_key'] ?? $skill_id . '_' . $key));

        // Skip questions whose conditional_on isn't met.
        if (!nibwp_skill_preflight_conditional_met($q, $environment, $resolved)) {
            continue;
        }

        $detected = nibwp_skill_preflight_resolve_detect((string) ($q['detect'] ?? ''), $environment);
        $cached   = (array_key_exists('cache', $q) && !$q['cache']) ? null : nibwp_user_default_get($cache_key);
        $submitted = $answers_in[$key] ?? null;

        // A remembered post can be deleted between sessions. Handing the id back
        // anyway sends the next write into a post that no longer exists, so a
        // cached target is only offered while it still resolves.
        $is_target_id = str_contains($key, 'post_id') || str_contains($key, 'template_id');
        if ($cached !== null && $cached !== '' && $is_target_id && is_numeric($cached)) {
            $status = get_post_status((int) $cached);
            if ($status === false || $status === 'trash') {
                nibwp_user_default_set([$cache_key => '']);
                $cached = null;
            }
        }

        // A `list(...)` probe returns CANDIDATES to show the user, not an answer.
        // Treating one as resolved silently baked a whole post list into the
        // token, and a downstream (int) cast turned it into post id 1.
        $detected_is_candidates = is_array($detected);
        $value = nibwp_skill_preflight_pick($submitted, $cached, $detected_is_candidates ? null : $detected);

        // Same guard for anything already cached in the wrong shape.
        if (is_array($value) && !str_contains((string) ($q['type'] ?? ''), 'array')) {
            $value = null;
        }
        if ($value === null || $value === '') {
            if (!empty($q['required']) || $submitted === null && $cached === null) {
                $missing[] = [
                    'key'            => $key,
                    'prompt'         => (string) ($q['prompt'] ?? $key),
                    'type'           => (string) ($q['type'] ?? 'string'),
                    'choices'        => array_values((array) ($q['choices'] ?? [])),
                    'detected_value' => $detected,
                    'cached_value'   => $cached,
                    'cache_key'      => $cache_key,
                    'conditional_on' => (array) ($q['conditional_on'] ?? []),
                ];
                continue;
            }
        }
        $resolved[$key] = $value;
    }

    if ($missing !== []) {
        return [
            'success'             => false,
            'requires_user_input' => true,
            'environment'         => $environment,
            'questions'           => $missing,
            'cached_answers'      => $resolved,
            'next_action'         => 'Ask the user each question in `questions`. Re-call this ability with answers:{key:value}. When all required answers are present, the call mints the preflight_token.',
        ];
    }

    // All required questions answered — persist durable answers via the
    // user-defaults store (key = cache_key), mint a transient preflight token.
    $persist_kv = [];
    foreach ($question_defs as $q) {
        if (!is_array($q) || empty($q['key']) || empty($q['cache_key'])) {
            continue;
        }
        // Some answers are true of the site and worth remembering; others are
        // true of one build only. A title or a rebuild target carried into the
        // next session is not a convenience, it is a wrong default offered
        // confidently — and for a target id, a silent overwrite of the wrong
        // template.
        if (array_key_exists('cache', $q) && !$q['cache']) {
            continue;
        }
        $key       = sanitize_key((string) $q['key']);
        $cache_key = sanitize_key((string) $q['cache_key']);
        if (!array_key_exists($key, $resolved) || $resolved[$key] === null || $resolved[$key] === '') {
            continue;
        }
        $persist_kv[$cache_key] = $resolved[$key];
    }
    if ($persist_kv !== []) {
        nibwp_user_default_set($persist_kv);
    }

    $raw_token = nibwp_skill_preflight_mint_token($skill_id, $resolved);

    return [
        'success'         => true,
        'environment'     => $environment,
        'cached_answers'  => $resolved,
        'preflight_token' => $raw_token,
        'expires_at'      => time() + NIBWP_PREFLIGHT_TOKEN_TTL,
        'next_action'     => sprintf(
            'Pass _preflight_token: "%s" to the downstream ability (e.g. nibwp/etchwp-pro-html-to-component). The token expires in 1 hour and is bound to user_id=%d.',
            $raw_token,
            get_current_user_id()
        ),
    ];
}

/**
 * Server-side environment probe — facts the agent can't know.
 *
 * Scoped to manage_options (the ability's permission_callback enforces it
 * upstream), so reading active_plugins + etch_styles + recent posts is
 * safe. The recent-posts list excludes drafts/private posts the user can't
 * edit (defense-in-depth for B4).
 *
 * @return array<string,mixed>
 */
function nibwp_skill_preflight_environment(string $skill_id): array
{
    $env = [
        'site'   => [
            'wp_version'  => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'home_url'    => home_url('/'),
            'locale'      => get_locale(),
        ],
        'plugins' => [],
        'recent_posts' => [],
        'brand_prefixes_detected' => [],
        'form_plugins_installed'  => [],
    ];

    // Detect ACSS / EtchWP / form plugins.
    $env['plugins']['acss'] = [
        'active'  => nibwp_skill_preflight_acss_active(),
        'version' => defined('ACSS_VERSION') ? ACSS_VERSION : null,
    ];
    $env['plugins']['etchwp'] = [
        'active'  => defined('ETCH_PLUGIN_FILE'),
        'version' => defined('ETCH_VERSION') ? ETCH_VERSION : null,
    ];
    $form_plugins = [
        'fluentforms'    => function_exists('wpFluentForm') || defined('FLUENTFORM'),
        'gravityforms'   => class_exists('GFForms'),
        'contact-form-7' => defined('WPCF7_VERSION'),
        'ninja-forms'    => class_exists('Ninja_Forms'),
        'wpforms'        => class_exists('WPForms\\WPForms'),
        'formidable'     => class_exists('FrmAppHelper'),
        'forminator'     => class_exists('Forminator'),
        'jetpack-form'   => class_exists('Jetpack_Contact_Form'),
        'happyforms'     => class_exists('HappyForms_Form_Controller'),
    ];
    foreach ($form_plugins as $slug => $active) {
        if ($active) {
            $env['form_plugins_installed'][] = $slug;
        }
    }

    // Detect Bricks runtime.
    $env['plugins']['bricks'] = [
        'active'  => defined('BRICKS_VERSION') || class_exists('\\Bricks\\Templates') || function_exists('bricks_render_dynamic_data'),
        'version' => defined('BRICKS_VERSION') ? BRICKS_VERSION : null,
    ];

    // Bricks templates (target candidates for replace_template / append_to_existing).
    if ($skill_id === 'bricks-pro' && ($env['plugins']['bricks']['active'] ?? false)) {
        $templates = get_posts([
            'post_type'      => 'bricks_template',
            'post_status'    => 'publish',
            'numberposts'    => 20,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'no_found_rows'  => true,
            'suppress_filters' => true,
        ]);
        $env['bricks_templates'] = [];
        foreach ($templates as $t) {
            if (!current_user_can('edit_post', $t->ID)) {
                continue;
            }
            $settings = (array) get_post_meta($t->ID, '_bricks_template_settings', true);
            $env['bricks_templates'][] = [
                'id'    => (int) $t->ID,
                'title' => (string) $t->post_title,
                'type'  => (string) ($settings['templateType'] ?? 'content'),
                'date'  => (string) $t->post_modified,
            ];
        }
        // Scan Bricks global classes for brand prefixes.
        $bricks_globals = (array) get_option('bricks_global_classes', []);
        $prefix_counts = [];
        foreach ($bricks_globals as $gc) {
            if (!is_array($gc) || empty($gc['name'])) {
                continue;
            }
            if (preg_match('/^([a-z][a-z0-9]*)-/', (string) $gc['name'], $m)) {
                $prefix_counts[$m[1]] = ($prefix_counts[$m[1]] ?? 0) + 1;
            }
        }
        arsort($prefix_counts);
        if ($prefix_counts !== []) {
            $env['brand_prefixes_detected'] = array_merge(array_keys($prefix_counts), $env['brand_prefixes_detected'] ?? []);
            $env['brand_prefixes_detected'] = array_values(array_unique($env['brand_prefixes_detected']));
        }
    }

    // Voxel post types, so "which post type is this for?" arrives with the
    // site's own answers attached instead of asking the user to recall slugs.
    if ($skill_id === 'voxel-pro') {
        $env['voxel_post_types'] = [];
        $voxel_pts = get_option('voxel:post_types', '');
        if (is_string($voxel_pts) && $voxel_pts !== '') {
            $voxel_pts = json_decode($voxel_pts, true);
        }
        foreach ((array) $voxel_pts as $slug => $config) {
            $slug = (string) $slug;
            if ($slug === '' || !is_array($config)) {
                continue;
            }
            $env['voxel_post_types'][] = [
                'key'   => $slug,
                'label' => (string) ($config['settings']['singular'] ?? $config['label'] ?? $slug),
            ];
        }
    }

    // Recent posts the user can edit — used as target_post_id candidates.
    if ($skill_id === 'etchwp-pro') {
        $posts = get_posts([
            'post_type'      => ['post', 'page'],
            'post_status'    => 'publish',
            'numberposts'    => 20,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'no_found_rows'  => true,
            'suppress_filters' => true,
        ]);
        foreach ($posts as $p) {
            if (!current_user_can('edit_post', $p->ID)) {
                continue;
            }
            $env['recent_posts'][] = [
                'id'    => (int) $p->ID,
                'title' => (string) $p->post_title,
                'type'  => (string) $p->post_type,
                'date'  => (string) $p->post_modified,
            ];
        }
    }

    // Scan existing etch_styles for brand prefixes already in use.
    $styles = (array) get_option('etch_styles', []);
    $prefix_counts = [];
    foreach ($styles as $sid => $_def) {
        if (!is_string($sid) || $sid === '') {
            continue;
        }
        if (preg_match('/^([a-z][a-z0-9]*)-/', $sid, $m)) {
            $prefix = $m[1];
            $prefix_counts[$prefix] = ($prefix_counts[$prefix] ?? 0) + 1;
        }
    }
    arsort($prefix_counts);
    $env['brand_prefixes_detected'] = array_slice(array_keys($prefix_counts), 0, 5);

    return $env;
}

/**
 * Resolve a manifest "detect" expression against the env. Whitelist of
 * recognised expressions — never eval(). Returns null when unrecognised.
 */
function nibwp_skill_preflight_resolve_detect(string $expr, array $env)
{
    $expr = trim($expr);
    if ($expr === '') {
        return null;
    }
    // Pipe-separated alternatives — first one that resolves wins.
    foreach (explode('|', $expr) as $alt) {
        $alt = trim($alt);
        $value = nibwp_skill_preflight_resolve_one($alt, $env);
        if ($value !== null && $value !== '') {
            return $value;
        }
    }
    return null;
}

function nibwp_skill_preflight_resolve_one(string $expr, array $env)
{
    switch ($expr) {
        case 'acss_active':
            return (bool) ($env['plugins']['acss']['active'] ?? false);
        case 'etchwp_active':
            return (bool) ($env['plugins']['etchwp']['active'] ?? false);
        case 'bricks_active':
            return (bool) ($env['plugins']['bricks']['active'] ?? false);
        case 'list(recent_posts,20)':
            return $env['recent_posts'] ?? [];
        case 'list(bricks_templates,20)':
            return $env['bricks_templates'] ?? [];
        case 'list(voxel_post_types)':
            return $env['voxel_post_types'] ?? [];
        case 'get_option(nibwp_etchwp_brand)':
            $v = (string) get_option('nibwp_etchwp_brand', '');
            return $v !== '' ? $v : null;
        case 'get_option(nibwp_bricks_brand)':
            $v = (string) get_option('nibwp_bricks_brand', '');
            return $v !== '' ? $v : null;
        case 'scan(etch_styles)':
            return $env['brand_prefixes_detected'][0] ?? null;
        case 'scan(bricks_global_classes)':
            return $env['brand_prefixes_detected'][0] ?? null;
        // Cross-skill shared brand prefix — single source of truth.
        case 'get_user_default(brand_prefix)':
        case 'shared(brand_prefix)':
            if (function_exists('nibwp_user_default_get')) {
                $v = (string) nibwp_user_default_get('brand_prefix', '');
                return $v !== '' ? $v : null;
            }
            return null;
        default:
            return null;
    }
}

/**
 * Check whether a conditional_on map is satisfied by the env + already-
 * resolved answers. Comparison is loose equality.
 *
 * @param array $q
 * @param array $env
 * @param array $resolved Already-resolved answer keys for prior questions.
 */
function nibwp_skill_preflight_conditional_met(array $q, array $env, array $resolved): bool
{
    $cond = (array) ($q['conditional_on'] ?? []);
    if ($cond === []) {
        return true;
    }
    foreach ($cond as $k => $expected) {
        $key = sanitize_key((string) $k);
        $actual = null;
        if (array_key_exists($key, $resolved)) {
            $actual = $resolved[$key];
        } elseif ($key === 'acss_active') {
            $actual = (bool) ($env['plugins']['acss']['active'] ?? false);
        } elseif ($key === 'etchwp_active') {
            $actual = (bool) ($env['plugins']['etchwp']['active'] ?? false);
        }
        // A list of expected values means "any of these" — a question that
        // applies to several answers but not all of them, which single-value
        // equality could only express by repeating the question.
        if (is_array($expected)) {
            if (!in_array($actual, $expected, false)) { // loose intentional
                return false;
            }
            continue;
        }
        if ($actual != $expected) { // loose intentional
            return false;
        }
    }
    return true;
}

/**
 * Pick the first non-null/non-empty value from a priority chain.
 */
function nibwp_skill_preflight_pick(...$candidates)
{
    foreach ($candidates as $c) {
        if ($c !== null && $c !== '' && $c !== []) {
            return $c;
        }
    }
    return null;
}

/**
 * Mint a token bound to the current user + skill_id + answers snapshot.
 *
 * The raw token is returned to the caller once. The transient key uses
 * the sha256 of the raw token so a transient-leak (object-cache, DB read)
 * does not enable token replay — an attacker would still need the
 * pre-image to call the downstream ability.
 */
function nibwp_skill_preflight_mint_token(string $skill_id, array $answers): string
{
    $raw = wp_generate_password(48, false, false);
    $hash = hash('sha256', $raw);
    $payload = [
        'skill_id'   => $skill_id,
        'user_id'    => get_current_user_id(),
        'answers'    => $answers,
        'attempts'   => 0,
        'expires_at' => time() + NIBWP_PREFLIGHT_TOKEN_TTL,
    ];
    set_transient('nibwp_pft_' . $hash, $payload, NIBWP_PREFLIGHT_TOKEN_TTL);
    return $raw;
}

/**
 * Read + validate a preflight token. Returns the payload on success or
 * WP_Error on any of: invalid, expired, user mismatch, skill mismatch,
 * attempts_exhausted.
 *
 * @return array{skill_id:string,user_id:int,answers:array<string,mixed>,attempts:int,expires_at:int}|WP_Error
 */
function nibwp_skill_preflight_consume_token(string $raw_token, string $expected_skill_id)
{
    if ($raw_token === '') {
        return new WP_Error('preflight_token_missing', '_preflight_token is required. Call nibwp/skill-preflight first.');
    }
    $hash = hash('sha256', $raw_token);
    $payload = get_transient('nibwp_pft_' . $hash);
    if (!is_array($payload)) {
        return new WP_Error('preflight_token_invalid', 'Invalid or expired _preflight_token. Re-run nibwp/skill-preflight.');
    }
    if ((int) ($payload['user_id'] ?? 0) !== get_current_user_id()) {
        return new WP_Error('preflight_token_user_mismatch', 'Preflight token belongs to a different user.');
    }
    if ((string) ($payload['skill_id'] ?? '') !== $expected_skill_id) {
        return new WP_Error('preflight_token_skill_mismatch', sprintf('Preflight token is bound to "%s" but called for "%s".', (string) ($payload['skill_id'] ?? ''), $expected_skill_id));
    }
    if (time() > (int) ($payload['expires_at'] ?? 0)) {
        delete_transient('nibwp_pft_' . $hash);
        return new WP_Error('preflight_token_expired', 'Preflight token expired. Re-run nibwp/skill-preflight.');
    }
    if ((int) ($payload['attempts'] ?? 0) >= NIBWP_PREFLIGHT_MAX_ATTEMPTS) {
        return new WP_Error('preflight_attempts_exhausted', sprintf('Preflight token has exhausted its %d-attempt budget. Re-run nibwp/skill-preflight.', NIBWP_PREFLIGHT_MAX_ATTEMPTS));
    }
    return $payload;
}

/**
 * Increment attempt counter on the transient. Call once per validator-
 * failure response in the destructive ability. After NIBWP_PREFLIGHT_MAX_ATTEMPTS
 * failures, nibwp_skill_preflight_consume_token returns attempts_exhausted.
 */
function nibwp_skill_preflight_bump_attempts(string $raw_token): void
{
    if ($raw_token === '') {
        return;
    }
    $hash = hash('sha256', $raw_token);
    $payload = get_transient('nibwp_pft_' . $hash);
    if (!is_array($payload)) {
        return;
    }
    $payload['attempts'] = (int) ($payload['attempts'] ?? 0) + 1;
    $ttl = max(60, (int) ($payload['expires_at'] ?? 0) - time());
    set_transient('nibwp_pft_' . $hash, $payload, $ttl);
}

/**
 * Clear a preflight token (one-shot semantics for destructive persists).
 */
function nibwp_skill_preflight_clear_token(string $raw_token): void
{
    if ($raw_token === '') {
        return;
    }
    delete_transient('nibwp_pft_' . hash('sha256', $raw_token));
}

/**
 * Best-effort ACSS detection (mirrors validator's helper to avoid coupling).
 */
function nibwp_skill_preflight_acss_active(): bool
{
    return defined('ACSS_PLUGIN_FILE')
        || defined('ACSS_VERSION')
        || class_exists('\\Automatic_CSS\\Plugin')
        || function_exists('acss_get_setting');
}
