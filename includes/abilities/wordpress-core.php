<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// =============================================================================
// CALLBACK FUNCTIONS
// =============================================================================

// -----------------------------------------------------------------------------
// Permission callback
// -----------------------------------------------------------------------------

function nibwp_wp_core_permission_callback(): bool {
    return current_user_can('edit_posts');
}

function nibwp_wp_core_manage_options_permission(): bool {
    return current_user_can('manage_options');
}

function nibwp_wp_core_upload_permission(): bool {
    return current_user_can('upload_files');
}

function nibwp_wp_core_manage_users_permission(): bool {
    return current_user_can('list_users');
}

function nibwp_wp_core_edit_theme_options_permission(): bool {
    return current_user_can('edit_theme_options');
}

function nibwp_wp_core_moderate_comments_permission(): bool {
    return current_user_can('moderate_comments');
}

// -----------------------------------------------------------------------------
// POSTS
// -----------------------------------------------------------------------------

function nibwp_wp_list_posts(array $input): array|\WP_Error {
    $args = [
        'post_type'      => sanitize_text_field($input['post_type'] ?? 'post'),
        'post_status'    => sanitize_text_field($input['status'] ?? 'publish'),
        'posts_per_page' => min(absint($input['per_page'] ?? 20), 100),
        'paged'          => max(1, absint($input['page'] ?? 1)),
        'orderby'        => sanitize_text_field($input['orderby'] ?? 'date'),
        'order'          => in_array(($input['order'] ?? 'DESC'), ['ASC', 'DESC'], true) ? $input['order'] : 'DESC',
    ];

    if (!empty($input['search'])) {
        $args['s'] = sanitize_text_field($input['search']);
    }
    if (!empty($input['category'])) {
        $args['cat'] = absint($input['category']);
    }
    if (!empty($input['tag'])) {
        $args['tag'] = sanitize_text_field($input['tag']);
    }
    if (!empty($input['author'])) {
        $args['author'] = absint($input['author']);
    }
    if (!empty($input['meta_key'])) {
        $args['meta_key'] = sanitize_text_field($input['meta_key']);
        if (isset($input['meta_value'])) {
            $args['meta_value'] = sanitize_text_field($input['meta_value']);
        }
    }

    $query = new \WP_Query($args);
    $posts = [];

    foreach ($query->posts as $post) {
        $author = get_userdata($post->post_author);
        $cats = wp_get_post_categories($post->ID, ['fields' => 'names']);
        $tag_list = wp_get_post_tags($post->ID, ['fields' => 'names']);

        $posts[] = [
            'id'         => $post->ID,
            'title'      => get_the_title($post),
            'status'     => $post->post_status,
            'date'       => $post->post_date,
            'author'     => $author ? $author->display_name : '',
            'excerpt'    => get_the_excerpt($post),
            'url'        => get_permalink($post),
            'categories' => is_array($cats) ? $cats : [],
            'tags'       => is_array($tag_list) ? $tag_list : [],
        ];
    }

    return [
        'posts'       => $posts,
        'total'       => $query->found_posts,
        'total_pages' => $query->max_num_pages,
        'page'        => $args['paged'],
    ];
}

function nibwp_wp_get_post(array $input): array|\WP_Error {
    $post_id = absint($input['post_id'] ?? 0);
    $post = get_post($post_id);

    if (!$post) {
        return new \WP_Error('not_found', __('Post not found.', 'nibwp'), ['status' => 404]);
    }

    $author = get_userdata($post->post_author);
    $cats = wp_get_post_categories($post->ID, ['fields' => 'all']);
    $tag_list = wp_get_post_tags($post->ID, ['fields' => 'all']);
    $thumbnail_id = get_post_thumbnail_id($post->ID);
    $featured_image = $thumbnail_id ? wp_get_attachment_url($thumbnail_id) : null;

    $categories = [];
    if (is_array($cats)) {
        foreach ($cats as $cat) {
            $categories[] = ['id' => $cat->term_id, 'name' => $cat->name, 'slug' => $cat->slug];
        }
    }

    $tags = [];
    if (is_array($tag_list)) {
        foreach ($tag_list as $tag) {
            $tags[] = ['id' => $tag->term_id, 'name' => $tag->name, 'slug' => $tag->slug];
        }
    }

    return [
        'id'              => $post->ID,
        'title'           => get_the_title($post),
        'content'         => $post->post_content,
        'excerpt'         => $post->post_excerpt,
        'status'          => $post->post_status,
        'date'            => $post->post_date,
        'modified'        => $post->post_modified,
        'author'          => $author ? $author->display_name : '',
        'url'             => get_permalink($post),
        'featured_image'  => $featured_image,
        'categories'      => $categories,
        'tags'            => $tags,
        'custom_fields'   => get_post_meta($post->ID),
    ];
}

function nibwp_wp_create_post(array $input): array|\WP_Error {
    if (empty($input['title'])) {
        return new \WP_Error('missing_title', __('Title is required.', 'nibwp'));
    }

    // B7 fix: skill-route sniffer. When a v2-routed skill is unlocked AND
    // the agent submits content that looks like a generator dump (raw <style>
    // tag, external <link rel=stylesheet>, or >50 lines of HTML), refuse to
    // persist until the agent either (a) routes through the appropriate
    // skill or (b) submits an explicit `raw_html_confirmation` flag.
    //
    // Returning a non-error array with requires_user_input lets the agent
    // surface the decision to the human rather than silently creating a
    // post with the bad CSS the validator/persister would have rejected.
    $sniffer = nibwp_wp_create_post_route_sniffer($input);
    if ($sniffer !== null) {
        return $sniffer;
    }

    $allowed_statuses = ['draft', 'publish', 'pending', 'private'];
    $status = in_array(($input['status'] ?? 'draft'), $allowed_statuses, true) ? $input['status'] : 'draft';

    $postarr = [
        'post_title'   => sanitize_text_field($input['title']),
        'post_content' => wp_kses_post($input['content'] ?? ''),
        'post_excerpt' => sanitize_textarea_field($input['excerpt'] ?? ''),
        'post_status'  => $status,
        'post_type'    => sanitize_text_field($input['post_type'] ?? 'post'),
    ];

    if (!empty($input['author'])) {
        $postarr['post_author'] = absint($input['author']);
    }
    if (!empty($input['slug'])) {
        $postarr['post_name'] = sanitize_title($input['slug']);
    }
    if (!empty($input['categories']) && is_array($input['categories'])) {
        $postarr['post_category'] = array_map('absint', $input['categories']);
    }

    $post_id = wp_insert_post($postarr, true);

    if (is_wp_error($post_id)) {
        return $post_id;
    }

    if (!empty($input['tags']) && is_array($input['tags'])) {
        wp_set_post_tags($post_id, array_map('sanitize_text_field', $input['tags']));
    }

    if (!empty($input['featured_image'])) {
        set_post_thumbnail($post_id, absint($input['featured_image']));
    }

    if (!empty($input['meta']) && is_array($input['meta'])) {
        foreach ($input['meta'] as $key => $value) {
            update_post_meta($post_id, sanitize_key($key), $value);
        }
    }

    return [
        'post_id'  => $post_id,
        'url'      => get_permalink($post_id),
        'edit_url' => get_edit_post_link($post_id, 'raw'),
    ];
}

function nibwp_wp_update_post(array $input): array|\WP_Error {
    $post_id = absint($input['post_id'] ?? 0);
    $post = get_post($post_id);

    if (!$post) {
        return new \WP_Error('not_found', __('Post not found.', 'nibwp'), ['status' => 404]);
    }

    $postarr = ['ID' => $post_id];

    if (isset($input['title'])) {
        $postarr['post_title'] = sanitize_text_field($input['title']);
    }
    if (isset($input['content'])) {
        $postarr['post_content'] = wp_kses_post($input['content']);
    }
    if (isset($input['excerpt'])) {
        $postarr['post_excerpt'] = sanitize_textarea_field($input['excerpt']);
    }
    if (isset($input['status'])) {
        $allowed = ['draft', 'publish', 'pending', 'private'];
        if (in_array($input['status'], $allowed, true)) {
            $postarr['post_status'] = $input['status'];
        }
    }
    if (isset($input['post_type'])) {
        $postarr['post_type'] = sanitize_text_field($input['post_type']);
    }
    if (isset($input['author'])) {
        $postarr['post_author'] = absint($input['author']);
    }
    if (isset($input['slug'])) {
        $postarr['post_name'] = sanitize_title($input['slug']);
    }
    if (isset($input['categories']) && is_array($input['categories'])) {
        $postarr['post_category'] = array_map('absint', $input['categories']);
    }

    $result = wp_update_post($postarr, true);

    if (is_wp_error($result)) {
        return $result;
    }

    if (isset($input['tags']) && is_array($input['tags'])) {
        wp_set_post_tags($post_id, array_map('sanitize_text_field', $input['tags']));
    }

    if (isset($input['featured_image'])) {
        set_post_thumbnail($post_id, absint($input['featured_image']));
    }

    if (!empty($input['meta']) && is_array($input['meta'])) {
        foreach ($input['meta'] as $key => $value) {
            update_post_meta($post_id, sanitize_key($key), $value);
        }
    }

    return [
        'post_id' => $post_id,
        'url'     => get_permalink($post_id),
    ];
}

function nibwp_wp_delete_post(array $input): array|\WP_Error {
    $post_id = absint($input['post_id'] ?? 0);
    $post = get_post($post_id);

    if (!$post) {
        return new \WP_Error('not_found', __('Post not found.', 'nibwp'), ['status' => 404]);
    }

    $force = (bool) ($input['force'] ?? false);
    $result = wp_delete_post($post_id, $force);

    if (!$result) {
        return new \WP_Error('delete_failed', __('Failed to delete post.', 'nibwp'));
    }

    return [
        'success'    => true,
        'deleted_id' => $post_id,
    ];
}

// -----------------------------------------------------------------------------
// MEDIA
// -----------------------------------------------------------------------------

function nibwp_wp_list_media(array $input): array|\WP_Error {
    $args = [
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => min(absint($input['per_page'] ?? 20), 100),
        'paged'          => max(1, absint($input['page'] ?? 1)),
    ];

    if (!empty($input['mime_type'])) {
        $args['post_mime_type'] = sanitize_text_field($input['mime_type']);
    }
    if (!empty($input['search'])) {
        $args['s'] = sanitize_text_field($input['search']);
    }

    $query = new \WP_Query($args);
    $items = [];

    foreach ($query->posts as $attachment) {
        $meta = wp_get_attachment_metadata($attachment->ID);
        $file_path = get_attached_file($attachment->ID);

        $items[] = [
            'id'         => $attachment->ID,
            'title'      => get_the_title($attachment),
            'url'        => wp_get_attachment_url($attachment->ID),
            'mime_type'  => $attachment->post_mime_type,
            'file_size'  => $file_path && file_exists($file_path) ? filesize($file_path) : null,
            'dimensions' => isset($meta['width'], $meta['height']) ? ['width' => $meta['width'], 'height' => $meta['height']] : null,
            'date'       => $attachment->post_date,
        ];
    }

    return [
        'items'       => $items,
        'total'       => $query->found_posts,
        'total_pages' => $query->max_num_pages,
    ];
}

function nibwp_wp_upload_media(array $input): array|\WP_Error {
    if (empty($input['url'])) {
        return new \WP_Error('missing_url', __('URL is required.', 'nibwp'));
    }

    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $url = esc_url_raw($input['url']);

    $tmp = download_url($url);
    if (is_wp_error($tmp)) {
        return $tmp;
    }

    $file_array = [
        'name'     => basename(wp_parse_url($url, PHP_URL_PATH) ?: 'upload'),
        'tmp_name' => $tmp,
    ];

    $attachment_id = media_handle_sideload($file_array, 0, $input['title'] ?? null);

    if (is_wp_error($attachment_id)) {
        @unlink($tmp);
        return $attachment_id;
    }

    if (!empty($input['alt_text'])) {
        update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($input['alt_text']));
    }

    if (!empty($input['caption']) || !empty($input['description'])) {
        $update = ['ID' => $attachment_id];
        if (!empty($input['caption'])) {
            $update['post_excerpt'] = sanitize_textarea_field($input['caption']);
        }
        if (!empty($input['description'])) {
            $update['post_content'] = sanitize_textarea_field($input['description']);
        }
        wp_update_post($update);
    }

    return [
        'attachment_id' => $attachment_id,
        'url'           => wp_get_attachment_url($attachment_id),
    ];
}

function nibwp_wp_delete_media(array $input): array|\WP_Error {
    $attachment_id = absint($input['attachment_id'] ?? 0);
    $attachment = get_post($attachment_id);

    if (!$attachment || $attachment->post_type !== 'attachment') {
        return new \WP_Error('not_found', __('Attachment not found.', 'nibwp'), ['status' => 404]);
    }

    $force = (bool) ($input['force'] ?? true);
    $result = wp_delete_attachment($attachment_id, $force);

    if (!$result) {
        return new \WP_Error('delete_failed', __('Failed to delete attachment.', 'nibwp'));
    }

    return [
        'success'    => true,
        'deleted_id' => $attachment_id,
    ];
}

// -----------------------------------------------------------------------------
// TAXONOMIES
// -----------------------------------------------------------------------------

function nibwp_wp_list_terms(array $input): array|\WP_Error {
    if (empty($input['taxonomy'])) {
        return new \WP_Error('missing_taxonomy', __('Taxonomy is required.', 'nibwp'));
    }

    $taxonomy = sanitize_text_field($input['taxonomy']);

    if (!taxonomy_exists($taxonomy)) {
        return new \WP_Error('invalid_taxonomy', __('Taxonomy does not exist.', 'nibwp'));
    }

    $args = [
        'taxonomy'   => $taxonomy,
        'number'     => min(absint($input['per_page'] ?? 50), 200),
        'hide_empty' => (bool) ($input['hide_empty'] ?? false),
        'orderby'    => sanitize_text_field($input['orderby'] ?? 'name'),
    ];

    if (!empty($input['search'])) {
        $args['search'] = sanitize_text_field($input['search']);
    }
    if (isset($input['parent'])) {
        $args['parent'] = absint($input['parent']);
    }

    $terms = get_terms($args);

    if (is_wp_error($terms)) {
        return $terms;
    }

    $result = [];
    foreach ($terms as $term) {
        $result[] = [
            'id'          => $term->term_id,
            'name'        => $term->name,
            'slug'        => $term->slug,
            'description' => $term->description,
            'count'       => $term->count,
            'parent'      => $term->parent,
        ];
    }

    return ['terms' => $result, 'total' => count($result)];
}

function nibwp_wp_create_term(array $input): array|\WP_Error {
    if (empty($input['taxonomy']) || empty($input['name'])) {
        return new \WP_Error('missing_fields', __('Taxonomy and name are required.', 'nibwp'));
    }

    $taxonomy = sanitize_text_field($input['taxonomy']);

    if (!taxonomy_exists($taxonomy)) {
        return new \WP_Error('invalid_taxonomy', __('Taxonomy does not exist.', 'nibwp'));
    }

    $args = [];
    if (!empty($input['slug'])) {
        $args['slug'] = sanitize_title($input['slug']);
    }
    if (!empty($input['description'])) {
        $args['description'] = sanitize_textarea_field($input['description']);
    }
    if (isset($input['parent'])) {
        $args['parent'] = absint($input['parent']);
    }

    $result = wp_insert_term(sanitize_text_field($input['name']), $taxonomy, $args);

    if (is_wp_error($result)) {
        return $result;
    }

    $term = get_term($result['term_id'], $taxonomy);

    return [
        'term_id' => $result['term_id'],
        'slug'    => $term instanceof \WP_Term ? $term->slug : '',
    ];
}

function nibwp_wp_update_term(array $input): array|\WP_Error {
    $term_id = absint($input['term_id'] ?? 0);

    if (empty($input['taxonomy'])) {
        return new \WP_Error('missing_taxonomy', __('Taxonomy is required.', 'nibwp'));
    }

    $taxonomy = sanitize_text_field($input['taxonomy']);

    if (!taxonomy_exists($taxonomy)) {
        return new \WP_Error('invalid_taxonomy', __('Taxonomy does not exist.', 'nibwp'));
    }

    $term = get_term($term_id, $taxonomy);
    if (!$term || is_wp_error($term)) {
        return new \WP_Error('not_found', __('Term not found.', 'nibwp'), ['status' => 404]);
    }

    $args = [];
    if (isset($input['name'])) {
        $args['name'] = sanitize_text_field($input['name']);
    }
    if (isset($input['slug'])) {
        $args['slug'] = sanitize_title($input['slug']);
    }
    if (isset($input['description'])) {
        $args['description'] = sanitize_textarea_field($input['description']);
    }
    if (isset($input['parent'])) {
        $args['parent'] = absint($input['parent']);
    }

    $result = wp_update_term($term_id, $taxonomy, $args);

    if (is_wp_error($result)) {
        return $result;
    }

    return [
        'term_id' => $result['term_id'],
        'slug'    => get_term($result['term_id'], $taxonomy)->slug ?? '',
    ];
}

function nibwp_wp_delete_term(array $input): array|\WP_Error {
    $term_id = absint($input['term_id'] ?? 0);

    if (empty($input['taxonomy'])) {
        return new \WP_Error('missing_taxonomy', __('Taxonomy is required.', 'nibwp'));
    }

    $taxonomy = sanitize_text_field($input['taxonomy']);

    if (!taxonomy_exists($taxonomy)) {
        return new \WP_Error('invalid_taxonomy', __('Taxonomy does not exist.', 'nibwp'));
    }

    $term = get_term($term_id, $taxonomy);
    if (!$term || is_wp_error($term)) {
        return new \WP_Error('not_found', __('Term not found.', 'nibwp'), ['status' => 404]);
    }

    $result = wp_delete_term($term_id, $taxonomy);

    if (is_wp_error($result)) {
        return $result;
    }

    if ($result === false) {
        return new \WP_Error('delete_failed', __('Failed to delete term.', 'nibwp'));
    }

    return [
        'success'    => true,
        'deleted_id' => $term_id,
    ];
}

// -----------------------------------------------------------------------------
// COMMENTS
// -----------------------------------------------------------------------------

function nibwp_wp_list_comments(array $input): array|\WP_Error {
    $args = [
        'number' => min(absint($input['per_page'] ?? 20), 100),
        'offset' => (max(1, absint($input['page'] ?? 1)) - 1) * min(absint($input['per_page'] ?? 20), 100),
        'status' => sanitize_text_field($input['status'] ?? 'approve'),
    ];

    if (!empty($input['post_id'])) {
        $args['post_id'] = absint($input['post_id']);
    }

    $comments = get_comments($args);
    $result = [];

    foreach ($comments as $comment) {
        $result[] = [
            'id'      => (int) $comment->comment_ID,
            'post_id' => (int) $comment->comment_post_ID,
            'author'  => $comment->comment_author,
            'email'   => $comment->comment_author_email,
            'content' => $comment->comment_content,
            'date'    => $comment->comment_date,
            'status'  => wp_get_comment_status($comment),
            'parent'  => (int) $comment->comment_parent,
        ];
    }

    return ['comments' => $result];
}

function nibwp_wp_create_comment(array $input): array|\WP_Error {
    if (empty($input['post_id']) || empty($input['content'])) {
        return new \WP_Error('missing_fields', __('post_id and content are required.', 'nibwp'));
    }

    $post = get_post(absint($input['post_id']));
    if (!$post) {
        return new \WP_Error('not_found', __('Post not found.', 'nibwp'), ['status' => 404]);
    }

    $commentdata = [
        'comment_post_ID' => absint($input['post_id']),
        'comment_content'  => sanitize_textarea_field($input['content']),
        'comment_approved' => sanitize_text_field($input['status'] ?? 'approve') === 'approve' ? 1 : 0,
    ];

    if (!empty($input['author'])) {
        $commentdata['comment_author'] = sanitize_text_field($input['author']);
    }
    if (!empty($input['author_email'])) {
        $commentdata['comment_author_email'] = sanitize_email($input['author_email']);
    }
    if (!empty($input['author_url'])) {
        $commentdata['comment_author_url'] = esc_url_raw($input['author_url']);
    }
    if (!empty($input['parent'])) {
        $commentdata['comment_parent'] = absint($input['parent']);
    }

    $comment_id = wp_insert_comment($commentdata);

    if (!$comment_id) {
        return new \WP_Error('insert_failed', __('Failed to create comment.', 'nibwp'));
    }

    return [
        'comment_id' => $comment_id,
    ];
}

function nibwp_wp_update_comment(array $input): array|\WP_Error {
    $comment_id = absint($input['comment_id'] ?? 0);
    $comment = get_comment($comment_id);

    if (!$comment) {
        return new \WP_Error('not_found', __('Comment not found.', 'nibwp'), ['status' => 404]);
    }

    $commentarr = ['comment_ID' => $comment_id];

    if (isset($input['content'])) {
        $commentarr['comment_content'] = sanitize_textarea_field($input['content']);
    }
    if (isset($input['status'])) {
        $status_map = [
            'approve' => 1,
            'hold'    => 0,
            'spam'    => 'spam',
            'trash'   => 'trash',
        ];
        if (isset($status_map[$input['status']])) {
            $commentarr['comment_approved'] = $status_map[$input['status']];
        }
    }

    $result = wp_update_comment($commentarr, true);

    if (is_wp_error($result)) {
        return $result;
    }

    return [
        'comment_id' => $comment_id,
        'success'    => true,
    ];
}

function nibwp_wp_delete_comment(array $input): array|\WP_Error {
    $comment_id = absint($input['comment_id'] ?? 0);
    $comment = get_comment($comment_id);

    if (!$comment) {
        return new \WP_Error('not_found', __('Comment not found.', 'nibwp'), ['status' => 404]);
    }

    $force = (bool) ($input['force'] ?? false);
    $result = wp_delete_comment($comment_id, $force);

    if (!$result) {
        return new \WP_Error('delete_failed', __('Failed to delete comment.', 'nibwp'));
    }

    return [
        'success'    => true,
        'deleted_id' => $comment_id,
    ];
}

// -----------------------------------------------------------------------------
// MENUS
// -----------------------------------------------------------------------------

function nibwp_wp_list_menus(array $input): array|\WP_Error {
    $menus = wp_get_nav_menus();
    $result = [];

    foreach ($menus as $menu) {
        $result[] = [
            'id'    => $menu->term_id,
            'name'  => $menu->name,
            'slug'  => $menu->slug,
            'count' => $menu->count,
        ];
    }

    return ['menus' => $result];
}

function nibwp_wp_get_menu_items(array $input): array|\WP_Error {
    $menu_id = absint($input['menu_id'] ?? 0);
    $menu = wp_get_nav_menu_object($menu_id);

    if (!$menu) {
        return new \WP_Error('not_found', __('Menu not found.', 'nibwp'), ['status' => 404]);
    }

    $items = wp_get_nav_menu_items($menu_id);

    if ($items === false) {
        return new \WP_Error('fetch_failed', __('Failed to get menu items.', 'nibwp'));
    }

    $result = [];
    foreach ($items as $item) {
        $result[] = [
            'id'       => (int) $item->ID,
            'title'    => $item->title,
            'url'      => $item->url,
            'type'     => $item->type,
            'parent'   => (int) $item->menu_item_parent,
            'position' => (int) $item->menu_order,
        ];
    }

    return ['items' => $result];
}

function nibwp_wp_create_menu(array $input): array|\WP_Error {
    if (empty($input['name'])) {
        return new \WP_Error('missing_name', __('Menu name is required.', 'nibwp'));
    }

    $menu_id = wp_create_nav_menu(sanitize_text_field($input['name']));

    if (is_wp_error($menu_id)) {
        return $menu_id;
    }

    return [
        'menu_id' => $menu_id,
        'name'    => sanitize_text_field($input['name']),
    ];
}

function nibwp_wp_add_menu_item(array $input): array|\WP_Error {
    $menu_id = absint($input['menu_id'] ?? 0);
    $menu = wp_get_nav_menu_object($menu_id);

    if (!$menu) {
        return new \WP_Error('not_found', __('Menu not found.', 'nibwp'), ['status' => 404]);
    }

    if (empty($input['title'])) {
        return new \WP_Error('missing_title', __('Title is required.', 'nibwp'));
    }

    $object_type = sanitize_text_field($input['object_type'] ?? 'custom');
    $menu_item_data = [
        'menu-item-title'     => sanitize_text_field($input['title']),
        'menu-item-url'       => esc_url_raw($input['url'] ?? ''),
        'menu-item-status'    => 'publish',
        'menu-item-type'      => $object_type,
        'menu-item-parent-id' => absint($input['parent'] ?? 0),
    ];

    if (isset($input['position'])) {
        $menu_item_data['menu-item-position'] = absint($input['position']);
    }

    if ($object_type === 'post_type' && !empty($input['object_id'])) {
        $post = get_post(absint($input['object_id']));
        if (!$post) {
            return new \WP_Error('invalid_object', __('Referenced post not found.', 'nibwp'));
        }
        $menu_item_data['menu-item-object-id'] = absint($input['object_id']);
        $menu_item_data['menu-item-object'] = $post->post_type;
    } elseif ($object_type === 'taxonomy' && !empty($input['object_id'])) {
        $term = get_term(absint($input['object_id']));
        if (!$term || is_wp_error($term)) {
            return new \WP_Error('invalid_object', __('Referenced term not found.', 'nibwp'));
        }
        $menu_item_data['menu-item-object-id'] = absint($input['object_id']);
        $menu_item_data['menu-item-object'] = $term->taxonomy;
    }

    $item_id = wp_update_nav_menu_item($menu_id, 0, $menu_item_data);

    if (is_wp_error($item_id)) {
        return $item_id;
    }

    return [
        'menu_item_id' => $item_id,
    ];
}

// -----------------------------------------------------------------------------
// USERS
// -----------------------------------------------------------------------------

function nibwp_wp_list_users(array $input): array|\WP_Error {
    $args = [
        'number'  => min(absint($input['per_page'] ?? 20), 100),
        'paged'   => max(1, absint($input['page'] ?? 1)),
        'orderby' => sanitize_text_field($input['orderby'] ?? 'display_name'),
    ];

    if (!empty($input['role'])) {
        $args['role'] = sanitize_text_field($input['role']);
    }
    if (!empty($input['search'])) {
        $args['search'] = '*' . sanitize_text_field($input['search']) . '*';
    }

    $user_query = new \WP_User_Query($args);
    $users = [];

    foreach ($user_query->get_results() as $user) {
        $users[] = [
            'id'           => $user->ID,
            'login'        => $user->user_login,
            'email'        => $user->user_email,
            'display_name' => $user->display_name,
            'role'         => implode(', ', $user->roles),
            'registered'   => $user->user_registered,
            'url'          => $user->user_url,
        ];
    }

    return [
        'users' => $users,
        'total' => $user_query->get_total(),
    ];
}

function nibwp_wp_get_user(array $input): array|\WP_Error {
    $user_id = absint($input['user_id'] ?? 0);
    $user = get_userdata($user_id);

    if (!$user) {
        return new \WP_Error('not_found', __('User not found.', 'nibwp'), ['status' => 404]);
    }

    return [
        'id'           => $user->ID,
        'login'        => $user->user_login,
        'email'        => $user->user_email,
        'display_name' => $user->display_name,
        'roles'        => $user->roles,
        'registered'   => $user->user_registered,
        'description'  => $user->description,
        'url'          => $user->user_url,
        'meta'         => get_user_meta($user->ID),
    ];
}

// -----------------------------------------------------------------------------
// SITE
// -----------------------------------------------------------------------------

function nibwp_wp_get_site_info(array $input): array|\WP_Error {
    $post_types = get_post_types(['public' => true], 'names');
    $taxonomies = get_taxonomies(['public' => true], 'names');

    return [
        'name'                => get_bloginfo('name'),
        'description'         => get_bloginfo('description'),
        'url'                 => home_url(),
        'admin_email'         => get_option('admin_email'),
        'wp_version'          => get_bloginfo('version'),
        'php_version'         => phpversion(),
        'timezone'            => wp_timezone_string(),
        'language'            => get_locale(),
        'permalink_structure' => get_option('permalink_structure'),
        'active_theme'        => get_stylesheet(),
        'is_multisite'        => is_multisite(),
        'post_types'          => array_values($post_types),
        'taxonomies'          => array_values($taxonomies),
    ];
}

function nibwp_wp_get_site_stats(array $input): array|\WP_Error {
    $post_count = wp_count_posts('post');
    $page_count = wp_count_posts('page');
    $comment_count = wp_count_comments();

    return [
        'posts'      => isset($post_count->publish) ? (int) $post_count->publish : 0,
        'pages'      => isset($page_count->publish) ? (int) $page_count->publish : 0,
        'comments'   => (int) ($comment_count->approved ?? 0),
        'users'      => (int) count_users()['total_users'],
        'categories' => (int) wp_count_terms(['taxonomy' => 'category']),
        'tags'       => (int) wp_count_terms(['taxonomy' => 'post_tag']),
        'media'      => (int) array_sum((array) wp_count_attachments()),
        'plugins'    => count(get_option('active_plugins', [])),
    ];
}

function nibwp_wp_update_option(array $input): array|\WP_Error {
    if (empty($input['option_name'])) {
        return new \WP_Error('missing_option', __('Option name is required.', 'nibwp'));
    }

    $option_name = sanitize_text_field($input['option_name']);
    $existing = get_option($option_name, null);

    if ($existing === null) {
        return new \WP_Error('not_found', __('Option does not exist.', 'nibwp'), ['status' => 404]);
    }

    $result = update_option($option_name, $input['option_value']);

    return [
        'success'     => $result,
        'option_name' => $option_name,
    ];
}

function nibwp_wp_get_option(array $input): array|\WP_Error {
    if (empty($input['option_name'])) {
        return new \WP_Error('missing_option', __('Option name is required.', 'nibwp'));
    }

    $option_name = sanitize_text_field($input['option_name']);
    $value = get_option($option_name, null);

    if ($value === null) {
        return new \WP_Error('not_found', __('Option not found.', 'nibwp'), ['status' => 404]);
    }

    return [
        'option_name'  => $option_name,
        'option_value' => $value,
    ];
}

function nibwp_wp_search(array $input): array|\WP_Error {
    if (empty($input['query'])) {
        return new \WP_Error('missing_query', __('Search query is required.', 'nibwp'));
    }

    $post_types = $input['post_types'] ?? ['post', 'page'];
    if (!is_array($post_types)) {
        $post_types = ['post', 'page'];
    }

    $args = [
        's'              => sanitize_text_field($input['query']),
        'post_type'      => array_map('sanitize_text_field', $post_types),
        'posts_per_page' => min(absint($input['per_page'] ?? 10), 100),
        'post_status'    => 'publish',
    ];

    $query = new \WP_Query($args);
    $results = [];

    foreach ($query->posts as $post) {
        $results[] = [
            'id'      => $post->ID,
            'title'   => get_the_title($post),
            'type'    => $post->post_type,
            'excerpt' => get_the_excerpt($post),
            'url'     => get_permalink($post),
            'date'    => $post->post_date,
        ];
    }

    return [
        'results' => $results,
        'total'   => $query->found_posts,
        'query'   => $input['query'],
    ];
}

// -----------------------------------------------------------------------------
// META
// -----------------------------------------------------------------------------

function nibwp_wp_get_meta(array $input): array|\WP_Error {
    $valid_types = ['post', 'user', 'term'];
    $object_type = $input['object_type'] ?? '';

    if (!in_array($object_type, $valid_types, true)) {
        return new \WP_Error('invalid_type', __('object_type must be post, user, or term.', 'nibwp'));
    }

    $object_id = absint($input['object_id'] ?? 0);
    $meta_key = $input['meta_key'] ?? '';

    // Validate the object exists
    switch ($object_type) {
        case 'post':
            if (!get_post($object_id)) {
                return new \WP_Error('not_found', __('Post not found.', 'nibwp'), ['status' => 404]);
            }
            $meta = $meta_key ? get_post_meta($object_id, sanitize_text_field($meta_key), true) : get_post_meta($object_id);
            break;
        case 'user':
            if (!get_userdata($object_id)) {
                return new \WP_Error('not_found', __('User not found.', 'nibwp'), ['status' => 404]);
            }
            $meta = $meta_key ? get_user_meta($object_id, sanitize_text_field($meta_key), true) : get_user_meta($object_id);
            break;
        case 'term':
            $term = get_term($object_id);
            if (!$term || is_wp_error($term)) {
                return new \WP_Error('not_found', __('Term not found.', 'nibwp'), ['status' => 404]);
            }
            $meta = $meta_key ? get_term_meta($object_id, sanitize_text_field($meta_key), true) : get_term_meta($object_id);
            break;
    }

    return [
        'object_type' => $object_type,
        'object_id'   => $object_id,
        'meta_key'    => $meta_key ?: null,
        'meta'        => $meta ?? null,
    ];
}

function nibwp_wp_update_meta(array $input): array|\WP_Error {
    $valid_types = ['post', 'user', 'term'];
    $object_type = $input['object_type'] ?? '';

    if (!in_array($object_type, $valid_types, true)) {
        return new \WP_Error('invalid_type', __('object_type must be post, user, or term.', 'nibwp'));
    }

    $object_id = absint($input['object_id'] ?? 0);

    if (empty($input['meta_key'])) {
        return new \WP_Error('missing_key', __('meta_key is required.', 'nibwp'));
    }

    $meta_key = sanitize_text_field($input['meta_key']);
    $meta_value = $input['meta_value'];

    switch ($object_type) {
        case 'post':
            if (!get_post($object_id)) {
                return new \WP_Error('not_found', __('Post not found.', 'nibwp'), ['status' => 404]);
            }
            $result = update_post_meta($object_id, $meta_key, $meta_value);
            break;
        case 'user':
            if (!get_userdata($object_id)) {
                return new \WP_Error('not_found', __('User not found.', 'nibwp'), ['status' => 404]);
            }
            $result = update_user_meta($object_id, $meta_key, $meta_value);
            break;
        case 'term':
            $term = get_term($object_id);
            if (!$term || is_wp_error($term)) {
                return new \WP_Error('not_found', __('Term not found.', 'nibwp'), ['status' => 404]);
            }
            $result = update_term_meta($object_id, $meta_key, $meta_value);
            break;
    }

    if (is_wp_error($result ?? null)) {
        return $result;
    }

    return [
        'success'     => true,
        'object_type' => $object_type,
        'object_id'   => $object_id,
        'meta_key'    => $meta_key,
    ];
}

// -----------------------------------------------------------------------------
// PLUGINS
// -----------------------------------------------------------------------------

function nibwp_wp_list_plugins(array $input): array|\WP_Error {
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $all_plugins = get_plugins();
    $active_plugins = get_option('active_plugins', []);
    $result = [];

    foreach ($all_plugins as $file => $data) {
        $result[] = [
            'file'        => $file,
            'name'        => $data['Name'] ?? '',
            'version'     => $data['Version'] ?? '',
            'active'      => in_array($file, $active_plugins, true),
            'description' => $data['Description'] ?? '',
            'author'      => $data['Author'] ?? '',
        ];
    }

    return ['plugins' => $result, 'total' => count($result)];
}


// =============================================================================
// ABILITY REGISTRATIONS
// =============================================================================

// -----------------------------------------------------------------------------
// POSTS
// -----------------------------------------------------------------------------

wp_register_ability('nibwp/wp-list-posts', [
    'label'       => __('List Posts', domain: 'nibwp'),
    'description' => __('List WordPress posts with filtering, search, and pagination.', domain: 'nibwp'),
    'category'    => 'wordpress',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'post_type'  => ['type' => 'string', 'default' => 'post', 'description' => 'Post type slug.'],
            'status'     => ['type' => 'string', 'default' => 'publish', 'description' => 'Post status filter.'],
            'per_page'   => ['type' => 'integer', 'default' => 20, 'maximum' => 100, 'description' => 'Results per page.'],
            'page'       => ['type' => 'integer', 'default' => 1, 'description' => 'Page number.'],
            'search'     => ['type' => 'string', 'description' => 'Search keyword.'],
            'category'   => ['type' => 'integer', 'description' => 'Category ID to filter by.'],
            'tag'        => ['type' => 'string', 'description' => 'Tag slug to filter by.'],
            'orderby'    => ['type' => 'string', 'default' => 'date', 'description' => 'Order by field.'],
            'order'      => ['type' => 'string', 'enum' => ['ASC', 'DESC'], 'default' => 'DESC'],
            'author'     => ['type' => 'integer', 'description' => 'Author user ID.'],
            'meta_key'   => ['type' => 'string', 'description' => 'Meta key to filter by.'],
            'meta_value' => ['type' => 'string', 'description' => 'Meta value to filter by.'],
        ],
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'posts'       => ['type' => 'array'],
            'total'       => ['type' => 'integer'],
            'total_pages' => ['type' => 'integer'],
            'page'        => ['type' => 'integer'],
        ],
    ],
    'execute_callback'    => 'nibwp_wp_list_posts',
    'permission_callback' => 'nibwp_wp_core_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => 'List WordPress posts with optional filters for type, status, search, category, tag, author, and meta. Supports pagination and sorting.',
            'readonly'     => true,
            'destructive'  => false,
            'idempotent'   => true,
        ],
    ],
]);

wp_register_ability('nibwp/wp-get-post', [
    'label'       => __('Get Post', domain: 'nibwp'),
    'description' => __('Get a single WordPress post with full content and metadata.', domain: 'nibwp'),
    'category'    => 'wordpress',
    'input_schema' => [
        'type'       => 'object',
        'required'   => ['post_id'],
        'properties' => [
            'post_id' => ['type' => 'integer', 'description' => 'The post ID.'],
        ],
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'id'             => ['type' => 'integer'],
            'title'          => ['type' => 'string'],
            'content'        => ['type' => 'string'],
            'excerpt'        => ['type' => 'string'],
            'status'         => ['type' => 'string'],
            'date'           => ['type' => 'string'],
            'modified'       => ['type' => 'string'],
            'author'         => ['type' => 'string'],
            'url'            => ['type' => 'string'],
            'featured_image' => ['type' => ['string', 'null']],
            'categories'     => ['type' => 'array'],
            'tags'           => ['type' => 'array'],
            'custom_fields'  => ['type' => 'object'],
        ],
    ],
    'execute_callback'    => 'nibwp_wp_get_post',
    'permission_callback' => 'nibwp_wp_core_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => 'Retrieve a single post by ID including full content, metadata, categories, tags, and custom fields.',
            'readonly'     => true,
            'destructive'  => false,
            'idempotent'   => true,
        ],
    ],
]);

wp_register_ability('nibwp/wp-create-post', [
    'label'       => __('Create Post', domain: 'nibwp'),
    'description' => __('Create a new WordPress post or custom post type entry.', domain: 'nibwp'),
    'category'    => 'wordpress',
    'input_schema' => [
        'type'       => 'object',
        'required'   => ['title'],
        'properties' => [
            'title'          => ['type' => 'string', 'description' => 'Post title.'],
            'content'        => ['type' => 'string', 'description' => 'Post content (HTML).'],
            'excerpt'        => ['type' => 'string', 'description' => 'Post excerpt.'],
            'status'         => ['type' => 'string', 'enum' => ['draft', 'publish', 'pending', 'private'], 'default' => 'draft'],
            'post_type'      => ['type' => 'string', 'default' => 'post'],
            'author'         => ['type' => 'integer', 'description' => 'Author user ID.'],
            'categories'     => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Category IDs.'],
            'tags'           => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Tag names.'],
            'featured_image' => ['type' => 'integer', 'description' => 'Attachment ID for featured image.'],
            'meta'           => ['type' => 'object', 'description' => 'Key-value pairs of custom fields.'],
            'slug'           => ['type' => 'string', 'description' => 'Post slug.'],
        ],
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'post_id'  => ['type' => 'integer'],
            'url'      => ['type' => 'string'],
            'edit_url' => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'nibwp_wp_create_post',
    'permission_callback' => 'nibwp_wp_core_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => 'Create a new post. Defaults to draft status. Supports title, content, excerpt, categories, tags, featured image, and custom meta fields.',
            'readonly'     => false,
            'destructive'  => false,
            'idempotent'   => false,
        ],
    ],
]);

wp_register_ability('nibwp/wp-update-post', [
    'label'       => __('Update Post', domain: 'nibwp'),
    'description' => __('Update an existing WordPress post.', domain: 'nibwp'),
    'category'    => 'wordpress',
    'input_schema' => [
        'type'       => 'object',
        'required'   => ['post_id'],
        'properties' => [
            'post_id'        => ['type' => 'integer', 'description' => 'The post ID to update.'],
            'title'          => ['type' => 'string'],
            'content'        => ['type' => 'string'],
            'excerpt'        => ['type' => 'string'],
            'status'         => ['type' => 'string', 'enum' => ['draft', 'publish', 'pending', 'private']],
            'post_type'      => ['type' => 'string'],
            'author'         => ['type' => 'integer'],
            'categories'     => ['type' => 'array', 'items' => ['type' => 'integer']],
            'tags'           => ['type' => 'array', 'items' => ['type' => 'string']],
            'featured_image' => ['type' => 'integer'],
            'meta'           => ['type' => 'object'],
            'slug'           => ['type' => 'string'],
        ],
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'url'     => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'nibwp_wp_update_post',
    'permission_callback' => 'nibwp_wp_core_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => 'Update an existing post by ID. Only provided fields will be changed. Supports all post fields, categories, tags, featured image, and meta.',
            'readonly'     => false,
            'destructive'  => false,
            'idempotent'   => true,
        ],
    ],
]);

wp_register_ability('nibwp/wp-delete-post', [
    'label'       => __('Delete Post', domain: 'nibwp'),
    'description' => __('Delete or trash a WordPress post.', domain: 'nibwp'),
    'category'    => 'wordpress',
    'input_schema' => [
        'type'       => 'object',
        'required'   => ['post_id'],
        'properties' => [
            'post_id' => ['type' => 'integer', 'description' => 'The post ID to delete.'],
            'force'   => ['type' => 'boolean', 'default' => false, 'description' => 'True to permanently delete, false to trash.'],
        ],
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'    => ['type' => 'boolean'],
            'deleted_id' => ['type' => 'integer'],
        ],
    ],
    'execute_callback'    => 'nibwp_wp_delete_post',
    'permission_callback' => 'nibwp_wp_core_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => 'Delete or trash a post. By default moves to trash. Set force=true to permanently delete. This action cannot be undone when force is true.',
            'readonly'     => false,
            'destructive'  => true,
            'idempotent'   => false,
        ],
    ],
]);

// -----------------------------------------------------------------------------
// MEDIA
// -----------------------------------------------------------------------------

wp_register_ability('nibwp/wp-list-media', [
    'label'       => __('List Media', domain: 'nibwp'),
    'description' => __('List media library items with optional filters.', domain: 'nibwp'),
    'category'    => 'wordpress',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'mime_type' => ['type' => 'string', 'description' => 'MIME type filter, e.g. "image", "video", "image/jpeg".'],
            'per_page'  => ['type' => 'integer', 'default' => 20],
            'page'      => ['type' => 'integer', 'default' => 1],
            'search'    => ['type' => 'string', 'description' => 'Search keyword.'],
        ],
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'items'       => ['type' => 'array'],
            'total'       => ['type' => 'integer'],
            'total_pages' => ['type' => 'integer'],
        ],
    ],
    'execute_callback'    => 'nibwp_wp_list_media',
    'permission_callback' => 'nibwp_wp_core_upload_permission',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => 'List media library items. Filter by MIME type (e.g. "image", "video") or search by keyword. Returns URLs, dimensions, and file sizes.',
            'readonly'     => true,
            'destructive'  => false,
            'idempotent'   => true,
        ],
    ],
]);

wp_register_ability('nibwp/wp-upload-media', [
    'label'       => __('Upload Media', domain: 'nibwp'),
    'description' => __('Upload media to the library from a URL.', domain: 'nibwp'),
    'category'    => 'wordpress',
    'input_schema' => [
        'type'       => 'object',
        'required'   => ['url'],
        'properties' => [
            'url'         => ['type' => 'string', 'description' => 'URL of the file to download and upload.'],
            'title'       => ['type' => 'string', 'description' => 'Attachment title.'],
            'alt_text'    => ['type' => 'string', 'description' => 'Alt text for images.'],
            'caption'     => ['type' => 'string', 'description' => 'Media caption.'],
            'description' => ['type' => 'string', 'description' => 'Media description.'],
        ],
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'attachment_id' => ['type' => 'integer'],
            'url'           => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'nibwp_wp_upload_media',
    'permission_callback' => 'nibwp_wp_core_upload_permission',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => 'Upload a file to the media library from an external URL. Optionally set title, alt text, caption, and description.',
            'readonly'     => false,
            'destructive'  => false,
            'idempotent'   => false,
        ],
    ],
]);

wp_register_ability('nibwp/wp-delete-media', [
    'label'       => __('Delete Media', domain: 'nibwp'),
    'description' => __('Delete a media attachment from the library.', domain: 'nibwp'),
    'category'    => 'wordpress',
    'input_schema' => [
        'type'       => 'object',
        'required'   => ['attachment_id'],
        'properties' => [
            'attachment_id' => ['type' => 'integer', 'description' => 'The attachment ID to delete.'],
            'force'         => ['type' => 'boolean', 'default' => true, 'description' => 'True to permanently delete.'],
        ],
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'    => ['type' => 'boolean'],
            'deleted_id' => ['type' => 'integer'],
        ],
    ],
    'execute_callback'    => 'nibwp_wp_delete_media',
    'permission_callback' => 'nibwp_wp_core_upload_permission',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => 'Permanently delete a media attachment. This removes the file from the server and cannot be undone.',
            'readonly'     => false,
            'destructive'  => true,
            'idempotent'   => false,
        ],
    ],
]);

// -----------------------------------------------------------------------------
// TAXONOMIES
// -----------------------------------------------------------------------------

wp_register_ability('nibwp/wp-list-terms', [
    'label'       => __('List Terms', domain: 'nibwp'),
    'description' => __('List terms for any taxonomy.', domain: 'nibwp'),
    'category'    => 'wordpress',
    'input_schema' => [
        'type'       => 'object',
        'required'   => ['taxonomy'],
        'properties' => [
            'taxonomy'   => ['type' => 'string', 'description' => 'Taxonomy slug, e.g. "category", "post_tag".'],
            'per_page'   => ['type' => 'integer', 'default' => 50],
            'search'     => ['type' => 'string', 'description' => 'Search terms by name.'],
            'parent'     => ['type' => 'integer', 'description' => 'Parent term ID for hierarchical taxonomies.'],
            'hide_empty' => ['type' => 'boolean', 'default' => false, 'description' => 'Hide terms with no posts.'],
            'orderby'    => ['type' => 'string', 'default' => 'name'],
        ],
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'terms' => ['type' => 'array'],
            'total' => ['type' => 'integer'],
        ],
    ],
    'execute_callback'    => 'nibwp_wp_list_terms',
    'permission_callback' => 'nibwp_wp_core_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => 'List terms for a given taxonomy (e.g. category, post_tag, or custom taxonomies). Supports search, parent filtering, and sorting.',
            'readonly'     => true,
            'destructive'  => false,
            'idempotent'   => true,
        ],
    ],
]);

wp_register_ability('nibwp/wp-create-term', [
    'label'       => __('Create Term', domain: 'nibwp'),
    'description' => __('Create a new taxonomy term.', domain: 'nibwp'),
    'category'    => 'wordpress',
    'input_schema' => [
        'type'       => 'object',
        'required'   => ['taxonomy', 'name'],
        'properties' => [
            'taxonomy'    => ['type' => 'string', 'description' => 'Taxonomy slug.'],
            'name'        => ['type' => 'string', 'description' => 'Term name.'],
            'slug'        => ['type' => 'string', 'description' => 'Term slug.'],
            'description' => ['type' => 'string', 'description' => 'Term description.'],
            'parent'      => ['type' => 'integer', 'description' => 'Parent term ID.'],
        ],
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'term_id' => ['type' => 'integer'],
            'slug'    => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'nibwp_wp_create_term',
    'permission_callback' => 'nibwp_wp_core_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => 'Create a new term in a given taxonomy. Provide the taxonomy slug and term name. Optionally set slug, description, and parent for hierarchical taxonomies.',
            'readonly'     => false,
            'destructive'  => false,
            'idempotent'   => false,
        ],
    ],
]);

wp_register_ability('nibwp/wp-update-term', [
    'label'       => __('Update Term', domain: 'nibwp'),
    'description' => __('Update an existing taxonomy term.', domain: 'nibwp'),
    'category'    => 'wordpress',
    'input_schema' => [
        'type'       => 'object',
        'required'   => ['term_id', 'taxonomy'],
        'properties' => [
            'term_id'     => ['type' => 'integer', 'description' => 'The term ID.'],
            'taxonomy'    => ['type' => 'string', 'description' => 'Taxonomy slug.'],
            'name'        => ['type' => 'string'],
            'slug'        => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'parent'      => ['type' => 'integer'],
        ],
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'term_id' => ['type' => 'integer'],
            'slug'    => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'nibwp_wp_update_term',
    'permission_callback' => 'nibwp_wp_core_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => 'Update a term by ID in a given taxonomy. Only provided fields will be changed.',
            'readonly'     => false,
            'destructive'  => false,
            'idempotent'   => true,
        ],
    ],
]);

wp_register_ability('nibwp/wp-delete-term', [
    'label'       => __('Delete Term', domain: 'nibwp'),
    'description' => __('Delete a taxonomy term.', domain: 'nibwp'),
    'category'    => 'wordpress',
    'input_schema' => [
        'type'       => 'object',
        'required'   => ['term_id', 'taxonomy'],
        'properties' => [
            'term_id'  => ['type' => 'integer', 'description' => 'The term ID to delete.'],
            'taxonomy' => ['type' => 'string', 'description' => 'Taxonomy slug.'],
        ],
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'    => ['type' => 'boolean'],
            'deleted_id' => ['type' => 'integer'],
        ],
    ],
    'execute_callback'    => 'nibwp_wp_delete_term',
    'permission_callback' => 'nibwp_wp_core_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => 'Permanently delete a taxonomy term. This cannot be undone and will remove the term from all associated posts.',
            'readonly'     => false,
            'destructive'  => true,
            'idempotent'   => false,
        ],
    ],
]);

// -----------------------------------------------------------------------------
// COMMENTS
// -----------------------------------------------------------------------------

wp_register_ability('nibwp/wp-list-comments', [
    'label'       => __('List Comments', domain: 'nibwp'),
    'description' => __('List comments with optional filters.', domain: 'nibwp'),
    'category'    => 'wordpress',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'post_id'  => ['type' => 'integer', 'description' => 'Filter by post ID.'],
            'status'   => ['type' => 'string', 'default' => 'approve', 'description' => 'Comment status: approve, hold, spam, trash.'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page'     => ['type' => 'integer', 'default' => 1],
        ],
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'comments' => ['type' => 'array'],
        ],
    ],
    'execute_callback'    => 'nibwp_wp_list_comments',
    'permission_callback' => 'nibwp_wp_core_moderate_comments_permission',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => 'List comments optionally filtered by post ID and status. Returns author, content, date, and threading info.',
            'readonly'     => true,
            'destructive'  => false,
            'idempotent'   => true,
        ],
    ],
]);

wp_register_ability('nibwp/wp-create-comment', [
    'label'       => __('Create Comment', domain: 'nibwp'),
    'description' => __('Create a new comment on a post.', domain: 'nibwp'),
    'category'    => 'wordpress',
    'input_schema' => [
        'type'       => 'object',
        'required'   => ['post_id', 'content'],
        'properties' => [
            'post_id'      => ['type' => 'integer', 'description' => 'The post ID to comment on.'],
            'content'      => ['type' => 'string', 'description' => 'Comment content.'],
            'author'       => ['type' => 'string', 'description' => 'Comment author name.'],
            'author_email' => ['type' => 'string', 'description' => 'Comment author email.'],
            'author_url'   => ['type' => 'string', 'description' => 'Comment author URL.'],
            'parent'       => ['type' => 'integer', 'description' => 'Parent comment ID for threading.'],
            'status'       => ['type' => 'string', 'default' => 'approve'],
        ],
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'comment_id' => ['type' => 'integer'],
        ],
    ],
    'execute_callback'    => 'nibwp_wp_create_comment',
    'permission_callback' => 'nibwp_wp_core_moderate_comments_permission',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => 'Create a new comment on a post. Provide post_id and content. Optionally set author info and parent for threading.',
            'readonly'     => false,
            'destructive'  => false,
            'idempotent'   => false,
        ],
    ],
]);

wp_register_ability('nibwp/wp-update-comment', [
    'label'       => __('Update Comment', domain: 'nibwp'),
    'description' => __('Update an existing comment.', domain: 'nibwp'),
    'category'    => 'wordpress',
    'input_schema' => [
        'type'       => 'object',
        'required'   => ['comment_id'],
        'properties' => [
            'comment_id' => ['type' => 'integer', 'description' => 'The comment ID to update.'],
            'content'    => ['type' => 'string', 'description' => 'Updated comment content.'],
            'status'     => ['type' => 'string', 'enum' => ['approve', 'hold', 'spam', 'trash'], 'description' => 'Comment status.'],
        ],
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'comment_id' => ['type' => 'integer'],
            'success'    => ['type' => 'boolean'],
        ],
    ],
    'execute_callback'    => 'nibwp_wp_update_comment',
    'permission_callback' => 'nibwp_wp_core_moderate_comments_permission',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => 'Update a comment by ID. Change content or moderation status (approve, hold, spam, trash).',
            'readonly'     => false,
            'destructive'  => false,
            'idempotent'   => true,
        ],
    ],
]);

wp_register_ability('nibwp/wp-delete-comment', [
    'label'       => __('Delete Comment', domain: 'nibwp'),
    'description' => __('Delete or trash a comment.', domain: 'nibwp'),
    'category'    => 'wordpress',
    'input_schema' => [
        'type'       => 'object',
        'required'   => ['comment_id'],
        'properties' => [
            'comment_id' => ['type' => 'integer', 'description' => 'The comment ID to delete.'],
            'force'      => ['type' => 'boolean', 'default' => false, 'description' => 'True to permanently delete.'],
        ],
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'    => ['type' => 'boolean'],
            'deleted_id' => ['type' => 'integer'],
        ],
    ],
    'execute_callback'    => 'nibwp_wp_delete_comment',
    'permission_callback' => 'nibwp_wp_core_moderate_comments_permission',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => 'Delete or trash a comment. By default moves to trash. Set force=true to permanently delete.',
            'readonly'     => false,
            'destructive'  => true,
            'idempotent'   => false,
        ],
    ],
]);

// -----------------------------------------------------------------------------
// MENUS
// -----------------------------------------------------------------------------

wp_register_ability('nibwp/wp-list-menus', [
    'label'       => __('List Menus', domain: 'nibwp'),
    'description' => __('List all registered navigation menus.', domain: 'nibwp'),
    'category'    => 'wordpress',
    'input_schema' => [
        'type'       => 'object',
        'properties' => (object) [],
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'menus' => ['type' => 'array'],
        ],
    ],
    'execute_callback'    => 'nibwp_wp_list_menus',
    'permission_callback' => 'nibwp_wp_core_edit_theme_options_permission',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => 'List all navigation menus. Returns menu ID, name, slug, and item count.',
            'readonly'     => true,
            'destructive'  => false,
            'idempotent'   => true,
        ],
    ],
]);

wp_register_ability('nibwp/wp-get-menu-items', [
    'label'       => __('Get Menu Items', domain: 'nibwp'),
    'description' => __('Get all items for a navigation menu.', domain: 'nibwp'),
    'category'    => 'wordpress',
    'input_schema' => [
        'type'       => 'object',
        'required'   => ['menu_id'],
        'properties' => [
            'menu_id' => ['type' => 'integer', 'description' => 'The menu ID.'],
        ],
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'items' => ['type' => 'array'],
        ],
    ],
    'execute_callback'    => 'nibwp_wp_get_menu_items',
    'permission_callback' => 'nibwp_wp_core_edit_theme_options_permission',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => 'Get all items in a navigation menu by menu ID. Returns title, URL, type, parent, and position for each item.',
            'readonly'     => true,
            'destructive'  => false,
            'idempotent'   => true,
        ],
    ],
]);

wp_register_ability('nibwp/wp-create-menu', [
    'label'       => __('Create Menu', domain: 'nibwp'),
    'description' => __('Create a new navigation menu.', domain: 'nibwp'),
    'category'    => 'wordpress',
    'input_schema' => [
        'type'       => 'object',
        'required'   => ['name'],
        'properties' => [
            'name' => ['type' => 'string', 'description' => 'Menu name.'],
        ],
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'menu_id' => ['type' => 'integer'],
            'name'    => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'nibwp_wp_create_menu',
    'permission_callback' => 'nibwp_wp_core_edit_theme_options_permission',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => 'Create a new empty navigation menu with a given name.',
            'readonly'     => false,
            'destructive'  => false,
            'idempotent'   => false,
        ],
    ],
]);

wp_register_ability('nibwp/wp-add-menu-item', [
    'label'       => __('Add Menu Item', domain: 'nibwp'),
    'description' => __('Add an item to a navigation menu.', domain: 'nibwp'),
    'category'    => 'wordpress',
    'input_schema' => [
        'type'       => 'object',
        'required'   => ['menu_id', 'title'],
        'properties' => [
            'menu_id'     => ['type' => 'integer', 'description' => 'The menu ID.'],
            'title'       => ['type' => 'string', 'description' => 'Menu item title.'],
            'url'         => ['type' => 'string', 'description' => 'URL for custom links.'],
            'object_type' => ['type' => 'string', 'enum' => ['custom', 'post_type', 'taxonomy'], 'default' => 'custom'],
            'object_id'   => ['type' => 'integer', 'description' => 'Post or term ID when object_type is post_type or taxonomy.'],
            'parent'      => ['type' => 'integer', 'default' => 0, 'description' => 'Parent menu item ID.'],
            'position'    => ['type' => 'integer', 'description' => 'Menu item position.'],
        ],
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'menu_item_id' => ['type' => 'integer'],
        ],
    ],
    'execute_callback'    => 'nibwp_wp_add_menu_item',
    'permission_callback' => 'nibwp_wp_core_edit_theme_options_permission',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => 'Add an item to an existing menu. Use object_type "custom" for URLs, "post_type" to link a post/page, or "taxonomy" to link a term.',
            'readonly'     => false,
            'destructive'  => false,
            'idempotent'   => false,
        ],
    ],
]);

// -----------------------------------------------------------------------------
// USERS
// -----------------------------------------------------------------------------

wp_register_ability('nibwp/wp-list-users', [
    'label'       => __('List Users', domain: 'nibwp'),
    'description' => __('List WordPress users with optional filters.', domain: 'nibwp'),
    'category'    => 'wordpress',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'role'    => ['type' => 'string', 'description' => 'Filter by user role.'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page'     => ['type' => 'integer', 'default' => 1],
            'search'   => ['type' => 'string', 'description' => 'Search by name, email, or login.'],
            'orderby'  => ['type' => 'string', 'default' => 'display_name'],
        ],
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'users' => ['type' => 'array'],
            'total' => ['type' => 'integer'],
        ],
    ],
    'execute_callback'    => 'nibwp_wp_list_users',
    'permission_callback' => 'nibwp_wp_core_manage_users_permission',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => 'List users with optional role filter and search. Returns login, email, display name, role, and registration date.',
            'readonly'     => true,
            'destructive'  => false,
            'idempotent'   => true,
        ],
    ],
]);

wp_register_ability('nibwp/wp-get-user', [
    'label'       => __('Get User', domain: 'nibwp'),
    'description' => __('Get detailed information about a user.', domain: 'nibwp'),
    'category'    => 'wordpress',
    'input_schema' => [
        'type'       => 'object',
        'required'   => ['user_id'],
        'properties' => [
            'user_id' => ['type' => 'integer', 'description' => 'The user ID.'],
        ],
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'id'           => ['type' => 'integer'],
            'login'        => ['type' => 'string'],
            'email'        => ['type' => 'string'],
            'display_name' => ['type' => 'string'],
            'roles'        => ['type' => 'array'],
            'registered'   => ['type' => 'string'],
            'description'  => ['type' => 'string'],
            'url'          => ['type' => 'string'],
            'meta'         => ['type' => 'object'],
        ],
    ],
    'execute_callback'    => 'nibwp_wp_get_user',
    'permission_callback' => 'nibwp_wp_core_manage_users_permission',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => 'Get detailed user information by ID including roles, description, and all user meta.',
            'readonly'     => true,
            'destructive'  => false,
            'idempotent'   => true,
        ],
    ],
]);

// -----------------------------------------------------------------------------
// SITE
// -----------------------------------------------------------------------------

wp_register_ability('nibwp/wp-get-site-info', [
    'label'       => __('Get Site Info', domain: 'nibwp'),
    'description' => __('Get WordPress site information and configuration.', domain: 'nibwp'),
    'category'    => 'wordpress',
    'input_schema' => [
        'type'       => 'object',
        'properties' => (object) [],
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'name'                => ['type' => 'string'],
            'description'         => ['type' => 'string'],
            'url'                 => ['type' => 'string'],
            'admin_email'         => ['type' => 'string'],
            'wp_version'          => ['type' => 'string'],
            'php_version'         => ['type' => 'string'],
            'timezone'            => ['type' => 'string'],
            'language'            => ['type' => 'string'],
            'permalink_structure' => ['type' => 'string'],
            'active_theme'        => ['type' => 'string'],
            'is_multisite'        => ['type' => 'boolean'],
            'post_types'          => ['type' => 'array'],
            'taxonomies'          => ['type' => 'array'],
        ],
    ],
    'execute_callback'    => 'nibwp_wp_get_site_info',
    'permission_callback' => 'nibwp_wp_core_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => 'Get general site information including name, URL, WP/PHP versions, timezone, active theme, registered post types, and taxonomies.',
            'readonly'     => true,
            'destructive'  => false,
            'idempotent'   => true,
        ],
    ],
]);

wp_register_ability('nibwp/wp-get-site-stats', [
    'label'       => __('Get Site Stats', domain: 'nibwp'),
    'description' => __('Get site content statistics and counts.', domain: 'nibwp'),
    'category'    => 'wordpress',
    'input_schema' => [
        'type'       => 'object',
        'properties' => (object) [],
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'posts'      => ['type' => 'integer'],
            'pages'      => ['type' => 'integer'],
            'comments'   => ['type' => 'integer'],
            'users'      => ['type' => 'integer'],
            'categories' => ['type' => 'integer'],
            'tags'       => ['type' => 'integer'],
            'media'      => ['type' => 'integer'],
            'plugins'    => ['type' => 'integer'],
        ],
    ],
    'execute_callback'    => 'nibwp_wp_get_site_stats',
    'permission_callback' => 'nibwp_wp_core_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => 'Get content counts for posts, pages, comments, users, categories, tags, media, and active plugins.',
            'readonly'     => true,
            'destructive'  => false,
            'idempotent'   => true,
        ],
    ],
]);

wp_register_ability('nibwp/wp-update-option', [
    'label'       => __('Update Option', domain: 'nibwp'),
    'description' => __('Update a WordPress option value.', domain: 'nibwp'),
    'category'    => 'wordpress',
    'input_schema' => [
        'type'       => 'object',
        'required'   => ['option_name', 'option_value'],
        'properties' => [
            'option_name'  => ['type' => 'string', 'description' => 'The option name.'],
            'option_value' => ['description' => 'The new option value.'],
        ],
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'     => ['type' => 'boolean'],
            'option_name' => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'nibwp_wp_update_option',
    'permission_callback' => 'nibwp_wp_core_manage_options_permission',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => 'Update a WordPress option. The option must already exist. Use with caution as changing options can affect site behavior.',
            'readonly'     => false,
            'destructive'  => true,
            'idempotent'   => true,
        ],
    ],
]);

wp_register_ability('nibwp/wp-get-option', [
    'label'       => __('Get Option', domain: 'nibwp'),
    'description' => __('Get a WordPress option value.', domain: 'nibwp'),
    'category'    => 'wordpress',
    'input_schema' => [
        'type'       => 'object',
        'required'   => ['option_name'],
        'properties' => [
            'option_name' => ['type' => 'string', 'description' => 'The option name.'],
        ],
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'option_name'  => ['type' => 'string'],
            'option_value' => ['description' => 'The option value.'],
        ],
    ],
    'execute_callback'    => 'nibwp_wp_get_option',
    'permission_callback' => 'nibwp_wp_core_manage_options_permission',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => 'Retrieve the value of a WordPress option by name.',
            'readonly'     => true,
            'destructive'  => false,
            'idempotent'   => true,
        ],
    ],
]);

wp_register_ability('nibwp/wp-search', [
    'label'       => __('Search', domain: 'nibwp'),
    'description' => __('Global search across WordPress content types.', domain: 'nibwp'),
    'category'    => 'wordpress',
    'input_schema' => [
        'type'       => 'object',
        'required'   => ['query'],
        'properties' => [
            'query'      => ['type' => 'string', 'description' => 'Search query string.'],
            'post_types' => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => ['post', 'page'], 'description' => 'Post types to search.'],
            'per_page'   => ['type' => 'integer', 'default' => 10],
        ],
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'results' => ['type' => 'array'],
            'total'   => ['type' => 'integer'],
            'query'   => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'nibwp_wp_search',
    'permission_callback' => 'nibwp_wp_core_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => 'Search across all published content. Specify post types to narrow results. Returns titles, excerpts, URLs, and dates.',
            'readonly'     => true,
            'destructive'  => false,
            'idempotent'   => true,
        ],
    ],
]);

// -----------------------------------------------------------------------------
// META
// -----------------------------------------------------------------------------

wp_register_ability('nibwp/wp-get-meta', [
    'label'       => __('Get Meta', domain: 'nibwp'),
    'description' => __('Get meta values for a post, user, or term.', domain: 'nibwp'),
    'category'    => 'wordpress',
    'input_schema' => [
        'type'       => 'object',
        'required'   => ['object_type', 'object_id'],
        'properties' => [
            'object_type' => ['type' => 'string', 'enum' => ['post', 'user', 'term'], 'description' => 'The object type.'],
            'object_id'   => ['type' => 'integer', 'description' => 'The object ID.'],
            'meta_key'    => ['type' => 'string', 'description' => 'Specific meta key. Omit to get all meta.'],
        ],
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'object_type' => ['type' => 'string'],
            'object_id'   => ['type' => 'integer'],
            'meta_key'    => ['type' => ['string', 'null']],
            'meta'        => ['description' => 'Meta value or all meta key-value pairs.'],
        ],
    ],
    'execute_callback'    => 'nibwp_wp_get_meta',
    'permission_callback' => 'nibwp_wp_core_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => 'Get meta for a post, user, or term. Provide a specific meta_key to get one value, or omit it to get all meta.',
            'readonly'     => true,
            'destructive'  => false,
            'idempotent'   => true,
        ],
    ],
]);

wp_register_ability('nibwp/wp-update-meta', [
    'label'       => __('Update Meta', domain: 'nibwp'),
    'description' => __('Update a meta value for a post, user, or term.', domain: 'nibwp'),
    'category'    => 'wordpress',
    'input_schema' => [
        'type'       => 'object',
        'required'   => ['object_type', 'object_id', 'meta_key', 'meta_value'],
        'properties' => [
            'object_type' => ['type' => 'string', 'enum' => ['post', 'user', 'term'], 'description' => 'The object type.'],
            'object_id'   => ['type' => 'integer', 'description' => 'The object ID.'],
            'meta_key'    => ['type' => 'string', 'description' => 'The meta key.'],
            'meta_value'  => ['description' => 'The meta value to set.'],
        ],
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'     => ['type' => 'boolean'],
            'object_type' => ['type' => 'string'],
            'object_id'   => ['type' => 'integer'],
            'meta_key'    => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'nibwp_wp_update_meta',
    'permission_callback' => 'nibwp_wp_core_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => 'Update a single meta key-value pair on a post, user, or term. Creates the meta if it does not exist.',
            'readonly'     => false,
            'destructive'  => false,
            'idempotent'   => true,
        ],
    ],
]);

wp_register_ability('nibwp/wp-list-plugins', [
    'label'       => __('List Plugins', domain: 'nibwp'),
    'description' => __('List all installed WordPress plugins.', domain: 'nibwp'),
    'category'    => 'wordpress',
    'input_schema' => [
        'type'       => 'object',
        'properties' => (object) [],
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'plugins' => ['type' => 'array'],
            'total'   => ['type' => 'integer'],
        ],
    ],
    'execute_callback'    => 'nibwp_wp_list_plugins',
    'permission_callback' => 'nibwp_wp_core_manage_options_permission',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => 'List all installed plugins with name, version, active status, description, and author.',
            'readonly'     => true,
            'destructive'  => false,
            'idempotent'   => true,
        ],
    ],
]);

/**
 * Skill-route sniffer for nibwp/wp-create-post (B7 from v2 design).
 *
 * Returns:
 *   - null when input looks fine (caller proceeds with creation),
 *   - an array {requires_user_input:true, …} when content matches a skill
 *     trigger / smells like a generator dump and a v2 skill is unlocked.
 *
 * Suppressed when:
 *   - No mandatory_routing skill is unlocked.
 *   - $input['raw_html_confirmation'] is truthy (explicit user escape).
 *   - post_type is anything other than post/page.
 */
function nibwp_wp_create_post_route_sniffer(array $input): ?array
{
    if (!empty($input['raw_html_confirmation'])) {
        return null;
    }
    $post_type = (string) ($input['post_type'] ?? 'post');
    if (!in_array($post_type, ['post', 'page'], true)) {
        return null;
    }

    if (!function_exists('nibwp_skills_skill_cards')) {
        return null;
    }
    $cards = nibwp_skills_skill_cards();
    if ($cards === []) {
        return null;
    }

    $content = (string) ($input['content'] ?? '');
    $title   = (string) ($input['title']   ?? '');

    $has_style_tag        = (bool) preg_match('/<style\b/i', $content);
    $has_external_sheet   = (bool) preg_match('/<link\b[^>]*\brel\s*=\s*["\']?stylesheet/i', $content) || (stripos($content, '@import') !== false);
    $has_iframe_provider  = (bool) preg_match('~<iframe\b[^>]*\bsrc\s*=\s*["\'][^"\']*(?:youtube\.com|youtu\.be|vimeo\.com)~i', $content);
    $line_count           = substr_count($content, "\n") + 1;
    $long_html            = $line_count > 50 && (bool) preg_match('/<(?:div|section|article|main|header|footer)\b/i', $content);
    $matches_skill_trigger = false;
    $matched_skill        = null;
    $matched_pattern      = '';

    $probe = $title . "\n" . $content;
    foreach ($cards as $card) {
        foreach ((array) ($card['triggers'] ?? []) as $pattern) {
            if (!is_string($pattern) || $pattern === '') {
                continue;
            }
            $ok = @preg_match($pattern, $probe);
            if ($ok === 1) {
                $matches_skill_trigger = true;
                $matched_skill = $card;
                $matched_pattern = $pattern;
                break 2;
            }
        }
    }

    if (!$has_style_tag && !$has_external_sheet && !$has_iframe_provider && !$long_html && !$matches_skill_trigger) {
        return null;
    }

    $offending = array_values(array_filter([
        $has_style_tag       ? 'raw <style> tag' : null,
        $has_external_sheet  ? 'external <link rel="stylesheet"> / @import' : null,
        $has_iframe_provider ? 'raw YouTube/Vimeo <iframe>' : null,
        $long_html           ? sprintf('long HTML dump (%d lines)', $line_count) : null,
        $matches_skill_trigger ? sprintf('skill trigger matched (%s)', (string) ($matched_skill['skill_id'] ?? '?')) : null,
    ]));

    $suggested_skill = $matched_skill ?? $cards[0];
    $top_command = '';
    foreach ((array) ($suggested_skill['commands'] ?? []) as $cmd => $_info) {
        $top_command = (string) $cmd;
        break;
    }

    return [
        'success'             => false,
        'requires_user_input' => true,
        'sniffer_triggered'   => true,
        'reason'              => sprintf('wp/create-post received content that looks like a skill-eligible payload (%s). The %s skill is unlocked and should own this conversion.', implode(', ', $offending), (string) ($suggested_skill['name'] ?? $suggested_skill['skill_id'] ?? 'unknown')),
        'detected'            => [
            'has_style_tag'           => $has_style_tag,
            'has_external_stylesheet' => $has_external_sheet,
            'has_iframe_provider'     => $has_iframe_provider,
            'long_html'               => $long_html,
            'line_count'              => $line_count,
            'matched_pattern'         => $matched_pattern,
        ],
        'suggested_skill_id'  => (string) ($suggested_skill['skill_id'] ?? ''),
        'suggested_routing'   => array_values((array) ($suggested_skill['pipeline'] ?? [])),
        'suggested_command'   => $top_command,
        'choices'             => [
            'route_through_skill' => sprintf('Call %s (preflight first), then submit the validated payload through the skill pipeline.', (string) ($suggested_skill['preflight_ability'] ?? 'nibwp/skill-preflight')),
            'force_raw_html'      => 'Confirm raw HTML is intentional and resubmit nibwp/wp-create-post with raw_html_confirmation:true.',
        ],
        'next_action'         => 'Ask the user which choice. Default to route_through_skill unless they explicitly want raw HTML.',
    ];
}
