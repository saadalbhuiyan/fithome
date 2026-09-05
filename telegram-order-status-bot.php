<?php
// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

// =========================================================================
// ⚙️ BOT TOKEN — Fit Home Order Status Bot (@fithomeorderstatusbot)
// =========================================================================
define('FITHOME_STATUS_BOT_TOKEN', '8384815603:AAFHPBlT3DBq379RW8q9x8reGAd6p_-brf4');

// একটা ফোন নাম্বারে সর্বোচ্চ কয়টা সাম্প্রতিক অর্ডার দেখানো হবে
define('FITHOME_STATUS_BOT_MAX_ORDERS', 3);

// কত দিন আগে পর্যন্ত অর্ডার খোঁজা হবে
define('FITHOME_STATUS_BOT_SEARCH_DAYS', 90);

// =========================================================================
// 📤 HELPER: Telegram এ মেসেজ পাঠানো
// =========================================================================
function fithome_status_bot_send_message($chat_id, $text) {
    wp_remote_post("https://api.telegram.org/bot" . FITHOME_STATUS_BOT_TOKEN . "/sendMessage", [
        'body'    => [ 'chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML' ],
        'timeout' => 15,
    ]);
}

// =========================================================================
// 🏷️ SteadFast delivery_status কী-গুলোকে বাংলা লেবেলে রূপান্তর
// =========================================================================
function fithome_status_bot_labels() {
    return array(
        'pending'                            => 'পেন্ডিং',
        // ✅ CHANGED: in_review এর মানে স্পষ্ট করা হলো — অর্ডার Steadfast-এ
        // কনফার্ম/বুক হয়ে গেছে, কিন্তু এখনো কুরিয়ারে ডেলিভারির জন্য পাঠানো হয়নি
        'in_review'                          => 'রিভিউ চলছে / কনফার্ম হয়েছে, এখনো ডেলিভারিতে পাঠানো হয়নি',
        'delivered_approval_pending'         => 'ডেলিভারড (অ্যাপ্রুভাল বাকি)',
        'partial_delivered_approval_pending' => 'আংশিক ডেলিভারড (অ্যাপ্রুভাল বাকি)',
        'cancelled_approval_pending'         => 'ক্যান্সেল (অ্যাপ্রুভাল বাকি)',
        'unknown_approval_pending'           => 'অজানা (অ্যাপ্রুভাল বাকি)',
        'delivered'                          => '✅ ডেলিভারড',
        'partial_delivered'                  => '🔶 আংশিক ডেলিভারড',
        'cancelled'                          => '❌ ক্যান্সেলড',
        'hold'                               => '⏸️ হোল্ডে আছে',
        'unknown'                            => 'অজানা',
    );
}

// =========================================================================
// 🔍 মেসেজ থেকে ফোন নাম্বার বের করা (বাংলা ডিজিট/স্পেস/হাইফেন/+৮৮ হ্যান্ডল করে)
// =========================================================================
function fithome_status_bot_extract_phone($text) {
    $bn_digits = array('০','১','২','৩','৪','৫','৬','৭','৮','৯');
    $en_digits = array('0','1','2','3','4','5','6','7','8','9');
    $clean = preg_replace('/[\s\-\+]/', '', str_replace($bn_digits, $en_digits, $text));

    if (preg_match('/(?:88)?(01[3-9]\d{8})/', $clean, $m)) {
        return $m[1];
    }
    return '';
}

// =========================================================================
// 🔎 ফোন নাম্বার দিয়ে সাম্প্রতিক WooCommerce অর্ডার খোঁজা (HPOS + legacy সেফ)
// =========================================================================
function fithome_status_bot_find_orders($phone, $days, $max_results) {
    $today      = current_time('Y-m-d');
    $since_date = date('Y-m-d', strtotime("-{$days} days", current_time('timestamp')));

    $order_ids = wc_get_orders(array(
        'limit'        => -1,
        'orderby'      => 'date',
        'order'        => 'DESC',
        'date_created' => $since_date . '...' . $today,
        'return'       => 'ids',
    ));

    $target_last10 = substr($phone, -10);
    $matches = array();

    foreach ($order_ids as $order_id) {
        $order = wc_get_order($order_id);
        if (!$order) continue;

        $order_phone_last10 = substr(preg_replace('/[^0-9]/', '', $order->get_billing_phone()), -10);
        if ($order_phone_last10 === $target_last10) {
            $matches[] = $order;
            if (count($matches) >= $max_results) break;
        }
    }

    return $matches;
}

// =========================================================================
// 🧾 একটা অর্ডারের জন্য রিপ্লাই-অংশ বানানো (নাম, ঠিকানা, বিল, ও SteadFast
// প্লাগইনের নিজস্ব ফাংশন stdf_get_status_by_consignment_id() reuse করে
// লাইভ ডেলিভারি স্ট্যাটাস)
// =========================================================================
function fithome_status_bot_format_order($order) {
    $order_id  = $order->get_id();
    $date_str  = $order->get_date_created() ? $order->get_date_created()->date('d M, h:i A') : '';
    $total     = number_format((float) $order->get_total(), 0);

    // ✅ NEW: নাম ও ঠিকানা
    $name    = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
    $address = trim($order->get_billing_address_1());
    $city    = trim($order->get_billing_city());
    if ($city !== '') {
        $address = $address !== '' ? $address . ', ' . $city : $city;
    }

    $line  = "🧾 <b>Order #{$order_id}</b> ({$date_str})\n";
    $line .= "👤 নাম: " . ($name !== '' ? esc_html($name) : 'উল্লেখ নেই') . "\n";
    $line .= "🏠 ঠিকানা: " . ($address !== '' ? esc_html($address) : 'উল্লেখ নেই') . "\n";
    $line .= "💰 বিল: ৳{$total}\n";

    $is_sent        = get_post_meta($order_id, 'steadfast_is_sent', true);
    $consignment_id = get_post_meta($order_id, 'steadfast_consignment_id', true);

    if ($is_sent !== 'yes' || empty($consignment_id)) {
        $line .= "⚠️ এখনো Steadfast-এ পাঠানো হয়নি (কল করে কনফার্ম করে Send করা বাকি)\n";
        return $line;
    }

    if (!function_exists('stdf_get_status_by_consignment_id')) {
        $line .= "❌ SteadFast প্লাগইন খুঁজে পাওয়া যায়নি বা অ্যাক্টিভ নেই।\n";
        return $line;
    }

    $live   = stdf_get_status_by_consignment_id($consignment_id);
    $labels = fithome_status_bot_labels();

    if (is_array($live) && isset($live['delivery_status'])) {
        $status_key = $live['delivery_status'];
        update_post_meta($order_id, 'stdf_delivery_status', $status_key);
    } else {
        $status_key = get_post_meta($order_id, 'stdf_delivery_status', true);

        if (empty($status_key)) {
            if ($live === 'unauthorized') {
                $line .= "❌ SteadFast API authorization ব্যর্থ — Settings-এ API Key/Secret বা Enable টগল চেক করুন।\n";
            } else {
                $line .= "⚠️ এই মুহূর্তে SteadFast থেকে লাইভ স্ট্যাটাস আনা যায়নি, একটু পরে আবার চেষ্টা করুন।\n";
            }
            return $line;
        }
        $line .= "<i>⚠️ লাইভ চেক ব্যর্থ, শেষ জানা স্ট্যাটাস দেখানো হচ্ছে:</i>\n";
    }

    $status_label = isset($labels[$status_key]) ? $labels[$status_key] : $status_key;
    $line .= "📦 CN ID: <code>{$consignment_id}</code>\n";
    $line .= "🚚 স্ট্যাটাস: <b>{$status_label}</b>\n";

    return $line;
}

// =========================================================================
// 🌐 WEBHOOK ENDPOINT REGISTRATION
// =========================================================================
add_action('rest_api_init', 'fithome_register_status_bot_webhook');
function fithome_register_status_bot_webhook() {
    register_rest_route('fithome/v1', '/status-bot', array(
        'methods'             => 'POST',
        'callback'            => 'fithome_status_bot_webhook_handler',
        'permission_callback' => '__return_true'
    ));
}

function fithome_status_bot_webhook_handler($request) {
    $body = $request->get_json_params();

    if (!isset($body['message']['text']) || !isset($body['message']['chat']['id'])) {
        return new WP_REST_Response('Success', 200);
    }

    $chat_id = strval($body['message']['chat']['id']);
    $text    = trim($body['message']['text']);

    // ✅ Authorization — Order Creation Bot এর existing মডারেটর লিস্টই reuse করা হচ্ছে
    $moderator_name = function_exists('fithome_get_moderator_name') ? fithome_get_moderator_name($chat_id) : false;
    if ($moderator_name === false) {
        fithome_status_bot_send_message($chat_id, "❌ আপনি অথরাইজড মডারেটর নন। অ্যাডমিনের সাথে যোগাযোগ করুন।");
        return new WP_REST_Response('Unauthorized', 403);
    }

    if ($text === '/start') {
        fithome_status_bot_send_message(
            $chat_id,
            "👋 হ্যালো " . esc_html($moderator_name) . "!\nযেকোনো কাস্টমারের ফোন নাম্বার পাঠান (যেমন 01712345678), তার অর্ডারের বর্তমান Steadfast ডেলিভারি স্ট্যাটাস দেখিয়ে দেব।"
        );
        return new WP_REST_Response('Started', 200);
    }

    $phone = fithome_status_bot_extract_phone($text);

    if (empty($phone)) {
        fithome_status_bot_send_message($chat_id, "⚠️ সঠিক ফরম্যাটে একটা ফোন নাম্বার পাঠান (01XXXXXXXXX)।");
        return new WP_REST_Response('Invalid Input', 200);
    }

    if (!class_exists('WooCommerce')) {
        fithome_status_bot_send_message($chat_id, "❌ WooCommerce active নেই।");
        return new WP_REST_Response('No WooCommerce', 200);
    }

    wp_remote_post("https://api.telegram.org/bot" . FITHOME_STATUS_BOT_TOKEN . "/sendChatAction", [
        'body' => [ 'chat_id' => $chat_id, 'action' => 'typing' ]
    ]);

    $orders = fithome_status_bot_find_orders($phone, FITHOME_STATUS_BOT_SEARCH_DAYS, FITHOME_STATUS_BOT_MAX_ORDERS);

    if (empty($orders)) {
        fithome_status_bot_send_message(
            $chat_id,
            "❌ <code>{$phone}</code> — এই নাম্বার থেকে কোনো অর্ডার পাওয়া যায়নি (গত " . FITHOME_STATUS_BOT_SEARCH_DAYS . " দিনে)।"
        );
        return new WP_REST_Response('No Orders', 200);
    }

    $reply = "🔍 <b>নাম্বার:</b> <code>{$phone}</code>\n━━━━━━━━━━━━━━━━━\n\n";
    foreach ($orders as $order) {
        $reply .= fithome_status_bot_format_order($order) . "\n";
    }

    fithome_status_bot_send_message($chat_id, rtrim($reply));
    return new WP_REST_Response('Status Sent', 200);
}