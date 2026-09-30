<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Skill Registry — auto-discovers skill packs from includes/skills/<slug>/manifest.php,
 * loads instructions, registers their abilities, and exposes a unified API to the
 * marketplace UI and runtime gating.
 *
 * A "skill" is a curated bundle that combines:
 *   - Abilities (MCP tools, registered the standard way via wp_register_ability)
 *   - System prompt (instructions.md returned through the discover-abilities hook)
 *   - Templates (component skeletons)
 *   - Few-shot examples
 *   - Validators
 *
 * Manifest shape (see includes/skills/etchwp-pro/manifest.php for example):
 *   return [
 *       'id'          => 'etchwp-pro',
 *       'name'        => 'EtchWP Pro',
 *       'tagline'     => '...',
 *       'description' => '...',
 *       'vendor'      => 'NIBWP',
 *       'version'     => '1.0.0',
 *       'category'    => 'page-builders',
 *       'premium'     => true,
 *       'price'       => 49,
 *       'requires'    => ['etchwp'],
 *       'features'    => ['...', '...'],
 *       'ability_files' => ['abilities/image-to-component.php'],
 *       'instructions_file' => 'instructions.md',
 *       'icon'        => '<svg>...</svg>',
 *   ];
 */

function nibwp_skills_dir(): string
{
    return __DIR__;
}

/**
 * Roots scanned for skill manifests. Defaults to Free's own includes/skills/.
 * The Pro plugin hooks this filter to add its own includes/skills/ root so
 * paid skill packs ship with the Pro distribution but plug into the same
 * registry.
 *
 * @return array<int, string>  absolute paths (no trailing slash)
 */
function nibwp_skills_roots(): array
{
    return array_values(array_filter(array_map(
        static fn ($dir) => rtrim((string) $dir, '/\\'),
        (array) apply_filters('nibwp_skills_roots', [__DIR__]),
    ), 'is_dir'));
}

/**
 * Discover all skill manifests on disk across every registered root.
 *
 * @return array<string, array<string, mixed>>
 */
function nibwp_skills_discover(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = [];
    foreach (nibwp_skills_roots() as $base) {
        $candidates = glob($base . '/*/manifest.php');
        if (!is_array($candidates)) {
            continue;
        }
        foreach ($candidates as $manifest_path) {
            $manifest = require $manifest_path;
            if (!is_array($manifest) || empty($manifest['id'])) {
                continue;
            }
            $manifest['path'] = dirname($manifest_path) . '/';
            $manifest['url']  = plugin_dir_url($manifest_path);
            $cache[$manifest['id']] = $manifest;
        }
    }

    return $cache;
}

function nibwp_skill_get(string $id): ?array
{
    $all = nibwp_skills_discover();
    return $all[$id] ?? null;
}

/**
 * Is the user allowed to use this skill right now?
 *
 * Free skills: always yes.
 * Premium skills: unlocked when ANY of these hold —
 *   • the user has `skill:<id>` entitlement (standalone skill license)
 *   • the user has `skill:*` entitlement (Bundle license)
 *   • the user has `pro` entitlement AND the skill manifest opts into the
 *     Pro tier via `'included_in_pro' => true` (not the default).
 */
function nibwp_skill_is_unlocked(string $id): bool
{
    $skill = nibwp_skill_get($id);
    if ($skill === null) {
        return false;
    }
    if (empty($skill['premium'])) {
        return true;
    }
    if (!function_exists('nibwp_has_entitlement')) {
        return false;
    }
    // Bundle wildcard unlocks every skill.
    if (nibwp_has_entitlement('skill:*')) {
        return true;
    }
    // Default entitlement code derived from skill id (e.g. skill:etchwp-pro).
    if (nibwp_has_entitlement('skill:' . $id)) {
        return true;
    }
    // Manifest-declared entitlement aliases. Lets a skill called `etchwp-pro`
    // accept legacy / shorter entitlement codes like `skill:etchwp` that
    // FluentCart products were already stamped with.
    foreach ((array) ($skill['entitlements'] ?? []) as $code) {
        $code = (string) $code;
        if ($code !== '' && nibwp_has_entitlement($code)) {
            return true;
        }
    }
    if (!empty($skill['included_in_pro']) && nibwp_has_entitlement('pro')) {
        return true;
    }
    return false;
}

/**
 * Is a Pro integration unlocked for the current user?
 *
 * A Pro integration unlocks under ANY of:
 *   • the user has a Pro / Bundle license (covers everything),
 *   • the user has an explicit `integration:<key>` entitlement,
 *   • a skill the user owns declares this integration as one it needs to ship
 *     (manifest['integration_files']). This is how a standalone Skill license
 *     (e.g. EtchWP Skill) can unlock its associated integration ability files
 *     without buying Pro.
 */
function nibwp_integration_is_unlocked(string $key): bool
{
    if (function_exists('nibwp_is_pro') && nibwp_is_pro()) {
        return true;
    }
    if (function_exists('nibwp_has_entitlement')) {
        if (nibwp_has_entitlement('integration:' . $key)) {
            return true;
        }
        if (nibwp_has_entitlement('skill:*')) {
            return true;
        }
    }
    foreach (nibwp_skills_discover() as $skill) {
        if (!nibwp_skill_is_enabled($skill['id'])) {
            continue;
        }
        $files = (array) ($skill['integration_files'] ?? []);
        // Accept both bare keys ("etchwp") and filenames ("etchwp.php") in the
        // manifest so authors can be casual.
        foreach ($files as $f) {
            $f = (string) $f;
            if ($f === $key || $f === ($key . '.php')) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Is the skill *enabled* by the user? Same toggle system as integrations.
 * Free skills default ON, premium default ON when license is active.
 */
function nibwp_skill_is_enabled(string $id): bool
{
    // "Active" = the user's toggle choice on an unlocked skill — independent of
    // whether the skill's required plugin is detected. A missing dependency is
    // surfaced as a warning on the card, not a gate on the toggle/active state.
    $enabled = get_option('nibwp_enabled_skills', []);
    if (!is_array($enabled)) {
        $enabled = [];
    }
    if (!array_key_exists($id, $enabled)) {
        return nibwp_skill_is_unlocked($id);
    }
    return (bool) $enabled[$id] && nibwp_skill_is_unlocked($id);
}

function nibwp_skill_set_enabled(string $id, bool $on): void
{
    $enabled = get_option('nibwp_enabled_skills', []);
    if (!is_array($enabled)) {
        $enabled = [];
    }
    $enabled[$id] = $on;
    update_option('nibwp_enabled_skills', $enabled, autoload: false);
}

/**
 * Are the skill's plugin dependencies satisfied? Reuses the same check as integrations.
 */
function nibwp_skill_deps_met(array $skill): bool
{
    return nibwp_skill_missing_deps($skill) === [];
}

/**
 * Which of the skill's dependencies are not present?
 *
 * @param array<string,mixed> $skill
 * @return array<int,string> integration keys that are missing
 */
function nibwp_skill_missing_deps(array $skill): array
{
    $missing = [];
    foreach ((array) ($skill['requires'] ?? []) as $dep) {
        if (function_exists('nibwp_is_integration_available') && !nibwp_is_integration_available((string) $dep)) {
            $missing[] = (string) $dep;
        }
    }
    return $missing;
}

/**
 * Skills that are owned and switched on but cannot run, and why.
 *
 * A skill withheld for a missing dependency used to disappear from the routing
 * contract without a word. The agent could not tell the difference between "no
 * skill claims this" and "the skill that claims this is unavailable", so it
 * improvised — usually by reaching for raw PHP. Saying so plainly lets it
 * explain the gap to the user instead of working around it.
 *
 * @return array<int,array<string,mixed>>
 */
function nibwp_skills_unavailable(): array
{
    $out = [];
    foreach (nibwp_skills_discover() as $skill) {
        $id = (string) $skill['id'];
        if (!nibwp_skill_is_enabled($id) || !nibwp_skill_is_unlocked($id)) {
            continue; // Locked skills are advertised separately, as a cross-sell.
        }
        $missing = nibwp_skill_missing_deps($skill);
        if ($missing === []) {
            continue;
        }
        $out[] = [
            'skill_id' => $id,
            'name'     => (string) ($skill['name'] ?? $id),
            'reason'   => 'missing_dependency',
            'requires' => $missing,
            'tell_the_user' => sprintf(
                /* translators: 1: skill name, 2: comma-separated plugin/theme keys */
                __('%1$s is installed and licensed but cannot run here because %2$s was not detected. Say so rather than building this another way.', 'nibwp'),
                (string) ($skill['name'] ?? $id),
                implode(', ', $missing)
            ),
        ];
    }
    return $out;
}

/**
 * Load all enabled-and-unlocked skill ability files. Hook on wp_abilities_api_init.
 */
function nibwp_skills_load_abilities(): void
{
    // Ensure we read a fresh entitlement vector at every abilities-API init —
    // protects against stale request-scoped caches when a license was added or
    // removed earlier in the same request.
    if (function_exists('nibwp_entitlements_reset')) {
        nibwp_entitlements_reset();
    }

    foreach (nibwp_skills_discover() as $skill) {
        if (!nibwp_skill_is_enabled($skill['id']) || !nibwp_skill_deps_met($skill)) {
            continue;
        }
        foreach ((array) ($skill['ability_files'] ?? []) as $rel) {
            $abs = $skill['path'] . ltrim($rel, '/');
            if (file_exists($abs)) {
                require_once $abs;
            }
        }
    }
}

/**
 * Append a SHORT skills index to the discover-abilities response.
 *
 * Replaces the v1.2 behavior where every enabled skill's full SKILL.md was
 * concatenated into the discover payload (response could grow into the tens
 * of KB and pushed the agent's context up with no benefit until conversion
 * actually started).
 *
 * The full playbook + per-element checklists are now loaded on demand via
 * the nibwp/load-skill-playbook ability — see includes/abilities/load-skill-playbook.php.
 */
function nibwp_skills_index_filter($instructions): string
{
    $instructions = (string) $instructions;
    $enabled = [];
    foreach (nibwp_skills_discover() as $skill) {
        if (!nibwp_skill_is_enabled($skill['id']) || !nibwp_skill_deps_met($skill)) {
            continue;
        }
        $tagline = (string) ($skill['tagline'] ?? $skill['description'] ?? $skill['name']);
        $tagline = trim(preg_replace('/\s+/', ' ', $tagline) ?? '');
        if (mb_strlen($tagline) > 180) {
            $tagline = mb_substr($tagline, 0, 177) . '…';
        }
        $top_command = '';
        foreach ((array) ($skill['commands'] ?? []) as $cmd => $info) {
            $top_command = (string) $cmd;
            break;
        }
        $card = sprintf('- **%s** — %s', (string) $skill['id'], $tagline);
        if ($top_command !== '') {
            $card .= sprintf(' Trigger: `%s` or call `nibwp/skill-preflight { skill_id:"%s" }` then `nibwp/load-skill-playbook`.', $top_command, $skill['id']);
        }
        $enabled[] = $card;
    }
    $unavailable = nibwp_skills_unavailable();

    if ($enabled === [] && $unavailable === []) {
        return $instructions;
    }

    $block = "\n\n## How to work here\n\n"
           . "1. **Look the subject up first.** The moment a message mentions a builder, plugin, theme or design tool — Etch, Figma, Kadence, Bricks, Elementor, Voxel, ACSS, SureCart, WooCommerce, Tutor LMS — call `nibwp/find-tools { subject: \"…\" }` before deciding anything. "
           . "It returns what THIS site has for that subject: the skill that claims it and the pipeline to run, any saved workflow, the integration, the matching abilities, and whether each is ready, switched off, locked, or unavailable and why. "
           . "It is cheaper and more reliable than recalling this brief later in a long conversation, and it answers for this site rather than for NibWP in general.\n"
           . "2. **Run what it names, in order.** A skill's pipeline is not a menu. Each step feeds the next, and the write step refuses a token the earlier steps did not mint.\n"
           . "3. **Ask once, at the start.** `nibwp/skill-preflight` returns the questions worth asking. Ask them together, then build — do not stop midway to ask what step one could have answered.\n"
           . "4. **Dry-run before you write** where the ability offers `dry_run` — the report tells you what would land, and it costs one call to avoid rebuilding a page badly.\n"
           . "5. **Check what you built.** A write that returned success is not the same as a page that looks right. Re-read what you wrote — `nibwp/visual-open` and `nibwp/visual-read` if the workspace is open, `nibwp/visual-audit` for contrast, alt text and headings, the skill's own validator otherwise — and fix what you find before saying it is done.\n"
           . "6. **Say what actually happened.** Report what you built, where it lives, and anything you skipped or could not do. If a step failed, say so with the error; do not describe the intended result as if it were the outcome.\n"
           . "7. **When nothing owns the job, build it.** If `nibwp/find-tools` finds no skill, workflow or ability for a subject, `nibwp/execute-php` is the right tool and not a last resort. Its last-resort framing applies to one thing only: writing builder content a live skill owns.\n";

    if ($enabled !== []) {
        $block .= "\n\n## Skills available (UNLOCKED — mandatory routing applies)\n\n"
               . "On any user message matching a skill's triggers regex (see response.mandatory_routing[]), you MUST run that skill's pipeline in order. Do not improvise. Do not call wp/create-post with raw HTML when an EtchWP-class skill claims the request.\n\n"
               . implode("\n", $enabled)
               . "\n\nEach of these is built for this site and knows its stack. `nibwp/get-skill` loads one — the manifest carries the rules and knowledge base that make its output correct here.";
    }
    if ($unavailable !== []) {
        $lines = [];
        foreach ($unavailable as $u) {
            $lines[] = sprintf('- **%s** — needs %s, which is not installed here.', (string) $u['name'], implode(', ', (array) $u['requires']));
        }
        $block .= "\n\n## Skills the user owns that CANNOT run here\n\n"
               . "These are switched on and licensed, but a plugin or theme they depend on was not detected. If the user asks for something one of these covers, say it is unavailable and name what is missing. Do not substitute raw PHP or hand-written markup for one of these.\n\n"
               . implode("\n", $lines);
    }
    // Routing goes FIRST, not last.
    //
    // Appended, this section began at character 17,336 of a 19,815-character
    // brief — 87% of the way in, after every generic instruction. An agent that
    // stopped reading early, or weighted the opening most heavily, never
    // reached the one part that says which requests are already claimed. The
    // rules that decide what to do belong before the reference material that
    // describes how.
    return ltrim($block) . "\n\n" . $instructions;
}
add_filter('nibwp_discover_abilities_instructions', 'nibwp_skills_index_filter');

/**
 * Build the typed skill_cards / mandatory_routing payload that the discover
 * ability inlines as a TOP-LEVEL field of its response. Agents read this
 * structured contract on call #1, before any prose.
 *
 * Schema per card (see also etchwp-pro/manifest.php for the source fields):
 *   {
 *     skill_id, name, tagline,
 *     triggers:  [regex, ...],
 *     commands:  { "/slash": { description } },
 *     pipeline:  [ { ability, args_template, why } ],
 *     forbidden_actions: [string, ...],
 *     preflight_required: bool,
 *     preflight_ability:  string,
 *     preflight_questions: [ { key, prompt, type, choices?, conditional_on?, cache_key? } ],
 *     before_answering:   string
 *   }
 *
 * Only ENABLED + UNLOCKED + deps-met skills appear. Locked skills are
 * advertised separately via locked_abilities[] (cross-sell, not routing).
 *
 * @return array<int,array<string,mixed>>
 */
function nibwp_skills_skill_cards(): array
{
    $cards = [];
    foreach (nibwp_skills_discover() as $skill) {
        if (!nibwp_skill_is_enabled($skill['id'])) {
            continue;
        }
        if (!nibwp_skill_deps_met($skill)) {
            continue;
        }
        if (!nibwp_skill_is_unlocked($skill['id'])) {
            continue;
        }
        $manifest_complete = !empty($skill['triggers']) && !empty($skill['mandatory_routing']);
        if (!$manifest_complete) {
            if (function_exists('_doing_it_wrong')) {
                _doing_it_wrong(
                    'nibwp_skills_skill_cards',
                    sprintf('Premium skill "%s" is missing triggers[] or mandatory_routing{} in its manifest. Add the v2 fields so the discover routing contract is complete.', (string) $skill['id']),
                    '1.1.0'
                );
            }
            continue; // Don't surface incomplete cards.
        }
        $routing = (array) $skill['mandatory_routing'];
        $cards[] = [
            'skill_id'           => (string) $skill['id'],
            'name'               => (string) ($skill['name'] ?? $skill['id']),
            'tagline'            => (string) ($skill['tagline'] ?? ''),
            // Plain English, and the field the agent is meant to reason from.
            // `triggers` are a fast path, not the boundary: they are regular
            // expressions, and a customer who asked for "a hero section with
            // etch and acss" matched none of them, so the skill never loaded and
            // the agent improvised a page with no classes and no styles. A model
            // reading a sentence would have routed it correctly.
            'use_when'           => (string) ($skill['use_when'] ?? $skill['tagline'] ?? ''),
            'triggers'           => array_values(array_filter((array) $skill['triggers'])),
            'commands'           => (array) ($skill['commands'] ?? []),
            'pipeline'           => array_values((array) ($routing['pipeline'] ?? [])),
            'forbidden_actions'  => array_values((array) ($routing['forbidden_actions'] ?? [])),
            'preflight_required' => (bool) ($routing['preflight_required'] ?? false),
            'preflight_ability'  => (string) ($routing['preflight_ability'] ?? 'nibwp/skill-preflight'),
            'preflight_questions'=> array_values((array) ($skill['preflight_questions'] ?? [])),
            'before_answering'   => (string) ($routing['before_answering'] ?? ''),
        ];
    }
    return $cards;
}

/**
 * Gate helper for ability callbacks — use at the top of premium tool functions:
 *
 *     $gate = nibwp_skill_gate('etchwp-pro');
 *     if (is_wp_error($gate)) return $gate;
 */
function nibwp_skill_gate(string $id)
{
    if (nibwp_skill_is_unlocked($id)) {
        return true;
    }
    return new WP_Error(
        'skill_locked',
        sprintf(
            'This action requires the %s skill pack. Activate a license at %s/admin.php?page=nibwp-skills',
            nibwp_skill_get($id)['name'] ?? $id,
            admin_url(),
        ),
        ['status' => 402, 'skill_id' => $id, 'upgrade_url' => admin_url('admin.php?page=nibwp-skills')],
    );
}

/**
 * Summary stats for the marketplace dashboard widget.
 *
 * @return array{total: int, premium: int, active: int, locked: int}
 */
function nibwp_skills_stats(): array
{
    $all = nibwp_skills_discover();
    $stats = ['total' => 0, 'premium' => 0, 'active' => 0, 'locked' => 0];
    foreach ($all as $skill) {
        $stats['total']++;
        if (!empty($skill['premium'])) {
            $stats['premium']++;
        }
        if (nibwp_skill_is_enabled($skill['id'])) {
            $stats['active']++;
        }
        if (!empty($skill['premium']) && !nibwp_skill_is_unlocked($skill['id'])) {
            $stats['locked']++;
        }
    }
    return $stats;
}
