<?php

declare(strict_types=1);

/**
 * The panel beside the stage: connection, workflows, and what just happened.
 *
 * The first version was a full-screen wall of instructions that got out of the
 * way once a page opened, which meant everything it offered — the workflows,
 * the connection state — disappeared exactly when you started working. So it is
 * a sidebar instead. You connect in it, start work from it, and watch the log
 * fill up in it while the site runs beside it.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Whether an AI client could reach this site at all, and how.
 *
 * @return array{enabled: bool, connected: bool, clients: array<int, string>, count: int}
 */
function nibwp_visual_connection_state(): array
{
    $enabled = function_exists('nibwp_is_enabled') && (bool) nibwp_is_enabled();

    $clients = [];
    foreach (function_exists('nibwp_oauth_user_connections') ? nibwp_oauth_user_connections() : [] as $c) {
        $clients[] = (string) ($c['name'] ?? __('Unknown application', 'nibwp'));
    }

    if (class_exists('WP_Application_Passwords')) {
        foreach (WP_Application_Passwords::get_user_application_passwords(get_current_user_id()) as $pw) {
            $name = trim((string) ($pw['name'] ?? ''));
            if (!str_starts_with($name, 'NIBWP')) {
                continue;
            }
            // Keep the label the user actually gave it. Stripping the prefix
            // turned "NIBWP 2" into "2", which names nothing.
            $clients[] = $name;
        }
    }

    $clients = array_values(array_unique($clients));

    return [
        'enabled' => $enabled,
        'connected' => $clients !== [],
        'clients' => array_slice($clients, 0, 6),
        'count' => count($clients),
    ];
}

/**
 * Builders installed here, from the integrations catalogue so there is one
 * source of truth for "is this present".
 *
 * @return array<int, string>
 */
function nibwp_visual_present_builders(): array
{
    if (!function_exists('nibwp_get_integrations')) {
        return [];
    }

    $interesting = ['etchwp', 'bricks', 'elementor', 'kadence', 'automaticcss'];
    $present = [];

    foreach (nibwp_get_integrations() as $key => $info) {
        if (in_array($key, $interesting, true) && !empty($info['plugin_available'])) {
            $present[] = $key;
        }
    }

    return $present;
}

function nibwp_visual_builder_label(string $key): string
{
    $labels = [
        'etchwp' => 'Etch',
        'bricks' => 'Bricks',
        'elementor' => 'Elementor',
        'kadence' => 'Kadence',
        'automaticcss' => 'Automatic.css',
    ];

    return $labels[$key] ?? ucfirst($key);
}

/**
 * Things worth starting: the site's own workflows first, then a few tasks that
 * suit this particular site.
 *
 * A workflow is already a written procedure the agent understands, so it is the
 * shortest path from "I want a new page" to something happening on screen. Each
 * one is turned into a sentence that also asks for the work to happen in here,
 * because a workflow run somewhere else is not something you can watch.
 *
 * @return array<int, array{label: string, note: string, prompt: string}>
 */
function nibwp_visual_jobs(): array
{
    $jobs = [];

    if (function_exists('nibwp_workflows_posts')) {
        foreach (nibwp_workflows_posts() as $post) {
            if ($post->post_status !== 'publish') {
                continue;
            }
            $jobs[] = [
                'label' => (string) $post->post_title,
                'note' => (string) get_post_meta($post->ID, 'nibwp_wf_summary', true),
                'prompt' => sprintf(
                    /* translators: %s: workflow name */
                    __('Run the NibWP workflow "%s" on this site. Work in NibWP Agent View so I can watch each step, and ask me before anything that changes something.', 'nibwp'),
                    (string) $post->post_title
                ),
            ];
            if (count($jobs) >= 10) {
                break;
            }
        }
    }

    return $jobs;
}

/**
 * Everyday tasks, phrased so the workspace is where they happen.
 *
 * @return array<int, array{label: string, prompt: string}>
 */
function nibwp_visual_quick_tasks(): array
{
    $tasks = [
        [
            'label' => __('Create a new page', 'nibwp'),
            'prompt' => __('Open the Pages screen in NibWP Agent View, then create a new page with me. Show me each step in the workspace as you go, and ask before you save anything.', 'nibwp'),
        ],
        [
            'label' => __('Review the homepage', 'nibwp'),
            'prompt' => __('Open my homepage in NibWP Agent View, check it at mobile width, and tell me what looks wrong.', 'nibwp'),
        ],
        [
            'label' => __('Audit accessibility', 'nibwp'),
            'prompt' => __('Open my homepage in NibWP Agent View and run the accessibility audit. List every issue with its selector, worst first.', 'nibwp'),
        ],
        [
            'label' => __('Triage the posts list', 'nibwp'),
            'prompt' => __('Open the Posts screen in NibWP Agent View, read it, and tell me which posts need attention and why.', 'nibwp'),
        ],
    ];

    $builders = [
        'etchwp' => ['Etch', 'etchwp'],
        'bricks' => ['Bricks', 'bricks'],
        'elementor' => ['Elementor', 'elementor'],
        'kadence' => ['Kadence', 'kadence'],
    ];

    foreach (nibwp_visual_present_builders() as $key) {
        if (!isset($builders[$key])) {
            continue;
        }
        [$name, $skill] = $builders[$key];
        $tasks[] = [
            // Not "Build a %s section": that yields "a Etch". The builder name
            // leads instead, which reads correctly whatever the name is.
            'label' => sprintf(
                /* translators: %s: builder name, e.g. Etch */
                __('%s: build a section', 'nibwp'),
                $name
            ),
            'prompt' => sprintf(
                /* translators: 1: builder name, 2: skill slug */
                __('Open one of my %1$s pages in NibWP Agent View and build a new section on it with me, using the %2$s skill. Show me the page in the workspace as you work.', 'nibwp'),
                $name,
                $skill
            ),
        ];
    }

    return $tasks;
}

/**
 * Whether this site may run workflows here.
 *
 * Workflows are a Pro feature elsewhere in the plugin, and the workspace is
 * not a way around that. A free site still gets everything the workspace does
 * — it just drives it with plain prompts rather than stored procedures.
 */
function nibwp_visual_workflows_allowed(): bool
{
    return function_exists('nibwp_is_pro') && nibwp_is_pro();
}

/**
 * Prompts built from the abilities this site has switched on.
 *
 * What an agent can actually do here depends on which abilities are enabled,
 * so offering a job the site cannot perform would be a lie. This reads the
 * live catalogue rather than assuming.
 *
 * @return array<int, array{label: string, note: string, prompt: string}>
 */
function nibwp_visual_ability_prompts(): array
{
    $out = [];
    if (!function_exists('wp_get_abilities')) {
        return $out;
    }

    $interesting = [
        'nibwp/visual-audit' => [__('Audit this page', 'nibwp'), __('Contrast, alt text, labels, heading order, overflow.', 'nibwp')],
        'nibwp/visual-read' => [__('Read this page', 'nibwp'), __('Text, headings and every clickable thing on it.', 'nibwp')],
        'nibwp/visual-console' => [__('Find JavaScript errors', 'nibwp'), __('Whatever the page threw since it loaded.', 'nibwp')],
    ];

    foreach ($interesting as $name => $meta) {
        if (wp_get_ability($name) === null) {
            continue;
        }
        [$label, $note] = $meta;
        $out[] = [
            'label' => $label,
            'note' => $note,
            'prompt' => sprintf(
                /* translators: %s: the ability name */
                __('In NibWP Agent View, use %s on the page that is open, and tell me what you find.', 'nibwp'),
                $name
            ),
        ];
    }

    return $out;
}

/**
 * How many abilities this site can actually reach, and where they came from.
 *
 * The panel used to count the handful of example prompts written by hand, which
 * said four on a site with two hundred and seventy. The number people want is
 * how much this site can do — and, since integrations and skills are most of
 * it, what their plan is contributing to that total.
 *
 * @return array{total: int, integrations: int, skills: int, plan: string}
 */
function nibwp_visual_ability_reach(): array
{
    $total = 0;
    if (function_exists('wp_get_abilities')) {
        foreach (wp_get_abilities() as $ability) {
            $meta = (array) $ability->get_meta();
            if (!empty($meta['mcp']['public']) && (($meta['mcp']['type'] ?? 'tool') === 'tool')) {
                $total++;
            }
        }
    }

    $integrations = 0;
    if (function_exists('nibwp_get_integrations')) {
        foreach (nibwp_get_integrations() as $key => $info) {
            if (!empty($info['plugin_available']) && !empty($info['enabled'])) {
                $integrations++;
            }
        }
    }

    $skills = 0;
    if (function_exists('nibwp_skills_discover')) {
        foreach (nibwp_skills_discover() as $skill) {
            if (nibwp_skill_is_enabled((string) $skill['id']) && nibwp_skill_deps_met($skill)) {
                $skills++;
            }
        }
    }

    return [
        'total' => $total,
        'integrations' => $integrations,
        'skills' => $skills,
        'plan' => function_exists('nibwp_license_plan_label') ? nibwp_license_plan_label() : '',
    ];
}

/**
 * Everything on this site worth typing at, in one flat list.
 *
 * The search box filtered the nineteen prompts that happened to be on screen,
 * which on a site with two hundred and eighty-nine abilities is a search that
 * cannot find almost anything. This is the whole surface — abilities,
 * workflows, and the starting tasks — carried to the browser once so typing
 * answers instantly rather than asking the server on every keystroke.
 *
 * Names and one short line each: the prompt is built in the browser from a
 * template, because shipping a sentence per ability would be most of the page.
 *
 * @return array<int, array{k: string, n: string, l: string, d: string}>
 */
function nibwp_visual_catalogue(): array
{
    $out = [];

    if (function_exists('wp_get_abilities')) {
        foreach (wp_get_abilities() as $ability) {
            $meta = (array) $ability->get_meta();
            if (empty($meta['mcp']['public']) || (($meta['mcp']['type'] ?? 'tool') !== 'tool')) {
                continue;
            }
            $desc = trim((string) $ability->get_description());
            if (mb_strlen($desc) > 96) {
                $desc = mb_substr($desc, 0, 93) . '…';
            }
            $out[] = [
                'k' => 'ability',
                'n' => (string) $ability->get_name(),
                'l' => (string) $ability->get_label(),
                'd' => $desc,
            ];
        }
    }

    if (nibwp_visual_workflows_allowed() && function_exists('nibwp_workflows_posts')) {
        foreach (nibwp_workflows_posts() as $post) {
            if ($post->post_status !== 'publish') {
                continue;
            }
            // The name goes into the prompt as written, because the agent
            // runs the workflow by that name. Only what is shown translates.
            $out[] = [
                'k' => 'workflow',
                'n' => (string) $post->post_title,
                'l' => nibwp_i18n_data((string) $post->post_title),
                'd' => nibwp_i18n_data((string) get_post_meta($post->ID, 'nibwp_wf_summary', true)),
            ];
        }
    }

    // Skills answer the search too. Someone typing "figma" or "etch" is asking
    // what this site can do about it, and the skill is the better answer than
    // whichever ability happens to share the word.
    if (function_exists('nibwp_enabled_skills')) {
        foreach (nibwp_enabled_skills() as $skill) {
            $out[] = [
                'k' => 'skill',
                'n' => $skill['label'],
                'l' => $skill['label'],
                'd' => nibwp_i18n_data((string) $skill['tagline']),
            ];
        }
    }

    foreach (nibwp_visual_quick_tasks() as $task) {
        $out[] = ['k' => 'task', 'n' => $task['prompt'], 'l' => $task['label'], 'd' => ''];
    }

    return $out;
}

/**
 * A one-click bundle for Claude Desktop, carrying no credentials.
 *
 * The existing bundle on the Connect screen embeds an application password, so
 * it is a secret you must not forward to anyone. This one points the desktop
 * app at this site's MCP address and nothing else: the sign-in happens in the
 * browser, through OAuth, the first time it connects. It is therefore safe to
 * keep in a downloads folder or hand to a colleague — they still have to be
 * able to sign in to the site.
 *
 * Offered only when the site can actually be signed in to, since a bundle that
 * cannot complete its own flow is worse than no bundle.
 */
function nibwp_visual_bundle_available(): bool
{
    if (!class_exists('ZipArchive') && !function_exists('nibwp_zip_store_bytes')) {
        return false;
    }

    return function_exists('nibwp_oauth_availability')
        ? (bool) (nibwp_oauth_availability()['ok'] ?? false)
        : false;
}

function nibwp_visual_bundle_url(): string
{
    return wp_nonce_url(
        admin_url('admin-post.php?action=nibwp_visual_bundle'),
        'nibwp_visual_bundle'
    );
}

add_action('admin_post_nibwp_visual_bundle', 'nibwp_visual_send_bundle');

function nibwp_visual_send_bundle(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You are not allowed to download this.', 'nibwp'));
    }

    check_admin_referer('nibwp_visual_bundle');

    if (!function_exists('nibwp_zip_store_bytes')) {
        wp_die(esc_html__('This server cannot build the bundle. Add the connector by hand from the Connect screen instead.', 'nibwp'));
    }

    $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    $mcp = function_exists('nibwp_oauth_resource_id')
        ? nibwp_oauth_resource_id()
        : rest_url('mcp/nibwp');

    $manifest = [
        'manifest_version' => '0.3',
        'name' => 'nibwp-' . preg_replace('/[^a-z0-9.-]+/i', '-', $host),
        'display_name' => 'NibWP — ' . $host,
        'version' => defined('NIBWP_VERSION') ? (string) NIBWP_VERSION : '1.0.0',
        'description' => sprintf(
            /* translators: %s: site host */
            __('Work on %s with your AI assistant, and watch it happen in NibWP Agent View.', 'nibwp'),
            $host
        ),
        'author' => ['name' => 'NibWP', 'url' => 'https://nibwp.com'],
        'server' => [
            // The entry point is required by the manifest schema even though the
            // server is reached over HTTP; nothing in the bundle is executed.
            'type' => 'node',
            'entry_point' => 'server/index.js',
            'mcp_config' => [
                'type' => 'http',
                'url' => $mcp,
            ],
        ],
    ];

    $readme = sprintf(
        /* translators: %s: this site's MCP address */
        __("This bundle points Claude Desktop at:\n\n    %s\n\nIt contains no password. The first time it connects, your browser opens\nand you approve exactly what it may do — so this file is safe to keep,\nand useless to anyone who cannot sign in to the site.\n", 'nibwp'),
        $mcp
    );

    $bytes = nibwp_zip_store_bytes([
        'manifest.json' => (string) wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        'server/index.js' => "// Not executed. The server is reached over HTTP; see manifest.json.\n",
        'README.txt' => $readme,
    ]);

    $file = 'nibwp-' . sanitize_file_name($host !== '' ? $host : 'site') . '.mcpb';

    nocache_headers();
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput -- binary archive
    exit;
}


/**
 * A section heading that folds its section away, and says how much is in it.
 *
 * The panel is one long column: without a count you cannot tell an empty
 * section from a collapsed one, and without folding, ten workflows push
 * everything below them off the screen.
 */
function nibwp_visual_panel_heading(string $label, int $count = 0, string $icon = ''): void
{
    ?>
    <h2 class="nw-vs-panel__h">
        <button type="button" class="nw-vs-panel__fold" aria-expanded="true">
            <?php if ($icon !== ''): ?>
                <span class="nw-vs-panel__icon" aria-hidden="true">
                    <?php echo nibwp_visual_icon($icon, 15); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?>
                </span>
            <?php endif; ?>
            <span class="nw-vs-panel__title"><?php echo esc_html($label); ?></span>
            <?php if ($count > 0): ?>
                <span class="nw-vs-panel__count"><?php echo esc_html((string) $count); ?></span>
            <?php endif; ?>
            <span class="nw-vs-panel__chev" aria-hidden="true">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
            </span>
        </button>
    </h2>
    <?php
}

/**
 * The whole left-hand panel.
 */
function nibwp_visual_render_panel(): void
{
    $state = nibwp_visual_connection_state();
    $pro = nibwp_visual_workflows_allowed();
    $jobs = $pro ? nibwp_visual_jobs() : [];
    $tasks = nibwp_visual_quick_tasks();
    $abilities = nibwp_visual_ability_prompts();
    $builders = nibwp_visual_present_builders();
    ?>
    <aside class="nw-vs-side" id="nw-vs-side">
        <div class="nw-vs-side__scroll">

        <?php // Connection comes first because nothing below it works without it. ?>
        <section class="nw-vs-panel nw-vs-conn<?php echo $state['connected'] ? ' is-on' : ''; ?>">
            <?php // Connected is a state, not an instruction: it wears the shape of
                  // the buttons under it and names what is on the other end,
                  // rather than a heading over three things to press that a
                  // connected site does not need. ?>
            <?php if ($state['connected']): ?>
                <div class="nw-vs-live">
                    <span class="nw-vs-live__dot" aria-hidden="true"></span>
                    <span class="nw-vs-live__text">
                        <strong><?php esc_html_e('Connected', 'nibwp'); ?></strong>
                        <span><?php echo esc_html(implode(', ', $state['clients'])); ?></span>
                    </span>
                </div>
                <p class="nw-vs-panel__note">
                    <?php esc_html_e('Ask it for something below.', 'nibwp'); ?>
                </p>
            <?php else: ?>
                <h2 class="nw-vs-panel__h">
                    <span class="nw-vs-conn__dot" aria-hidden="true"></span>
                    <?php esc_html_e('Not connected', 'nibwp'); ?>
                </h2>
                <?php // The connecting itself is on the stage, at full width. Repeating
                      // it here would be two half-explanations of one thing. ?>
                <p class="nw-vs-panel__note">
                    <?php esc_html_e('Nothing can reach this site yet.', 'nibwp'); ?>
                </p>
            <?php endif; ?>

            <?php // Everything below answers "how do I connect", so it goes once
                  // something has. The bar's plug icon brings the whole connect
                  // screen back for a second assistant. ?>
            <?php if (!$state['connected']): ?>
            <p class="nw-vs-panel__note nw-vs-howto">
                <?php esc_html_e('Paste a job below into your assistant — the page it opens appears here.', 'nibwp'); ?>
            </p>

            <?php if (nibwp_visual_bundle_available()): ?>
                <a class="nw-vs-check" href="<?php echo esc_url(nibwp_visual_bundle_url()); ?>">
                    <?php echo nibwp_visual_icon('download', 15); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?>
                    <span><?php esc_html_e('Download for Claude Desktop', 'nibwp'); ?></span>
                </a>
                <p class="nw-vs-cmd__hint">
                    <?php esc_html_e('No password in it. Double-click to install, then sign in.', 'nibwp'); ?>
                </p>
            <?php endif; ?>

            <button type="button" class="nw-vs-check" id="nw-vs-check">
                <?php echo nibwp_visual_icon('refresh', 15); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?>
                <span><?php esc_html_e('Check connection', 'nibwp'); ?></span>
            </button>
            <div class="nw-vs-checkout" id="nw-vs-checkout" role="status" aria-live="polite"></div>
            <?php endif; ?>

            <?php if (!$state['enabled']): ?>
                <p class="nw-vs-warn">
                    <?php esc_html_e('AI Abilities are switched off. Turn them on from the Connect screen or nothing can reach this site.', 'nibwp'); ?>
                </p>
            <?php endif; ?>
        </section>

        <?php // Type to filter everything below, or use a slash command. ?>
        <div class="nw-vs-cmd">
            <input type="search" class="nw-vs-cmd__input" id="nw-vs-cmd"
                   autocomplete="off" spellcheck="false"
                   placeholder="<?php esc_attr_e('Search, or type / for commands', 'nibwp'); ?>"
                   aria-label="<?php esc_attr_e('Search jobs and workflows', 'nibwp'); ?>">
            <p class="nw-vs-cmd__hint" id="nw-vs-cmd-hint">
                <code>/workflows</code> <code>/abilities</code> <code>/tasks</code>
            </p>

            <?php // Answers from the whole catalogue, not just what is on screen. ?>
            <div class="nw-vs-find" id="nw-vs-find" hidden role="listbox" aria-label="<?php esc_attr_e('Search results', 'nibwp'); ?>"></div>
        </div>

        <section class="nw-vs-panel" data-vs-group="tasks">
            <?php nibwp_visual_panel_heading(__('Start something', 'nibwp'), count($tasks), 'bolt'); ?>
            <div class="nw-vs-panel__body">
            <div class="nw-vs-jobs nw-vs-jobs--cage">
                <?php foreach ($tasks as $task): ?>
                    <button type="button" class="nw-vs-job" data-copy-label="<?php esc_attr_e('Copy', 'nibwp'); ?>" data-copied-label="<?php esc_attr_e('Copied', 'nibwp'); ?>" data-vs-prompt="<?php echo esc_attr($task['prompt']); ?>">
                        <span class="nw-vs-job__label"><?php echo esc_html($task['label']); ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
            <?php if ($builders !== []): ?>
                <p class="nw-vs-panel__note nw-vs-detected">
                    <?php esc_html_e('On this site:', 'nibwp'); ?>
                    <?php foreach ($builders as $key): ?>
                        <span class="nw-vs-chip"><?php echo esc_html(nibwp_visual_builder_label($key)); ?></span>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>
            </div>
        </section>

        <section class="nw-vs-panel" data-vs-group="workflows">
            <?php nibwp_visual_panel_heading(__('Workflows', 'nibwp'), $pro ? count($jobs) : 0, 'flow'); ?>
            <div class="nw-vs-panel__body">
            <?php if (!$pro): ?>
                <?php // Shown, not hidden: a Pro feature nobody can see is a Pro
                      // feature nobody buys, and pretending it works is worse. ?>
                <p class="nw-vs-panel__note">
                    <?php esc_html_e('Saved workflows need Pro. Everything else here works without it.', 'nibwp'); ?>
                </p>
                <button type="button" class="nw-vs-more nw-vs-more--btn" data-vs-locked="workflows">
                    <?php esc_html_e('What Pro adds here', 'nibwp'); ?>
                </button>
            <?php elseif ($jobs === []): ?>
                <p class="nw-vs-panel__note"><?php esc_html_e('No workflows saved yet.', 'nibwp'); ?></p>
            <?php else: ?>
                <p class="nw-vs-panel__note"><?php esc_html_e('Copy one into your assistant.', 'nibwp'); ?></p>
                <div class="nw-vs-jobs nw-vs-jobs--cage">
                    <?php foreach ($jobs as $job): ?>
                        <button type="button" class="nw-vs-job" data-copy-label="<?php esc_attr_e('Copy', 'nibwp'); ?>" data-copied-label="<?php esc_attr_e('Copied', 'nibwp'); ?>" data-vs-prompt="<?php echo esc_attr($job['prompt']); ?>">
                            <span class="nw-vs-job__label"><?php echo esc_html(nibwp_i18n_data($job['label'])); ?></span>
                            <?php if ($job['note'] !== ''): ?>
                                <span class="nw-vs-job__note"><?php echo esc_html(nibwp_i18n_data($job['note'])); ?></span>
                            <?php endif; ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                <a class="nw-vs-more" href="<?php echo esc_url(admin_url('admin.php?page=nibwp-workflows')); ?>"
                   target="_blank" rel="noopener">
                    <?php esc_html_e('All workflows', 'nibwp'); ?>
                    <?php echo nibwp_visual_icon('external', 12); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?>
                </a>
            <?php endif; ?>
            </div>
        </section>

        <?php
        // Skills are not abilities, so they appear nowhere else in here — and a
        // skill nobody knows is on is a skill nobody asks for. Each card copies
        // a prompt that names it, because naming it is what makes the agent
        // load it instead of building the same thing by hand.
        $skills = function_exists('nibwp_enabled_skills') ? nibwp_enabled_skills() : [];
        ?>
        <?php if ($skills !== []): ?>
            <section class="nw-vs-panel" data-vs-group="skills">
                <?php nibwp_visual_panel_heading(__('Skills', 'nibwp'), count($skills), 'spark'); ?>
                <div class="nw-vs-panel__body">
                    <p class="nw-vs-panel__note">
                        <?php esc_html_e('On for this site. Name one and the assistant builds to its rules instead of guessing.', 'nibwp'); ?>
                    </p>
                    <div class="nw-vs-jobs nw-vs-jobs--cage">
                        <?php foreach ($skills as $skill): ?>
                            <button type="button" class="nw-vs-job" data-copy-label="<?php esc_attr_e('Copy', 'nibwp'); ?>" data-copied-label="<?php esc_attr_e('Copied', 'nibwp'); ?>"
                                    data-vs-prompt="<?php echo esc_attr(sprintf(
                                        /* translators: %s: the skill name */
                                        __('Load the %s skill with nibwp/get-skill and follow it for this task.', 'nibwp'),
                                        $skill['label']
                                    )); ?>">
                                <span class="nw-vs-job__label"><?php echo esc_html($skill['label']); ?></span>
                                <?php if ($skill['tagline'] !== ''): ?>
                                    <span class="nw-vs-job__note"><?php echo esc_html(nibwp_i18n_data((string) $skill['tagline'])); ?></span>
                                <?php endif; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <a class="nw-vs-more" href="<?php echo esc_url(admin_url('admin.php?page=nibwp-skills')); ?>"
                       target="_blank" rel="noopener">
                        <?php esc_html_e('All skills', 'nibwp'); ?>
                        <?php echo nibwp_visual_icon('external', 12); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?>
                    </a>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($abilities !== []): ?>
            <section class="nw-vs-panel" data-vs-group="abilities">
                <?php $reach = nibwp_visual_ability_reach(); ?>
                <?php nibwp_visual_panel_heading(__('Abilities', 'nibwp'), $reach['total'], 'spark'); ?>
                <div class="nw-vs-panel__body">
                <p class="nw-vs-panel__note">
                    <?php
                    // What the number is made of. On a Pro or Bundle site most
                    // of it arrived with the plan, and saying so is the honest
                    // version of "look how much you can do".
                    $parts = [];
                    if ($reach['integrations'] > 0) {
                        $parts[] = sprintf(
                            /* translators: %d: number of integrations */
                            _n('%d integration', '%d integrations', $reach['integrations'], 'nibwp'),
                            $reach['integrations']
                        );
                    }
                    if ($reach['skills'] > 0) {
                        $parts[] = sprintf(
                            /* translators: %d: number of skills */
                            _n('%d skill', '%d skills', $reach['skills'], 'nibwp'),
                            $reach['skills']
                        );
                    }

                    if ($parts !== [] && in_array($reach['plan'], ['pro', 'bundle'], true)) {
                        printf(
                            /* translators: 1: total abilities, 2: e.g. "12 integrations and 4 skills", 3: plan name */
                            esc_html__('%1$d on this site, including %2$s your %3$s license unlocks. A few to start with:', 'nibwp'),
                            (int) $reach['total'],
                            esc_html(implode(__(' and ', 'nibwp'), $parts)),
                            esc_html($reach['plan'] === 'bundle' ? __('Bundle', 'nibwp') : __('Pro', 'nibwp'))
                        );
                    } elseif ($parts !== []) {
                        printf(
                            /* translators: 1: total abilities, 2: e.g. "3 integrations and 1 skill" */
                            esc_html__('%1$d on this site, from %2$s. A few to start with:', 'nibwp'),
                            (int) $reach['total'],
                            esc_html(implode(__(' and ', 'nibwp'), $parts))
                        );
                    } else {
                        printf(
                            /* translators: %d: total abilities */
                            esc_html__('%d on this site. A few to start with:', 'nibwp'),
                            (int) $reach['total']
                        );
                    }
                    ?>
                </p>
                <div class="nw-vs-jobs nw-vs-jobs--cage">
                    <?php foreach ($abilities as $job): ?>
                        <button type="button" class="nw-vs-job" data-copy-label="<?php esc_attr_e('Copy', 'nibwp'); ?>" data-copied-label="<?php esc_attr_e('Copied', 'nibwp'); ?>" data-vs-prompt="<?php echo esc_attr($job['prompt']); ?>">
                            <span class="nw-vs-job__label"><?php echo esc_html($job['label']); ?></span>
                            <span class="nw-vs-job__note"><?php echo esc_html($job['note']); ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
                <a class="nw-vs-more" href="<?php echo esc_url(admin_url('admin.php?page=nibwp')); ?>"
                   target="_blank" rel="noopener">
                    <?php esc_html_e('All abilities', 'nibwp'); ?>
                    <?php echo nibwp_visual_icon('external', 12); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?>
                </a>
                </div>
            </section>
        <?php endif; ?>

        <?php
        // What agents have asked for lately, whoever they signed in as. The
        // failure this exposes is a command sent by an agent authenticated as
        // one account while the workspace is open as another: the queue is per
        // user, so nothing arrives and both sides look fine.
        $attempts = function_exists('nibwp_visual_attempts') ? nibwp_visual_attempts() : [];
        $me = wp_get_current_user();
        ?>
        <?php if ($attempts !== []): ?>
            <section class="nw-vs-panel">
                <?php nibwp_visual_panel_heading(__('Recent requests', 'nibwp'), count($attempts), 'activity'); ?>
                <div class="nw-vs-panel__body">
                <div class="nw-vs-tries">
                    <?php foreach (array_slice($attempts, 0, 6) as $try): ?>
                        <?php $mine = (int) $try['user_id'] === (int) $me->ID; ?>
                        <div class="nw-vs-try<?php echo $try['outcome'] === 'queued' ? ' is-ok' : ' is-bad'; ?>">
                            <span class="nw-vs-try__cmd"><?php echo esc_html((string) $try['command']); ?></span>
                            <span class="nw-vs-try__who">
                                <?php echo esc_html((string) $try['user']); ?>
                                <?php if (!$mine): ?>
                                    <strong><?php esc_html_e('— not you', 'nibwp'); ?></strong>
                                <?php endif; ?>
                            </span>
                            <span class="nw-vs-try__out">
                                <?php echo $try['outcome'] === 'queued'
                                    ? esc_html__('reached this screen', 'nibwp')
                                    : esc_html__('no workspace was open for that account', 'nibwp'); ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php
        // Pairing a headless runner.
        //
        // The key is made here and nowhere else, because this screen is the one
        // place a person has already proved they are a person: it opens behind a
        // logged-in cookie, which only wp-login.php sets, and wp-login is where
        // a site's two-factor or SSO challenge sits. Without that step the
        // runner's application password would be enough on its own to mint
        // browser cookies for the account — and an application password is
        // precisely the credential WordPress promises cannot sign in to
        // wp-admin, held on a build box where it is meant to be the safe thing
        // to leave lying around.
        $nw_vs_keys = function_exists('nibwp_visual_pass_count') ? nibwp_visual_pass_count((int) $me->ID) : 0;
        ?>
        <section class="nw-vs-panel" data-vs-group="runner">
            <?php nibwp_visual_panel_heading(__('Headless runner', 'nibwp'), $nw_vs_keys, 'plug'); ?>
            <div class="nw-vs-panel__body">
                <p class="nw-vs-panel__note">
                    <?php esc_html_e('Runs this workspace without a browser open, for screenshots and scheduled checks. It needs a pairing key from here as well as its application password.', 'nibwp'); ?>
                </p>

                <?php // A reusable key is the only way a nightly run can work, and
                      // it is the riskier of the two, so it is the one you have to
                      // ask for rather than the one you get by not reading. ?>
                <label class="nw-vs-pair__opt">
                    <input type="checkbox" id="nw-vs-pair-sched">
                    <span><?php esc_html_e('For scheduled runs — reusable until revoked', 'nibwp'); ?></span>
                </label>

                <button type="button" class="nw-vs-check" id="nw-vs-pair">
                    <?php echo nibwp_visual_icon('plug', 15); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?>
                    <span><?php esc_html_e('Create a pairing key', 'nibwp'); ?></span>
                </button>

                <?php // Shown once. Nothing here can read it back, because the site
                      // only ever stored its fingerprint. ?>
                <div class="nw-vs-checkout" id="nw-vs-pair-out" role="status" aria-live="polite"></div>

                <button type="button" class="nw-vs-more nw-vs-more--btn" id="nw-vs-pair-revoke"<?php echo $nw_vs_keys > 0 ? '' : ' hidden'; ?>>
                    <?php esc_html_e('Revoke pairing keys', 'nibwp'); ?>
                </button>
            </div>
        </section>

        <?php
        // Locked things worth knowing about — and only the ones with a reason to
        // be here. A skill for a plugin this site does not have is not a missing
        // capability, it is an advert, and it is left out.
        $locked = function_exists('nibwp_visual_locked') ? nibwp_visual_locked() : [];
        $ready = array_values(array_filter($locked, static fn(array $l): bool => $l['ready']));
        ?>
        <?php if ($ready !== []): ?>
            <section class="nw-vs-panel" data-vs-group="locked">
                <?php nibwp_visual_panel_heading(__('Not unlocked here', 'nibwp'), count($ready), 'lock'); ?>
                <div class="nw-vs-panel__body">
                <div class="nw-vs-jobs nw-vs-jobs--cage">
                    <?php foreach (array_slice($ready, 0, 4) as $item): ?>
                        <button type="button" class="nw-vs-job nw-vs-job--locked" data-vs-locked="<?php echo esc_attr($item['key']); ?>">
                            <span class="nw-vs-job__label">
                                <?php echo esc_html($item['title']); ?>
                                <span class="nw-vs-lock"><?php esc_html_e('Locked', 'nibwp'); ?></span>
                            </span>
                            <span class="nw-vs-job__note"><?php echo esc_html($item['line']); ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
                <a class="nw-vs-more" href="<?php echo esc_url(admin_url('admin.php?page=nibwp')); ?>"
                   target="_blank" rel="noopener">
                    <?php esc_html_e('All abilities', 'nibwp'); ?>
                    <?php echo nibwp_visual_icon('external', 12); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?>
                </a>
                </div>
            </section>
        <?php endif; ?>

                <?php // The whole point of the list above: an agent signed in as
              // someone else is answered by their workspace, not this one,
              // and both sides look fine while nothing arrives. ?>
        </div>

        <p class="nw-vs-whose">
            <span class="nw-vs-whose__face" aria-hidden="true"><?php echo esc_html(strtoupper(mb_substr($me->user_login, 0, 1))); ?></span>
            <span class="nw-vs-whose__text">
                <strong><?php echo esc_html($me->user_login); ?></strong>
                <span><?php esc_html_e('Only an agent signed in as this account reaches this screen.', 'nibwp'); ?></span>
            </span>
        </p>

    </aside>
    <?php
}

/**
 * The clients worth offering first, in the order most people want them.
 *
 * The Connect screen lists sixteen because it is a reference. This is the middle
 * of the screen someone is looking at, so it shows the handful that cover nearly
 * everyone and links to the rest.
 *
 * Each carries where it runs, because that is the question someone is actually
 * answering here — a list of sixteen product names is not a choice, it is a
 * quiz. "In your browser" or "in your terminal" is something a person already
 * knows about themselves.
 *
 * @return array<string, array{where: string, note: string}>
 */
function nibwp_visual_headline_clients(): array
{
    return [
        'claude-ai' => [
            'where' => __('In your browser', 'nibwp'),
            'note' => __('One button. Nothing to install.', 'nibwp'),
        ],
        'claude-desktop' => [
            'where' => __('Desktop app', 'nibwp'),
            'note' => __('Add it once under Connectors.', 'nibwp'),
        ],
        'chatgpt' => [
            'where' => __('In your browser', 'nibwp'),
            'note' => __('Needs developer mode switched on.', 'nibwp'),
        ],
        'claude-code' => [
            'where' => __('Terminal', 'nibwp'),
            'note' => __('One command, then approve in the browser.', 'nibwp'),
        ],
        'codex' => [
            'where' => __('Terminal', 'nibwp'),
            'note' => __('Two lines: add, then log in.', 'nibwp'),
        ],
        'cursor' => [
            'where' => __('Code editor', 'nibwp'),
            'note' => __('Opens Cursor and installs it for you.', 'nibwp'),
        ],
        'vscode' => [
            'where' => __('Code editor', 'nibwp'),
            'note' => __('Also covers GitHub Copilot.', 'nibwp'),
        ],
        'gemini-cli' => [
            'where' => __('Terminal', 'nibwp'),
            'note' => __('One block in settings.json.', 'nibwp'),
        ],
    ];
}

/**
 * Two letters that tell one client from another.
 *
 * A single initial does not: of the seven clients offered here, six begin with
 * C, so the row of tiles read C C C C C C and named nothing at all.
 */
function nibwp_visual_monogram(string $label): string
{
    $words = preg_split('/[\s.\-_]+/', trim($label), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    if (count($words) >= 2) {
        return mb_strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1));
    }

    return mb_strtoupper(mb_substr($label, 0, 2));
}

/**
 * What to do with what the card just copied, one step at a time.
 *
 * The Connect screen carries a sentence per client. A sentence is the right
 * shape there, beside a heading and a screenshot's worth of room; on a card it
 * is a paragraph nobody reads. These are the same instructions cut into steps,
 * and anything not listed here falls back to that sentence rather than to
 * nothing.
 *
 * @param array<string, mixed> $cfg The client's entry from nibwp_oauth_remote_configs().
 * @return array<int, string> Steps, possibly with <strong>/<code>.
 */
function nibwp_visual_client_steps(string $key, array $cfg): array
{
    $steps = [
        'claude-desktop' => [
            __('Open <strong>Settings → Connectors</strong> in Claude Desktop.', 'nibwp'),
            __('Choose <strong>Add custom connector</strong>, name it, and paste the address.', 'nibwp'),
            __('Leave the OAuth client ID and secret empty — this site needs neither.', 'nibwp'),
            __('Save. Your browser opens here to approve what it may do.', 'nibwp'),
        ],
        'claude-ai' => [
            __('Press the button — Claude opens with the connector filled in.', 'nibwp'),
            __('Review it and press <strong>Add</strong>.', 'nibwp'),
            __('Approve the permissions when your browser returns here.', 'nibwp'),
            __('It is on your account, so Claude Desktop picks it up after a short sync.', 'nibwp'),
        ],
        'chatgpt' => [
            __('Turn on <strong>Developer mode</strong> under <strong>Settings → Security and login</strong>.', 'nibwp'),
            __('Go to <strong>Settings → Plugins → Browse plugins</strong> and press <strong>+</strong>.', 'nibwp'),
            __('Give it a name, paste the address as the server URL, tick the confirmation.', 'nibwp'),
            __('Press <strong>Create</strong>, then approve when it sends you here.', 'nibwp'),
        ],
        'claude-code' => [
            __('Paste the copied command into your terminal and run it.', 'nibwp'),
            __('Your browser opens on this site to approve the connection.', 'nibwp'),
            __('Back in Claude Code, the NibWP tools are there.', 'nibwp'),
        ],
        'codex' => [
            __('Run both copied lines: the first adds it, the second signs in.', 'nibwp'),
            __('Approve in the browser window that opens.', 'nibwp'),
            __('If you would rather do it by hand, add the block to <code>config.toml</code>, restart Codex, and press <strong>Authenticate</strong> beside it.', 'nibwp'),
        ],
        'cursor' => [
            __('Press the button — Cursor opens its <strong>Tools and MCP</strong> page.', 'nibwp'),
            __('Press <strong>Install</strong>, then <strong>Connect</strong>.', 'nibwp'),
            __('If nothing opens, Cursor has not registered its link handler: add the block to <code>mcp.json</code> instead.', 'nibwp'),
        ],
        'vscode' => [
            __('Press the button and confirm the server when VS Code asks.', 'nibwp'),
            __('Or add the block to <code>mcp.json</code> yourself.', 'nibwp'),
            __('Approve in the browser, then use it from Copilot Chat in agent mode.', 'nibwp'),
        ],
        'gemini-cli' => [
            __('Open <code>settings.json</code> and paste the copied block, merging it into what is there.', 'nibwp'),
            __('Start Gemini CLI; it signs in through your browser on first use.', 'nibwp'),
        ],
    ];

    if (isset($steps[$key])) {
        return $steps[$key];
    }

    // Unlisted client: the sentence the Connect screen shows is better than an
    // empty panel, and it is already written for every entry in the catalogue.
    $hint = trim((string) ($cfg['hint'] ?? ''));

    return $hint === '' ? [] : [$hint];
}

/**
 * The stage's own empty state.
 *
 * Two entirely different screens, because there are two entirely different
 * situations. Not connected: connecting is the only thing worth doing, so it
 * happens here, in the middle, at full width — not tucked into a sidebar column
 * beside an empty stage that explains nothing. Connected: the stage gets out of
 * the way, because the next thing to arrive is a page.
 */
function nibwp_visual_render_stage_empty(): void
{
    $state = nibwp_visual_connection_state();

    // Both screens, always, each under its own id — the connect screen used to
    // borrow the empty state's id when nothing was connected, so the button in
    // the bar was pointing at an element that did not exist on exactly the
    // sites where connecting is the whole task. It appeared, and did nothing.
    ?>
    <div class="nw-vs-empty" id="nw-vs-empty" <?php echo $state['connected'] ? '' : 'hidden'; ?>>
        <span class="nw-vs-empty__mark" aria-hidden="true">
            <img src="<?php echo esc_url(NIBWP_PLUGIN_URL . 'assets/nibwp-icon.svg'); ?>" alt="" width="72">
        </span>
        <h1><?php esc_html_e('The floor is yours', 'nibwp'); ?></h1>
        <p><?php esc_html_e('Ask your assistant to build something. It opens the page here and works on it while you watch — every click, every edit, in front of you.', 'nibwp'); ?></p>
        <p class="nw-vs-empty__try"><?php esc_html_e('Try: “open my homepage and check it on a phone”', 'nibwp'); ?></p>
    </div>
    <?php
    // Out of the way once something is connected. Connecting a second
    // assistant, or reconnecting one that broke, should not require
    // disconnecting the first to find the instructions again.
    nibwp_visual_render_connect_screen($state['connected']);
}

/**
 * The connect screen itself.
 *
 * @param bool $tucked Rendered hidden, for the Reconnect button to bring up.
 */
function nibwp_visual_render_connect_screen(bool $tucked): void
{
    $state = nibwp_visual_connection_state();
    $mcp = function_exists('nibwp_oauth_resource_id') ? nibwp_oauth_resource_id() : rest_url('mcp/nibwp');
    $name = function_exists('nibwp_oauth_connector_name') ? nibwp_oauth_connector_name() : 'NibWP';
    $configs = function_exists('nibwp_oauth_remote_configs') ? nibwp_oauth_remote_configs($mcp, $name) : [];
    $labels = function_exists('nibwp_oauth_remote_client_labels') ? nibwp_oauth_remote_client_labels() : [];
    ?>
    <div class="nw-vs-start" id="nw-vs-start" <?php echo $tucked ? 'hidden' : ''; ?>>
        <div class="nw-vs-start__inner">

            <?php // Shown by the client once there is something to go back to —
                  // on a site with nothing connected and nothing open, "back"
                  // leads to an empty stage, which is not a way out of anything. ?>
            <div class="nw-vs-start__backrow">
                <button type="button" class="nw-vs-start__back" id="nw-vs-start-back" hidden>
                    <span class="nw-rtl-flip" aria-hidden="true">←</span> <?php esc_html_e('Back to the site', 'nibwp'); ?>
                </button>
            </div>

            <img src="<?php echo esc_url(NIBWP_PLUGIN_URL . 'assets/nibwp-icon.svg'); ?>" alt="" width="42">
            <h1><?php
                echo $state['connected']
                    ? esc_html__('Connect another assistant', 'nibwp')
                    : esc_html__('Connect an assistant, and watch it work', 'nibwp');
            ?></h1>
            <p class="nw-vs-start__lead">
                <?php
                echo $state['connected']
                    ? esc_html__('The same address works for as many assistants as you like — and it is the one to paste again if a connection stopped working.', 'nibwp')
                    : esc_html__('Pick where you work. Your browser opens, you approve what it may do, and pages start appearing on this screen as it builds them.', 'nibwp');
                ?>
            </p>

            <?php if ($state['connected'] && $state['clients'] !== []): ?>
                <p class="nw-vs-start__already">
                    <?php esc_html_e('Already connected:', 'nibwp'); ?>
                    <?php foreach ($state['clients'] as $client): ?>
                        <span class="nw-vs-chip"><?php echo esc_html($client); ?></span>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>

            <div class="nw-vs-start__url">
                <input type="text" readonly id="nw-vs-start-url" value="<?php echo esc_attr($mcp); ?>"
                       aria-label="<?php esc_attr_e('This site’s MCP address', 'nibwp'); ?>">
                <?php // The field beside it already says what the thing is; the
                      // button only has to say what pressing it does, and the
                      // mark changes to a tick when it has. ?>
                <button type="button" class="nw-vs-start__copy" data-vs-copy="nw-vs-start-url"
                        aria-label="<?php esc_attr_e('Copy the address', 'nibwp'); ?>">
                    <span class="nw-vs-start__copy-i" aria-hidden="true">
                        <?php echo nibwp_visual_icon('copy', 15); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?>
                    </span>
                    <span class="nw-vs-start__copy-ok" aria-hidden="true">
                        <?php echo nibwp_visual_icon('check', 15); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?>
                    </span>
                </button>
            </div>

            <?php // What is about to happen, before it happens. Three short steps
                  // stop the grid below reading as a wall of unexplained buttons. ?>
            <ol class="nw-vs-steps">
                <li><span><?php esc_html_e('Pick where you work', 'nibwp'); ?></span></li>
                <li><span><?php esc_html_e('Approve it in your browser', 'nibwp'); ?></span></li>
                <li><span><?php esc_html_e('Come back here and ask for something', 'nibwp'); ?></span></li>
            </ol>

            <div class="nw-vs-start__grid">
                <?php
                // Anything that cannot reach this site goes last. Two dead cards
                // in the first row is the worst thing this screen could open
                // with, and on a local address that is exactly what happens.
                $offer = nibwp_visual_headline_clients();
                uksort(
                    $offer,
                    static function (string $a, string $b) use ($configs): int {
                        return (int) !empty($configs[$a]['unreachable']) <=> (int) !empty($configs[$b]['unreachable']);
                    }
                );

                $lead = true;
                foreach ($offer as $key => $about):
                    $cfg = $configs[$key] ?? null;
                    if ($cfg === null) {
                        continue;
                    }
                    $label = (string) ($labels[$key] ?? $key);
                    $action = $cfg['action'] ?? null;
                    $off = !empty($cfg['unreachable']);
                    // The first client that can actually work here leads. On a
                    // local address that is never the browser assistants, so the
                    // badge follows reality rather than a hard-coded favorite.
                    $first = $lead && !$off;
                    if ($first) {
                        $lead = false;
                    }
                    ?>
                    <div class="nw-vs-client<?php echo $off ? ' is-off' : ''; ?><?php echo $first ? ' is-lead' : ''; ?>">
                        <div class="nw-vs-client__top">
                            <span class="nw-vs-client__mark" aria-hidden="true"><?php echo esc_html(nibwp_visual_monogram($label)); ?></span>
                            <span class="nw-vs-client__id">
                                <span class="nw-vs-client__name"><?php echo esc_html($label); ?></span>
                                <span class="nw-vs-client__where"><?php echo esc_html($about['where']); ?></span>
                            </span>
                            <?php if ($first): ?>
                                <span class="nw-vs-client__badge"><?php esc_html_e('Easiest', 'nibwp'); ?></span>
                            <?php endif; ?>
                        </div>

                        <span class="nw-vs-client__note">
                            <?php echo esc_html($off
                                ? __('Cannot reach a local address from the cloud.', 'nibwp')
                                : $about['note']); ?>
                        </span>

                        <?php if ($off): ?>
                            <span class="nw-vs-client__go is-dead" aria-disabled="true"><?php esc_html_e('Not available here', 'nibwp'); ?></span>
                        <?php elseif ($action !== null): ?>
                            <?php // The protocol allowlist matters: esc_url drops any scheme it
                                  // does not know, and "cursor:" and "vscode:" are not among the
                                  // ones it knows — so both buttons shipped with href="". ?>
                            <a class="nw-vs-client__go" target="_blank" rel="noopener"
                               href="<?php echo esc_url((string) $action['url'], ['http', 'https', 'vscode', 'vscode-insiders', 'cursor']); ?>">
                                <?php echo esc_html((string) $action['label']); ?>
                                <?php echo nibwp_visual_icon('external', 13); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?>
                            </a>
                        <?php else: ?>
                            <button type="button" class="nw-vs-client__go" data-vs-copytext="<?php echo esc_attr((string) $cfg['code']); ?>">
                                <?php echo esc_html(!empty($cfg['isShell'])
                                    ? __('Copy the command', 'nibwp')
                                    : __('Copy the address', 'nibwp')); ?>
                            </button>
                        <?php endif; ?>

                        <?php
                        // What to do with what you just copied. Written per client
                        // on the Connect screen already, so it is shown rather than
                        // written twice — folded away, because eight cards of
                        // instructions is a manual, not a choice.
                        $steps = nibwp_visual_client_steps($key, $cfg);
                        ?>
                        <?php if (!$off && $steps !== []): ?>
                            <details class="nw-vs-client__how">
                                <summary>
                                    <?php esc_html_e('How', 'nibwp'); ?>
                                    <?php echo nibwp_visual_icon('chevron', 12); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?>
                                </summary>
                                <ol class="nw-vs-client__steps">
                                    <?php foreach ($steps as $step): ?>
                                        <li><?php echo wp_kses($step, ['strong' => [], 'code' => [], 'em' => []]); ?></li>
                                    <?php endforeach; ?>
                                </ol>
                                <?php if (!empty($cfg['paths'])): ?>
                                    <ul class="nw-vs-client__paths">
                                        <?php foreach ((array) $cfg['paths'] as $where => $path): ?>
                                            <li>
                                                <span><?php echo esc_html((string) $where); ?></span>
                                                <code><?php echo esc_html((string) $path); ?></code>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </details>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php // Which plan this site is on, and what that actually buys —
                  // answered here rather than left to be discovered one refused
                  // tool at a time. ?>
            <?php $plan = function_exists('nibwp_visual_current_plan') ? nibwp_visual_current_plan() : null; ?>
            <?php if ($plan !== null): ?>
                <div class="nw-vs-plan-now">
                    <p class="nw-vs-plan-now__h">
                        <span class="nw-vs-plan-now__label"><?php esc_html_e('Your plan', 'nibwp'); ?></span>
                        <span class="nw-vs-plan-now__name"><?php echo esc_html($plan['name']); ?></span>
                    </p>
                    <ul class="nw-vs-plan-now__list">
                        <?php foreach ($plan['lines'] as $line): ?>
                            <li><?php echo esc_html($line); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php
                    // Nothing left to sell to a Bundle holder, and the card that
                    // would open is not rendered for them either.
                    $locked_here = function_exists('nibwp_visual_locked') ? nibwp_visual_locked() : [];
                    ?>
                    <?php if ($locked_here !== []): ?>
                        <button type="button" class="nw-vs-plan-now__more" data-vs-locked="<?php echo esc_attr($locked_here[0]['key']); ?>">
                            <?php esc_html_e('What Pro and Bundle add', 'nibwp'); ?>
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="nw-vs-start__foot">
                <?php if (nibwp_visual_bundle_available()): ?>
                    <a class="nw-vs-start__link" href="<?php echo esc_url(nibwp_visual_bundle_url()); ?>">
                        <?php esc_html_e('Download the Claude Desktop bundle', 'nibwp'); ?>
                    </a>
                <?php endif; ?>
                <a class="nw-vs-start__link" href="<?php echo esc_url(admin_url('admin.php?page=nibwp-connect')); ?>">
                    <?php esc_html_e('Every client, and the password method', 'nibwp'); ?>
                </a>
                <button type="button" class="nw-vs-start__link" id="nw-vs-start-recheck">
                    <?php esc_html_e('I have connected — check again', 'nibwp'); ?>
                </button>
            </div>

            <p class="nw-vs-start__said" id="nw-vs-start-said" role="status" aria-live="polite"></p>

            <?php if (!$state['enabled']): ?>
                <p class="nw-vs-warn nw-vs-start__warn">
                    <?php esc_html_e('AI Abilities are switched off on this site. Nothing can reach it until they are on.', 'nibwp'); ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=nibwp-connect')); ?>"><?php esc_html_e('Turn them on', 'nibwp'); ?></a>
                </p>
            <?php endif; ?>

        </div>
    </div>
    <?php
}
