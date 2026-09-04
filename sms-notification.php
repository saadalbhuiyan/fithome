<?php
/**
 * Fit Home - Order Confirmation SMS + 6-Hourly Server Health/Balance Alert
 * Gateway: MiMSMS API v2 (mimsms.com)
 * এই ফাইলটি sbmart-child theme এর ভিতরে রাখুন এবং functions.php থেকে require করুন
 *
 * ফিচার ১: অর্ডার "Processing" হলে কাস্টমারকে কনফার্মেশন SMS যাবে (আগের মতোই)
 *          ফেইল হলে প্রতি ৩০ মিনিট পরপর retry, সর্বোচ্চ ৬ ঘণ্টা পর্যন্ত।
 * ফিচার ২: প্রতি ৬ ঘণ্টা পরপর আপনার ও আপনার বন্ধুর নম্বরে একটা SMS যাবে যেখানে
 *          লেখা থাকবে সার্ভার সচল আছে কিনা এবং বর্তমান MiMSMS ব্যালেন্স কত টাকা।
 * লগ: sbmart-child ফোল্ডারের ভিতরেই sms-log.txt এ সব ইভেন্ট লেখা থাকবে।
 */

if ( !defined( 'ABSPATH' ) ) exit;

// =========================================================================
// CONFIG - sms.mimsms.com → Utility → Developer Option থেকে পাওয়া তথ্য
// =========================================================================
if ( !defined('FITHOME_SMS_API_KEY') ) {
    define('FITHOME_SMS_API_KEY', '4BFUSHUHPIM5DG0'); // <-- MiMSMS Developer Option থেকে পাওয়া API Key
}
if ( !defined('FITHOME_SMS_USERNAME') ) {
    define('FITHOME_SMS_USERNAME', 'omarfarukshadbhuiyan@gmail.com'); // MiMSMS প্যানেলের লগইন ইমেইল
}
if ( !defined('FITHOME_SMS_SENDER_NAME') ) {
    define('FITHOME_SMS_SENDER_NAME', '8809601018090'); // Utility → Sender ID থেকে পাওয়া Active Non-Masking ID
}

// API endpoints (সাধারণত পরিবর্তনের দরকার নেই)
if ( !defined('FITHOME_SMS_SEND_URL') ) {
    define('FITHOME_SMS_SEND_URL', 'https://api.mimsms.com/api/V2/SMS');
}
if ( !defined('FITHOME_SMS_BALANCE_URL') ) {
    define('FITHOME_SMS_BALANCE_URL', 'https://api.mimsms.com/api/V2/BalanceCheck');
}

// প্রতি ৬ ঘণ্টা পরপর "সার্ভার সচল + ব্যালেন্স" জানিয়ে যাদের কাছে SMS যাবে
if ( !defined('FITHOME_SMS_ADMIN_NUMBERS') ) {
    define('FITHOME_SMS_ADMIN_NUMBERS', serialize(array(
        '8801981455568',  // <-- আপনার নম্বর
        '8801322386762',  // <-- আপনার বন্ধুর নম্বর
    )));
}

// Retry সেটিংস (আগের মতোই অপরিবর্তিত)
if ( !defined('FITHOME_SMS_RETRY_INTERVAL') ) {
    define('FITHOME_SMS_RETRY_INTERVAL', 30 * MINUTE_IN_SECONDS); // প্রতি ৩০ মিনিট পরপর চেষ্টা
}
if ( !defined('FITHOME_SMS_RETRY_MAX_DURATION') ) {
    define('FITHOME_SMS_RETRY_MAX_DURATION', 6 * HOUR_IN_SECONDS); // সর্বোচ্চ ৬ ঘণ্টা পর্যন্ত চেষ্টা
}


// =========================================================================
// HOOKS - অর্ডার কনফার্মেশন SMS
// =========================================================================
add_action('woocommerce_order_status_processing', 'fit_home_attempt_sms_send', 20, 1);
add_action('fithome_retry_sms_event', 'fit_home_attempt_sms_send', 10, 1);


// =========================================================================
// HOOKS - প্রতি ৬ ঘণ্টা পরপর সার্ভার/ব্যালেন্স অ্যালার্ট
// =========================================================================
add_filter('cron_schedules', 'fit_home_add_six_hour_schedule');
function fit_home_add_six_hour_schedule($schedules) {
    if (!isset($schedules['fithome_twelve_hours'])) {
        $schedules['fithome_twelve_hours'] = array(
            'interval' => 12 * HOUR_IN_SECONDS,
            'display'  => 'Every 12 Hours (Fit Home SMS Alert)',
        );
    }
    return $schedules;
}

add_action('init', 'fit_home_schedule_balance_alert');
function fit_home_schedule_balance_alert() {
    // ✅ CHANGED: ৬ ঘণ্টা থেকে ১২ ঘণ্টায় পরিবর্তনের জন্য এক-বারের ক্লিনআপ —
    // আগের ৬-ঘণ্টার শিডিউলটা মুছে নতুন করে ১২ ঘণ্টার জন্য বসানো হচ্ছে,
    // যাতে পরিবর্তনটা সাথে সাথেই কার্যকর হয় (WP নিজে থেকে interval আপডেট করে না)
    if (!get_option('fithome_sms_interval_switched_to_12h')) {
        wp_clear_scheduled_hook('fithome_balance_alert_event');
        update_option('fithome_sms_interval_switched_to_12h', 1);
    }

    if (!wp_next_scheduled('fithome_balance_alert_event')) {
        wp_schedule_event(time(), 'fithome_twelve_hours', 'fithome_balance_alert_event');
    }
}
add_action('fithome_balance_alert_event', 'fit_home_send_balance_alert');


// =========================================================================
// MAIN FUNCTION - অর্ডার কনফার্মেশন SMS পাঠানোর চেষ্টা করে, ফেইল হলে retry শিডিউল করে
// =========================================================================
function fit_home_attempt_sms_send($order_id) {

    // ইতিমধ্যে সফলভাবে পাঠানো হয়ে থাকলে আর কিছু করার দরকার নেই
    if (get_post_meta($order_id, '_fithome_sms_sent', true)) {
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) return;

    $phone = $order->get_billing_phone();
    if (empty($phone)) return;

    // প্রথমবার চেষ্টার সময়টা সেভ করে রাখা (৬ ঘণ্টার হিসাব এখান থেকেই শুরু হবে)
    $first_attempt_time = get_post_meta($order_id, '_fithome_sms_first_attempt', true);
    if (empty($first_attempt_time)) {
        $first_attempt_time = time();
        update_post_meta($order_id, '_fithome_sms_first_attempt', $first_attempt_time);
    }

    // ----------------------------------------------------------
    // ফোন নম্বর ফরম্যাট ঠিক করা (MiMSMS চায় 880XXXXXXXXXX ফরম্যাট, কোনো + চিহ্ন ছাড়া)
    // ----------------------------------------------------------
    $clean_phone = fit_home_format_phone($phone);

    // ----------------------------------------------------------
    // ✅ NEW: এই অর্ডারটা Website নাকি Telegram Order Bot (Messenger) দিয়ে
    // তৈরি হয়েছে সেটা আগে চেক করা — কারণ bot অর্ডারে কোনো real প্রোডাক্ট
    // quantity বা billing city ফিল্ড থাকে না, তাই আলাদাভাবে হ্যান্ডেল করা লাগবে
    // ----------------------------------------------------------
    $is_bot_order = ($order->get_meta('_created_via_telegram_bot') === 'yes');
    $billing_city = $order->get_billing_city();

    // ----------------------------------------------------------
    // টোটাল প্রাইস
    // ----------------------------------------------------------
    $total_price  = number_format((float) $order->get_total(), 0);
    $total_amount = (float) $order->get_total();

    if ($is_bot_order) {
        // ✅ CHANGED: Bot/Messenger অর্ডারে আর টাকা/প্যাকেজ অনুমান করে SMS-এ বলা হবে না —
        // শুধু একটা সাধারণ কনফার্মেশন মেসেজ যাবে, ডেলিভারি টাইম সবসময় ২৪-৪৮ ঘণ্টা
        $delivery_time = '২৪-৪৮ ঘণ্টার মধ্যে';
        $area_label    = 'Messenger অর্ডার (ফিক্সড ২৪-৪৮ ঘণ্টা)';

        // লগ ফাইলে দেখার জন্য প্যাকেজ নাম এখনো রাখা হচ্ছে (SMS টেক্সটে যাবে না)
        if ($total_amount <= 1100) {
            $package_name = '১ মাসের প্যাকেজ (অনুমিত)';
        } elseif ($total_amount <= 1800) {
            $package_name = '২ মাসের প্যাকেজ (অনুমিত)';
        } else {
            $package_name = '৩ মাসের প্যাকেজ (অনুমিত)';
        }

    } else {
        // ওয়েবসাইট অর্ডার — আগের মতোই quantity ও billing city থেকে নির্ভুলভাবে বের করা
        $qty = 1;
        foreach ($order->get_items() as $item) {
            $qty = $item->get_quantity();
            break;
        }

        $package_map = array(
            1 => '১ মাসের প্যাকেজ',
            2 => '২ মাসের প্যাকেজ',
            3 => '৩ মাসের প্যাকেজ',
        );
        $package_name = isset($package_map[$qty]) ? $package_map[$qty] : ($qty . ' পিস প্যাকেজ');

        if ($billing_city === 'Dhaka') {
            $delivery_time = '২৪-৪৮ ঘণ্টার মধ্যে';
            $area_label    = 'ঢাকার ভিতরে';
        } else {
            $delivery_time = '৪৮-৭২ ঘণ্টার মধ্যে';
            $area_label    = 'ঢাকার বাইরে';
        }
    }

    // ----------------------------------------------------------
    // ✅ CHANGED: ডায়নামিক SMS টেমপ্লেট — bot অর্ডারে সাধারণ মেসেজ (টাকা/প্যাকেজ ছাড়া),
    // ওয়েবসাইট অর্ডারে আগের মতোই বিস্তারিত মেসেজ
    // ----------------------------------------------------------
    if ($is_bot_order) {
        $message = "প্রিয় Sir/Mam, আপনার অর্ডারটি কনফার্ম হয়েছে। {$delivery_time} আপনি পণ্যটি হাতে পাবেন। ধন্যবাদ - Fit Home";
    } else {
        $message = "প্রিয় Sir/Mam, আপনার {$package_name} অর্ডারটি কনফার্ম হয়েছে। সর্বমোট মূল্যঃ {$total_price} টাকা। {$delivery_time} আপনি পণ্যটি হাতে পাবেন। ধন্যবাদ - Fit Home";
    }

    // ----------------------------------------------------------
    // SMS পাঠানোর চেষ্টা
    // ----------------------------------------------------------
    $success = fit_home_call_sms_api($clean_phone, $message);

    if ($success) {
        // সফল হলে flag সেভ করে দিন, আর কোনো retry লাগবে না
        update_post_meta($order_id, '_fithome_sms_sent', 1);
        delete_post_meta($order_id, '_fithome_sms_first_attempt');

        fit_home_log_sms_error(
            "Order #{$order_id}: SMS সফলভাবে পাঠানো হয়েছে। | ফোনঃ {$clean_phone} | প্যাকেজঃ " . ($package_name ?? 'অজানা') . " | এলাকাঃ {$area_label} | মূল্যঃ {$total_price} টাকা | মেসেজঃ {$message}"
        );
        return;
    }

    // ----------------------------------------------------------
    // ফেইল হলে - ৬ ঘণ্টা পার হয়ে গেছে কিনা চেক করা
    // ----------------------------------------------------------
    $elapsed = time() - (int) $first_attempt_time;

    if ($elapsed >= FITHOME_SMS_RETRY_MAX_DURATION) {
        // ৬ ঘণ্টা পার হয়ে গেছে, আর চেষ্টা করবে না, চুপচাপ থেমে যাবে
        fit_home_log_sms_error(
            "Order #{$order_id}: ৬ ঘণ্টা ধরে চেষ্টা করেও SMS পাঠানো যায়নি, retry বন্ধ করা হলো। | ফোনঃ {$clean_phone} | প্যাকেজঃ " . ($package_name ?? 'অজানা') . " | এলাকাঃ {$area_label} | মূল্যঃ {$total_price} টাকা"
        );
        delete_post_meta($order_id, '_fithome_sms_first_attempt');
        return;
    }

    // এখনো সময় আছে - পরবর্তী চেষ্টার জন্য শিডিউল করা (একাধিকবার শিডিউল যেন না হয়, তাই চেক করা)
    if (!wp_next_scheduled('fithome_retry_sms_event', array($order_id))) {
        wp_schedule_single_event(time() + FITHOME_SMS_RETRY_INTERVAL, 'fithome_retry_sms_event', array($order_id));
        fit_home_log_sms_error(
            "Order #{$order_id}: SMS পাঠানো যায়নি, ৩০ মিনিট পর আবার চেষ্টা হবে। | ফোনঃ {$clean_phone} | প্যাকেজঃ " . ($package_name ?? 'অজানা') . " | এলাকাঃ {$area_label} | মূল্যঃ {$total_price} টাকা"
        );
    }
}


// =========================================================================
// ৬-ঘণ্টা পরপর সার্ভার/ব্যালেন্স অ্যালার্ট - owner + friend এর নম্বরে যাবে
// =========================================================================
function fit_home_send_balance_alert() {

    $balance = fit_home_check_sms_balance();

    if ($balance === false) {
        $message = "সতর্কতা: Fit Home SMS সার্ভারের ব্যালেন্স চেক করা যায়নি। ম্যানুয়ালি dashboard চেক করুন।";
    } else {
        $message = "Fit Home SMS সার্ভার সচল আছে। বর্তমান ব্যালেন্সঃ {$balance} টাকা।";
    }

    $admin_numbers = unserialize(FITHOME_SMS_ADMIN_NUMBERS);

    foreach ($admin_numbers as $admin_number) {
        $clean_number = fit_home_format_phone($admin_number);
        fit_home_call_sms_api($clean_number, $message);
    }
}

// =========================================================================
// MiMSMS ব্যালেন্স চেক - GET /V2/BalanceCheck
// =========================================================================
function fit_home_check_sms_balance() {

    $url = add_query_arg(array(
        'userName' => FITHOME_SMS_USERNAME,
        'apiKey'   => FITHOME_SMS_API_KEY,
    ), FITHOME_SMS_BALANCE_URL);

    try {
        $response = wp_remote_get($url, array('timeout' => 8));

        if (is_wp_error($response)) {
            fit_home_log_sms_error('Balance check request failed: ' . $response->get_error_message());
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response));

        // ✅ FIX: MiMSMS API স্ট্যাটাস "OK" পাঠায় না, পাঠায় "Success" —
        // আগে "ok" এর সাথে মেলানো হচ্ছিল, তাই সফল রেসপন্সও "invalid" ধরে নিচ্ছিল
        if (!isset($body->status) || strtolower($body->status) !== 'success') {
            fit_home_log_sms_error('Balance check response invalid: ' . wp_remote_retrieve_body($response));
            return false;
        }

        // ✅ FIX: ব্যালেন্স $body->responseResult এ থাকে না — আসল রেসপন্সে
        // থাকে $body->data[0]->balance এর ভেতরে
        if (!isset($body->data[0]->balance)) {
            fit_home_log_sms_error('Balance check response missing balance field: ' . wp_remote_retrieve_body($response));
            return false;
        }

        return $body->data[0]->balance;

    } catch (Throwable $e) {
        fit_home_log_sms_error('Balance check exception: ' . $e->getMessage());
        return false;
    }
}


// =========================================================================
// ফোন নম্বর ফরম্যাট হেল্পার - 880XXXXXXXXXX ফরম্যাটে (কোনো + ছাড়া)
// =========================================================================
function fit_home_format_phone($phone) {
    $clean = preg_replace('/[^0-9]/', '', $phone);
    if (substr($clean, 0, 2) !== '88') {
        $clean = '88' . $clean;
    }
    return $clean;
}


// =========================================================================
// SMS API CALLER - MiMSMS V2/SMS (Transactional, single recipient), fail-safe wrapper
// =========================================================================
function fit_home_call_sms_api($phone, $message) {

    if (empty(FITHOME_SMS_API_KEY) || FITHOME_SMS_API_KEY === 'YOUR_MIMSMS_API_KEY') {
        fit_home_log_sms_error('MiMSMS API Key সেট করা হয়নি।');
        return false;
    }

    $payload = array(
        'apiKey'          => FITHOME_SMS_API_KEY,
        'userName'        => FITHOME_SMS_USERNAME,
        'senderName'      => FITHOME_SMS_SENDER_NAME,
        'campaignName'    => 'FitHome SMS', // ডকুমেন্টেশনে "Optional" বলা থাকলেও লাইভ সার্ভারে আসলে এটা required, তাই বসিয়ে দেওয়া হলো
        'transactionType' => 'T', // Transactional - max 1 receiver per কল
        'mobileNumber'    => $phone,
        'message'         => $message,
    );

    try {
        $response = wp_remote_post(FITHOME_SMS_SEND_URL, array(
            'timeout' => 8,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode($payload),
        ));

        // নেটওয়ার্ক লেভেলেই ব্যর্থ হলে (timeout, DNS ফেইল ইত্যাদি)
        if (is_wp_error($response)) {
            fit_home_log_sms_error('Request failed: ' . $response->get_error_message());
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body_raw    = wp_remote_retrieve_body($response);

        if ($status_code < 200 || $status_code >= 300) {
            fit_home_log_sms_error("Gateway response (HTTP {$status_code}): " . $body_raw);
            return false;
        }

        // MiMSMS success হলে "status": "Success", ফেইল হলে "status": "Failed" রিটার্ন করে
        $data = json_decode($body_raw);

        if (!isset($data->status) || strtolower($data->status) !== 'success') {
            $err_msg = isset($data->responseResult) ? $data->responseResult : 'Unknown error';
            fit_home_log_sms_error("SMS পাঠাতে ব্যর্থ: {$err_msg} | Response: " . $body_raw);
            return false;
        }

        return true;

    } catch (Throwable $e) {
        // কোনো অপ্রত্যাশিত error/exception হলেও সাইট ক্র্যাশ করবে না
        fit_home_log_sms_error('Exception: ' . $e->getMessage());
        return false;
    }
}


// =========================================================================
// ERROR LOGGER - sbmart-child থিম ফোল্ডারের ভিতরে sms-log.txt এ সেভ হবে
// (ফাইলটা নিজে থেকেই তৈরি হয়ে যায়, ম্যানুয়ালি কিছু তৈরি করার দরকার নেই)
// =========================================================================
function fit_home_log_sms_error($message) {

    $log_file  = get_stylesheet_directory() . '/sms-log.txt';
    $timestamp = date('Y-m-d H:i:s');
    $line      = "[{$timestamp}] {$message}" . PHP_EOL;

    // ফাইল ১ MB এর বেশি বড় হয়ে গেলে পুরনো লগ ছেঁটে শুধু শেষ ৫০০ লাইন রাখা হবে
    if (file_exists($log_file) && filesize($log_file) > 1024 * 1024) {
        $lines = file($log_file);
        if ($lines !== false) {
            $lines = array_slice($lines, -500);
            file_put_contents($log_file, implode('', $lines));
        }
    }

    // FILE_APPEND এর সাথে ফাইল না থাকলে file_put_contents নিজে থেকেই ফাইলটা তৈরি করে নেয়
    file_put_contents($log_file, $line, FILE_APPEND | LOCK_EX);

    // WordPress এর debug.log এও ব্যাকআপ হিসেবে লেখা থাকবে
    if (function_exists('error_log')) {
        error_log('[Fit Home SMS] ' . $message);
    }
}