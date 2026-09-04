<?php
// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

// =========================================================================
// ⚙️ BOT TOKEN (Order Creation Bot)
// ⚠️ NOTE: constant নাম আলাদা রাখা হয়েছে (FITHOME_ORDER_CREATE_BOT_TOKEN)
// কারণ telegram-bot.php তে আগে থেকেই FITHOME_ORDER_BOT_TOKEN নামে
// Bot 1 (Orders Notification) এর টোকেন define করা আছে।
// =========================================================================
define('FITHOME_ORDER_CREATE_BOT_TOKEN', '8789615291:AAFlcX2pUT4xzEzEtpRcq-aQ4aTffK2jol0');

// Pending confirmation কতক্ষণ valid থাকবে (সেকেন্ড) — ৫ মিনিট
define('FITHOME_ORDER_BOT_PENDING_TTL', 300);

// ✅ প্রোডাক্ট প্রাইস রেঞ্জ — COD লেবেল ছাড়া থাকলে এই রেঞ্জের সংখ্যাকেই
// সম্ভাব্য COD amount হিসেবে গেস করা হবে (Daily Protein প্যাকেজ: ৯৯০-২৫৪৯tk)
define('FITHOME_ORDER_BOT_MIN_COD', 700);
define('FITHOME_ORDER_BOT_MAX_COD', 3000);

// একই ফোন নম্বরে কত ঘণ্টার মধ্যে অর্ডার থাকলে "ডুপ্লিকেট হতে পারে" ওয়ার্নিং দেখাবে
define('FITHOME_ORDER_BOT_DUPLICATE_WINDOW_HOURS', 48);

// =========================================================================
// 🧠 GROQ AI (প্রধান extraction পদ্ধতি) — ফ্রি Groq API key, OpenAI-compatible
//
// ✅ CHANGED (৩ ধাপের fallback chain):
//   ধাপ ১ → PRIMARY মডেল (দ্রুত, হালকা)
//   ধাপ ২ → BACKUP মডেল (প্রথমটা fail করলে, বড়/বেশি নির্ভরযোগ্য)
//   ধাপ ৩ → regex-ভিত্তিক পুরনো লজিক (দুটো মডেলই fail করলে)
//
// কারণ: Groq মাঝে মাঝে মডেল deprecate/বন্ধ করে দেয় (যেমন
// llama-3.3-70b-versatile বন্ধ হওয়ায় HTTP 404 model_not_found আসছিল, আর
// প্রতিটা অর্ডারই চুপচাপ regex-এ নেমে যাচ্ছিল)। দুটো মডেল রাখলে একটা বন্ধ
// হলেও বট AI দিয়েই চলতে থাকবে।
//
// ⚠️ দুটো মডেলের জন্য আলাদা API key লাগে না — একই key দিয়েই সব মডেল চলে।
// ⚠️ মডেলের নাম বদলানোর আগে নিশ্চিত হয়ে নাও কোনগুলো available:
//    curl https://api.groq.com/openai/v1/models -H "Authorization: Bearer YOUR_KEY"
// =========================================================================
define('FITHOME_GROQ_API_KEY', 'apikey');

// ধাপ ১: প্রাইমারি — ছোট ও দ্রুত, তোমার মতো ছোট extraction টাস্কের জন্য যথেষ্ট
define('FITHOME_GROQ_MODEL_PRIMARY', 'openai/gpt-oss-20b');

// ধাপ ২: ব্যাকআপ — প্রাইমারি fail করলে (বন্ধ হয়ে গেলে / rate limit / এলোমেলো টেক্সট)
define('FITHOME_GROQ_MODEL_BACKUP',  'openai/gpt-oss-120b');

// ⚠️ টেস্টিং শেষ হলে false করে দিন — true থাকলে AI fail করলে প্রিভিউ
// মেসেজে ঠিক কী কারণে fail করলো সেটা দেখাবে (ডিবাগ করতে সুবিধার জন্য)
define('FITHOME_AI_DEBUG', true);

// =========================================================================
// 📤 HELPER: Telegram এ মেসেজ পাঠানো
// =========================================================================
function fithome_order_bot_send_message($chat_id, $text) {
    wp_remote_post("https://api.telegram.org/bot" . FITHOME_ORDER_CREATE_BOT_TOKEN . "/sendMessage", [
        'body'    => [ 'chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML' ],
        'timeout' => 15,
    ]);
}

// =========================================================================
// 🏷️ HELPER: extraction সোর্সকে পড়ার উপযোগী লেবেলে রূপান্তর
//
// ✅ NEW: কনফার্মেশন মেসেজে দেখাবে ডেটাটা কোথা থেকে এসেছে — প্রাইমারি AI,
// ব্যাকআপ AI, নাকি regex। এটা FITHOME_AI_DEBUG এর উপর নির্ভর করে না, তাই
// ডিবাগ বন্ধ থাকলেও প্রাইমারি মডেল বন্ধ হয়ে গেলে সাথে সাথেই টের পাওয়া যাবে।
// =========================================================================
function fithome_order_bot_source_label($source) {
    if ($source === 'regex') {
        return "⚙️ <b>এক্সট্রাকশন:</b> Regex (দুটো AI মডেলই fail করেছে — ডেটা ভালো করে মিলিয়ে নিন)";
    }
    if ($source === 'ai:' . FITHOME_GROQ_MODEL_PRIMARY) {
        return "🧠 <b>এক্সট্রাকশন:</b> AI প্রাইমারি (" . FITHOME_GROQ_MODEL_PRIMARY . ")";
    }
    if ($source === 'ai:' . FITHOME_GROQ_MODEL_BACKUP) {
        return "🔁 <b>এক্সট্রাকশন:</b> AI ব্যাকআপ (" . FITHOME_GROQ_MODEL_BACKUP . ") — প্রাইমারি fail করেছে";
    }
    return "❔ <b>এক্সট্রাকশন:</b> অজানা সোর্স";
}

// =========================================================================
// 🧠 GROQ দিয়ে ডেটা এক্সট্রাকশন (OpenAI-compatible chat completions API)
//
// ✅ CHANGED: এখন ফাংশনটা দ্বিতীয় প্যারামিটার হিসেবে $model নেয়, যাতে একই
// ফাংশন দিয়ে প্রাইমারি ও ব্যাকআপ — দুটো মডেলই কল করা যায়।
// fail করলে false রিটার্ন করে, আর কারণটা $failure_reason এ রেখে যায়।
// =========================================================================
function fithome_extract_order_data_via_groq($raw_text, $model, &$failure_reason = '') {
    $failure_reason = '';

    if (empty(FITHOME_GROQ_API_KEY) || FITHOME_GROQ_API_KEY === 'PASTE_YOUR_GROQ_API_KEY_HERE') {
        $failure_reason = 'API key বসানো নেই';
        return false;
    }

    $system_prompt = "তুমি একজন ডেটা এক্সট্রাকশন অ্যাসিস্ট্যান্ট। ব্যবহারকারী একটা বাংলা/ইংরেজি "
        . "মিশ্র, এলোমেলো, যেকোনো ফরম্যাটের অর্ডার টেক্সট দেবে। সেখান থেকে কাস্টমারের অর্ডার তথ্য "
        . "বের করে শুধু JSON আকারে ফেরত দাও — কোনো ব্যাখ্যা, কোনো markdown কোড ব্লক, কোনো অতিরিক্ত "
        . "টেক্সট দিও না, শুধু raw JSON অবজেক্ট।\n\n"
        . "ফিল্ড:\n"
        . "- name: কাস্টমারের নাম (string; লেবেল/ইমোজি/বুলেট নাম্বার বাদ দিয়ে শুধু আসল নাম; না পেলে খালি স্ট্রিং)\n"
        . "- phone: বাংলাদেশি মোবাইল নম্বর, অবশ্যই 01XXXXXXXXX ফরম্যাটে (১১ ডিজিট, ইংরেজি সংখ্যায়); "
        . "একাধিক নম্বর থাকলে প্রথমটা নাও; বাংলা সংখ্যা/স্পেস/হাইফেন/+৮৮ থাকলেও ক্লিন করে দাও; না পেলে খালি স্ট্রিং\n"
        . "- address: শুধু প্রকৃত ঠিকানা (বাড়ি/রোড/এলাকা/উপজেলা/থানা/জেলা) — ফিল্ড-লেবেল টেক্সট (যেমন 'জেলা:', "
        . "'এলাকা:'), ইমোজি, বুলেট নাম্বার, আর প্রোমোশনাল/বয়লারপ্লেট লাইন (যেমন 'সারা বাংলাদেশে ক্যাশ অন ডেলিভারি "
        . "সুবিধা রয়েছে') বাদ দিয়ে শুধু ঠিকানার অংশটুকু পরিষ্কার করে কমা দিয়ে সাজাও; না পেলে খালি স্ট্রিং\n"
        . "- cod: টাকার অংক, সংখ্যা হিসেবে (number, টাকা চিহ্ন/কমা ছাড়া); 'k'/'হাজার' থাকলে ১০০০ দিয়ে গুণ করো; "
        . "লেবেল (COD/দাম/বিল/প্যাকেজ মূল্য) না থাকলে টেক্সট থেকে প্রোডাক্টের দাম বলে মনে হয় এমন সংখ্যা (সাধারণত "
        . "৭০০-৩০০০ টাকার মধ্যে) অনুমান করো, কিন্তু postal code/বাড়ি-রোড নম্বরকে দাম বলে ভুল কোরো না; না পেলে 0\n\n"
        . "উত্তর অবশ্যই এই ফরম্যাটে দাও: {\"name\": \"...\", \"phone\": \"...\", \"address\": \"...\", \"cod\": 0}";

    $body = array(
        'model'           => $model,
        'temperature'     => 0,
        'response_format' => array('type' => 'json_object'),
        'messages'        => array(
            array('role' => 'system', 'content' => $system_prompt),
            array('role' => 'user',   'content' => $raw_text),
        ),
    );

    $response = wp_remote_post('https://api.groq.com/openai/v1/chat/completions', array(
        'headers' => array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . FITHOME_GROQ_API_KEY,
        ),
        'body'    => wp_json_encode($body),
        'timeout' => 20,
    ));

    // নেটওয়ার্ক এরর বা non-200 রেসপন্স হলে fallback এর জন্য false রিটার্ন
    if (is_wp_error($response)) {
        $failure_reason = 'নেটওয়ার্ক এরর: ' . $response->get_error_message();
        return false;
    }
    $http_code = wp_remote_retrieve_response_code($response);
    if ($http_code !== 200) {
        $failure_reason = "HTTP {$http_code}: " . substr(wp_remote_retrieve_body($response), 0, 200);
        return false;
    }

    $result    = json_decode(wp_remote_retrieve_body($response), true);
    $json_text = isset($result['choices'][0]['message']['content'])
        ? $result['choices'][0]['message']['content'] : '';
    if (empty($json_text)) {
        $failure_reason = 'খালি রেসপন্স';
        return false;
    }

    $parsed = json_decode($json_text, true);
    if (!is_array($parsed) || !array_key_exists('name', $parsed) || !array_key_exists('phone', $parsed)
        || !array_key_exists('address', $parsed) || !array_key_exists('cod', $parsed)) {
        $failure_reason = 'অপ্রত্যাশিত JSON: ' . substr($json_text, 0, 200);
        return false;
    }

    // ফোন নম্বর ভ্যালিডেট করা — AI ভুল ফরম্যাটে দিলে regex দিয়ে raw টেক্সট থেকে আবার চেষ্টা
    $phone = fithome_normalize_bangla_digits(trim($parsed['phone']));
    if (!preg_match('/^01[3-9]\d{8}$/', $phone)) {
        $phone = fithome_extract_phone_from_text(fithome_normalize_bangla_digits($raw_text));
        if (empty($phone)) {
            $failure_reason = 'ভ্যালিড ফোন নম্বর পাওয়া যায়নি';
            return false; // কোনোভাবেই ফোন না পেলে পুরো রেজাল্ট বাতিল
        }
    }

    return array(
        'name'    => !empty(trim($parsed['name'])) ? trim($parsed['name']) : 'অজানা (ম্যানুয়ালি চেক করুন)',
        'phone'   => $phone,
        'address' => !empty(trim($parsed['address'])) ? trim($parsed['address']) : 'উল্লেখ নেই',
        'cod'     => floatval($parsed['cod']),
    );
}

// =========================================================================
// 🔀 SMART WRAPPER — ৩ ধাপ: প্রাইমারি মডেল → ব্যাকআপ মডেল → regex fallback
//
// রিটার্ন করা array তে 'source' ফিল্ড থাকে:
//   'ai:openai/gpt-oss-20b'  → প্রাইমারি মডেল কাজ করেছে
//   'ai:openai/gpt-oss-120b' → ব্যাকআপ মডেল কাজ করেছে
//   'regex'                  → দুটোই fail, পুরনো লজিক চলেছে
// =========================================================================
function fithome_extract_order_data_smart($raw_text) {
    $GLOBALS['fithome_ai_debug'] = '';
    $debug_log = array();

    // ধাপ ১ — প্রাইমারি মডেল
    $reason = '';
    $result = fithome_extract_order_data_via_groq($raw_text, FITHOME_GROQ_MODEL_PRIMARY, $reason);
    if ($result !== false) {
        $result['source'] = 'ai:' . FITHOME_GROQ_MODEL_PRIMARY;
        return $result;
    }
    $debug_log[] = FITHOME_GROQ_MODEL_PRIMARY . ' → ' . $reason;

    // ধাপ ২ — ব্যাকআপ মডেল
    $reason = '';
    $result = fithome_extract_order_data_via_groq($raw_text, FITHOME_GROQ_MODEL_BACKUP, $reason);
    if ($result !== false) {
        $result['source'] = 'ai:' . FITHOME_GROQ_MODEL_BACKUP;
        // প্রাইমারি কেন fail করলো সেটা ডিবাগে রেখে দেওয়া হচ্ছে (ব্যাকআপ কাজ করলেও
        // প্রাইমারি বন্ধ হয়ে থাকলে সেটা জানা দরকার, যাতে constant আপডেট করা যায়)
        $GLOBALS['fithome_ai_debug'] = implode(' | ', $debug_log);
        return $result;
    }
    $debug_log[] = FITHOME_GROQ_MODEL_BACKUP . ' → ' . $reason;

    // ধাপ ৩ — দুটো মডেলই fail, regex fallback
    $GLOBALS['fithome_ai_debug'] = implode(' | ', $debug_log);
    $regex_result = fithome_parse_order_text($raw_text);
    $regex_result['source'] = 'regex';
    return $regex_result;
}

// =========================================================================
// 🔁 ডুপ্লিকেট অর্ডার চেক — এই ফোন নম্বরে গত ৪৮ ঘণ্টায় (Website বা
// Messenger, যেকোনো মডারেটরের) কোনো অর্ডার আগে থেকে আছে কিনা খুঁজে বের করা
// =========================================================================
function fithome_find_recent_order_by_phone($phone) {
    if (empty($phone)) return null;

    $since_timestamp = time() - (FITHOME_ORDER_BOT_DUPLICATE_WINDOW_HOURS * HOUR_IN_SECONDS);

    // HPOS (নতুন Custom Order Tables) চালু থাকলে ফোন নম্বর আলাদা postmeta হিসেবে
    // না থেকে সরাসরি অর্ডার টেবিলের কলামে থাকে — তাই meta_query কোনো ম্যাচ পেত না।
    // এখন দিন-ভিত্তিক রেঞ্জ দিয়ে সব অর্ডার এনে, প্রতিটার get_billing_phone()
    // (যেটা HPOS/legacy দুটোতেই নির্ভরযোগ্য) দিয়ে সরাসরি মিলিয়ে দেখা হচ্ছে।
    $today      = current_time('Y-m-d');
    $since_date = date('Y-m-d', $since_timestamp - DAY_IN_SECONDS); // এক দিন বাফার

    $order_ids = wc_get_orders(array(
        'limit'        => -1,
        'orderby'      => 'date',
        'order'        => 'DESC',
        'date_created' => $since_date . '...' . $today,
        'return'       => 'ids',
    ));

    if (empty($order_ids)) return null;

    // শুধু ডিজিট রেখে শেষ ১০ ডিজিট মিলিয়ে তুলনা করা হচ্ছে
    $target_last10 = substr(preg_replace('/[^0-9]/', '', $phone), -10);
    if (strlen($target_last10) < 10) return null;

    foreach ($order_ids as $order_id) {
        $order = wc_get_order($order_id);
        if (!$order) continue;

        $created = $order->get_date_created();
        if (!$created || $created->getTimestamp() < $since_timestamp) continue;

        $order_phone_last10 = substr(preg_replace('/[^0-9]/', '', $order->get_billing_phone()), -10);
        if ($order_phone_last10 === $target_last10) {
            return $order;
        }
    }

    return null;
}

// পুরনো অর্ডারটার তথ্য দিয়ে একটা readable ওয়ার্নিং লাইন বানানো
// ✅ CHANGED: ১ ঘণ্টার কম হলে আগে "0 ঘণ্টা আগে" দেখাত, যেটা অদ্ভুত লাগত।
// এখন ১ ঘণ্টার কম হলে মিনিটে দেখাবে।
function fithome_build_duplicate_warning_line($order) {
    $seconds_ago = time() - $order->get_date_created()->getTimestamp();

    if ($seconds_ago < 3600) {
        $minutes = max(1, round($seconds_ago / 60));
        $when = "{$minutes} মিনিট আগে";
    } else {
        $hours = round($seconds_ago / 3600);
        $when = "{$hours} ঘণ্টা আগে";
    }

    if ($order->get_meta('_created_via_telegram_bot') === 'yes') {
        $mod_name = $order->get_meta('_telegram_moderator_name');
        $source   = 'Messenger (মডারেটর: ' . esc_html($mod_name) . ')';
    } else {
        $source = 'Website';
    }

    return "\n⚠️ <b>ডুপ্লিকেট!</b> এই নম্বর থেকে অর্ডার #" . $order->get_id()
         . " মাত্র {$when} তৈরি হয়েছে (সোর্স: {$source})";
}

// =========================================================================
// 🌐 WEBHOOK ENDPOINT REGISTRATION
// =========================================================================
add_action('rest_api_init', 'fithome_register_order_bot_webhook');
function fithome_register_order_bot_webhook() {
    register_rest_route('fithome/v1', '/order-bot', array(
        'methods'             => 'POST',
        'callback'            => 'fithome_order_bot_webhook_handler',
        'permission_callback' => '__return_true'
    ));
}

function fithome_order_bot_webhook_handler($request) {
    $body = $request->get_json_params();

    if (!isset($body['message']['text']) || !isset($body['message']['chat']['id'])) {
        return new WP_REST_Response('Success', 200);
    }

    $chat_id = strval($body['message']['chat']['id']);
    $text    = trim($body['message']['text']);

    // ✅ Authorization: শুধু রেজিস্টার্ড মডারেটর ব্যবহার করতে পারবে
    $moderator_name = fithome_get_moderator_name($chat_id);
    if ($moderator_name === false) {
        fithome_order_bot_send_message($chat_id, "❌ আপনি অথরাইজড মডারেটর নন। অ্যাডমিনের সাথে যোগাযোগ করুন।");
        return new WP_REST_Response('Unauthorized', 403);
    }

    // ১. কনফার্মেশন মেসেজ (1 / Ok / হ্যাঁ)
    if (preg_match('/^(1|ok|okay|ওকে|হ্যাঁ)$/iu', $text)) {
        fithome_order_bot_confirm_and_create($chat_id, $moderator_name);
        return new WP_REST_Response('Order Confirmed', 200);
    }

    // ২. স্ট্যাটস কমান্ড (/daily, /weekly, /monthly, /N)
    if (strpos($text, '/') === 0) {
        fithome_order_bot_handle_stats_command($text, $chat_id, $moderator_name);
        return new WP_REST_Response('Stats Sent', 200);
    }

    // ৩. নতুন অর্ডার টেক্সট → extract করে প্রিভিউ পাঠানো
    // (প্রাইমারি মডেল → ব্যাকআপ মডেল → regex fallback)
    $parsed = fithome_extract_order_data_smart($text);

    if (empty($parsed['phone'])) {
        fithome_order_bot_send_message($chat_id, "⚠️ মোবাইল নম্বর খুঁজে পাওয়া যায়নি। মেসেজে সঠিক ফরম্যাটে (01XXXXXXXXX) নম্বর দিয়ে আবার পাঠান।");
        return new WP_REST_Response('Missing Phone', 200);
    }

    set_transient('fithome_pending_order_' . $chat_id, $parsed, FITHOME_ORDER_BOT_PENDING_TTL);

    $warnings = '';
    if ($parsed['cod'] <= 0) {
        $warnings .= "\n⚠️ <b>COD amount পাওয়া যায়নি</b> — নিশ্চিত করে চেক করুন!";
    }
    if ($parsed['name'] === 'অজানা (ম্যানুয়ালি চেক করুন)') {
        $warnings .= "\n⚠️ <b>নাম নিশ্চিতভাবে বোঝা যায়নি</b> — মিলিয়ে দেখুন!";
    }

    // গত ৪৮ ঘণ্টায় এই নম্বরে অর্ডার আছে কিনা চেক করা (Website + Messenger দুটোই)
    $duplicate_order = fithome_find_recent_order_by_phone($parsed['phone']);
    if ($duplicate_order) {
        $warnings .= fithome_build_duplicate_warning_line($duplicate_order);
    }

    // ✅ NEW: প্রিভিউতেও দেখানো হচ্ছে ডেটা কোন সোর্স থেকে এসেছে
    $warnings .= "\n" . fithome_order_bot_source_label($parsed['source']);

    // ✅ CHANGED: ডিবাগ মোডে এখন দেখাবে — কোন মডেল কাজ করলো, আর কোনটা কেন fail করলো
    if (FITHOME_AI_DEBUG) {
        if ($parsed['source'] === 'regex') {
            $warnings .= "\n🐞 <b>দুটো AI মডেলই fail:</b> " . esc_html($GLOBALS['fithome_ai_debug']);
        } elseif (!empty($GLOBALS['fithome_ai_debug'])) {
            // ব্যাকআপ মডেল দিয়ে কাজ হয়েছে — প্রাইমারি কেন fail করলো সেটা দেখানো
            $warnings .= "\n🐞 <b>ব্যাকআপ মডেল ব্যবহার হয়েছে।</b> প্রাইমারি fail: " . esc_html($GLOBALS['fithome_ai_debug']);
        }
    }

    $preview = "📝 <b>নিচের তথ্য চেক করুন:</b>\n"
             . "━━━━━━━━━━━━━━━━━\n"
             . "👤 <b>নাম:</b> " . esc_html($parsed['name']) . "\n"
             . "☎️ <b>মোবাইল:</b> <code>" . esc_html($parsed['phone']) . "</code>\n"
             . "🏠 <b>ঠিকানা:</b> " . esc_html($parsed['address']) . "\n"
             . "💰 <b>COD:</b> ৳" . esc_html(number_format($parsed['cod'], 2))
             . $warnings . "\n"
             . "━━━━━━━━━━━━━━━━━\n"
             . "সব ঠিক থাকলে <b>1</b> বা <b>Ok</b> লিখে কনফার্ম করুন। (৫ মিনিটের মধ্যে কনফার্ম না করলে বাতিল হয়ে যাবে)";

    fithome_order_bot_send_message($chat_id, $preview);
    return new WP_REST_Response('Preview Sent', 200);
}

// =========================================================================
// ✅ CONFIRM & CREATE ORDER
// =========================================================================
function fithome_order_bot_confirm_and_create($chat_id, $moderator_name) {
    $pending = get_transient('fithome_pending_order_' . $chat_id);

    if (!$pending) {
        fithome_order_bot_send_message($chat_id, "⚠️ কোনো পেন্ডিং অর্ডার নেই বা মেয়াদ শেষ হয়ে গেছে। নতুন করে অর্ডারের তথ্য পাঠান।");
        return;
    }

    if (!class_exists('WooCommerce')) {
        fithome_order_bot_send_message($chat_id, "❌ WooCommerce active নেই।");
        return;
    }

    $order = wc_create_order();

    $order->set_address(array(
        'first_name' => $pending['name'],
        'phone'      => $pending['phone'],
        'address_1'  => $pending['address'],
        'country'    => 'BD',
    ), 'billing');

    // প্রোডাক্ট/quantity ম্যাপিং ছাড়াই সরাসরি COD amount একটা fee line item হিসেবে বসছে
    $fee = new WC_Order_Item_Fee();
    $fee->set_name('Order Amount');
    $fee->set_amount($pending['cod']);
    $fee->set_total($pending['cod']);
    $fee->set_tax_status('none');
    $order->add_item($fee);

    $order->set_payment_method('cod');
    $order->set_payment_method_title('Cash on delivery');
    $order->calculate_totals();

    // Order meta — Telegram সোর্স ট্র্যাকিং — status change করার *আগেই* সেভ করা
    // হচ্ছে (এবং আলাদা করে save() কল করা হচ্ছে)। কারণ update_status('processing')
    // নিজেই woocommerce_order_status_processing hook ফায়ার করে, যেটা শোনে
    // Bot 1 (নোটিফিকেশন) আর SMS সিস্টেম — মেটা আগে সেভ না থাকলে ওরা ভুল সোর্স
    // ("Website") দেখাতে পারে, রেস কন্ডিশনের মতো।
    $order->update_meta_data('_created_via_telegram_bot', 'yes');
    $order->update_meta_data('_telegram_moderator_chat_id', $chat_id);
    $order->update_meta_data('_telegram_moderator_name', $moderator_name);
    $order->save();

    $order->update_status('processing', 'Created via Telegram Order Bot by ' . $moderator_name . '.', true);

    delete_transient('fithome_pending_order_' . $chat_id);

    $today_count = fithome_get_moderator_order_count($chat_id, 'today');

    // ✅ NEW: ডেটা কোন সোর্স থেকে এসেছিল (প্রাইমারি AI / ব্যাকআপ AI / regex)
    // পুরনো transient এ 'source' না থাকলেও যাতে এরর না হয়, তাই isset চেক
    $source_line = isset($pending['source'])
        ? fithome_order_bot_source_label($pending['source']) . "\n"
        : '';

    $reply = "✅ <b>অর্ডার তৈরি হয়েছে!</b>\n"
           . "━━━━━━━━━━━━━━━━━\n"
           . "🆔 <b>অর্ডার নম্বর:</b> #" . $order->get_id() . "\n"
           . "👤 <b>নাম:</b> " . esc_html($pending['name']) . "\n"
           . "☎️ <b>মোবাইল:</b> <code>" . esc_html($pending['phone']) . "</code>\n"
           . "🏠 <b>ঠিকানা:</b> " . esc_html($pending['address']) . "\n"
           . "💰 <b>COD:</b> ৳" . esc_html(number_format($pending['cod'], 2)) . "\n"
           . $source_line
           . "━━━━━━━━━━━━━━━━━\n"
           . "📊 আজ আপনি মোট <b>{$today_count}</b> টি অর্ডার তৈরি করেছেন।";

    fithome_order_bot_send_message($chat_id, $reply);
}

// =========================================================================
// 🔍 EXTRACTION HELPERS — লেবেল থাকলে লেবেল দিয়ে, না থাকলে স্মার্ট গেস দিয়ে
// (regex fallback — AI দুটোই fail করলে এগুলোই চলবে)
// =========================================================================

// বাংলা সংখ্যাকে ইংরেজি সংখ্যায় কনভার্ট
function fithome_normalize_bangla_digits($text) {
    $bn_digits = array('০','১','২','৩','৪','৫','৬','৭','৮','৯');
    $en_digits = array('0','1','2','3','4','5','6','7','8','9');
    return str_replace($bn_digits, $en_digits, $text);
}

// ঠিকানায় সচরাচর থাকা শব্দ — এগুলো থাকলে লাইনটাকে/শব্দটাকে নাম হিসেবে গণ্য করা হবে না
function fithome_order_bot_address_keywords() {
    return array(
        // সাধারণ ঠিকানা-শব্দ
        'রোড', 'সড়ক', 'বাসা', 'বাড়ি', 'নং', 'নম্বর', 'এলাকা', 'থানা', 'জেলা',
        'উপজেলা', 'সেক্টর', 'ব্লক', 'ফ্ল্যাট', 'গ্রাম', 'ইউনিয়ন', 'ওয়ার্ড',
        'মহল্লা', 'কলোনি', 'গলি', 'লেন', 'এভিনিউ', 'ঠিকানা', 'apartment',
        'road', 'house', 'flat', 'sector', 'block', 'village', 'address',
        // ৬৪ জেলার নাম
        'বাগেরহাট', 'বান্দরবান', 'বরগুনা', 'বরিশাল', 'ভোলা', 'বগুড়া',
        'ব্রাহ্মণবাড়িয়া', 'চাঁদপুর', 'চাঁপাইনবাবগঞ্জ', 'চট্টগ্রাম', 'চুয়াডাঙ্গা',
        'কুমিল্লা', 'কক্সবাজার', 'ঢাকা', 'দিনাজপুর', 'ফরিদপুর', 'ফেনী',
        'গাইবান্ধা', 'গাজীপুর', 'গোপালগঞ্জ', 'হবিগঞ্জ', 'জামালপুর', 'যশোর',
        'ঝালকাঠি', 'ঝিনাইদহ', 'জয়পুরহাট', 'খাগড়াছড়ি', 'খুলনা', 'কিশোরগঞ্জ',
        'কুড়িগ্রাম', 'কুষ্টিয়া', 'লক্ষ্মীপুর', 'লালমনিরহাট', 'মাদারীপুর',
        'মাগুরা', 'মানিকগঞ্জ', 'মেহেরপুর', 'মৌলভীবাজার', 'মুন্সিগঞ্জ',
        'ময়মনসিংহ', 'নওগাঁ', 'নড়াইল', 'নারায়ণগঞ্জ', 'নরসিংদী', 'নাটোর',
        'নেত্রকোণা', 'নীলফামারী', 'নোয়াখালী', 'পাবনা', 'পঞ্চগড়', 'পটুয়াখালী',
        'পিরোজপুর', 'রাজবাড়ী', 'রাজশাহী', 'রাঙ্গামাটি', 'রংপুর', 'সাতক্ষীরা',
        'শরীয়তপুর', 'শেরপুর', 'সিরাজগঞ্জ', 'সুনামগঞ্জ', 'সিলেট', 'টাঙ্গাইল',
        'ঠাকুরগাঁও',
        // ঢাকার প্রধান এলাকা/থানা
        'হাটহাজারী', 'উত্তরা', 'মিরপুর', 'ধানমন্ডি', 'বনানী', 'মোহাম্মদপুর',
        'যাত্রাবাড়ী', 'বিয়ানীবাজার', 'গুলশান', 'বসুন্ধরা', 'বারিধারা',
        'শ্যামলী', 'ফার্মগেট', 'মালিবাগ', 'বাড্ডা', 'রামপুরা', 'খিলগাঁও',
        'ওয়ারী', 'শাহবাগ', 'নিউমার্কেট', 'আজিমপুর', 'লালবাগ', 'পল্টন',
        'মতিঝিল', 'ডেমরা', 'কেরানীগঞ্জ', 'সাভার', 'টঙ্গী', 'নন্দীরহাট',
    );
}

// ১. ফোন নম্বর — পুরো টেক্সটের যেকোনো জায়গায় খুঁজে বের করে, দুইটা থাকলে প্রথমটা নেয়
function fithome_extract_phone_from_text($text) {
    preg_match_all('/(?:\+?88)?(01[3-9]\d{8})/', $text, $matches);
    if (!empty($matches[1])) {
        return $matches[1][0];
    }
    return '';
}

// ফোন নম্বরটা টেক্সট থেকে সরিয়ে ফেলা
function fithome_strip_phone_from_text($text, $phone) {
    if (empty($phone)) return $text;
    $digits = str_split($phone);
    $flexible = implode('[\s\-]*', $digits);
    $pattern = '/(?:\+?88[\s\-]*)?' . $flexible . '/u';
    return preg_replace($pattern, ' ', $text, 1);
}

// ২. COD amount — লেবেল খোঁজা, না পেলে প্রাইস-রেঞ্জ (৭০০-৩০০০) দিয়ে গেস
function fithome_extract_and_strip_cod($text) {
    // (ক) লেবেল-ভিত্তিক
    $label_pattern = '/(COD|cod|ক্যাশ\s*অন\s*ডেলিভারি|মূল্য|দাম|বিল|টোটাল|Total|total|Price|price)\s*[:ঃ\-]?\s*(৳)?\s*([\d,\.]+)\s*(k|K|হাজার)?/u';
    if (preg_match($label_pattern, $text, $m)) {
        $amount = floatval(str_replace(',', '', $m[3]));
        if (!empty($m[4])) {
            $amount *= 1000;
        }
        $text = str_replace($m[0], ' ', $text);
        return array($amount, $text);
    }

    // (খ) জানা প্যাকেজ প্রাইসের সাথে হুবহু মিল খোঁজা (postal code এর চেয়ে নির্ভরযোগ্য)
    $known_prices = array(990, 1749, 2549);
    if (preg_match_all('/\b(\d{3,4})\b/u', $text, $all, PREG_SET_ORDER)) {
        foreach ($all as $match) {
            $num = intval($match[1]);
            if (in_array($num, $known_prices, true)) {
                $text = str_replace($match[0], ' ', $text);
                return array(floatval($num), $text);
            }
        }
    }

    // (গ) হুবহু মিল না পেলে — প্রাইস রেঞ্জের মধ্যে প্রথম standalone সংখ্যা
    if (preg_match_all('/৳?\s*\b(\d{3,4})\b\s*(k|K|হাজার)?/u', $text, $all, PREG_SET_ORDER)) {
        foreach ($all as $match) {
            $num = intval($match[1]);
            if (!empty($match[2])) $num *= 1000;
            if ($num >= FITHOME_ORDER_BOT_MIN_COD && $num <= FITHOME_ORDER_BOT_MAX_COD) {
                $text = str_replace($match[0], ' ', $text);
                return array(floatval($num), $text);
            }
        }
    }

    return array(0, $text);
}

// ৩. নাম — লেবেল খোঁজা, না পেলে লাইন-ভিত্তিক স্মার্ট গেস
function fithome_extract_and_strip_name($text) {
    // (ক) লেবেল-ভিত্তিক
    $label_pattern = '/(?:Customer\s*)?(?:Name|name|নাম|কাস্টমার\s*নাম)\s*[:ঃ\-]\s*([^\n\r]+)/u';
    if (preg_match($label_pattern, $text, $m)) {
        $name = trim($m[1]);
        $text = str_replace($m[0], ' ', $text);
        return array($name, $text);
    }

    $address_keywords = fithome_order_bot_address_keywords();

    // (খ) টেক্সটের শুরু থেকে টানা কয়েকটা "নাম-সদৃশ" শব্দ নেওয়া
    $words = preg_split('/\s+/', trim($text));
    $leading_words = array();
    foreach ($words as $word) {
        if ($word === '') continue;
        if (preg_match('/\d/', $word)) break; // সংখ্যা পেলেই থামা
        $is_kw = false;
        foreach ($address_keywords as $kw) {
            if (mb_stripos($word, $kw) !== false) { $is_kw = true; break; }
        }
        if ($is_kw) break; // ঠিকানা-শব্দ পেলেই থামা
        if (!preg_match('/^[\x{0980}-\x{09FF}A-Za-z\.]+$/u', $word)) break;
        $leading_words[] = $word;
        if (count($leading_words) >= 3) break;
    }
    if (!empty($leading_words)) {
        $name = implode(' ', $leading_words);
        $text = preg_replace('/' . preg_quote($name, '/') . '/u', ' ', $text, 1);
        return array($name, $text);
    }

    // (গ) প্রতিটা আলাদা লাইন ধরে নাম-সদৃশ লাইন গেস করা
    $lines = preg_split('/\r\n|\r|\n/', trim($text));

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (preg_match('/\d/', $line)) continue;

        $is_address_like = false;
        foreach ($address_keywords as $kw) {
            if (mb_stripos($line, $kw) !== false) { $is_address_like = true; break; }
        }
        if ($is_address_like) continue;

        $word_count = count(preg_split('/\s+/', $line));
        if ($word_count > 4) continue;

        $is_bangla_name  = preg_match('/^[\x{0980}-\x{09FF}\s\.]{2,40}$/u', $line);
        $is_english_name = preg_match('/^[A-Za-z\s\.]{2,40}$/u', $line);

        if ($is_bangla_name || $is_english_name) {
            $text = str_replace($line, ' ', $text);
            return array($line, $text);
        }
    }

    return array('', $text);
}

// ৪. স্ট্রাকচার্ড ঠিকানা — "জেলা:", "উপজেলা/থানা:", "এলাকা/মহল্লা/গ্রাম:" লেবেল থাকলে
function fithome_extract_structured_address($text) {
    $district = null; $upazila = null; $area = null;

    if (preg_match('/জেলা\s*[:ঃ]\s*([^\n\r,।]+)/u', $text, $m)) {
        $district = trim($m[1]);
        $text = str_replace($m[0], ' ', $text);
    }
    if (preg_match('/(?:উপজেলা\s*\/?\s*থানা|থানা\s*\/?\s*উপজেলা|উপজেলা|থানা)\s*[:ঃ]\s*([^\n\r]+)/u', $text, $m)) {
        $upazila = trim($m[1]);
        $text = str_replace($m[0], ' ', $text);
    }
    if (preg_match('/এলাকা\s*\/?\s*মহল্লা\s*\/?\s*গ্রাম\s*[:ঃ]\s*([^\n\r]+)/u', $text, $m)) {
        $area = trim($m[1]);
        $text = str_replace($m[0], ' ', $text);
    } elseif (preg_match('/(?:এলাকা|মহল্লা|গ্রাম)\s*[:ঃ]\s*([^\n\r]+)/u', $text, $m)) {
        $area = trim($m[1]);
        $text = str_replace($m[0], ' ', $text);
    }

    if ($district === null && $upazila === null && $area === null) {
        return array(null, $text);
    }

    // ঠিকানা লেখার স্বাভাবিক ক্রম: এলাকা/মহল্লা → উপজেলা/থানা → জেলা
    $parts = array_filter(array($area, $upazila, $district), function($v) { return $v !== null && $v !== ''; });
    $address = implode(', ', $parts);
    $address = rtrim($address, " ।,");
    return array($address, $text);
}

// ৫. ঠিকানা (fallback) — boilerplate/junk লাইন বাদ দিয়ে পরিষ্কার করে জোড়া দেওয়া
function fithome_cleanup_address_text($text) {
    $junk_phrases = array(
        'সারা বাংলাদেশে', 'সুবিধা রয়েছে', 'অর্ডার করতে পাঠান', 'ক্যাশ অন ডেলিভারি',
        'ডেলিভারি চার্জ', 'কুরিয়ার চার্জ', 'ডেলিভারি সুবিধা',
    );

    $lines = preg_split('/\r\n|\r|\n/', $text);
    $clean_lines = array();

    foreach ($lines as $line) {
        $line = trim($line);
        $line = preg_replace('/\s+/', ' ', $line);
        if ($line === '') continue;
        if (preg_match('/^[\s,\-]*$/', $line)) continue;
        if (mb_strlen($line) <= 2) continue;

        $is_junk = false;
        foreach ($junk_phrases as $phrase) {
            if (mb_stripos($line, $phrase) !== false) { $is_junk = true; break; }
        }
        if ($is_junk) continue;

        $clean_lines[] = $line;
    }

    $address = implode(', ', $clean_lines);
    $address = preg_replace('/,\s*,/', ',', $address);
    $address = trim($address, " ,\t\n\r\0\x0B");
    return $address;
}

// সবগুলো ধাপ একসাথে চালিয়ে চূড়ান্ত ডেটা রিটার্ন করা
function fithome_parse_order_text($raw_text) {
    $text = fithome_normalize_bangla_digits(trim($raw_text));

    // ধাপ ১: ফোন বের করে টেক্সট থেকে সরানো
    $phone   = fithome_extract_phone_from_text($text);
    $working = fithome_strip_phone_from_text($text, $phone);

    // ধাপ ২: COD বের করে সরানো
    list($cod, $working) = fithome_extract_and_strip_cod($working);

    // ধাপ ৩: নাম বের করে সরানো
    list($name, $working) = fithome_extract_and_strip_name($working);

    // ধাপ ৪: আগে স্ট্রাকচার্ড ঠিকানা ট্রাই করা, না পেলে বাকি টেক্সট পরিষ্কার করা
    list($structured_address, $working) = fithome_extract_structured_address($working);
    if ($structured_address !== null && $structured_address !== '') {
        $address = $structured_address;
    } else {
        $address = fithome_cleanup_address_text($working);
    }

    return array(
        'name'    => $name !== '' ? $name : 'অজানা (ম্যানুয়ালি চেক করুন)',
        'phone'   => $phone,
        'address' => $address !== '' ? $address : 'উল্লেখ নেই',
        'cod'     => $cod,
    );
}

// =========================================================================
// 📋 ORDERS LIST — Telegram Source ব্যাজ (HPOS + Classic দুটোর জন্যই)
// =========================================================================
add_filter('manage_woocommerce_page_wc-orders_columns', 'fithome_add_telegram_source_column');
add_filter('manage_edit-shop_order_columns', 'fithome_add_telegram_source_column');
function fithome_add_telegram_source_column($columns) {
    $new_columns = array();
    foreach ($columns as $key => $label) {
        $new_columns[$key] = $label;
        if ($key === 'order_status') {
            $new_columns['telegram_source'] = 'Source';
        }
    }
    if (!isset($new_columns['telegram_source'])) {
        $new_columns['telegram_source'] = 'Source';
    }
    return $new_columns;
}

add_action('manage_woocommerce_page_wc-orders_custom_column', 'fithome_render_telegram_source_column_hpos', 10, 2);
add_action('manage_shop_order_posts_custom_column', 'fithome_render_telegram_source_column_classic', 10, 2);

function fithome_render_telegram_source_column_hpos($column, $order) {
    if ($column === 'telegram_source') {
        fithome_echo_telegram_badge($order);
    }
}
function fithome_render_telegram_source_column_classic($column, $post_id) {
    if ($column === 'telegram_source') {
        $order = wc_get_order($post_id);
        if ($order) fithome_echo_telegram_badge($order);
    }
}
function fithome_echo_telegram_badge($order) {
    if ($order->get_meta('_created_via_telegram_bot') === 'yes') {
        $mod_name = $order->get_meta('_telegram_moderator_name');
        echo '<span style="background:#0088cc;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;">📱 ' . esc_html($mod_name) . '</span>';
    } else {
        echo '—';
    }
}

// =========================================================================
// Load Moderator Settings + Stats Files
// =========================================================================
if ( file_exists( get_stylesheet_directory() . '/telegram-order-bot-settings.php' ) ) {
    require_once get_stylesheet_directory() . '/telegram-order-bot-settings.php';
}
if ( file_exists( get_stylesheet_directory() . '/telegram-order-bot-stats.php' ) ) {
    require_once get_stylesheet_directory() . '/telegram-order-bot-stats.php';
}