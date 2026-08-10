<?php

declare(strict_types=1);

/**
 * User access screen.
 *
 * Owner-facing configuration for includes/user-access.php. Follows the Settings
 * form pattern (plain POST to self, nonce, no Settings API) and the Status page
 * chrome (cards, house buttons, inline icon set).
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Every administrator, as id => WP_User, with the owner first.
 *
 * The owner is included deliberately. Hiding them from their own screen meant a
 * row that could be configured and then silently disappeared the moment saving
 * claimed ownership, discarding the choice with it.
 *
 * @return array<int, WP_User>
 */
function nibwp_user_access_admins(): array
{
    $owner_id = nibwp_user_access_owner_id();

    $users = get_users([
        'capability' => 'manage_options',
        'orderby' => 'display_name',
        'order' => 'ASC',
    ]);

    $out = [];
    foreach ($users as $user) {
        $out[(int) $user->ID] = $user;
    }

    if ($owner_id > 0 && isset($out[$owner_id])) {
        $owner = $out[$owner_id];
        unset($out[$owner_id]);
        $out = [$owner_id => $owner] + $out;
    }

    return $out;
}

/**
 * Handle the save.
 *
 * @return bool|null True on save, null when there was nothing to do or the
 *                   caller is not entitled to save.
 */
function nibwp_handle_user_access_save(): ?bool
{
    if (!isset($_POST['nibwp_user_access_save'])) {
        return null;
    }
    if (!current_user_can('manage_options')) {
        return null;
    }
    check_admin_referer('nibwp_user_access_page');

    if (!nibwp_user_access_available()) {
        return null;
    }

    $config = nibwp_user_access_config();

    // Claim-on-save. Installs that predate this feature (or were activated by
    // WP-CLI, where there is no current user) have no owner recorded, so the
    // first administrator to save this screen becomes the owner.
    if (nibwp_user_access_owner_id() === 0) {
        $config['owner_id'] = get_current_user_id();
        nibwp_user_access_save($config);
        $config = nibwp_user_access_config();
    } elseif (!nibwp_user_access_is_owner()) {
        return null;
    }

    $config['enabled'] = !empty($_POST['nibwp_ua_enabled']);

    $owner_id = nibwp_user_access_owner_id();
    $admins = nibwp_user_access_admins();
    $modes = isset($_POST['nibwp_ua_mode']) && is_array($_POST['nibwp_ua_mode'])
        ? wp_unslash($_POST['nibwp_ua_mode'])
        : [];
    $pages = isset($_POST['nibwp_ua_pages']) && is_array($_POST['nibwp_ua_pages'])
        ? wp_unslash($_POST['nibwp_ua_pages'])
        : [];
    $chips = isset($_POST['nibwp_ua_chip']) && is_array($_POST['nibwp_ua_chip'])
        ? wp_unslash($_POST['nibwp_ua_chip'])
        : [];
    $notices = isset($_POST['nibwp_ua_notices']) && is_array($_POST['nibwp_ua_notices'])
        ? wp_unslash($_POST['nibwp_ua_notices'])
        : [];

    $users = [];
    foreach ($admins as $user_id => $user) {
        $choices = array_keys(nibwp_user_access_page_choices($user_id === $owner_id));

        // No row in the payload means the form never offered one. Keep whatever
        // is already stored rather than inventing an answer — defaulting to
        // 'all' here would hand out access on a malformed submit.
        if (!isset($modes[$user_id])) {
            if (isset($config['users'][$user_id])) {
                $users[$user_id] = $config['users'][$user_id];
            }
            continue;
        }

        $mode = sanitize_key((string) $modes[$user_id]);

        if ($mode === 'custom') {
            $selected = isset($pages[$user_id]) && is_array($pages[$user_id])
                ? array_map('sanitize_key', $pages[$user_id])
                : [];
            $selected = array_values(array_intersect($selected, $choices));

            // An empty custom set is indistinguishable from "hide everything",
            // so store it as such rather than as a list that resolves to nothing.
            if ($selected === []) {
                $entry_pages = 'none';
            } else {
                // The resolver adds Dashboard back regardless — it anchors the
                // top-level menu. Store it, so what is saved is what applies and
                // re-saving an untouched form is a no-op.
                if (!in_array('nibwp-dashboard', $selected, true)) {
                    array_unshift($selected, 'nibwp-dashboard');
                }
                $entry_pages = $selected;
            }
        } else {
            $entry_pages = $mode === 'none' ? 'none' : 'all';
        }

        $entry = [
            'pages' => $entry_pages,
            'chip' => !empty($chips[$user_id]),
            'notices' => !empty($notices[$user_id]),
        ];

        if (is_array($entry['pages'])) {
            sort($entry['pages']);
        }

        // Matching this user's own default is not worth a row: it would only
        // grow the option and would freeze today's default into storage.
        if ($entry === nibwp_user_access_default_entry($user_id)) {
            continue;
        }

        $users[$user_id] = $entry;
    }

    $config['users'] = $users;
    nibwp_user_access_save($config);

    nibwp_handle_branding_save();

    return true;
}

/**
 * Store the renaming half of the same form.
 *
 * Reset wins over the field values: the button that says Restore original
 * has to restore the original even when the inputs beside it still hold the
 * custom text the user is trying to get rid of.
 */
function nibwp_handle_branding_save(): void
{
    if (isset($_POST['nibwp_br_reset'])) {
        nibwp_branding_reset();

        return;
    }

    nibwp_branding_save([
        'enabled' => !empty($_POST['nibwp_br_enabled']),
        'name' => isset($_POST['nibwp_br_name']) ? wp_unslash($_POST['nibwp_br_name']) : '',
        'description' => isset($_POST['nibwp_br_description']) ? wp_unslash($_POST['nibwp_br_description']) : '',
        'author' => isset($_POST['nibwp_br_author']) ? wp_unslash($_POST['nibwp_br_author']) : '',
        'author_uri' => isset($_POST['nibwp_br_author_uri']) ? wp_unslash($_POST['nibwp_br_author_uri']) : '',
        'plugin_uri' => isset($_POST['nibwp_br_plugin_uri']) ? wp_unslash($_POST['nibwp_br_plugin_uri']) : '',
        'menu' => isset($_POST['nibwp_br_menu']) ? wp_unslash($_POST['nibwp_br_menu']) : '',
        'keep_icon' => true,
    ]);
}

/**
 * AJAX save, so the screen does not reload the whole admin to store a checkbox.
 *
 * Delegates to the same handler as the classic POST: one place decides what a
 * submission means, and the two paths cannot drift.
 */
add_action('wp_ajax_nibwp_user_access_save', static function (): void {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('You do not have permission to do this.', 'nibwp')], 403);
    }

    $result = nibwp_handle_user_access_save();

    if ($result !== true) {
        wp_send_json_error([
            'message' => nibwp_user_access_available()
                ? __('Only the owner can change these settings.', 'nibwp')
                : __('User access needs an active Pro license.', 'nibwp'),
        ], 403);
    }

    $config = nibwp_user_access_config();

    wp_send_json_success([
        'message' => __('User access saved.', 'nibwp'),
        // The sidebar and toolbar for the current user may have just changed,
        // so tell the page whether a reload is warranted.
        'reload' => nibwp_user_access_is_owner()
            && nibwp_user_access_visible_slugs(get_current_user_id()) !== null,
        'enabled' => $config['enabled'],
    ]);
});

/**
 * Render the screen.
 */
function nibwp_render_user_access_page(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to view this page.', 'nibwp'));
    }

    $saved = nibwp_handle_user_access_save();

    $config = nibwp_user_access_config();
    $owner_id = nibwp_user_access_owner_id();
    $is_owner = nibwp_user_access_is_owner();
    $unclaimed = $owner_id === 0;
    $can_edit = $is_owner || $unclaimed;
    $admins = nibwp_user_access_admins();
    $dashboard_url = admin_url('admin.php?page=nibwp-dashboard');

    nibwp_render_admin_header();
    ?>
    <div class="wrap nibwp-wrap nw-ua">
        <div class="nibwp-page-header">
            <div>
                <h1><?php esc_html_e('User access', 'nibwp'); ?></h1>
                <p class="nibwp-subtitle"><?php esc_html_e('Choose which administrators see NIBWP in their WordPress menu.', 'nibwp'); ?></p>
            </div>
        </div>

        <?php if ($saved === true): ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('User access saved.', 'nibwp'); ?></p></div>
        <?php endif; ?>

        <?php if (!$can_edit): ?>
            <div class="nibwp-card nw-ua-locked">
                <span class="nw-ua-locked__icon"><?php echo nibwp_status_icon('user', 20); ?></span>
                <div>
                    <h2 class="nibwp-card-title"><?php esc_html_e('Managed by someone else', 'nibwp'); ?></h2>
                    <p class="nw-ua-lead">
                        <?php
                        $owner = get_userdata($owner_id);
                        printf(
                            /* translators: %s: display name of the administrator who owns the configuration */
                            esc_html__('NIBWP menu visibility on this site is managed by %s. Ask them if you need a screen that is not showing for you.', 'nibwp'),
                            '<strong>' . esc_html($owner ? $owner->display_name : __('another administrator', 'nibwp')) . '</strong>'
                        );
                        ?>
                    </p>
                </div>
            </div>
        <?php else: ?>

            <?php if ($unclaimed): ?>
                <div class="nibwp-card nw-ua-claim">
                    <span class="nw-ua-locked__icon"><?php echo nibwp_status_icon('key', 20); ?></span>
                    <div>
                        <h2 class="nibwp-card-title"><?php esc_html_e('You will become the owner', 'nibwp'); ?></h2>
                        <p class="nw-ua-lead"><?php esc_html_e('Nobody manages menu visibility on this site yet. Saving this screen puts you in charge of it — after that, other administrators can see this page but not change it.', 'nibwp'); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field('nibwp_user_access_page'); ?>
                <?php // Every submit in this form reaches the handler, not just the save button, so Restore original works without JavaScript. ?>
                <input type="hidden" name="nibwp_user_access_save" value="1">

                <div class="nibwp-card">
                    <h2 class="nibwp-card-title">
                        <?php echo nibwp_status_icon('shield', 14); ?>
                        <?php esc_html_e('Restrict the menu', 'nibwp'); ?>
                    </h2>
                    <label class="nw-ua-switch">
                        <input type="checkbox" name="nibwp_ua_enabled" value="1" <?php checked($config['enabled']); ?>>
                        <span><?php esc_html_e('Control which administrators see NIBWP', 'nibwp'); ?></span>
                    </label>
                    <p class="nw-ua-lead"><?php esc_html_e('While this is off, every administrator sees NIBWP exactly as they do today.', 'nibwp'); ?></p>

                    <ul class="nw-ua-facts">
                        <li>
                            <?php echo nibwp_status_icon('link', 13); ?>
                            <span><?php esc_html_e('This hides menu items, it does not lock pages. Anyone with a direct link can still open a hidden screen — that is how you keep your own access.', 'nibwp'); ?></span>
                        </li>
                        <li>
                            <?php echo nibwp_status_icon('key', 13); ?>
                            <span><?php esc_html_e('Turning it on hides NIBWP from every other administrator by default, including any added later. Settings, License and this page are never granted to them, so your setup cannot be undone.', 'nibwp'); ?></span>
                        </li>
                        <li>
                            <?php echo nibwp_status_icon('check', 13); ?>
                            <span><?php esc_html_e('If your account is removed or the license lapses, all menus come back automatically. Nobody gets locked out permanently.', 'nibwp'); ?></span>
                        </li>
                    </ul>
                </div>

                <div class="nibwp-card">
                    <h2 class="nibwp-card-title">
                        <?php echo nibwp_status_icon('link', 14); ?>
                        <?php esc_html_e('Your link back in', 'nibwp'); ?>
                    </h2>
                    <p class="nw-ua-lead"><?php esc_html_e('These open NIBWP even for an administrator whose menu is hidden. Save them somewhere you will find them again — if you hide NIBWP from yourself, they are the way back.', 'nibwp'); ?></p>

                    <label class="nw-ua-field-label" for="nw-ua-url"><?php esc_html_e('Dashboard', 'nibwp'); ?></label>
                    <div class="nw-status-copyrow">
                        <input type="text" readonly value="<?php echo esc_attr($dashboard_url); ?>" id="nw-ua-url" class="nw-status-copyrow__input">
                        <?php nibwp_status_copy_button('nw-ua-url', __('Copy', 'nibwp')); ?>
                    </div>

                    <label class="nw-ua-field-label" for="nw-ua-url-self"><?php esc_html_e('This screen', 'nibwp'); ?></label>
                    <div class="nw-status-copyrow">
                        <input type="text" readonly value="<?php echo esc_attr(admin_url('admin.php?page=nibwp-user-access')); ?>" id="nw-ua-url-self" class="nw-status-copyrow__input">
                        <?php nibwp_status_copy_button('nw-ua-url-self', __('Copy', 'nibwp')); ?>
                    </div>
                </div>

                <div class="nibwp-card">
                    <h2 class="nibwp-card-title">
                        <?php echo nibwp_status_icon('user', 14); ?>
                        <?php esc_html_e('Administrators', 'nibwp'); ?>
                    </h2>

                    <?php if ($admins === []): ?>
                        <p class="nw-ua-lead"><?php esc_html_e('No administrators found on this site.', 'nibwp'); ?></p>
                    <?php else: ?>
                        <p class="nw-ua-lead"><?php esc_html_e('While the switch above is on, every other administrator sees nothing unless you grant it here — including anyone added later. You can tidy your own menu too.', 'nibwp'); ?></p>

                        <?php foreach ($admins as $user_id => $user): ?>
                            <?php
                            $entry = $config['users'][$user_id]
                                ?? nibwp_user_access_default_entry($user_id);
                            $setting = $entry['pages'];
                            $mode = is_array($setting) ? 'custom' : (string) $setting;
                            $selected = is_array($setting) ? $setting : [];
                            $row_is_you = $user_id === get_current_user_id();
                            // Only the owner may grant themselves the screens
                            // that change this configuration.
                            $choices = nibwp_user_access_page_choices($user_id === $owner_id);
                            ?>
                            <div class="nw-ua-user<?php echo $mode === 'custom' ? ' is-custom' : ''; ?><?php echo $row_is_you ? ' is-you' : ''; ?>" data-user="<?php echo esc_attr((string) $user_id); ?>">
                                <div class="nw-ua-user__head">
                                    <span class="nw-ua-user__avatar"><?php echo get_avatar($user_id, 36); ?></span>
                                    <span class="nw-ua-user__id">
                                        <strong>
                                            <?php echo esc_html($user->display_name); ?>
                                            <?php if ($row_is_you): ?>
                                                <span class="nw-ua-you"><?php esc_html_e('You', 'nibwp'); ?></span>
                                            <?php endif; ?>
                                        </strong>
                                        <span>
                                            <?php if ($row_is_you): ?>
                                                <?php esc_html_e('You can hide NIBWP from yourself too. Save the link below first — it is how you get back.', 'nibwp'); ?>
                                            <?php else: ?>
                                                <?php echo esc_html($user->user_email); ?>
                                            <?php endif; ?>
                                        </span>
                                    </span>
                                    <span class="nw-ua-user__modes">
                                        <?php
                                        $modes = [
                                            'all' => __('Everything', 'nibwp'),
                                            'custom' => __('Only some', 'nibwp'),
                                            'none' => __('Nothing', 'nibwp'),
                                        ];
                                        foreach ($modes as $value => $label): ?>
                                            <label class="nw-ua-mode">
                                                <input type="radio"
                                                       name="nibwp_ua_mode[<?php echo esc_attr((string) $user_id); ?>]"
                                                       value="<?php echo esc_attr($value); ?>"
                                                       <?php checked($mode, $value); ?>>
                                                <span><?php echo esc_html($label); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </span>
                                </div>

                                <div class="nw-ua-user__extras">
                                    <label class="nw-ua-extra">
                                        <input type="checkbox"
                                               name="nibwp_ua_chip[<?php echo esc_attr((string) $user_id); ?>]"
                                               value="1"
                                               <?php checked(!empty($entry['chip'])); ?>>
                                        <span><?php esc_html_e('Show the NIBWP badge in the toolbar', 'nibwp'); ?></span>
                                    </label>
                                    <label class="nw-ua-extra">
                                        <input type="checkbox"
                                               name="nibwp_ua_notices[<?php echo esc_attr((string) $user_id); ?>]"
                                               value="1"
                                               <?php checked(!empty($entry['notices'])); ?>>
                                        <span><?php esc_html_e('Show NIBWP notices', 'nibwp'); ?></span>
                                    </label>
                                </div>

                                <div class="nw-ua-user__pages">
                                    <?php foreach ($choices as $slug => $label): ?>
                                        <?php $is_dashboard = $slug === 'nibwp-dashboard'; ?>
                                        <label class="nw-ua-page<?php echo $is_dashboard ? ' is-locked' : ''; ?>">
                                            <input type="checkbox"
                                                   name="nibwp_ua_pages[<?php echo esc_attr((string) $user_id); ?>][]"
                                                   value="<?php echo esc_attr($slug); ?>"
                                                   <?php checked($is_dashboard || in_array($slug, $selected, true)); ?>
                                                   <?php disabled($is_dashboard); ?>>
                                            <span><?php echo esc_html($label); ?></span>
                                        </label>
                                        <?php if ($is_dashboard): ?>
                                            <input type="hidden"
                                                   name="nibwp_ua_pages[<?php echo esc_attr((string) $user_id); ?>][]"
                                                   value="nibwp-dashboard">
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>


                <?php
                $brand = nibwp_branding_config();
                $brand_defaults = nibwp_branding_defaults();
                $brand_live = nibwp_branding_resolved();
                ?>
                <div class="nibwp-card nw-br" id="nw-br">
                    <h2 class="nibwp-card-title">
                        <?php echo nibwp_status_icon('tag', 14); ?>
                        <?php esc_html_e('White label', 'nibwp'); ?>
                    </h2>

                    <label class="nw-ua-switch">
                        <input type="checkbox" name="nibwp_br_enabled" id="nw-br-enabled" value="1" <?php checked($brand['enabled']); ?>>
                        <span><?php esc_html_e('Present this plugin as your own', 'nibwp'); ?></span>
                    </label>
                    <p class="nw-ua-lead">
                        <?php esc_html_e('Changes the Plugins row and the admin menu on this site only. Leave a field empty to keep what is there now.', 'nibwp'); ?>
                    </p>

                    <div class="nw-br-body" id="nw-br-body">
                        <div class="nw-br-grid">
                            <div class="nw-br-field">
                                <label class="nw-oc-label" for="nw-br-name"><?php esc_html_e('Plugin name', 'nibwp'); ?></label>
                                <input type="text" id="nw-br-name" name="nibwp_br_name" class="regular-text"
                                       value="<?php echo esc_attr($brand['name']); ?>"
                                       placeholder="<?php echo esc_attr($brand_defaults['name']); ?>"
                                       data-br-preview="name" maxlength="80">
                            </div>
                            <div class="nw-br-field">
                                <label class="nw-oc-label" for="nw-br-menu"><?php esc_html_e('Menu label', 'nibwp'); ?></label>
                                <input type="text" id="nw-br-menu" name="nibwp_br_menu" class="regular-text"
                                       value="<?php echo esc_attr($brand['menu']); ?>"
                                       placeholder="<?php echo esc_attr($brand_defaults['menu']); ?>"
                                       maxlength="40">
                            </div>
                        </div>

                        <label class="nw-oc-label" for="nw-br-desc"><?php esc_html_e('Description', 'nibwp'); ?></label>
                        <textarea id="nw-br-desc" name="nibwp_br_description" rows="3" class="large-text"
                                  placeholder="<?php echo esc_attr($brand_defaults['description']); ?>"
                                  data-br-preview="description" maxlength="400"><?php echo esc_textarea($brand['description']); ?></textarea>

                        <div class="nw-br-grid">
                            <div class="nw-br-field">
                                <label class="nw-oc-label" for="nw-br-author"><?php esc_html_e('Author', 'nibwp'); ?></label>
                                <input type="text" id="nw-br-author" name="nibwp_br_author" class="regular-text"
                                       value="<?php echo esc_attr($brand['author']); ?>"
                                       placeholder="<?php echo esc_attr($brand_defaults['author']); ?>"
                                       data-br-preview="author" maxlength="80">
                            </div>
                            <div class="nw-br-field">
                                <label class="nw-oc-label" for="nw-br-author-uri"><?php esc_html_e('Author link', 'nibwp'); ?></label>
                                <input type="url" id="nw-br-author-uri" name="nibwp_br_author_uri" class="regular-text"
                                       value="<?php echo esc_attr($brand['author_uri']); ?>"
                                       placeholder="<?php echo esc_attr($brand_defaults['author_uri']); ?>">
                            </div>
                        </div>

                        <label class="nw-oc-label" for="nw-br-plugin-uri"><?php esc_html_e('Plugin link', 'nibwp'); ?></label>
                        <input type="url" id="nw-br-plugin-uri" name="nibwp_br_plugin_uri" class="large-text"
                               value="<?php echo esc_attr($brand['plugin_uri']); ?>"
                               placeholder="<?php echo esc_attr($brand_defaults['plugin_uri']); ?>">

                        <p class="nw-br-token">
                            <?php
                            printf(
                                /* translators: %s: the literal token {site}, in a code tag */
                                esc_html__('Use %s in any of these and it becomes the site title, which saves retyping it when you set up a lot of sites the same way.', 'nibwp'),
                                '<code>{site}</code>'
                            );
                            ?>
                        </p>

                        <?php // What the row will look like, drawn as the row rather than described. ?>
                        <span class="nw-oc-label"><?php esc_html_e('Preview', 'nibwp'); ?></span>
                        <div class="nw-br-preview" aria-live="polite">
                            <div class="nw-br-preview__row">
                                <strong data-br-slot="name"><?php echo esc_html($brand_live['name']); ?></strong>
                                <div class="nw-br-preview__links">
                                    <span><?php esc_html_e('Deactivate', 'nibwp'); ?></span>
                                    <span aria-hidden="true">|</span>
                                    <span><?php esc_html_e('Settings', 'nibwp'); ?></span>
                                </div>
                                <p data-br-slot="description"><?php echo esc_html($brand_live['description']); ?></p>
                                <p class="nw-br-preview__meta">
                                    <?php
                                    printf(
                                        /* translators: 1: version number, 2: author name */
                                        esc_html__('Version %1$s | By %2$s', 'nibwp'),
                                        esc_html(defined('NIBWP_VERSION') ? NIBWP_VERSION : ''),
                                        '<span data-br-slot="author">' . esc_html($brand_live['author']) . '</span>'
                                    );
                                    ?>
                                </p>
                            </div>
                        </div>

                        <ul class="nw-ua-facts nw-br-facts">
                            <li>
                                <?php echo nibwp_status_icon('refresh', 13); ?>
                                <span><?php esc_html_e('Updates keep working. They are matched on the plugin folder, which never changes here, so only what is printed on the screen is different.', 'nibwp'); ?></span>
                            </li>
                            <li>
                                <?php echo nibwp_status_icon('shield', 13); ?>
                                <span><?php esc_html_e('Your license, settings and connected AI clients are untouched. Nothing is stored against the name.', 'nibwp'); ?></span>
                            </li>
                            <li>
                                <?php echo nibwp_status_icon('check', 13); ?>
                                <span><?php esc_html_e('Reversible at any moment. Switch it off, or use Restore original, and the real name comes straight back.', 'nibwp'); ?></span>
                            </li>
                        </ul>

                        <p class="nw-br-reset">
                            <button type="submit" class="nibwp-btn-ghost" name="nibwp_br_reset" value="1" id="nw-br-reset">
                                <?php esc_html_e('Restore original', 'nibwp'); ?>
                            </button>
                            <a class="nw-br-seeit" href="<?php echo esc_url(admin_url('plugins.php')); ?>" target="_blank" rel="noopener">
                                <?php esc_html_e('Open the Plugins screen', 'nibwp'); ?>
                            </a>
                        </p>
                    </div>
                </div>

                <div class="nw-ua-actions">
                    <button type="submit" class="nibwp-btn-primary" id="nw-ua-save" name="nibwp_user_access_save" value="1">
                        <span class="nw-ua-spin"><?php echo nibwp_status_icon('refresh', 15); ?></span>
                        <span class="nw-ua-save-label"><?php esc_html_e('Save user access', 'nibwp'); ?></span>
                    </button>
                    <span class="nw-ua-saved" id="nw-ua-saved" role="status" aria-live="polite"></span>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <script>
    (function () {
        var root = document.querySelector('.nw-ua');
        if (!root) { return; }

        // AJAX save. Falls through to a normal POST if fetch is unavailable, so
        // the form still works without JS.
        var form = root.querySelector('form');
        var btn = document.getElementById('nw-ua-save');
        var status = document.getElementById('nw-ua-saved');
        if (form && btn && window.fetch && window.FormData) {
            // Restore original posts normally: FormData does not carry the
            // button that submitted the form, so an AJAX save would drop the
            // very flag that makes it a reset.
            var resetBtn = document.getElementById('nw-br-reset');
            var resetting = false;
            if (resetBtn) {
                resetBtn.addEventListener('click', function () { resetting = true; });
            }

            form.addEventListener('submit', function (e) {
                if (resetting) { return; }
                e.preventDefault();
                if (btn.classList.contains('is-busy')) { return; }
                btn.classList.add('is-busy');
                if (status) { status.textContent = ''; status.className = 'nw-ua-saved'; }

                var data = new FormData(form);
                data.append('action', 'nibwp_user_access_save');
                data.append('nibwp_user_access_save', '1');

                fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: data })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res && res.success) {
                            if (status) {
                                status.textContent = res.data.message;
                                status.className = 'nw-ua-saved is-ok';
                            }
                            // Your own menu may have just changed; the sidebar
                            // around this page is already rendered, so redraw it.
                            if (res.data.reload) {
                                window.setTimeout(function () { window.location.reload(); }, 600);
                            }
                        } else if (status) {
                            status.textContent = (res && res.data && res.data.message)
                                ? res.data.message
                                : <?php echo wp_json_encode(__('Could not save. Please try again.', 'nibwp')); ?>;
                            status.className = 'nw-ua-saved is-error';
                        }
                    })
                    .catch(function () {
                        if (status) {
                            status.textContent = <?php echo wp_json_encode(__('Could not reach the server. Please try again.', 'nibwp')); ?>;
                            status.className = 'nw-ua-saved is-error';
                        }
                    })
                    .then(function () { btn.classList.remove('is-busy'); });
            });
        }

        // The page checklist only means anything in "Only some" mode; the other
        // two modes are whole-set answers, so the grid collapses out of the way.
        root.addEventListener('change', function (e) {
            var radio = e.target.closest ? e.target.closest('.nw-ua-mode input') : null;
            if (!radio) { return; }
            var row = radio.closest('.nw-ua-user');
            if (!row) { return; }
            row.classList.toggle('is-custom', radio.value === 'custom');
        });

        // Copy button. Mirrors the Status page handler: only the label span is
        // swapped so the icon survives the confirmation.
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

        // Nothing and Everything are whole-answer choices, so the badge and
        // notice toggles follow them. Both stay editable afterwards.
        root.addEventListener('change', function (e) {
            var radio = e.target.closest ? e.target.closest('.nw-ua-mode input') : null;
            if (!radio || radio.value === 'custom') { return; }
            var row = radio.closest('.nw-ua-user');
            if (!row) { return; }
            var on = radio.value === 'all';
            row.querySelectorAll('.nw-ua-extra input').forEach(function (box) { box.checked = on; });
        });

        // Ticking a page is an implicit "Only some" — silently leaving the mode
        // on Everything would discard the choice at save time.
        root.addEventListener('change', function (e) {
            var box = e.target.closest ? e.target.closest('.nw-ua-page input') : null;
            if (!box || box.disabled) { return; }
            var row = box.closest('.nw-ua-user');
            if (!row) { return; }
            var custom = row.querySelector('.nw-ua-mode input[value="custom"]');
            if (custom && !custom.checked) {
                custom.checked = true;
                row.classList.add('is-custom');
            }
        });
    })();

    /* Renaming: the preview is the point. Typing a name and only finding out
       what it looks like after saving is what makes people leave the feature
       alone, so the row redraws as you type — including the {site} token and
       the fallback to the real value when a field is emptied. */
    (function () {
        var card = document.getElementById('nw-br');
        if (!card) { return; }

        var toggle = document.getElementById('nw-br-enabled');
        var body = document.getElementById('nw-br-body');
        var site = <?php echo wp_json_encode(get_bloginfo('name')); ?>;
        var defaults = <?php echo wp_json_encode(nibwp_branding_defaults()); ?>;

        function expand() {
            if (!body || !toggle) { return; }
            card.classList.toggle('is-on', toggle.checked);
        }

        function paint() {
            var fields = card.querySelectorAll('[data-br-preview]');
            for (var i = 0; i < fields.length; i++) {
                var key = fields[i].getAttribute('data-br-preview');
                var slot = card.querySelector('[data-br-slot="' + key + '"]');
                if (!slot) { continue; }
                var value = (fields[i].value || '').trim();
                if (value === '') { value = defaults[key] || ''; }
                slot.textContent = value.split('{site}').join(site);
            }
        }

        if (toggle) {
            toggle.addEventListener('change', expand);
            expand();
        }

        card.addEventListener('input', function (e) {
            if (e.target && e.target.hasAttribute && e.target.hasAttribute('data-br-preview')) {
                paint();
            }
        });

        paint();
    })();
    </script>
    <?php
    nibwp_render_admin_footer();
}
