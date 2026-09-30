<?php

declare(strict_types=1);

/**
 * What this site cannot do yet, and what would fix it.
 *
 * The workspace is where someone finds out a capability is missing, and the way
 * they find out today is that nothing happens. This turns that moment into an
 * answer: name the exact thing that is locked, say what it would do here, and
 * offer the one purchase that unlocks it.
 *
 * Ordered by intent, not by price. A skill for a builder that is already
 * installed on this site is worth showing; a skill for a plugin nobody here has
 * is noise, and is left out entirely rather than padded in.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Where to send someone for a given tier.
 *
 * Filterable because the store's URLs are not this plugin's business, and a CTA
 * that lands on a 404 is worse than no CTA. Skills point at the pricing page,
 * which always exists, rather than at a per-item URL that may not.
 */
function nibwp_visual_buy_url(string $tier): string
{
    $pricing = function_exists('nibwp_pricing_url') ? nibwp_pricing_url() : 'https://nibwp.com/pricing';

    $url = $pricing;
    if (function_exists('nibwp_item_url') && ($tier === 'pro' || $tier === 'bundle')) {
        // The same two item URLs the unlock popup has always used. A second set
        // of destinations for the same two products is how one of them quietly
        // rots.
        $url = nibwp_item_url($tier);
    }

    /**
     * Filters the upgrade destination for a tier.
     *
     * @param string $url  The URL to open.
     * @param string $tier One of 'pro', 'bundle', or 'skill:<id>'.
     */
    return (string) apply_filters('nibwp_visual_buy_url', $url, $tier);
}

/**
 * Is everything this skill needs already installed here?
 */
function nibwp_visual_skill_deps_present(array $skill): bool
{
    $requires = (array) ($skill['requires'] ?? []);
    if ($requires === []) {
        return true;
    }

    foreach ($requires as $dep) {
        if (function_exists('nibwp_is_integration_available') && !nibwp_is_integration_available((string) $dep)) {
            return false;
        }
    }

    return true;
}

/**
 * Everything locked on this site that is worth telling someone about.
 *
 * @return array<int, array{
 *     key: string, kind: string, title: string, line: string, tier: string,
 *     cta: string, url: string, icon: string, price: string,
 *     features: array<int, string>, ready: bool
 * }>
 */
function nibwp_visual_locked(): array
{
    $pro = function_exists('nibwp_is_pro') && nibwp_is_pro();
    $locked = [];

    // Skills first: they are the most specific thing an agent can be missing,
    // and the ones whose plugin is already here are the strongest case of all.
    if (function_exists('nibwp_skills_discover')) {
        foreach (nibwp_skills_discover() as $skill) {
            $id = (string) $skill['id'];
            if (empty($skill['premium']) || nibwp_skill_is_unlocked($id)) {
                continue;
            }

            $ready = nibwp_visual_skill_deps_present($skill);
            $requires = (array) ($skill['requires'] ?? []);
            $needs = $requires !== [] ? nibwp_visual_builder_label((string) $requires[0]) : '';

            $locked[] = [
                'key' => 'skill:' . $id,
                'kind' => 'skill',
                'title' => (string) ($skill['name'] ?? $id),
                'line' => $ready && $needs !== ''
                    ? sprintf(
                        /* translators: %s: plugin name, e.g. Etch */
                        __('%s is installed here. This skill is what teaches the agent to build with it properly.', 'nibwp'),
                        $needs
                    )
                    : nibwp_i18n_data((string) ($skill['tagline'] ?? '')),
                'tier' => 'skill:' . $id,
                'cta' => sprintf(
                    /* translators: %s: skill name */
                    __('Unlock %s', 'nibwp'),
                    (string) ($skill['name'] ?? $id)
                ),
                'url' => nibwp_visual_buy_url('skill:' . $id),
                'icon' => (string) ($skill['icon'] ?? ''),
                'price' => isset($skill['price']) ? '$' . (string) $skill['price'] : '',
                'features' => array_map(
                    static fn($f): string => nibwp_i18n_data((string) $f),
                    array_slice((array) ($skill['features'] ?? []), 0, 3)
                ),
                'ready' => $ready,
            ];
        }
    }

    // Stored workflows. Free sites can do the same work by prompt, so this is a
    // convenience being sold, and the copy says exactly that rather than
    // implying the workspace is crippled without it.
    if (!$pro) {
        $locked[] = [
            'key' => 'workflows',
            'kind' => 'feature',
            'title' => __('Saved workflows', 'nibwp'),
            'line' => __('Your written procedures, run start to finish on request — instead of retyping the same instructions into a chat every time.', 'nibwp'),
            'tier' => 'pro',
            'cta' => __('Get Pro', 'nibwp'),
            'url' => nibwp_visual_buy_url('pro'),
            'icon' => '',
            'price' => '',
            'features' => [
                __('Run any saved workflow by name', 'nibwp'),
                __('Import ready-made ones from the library', 'nibwp'),
                __('Every step visible in the workspace as it happens', 'nibwp'),
            ],
            'ready' => true,
        ];
    }

    // Premium integrations whose plugin is installed. Only those: an integration
    // for something nobody here uses is not a missed capability.
    if (!$pro && function_exists('nibwp_premium_integrations') && function_exists('nibwp_get_integrations')) {
        $premium = (array) nibwp_premium_integrations();
        $catalogue = nibwp_get_integrations();

        foreach ($premium as $key) {
            $key = (string) $key;
            $info = $catalogue[$key] ?? null;
            if ($info === null || empty($info['plugin_available'])) {
                continue;
            }
            if (function_exists('nibwp_integration_is_unlocked') && nibwp_integration_is_unlocked($key)) {
                continue;
            }

            $locked[] = [
                'key' => 'integration:' . $key,
                'kind' => 'integration',
                'title' => (string) ($info['name'] ?? $key),
                'line' => sprintf(
                    /* translators: %s: plugin name */
                    __('%s is installed on this site, but the agent cannot drive it without Pro.', 'nibwp'),
                    (string) ($info['plugin_name'] ?? $info['name'] ?? $key)
                ),
                'tier' => 'pro',
                'cta' => __('Get Pro', 'nibwp'),
                'url' => nibwp_visual_buy_url('pro'),
                'icon' => (string) ($info['icon'] ?? ''),
                'price' => '',
                'features' => array_slice(
                    array_map(
                        static fn($a): string => str_replace('-', ' ', ucfirst((string) $a)),
                        (array) ($info['abilities'] ?? [])
                    ),
                    0,
                    3
                ),
                'ready' => true,
            ];
        }
    }

    // Installed-and-waiting first. Everything else keeps its order.
    usort($locked, static fn(array $a, array $b): int => (int) $b['ready'] <=> (int) $a['ready']);

    return $locked;
}

/**
 * The locked entry a given thing belongs to, or null when nothing is locked.
 */
function nibwp_visual_locked_by_key(string $key): ?array
{
    foreach (nibwp_visual_locked() as $entry) {
        if ($entry['key'] === $key) {
            return $entry;
        }
    }

    return null;
}

/**
 * What each plan lets you build, answered for this site rather than in general.
 *
 * A pricing table is a list of nouns. This says what changes here — how many of
 * the premium integrations are for plugins already installed, which locked
 * skills are for builders already in use — because that is the difference
 * between a page of marketing and an answer to "would this help me".
 *
 * @return array<int, array{
 *     key: string, name: string, price: string, current: bool,
 *     url: string, lines: array<int, string>
 * }>
 */
function nibwp_visual_plans(): array
{
    $plan = function_exists('nibwp_license_plan_label') ? nibwp_license_plan_label() : '';

    // Premium integrations whose plugin is actually here.
    $present = [];
    if (function_exists('nibwp_premium_integrations') && function_exists('nibwp_get_integrations')) {
        $catalogue = nibwp_get_integrations();
        foreach ((array) nibwp_premium_integrations() as $key) {
            $info = $catalogue[(string) $key] ?? null;
            if ($info !== null && !empty($info['plugin_available'])) {
                $present[] = (string) ($info['name'] ?? $key);
            }
        }
    }

    // Premium skills, and the ones whose builder is installed here.
    $skills = 0;
    $skills_ready = [];
    if (function_exists('nibwp_skills_discover')) {
        foreach (nibwp_skills_discover() as $skill) {
            if (empty($skill['premium'])) {
                continue;
            }
            $skills++;
            if (nibwp_visual_skill_deps_present($skill)) {
                $skills_ready[] = (string) ($skill['name'] ?? $skill['id']);
            }
        }
    }

    $pro_lines = [
        __('Saved workflows: your own procedures, run start to finish on request', 'nibwp'),
        $present !== []
            ? sprintf(
                /* translators: %s: comma-separated plugin names */
                __('Drive the premium plugins already on this site — %s', 'nibwp'),
                implode(', ', array_slice($present, 0, 4))
            )
            : __('Drive premium plugins: page builders, custom fields, CRM, commerce', 'nibwp'),
        __('Run PHP and write files, for the jobs no ability covers', 'nibwp'),
    ];

    $bundle_lines = [
        __('Everything in Pro', 'nibwp'),
        $skills > 0
            ? sprintf(
                /* translators: %d: number of premium skills */
                _n('Every builder skill — %d of them', 'Every builder skill — all %d', $skills, 'nibwp'),
                $skills
            )
            : __('Every builder skill', 'nibwp'),
        $skills_ready !== []
            ? sprintf(
                /* translators: %s: comma-separated skill names */
                __('Including the ones for what you already build with: %s', 'nibwp'),
                implode(', ', array_slice($skills_ready, 0, 3))
            )
            : __('Turn a screenshot, a URL or a Figma frame into real components', 'nibwp'),
    ];

    return [
        [
            'key' => 'free',
            'name' => __('Free', 'nibwp'),
            'price' => '',
            'current' => $plan === '',
            'url' => '',
            'lines' => [
                __('Agent View, all of it — watch, drive, audit, edit blocks', 'nibwp'),
                __('Pages, posts, media, menus and users by prompt', 'nibwp'),
                __('Connect any number of AI clients, with sign-in or a password', 'nibwp'),
            ],
        ],
        [
            'key' => 'pro',
            'name' => __('Pro', 'nibwp'),
            'price' => '€49',
            'current' => $plan === 'pro',
            'url' => nibwp_visual_buy_url('pro'),
            'lines' => $pro_lines,
        ],
        [
            'key' => 'bundle',
            'name' => __('Bundle', 'nibwp'),
            'price' => '€79',
            'current' => $plan === 'bundle',
            'url' => nibwp_visual_buy_url('bundle'),
            'lines' => $bundle_lines,
        ],
    ];
}

/**
 * The plan this site is actually on, and what it gets.
 *
 * @return array{key: string, name: string, price: string, current: bool, url: string, lines: array<int, string>}
 */
function nibwp_visual_current_plan(): array
{
    $plans = nibwp_visual_plans();

    foreach ($plans as $plan) {
        if ($plan['current']) {
            return $plan;
        }
    }

    // A license for a single skill is neither Free nor Pro, and calling it
    // either would be wrong in a different direction each time.
    $label = function_exists('nibwp_license_plan_label') ? nibwp_license_plan_label() : '';

    return [
        'key' => $label !== '' ? $label : 'free',
        'name' => $label === 'skill' ? __('Skill license', 'nibwp') : __('Free', 'nibwp'),
        'price' => '',
        'current' => true,
        'url' => '',
        'lines' => $plans[0]['lines'] ?? [],
    ];
}

/**
 * The overlay the workspace fills in when someone reaches for a locked thing.
 *
 * Rendered once, empty, and populated from the data below — so a locked job
 * button opens it instantly rather than waiting on a round trip.
 */
function nibwp_visual_render_upsell(): void
{
    $entries = nibwp_visual_locked();
    if ($entries === []) {
        return;
    }

    $bundle = nibwp_visual_buy_url('bundle');
    ?>
    <div class="nw-vs-up" id="nw-vs-up" hidden>
        <div class="nw-vs-up__card" role="dialog" aria-modal="true" aria-labelledby="nw-vs-up-title">
            <button type="button" class="nw-vs-up__x" id="nw-vs-up-close" aria-label="<?php esc_attr_e('Close', 'nibwp'); ?>">&times;</button>

            <div class="nw-vs-up__head">
                <span class="nw-vs-up__icon" id="nw-vs-up-icon" aria-hidden="true"></span>
                <div>
                    <p class="nw-vs-up__eyebrow" id="nw-vs-up-eyebrow"></p>
                    <h2 id="nw-vs-up-title"></h2>
                </div>
            </div>

            <p class="nw-vs-up__line" id="nw-vs-up-line"></p>
            <ul class="nw-vs-up__list" id="nw-vs-up-list"></ul>

            <?php // What each plan actually lets you build here, so the choice is
                  // visible rather than implied by one button. ?>
            <div class="nw-vs-up__plans">
                <?php foreach (nibwp_visual_plans() as $plan): ?>
                    <div class="nw-vs-plan<?php echo $plan['current'] ? ' is-current' : ''; ?><?php echo $plan['key'] === 'bundle' ? ' is-best' : ''; ?>">
                        <p class="nw-vs-plan__h">
                            <span class="nw-vs-plan__name"><?php echo esc_html($plan['name']); ?></span>
                            <?php if ($plan['current']): ?>
                                <span class="nw-vs-plan__tag"><?php esc_html_e('You are here', 'nibwp'); ?></span>
                            <?php elseif ($plan['price'] !== ''): ?>
                                <span class="nw-vs-plan__price"><?php echo esc_html($plan['price']); ?><small><?php echo esc_html_x('/yr', 'after a price: per year', 'nibwp'); ?></small></span>
                            <?php endif; ?>
                        </p>
                        <ul class="nw-vs-plan__list">
                            <?php foreach ($plan['lines'] as $line): ?>
                                <li><?php echo esc_html($line); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="nw-vs-up__actions">
                <a class="nw-vs-up__go" id="nw-vs-up-go" href="#" target="_blank" rel="noopener"></a>
                <a class="nw-vs-up__alt" href="<?php echo esc_url($bundle); ?>" target="_blank" rel="noopener">
                    <?php esc_html_e('Bundle — everything, one price', 'nibwp'); ?>
                </a>
            </div>

            <button type="button" class="nw-vs-up__not" id="nw-vs-up-not">
                <?php esc_html_e('Not now', 'nibwp'); ?>
            </button>
        </div>
    </div>
    <?php
}

/**
 * The locked catalogue in the shape the client needs, keyed for instant lookup.
 *
 * @return array<string, array<string, mixed>>
 */
function nibwp_visual_upsell_data(): array
{
    $out = [];
    foreach (nibwp_visual_locked() as $entry) {
        $out[$entry['key']] = $entry;
    }

    return $out;
}
