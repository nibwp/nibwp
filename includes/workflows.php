<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * NIBWP Workflows — user-and-AI-authored operating playbooks (Pro).
 *
 * A Workflow is a structured markdown playbook (principles, process, strict
 * rules, reporting format, patterns, project notes) that NIBWP stores and serves
 * to the connected AI client as mandatory context. Both the admin UI and the AI
 * agent (via MCP abilities) can create + edit them. The ACTIVE workflow(s) are
 * injected into the discover-abilities instructions so the agent follows them.
 *
 * Layer model: abilities → skills → WORKFLOWS (procedures that conduct them).
 *
 * Stored as the `nibwp_workflow` CPT (revisions ON → free history/rollback).
 * Pro-only: the CPT registers everywhere (harmless), but seeding, the abilities,
 * and context-injection are gated behind nibwp_is_pro().
 */

const NIBWP_WORKFLOW_CPT = 'nibwp_workflow';

/* ----------------------------------------------------------------------------
 * Gate
 * ------------------------------------------------------------------------- */

/** Is the Workflows feature unlocked for this site? Pro / Bundle. */
function nibwp_workflows_unlocked(): bool
{
    if (function_exists('nibwp_is_pro') && nibwp_is_pro()) {
        return true;
    }
    // Bundle wildcard (skill:*) also covers it.
    return function_exists('nibwp_has_entitlement') && nibwp_has_entitlement('skill:*');
}

/** WP_Error gate for ability callbacks. */
function nibwp_workflows_gate()
{
    if (nibwp_workflows_unlocked()) {
        return true;
    }
    return new WP_Error('workflows_locked', 'NIBWP Workflows is a Pro feature. Activate a Pro or Bundle license.', ['status' => 402, 'upgrade_url' => admin_url('admin.php?page=nibwp-license')]);
}

/* ----------------------------------------------------------------------------
 * CPT
 * ------------------------------------------------------------------------- */

add_action('init', static function (): void {
    register_post_type(NIBWP_WORKFLOW_CPT, [
        'label'             => __('Workflows', 'nibwp'),
        'public'            => false,
        'show_ui'           => false,   // our own admin panel renders the UI
        'show_in_menu'      => false,
        'show_in_rest'      => false,   // CRUD goes through nibwp/v1 + MCP abilities
        'hierarchical'      => false,
        'supports'          => ['title', 'editor', 'excerpt', 'revisions'],
        'capability_type'   => 'post',
        'map_meta_cap'      => true,
        'rewrite'           => false,
        'query_var'         => false,
        'delete_with_user'  => false,
    ]);
});

/* ----------------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------------- */

/** Built-in workflow categories (slug => label). */
function nibwp_workflow_builtin_categories(): array
{
    return [
        'build-sites'   => __('Build Sites', 'nibwp'),
        'convert-etchwp'=> __('Convert to EtchWP', 'nibwp'),
        'seo'           => __('SEO', 'nibwp'),
        'content'       => __('Content', 'nibwp'),
        'forms'         => __('Forms', 'nibwp'),
        'automations'   => __('Automations', 'nibwp'),
        'ecommerce'     => __('E-commerce', 'nibwp'),
        'maintenance'   => __('Maintenance', 'nibwp'),
        'custom'        => __('Custom', 'nibwp'),
    ];
}

/** All workflow categories — built-in + user-added custom ones (slug => label). */
function nibwp_workflow_categories(): array
{
    $custom = get_option('nibwp_workflow_custom_categories', []);
    $custom = is_array($custom) ? array_map('strval', $custom) : [];
    return nibwp_workflow_builtin_categories() + $custom;
}

/**
 * Register a custom category from a free-text label; returns its slug. Stored in
 * the option nibwp_workflow_custom_categories (slug => label). Built-in slugs are
 * reused as-is. Lets users (and the AI) name their own categories.
 */
function nibwp_workflow_register_category(string $label): string
{
    $label = sanitize_text_field($label);
    $slug  = sanitize_key(sanitize_title($label));
    if ($slug === '') {
        return 'custom';
    }
    if (array_key_exists($slug, nibwp_workflow_builtin_categories())) {
        return $slug;
    }
    $custom = get_option('nibwp_workflow_custom_categories', []);
    $custom = is_array($custom) ? $custom : [];
    if (!isset($custom[$slug])) {
        $custom[$slug] = $label;
        update_option('nibwp_workflow_custom_categories', $custom, false);
    }
    return $slug;
}

/**
 * Detection status for a tool key referenced by a workflow.
 * Returns 'active' | 'available' | 'missing'. Resolves integration keys first,
 * then skill ids.
 */
function nibwp_workflow_tool_status(string $key): string
{
    $key = sanitize_key($key);
    if ($key === '') {
        return 'missing';
    }
    // Integration?
    if (function_exists('nibwp_is_integration_available') && nibwp_is_integration_available($key)) {
        $enabled = function_exists('nibwp_is_integration_enabled') ? nibwp_is_integration_enabled($key) : false;
        return $enabled ? 'active' : 'available';
    }
    // Skill?
    if (function_exists('nibwp_skill_get') && nibwp_skill_get($key) !== null) {
        $enabled = function_exists('nibwp_skill_is_enabled') && nibwp_skill_is_enabled($key);
        $unlocked = function_exists('nibwp_skill_is_unlocked') && nibwp_skill_is_unlocked($key);
        return $enabled ? 'active' : ($unlocked ? 'available' : 'missing');
    }
    // Theme? (installed theme by its stylesheet/template slug)
    static $theme_slugs = null;
    if ($theme_slugs === null && function_exists('wp_get_themes')) {
        $theme_slugs = array_map('strval', array_keys(wp_get_themes()));
    }
    if (is_array($theme_slugs) && in_array($key, $theme_slugs, true)) {
        return (function_exists('get_stylesheet') && get_stylesheet() === $key)
            || (function_exists('get_template') && get_template() === $key)
            ? 'active' : 'available';
    }
    return 'missing';
}

/**
 * Normalize a workflow visibility value to an allowed subset.
 * Private is exclusive: if present (or empty), returns ['private']. Otherwise
 * any of ['license','community'] (License Circle and/or Community).
 *
 * @return array<int,string>
 */
function nibwp_workflow_sanitize_visibility($v): array
{
    $allowed = ['private', 'license', 'community'];
    $v = array_values(array_intersect($allowed, array_map('sanitize_key', (array) $v)));
    if ($v === [] || in_array('private', $v, true)) {
        return ['private'];
    }
    return $v;
}

/**
 * Is this workflow owned by this install (so the user may change its sharing /
 * attribution)? Built-in starters and imported community/license copies are NOT
 * owned — duplicate one to get your own editable, private copy.
 *
 * @param array<string,mixed> $wf a nibwp_workflow_to_array() result
 */
function nibwp_workflow_is_owned(array $wf): bool
{
    $source = (string) ($wf['source'] ?? 'admin');
    return !in_array($source, ['starter', 'community', 'license'], true);
}

/* ----------------------------------------------------------------------------
 * Workflow votes — centralized thumbs up/down on nibwp.com, one per install.
 * ------------------------------------------------------------------------- */

/** Stable per-install id (uuid), created once. */
if (!function_exists('nibwp_install_id')) {
    function nibwp_install_id(): string
    {
        $id = get_option('nibwp_install_id', '');
        if (!is_string($id) || $id === '') {
            $id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : md5(uniqid('nibwp', true));
            update_option('nibwp_install_id', $id, false);
        }
        return (string) $id;
    }
}

/** One-way voter id for this install (any install may vote once per workflow). */
function nibwp_workflow_voter_hash(): string
{
    return hash('sha256', 'nibwp-voter|' . nibwp_install_id());
}

/**
 * Base URL of the NibWP Library hub (the site running the NibWP Library plugin).
 * Override with the NIBWP_LIBRARY_HUB constant (wp-config) or the filter.
 */
function nibwp_library_hub_url(): string
{
    $url = defined('NIBWP_LIBRARY_HUB') ? (string) NIBWP_LIBRARY_HUB : 'https://library.nibwp.com';
    return rtrim((string) apply_filters('nibwp_library_hub_url', $url), '/');
}

/** Base URL of the centralized votes API (served by the Library hub). */
function nibwp_votes_api_base(): string
{
    return (string) apply_filters('nibwp_votes_api_base', nibwp_library_hub_url() . '/wp-json/nibwp/v1');
}

/** Base URL of the NibWP Library distribution API (community + curated assets). */
function nibwp_library_api_base(): string
{
    return (string) apply_filters('nibwp_library_api_base', nibwp_library_hub_url() . '/wp-json/nibwp-library/v1');
}

/** Slugs already present locally (post slugs + bound starter slugs) — for discover dedupe. */
function nibwp_workflows_local_slugs(): array
{
    $have = [];
    foreach (nibwp_workflows_posts() as $p) {
        $have[$p->post_name] = 1;
        $s = (string) get_post_meta($p->ID, '_nibwp_wf_starter_slug', true);
        if ($s !== '') {
            $have[$s] = 1;
        }
    }
    return $have;
}

/**
 * Shape a workflow post into a plain array. Pass $with_body=false for lists.
 *
 * @return array<string,mixed>
 */
function nibwp_workflow_to_array(WP_Post $post, bool $with_body = true): array
{
    $tools = (array) get_post_meta($post->ID, '_nibwp_wf_tools', true);
    $tools = array_values(array_filter(array_map('sanitize_key', $tools)));
    $tool_status = [];
    foreach ($tools as $t) {
        $tool_status[] = ['key' => $t, 'status' => nibwp_workflow_tool_status($t)];
    }
    $out = [
        'id'      => $post->ID,
        'slug'    => $post->post_name,
        'title'   => $post->post_title,
        'summary' => $post->post_excerpt,
        'when'    => (string) get_post_meta($post->ID, '_nibwp_wf_when', true),
        'active'  => (bool) get_post_meta($post->ID, '_nibwp_wf_active', true),
        'pinned'  => (bool) get_post_meta($post->ID, '_nibwp_wf_active', true),
        'source'  => (string) (get_post_meta($post->ID, '_nibwp_wf_source', true) ?: 'admin'),
        'category'=> (string) (get_post_meta($post->ID, '_nibwp_wf_category', true) ?: 'custom'),
        'creator' => (string) get_post_meta($post->ID, '_nibwp_wf_creator', true),
        'visibility' => nibwp_workflow_sanitize_visibility(get_post_meta($post->ID, '_nibwp_wf_visibility', true)),
        'vote_key' => (string) get_post_meta($post->ID, '_nibwp_wf_starter_slug', true),
        'icon'    => (string) get_post_meta($post->ID, '_nibwp_wf_icon', true),
        'tools'   => $tool_status,
        'updated' => $post->post_modified_gmt,
    ];
    $out['owned'] = nibwp_workflow_is_owned($out);
    if ($with_body) {
        $out['body'] = $post->post_content;
    }
    return $out;
}

/**
 * All workflows (newest first).
 *
 * @return array<int,WP_Post>
 */
function nibwp_workflows_posts(): array
{
    return get_posts([
        'post_type'   => NIBWP_WORKFLOW_CPT,
        'post_status' => ['publish', 'draft'],
        'numberposts' => 200,
        'orderby'     => 'title',
        'order'       => 'ASC',
    ]);
}

/** Resolve a workflow post by id or slug. */
function nibwp_workflow_find($id_or_slug): ?WP_Post
{
    if (is_numeric($id_or_slug)) {
        $p = get_post((int) $id_or_slug);
        return ($p instanceof WP_Post && $p->post_type === NIBWP_WORKFLOW_CPT) ? $p : null;
    }
    $slug = sanitize_title((string) $id_or_slug);
    $found = get_posts([
        'post_type'   => NIBWP_WORKFLOW_CPT,
        'name'        => $slug,
        'post_status' => ['publish', 'draft'],
        'numberposts' => 1,
    ]);
    return $found ? $found[0] : null;
}

/** Active workflow arrays (with body). */
function nibwp_workflows_active(): array
{
    $out = [];
    foreach (nibwp_workflows_posts() as $p) {
        if (get_post_meta($p->ID, '_nibwp_wf_active', true)) {
            $out[] = nibwp_workflow_to_array($p, true);
        }
    }
    return $out;
}

/**
 * Persist a workflow from a normalized input array. Creates or updates.
 *
 * @param array<string,mixed> $in { id?, title, body, summary?, when?, tools?, source?, icon?, active? }
 * @return int|WP_Error post id
 */
function nibwp_workflow_save(array $in)
{
    $id = isset($in['id']) ? (int) $in['id'] : 0;
    $postarr = [
        'post_type'   => NIBWP_WORKFLOW_CPT,
        'post_status' => 'publish',
    ];
    if ($id > 0) {
        $postarr['ID'] = $id;
    }
    if (array_key_exists('title', $in)) {
        $postarr['post_title'] = sanitize_text_field((string) $in['title']);
    }
    if (array_key_exists('body', $in)) {
        // Playbooks are markdown/plain text — keep author HTML/markdown intact,
        // strip only scripts. wp_kses_post is too aggressive for code fences, so
        // store raw text with KSES on a generous allowlist via wp_filter_post_kses.
        $postarr['post_content'] = (string) $in['body'];
    }
    if (array_key_exists('summary', $in)) {
        $postarr['post_excerpt'] = sanitize_text_field((string) $in['summary']);
    }
    if ($id <= 0 && empty($postarr['post_title'])) {
        $postarr['post_title'] = __('Untitled workflow', 'nibwp');
    }

    // Updating only the metadata — a visibility change, a category, a rename of
    // the creator — carries no title, content or excerpt, and wp_insert_post
    // rejects that as an empty post. The whole save then failed and the change
    // was silently dropped. Nothing to write to the post itself is not an error;
    // it just means the work is all in the meta below.
    $touches_post = isset($postarr['post_title']) || isset($postarr['post_content']) || isset($postarr['post_excerpt']);
    if ($id > 0 && !$touches_post) {
        if (get_post_type($id) !== NIBWP_WORKFLOW_CPT) {
            return new WP_Error('nibwp_workflow_missing', __('That workflow does not exist.', 'nibwp'));
        }
        $post_id = $id;
    } else {
        $post_id = wp_insert_post(wp_slash($postarr), true);
        if (is_wp_error($post_id)) {
            return $post_id;
        }
        $post_id = (int) $post_id;
    }

    if (array_key_exists('when', $in)) {
        update_post_meta($post_id, '_nibwp_wf_when', sanitize_text_field((string) $in['when']));
    }
    if (!empty($in['category_new'])) {
        update_post_meta($post_id, '_nibwp_wf_category', nibwp_workflow_register_category((string) $in['category_new']));
    } elseif (array_key_exists('category', $in)) {
        $raw  = (string) $in['category'];
        $slug = sanitize_key($raw);
        if (array_key_exists($slug, nibwp_workflow_categories())) {
            $cat = $slug;
        } elseif ($slug !== '' && $slug !== 'new') {
            // Unknown category (from the AI or an import) → register it as custom.
            $cat = nibwp_workflow_register_category($raw);
        } else {
            $cat = 'custom';
        }
        update_post_meta($post_id, '_nibwp_wf_category', $cat);
    }
    if (array_key_exists('tools', $in)) {
        $tools = array_values(array_filter(array_map('sanitize_key', (array) $in['tools'])));
        update_post_meta($post_id, '_nibwp_wf_tools', $tools);
    }
    if (array_key_exists('icon', $in)) {
        update_post_meta($post_id, '_nibwp_wf_icon', sanitize_key((string) $in['icon']));
    }
    if (array_key_exists('source', $in)) {
        update_post_meta($post_id, '_nibwp_wf_source', sanitize_key((string) $in['source']));
    } elseif ($id <= 0) {
        update_post_meta($post_id, '_nibwp_wf_source', 'admin');
    }
    if (array_key_exists('creator', $in)) {
        update_post_meta($post_id, '_nibwp_wf_creator', sanitize_text_field((string) $in['creator']));
    } elseif ($id <= 0) {
        $creator = '';
        if (function_exists('wp_get_current_user')) {
            $u = wp_get_current_user();
            $creator = ($u && !empty($u->display_name)) ? (string) $u->display_name : '';
        }
        update_post_meta($post_id, '_nibwp_wf_creator', sanitize_text_field($creator));
    }
    if (array_key_exists('visibility', $in)) {
        update_post_meta($post_id, '_nibwp_wf_visibility', nibwp_workflow_sanitize_visibility($in['visibility']));
    } elseif ($id <= 0) {
        update_post_meta($post_id, '_nibwp_wf_visibility', ['private']);
    }
    if (array_key_exists('active', $in)) {
        nibwp_workflow_set_active($post_id, (bool) $in['active']);
    }

    // Ticking a sharing scope has to actually share it. Queued rather than sent
    // here, so saving a workflow never waits on the network or fails with it.
    if (function_exists('nibwp_workflow_sharing_queue')) {
        nibwp_workflow_sharing_queue($post_id);
    }

    return $post_id;
}

/** Flip a workflow's active flag (optionally exclusive — deactivate all others). */
function nibwp_workflow_set_active(int $post_id, bool $active, bool $exclusive = false): void
{
    if ($active && $exclusive) {
        foreach (nibwp_workflows_posts() as $p) {
            if ($p->ID !== $post_id) {
                delete_post_meta($p->ID, '_nibwp_wf_active');
            }
        }
    }
    if ($active) {
        update_post_meta($post_id, '_nibwp_wf_active', 1);
    } else {
        delete_post_meta($post_id, '_nibwp_wf_active');
    }
}

/* ----------------------------------------------------------------------------
 * Starters — seed the shipped playbooks once (idempotent by stable slug)
 * ------------------------------------------------------------------------- */

/**
 * Manifest of shipped starter workflows. Body lives in
 * includes/workflows/starters/<slug>.md.
 *
 * @return array<string,array{title:string,summary:string,when:string,tools:array<int,string>,icon:string}>
 */
function nibwp_workflows_starters(): array
{
    return [
        // ── Build Sites ──────────────────────────────────────────────────
        'build-from-screenshot' => [
            'title'   => 'Build from a screenshot / image',
            'summary' => 'Recreate a design from a screenshot or image as a real, validated page/section — EtchWP Pro + ACSS tokens, responsive, accessible, pixel-accurate.',
            'when'    => 'Rebuilding a design from a screenshot, mockup, or image into the site builder.',
            'tools'   => ['etchwp', 'etchwp-pro', 'acss-pro', 'automaticcss'],
            'category'=> 'build-sites',
            'icon'    => 'image',
        ],
        'build-from-html' => [
            'title'   => 'Convert HTML / a URL to components',
            'summary' => 'Rebuild raw HTML or a live URL as clean builder components via the EtchWP Pro / Bricks Pro skill — no inline styles, real elements, design tokens.',
            'when'    => 'Converting pasted HTML, a Figma export, or a URL into Etch/Bricks components.',
            'tools'   => ['etchwp-pro', 'bricks-pro'],
            'category'=> 'build-sites',
            'icon'    => 'code-2',
        ],
        'full-site-build' => [
            'title'   => 'Build a full site from a brief',
            'summary' => 'Brief → sitemap → design tokens → global styles → page-by-page build → nav/footer → QA. The whole stack: EtchWP Pro, ACSS, ACF, forms.',
            'when'    => 'Standing up a multi-page site from a brief or sitemap.',
            'tools'   => ['etchwp', 'etchwp-pro', 'acss-pro', 'automaticcss', 'acf'],
            'category'=> 'build-sites',
            'icon'    => 'layout-template',
        ],
        'page-build-standard' => [
            'title'   => 'Page build standard',
            'summary' => 'Detect the builder, build in sections, design tokens not hardcodes, responsive + accessible + fast.',
            'when'    => 'Building any page or section with the site’s page builder.',
            'tools'   => [],
            'category'=> 'build-sites',
            'icon'    => 'layout',
        ],
        // ── Convert to EtchWP ────────────────────────────────────────────
        'convert-to-etchwp' => [
            'title'   => 'Convert a page to EtchWP',
            'summary' => 'Rebuild a page from another builder, Gutenberg, HTML, a URL, or Figma as native EtchWP — ACSS tokens, global classes, query loops, native elements, validated + parity-checked.',
            'when'    => 'Migrating or rebuilding existing content (Bricks / Elementor / Divi / WPBakery / Gutenberg / HTML / URL / Figma) into EtchWP.',
            'tools'   => ['etchwp', 'etchwp-pro', 'acss-pro', 'automaticcss', 'acf'],
            'category'=> 'convert-etchwp',
            'icon'    => 'replace',
        ],
        'convert-to-kadence' => [
            'title'   => 'Convert a design to Kadence Blocks',
            'summary' => 'Rebuild HTML, a URL, or a screenshot as native Kadence Blocks via the Kadence Pro skill — real blocks (rowlayout/column/advancedheading/singlebtn/infobox…), correct nesting, unique IDs, attribute styling, validated + scored, persisted to a page, template, or pattern.',
            'when'    => 'Rebuilding a design (HTML / URL / image) into a Kadence Blocks layout, template, or reusable pattern.',
            'tools'   => ['kadence', 'kadence-pro'],
            'category'=> 'build-sites',
            'icon'    => 'layout-grid',
        ],
        'voxel-directory-build' => [
            'title'   => 'Build a Voxel directory',
            'summary' => 'From a post type to a working directory — fields and filters, then the preview card, single layout, archive and a wired search page, each assigned and each checked against the site\'s own data.',
            'when'    => 'Standing up a new listing type on a Voxel site, or rebuilding one end to end.',
            'tools'   => ['voxel', 'voxel-pro'],
            'category'=> 'build-sites',
            'icon'    => 'layout-dashboard',
        ],
        'voxel-design-card' => [
            'title'   => 'Design a Voxel preview card',
            'summary' => 'One preview card for a Voxel post type — bound to real field keys, every dynamic tag rendered against a real listing before it is written, assigned as the main card or a named alternate.',
            'when'    => 'Changing how listings look in search results, feeds, or map popups.',
            'tools'   => ['voxel', 'voxel-pro'],
            'category'=> 'build-sites',
            'icon'    => 'credit-card',
        ],
        // ── SEO ──────────────────────────────────────────────────────────
        'seo-full-audit' => [
            'title'   => 'Full SEO audit',
            'summary' => 'End-to-end SEO health check — technical, indexing, on-page, content, internal links, schema, performance — into a scored, prioritized report.',
            'when'    => 'A complete SEO health check of a site or a section.',
            'tools'   => ['seo', 'seo-pro'],
            'category'=> 'seo',
            'icon'    => 'search-check',
        ],
        'seo-content-pass' => [
            'title'   => 'SEO content pass',
            'summary' => 'One post → keyword/intent → title + meta → headings → internal links → schema → image alt → publish.',
            'when'    => 'Publishing or optimizing a single post or page for search.',
            'tools'   => ['seo', 'seo-pro'],
            'category'=> 'seo',
            'icon'    => 'search',
        ],
        'fix-404-redirects' => [
            'title'   => 'Fix 404s with 301 redirects',
            'summary' => 'Pull the 404 log, group by intent, map each to the best live target, create 301s, verify.',
            'when'    => 'Broken links or 404s after a migration, rename, or restructure.',
            'tools'   => ['seo', 'redirection'],
            'category'=> 'seo',
            'icon'    => 'signpost',
        ],
        // ── Content ──────────────────────────────────────────────────────
        'content-creation' => [
            'title'   => 'Content creation (research → publish)',
            'summary' => 'Research the topic + SERP → brief → outline → draft in brand voice → media + alt → SEO pass → internal links → schedule.',
            'when'    => 'Writing a new article or page from scratch.',
            'tools'   => ['seo'],
            'category'=> 'content',
            'icon'    => 'pen-tool',
        ],
        'content-cleanup' => [
            'title'   => 'Content audit & cleanup',
            'summary' => 'Find thin, duplicate, outdated, or cannibalizing content → decide keep / merge / redirect / refresh.',
            'when'    => 'Pruning or consolidating a large content library.',
            'tools'   => ['seo'],
            'category'=> 'content',
            'icon'    => 'list-checks',
        ],
        // ── Forms ────────────────────────────────────────────────────────
        'contact-form' => [
            'title'   => 'Build a contact / lead form',
            'summary' => 'Detected forms plugin: fields, validation, spam protection, admin + autoresponder email, confirmation, CRM hook, real delivery test.',
            'when'    => 'Adding a contact or lead-capture form to a site.',
            'tools'   => ['forms'],
            'category'=> 'forms',
            'icon'    => 'mail',
        ],
        // ── Automations ──────────────────────────────────────────────────
        'automation-setup' => [
            'title'   => 'Build an automation (trigger → action)',
            'summary' => 'Map trigger → conditions → actions, build it in the detected automation tool (FluentCRM / Fluent Forms / Woo), test end-to-end, document it.',
            'when'    => 'Automating a flow: form → email + tag, new order → fulfillment, signup → onboarding sequence.',
            'tools'   => ['fluentcrm', 'fluent-forms'],
            'category'=> 'automations',
            'icon'    => 'workflow',
        ],
        // ── E-commerce ───────────────────────────────────────────────────
        'woocommerce-product' => [
            'title'   => 'Create a WooCommerce product',
            'summary' => 'Type → pricing → images + alt → description → attributes/variations → inventory → SEO/schema → categories → publish.',
            'when'    => 'Adding or optimizing a WooCommerce product.',
            'tools'   => ['woocommerce'],
            'category'=> 'ecommerce',
            'icon'    => 'shopping-bag',
        ],
        // ── Maintenance ──────────────────────────────────────────────────
        'safe-changes' => [
            'title'   => 'Safe changes (plan → approve → apply)',
            'summary' => 'Plan first, explain the impact, get explicit approval, apply, summarize, and always leave a rollback path.',
            'when'    => 'Any change to a live, client, or production site.',
            'tools'   => [],
            'category'=> 'maintenance',
            'icon'    => 'shield-check',
        ],
        'site-audit-report' => [
            'title'   => 'Site audit (read-only report)',
            'summary' => 'Inspect-only health check → structured Issues / Observations / Suggestions report, prioritized Critical → Note.',
            'when'    => 'Auditing, reviewing, or debugging an existing site before any change.',
            'tools'   => [],
            'category'=> 'maintenance',
            'icon'    => 'clipboard-check',
        ],
        'pre-launch-checklist' => [
            'title'   => 'Pre-launch checklist',
            'summary' => 'Backups, security, SEO basics, broken links, performance, and final QA before a site goes live.',
            'when'    => 'Before taking a site live or handing it off to a client.',
            'tools'   => ['seo'],
            'category'=> 'maintenance',
            'icon'    => 'rocket',
        ],
        'performance-tune' => [
            'title'   => 'Performance tune-up',
            'summary' => 'Measure Core Web Vitals, then fix the biggest wins: images, caching, render-blocking assets, slow queries.',
            'when'    => 'A site feels slow or fails Core Web Vitals.',
            'tools'   => [],
            'category'=> 'maintenance',
            'icon'    => 'gauge',
        ],
        // ── Custom ───────────────────────────────────────────────────────
        'cpt-and-fields' => [
            'title'   => 'Custom post type + fields',
            'summary' => 'Model new content: register a CPT, add ACF fields, set admin columns, wire templates and queries.',
            'when'    => 'Modeling new structured content with a CPT and custom fields.',
            'tools'   => ['acf'],
            'category'=> 'custom',
            'icon'    => 'database',
        ],
    ];
}

/** Read a starter's markdown body from disk. */
function nibwp_workflows_starter_body(string $slug): string
{
    $file = __DIR__ . '/workflows/starters/' . sanitize_file_name($slug) . '.md';
    return is_file($file) ? (string) file_get_contents($file) : '';
}

/**
 * Seed any missing starter workflows into the CPT. Idempotent: a starter is
 * skipped if a post with its `_nibwp_wf_starter_slug` already exists, so user
 * edits survive. Safe to call on every admin load.
 */
function nibwp_workflows_seed_starters(): void
{
    if (!nibwp_workflows_unlocked()) {
        return;
    }
    // Cheap guard so we don't query on every single admin request. Bump to
    // re-seed: refreshes shipped text + back-fills creator/visibility.
    $version = '8';
    if (get_option('nibwp_workflows_seeded') === $version) {
        return;
    }

    $manifest = nibwp_workflows_starters();

    // Remove every starter-sourced post that isn't a current manifest entry —
    // clears retired starters AND any duplicates left by earlier seeds/migrations.
    // User-created workflows (source != 'starter') are never touched.
    foreach (nibwp_workflows_posts() as $p) {
        $src  = (string) get_post_meta($p->ID, '_nibwp_wf_source', true);
        $slug = (string) get_post_meta($p->ID, '_nibwp_wf_starter_slug', true);
        if ($src === 'starter' && !isset($manifest[$slug])) {
            wp_trash_post($p->ID);
        }
    }

    // Map the surviving shipped starters by their stable slug (for upsert).
    $by_slug = [];
    foreach (nibwp_workflows_posts() as $p) {
        $s = (string) get_post_meta($p->ID, '_nibwp_wf_starter_slug', true);
        if ($s !== '') {
            $by_slug[$s] = $p;
        }
    }

    // ponytail: upsert refreshes shipped starters to the current text on each
    // version bump — these are managed defaults, so direct edits to them are not
    // preserved (revisions remain for rollback). User-created workflows (no
    // starter slug) are never touched.
    foreach ($manifest as $slug => $meta) {
        $body = nibwp_workflows_starter_body($slug);
        if ($body === '') {
            continue;
        }
        $existing = $by_slug[$slug] ?? null;
        $id = nibwp_workflow_save([
            'id'       => $existing ? $existing->ID : 0,
            'title'    => $meta['title'],
            'body'     => $body,
            'summary'  => $meta['summary'],
            'when'     => $meta['when'],
            'tools'    => $meta['tools'],
            'category' => $meta['category'] ?? 'custom',
            'icon'     => $meta['icon'],
            'source'   => 'starter',
            'creator'  => 'NIBWP.COM',
        ]);
        if (!is_wp_error($id)) {
            update_post_meta($id, '_nibwp_wf_starter_slug', $slug);
        }
    }
    update_option('nibwp_workflows_seeded', $version, false);
}
add_action('admin_init', 'nibwp_workflows_seed_starters');

/**
 * Re-create any shipped default workflows the user has deleted. Only adds the
 * manifest slugs that are currently missing — never overwrites existing ones or
 * touches user-created workflows. Returns how many were restored.
 */
function nibwp_workflows_restore_defaults(): int
{
    if (!nibwp_workflows_unlocked()) {
        return 0;
    }
    $existing = [];
    foreach (nibwp_workflows_posts() as $p) {
        $s = (string) get_post_meta($p->ID, '_nibwp_wf_starter_slug', true);
        if ($s !== '') {
            $existing[$s] = true;
        }
    }
    $restored = 0;

    // 1) The canonical defaults are whatever the NibWP Library hub curates —
    //    workflows added/approved on library.nibwp.com. Pull them live.
    foreach (nibwp_workflows_hub_defaults() as $slug => $d) {
        if (isset($existing[$slug]) || ($d['body'] ?? '') === '') {
            continue;
        }
        $id = nibwp_workflow_save([
            'title'    => $d['title'],
            'body'     => $d['body'],
            'summary'  => $d['summary'],
            'when'     => $d['when'],
            'tools'    => $d['tools'],
            'category' => $d['category'],
            'icon'     => $d['icon'],
            'source'   => 'starter',
            'creator'  => $d['author'] !== '' ? $d['author'] : 'NIBWP.COM',
        ]);
        if (!is_wp_error($id)) {
            update_post_meta($id, '_nibwp_wf_starter_slug', $slug);
            $existing[$slug] = true;
            $restored++;
        }
    }

    // 2) Back-fill from the bundled starters for anything the hub didn't cover
    //    (hub unreachable / offline).
    foreach (nibwp_workflows_starters() as $slug => $meta) {
        if (isset($existing[$slug])) {
            continue;
        }
        $body = nibwp_workflows_starter_body($slug);
        if ($body === '') {
            continue;
        }
        $id = nibwp_workflow_save([
            'title'    => $meta['title'],
            'body'     => $body,
            'summary'  => $meta['summary'],
            'when'     => $meta['when'],
            'tools'    => $meta['tools'],
            'category' => $meta['category'] ?? 'custom',
            'icon'     => $meta['icon'],
            'source'   => 'starter',
            'creator'  => 'NIBWP.COM',
        ]);
        if (!is_wp_error($id)) {
            update_post_meta($id, '_nibwp_wf_starter_slug', $slug);
            $restored++;
        }
    }
    return $restored;
}

/**
 * The Library hub's curated workflows — the live, canonical default set.
 * Returns [slug => {title,summary,when,tools,category,icon,author,body}]. Empty
 * if the hub is unreachable (caller falls back to the bundled starters).
 */
function nibwp_workflows_hub_defaults(): array
{
    $url  = nibwp_library_api_base() . '/assets?' . http_build_query(['type' => 'workflow', 'channel' => 'curated', 'per_page' => 200]);
    $resp = wp_remote_get($url, ['timeout' => 10]);
    if (is_wp_error($resp)) {
        return [];
    }
    $body   = json_decode((string) wp_remote_retrieve_body($resp), true);
    $assets = is_array($body['assets'] ?? null) ? $body['assets'] : [];
    $out = [];
    foreach (array_slice($assets, 0, 200) as $a) {
        $slug = (string) ($a['vote_key'] ?? $a['slug'] ?? '');
        if ($slug === '') {
            continue;
        }
        // The list omits the body; fetch it per asset.
        $playbook = '';
        $br = wp_remote_get(nibwp_library_api_base() . '/assets/' . (int) ($a['id'] ?? 0), ['timeout' => 10]);
        if (!is_wp_error($br)) {
            $bd = json_decode((string) wp_remote_retrieve_body($br), true);
            $playbook = (string) ($bd['asset']['body'] ?? '');
        }
        $out[$slug] = [
            'title'    => (string) ($a['title'] ?? $slug),
            'summary'  => (string) ($a['summary'] ?? ''),
            'when'     => (string) ($a['when'] ?? ''),
            'tools'    => (array) ($a['tools'] ?? []),
            'category' => (string) ($a['category'] ?? 'custom'),
            'icon'     => (string) ($a['icon'] ?? ''),
            'author'   => (string) ($a['author'] ?? 'NIBWP.COM'),
            'body'     => $playbook,
        ];
    }
    return $out;
}

/**
 * One-time: re-attribute every EXISTING workflow to NIBWP.COM and mark it a
 * shipped default (source 'starter' → not user-owned), so the install's whole
 * baseline belongs to NIBWP.COM. Workflows the user creates or duplicates after
 * this runs are their own (owned, editable visibility).
 */
function nibwp_workflows_claim_for_nibwp(): void
{
    if (!nibwp_workflows_unlocked()) {
        return;
    }
    if (get_option('nibwp_workflows_nibwp_owned') === '1') {
        return;
    }
    foreach (nibwp_workflows_posts() as $p) {
        update_post_meta($p->ID, '_nibwp_wf_creator', 'NIBWP.COM');
        update_post_meta($p->ID, '_nibwp_wf_source', 'starter');
    }
    update_option('nibwp_workflows_nibwp_owned', '1', false);
}
add_action('admin_init', 'nibwp_workflows_claim_for_nibwp', 11);

/* ----------------------------------------------------------------------------
 * Context injection — the active workflow(s) are followed strictly
 * ------------------------------------------------------------------------- */

add_filter('nibwp_discover_abilities_instructions', static function ($instructions) {
    $instructions = (string) $instructions;
    if (!nibwp_workflows_unlocked()) {
        return $instructions;
    }
    $posts = nibwp_workflows_posts();
    if ($posts === []) {
        return $instructions;
    }

    $pinned = [];
    $index  = [];
    foreach ($posts as $p) {
        $index[] = nibwp_workflow_to_array($p, false);
        if (get_post_meta($p->ID, '_nibwp_wf_active', true)) {
            $pinned[] = nibwp_workflow_to_array($p, true);
        }
    }

    $block = "\n\n## NIBWP Workflows\n\n"
        . "Workflows are the user's saved operating playbooks (principles, process, strict rules, standards). "
        . "When a request matches a workflow's \"when to use\", load that playbook with `nibwp/get-workflow { slug }` and FOLLOW IT STRICTLY — do not improvise around it. Auto-route by best match; if the user names one, use it.\n";

    // Pinned (always-on) workflows are inlined in full and apply to every request.
    if ($pinned !== []) {
        $cap  = 14000;
        $used = strlen($block);
        $block .= "\n### Pinned — ALWAYS FOLLOW STRICTLY\n";
        foreach ($pinned as $wf) {
            $head = sprintf("\n---\n#### %s\n%s\n\n", (string) $wf['title'], $wf['when'] !== '' ? '_When:_ ' . (string) $wf['when'] : '');
            $body = (string) ($wf['body'] ?? '');
            if ($used + strlen($head) + strlen($body) > $cap) {
                $block .= $head . sprintf("Large — call `nibwp/get-workflow { id: %d }` for the full text.\n", (int) $wf['id']);
                $used += strlen($head) + 120;
                continue;
            }
            $block .= $head . $body . "\n";
            $used += strlen($head) + strlen($body);
        }
    }

    // Index of every workflow so the agent can auto-route + load on demand.
    $block .= "\n### Available workflows (load the matching one via nibwp/get-workflow)\n";
    foreach ($index as $wf) {
        $tools = implode(', ', array_map(static fn ($t) => (string) $t['key'], (array) $wf['tools']));
        $block .= sprintf(
            "- **%s** (slug: `%s`)%s%s%s\n",
            (string) $wf['title'],
            (string) $wf['slug'],
            !empty($wf['active']) ? ' — PINNED' : '',
            $wf['when'] !== '' ? ' — when: ' . (string) $wf['when'] : '',
            $tools !== '' ? ' — tools: ' . $tools : ''
        );
    }

    return $instructions . $block;
});

/* ----------------------------------------------------------------------------
 * MCP abilities (Pro-gated)
 * ------------------------------------------------------------------------- */

add_action('wp_abilities_api_init', static function (): void {
    if (!function_exists('wp_register_ability')) {
        return;
    }

    wp_register_ability('nibwp/list-workflows', [
        'label'       => __('List workflows', 'nibwp'),
        'description' => __('List saved NIBWP workflows (operating playbooks) with their summary, when-to-use, whether they are PINNED (always-on), and the tools each one targets (with detection status). Unpinned workflows are still used — the agent auto-loads them when a task matches their when-to-use.', 'nibwp'),
        'category'    => 'nibwp',
        'input_schema'  => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
        'output_schema' => ['type' => 'object'],
        'execute_callback'    => 'nibwp_ability_list_workflows',
        'permission_callback' => 'nibwp_permission_callback',
        'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
    ]);

    wp_register_ability('nibwp/get-workflow', [
        'label'       => __('Get workflow', 'nibwp'),
        'description' => __('Load the full markdown playbook + metadata for one workflow, by id or slug.', 'nibwp'),
        'category'    => 'nibwp',
        'input_schema'  => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'slug' => ['type' => 'string']], 'additionalProperties' => false],
        'output_schema' => ['type' => 'object'],
        'execute_callback'    => 'nibwp_ability_get_workflow',
        'permission_callback' => 'nibwp_permission_callback',
        'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
    ]);

    wp_register_ability('nibwp/save-workflow', [
        'label'       => __('Save workflow', 'nibwp'),
        'description' => __('Create a new NIBWP workflow (operating playbook) — capture a process you just agreed on. Provide a title + the markdown body (principles, process, strict rules, reporting, patterns). Optionally set when-to-use, target tools, and activate it.', 'nibwp'),
        'category'    => 'nibwp',
        'input_schema'  => [
            'type' => 'object',
            'properties' => [
                'title'    => ['type' => 'string'],
                'body'     => ['type' => 'string', 'description' => 'The markdown playbook.'],
                'summary'  => ['type' => 'string'],
                'when'     => ['type' => 'string', 'description' => 'When to use this workflow.'],
                'tools'    => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Integration/skill keys this workflow targets (e.g. bricks, acf, etchwp-pro).'],
                'category' => ['type' => 'string', 'description' => 'One of: page-builders, seo, content, forms, maintenance, custom.'],
                'activate' => ['type' => 'boolean', 'description' => 'Pin it (always-on): inject its playbook as strict context on every request.'],
            ],
            'required' => ['title', 'body'],
            'additionalProperties' => false,
        ],
        'output_schema' => ['type' => 'object'],
        'execute_callback'    => 'nibwp_ability_save_workflow',
        'permission_callback' => 'nibwp_permission_callback',
        'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
    ]);

    wp_register_ability('nibwp/update-workflow', [
        'label'       => __('Update workflow', 'nibwp'),
        'description' => __('Edit an existing workflow (by id or slug) — change the body, rules, summary, when-to-use, tools, or active state. A revision is kept so changes can be rolled back.', 'nibwp'),
        'category'    => 'nibwp',
        'input_schema'  => [
            'type' => 'object',
            'properties' => [
                'id'      => ['type' => 'integer'],
                'slug'    => ['type' => 'string'],
                'title'   => ['type' => 'string'],
                'body'    => ['type' => 'string'],
                'summary' => ['type' => 'string'],
                'when'    => ['type' => 'string'],
                'tools'   => ['type' => 'array', 'items' => ['type' => 'string']],
                'category'=> ['type' => 'string'],
                'active'  => ['type' => 'boolean'],
            ],
            'additionalProperties' => false,
        ],
        'output_schema' => ['type' => 'object'],
        'execute_callback'    => 'nibwp_ability_update_workflow',
        'permission_callback' => 'nibwp_permission_callback',
        'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
    ]);

    wp_register_ability('nibwp/activate-workflow', [
        'label'       => __('Pin / unpin workflow', 'nibwp'),
        'description' => __('Pin or unpin a workflow. PINNED = always-on: its playbook is injected as strict context on EVERY request. A workflow does NOT need to be pinned to be used — unpinned ones auto-load when a task matches their when-to-use. Pass exclusive:true to make it the only pinned one.', 'nibwp'),
        'category'    => 'nibwp',
        'input_schema'  => [
            'type' => 'object',
            'properties' => [
                'id'        => ['type' => 'integer'],
                'slug'      => ['type' => 'string'],
                'active'    => ['type' => 'boolean', 'default' => true, 'description' => 'Pin (true) or unpin (false).'],
                'exclusive' => ['type' => 'boolean', 'description' => 'Unpin every other workflow (only this one stays pinned).'],
            ],
            'additionalProperties' => false,
        ],
        'output_schema' => ['type' => 'object'],
        'execute_callback'    => 'nibwp_ability_activate_workflow',
        'permission_callback' => 'nibwp_permission_callback',
        'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
    ]);
}, 15);

function nibwp_ability_list_workflows(array $input): array|WP_Error
{
    $gate = nibwp_workflows_gate();
    if (is_wp_error($gate)) {
        return $gate;
    }
    $items = [];
    foreach (nibwp_workflows_posts() as $p) {
        $items[] = nibwp_workflow_to_array($p, false);
    }
    return ['workflows' => $items, 'count' => count($items), 'pinned' => count(array_filter($items, static fn ($w) => !empty($w['pinned'])))];
}

function nibwp_ability_get_workflow(array $input): array|WP_Error
{
    $gate = nibwp_workflows_gate();
    if (is_wp_error($gate)) {
        return $gate;
    }
    $ref = $input['id'] ?? ($input['slug'] ?? '');
    $post = nibwp_workflow_find($ref);
    if (!$post) {
        return new WP_Error('not_found', 'Workflow not found. Pass a valid id or slug (see nibwp/list-workflows).');
    }
    return nibwp_workflow_to_array($post, true);
}

function nibwp_ability_save_workflow(array $input): array|WP_Error
{
    $gate = nibwp_workflows_gate();
    if (is_wp_error($gate)) {
        return $gate;
    }
    $id = nibwp_workflow_save([
        'title'    => (string) ($input['title'] ?? ''),
        'body'     => (string) ($input['body'] ?? ''),
        'summary'  => (string) ($input['summary'] ?? ''),
        'when'     => (string) ($input['when'] ?? ''),
        'tools'    => (array) ($input['tools'] ?? []),
        'category' => (string) ($input['category'] ?? 'custom'),
        'source'   => 'ai',
        'active'   => !empty($input['activate']),
    ]);
    if (is_wp_error($id)) {
        return $id;
    }
    $post = get_post($id);
    return ['success' => true, 'workflow' => nibwp_workflow_to_array($post, false), 'edit_url' => admin_url('admin.php?page=nibwp-workflows&edit=' . $id)];
}

function nibwp_ability_update_workflow(array $input): array|WP_Error
{
    $gate = nibwp_workflows_gate();
    if (is_wp_error($gate)) {
        return $gate;
    }
    $post = nibwp_workflow_find($input['id'] ?? ($input['slug'] ?? ''));
    if (!$post) {
        return new WP_Error('not_found', 'Workflow not found.');
    }
    $patch = ['id' => $post->ID];
    foreach (['title', 'body', 'summary', 'when', 'tools', 'category', 'active'] as $k) {
        if (array_key_exists($k, $input)) {
            $patch[$k] = $input[$k];
        }
    }
    $id = nibwp_workflow_save($patch);
    if (is_wp_error($id)) {
        return $id;
    }
    return ['success' => true, 'workflow' => nibwp_workflow_to_array(get_post($id), false)];
}

function nibwp_ability_activate_workflow(array $input): array|WP_Error
{
    $gate = nibwp_workflows_gate();
    if (is_wp_error($gate)) {
        return $gate;
    }
    $post = nibwp_workflow_find($input['id'] ?? ($input['slug'] ?? ''));
    if (!$post) {
        return new WP_Error('not_found', 'Workflow not found.');
    }
    $active = array_key_exists('active', $input) ? (bool) $input['active'] : true;
    nibwp_workflow_set_active($post->ID, $active, !empty($input['exclusive']));
    return ['success' => true, 'id' => $post->ID, 'pinned' => $active, 'pinned_workflows' => array_map(static fn ($w) => $w['title'], nibwp_workflows_active())];
}
