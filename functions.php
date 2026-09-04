<?php
// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

// BEGIN ENQUEUE PARENT ACTION
// AUTO GENERATED - Do not modify or remove comment markers above or below:

if ( !function_exists( 'chld_thm_cfg_locale_css' ) ):
    function chld_thm_cfg_locale_css( $uri ){
        if ( empty( $uri ) && is_rtl() && file_exists( get_template_directory() . '/rtl.css' ) )
            $uri = get_template_directory_uri() . '/rtl.css';
        return $uri;
    }
endif;
add_filter( 'locale_stylesheet_uri', 'chld_thm_cfg_locale_css' );
         
if ( !function_exists( 'child_theme_configurator_css' ) ):
    function child_theme_configurator_css() {
        wp_enqueue_style( 'chld_thm_cfg_child', trailingslashit( get_stylesheet_directory_uri() ) . 'style.css', array( 'rt-animated-headline','rt-animate','rt-magnific-popup','rt-swiper','sbmart-main','elementor-icons-rt-fontello-icons' ) );
    }
endif;
add_action( 'wp_enqueue_scripts', 'child_theme_configurator_css', 10 );

// END ENQUEUE PARENT ACTION


// =========================================================================
// 0. Central Config — দাম / শিপিং / প্রোডাক্ট আইডি একটাই জায়গায়
//    [NEW] আগে দাম ৪ জায়গায় হার্ডকোড ছিল (PHP ১ + JS ৩)। এখন এই ফাইলই
//    single source of truth — ল্যান্ডিং পেজ, JS, lead-convert সবাই এখান থেকেই নেয়।
// =========================================================================

if ( ! defined( 'FITHOME_PRODUCT_ID' ) )         define( 'FITHOME_PRODUCT_ID', 1905 );
if ( ! defined( 'FITHOME_FREE_SHIP_MIN_QTY' ) )  define( 'FITHOME_FREE_SHIP_MIN_QTY', 2 );

/**
 * অনুমোদিত প্যাকেজ ও তাদের মোট দাম (whitelist)।
 * এখানে না থাকলে সেই qty গ্রহণই করা হবে না।
 */
function fithome_get_packages() {
    return array(
        1 => 990,
        2 => 1749,
        3 => 2549,
    );
}

/**
 * qty ভ্যালিড কি না চেক করে দাম রিটার্ন করে, নাহলে false।
 */
function fithome_get_package_price( $qty ) {
    $packages = fithome_get_packages();
    $qty      = intval( $qty );
    return isset( $packages[ $qty ] ) ? $packages[ $qty ] : false;
}

/**
 * ডেলিভারি চার্জের রেট।
 */
function fithome_get_shipping_rates() {
    return array(
        'inside_dhaka'  => 59,
        'outside_dhaka' => 89,
    );
}

/**
 * qty + location অনুযায়ী ফাইনাল ডেলিভারি চার্জ।
 */
function fithome_calc_shipping( $qty, $location ) {
    if ( intval( $qty ) >= FITHOME_FREE_SHIP_MIN_QTY ) {
        return 0;
    }
    $rates = fithome_get_shipping_rates();
    return isset( $rates[ $location ] ) ? $rates[ $location ] : $rates['inside_dhaka'];
}

/**
 * ইংরেজি সংখ্যা → বাংলা সংখ্যা।
 */
function fithome_bn_num( $num ) {
    $en = array( '0','1','2','3','4','5','6','7','8','9' );
    $bn = array( '০','১','২','৩','৪','৫','৬','৭','৮','৯' );
    return str_replace( $en, $bn, (string) $num );
}

/**
 * [FIX] Meta CAPI ম্যাচ কোয়ালিটির জন্য ফোন নম্বর E.164 ফরম্যাটে (8801XXXXXXXXX)।
 *
 * [FIX — ক্রিটিক্যাল] আগে ১০ ডিজিটের কেসটা (শূন্য ছাড়া, যেমন '1712345678')
 * এখানে হ্যান্ডেল করা ছিল না — ফলে '881712345678' হয়ে যেত (শূন্য মিসিং, ভুল E.164)।
 * ল্যান্ডিং পেজ ও থ্যাংক-ইউ পেজে যে fallback লেখা ছিল সেটা কখনোই চলত না,
 * কারণ functions.php আগে লোড হওয়ায় আসল ফাংশন এটাই। এখন ঠিক করা হলো।
 */
function fithome_normalize_phone( $phone ) {
    $digits = preg_replace( '/\D/', '', (string) $phone );
    if ( $digits === '' ) return '';
    if ( strpos( $digits, '880' ) === 0 ) return $digits;
    if ( strpos( $digits, '0' ) === 0 )   return '88' . $digits;
    if ( strlen( $digits ) === 10 && strpos( $digits, '1' ) === 0 ) return '880' . $digits;
    return '88' . $digits;
}

/**
 * ফোন নম্বর ভ্যালিডেশন (১১ ডিজিট BD মোবাইল)।
 */
function fithome_is_valid_phone( $phone ) {
    return (bool) preg_match( '/^01[3-9][0-9]{8}$/', (string) $phone );
}


// =========================================================================
// 1. Buy Now Shortcode
// =========================================================================

// প্রোডাক্ট পেজে "এখনই কিনুন" বাটন — সরাসরি চেকআউটে নিয়ে যায়
add_shortcode('fit_home_buy_now', 'fit_home_custom_buy_now_shortcode');
function fit_home_custom_buy_now_shortcode() {
    $product_id = get_the_ID();
    if (!$product_id) return '';

    $checkout_url = wc_get_checkout_url();
    $buy_now_url = add_query_arg('add-to-cart', $product_id, $checkout_url);

    return '<a href="' . esc_url($buy_now_url) . '" class="custom-buy-now-button" style="display: block; text-align: center; width: 100%; background-color: #ff5722; color: #fff; font-weight: bold; padding: 12px; margin-top: 15px; border-radius: 5px; text-decoration: none; font-size: 16px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); transition: all 0.3s ease;">এখনই কিনুন</a>';
}


// =========================================================================
// 2. Abandoned Cart System
// =========================================================================

// (a) Abandoned Lead CPT রেজিস্টার
add_action('init', 'fit_home_register_abandoned_leads_cpt');
function fit_home_register_abandoned_leads_cpt() {
    register_post_type('abandoned_lead', array(
        'labels'      => array(
            'name'          => 'Abandoned Leads',
            'singular_name' => 'Abandoned Lead',
            'menu_name'     => 'Abandoned Leads'
        ),
        'public'      => false,
        'show_ui'     => true,
        'show_in_menu'=> true,
        'menu_icon'   => 'dashicons-phone',
        'supports'    => array('title', 'editor')
    ));
}

// (b) "Add New" সাবমেনু হাইড
add_action('admin_menu', 'fit_home_remove_abandoned_lead_add_new_sub', 999);
function fit_home_remove_abandoned_lead_add_new_sub() {
    remove_submenu_page('edit.php?post_type=abandoned_lead', 'post-new.php?post_type=abandoned_lead');
}

// (c) ল্যান্ডিং পেজ থেকে AJAX দিয়ে lead সেভ (upsert — আগে থাকলে আপডেট)
add_action('wp_ajax_save_abandoned_cart', 'fit_home_save_abandoned_cart_data');
add_action('wp_ajax_nopriv_save_abandoned_cart', 'fit_home_save_abandoned_cart_data');

function fit_home_save_abandoned_cart_data() {

    // [NOTE] nonce চেক বাদ দেওয়া হয়েছে — ল্যান্ডিং পেজ Cloudflare-এ ক্যাশ হয়, তাই
    // ক্যাশ করা nonce বাসি হয়ে গেলে abandoned cart নীরবে বন্ধ হয়ে যেত।
    // স্প্যাম প্রোটেকশন এখন নিচের ফোন regex + qty/location/product_id whitelist দিয়ে।

    $phone      = isset($_POST['phone'])      ? sanitize_text_field( wp_unslash( $_POST['phone'] ) )      : '';
    $name       = isset($_POST['name'])       ? sanitize_text_field( wp_unslash( $_POST['name'] ) )       : '';
    $address    = isset($_POST['address'])    ? sanitize_text_field( wp_unslash( $_POST['address'] ) )    : '';
    $product_id = isset($_POST['product_id']) ? intval( $_POST['product_id'] )                            : FITHOME_PRODUCT_ID;
    $qty        = isset($_POST['product_qty'])? intval( $_POST['product_qty'] )                           : 1;
    $location   = isset($_POST['location'])   ? sanitize_text_field( wp_unslash( $_POST['location'] ) )   : 'inside_dhaka';

    // [FIX] ফোন নম্বর ভ্যালিড না হলে জাংক lead তৈরি হবে না
    if ( ! fithome_is_valid_phone( $phone ) ) {
        wp_send_json_error('Invalid phone number');
    }

    // [FIX] qty / location whitelist — না মিললে ডিফল্ট
    if ( ! fithome_get_package_price( $qty ) ) {
        $qty = 1;
    }
    $rates = fithome_get_shipping_rates();
    if ( ! isset( $rates[ $location ] ) ) {
        $location = 'inside_dhaka';
    }

    // [FIX] প্রোডাক্ট আইডি স্পুফ করা যেত — এখন শুধু আসল প্রোডাক্টই গ্রহণযোগ্য
    if ( $product_id !== FITHOME_PRODUCT_ID ) {
        $product_id = FITHOME_PRODUCT_ID;
    }

    $content  = "Customer Name: " . ($name !== '' ? $name : 'Unknown') . "\n";
    $content .= "Address: " . $address . "\n";
    $content .= "Product ID: " . $product_id . "\n";
    $content .= "Quantity: " . $qty . "\n";
    $content .= "Location: " . $location . "\n";
    $content .= "Time: " . current_time('mysql');

    $existing_leads = get_posts(array(
        'post_type'   => 'abandoned_lead',
        'title'       => $phone,
        'post_status' => 'publish',
        'numberposts' => 1
    ));

    if (empty($existing_leads)) {
        $lead_data = array(
            'post_title'    => $phone,
            'post_content'  => $content,
            'post_status'   => 'publish',
            'post_type'     => 'abandoned_lead'
        );
        wp_insert_post($lead_data);
        wp_send_json_success('Lead saved successfully!');
    } else {
        wp_update_post(array(
            'ID'           => $existing_leads[0]->ID,
            'post_content' => $content
        ));
        wp_send_json_success('Lead updated successfully!');
    }
}

// (d) Order "processing" এ গেলে matching lead ডিলিট
add_action('woocommerce_order_status_processing', 'fit_home_cleanup_abandoned_lead', 10, 2);
function fit_home_cleanup_abandoned_lead($order_id, $order) {
    $billing_phone = $order->get_billing_phone();

    if (!empty($billing_phone)) {
        $existing_leads = get_posts(array(
            'post_type'   => 'abandoned_lead',
            'title'       => $billing_phone,
            'post_status' => 'publish',
            'numberposts' => -1
        ));

        foreach ($existing_leads as $lead) {
            wp_delete_post($lead->ID, true);
        }
    }
}


// -------------------------------------------------------------------------
// (e) [NEW] পুরনো Abandoned Lead অটো-ক্লিনআপ
//
//     সমস্যা: `get_posts( array( 'title' => $phone ) )` — wp_posts টেবিলে
//     post_title-এ কোনো index নেই, তাই প্রতিটা AJAX কলে full table scan হয়।
//     লিড জমতে জমতে হাজার ছাড়ালে প্রতিটা blur-এ কয়েকশো ms লেগে যায়।
//
//     সমাধান: ৩ দিন পরপর cron চেক করবে — মোট লিড ১০০০ ছাড়ালে সবচেয়ে
//     পুরনোগুলো ডিলিট হয়ে যাবে, সবসময় সর্বশেষ ১০০০টি লিড থাকবে।
// -------------------------------------------------------------------------

if ( ! defined( 'FITHOME_LEAD_KEEP_LIMIT' ) ) define( 'FITHOME_LEAD_KEEP_LIMIT', 1000 );

// কাস্টম cron schedule — প্রতি ৩ দিনে একবার
add_filter( 'cron_schedules', 'fithome_add_three_day_cron_schedule' );
function fithome_add_three_day_cron_schedule( $schedules ) {
    if ( ! isset( $schedules['fithome_three_days'] ) ) {
        $schedules['fithome_three_days'] = array(
            'interval' => 3 * DAY_IN_SECONDS,
            'display'  => 'Every 3 Days (Fit Home Lead Cleanup)'
        );
    }
    return $schedules;
}

// ইভেন্ট শিডিউল (একবারই বসবে)
add_action( 'init', 'fithome_schedule_lead_cleanup' );
function fithome_schedule_lead_cleanup() {
    if ( ! wp_next_scheduled( 'fithome_cleanup_old_leads_event' ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'fithome_three_days', 'fithome_cleanup_old_leads_event' );
    }
}

// আসল ক্লিনআপ — ১০০০-এর বেশি হলে পুরনো লিড ডিলিট
add_action( 'fithome_cleanup_old_leads_event', 'fithome_cleanup_old_abandoned_leads' );
function fithome_cleanup_old_abandoned_leads() {
    global $wpdb;

    $keep_limit = (int) FITHOME_LEAD_KEEP_LIMIT;

    $total_leads = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
        'abandoned_lead',
        'publish'
    ) );

    if ( $total_leads <= $keep_limit ) {
        return;
    }

    $delete_count = $total_leads - $keep_limit;

    $old_lead_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_type = %s AND post_status = %s
         ORDER BY post_date ASC
         LIMIT %d",
        'abandoned_lead',
        'publish',
        $delete_count
    ) );

    if ( empty( $old_lead_ids ) ) {
        return;
    }

    foreach ( $old_lead_ids as $lead_id ) {
        wp_delete_post( (int) $lead_id, true );
    }
}


// =========================================================================
// 3. One-Click Order Conversion
// =========================================================================

// (e) [UPDATED] Abandoned Leads লিস্ট টেবিলে "নাম", "ঠিকানা" কলাম +
// "Convert to Order" বাটন সবসময় visible (আগে এটা post_row_actions দিয়ে
// hover করলে তবেই দেখাত — এখন আলাদা কলাম হওয়ায় সবসময় দেখা যাবে)
add_filter('manage_edit-abandoned_lead_columns', 'fit_home_abandoned_lead_columns');
function fit_home_abandoned_lead_columns($columns) {
    $new_columns = array();
    foreach ($columns as $key => $label) {
        $new_columns[$key] = $label;
        if ($key === 'title') {
            $new_columns['abandoned_name']    = 'নাম';
            $new_columns['abandoned_address'] = 'ঠিকানা';
            $new_columns['abandoned_convert'] = 'Action';
        }
    }
    return $new_columns;
}

add_action('manage_abandoned_lead_posts_custom_column', 'fit_home_render_abandoned_lead_columns', 10, 2);
function fit_home_render_abandoned_lead_columns($column, $post_id) {

    if ($column === 'abandoned_name') {
        $content = get_post_field('post_content', $post_id);
        preg_match('/Customer Name:\s*(.*)/', $content, $m);
        echo isset($m[1]) && trim($m[1]) !== '' ? esc_html(trim($m[1])) : '<span style="color:#aaa;">—</span>';
    }

    if ($column === 'abandoned_address') {
        $content = get_post_field('post_content', $post_id);
        preg_match('/Address:\s*(.*)/', $content, $m);
        echo isset($m[1]) && trim($m[1]) !== '' ? esc_html(trim($m[1])) : '<span style="color:#aaa;">—</span>';
    }

    if ($column === 'abandoned_convert') {
        $convert_url = wp_nonce_url(
            admin_url('admin-post.php?action=convert_abandoned_lead&post_id=' . $post_id),
            'convert_lead_nonce'
        );
        echo '<a href="' . esc_url($convert_url) . '"
                onclick="return confirm(\'এই লিডটি অর্ডারে কনভার্ট করতে চান?\');"
                style="display:inline-block; color:#fff; background:#1B4D3E; padding:5px 12px;
                       border-radius:4px; text-decoration:none; font-weight:bold; font-size:12px;
                       white-space:nowrap;">Convert to Order 🛒</a>';
    }
}

// (f) Lead কে real order এ convert করার লজিক
add_action('admin_post_convert_abandoned_lead', 'fit_home_process_convert_lead');
function fit_home_process_convert_lead() {

    // [FIX] আগে শুধু nonce চেক ছিল, capability চেক ছিল না —
    // লগইন করা যেকোনো সাবস্ক্রাইবারও লিংক পেলে অর্ডার বানাতে পারত
    if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
        wp_die('You do not have permission to do this.');
    }

    if (!isset($_GET['post_id']) || !isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'convert_lead_nonce')) {
        wp_die('Security check failed. You cannot do this.');
    }

    $post_id = intval($_GET['post_id']);
    $lead = get_post($post_id);

    if (!$lead || $lead->post_type !== 'abandoned_lead') {
        wp_die('Invalid Lead.');
    }

    if ( ! class_exists('WooCommerce') ) {
        wp_die('WooCommerce is not active.');
    }

    $phone   = $lead->post_title;
    $content = $lead->post_content;

    preg_match('/Customer Name:\s*(.*)/', $content, $name_match);
    preg_match('/Address:\s*(.*)/', $content, $address_match);
    preg_match('/Product ID:\s*(.*)/', $content, $pid_match);
    preg_match('/Quantity:\s*(.*)/', $content, $qty_match);
    preg_match('/Location:\s*(.*)/', $content, $loc_match);

    $name         = isset($name_match[1])    ? trim($name_match[1])    : 'Unknown';
    $address_line = isset($address_match[1]) ? trim($address_match[1]) : '';
    $product_id   = isset($pid_match[1])     ? intval(trim($pid_match[1])) : FITHOME_PRODUCT_ID;
    $qty          = isset($qty_match[1])     ? intval(trim($qty_match[1])) : 1;
    $location     = isset($loc_match[1])     ? trim($loc_match[1])     : 'inside_dhaka';

    if ( $product_id <= 0 ) {
        $product_id = FITHOME_PRODUCT_ID;
    }

    // [FIX] আগে সবসময় qty=1 আর ডিফল্ট প্রোডাক্ট প্রাইসে অর্ডার হতো —
    // ২/৩ মাসের প্যাকেজের লিড কনভার্ট করলে ভুল দাম ও ভুল পরিমাণে অর্ডার যেত
    $package_price = fithome_get_package_price( $qty );
    if ( ! $package_price ) {
        $qty           = 1;
        $package_price = fithome_get_package_price( 1 );
    }

    $rates = fithome_get_shipping_rates();
    if ( ! isset( $rates[ $location ] ) ) {
        $location = 'inside_dhaka';
    }

    $product = wc_get_product($product_id);
    if ( ! $product ) {
        wp_die('Product not found with ID: ' . esc_html( $product_id ));
    }

    $order = wc_create_order();

    $order->add_product( $product, $qty, array(
        'subtotal' => $package_price,
        'total'    => $package_price,
    ) );

    $address = array(
        'first_name' => $name,
        'phone'      => $phone,
        'address_1'  => $address_line,
        'city'       => ( $location === 'inside_dhaka' ) ? 'Dhaka' : 'Outside Dhaka',
        'country'    => 'BD'
    );

    $order->set_address($address, 'billing');
    $order->set_address($address, 'shipping');

    // [FIX] ডেলিভারি চার্জও যোগ হচ্ছে (আগে একদমই যোগ হতো না)
    $shipping_charge = fithome_calc_shipping( $qty, $location );
    if ( $shipping_charge > 0 ) {
        $item_fee = new WC_Order_Item_Fee();
        $item_fee->set_name('ডেলিভারি চার্জ');
        $item_fee->set_amount($shipping_charge);
        $item_fee->set_total($shipping_charge);
        $order->add_item($item_fee);
    }

    $order->set_payment_method('cod');
    $order->set_payment_method_title('Cash on delivery');
    $order->calculate_totals();
    $order->save();
    $order->update_status('processing', 'Converted from Abandoned Lead via 1-Click Action.', true);

    wp_delete_post($post_id, true);

    wp_redirect(admin_url('edit.php?post_type=abandoned_lead&converted=true'));
    exit;
}

// (g) Convert সাকসেস মেসেজ
add_action('admin_notices', 'fit_home_converted_success_notice');
function fit_home_converted_success_notice() {
    if (isset($_GET['converted']) && $_GET['converted'] == 'true') {
        echo '<div class="notice notice-success is-dismissible" style="border-left-color: #1B4D3E;"><p><strong>Success!</strong> Abandoned lead has been successfully converted into an order. You can view the details in WooCommerce -> Orders.</p></div>';
    }
}


// =========================================================================
// 4. Custom Order Status: Delivery Rescheduled
// =========================================================================

// (a) কাস্টম স্ট্যাটাস রেজিস্টার
add_action( 'init', 'fit_home_register_rescheduled_order_status' );
function fit_home_register_rescheduled_order_status() {
    register_post_status( 'wc-rescheduled', array(
        'label'                     => 'Delivery Rescheduled',
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop( 'Delivery Rescheduled <span class="count">(%s)</span>', 'Delivery Rescheduled <span class="count">(%s)</span>' )
    ) );
}

// (b) Dropdown এ status যোগ ("On hold" এর পরে)
add_filter( 'wc_order_statuses', 'fit_home_add_rescheduled_status_to_dropdown' );
function fit_home_add_rescheduled_status_to_dropdown( $order_statuses ) {
    $new_order_statuses = array();

    foreach ( $order_statuses as $key => $status ) {
        $new_order_statuses[ $key ] = $status;

        if ( 'wc-on-hold' === $key ) {
            $new_order_statuses['wc-rescheduled'] = 'Delivery Rescheduled';
        }
    }
    return $new_order_statuses;
}


// =========================================================================
// 5. Bulk Action: Delivery Rescheduled
// =========================================================================

// (a) Bulk dropdown এ অপশন যোগ (Classic + HPOS)
add_filter( 'bulk_actions-edit-shop_order', 'fit_home_add_rescheduled_bulk_action' );
add_filter( 'bulk_actions-woocommerce_page_wc-orders', 'fit_home_add_rescheduled_bulk_action' );

function fit_home_add_rescheduled_bulk_action( $bulk_actions ) {
    $bulk_actions['mark_rescheduled'] = 'Change status to Delivery Rescheduled';
    return $bulk_actions;
}

// (b) Bulk action হ্যান্ডল
add_filter( 'handle_bulk_actions-edit-shop_order', 'fit_home_handle_rescheduled_bulk_action', 10, 3 );
add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', 'fit_home_handle_rescheduled_bulk_action', 10, 3 );

function fit_home_handle_rescheduled_bulk_action( $redirect_to, $doaction, $post_ids ) {
    if ( $doaction !== 'mark_rescheduled' ) {
        return $redirect_to;
    }

    foreach ( $post_ids as $post_id ) {
        $order = wc_get_order( $post_id );
        if ( $order ) {
            $order->update_status( 'rescheduled', 'Bulk action applied: Delivery Rescheduled.' );
        }
    }

    return add_query_arg( array( 'bulk_rescheduled' => count( $post_ids ) ), $redirect_to );
}


// =========================================================================
// 6. Performance Optimization
// =========================================================================

// (a) Emoji script/style বন্ধ (site-wide)
remove_action('wp_head', 'print_emoji_detection_script', 7);
remove_action('admin_print_scripts', 'print_emoji_detection_script');
remove_action('wp_print_styles', 'print_emoji_styles');
remove_action('admin_print_styles', 'print_emoji_styles');
remove_filter('the_content_feed', 'wp_staticize_emoji');
remove_filter('comment_text_rss', 'wp_staticize_emoji');
remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
add_filter('emoji_svg_url', '__return_false');

// (b) ল্যান্ডিং পেজে অপ্রয়োজনীয় script/style বন্ধ
add_action('wp_enqueue_scripts', function () {

    if ( ! is_page_template('landing-page-daily-protein.php') ) return;

    global $wp_scripts, $wp_styles;

    foreach ( array_keys( $wp_scripts->registered ) as $handle ) {
        wp_dequeue_script( $handle );
        wp_deregister_script( $handle );
    }
    foreach ( array_keys( $wp_styles->registered ) as $handle ) {
        wp_dequeue_style( $handle );
        wp_deregister_style( $handle );
    }

}, PHP_INT_MAX );

// (c) ল্যান্ডিং পেজে WooCommerce footer injection বন্ধ
// [FIX] 'woocommerce_is_checkout' নামে WooCommerce-এ কোনো ফিল্টারই নেই — ডেড কোড ছিল, বাদ দেওয়া হলো
// [FIX] WC_Cart_Fragments ক্লাস WooCommerce 3.3 থেকে নেই — ওই remove_action-ও কিছুই করত না, বাদ দেওয়া হলো
add_action('wp', function() {
    if ( ! is_page_template('landing-page-daily-protein.php') ) return;

    remove_action('wp_footer', 'wc_print_js', 25);
    add_filter('woocommerce_cart_needs_payment', '__return_false');
});

// (d) বাকি WC element CSS দিয়ে লুকানো (backup)
add_action('wp_head', function() {
    if ( ! is_page_template('landing-page-daily-protein.php') ) return;
    echo '<style>
        form.cart, .woocommerce-notices-wrapper,
        .wc-block-components-notices, #add_payment_method,
        .woocommerce-cart-form, .woocommerce-checkout,
        div[id^="wc-"], input[name^="wc-"],
        #photoswipe-fullscreen-dialog,
        .pswp { display: none !important; }
    </style>';
});


// =========================================================================
// 7. External Files Load
// =========================================================================

// Telegram Bot (order notifications ইত্যাদি)
if ( file_exists( get_stylesheet_directory() . '/telegram-bot.php' ) ) {
    require_once get_stylesheet_directory() . '/telegram-bot.php';
}

// SMS Notification (BulkSMSBD)
if ( file_exists( get_stylesheet_directory() . '/sms-notification.php' ) ) {
    require_once get_stylesheet_directory() . '/sms-notification.php';
}

// Order List এ Phone Column
if ( file_exists( get_stylesheet_directory() . '/order-list-phone-column.php' ) ) {
    require_once get_stylesheet_directory() . '/order-list-phone-column.php';
}

// Telegram Order Bot (মডারেটর অর্ডার ক্রিয়েশন)
if ( file_exists( get_stylesheet_directory() . '/telegram-order-bot.php' ) ) {
    require_once get_stylesheet_directory() . '/telegram-order-bot.php';
}