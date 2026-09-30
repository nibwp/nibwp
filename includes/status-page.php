<?php

declare(strict_types=1);

/**
 * Status & Diagnostics screen.
 *
 * Renders the results of includes/status-checks.php: one verdict at the top,
 * then the evidence. The ordering is deliberate — someone arriving here is
 * stuck, so the first thing on screen is the single item to fix, not a table.
 *
 * Chrome (tabs, buttons, cards) reuses the shared admin classes rather than
 * inventing a parallel set: .nw-int-tab* from admin-integrations.css, and
 * .nibwp-card / .nibwp-btn-* from admin.css. Only what is genuinely unique to
 * this screen lives in admin-status.css.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Inline icon set. Kept here rather than in the engine so the diagnostics stay
 * presentation-free and remain usable from WP-CLI or a REST response.
 */
function nibwp_status_icon(string $name, int $size = 16): string
{
    $paths = [
        'activity' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
        'plug' => '<path d="M12 22v-5"/><path d="M9 8V2M15 8V2"/><path d="M18 8v3a6 6 0 0 1-12 0V8Z"/>',
        'clipboard' => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 12h6M9 16h4"/>',
        'key' => '<circle cx="7.5" cy="15.5" r="4.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/>',
        'server' => '<rect x="2" y="3" width="20" height="8" rx="2"/><rect x="2" y="13" width="20" height="8" rx="2"/><path d="M6 7h.01M6 17h.01"/>',
        'shield' => '<path d="M20 13c0 5-3.5 7.5-7.7 8.9a2 2 0 0 1-1.3 0C6.5 20.5 3 18 3 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.2-2.7a2 2 0 0 1 2.6 0C14.5 3.8 17 5 19 5a1 1 0 0 1 1 1Z"/>',
        'box' => '<path d="M21 8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/>',
        'refresh' => '<path d="M21 12a9 9 0 1 1-2.6-6.4"/><path d="M21 3v6h-6"/>',
        'copy' => '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7L12.3 19"/>',
        'user' => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'lifebuoy' => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/><path d="m4.9 4.9 4.2 4.2M14.9 14.9l4.2 4.2M14.9 9.1l4.2-4.2M4.9 19.1l4.2-4.2"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'alert' => '<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/>',
        'x' => '<circle cx="12" cy="12" r="9"/><path d="M15 9l-6 6M9 9l6 6"/>',
        'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
        'arrow' => '<path d="M5 12h14M12 5l7 7-7 7"/>',
        'chevron' => '<path d="m6 9 6 6 6-6"/>',
        'wrench' => '<path d="M14.7 6.3a4 4 0 0 0 5 5l-9.4 9.4a2.1 2.1 0 0 1-3-3Z"/>',
        'tag' => '<path d="M20.6 13.4 12 22l-9-9V3h10l7.6 7.6a2 2 0 0 1 0 2.8Z"/><path d="M7.5 7.5h.01"/>',
    ];

    if (!isset($paths[$name])) {
        return '';
    }

    return sprintf(
        '<svg class="nw-status-ico%3$s" width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        . ' stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%2$s</svg>',
        $size,
        $paths[$name],
        // The knock-on arrow points from cause to effect, the way the line reads.
        $name === 'arrow' ? ' nw-rtl-flip' : ''
    );
}

/**
 * A copy-to-clipboard button.
 *
 * Confirmation is carried by the icon and the label, not by recoloring the
 * text: this markup is used on both the ghost and the accent button, and a
 * green success color on the accent's orange ground is close to unreadable.
 */
function nibwp_status_copy_button(string $target, string $label, bool $primary = false): void
{
    ?>
    <button type="button"
            class="<?php echo $primary ? 'nibwp-btn-primary' : 'nibwp-btn-ghost'; ?> nw-status-copy"
            data-target="<?php echo esc_attr($target); ?>">
        <span class="nw-status-copy__icon nw-status-copy__icon--idle"><?php echo nibwp_status_icon('copy', 14); ?></span>
        <span class="nw-status-copy__icon nw-status-copy__icon--done"><?php echo nibwp_status_icon('check', 14); ?></span>
        <span class="nw-status-copy__label"><?php echo esc_html($label); ?></span>
    </button>
    <?php
}

/**
 * Icon per check group, so the sections are scannable without reading.
 *
 * @return array<string, string>
 */
function nibwp_status_group_icons(): array
{
    return [
        'connection' => 'plug',
        'auth' => 'key',
        'environment' => 'server',
        'interference' => 'shield',
        'extensions' => 'box',
    ];
}

/**
 * Transport shapes a client can use. What breaks differs per shape, so the
 * picker changes the advice rather than just the label.
 *
 * @return array<string, array{label: string, clients: string, icon: string, notes: array<int, string>}>
 */
function nibwp_status_methods(): array
{
    return [
        'remote' => [
            'label' => __('Direct MCP URL', 'nibwp'),
            'icon' => 'link',
            'clients' => __('Claude Code, Cursor, VS Code, Windsurf, Cline, Zed, Codex, Gemini CLI and most others', 'nibwp'),
            'notes' => [
                __('The client talks straight to the endpoint below over HTTPS and signs in with your WordPress username plus an application password.', 'nibwp'),
                __('Use the application password exactly as WordPress showed it. The spaces are part of it — do not strip them.', 'nibwp'),
                __('Use your username, not your email address, unless your client says otherwise.', 'nibwp'),
                __('If this fails while every check on this page passes, the credentials in the client are wrong or belong to a different site.', 'nibwp'),
            ],
        ],
        'bridge' => [
            'label' => __('Bridge / mcp-remote', 'nibwp'),
            'icon' => 'box',
            'clients' => __('Claude Desktop and other clients without native remote MCP support', 'nibwp'),
            'notes' => [
                __('A helper process on your own computer relays between the client and this site, so failures can be local rather than server-side.', 'nibwp'),
                __('Node.js must be installed on that computer and reachable on its PATH.', 'nibwp'),
                __('Restart the client completely after editing its config file. Most of them only read it at startup.', 'nibwp'),
                __('Corporate VPNs and local antivirus can block the helper before it ever reaches this site.', 'nibwp'),
            ],
        ],
        'other' => [
            'label' => __('Something else', 'nibwp'),
            'icon' => 'wrench',
            'clients' => __('Custom scripts, self-built integrations, automation platforms', 'nibwp'),
            'notes' => [
                __('Send the application password with HTTP Basic authentication over the Authorization header.', 'nibwp'),
                __('Confirm the Authorization header check on this page passes — many servers discard that header silently.', 'nibwp'),
                __('Follow redirects. A site that redirects http to https or non-www to www will otherwise drop the credentials.', 'nibwp'),
            ],
        ],
    ];
}

/**
 * Map a status to its CSS modifier, human label and icon.
 *
 * @return array{0: string, 1: string, 2: string}
 */
function nibwp_status_badge(string $status): array
{
    return match ($status) {
        NIBWP_STATUS_PASS => ['ok', __('Pass', 'nibwp'), 'check'],
        NIBWP_STATUS_WARN => ['warn', __('Check', 'nibwp'), 'alert'],
        NIBWP_STATUS_FAIL => ['fail', __('Problem', 'nibwp'), 'x'],
        default => ['info', __('Info', 'nibwp'), 'info'],
    };
}

/**
 * Render the verdict hero plus the grouped checklist. Shared by the initial
 * page render and the AJAX re-run so the two can never drift apart.
 *
 * @param array<string, mixed> $run
 */
function nibwp_status_render_results(array $run): void
{
    $verdict = $run['verdict'];
    $state = (string) $verdict['status'];
    $hero_icon = match ($state) {
        'ready' => 'check',
        'degraded' => 'alert',
        default => 'x',
    };
    $group_icons = nibwp_status_group_icons();
    ?>
    <div class="nw-status-verdict is-<?php echo esc_attr($state); ?>">
        <span class="nw-status-verdict__icon"><?php echo nibwp_status_icon($hero_icon, 22); ?></span>
        <div class="nw-status-verdict__body">
            <h2><?php echo esc_html((string) $verdict['headline']); ?></h2>
            <p><?php echo esc_html((string) $verdict['sub']); ?></p>

            <?php if (!empty($verdict['culprit'])): ?>
                <?php $culprit = $verdict['culprit']; ?>
                <div class="nw-status-culprit">
                    <div class="nw-status-culprit__head">
                        <span class="nw-status-culprit__tag">
                            <?php echo nibwp_status_icon('wrench', 12); ?>
                            <?php esc_html_e('Fix this first', 'nibwp'); ?>
                        </span>
                        <strong><?php echo esc_html((string) $culprit['label']); ?></strong>
                    </div>
                    <?php if ((string) $culprit['detail'] !== ''): ?>
                        <p class="nw-status-culprit__detail"><?php echo esc_html((string) $culprit['detail']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($culprit['fix'])): ?>
                        <ol class="nw-status-steps">
                            <?php foreach ((array) $culprit['fix'] as $step): ?>
                                <li><?php echo esc_html((string) $step); ?></li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="nw-status-counts">
        <?php
        $counts = $run['counts'];
        $tiles = [
            ['ok', __('Passed', 'nibwp'), (int) $counts[NIBWP_STATUS_PASS], 'check'],
            ['warn', __('Needs a look', 'nibwp'), (int) $counts[NIBWP_STATUS_WARN], 'alert'],
            ['fail', __('Problems', 'nibwp'), (int) $counts[NIBWP_STATUS_FAIL], 'x'],
        ];
        foreach ($tiles as $tile): ?>
            <div class="nw-status-count is-<?php echo esc_attr($tile[0]); ?>">
                <span class="nw-status-count__icon"><?php echo nibwp_status_icon($tile[3], 16); ?></span>
                <span class="nw-status-count__meta">
                    <span class="nw-status-count__value"><?php echo esc_html((string) $tile[2]); ?></span>
                    <span class="nw-status-count__label"><?php echo esc_html($tile[1]); ?></span>
                </span>
            </div>
        <?php endforeach; ?>
    </div>

    <?php foreach (nibwp_status_groups() as $key => $title): ?>
        <?php if (empty($run['groups'][$key])) { continue; } ?>
        <h3 class="nw-status-group">
            <?php echo nibwp_status_icon($group_icons[$key] ?? 'info', 14); ?>
            <?php echo esc_html($title); ?>
        </h3>
        <div class="nw-status-list">
            <?php foreach ($run['groups'][$key] as $check): ?>
                <?php
                [$modifier, $badge_label, $badge_icon] = nibwp_status_badge((string) $check['status']);
                $consequence = (string) ($check['consequence_of'] ?? '');
                // A downstream failure gets the muted treatment: it is evidence,
                // not a second job. Fixing the named cause clears it.
                if ($consequence !== '') {
                    $modifier = 'knock';
                    $badge_label = __('Knock-on', 'nibwp');
                    $badge_icon = 'arrow';
                }
                $has_more = (string) $check['detail'] !== '' || !empty($check['fix']);
                ?>
                <div class="nw-status-item is-<?php echo esc_attr($modifier); ?><?php echo $has_more ? ' has-more' : ''; ?>">
                    <div class="nw-status-item__head"<?php echo $has_more ? ' tabindex="0" role="button" aria-expanded="false"' : ''; ?>>
                        <span class="nw-status-badge is-<?php echo esc_attr($modifier); ?>">
                            <?php echo nibwp_status_icon($badge_icon, 12); ?>
                            <?php echo esc_html($badge_label); ?>
                        </span>
                        <span class="nw-status-item__text">
                            <strong><?php echo esc_html((string) $check['label']); ?></strong>
                            <span><?php echo esc_html((string) $check['summary']); ?></span>
                            <?php if ($consequence !== ''): ?>
                                <span class="nw-status-item__because">
                                    <?php
                                    printf(
                                        /* translators: %s: label of the upstream check that failed */
                                        esc_html__('Caused by "%s" above — fix that and this clears itself.', 'nibwp'),
                                        esc_html($consequence)
                                    );
                                    ?>
                                </span>
                            <?php endif; ?>
                        </span>
                        <?php if ($has_more): ?>
                            <span class="nw-status-item__chevron"><?php echo nibwp_status_icon('chevron', 16); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($has_more): ?>
                        <div class="nw-status-item__body">
                            <?php if ((string) $check['detail'] !== ''): ?>
                                <?php foreach (explode("\n", (string) $check['detail']) as $paragraph): ?>
                                    <?php if (trim($paragraph) === '') { continue; } ?>
                                    <p><?php echo esc_html(trim($paragraph)); ?></p>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php if (!empty($check['fix'])): ?>
                                <ul class="nw-status-fix">
                                    <?php foreach ((array) $check['fix'] as $step): ?>
                                        <li><?php echo esc_html((string) $step); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
    <?php
}

/**
 * AJAX: re-run the diagnostic with fresh loopback probes.
 */
add_action('wp_ajax_nibwp_status_rerun', static function (): void {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('You do not have permission to run this.', 'nibwp')], 403);
    }
    check_ajax_referer('nibwp_status_rerun');

    $run = nibwp_status_run(true);

    ob_start();
    nibwp_status_render_results($run);
    $html = (string) ob_get_clean();

    wp_send_json_success([
        'html' => $html,
        'report' => nibwp_status_report_text($run),
    ]);
});

/**
 * Render the Status & Diagnostics page.
 */
function nibwp_render_status_page(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to view this page.', 'nibwp'));
    }

    $run = nibwp_status_run(false);
    $endpoint = (string) $run['endpoint'];
    $methods = nibwp_status_methods();
    $user = wp_get_current_user();

    $tabs = [
        'diagnosis' => [__('Diagnosis', 'nibwp'), 'activity'],
        'connect' => [__('How you connect', 'nibwp'), 'plug'],
        'report' => [__('Support report', 'nibwp'), 'clipboard'],
    ];

    nibwp_render_admin_header();
    ?>
    <div class="wrap nibwp-wrap nw-status">
        <div class="nibwp-page-header">
            <div>
                <h1><?php esc_html_e('Status', 'nibwp'); ?></h1>
                <p class="nibwp-subtitle"><?php esc_html_e('Health of this site and of the MCP connection — and, when something is wrong, which single thing to fix.', 'nibwp'); ?></p>
            </div>
            <div class="nibwp-page-header__actions">
                <button type="button" class="nibwp-btn-primary" id="nw-status-rerun"
                        data-nonce="<?php echo esc_attr(wp_create_nonce('nibwp_status_rerun')); ?>">
                    <span class="nw-status-spin-target"><?php echo nibwp_status_icon('refresh', 15); ?></span>
                    <span><?php esc_html_e('Re-run diagnostic', 'nibwp'); ?></span>
                </button>
            </div>
        </div>

        <div class="nw-int-tabs-wrap nw-status-tabs-wrap">
            <div class="nw-int-tabs" role="tablist">
                <?php $first = true; ?>
                <?php foreach ($tabs as $key => $tab): ?>
                    <button type="button"
                            class="nw-int-tab<?php echo $first ? ' is-active' : ''; ?>"
                            data-tab="<?php echo esc_attr($key); ?>"
                            role="tab"
                            aria-selected="<?php echo $first ? 'true' : 'false'; ?>">
                        <?php echo nibwp_status_icon($tab[1], 15); ?>
                        <?php echo esc_html($tab[0]); ?>
                    </button>
                    <?php $first = false; ?>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="nw-status-panel is-active" data-panel="diagnosis">
            <div id="nw-status-results">
                <?php nibwp_status_render_results($run); ?>
            </div>
        </div>

        <div class="nw-status-panel" data-panel="connect">
            <div class="nibwp-card">
                <h2 class="nibwp-card-title">
                    <?php echo nibwp_status_icon('link', 14); ?>
                    <?php esc_html_e('Your endpoint', 'nibwp'); ?>
                </h2>
                <p class="nw-status-card__lead"><?php esc_html_e('This is the address a client connects to. It must be reachable from the machine running the client, not just from this server.', 'nibwp'); ?></p>

                <label class="nw-status-field-label" for="nw-status-endpoint">
                    <?php echo nibwp_status_icon('link', 13); ?>
                    <?php esc_html_e('MCP endpoint', 'nibwp'); ?>
                </label>
                <div class="nw-status-copyrow">
                    <input type="text" readonly value="<?php echo esc_attr($endpoint); ?>" id="nw-status-endpoint" class="nw-status-copyrow__input">
                    <?php nibwp_status_copy_button('nw-status-endpoint', __('Copy', 'nibwp')); ?>
                </div>

                <label class="nw-status-field-label" for="nw-status-user">
                    <?php echo nibwp_status_icon('user', 13); ?>
                    <?php esc_html_e('Username', 'nibwp'); ?>
                </label>
                <div class="nw-status-copyrow">
                    <input type="text" readonly value="<?php echo esc_attr($user->user_login); ?>" id="nw-status-user" class="nw-status-copyrow__input">
                    <?php nibwp_status_copy_button('nw-status-user', __('Copy', 'nibwp')); ?>
                </div>

                <p class="nw-status-note">
                    <?php echo nibwp_status_icon('key', 13); ?>
                    <?php
                    printf(
                        /* translators: %s: link to the Connect screen */
                        esc_html__('Application passwords are created on the %s screen. WordPress shows each one exactly once.', 'nibwp'),
                        '<a href="' . esc_url(admin_url('admin.php?page=nibwp-connect')) . '">' . esc_html__('Connect', 'nibwp') . '</a>'
                    );
                    ?>
                </p>
            </div>

            <div class="nibwp-card">
                <h2 class="nibwp-card-title">
                    <?php echo nibwp_status_icon('plug', 14); ?>
                    <?php esc_html_e('How are you connecting?', 'nibwp'); ?>
                </h2>
                <p class="nw-status-card__lead"><?php esc_html_e('Pick the shape that matches your setup. Different transports fail in different places, so the advice changes with it.', 'nibwp'); ?></p>
                <div class="nw-status-methods">
                    <?php $first = true; ?>
                    <?php foreach ($methods as $key => $method): ?>
                        <button type="button" class="nw-status-method<?php echo $first ? ' is-active' : ''; ?>" data-method="<?php echo esc_attr($key); ?>">
                            <span class="nw-status-method__icon"><?php echo nibwp_status_icon($method['icon'], 16); ?></span>
                            <span class="nw-status-method__text">
                                <strong><?php echo esc_html($method['label']); ?></strong>
                                <span><?php echo esc_html($method['clients']); ?></span>
                            </span>
                        </button>
                        <?php $first = false; ?>
                    <?php endforeach; ?>
                </div>
                <?php $first = true; ?>
                <?php foreach ($methods as $key => $method): ?>
                    <div class="nw-status-method-notes<?php echo $first ? ' is-active' : ''; ?>" data-method-notes="<?php echo esc_attr($key); ?>">
                        <ul class="nw-status-fix">
                            <?php foreach ($method['notes'] as $note): ?>
                                <li><?php echo esc_html($note); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php $first = false; ?>
                <?php endforeach; ?>
            </div>

            <div class="nibwp-card">
                <h2 class="nibwp-card-title">
                    <?php echo nibwp_status_icon('lifebuoy', 14); ?>
                    <?php esc_html_e('If it still will not connect', 'nibwp'); ?>
                </h2>
                <ol class="nw-status-steps">
                    <li><?php esc_html_e('Re-run the diagnostic on this page and fix anything marked Problem, starting at the top.', 'nibwp'); ?></li>
                    <li><?php esc_html_e('Open the endpoint above in a private browser window. A login prompt or a JSON error means the site is reachable; a firewall page or timeout means it is not.', 'nibwp'); ?></li>
                    <li><?php esc_html_e('Temporarily deactivate security and firewall plugins one at a time, re-running the diagnostic after each. This is the fastest way to name the culprit.', 'nibwp'); ?></li>
                    <li><?php esc_html_e('Generate a fresh application password and paste it into the client again, spaces included.', 'nibwp'); ?></li>
                    <li><?php esc_html_e('Still stuck? Copy the support report from the next tab and send it to us — it contains everything we would otherwise have to ask for.', 'nibwp'); ?></li>
                </ol>
            </div>
        </div>

        <div class="nw-status-panel" data-panel="report">
            <div class="nibwp-card">
                <div class="nw-status-report-head">
                    <div>
                        <h2 class="nibwp-card-title">
                            <?php echo nibwp_status_icon('clipboard', 14); ?>
                            <?php esc_html_e('Support report', 'nibwp'); ?>
                        </h2>
                        <p class="nw-status-card__lead"><?php esc_html_e('A plain-text summary of every check. It contains your site address and software versions — no passwords, keys or tokens.', 'nibwp'); ?></p>
                    </div>
                    <?php nibwp_status_copy_button('nw-status-report', __('Copy report', 'nibwp'), true); ?>
                </div>
                <textarea id="nw-status-report" class="nw-status-report" rows="20" readonly><?php echo esc_textarea(nibwp_status_report_text($run)); ?></textarea>
            </div>
        </div>
    </div>

    <script>
    (function () {
        var root = document.querySelector('.nw-status');
        if (!root) { return; }

        // Tabs.
        root.querySelectorAll('.nw-int-tab[data-tab]').forEach(function (tab) {
            tab.addEventListener('click', function () {
                var name = tab.getAttribute('data-tab');
                root.querySelectorAll('.nw-int-tab[data-tab]').forEach(function (t) {
                    var on = t === tab;
                    t.classList.toggle('is-active', on);
                    t.setAttribute('aria-selected', on ? 'true' : 'false');
                });
                root.querySelectorAll('.nw-status-panel').forEach(function (p) {
                    p.classList.toggle('is-active', p.getAttribute('data-panel') === name);
                });
            });
        });

        // Expandable check rows. Delegated, because the list is replaced wholesale
        // when the diagnostic is re-run.
        root.addEventListener('click', function (e) {
            var head = e.target.closest ? e.target.closest('.nw-status-item__head') : null;
            if (!head || !root.contains(head)) { return; }
            var item = head.parentNode;
            if (!item.classList.contains('has-more')) { return; }
            var open = item.classList.toggle('is-open');
            head.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        root.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') { return; }
            var head = e.target.closest ? e.target.closest('.nw-status-item__head') : null;
            if (!head || !root.contains(head)) { return; }
            e.preventDefault();
            head.click();
        });

        // Method picker.
        root.querySelectorAll('.nw-status-method').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var key = btn.getAttribute('data-method');
                root.querySelectorAll('.nw-status-method').forEach(function (b) {
                    b.classList.toggle('is-active', b === btn);
                });
                root.querySelectorAll('[data-method-notes]').forEach(function (n) {
                    n.classList.toggle('is-active', n.getAttribute('data-method-notes') === key);
                });
            });
        });

        // Copy buttons. Only the label span is swapped, so the icon survives.
        root.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('.nw-status-copy') : null;
            if (!btn || !root.contains(btn)) { return; }
            var field = document.getElementById(btn.getAttribute('data-target'));
            if (!field) { return; }
            var label = btn.querySelector('.nw-status-copy__label');
            field.select();
            field.setSelectionRange(0, field.value.length);
            var done = function () {
                if (!label || btn.classList.contains('is-copied')) { return; }
                var original = label.textContent;
                btn.classList.add('is-copied');
                label.textContent = <?php echo wp_json_encode(__('Copied', 'nibwp')); ?>;
                setTimeout(function () {
                    label.textContent = original;
                    btn.classList.remove('is-copied');
                }, 1600);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(field.value).then(done, function () { document.execCommand('copy'); done(); });
            } else {
                document.execCommand('copy');
                done();
            }
        });

        // Re-run.
        var rerun = document.getElementById('nw-status-rerun');
        if (rerun) {
            rerun.addEventListener('click', function () {
                if (rerun.classList.contains('is-busy')) { return; }
                rerun.classList.add('is-busy');
                var body = new URLSearchParams();
                body.append('action', 'nibwp_status_rerun');
                body.append('_wpnonce', rerun.getAttribute('data-nonce'));
                fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: body.toString()
                }).then(function (r) { return r.json(); }).then(function (res) {
                    if (res && res.success && res.data) {
                        document.getElementById('nw-status-results').innerHTML = res.data.html;
                        var report = document.getElementById('nw-status-report');
                        if (report) { report.value = res.data.report; }
                    }
                }).catch(function () {
                    // Leave the previous results on screen; a failed refresh is
                    // less confusing than an empty page.
                }).then(function () {
                    rerun.classList.remove('is-busy');
                });
            });
        }
    })();
    </script>
    <?php
    nibwp_render_admin_footer();
}
