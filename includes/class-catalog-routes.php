<?php
/**
 * Catalog & Products REST API Routes
 *
 * Handles category queries, product listings, pagination, and multi-criteria sorting
 * (latest, popularity, rating, price low-to-high, price high-to-low).
 *
 * @package   Handicraft_Auth
 * @namespace handicraft/v1
 */

if (!defined('ABSPATH')) {
    exit;
}

class HIN_Catalog_Routes {

    const NAMESPACE = 'handicraft/v1';

    /**
     * Register REST API routes for Catalog & Products.
     */
    public function register_routes() {

        /**
         * @route   GET /wp-json/handicraft/v1/catalog
         * @route   GET /wp-json/handicraft/v1/catalog/{slug}
         * @route   GET /wp-json/handicraft/v1/products
         * @desc    Retrieve category details, subcategories, paginated products, and sort filters.
         * @auth    Public
         */
        register_rest_route(self::NAMESPACE, '/catalog', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle_get_catalog'],
            'permission_callback' => '__return_true',
            'args'                => $this->get_catalog_params(),
        ]);

        register_rest_route(self::NAMESPACE, '/catalog/(?P<slug>[a-zA-Z0-9_-]+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle_get_catalog_by_slug'],
            'permission_callback' => '__return_true',
            'args'                => $this->get_catalog_params(),
        ]);

        register_rest_route(self::NAMESPACE, '/products', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle_get_catalog'],
            'permission_callback' => '__return_true',
            'args'                => $this->get_catalog_params(),
        ]);

        /**
         * @route   GET /wp-json/handicraft/v1/search
         * @desc    Instant predictive search returning matched categories and products.
         * @auth    Public
         */
        register_rest_route(self::NAMESPACE, '/search', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle_instant_search'],
            'permission_callback' => '__return_true',
            'args'                => [
                'q' => [
                    'description'       => 'Search query keyword',
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'limit' => [
                    'description'       => 'Maximum items to return (default 6)',
                    'required'          => false,
                    'type'              => 'integer',
                    'default'           => 6,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        /**
         * @route   GET /wp-json/handicraft/v1/products/{slug}
         * @desc    Retrieve single product detail, attributes, gallery, reviews, and related products.
         * @auth    Public
         */
        register_rest_route(self::NAMESPACE, '/products/(?P<slug>[a-zA-Z0-9_-]+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle_get_product_detail'],
            'permission_callback' => '__return_true',
            'args'                => [
                'slug' => [
                    'description'       => 'Product slug or numeric ID',
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_title',
                ],
            ],
        ]);
    }

    /**
     * Common query parameters schema.
     */
    private function get_catalog_params(): array {
        return [
            'slug' => [
                'description'       => 'Category or collection slug (e.g. arrival, incense, ancient-tibetan, stock)',
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_title',
            ],
            'page' => [
                'description'       => 'Current page number',
                'required'          => false,
                'type'              => 'integer',
                'default'           => 1,
                'sanitize_callback' => 'absint',
            ],
            'per_page' => [
                'description'       => 'Number of products per page (max 100)',
                'required'          => false,
                'type'              => 'integer',
                'default'           => 24,
                'sanitize_callback' => 'absint',
            ],
            'sort' => [
                'description'       => 'Sorting option: latest, popularity, rating, price_low_high, price_high_low, title',
                'required'          => false,
                'type'              => 'string',
                'default'           => 'latest',
                'sanitize_callback' => 'sanitize_key',
            ],
            'min_price' => [
                'description'       => 'Minimum product price filter',
                'required'          => false,
                'type'              => 'number',
            ],
            'max_price' => [
                'description'       => 'Maximum product price filter',
                'required'          => false,
                'type'              => 'number',
            ],
            'in_stock' => [
                'description'       => 'Filter by in-stock products only',
                'required'          => false,
                'type'              => 'boolean',
            ],
            'search' => [
                'description'       => 'Search query string',
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }

    /**
     * Handle Get Catalog by Path Slug.
     */
    public function handle_get_catalog_by_slug(WP_REST_Request $request): WP_REST_Response {
        return $this->handle_get_catalog($request);
    }

    /**
     * Main Catalog & Products Endpoint Handler.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_get_catalog(WP_REST_Request $request): WP_REST_Response {
        $slug      = $request->get_param('slug');
        $page      = max(1, (int) ($request->get_param('page') ?: 1));
        $per_page  = min(100, max(1, (int) ($request->get_param('per_page') ?: 24)));
        $sort      = $request->get_param('sort') ?: 'latest';
        $min_price = $request->get_param('min_price');
        $max_price = $request->get_param('max_price');
        $in_stock  = $request->get_param('in_stock');
        $search    = trim($request->get_param('search') ?: $request->get_param('q') ?: $request->get_param('s') ?: '');

        // Check if requester is wholesale
        $is_wholesale = $this->is_wholesale_requester();

        // 1. Resolve Category / Collection Info
        $category_data = $this->resolve_category_info($slug);

        if (!empty($search) && (empty($category_data['term_id']) || empty($slug) || $slug === 'all' || $slug === 'products')) {
            $category_data['info']['name']        = 'Search: "' . esc_html($search) . '"';
            $category_data['info']['description'] = 'Search results for authentic handcrafted items matching "' . esc_html($search) . '".';
        }

        // 2. Build WP_Query Arguments
        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'tax_query'      => [],
            'meta_query'     => [],
        ];

        // Search Query
        if (!empty($search)) {
            $args['s'] = $search;
        }

        // Category Filter
        if (!empty($category_data['term_id'])) {
            $args['tax_query'][] = [
                'taxonomy'         => 'product_cat',
                'field'            => 'term_id',
                'terms'            => (int) $category_data['term_id'],
                'include_children' => true,
            ];
        }



        // In-stock Filter
        if ($in_stock === true || $in_stock === 'true' || $in_stock === 1) {
            $args['meta_query'][] = [
                'key'     => '_stock_status',
                'value'   => 'instock',
                'compare' => '=',
            ];
        }

        // Price Filter
        if ($min_price !== null || $max_price !== null) {
            $price_query = [
                'key'     => '_price',
                'type'    => 'NUMERIC',
                'compare' => 'BETWEEN',
            ];

            $min = is_numeric($min_price) ? floatval($min_price) : 0;
            $max = is_numeric($max_price) ? floatval($max_price) : 999999;
            $price_query['value'] = [$min, $max];
            $args['meta_query'][] = $price_query;
        }

        // Apply Sorting Criteria
        $args = array_merge($args, $this->build_sort_args($sort));

        // Execute Query
        $query = new WP_Query($args);
        $total_products = (int) $query->found_posts;
        $total_pages    = (int) $query->max_num_pages;

        // If category is a special collection, sync total product count
        if (isset($category_data['info']['count']) && empty($category_data['term_id'])) {
            $category_data['info']['count'] = $total_products;
        }

        // 3. Format Products
        $products = [];
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $product = wc_get_product(get_the_ID());
                if ($product) {
                    $products[] = $this->format_product($product, $is_wholesale);
                }
            }
            wp_reset_postdata();
        }

        // 4. Return Structured Response
        return new WP_REST_Response([
            'success'    => true,
            'category'   => $category_data['info'],
            'pagination' => [
                'total'       => $total_products,
                'totalPages'  => $total_pages,
                'currentPage' => $page,
                'perPage'     => $per_page,
                'hasNext'     => $page < $total_pages,
                'hasPrev'     => $page > 1,
            ],
            'sort'       => $sort,
            'availableSorts' => [
                ['value' => 'latest', 'label' => 'Latest Arrivals'],
                ['value' => 'popularity', 'label' => 'Popularity / Best Selling'],
                ['value' => 'rating', 'label' => 'Average Rating'],
                ['value' => 'price_low_high', 'label' => 'Price: Low to High'],
                ['value' => 'price_high_low', 'label' => 'Price: High to Low'],
                ['value' => 'title', 'label' => 'Alphabetical (A-Z)'],
            ],
            'products'   => $products,
        ], 200);
    }

    /**
     * Resolve category metadata and subcategories.
     */
    private function resolve_category_info(?string $slug): array {
        // Special 1: Arrival (New Arrivals) - Link to WooCommerce category if exists
        if ($slug === 'arrival' || $slug === 'new-arrivals') {
            $term = get_term_by('slug', 'new-arrivals', 'product_cat') ?: get_term_by('slug', 'arrival', 'product_cat');
            if ($term && !is_wp_error($term)) {
                return [
                    'term_id' => (int) $term->term_id,
                    'info'    => [
                        'id'            => (int) $term->term_id,
                        'name'          => html_entity_decode($term->name, ENT_QUOTES, 'UTF-8'),
                        'slug'          => $term->slug,
                        'description'   => wp_strip_all_tags($term->description) ?: 'Discover our newest handcrafted collections made with authentic traditions and spiritual care in Nepal.',
                        'rawDescription'=> $term->description,
                        'parent'        => (int) $term->parent,
                        'count'         => (int) $term->count,
                        'image'         => null,
                        'subcategories' => [],
                        'isSpecial'     => false,
                    ],
                ];
            }

            return [
                'term_id' => null,
                'info'    => [
                    'id'            => 0,
                    'name'          => 'New Arrivals',
                    'slug'          => 'new-arrivals',
                    'description'   => 'Discover our newest handcrafted collections made with authentic traditions and spiritual care in Nepal.',
                    'parent'        => 0,
                    'count'         => 0,
                    'subcategories' => [],
                    'isSpecial'     => true,
                ],
            ];
        }

        // Special 2: Stock (On Sale / Clearance Items) - Link to on-sale category
        if ($slug === 'stock' || $slug === 'on-sale' || $slug === 'sale') {
            $term = get_term_by('slug', 'on-sale', 'product_cat') ?: get_term_by('slug', 'stock', 'product_cat');
            if ($term && !is_wp_error($term)) {
                return [
                    'term_id' => (int) $term->term_id,
                    'info'    => [
                        'id'            => (int) $term->term_id,
                        'name'          => 'On Sale / Clearance',
                        'slug'          => 'stock',
                        'description'   => wp_strip_all_tags($term->description) ?: 'Discover discounted handcrafted singing bowls and artisanal items on special sale.',
                        'rawDescription'=> $term->description,
                        'parent'        => (int) $term->parent,
                        'count'         => (int) $term->count,
                        'image'         => null,
                        'subcategories' => [],
                        'isSpecial'     => true,
                    ],
                ];
            }

            return [
                'term_id' => null,
                'info'    => [
                    'id'            => 0,
                    'name'          => 'On Sale / Clearance',
                    'slug'          => 'stock',
                    'description'   => 'Explore authentic handcrafted goods currently on special clearance.',
                    'parent'        => 0,
                    'count'         => 0,
                    'subcategories' => [],
                    'isSpecial'     => true,
                ],
            ];
        }

        // Slug Aliases & Synonyms Mapping
        $slug_aliases = [
            'yoga-items'   => 'yoga-accessories',
            'yoga'         => 'yoga-accessories',
            'blankets'     => 'blanket',
            'arrival'      => 'new-arrivals',
            'stock'        => 'on-sale',
            'sale'         => 'on-sale',
        ];

        if (isset($slug_aliases[$slug])) {
            $slug = $slug_aliases[$slug];
        }

        // Regular WooCommerce product category (supports leaf slug from multi-segment path e.g. incense/ancient-tibetan)
        if (!empty($slug)) {
            $search_slug = $slug;
            if (strpos($slug, '/') !== false) {
                $parts = array_filter(explode('/', trim($slug, '/')));
                $search_slug = end($parts) ?: $slug;
            }

            if (isset($slug_aliases[$search_slug])) {
                $search_slug = $slug_aliases[$search_slug];
            }

            $term = get_term_by('slug', $search_slug, 'product_cat')
                 ?: get_term_by('slug', $slug, 'product_cat')
                 ?: get_term_by('slug', rtrim($search_slug, 's'), 'product_cat');

            // Fallback: search by name without hyphen
            if (!$term) {
                $name_guess = str_replace('-', ' ', $search_slug);
                $term = get_term_by('name', $name_guess, 'product_cat');
            }

            if ($term && !is_wp_error($term)) {
                $child_terms = get_terms([
                    'taxonomy'   => 'product_cat',
                    'parent'     => $term->term_id,
                    'hide_empty' => false,
                ]);

                $subcategories = [];
                if (!empty($child_terms) && !is_wp_error($child_terms)) {
                    foreach ($child_terms as $child) {
                        $subcategories[] = [
                            'id'    => (int) $child->term_id,
                            'name'  => html_entity_decode($child->name, ENT_QUOTES, 'UTF-8'),
                            'slug'  => $child->slug,
                            'count' => (int) $child->count,
                            'href'  => '/' . $child->slug,
                        ];
                    }
                }

                $image_url = null;
                $thumb_id  = get_term_meta($term->term_id, 'thumbnail_id', true);
                if ($thumb_id) {
                    $image_url = wp_get_attachment_url($thumb_id);
                }

                return [
                    'term_id' => (int) $term->term_id,
                    'info'    => [
                        'id'            => (int) $term->term_id,
                        'name'          => html_entity_decode($term->name, ENT_QUOTES, 'UTF-8'),
                        'slug'          => $term->slug,
                        'description'   => wp_strip_all_tags($term->description),
                        'rawDescription'=> $term->description,
                        'parent'        => (int) $term->parent,
                        'count'         => (int) $term->count,
                        'image'         => $image_url,
                        'subcategories' => $subcategories,
                        'isSpecial'     => false,
                    ],
                ];
            }
        }

        // Default Catalog
        return [
            'term_id' => null,
            'info'    => [
                'id'            => 0,
                'name'          => 'All Products',
                'slug'          => 'all',
                'description'   => 'Browse our complete catalog of authentic handmade items from Nepal.',
                'parent'        => 0,
                'count'         => (int) wp_count_posts('product')->publish,
                'subcategories' => [],
                'isSpecial'     => true,
            ],
        ];
    }

    /**
     * Build sorting arguments for WP_Query.
     */
    private function build_sort_args(string $sort): array {
        switch ($sort) {
            case 'popularity':
            case 'popular':
                return [
                    'meta_key' => 'total_sales',
                    'orderby'  => 'meta_value_num date',
                    'order'    => 'DESC',
                ];

            case 'rating':
            case 'average_rating':
                return [
                    'meta_key' => '_wc_average_rating',
                    'orderby'  => 'meta_value_num',
                    'order'    => 'DESC',
                ];

            case 'price_low_high':
            case 'price-asc':
            case 'price_asc':
                return [
                    'meta_key' => '_price',
                    'orderby'  => 'meta_value_num',
                    'order'    => 'ASC',
                ];

            case 'price_high_low':
            case 'price-desc':
            case 'price_desc':
                return [
                    'meta_key' => '_price',
                    'orderby'  => 'meta_value_num',
                    'order'    => 'DESC',
                ];

            case 'title':
            case 'name':
                return [
                    'orderby' => 'title',
                    'order'   => 'ASC',
                ];

            case 'latest':
            case 'date':
            default:
                return [
                    'orderby' => 'date',
                    'order'   => 'DESC',
                ];
        }
    }

    /**
     * Format a WooCommerce product object for clean JSON output.
     */
    private function format_product(WC_Product $product, bool $is_wholesale): array {
        $id = $product->get_id();

        // Standard Prices
        $regular_price = $product->get_regular_price();
        $sale_price    = $product->get_sale_price();
        $price         = $product->get_price();

        // Dynamic Wholesale Pricing Check
        $wholesale_price = get_post_meta($id, '_wholesale_price', true);
        if ($is_wholesale && !empty($wholesale_price) && is_numeric($wholesale_price)) {
            $price = (string) $wholesale_price;
        }

        // Images formatting
        $images = [];
        $featured_id = $product->get_image_id();
        if ($featured_id) {
            $images[] = [
                'id'        => (int) $featured_id,
                'src'       => wp_get_attachment_url($featured_id) ?: '',
                'thumbnail' => wp_get_attachment_image_url($featured_id, 'woocommerce_thumbnail') ?: '',
                'alt'       => get_post_meta($featured_id, '_wp_attachment_image_alt', true) ?: $product->get_name(),
            ];
        }

        $gallery_ids = $product->get_gallery_image_ids();
        if (!empty($gallery_ids)) {
            foreach ($gallery_ids as $gallery_id) {
                if ($gallery_id !== $featured_id) {
                    $images[] = [
                        'id'        => (int) $gallery_id,
                        'src'       => wp_get_attachment_url($gallery_id) ?: '',
                        'thumbnail' => wp_get_attachment_image_url($gallery_id, 'woocommerce_thumbnail') ?: '',
                        'alt'       => get_post_meta($gallery_id, '_wp_attachment_image_alt', true) ?: $product->get_name(),
                    ];
                }
            }
        }

        // Categories
        $categories = [];
        $terms = get_the_terms($id, 'product_cat');
        if (!empty($terms) && !is_wp_error($terms)) {
            foreach ($terms as $t) {
                $categories[] = [
                    'id'   => (int) $t->term_id,
                    'name' => html_entity_decode($t->name, ENT_QUOTES, 'UTF-8'),
                    'slug' => $t->slug,
                ];
            }
        }

        return [
            'id'               => (int) $id,
            'name'             => html_entity_decode($product->get_name(), ENT_QUOTES, 'UTF-8'),
            'slug'             => $product->get_slug(),
            'sku'              => $product->get_sku() ?: '',
            'price'            => !empty($price) ? floatval($price) : 0,
            'regularPrice'     => !empty($regular_price) ? floatval($regular_price) : (!empty($price) ? floatval($price) : 0),
            'salePrice'        => !empty($sale_price) ? floatval($sale_price) : null,
            'wholesalePrice'   => !empty($wholesale_price) ? floatval($wholesale_price) : null,
            'onSale'           => $product->is_on_sale(),
            'inStock'          => $product->is_in_stock(),
            'stockQuantity'    => $product->get_stock_quantity(),
            'rating'           => floatval($product->get_average_rating()),
            'reviewCount'      => (int) $product->get_review_count(),
            'images'           => $images,
            'featuredImage'    => !empty($images[0]['src']) ? $images[0]['src'] : '',
            'categories'       => $categories,
            'shortDescription' => wp_strip_all_tags($product->get_short_description()),
            'description'      => $product->get_description(),
            'weight'           => $product->get_weight(),
            'dimensions'       => $product->get_dimensions(false),
            'createdAt'        => get_the_date('c', $id),
        ];
    }

    /**
     * Single Product Detail Endpoint Handler.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_get_product_detail(WP_REST_Request $request): WP_REST_Response {
        $slug = $request->get_param('slug');
        if (empty($slug)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Product identifier is required.',
            ], 400);
        }

        $product_id = 0;
        if (is_numeric($slug)) {
            $product_id = (int) $slug;
        } else {
            // Locate product by slug (post_name)
            $posts = get_posts([
                'name'        => $slug,
                'post_type'   => 'product',
                'post_status' => 'publish',
                'numberposts' => 1,
                'fields'      => 'ids',
            ]);
            if (!empty($posts)) {
                $product_id = (int) $posts[0];
            }
        }

        if (!$product_id) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Product not found.',
            ], 404);
        }

        $product = wc_get_product($product_id);
        if (!$product || $product->get_status() !== 'publish') {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Product not found or currently unavailable.',
            ], 404);
        }

        $is_wholesale = $this->is_wholesale_requester();
        $formatted    = $this->format_product($product, $is_wholesale);

        // 1. Extract Attributes (Dimensions, Materials, Origins, Variations)
        $attributes = [];
        foreach ($product->get_attributes() as $attr) {
            if (is_a($attr, 'WC_Product_Attribute')) {
                $attr_name = wc_attribute_label($attr->get_name(), $product);
                $options   = $attr->get_options();
                if ($attr->is_taxonomy()) {
                    $terms   = wc_get_product_terms($product_id, $attr->get_name(), ['fields' => 'names']);
                    $options = !is_wp_error($terms) ? $terms : [];
                }
                $attributes[] = [
                    'id'        => (int) $attr->get_id(),
                    'name'      => $attr_name,
                    'slug'      => sanitize_title($attr->get_name()),
                    'options'   => (array) $options,
                    'visible'   => (bool) $attr->get_visible(),
                    'variation' => (bool) $attr->get_variation(),
                ];
            }
        }

        // 2. Related Products (Up to 4 related items)
        $related_ids      = wc_get_related_products($product_id, 4);
        $related_products = [];
        if (!empty($related_ids)) {
            foreach ($related_ids as $r_id) {
                $r_prod = wc_get_product($r_id);
                if ($r_prod && $r_prod->is_visible()) {
                    $related_products[] = $this->format_product($r_prod, $is_wholesale);
                }
            }
        }

        // Fallback related products from same primary category if wc_get_related_products returns empty
        if (empty($related_products) && !empty($formatted['categories'][0]['id'])) {
            $cat_id = $formatted['categories'][0]['id'];
            $fallback_posts = get_posts([
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => 4,
                'post__not_in'   => [$product_id],
                'tax_query'      => [
                    [
                        'taxonomy' => 'product_cat',
                        'field'    => 'term_id',
                        'terms'    => $cat_id,
                    ],
                ],
                'fields'         => 'ids',
            ]);
            foreach ($fallback_posts as $f_id) {
                $f_prod = wc_get_product($f_id);
                if ($f_prod && $f_prod->is_visible()) {
                    $related_products[] = $this->format_product($f_prod, $is_wholesale);
                }
            }
        }

        // 3. Customer Reviews
        $comments = get_comments([
            'post_id' => $product_id,
            'status'  => 'approve',
            'type'    => 'review',
            'number'  => 10,
        ]);
        $reviews = [];
        if (!empty($comments)) {
            foreach ($comments as $comment) {
                $rating    = (int) get_comment_meta($comment->comment_ID, 'rating', true);
                $reviews[] = [
                    'id'        => (int) $comment->comment_ID,
                    'author'    => html_entity_decode($comment->comment_author, ENT_QUOTES, 'UTF-8'),
                    'content'   => wp_strip_all_tags($comment->comment_content),
                    'rating'    => $rating ?: 5,
                    'date'      => get_comment_date('c', $comment),
                    'verified'  => (bool) wc_review_is_from_verified_owner($comment->comment_ID),
                ];
            }
        }

        return new WP_REST_Response([
            'success' => true,
            'product' => array_merge($formatted, [
                'attributes'      => $attributes,
                'relatedProducts' => $related_products,
                'reviews'         => $reviews,
            ]),
        ], 200);
    }

    /**
     * Predictive Instant Search Handler (Categories + Products).
     *
     * @route   GET /wp-json/handicraft/v1/search?q={query}&limit={limit}
     * @param   WP_REST_Request $request
     * @return  WP_REST_Response
     */
    public function handle_instant_search(WP_REST_Request $request): WP_REST_Response {
        $query = trim($request->get_param('q') ?: $request->get_param('search') ?: '');
        $limit = min(20, max(1, (int) ($request->get_param('limit') ?: 6)));
        $is_wholesale = $this->is_wholesale_requester();

        if (empty($query)) {
            return new WP_REST_Response([
                'success'    => true,
                'query'      => '',
                'total'      => 0,
                'categories' => [],
                'products'   => [],
            ], 200);
        }

        // 1. Search matching product categories
        $matching_categories = [];
        $cat_terms = get_terms([
            'taxonomy'   => 'product_cat',
            'name__like' => $query,
            'hide_empty' => true,
            'number'     => 4,
        ]);
        if (!empty($cat_terms) && !is_wp_error($cat_terms)) {
            foreach ($cat_terms as $ct) {
                $matching_categories[] = [
                    'id'    => (int) $ct->term_id,
                    'name'  => html_entity_decode($ct->name, ENT_QUOTES, 'UTF-8'),
                    'slug'  => $ct->slug,
                    'count' => (int) $ct->count,
                    'href'  => '/' . $ct->slug,
                ];
            }
        }

        // 2. Search matching products (title, description, excerpt, SKU)
        $prod_query = new WP_Query([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            's'              => $query,
            'orderby'        => 'relevance',
        ]);

        $total_found = (int) $prod_query->found_posts;
        $products = [];

        if ($prod_query->have_posts()) {
            while ($prod_query->have_posts()) {
                $prod_query->the_post();
                $product = wc_get_product(get_the_ID());
                if ($product && $product->is_visible()) {
                    $products[] = $this->format_product($product, $is_wholesale);
                }
            }
            wp_reset_postdata();
        }

        return new WP_REST_Response([
            'success'    => true,
            'query'      => $query,
            'total'      => $total_found,
            'categories' => $matching_categories,
            'products'   => $products,
        ], 200);
    }

    /**
     * Check if requester has a wholesale role or international wholesale status.
     */
    private function is_wholesale_requester(): bool {
        $user_id = get_current_user_id();
        if ($user_id) {
            $user = get_userdata($user_id);
            if ($user && (in_array('wholesale', (array) $user->roles, true) || get_user_meta($user_id, 'is_wholesale', true))) {
                return true;
            }
        }

        // Geolocation Check: Non-Nepal requests in wholesale context
        $country = isset($_SERVER['HTTP_X_COUNTRY_CODE']) ? strtoupper(trim(sanitize_text_field($_SERVER['HTTP_X_COUNTRY_CODE']))) : '';
        if (!empty($country) && $country !== 'NP') {
            return true;
        }

        return false;
    }
}
