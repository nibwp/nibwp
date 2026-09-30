<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The Connect page as three steps you can follow.
 *
 * The page always promised "three quick steps" in its own copy and then
 * rendered seven stacked sections with seventeen buttons, a tabbed fork in the
 * middle, and — the part that really hurt — two separate client pickers. The
 * sign-in pane asked which AI tool you use, and so did the password pane, from
 * different lists. Whichever you answered, the other one was still sitting
 * there asking again.
 *
 * So the client is asked for once, at the top, and everything below follows
 * from it. Nobody is asked to choose between OAuth and an application password:
 * that is a decision about protocol support, which the page knows and the
 * reader does not.
 *
 * Deliberately not a wizard that hides finished steps. This is not one-time
 * onboarding — people come back to grab the config for a second client or mint
 * a fresh key. Completed steps collapse to a single summary line and reopen on
 * click, so a return visit is one glance and one click rather than a flow to
 * walk through again.
 *
 * Every pane here is an existing renderer. This file is composition and shell;
 * it does not reimplement the toggle, the password form, the config blocks or
 * the OAuth panes.
 */

/**
 * The clients the page can set up, and what each one can actually do.
 *
 * `oauth`: the client speaks remote MCP, so it can sign in and never holds a
 * credential. `local`: the client runs on this machine, which decides whether a
 * site on a private hostname is reachable at all.
 *
 * @return array<string, array{label: string, oauth: bool, local: bool}>
 */
function nibwp_connect_clients(): array
{
    $oauth_capable = function_exists('nibwp_oauth_remote_client_labels')
        ? nibwp_oauth_remote_client_labels()
        : [];

    // Browser assistants run in a vendor's cloud; everything else runs here.
    $cloud = ['claude-ai' => true, 'chatgpt' => true];

    $clients = [
        'claude-ai'      => 'Claude.ai',
        'claude-desktop' => 'Claude Desktop',
        'chatgpt'        => 'ChatGPT',
        'claude-code'    => 'Claude Code',
        'cursor'         => 'Cursor',
        'vscode'         => 'VS Code',
        'github-copilot' => 'GitHub Copilot',
        'antigravity'    => 'Antigravity',
        'windsurf'       => 'Windsurf',
        'cline'          => 'Cline',
        'roo-code'       => 'Roo Code',
        'kilo-code'      => 'Kilo Code',
        'codex'          => 'Codex',
        'gemini-cli'     => 'Gemini CLI',
        'opencode'       => 'OpenCode',
        'amazon-q'       => 'Amazon Q',
        'zed'            => 'Zed',
    ];

    $out = [];
    foreach ($clients as $key => $label) {
        $out[$key] = [
            'label' => $label,
            'oauth' => isset($oauth_capable[$key]),
            'local' => !isset($cloud[$key]),
        ];
    }

    return $out;
}

/**
 * Which route a given client should be shown.
 *
 * Returns 'oauth' or 'password'. A client that speaks remote MCP signs in —
 * unless it runs in a vendor's cloud and this site is on an address that cloud
 * cannot reach, in which case sign-in is a dead end and the page must not offer
 * it as though it were a choice.
 */
function nibwp_connect_route_for(string $client): string
{
    $clients = nibwp_connect_clients();
    $info = $clients[$client] ?? null;
    if ($info === null || !$info['oauth']) {
        return 'password';
    }

    $available = function_exists('nibwp_oauth_availability') ? nibwp_oauth_availability() : ['ok' => false];
    if (empty($available['ok'])) {
        return 'password';
    }

    $reachable = function_exists('nibwp_oauth_host_is_cloud_reachable')
        ? nibwp_oauth_host_is_cloud_reachable()
        : true;

    if (!$info['local'] && !$reachable) {
        return 'password';
    }

    return 'oauth';
}

/**
 * One step's frame: number, title, state, and a summary shown when collapsed.
 *
 * @param 'done'|'current'|'locked' $state
 */
function nibwp_connect_step_open(int $n, string $title, string $state, string $summary = '', string $id = ''): void
{
    $classes = 'nw-cf-step is-' . $state;
    ?>
    <section class="<?php echo esc_attr($classes); ?>"
             data-cf-step="<?php echo (int) $n; ?>"
             <?php echo $id !== '' ? 'id="' . esc_attr($id) . '"' : ''; ?>>
        <header class="nw-cf-step__head">
            <span class="nw-cf-step__n" aria-hidden="true"><?php
                echo $state === 'done'
                    ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>'
                    : (int) $n;
            ?></span>
            <h2 class="nw-cf-step__title"><?php echo esc_html($title); ?></h2>
            <?php if ($summary !== ''): ?>
                <span class="nw-cf-step__summary"><?php echo esc_html($summary); ?></span>
            <?php endif; ?>
        </header>
        <div class="nw-cf-step__body">
    <?php
}

function nibwp_connect_step_close(): void
{
    ?>
        </div>
    </section>
    <?php
}

/**
 * Step 3 — how to connect.
 *
 * The route the client can use is worked out for them and pre-selected, but it
 * is shown as a choice rather than decided silently: somebody may want a
 * password even where sign-in works, and burying that in a link under the panel
 * made it look unsupported.
 *
 * Where sign-in genuinely cannot work — a cloud assistant against a private
 * hostname, or OAuth switched off for the site — the option is disabled with
 * the reason rather than offered and then failing.
 */
function nibwp_connect_oauth_blocker(string $client): string
{
    if ($client === '' || nibwp_connect_route_for($client) === 'oauth') {
        return '';
    }

    $info = nibwp_connect_clients()[$client] ?? null;
    if ($info === null) {
        return __('This tool has no remote MCP support, so it cannot sign in.', 'nibwp');
    }

    $available = function_exists('nibwp_oauth_availability') ? nibwp_oauth_availability() : ['ok' => true];
    if (empty($available['ok'])) {
        return __('Sign-in is not available on this site yet.', 'nibwp');
    }

    // Switched on and on https is not the same as reachable. Some hosts block
    // /.well-known or strip the challenge header, and until this asked, the
    // page recommended a route that could only fail — in the customer's AI
    // client, with a message from their vendor rather than from us.
    if (function_exists('nibwp_oauth_discovery_probe')) {
        $served = nibwp_oauth_discovery_probe();
        if (empty($served['ok'])) {
            return (string) $served['message'];
        }
    }
    if (!$info['oauth']) {
        return sprintf(
            /* translators: %s: the AI client's name */
            __('%s cannot sign in — it has no remote MCP support.', 'nibwp'),
            $info['label']
        );
    }

    return __('This site is on a local address, which an assistant running in the cloud cannot reach.', 'nibwp');
}

function nibwp_connect_render_method_picker(string $client, string $chosen): void
{
    $why = nibwp_connect_oauth_blocker($client);
    $can_oauth = $client !== '' && $why === '';
    // A shield for the route that holds no credential, a key for the one that
    // mints one. The icon carries the difference faster than the copy does.
    $icons = [
        'oauth'    => '<path d="M12 3 4 6.5v5c0 4.4 3.2 8.4 8 9.5 4.8-1.1 8-5.1 8-9.5v-5L12 3Z"/><path d="m9 12 2 2 4-4"/>',
        'password' => '<circle cx="8" cy="12" r="3.5"/><path d="M11.5 12H20"/><path d="M17 12v3"/><path d="M20 12v2.5"/>',
    ];

    // Application password leads because it works on every host. Signing in is
    // the better route where it works — nothing stored, revocable — but it
    // depends on the host serving discovery, and enough of them do not that
    // recommending it first sent people into an opaque failure in their own
    // AI client. It is still offered, still marked as the lighter option, and
    // withdrawn with a reason when the probe above proves it cannot work.
    $methods = [
        'password' => [
            'title' => __('Application password', 'nibwp'),
            'note'  => __('Works with every client and every host. Creates a key you paste into its config.', 'nibwp'),
            'meta'  => __('Universal · one copy & paste', 'nibwp'),
            'ok'    => true,
        ],
        'oauth' => [
            'title' => __('Sign in', 'nibwp'),
            'note'  => $can_oauth ? __('Approve it once. Nothing to copy, no password stored anywhere.', 'nibwp') : $why,
            'meta'  => __('Nothing to store · needs host support', 'nibwp'),
            'ok'    => $can_oauth,
        ],
    ];

    // What this client would pick on its own, so the badge follows the client
    // rather than being pinned to one route in the markup.
    // The application password is what we put our name behind: it works on
    // every client and every host, and its failure modes are ours to explain.
    // Sign-in stays available and is the lighter option where the host serves
    // it, but recommending it by default meant recommending something we
    // could not guarantee.
    $recommended = 'password';
    ?>
    <div class="nw-cf-methods" role="radiogroup" aria-label="<?php esc_attr_e('Connection method', 'nibwp'); ?>">
        <?php foreach ($methods as $key => $m): ?>
            <button type="button"
                    class="nw-cf-method<?php echo $chosen === $key ? ' is-active' : ''; ?><?php echo $m['ok'] ? '' : ' is-disabled'; ?>"
                    role="radio"
                    aria-checked="<?php echo $chosen === $key ? 'true' : 'false'; ?>"
                    <?php echo $m['ok'] ? '' : 'disabled'; ?>
                    data-cf-method="<?php echo esc_attr($key); ?>">
                <span class="nw-cf-method__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
                         stroke-linecap="round" stroke-linejoin="round"><?php
                        echo $icons[$key]; // phpcs:ignore -- literal markup above, no input.
                    ?></svg>
                </span>
                <span class="nw-cf-method__body">
                    <span class="nw-cf-method__title">
                        <?php echo esc_html($m['title']); ?>
                        <?php // Rendered on both, shown on one: the recommendation follows the
                              // client, which can change without a reload. ?>
                        <span class="nw-cf-pill"<?php echo $m['ok'] && $key === $recommended ? '' : ' hidden'; ?>><?php
                            esc_html_e('Recommended', 'nibwp');
                        ?></span>
                    </span>
                    <span class="nw-cf-method__note"><?php echo esc_html($m['note']); ?></span>
                    <span class="nw-cf-method__meta"><?php echo esc_html($m['meta']); ?></span>
                </span>
                <span class="nw-cf-method__dot" aria-hidden="true"></span>
            </button>
        <?php endforeach; ?>
    </div>
    <?php
}

/**
 * Step 2 — the one place the page asks which AI tool you use.
 */
function nibwp_connect_render_client_picker(string $selected): void
{
    $clients = nibwp_connect_clients();
    ?>
    <p class="nw-cf-hint"><?php esc_html_e('Pick the tool you want to connect. The next step changes to match it.', 'nibwp'); ?></p>
    <?php // One line that scrolls under the mouse, like the strips on
          // Integrations and the dashboard — seventeen chips wrapped to three
          // rows made the step look heavier than the question it asks. ?>
    <div class="nw-int-tabs-wrap nw-cf-strip" id="nw-cf-clients-wrap">
    <div class="nw-int-tabs nw-cf-clients" id="nw-cf-clients" role="radiogroup" aria-label="<?php esc_attr_e('AI tool', 'nibwp'); ?>">
        <?php foreach ($clients as $key => $info): ?>
            <button type="button"
                    class="nw-cf-client<?php echo $key === $selected ? ' is-active' : ''; ?>"
                    role="radio"
                    aria-checked="<?php echo $key === $selected ? 'true' : 'false'; ?>"
                    data-cf-client="<?php echo esc_attr($key); ?>"
                    data-cf-route="<?php echo esc_attr(nibwp_connect_route_for($key)); ?>"
                    data-cf-reason="<?php echo esc_attr(nibwp_connect_oauth_blocker($key)); ?>">
                <span class="nw-cf-client__label"><?php echo esc_html($info['label']); ?></span>
            </button>
        <?php endforeach; ?>
    </div>
    </div>
    <?php
}

/**
 * The whole flow. Panes for both routes are rendered and the picker reveals
 * one, which is how the page already shipped every client's config — the
 * switching was always client-side. Rendering both also keeps the sign-in pane
 * out from behind the "did this request just mint a password?" gate: it needs
 * no credential, and hiding it on the reload that follows the password form is
 * what used to make the page look like it had thrown the work away.
 */
function nibwp_render_connect_flow(
    string $rest_url,
    string $username,
    string $display_password,
    $new_password,
    $existing_password,
    $create_error,
    $existing_error
): void {
    $enabled = function_exists('nibwp_is_enabled') ? (bool) nibwp_is_enabled() : true;
    $has_key = $new_password !== null || $existing_password !== null;

    // Something just happened on the password form, so that is where the reader
    // is: keep them there rather than resetting to the client picker.
    $password_active = $has_key || $create_error !== null || $existing_error !== null;

    $clients = nibwp_connect_clients();
    $selected = (string) get_user_meta(get_current_user_id(), 'nibwp_connect_client', true);
    if (!isset($clients[$selected])) {
        $selected = $password_active ? 'vscode' : '';
    }

    // The method is a step of its own, so it is remembered like the client. The
    // route the client can use seeds it; the reader can still choose the other.
    $route = (string) get_user_meta(get_current_user_id(), 'nibwp_connect_method', true);

    // Seeding the route is a suggestion, not an answer. A seeded step that
    // collapsed itself as "done" hid the very choice this step exists to offer,
    // so only a method the reader actually picked folds the step away.
    $chosen = $route === 'oauth' || $route === 'password';
    if (!$chosen) {
        $route = $selected !== '' ? nibwp_connect_route_for($selected) : '';
    }
    // A client that cannot sign in must not sit on a remembered 'oauth'.
    if ($route === 'oauth' && ($selected === '' || nibwp_connect_route_for($selected) !== 'oauth')) {
        $route = 'password';
    }
    // Work on the password form outranks any of it: that is where the reader is,
    // and they have plainly settled the question.
    if ($password_active) {
        $route = 'password';
        $chosen = true;
    }

    $method_label = $route === 'oauth'
        ? __('Sign in', 'nibwp')
        : ($route === 'password' ? __('Application password', 'nibwp') : '');
    ?>
    <div class="nw-cf" id="nw-cf"
         data-cf-selected="<?php echo esc_attr($selected); ?>"
         data-cf-route="<?php echo esc_attr($route); ?>"
         data-cf-password-active="<?php echo $password_active ? '1' : '0'; ?>"
         data-cf-has-key="<?php echo $has_key ? '1' : '0'; ?>">

        <?php /* Step 1 — the toggle, unchanged, just framed. */ ?>
        <?php nibwp_connect_step_open(
            1,
            __('Turn on AI abilities', 'nibwp'),
            $enabled ? 'done' : 'current',
            $enabled ? __('On', 'nibwp') : ''
        ); ?>
            <?php nibwp_render_enable_toggle(); ?>
        <?php nibwp_connect_step_close(); ?>

        <?php nibwp_connect_step_open(
            2,
            __('Which AI tool are you using?', 'nibwp'),
            !$enabled ? 'locked' : ($selected !== '' ? 'done' : 'current'),
            $selected !== '' ? $clients[$selected]['label'] : ''
        ); ?>
            <?php nibwp_connect_render_client_picker($selected); ?>
        <?php nibwp_connect_step_close(); ?>

        <?php nibwp_connect_step_open(
            3,
            __('How do you want to connect?', 'nibwp'),
            !$enabled || $selected === '' ? 'locked' : ($chosen ? 'done' : 'current'),
            $chosen ? $method_label : '',
            'nw-cf-method'
        ); ?>
            <?php // Nothing is marked chosen until it is chosen: a filled radio over a
                  // locked step 4 said the decision was already made. ?>
            <?php nibwp_connect_render_method_picker($selected, $chosen ? $route : ''); ?>
        <?php nibwp_connect_step_close(); ?>

        <?php
        // Making the key and using it are two different jobs, and the password
        // route was doing both in one step: the form you had just finished with
        // stayed open above the thing you actually came for. So the key gets
        // step 4 and folds away once it exists, and the config becomes step 5.
        //
        // Signing in mints nothing, so on that route step 4 is the whole job
        // and there is no step 5 — the titles swap with the route.
        $step4_title = $route === 'password' ? __('Create your key', 'nibwp') : __('Connect it', 'nibwp');
        $step4_state = !$enabled || $selected === '' || !$chosen
            ? 'locked'
            : ($route === 'password' && $has_key ? 'done' : 'current');
        ?>
        <?php nibwp_connect_step_open(
            4,
            $step4_title,
            $step4_state,
            $route === 'password' && $has_key ? __('Key ready', 'nibwp') : '',
            'nw-cf-connect'
        ); ?>
            <div class="nw-cf-route" data-cf-pane="oauth"<?php echo $route === 'oauth' ? '' : ' hidden'; ?>>
                <?php nibwp_oauth_render_signin_pane($rest_url, nibwp_get_mcp_server_name_default()); ?>
            </div>

            <div class="nw-cf-route" data-cf-pane="password"<?php echo $route === 'password' ? '' : ' hidden'; ?>>
                <?php nibwp_render_password_step($new_password, $existing_password, $existing_error); ?>
            </div>
        <?php nibwp_connect_step_close(); ?>

        <?php // Only the password route has a fifth step; sign-in has nothing
              // to paste. Hidden rather than absent so the route can change
              // without a reload. ?>
        <div class="nw-cf-route" data-cf-pane="password" data-cf-step5<?php echo $route === 'password' ? '' : ' hidden'; ?>>
            <?php nibwp_connect_step_open(
                5,
                __('Connect your AI client', 'nibwp'),
                !$enabled || $selected === '' || !$chosen || !$has_key ? 'locked' : 'current',
                '',
                'nibwp-connect-client'
            ); ?>
                <?php if ($has_key) { nibwp_render_config_section($rest_url, $username, $display_password); } ?>
            <?php nibwp_connect_step_close(); ?>
        </div>
    </div>

    <?php /* Everything that is not the path out of the flow entirely. */ ?>
    <details class="nw-cf-manage">
        <summary class="nw-cf-manage__summary">
            <span><?php esc_html_e('Manage connections and keys', 'nibwp'); ?></span>
            <span class="nw-cf-manage__chev" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span>
        </summary>
        <div class="nw-cf-manage__body">
            <?php nibwp_oauth_render_connections(); ?>
            <?php nibwp_render_manage_passwords_section(allow_create_hint: true); ?>
            <?php nibwp_oauth_render_manual_clients(); ?>
        </div>
    </details>

    <script>
    (function () {
        var root = document.getElementById('nw-cf');
        if (!root) { return; }

        var steps = root.querySelectorAll('[data-cf-step]');
        var panes = root.querySelectorAll('[data-cf-pane]');

        var KEY_READY = <?php echo wp_json_encode(__('Key ready', 'nibwp')); ?>;
        var STEP4_PASSWORD = <?php echo wp_json_encode(__('Create your key', 'nibwp')); ?>;
        var STEP4_OAUTH = <?php echo wp_json_encode(__('Connect it', 'nibwp')); ?>;
        var OAUTH_LABEL = <?php echo wp_json_encode(__('Sign in', 'nibwp')); ?>;
        var PASSWORD_LABEL = <?php echo wp_json_encode(__('Application password', 'nibwp')); ?>;
        var OAUTH_NOTE = <?php echo wp_json_encode(__('Approve it once. Nothing to copy, no password stored anywhere.', 'nibwp')); ?>;

        function step(n) {
            for (var i = 0; i < steps.length; i++) {
                if (steps[i].getAttribute('data-cf-step') === String(n)) { return steps[i]; }
            }
            return null;
        }

        var TICK = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';

        function setState(el, state) {
            if (!el) { return; }
            el.classList.remove('is-done', 'is-current', 'is-locked');
            el.classList.add('is-' + state);

            // PHP draws a tick for a finished step; a step finished by clicking
            // has to agree, or one badge shows a tick and its neighbour a number
            // for the same state.
            var badge = el.querySelector('.nw-cf-step__n');
            if (!badge) { return; }
            if (state === 'done') {
                badge.innerHTML = TICK;
            } else if (badge.querySelector('svg')) {
                badge.textContent = el.getAttribute('data-cf-step');
            }
        }

        function summary(el, text) {
            if (!el) { return; }
            var s = el.querySelector('.nw-cf-step__summary');
            if (!s && text) {
                s = document.createElement('span');
                s.className = 'nw-cf-step__summary';
                el.querySelector('.nw-cf-step__head').appendChild(s);
            }
            if (s) { s.textContent = text || ''; }
        }

        // Both panes shipped with their own client picker. The client is chosen
        // once at step 2 now, so those strips are hidden and the panes are
        // switched from here instead.
        //
        // The sign-in pane's switcher used to live in the tab wrapper that this
        // flow replaced, so there is nothing left to click: its panes are
        // toggled directly, which is all that switcher did. The config block
        // still owns its own handler, so that one is driven by a click.
        function syncPaneClient(key) {
            var ocPanes = root.querySelectorAll('[data-oc-pane]');
            var matched = false;
            for (var i = 0; i < ocPanes.length; i++) {
                var on = ocPanes[i].getAttribute('data-oc-pane') === key;
                ocPanes[i].classList.toggle('is-active', on);
                if (on) { matched = true; }
            }
            // A client the sign-in pane has no entry for falls back to its
            // catch-all rather than showing nothing at all.
            if (!matched) {
                var other = root.querySelector('[data-oc-pane="other"]');
                if (other) { other.classList.add('is-active'); }
            }

            var cfg = root.querySelector('.nibwp-client-tab[data-client="' + key + '"]');
            if (cfg) { cfg.click(); }
        }

        // `decided` is the difference between preparing a route and choosing
        // it. Picking a client used to call this with no distinction, so step 3
        // went straight to done and step 4 opened — the method was decided for
        // the reader and the one real choice on the page was skipped past.
        function showRoute(route, decided) {
            for (var i = 0; i < panes.length; i++) {
                panes[i].hidden = panes[i].getAttribute('data-cf-pane') !== route;
            }

            // Step 4 is "create the key" on the password route and the whole
            // job on the sign-in one, and only the password route has a step 5
            // to paste it into.
            var s4 = step(4);
            if (s4) {
                var t = s4.querySelector('.nw-cf-step__title');
                if (t) { t.textContent = route === 'password' ? STEP4_PASSWORD : STEP4_OAUTH; }
            }

            // Only a chosen method is shown as chosen. Filling the radio while
            // step 3 is still the open question tells the reader the decision
            // has already been made for them.
            var methods = root.querySelectorAll('[data-cf-method]');
            for (var j = 0; j < methods.length; j++) {
                var on = decided && methods[j].getAttribute('data-cf-method') === route;
                methods[j].classList.toggle('is-active', on);
                methods[j].setAttribute('aria-checked', on ? 'true' : 'false');
            }

            if (!decided) {
                // Panes are ready underneath; step 3 stays the open question and
                // step 4 stays shut until it is answered.
                setState(step(3), 'current');
                summary(step(3), '');
                setState(step(4), 'locked');
                setState(step(5), 'locked');
                return;
            }

            root.setAttribute('data-cf-route', route);
            setState(step(3), 'done');
            summary(step(3), route === 'oauth' ? OAUTH_LABEL : PASSWORD_LABEL);

            // Coming back to a route whose key already exists lands on step 5,
            // not on the form that made it.
            var hasKey = root.getAttribute('data-cf-has-key') === '1';
            if (route === 'password' && hasKey) {
                setState(step(4), 'done');
                summary(step(4), KEY_READY);
                setState(step(5), 'current');
            } else {
                setState(step(4), 'current');
                summary(step(4), '');
                setState(step(5), 'locked');
            }
        }

        // Sign-in is offered only where it can actually work; the picker is
        // re-derived whenever the client changes so a remembered choice cannot
        // strand somebody on a method their new client does not support.
        function applyMethodAvailability(canOauth, reason) {
            var btn = root.querySelector('[data-cf-method="oauth"]');
            if (!btn) { return; }

            btn.disabled = !canOauth;
            btn.classList.toggle('is-disabled', !canOauth);

            var note = btn.querySelector('.nw-cf-method__note');
            if (note) { note.textContent = canOauth ? OAUTH_NOTE : (reason || ''); }

            // The badge stays on the application password. It used to follow
            // whatever the client could do, which quietly undid the server's
            // recommendation the moment a client was picked — PHP drew the pill
            // on password and this moved it straight back to sign-in.
            //
            // What we recommend does not depend on the client: the password
            // route works on every client and every host, and its failures are
            // ours to explain rather than a vendor's.
            var pills = root.querySelectorAll('.nw-cf-method .nw-cf-pill');
            for (var i = 0; i < pills.length; i++) {
                var owner = pills[i].closest('[data-cf-method]').getAttribute('data-cf-method');
                pills[i].hidden = owner !== 'password';
            }
        }

        // Remembering a choice is fire-and-forget: the page has already moved on
        // by the time this lands, so a failure costs the memory, not the click.
        function remember(what, value) {
            var f = document.getElementById('nw-cf-remember');
            if (!f || !window.fetch) { return; }
            f.querySelector('input[name="action"]').value = 'nibwp_connect_remember_' + what;
            f.querySelector('input[name="value"]').value = value;

            // Not `f.action`: the form owns an input named "action" (admin-post
            // demands it) and that input shadows the property, so `f.action` is
            // the element, not the URL. It posted to "[object HTMLInputElement]"
            // and 404'd silently — which is why the choice was never remembered.
            fetch(f.getAttribute('action'), {
                method: 'POST',
                body: new FormData(f),
                credentials: 'same-origin'
            }).catch(function () {});
        }

        function scrollToStep(n) {
            var el = step(n);
            if (el) { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
        }

        function chooseClient(pick) {
            var key = pick.getAttribute('data-cf-client');
            var reason = pick.getAttribute('data-cf-reason');

            var all = root.querySelectorAll('[data-cf-client]');
            for (var i = 0; i < all.length; i++) {
                var on = all[i] === pick;
                all[i].classList.toggle('is-active', on);
                all[i].setAttribute('aria-checked', on ? 'true' : 'false');
            }

            root.setAttribute('data-cf-selected', key);
            setState(step(2), 'done');
            summary(step(2), pick.textContent.trim());
            applyMethodAvailability(!reason, reason);

            // A remembered method survives a change of client — unless the new
            // client cannot use it, which is exactly when it must not.
            var current = root.getAttribute('data-cf-route');
            var route = (current === 'oauth' && reason) || !current
                ? pick.getAttribute('data-cf-route')
                : current;

            showRoute(route, false);
            syncPaneClient(key);
            remember('client', key);
            scrollToStep(3);
        }

        function chooseMethod(btn) {
            if (btn.disabled) { return; }
            var route = btn.getAttribute('data-cf-method');
            showRoute(route, true);
            remember('method', route);
            scrollToStep(4);
        }

        root.addEventListener('click', function (e) {
            var pick = e.target.closest('[data-cf-client]');
            if (pick) { chooseClient(pick); return; }

            var method = e.target.closest('[data-cf-method]');
            if (method) { chooseMethod(method); return; }

            var swap = e.target.closest('[data-cf-switch]');
            // Switching method is itself a choice, so it decides.
            if (swap) { showRoute(swap.getAttribute('data-cf-switch'), true); }
        });

        // Arrow keys walk a radiogroup — a keyboard reader lands on one button
        // and would otherwise have to tab through seventeen of them.
        root.addEventListener('keydown', function (e) {
            if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft' && e.key !== 'ArrowDown' && e.key !== 'ArrowUp') { return; }
            var group = e.target.closest('[role="radiogroup"]');
            if (!group) { return; }

            var items = [];
            var candidates = group.querySelectorAll('[role="radio"]');
            for (var i = 0; i < candidates.length; i++) {
                if (!candidates[i].disabled) { items.push(candidates[i]); }
            }
            var at = items.indexOf(e.target);
            if (at === -1) { return; }

            e.preventDefault();
            var next = e.key === 'ArrowRight' || e.key === 'ArrowDown' ? at + 1 : at - 1;
            next = (next + items.length) % items.length;
            items[next].focus();
            items[next].click();
        });

        // A finished step collapses; clicking its header opens it again.
        root.addEventListener('click', function (e) {
            var head = e.target.closest('.nw-cf-step__head');
            if (!head) { return; }
            var owner = head.parentElement;
            if (owner.classList.contains('is-locked')) { return; }
            owner.classList.toggle('is-open');
        });

        // The tool strip scrolls under the mouse: X position drives the speed,
        // faster toward the edges. Same behaviour as the strips on Integrations
        // and the dashboard, so the two pages do not feel like different apps.
        (function strip() {
            var wrap = document.getElementById('nw-cf-clients-wrap');
            var rail = document.getElementById('nw-cf-clients');
            if (!wrap || !rail) { return; }

            var rafId = null, velocity = 0;
            var DEAD_ZONE = 0.05, MAX_SPEED = 32;

            function shadows() {
                var max = rail.scrollWidth - rail.clientWidth;
                // Right to left, scrollLeft runs from 0 at the start down to -max at
                // the end, and the RTL stylesheet swaps the fades, so the distance
                // from the start is what both classes need.
                wrap.classList.toggle('has-scroll-left', Math.abs(rail.scrollLeft) > 2);
                wrap.classList.toggle('has-scroll-right', Math.abs(rail.scrollLeft) < max - 2);
            }

            function animate() {
                if (Math.abs(velocity) < 0.1) { rafId = null; return; }
                rail.scrollLeft += velocity;
                shadows();
                rafId = requestAnimationFrame(animate);
            }

            wrap.addEventListener('mousemove', function (e) {
                var rect = wrap.getBoundingClientRect();
                var offset = ((e.clientX - rect.left) / rect.width) * 2 - 1;
                if (Math.abs(offset) < DEAD_ZONE) {
                    velocity = 0;
                } else {
                    var sign = offset < 0 ? -1 : 1;
                    var mag = (Math.abs(offset) - DEAD_ZONE) / (1 - DEAD_ZONE);
                    velocity = sign * MAX_SPEED * mag;
                }
                if (velocity !== 0 && rafId === null) { rafId = requestAnimationFrame(animate); }
            });

            wrap.addEventListener('mouseleave', function () { velocity = 0; });

            // A wheel over a horizontal strip should move it, not the page.
            rail.addEventListener('wheel', function (e) {
                if (Math.abs(e.deltaY) <= Math.abs(e.deltaX)) { return; }
                var max = rail.scrollWidth - rail.clientWidth;
                if (max <= 0) { return; }
                // Wheel down moves toward the end of the strip, which is leftward
                // (negative scrollLeft) when the page reads right to left.
                var rtl = getComputedStyle(rail).direction === 'rtl';
                var next = Math.abs(rail.scrollLeft) + e.deltaY;
                // Only swallow the page scroll while there is somewhere to go.
                if (next > 0 && next < max) { e.preventDefault(); }
                rail.scrollLeft = rtl ? -next : next;
                shadows();
            }, { passive: false });

            rail.addEventListener('scroll', shadows);
            window.addEventListener('resize', shadows);
            shadows();

            // A remembered tool can sit off-screen in a strip this long.
            var active = rail.querySelector('.nw-cf-client.is-active');
            if (active && active.scrollIntoView) {
                active.scrollIntoView({ block: 'nearest', inline: 'center' });
            }
        })();

        // A remembered client arrives already chosen, but the panes inside the
        // sign-in renderer still default to whichever client it drew first —
        // so the page opened showing another client's panel, warning and all.
        // Nothing here changes state; it just makes the panes agree with the
        // choice PHP already rendered.
        (function init() {
            var selected = root.getAttribute('data-cf-selected');
            if (!selected) { return; }

            var pick = root.querySelector('[data-cf-client="' + selected + '"]');
            if (pick) { applyMethodAvailability(!pick.getAttribute('data-cf-reason'), pick.getAttribute('data-cf-reason')); }
            syncPaneClient(selected);
        })();
    })();
    </script>

    <?php // One form, two endpoints: the script rewrites `action` before posting. ?>
    <form id="nw-cf-remember" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" hidden>
        <input type="hidden" name="action" value="nibwp_connect_remember_client" />
        <input type="hidden" name="value" value="" />
        <?php wp_nonce_field('nibwp_connect_remember'); ?>
    </form>
    <?php
}

/**
 * Remember the chosen client so a return visit opens where they left off.
 *
 * Fire-and-forget from the picker: the page has already switched by the time
 * this runs, so a failure here costs the memory, not the interaction.
 */
add_action('admin_post_nibwp_connect_remember_client', static function (): void {
    nibwp_connect_remember('nibwp_connect_client', static function (string $v): bool {
        return isset(nibwp_connect_clients()[$v]);
    });
});

add_action('admin_post_nibwp_connect_remember_method', static function (): void {
    nibwp_connect_remember('nibwp_connect_method', static function (string $v): bool {
        return $v === 'oauth' || $v === 'password';
    });
});

/**
 * Store one remembered choice against the current user.
 *
 * Both endpoints write user meta from a POST, so both need the capability and
 * the nonce, and neither stores a value it does not recognise.
 */
function nibwp_connect_remember(string $meta_key, callable $is_valid): void
{
    if (!current_user_can('manage_options')) {
        wp_die('', '', ['response' => 403]);
    }
    check_admin_referer('nibwp_connect_remember');

    $value = isset($_POST['value']) ? sanitize_key(wp_unslash($_POST['value'])) : '';
    if ($is_valid($value)) {
        update_user_meta(get_current_user_id(), $meta_key, $value);
    }

    // Answer the beacon rather than redirecting: nothing is waiting on this.
    wp_send_json_success();
}
