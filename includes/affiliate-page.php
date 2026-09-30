<?php

declare(strict_types=1);

/**
 * NIBWP Affiliate Program — admin page.
 *
 * Pitches the affiliate program and signs the site owner up without them
 * leaving wp-admin. Three states, one page: pitch, joined, failed.
 *
 * The offer itself (commission, cookie window, perks) is fetched from
 * nibwp.com and cached for a day, so the terms can change — or the program
 * can be paused — without a plugin release. Installs that never update would
 * otherwise keep advertising a rate we no longer honor. A bundled copy is
 * shown on first paint and whenever the fetch fails, so the page is never
 * blank and never spins.
 *
 * Signup POSTs to our own admin-post handler, which relays server-side to
 * nibwp.com. Server-side because the browser has no business talking to
 * another origin here, and because a client-side POST would let the site URL
 * be forged.
 */

if (!defined('ABSPATH')) {
    exit();
}

/** Base URL of the affiliate endpoints. Filterable for staging. */
function nibwp_affiliate_api_base(): string
{
    return (string) apply_filters('nibwp_affiliate_api_base', 'https://www.nibwp.com/wp-json/nibwp/v1');
}

/**
 * The program page — also the affiliate's dashboard once they have joined.
 *
 * These are real pages on nibwp.com. `/affiliates` was a guess and 404s, which
 * meant every fallback link on this page led nowhere: the one shown when the
 * signup form cannot reach us, and the dashboard link shown after joining.
 */
function nibwp_affiliate_public_url(): string
{
    return (string) apply_filters('nibwp_affiliate_public_url', 'https://www.nibwp.com/affiliate/');
}

/**
 * Where someone applies when the in-plugin form cannot complete.
 *
 * A child of the program page, so the path is /affiliate/apply/ — not
 * /apply/, which 404s.
 */
function nibwp_affiliate_apply_url(): string
{
    return (string) apply_filters('nibwp_affiliate_apply_url', 'https://www.nibwp.com/affiliate/apply/');
}

/**
 * The offer shown when nibwp.com has not answered yet, is unreachable, or
 * returns something we cannot read.
 *
 * Every figure here now matches what FluentAffiliate on nibwp.com is actually
 * configured to pay, read from `_fa_referral_settings`: 25% of the order
 * value, a 30-day cookie, euros by PayPal, €100 minimum, last click wins.
 *
 * It deliberately does NOT claim recurring commission. The program is set to
 * `enable_subscription_renewal: no`, so renewals pay nothing — this page used
 * to promise "you earn on every renewal for as long as the customer stays",
 * which the software would never have honored. If that setting is turned on,
 * change it here AND in /affiliate-terms/ before advertising it.
 */
function nibwp_affiliate_fallback_offer(): array
{
    return [
        'status'           => 'on',
        'headline'         => __('Get paid for recommending NibWP', 'nibwp'),
        'rate'             => __('25% per sale', 'nibwp'),
        'cookie_days'      => 30,
        'payout_min'       => __('€100', 'nibwp'),
        'payout_schedule'  => __('Paid in euros by PayPal once you reach the minimum', 'nibwp'),
        // Numbers behind the estimator. Kept separate from the display strings
        // above so the estimator never has to parse "25% per sale" back into a
        // number — a string that changes shape would otherwise quietly produce
        // an estimate of zero. avg_order tracks the real entry price: Pro is
        // €49/yr, so the estimate stays on the cautious side of what people
        // actually buy.
        'rate_percent'     => 25,
        'avg_order'        => 49,
        'currency'         => '€',
        'perks'            => [
            [
                'title' => __('A quarter of every sale', 'nibwp'),
                'body'  => __('25% of the order value on every first purchase made through your link. Nothing to claim and nothing to chase — it lands in your balance automatically.', 'nibwp'),
            ],
            [
                'title' => __('30-day cookie', 'nibwp'),
                'body'  => __('A month for someone to make up their mind and still be credited to you. Where two affiliates referred the same person, the most recent link wins.', 'nibwp'),
            ],
            [
                'title' => __('However you reach people', 'nibwp'),
                'body'  => __('Client sites, your own sites, a channel, a newsletter, a course, a community, a conference talk. Anywhere you can put a link counts.', 'nibwp'),
            ],
            [
                'title' => __('Assets that convert', 'nibwp'),
                'body'  => __('Banners, demo videos, screenshots, copy you can lift, and a live sandbox to send people to.', 'nibwp'),
            ],
            [
                'title' => __('Real-time dashboard', 'nibwp'),
                'body'  => __('Clicks, signups and commission, updated as they happen. No waiting for a monthly report.', 'nibwp'),
            ],
            [
                'title' => __('A human on the other end', 'nibwp'),
                'body'  => __('Direct line to the team for custom coupons, joint webinars or a bespoke rate if you send volume.', 'nibwp'),
            ],
        ],
        // The share-and-tell-us offer. Rewards are deliberately open-ended
        // here — what someone gets depends on where they posted and who saw
        // it, and promising a fixed number for an unknown audience would be
        // a promise we could not keep.
        'share'            => [
            'title' => __('Post about NibWP, send us the link', 'nibwp'),
            'body'  => __('Someone in a WordPress Facebook group asks which AI plugin to use, or how to connect Claude to their site. Answer them properly, mention NibWP with your link, then send us the URL of that post or comment. Reddit, forums, Discord, a YouTube comment — all of it counts.', 'nibwp'),
            'steps' => [
                __('Answer a real question somewhere your people already are', 'nibwp'),
                __('Include your referral link', 'nibwp'),
                __('Email us the URL of the post or comment', 'nibwp'),
            ],
            'reward' => __('We read every one and come back with something worth having: a higher rate on your account, a free license for a site of your own, or a coupon code you can give that group.', 'nibwp'),
            'caveat' => __('Please do not spam. One useful answer in the right thread is worth more to us than fifty drive-by links, and group admins ban the second kind.', 'nibwp'),
        ],
        'terms_url'        => 'https://www.nibwp.com/affiliate-terms/',
        'privacy_url'      => 'https://www.nibwp.com/privacy/',
    ];
}

/**
 * Current offer — cached for a day. A failed or malformed response falls back
 * to the bundled copy AND is cached briefly, so an outage does not mean a
 * remote call on every page load.
 */
function nibwp_affiliate_offer(bool $refresh = false): array
{
    $cached = $refresh ? false : get_transient('nibwp_affiliate_offer');
    if (is_array($cached) && $cached !== []) {
        // A cached FALLBACK is only a "do not refetch yet" marker — its
        // contents are whatever the plugin shipped when it was stored, so an
        // update that changes the bundled offer would otherwise keep showing
        // the old one until the cache expired. Rebuild it from what is
        // shipped now. A cached real offer is returned untouched.
        return empty($cached['_fallback'])
            ? $cached
            : array_merge(nibwp_affiliate_fallback_offer(), ['_fallback' => true]);
    }

    $response = wp_remote_get(nibwp_affiliate_api_base() . '/affiliate-offer', [
        'timeout' => 6,
        'headers' => ['Accept' => 'application/json'],
    ]);

    $offer = nibwp_affiliate_parse_offer($response);

    // A short cache on failure: retry in an hour rather than on every paint.
    set_transient('nibwp_affiliate_offer', $offer, $offer['_fallback'] ?? false ? HOUR_IN_SECONDS : DAY_IN_SECONDS);

    return $offer;
}

/**
 * Turn a remote response into an offer, falling back on anything we cannot
 * read. Split out from the fetch so it can be checked without a network.
 *
 * @param mixed $response
 */
function nibwp_affiliate_parse_offer($response): array
{
    $fallback = nibwp_affiliate_fallback_offer();
    $fallback['_fallback'] = true;

    if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
        return $fallback;
    }

    $body = json_decode((string) wp_remote_retrieve_body($response), true);
    if (!is_array($body) || !isset($body['status'])) {
        return $fallback;
    }

    // Merge over the bundled copy so a partial payload still renders whole.
    $offer = array_merge(nibwp_affiliate_fallback_offer(), $body);
    $offer['perks'] = array_values(array_filter(
        is_array($offer['perks'] ?? null) ? $offer['perks'] : [],
        static fn ($perk): bool => is_array($perk) && !empty($perk['title'])
    ));
    if ($offer['perks'] === []) {
        $offer['perks'] = $fallback['perks'];
    }
    $offer['_fallback'] = false;

    return $offer;
}

/**
 * What we know about this site's affiliate account.
 *
 * @return array{state:string, referral_url?:string, affiliate_id?:string, email?:string, message?:string}
 */
function nibwp_affiliate_status(): array
{
    $status = get_option('nibwp_affiliate_status', []);

    return is_array($status) && isset($status['state']) ? $status : ['state' => 'none'];
}

/**
 * Whether the menu item should appear at all.
 *
 * Runs on admin_menu, i.e. on every admin page load, so it reads the cached
 * offer only — never fetches. A remote call here would put nibwp.com's
 * latency in front of wp-admin. The page itself does the fetching, which
 * means a newly-paused program keeps its menu item until someone opens it
 * or the cache expires. That is the right trade.
 */
function nibwp_affiliate_visible(): bool
{
    $status = nibwp_affiliate_status();

    // Someone who has joined keeps the page — it is their links and status now.
    if (in_array($status['state'], ['pending', 'approved'], true)) {
        return true;
    }

    $cached = get_transient('nibwp_affiliate_offer');

    return !is_array($cached) || ($cached['status'] ?? 'on') !== 'off';
}

/** Menu label — stops selling once they are in. */
function nibwp_affiliate_menu_label(): string
{
    return in_array(nibwp_affiliate_status()['state'], ['pending', 'approved'], true)
        ? __('Affiliate', 'nibwp')
        : __('Affiliate Program', 'nibwp');
}

// ── Form handling ───────────────────────────────────────────────────────────

add_action('admin_post_nibwp_affiliate_signup', 'nibwp_affiliate_handle_signup');
add_action('admin_post_nibwp_affiliate_share', 'nibwp_affiliate_handle_share');

/** Where to send the browser back to after a POST. */
function nibwp_affiliate_page_url(array $args = []): string
{
    return add_query_arg(array_merge(['page' => 'nibwp-affiliate'], $args), admin_url('admin.php'));
}

function nibwp_affiliate_handle_signup(): void
{
    if (!current_user_can('manage_options') || !check_admin_referer('nibwp_affiliate_signup')) {
        wp_die(esc_html__('You are not allowed to do that.', 'nibwp'), 403);
    }

    $submission = [
        'name'      => sanitize_text_field(wp_unslash($_POST['nibwp_aff_name'] ?? '')),
        'email'     => sanitize_email(wp_unslash($_POST['nibwp_aff_email'] ?? '')),
        'promotion' => sanitize_textarea_field(wp_unslash($_POST['nibwp_aff_promotion'] ?? '')),
        'consent'   => !empty($_POST['nibwp_aff_consent']),
    ];

    $error = nibwp_affiliate_validate($submission);
    if ($error !== null) {
        nibwp_affiliate_stash_failure($error, $submission);
        wp_safe_redirect(nibwp_affiliate_page_url(['nibwp_aff' => 'error']));
        exit();
    }

    $result = nibwp_affiliate_submit($submission);

    // The API being down must not cost someone their application. Email is the
    // fallback: it reaches us, and it is honest — the state recorded is
    // "pending", which is what it is, and the page then says a human will pick
    // it up rather than claiming an account exists.
    if (is_wp_error($result) && nibwp_affiliate_email_application($submission)) {
        $result = [
            'state'     => 'pending',
            'via'       => 'email',
            'email'     => $submission['email'],
            'joined_at' => time(),
        ];
    }

    if (is_wp_error($result)) {
        nibwp_affiliate_stash_failure($result->get_error_message(), $submission);
        wp_safe_redirect(nibwp_affiliate_page_url(['nibwp_aff' => 'error']));
        exit();
    }

    update_option('nibwp_affiliate_status', $result, autoload: false);
    delete_transient('nibwp_affiliate_failure_' . get_current_user_id());
    wp_safe_redirect(nibwp_affiliate_page_url(['nibwp_aff' => 'joined']));
    exit();
}

/**
 * The share-and-tell-us offer: post somewhere real, send us the URL, get
 * something back for it.
 *
 * Given its own highlighted section rather than a seventh perk card because
 * it asks for an action, and an action buried among six statements gets read
 * as another statement. The reward is deliberately not a number — it depends
 * on where the post landed, and a fixed figure for an unknown audience would
 * be a promise we could not keep.
 */
function nibwp_affiliate_render_share_offer(array $offer): void
{
    $share = $offer['share'] ?? [];
    if (!is_array($share) || empty($share['title'])) {
        return;
    }

    ?>
    <?php // Not .nibwp-dash-card: this panel overrides every part of that card,
          // so carrying the class would only claim a relationship it does not have. ?>
    <div class="nw-aff__share">
        <div class="nw-aff__share-main">
            <div class="nw-aff__share-tag"><?php esc_html_e('Extra perks', 'nibwp'); ?></div>
            <h2 class="nw-aff__share-title"><?php echo esc_html((string) $share['title']); ?></h2>

            <?php if (!empty($share['body'])) : ?>
                <p class="nw-aff__share-body"><?php echo esc_html((string) $share['body']); ?></p>
            <?php endif; ?>

            <?php if (!empty($share['steps']) && is_array($share['steps'])) : ?>
                <ol class="nw-aff__share-steps">
                    <?php foreach ($share['steps'] as $step) : ?>
                        <li><?php echo esc_html((string) $step); ?></li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>

            <?php if (!empty($share['caveat'])) : ?>
                <p class="nw-aff__share-caveat"><?php echo esc_html((string) $share['caveat']); ?></p>
            <?php endif; ?>
        </div>

        <div class="nw-aff__share-side">
            <?php // A seal, not an icon: it sits on the panel edge and marks the
                  // promise underneath it as one a person keeps. Decorative, so
                  // hidden from assistive tech — the sentence below says it. ?>
            <span class="nw-aff__seal" aria-hidden="true">
                <svg viewBox="0 0 64 64" width="58" height="58" fill="none">
                    <circle cx="32" cy="32" r="29" stroke="currentColor" stroke-width="1.4" stroke-dasharray="2.5 4.5" opacity=".65"/>
                    <circle cx="32" cy="32" r="23" fill="currentColor" opacity=".14"/>
                    <circle cx="32" cy="32" r="23" stroke="currentColor" stroke-width="1.6"/>
                    <path d="M22.5 32.8l6.2 6.2L41.5 26" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </span>

            <p class="nw-aff__seal-label"><?php esc_html_e('Read by a person', 'nibwp'); ?></p>

            <?php if (!empty($share['reward'])) : ?>
                <p class="nw-aff__share-reward"><?php echo esc_html((string) $share['reward']); ?></p>
            <?php endif; ?>
            <button type="button" class="nw-aff__btn nw-aff__btn--primary" id="nw-aff-share-open">
                <?php esc_html_e('Send us your post', 'nibwp'); ?>
            </button>
            <p class="nw-aff__share-to"><?php esc_html_e('Takes a few seconds. No email client needed.', 'nibwp'); ?></p>
        </div>
    </div>

    <?php nibwp_affiliate_render_share_modal(); ?>
    <?php
}

/**
 * What happened to a submitted post link.
 *
 * A failed send says so. Telling someone their post is with us when the mail
 * never left would have them waiting on a reply that is never coming.
 */
function nibwp_affiliate_render_share_result(): void
{
    $messages = [
        'shared' => [
            'ok',
            __('Got it — your post is with us.', 'nibwp'),
            __('We read every one. Expect a reply at the address on your account.', 'nibwp'),
        ],
        'share_failed' => [
            'bad',
            __('That did not send.', 'nibwp'),
            /* translators: %s: the email address to write to instead. */
            sprintf(__('This site could not send the mail. Write to %s directly and we will pick it up there.', 'nibwp'), nibwp_affiliate_notify_email()),
        ],
        'share_bad_url' => [
            'bad',
            __('That link did not look like a link.', 'nibwp'),
            __('Paste the full address, starting with https://', 'nibwp'),
        ],
    ];

    $key = sanitize_key(wp_unslash($_GET['nibwp_aff'] ?? ''));
    if (!isset($messages[$key])) {
        return;
    }

    [$tone, $title, $body] = $messages[$key];
    ?>
    <div class="nibwp-dash-card nw-aff__result is-<?php echo esc_attr($tone); ?>" role="status">
        <strong><?php echo esc_html($title); ?></strong>
        <span><?php echo esc_html($body); ?></span>
    </div>
    <?php
}

/** The "send us your post" dialog. Same shape as the integration request modal. */
function nibwp_affiliate_render_share_modal(): void
{
    ?>
    <div class="nw-aff-modal" id="nw-aff-share-modal" role="dialog" aria-modal="true"
         aria-labelledby="nw-aff-share-modal-title" aria-hidden="true">
        <div class="nw-aff-modal__backdrop" id="nw-aff-share-backdrop"></div>
        <div class="nw-aff-modal__panel">
            <button type="button" class="nw-aff-modal__close" id="nw-aff-share-close"
                    aria-label="<?php esc_attr_e('Close', 'nibwp'); ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>

            <h2 class="nw-aff-modal__title" id="nw-aff-share-modal-title">
                <?php esc_html_e('Send us your post', 'nibwp'); ?>
            </h2>
            <p class="nw-aff-modal__sub">
                <?php esc_html_e('Paste the link to the post or comment. We read every one and come back to you.', 'nibwp'); ?>
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('nibwp_affiliate_share'); ?>
                <input type="hidden" name="action" value="nibwp_affiliate_share">

                <div class="nw-aff__field">
                    <label class="nw-aff__label" for="nw-aff-share-url"><?php esc_html_e('Link to the post or comment', 'nibwp'); ?></label>
                    <input type="url" id="nw-aff-share-url" name="nibwp_aff_share_url" required
                           placeholder="https://www.facebook.com/groups/…">
                </div>

                <div class="nw-aff__field">
                    <label class="nw-aff__label" for="nw-aff-share-note"><?php esc_html_e('Anything we should know? (optional)', 'nibwp'); ?></label>
                    <textarea id="nw-aff-share-note" name="nibwp_aff_share_note" rows="3"
                              placeholder="<?php esc_attr_e('Which group, how big it is, what you were answering.', 'nibwp'); ?>"></textarea>
                </div>

                <button type="submit" class="nw-aff__btn nw-aff__btn--primary">
                    <?php esc_html_e('Send it', 'nibwp'); ?>
                </button>
            </form>
        </div>
    </div>

    <script>
    (function () {
        var modal = document.getElementById('nw-aff-share-modal');
        var open  = document.getElementById('nw-aff-share-open');
        if (!modal || !open) { return; }

        var field = document.getElementById('nw-aff-share-url');
        var last  = null;

        function show() {
            last = document.activeElement;
            modal.setAttribute('aria-hidden', 'false');
            modal.classList.add('is-open');
            document.body.style.overflow = 'hidden';
            if (field) { field.focus(); }
        }

        function hide() {
            modal.setAttribute('aria-hidden', 'true');
            modal.classList.remove('is-open');
            document.body.style.overflow = '';
            // Back where they were, or the button is lost for keyboard users.
            if (last && last.focus) { last.focus(); }
        }

        open.addEventListener('click', show);
        document.getElementById('nw-aff-share-close').addEventListener('click', hide);
        document.getElementById('nw-aff-share-backdrop').addEventListener('click', hide);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('is-open')) { hide(); }
        });
    })();
    </script>
    <?php
}

/**
 * Someone sending us the URL of a post they made.
 *
 * A form rather than a mailto: a mailto hands the job to whatever mail client
 * the machine happens to have configured, which on a lot of machines is
 * nothing at all, and the person is left staring at a dialog they cannot use.
 */
function nibwp_affiliate_handle_share(): void
{
    if (!current_user_can('manage_options') || !check_admin_referer('nibwp_affiliate_share')) {
        wp_die(esc_html__('You are not allowed to do that.', 'nibwp'), 403);
    }

    $url  = esc_url_raw(trim((string) wp_unslash($_POST['nibwp_aff_share_url'] ?? '')));
    $note = sanitize_textarea_field(wp_unslash($_POST['nibwp_aff_share_note'] ?? ''));

    if ($url === '' || !wp_http_validate_url($url)) {
        wp_safe_redirect(nibwp_affiliate_page_url(['nibwp_aff' => 'share_bad_url']));
        exit();
    }

    $sent = nibwp_affiliate_email_share($url, $note);
    wp_safe_redirect(nibwp_affiliate_page_url(['nibwp_aff' => $sent ? 'shared' : 'share_failed']));
    exit();
}

/**
 * Mail us a post someone wants credit for.
 *
 * Carries who and where from the server rather than the form, so the message
 * identifies the account even when the note is left blank.
 */
function nibwp_affiliate_email_share(string $url, string $note = ''): bool
{
    $user   = wp_get_current_user();
    $status = nibwp_affiliate_status();

    $lines = [
        sprintf('From:      %s <%s>', $user->display_name, $user->user_email),
        sprintf('Site:      %s', home_url()),
        sprintf('Affiliate: %s', $status['affiliate_id'] ?? '(not linked yet)'),
        '',
        sprintf('Posted at: %s', $url),
        '',
        'Their note:',
        $note !== '' ? $note : '(none)',
    ];

    return (bool) wp_mail(
        nibwp_affiliate_notify_email(),
        sprintf('NibWP post shared — %s', $user->display_name),
        implode("\n", $lines),
        [
            'Content-Type: text/plain; charset=UTF-8',
            sprintf('Reply-To: %s <%s>', $user->display_name, $user->user_email),
        ]
    );
}

/** Where applications go when the API cannot take them. */
function nibwp_affiliate_notify_email(): string
{
    return (string) apply_filters('nibwp_affiliate_notify_email', nibwp_support_email());
}

/**
 * Send the application by email as a fallback.
 *
 * Reply-To is the applicant, so answering it goes to them rather than to the
 * site the mail was sent from. Returns whatever wp_mail says — which is
 * "handed to the mail server", not "delivered", so nothing downstream should
 * claim more than that.
 */
function nibwp_affiliate_email_application(array $submission): bool
{
    $lines = [
        sprintf('Name:      %s', $submission['name']),
        sprintf('Email:     %s', $submission['email']),
        sprintf('Site:      %s', home_url()),
        sprintf('Version:   %s', defined('NIBWP_VERSION') ? NIBWP_VERSION : 'unknown'),
        '',
        'How they plan to promote NibWP:',
        $submission['promotion'] !== '' ? $submission['promotion'] : '(not answered)',
        '',
        'Sent from the plugin because the affiliate API did not accept the signup.',
    ];

    return (bool) wp_mail(
        nibwp_affiliate_notify_email(),
        sprintf('NibWP affiliate application — %s', $submission['name']),
        implode("\n", $lines),
        [
            'Content-Type: text/plain; charset=UTF-8',
            sprintf('Reply-To: %s <%s>', $submission['name'], $submission['email']),
        ]
    );
}

/** Returns an error message, or null when the submission is usable. */
function nibwp_affiliate_validate(array $submission): ?string
{
    if ($submission['name'] === '') {
        return __('Please tell us your name.', 'nibwp');
    }
    if ($submission['email'] === '' || !is_email($submission['email'])) {
        return __('That email address does not look right. Commission is paid against it, so it needs to be one you read.', 'nibwp');
    }
    if (empty($submission['consent'])) {
        return __('We need your agreement before sending your details to nibwp.com.', 'nibwp');
    }

    return null;
}

/**
 * Relay the signup to nibwp.com.
 *
 * @return array|WP_Error The new status on success.
 */
function nibwp_affiliate_submit(array $submission)
{
    $response = wp_remote_post(nibwp_affiliate_api_base() . '/affiliate-signup', [
        'timeout' => 15,
        'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
        'body'    => wp_json_encode([
            'name'      => $submission['name'],
            'email'     => $submission['email'],
            // Taken here rather than from the form: the site being credited is
            // this one, and nothing the browser sent should be able to say otherwise.
            'site_url'  => home_url(),
            'promotion' => $submission['promotion'] ?? '',
            'source'    => 'plugin',
            'version'   => defined('NIBWP_VERSION') ? NIBWP_VERSION : '',
        ]),
    ]);

    if (is_wp_error($response)) {
        return new WP_Error(
            'nibwp_affiliate_unreachable',
            /* translators: %s: the underlying transport error. */
            sprintf(__('We could not reach nibwp.com (%s).', 'nibwp'), $response->get_error_message())
        );
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $body = json_decode((string) wp_remote_retrieve_body($response), true);

    if ($code !== 200 && $code !== 201) {
        $message = is_array($body) && !empty($body['message'])
            ? (string) $body['message']
            /* translators: %d: HTTP status code. */
            : sprintf(__('nibwp.com refused the signup (HTTP %d).', 'nibwp'), $code);

        return new WP_Error('nibwp_affiliate_refused', $message);
    }

    if (!is_array($body) || empty($body['state'])) {
        return new WP_Error(
            'nibwp_affiliate_bad_response',
            __('nibwp.com answered, but not with anything we could read. Your signup may not have gone through.', 'nibwp')
        );
    }

    return [
        'state'        => (string) $body['state'],
        'affiliate_id' => (string) ($body['affiliate_id'] ?? ''),
        'referral_url' => esc_url_raw((string) ($body['referral_url'] ?? '')),
        'email'        => $submission['email'],
        'joined_at'    => time(),
    ];
}

/** Keep a failed attempt (and what they typed) long enough to redraw the form. */
function nibwp_affiliate_stash_failure(string $message, array $submission): void
{
    set_transient('nibwp_affiliate_failure_' . get_current_user_id(), [
        'message'    => $message,
        'name'       => $submission['name'],
        'email'      => $submission['email'],
        'promotion'  => $submission['promotion'],
    ], 5 * MINUTE_IN_SECONDS);
}

// ── Rendering ───────────────────────────────────────────────────────────────

function nibwp_render_affiliate_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    nibwp_render_admin_header();

    $status = nibwp_affiliate_status();

    if (in_array($status['state'], ['pending', 'approved'], true)) {
        nibwp_affiliate_render_joined($status);
    } else {
        nibwp_affiliate_render_pitch();
    }

    nibwp_render_admin_footer();
}

/** The state after signing up: their links and status. Never pitches again. */
function nibwp_affiliate_render_joined(array $status): void
{
    $approved = $status['state'] === 'approved';
    ?>
    <div class="nw-aff nibwp-dash-card nw-aff--joined">
        <div class="nw-aff__badge <?php echo $approved ? 'is-approved' : 'is-pending'; ?>">
            <?php echo $approved ? esc_html__('Approved', 'nibwp') : esc_html__('Awaiting review', 'nibwp'); ?>
        </div>
        <h1 class="nw-aff__title">
            <?php echo $approved
                ? esc_html__("You're a NibWP affiliate", 'nibwp')
                : esc_html__("You're on the list", 'nibwp'); ?>
        </h1>
        <p class="nw-aff__sub">
            <?php if ($approved) : ?>
                <?php esc_html_e('Share your link. Anything bought through it in the next 60 days is credited to you.', 'nibwp'); ?>
            <?php else : ?>
                <?php esc_html_e('We review applications by hand, usually within a working day. You will get an email at the address you gave us.', 'nibwp'); ?>
            <?php endif; ?>
        </p>

        <?php if (!empty($status['referral_url'])) : ?>
            <div class="nw-aff__link-row">
                <label class="nw-aff__label" for="nw-aff-link"><?php esc_html_e('Your referral link', 'nibwp'); ?></label>
                <div class="nw-aff__copy">
                    <input type="text" id="nw-aff-link" class="nw-aff__link" readonly value="<?php echo esc_attr($status['referral_url']); ?>">
                    <button type="button" class="nw-aff__btn nw-aff__btn--copy" data-copy="<?php echo esc_attr($status['referral_url']); ?>">
                        <?php esc_html_e('Copy', 'nibwp'); ?>
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <p class="nw-aff__meta">
            <?php // No dashboard link for an application that only reached us by
                  // email — there is nothing to log into yet. ?>
            <?php if ($approved || !empty($status['referral_url'])) : ?>
                <a class="nw-aff__btn nw-aff__btn--ghost" href="<?php echo esc_url(nibwp_affiliate_public_url()); ?>" target="_blank" rel="noopener">
                    <?php esc_html_e('Open affiliate dashboard', 'nibwp'); ?>
                </a>
            <?php endif; ?>
            <?php if (!empty($status['email'])) : ?>
                <span class="nw-aff__note">
                    <?php
                    /* translators: %s: the email address the account was opened with. */
                    printf(esc_html__('Account email: %s', 'nibwp'), '<strong>' . esc_html($status['email']) . '</strong>');
                    ?>
                </span>
            <?php endif; ?>
        </p>
    </div>
    <?php
    nibwp_affiliate_render_copy_script();
}


/**
 * Icons for the perk cards, by position.
 *
 * Deliberately positional rather than sent with the perk: an icon is a design
 * decision, and the endpoint should not be able to put arbitrary SVG on the
 * page. Cycles, so a longer perk list from nibwp.com still renders complete.
 */
function nibwp_affiliate_perk_icon(int $index): string
{
    $paths = [
        '<path d="M12 2v20"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>',
        '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        '<path d="M3 21h18"/><path d="M5 21V9l7-6 7 6v12"/><path d="M10 21v-6h4v6"/>',
        '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/>',
        '<path d="M3 3v18h18"/><path d="m7 14 4-4 3 3 5-6"/>',
        '<path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>',
    ];

    return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">'
        . $paths[$index % count($paths)]
        . '</svg>';
}

function nibwp_affiliate_render_pitch(): void
{
    $offer   = nibwp_affiliate_offer();
    $failure = get_transient('nibwp_affiliate_failure_' . get_current_user_id());
    $failure = is_array($failure) ? $failure : [];
    $user    = wp_get_current_user();

    $name  = $failure['name'] ?? trim($user->first_name . ' ' . $user->last_name);
    $name  = $name !== '' ? $name : $user->display_name;
    $email = $failure['email'] ?? $user->user_email;
    ?>
    <?php // Two page-level columns, not two columns inside the hero: the form
          // is sticky, and a sticky element can only travel as far as its own
          // container. Beside the hero alone it unsticks after one screen. ?>
    <?php nibwp_affiliate_render_share_result(); ?>

    <div class="nw-aff nw-aff__hero">
        <div class="nw-aff__col">

            <div class="nibwp-dash-card nw-aff__hero-copy">
                <div class="nw-aff__eyebrow">
                    <span class="nw-aff__pip"></span>
                    <?php esc_html_e('NibWP Affiliate Program', 'nibwp'); ?>
                </div>

                <h1 class="nw-aff__title"><?php echo esc_html($offer['headline']); ?></h1>

                <p class="nw-aff__sub">
                    <?php esc_html_e('You are already recommending NibWP — on client sites, in a video, a newsletter, a group. This is where that starts paying you back.', 'nibwp'); ?>
                </p>

                <p class="nw-aff__sub nw-aff__sub--tight">
                    <?php esc_html_e('Every license bought through your link pays you a quarter of the order value, and the link keeps working long after you posted it.', 'nibwp'); ?>
                </p>

                <ul class="nw-aff__stats">
                    <li>
                        <strong><?php echo esc_html($offer['rate']); ?></strong>
                        <span><?php esc_html_e('Commission', 'nibwp'); ?></span>
                    </li>
                    <li>
                        <strong><?php
                            $cookie_days = (int) $offer['cookie_days'];
                            printf(
                                /* translators: %s: number of days the referral cookie lasts */
                                esc_html(_n('%s day', '%s days', $cookie_days, 'nibwp')),
                                esc_html(number_format_i18n($cookie_days))
                            );
                        ?></strong>
                        <span><?php esc_html_e('Cookie window', 'nibwp'); ?></span>
                    </li>
                    <li>
                        <strong><?php echo esc_html($offer['payout_min']); ?></strong>
                        <span><?php esc_html_e('Payout minimum', 'nibwp'); ?></span>
                    </li>
                </ul>

                <p class="nw-aff__payout">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                    <?php echo esc_html($offer['payout_schedule']); ?>
                </p>

                <?php nibwp_affiliate_render_estimator($offer); ?>
            </div>

            <div class="nw-aff__section-head">
            <h2><?php esc_html_e('What you get', 'nibwp'); ?></h2>
        </div>

        <div class="nw-aff__perks">
            <?php foreach (array_values($offer['perks']) as $index => $perk) : ?>
                <div class="nibwp-dash-card nw-aff__perk">
                    <div class="nibwp-dash-card-title">
                        <?php echo nibwp_affiliate_perk_icon((int) $index); ?>
                        <?php echo esc_html((string) $perk['title']); ?>
                    </div>
                    <?php if (!empty($perk['body'])) : ?>
                        <p><?php echo esc_html((string) $perk['body']); ?></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php nibwp_affiliate_render_share_offer($offer); ?>

        <div class="nibwp-dash-card nw-aff__steps">
            <?php
            $steps = [
                [__('Apply', 'nibwp'), __('The form on this page. We read every application by hand, usually the same day.', 'nibwp')],
                [__('Share your link', 'nibwp'), __('One link, put it wherever your people already are — a site, a video, a newsletter, a group, a proposal.', 'nibwp')],
                [__('Get paid monthly', 'nibwp'), __('Every renewal counts, for as long as the customer stays with us.', 'nibwp')],
            ];
            foreach ($steps as $index => [$title, $body]) :
                ?>
                <div class="nw-aff__step">
                    <span class="nw-aff__step-num"><?php echo esc_html((string) ($index + 1)); ?></span>
                    <div>
                        <h4><?php echo esc_html($title); ?></h4>
                        <p><?php echo esc_html($body); ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="nibwp-dash-card nw-aff__faq">
            <?php
            $faq = [
                [
                    __('Do I need to be a customer?', 'nibwp'),
                    __('No. It helps, because you can talk about what NibWP actually does, but the program is open either way.', 'nibwp'),
                ],
                [
                    __('What happens if a customer refunds or cancels?', 'nibwp'),
                    __('Commission is paid on the first purchase, so a later cancellation does not affect what you have already earned. A refund or chargeback on that first order does reverse it — we cannot pay commission on money we gave back.', 'nibwp'),
                ],
                [
                    __('Where am I allowed to share my link?', 'nibwp'),
                    __('Anywhere you have an audience: your own sites, client sites you manage, YouTube, a newsletter, a course, a podcast, a Facebook or Slack group, a conference talk. The only things we ask you not to do are bid on our brand name in paid search, post it as spam, or pass it off as anything other than an affiliate link — when you are recommending it to a client, say so. They will find out anyway, and it is a small thing to lose their trust over.', 'nibwp'),
                ],
                [
                    __('Is there a cost, or a minimum?', 'nibwp'),
                    __('No cost and no quota. There is a payout minimum, and a balance below it simply rolls into the next month.', 'nibwp'),
                ],
            ];
            foreach ($faq as [$question, $answer]) :
                ?>
                <details class="nw-aff__faq-item">
                    <summary><?php echo esc_html($question); ?></summary>
                    <p><?php echo esc_html($answer); ?></p>
                </details>
            <?php endforeach; ?>
        </div>

        <div class="nw-aff__foot">
            <a href="<?php echo esc_url($offer['terms_url']); ?>" target="_blank" rel="noopener">
                <?php esc_html_e('Program terms', 'nibwp'); ?>
            </a>
            <a href="<?php echo esc_url(nibwp_affiliate_public_url()); ?>" target="_blank" rel="noopener">
                <?php esc_html_e('Read more on nibwp.com', 'nibwp'); ?>
            </a>
        </div>

        </div>

        <aside class="nw-aff__aside">
            <?php nibwp_affiliate_render_form($name, $email, $failure, $offer); ?>
        </aside>
    </div>
    <?php
    nibwp_affiliate_render_estimator_script($offer);
}

/**
 * A slider that turns "how many people would I refer" into money.
 *
 * Deliberately NOT a monthly figure, and no longer a multi-year one either.
 * Commission is paid once, on the first purchase — the program is configured
 * with renewals disabled — so both "per month, recurring" and "over three
 * years, if they stay" described money that would never arrive. What is shown
 * now is what a year of referrals earns, and what a single referral is worth.
 *
 * Labelled an estimate, and it says so on the page. Showing someone a number
 * they will treat as a promise is worse than showing them nothing.
 */
function nibwp_affiliate_render_estimator(array $offer): void
{
    $currency = (string) ($offer['currency'] ?? '€');
    ?>
    <div class="nibwp-dash-card nw-aff__calc">
        <div class="nw-aff__calc-head">
            <label for="nw-aff-sites"><?php esc_html_e('People you refer in a year', 'nibwp'); ?></label>
            <output class="nw-aff__calc-count" id="nw-aff-count" for="nw-aff-sites">10</output>
        </div>

        <input type="range" id="nw-aff-sites" class="nw-aff__range" min="1" max="50" step="1" value="10"
               aria-describedby="nw-aff-calc-note">

        <div class="nw-aff__calc-out" aria-live="polite">
            <div>
                <strong id="nw-aff-year"><?php echo esc_html($currency . '0'); ?></strong>
                <span><?php esc_html_e('commission that year', 'nibwp'); ?></span>
            </div>
            <div>
                <strong id="nw-aff-three"><?php echo esc_html($currency . '0'); ?></strong>
                <span><?php esc_html_e('for each person you refer', 'nibwp'); ?></span>
            </div>
        </div>

        <p class="nw-aff__calc-note" id="nw-aff-calc-note">
            <?php
            printf(
                /* translators: 1: commission rate, 2: average order value with currency. */
                esc_html__('An estimate, not a promise: %1$s of an average %2$s license, one license each. Commission is paid on the first purchase, not on renewals.', 'nibwp'),
                esc_html($offer['rate']),
                esc_html($currency . (int) $offer['avg_order'])
            );
            ?>
        </p>
    </div>
    <?php
}

function nibwp_affiliate_render_estimator_script(array $offer): void
{
    ?>
    <script>
    (function () {
        var slider = document.getElementById('nw-aff-sites');
        if (!slider) { return; }

        var config  = <?php echo wp_json_encode([
            'rate'     => (float) $offer['rate_percent'] / 100,
            'order'    => (float) $offer['avg_order'],
            'currency' => (string) ($offer['currency'] ?? '€'),
        ]); ?>;
        var count = document.getElementById('nw-aff-count');
        var year  = document.getElementById('nw-aff-year');
        var three = document.getElementById('nw-aff-three');

        function money(value) {
            return config.currency + Math.round(value).toLocaleString();
        }

        function update() {
            var people  = parseInt(slider.value, 10) || 0;
            var perYear = people * config.order * config.rate;
            var min     = parseInt(slider.min, 10) || 0;
            var max     = parseInt(slider.max, 10) || 100;

            count.textContent = people;
            year.textContent  = money(perYear);
            // What one referral is worth. This used to be three years of
            // renewals, which the program does not pay — renewals earn
            // nothing, so that figure overstated the return threefold.
            three.textContent = money(config.order * config.rate);
            slider.style.setProperty('--nw-aff-fill', ((people - min) / (max - min) * 100) + '%');
        }

        slider.addEventListener('input', update);
        update();
    })();
    </script>
    <?php
}

function nibwp_affiliate_render_form(string $name, string $email, array $failure, array $offer): void
{
    ?>
    <form class="nibwp-dash-card nw-aff__form" id="nw-aff-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('nibwp_affiliate_signup'); ?>
        <input type="hidden" name="action" value="nibwp_affiliate_signup">

        <h2 class="nw-aff__form-title"><?php esc_html_e('Join in 30 seconds', 'nibwp'); ?></h2>
        <p class="nw-aff__form-sub"><?php esc_html_e('Free, no exclusivity, leave whenever you like.', 'nibwp'); ?></p>

        <?php if (!empty($failure['message'])) : ?>
            <div class="nw-aff__error" role="alert">
                <strong><?php esc_html_e('That did not go through.', 'nibwp'); ?></strong>
                <span><?php echo esc_html((string) $failure['message']); ?></span>
                <a href="<?php echo esc_url(nibwp_affiliate_apply_url()); ?>" target="_blank" rel="noopener">
                    <?php esc_html_e('Sign up on nibwp.com instead', 'nibwp'); ?>
                </a>
            </div>
        <?php endif; ?>

        <div class="nw-aff__field">
            <label class="nw-aff__label" for="nw-aff-name"><?php esc_html_e('Your name', 'nibwp'); ?></label>
            <input type="text" id="nw-aff-name" name="nibwp_aff_name" required value="<?php echo esc_attr($name); ?>">
        </div>

        <div class="nw-aff__field">
            <label class="nw-aff__label" for="nw-aff-email"><?php esc_html_e('Email for payouts', 'nibwp'); ?></label>
            <input type="email" id="nw-aff-email" name="nibwp_aff_email" required value="<?php echo esc_attr($email); ?>">
        </div>

        <div class="nw-aff__field">
            <label class="nw-aff__label" for="nw-aff-site"><?php esc_html_e('This site', 'nibwp'); ?></label>
            <input type="text" id="nw-aff-site" value="<?php echo esc_attr(home_url()); ?>" readonly>
        </div>

        <div class="nw-aff__field">
            <label class="nw-aff__label" for="nw-aff-promotion"><?php esc_html_e('How will you promote NibWP?', 'nibwp'); ?></label>
            <textarea id="nw-aff-promotion" name="nibwp_aff_promotion" rows="3" placeholder="<?php esc_attr_e('Client sites, your own sites, YouTube, a newsletter, a course, a community — whatever it is.', 'nibwp'); ?>"><?php echo esc_textarea((string) ($failure['promotion'] ?? '')); ?></textarea>
        </div>

        <label class="nw-aff__consent">
            <input type="checkbox" name="nibwp_aff_consent" value="1" required>
            <span>
                <?php
                printf(
                    /* translators: 1: privacy policy link, 2: terms link. */
                    esc_html__('Send my name, email and site address to nibwp.com to open an affiliate account. %1$s · %2$s', 'nibwp'),
                    '<a href="' . esc_url($offer['privacy_url']) . '" target="_blank" rel="noopener">' . esc_html__('Privacy', 'nibwp') . '</a>',
                    '<a href="' . esc_url($offer['terms_url']) . '" target="_blank" rel="noopener">' . esc_html__('Terms', 'nibwp') . '</a>'
                );
                ?>
            </span>
        </label>

        <button type="submit" class="nw-aff__btn nw-aff__btn--primary">
            <?php esc_html_e('Apply to join', 'nibwp'); ?>
        </button>
    </form>
    <?php
}

function nibwp_affiliate_render_copy_script(): void
{
    ?>
    <script>
    (function () {
        document.querySelectorAll('.nw-aff__btn--copy').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var value = btn.getAttribute('data-copy') || '';
                var done = function () {
                    var original = btn.textContent;
                    btn.textContent = <?php echo wp_json_encode(__('Copied', 'nibwp')); ?>;
                    setTimeout(function () { btn.textContent = original; }, 1600);
                };
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(value).then(done, done);
                    return;
                }
                var field = document.getElementById('nw-aff-link');
                if (field) { field.select(); document.execCommand('copy'); done(); }
            });
        });
    })();
    </script>
    <?php
}
