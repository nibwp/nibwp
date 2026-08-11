<?php

declare(strict_types=1);

/**
 * The "Sign in" half of the Connect screen, plus the connections manager.
 *
 * Kept out of connect-page.php deliberately: that file is already 1,700 lines
 * and the password flow there works. This adds the second path beside it rather
 * than rewriting the first.
 */

if (!defined('ABSPATH')) {
    exit();
}

add_action('admin_post_nibwp_oauth_revoke', 'nibwp_oauth_handle_admin_revoke');
add_action('admin_post_nibwp_oauth_add_client', 'nibwp_oauth_handle_add_client');
add_action('admin_post_nibwp_oauth_delete_client', 'nibwp_oauth_handle_delete_client');

/**
 * Create a client ID by hand.
 *
 * Registration normally happens on its own: the client asks, the site answers.
 * That call comes from the client's own servers, so a firewall that blocks
 * unknown POSTs stops it, and some clients ask for a client ID up front rather
 * than registering at all. Either way the user is stuck with nothing to type in.
 * This gives them the two values those forms ask for.
 */
function nibwp_oauth_handle_add_client(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to do this.', 'nibwp'));
    }

    check_admin_referer('nibwp_oauth_add_client');

    $name = isset($_POST['client_name']) ? sanitize_text_field(wp_unslash($_POST['client_name'])) : '';
    $raw = isset($_POST['redirect_uris']) ? sanitize_textarea_field(wp_unslash($_POST['redirect_uris'])) : '';

    $uris = [];
    foreach (preg_split('/[\r\n,]+/', $raw) ?: [] as $line) {
        $line = trim((string) $line);
        if ($line !== '') {
            $uris[] = $line;
        }
    }

    $error = '';
    if ($name === '') {
        $error = 'name';
    } elseif ($uris === []) {
        $error = 'uris';
    } else {
        foreach ($uris as $uri) {
            if (!nibwp_oauth_redirect_uri_is_valid($uri)) {
                $error = 'invalid';
                break;
            }
        }
    }

    if ($error !== '') {
        wp_safe_redirect(admin_url('admin.php?page=nibwp-connect&nibwp_client_error=' . $error . '#nibwp-oauth-manual'));
        exit;
    }

    $client_id = 'nibwp-client-' . wp_generate_password(24, false, false);
    nibwp_oauth_save_client($client_id, [
        'client_id' => $client_id,
        'client_name' => $name,
        'redirect_uris' => $uris,
        'created' => time(),
        // Marks it exempt from the sweep that clears registrations nobody used:
        // one created by hand is waiting to be pasted somewhere, which can
        // reasonably take longer than a day.
        'manual' => true,
    ]);

    wp_safe_redirect(admin_url('admin.php?page=nibwp-connect&nibwp_client_added=' . rawurlencode($client_id) . '#nibwp-oauth-manual'));
    exit;
}

function nibwp_oauth_handle_delete_client(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to do this.', 'nibwp'));
    }

    $client_id = isset($_POST['client_id']) ? sanitize_text_field(wp_unslash($_POST['client_id'])) : '';
    check_admin_referer('nibwp_oauth_delete_client_' . $client_id);

    nibwp_oauth_forget_client($client_id);

    wp_safe_redirect(admin_url('admin.php?page=nibwp-connect&nibwp_client_deleted=1#nibwp-oauth-manual'));
    exit;
}

/**
 * Client IDs created by hand, newest first.
 *
 * @return array<int, array<string, mixed>>
 */
function nibwp_oauth_manual_clients(): array
{
    $rows = [];
    foreach (nibwp_oauth_clients() as $id => $client) {
        if (empty($client['manual'])) {
            continue;
        }
        $rows[] = [
            'id' => (string) $id,
            'name' => (string) ($client['client_name'] ?? ''),
            'redirect_uris' => (array) ($client['redirect_uris'] ?? []),
            'created' => (int) ($client['created'] ?? 0),
        ];
    }

    usort($rows, static fn(array $a, array $b): int => $b['created'] <=> $a['created']);

    return $rows;
}

/**
 * Revoke a connection from the connections list.
 */
function nibwp_oauth_handle_admin_revoke(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to do this.', 'nibwp'));
    }

    $token_id = isset($_POST['token_id']) ? sanitize_text_field(wp_unslash($_POST['token_id'])) : '';
    check_admin_referer('nibwp_oauth_revoke_' . $token_id);

    nibwp_oauth_revoke_token(get_current_user_id(), $token_id);

    wp_safe_redirect(admin_url('admin.php?page=nibwp-connect&nibwp_result=disconnected'));
    exit;
}

/**
 * Every live connection for the current user, newest first.
 *
 * @return array<int, array<string, mixed>>
 */
function nibwp_oauth_user_connections(): array
{
    $user_id = get_current_user_id();
    nibwp_oauth_prune_tokens($user_id);

    $rows = [];
    foreach (nibwp_oauth_user_tokens($user_id) as $id => $record) {
        $client = nibwp_oauth_client((string) ($record['client_id'] ?? ''));
        $rows[] = [
            'id' => (string) $id,
            // Recorded with the token from now on; older tokens fall back to
            // resolving it, which still beats calling it unknown.
            'name' => trim((string) ($record['client_name'] ?? '')) !== ''
                ? (string) $record['client_name']
                : nibwp_oauth_client_display_name((string) ($record['client_id'] ?? '')),
            'scopes' => (array) ($record['scopes'] ?? []),
            'created' => (int) ($record['created'] ?? 0),
            'last_used' => (int) ($record['last_used'] ?? 0),
            'last_ip' => (string) ($record['last_ip'] ?? ''),
            'expires' => (int) ($record['expires'] ?? 0),
        ];
    }

    usort($rows, static fn(array $a, array $b): int => $b['created'] <=> $a['created']);

    return $rows;
}

/**
 * Whether OAuth can work here at all.
 *
 * Fails loudly rather than silently: without HTTPS no AI client will complete
 * the flow, and a tab that just never works is worse than one that explains why.
 *
 * @return array{ok: bool, reason: string, message: string}
 */
function nibwp_oauth_availability(): array
{
    if (wp_parse_url(home_url(), PHP_URL_SCHEME) !== 'https') {
        $local = function_exists('wp_get_environment_type') && wp_get_environment_type() === 'local';

        return [
            'ok' => false,
            'reason' => 'no_https',
            'message' => $local
                ? __('Signing in needs HTTPS. This looks like a local environment — use an application password here, or serve the site over HTTPS to test the full flow.', 'nibwp')
                : __('Signing in needs HTTPS. Install a certificate and set your site address to https:// — worth doing regardless, since credentials cross the network in the clear without it.', 'nibwp'),
        ];
    }

    return ['ok' => true, 'reason' => 'available', 'message' => ''];
}

// ---------------------------------------------------------------------------
// Remote-transport client configuration
// ---------------------------------------------------------------------------

/**
 * Whether an assistant running on someone else's servers could reach this site.
 *
 * Browser assistants connect from the vendor's cloud, not from the machine the
 * admin screen is open on. A hostname only this machine or this network can
 * resolve is therefore unusable for them, however well the site works locally —
 * so those options are withdrawn rather than left to fail with a timeout the
 * user has no way to interpret.
 */
function nibwp_oauth_host_is_cloud_reachable(): bool
{
    $host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
    if ($host === '') {
        return false;
    }

    if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
        return false;
    }

    // Development suffixes that resolve only through a local hosts file.
    foreach (['.local', '.test', '.localhost', '.invalid', '.example'] as $suffix) {
        if (str_ends_with($host, $suffix)) {
            return false;
        }
    }

    // A name with no dot at all cannot be a public domain.
    if (!str_contains($host, '.') && !str_contains($host, ':')) {
        return false;
    }

    $literal = trim($host, '[]');
    if (filter_var($literal, FILTER_VALIDATE_IP)) {
        return (bool) filter_var(
            $literal,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );
    }

    return true;
}

/**
 * The name the connector carries in the assistant's own list.
 */
function nibwp_oauth_connector_name(): string
{
    $site = trim((string) get_bloginfo('name'));

    return $site !== '' ? 'NibWP — ' . $site : 'NibWP';
}

/**
 * Open the add-connector dialog with the name and address already filled in.
 *
 * The user still reviews and confirms; nothing is added on their behalf, and no
 * credential rides along — there is none to send.
 */
function nibwp_oauth_connector_link(string $mcp_url, string $name): string
{
    return 'https://claude.ai/customize/connectors?modal=add-custom-connector'
        . '&connectorName=' . rawurlencode($name)
        . '&connectorUrl=' . rawurlencode($mcp_url);
}

/**
 * Config snippets for clients that speak HTTP MCP directly.
 *
 * Every existing snippet in the plugin proxies through a local npx bridge and
 * carries a password in an env var. These carry no credential at all — the
 * client discovers the authorization server from the endpoint and runs the flow
 * itself, so there is nothing in the file to leak.
 *
 * @return array<string, array{code: string, hint: string, paths: array<string, string>, isShell: bool}>
 */
function nibwp_oauth_remote_configs(string $mcp_url, string $name): array
{
    $json = static fn(array $data): string => (string) wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    $mcp_servers = $json(['mcpServers' => [$name => ['type' => 'http', 'url' => $mcp_url]]]);
    $servers = $json(['servers' => [$name => ['type' => 'http', 'url' => $mcp_url]]]);
    $url_only = $json(['mcpServers' => [$name => ['url' => $mcp_url]]]);

    // Whether the assistant's own servers can reach this site at all. Browser
    // assistants connect from the vendor's cloud, not from the machine looking
    // at this screen, so on a local hostname the connector can never work and
    // handing someone the address is a dead end dressed as an instruction.
    $reachable = nibwp_oauth_host_is_cloud_reachable();
    $connector_name = nibwp_oauth_connector_name();
    $connector_link = nibwp_oauth_connector_link($mcp_url, $connector_name);
    $local_note = __('This site uses a local address, so an assistant running in the cloud cannot reach it. Connect from a client on this machine, or publish the site to a public HTTPS address.', 'nibwp');

    // One-click install links, where the client publishes a documented handler.
    // Nothing is invented here: a link that silently does nothing is worse than
    // the instructions it replaced, so clients without a real handler get a link
    // to the screen where the connector is added instead.
    $vscode_link = 'vscode:mcp/install?' . rawurlencode((string) wp_json_encode([
        'name' => $name,
        'type' => 'http',
        'url' => $mcp_url,
    ]));
    // Cursor's config is a bare url and it detects the transport itself. The
    // `type` key is VS Code's, and passing it here gets the payload ignored.
    $cursor_link = 'cursor://anysphere.cursor-deeplink/mcp/install?name=' . rawurlencode($name)
        . '&config=' . rawurlencode(base64_encode((string) wp_json_encode(['url' => $mcp_url])));

    $configs = [];

    // Assistants that connect from their own cloud. Offered only when they could
    // actually reach this site.
    if ($reachable) {
        $configs['claude-ai'] = [
            'code' => $mcp_url,
            'hint' => __('Press the button. Claude opens with the connector already filled in — review it and press <strong>Add</strong>. Because it is added to your account rather than one app, it also appears in Claude Desktop after a short sync.', 'nibwp'),
            'paths' => [],
            'isShell' => false,
            'action' => [
                'label' => __('Add the connector to Claude.ai', 'nibwp'),
                'url' => $connector_link,
            ],
        ];
        $configs['chatgpt'] = [
            'code' => $mcp_url,
            'hint' => __('Copy the address above, then turn on <strong>Developer mode</strong> under <strong>Settings → Security and login</strong>, then <strong>Settings → Plugins → Browse plugins</strong>, press <strong>+</strong>, give it a name, paste the address as the server URL, tick the confirmation box and press <strong>Create</strong>. Signing in brings you back here to approve.', 'nibwp'),
            'paths' => [],
            'isShell' => false,
            'action' => [
                'label' => __('Open ChatGPT connectors', 'nibwp'),
                'url' => 'https://chatgpt.com/#settings/Connectors',
            ],
        ];
    } else {
        $configs['claude-ai'] = [
            'code' => $mcp_url,
            'hint' => $local_note,
            'paths' => [],
            'isShell' => false,
            'unreachable' => true,
        ];
        $configs['chatgpt'] = $configs['claude-ai'];
    }

    return $configs + [
        // One command instead of a config file. It signs in through the same
        // consent screen as everything else here, and can then write the
        // configuration for whichever editor the person is actually using.
        'terminal' => [
            'code' => 'npx nibwp-cli auth login ' . escapeshellarg(home_url('/')),
            'hint' => __(
                'Run this in your terminal — it needs <strong>Node.js 20 or newer</strong> from nodejs.org. Your browser opens to approve, exactly as it does above. Afterwards <code>nibwp agent add cursor</code> (or <code>vscode</code>, <code>claude-code</code>, <code>codex</code>, …) writes the connection into that editor for you, and <code>nibwp discover</code> lists what this site can do.',
                'nibwp',
            ),
            'paths' => [],
            'isShell' => true,
        ],
        'claude-code' => [
            'code' => 'claude mcp add --transport http ' . escapeshellarg($name) . ' ' . escapeshellarg($mcp_url),
            'hint' => __('Run this in your terminal. Your browser opens to approve the connection.', 'nibwp'),
            'paths' => [],
            'isShell' => true,
        ],
        'claude-desktop' => [
            // Not the JSON config file: that route is for local command servers.
            // A remote endpoint with sign-in is added as a connector.
            'code' => $mcp_url,
            'hint' => __('Open <strong>Settings → Connectors</strong>, choose <strong>Add custom connector</strong>, give it a name and paste this address. Leave the OAuth client ID and secret under advanced settings empty — this site issues public clients and needs neither. Saving opens your browser to sign in.', 'nibwp'),
            'paths' => [],
            'isShell' => false,
        ],
        'cursor' => [
            'code' => $mcp_servers,
            'hint' => __('Press the button: Cursor opens its <strong>Tools and MCP</strong> page with this server listed, and you press <strong>Install</strong> then <strong>Connect</strong>. If nothing opens, Cursor has not registered its link handler on this machine — add the block below to <code>mcp.json</code> instead, which always works.', 'nibwp'),
            'paths' => ['Global' => '~/.cursor/mcp.json', 'Project' => '.cursor/mcp.json'],
            'isShell' => false,
            'action' => [
                'label' => __('Add to Cursor', 'nibwp'),
                'url' => $cursor_link,
            ],
        ],
        'vscode' => [
            'code' => $servers,
            'hint' => __('Use the button, or add this to <code>mcp.json</code> yourself.', 'nibwp'),
            'paths' => ['Workspace' => '.vscode/mcp.json', 'User' => 'Run “MCP: Open User Configuration”'],
            'isShell' => false,
            'action' => [
                'label' => __('Add to VS Code', 'nibwp'),
                'url' => $vscode_link,
            ],
        ],
        'github-copilot' => [
            'code' => $servers,
            'hint' => __('Copilot reads VS Code\'s MCP configuration, so the same button works.', 'nibwp'),
            'paths' => ['Workspace' => '.vscode/mcp.json', 'User' => 'Run “MCP: Open User Configuration”'],
            'isShell' => false,
            'action' => [
                'label' => __('Add to VS Code', 'nibwp'),
                'url' => $vscode_link,
            ],
        ],
        'windsurf' => [
            'code' => $mcp_servers,
            'hint' => __('Add to <code>mcp_config.json</code>.', 'nibwp'),
            'paths' => ['macOS / Linux' => '~/.codeium/windsurf/mcp_config.json'],
            'isShell' => false,
        ],
        'cline' => [
            'code' => $url_only,
            'hint' => __('Add to <code>cline_mcp_settings.json</code>, or use <strong>MCP Servers → Remote Servers</strong> and paste the address.', 'nibwp'),
            'paths' => [],
            'isShell' => false,
        ],
        'roo-code' => [
            'code' => $url_only,
            'hint' => __('Add to <code>mcp_settings.json</code>. If Roo Code asks for a transport type, choose the streamable HTTP option.', 'nibwp'),
            'paths' => [],
            'isShell' => false,
        ],
        'kilo-code' => [
            'code' => $url_only,
            'hint' => __('Add to <code>mcp_settings.json</code>. If Kilo Code asks for a transport type, choose the streamable HTTP option.', 'nibwp'),
            'paths' => [],
            'isShell' => false,
        ],
        'gemini-cli' => [
            'code' => $json(['mcpServers' => [$name => ['httpUrl' => $mcp_url]]]),
            'hint' => __('Add to <code>settings.json</code>.', 'nibwp'),
            'paths' => [
                __('Global', 'nibwp') => '~/.gemini/settings.json',
                __('Project', 'nibwp') => '.gemini/settings.json',
            ],
            'isShell' => false,
        ],
        'opencode' => [
            'code' => $json(['mcp' => [$name => ['type' => 'remote', 'url' => $mcp_url, 'enabled' => true]]]),
            'hint' => __('Add to <code>opencode.json</code>.', 'nibwp'),
            'paths' => [
                __('Project', 'nibwp') => 'opencode.json',
                __('Global', 'nibwp') => '~/.config/opencode/opencode.json',
            ],
            'isShell' => false,
        ],
        'codex' => [
            'code' => 'codex mcp add ' . escapeshellarg($name) . ' --url ' . escapeshellarg($mcp_url)
                . "\ncodex mcp login " . escapeshellarg($name),
            'hint' => __('Run these two lines. The second opens your browser to approve. To do it by hand instead, add the block below to <code>config.toml</code> — merge it into what is already there rather than replacing the file — then restart Codex and press <strong>Authenticate</strong> beside it in the MCP list.', 'nibwp'),
            'paths' => [
                'macOS / Linux' => '~/.codex/config.toml',
                'Windows' => '%USERPROFILE%\\.codex\\config.toml',
            ],
            'isShell' => true,
            'extra' => "[mcp_servers." . $name . "]\nurl = \"" . $mcp_url . "\"",
        ],
        'antigravity' => [
            'code' => $mcp_servers,
            'hint' => __('Add to <code>mcp_config.json</code>, merging it into what is already there. Then open <strong>Settings → Customization → Install MCP servers</strong> and press refresh; your browser opens to approve.', 'nibwp'),
            'paths' => [
                'macOS / Linux' => '~/.gemini/config/mcp_config.json',
                'Windows' => '%USERPROFILE%\\.gemini\\config\\mcp_config.json',
            ],
            'isShell' => false,
        ],
        'other' => [
            'code' => $mcp_url,
            'hint' => __('Any client that supports remote MCP: give it this URL and it will handle sign-in itself.', 'nibwp'),
            'paths' => [],
            'isShell' => false,
        ],
    ];
}

/**
 * @return array<string, string>
 */
function nibwp_oauth_remote_client_labels(): array
{
    return [
        'claude-ai' => 'Claude.ai',
        'claude-desktop' => 'Claude Desktop',
        'chatgpt' => 'ChatGPT',
        'terminal' => __('Terminal', 'nibwp'),
        'claude-code' => 'Claude Code',
        'cursor' => 'Cursor',
        'vscode' => 'VS Code',
        'github-copilot' => 'GitHub Copilot',
        'antigravity' => 'Antigravity',
        'windsurf' => 'Windsurf',
        'cline' => 'Cline',
        'roo-code' => 'Roo Code',
        'kilo-code' => 'Kilo Code',
        'gemini-cli' => 'Gemini CLI',
        'opencode' => 'OpenCode',
        'codex' => 'Codex',
        'other' => __('Anything else', 'nibwp'),
    ];
}

// ---------------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------------

/**
 * The Sign-in pane.
 */
function nibwp_oauth_render_signin_pane(string $mcp_url, string $name): void
{
    $availability = nibwp_oauth_availability();
    $configs = nibwp_oauth_remote_configs($mcp_url, $name);
    $labels = nibwp_oauth_remote_client_labels();
    ?>
    <?php // The step heading lives above the tabs now — see
          // nibwp_oauth_render_connect_tabs(). ?>
    <?php if (!$availability['ok']): ?>
        <div class="nw-oc-warn">
            <strong><?php esc_html_e('Not available on this site yet', 'nibwp'); ?></strong>
            <span><?php echo esc_html($availability['message']); ?></span>
        </div>
    <?php endif; ?>

    <?php // No lead paragraph: the numbered steps below say the same thing, and
          // saying it twice is what made this pane feel like reading rather than
          // connecting. ?>
    <label class="nw-oc-label" for="nw-oc-url"><?php esc_html_e('Your MCP address', 'nibwp'); ?></label>
    <div class="nw-status-copyrow">
        <input type="text" readonly id="nw-oc-url" class="nw-status-copyrow__input" value="<?php echo esc_attr($mcp_url); ?>">
        <?php nibwp_status_copy_button('nw-oc-url', __('Copy', 'nibwp')); ?>
    </div>

    <ol class="nw-oc-steps">
        <li><?php esc_html_e('Paste it into your AI client.', 'nibwp'); ?></li>
        <li><?php esc_html_e('Your browser opens here, already signed in.', 'nibwp'); ?></li>
        <li><?php esc_html_e('Approve what it may do.', 'nibwp'); ?></li>
    </ol>

    <div class="nw-int-tabs-wrap nw-oc-tabs">
        <div class="nw-int-tabs" role="tablist">
            <?php $first = true; ?>
            <?php foreach ($labels as $key => $label): ?>
                <button type="button" class="nw-int-tab<?php echo $first ? ' is-active' : ''; ?>"
                        data-oc-client="<?php echo esc_attr($key); ?>" role="tab"
                        aria-selected="<?php echo $first ? 'true' : 'false'; ?>">
                    <?php echo esc_html($label); ?>
                </button>
                <?php $first = false; ?>
            <?php endforeach; ?>
        </div>
    </div>

    <?php $first = true; ?>
    <?php foreach (array_keys($labels) as $key): ?>
        <?php $cfg = $configs[$key] ?? null; ?>
        <?php if ($cfg === null) { continue; } ?>
        <div class="nw-oc-pane<?php echo $first ? ' is-active' : ''; ?>" data-oc-pane="<?php echo esc_attr($key); ?>">
            <?php if (!empty($cfg['unreachable'])): ?>
                <div class="nw-oc-warn">
                    <strong><?php esc_html_e('Not available for this site', 'nibwp'); ?></strong>
                    <span><?php echo esc_html($cfg['hint']); ?></span>
                </div>
            <?php endif; ?>
            <?php if (!empty($cfg['action'])): ?>
                <p class="nw-oc-act">
                    <?php // esc_url drops unknown schemes, and vscode:/cursor: are the whole point. ?>
                    <a class="nibwp-btn-primary" href="<?php echo esc_url($cfg['action']['url'], ['http', 'https', 'vscode', 'vscode-insiders', 'cursor']); ?>"
                       target="_blank" rel="noopener noreferrer">
                        <?php echo esc_html($cfg['action']['label']); ?>
                    </a>
                </p>
            <?php endif; ?>
            <?php if (empty($cfg['unreachable'])): ?>
                <div class="nibwp-config-block">
                    <pre id="nw-oc-code-<?php echo esc_attr($key); ?>"><?php echo esc_html($cfg['code']); ?></pre>
                    <?php nibwp_status_copy_button('nw-oc-code-' . $key, __('Copy', 'nibwp')); ?>
                </div>
            <?php endif; ?>
            <?php if (empty($cfg['unreachable'])): ?>
                <p class="nw-oc-hint"><?php echo wp_kses($cfg['hint'], ['code' => [], 'strong' => []]); ?></p>
            <?php endif; ?>
            <?php if (!empty($cfg['extra'])): ?>
                <div class="nibwp-config-block nw-oc-extra">
                    <pre id="nw-oc-extra-<?php echo esc_attr($key); ?>"><?php echo esc_html($cfg['extra']); ?></pre>
                    <?php nibwp_status_copy_button('nw-oc-extra-' . $key, __('Copy', 'nibwp')); ?>
                </div>
            <?php endif; ?>
            <?php if ($cfg['paths'] !== []): ?>
                <ul class="nw-oc-paths">
                    <?php foreach ($cfg['paths'] as $label => $path): ?>
                        <li><span><?php echo esc_html($label); ?></span><code><?php echo esc_html($path); ?></code></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php $first = false; ?>
    <?php endforeach; ?>
    <?php
}

/**
 * Manual client IDs — collapsed, because most people never need one.
 */
function nibwp_oauth_render_manual_clients(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $clients = nibwp_oauth_manual_clients();
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only
    // notice state carried back from a nonce-checked redirect.
    $added = isset($_GET['nibwp_client_added']) ? sanitize_text_field(wp_unslash($_GET['nibwp_client_added'])) : '';
    $error = isset($_GET['nibwp_client_error']) ? sanitize_text_field(wp_unslash($_GET['nibwp_client_error'])) : '';
    $deleted = isset($_GET['nibwp_client_deleted']);
    // phpcs:enable

    $messages = [
        'name' => __('Give the application a name so you can recognise it later.', 'nibwp'),
        'uris' => __('At least one return address is required.', 'nibwp'),
        'invalid' => __('Return addresses must be https, a loopback address, or the application\'s own scheme.', 'nibwp'),
    ];
    ?>
    <div class="nibwp-connect-section" id="nibwp-oauth-manual">
        <details class="nw-oc-manual"<?php echo ($added !== '' || $error !== '' || $clients !== []) ? ' open' : ''; ?>>
            <summary class="nw-oc-manual__summary">
                <span><?php esc_html_e('Create a client ID by hand', 'nibwp'); ?></span>
                <span class="nw-oc-manual__chev" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span>
            </summary>

            <p class="nw-oc-lead">
                <?php esc_html_e('Most clients register themselves and you never see a client ID. Make one here only if your AI client asks for one, or if its registration request is being blocked before it reaches this site.', 'nibwp'); ?>
            </p>

            <?php if ($deleted): ?>
                <div class="notice notice-success inline"><p><?php esc_html_e('Client ID deleted.', 'nibwp'); ?></p></div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="notice notice-error inline"><p><?php echo esc_html($messages[$error] ?? __('That could not be saved.', 'nibwp')); ?></p></div>
            <?php endif; ?>

            <?php if ($added !== ''): ?>
                <label class="nw-oc-label" for="nw-oc-new-client"><?php esc_html_e('Your new client ID', 'nibwp'); ?></label>
                <div class="nw-status-copyrow">
                    <input type="text" readonly id="nw-oc-new-client" class="nw-status-copyrow__input" value="<?php echo esc_attr($added); ?>">
                    <?php nibwp_status_copy_button('nw-oc-new-client', __('Copy', 'nibwp')); ?>
                </div>
                <p class="nw-oc-hint"><?php esc_html_e('Paste this where the client asks for a client ID. Leave any client secret field empty — this site issues public clients, which is what the specification requires for applications that cannot keep a secret.', 'nibwp'); ?></p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="nw-oc-manual__form">
                <?php wp_nonce_field('nibwp_oauth_add_client'); ?>
                <input type="hidden" name="action" value="nibwp_oauth_add_client">

                <label class="nw-oc-label" for="nw-oc-client-name"><?php esc_html_e('Application name', 'nibwp'); ?></label>
                <input type="text" id="nw-oc-client-name" name="client_name" class="regular-text" placeholder="<?php esc_attr_e('My AI client', 'nibwp'); ?>">

                <label class="nw-oc-label" for="nw-oc-client-uris"><?php esc_html_e('Return addresses', 'nibwp'); ?></label>
                <textarea id="nw-oc-client-uris" name="redirect_uris" rows="3" class="large-text code" placeholder="https://example.com/oauth/callback"></textarea>
                <p class="nw-oc-hint"><?php esc_html_e('One per line. The client documentation calls this the redirect URI or callback URL. Sign-in is refused for any address not listed here, which is what stops an approved sign-in being sent somewhere else.', 'nibwp'); ?></p>

                <p class="nw-oc-act">
                    <button type="submit" class="nibwp-btn-primary"><?php esc_html_e('Create client ID', 'nibwp'); ?></button>
                </p>
            </form>

            <?php if ($clients !== []): ?>
                <div class="nw-oc-conns">
                    <?php foreach ($clients as $client): ?>
                        <div class="nw-oc-conn">
                            <div class="nw-oc-conn__main">
                                <strong><?php echo esc_html($client['name']); ?></strong>
                                <code class="nw-oc-conn__meta"><?php echo esc_html($client['id']); ?></code>
                                <span class="nw-oc-conn__meta"><?php echo esc_html(implode(', ', $client['redirect_uris'])); ?></span>
                            </div>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="nw-oc-conn__act">
                                <?php wp_nonce_field('nibwp_oauth_delete_client_' . $client['id']); ?>
                                <input type="hidden" name="action" value="nibwp_oauth_delete_client">
                                <input type="hidden" name="client_id" value="<?php echo esc_attr($client['id']); ?>">
                                <button type="submit" class="nibwp-btn-ghost"><?php esc_html_e('Delete', 'nibwp'); ?></button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </details>
    </div>
    <?php
}

/**
 * The connections list.
 */
function nibwp_oauth_render_connections(): void
{
    $connections = nibwp_oauth_user_connections();
    if ($connections === []) {
        return;
    }

    $catalogue = nibwp_oauth_scopes();
    ?>
    <div class="nibwp-connect-section">
        <h2 class="nibwp-step-heading"><?php esc_html_e('Connected applications', 'nibwp'); ?></h2>
        <p class="nw-oc-lead"><?php esc_html_e('Everything currently signed in to this site as you. Revoking takes effect on the next request the application makes.', 'nibwp'); ?></p>

        <div class="nw-oc-conns">
            <?php foreach ($connections as $conn): ?>
                <div class="nw-oc-conn">
                    <div class="nw-oc-conn__main">
                        <strong><?php echo esc_html($conn['name']); ?></strong>
                        <span class="nw-oc-conn__meta">
                            <?php
                            printf(
                                /* translators: 1: how long ago it was connected, 2: last used */
                                esc_html__('Connected %1$s · %2$s', 'nibwp'),
                                esc_html(human_time_diff($conn['created']) . __(' ago', 'nibwp')),
                                $conn['last_used'] > 0
                                    ? esc_html(sprintf(
                                        /* translators: %s: human time difference */
                                        __('last used %s ago', 'nibwp'),
                                        human_time_diff($conn['last_used'])
                                    ))
                                    : esc_html__('never used', 'nibwp')
                            );
                            ?>
                        </span>
                        <span class="nw-oc-chips">
                            <?php foreach ($conn['scopes'] as $slug): ?>
                                <?php $meta = $catalogue[$slug] ?? null; ?>
                                <span class="nw-oc-chip<?php echo ($meta && $meta['danger']) ? ' is-danger' : ''; ?>">
                                    <?php echo esc_html($meta['label'] ?? $slug); ?>
                                </span>
                            <?php endforeach; ?>
                        </span>
                    </div>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="nw-oc-conn__act">
                        <?php wp_nonce_field('nibwp_oauth_revoke_' . $conn['id']); ?>
                        <input type="hidden" name="action" value="nibwp_oauth_revoke">
                        <input type="hidden" name="token_id" value="<?php echo esc_attr($conn['id']); ?>">
                        <button type="submit" class="nibwp-btn-ghost nibwp-btn-danger"><?php esc_html_e('Revoke', 'nibwp'); ?></button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

/**
 * Tab strip + panes wrapping both connection methods.
 *
 * @param callable $render_password_pane
 */
function nibwp_oauth_render_connect_tabs(
    string $mcp_url,
    string $name,
    callable $render_password_pane,
    string $open = 'signin'
): void {
    // Which pane the page opens on. Sign-in is the recommendation and so the
    // default, but the password form posts back to this same screen: opening on
    // sign-in after it meant the password you just generated — and the config
    // built from it — rendered inside a hidden pane. It read as the page
    // throwing the work away and putting you back at the start.
    $password_first = $open === 'password';
    ?>
    <div class="nw-oc" id="nw-oc">
        <?php // One heading for the step, above the choice it describes. Each
              // pane used to carry its own, so the page numbered the same step
              // twice and the heading changed when you switched tabs. ?>
        <h2 class="nibwp-step-heading">
            <span class="nibwp-step-badge">2</span>
            <?php esc_html_e('Choose how to connect', 'nibwp'); ?>
            <span class="nw-tooltip" data-tip="<?php esc_attr_e('Sign-in asks your approval and hands over no password. A password gives full access and never expires.', 'nibwp'); ?>">?</span>
        </h2>

        <div class="nw-int-tabs-wrap nw-oc-methods">
            <div class="nw-int-tabs" role="tablist">
                <button type="button" class="nw-int-tab<?php echo $password_first ? '' : ' is-active'; ?>" data-oc-method="signin" role="tab" aria-selected="<?php echo $password_first ? 'false' : 'true'; ?>">
                    <?php esc_html_e('OAuth', 'nibwp'); ?>
                    <span class="nw-oc-rec"><?php esc_html_e('Recommended', 'nibwp'); ?></span>
                </button>
                <button type="button" class="nw-int-tab<?php echo $password_first ? ' is-active' : ''; ?>" data-oc-method="password" role="tab" aria-selected="<?php echo $password_first ? 'true' : 'false'; ?>">
                    <?php esc_html_e('Application password', 'nibwp'); ?>
                </button>
            </div>
        </div>

        <div class="nw-oc-method<?php echo $password_first ? '' : ' is-active'; ?>" data-oc-method-pane="signin">
            <?php nibwp_oauth_render_signin_pane($mcp_url, $name); ?>
        </div>

        <div class="nw-oc-method<?php echo $password_first ? ' is-active' : ''; ?>" data-oc-method-pane="password">
            <?php $render_password_pane(); ?>
        </div>
    </div>

    <script>
    (function () {
        var root = document.getElementById('nw-oc');
        if (!root) { return; }

        function switchGroup(btnAttr, paneAttr, btn) {
            var key = btn.getAttribute(btnAttr);
            root.querySelectorAll('[' + btnAttr + ']').forEach(function (b) {
                var on = b === btn;
                b.classList.toggle('is-active', on);
                b.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            root.querySelectorAll('[' + paneAttr + ']').forEach(function (p) {
                p.classList.toggle('is-active', p.getAttribute(paneAttr) === key);
            });
        }

        root.addEventListener('click', function (e) {
            if (!e.target.closest) { return; }
            var method = e.target.closest('[data-oc-method]');
            if (method) { switchGroup('data-oc-method', 'data-oc-method-pane', method); return; }
            var client = e.target.closest('[data-oc-client]');
            if (client) { switchGroup('data-oc-client', 'data-oc-pane', client); }
        });

        // Hover-to-scroll on the client strip, matching the Integrations page:
        // the pointer's distance from centre sets direction and speed, so a long
        // list is reachable without a scrollbar or a drag.
        root.querySelectorAll('.nw-oc-tabs').forEach(function (wrap) {
            var strip = wrap.querySelector('.nw-int-tabs');
            if (!strip) { return; }

            var rafId = null;
            var velocity = 0;
            var DEAD_ZONE = 0.05;   // centre 10% holds still
            var MAX_SPEED = 38;     // pixels per frame at the very edge

            function shadows() {
                var max = strip.scrollWidth - strip.clientWidth;
                wrap.classList.toggle('has-scroll-left', strip.scrollLeft > 2);
                wrap.classList.toggle('has-scroll-right', strip.scrollLeft < max - 2);
            }

            function step() {
                if (Math.abs(velocity) < 0.1) { rafId = null; return; }
                strip.scrollLeft += velocity;
                shadows();
                rafId = requestAnimationFrame(step);
            }

            wrap.addEventListener('mousemove', function (e) {
                var rect = wrap.getBoundingClientRect();
                var offset = ((e.clientX - rect.left) / rect.width) * 2 - 1;

                if (Math.abs(offset) < DEAD_ZONE) {
                    velocity = 0;
                } else {
                    var sign = offset < 0 ? -1 : 1;
                    velocity = sign * MAX_SPEED * ((Math.abs(offset) - DEAD_ZONE) / (1 - DEAD_ZONE));
                }

                if (velocity !== 0 && rafId === null) {
                    rafId = requestAnimationFrame(step);
                }
            });

            wrap.addEventListener('mouseleave', function () { velocity = 0; });
            strip.addEventListener('scroll', shadows);
            window.addEventListener('resize', shadows);
            shadows();
        });

        // Copy buttons are the Status page component; mirror its handler so the
        // icon survives the confirmation.
        root.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('.nw-status-copy') : null;
            if (!btn) { return; }
            var field = document.getElementById(btn.getAttribute('data-target'));
            if (!field) { return; }
            var label = btn.querySelector('.nw-status-copy__label');
            var text = field.value !== undefined ? field.value : field.textContent;
            var done = function () {
                if (!label || btn.classList.contains('is-copied')) { return; }
                var original = label.textContent;
                btn.classList.add('is-copied');
                label.textContent = <?php echo wp_json_encode(__('Copied', 'nibwp')); ?>;
                setTimeout(function () { label.textContent = original; btn.classList.remove('is-copied'); }, 1600);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done, done);
            } else if (field.select) {
                field.select();
                document.execCommand('copy');
                done();
            }
        });
    })();
    </script>
    <?php
}
