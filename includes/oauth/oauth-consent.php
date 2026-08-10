<?php

declare(strict_types=1);

/**
 * The consent screen.
 *
 * This is the whole argument for signing in rather than pasting a password. A
 * password says nothing about what the client will do with it; this screen names
 * each permission in plain language, separates the far-reaching ones, and lets
 * the user hand over a subset.
 *
 * It is not a REST route: it needs a browser session and renders HTML. Sitting
 * on admin-post.php means WordPress handles the "log in first" case for us and
 * returns the user here afterwards, with the request intact.
 */

if (!defined('ABSPATH')) {
    exit();
}

add_action('admin_post_nibwp_oauth_authorize', 'nibwp_oauth_handle_authorize');
add_action('admin_post_nopriv_nibwp_oauth_authorize', 'nibwp_oauth_require_login');
add_action('admin_post_nibwp_oauth_decide', 'nibwp_oauth_handle_decision');

/**
 * Send an unauthenticated visitor to log in, then straight back here.
 */
function nibwp_oauth_require_login(): void
{
    auth_redirect();
}

/**
 * Validate an authorization request and render the consent screen.
 */
function nibwp_oauth_handle_authorize(): void
{
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- an OAuth
    // authorization request arrives from another application by redirect and
    // cannot carry one. It is validated by client_id + redirect_uri instead, and
    // nothing is granted until the signed consent form below is submitted.
    $client_id = isset($_GET['client_id']) ? sanitize_text_field(wp_unslash($_GET['client_id'])) : '';
    $redirect_uri = isset($_GET['redirect_uri']) ? esc_url_raw(wp_unslash($_GET['redirect_uri'])) : '';
    $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
    $challenge = isset($_GET['code_challenge']) ? sanitize_text_field(wp_unslash($_GET['code_challenge'])) : '';
    $method = isset($_GET['code_challenge_method']) ? sanitize_text_field(wp_unslash($_GET['code_challenge_method'])) : '';
    $scope = isset($_GET['scope']) ? sanitize_text_field(wp_unslash($_GET['scope'])) : '';
    $resource = isset($_GET['resource']) ? esc_url_raw(wp_unslash($_GET['resource'])) : '';
    // phpcs:enable

    if (!current_user_can('manage_options')) {
        nibwp_oauth_consent_error(
            __('You do not have permission to connect an AI client', 'nibwp'),
            __('Connecting requires an administrator account on this site. Sign in as an administrator and try again.', 'nibwp')
        );
    }

    $client = nibwp_oauth_resolve_client($client_id);
    if ($client === null) {
        nibwp_oauth_consent_error(
            __('Unknown application', 'nibwp'),
            __('This application is not registered with your site, so the request cannot be completed.', 'nibwp')
        );
    }

    // Everything below redirects errors back to the client. Above this line we
    // could not verify the redirect target, so those errors are shown here
    // instead — bouncing to an unverified URL is the classic open redirect.
    if (!nibwp_oauth_redirect_uri_allowed($redirect_uri, (array) $client['redirect_uris'])) {
        nibwp_oauth_consent_error(
            __('That return address is not registered', 'nibwp'),
            __('The application asked to be sent somewhere it has not registered. This can happen after an application updates, and is refused deliberately.', 'nibwp')
        );
    }

    if ($method !== 'S256' || $challenge === '') {
        nibwp_oauth_redirect_error($redirect_uri, 'invalid_request', 'code_challenge with method S256 is required.', $state);
    }

    if ($resource !== '' && !in_array(untrailingslashit($resource), nibwp_oauth_valid_audiences(), true)) {
        nibwp_oauth_redirect_error($redirect_uri, 'invalid_target', 'The requested resource does not match this server.', $state);
    }

    $requested = nibwp_oauth_sanitize_scopes($scope);
    $asked_for_nothing = $requested === [];
    if ($asked_for_nothing) {
        $requested = nibwp_oauth_default_scopes();
    }

    // What this account already gave this same client, if anything. Two uses:
    // it pre-ticks what was granted before so a reconnection cannot silently
    // downgrade a working setup, and it is what makes a repeat authorization
    // pass through without a second consent screen.
    $held = nibwp_oauth_client_scopes_held((string) $client['client_id']);

    // Every permission is always offered, whatever the client asked for.
    //
    // Showing only what was requested sounds right and is wrong in practice: a
    // client asks for read and write, so the screen renders read and write, and
    // the option to run PHP — the reason someone installs this — is nowhere on
    // the page. The refusal message then tells them to tick a box that was
    // never drawn. What the client asks for is a suggestion; what the account
    // holder is willing to grant is the actual question, and they can only
    // answer it if they can see all of it.
    //
    // Granting more than was asked for is allowed: the token response reports
    // the scopes actually issued, which is where a client is required to look.
    $offered = array_keys(nibwp_oauth_scopes());

    // The screen is shown every time, with one exception.
    //
    // Reaching this endpoint means a person pressed connect, and the reason
    // they press it a second time is nearly always to change what they granted
    // the first time. Re-issuing the held scopes without asking answered that
    // person with a redirect: the connection reported success, the permissions
    // were identical, and there was no screen on which to say otherwise. Every
    // reconnection they tried was silently a no-op.
    //
    // `prompt=none` is the one case where a client states it wants no UI, and
    // the spec requires either a silent answer or an error. Silent is only
    // possible when this account already granted everything being asked for.
    // phpcs:disable WordPress.Security.NonceVerification.Recommended
    $prompt = isset($_GET['prompt']) ? sanitize_text_field(wp_unslash($_GET['prompt'])) : '';
    // phpcs:enable

    if ($prompt === 'none' && !$asked_for_nothing && $held !== [] && array_diff($requested, $held) === []) {
        nibwp_oauth_finish_authorization([
            'client_id' => (string) $client['client_id'],
            'redirect_uri' => $redirect_uri,
            'state' => $state,
            'code_challenge' => $challenge,
            'resource' => $resource !== '' ? $resource : nibwp_oauth_resource_id(),
            'scopes' => $held,
        ]);
    }

    // Asked for no UI and we cannot answer without one. Drawing the screen
    // anyway would break a client that opened this in a hidden frame.
    if ($prompt === 'none') {
        nibwp_oauth_redirect_error($redirect_uri, 'interaction_required', 'This connection needs someone to approve it.', $state);
    }

    nibwp_oauth_render_consent([
        'client' => $client,
        'redirect_uri' => $redirect_uri,
        'state' => $state,
        'code_challenge' => $challenge,
        'resource' => $resource !== '' ? $resource : nibwp_oauth_resource_id(),
        'requested' => $requested,
        'offered' => $offered,
        'held' => $held,
    ]);
}

/**
 * Scopes this user has already granted this client and not revoked.
 *
 * @return array<int, string>
 */
function nibwp_oauth_client_scopes_held(string $client_id): array
{
    $now = time();
    $scopes = [];

    foreach (nibwp_oauth_user_tokens(get_current_user_id()) as $record) {
        if ((string) ($record['client_id'] ?? '') !== $client_id) {
            continue;
        }
        // A dead token is not a standing permission. Refresh counts: the client
        // holding one is still connected, it just has not called anything since
        // its access token aged out.
        if ((int) ($record['refresh_expires'] ?? 0) < $now && (int) ($record['expires'] ?? 0) < $now) {
            continue;
        }
        $scopes = array_merge($scopes, (array) ($record['scopes'] ?? []));
    }

    return nibwp_oauth_sanitize_scopes(array_values(array_unique($scopes)));
}

/**
 * Issue the code and bounce back to the client.
 *
 * @param array{client_id: string, redirect_uri: string, state: string, code_challenge: string, resource: string, scopes: array<int, string>} $grant
 */
function nibwp_oauth_finish_authorization(array $grant): void
{
    $code = nibwp_oauth_issue_code([
        'user_id' => get_current_user_id(),
        'client_id' => $grant['client_id'],
        'redirect_uri' => $grant['redirect_uri'],
        'code_challenge' => $grant['code_challenge'],
        'scopes' => $grant['scopes'],
        'resource' => $grant['resource'],
    ]);

    $args = ['code' => $code, 'iss' => nibwp_oauth_issuer()];
    if ($grant['state'] !== '') {
        $args['state'] = $grant['state'];
    }

    // Not wp_safe_redirect: the target is an external application, verified by
    // the caller against the client's registered addresses.
    wp_redirect(add_query_arg($args, $grant['redirect_uri']));
    exit;
}

/**
 * Handle Approve / Deny.
 */
function nibwp_oauth_handle_decision(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to do this.', 'nibwp'));
    }
    check_admin_referer('nibwp_oauth_consent');

    $redirect_uri = isset($_POST['redirect_uri']) ? esc_url_raw(wp_unslash($_POST['redirect_uri'])) : '';
    $client_id = isset($_POST['client_id']) ? sanitize_text_field(wp_unslash($_POST['client_id'])) : '';
    $state = isset($_POST['state']) ? sanitize_text_field(wp_unslash($_POST['state'])) : '';
    $challenge = isset($_POST['code_challenge']) ? sanitize_text_field(wp_unslash($_POST['code_challenge'])) : '';
    $resource = isset($_POST['resource']) ? esc_url_raw(wp_unslash($_POST['resource'])) : '';

    $client = nibwp_oauth_resolve_client($client_id);
    if ($client === null || !nibwp_oauth_redirect_uri_allowed($redirect_uri, (array) $client['redirect_uris'])) {
        wp_die(esc_html__('This authorization request is no longer valid.', 'nibwp'));
    }

    if (empty($_POST['nibwp_oauth_approve'])) {
        nibwp_oauth_redirect_error($redirect_uri, 'access_denied', 'The user declined the request.', $state);
    }

    // The ticked boxes are the whole decision. The old approve-everything
    // submit value went when every box started ticked; honouring it still
    // would mean a crafted POST could grant the lot regardless of what the
    // screen showed.
    $granted = nibwp_oauth_sanitize_scopes(
        isset($_POST['scopes']) && is_array($_POST['scopes'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['scopes']))
            : []
    );

    if ($granted === []) {
        nibwp_oauth_redirect_error($redirect_uri, 'invalid_scope', 'No permissions were approved.', $state);
    }

    nibwp_oauth_finish_authorization([
        'client_id' => $client_id,
        'redirect_uri' => $redirect_uri,
        'state' => $state,
        'code_challenge' => $challenge,
        'resource' => $resource !== '' ? $resource : nibwp_oauth_resource_id(),
        'scopes' => $granted,
    ]);
}

/**
 * Bounce an error back to the client, as the spec requires.
 */
function nibwp_oauth_redirect_error(string $redirect_uri, string $error, string $description, string $state): void
{
    $args = [
        'error' => $error,
        'error_description' => rawurlencode($description),
        'iss' => nibwp_oauth_issuer(),
    ];
    if ($state !== '') {
        $args['state'] = $state;
    }

    wp_redirect(add_query_arg($args, $redirect_uri));
    exit;
}

/**
 * A dead end we cannot safely redirect out of.
 */
function nibwp_oauth_consent_error(string $title, string $body): void
{
    wp_die(
        '<p>' . esc_html($body) . '</p>',
        esc_html($title),
        ['response' => 400, 'back_link' => false]
    );
}

/**
 * Render the consent screen.
 *
 * @param array<string, mixed> $ctx
 */
function nibwp_oauth_render_consent(array $ctx): void
{
    $client = $ctx['client'];
    $catalogue = nibwp_oauth_scopes();
    $user = wp_get_current_user();
    $name = (string) ($client['client_name'] ?: __('An application', 'nibwp'));

    // Offered is what appears on the screen; requested and held decide what
    // starts ticked. A permission that is never shown cannot be granted, and a
    // tool needing it then fails forever however many times you reconnect.
    $offered = $ctx['offered'] ?? $ctx['requested'];
    $held = $ctx['held'] ?? [];

    // Everything starts ticked.
    //
    // The screen exists to show what is being granted, not to ration it. A
    // half-granted connection does not fail visibly — it fails one tool at a
    // time, days later, as a refusal the user reads as a broken plugin. The
    // person approving is signed into this site as an administrator and is
    // connecting their own assistant; the useful question is what they would
    // rather withhold, and every box is theirs to untick before approving.
    $ticked = $offered;

    $safe = [];
    $risky = [];
    foreach ($offered as $slug) {
        if (!isset($catalogue[$slug])) {
            continue;
        }
        if ($catalogue[$slug]['danger']) {
            $risky[$slug] = $catalogue[$slug];
        } else {
            $safe[$slug] = $catalogue[$slug];
        }
    }

    nibwp_oauth_consent_head($name);
    ?>
    <div class="nw-oa">
        <div class="nw-oa-card">
            <div class="nw-oa-head">
                <?php // Both ends of the connection, so it is clear what is being joined to what. ?>
                <div class="nw-oa-marks" aria-hidden="true">
                    <?php
                    // A connector added through the one-click link registers
                    // under the name this plugin gave it — "NibWP — <site>" —
                    // so the letter fallback drew our own product as a grey N
                    // beside our own logo. When the name is ours, use the mark.
                    $brand = function_exists('nibwp_branding_display_name') ? nibwp_branding_display_name() : 'NibWP';
                    $is_ours = stripos($name, (string) $brand) === 0 || stripos($name, 'nibwp') === 0;
                    ?>
                    <?php if (!empty($client['logo_uri'])): ?>
                        <img class="nw-oa-mark" src="<?php echo esc_url((string) $client['logo_uri']); ?>" alt="">
                    <?php elseif ($is_ours): ?>
                        <span class="nw-oa-mark nw-oa-mark--site">
                            <img src="<?php echo esc_url(NIBWP_PLUGIN_URL . 'assets/nibwp-icon.svg'); ?>" alt="">
                        </span>
                    <?php else: ?>
                        <span class="nw-oa-mark nw-oa-mark--letter"><?php echo esc_html(strtoupper(mb_substr($name, 0, 1))); ?></span>
                    <?php endif; ?>
                    <span class="nw-oa-link">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                    </span>
                    <span class="nw-oa-mark nw-oa-mark--site">
                        <img src="<?php echo esc_url(NIBWP_PLUGIN_URL . 'assets/nibwp-icon.svg'); ?>" alt="">
                    </span>
                </div>
                <h1><?php
                    printf(
                        /* translators: %s: the name of the application requesting access */
                        esc_html__('Connect %s to your site?', 'nibwp'),
                        '<strong>' . esc_html($name) . '</strong>'
                    );
                ?></h1>
                <p class="nw-oa-site"><?php echo esc_html((string) wp_parse_url(home_url(), PHP_URL_HOST)); ?></p>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('nibwp_oauth_consent'); ?>
                <input type="hidden" name="action" value="nibwp_oauth_decide">
                <input type="hidden" name="client_id" value="<?php echo esc_attr((string) $client['client_id']); ?>">
                <input type="hidden" name="redirect_uri" value="<?php echo esc_attr((string) $ctx['redirect_uri']); ?>">
                <input type="hidden" name="state" value="<?php echo esc_attr((string) $ctx['state']); ?>">
                <input type="hidden" name="code_challenge" value="<?php echo esc_attr((string) $ctx['code_challenge']); ?>">
                <input type="hidden" name="resource" value="<?php echo esc_attr((string) $ctx['resource']); ?>">

                <?php
                // Approving a connection to a site whose abilities are switched
                // off grants a key to a locked door: the screen says yes, and
                // then nothing works, with the client reporting no tools and no
                // reason. The domain lock is the usual cause on hosts that hand
                // out new URLs — a site that moved has its abilities disabled
                // and nothing on this screen used to mention it.
                $nw_oa_ready = !function_exists('nibwp_is_enabled') || nibwp_is_enabled();
                $nw_oa_moved = function_exists('nibwp_is_domain_mismatch') && nibwp_is_domain_mismatch();
                ?>
                <?php if (!$nw_oa_ready): ?>
                    <div class="nw-oa-warn nw-oa-warn--block">
                        <strong><?php esc_html_e('This connection will not be able to do anything yet', 'nibwp'); ?></strong>
                        <span><?php
                            echo $nw_oa_moved
                                ? esc_html(sprintf(
                                    /* translators: %s: the host the abilities were switched on for */
                                    __('AI Abilities were switched on for %s, and this site now answers on a different address, so they are off. Approving still works — turn them back on afterwards and this connection starts working.', 'nibwp'),
                                    (string) get_option('nibwp_ai_abilities_domain', '')
                                ))
                                : esc_html__('AI Abilities are switched off on this site. Approving still works — turn them on afterwards and this connection starts working.', 'nibwp');
                        ?></span>
                        <span><?php
                            printf(
                                /* translators: %s: the admin screen name */
                                esc_html__('You will find the switch under %s.', 'nibwp'),
                                '<strong>' . esc_html__('NibWP → Connect', 'nibwp') . '</strong>'
                            );
                        ?></span>
                    </div>
                <?php endif; ?>

                <p class="nw-oa-lead"><?php esc_html_e('It is asking to:', 'nibwp'); ?></p>

                <ul class="nw-oa-scopes">
                    <?php foreach ($safe as $slug => $meta): ?>
                        <li>
                            <label class="nw-oa-scope">
                                <input type="checkbox" name="scopes[]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, $ticked, true)); ?>>
                                <span class="nw-oa-box" aria-hidden="true">
                                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 8.5l3 3 6-6.5"/></svg>
                                </span>
                                <span class="nw-oa-scope__text">
                                    <strong><?php echo esc_html($meta['label']); ?></strong>
                                    <span><?php echo esc_html($meta['description']); ?></span>
                                </span>
                            </label>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <?php if ($risky !== []): ?>
                    <p class="nw-oa-lead nw-oa-lead--risk">
                        <?php esc_html_e('And these, which go further:', 'nibwp'); ?>
                    </p>
                    <p class="nw-oa-note">
                        <?php esc_html_e('Most of what people ask an assistant to build needs these — running code is how a feature gets made rather than described. Tick what you want it to be able to do; a tool without its permission says which one it needs, and you can come back and grant it.', 'nibwp'); ?>
                    </p>
                    <ul class="nw-oa-scopes nw-oa-scopes--risk">
                        <?php foreach ($risky as $slug => $meta): ?>
                            <li>
                                <label class="nw-oa-scope">
                                    <?php // Ticked like everything else, and set apart so the
                                          // difference is visible: these are the ones worth a
                                          // moment's thought before approving. ?>
                                    <input type="checkbox" name="scopes[]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, $ticked, true)); ?>>
                                    <span class="nw-oa-box" aria-hidden="true">
                                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 8.5l3 3 6-6.5"/></svg>
                                    </span>
                                    <span class="nw-oa-scope__text">
                                        <strong><?php echo esc_html($meta['label']); ?></strong>
                                        <span><?php echo esc_html($meta['description']); ?></span>
                                    </span>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <p class="nw-oa-as">
                    <?php
                    printf(
                        /* translators: 1: WordPress display name, 2: user login */
                        esc_html__('Acting as %1$s (%2$s). It will only ever be able to do what that account can.', 'nibwp'),
                        '<strong>' . esc_html($user->display_name) . '</strong>',
                        esc_html($user->user_login)
                    );
                    ?>
                </p>

                <div class="nw-oa-actions">
                    <button type="submit" name="nibwp_oauth_approve" value="1" class="nw-oa-btn nw-oa-btn--go">
                        <?php esc_html_e('Approve', 'nibwp'); ?>
                    </button>
                    <button type="submit" name="nibwp_oauth_deny" value="1" class="nw-oa-btn">
                        <?php esc_html_e('Cancel', 'nibwp'); ?>
                    </button>
                </div>

                <?php // The "approve everything" shortcut is gone: everything is
                      // already ticked, so it would be a button that does nothing. ?>
                <p class="nw-oa-foot">
                    <?php esc_html_e('Everything is ticked. Untick whatever you would rather this connection could not do — you can also revoke the whole thing at any time from NibWP → Connect.', 'nibwp'); ?>
                </p>
            </form>
        </div>
    </div>
    </body></html>
    <?php
    exit;
}

/**
 * Standalone page chrome — this screen renders outside the admin shell so the
 * only thing on it is the decision being asked for.
 */
function nibwp_oauth_consent_head(string $client_name): void
{
    status_header(200);
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');

    // This screen grants access, so it must never render inside someone else's
    // page. WordPress sends frame-blocking headers on admin_init and login_init,
    // and this is served from the front end through parse_request, so neither
    // fires here. Without them the whole approval can be clickjacked: the nonce
    // does not help, because it is the victim's own browser that submits it.
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: frame-ancestors 'none'");
    header('Referrer-Policy: strict-origin-when-cross-origin');
    ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php
    printf(
        /* translators: %s: application name */
        esc_html__('Connect %s', 'nibwp'),
        esc_html($client_name)
    );
?></title>
<?php wp_admin_css('login', true); ?>
<link rel="stylesheet" href="<?php echo esc_url(NIBWP_PLUGIN_URL . 'assets/css/admin-oauth.css?v=' . (defined('NIBWP_VERSION') ? NIBWP_VERSION : '1')); ?>">
</head>
<body class="nw-oa-body">
<?php
}
