<?php

declare(strict_types=1);

/**
 * Prove that what a builder skill wrote actually appears on the page.
 *
 * Every builder skill validates its output before writing, and every one of them
 * was still able to ship markup that saved cleanly, parsed cleanly, passed its
 * own validator, and rendered wrong. Two Kadence bugs shipped that way in the
 * same week: a row missing `kbVersion` produced no wrapper and silently dropped
 * every row-level style, and an image written attribute-only produced no `<img>`
 * at all. Both serialised perfectly. Neither validator could have known.
 *
 * The reason is structural rather than careless. A validator checks the shape of
 * what we are about to write against what we believe the builder wants. When the
 * belief is wrong, the validator is wrong in exactly the same way, and the two
 * agree with each other all the way to production. Rendering asks a different
 * question, of a different authority: not "is this what we intended" but "what
 * does the builder actually do with it". That is the only question whose answer
 * we do not already think we know.
 *
 * So this writes to a scratch draft, renders it through the builder's own code,
 * checks what came back, and deletes the draft. It is deliberately cheap enough
 * to run on every conversion.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Render a candidate document and report what survived.
 *
 * @param array{
 *   builder?: string,
 *   content?: string,
 *   meta?: array<string, mixed>,
 *   post_type?: string,
 *   expect_text?: array<int, string>,
 *   expect_markup?: array<int, string>,
 *   forbid_markup?: array<int, string>,
 *   min_bytes?: int
 * } $spec
 * @return array<string, mixed>
 */
function nibwp_render_check(array $spec): array
{
    $builder = (string) ($spec['builder'] ?? 'blocks');
    $content = (string) ($spec['content'] ?? '');
    $meta    = is_array($spec['meta'] ?? null) ? $spec['meta'] : [];

    // Voxel's widgets read the template they are placed in and return early when
    // that context is missing, so rendering one on an ordinary page reports an
    // empty result that says nothing about the markup. Each builder therefore
    // gets the post type it is actually built for rather than a shared default.
    $post_type = (string) ($spec['post_type'] ?? nibwp_render_check_post_type($builder));

    $report = [
        'builder'        => $builder,
        'passed'         => false,
        'checks'         => [],
        'rendered_bytes' => 0,
        'errors'         => [],
    ];

    $post_id = wp_insert_post([
        'post_title'   => 'NIBWP render check (temporary)',
        'post_type'    => $post_type,
        'post_status'  => 'draft',
        // Slashed, because wp_insert_post unslashes what it is handed and would
        // otherwise eat the JSON escaping inside block comments.
        'post_content' => wp_slash($content),
    ], true);

    if (is_wp_error($post_id)) {
        $report['errors'][] = $post_id->get_error_message();
        return $report;
    }

    try {
        foreach ($meta as $key => $value) {
            update_post_meta($post_id, (string) $key, is_string($value) ? wp_slash($value) : $value);
        }

        $html = nibwp_render_check_render($builder, $post_id, $content);
        $report['rendered_bytes'] = strlen($html);

        $checks = [];

        foreach ((array) ($spec['expect_text'] ?? []) as $needle) {
            $needle = (string) $needle;
            if ($needle === '') {
                continue;
            }
            $checks[] = [
                'kind'   => 'text',
                'needle' => $needle,
                'passed' => str_contains(wp_strip_all_tags($html), $needle),
                'why'    => 'Authored text has to reach the page. Text that validates and does '
                          . 'not render is the failure nobody notices until a client does.',
            ];
        }

        foreach ((array) ($spec['expect_markup'] ?? []) as $needle) {
            $needle = (string) $needle;
            if ($needle === '') {
                continue;
            }
            $checks[] = [
                'kind'   => 'markup',
                'needle' => $needle,
                'passed' => str_contains($html, $needle),
                'why'    => 'A wrapper the builder generates from its own attributes. Missing means '
                          . 'the builder declined to build it, which is how styling disappears '
                          . 'while the text stays.',
            ];
        }

        foreach ((array) ($spec['forbid_markup'] ?? []) as $needle) {
            $needle = (string) $needle;
            if ($needle === '') {
                continue;
            }
            $checks[] = [
                'kind'   => 'forbidden',
                'needle' => $needle,
                'passed' => !str_contains($html, $needle),
                'why'    => 'Present means the builder fell back to a placeholder or an error state.',
            ];
        }

        $min = (int) ($spec['min_bytes'] ?? 0);
        if ($min > 0) {
            $checks[] = [
                'kind'   => 'min_bytes',
                'needle' => (string) $min,
                'passed' => strlen($html) >= $min,
                'why'    => 'A document that renders to almost nothing has been dropped somewhere.',
            ];
        }

        $report['checks'] = $checks;
        $report['passed'] = $checks !== [] && !in_array(false, array_column($checks, 'passed'), true);
        $report['failed'] = array_values(array_filter($checks, static fn($c) => !$c['passed']));
    } catch (\Throwable $e) {
        // A builder that fatals on our markup is the most valuable result of all,
        // so it is caught and reported rather than taking the request down.
        $report['errors'][] = $e->getMessage();
    } finally {
        // The scratch post goes away whatever happened. Leaving debris in
        // someone's page list is its own small betrayal.
        wp_delete_post($post_id, true);
    }

    return $report;
}

/**
 * The post type a builder's output has to live in to render honestly.
 */
function nibwp_render_check_post_type(string $builder): string
{
    if ($builder === 'voxel') {
        // Voxel templates are elementor_library posts, and its widgets read that
        // context. On an ordinary page they return early and render nothing,
        // which would look exactly like a bug in whatever wrote the markup.
        return post_type_exists('elementor_library') ? 'elementor_library' : 'page';
    }

    return 'page';
}

/**
 * Run the builder's own render path. Nothing here reimplements a builder: the
 * whole point is to ask the builder rather than to model it.
 */
function nibwp_render_check_render(string $builder, int $post_id, string $content): string
{
    if ($builder === 'elementor' || $builder === 'voxel') {
        if (class_exists('\\Elementor\\Plugin')) {
            $frontend = \Elementor\Plugin::instance()->frontend;
            return (string) $frontend->get_builder_content_for_display($post_id, true);
        }
        return '';
    }

    if ($builder === 'etch') {
        // Etch renders through the ordinary content filters.
        $post = get_post($post_id);
        return (string) apply_filters('the_content', $post ? $post->post_content : $content);
    }

    // Blocks: Kadence, core, and anything else that lives in post_content.
    $post = get_post($post_id);
    return (string) do_blocks($post ? $post->post_content : $content);
}

/**
 * One line an agent can read, so a failed render says what to do next rather
 * than only that something is wrong.
 *
 * @param array<string, mixed> $report
 */
function nibwp_render_check_summary(array $report): string
{
    if (!empty($report['errors'])) {
        return sprintf(
            /* translators: %s: error message from the builder */
            __('The builder threw an error while rendering this: %s. The markup is wrong in a way the validator cannot see.', 'nibwp'),
            implode('; ', (array) $report['errors'])
        );
    }

    if (!empty($report['passed'])) {
        return sprintf(
            /* translators: 1: number of checks, 2: rendered size in bytes */
            __('Rendered and verified: %1$d check(s) passed, %2$s bytes of output.', 'nibwp'),
            count((array) $report['checks']),
            number_format_i18n((int) $report['rendered_bytes'])
        );
    }

    $failed = (array) ($report['failed'] ?? []);
    if ($failed === []) {
        return __('Nothing was checked, so nothing was proven. Give the render check something to look for.', 'nibwp');
    }

    $bits = [];
    foreach ($failed as $c) {
        $bits[] = $c['kind'] === 'forbidden'
            ? sprintf('"%s" should not be on the page but is', $c['needle'])
            : sprintf('"%s" is missing from the rendered page', $c['needle']);
    }

    return sprintf(
        /* translators: 1: list of failures, 2: rendered size */
        __('This saves and validates but does not render correctly: %1$s. Output was %2$s bytes. Fix the attributes rather than the markup.', 'nibwp'),
        implode('; ', $bits),
        number_format_i18n((int) $report['rendered_bytes'])
    );
}
