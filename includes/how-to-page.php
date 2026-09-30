<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * AJAX handler — sends a support request email.
 */
function nibwp_handle_send_support(): void
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Insufficient permissions.', 'nibwp')], 403);
    }
    if (!check_ajax_referer('nibwp_support', '_wpnonce', false)) {
        wp_send_json_error(['message' => __('Invalid security token.', 'nibwp')], 403);
    }

    $topic = isset($_POST['topic']) ? sanitize_text_field(wp_unslash($_POST['topic'])) : '';
    $subject = isset($_POST['subject']) ? sanitize_text_field(wp_unslash($_POST['subject'])) : '';
    $message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';
    $reply_to = isset($_POST['reply_to']) ? sanitize_email(wp_unslash($_POST['reply_to'])) : '';
    $include_diag = !empty($_POST['include_diagnostics']);

    if ($topic === '' || $subject === '' || $message === '' || !is_email($reply_to)) {
        wp_send_json_error(['message' => __('Please fill all required fields with valid values.', 'nibwp')], 400);
    }

    $support_email = nibwp_support_email();
    $body = "Topic: {$topic}\nFrom: {$reply_to}\nSite: " . home_url() . "\n\n----\n\n{$message}";

    if ($include_diag) {
        global $wp_version;
        $active_integrations = (array) get_option('nibwp_enabled_integrations', []);
        $integration_keys = array_keys(array_filter($active_integrations));
        $diag = "\n\n---- DIAGNOSTICS ----\n";
        $diag .= "NIBWP: " . (defined('NIBWP_VERSION') ? NIBWP_VERSION : 'unknown') . "\n";
        $diag .= "WordPress: {$wp_version}\n";
        $diag .= "PHP: " . PHP_VERSION . "\n";
        $diag .= "Locale: " . get_locale() . "\n";
        $diag .= "Site URL: " . home_url() . "\n";
        $diag .= "MCP enabled: " . (function_exists('nibwp_is_enabled') && nibwp_is_enabled() ? 'yes' : 'no') . "\n";
        $diag .= "Active integrations: " . (count($integration_keys) > 0 ? implode(', ', $integration_keys) : 'none') . "\n";
        $body .= $diag;
    }

    $headers = [
        'Content-Type: text/plain; charset=UTF-8',
        'Reply-To: ' . $reply_to,
    ];

    $sent = wp_mail($support_email, '[NIBWP Support] ' . $subject, $body, $headers);

    if ($sent) {
        wp_send_json_success(['message' => __('Sent.', 'nibwp')]);
    }
    wp_send_json_error([
        'message' => sprintf(
            /* translators: %s: support email address */
            __('Could not send email. Please email us directly at %s', 'nibwp'),
            $support_email
        ),
    ], 500);
}
add_action('wp_ajax_nibwp_send_support', 'nibwp_handle_send_support');

/**
 * Interactive How-To page — walks users through NIBWP setup, IDE config,
 * and daily usage with step-by-step instructions, code snippets, and CTAs.
 */
function nibwp_render_how_to_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $username = wp_get_current_user()->user_login;
    $rest_url = rest_url('mcp/nibwp');
    $mcp_name = function_exists('nibwp_get_mcp_server_name_default') ? nibwp_get_mcp_server_name_default() : 'nibwp-' . (string) wp_parse_url(home_url(), PHP_URL_HOST);
    $is_enabled = function_exists('nibwp_is_enabled') ? nibwp_is_enabled() : false;
    $pw_count = function_exists('nibwp_get_mcp_passwords') ? count(nibwp_get_mcp_passwords()) : 0;

    nibwp_render_admin_header();
    ?>
    <div class="wrap nibwp-wrap">
        <div class="nibwp-page-header">
            <div>
                <h1><?php esc_html_e('How to use NIBWP', 'nibwp'); ?></h1>
                <p class="nibwp-subtitle"><?php esc_html_e('Interactive walkthrough — from zero to your first AI-powered WordPress edit in under 5 minutes.', 'nibwp'); ?></p>
            </div>
            <div class="nibwp-page-actions">
                <button type="button" class="button" id="nw-howto-reset" title="<?php esc_attr_e('Reset progress', 'nibwp'); ?>">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px; margin-inline-end:4px;"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 102.13-9.36L1 10"/></svg>
                    <?php esc_html_e('Reset Progress', 'nibwp'); ?>
                </button>
            </div>
        </div>

        <!-- Progress tracker -->
        <div class="nw-howto-progress">
            <div class="nw-howto-progress__bar">
                <div class="nw-howto-progress__fill" id="nw-progress-fill"></div>
            </div>
            <div class="nw-howto-progress__text">
                <span id="nw-progress-text"><?php
                    printf(
                        /* translators: 1: number of completed steps, 2: total number of steps */
                        esc_html(_n('%1$s of %2$s step completed', '%1$s of %2$s steps completed', 7, 'nibwp')),
                        esc_html(number_format_i18n(0)),
                        esc_html(number_format_i18n(7))
                    );
                ?></span>
                <span class="nw-howto-progress__percent" id="nw-progress-percent">0%</span>
            </div>
        </div>

        <!-- Two-column layout: chapter nav + content -->
        <div class="nw-howto-grid">
            <!-- Chapter nav -->
            <aside class="nw-howto-nav">
                <div class="nw-howto-nav__title"><?php esc_html_e('CHAPTERS', 'nibwp'); ?></div>
                <a class="nw-howto-nav-item is-active" data-chapter="welcome" href="#welcome">
                    <div class="nw-howto-nav-num">1</div>
                    <div>
                        <strong><?php esc_html_e('Welcome', 'nibwp'); ?></strong>
                        <span><?php esc_html_e('What NIBWP does', 'nibwp'); ?></span>
                    </div>
                </a>
                <a class="nw-howto-nav-item" data-chapter="enable" href="#enable">
                    <div class="nw-howto-nav-num">2</div>
                    <div>
                        <strong><?php esc_html_e('Enable MCP', 'nibwp'); ?></strong>
                        <span><?php esc_html_e('Turn on AI Abilities', 'nibwp'); ?></span>
                    </div>
                </a>
                <a class="nw-howto-nav-item" data-chapter="password" href="#password">
                    <div class="nw-howto-nav-num">3</div>
                    <div>
                        <strong><?php esc_html_e('Create Password', 'nibwp'); ?></strong>
                        <span><?php esc_html_e('Generate credentials', 'nibwp'); ?></span>
                    </div>
                </a>
                <a class="nw-howto-nav-item" data-chapter="ide" href="#ide">
                    <div class="nw-howto-nav-num">4</div>
                    <div>
                        <strong><?php esc_html_e('Configure IDE', 'nibwp'); ?></strong>
                        <span><?php esc_html_e('Connect Claude, Cursor, etc.', 'nibwp'); ?></span>
                    </div>
                </a>
                <a class="nw-howto-nav-item" data-chapter="first-chat" href="#first-chat">
                    <div class="nw-howto-nav-num">5</div>
                    <div>
                        <strong><?php esc_html_e('First Conversation', 'nibwp'); ?></strong>
                        <span><?php esc_html_e('Try sample prompts', 'nibwp'); ?></span>
                    </div>
                </a>
                <a class="nw-howto-nav-item" data-chapter="workflows" href="#workflows">
                    <div class="nw-howto-nav-num">6</div>
                    <div>
                        <strong><?php esc_html_e('Workflows', 'nibwp'); ?></strong>
                        <span><?php esc_html_e('Pin & auto-route playbooks', 'nibwp'); ?></span>
                    </div>
                </a>
                <a class="nw-howto-nav-item" data-chapter="best-practices" href="#best-practices">
                    <div class="nw-howto-nav-num">7</div>
                    <div>
                        <strong><?php esc_html_e('Best Practices', 'nibwp'); ?></strong>
                        <span><?php esc_html_e('Safety & productivity', 'nibwp'); ?></span>
                    </div>
                </a>
                <a class="nw-howto-nav-item" data-chapter="troubleshoot" href="#troubleshoot">
                    <div class="nw-howto-nav-num">?</div>
                    <div>
                        <strong><?php esc_html_e('Troubleshooting', 'nibwp'); ?></strong>
                        <span><?php esc_html_e('Common issues', 'nibwp'); ?></span>
                    </div>
                </a>
            </aside>

            <!-- Content -->
            <div class="nw-howto-content">

                <!-- CHAPTER 1: Welcome -->
                <section class="nw-chapter is-active" id="ch-welcome" data-chapter="welcome">
                    <div class="nw-chapter__badge"><?php esc_html_e('CHAPTER 1', 'nibwp'); ?></div>
                    <h2><?php esc_html_e('What is NIBWP?', 'nibwp'); ?></h2>
                    <p class="nw-chapter__lead">
                        <?php esc_html_e('NIBWP turns your WordPress site into an MCP server. AI agents like Claude, ChatGPT, or Cursor can read, write, and manage your site through natural language — no code edits, no FTP, no admin clicks.', 'nibwp'); ?>
                    </p>

                    <div class="nw-howto-cards">
                        <div class="nw-howto-card">
                            <div class="nw-howto-card__icon" style="background:var(--nw-brand-soft); color:var(--nw-brand);">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                            </div>
                            <strong><?php esc_html_e('143 AI tools', 'nibwp'); ?></strong>
                            <span><?php esc_html_e('Posts, pages, products, users, SEO, security, integrations.', 'nibwp'); ?></span>
                        </div>
                        <div class="nw-howto-card">
                            <div class="nw-howto-card__icon" style="background:var(--nw-ok-soft); color:var(--nw-ok);">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                            </div>
                            <strong><?php esc_html_e('Secure by design', 'nibwp'); ?></strong>
                            <span><?php esc_html_e('Domain-locked, rate-limited, audit-logged, sandbox-isolated.', 'nibwp'); ?></span>
                        </div>
                        <div class="nw-howto-card">
                            <div class="nw-howto-card__icon" style="background:var(--nw-warn-soft); color:var(--nw-warn);">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 1v6m0 10v6m-7-11h6m4 0h6"/></svg>
                            </div>
                            <strong><?php esc_html_e('15+ AI clients', 'nibwp'); ?></strong>
                            <span><?php esc_html_e('Claude Desktop, Cursor, VS Code, Windsurf, Cline, more.', 'nibwp'); ?></span>
                        </div>
                    </div>

                    <div class="nw-callout nw-callout--info">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                        <div>
                            <strong><?php esc_html_e('Best on staging or development sites', 'nibwp'); ?></strong>
                            <p><?php esc_html_e('AI agents can execute PHP and modify files. Use a staging site or sandbox — never on live production without backups.', 'nibwp'); ?></p>
                        </div>
                    </div>

                    <div class="nw-step-foot">
                        <label class="nw-step-check">
                            <input type="checkbox" data-step="welcome">
                            <span><?php esc_html_e('Got it — let\'s start', 'nibwp'); ?></span>
                        </label>
                        <button type="button" class="button button-primary nw-next-chapter" data-next="enable">
                            <?php esc_html_e('Next: Enable MCP', 'nibwp'); ?>
                            <svg class="nw-rtl-flip" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px; margin-inline-start:4px;"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                        </button>
                    </div>
                </section>

                <!-- CHAPTER 2: Enable MCP -->
                <section class="nw-chapter" id="ch-enable" data-chapter="enable">
                    <div class="nw-chapter__badge"><?php esc_html_e('CHAPTER 2', 'nibwp'); ?></div>
                    <h2><?php esc_html_e('Enable AI Abilities', 'nibwp'); ?></h2>
                    <p class="nw-chapter__lead">
                        <?php esc_html_e('The MCP server is off by default. Flip it on to expose the AI endpoint at this URL:', 'nibwp'); ?>
                    </p>

                    <div class="nw-code-block">
                        <pre><code id="nw-rest-url"><?php echo esc_html($rest_url); ?></code></pre>
                        <button type="button" class="nw-copy-btn" data-copy="nw-rest-url">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                            <?php esc_html_e('Copy', 'nibwp'); ?>
                        </button>
                    </div>

                    <h3><?php esc_html_e('Steps', 'nibwp'); ?></h3>
                    <ol class="nw-step-list">
                        <li>
                            <strong><?php esc_html_e('Go to Connect', 'nibwp'); ?></strong>
                            <span><?php esc_html_e('Use the sidebar navigation', 'nibwp'); ?></span>
                        </li>
                        <li>
                            <strong><?php esc_html_e('Toggle "Enable AI Abilities"', 'nibwp'); ?></strong>
                            <span><?php esc_html_e('This activates the MCP endpoint and locks it to your current domain', 'nibwp'); ?></span>
                        </li>
                        <li>
                            <strong><?php esc_html_e('Confirm production warning if shown', 'nibwp'); ?></strong>
                            <span><?php esc_html_e('NIBWP detects production-like hostnames and asks for confirmation', 'nibwp'); ?></span>
                        </li>
                    </ol>

                    <div class="nw-status-row">
                        <span><?php esc_html_e('Current status:', 'nibwp'); ?></span>
                        <?php if ($is_enabled): ?>
                            <span class="nibwp-badge is-ok" style="padding:4px 10px;">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-2px; margin-inline-end:3px;"><polyline points="20 6 9 17 4 12"/></svg>
                                <?php esc_html_e('MCP is Active', 'nibwp'); ?>
                            </span>
                        <?php else: ?>
                            <span class="nibwp-badge is-warn" style="padding:4px 10px;">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-2px; margin-inline-end:3px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/></svg>
                                <?php esc_html_e('MCP is Off', 'nibwp'); ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="nw-step-foot">
                        <label class="nw-step-check">
                            <input type="checkbox" data-step="enable" <?php echo $is_enabled ? 'checked' : ''; ?>>
                            <span><?php esc_html_e('I enabled AI Abilities', 'nibwp'); ?></span>
                        </label>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=nibwp-connect')); ?>" class="button button-primary">
                            <?php esc_html_e('Open Connect Page', 'nibwp'); ?>
                            <svg class="nw-rtl-flip" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px; margin-inline-start:4px;"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                        </a>
                    </div>
                </section>

                <!-- CHAPTER 3: Password -->
                <section class="nw-chapter" id="ch-password" data-chapter="password">
                    <div class="nw-chapter__badge"><?php esc_html_e('CHAPTER 3', 'nibwp'); ?></div>
                    <h2><?php esc_html_e('Generate an Application Password', 'nibwp'); ?></h2>
                    <p class="nw-chapter__lead">
                        <?php esc_html_e('AI clients authenticate using a WordPress Application Password — a separate credential from your login. It gives the AI scoped access without exposing your main password.', 'nibwp'); ?>
                    </p>

                    <div class="nw-callout nw-callout--warn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        <div>
                            <strong><?php esc_html_e('Save the password immediately', 'nibwp'); ?></strong>
                            <p><?php esc_html_e('WordPress only shows the password once on creation. After that, you can revoke it but not recover it. Paste it into your IDE config or password manager right away.', 'nibwp'); ?></p>
                        </div>
                    </div>

                    <h3><?php esc_html_e('Steps', 'nibwp'); ?></h3>
                    <ol class="nw-step-list">
                        <li>
                            <strong><?php esc_html_e('On the Connect page, click "Generate Application Password"', 'nibwp'); ?></strong>
                            <span><?php esc_html_e('Optionally give it a custom name like "Claude on laptop"', 'nibwp'); ?></span>
                        </li>
                        <li>
                            <strong><?php esc_html_e('Copy the password value', 'nibwp'); ?></strong>
                            <span><?php esc_html_e('It looks like: xxxx xxxx xxxx xxxx xxxx xxxx', 'nibwp'); ?></span>
                        </li>
                        <li>
                            <strong><?php esc_html_e('Keep it secret', 'nibwp'); ?></strong>
                            <span><?php esc_html_e('Anyone with this password can control your WordPress site through MCP', 'nibwp'); ?></span>
                        </li>
                    </ol>

                    <div class="nw-status-row">
                        <span><?php esc_html_e('App passwords for NIBWP:', 'nibwp'); ?></span>
                        <span class="nibwp-badge <?php echo $pw_count > 0 ? 'is-ok' : 'is-muted'; ?>" style="padding:4px 10px;">
                            <?php
                            /* translators: %d: number of application passwords */
                            printf(esc_html(_n('%d password', '%d passwords', $pw_count, 'nibwp')), $pw_count);
                            ?>
                        </span>
                    </div>

                    <div class="nw-step-foot">
                        <label class="nw-step-check">
                            <input type="checkbox" data-step="password" <?php echo $pw_count > 0 ? 'checked' : ''; ?>>
                            <span><?php esc_html_e('I have a password ready', 'nibwp'); ?></span>
                        </label>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=nibwp-connect')); ?>" class="button button-primary">
                            <?php esc_html_e('Generate Password', 'nibwp'); ?>
                        </a>
                    </div>
                </section>

                <!-- CHAPTER 4: IDE Configuration -->
                <section class="nw-chapter" id="ch-ide" data-chapter="ide">
                    <div class="nw-chapter__badge"><?php esc_html_e('CHAPTER 4', 'nibwp'); ?></div>
                    <h2><?php esc_html_e('Configure Your AI Client', 'nibwp'); ?></h2>
                    <p class="nw-chapter__lead">
                        <?php esc_html_e('Pick your client below to see the exact config file path and JSON to paste.', 'nibwp'); ?>
                    </p>

                    <!-- Client tabs -->
                    <div class="nw-ide-tabs">
                        <button type="button" class="nw-ide-tab is-active" data-ide="claude-desktop">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg>
                            <?php esc_html_e('Claude Desktop', 'nibwp'); ?>
                        </button>
                        <button type="button" class="nw-ide-tab" data-ide="claude-code"><?php esc_html_e('Claude Code', 'nibwp'); ?></button>
                        <button type="button" class="nw-ide-tab" data-ide="cursor"><?php esc_html_e('Cursor', 'nibwp'); ?></button>
                        <button type="button" class="nw-ide-tab" data-ide="vscode"><?php esc_html_e('VS Code', 'nibwp'); ?></button>
                        <button type="button" class="nw-ide-tab" data-ide="windsurf"><?php esc_html_e('Windsurf', 'nibwp'); ?></button>
                        <button type="button" class="nw-ide-tab" data-ide="cline"><?php esc_html_e('Cline', 'nibwp'); ?></button>
                    </div>

                    <!-- Claude Desktop -->
                    <div class="nw-ide-panel is-active" data-ide="claude-desktop">
                        <h4><?php esc_html_e('1. Locate the config file', 'nibwp'); ?></h4>
                        <ul class="nw-path-list">
                            <li><strong>macOS</strong> <code>~/Library/Application Support/Claude/claude_desktop_config.json</code></li>
                            <li><strong>Windows</strong> <code>%APPDATA%\Claude\claude_desktop_config.json</code></li>
                        </ul>

                        <h4><?php esc_html_e('2. Add this entry', 'nibwp'); ?></h4>
                        <div class="nw-code-block">
                            <pre><code id="nw-claude-desktop-cfg">{
  "mcpServers": {
    "<?php echo esc_html($mcp_name); ?>": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "<?php echo esc_html($rest_url); ?>",
        "WP_API_USERNAME": "<?php echo esc_html($username); ?>",
        "WP_API_PASSWORD": "YOUR-APP-PASSWORD"
      }
    }
  }
}</code></pre>
                            <button type="button" class="nw-copy-btn" data-copy="nw-claude-desktop-cfg">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                                <?php esc_html_e('Copy', 'nibwp'); ?>
                            </button>
                        </div>

                        <h4><?php esc_html_e('3. Replace YOUR-APP-PASSWORD', 'nibwp'); ?></h4>
                        <p class="nw-text-muted"><?php esc_html_e('Paste the password you generated in Chapter 3.', 'nibwp'); ?></p>

                        <h4><?php esc_html_e('4. Restart Claude Desktop', 'nibwp'); ?></h4>
                        <p class="nw-text-muted"><?php esc_html_e('Fully quit and reopen for the MCP server to register.', 'nibwp'); ?></p>
                    </div>

                    <!-- Claude Code -->
                    <div class="nw-ide-panel" data-ide="claude-code">
                        <h4><?php esc_html_e('Run this in your terminal', 'nibwp'); ?></h4>
                        <div class="nw-code-block">
                            <pre><code id="nw-claude-code-cfg">claude mcp add '<?php echo esc_html($mcp_name); ?>' \
  --env WP_API_URL='<?php echo esc_html($rest_url); ?>' \
  --env WP_API_USERNAME='<?php echo esc_html($username); ?>' \
  --env WP_API_PASSWORD='YOUR-APP-PASSWORD' \
  -- npx -y @automattic/mcp-wordpress-remote@latest</code></pre>
                            <button type="button" class="nw-copy-btn" data-copy="nw-claude-code-cfg">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                                <?php esc_html_e('Copy', 'nibwp'); ?>
                            </button>
                        </div>
                        <p class="nw-text-muted"><?php esc_html_e('Replace YOUR-APP-PASSWORD before running. Then verify with: claude mcp list', 'nibwp'); ?></p>
                    </div>

                    <!-- Cursor -->
                    <div class="nw-ide-panel" data-ide="cursor">
                        <h4><?php esc_html_e('Edit ~/.cursor/mcp.json', 'nibwp'); ?></h4>
                        <div class="nw-code-block">
                            <pre><code id="nw-cursor-cfg">{
  "mcpServers": {
    "<?php echo esc_html($mcp_name); ?>": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "<?php echo esc_html($rest_url); ?>",
        "WP_API_USERNAME": "<?php echo esc_html($username); ?>",
        "WP_API_PASSWORD": "YOUR-APP-PASSWORD"
      }
    }
  }
}</code></pre>
                            <button type="button" class="nw-copy-btn" data-copy="nw-cursor-cfg">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                                <?php esc_html_e('Copy', 'nibwp'); ?>
                            </button>
                        </div>
                        <p class="nw-text-muted"><?php esc_html_e('Settings → MCP → Edit JSON → paste → restart Cursor', 'nibwp'); ?></p>
                    </div>

                    <!-- VS Code -->
                    <div class="nw-ide-panel" data-ide="vscode">
                        <h4><?php esc_html_e('Workspace: .vscode/mcp.json', 'nibwp'); ?></h4>
                        <div class="nw-code-block">
                            <pre><code id="nw-vscode-cfg">{
  "servers": {
    "<?php echo esc_html($mcp_name); ?>": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "<?php echo esc_html($rest_url); ?>",
        "WP_API_USERNAME": "<?php echo esc_html($username); ?>",
        "WP_API_PASSWORD": "YOUR-APP-PASSWORD"
      }
    }
  }
}</code></pre>
                            <button type="button" class="nw-copy-btn" data-copy="nw-vscode-cfg">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                                <?php esc_html_e('Copy', 'nibwp'); ?>
                            </button>
                        </div>
                        <p class="nw-text-muted"><?php esc_html_e('Run MCP: Open User Configuration from the command palette for a user-wide config.', 'nibwp'); ?></p>
                    </div>

                    <!-- Windsurf -->
                    <div class="nw-ide-panel" data-ide="windsurf">
                        <h4><?php esc_html_e('Edit ~/.codeium/windsurf/mcp_config.json', 'nibwp'); ?></h4>
                        <div class="nw-code-block">
                            <pre><code id="nw-windsurf-cfg">{
  "mcpServers": {
    "<?php echo esc_html($mcp_name); ?>": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "<?php echo esc_html($rest_url); ?>",
        "WP_API_USERNAME": "<?php echo esc_html($username); ?>",
        "WP_API_PASSWORD": "YOUR-APP-PASSWORD"
      }
    }
  }
}</code></pre>
                            <button type="button" class="nw-copy-btn" data-copy="nw-windsurf-cfg">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                                <?php esc_html_e('Copy', 'nibwp'); ?>
                            </button>
                        </div>
                    </div>

                    <!-- Cline -->
                    <div class="nw-ide-panel" data-ide="cline">
                        <h4><?php esc_html_e('Cline sidebar → MCP Servers → Configure', 'nibwp'); ?></h4>
                        <div class="nw-code-block">
                            <pre><code id="nw-cline-cfg">{
  "mcpServers": {
    "<?php echo esc_html($mcp_name); ?>": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "<?php echo esc_html($rest_url); ?>",
        "WP_API_USERNAME": "<?php echo esc_html($username); ?>",
        "WP_API_PASSWORD": "YOUR-APP-PASSWORD"
      }
    }
  }
}</code></pre>
                            <button type="button" class="nw-copy-btn" data-copy="nw-cline-cfg">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                                <?php esc_html_e('Copy', 'nibwp'); ?>
                            </button>
                        </div>
                    </div>

                    <div class="nw-step-foot">
                        <label class="nw-step-check">
                            <input type="checkbox" data-step="ide">
                            <span><?php esc_html_e('I configured my IDE', 'nibwp'); ?></span>
                        </label>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=nibwp-connect')); ?>" class="button">
                            <?php esc_html_e('See all 15 clients', 'nibwp'); ?>
                        </a>
                    </div>
                </section>

                <!-- CHAPTER 5: First Conversation -->
                <section class="nw-chapter" id="ch-first-chat" data-chapter="first-chat">
                    <div class="nw-chapter__badge"><?php esc_html_e('CHAPTER 5', 'nibwp'); ?></div>
                    <h2><?php esc_html_e('Your first AI conversation', 'nibwp'); ?></h2>
                    <p class="nw-chapter__lead">
                        <?php esc_html_e('Once your IDE is connected, try these sample prompts to verify everything works. Click any prompt to copy it.', 'nibwp'); ?>
                    </p>

                    <div class="nw-prompts">
                        <button type="button" class="nw-prompt" data-prompt="<?php /* translators: a prompt the reader copies into their AI chat */ esc_attr_e('List the 5 most recent posts on this site with their titles and publish dates.', 'nibwp'); ?>">
                            <div class="nw-prompt__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
                            <div>
                                <strong><?php esc_html_e('Read recent posts', 'nibwp'); ?></strong>
                                <span><?php esc_html_e('Tests basic read access', 'nibwp'); ?></span>
                            </div>
                            <span class="nw-prompt__copy"><?php esc_html_e('Click to copy', 'nibwp'); ?></span>
                        </button>
                        <button type="button" class="nw-prompt" data-prompt="<?php /* translators: a prompt the reader copies into their AI chat */ esc_attr_e('Create a draft post titled \'Hello from NIBWP\' with a short paragraph explaining what MCP is.', 'nibwp'); ?>">
                            <div class="nw-prompt__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9M16.5 3.5a2.12 2.12 0 113 3L7 19l-4 1 1-4L16.5 3.5z"/></svg></div>
                            <div>
                                <strong><?php esc_html_e('Create a draft post', 'nibwp'); ?></strong>
                                <span><?php esc_html_e('Tests write access (safely as draft)', 'nibwp'); ?></span>
                            </div>
                            <span class="nw-prompt__copy"><?php esc_html_e('Click to copy', 'nibwp'); ?></span>
                        </button>
                        <button type="button" class="nw-prompt" data-prompt="<?php /* translators: a prompt the reader copies into their AI chat */ esc_attr_e('Run a security health check on this WordPress site and tell me about any issues.', 'nibwp'); ?>">
                            <div class="nw-prompt__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div>
                            <div>
                                <strong><?php esc_html_e('Run security scan', 'nibwp'); ?></strong>
                                <span><?php esc_html_e('Tests the security toolkit', 'nibwp'); ?></span>
                            </div>
                            <span class="nw-prompt__copy"><?php esc_html_e('Click to copy', 'nibwp'); ?></span>
                        </button>
                        <button type="button" class="nw-prompt" data-prompt="<?php /* translators: a prompt the reader copies into their AI chat */ esc_attr_e('Remember that this site uses BEM naming for CSS classes and prefixes all CPTs with \'tdr_\'. Save this to your memory for future sessions.', 'nibwp'); ?>">
                            <div class="nw-prompt__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="2" width="16" height="20" rx="2"/><path d="M8 2v20M16 2v20M4 12h16"/></svg></div>
                            <div>
                                <strong><?php esc_html_e('Store project context in memory', 'nibwp'); ?></strong>
                                <span><?php esc_html_e('AI remembers it across sessions', 'nibwp'); ?></span>
                            </div>
                            <span class="nw-prompt__copy"><?php esc_html_e('Click to copy', 'nibwp'); ?></span>
                        </button>
                        <button type="button" class="nw-prompt" data-prompt="<?php /* translators: a prompt the reader copies into their AI chat */ esc_attr_e('List all available MCP tools you have access to on this WordPress site. Group them by category.', 'nibwp'); ?>">
                            <div class="nw-prompt__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg></div>
                            <div>
                                <strong><?php esc_html_e('Discover all tools', 'nibwp'); ?></strong>
                                <span><?php esc_html_e('See what AI can do here', 'nibwp'); ?></span>
                            </div>
                            <span class="nw-prompt__copy"><?php esc_html_e('Click to copy', 'nibwp'); ?></span>
                        </button>
                    </div>

                    <div class="nw-step-foot">
                        <label class="nw-step-check">
                            <input type="checkbox" data-step="first-chat">
                            <span><?php esc_html_e('I tested a prompt', 'nibwp'); ?></span>
                        </label>
                        <button type="button" class="button button-primary nw-next-chapter" data-next="workflows">
                            <?php esc_html_e('Next: Workflows', 'nibwp'); ?>
                            <svg class="nw-rtl-flip" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px; margin-inline-start:4px;"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                        </button>
                    </div>
                </section>

                <!-- CHAPTER 6: Workflows -->
                <section class="nw-chapter" id="ch-workflows" data-chapter="workflows">
                    <div class="nw-chapter__badge"><?php esc_html_e('CHAPTER 6', 'nibwp'); ?></div>
                    <h2><?php esc_html_e('Workflows — your operating playbooks', 'nibwp'); ?></h2>
                    <p class="nw-chapter__lead">
                        <?php esc_html_e('A workflow is a saved markdown playbook — your rules, process, and standards for a kind of task — so every run is consistent. Build them on the Workflows page (Pro), or just tell the AI "save this as a workflow" after a good session.', 'nibwp'); ?>
                    </p>

                    <h3><?php esc_html_e('Three ways they run', 'nibwp'); ?></h3>
                    <ol class="nw-step-list">
                        <li>
                            <strong><?php esc_html_e('Auto-route (default)', 'nibwp'); ?></strong>
                            <span><?php esc_html_e('The AI reads each workflow\'s "when to use" and loads the matching one automatically — only when the task fits. Nothing to toggle.', 'nibwp'); ?></span>
                        </li>
                        <li>
                            <strong><?php esc_html_e('Pin = always-on', 'nibwp'); ?></strong>
                            <span><?php esc_html_e('A pinned workflow is injected on every request and governs all work on this site. Pinned + auto-routed stack: house rules plus the task-specific playbook both apply.', 'nibwp'); ?></span>
                        </li>
                        <li>
                            <strong><?php esc_html_e('Copy Prompt = force it', 'nibwp'); ?></strong>
                            <span><?php esc_html_e('Grab any workflow\'s prompt and paste it into chat to run it on demand.', 'nibwp'); ?></span>
                        </li>
                    </ol>

                    <div class="nw-callout nw-callout--info">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                        <div>
                            <strong><?php esc_html_e('Out of the box: 0 pinned, all auto-routing', 'nibwp'); ?></strong>
                            <p><?php esc_html_e('Unpinned never means off — the AI still loads a workflow when the task matches. Pin only universal house rules (e.g. "never edit functions.php; plan before applying"); leave task-specific ones (SEO, forms) unpinned so the right one routes in per job.', 'nibwp'); ?></p>
                        </div>
                    </div>

                    <div class="nw-prompts">
                        <button type="button" class="nw-prompt" data-prompt="<?php /* translators: a prompt the reader copies into their AI chat */ esc_attr_e('Save the approach we just used as a reusable NIBWP workflow, with a clear \'when to use\' so you auto-load it next time.', 'nibwp'); ?>">
                            <div class="nw-prompt__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg></div>
                            <div>
                                <strong><?php esc_html_e('Save a workflow', 'nibwp'); ?></strong>
                                <span><?php esc_html_e('Capture a good session as a playbook', 'nibwp'); ?></span>
                            </div>
                            <span class="nw-prompt__copy"><?php esc_html_e('Click to copy', 'nibwp'); ?></span>
                        </button>
                        <button type="button" class="nw-prompt" data-prompt="<?php /* translators: a prompt the reader copies into their AI chat */ esc_attr_e('What NIBWP workflows are pinned, and which are available to auto-route? List each with its when-to-use.', 'nibwp'); ?>">
                            <div class="nw-prompt__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg></div>
                            <div>
                                <strong><?php esc_html_e('List active workflows', 'nibwp'); ?></strong>
                                <span><?php esc_html_e('See what\'s pinned vs auto-routing', 'nibwp'); ?></span>
                            </div>
                            <span class="nw-prompt__copy"><?php esc_html_e('Click to copy', 'nibwp'); ?></span>
                        </button>
                        <button type="button" class="nw-prompt" data-prompt="<?php /* translators: a prompt the reader copies into their AI chat; %s: workflow name, kept in English */ echo esc_attr(sprintf(__('Pin the \'%s\' workflow as always-on for this site, so you plan and get my approval before applying anything.', 'nibwp'), 'Safe changes')); ?>">
                            <div class="nw-prompt__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14l-5-4.87 6.91-1.01L12 2z"/></svg></div>
                            <div>
                                <strong><?php esc_html_e('Pin a house rule', 'nibwp'); ?></strong>
                                <span><?php esc_html_e('Make one always-on for every task', 'nibwp'); ?></span>
                            </div>
                            <span class="nw-prompt__copy"><?php esc_html_e('Click to copy', 'nibwp'); ?></span>
                        </button>
                    </div>

                    <div class="nw-step-foot">
                        <label class="nw-step-check">
                            <input type="checkbox" data-step="workflows">
                            <span><?php esc_html_e('Got workflows', 'nibwp'); ?></span>
                        </label>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=nibwp-workflows')); ?>" class="button button-primary">
                            <?php esc_html_e('Open Workflows', 'nibwp'); ?>
                            <svg class="nw-rtl-flip" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px; margin-inline-start:4px;"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                        </a>
                    </div>
                </section>

                <!-- CHAPTER 7: Best Practices -->
                <section class="nw-chapter" id="ch-best-practices" data-chapter="best-practices">
                    <div class="nw-chapter__badge"><?php esc_html_e('CHAPTER 7', 'nibwp'); ?></div>
                    <h2><?php esc_html_e('Best practices', 'nibwp'); ?></h2>

                    <div class="nw-tip-grid">
                        <div class="nw-tip nw-tip--do">
                            <div class="nw-tip__head">
                                <div class="nw-tip__icon" style="background:var(--nw-ok-soft); color:var(--nw-ok);">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                </div>
                                <strong><?php esc_html_e('Do', 'nibwp'); ?></strong>
                            </div>
                            <ul>
                                <li><?php esc_html_e('Use Force Draft mode in Settings while learning', 'nibwp'); ?></li>
                                <li><?php esc_html_e('Configure your AI client to ask before destructive actions', 'nibwp'); ?></li>
                                <li><?php esc_html_e('Take a database backup before running migrations', 'nibwp'); ?></li>
                                <li><?php esc_html_e('Store project conventions in Memory so AI follows them', 'nibwp'); ?></li>
                                <li><?php esc_html_e('Pin universal house rules as a Workflow; let task-specific ones auto-route', 'nibwp'); ?></li>
                                <li><?php esc_html_e('Review the Audit Log periodically', 'nibwp'); ?></li>
                            </ul>
                        </div>
                        <div class="nw-tip nw-tip--dont">
                            <div class="nw-tip__head">
                                <div class="nw-tip__icon" style="background:var(--nw-danger-soft); color:var(--nw-danger);">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                </div>
                                <strong><?php esc_html_e('Don\'t', 'nibwp'); ?></strong>
                            </div>
                            <ul>
                                <li><?php esc_html_e('Enable on live production without backups', 'nibwp'); ?></li>
                                <li><?php esc_html_e('Share your app password — treat it like a key', 'nibwp'); ?></li>
                                <li><?php esc_html_e('Approve PHP execution without reading the code first', 'nibwp'); ?></li>
                                <li><?php esc_html_e('Leave MCP enabled after testing on a public domain', 'nibwp'); ?></li>
                                <li><?php esc_html_e('Skip the security health check after migrations', 'nibwp'); ?></li>
                            </ul>
                        </div>
                    </div>

                    <h3><?php esc_html_e('Productivity tips', 'nibwp'); ?></h3>
                    <ul class="nw-tip-list">
                        <li><strong><?php esc_html_e('Be specific:', 'nibwp'); ?></strong> <?php esc_html_e('"Create a CPT for portfolio items with title, gallery, and project_url fields" beats "make a portfolio thing".', 'nibwp'); ?></li>
                        <li><strong><?php esc_html_e('Chain tools:', 'nibwp'); ?></strong> <?php esc_html_e('Ask AI to scan, fix, verify, and report in one prompt — it will plan the sequence.', 'nibwp'); ?></li>
                        <li><strong><?php esc_html_e('Use memory for context:', 'nibwp'); ?></strong> <?php esc_html_e('"Remember our brand colors are #2563eb (primary) and #f59e0b (accent)" — AI applies them automatically next time.', 'nibwp'); ?></li>
                        <li><strong><?php esc_html_e('Rate-limit in Settings:', 'nibwp'); ?></strong> <?php esc_html_e('Cap calls/minute to protect against runaway agents.', 'nibwp'); ?></li>
                    </ul>

                    <div class="nw-step-foot">
                        <label class="nw-step-check">
                            <input type="checkbox" data-step="best-practices">
                            <span><?php esc_html_e('Read the practices', 'nibwp'); ?></span>
                        </label>
                    </div>
                </section>

                <!-- CHAPTER ?: Troubleshooting -->
                <section class="nw-chapter" id="ch-troubleshoot" data-chapter="troubleshoot">
                    <div class="nw-chapter__badge"><?php esc_html_e('TROUBLESHOOTING', 'nibwp'); ?></div>
                    <h2><?php esc_html_e('Common issues', 'nibwp'); ?></h2>

                    <details class="nw-faq" open>
                        <summary><?php esc_html_e('AI client says "MCP server failed to start"', 'nibwp'); ?></summary>
                        <div>
                            <p><?php esc_html_e('Usually means npx can\'t reach the WordPress endpoint. Check:', 'nibwp'); ?></p>
                            <ul>
                                <li><?php esc_html_e('Node.js is installed and npx is in PATH', 'nibwp'); ?></li>
                                <li><?php esc_html_e('The WP_API_URL is reachable in your browser', 'nibwp'); ?></li>
                                <li><?php esc_html_e('AI Abilities are enabled (Connect page)', 'nibwp'); ?></li>
                                <li><?php esc_html_e('App password is correct (no extra spaces)', 'nibwp'); ?></li>
                            </ul>
                        </div>
                    </details>

                    <details class="nw-faq">
                        <summary><?php esc_html_e('"401 Unauthorized" errors', 'nibwp'); ?></summary>
                        <div>
                            <p><?php esc_html_e('Authentication failed. Common causes:', 'nibwp'); ?></p>
                            <ul>
                                <li><?php esc_html_e('Password was copied with hidden whitespace — paste, then trim', 'nibwp'); ?></li>
                                <li><?php esc_html_e('Username case mismatch (use exact WP login)', 'nibwp'); ?></li>
                                <li><?php esc_html_e('App password was revoked in WordPress', 'nibwp'); ?></li>
                                <li><?php esc_html_e('Application Passwords are disabled on this site (HTTP without WP_ENVIRONMENT_TYPE=local)', 'nibwp'); ?></li>
                            </ul>
                        </div>
                    </details>

                    <details class="nw-faq">
                        <summary><?php esc_html_e('Tools not showing up in AI client', 'nibwp'); ?></summary>
                        <div>
                            <p><?php esc_html_e('After config changes you must restart the AI client. Some clients also need a manual "reload MCP servers" command. In Claude Desktop: fully quit (Cmd+Q on Mac).', 'nibwp'); ?></p>
                        </div>
                    </details>

                    <details class="nw-faq">
                        <summary><?php esc_html_e('SSL certificate errors on local dev sites', 'nibwp'); ?></summary>
                        <div>
                            <p><?php esc_html_e('Add this to your env block:', 'nibwp'); ?></p>
                            <code style="display:inline-block;padding:6px 10px;background:var(--nw-surface-3);border-radius:4px;font-size:12px;">"NODE_TLS_REJECT_UNAUTHORIZED": "0"</code>
                            <p style="margin-top:8px;font-size:12px;color:var(--nw-text-muted);"><?php esc_html_e('Only use this for local development — never for public sites.', 'nibwp'); ?></p>
                        </div>
                    </details>

                    <details class="nw-faq">
                        <summary><?php esc_html_e('Plugin integration toggle on but tools missing', 'nibwp'); ?></summary>
                        <div>
                            <p><?php esc_html_e('Check that the underlying WordPress plugin is actually active. Many integrations only register tools when both conditions are true: (1) target plugin is installed/active, (2) integration is toggled on.', 'nibwp'); ?></p>
                        </div>
                    </details>

                    <div class="nw-step-foot">
                        <button type="button" class="button button-primary" id="nw-support-open">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px; margin-inline-end:4px;"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg>
                            <?php esc_html_e('Contact Support', 'nibwp'); ?>
                        </button>
                    </div>
                </section>

                <!-- Support Modal -->
                <div class="nw-req-modal" id="nw-support-modal" role="dialog" aria-hidden="true">
                    <div class="nw-req-modal__backdrop" id="nw-support-backdrop"></div>
                    <div class="nw-req-modal__panel">
                        <button type="button" class="nw-req-modal__close" id="nw-support-close" aria-label="<?php esc_attr_e('Close', 'nibwp'); ?>">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>

                        <div class="nw-req-modal__head">
                            <div class="nw-req-modal__icon" style="background:linear-gradient(135deg,#dbeafe,#bfdbfe);color:var(--nw-brand);">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg>
                            </div>
                            <div>
                                <strong><?php esc_html_e('Contact NIBWP Support', 'nibwp'); ?></strong>
                                <span><?php esc_html_e('We usually reply within 1 business day.', 'nibwp'); ?></span>
                            </div>
                        </div>

                        <form id="nw-support-form" class="nw-req-form">
                            <div class="nw-req-pane is-active" data-pane="form">
                                <div class="nw-req-field">
                                    <label for="nw-support-topic"><?php esc_html_e('What do you need help with?', 'nibwp'); ?></label>
                                    <select id="nw-support-topic" name="topic" required>
                                        <option value=""><?php esc_html_e('Choose a topic...', 'nibwp'); ?></option>
                                        <option value="connection"><?php esc_html_e('Connection / authentication issue', 'nibwp'); ?></option>
                                        <option value="ide-setup"><?php esc_html_e('IDE configuration help', 'nibwp'); ?></option>
                                        <option value="integration"><?php esc_html_e('Integration not working', 'nibwp'); ?></option>
                                        <option value="bug"><?php esc_html_e('Bug report', 'nibwp'); ?></option>
                                        <option value="feature"><?php esc_html_e('Feature request', 'nibwp'); ?></option>
                                        <option value="billing"><?php esc_html_e('Billing / license', 'nibwp'); ?></option>
                                        <option value="other"><?php esc_html_e('Something else', 'nibwp'); ?></option>
                                    </select>
                                </div>

                                <div class="nw-req-field">
                                    <label for="nw-support-subject"><?php esc_html_e('Subject', 'nibwp'); ?></label>
                                    <input type="text" id="nw-support-subject" name="subject" placeholder="<?php esc_attr_e('Short summary of your issue', 'nibwp'); ?>" required>
                                </div>

                                <div class="nw-req-field">
                                    <label for="nw-support-message"><?php esc_html_e('Message', 'nibwp'); ?></label>
                                    <textarea id="nw-support-message" name="message" rows="6" placeholder="<?php esc_attr_e('Describe what happened, what you expected, and any error messages...', 'nibwp'); ?>" required></textarea>
                                </div>

                                <div class="nw-req-field">
                                    <label class="nw-req-checkbox-row">
                                        <input type="checkbox" name="include_diagnostics" value="1" checked>
                                        <span><?php esc_html_e('Include diagnostic info (WP version, PHP version, site URL, active integrations)', 'nibwp'); ?></span>
                                    </label>
                                </div>

                                <div class="nw-req-field">
                                    <label for="nw-support-email"><?php esc_html_e('Reply to this email', 'nibwp'); ?></label>
                                    <input type="email" id="nw-support-email" name="reply_to" value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>" required>
                                </div>
                            </div>

                            <!-- SUCCESS pane -->
                            <div class="nw-req-pane nw-req-pane-success" data-pane="success">
                                <div class="nw-req-success-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                </div>
                                <strong><?php esc_html_e('Message sent!', 'nibwp'); ?></strong>
                                <p><?php esc_html_e('Your support request was emailed to our team. We\'ll reply to the address you provided.', 'nibwp'); ?></p>
                                <p style="font-size:12px;color:var(--nw-text-muted);"><?php esc_html_e('Average reply time: under 24 hours.', 'nibwp'); ?></p>
                            </div>

                            <div class="nw-req-foot">
                                <button type="button" class="nw-req-btn nw-req-btn-secondary" id="nw-support-cancel"><?php esc_html_e('Cancel', 'nibwp'); ?></button>
                                <button type="submit" class="nw-req-btn nw-req-btn-primary" id="nw-support-submit">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                                    <?php esc_html_e('Send Message', 'nibwp'); ?>
                                </button>
                                <button type="button" class="nw-req-btn nw-req-btn-primary" id="nw-support-done" style="display:none;"><?php esc_html_e('Done', 'nibwp'); ?></button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <script>
    (function(){
        var i18n = <?php echo wp_json_encode([
            'copied'       => __('Copied!', 'nibwp'),
            'resetConfirm' => __('Reset all progress?', 'nibwp'),
            'sending'      => __('Sending...', 'nibwp'),
            'sendFailed'   => __('Failed to send. Please try again or email us directly.', 'nibwp'),
            'networkError' => __('Network error. Please try again.', 'nibwp'),
            /* translators: 1: number of completed steps, 2: total number of steps */
            'progress'     => nibwp_i18n_js_plural(_n_noop('%1$s of %2$s step completed', '%1$s of %2$s steps completed', 'nibwp')),
        ]); ?>;
        function plural(p, n) { return p.forms[p.index[n < 200 ? n : 100 + n % 100]]; }
        function escHtml(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
        var STORAGE_KEY = 'nibwp_howto_progress';
        var steps = ['welcome', 'enable', 'password', 'ide', 'first-chat', 'workflows', 'best-practices'];

        /* Load saved progress */
        function loadProgress(){
            try { return JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}'); } catch(e){ return {}; }
        }
        function saveProgress(data){
            try { localStorage.setItem(STORAGE_KEY, JSON.stringify(data)); } catch(e){}
        }

        var progress = loadProgress();

        /* Initialize checkbox states — merge PHP-detected + localStorage */
        document.querySelectorAll('.nw-step-check input[type="checkbox"]').forEach(function(cb){
            var step = cb.getAttribute('data-step');
            /* PHP-detected (server says step is done) takes precedence */
            if (cb.checked) {
                progress[step] = true;
            } else if (progress[step]) {
                cb.checked = true;
            }
            cb.addEventListener('change', function(){
                progress[step] = cb.checked;
                saveProgress(progress);
                updateProgress();
                updateNavCompletion();
            });
        });
        saveProgress(progress);

        /* Update progress bar */
        function updateProgress(){
            var done = steps.filter(function(s){ return progress[s]; }).length;
            var pct = Math.round((done / steps.length) * 100);
            document.getElementById('nw-progress-fill').style.width = pct + '%';
            document.getElementById('nw-progress-text').textContent = plural(i18n.progress, steps.length).replace('%1$s', done).replace('%2$s', steps.length);
            document.getElementById('nw-progress-percent').textContent = pct + '%';
        }

        /* Mark completed nav items */
        function updateNavCompletion(){
            steps.forEach(function(s){
                var item = document.querySelector('.nw-howto-nav-item[data-chapter="' + s + '"]');
                if (item) item.classList.toggle('is-complete', !!progress[s]);
            });
        }

        /* Chapter navigation */
        var navItems = document.querySelectorAll('.nw-howto-nav-item');
        var chapters = document.querySelectorAll('.nw-chapter');

        function showChapter(name){
            navItems.forEach(function(item){
                item.classList.toggle('is-active', item.getAttribute('data-chapter') === name);
            });
            chapters.forEach(function(ch){
                ch.classList.toggle('is-active', ch.getAttribute('data-chapter') === name);
            });
            updateNavCompletion();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        navItems.forEach(function(item){
            item.addEventListener('click', function(e){
                e.preventDefault();
                showChapter(item.getAttribute('data-chapter'));
            });
        });

        /* Next chapter button */
        document.querySelectorAll('.nw-next-chapter').forEach(function(btn){
            btn.addEventListener('click', function(){
                showChapter(btn.getAttribute('data-next'));
            });
        });

        /* IDE tab switcher */
        document.querySelectorAll('.nw-ide-tab').forEach(function(tab){
            tab.addEventListener('click', function(){
                var key = tab.getAttribute('data-ide');
                document.querySelectorAll('.nw-ide-tab').forEach(function(t){ t.classList.toggle('is-active', t.getAttribute('data-ide') === key); });
                document.querySelectorAll('.nw-ide-panel').forEach(function(p){ p.classList.toggle('is-active', p.getAttribute('data-ide') === key); });
            });
        });

        /* Copy buttons */
        document.querySelectorAll('.nw-copy-btn').forEach(function(btn){
            btn.addEventListener('click', function(){
                var targetId = btn.getAttribute('data-copy');
                var el = document.getElementById(targetId);
                if (!el) return;
                navigator.clipboard.writeText(el.textContent).then(function(){
                    var orig = btn.innerHTML;
                    btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> ' + escHtml(i18n.copied);
                    btn.classList.add('is-copied');
                    setTimeout(function(){
                        btn.innerHTML = orig;
                        btn.classList.remove('is-copied');
                    }, 1500);
                });
            });
        });

        /* Click-to-copy prompts */
        document.querySelectorAll('.nw-prompt').forEach(function(p){
            p.addEventListener('click', function(){
                navigator.clipboard.writeText(p.getAttribute('data-prompt')).then(function(){
                    var copyEl = p.querySelector('.nw-prompt__copy');
                    var orig = copyEl.textContent;
                    copyEl.textContent = '✓ ' + i18n.copied;
                    p.classList.add('is-copied');
                    setTimeout(function(){
                        copyEl.textContent = orig;
                        p.classList.remove('is-copied');
                    }, 1500);
                });
            });
        });

        /* Reset progress */
        document.getElementById('nw-howto-reset').addEventListener('click', function(){
            if (!confirm(i18n.resetConfirm)) return;
            progress = {};
            saveProgress(progress);
            document.querySelectorAll('.nw-step-check input[type="checkbox"]').forEach(function(cb){
                if (!cb.disabled) cb.checked = false;
            });
            updateProgress();
            updateNavCompletion();
        });

        /* ── Contact Support Modal ── */
        var supportModal = document.getElementById('nw-support-modal');
        var supportOpen = document.getElementById('nw-support-open');
        var supportClose = document.getElementById('nw-support-close');
        var supportBackdrop = document.getElementById('nw-support-backdrop');
        var supportCancel = document.getElementById('nw-support-cancel');
        var supportForm = document.getElementById('nw-support-form');
        var supportSubmit = document.getElementById('nw-support-submit');
        var supportDone = document.getElementById('nw-support-done');
        var supportFormPane = supportModal ? supportModal.querySelector('.nw-req-pane[data-pane="form"]') : null;
        var supportSuccessPane = supportModal ? supportModal.querySelector('.nw-req-pane[data-pane="success"]') : null;

        function openSupport(){ supportModal.classList.add('is-open'); supportModal.setAttribute('aria-hidden','false'); document.body.style.overflow='hidden'; }
        function closeSupport(){
            supportModal.classList.remove('is-open');
            supportModal.setAttribute('aria-hidden','true');
            document.body.style.overflow='';
            /* Reset state */
            setTimeout(function(){
                if(supportFormPane) supportFormPane.classList.add('is-active');
                if(supportSuccessPane) supportSuccessPane.classList.remove('is-active');
                supportSubmit.style.display = 'inline-flex';
                supportDone.style.display = 'none';
                supportForm.reset();
            }, 200);
        }

        if(supportOpen) supportOpen.addEventListener('click', openSupport);
        if(supportClose) supportClose.addEventListener('click', closeSupport);
        if(supportBackdrop) supportBackdrop.addEventListener('click', closeSupport);
        if(supportCancel) supportCancel.addEventListener('click', closeSupport);
        if(supportDone) supportDone.addEventListener('click', closeSupport);
        document.addEventListener('keydown', function(e){ if(e.key==='Escape' && supportModal && supportModal.classList.contains('is-open')) closeSupport(); });

        if(supportForm){
            supportForm.addEventListener('submit', function(e){
                e.preventDefault();
                var btn = supportSubmit;
                var originalText = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<svg class="nw-spin" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 11-6.219-8.56"/></svg> ' + escHtml(i18n.sending);

                var data = new FormData(supportForm);
                data.append('action', 'nibwp_send_support');
                data.append('_wpnonce', '<?php echo esc_js(wp_create_nonce('nibwp_support')); ?>');

                fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                    method: 'POST',
                    body: data,
                    credentials: 'same-origin'
                })
                .then(function(r){ return r.json(); })
                .then(function(res){
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                    if(res && res.success){
                        supportFormPane.classList.remove('is-active');
                        supportSuccessPane.classList.add('is-active');
                        supportSubmit.style.display = 'none';
                        supportCancel.style.display = 'none';
                        supportDone.style.display = 'inline-flex';
                    } else {
                        alert((res && res.data && res.data.message) || i18n.sendFailed);
                    }
                })
                .catch(function(err){
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                    alert(i18n.networkError);
                });
            });
        }

        /* Initial render */
        updateProgress();
        updateNavCompletion();
    })();
    </script>
    <?php
    nibwp_render_admin_footer();
}
