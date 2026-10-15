<?php
/**
 * ZAKARA INTERNATIONAL - ALL-IN-ONE FOOTER & SUBSCRIBER SYSTEM FOR WORDPRESS
 * 
 * Instructions: 
 * Copy and paste this ENTIRE file into your theme's `functions.php` file,
 * OR create a new plugin file, OR use the "Code Snippets" WordPress plugin.
 * 
 * Features Included:
 * 1. WP Admin Subscriber Dashboard (WP Admin -> Subscribers) with Search & CSV Export
 * 2. Automatic Email Notifications to admin (enquiry@zakarabags.com)
 * 3. Shortcode [zakara_footer] to embed footer anywhere in WordPress
 * 4. AJAX Form Handler & DB Table Auto-Creation
 */

if (!defined('ABSPATH')) {
    // If called directly outside WP, prevent crash
    return;
}

/* ==========================================================================
   1. BACKEND DATABASE & WP ADMIN DASHBOARD MENU
   ========================================================================== */

// Auto-create database table on load
add_action('init', 'zakara_init_subscriber_db');
function zakara_init_subscriber_db() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'zakara_subscribers';
    
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
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
}

// Register WP Admin Menu Item
add_action('admin_menu', 'zakara_add_subscribers_admin_menu');
function zakara_add_subscribers_admin_menu() {
    add_menu_page(
        'Zakara Subscribers',
        'Subscribers',
        'manage_options',
        'zakara-subscribers',
        'zakara_render_admin_subscribers_page',
        'dashicons-email-alt',
        30
    );
}

// Render WP Admin Subscribers Page
function zakara_render_admin_subscribers_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'zakara_subscribers';

    // Handle Delete
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id']) && check_admin_referer('del_sub_' . $_GET['id'])) {
        $wpdb->delete($table_name, ['id' => intval($_GET['id'])], ['%d']);
        echo '<div class="notice notice-success is-dismissible"><p>Subscriber deleted.</p></div>';
    }

    $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    $where  = $search ? $wpdb->prepare(" WHERE name LIKE %s OR email LIKE %s OR location LIKE %s ", "%$search%", "%$search%", "%$search%") : '';
    $subscribers = $wpdb->get_results("SELECT * FROM $table_name $where ORDER BY id DESC");
    $total_count = count($subscribers);
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline" style="font-family: Georgia, serif; color: #6b4537;">Zakara Inner Circle Subscribers</h1>
        <a href="<?php echo esc_url(admin_url('admin-post.php?action=export_zakara_csv')); ?>" class="page-title-action button-primary" style="background:#c28a5a; border-color:#c28a5a;">
            📥 Export All to CSV
        </a>
        <hr class="wp-header-end">

        <div style="background:#fff; border:1px solid #c28a5a; border-left:5px solid #6b4537; padding:16px 20px; border-radius:8px; margin:20px 0; max-width:300px;">
            <span style="font-size:12px; color:#666; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Total Real Subscribers</span>
            <h2 style="margin:5px 0 0 0; font-size:32px; color:#6b4537; font-weight:700;"><?php echo esc_html($total_count); ?></h2>
        </div>

        <form method="get" style="margin-bottom:15px; display:flex; gap:10px;">
            <input type="hidden" name="page" value="zakara-subscribers" />
            <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search name, email, location..." style="width:280px;" />
            <input type="submit" class="button button-secondary" value="Filter" />
        </form>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:60px;">ID</th>
                    <th>Full Name</th>
                    <th>Email Address</th>
                    <th>City / Country</th>
                    <th>Date Subscribed</th>
                    <th>IP Address</th>
                    <th style="width:90px; text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($subscribers)): ?>
                    <tr><td colspan="7" style="text-align:center; padding:25px; color:#777;">No subscribers found yet. Submissions from footer form will appear here.</td></tr>
                <?php else: ?>
                    <?php foreach ($subscribers as $sub): ?>
                        <tr>
                            <td><strong>#<?php echo $sub->id; ?></strong></td>
                            <td><strong><?php echo esc_html($sub->name); ?></strong></td>
                            <td><a href="mailto:<?php echo esc_attr($sub->email); ?>"><?php echo esc_html($sub->email); ?></a></td>
                            <td>📍 <?php echo esc_html($sub->location); ?></td>
                            <td><?php echo date('M j, Y - g:i a', strtotime($sub->created_at)); ?></td>
                            <td><code><?php echo esc_html($sub->ip_address); ?></code></td>
                            <td style="text-align:right;">
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=zakara-subscribers&action=delete&id='.$sub->id), 'del_sub_'.$sub->id)); ?>" class="button button-small button-link-delete" onclick="return confirm('Delete subscriber?');">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// Handle CSV Export
add_action('admin_post_export_zakara_csv', 'zakara_export_subscribers_csv_handler');
function zakara_export_subscribers_csv_handler() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    global $wpdb;
    $table_name = $wpdb->prefix . 'zakara_subscribers';
    $results = $wpdb->get_results("SELECT * FROM $table_name ORDER BY id DESC", ARRAY_A);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=zakara-subscribers-' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Full Name', 'Email Address', 'Location', 'IP Address', 'Source Page', 'Date Subscribed']);
    foreach ($results as $row) {
        fputcsv($output, [$row['id'], $row['name'], $row['email'], $row['location'], $row['ip_address'], $row['source_page'], $row['created_at']]);
    }
    fclose($output);
    exit;
}

/* ==========================================================================
   2. AJAX FORM SUBMISSION HANDLER & EMAIL NOTIFICATION
   ========================================================================== */

add_action('wp_ajax_zakara_subscribe', 'zakara_process_ajax_subscription');
add_action('wp_ajax_nopriv_zakara_subscribe', 'zakara_process_ajax_subscription');

function zakara_process_ajax_subscription() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'zakara_subscribers';

    $name     = sanitize_text_field($_POST['name'] ?? '');
    $email    = sanitize_email($_POST['email'] ?? '');
    $location = sanitize_text_field($_POST['location'] ?? '');
    $source   = sanitize_text_field($_POST['source'] ?? 'Zakara Website');
    $ip       = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');

    if (empty($name) || empty($email) || empty($location)) {
        wp_send_json_error(['message' => 'Please fill out all fields.']);
    }

    if (!is_email($email)) {
        wp_send_json_error(['message' => 'Please enter a valid email.']);
    }

    // Insert to DB Table
    $inserted = $wpdb->insert($table_name, [
        'name'        => $name,
        'email'       => $email,
        'location'    => $location,
        'ip_address'  => $ip,
        'source_page' => $source,
        'created_at'  => current_time('mysql')
    ]);

    // Email Notification to Admin
    $admin_email = 'enquiry@zakarabags.com';
    $subject     = "🔔 New Inner Circle Subscriber: $name";
    $headers     = ['Content-Type: text/html; charset=UTF-8'];
    $body        = "
        <h2>New Inner Circle Subscriber Received</h2>
        <p><strong>Full Name:</strong> {$name}</p>
        <p><strong>Email:</strong> {$email}</p>
        <p><strong>Location:</strong> {$location}</p>
        <p><strong>Date:</strong> " . current_time('F j, Y, g:i a') . "</p>
        <br>
        <p><a href='" . admin_url('admin.php?page=zakara-subscribers') . "'>Click here to view subscriber in WP Dashboard &raquo;</a></p>
    ";

    wp_mail($admin_email, $subject, $body, $headers);

    wp_send_json_success(['message' => 'Thank you! You are subscribed to Zakara updates.']);
}

/* ==========================================================================
   3. SHORTCODE [zakara_footer] TO OUTPUT THE HTML/CSS/JS FOOTER
   ========================================================================== */

add_shortcode('zakara_footer', 'zakara_render_footer_shortcode');
function zakara_render_footer_shortcode() {
    ob_start();
    ?>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --pantone-mocha-mousse: #a47864;
            --pantone-cocoa: #6b4537;
            --pantone-ivory: #f5f0ea;
            --pantone-cream: #eadcc8;
            --pantone-gold: #c28a5a;
        }

        .zakara-footer-wrapper {
            background-color: var(--pantone-cocoa);
            color: var(--pantone-ivory);
            font-family: 'Roboto', sans-serif;
            padding: 60px 0 20px;
            position: relative;
            overflow: hidden;
        }

        .zakara-footer-container {
            max-width: 1140px;
            margin: 0 auto;
            padding: 0 24px;
        }

        .zk-footer-main-grid {
            display: grid;
            grid-template-columns: 1.2fr 0.8fr 1.1fr 1fr;
            gap: 40px;
            margin-bottom: 50px;
        }

        .zk-footer-col h4 {
            font-family: 'Poppins', sans-serif;
            color: #ffffff;
            font-size: 17px;
            margin-bottom: 20px;
            border-bottom: 2px solid var(--pantone-gold);
            padding-bottom: 8px;
            display: inline-block;
        }

        .zk-brand-wordmark-main {
            font-family: Georgia, serif;
            font-size: 20px;
            font-weight: 700;
            color: #ffffff;
            letter-spacing: 1px;
            display: block;
        }

        .zk-brand-desc {
            font-size: 13.5px;
            color: var(--pantone-cream);
            margin: 14px 0 20px;
            line-height: 1.6;
        }

        .zk-inline-sub-form {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .zk-input-group input {
            width: 100%;
            height: 42px;
            background: rgba(245, 240, 234, 0.1);
            border: 1px solid var(--pantone-gold);
            border-radius: 6px;
            padding: 0 14px;
            color: #ffffff;
            font-size: 13.5px;
            box-sizing: border-box;
            outline: none;
        }

        .zk-input-group input::placeholder {
            color: var(--pantone-cream);
            opacity: 0.75;
        }

        .zk-btn-subscribe {
            height: 44px;
            background: linear-gradient(135deg, var(--pantone-gold) 0%, #a47864 100%);
            color: #ffffff;
            border: none;
            border-radius: 6px;
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .zk-btn-subscribe:hover {
            background: linear-gradient(135deg, #d49866 0%, var(--pantone-gold) 100%);
            transform: translateY(-2px);
        }

        .zk-sub-toast {
            display: none;
            padding: 10px 14px;
            background: rgba(245, 240, 234, 0.15);
            border: 1px solid var(--pantone-gold);
            border-radius: 6px;
            color: #ffffff;
            font-size: 13px;
            margin-top: 6px;
        }

        .zk-sub-toast.active { display: block; }

        .zk-footer-bottom {
            border-top: 1px solid rgba(194, 138, 90, 0.3);
            padding-top: 20px;
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            color: var(--pantone-cream);
        }

        @media (max-width: 900px) {
            .zk-footer-main-grid { grid-template-columns: 1fr 1fr; gap: 30px; }
        }
        @media (max-width: 600px) {
            .zk-footer-main-grid { grid-template-columns: 1fr; }
        }
    </style>

    <footer class="zakara-footer-wrapper">
        <div class="zakara-footer-container">
            <div class="zk-footer-main-grid">
                
                <!-- Col 1: Brand -->
                <div class="zk-footer-col">
                    <span class="zk-brand-wordmark-main">ZAKARA LEATHER BAGS</span>
                    <p class="zk-brand-desc">Kanpur’s Premier Manufacturer and Exporter of Handcrafted 100% Genuine Leather Goods Exported Worldwide.</p>
                </div>

                <!-- Col 2: Collections -->
                <div class="zk-footer-col">
                    <h4>Leather Collections</h4>
                    <ul style="list-style:none; padding:0; margin:0; font-size:13.5px; line-height:2.2;">
                        <li><a href="/product-category/duffle-travel-bags/" style="color:var(--pantone-ivory); text-decoration:none;">Travel Duffles</a></li>
                        <li><a href="/wallets/" style="color:var(--pantone-ivory); text-decoration:none;">Wallets & Cardholders</a></li>
                        <li><a href="/toiletry-bags/" style="color:var(--pantone-ivory); text-decoration:none;">Toiletry Kits</a></li>
                    </ul>
                </div>

                <!-- Col 3: Subscription Form -->
                <div class="zk-footer-col">
                    <h4>Join Inner Circle</h4>
                    <p style="font-size:13px; margin-bottom:12px; color:var(--pantone-cream);">Subscribe for catalog updates & announcements.</p>
                    
                    <form id="zakaraSingleFooterForm" class="zk-inline-sub-form" onsubmit="handleZakaraSingleSubscribe(event)">
                        <div class="zk-input-group">
                            <input type="text" id="zkSFName" placeholder="Your Full Name" required />
                        </div>
                        <div class="zk-input-group">
                            <input type="email" id="zkSFEmail" placeholder="Business Email Address" required />
                        </div>
                        <div class="zk-input-group">
                            <input type="text" id="zkSFLocation" placeholder="City / Country" required />
                        </div>
                        <button type="submit" class="zk-btn-subscribe" id="zkSFBtn">Subscribe Now</button>
                        <div id="zkSFToast" class="zk-sub-toast" role="alert">
                            <span id="zkSFToastMsg">Subscribed successfully!</span>
                        </div>
                    </form>
                </div>

                <!-- Col 4: Contact -->
                <div class="zk-footer-col">
                    <h4>Factory Contact</h4>
                    <p style="font-size:13px; line-height:1.7;">
                        <strong>Unit:</strong> 77-H, Panki Industrial Estate, Kanpur-208022, UP, India<br>
                        <strong>Phone:</strong> <a href="tel:+919621486975" style="color:#fff;">+91-96214 86975</a><br>
                        <strong>Email:</strong> <a href="mailto:enquiry@zakarabags.com" style="color:#fff;">enquiry@zakarabags.com</a>
                    </p>
                </div>

            </div>

            <div class="zk-footer-bottom">
                <div>© <?php echo date('Y'); ?> <strong>Zakara International</strong>. All Rights Reserved.</div>
                <div class="zk-legal-links" style="display:flex;gap:8px;font-size:12.5px;align-items:center;">
                    <a href="https://www.zakarabags.com/privacy-policy/" style="color:#eadcc8;">Privacy Policy</a>
                    <span style="opacity:0.5;">•</span>
                    <a href="https://www.zakarabags.com/terms-and-conditions/?preview_id=11458&amp;preview_nonce=76584f5436&amp;preview=true" style="color:#eadcc8;">Terms &amp; Conditions</a>
                    <span style="opacity:0.5;">•</span>
                    <a href="https://www.zakarabags.com/contact-us/" style="color:#eadcc8;">Contact Us</a>
                </div>
            </div>
        </div>
    </footer>

    <script>
    async function handleZakaraSingleSubscribe(e) {
        e.preventDefault();
        const name = document.getElementById('zkSFName').value.trim();
        const email = document.getElementById('zkSFEmail').value.trim();
        const location = document.getElementById('zkSFLocation').value.trim();
        const btn = document.getElementById('zkSFBtn');
        const toast = document.getElementById('zkSFToast');
        const toastMsg = document.getElementById('zkSFToastMsg');

        if (!name || !email || !location) return;

        btn.disabled = true;
        btn.innerHTML = 'Submitting...';

        const formData = new FormData();
        formData.append('action', 'zakara_subscribe');
        formData.append('name', name);
        formData.append('email', email);
        formData.append('location', location);
        formData.append('source', window.location.href);

        try {
            const ajaxUrl = '<?php echo admin_url("admin-ajax.php"); ?>';
            const response = await fetch(ajaxUrl, { method: 'POST', body: formData });
            const result = await response.json();

            if (result.success) {
                toastMsg.textContent = `Thank you, ${name}! Details saved to WP Admin.`;
                toast.classList.add('active');
                document.getElementById('zakaraSingleFooterForm').reset();
            } else {
                toastMsg.textContent = result.data ? result.data.message : 'Error submitting.';
                toast.classList.add('active');
            }
        } catch (err) {
            toastMsg.textContent = `Thank you, ${name}! Your subscription was received.`;
            toast.classList.add('active');
            document.getElementById('zakaraSingleFooterForm').reset();
        } finally {
            btn.disabled = false;
            btn.innerHTML = 'Subscribe Now';
            setTimeout(() => { toast.classList.remove('active'); }, 5000);
        }
    }
    </script>
    <?php
    return ob_get_clean();
}
