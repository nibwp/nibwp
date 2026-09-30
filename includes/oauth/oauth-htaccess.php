<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The one .htaccess fix worth a button: pass the Authorization header through.
 *
 * CGI and FastCGI Apache setups drop the Authorization header before PHP sees
 * it. Clients then send perfectly good credentials, WordPress reports the
 * request as logged out, and it reads exactly like a wrong password — which is
 * why this one costs people hours. The Status page has always printed the fix
 * as advice; this writes it, on explicit admin consent, and removes it when
 * the plugin deactivates.
 *
 * What is written is deliberately minimal. `CGIPassAuth On` would be the
 * cleaner directive and is deliberately NOT here: it is a core directive with
 * no possible <IfModule> guard, invalid before Apache 2.4.13 and refused
 * wherever AllowOverride lacks AuthConfig — either case is an instant 500 for
 * the whole site, and a plugin must never hold that trigger. No rewrite rules
 * either: insert_with_markers() appends after WordPress's own block, where
 * rules sit unreachable behind its [L], and the hostile dotfile denies live in
 * server config that .htaccess cannot override anyway.
 */

/**
 * @return array<int, string> The marker-block lines, exactly as written.
 */
function nibwp_oauth_htaccess_rules(): array
{
    return [
        '<IfModule mod_setenvif.c>',
        'SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1',
        '</IfModule>',
    ];
}

/**
 * Whether writing the fix here could help rather than harm.
 *
 * The `# BEGIN WordPress` presence test is the load-bearing one: it proves
 * this host actually honours .htaccess overrides, which is the strongest cheap
 * evidence that adding a guarded directive will be read rather than 500 on.
 */
function nibwp_oauth_can_fix_htaccess(): bool
{
    global $is_apache;

    $litespeed = isset($_SERVER['SERVER_SOFTWARE']) && stripos((string) $_SERVER['SERVER_SOFTWARE'], 'litespeed') !== false;
    if (empty($is_apache) && !$litespeed) {
        return false;
    }

    $file = ABSPATH . '.htaccess';
    if (!is_file($file) || !is_writable($file)) {
        return false;
    }

    $contents = (string) file_get_contents($file);

    return str_contains($contents, '# BEGIN WordPress');
}

/**
 * The admin action behind the "Fix this" button.
 *
 * Never automatic: a server-config write is the site owner's decision, made on
 * a screen that has just shown them the proof it is needed.
 */
add_action('admin_post_nibwp_fix_auth_header', 'nibwp_oauth_handle_fix_auth_header');

function nibwp_oauth_handle_fix_auth_header(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You are not allowed to change server configuration.', 'nibwp'));
    }
    check_admin_referer('nibwp_fix_auth_header');

    $applied = false;
    if (nibwp_oauth_can_fix_htaccess()) {
        if (!function_exists('insert_with_markers')) {
            require_once ABSPATH . 'wp-admin/includes/misc.php';
        }
        $applied = (bool) insert_with_markers(ABSPATH . '.htaccess', 'NibWP', nibwp_oauth_htaccess_rules());
    }

    // The verdicts cached before the write describe a server that no longer
    // exists; the next probe has to look again.
    delete_transient('nibwp_oauth_discovery');
    delete_transient('nibwp_status_selftest_token');

    $back = wp_get_referer();
    if (!is_string($back) || $back === '') {
        $back = admin_url('admin.php?page=nibwp-status');
    }

    wp_safe_redirect(add_query_arg('nibwp_auth_fix', $applied ? 'applied' : 'failed', $back));
    exit;
}

/**
 * Remove the marker block. Hooked to deactivation from nibwp.php — a
 * deactivated NibWP must leave no fingerprints in server configuration.
 */
function nibwp_oauth_remove_htaccess_rules(): void
{
    $file = ABSPATH . '.htaccess';
    if (!is_file($file) || !is_writable($file)) {
        return;
    }

    if (!function_exists('insert_with_markers')) {
        require_once ABSPATH . 'wp-admin/includes/misc.php';
    }
    insert_with_markers($file, 'NibWP', []);
}
