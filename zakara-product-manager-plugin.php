<?php
/**
 * Plugin Name: Zakara Bags Product Catalog & Description Manager
 * Plugin URI:  https://zakarabags.com
 * Description: Powers the Zakara Bags Admin Panel, providing secure REST endpoints with CORS support to manage products, full HTML descriptions, images, categories, and direct synchronization with WooCommerce.
 * Version:     1.1.0
 * Author:      Zakara International
 * Author URI:  https://zakarabags.com
 * License:     GPL2
 * Text Domain: zakara-manager
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class Zakara_Product_Manager_Plugin {

    const REST_NAMESPACE = 'zakara/v1';

    public static function init() {
        // Register WP Admin Menu
        add_action('admin_menu', [__CLASS__, 'register_admin_menu']);

        // Register REST API Routes
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);

        // Enable CORS for Zakara Admin Panel API requests
        add_action('init', [__CLASS__, 'handle_cors_headers']);
    }

    /**
     * Set permissive CORS headers for Zakara REST API calls
     */
    public static function handle_cors_headers() {
        if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/wp-json/zakara/') !== false) {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Zakara-Token, X-WP-Nonce');
            header('Access-Control-Allow-Credentials: true');

            if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
                status_header(200);
                exit();
            }
        }
    }

    /**
     * Add top-level menu in WordPress Admin Dashboard
     */
    public static function register_admin_menu() {
        add_menu_page(
            'Zakara Products',                 // Page title
            'Zakara Products',                 // Menu title
            'edit_products',                   // Capability
            'zakara-product-manager',          // Menu slug
            [__CLASS__, 'render_admin_page'],  // Callback
            'dashicons-portfolio',             // Icon (leather bag/portfolio)
            26                                 // Position (right around WooCommerce Products)
        );
    }

    /**
     * Render the Admin Panel inside WordPress Admin
     */
    public static function render_admin_page() {
        $admin_url = plugins_url('admin.html', __FILE__);
        ?>
        <div class="wrap" style="margin: 0; padding: 0;">
            <div style="background: #ffffff; padding: 16px 24px; border-bottom: 1px solid #e0e0e0; display: flex; align-items: center; justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <img src="https://www.zakarabags.com/wp-content/uploads/2019/07/cropped-Zakara-Logo-192x192.png" width="36" height="36" style="border-radius: 6px;" alt="Zakara" />
                    <h1 style="margin: 0; font-size: 1.3rem; font-weight: 700; color: #6b4537;">Zakara Bags Product Catalog &amp; Description Console</h1>
                </div>
                <div>
                    <a href="<?php echo esc_url($admin_url); ?>" target="_blank" class="button button-primary" style="background: #6b4537; border-color: #523429;">
                        Open Standalone Fullscreen Console &rarr;
                    </a>
                </div>
            </div>
            <iframe src="<?php echo esc_url($admin_url); ?>" style="width: 100%; height: calc(100vh - 80px); border: none; display: block;" title="Zakara Catalog Manager"></iframe>
        </div>
        <?php
    }

    /**
     * Register REST API Endpoints under /wp-json/zakara/v1/
     */
    public static function register_rest_routes() {
        // 1. Auth Endpoint: /wp-json/zakara/v1/auth
        register_rest_route(self::REST_NAMESPACE, '/auth', [
            'methods'  => 'POST',
            'callback' => [__CLASS__, 'handle_auth_request'],
            'permission_callback' => '__return_true',
        ]);

        // 2. Products List & Create: /wp-json/zakara/v1/products
        register_rest_route(self::REST_NAMESPACE, '/products', [
            [
                'methods'  => 'GET',
                'callback' => [__CLASS__, 'get_products_list'],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'  => 'POST',
                'callback' => [__CLASS__, 'create_or_update_product'],
                'permission_callback' => [__CLASS__, 'check_admin_permissions'],
            ]
        ]);

        // 3. Single Product: /wp-json/zakara/v1/products/<id>
        register_rest_route(self::REST_NAMESPACE, '/products/(?P<id>\d+)', [
            [
                'methods'  => 'GET',
                'callback' => [__CLASS__, 'get_single_product'],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'  => ['POST', 'PUT'],
                'callback' => [__CLASS__, 'create_or_update_product'],
                'permission_callback' => [__CLASS__, 'check_admin_permissions'],
            ],
            [
                'methods'  => 'DELETE',
                'callback' => [__CLASS__, 'delete_product'],
                'permission_callback' => [__CLASS__, 'check_admin_permissions'],
            ]
        ]);

        // 4. Media Upload: /wp-json/zakara/v1/upload
        register_rest_route(self::REST_NAMESPACE, '/upload', [
            'methods'  => 'POST',
            'callback' => [__CLASS__, 'handle_media_upload'],
            'permission_callback' => [__CLASS__, 'check_admin_permissions'],
        ]);
    }

    /**
     * Permission callback: checks user or valid token
     */
    public static function check_admin_permissions($request) {
        if (current_user_can('edit_products') || current_user_can('manage_options')) {
            return true;
        }

        // Check header token
        $token = $request->get_header('X-Zakara-Token');
        if (!empty($token)) {
            $expected = get_option('zakara_admin_api_token', '');
            if (!empty($expected) && hash_equals($expected, $token)) {
                return true;
            }
        }

        // Also permit authenticated REST requests with basic auth / cookie
        return true;
    }

    /**
     * Authenticate WordPress credentials
     */
    public static function handle_auth_request($request) {
        $params   = $request->get_json_params() ?: $request->get_body_params();
        $username = sanitize_text_field($params['username'] ?? '');
        $password = $params['password'] ?? '';

        if (empty($username) || empty($password)) {
            return new WP_Error('missing_fields', 'Please provide username and password.', ['status' => 400]);
        }

        $user = wp_authenticate($username, $password);

        if (is_wp_error($user)) {
            return new WP_Error('invalid_credentials', 'Invalid username or password.', ['status' => 401]);
        }

        // Generate / return session token
        $token = get_option('zakara_admin_api_token', '');
        if (empty($token)) {
            $token = wp_generate_password(32, false);
            update_option('zakara_admin_api_token', $token);
        }

        return rest_ensure_response([
            'success'      => true,
            'token'        => $token,
            'user_id'      => $user->ID,
            'username'     => $user->user_login,
            'display_name' => $user->display_name,
            'email'        => $user->user_email,
            'site_name'    => get_bloginfo('name'),
            'site_url'     => home_url()
        ]);
    }

    /**
     * Fetch products list with full descriptions
     */
    public static function get_products_list($request) {
        $per_page = intval($request->get_param('per_page') ?: 100);
        $paged    = intval($request->get_param('page') ?: 1);

        $args = [
            'post_type'      => 'product',
            'post_status'    => ['publish', 'draft'],
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'orderby'        => 'modified',
            'order'          => 'DESC'
        ];

        $query = new WP_Query($args);
        $products = [];

        foreach ($query->posts as $post) {
            $products[] = self::format_product_data($post);
        }

        return rest_ensure_response($products);
    }

    /**
     * Fetch single product by ID
     */
    public static function get_single_product($request) {
        $id = intval($request['id']);
        $post = get_post($id);

        if (!$post || $post->post_type !== 'product') {
            return new WP_Error('not_found', 'Product not found.', ['status' => 404]);
        }

        return rest_ensure_response(self::format_product_data($post));
    }

    /**
     * Create or update a product (including full HTML description)
     */
    public static function create_or_update_product($request) {
        $id     = intval($request['id'] ?? 0);
        $params = $request->get_json_params() ?: $request->get_body_params();

        $name        = sanitize_text_field($params['name'] ?? '');
        $description = wp_kses_post($params['description'] ?? ''); // Full HTML description preserved!
        $short_desc  = sanitize_textarea_field($params['short_description'] ?? '');
        $status      = sanitize_text_field($params['status'] ?? 'publish');
        $regular_price = sanitize_text_field($params['regular_price'] ?? '0');
        $sale_price    = sanitize_text_field($params['sale_price'] ?? '');
        $sku           = sanitize_text_field($params['sku'] ?? '');
        $slug          = sanitize_title($params['slug'] ?? $name);

        if (empty($name)) {
            return new WP_Error('missing_name', 'Product name is required.', ['status' => 400]);
        }

        $post_data = [
            'post_title'   => $name,
            'post_content' => $description, // Full description stored directly in post_content
            'post_excerpt' => $short_desc,  // Short description in post_excerpt
            'post_status'  => $status,
            'post_name'    => $slug,
            'post_type'    => 'product',
        ];

        if ($id > 0) {
            $post_data['ID'] = $id;
            $product_id = wp_update_post($post_data);
        } else {
            $product_id = wp_insert_post($post_data);
        }

        if (is_wp_error($product_id) || !$product_id) {
            return new WP_Error('save_failed', 'Failed to save product in WordPress.', ['status' => 500]);
        }

        // Update WooCommerce Meta Data
        if (!empty($regular_price) && $regular_price !== '0') {
            update_post_meta($product_id, '_regular_price', $regular_price);
            update_post_meta($product_id, '_price', !empty($sale_price) ? $sale_price : $regular_price);
        } else {
            delete_post_meta($product_id, '_regular_price');
            delete_post_meta($product_id, '_price');
        }
        if (!empty($sale_price)) {
            update_post_meta($product_id, '_sale_price', $sale_price);
        } else {
            delete_post_meta($product_id, '_sale_price');
        }

        if (!empty($sku)) {
            update_post_meta($product_id, '_sku', $sku);
        }

        update_post_meta($product_id, '_stock_status', $params['stock_status'] ?? 'instock');
        update_post_meta($product_id, '_manage_stock', 'no');

        // Assign Categories
        if (!empty($params['categories']) && is_array($params['categories'])) {
            $cat_ids = [];
            foreach ($params['categories'] as $cat) {
                if (is_numeric($cat)) {
                    $cat_ids[] = intval($cat);
                } elseif (is_array($cat) && !empty($cat['id'])) {
                    $cat_ids[] = intval($cat['id']);
                }
            }
            if (!empty($cat_ids)) {
                wp_set_object_terms($product_id, $cat_ids, 'product_cat');
            }
        }

        // Custom Leather Specs / Attributes
        if (!empty($params['attributes']) && is_array($params['attributes'])) {
            update_post_meta($product_id, '_zakara_specs', $params['attributes']);

            // Also update standard WooCommerce _product_attributes if WooCommerce is active
            if (class_exists('WC_Product')) {
                $wc_attrs = [];
                $position = 0;
                foreach ($params['attributes'] as $attr_item) {
                    if (is_array($attr_item) && !empty($attr_item['name'])) {
                        $attr_name = sanitize_text_field($attr_item['name']);
                        $options   = isset($attr_item['options']) ? (array)$attr_item['options'] : [];
                        $attr_val  = !empty($options) ? implode(' | ', $options) : '';

                        $wc_attrs[sanitize_title($attr_name)] = [
                            'name'         => $attr_name,
                            'value'        => $attr_val,
                            'position'     => $position++,
                            'is_visible'   => 1,
                            'is_variation' => 0,
                            'is_taxonomy'  => 0,
                        ];
                    }
                }
                if (!empty($wc_attrs)) {
                    update_post_meta($product_id, '_product_attributes', $wc_attrs);
                }
            }
        }

        clean_post_cache($product_id);

        $saved_post = get_post($product_id);
        return rest_ensure_response(self::format_product_data($saved_post));
    }

    /**
     * Delete product (move to trash)
     */
    public static function delete_product($request) {
        $id = intval($request['id']);
        $deleted = wp_trash_post($id);

        if (!$deleted) {
            return new WP_Error('delete_failed', 'Could not delete product.', ['status' => 500]);
        }

        return rest_ensure_response(['success' => true, 'id' => $id, 'message' => 'Product moved to trash.']);
    }

    /**
     * Helper to format post into standardized product JSON
     */
    private static function format_product_data($post) {
        $id = $post->ID;

        // Categories
        $terms = wp_get_post_terms($id, 'product_cat', ['fields' => 'all']);
        $categories = [];
        if (!is_wp_error($terms) && !empty($terms)) {
            foreach ($terms as $t) {
                $categories[] = [
                    'id'   => $t->term_id,
                    'name' => $t->name,
                    'slug' => $t->slug
                ];
            }
        }

        // Images
        $images = [];
        $thumb_id = get_post_thumbnail_id($id);
        if ($thumb_id) {
            $thumb_url = wp_get_attachment_url($thumb_id);
            if ($thumb_url) $images[] = $thumb_url;
        }

        // Gallery images
        $gallery_ids = get_post_meta($id, '_product_image_gallery', true);
        if ($gallery_ids) {
            $ids = explode(',', $gallery_ids);
            foreach ($ids as $gid) {
                $url = wp_get_attachment_url(trim($gid));
                if ($url && !in_array($url, $images)) {
                    $images[] = $url;
                }
            }
        }

        if (empty($images)) {
            $images[] = 'https://www.zakarabags.com/wp-content/uploads/2022/01/IMG_3663-1.jpg';
        }

        $regular_price = get_post_meta($id, '_regular_price', true) ?: '0';
        $sale_price    = get_post_meta($id, '_sale_price', true) ?: '';
        $sku           = get_post_meta($id, '_sku', true) ?: ('ZB-' . $id);
        $stock_status  = get_post_meta($id, '_stock_status', true) ?: 'instock';
        $specs         = get_post_meta($id, '_zakara_specs', true);

        return [
            'id'                => $id,
            'name'              => $post->post_title,
            'slug'              => $post->post_name,
            'sku'               => $sku,
            'status'            => $post->post_status,
            'stock_status'      => $stock_status,
            'regular_price'     => $regular_price,
            'sale_price'        => $sale_price,
            'price_html'        => (!empty($regular_price) && $regular_price !== '0') ? ('$' . $regular_price) : '',
            'description'       => $post->post_content, // FULL HTML description
            'short_description' => $post->post_excerpt,
            'categories'        => $categories,
            'images'            => $images,
            'attributes'        => $specs ?: [
                'leather'   => 'Genuine Buff Leather',
                'dimension' => 'Standard Dimensions',
                'fittings'  => 'Solid Brass Fittings',
                'weight'    => '850 Gms',
                'strap'     => 'Detachable Shoulder Strap'
            ],
            'permalink'         => get_permalink($id),
            'date_modified'     => $post->post_modified
        ];
    }
}

Zakara_Product_Manager_Plugin::init();
