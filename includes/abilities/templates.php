<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * NIBWP — Templates library.
 *
 * Curated starter templates the agent can apply in one call instead of
 * synthesizing from scratch. Categories: form, acf, post, page, menu,
 * fluentcrm, woocommerce. Each template declares the target ability + a
 * ready-made parameter payload.
 *
 * Agent flow:
 *   1. nibwp/templates-list { category: "form" }      → see available
 *   2. nibwp/templates-apply { template_id: "form:contact-basic" } → ships it
 *
 * Apply also accepts `overrides` to tweak strings (title, intro, fields)
 * before forwarding to the target ability.
 */

add_action('wp_abilities_api_init', 'nibwp_templates_register_abilities', 15);

function nibwp_templates_register_abilities(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }
    if (! nibwp_has_ability('nibwp/templates-list')) {
        wp_register_ability('nibwp/templates-list', [
            'label'       => __('Templates — List', 'nibwp'),
            'description' => 'List ready-to-apply templates (form / acf / post / page / menu / crm). Each template ships with a target ability + pre-filled parameters so a single templates-apply call creates the asset. Filter by category if known.',
            'category'    => 'nibwp',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'category' => ['type' => 'string'],
                    'q'        => ['type' => 'string', 'description' => 'Optional substring search.'],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'templates' => ['type' => 'array'],
                ],
            ],
            'execute_callback'    => 'nibwp_templates_list',
            'permission_callback' => 'nibwp_permission_callback',
            'meta' => [
                'show_in_rest' => true,
                'mcp'          => ['public' => true, 'type' => 'tool'],
                'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
        ]);
    }
    if (! nibwp_has_ability('nibwp/templates-apply')) {
        wp_register_ability('nibwp/templates-apply', [
            'label'       => __('Templates — Apply', 'nibwp'),
            'description' => 'Apply a named starter template. Single call creates: contact form, newsletter signup, SEO ACF group, product spec ACF group, blog post layout, BuddyPress group, etc. Pass `overrides` to tweak titles/labels before persistence.',
            'category'    => 'nibwp',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'template_id' => ['type' => 'string'],
                    'overrides'   => ['type' => 'object'],
                ],
                'required' => ['template_id'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'applied'  => ['type' => 'boolean'],
                    'ability'  => ['type' => 'string'],
                    'result'   => ['type' => 'object'],
                ],
            ],
            'execute_callback'    => 'nibwp_templates_apply',
            'permission_callback' => 'nibwp_permission_callback',
            'meta' => [
                'show_in_rest' => true,
                'mcp'          => ['public' => true, 'type' => 'tool'],
                'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
            ],
        ]);
    }
}

/**
 * Template definitions. Each template is:
 *   id          — `<category>:<slug>`
 *   label       — short human label
 *   description — when to use it
 *   category    — form / acf / post / page / menu / crm / fluentcart / cta
 *   ability     — target NIBWP ability to invoke on apply
 *   parameters  — pre-filled payload (merged with `overrides` deep)
 *
 * @return array<int, array<string, mixed>>
 */
function nibwp_templates_definitions(): array
{
    return [
        // ─── Forms ─────────────────────────────────────────────────────
        [
            'id' => 'form:contact-basic',
            'category' => 'form',
            'label' => 'Contact form (name + email + message)',
            'description' => '3-field contact form. Submits to the user\'s preferred form plugin.',
            'ability' => 'nibwp/forms-manage',
            'parameters' => [
                'action' => 'create_form',
                'title'  => 'Contact',
                'fields' => [
                    ['type' => 'text',     'label' => 'Name',    'name' => 'name',    'required' => true],
                    ['type' => 'email',    'label' => 'Email',   'name' => 'email',   'required' => true],
                    ['type' => 'textarea', 'label' => 'Message', 'name' => 'message', 'required' => true],
                ],
            ],
        ],
        [
            'id' => 'form:newsletter-signup',
            'category' => 'form',
            'label' => 'Newsletter signup (email-only)',
            'description' => 'Single email field with consent checkbox.',
            'ability' => 'nibwp/forms-manage',
            'parameters' => [
                'action' => 'create_form',
                'title'  => 'Newsletter signup',
                'fields' => [
                    ['type' => 'email', 'label' => 'Email',   'name' => 'email',   'required' => true],
                    ['type' => 'checkbox', 'label' => 'I agree to receive emails', 'name' => 'consent', 'required' => true],
                ],
            ],
        ],
        [
            'id' => 'form:quote-request',
            'category' => 'form',
            'label' => 'Quote request',
            'description' => 'Name / company / email / phone / project description / budget.',
            'ability' => 'nibwp/forms-manage',
            'parameters' => [
                'action' => 'create_form',
                'title'  => 'Request a quote',
                'fields' => [
                    ['type' => 'text',     'label' => 'Name',        'name' => 'name',    'required' => true],
                    ['type' => 'text',     'label' => 'Company',     'name' => 'company'],
                    ['type' => 'email',    'label' => 'Email',       'name' => 'email',   'required' => true],
                    ['type' => 'tel',      'label' => 'Phone',       'name' => 'phone'],
                    ['type' => 'textarea', 'label' => 'Description', 'name' => 'description', 'required' => true],
                    ['type' => 'select',   'label' => 'Budget',      'name' => 'budget',
                        'options' => ['<5k', '5k-15k', '15k-50k', '50k+']],
                ],
            ],
        ],
        [
            'id' => 'form:event-rsvp',
            'category' => 'form',
            'label' => 'Event RSVP',
            'description' => 'Name / email / number of guests / dietary notes.',
            'ability' => 'nibwp/forms-manage',
            'parameters' => [
                'action' => 'create_form',
                'title'  => 'RSVP',
                'fields' => [
                    ['type' => 'text',  'label' => 'Name',    'name' => 'name',  'required' => true],
                    ['type' => 'email', 'label' => 'Email',   'name' => 'email', 'required' => true],
                    ['type' => 'number','label' => 'Guests',  'name' => 'guests','default' => 1],
                    ['type' => 'textarea','label' => 'Dietary notes', 'name' => 'dietary'],
                ],
            ],
        ],
        [
            'id' => 'form:support-ticket',
            'category' => 'form',
            'label' => 'Support ticket',
            'description' => 'Name / email / priority / topic / description.',
            'ability' => 'nibwp/forms-manage',
            'parameters' => [
                'action' => 'create_form',
                'title'  => 'Support ticket',
                'fields' => [
                    ['type' => 'text',  'label' => 'Name',    'name' => 'name',  'required' => true],
                    ['type' => 'email', 'label' => 'Email',   'name' => 'email', 'required' => true],
                    ['type' => 'select','label' => 'Priority','name' => 'priority',
                        'options' => ['Low', 'Normal', 'High', 'Urgent']],
                    ['type' => 'text',  'label' => 'Topic',   'name' => 'topic', 'required' => true],
                    ['type' => 'textarea','label' => 'Description', 'name' => 'description', 'required' => true],
                ],
            ],
        ],

        // ─── ACF groups ────────────────────────────────────────────────
        [
            'id' => 'acf:seo',
            'category' => 'acf',
            'label' => 'SEO meta (title / description / OG image)',
            'description' => 'Per-post SEO override fields. Attaches to post + page by default.',
            'ability' => 'nibwp/acf-manage-fields',
            'parameters' => [
                'action' => 'create_group',
                'group_data' => [
                    'title' => 'SEO',
                    'fields' => [
                        ['name' => 'seo_title',       'label' => 'SEO title (60 chars max)',    'type' => 'text', 'maxlength' => 60],
                        ['name' => 'seo_description', 'label' => 'Meta description (160 max)', 'type' => 'textarea', 'maxlength' => 160],
                        ['name' => 'og_image',        'label' => 'Open Graph image',           'type' => 'image', 'return_format' => 'array'],
                        ['name' => 'canonical_url',   'label' => 'Canonical URL',              'type' => 'url'],
                        ['name' => 'noindex',         'label' => 'Noindex this page',          'type' => 'true_false'],
                    ],
                    'location' => [
                        [['param' => 'post_type', 'operator' => '==', 'value' => 'post']],
                        [['param' => 'post_type', 'operator' => '==', 'value' => 'page']],
                    ],
                    'position' => 'side',
                ],
            ],
        ],
        [
            'id' => 'acf:product-specs',
            'category' => 'acf',
            'label' => 'Product specifications',
            'description' => 'SKU / weight / dimensions / materials / warranty. Attaches to WooCommerce product.',
            'ability' => 'nibwp/acf-manage-fields',
            'parameters' => [
                'action' => 'create_group',
                'group_data' => [
                    'title' => 'Product specs',
                    'fields' => [
                        ['name' => 'product_sku',    'label' => 'SKU',         'type' => 'text'],
                        ['name' => 'product_weight', 'label' => 'Weight (kg)', 'type' => 'number', 'step' => 0.01],
                        ['name' => 'product_dimensions', 'label' => 'Dimensions (L×W×H cm)', 'type' => 'text'],
                        ['name' => 'product_materials',  'label' => 'Materials', 'type' => 'textarea'],
                        ['name' => 'product_warranty',   'label' => 'Warranty period (months)', 'type' => 'number'],
                    ],
                    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'product']]],
                ],
            ],
        ],
        [
            'id' => 'acf:faq',
            'category' => 'acf',
            'label' => 'FAQ accordion (repeater)',
            'description' => 'Repeater group of question / answer pairs for FAQ pages.',
            'ability' => 'nibwp/acf-manage-fields',
            'parameters' => [
                'action' => 'create_group',
                'group_data' => [
                    'title' => 'FAQ',
                    'fields' => [[
                        'name'  => 'faq_items',
                        'label' => 'FAQ items',
                        'type'  => 'repeater',
                        'sub_fields' => [
                            ['name' => 'question', 'label' => 'Question', 'type' => 'text', 'required' => true],
                            ['name' => 'answer',   'label' => 'Answer',   'type' => 'wysiwyg', 'required' => true],
                        ],
                    ]],
                    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'page']]],
                ],
            ],
        ],
        [
            'id' => 'acf:team-member',
            'category' => 'acf',
            'label' => 'Team member profile',
            'description' => 'Photo / role / bio / socials for an About page.',
            'ability' => 'nibwp/acf-manage-fields',
            'parameters' => [
                'action' => 'create_group',
                'group_data' => [
                    'title' => 'Team member',
                    'fields' => [
                        ['name' => 'tm_photo', 'label' => 'Photo',    'type' => 'image', 'return_format' => 'array'],
                        ['name' => 'tm_role',  'label' => 'Role',     'type' => 'text'],
                        ['name' => 'tm_bio',   'label' => 'Bio',      'type' => 'wysiwyg'],
                        ['name' => 'tm_linkedin', 'label' => 'LinkedIn URL', 'type' => 'url'],
                        ['name' => 'tm_twitter',  'label' => 'X / Twitter',  'type' => 'url'],
                    ],
                    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'page']]],
                ],
            ],
        ],
        [
            'id' => 'acf:testimonial',
            'category' => 'acf',
            'label' => 'Testimonial',
            'description' => 'Quote / author / role / company / rating.',
            'ability' => 'nibwp/acf-manage-fields',
            'parameters' => [
                'action' => 'create_group',
                'group_data' => [
                    'title' => 'Testimonial',
                    'fields' => [
                        ['name' => 't_quote',   'label' => 'Quote',   'type' => 'textarea', 'required' => true],
                        ['name' => 't_author',  'label' => 'Author',  'type' => 'text',     'required' => true],
                        ['name' => 't_role',    'label' => 'Role',    'type' => 'text'],
                        ['name' => 't_company', 'label' => 'Company', 'type' => 'text'],
                        ['name' => 't_rating',  'label' => 'Rating (1-5)', 'type' => 'number', 'min' => 1, 'max' => 5],
                    ],
                    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'testimonial']]],
                ],
            ],
        ],
        [
            'id' => 'acf:pricing-tier',
            'category' => 'acf',
            'label' => 'Pricing tier',
            'description' => 'Name / price / period / features list / CTA.',
            'ability' => 'nibwp/acf-manage-fields',
            'parameters' => [
                'action' => 'create_group',
                'group_data' => [
                    'title' => 'Pricing tier',
                    'fields' => [
                        ['name' => 'p_name',     'label' => 'Tier name', 'type' => 'text'],
                        ['name' => 'p_price',    'label' => 'Price',     'type' => 'number'],
                        ['name' => 'p_period',   'label' => 'Period',    'type' => 'select', 'choices' => ['mo' => '/mo', 'yr' => '/yr', 'one-time' => 'one-time']],
                        ['name' => 'p_features', 'label' => 'Features',  'type' => 'repeater', 'sub_fields' => [['name' => 'feature', 'label' => 'Feature', 'type' => 'text']]],
                        ['name' => 'p_cta_label','label' => 'CTA label', 'type' => 'text'],
                        ['name' => 'p_cta_url',  'label' => 'CTA URL',   'type' => 'url'],
                        ['name' => 'p_featured', 'label' => 'Featured?', 'type' => 'true_false'],
                    ],
                    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'pricing-tier']]],
                ],
            ],
        ],
        [
            'id' => 'acf:event',
            'category' => 'acf',
            'label' => 'Event',
            'description' => 'Date / time / venue / capacity / price.',
            'ability' => 'nibwp/acf-manage-fields',
            'parameters' => [
                'action' => 'create_group',
                'group_data' => [
                    'title' => 'Event',
                    'fields' => [
                        ['name' => 'e_starts_at', 'label' => 'Starts at', 'type' => 'date_time_picker'],
                        ['name' => 'e_ends_at',   'label' => 'Ends at',   'type' => 'date_time_picker'],
                        ['name' => 'e_venue',     'label' => 'Venue',     'type' => 'text'],
                        ['name' => 'e_address',   'label' => 'Address',   'type' => 'textarea'],
                        ['name' => 'e_capacity',  'label' => 'Capacity',  'type' => 'number'],
                        ['name' => 'e_price',     'label' => 'Price',     'type' => 'number'],
                    ],
                    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'event']]],
                ],
            ],
        ],

        // ─── Posts / Pages ─────────────────────────────────────────────
        [
            'id' => 'post:blog-listicle',
            'category' => 'post',
            'label' => 'Blog post — listicle skeleton',
            'description' => 'Draft post with intro + 5 numbered headings.',
            'ability' => 'nibwp/wp-create-post',
            'parameters' => [
                'post_type' => 'post',
                'status'    => 'draft',
                'title'     => '10 ways to [topic]',
                'content'   => "Intro paragraph.\n\n## 1. [point one]\n\nBody.\n\n## 2. [point two]\n\nBody.\n\n## 3. [point three]\n\nBody.\n\n## 4. [point four]\n\nBody.\n\n## 5. [point five]\n\nBody.\n\n## Conclusion\n\nWrap-up.",
            ],
        ],
        [
            'id' => 'page:landing-saas',
            'category' => 'page',
            'label' => 'Landing page — SaaS skeleton',
            'description' => 'Page draft with hero / features / pricing / FAQ / CTA placeholder sections.',
            'ability' => 'nibwp/wp-create-post',
            'parameters' => [
                'post_type' => 'page',
                'status'    => 'draft',
                'title'     => 'Get started',
                'content'   => "<!-- wp:html --><section><h1>Hero headline</h1><p>Subheadline.</p><a href=\"#\">CTA</a></section><!-- /wp:html -->\n\n<!-- wp:html --><section><h2>Features</h2><div class=\"grid\">3 feature cards</div></section><!-- /wp:html -->\n\n<!-- wp:html --><section><h2>Pricing</h2><div class=\"grid\">3 tiers</div></section><!-- /wp:html -->\n\n<!-- wp:html --><section><h2>FAQ</h2></section><!-- /wp:html -->\n\n<!-- wp:html --><section><h2>Ready?</h2><a href=\"#\">CTA</a></section><!-- /wp:html -->",
            ],
        ],
        [
            'id' => 'page:about',
            'category' => 'page',
            'label' => 'About page — skeleton',
            'description' => 'Story / mission / team / contact CTA.',
            'ability' => 'nibwp/wp-create-post',
            'parameters' => [
                'post_type' => 'page',
                'status'    => 'draft',
                'title'     => 'About',
                'content'   => "## Our story\n\n[history]\n\n## Mission\n\n[mission]\n\n## Team\n\n[team grid]\n\n## Get in touch\n\n[contact CTA]",
            ],
        ],

        // ─── CRM ───────────────────────────────────────────────────────
        [
            'id' => 'crm:welcome-tag',
            'category' => 'crm',
            'label' => 'CRM — Welcome tag',
            'description' => 'Create a "welcome-sequence" tag for new subscribers.',
            'ability' => 'nibwp/fluentcrm-manage',
            'parameters' => [
                'action' => 'create_tag',
                'tag'    => ['title' => 'Welcome sequence', 'slug' => 'welcome-sequence'],
            ],
        ],
        [
            'id' => 'crm:customer-list',
            'category' => 'crm',
            'label' => 'CRM — Customers list',
            'description' => 'Create a "Customers" list for active buyers.',
            'ability' => 'nibwp/fluentcrm-manage',
            'parameters' => [
                'action' => 'create_list',
                'list'   => ['title' => 'Customers', 'slug' => 'customers'],
            ],
        ],

        // ─── Menu / nav ────────────────────────────────────────────────
        [
            'id' => 'menu:primary',
            'category' => 'menu',
            'label' => 'Primary menu (Home / Services / About / Blog / Contact)',
            'description' => 'Standard 5-item primary navigation.',
            'ability' => 'nibwp/wp-create-menu',
            'parameters' => ['name' => 'Primary'],
        ],
    ];
}

function nibwp_templates_list(array $input): array
{
    $cat = isset($input['category']) ? sanitize_key((string) $input['category']) : '';
    $q   = isset($input['q']) ? strtolower((string) $input['q']) : '';
    $out = [];
    foreach (nibwp_templates_definitions() as $t) {
        if ($cat !== '' && (string) $t['category'] !== $cat) {
            continue;
        }
        if ($q !== '' && stripos($t['label'] . ' ' . $t['description'] . ' ' . $t['id'], $q) === false) {
            continue;
        }
        $out[] = [
            'id'          => $t['id'],
            'category'    => $t['category'],
            'label'       => $t['label'],
            'description' => $t['description'],
            'ability'     => $t['ability'],
        ];
    }
    return ['templates' => $out];
}

function nibwp_templates_apply(array $input): array|WP_Error
{
    $id = (string) ($input['template_id'] ?? '');
    if ($id === '') {
        return new WP_Error('missing_id', 'template_id required.');
    }
    $tpl = null;
    foreach (nibwp_templates_definitions() as $t) {
        if ($t['id'] === $id) {
            $tpl = $t;
            break;
        }
    }
    if (!$tpl) {
        return new WP_Error('not_found', sprintf('Template "%s" not found.', $id));
    }
    $params = (array) ($tpl['parameters'] ?? []);
    $overrides = (array) ($input['overrides'] ?? []);
    $params = nibwp_array_deep_merge($params, $overrides);

    $ability = nibwp_has_ability((string) $tpl['ability']) ? wp_get_ability((string) $tpl['ability']) : null;
    if ($ability === null) {
        return new WP_Error('target_missing', sprintf('Target ability "%s" not available — install the host plugin first.', $tpl['ability']));
    }
    $result = $ability->execute($params);
    if (is_wp_error($result)) {
        return $result;
    }
    return [
        'applied' => true,
        'ability' => (string) $tpl['ability'],
        'result'  => is_array($result) ? $result : ['value' => $result],
    ];
}

function nibwp_array_deep_merge(array $base, array $overlay): array
{
    foreach ($overlay as $k => $v) {
        if (is_array($v) && isset($base[$k]) && is_array($base[$k])) {
            $base[$k] = nibwp_array_deep_merge($base[$k], $v);
        } else {
            $base[$k] = $v;
        }
    }
    return $base;
}
