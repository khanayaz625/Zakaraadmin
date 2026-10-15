<?php
/**
 * Plugin Name: Zakara Newsletter Subscribers Manager
 * Plugin URI:  https://zakarabags.com
 * Description: Captures real newsletter subscribers from footer forms, stores them in WP Admin database with search/export, and sends instant admin email notifications.
 * Version:     1.0.0
 * Author:      Zakara International
 * License:     GPL2
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class Zakara_Subscribers_Plugin {

    private static $table_name;

    public static function init() {
        global $wpdb;
        self::$table_name = $wpdb->prefix . 'zakara_subscribers';

        // Activation Hook to create DB table
        register_activation_hook(__FILE__, [__CLASS__, 'create_table']);

        // Register Admin Menu in WP Dashboard
        add_action('admin_menu', [__CLASS__, 'register_admin_menu']);

        // Register AJAX Endpoints (For Logged in & Guest Users)
        add_action('wp_ajax_zakara_subscribe', [__CLASS__, 'handle_subscribe_ajax']);
        add_action('wp_ajax_nopriv_zakara_subscribe', [__CLASS__, 'handle_subscribe_ajax']);

        // Handle CSV Export
        add_action('admin_post_export_zakara_subscribers_csv', [__CLASS__, 'export_subscribers_csv']);
    }

    /**
     * Create custom database table on plugin activation
     */
    public static function create_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'zakara_subscribers';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            name varchar(191) NOT NULL,
            email varchar(191) NOT NULL,
            location varchar(191) NOT NULL,
            ip_address varchar(45) NOT NULL,
            source_page varchar(255) DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY email (email)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Register WP Admin Dashboard Menu: "Zakara Subscribers"
     */
    public static function register_admin_menu() {
        add_menu_page(
            'Zakara Subscribers',            // Page Title
            'Subscribers',                     // Menu Title
            'manage_options',                  // Capability
            'zakara-subscribers',              // Menu Slug
            [__CLASS__, 'render_admin_page'],  // Callback
            'dashicons-email-alt',             // Icon
            30                                 // Position
        );
    }

    /**
     * Handle Newsletter Subscription AJAX Submission
     */
    public static function handle_subscribe_ajax() {
        global $wpdb;
        self::$table_name = $wpdb->prefix . 'zakara_subscribers';

        // Read POST parameters or JSON body
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            $input = $_POST;
        }

        $name     = sanitize_text_field($input['name'] ?? '');
        $email    = sanitize_email($input['email'] ?? '');
        $location = sanitize_text_field($input['location'] ?? '');
        $source   = sanitize_text_field($input['source'] ?? 'Zakara Website');
        $ip       = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');

        if (empty($name) || empty($email) || empty($location)) {
            wp_send_json_error(['message' => 'Please fill in all required fields.']);
        }

        if (!is_email($email)) {
            wp_send_json_error(['message' => 'Please enter a valid email address.']);
        }

        // 1. Insert into Database Table
        $inserted = $wpdb->insert(
            self::$table_name,
            [
                'name'        => $name,
                'email'       => $email,
                'location'    => $location,
                'ip_address'  => $ip,
                'source_page' => $source,
                'created_at'  => current_time('mysql')
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );

        // 2. Send Admin Email Notification
        $admin_email = get_option('admin_email', 'enquiry@zakarabags.com');
        $subject     = "🔔 New Inner Circle Subscriber: $name";
        $headers     = ['Content-Type: text/html; charset=UTF-8'];
        $body        = "
            <h2>New Newsletter Subscription Received</h2>
            <p><strong>Full Name:</strong> {$name}</p>
            <p><strong>Email Address:</strong> {$email}</p>
            <p><strong>Location:</strong> {$location}</p>
            <p><strong>Date & Time:</strong> " . current_time('F j, Y, g:i a') . "</p>
            <p><strong>Source Page:</strong> {$source}</p>
            <hr>
            <p><a href='" . admin_url('admin.php?page=zakara-subscribers') . "'>View all subscribers in WP Dashboard &raquo;</a></p>
        ";

        wp_mail($admin_email, $subject, $body, $headers);

        if ($inserted) {
            wp_send_json_success(['message' => 'Thank you! You are subscribed to Zakara updates.']);
        } else {
            wp_send_json_error(['message' => 'Database error. Please try again.']);
        }
    }

    /**
     * Render WP Admin Page Layout & Data Table
     */
    public static function render_admin_page() {
        global $wpdb;
        self::$table_name = $wpdb->prefix . 'zakara_subscribers';

        // Check DB Table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '" . self::$table_name . "'") != self::$table_name) {
            self::create_table();
        }

        // Handle Delete Action
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id']) && check_admin_referer('delete_subscriber_' . $_GET['id'])) {
            $id = intval($_GET['id']);
            $wpdb->delete(self::$table_name, ['id' => $id], ['%d']);
            echo '<div class="notice notice-success is-dismissible"><p>Subscriber deleted successfully.</p></div>';
        }

        // Search Filter
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $where  = '';
        if (!empty($search)) {
            $where = $wpdb->prepare(" WHERE name LIKE %s OR email LIKE %s OR location LIKE %s ", "%$search%", "%$search%", "%$search%");
        }

        // Query Subscribers List
        $subscribers = $wpdb->get_results("SELECT * FROM " . self::$table_name . $where . " ORDER BY id DESC");
        $total_count = count($subscribers);
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Zakara Newsletter Subscribers</h1>
            
            <a href="<?php echo esc_url(admin_url('admin-post.php?action=export_zakara_subscribers_csv')); ?>" class="page-title-action button-primary">
                📥 Export All to CSV
            </a>
            <hr class="wp-header-end">

            <!-- Stat Summary Cards -->
            <div style="display: flex; gap: 20px; margin: 20px 0;">
                <div style="background: #fff; border: 1px solid #ccc; border-left: 4px solid #6b4537; padding: 15px 20px; border-radius: 6px; min-width: 200px;">
                    <span style="font-size: 13px; color: #666; font-weight: 600; text-transform: uppercase;">Total Inner Circle Subscribers</span>
                    <h2 style="margin: 5px 0 0 0; font-size: 32px; color: #6b4537; font-weight: 700;"><?php echo esc_html($total_count); ?></h2>
                </div>
            </div>

            <!-- Search Form -->
            <form method="get" style="margin-bottom: 15px; display: flex; gap: 10px; align-items: center;">
                <input type="hidden" name="page" value="zakara-subscribers" />
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search name, email, country..." style="width: 280px;" />
                <input type="submit" class="button button-secondary" value="Filter Subscribers" />
                <?php if ($search): ?>
                    <a href="<?php echo admin_url('admin.php?page=zakara-subscribers'); ?>" class="button">Reset</a>
                <?php endif; ?>
            </form>

            <!-- Data Table -->
            <table class="wp-list-table widefat fixed striped table-view-list">
                <thead>
                    <tr>
                        <th style="width: 50px;">ID</th>
                        <th>Full Name</th>
                        <th>Email Address</th>
                        <th>City / Country</th>
                        <th>Date Subscribed</th>
                        <th>IP Address</th>
                        <th style="width: 100px; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($subscribers)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 25px; color: #666;">
                                No subscribers found yet. When users submit the footer form, their details will appear here.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($subscribers as $sub): ?>
                            <tr>
                                <td><strong>#<?php echo esc_html($sub->id); ?></strong></td>
                                <td><strong><?php echo esc_html($sub->name); ?></strong></td>
                                <td><a href="mailto:<?php echo esc_attr($sub->email); ?>"><?php echo esc_html($sub->email); ?></a></td>
                                <td><span class="dashicons dashicons-location" style="font-size:16px; width:16px; height:16px; vertical-align:middle; color:#6b4537;"></span> <?php echo esc_html($sub->location); ?></td>
                                <td><?php echo esc_html(date('M j, Y - g:i a', strtotime($sub->created_at))); ?></td>
                                <td><code><?php echo esc_html($sub->ip_address); ?></code></td>
                                <td style="text-align: right;">
                                    <?php 
                                    $delete_url = wp_nonce_url(
                                        admin_url('admin.php?page=zakara-subscribers&action=delete&id=' . $sub->id),
                                        'delete_subscriber_' . $sub->id
                                    ); 
                                    ?>
                                    <a href="<?php echo esc_url($delete_url); ?>" class="button button-small button-link-delete" onclick="return confirm('Delete this subscriber?');">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Download CSV File of All Subscribers
     */
    public static function export_subscribers_csv() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized user.');
        }

        global $wpdb;
        self::$table_name = $wpdb->prefix . 'zakara_subscribers';
        $subscribers = $wpdb->get_results("SELECT * FROM " . self::$table_name . " ORDER BY id DESC", ARRAY_A);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=zakara-subscribers-' . date('Y-m-d') . '.csv');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'Full Name', 'Email Address', 'Location', 'IP Address', 'Source Page', 'Date Subscribed']);

        if (!empty($subscribers)) {
            foreach ($subscribers as $row) {
                fputcsv($output, [
                    $row['id'],
                    $row['name'],
                    $row['email'],
                    $row['location'],
                    $row['ip_address'],
                    $row['source_page'],
                    $row['created_at']
                ]);
            }
        }
        fclose($output);
        exit;
    }
}

// Initialize Plugin
Zakara_Subscribers_Plugin::init();
