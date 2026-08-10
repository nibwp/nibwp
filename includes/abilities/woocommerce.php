<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

// --- Ability: nibwp/wc-list-products ---

wp_register_ability('nibwp/wc-list-products', [
    'label' => __('WooCommerce – List Products', domain: 'nibwp'),
    'description' => __(
        'List WooCommerce products with comprehensive filters including status, type, category, tag, price range, search, and pagination.',
        domain: 'nibwp',
    ),
    'category' => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'status' => [
                'type' => 'string',
                'description' => 'Product status: publish, draft, pending, private, trash.',
                'default' => 'publish',
            ],
            'type' => [
                'type' => 'string',
                'enum' => ['simple', 'variable', 'grouped', 'external'],
                'description' => 'Product type filter.',
            ],
            'category' => [
                'type' => 'string',
                'description' => 'Category slug to filter by.',
            ],
            'tag' => [
                'type' => 'string',
                'description' => 'Tag slug to filter by.',
            ],
            'per_page' => [
                'type' => 'integer',
                'description' => 'Number of products per page.',
                'default' => 20,
            ],
            'page' => [
                'type' => 'integer',
                'description' => 'Page number.',
                'default' => 1,
            ],
            'search' => [
                'type' => 'string',
                'description' => 'Search term to filter products.',
            ],
            'orderby' => [
                'type' => 'string',
                'enum' => ['date', 'title', 'price', 'popularity', 'rating', 'id', 'menu_order'],
                'default' => 'date',
            ],
            'order' => [
                'type' => 'string',
                'enum' => ['ASC', 'DESC'],
                'default' => 'DESC',
            ],
            'featured' => [
                'type' => 'boolean',
                'description' => 'Filter by featured status.',
            ],
            'on_sale' => [
                'type' => 'boolean',
                'description' => 'Filter to only on-sale products.',
            ],
            'min_price' => [
                'type' => 'number',
                'description' => 'Minimum price filter.',
            ],
            'max_price' => [
                'type' => 'number',
                'description' => 'Maximum price filter.',
            ],
            'sku' => [
                'type' => 'string',
                'description' => 'Filter by exact SKU.',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'products' => ['type' => 'array', 'description' => 'Array of product summaries.'],
            'total' => ['type' => 'integer'],
            'pages' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => 'nibwp_wc_list_products',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'List WooCommerce products with filters and pagination.',
                '',
                'FILTERS:',
                '- status: publish (default), draft, pending, private, trash.',
                '- type: simple, variable, grouped, external.',
                '- category/tag: filter by slug.',
                '- search: keyword search across product name and content.',
                '- featured/on_sale: boolean filters.',
                '- min_price/max_price: price range filter.',
                '- sku: exact SKU match.',
                '',
                'PAGINATION:',
                '- per_page (default 20, max 100) and page (default 1).',
                '- Response includes total count and total pages.',
                '',
                'SORTING:',
                '- orderby: date, title, price, popularity, rating, id, menu_order.',
                '- order: ASC or DESC.',
                '',
                'REQUIRES: WooCommerce plugin must be active.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_wc_list_products(array $input): array|WP_Error
{
    if (!class_exists('WooCommerce')) {
        return new WP_Error('wc_not_active', 'WooCommerce is not active.');
    }

    $per_page = min(max((int) ($input['per_page'] ?? 20), 1), 100);
    $page = max((int) ($input['page'] ?? 1), 1);

    $args = [
        'status' => $input['status'] ?? 'publish',
        'limit' => $per_page,
        'page' => $page,
        'orderby' => $input['orderby'] ?? 'date',
        'order' => $input['order'] ?? 'DESC',
        'paginate' => true,
    ];

    if (!empty($input['type'])) {
        $args['type'] = (string) $input['type'];
    }
    if (!empty($input['category'])) {
        $args['category'] = [(string) $input['category']];
    }
    if (!empty($input['tag'])) {
        $args['tag'] = [(string) $input['tag']];
    }
    if (!empty($input['search'])) {
        $args['s'] = (string) $input['search'];
    }
    if (isset($input['featured'])) {
        $args['featured'] = (bool) $input['featured'];
    }
    if (!empty($input['on_sale'])) {
        $args['include'] = wc_get_product_ids_on_sale();
        if (empty($args['include'])) {
            return ['products' => [], 'total' => 0, 'pages' => 0];
        }
    }
    if (!empty($input['sku'])) {
        $args['sku'] = (string) $input['sku'];
    }

    $results = wc_get_products($args);

    $products = [];
    foreach ($results->products as $product) {
        /** @var WC_Product $product */
        $price = (float) $product->get_price();
        if (isset($input['min_price']) && $price < (float) $input['min_price']) {
            continue;
        }
        if (isset($input['max_price']) && $price > (float) $input['max_price']) {
            continue;
        }

        $categories = [];
        foreach ($product->get_category_ids() as $cat_id) {
            $term = get_term($cat_id, 'product_cat');
            if ($term && !is_wp_error($term)) {
                $categories[] = ['id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug];
            }
        }

        $images = [];
        $image_id = $product->get_image_id();
        if ($image_id) {
            $images[] = ['id' => $image_id, 'src' => wp_get_attachment_url($image_id)];
        }
        foreach ($product->get_gallery_image_ids() as $gid) {
            $images[] = ['id' => $gid, 'src' => wp_get_attachment_url($gid)];
        }

        $products[] = [
            'id' => $product->get_id(),
            'name' => $product->get_name(),
            'slug' => $product->get_slug(),
            'type' => $product->get_type(),
            'status' => $product->get_status(),
            'price' => $product->get_price(),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'sku' => $product->get_sku(),
            'stock_status' => $product->get_stock_status(),
            'stock_quantity' => $product->get_stock_quantity(),
            'categories' => $categories,
            'images' => $images,
            'url' => get_permalink($product->get_id()),
        ];
    }

    return [
        'products' => $products,
        'total' => (int) $results->total,
        'pages' => (int) $results->max_num_pages,
    ];
}

// --- Ability: nibwp/wc-get-product ---

wp_register_ability('nibwp/wc-get-product', [
    'label' => __('WooCommerce – Get Product', domain: 'nibwp'),
    'description' => __(
        'Get full details of a single WooCommerce product including description, attributes, variations, images, and all metadata.',
        domain: 'nibwp',
    ),
    'category' => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'product_id' => [
                'type' => 'integer',
                'description' => 'The product ID.',
            ],
        ],
        'required' => ['product_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'product' => ['type' => 'object', 'description' => 'Full product data.'],
        ],
    ],
    'execute_callback' => 'nibwp_wc_get_product',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Retrieve full details for a single WooCommerce product.',
                '',
                'Returns comprehensive data including:',
                '- Basic info: id, name, slug, type, status, permalink.',
                '- Content: description, short_description.',
                '- Pricing: price, regular_price, sale_price, date_on_sale_from/to.',
                '- Inventory: sku, manage_stock, stock_quantity, stock_status, backorders.',
                '- Shipping: weight, dimensions (length, width, height), shipping_class.',
                '- Taxonomy: categories, tags.',
                '- Media: images (id, src, alt) including featured and gallery.',
                '- Attributes: name, options, visible, variation.',
                '- Variations: full array for variable products with price/stock/attributes.',
                '- Related: upsell_ids, cross_sell_ids.',
                '- Meta: all custom meta_data key-value pairs.',
                '',
                'REQUIRES: WooCommerce plugin must be active.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_wc_get_product(array $input): array|WP_Error
{
    if (!class_exists('WooCommerce')) {
        return new WP_Error('wc_not_active', 'WooCommerce is not active.');
    }

    $product_id = (int) ($input['product_id'] ?? 0);
    if ($product_id <= 0) {
        return new WP_Error('invalid_product_id', 'product_id must be a positive integer.');
    }

    $product = wc_get_product($product_id);
    if (!$product) {
        return new WP_Error('product_not_found', sprintf('Product %d not found.', $product_id));
    }

    $categories = [];
    foreach ($product->get_category_ids() as $cat_id) {
        $term = get_term($cat_id, 'product_cat');
        if ($term && !is_wp_error($term)) {
            $categories[] = ['id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug];
        }
    }

    $tags = [];
    foreach ($product->get_tag_ids() as $tag_id) {
        $term = get_term($tag_id, 'product_tag');
        if ($term && !is_wp_error($term)) {
            $tags[] = ['id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug];
        }
    }

    $images = [];
    $image_id = $product->get_image_id();
    if ($image_id) {
        $images[] = [
            'id' => (int) $image_id,
            'src' => wp_get_attachment_url($image_id),
            'alt' => get_post_meta($image_id, '_wp_attachment_image_alt', true),
        ];
    }
    foreach ($product->get_gallery_image_ids() as $gid) {
        $images[] = [
            'id' => (int) $gid,
            'src' => wp_get_attachment_url($gid),
            'alt' => get_post_meta($gid, '_wp_attachment_image_alt', true),
        ];
    }

    $attributes = [];
    foreach ($product->get_attributes() as $attr) {
        if ($attr instanceof WC_Product_Attribute) {
            $attributes[] = [
                'name' => $attr->get_name(),
                'options' => $attr->get_options(),
                'visible' => $attr->get_visible(),
                'variation' => $attr->get_variation(),
            ];
        }
    }

    $variations = [];
    if ($product->is_type('variable')) {
        /** @var WC_Product_Variable $product */
        foreach ($product->get_children() as $var_id) {
            $variation = wc_get_product($var_id);
            if (!$variation) {
                continue;
            }
            $variations[] = [
                'id' => $variation->get_id(),
                'sku' => $variation->get_sku(),
                'price' => $variation->get_price(),
                'regular_price' => $variation->get_regular_price(),
                'sale_price' => $variation->get_sale_price(),
                'stock_status' => $variation->get_stock_status(),
                'stock_quantity' => $variation->get_stock_quantity(),
                'attributes' => $variation->get_attributes(),
                'image' => $variation->get_image_id() ? [
                    'id' => (int) $variation->get_image_id(),
                    'src' => wp_get_attachment_url($variation->get_image_id()),
                ] : null,
            ];
        }
    }

    $meta_data = [];
    foreach ($product->get_meta_data() as $meta) {
        $meta_data[] = [
            'key' => $meta->key,
            'value' => $meta->value,
        ];
    }

    $data = [
        'id' => $product->get_id(),
        'name' => $product->get_name(),
        'slug' => $product->get_slug(),
        'type' => $product->get_type(),
        'status' => $product->get_status(),
        'permalink' => get_permalink($product->get_id()),
        'description' => $product->get_description(),
        'short_description' => $product->get_short_description(),
        'sku' => $product->get_sku(),
        'price' => $product->get_price(),
        'regular_price' => $product->get_regular_price(),
        'sale_price' => $product->get_sale_price(),
        'date_on_sale_from' => $product->get_date_on_sale_from() ? $product->get_date_on_sale_from()->date('Y-m-d H:i:s') : null,
        'date_on_sale_to' => $product->get_date_on_sale_to() ? $product->get_date_on_sale_to()->date('Y-m-d H:i:s') : null,
        'manage_stock' => $product->get_manage_stock(),
        'stock_quantity' => $product->get_stock_quantity(),
        'stock_status' => $product->get_stock_status(),
        'backorders' => $product->get_backorders(),
        'weight' => $product->get_weight(),
        'dimensions' => [
            'length' => $product->get_length(),
            'width' => $product->get_width(),
            'height' => $product->get_height(),
        ],
        'shipping_class' => $product->get_shipping_class(),
        'categories' => $categories,
        'tags' => $tags,
        'images' => $images,
        'attributes' => $attributes,
        'variations' => $variations,
        'upsell_ids' => $product->get_upsell_ids(),
        'cross_sell_ids' => $product->get_cross_sell_ids(),
        'meta_data' => $meta_data,
    ];

    return ['product' => $data];
}

// --- Ability: nibwp/wc-create-product ---

wp_register_ability('nibwp/wc-create-product', [
    'label' => __('WooCommerce – Create Product', domain: 'nibwp'),
    'description' => __(
        'Create a new WooCommerce product with all properties: pricing, inventory, categories, images, attributes, and more.',
        domain: 'nibwp',
    ),
    'category' => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string', 'description' => 'Product name.'],
            'type' => [
                'type' => 'string',
                'enum' => ['simple', 'variable', 'grouped', 'external'],
                'default' => 'simple',
            ],
            'status' => [
                'type' => 'string',
                'enum' => ['draft', 'publish', 'pending', 'private'],
                'default' => 'draft',
            ],
            'regular_price' => ['type' => 'string', 'description' => 'Regular price.'],
            'sale_price' => ['type' => 'string', 'description' => 'Sale price.'],
            'description' => ['type' => 'string'],
            'short_description' => ['type' => 'string'],
            'sku' => ['type' => 'string'],
            'manage_stock' => ['type' => 'boolean', 'default' => false],
            'stock_quantity' => ['type' => 'integer'],
            'categories' => [
                'type' => 'array',
                'description' => 'Array of category objects with id or name.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'name' => ['type' => 'string'],
                    ],
                ],
            ],
            'images' => [
                'type' => 'array',
                'description' => 'Array of image objects with src URL.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'src' => ['type' => 'string'],
                    ],
                ],
            ],
            'attributes' => [
                'type' => 'array',
                'description' => 'Array of attribute objects.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'options' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'visible' => ['type' => 'boolean', 'default' => true],
                        'variation' => ['type' => 'boolean', 'default' => false],
                    ],
                ],
            ],
            'weight' => ['type' => 'string'],
            'dimensions' => [
                'type' => 'object',
                'properties' => [
                    'length' => ['type' => 'string'],
                    'width' => ['type' => 'string'],
                    'height' => ['type' => 'string'],
                ],
            ],
        ],
        'required' => ['name'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            'permalink' => ['type' => 'string'],
            'edit_url' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => 'nibwp_wc_create_product',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Create a new WooCommerce product.',
                '',
                'REQUIRED: name.',
                'DEFAULTS: type=simple, status=draft.',
                '',
                'CATEGORIES:',
                '- Pass {id: 5} to use an existing category by ID.',
                '- Pass {name: "My Category"} to find or create by name.',
                '',
                'IMAGES:',
                '- Pass {src: "https://..."} to sideload images from URLs.',
                '- The first image becomes the featured image, rest go to the gallery.',
                '- Image sideloading may take time for large files.',
                '',
                'ATTRIBUTES:',
                '- Each attribute: {name, options: ["Option1", "Option2"], visible: true, variation: false}.',
                '- Set variation: true for attributes used in product variations.',
                '',
                'STOCK:',
                '- Set manage_stock: true and stock_quantity to enable stock management.',
                '',
                'REQUIRES: WooCommerce plugin must be active.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_wc_create_product(array $input): array|WP_Error
{
    if (!class_exists('WooCommerce')) {
        return new WP_Error('wc_not_active', 'WooCommerce is not active.');
    }

    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '') {
        return new WP_Error('missing_name', 'Product name is required.');
    }

    $type = $input['type'] ?? 'simple';
    $product = nibwp_wc_create_product_instance($type);
    if (is_wp_error($product)) {
        return $product;
    }

    $product->set_name($name);
    $product->set_status($input['status'] ?? 'draft');

    if (isset($input['regular_price'])) {
        $product->set_regular_price((string) $input['regular_price']);
    }
    if (isset($input['sale_price'])) {
        $product->set_sale_price((string) $input['sale_price']);
    }
    if (isset($input['description'])) {
        $product->set_description((string) $input['description']);
    }
    if (isset($input['short_description'])) {
        $product->set_short_description((string) $input['short_description']);
    }
    if (isset($input['sku'])) {
        $product->set_sku((string) $input['sku']);
    }
    if (isset($input['manage_stock'])) {
        $product->set_manage_stock((bool) $input['manage_stock']);
    }
    if (isset($input['stock_quantity'])) {
        $product->set_stock_quantity((int) $input['stock_quantity']);
    }
    if (isset($input['weight'])) {
        $product->set_weight((string) $input['weight']);
    }
    if (isset($input['dimensions'])) {
        $dims = (array) $input['dimensions'];
        if (isset($dims['length'])) $product->set_length((string) $dims['length']);
        if (isset($dims['width'])) $product->set_width((string) $dims['width']);
        if (isset($dims['height'])) $product->set_height((string) $dims['height']);
    }

    // Categories.
    if (!empty($input['categories'])) {
        $cat_ids = nibwp_wc_resolve_category_ids((array) $input['categories']);
        if (is_wp_error($cat_ids)) {
            return $cat_ids;
        }
        $product->set_category_ids($cat_ids);
    }

    // Attributes.
    if (!empty($input['attributes'])) {
        $attrs = nibwp_wc_build_attributes((array) $input['attributes']);
        $product->set_attributes($attrs);
    }

    // Save first to get an ID for image attachment.
    $product_id = $product->save();
    if (!$product_id) {
        return new WP_Error('save_failed', 'Failed to save the product.');
    }

    // Images.
    if (!empty($input['images'])) {
        $image_result = nibwp_wc_sideload_images((array) $input['images'], $product_id);
        if (is_wp_error($image_result)) {
            return $image_result;
        }
        if (!empty($image_result)) {
            $product->set_image_id($image_result[0]);
            if (count($image_result) > 1) {
                $product->set_gallery_image_ids(array_slice($image_result, 1));
            }
            $product->save();
        }
    }

    return [
        'id' => $product_id,
        'name' => $product->get_name(),
        'permalink' => get_permalink($product_id),
        'edit_url' => admin_url('post.php?post=' . $product_id . '&action=edit'),
    ];
}

/**
 * Create the correct WC_Product subclass by type.
 */
function nibwp_wc_create_product_instance(string $type): WC_Product|WP_Error
{
    return match ($type) {
        'simple' => new WC_Product_Simple(),
        'variable' => new WC_Product_Variable(),
        'grouped' => new WC_Product_Grouped(),
        'external' => new WC_Product_External(),
        default => new WP_Error('invalid_type', sprintf('Invalid product type: %s', $type)),
    };
}

/**
 * Resolve category input (id or name) to an array of term IDs.
 */
function nibwp_wc_resolve_category_ids(array $categories): array|WP_Error
{
    $ids = [];
    foreach ($categories as $cat) {
        if (!empty($cat['id'])) {
            $term = get_term((int) $cat['id'], 'product_cat');
            if (!$term || is_wp_error($term)) {
                return new WP_Error('category_not_found', sprintf('Category ID %d not found.', $cat['id']));
            }
            $ids[] = $term->term_id;
        } elseif (!empty($cat['name'])) {
            $term = get_term_by('name', (string) $cat['name'], 'product_cat');
            if (!$term) {
                $result = wp_insert_term((string) $cat['name'], 'product_cat');
                if (is_wp_error($result)) {
                    return $result;
                }
                $ids[] = (int) $result['term_id'];
            } else {
                $ids[] = $term->term_id;
            }
        }
    }
    return $ids;
}

/**
 * Build WC_Product_Attribute array from input.
 */
function nibwp_wc_build_attributes(array $attributes): array
{
    $wc_attrs = [];
    $position = 0;
    foreach ($attributes as $attr_data) {
        if (empty($attr_data['name']) || empty($attr_data['options'])) {
            continue;
        }
        $attribute = new WC_Product_Attribute();
        $attribute->set_name((string) $attr_data['name']);
        $attribute->set_options((array) $attr_data['options']);
        $attribute->set_visible((bool) ($attr_data['visible'] ?? true));
        $attribute->set_variation((bool) ($attr_data['variation'] ?? false));
        $attribute->set_position($position++);
        $wc_attrs[] = $attribute;
    }
    return $wc_attrs;
}

/**
 * Sideload images from URLs and return attachment IDs.
 */
function nibwp_wc_sideload_images(array $images, int $product_id): array|WP_Error
{
    if (!function_exists('media_sideload_image')) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    $attachment_ids = [];
    foreach ($images as $image) {
        $src = (string) ($image['src'] ?? '');
        if ($src === '') {
            continue;
        }

        // If it's already an attachment ID.
        if (!empty($image['id'])) {
            $attachment_ids[] = (int) $image['id'];
            continue;
        }

        $attachment_id = media_sideload_image($src, $product_id, '', 'id');
        if (is_wp_error($attachment_id)) {
            return new WP_Error(
                'image_sideload_failed',
                sprintf('Failed to sideload image %s: %s', $src, $attachment_id->get_error_message()),
            );
        }
        $attachment_ids[] = (int) $attachment_id;
    }
    return $attachment_ids;
}

// --- Ability: nibwp/wc-update-product ---

wp_register_ability('nibwp/wc-update-product', [
    'label' => __('WooCommerce – Update Product', domain: 'nibwp'),
    'description' => __(
        'Update an existing WooCommerce product. Only the provided fields will be changed; all others remain untouched.',
        domain: 'nibwp',
    ),
    'category' => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'product_id' => ['type' => 'integer', 'description' => 'The product ID to update.'],
            'name' => ['type' => 'string'],
            'status' => ['type' => 'string', 'enum' => ['draft', 'publish', 'pending', 'private']],
            'regular_price' => ['type' => 'string'],
            'sale_price' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'short_description' => ['type' => 'string'],
            'sku' => ['type' => 'string'],
            'manage_stock' => ['type' => 'boolean'],
            'stock_quantity' => ['type' => 'integer'],
            'categories' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'name' => ['type' => 'string'],
                    ],
                ],
            ],
            'images' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'src' => ['type' => 'string'],
                        'id' => ['type' => 'integer'],
                    ],
                ],
            ],
            'attributes' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'options' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'visible' => ['type' => 'boolean'],
                        'variation' => ['type' => 'boolean'],
                    ],
                ],
            ],
            'weight' => ['type' => 'string'],
            'dimensions' => [
                'type' => 'object',
                'properties' => [
                    'length' => ['type' => 'string'],
                    'width' => ['type' => 'string'],
                    'height' => ['type' => 'string'],
                ],
            ],
        ],
        'required' => ['product_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            'updated_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ],
    'execute_callback' => 'nibwp_wc_update_product',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Update an existing WooCommerce product.',
                '',
                'Only fields you include will be updated; omitted fields stay unchanged.',
                '',
                'REQUIRED: product_id.',
                '',
                'CATEGORIES: Same format as create — {id} or {name}.',
                'IMAGES: Providing images replaces the current images.',
                'ATTRIBUTES: Providing attributes replaces all current attributes.',
                '',
                'TIP: Use wc-get-product first to see the current state before updating.',
                '',
                'REQUIRES: WooCommerce plugin must be active.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_wc_update_product(array $input): array|WP_Error
{
    if (!class_exists('WooCommerce')) {
        return new WP_Error('wc_not_active', 'WooCommerce is not active.');
    }

    $product_id = (int) ($input['product_id'] ?? 0);
    if ($product_id <= 0) {
        return new WP_Error('invalid_product_id', 'product_id must be a positive integer.');
    }

    $product = wc_get_product($product_id);
    if (!$product) {
        return new WP_Error('product_not_found', sprintf('Product %d not found.', $product_id));
    }

    $updated_fields = [];

    $simple_setters = [
        'name' => 'set_name',
        'status' => 'set_status',
        'regular_price' => 'set_regular_price',
        'sale_price' => 'set_sale_price',
        'description' => 'set_description',
        'short_description' => 'set_short_description',
        'sku' => 'set_sku',
        'manage_stock' => 'set_manage_stock',
        'stock_quantity' => 'set_stock_quantity',
        'weight' => 'set_weight',
    ];

    foreach ($simple_setters as $field => $setter) {
        if (array_key_exists($field, $input)) {
            $product->$setter($input[$field]);
            $updated_fields[] = $field;
        }
    }

    if (isset($input['dimensions'])) {
        $dims = (array) $input['dimensions'];
        if (isset($dims['length'])) { $product->set_length((string) $dims['length']); }
        if (isset($dims['width'])) { $product->set_width((string) $dims['width']); }
        if (isset($dims['height'])) { $product->set_height((string) $dims['height']); }
        $updated_fields[] = 'dimensions';
    }

    if (isset($input['categories'])) {
        $cat_ids = nibwp_wc_resolve_category_ids((array) $input['categories']);
        if (is_wp_error($cat_ids)) {
            return $cat_ids;
        }
        $product->set_category_ids($cat_ids);
        $updated_fields[] = 'categories';
    }

    if (isset($input['attributes'])) {
        $attrs = nibwp_wc_build_attributes((array) $input['attributes']);
        $product->set_attributes($attrs);
        $updated_fields[] = 'attributes';
    }

    if (isset($input['images'])) {
        $image_result = nibwp_wc_sideload_images((array) $input['images'], $product_id);
        if (is_wp_error($image_result)) {
            return $image_result;
        }
        if (!empty($image_result)) {
            $product->set_image_id($image_result[0]);
            $product->set_gallery_image_ids(array_slice($image_result, 1));
        } else {
            $product->set_image_id(0);
            $product->set_gallery_image_ids([]);
        }
        $updated_fields[] = 'images';
    }

    $product->save();

    return [
        'id' => $product->get_id(),
        'name' => $product->get_name(),
        'updated_fields' => $updated_fields,
    ];
}

// --- Ability: nibwp/wc-delete-product ---

wp_register_ability('nibwp/wc-delete-product', [
    'label' => __('WooCommerce – Delete Product', domain: 'nibwp'),
    'description' => __(
        'Delete or trash a WooCommerce product. By default moves to trash; use force=true for permanent deletion.',
        domain: 'nibwp',
    ),
    'category' => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'product_id' => ['type' => 'integer', 'description' => 'The product ID to delete.'],
            'force' => [
                'type' => 'boolean',
                'description' => 'If true, permanently delete. If false (default), move to trash.',
                'default' => false,
            ],
        ],
        'required' => ['product_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
            'deleted' => ['type' => 'boolean'],
            'trashed' => ['type' => 'boolean'],
        ],
    ],
    'execute_callback' => 'nibwp_wc_delete_product',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Delete or trash a WooCommerce product.',
                '',
                'By default (force=false), the product is moved to trash and can be restored.',
                'With force=true, the product is permanently and irreversibly deleted.',
                '',
                'REQUIRES: WooCommerce plugin must be active.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_wc_delete_product(array $input): array|WP_Error
{
    if (!class_exists('WooCommerce')) {
        return new WP_Error('wc_not_active', 'WooCommerce is not active.');
    }

    $product_id = (int) ($input['product_id'] ?? 0);
    if ($product_id <= 0) {
        return new WP_Error('invalid_product_id', 'product_id must be a positive integer.');
    }

    $product = wc_get_product($product_id);
    if (!$product) {
        return new WP_Error('product_not_found', sprintf('Product %d not found.', $product_id));
    }

    $force = (bool) ($input['force'] ?? false);
    $product->delete($force);

    return [
        'id' => $product_id,
        'deleted' => $force,
        'trashed' => !$force,
    ];
}

// --- Ability: nibwp/wc-list-orders ---

wp_register_ability('nibwp/wc-list-orders', [
    'label' => __('WooCommerce – List Orders', domain: 'nibwp'),
    'description' => __(
        'List WooCommerce orders with filters for status, customer, date range, and pagination.',
        domain: 'nibwp',
    ),
    'category' => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'status' => [
                'description' => 'Order status or array of statuses (e.g. "processing", "completed", "on-hold").',
                'oneOf' => [
                    ['type' => 'string'],
                    ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page' => ['type' => 'integer', 'default' => 1],
            'customer_id' => ['type' => 'integer', 'description' => 'Filter by customer user ID.'],
            'date_after' => ['type' => 'string', 'description' => 'Orders created after this date (Y-m-d or ISO 8601).'],
            'date_before' => ['type' => 'string', 'description' => 'Orders created before this date.'],
            'orderby' => [
                'type' => 'string',
                'enum' => ['date', 'id', 'total'],
                'default' => 'date',
            ],
            'order' => [
                'type' => 'string',
                'enum' => ['ASC', 'DESC'],
                'default' => 'DESC',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'orders' => ['type' => 'array'],
            'total' => ['type' => 'integer'],
            'pages' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => 'nibwp_wc_list_orders',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'List WooCommerce orders with filters and pagination.',
                '',
                'FILTERS:',
                '- status: single string or array. Common statuses: pending, processing, on-hold, completed, cancelled, refunded, failed.',
                '- customer_id: filter orders for a specific customer user ID.',
                '- date_after / date_before: date range filter (Y-m-d format).',
                '',
                'PAGINATION:',
                '- per_page (default 20, max 100) and page (default 1).',
                '',
                'SORTING:',
                '- orderby: date, id, total.',
                '- order: ASC or DESC.',
                '',
                'Returns summary data per order. Use wc-get-order for full details.',
                '',
                'REQUIRES: WooCommerce plugin must be active.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_wc_list_orders(array $input): array|WP_Error
{
    if (!class_exists('WooCommerce')) {
        return new WP_Error('wc_not_active', 'WooCommerce is not active.');
    }

    $per_page = min(max((int) ($input['per_page'] ?? 20), 1), 100);
    $page = max((int) ($input['page'] ?? 1), 1);

    $args = [
        'limit' => $per_page,
        'page' => $page,
        'orderby' => $input['orderby'] ?? 'date',
        'order' => $input['order'] ?? 'DESC',
        'paginate' => true,
    ];

    if (!empty($input['status'])) {
        $args['status'] = $input['status'];
    }
    if (!empty($input['customer_id'])) {
        $args['customer_id'] = (int) $input['customer_id'];
    }
    if (!empty($input['date_after'])) {
        $args['date_created'] = '>' . (string) $input['date_after'];
    }
    if (!empty($input['date_before'])) {
        if (isset($args['date_created'])) {
            $args['date_created'] .= '...<' . (string) $input['date_before'];
        } else {
            $args['date_created'] = '<' . (string) $input['date_before'];
        }
    }

    $results = wc_get_orders($args);

    $orders = [];
    foreach ($results->orders as $order) {
        /** @var WC_Order $order */
        $orders[] = [
            'id' => $order->get_id(),
            'status' => $order->get_status(),
            'total' => $order->get_total(),
            'currency' => $order->get_currency(),
            'customer_id' => $order->get_customer_id(),
            'billing' => [
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'email' => $order->get_billing_email(),
            ],
            'items_count' => $order->get_item_count(),
            'date_created' => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : null,
        ];
    }

    return [
        'orders' => $orders,
        'total' => (int) $results->total,
        'pages' => (int) $results->max_num_pages,
    ];
}

// --- Ability: nibwp/wc-get-order ---

wp_register_ability('nibwp/wc-get-order', [
    'label' => __('WooCommerce – Get Order', domain: 'nibwp'),
    'description' => __(
        'Get full details of a single WooCommerce order including items, billing, shipping, fees, coupons, and notes.',
        domain: 'nibwp',
    ),
    'category' => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'order_id' => ['type' => 'integer', 'description' => 'The order ID.'],
        ],
        'required' => ['order_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'order' => ['type' => 'object', 'description' => 'Full order data.'],
        ],
    ],
    'execute_callback' => 'nibwp_wc_get_order',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Retrieve full details for a single WooCommerce order.',
                '',
                'Returns comprehensive data including:',
                '- Status, totals (subtotal, tax, shipping, discount, total), currency.',
                '- Payment method and transaction ID.',
                '- Customer info (user ID, IP, user agent).',
                '- Billing and shipping addresses.',
                '- Line items (product_id, name, quantity, subtotal, total).',
                '- Fee lines and coupon lines.',
                '- Order notes (customer and internal).',
                '- Dates (created, modified, paid, completed).',
                '',
                'REQUIRES: WooCommerce plugin must be active.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_wc_get_order(array $input): array|WP_Error
{
    if (!class_exists('WooCommerce')) {
        return new WP_Error('wc_not_active', 'WooCommerce is not active.');
    }

    $order_id = (int) ($input['order_id'] ?? 0);
    if ($order_id <= 0) {
        return new WP_Error('invalid_order_id', 'order_id must be a positive integer.');
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        return new WP_Error('order_not_found', sprintf('Order %d not found.', $order_id));
    }

    $items = [];
    foreach ($order->get_items() as $item) {
        /** @var WC_Order_Item_Product $item */
        $items[] = [
            'id' => $item->get_id(),
            'product_id' => $item->get_product_id(),
            'variation_id' => $item->get_variation_id(),
            'name' => $item->get_name(),
            'quantity' => $item->get_quantity(),
            'subtotal' => $item->get_subtotal(),
            'total' => $item->get_total(),
            'tax' => $item->get_total_tax(),
            'sku' => $item->get_product() ? $item->get_product()->get_sku() : '',
        ];
    }

    $fees = [];
    foreach ($order->get_fees() as $fee) {
        $fees[] = [
            'name' => $fee->get_name(),
            'total' => $fee->get_total(),
            'tax' => $fee->get_total_tax(),
        ];
    }

    $coupons = [];
    foreach ($order->get_coupon_codes() as $code) {
        $coupons[] = $code;
    }

    $notes = [];
    $order_notes = wc_get_order_notes(['order_id' => $order_id, 'orderby' => 'date_created_gmt']);
    foreach ($order_notes as $note) {
        $notes[] = [
            'id' => $note->id,
            'content' => $note->content,
            'customer_note' => (bool) $note->customer_note,
            'date' => $note->date_created->date('Y-m-d H:i:s'),
            'added_by' => $note->added_by,
        ];
    }

    $data = [
        'id' => $order->get_id(),
        'status' => $order->get_status(),
        'total' => $order->get_total(),
        'subtotal' => $order->get_subtotal(),
        'total_tax' => $order->get_total_tax(),
        'shipping_total' => $order->get_shipping_total(),
        'discount_total' => $order->get_discount_total(),
        'currency' => $order->get_currency(),
        'payment_method' => $order->get_payment_method(),
        'payment_method_title' => $order->get_payment_method_title(),
        'transaction_id' => $order->get_transaction_id(),
        'customer_id' => $order->get_customer_id(),
        'customer_ip' => $order->get_customer_ip_address(),
        'customer_user_agent' => $order->get_customer_user_agent(),
        'billing' => [
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name(),
            'company' => $order->get_billing_company(),
            'address_1' => $order->get_billing_address_1(),
            'address_2' => $order->get_billing_address_2(),
            'city' => $order->get_billing_city(),
            'state' => $order->get_billing_state(),
            'postcode' => $order->get_billing_postcode(),
            'country' => $order->get_billing_country(),
            'email' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
        ],
        'shipping' => [
            'first_name' => $order->get_shipping_first_name(),
            'last_name' => $order->get_shipping_last_name(),
            'company' => $order->get_shipping_company(),
            'address_1' => $order->get_shipping_address_1(),
            'address_2' => $order->get_shipping_address_2(),
            'city' => $order->get_shipping_city(),
            'state' => $order->get_shipping_state(),
            'postcode' => $order->get_shipping_postcode(),
            'country' => $order->get_shipping_country(),
        ],
        'items' => $items,
        'fees' => $fees,
        'coupons' => $coupons,
        'notes' => $notes,
        'date_created' => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : null,
        'date_modified' => $order->get_date_modified() ? $order->get_date_modified()->date('Y-m-d H:i:s') : null,
        'date_paid' => $order->get_date_paid() ? $order->get_date_paid()->date('Y-m-d H:i:s') : null,
        'date_completed' => $order->get_date_completed() ? $order->get_date_completed()->date('Y-m-d H:i:s') : null,
    ];

    return ['order' => $data];
}

// --- Ability: nibwp/wc-update-order-status ---

wp_register_ability('nibwp/wc-update-order-status', [
    'label' => __('WooCommerce – Update Order Status', domain: 'nibwp'),
    'description' => __(
        'Update the status of a WooCommerce order with an optional note.',
        domain: 'nibwp',
    ),
    'category' => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'order_id' => ['type' => 'integer', 'description' => 'The order ID.'],
            'status' => [
                'type' => 'string',
                'description' => 'New order status (without wc- prefix): pending, processing, on-hold, completed, cancelled, refunded, failed.',
            ],
            'note' => [
                'type' => 'string',
                'description' => 'Optional note to add when changing status.',
            ],
        ],
        'required' => ['order_id', 'status'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
            'old_status' => ['type' => 'string'],
            'new_status' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => 'nibwp_wc_update_order_status',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Update the status of a WooCommerce order.',
                '',
                'STATUSES (use without the "wc-" prefix):',
                '- pending: Awaiting payment.',
                '- processing: Payment received, awaiting fulfillment.',
                '- on-hold: Awaiting confirmation (e.g. bank transfer).',
                '- completed: Order fulfilled and complete.',
                '- cancelled: Order cancelled by admin or customer.',
                '- refunded: Order refunded.',
                '- failed: Payment failed or was declined.',
                '',
                'NOTE:',
                '- Optionally add a note that will be stored in the order history.',
                '- The note is added as an internal note (not visible to customer).',
                '- Status changes trigger WooCommerce hooks and may send emails.',
                '',
                'REQUIRES: WooCommerce plugin must be active.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_wc_update_order_status(array $input): array|WP_Error
{
    if (!class_exists('WooCommerce')) {
        return new WP_Error('wc_not_active', 'WooCommerce is not active.');
    }

    $order_id = (int) ($input['order_id'] ?? 0);
    if ($order_id <= 0) {
        return new WP_Error('invalid_order_id', 'order_id must be a positive integer.');
    }

    $status = trim((string) ($input['status'] ?? ''));
    if ($status === '') {
        return new WP_Error('missing_status', 'status is required.');
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        return new WP_Error('order_not_found', sprintf('Order %d not found.', $order_id));
    }

    $old_status = $order->get_status();
    $note = isset($input['note']) ? (string) $input['note'] : '';

    $order->update_status($status, $note);

    return [
        'id' => $order->get_id(),
        'old_status' => $old_status,
        'new_status' => $order->get_status(),
    ];
}

// --- Ability: nibwp/wc-list-customers ---

wp_register_ability('nibwp/wc-list-customers', [
    'label' => __('WooCommerce – List Customers', domain: 'nibwp'),
    'description' => __(
        'List WooCommerce customers with search, pagination, and sorting. Returns customer details with order statistics.',
        domain: 'nibwp',
    ),
    'category' => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page' => ['type' => 'integer', 'default' => 1],
            'search' => ['type' => 'string', 'description' => 'Search by name or email.'],
            'role' => [
                'type' => 'string',
                'description' => 'User role filter. Default: customer.',
                'default' => 'customer',
            ],
            'orderby' => [
                'type' => 'string',
                'enum' => ['ID', 'display_name', 'login', 'email', 'registered'],
                'default' => 'registered',
            ],
            'order' => [
                'type' => 'string',
                'enum' => ['ASC', 'DESC'],
                'default' => 'DESC',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'customers' => ['type' => 'array'],
            'total' => ['type' => 'integer'],
            'pages' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => 'nibwp_wc_list_customers',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'List WooCommerce customers with pagination and search.',
                '',
                'FILTERS:',
                '- search: Searches name, email, and login fields.',
                '- role: WordPress user role (default: "customer").',
                '',
                'RETURNED FIELDS:',
                '- id, email, first_name, last_name, display_name.',
                '- orders_count: Total number of orders placed.',
                '- total_spent: Lifetime total spent.',
                '- date_created: Registration date.',
                '',
                'REQUIRES: WooCommerce plugin must be active.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_wc_list_customers(array $input): array|WP_Error
{
    if (!class_exists('WooCommerce')) {
        return new WP_Error('wc_not_active', 'WooCommerce is not active.');
    }

    $per_page = min(max((int) ($input['per_page'] ?? 20), 1), 100);
    $page = max((int) ($input['page'] ?? 1), 1);

    $args = [
        'role' => $input['role'] ?? 'customer',
        'number' => $per_page,
        'paged' => $page,
        'orderby' => $input['orderby'] ?? 'registered',
        'order' => $input['order'] ?? 'DESC',
        'count_total' => true,
    ];

    if (!empty($input['search'])) {
        $args['search'] = '*' . esc_attr((string) $input['search']) . '*';
        $args['search_columns'] = ['user_login', 'user_email', 'user_nicename', 'display_name'];
    }

    $user_query = new WP_User_Query($args);
    $users = $user_query->get_results();
    $total = (int) $user_query->get_total();

    $customers = [];
    foreach ($users as $user) {
        $customer = new WC_Customer($user->ID);
        $customers[] = [
            'id' => $user->ID,
            'email' => $user->user_email,
            'first_name' => $customer->get_first_name(),
            'last_name' => $customer->get_last_name(),
            'display_name' => $user->display_name,
            'orders_count' => $customer->get_order_count(),
            'total_spent' => $customer->get_total_spent(),
            'date_created' => $user->user_registered,
        ];
    }

    return [
        'customers' => $customers,
        'total' => $total,
        'pages' => (int) ceil($total / $per_page),
    ];
}

// --- Ability: nibwp/wc-list-coupons ---

wp_register_ability('nibwp/wc-list-coupons', [
    'label' => __('WooCommerce – List Coupons', domain: 'nibwp'),
    'description' => __(
        'List WooCommerce coupons with search and pagination.',
        domain: 'nibwp',
    ),
    'category' => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page' => ['type' => 'integer', 'default' => 1],
            'search' => ['type' => 'string', 'description' => 'Search coupon codes.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'coupons' => ['type' => 'array'],
            'total' => ['type' => 'integer'],
            'pages' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => 'nibwp_wc_list_coupons',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'List WooCommerce coupons with search and pagination.',
                '',
                'RETURNED FIELDS:',
                '- id, code, discount_type, amount, usage_count, usage_limit.',
                '- date_expires: Expiration date or null.',
                '- description: Coupon description.',
                '',
                'DISCOUNT TYPES:',
                '- percent: Percentage discount.',
                '- fixed_cart: Fixed cart discount.',
                '- fixed_product: Fixed product discount.',
                '',
                'REQUIRES: WooCommerce plugin must be active.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_wc_list_coupons(array $input): array|WP_Error
{
    if (!class_exists('WooCommerce')) {
        return new WP_Error('wc_not_active', 'WooCommerce is not active.');
    }

    $per_page = min(max((int) ($input['per_page'] ?? 20), 1), 100);
    $page = max((int) ($input['page'] ?? 1), 1);

    $args = [
        'post_type' => 'shop_coupon',
        'post_status' => 'publish',
        'posts_per_page' => $per_page,
        'paged' => $page,
        'orderby' => 'date',
        'order' => 'DESC',
    ];

    if (!empty($input['search'])) {
        $args['s'] = (string) $input['search'];
    }

    $query = new WP_Query($args);
    $coupons = [];

    foreach ($query->posts as $post) {
        $coupon = new WC_Coupon($post->ID);
        $coupons[] = [
            'id' => $coupon->get_id(),
            'code' => $coupon->get_code(),
            'discount_type' => $coupon->get_discount_type(),
            'amount' => $coupon->get_amount(),
            'description' => $coupon->get_description(),
            'usage_count' => $coupon->get_usage_count(),
            'usage_limit' => $coupon->get_usage_limit(),
            'date_expires' => $coupon->get_date_expires() ? $coupon->get_date_expires()->date('Y-m-d H:i:s') : null,
        ];
    }

    return [
        'coupons' => $coupons,
        'total' => (int) $query->found_posts,
        'pages' => (int) $query->max_num_pages,
    ];
}

// --- Ability: nibwp/wc-create-coupon ---

wp_register_ability('nibwp/wc-create-coupon', [
    'label' => __('WooCommerce – Create Coupon', domain: 'nibwp'),
    'description' => __(
        'Create a new WooCommerce coupon with discount type, amount, usage limits, product restrictions, and more.',
        domain: 'nibwp',
    ),
    'category' => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'code' => ['type' => 'string', 'description' => 'Coupon code (must be unique).'],
            'discount_type' => [
                'type' => 'string',
                'enum' => ['percent', 'fixed_cart', 'fixed_product'],
                'default' => 'percent',
            ],
            'amount' => ['type' => 'string', 'description' => 'Discount amount.'],
            'description' => ['type' => 'string'],
            'date_expires' => ['type' => 'string', 'description' => 'Expiration date (Y-m-d).'],
            'usage_limit' => ['type' => 'integer', 'description' => 'Total usage limit.'],
            'usage_limit_per_user' => ['type' => 'integer', 'description' => 'Per-user usage limit.'],
            'individual_use' => ['type' => 'boolean', 'description' => 'Cannot be combined with other coupons.', 'default' => false],
            'product_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Products the coupon applies to.'],
            'excluded_product_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Products excluded from the coupon.'],
            'minimum_amount' => ['type' => 'string', 'description' => 'Minimum cart amount for coupon to apply.'],
            'maximum_amount' => ['type' => 'string', 'description' => 'Maximum cart amount for coupon to apply.'],
            'free_shipping' => ['type' => 'boolean', 'description' => 'Grants free shipping.', 'default' => false],
        ],
        'required' => ['code', 'amount'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
            'code' => ['type' => 'string'],
            'discount_type' => ['type' => 'string'],
            'amount' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => 'nibwp_wc_create_coupon',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Create a new WooCommerce coupon.',
                '',
                'REQUIRED: code (unique coupon code) and amount.',
                '',
                'DISCOUNT TYPES:',
                '- percent: Percentage discount (e.g. amount "10" = 10% off).',
                '- fixed_cart: Fixed amount off the entire cart.',
                '- fixed_product: Fixed amount off per qualifying product.',
                '',
                'RESTRICTIONS:',
                '- product_ids / excluded_product_ids: Restrict or exclude specific products.',
                '- minimum_amount / maximum_amount: Cart total requirements.',
                '- individual_use: Cannot be combined with other coupons.',
                '- usage_limit: Total number of times the coupon can be used.',
                '- usage_limit_per_user: Max uses per customer.',
                '',
                'REQUIRES: WooCommerce plugin must be active.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_wc_create_coupon(array $input): array|WP_Error
{
    if (!class_exists('WooCommerce')) {
        return new WP_Error('wc_not_active', 'WooCommerce is not active.');
    }

    $code = trim((string) ($input['code'] ?? ''));
    if ($code === '') {
        return new WP_Error('missing_code', 'Coupon code is required.');
    }

    $amount = trim((string) ($input['amount'] ?? ''));
    if ($amount === '') {
        return new WP_Error('missing_amount', 'Coupon amount is required.');
    }

    // Check for duplicate code.
    $existing_id = wc_get_coupon_id_by_code($code);
    if ($existing_id > 0) {
        return new WP_Error('duplicate_code', sprintf('A coupon with code "%s" already exists (ID: %d).', $code, $existing_id));
    }

    $coupon = new WC_Coupon();
    $coupon->set_code($code);
    $coupon->set_amount($amount);
    $coupon->set_discount_type($input['discount_type'] ?? 'percent');

    if (isset($input['description'])) {
        $coupon->set_description((string) $input['description']);
    }
    if (isset($input['date_expires'])) {
        $coupon->set_date_expires((string) $input['date_expires']);
    }
    if (isset($input['usage_limit'])) {
        $coupon->set_usage_limit((int) $input['usage_limit']);
    }
    if (isset($input['usage_limit_per_user'])) {
        $coupon->set_usage_limit_per_user((int) $input['usage_limit_per_user']);
    }
    if (isset($input['individual_use'])) {
        $coupon->set_individual_use((bool) $input['individual_use']);
    }
    if (isset($input['product_ids'])) {
        $coupon->set_product_ids(array_map('intval', (array) $input['product_ids']));
    }
    if (isset($input['excluded_product_ids'])) {
        $coupon->set_excluded_product_ids(array_map('intval', (array) $input['excluded_product_ids']));
    }
    if (isset($input['minimum_amount'])) {
        $coupon->set_minimum_amount((string) $input['minimum_amount']);
    }
    if (isset($input['maximum_amount'])) {
        $coupon->set_maximum_amount((string) $input['maximum_amount']);
    }
    if (isset($input['free_shipping'])) {
        $coupon->set_free_shipping((bool) $input['free_shipping']);
    }

    $coupon_id = $coupon->save();
    if (!$coupon_id) {
        return new WP_Error('save_failed', 'Failed to save the coupon.');
    }

    return [
        'id' => $coupon_id,
        'code' => $coupon->get_code(),
        'discount_type' => $coupon->get_discount_type(),
        'amount' => $coupon->get_amount(),
    ];
}

// --- Ability: nibwp/wc-get-reports ---

wp_register_ability('nibwp/wc-get-reports', [
    'label' => __('WooCommerce – Get Reports', domain: 'nibwp'),
    'description' => __(
        'Get WooCommerce sales reports and statistics including totals, top sellers, and top earners for configurable date ranges.',
        domain: 'nibwp',
    ),
    'category' => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'report_type' => [
                'type' => 'string',
                'enum' => ['sales', 'top_sellers', 'top_earners'],
                'description' => 'Type of report.',
                'default' => 'sales',
            ],
            'period' => [
                'type' => 'string',
                'enum' => ['week', 'month', 'year', 'custom'],
                'default' => 'month',
            ],
            'date_min' => ['type' => 'string', 'description' => 'Start date (Y-m-d) for custom period.'],
            'date_max' => ['type' => 'string', 'description' => 'End date (Y-m-d) for custom period.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'report' => ['type' => 'object', 'description' => 'Report data with totals and items.'],
        ],
    ],
    'execute_callback' => 'nibwp_wc_get_reports',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Get WooCommerce sales reports and statistics.',
                '',
                'REPORT TYPES:',
                '- sales: Overall sales totals (revenue, orders count, items sold, refunds, shipping, tax).',
                '- top_sellers: Top selling products by quantity.',
                '- top_earners: Top earning products by revenue.',
                '',
                'PERIODS:',
                '- week: Last 7 days.',
                '- month: Last 30 days (default).',
                '- year: Last 365 days.',
                '- custom: Requires date_min and date_max (Y-m-d format).',
                '',
                'REQUIRES: WooCommerce plugin must be active.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_wc_get_reports(array $input): array|WP_Error
{
    if (!class_exists('WooCommerce')) {
        return new WP_Error('wc_not_active', 'WooCommerce is not active.');
    }

    $report_type = $input['report_type'] ?? 'sales';
    $period = $input['period'] ?? 'month';

    // Determine date range.
    $now = current_time('timestamp');
    switch ($period) {
        case 'week':
            $date_min = gmdate('Y-m-d', $now - 7 * DAY_IN_SECONDS);
            $date_max = gmdate('Y-m-d', $now);
            break;
        case 'year':
            $date_min = gmdate('Y-m-d', $now - 365 * DAY_IN_SECONDS);
            $date_max = gmdate('Y-m-d', $now);
            break;
        case 'custom':
            $date_min = $input['date_min'] ?? '';
            $date_max = $input['date_max'] ?? '';
            if ($date_min === '' || $date_max === '') {
                return new WP_Error('missing_dates', 'date_min and date_max are required for custom period.');
            }
            break;
        default: // month
            $date_min = gmdate('Y-m-d', $now - 30 * DAY_IN_SECONDS);
            $date_max = gmdate('Y-m-d', $now);
            break;
    }

    // Query completed/processing orders in the date range.
    $orders = wc_get_orders([
        'status' => ['completed', 'processing'],
        'date_created' => $date_min . '...' . $date_max,
        'limit' => -1,
    ]);

    if ($report_type === 'sales') {
        $total_sales = 0.0;
        $total_orders = 0;
        $total_items = 0;
        $total_shipping = 0.0;
        $total_tax = 0.0;
        $total_refunds = 0.0;

        foreach ($orders as $order) {
            /** @var WC_Order $order */
            $total_sales += (float) $order->get_total();
            $total_orders++;
            $total_items += $order->get_item_count();
            $total_shipping += (float) $order->get_shipping_total();
            $total_tax += (float) $order->get_total_tax();
            $total_refunds += (float) $order->get_total_refunded();
        }

        return [
            'report' => [
                'type' => 'sales',
                'period' => $period,
                'date_min' => $date_min,
                'date_max' => $date_max,
                'total_sales' => round($total_sales, 2),
                'net_sales' => round($total_sales - $total_refunds, 2),
                'total_orders' => $total_orders,
                'total_items' => $total_items,
                'total_shipping' => round($total_shipping, 2),
                'total_tax' => round($total_tax, 2),
                'total_refunds' => round($total_refunds, 2),
                'average_order_value' => $total_orders > 0 ? round($total_sales / $total_orders, 2) : 0,
            ],
        ];
    }

    // Top sellers or top earners.
    $product_data = [];
    foreach ($orders as $order) {
        foreach ($order->get_items() as $item) {
            /** @var WC_Order_Item_Product $item */
            $pid = $item->get_product_id();
            if (!isset($product_data[$pid])) {
                $product_data[$pid] = [
                    'product_id' => $pid,
                    'name' => $item->get_name(),
                    'quantity' => 0,
                    'revenue' => 0.0,
                ];
            }
            $product_data[$pid]['quantity'] += $item->get_quantity();
            $product_data[$pid]['revenue'] += (float) $item->get_total();
        }
    }

    $sort_key = $report_type === 'top_sellers' ? 'quantity' : 'revenue';
    usort($product_data, fn($a, $b) => $b[$sort_key] <=> $a[$sort_key]);

    $items = array_slice($product_data, 0, 20);
    foreach ($items as &$item) {
        $item['revenue'] = round($item['revenue'], 2);
    }
    unset($item);

    return [
        'report' => [
            'type' => $report_type,
            'period' => $period,
            'date_min' => $date_min,
            'date_max' => $date_max,
            'items' => $items,
        ],
    ];
}
