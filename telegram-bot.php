<?php
// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

// =========================================================================
// ⚙️ BOT CONFIGURATIONS & TOKENS (৩টি বটের জন্য ৩টি আলাদা টোকেন)
// =========================================================================

// ১. Bot 1 (Fit Home Orders Notification - শুধু অর্ডার নোটিফিকেশন পাঠাবে)
define('FITHOME_ORDER_BOT_TOKEN', '8827614110:AAFGgSLe7c_tXM1btc9PX__cCDpxIQPjr4Q'); 

// ২. Bot 2 (Fit Home Courier Ratio Checker - শুধু রেশিও চেক করবে)
define('FITHOME_RATIO_BOT_TOKEN', '8647260737:AAFscWFduwCA7FdC-UvggOGfwZqfekuu5f8'); 

// ৩. Bot 3 (Fit Home Sales Summary - শুধু সেলস সামারি দেখাবে)
define('FITHOME_SALES_BOT_TOKEN', '8634785557:AAGuGbljHvNx7242L8G0leMKgGlGqwtlxaU');



// ✅ CHANGED: একাধিক Admin/Partner এর Chat ID এখানে বসাও (কমা দিয়ে আলাদা করে)
// পার্টনারের Chat ID পেলে নিচের array তে যোগ করে দাও
define('FITHOME_ADMIN_CHAT_IDS', array(
    '8594293089',   // তোমার Chat ID
    '6001942632' , 
    '7735677831'
));

// Courier API Key
define('FITHOME_COURIER_API_KEY', 'nrc_live_5z1d7bwgiomEBNuob4eapNTdbcKGqjc1'); 

// ✅ NEW: Naimur Courier প্যানেলে যে ডোমেইনটা whitelist করা আছে হুবহু সেটাই এখানে বসাও।
// সার্ভার থেকে করা wp_remote_get কলে ব্রাউজারের মতো Origin/Referer হেডার নিজে থেকে যায় না,
// তাই API সেটাকে "unauthorized domain" ধরে নেয় — নিচের কনস্ট্যান্ট দিয়ে সেটা ম্যানুয়ালি পাঠানো হচ্ছে।
define('FITHOME_COURIER_ORIGIN', 'https://fithomebd.com');


// =========================================================================
// 🔄 HELPER FUNCTION: Fetch Courier Ratio API (With Error Tracking)
// =========================================================================
function fit_home_get_courier_ratio($phone) {
    // ⚠️ API কলটি বন্ধ করতে চাইলে নিচের লাইনটির শুরুতে 'return false;' লিখে দিন।
    // return false; 

    // ✅ NEW: নম্বর ক্লিন করা — Bot 2 এর মতোই 01XXXXXXXXX ফরম্যাটে আনা।
    // Bot 1 আগে raw billing phone পাঠাত (+880, স্পেস, বাংলা ডিজিট সহ), যেটা API বুঝত না।
    $bn_digits = array('০','১','২','৩','৪','৫','৬','৭','৮','৯');
    $en_digits = array('0','1','2','3','4','5','6','7','8','9');
    $clean = preg_replace('/[\s\-\+]/', '', str_replace($bn_digits, $en_digits, $phone));
    if (preg_match('/(?:88)?(01[3-9]\d{8})/', $clean, $phone_match)) {
        $phone = $phone_match[1];
    }

    $api_url = "https://courier.naimurrahmannahid.com/api/v1/courier-history?phone=" . urlencode($phone);
    
    $response = wp_remote_get($api_url, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . FITHOME_COURIER_API_KEY,
            // ✅ NEW: whitelisted ডোমেইন থেকে আসছে বোঝানোর জন্য হেডারগুলো
            'Origin'        => FITHOME_COURIER_ORIGIN,
            'Referer'       => trailingslashit(FITHOME_COURIER_ORIGIN),
            'Accept'        => 'application/json',
        ),
        'timeout' => 25,
        'blocking' => true
    ));

    // সার্ভারে কানেক্ট করতে না পারলে
    if (is_wp_error($response)) {
        // ✅ NEW: লগে রাখা, যাতে পরে debug.log দেখে আসল কারণ বোঝা যায়
        error_log('[FitHome Ratio] WP_Error (' . $phone . '): ' . $response->get_error_message());
        return array('status' => 'error', 'message' => 'ওয়েবসাইট থেকে কুরিয়ার সার্ভারে কানেক্ট করা যাচ্ছে না। (' . $response->get_error_message() . ')'); 
    }

    // ✅ NEW: HTTP স্ট্যাটাস কোড ও raw body আলাদা করে রাখা
    $status_code = wp_remote_retrieve_response_code($response);
    $raw_body    = wp_remote_retrieve_body($response);
    $body        = json_decode($raw_body, true);

    // ডেটা সফলভাবে পেলে
    if (isset($body['success']) && $body['success'] == true && isset($body['data'])) {
        return array('status' => 'success', 'data' => $body['data']); 
    } else {
        // ✅ NEW: ব্যর্থ হলে HTTP কোড সহ পুরো রেসপন্স লগে লিখে রাখা
        error_log("[FitHome Ratio] HTTP {$status_code} ({$phone}): " . substr($raw_body, 0, 500));

        // API থেকে পাঠানো আসল এরর মেসেজটি ধরবে
        $error_msg = isset($body['message']) ? $body['message'] : "HTTP {$status_code} — এই নম্বরের কোনো কুরিয়ার হিস্ট্রি নেই বা API তে সমস্যা আছে।";
        return array('status' => 'error', 'message' => $error_msg);
    }
}

// =========================================================================
// 🤖 BOT 1: New Order Notification (Background Process)
// =========================================================================
// =========================================================================
// ✅ CHANGED: আগে এটা woocommerce_new_order এ হুক করা ছিল, যেটা অর্ডার তৈরির
// একদম প্রথম মুহূর্তেই ফায়ার হয় — Order Bot তখনো _created_via_telegram_bot
// মেটা সেট করেনি, তাই মাঝে মাঝে notification ভুল সোর্স ("Website") দেখাত।
// এখন woocommerce_order_status_processing এ হুক করা হচ্ছে (SMS notification
// এই একই hook ব্যবহার করে, তাই সেখানে এই সমস্যা কখনো হয়নি) — এতক্ষণে মেটা
// ইতিমধ্যেই সেভ হয়ে যায়, তাই সোর্স সবসময় সঠিক দেখাবে।
// =========================================================================
add_action('woocommerce_order_status_processing', 'fit_home_schedule_telegram_notification', 10, 1);
function fit_home_schedule_telegram_notification($order_id) {
    if (!$order_id) return;
    wp_schedule_single_event(time(), 'fit_home_send_async_telegram_order_notice', array($order_id));
}

add_action('fit_home_send_async_telegram_order_notice', 'fit_home_process_async_telegram_order_notice');
function fit_home_process_async_telegram_order_notice($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    $phone = $order->get_billing_phone();
    $name = $order->get_billing_first_name();
    $total = $order->get_total();

    // ✅ কাস্টমারের ঠিকানা সংগ্রহ করা হচ্ছে
    $address_1 = $order->get_billing_address_1();
    $address_2 = $order->get_billing_address_2();
    $city      = $order->get_billing_city();

    $full_address = trim($address_1 . ($address_2 ? ', ' . $address_2 : '') . ($city ? ' (' . $city . ')' : ''));
    if (empty($full_address)) {
        $full_address = 'উল্লেখ নেই';
    }

    // ✅ NEW: অর্ডারের সোর্স — Website নাকি Messenger (Telegram Order Bot দিয়ে তৈরি)
    $source_text = "🌐 <b>সোর্স:</b> Website";
    if ($order->get_meta('_created_via_telegram_bot') === 'yes') {
        $mod_name    = $order->get_meta('_telegram_moderator_name');
        $mod_chat_id = $order->get_meta('_telegram_moderator_chat_id');
        $mod_count   = (function_exists('fithome_get_moderator_order_count') && !empty($mod_chat_id))
            ? fithome_get_moderator_order_count($mod_chat_id, 'today')
            : '?';
        $source_text = "📱 <b>সোর্স:</b> Messenger (মডারেটর: " . esc_html($mod_name) . ", আজ মোট: {$mod_count} টি)";
    }

    // ✅ API একবারই call হবে, তারপর নিচে সবার কাছে একই রেজাল্ট broadcast হবে
    $ratio_text = "";
    $ratio_response = fit_home_get_courier_ratio($phone);
    if ($ratio_response && $ratio_response['status'] == 'success') {
        $rd = $ratio_response['data']['summary'];
        $ratio_text = "\n📊 <b>Success Ratio:</b> " . esc_html($rd['success_ratio']) . "%\n"
                    . "✅ Success: " . esc_html($rd['success_parcel']) . " | ❌ Cancelled: " . esc_html($rd['cancelled_parcel']);
    } else {
        // ⚠️ TEMPORARY DEBUG: রেশিও কেন আসছে না সেটা সরাসরি Telegram এ দেখাবে।
        // সমস্যা ঠিক হয়ে গেলে নিচের ২ লাইন মুছে দিলেই আগের মতো চুপচাপ স্কিপ করবে।
        $ratio_err  = isset($ratio_response['message']) ? $ratio_response['message'] : 'কোনো রেসপন্স আসেনি';
        $ratio_text = "\n⚠️ <b>Ratio পাওয়া যায়নি:</b> " . esc_html($ratio_err);
    }

    $message = "🛒 <b>নতুন অর্ডার এসেছে!</b>\n"
             . "━━━━━━━━━━━━━━━━━\n"
             . "🆔 <b>অর্ডার নম্বর:</b> #{$order_id}\n"
             . "👤 <b>নাম:</b> {$name}\n"
             . "☎️ <b>মোবাইল:</b> <code>{$phone}</code>\n"
             . "🏠 <b>ঠিকানা:</b> " . esc_html($full_address) . "\n"
             . "{$source_text}\n"
             . "💰 <b>মোট বিল:</b> ৳{$total}"
             . $ratio_text;

    // ✅ CHANGED: এখন array এর সবাইকে loop করে একই মেসেজ পাঠানো হবে (API call একবারই হয়েছে উপরে)
    foreach (FITHOME_ADMIN_CHAT_IDS as $recipient_chat_id) {
        wp_remote_post("https://api.telegram.org/bot" . FITHOME_ORDER_BOT_TOKEN . "/sendMessage", [
            'body' => [ 'chat_id' => $recipient_chat_id, 'text' => $message, 'parse_mode' => 'HTML' ],
            'timeout' => 15,
        ]);
    }
}

// =========================================================================
// 🌐 WEBHOOK ENDPOINT REGISTRATION (For Bot 2 & Bot 3)
// =========================================================================
add_action('rest_api_init', 'fit_home_register_telegram_webhook');
function fit_home_register_telegram_webhook() {
    register_rest_route('fithome/v1', '/bot', array(
        'methods' => 'POST',
        'callback' => 'fit_home_telegram_webhook_handler',
        'permission_callback' => '__return_true'
    ));
}

function fit_home_telegram_webhook_handler($request) {
    $body = $request->get_json_params();
    
    if (isset($body['message']['text']) && isset($body['message']['chat']['id'])) {
        $command_text = trim($body['message']['text']);
        $chat_id = $body['message']['chat']['id'];

        // 🤖 BOT 3: Sales Summary Report Logic (Starts with '/')
        if (strpos($command_text, '/') === 0) {
            
            // ✅ CHANGED: এখন array এর যেকোনো একজন হলেই authorized হবে
            if (!in_array($chat_id, FITHOME_ADMIN_CHAT_IDS)) {
                return new WP_REST_Response('Unauthorized Chat ID', 403);
            }

            $command = strtolower($command_text);
            $days_to_fetch = 0; $report_title = "Advanced Live Report";

            if ($command === '/daily' || $command === '/1') {
                $days_to_fetch = 1; $report_title = "Daily Sales Report";
            } elseif ($command === '/weekly' || $command === '/7') {
                $days_to_fetch = 7; $report_title = "Weekly Sales Report (Last 7 Days)";
            } elseif ($command === '/monthly' || $command === '/30') {
                $days_to_fetch = 30; $report_title = "Monthly Sales Report (Last 30 Days)";
            } elseif (preg_match('/^\/(\d+)$/', $command, $matches)) {
                $days_to_fetch = intval($matches[1]); $report_title = "Custom Report (Last {$days_to_fetch} Days)";
            }

            if ($days_to_fetch > 0) {
                $today_date = current_time('Y-m-d');
                if ($days_to_fetch == 1) {
                    $date_query = $today_date . '...' . $today_date;
                } else {
                    $past_date = date('Y-m-d', strtotime("-".($days_to_fetch - 1)." days", current_time('timestamp')));
                    $date_query = $past_date . '...' . $today_date;
                }
                
                $args = array(
                    'limit' => -1, 'date_created' => $date_query,
                    'status' => array('wc-processing', 'wc-completed', 'wc-on-hold', 'wc-rescheduled'),
                );
                $orders = wc_get_orders($args);

                $total_sales = 0; $order_count = count($orders);
                $unique_customers = array();
                $status_counts = array('processing' => 0, 'completed' => 0, 'on-hold' => 0, 'rescheduled' => 0);

                foreach ($orders as $order) {
                    $total_sales += $order->get_total();
                    $status = $order->get_status();
                    if (isset($status_counts[$status])) { $status_counts[$status]++; }
                    $phone = $order->get_billing_phone();
                    if (!empty($phone) && !in_array($phone, $unique_customers)) { $unique_customers[] = $phone; }
                }

                $customer_count = count($unique_customers);
                $avg_order_value = $order_count > 0 ? ($total_sales / $order_count) : 0;

                $message = "📊 <b>Fit Home - {$report_title}</b>\n";
                $message .= ($days_to_fetch == 1) ? "🗓️ <b>তারিখ:</b> " . current_time('d-m-Y') . "\n" : "🗓️ <b>রেঞ্জ:</b> " . date('d-m-Y', strtotime($past_date)) . " হতে " . current_time('d-m-Y') . "\n";
                $message .= "━━━━━━━━━━━━━━━━━\n\n";
                $message .= "💰 <b>মোট সেলস:</b> ৳" . esc_html(number_format($total_sales, 2)) . "\n";
                $message .= "📦 <b>মোট অর্ডার:</b> " . esc_html($order_count) . " টি\n";
                $message .= "👥 <b>মোট কাস্টমার:</b> " . esc_html($customer_count) . " জন\n";
                $message .= "📈 <b>এভারেজ অর্ডার ভ্যালু:</b> ৳" . esc_html(number_format($avg_order_value, 2)) . "\n\n";
                $message .= "<b>📌 অর্ডার স্ট্যাটাস:</b>\n";
                $message .= "🔄 Processing: {$status_counts['processing']} | ✅ Completed: {$status_counts['completed']}\n";
                $message .= "⏸️ On Hold: {$status_counts['on-hold']} | 📅 Rescheduled: {$status_counts['rescheduled']}\n";
                $message .= "━━━━━━━━━━━━━━━━━\n<i>Requested by Admin</i>";

                wp_remote_post("https://api.telegram.org/bot" . FITHOME_SALES_BOT_TOKEN . "/sendMessage", [
                    'body' => [ 'chat_id' => $chat_id, 'text' => $message, 'parse_mode' => 'HTML' ],
                    'timeout' => 15,
                ]);
            }
            return new WP_REST_Response('Sales Report Processed', 200);
        }
        else {
            // 🤖 BOT 2: PURE RATIO CHECKER (Handles Bangla digits, Space, Hyphen & +880)
            
            // ✅ ADDED: Security — শুধু admin/partner রা কাস্টমার ডেটা দেখতে পারবে
            if (!in_array($chat_id, FITHOME_ADMIN_CHAT_IDS)) {
                return new WP_REST_Response('Unauthorized', 403);
            }
            
            // ১. বাংলা নম্বরকে ইংরেজিতে কনভার্ট করা
            $bn_digits = array('০','১','২','৩','৪','৫','৬','৭','৮','৯');
            $en_digits = array('0','1','2','3','4','5','6','7','8','9');
            $clean_text = str_replace($bn_digits, $en_digits, $command_text);
            
            // ২. নম্বরের ভেতর থেকে স্পেস, ড্যাশ বা প্লাস সাইন রিমুভ করা
            $clean_text = preg_replace('/[\s\-\+]/', '', $clean_text);
            
            // ৩. শুধুমাত্র 01XXXXXXXXX ফরম্যাটটি খুঁজে বের করা (কান্ট্রি কোড 88 থাকলেও বাদ দিয়ে দেবে)
            if (preg_match('/(?:88)?(01[3-9]\d{8})/', $clean_text, $phone_match)) {
                $phone_to_check = $phone_match[1]; // একদম ফ্রেশ ১১ ডিজিট
                
                wp_remote_post("https://api.telegram.org/bot" . FITHOME_RATIO_BOT_TOKEN . "/sendChatAction", [
                    'body' => [ 'chat_id' => $chat_id, 'action' => 'typing' ]
                ]);

                $ratio_response = fit_home_get_courier_ratio($phone_to_check);
                
                if ($ratio_response && $ratio_response['status'] == 'success') {
                    $rd = $ratio_response['data']['summary'];
                    $msg = "🔍 <b>কাস্টমার ডেটা:</b> <code>{$phone_to_check}</code>\n"
                         . "━━━━━━━━━━━━━━━━━\n"
                         . "📦 <b>মোট অর্ডার:</b> " . esc_html($rd['total_parcel']) . "\n"
                         . "✅ <b>সাকসেস:</b> " . esc_html($rd['success_parcel']) . "\n"
                         . "❌ <b>ক্যান্সেল:</b> " . esc_html($rd['cancelled_parcel']) . "\n"
                         . "📊 <b>রেশিও:</b> " . esc_html($rd['success_ratio']) . "%";
                } else {
                    $api_error = $ratio_response ? $ratio_response['message'] : 'API থেকে কোনো রেসপন্স আসেনি।';
                    $msg = "❌ <b>দুঃখিত, রেশিও পাওয়া যায়নি!</b>\n"
                         . "<b>কারণ:</b> " . esc_html($api_error) . "\n"
                         . "(নম্বর: {$phone_to_check})";
                }
                
                wp_remote_post("https://api.telegram.org/bot" . FITHOME_RATIO_BOT_TOKEN . "/sendMessage", [
                    'body' => [ 'chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'HTML' ],
                    'timeout' => 15,
                ]);
                
                return new WP_REST_Response('Ratio Checked', 200);
            }
        }
    }
    
    return new WP_REST_Response('Success', 200);
}