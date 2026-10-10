<?php
// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

// =========================================================================
// 📊 SHARED HELPER: নির্দিষ্ট মডারেটরের নির্দিষ্ট সময়ের অর্ডার count
// (wp-admin ড্যাশবোর্ডে এখনো ব্যবহার হয় — প্রতি মডারেটরের নিজস্ব কাউন্ট দেখাতে)
// =========================================================================
function fithome_get_moderator_order_count($chat_id, $range) {
    $date_query = fithome_order_bot_build_date_query($range);

    $args = array(
        'limit'        => -1,
        'return'       => 'ids',
        'date_created' => $date_query,
        'meta_query'   => array(
            array('key' => '_created_via_telegram_bot', 'value' => 'yes'),
            array('key' => '_telegram_moderator_chat_id', 'value' => strval($chat_id)),
        ),
    );

    $orders = wc_get_orders($args);
    return count($orders);
}

// range: 'today' | সংখ্যা (দিন) | 'weekly' | 'monthly'
function fithome_order_bot_build_date_query($range) {
    $today = current_time('Y-m-d');

    if ($range === 'today') {
        return $today . '...' . $today;
    }

    $days = 1;
    if ($range === 'weekly') {
        $days = 7;
    } elseif ($range === 'monthly') {
        $days = 30;
    } elseif (is_numeric($range)) {
        $days = intval($range);
    }
    if ($days < 1) $days = 1;

    $past_date = date('Y-m-d', strtotime("-" . ($days - 1) . " days", current_time('timestamp')));
    return $past_date . '...' . $today;
}

// =========================================================================
// ✅ SHARED: একটা date_query রেঞ্জের জন্য সব মডারেটরের count একসাথে বের
// করা (১টা মাত্র wc_get_orders() কল, তারপর PHP-তে প্রতিটা মডারেটরের bucket-এ
// গোনা হচ্ছে) — /N কমান্ড আর কাস্টম ডেট-রেঞ্জ রিপোর্ট, দুটোতেই রিইউজ হয়
// =========================================================================
function fithome_order_bot_get_moderator_counts_for_range($date_query) {
    $moderators = function_exists('fithome_get_all_moderators') ? fithome_get_all_moderators() : array();
    if (empty($moderators)) return array();

    $counts = array();
    foreach ($moderators as $mod) {
        $counts[strval($mod['chat_id'])] = 0;
    }

    if (!class_exists('WooCommerce')) return $counts;

    $orders = wc_get_orders(array(
        'limit'        => -1,
        'date_created' => $date_query,
        'meta_query'   => array(
            array('key' => '_created_via_telegram_bot', 'value' => 'yes'),
        ),
    ));

    foreach ($orders as $order) {
        $chat_id = strval($order->get_meta('_telegram_moderator_chat_id'));
        if (!isset($counts[$chat_id])) continue; // রিমুভ হয়ে যাওয়া মডারেটরের অর্ডার
        $counts[$chat_id]++;
    }

    return $counts;
}

// ✅ SHARED: chat_id => count অ্যারে থেকে monospace টেবিল বানানো
// (হেডার + সারি + TOTAL সহ), অর্ডার সংখ্যা বেশি যার আগে তাকে রেখে সাজানো
function fithome_order_bot_build_count_table($counts_by_chatid) {
    arsort($counts_by_chatid);

    $rows  = '';
    $rank  = 1;
    $total = 0;

    foreach ($counts_by_chatid as $chat_id => $count) {
        $name = function_exists('fithome_get_moderator_name') ? fithome_get_moderator_name($chat_id) : false;
        if ($name === false) $name = 'অজানা';

        $rows .= fithome_mb_pad($rank . '. ' . $name, 20) . sprintf('%6d', $count) . "\n";
        $total += $count;
        $rank++;
    }

    $header = fithome_mb_pad('Moderator', 20) . sprintf('%6s', 'Order');
    $line   = str_repeat('-', 26);
    $footer = fithome_mb_pad('TOTAL', 20) . sprintf('%6d', $total);

    return $header . "\n" . $line . "\n" . $rows . $line . "\n" . $footer;
}

// মাল্টিবাইট-সেফ padding — বাংলা নামের ক্ষেত্রে strlen() ভুল গোনে, তাই mb_strlen
// ⚠️ নাম ইংরেজিতে থাকলে টেবিলের কলাম সবচেয়ে সুন্দর অ্যালাইন হয়; বাংলা যুক্তাক্ষরের
// প্রস্থ monospace ফন্টেও সমান নয়, তাই বাংলা নামে অ্যালাইনমেন্ট একটু এলোমেলো লাগবে।
function fithome_mb_pad($text, $length) {
    $text = trim($text);
    if (mb_strlen($text) > $length) {
        $text = mb_substr($text, 0, $length - 1) . '.';
    }
    return $text . str_repeat(' ', max(0, $length - mb_strlen($text)));
}

// =========================================================================
// 🏆 LEADERBOARD — সব মডারেটরের ৪টা স্ট্যাট একসাথে: আজকে / গতকাল / গত মাস / এই মাস
//
// ✅ CHANGED: আগে ছিল রোলিং ১/৩/৭/১৫/৩০ দিন + চলতি মাস (৬ কলাম)। এখন সহজ করে
// মাত্র ৪টা নির্দিষ্ট ক্যালেন্ডার-ভিত্তিক স্ট্যাট দেখানো হচ্ছে —
//   - আজকে: আজকের ক্যালেন্ডার দিন (০০:০০ থেকে এখন পর্যন্ত)
//   - গতকাল: শুধু গতকালের নির্দিষ্ট দিন (রোলিং না, একটাই দিন)
//   - গত মাস: গত ক্যালেন্ডার মাসের ১ তারিখ থেকে শেষ তারিখ পর্যন্ত পুরোটা
//   - এই মাস: চলতি মাসের ১ তারিখ থেকে আজ পর্যন্ত (মাস শেষ না হওয়া পর্যন্ত চলমান)
//
// কোয়েরির রেঞ্জ গত মাসের ১ তারিখ থেকে আজ পর্যন্ত টানা হচ্ছে, যাতে ৪টা
// স্ট্যাটের জন্যই একটা মাত্র wc_get_orders() কল যথেষ্ট হয় — তারপর প্রতিটা
// অর্ডারকে টাইমস্ট্যাম্প দেখে সঠিক bucket(গুলো)-এ ফেলা হচ্ছে।
//
// ফাঁকা স্ট্রিং রিটার্ন করে যদি কোনো মডারেটর যোগ করা না থাকে।
// =========================================================================
function fithome_order_bot_build_leaderboard_message() {
    if (!class_exists('WooCommerce')) return '';

    $moderators = function_exists('fithome_get_all_moderators') ? fithome_get_all_moderators() : array();
    if (empty($moderators)) return '';

    $now       = current_time('timestamp');
    $today     = current_time('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day', $now));

    // চলতি মাস
    $this_year        = (int) current_time('Y');
    $this_month_num   = (int) current_time('n');
    $this_month_start = sprintf('%04d-%02d-01', $this_year, $this_month_num);
    $this_month_full  = current_time('F'); // যেমন September

    // গত মাস (বছর পাল্টে গেলেও ঠিকভাবে হ্যান্ডেল করে — জানুয়ারিতে গত মাস = গত বছরের ডিসেম্বর)
    $last_month_num  = $this_month_num - 1;
    $last_month_year = $this_year;
    if ($last_month_num < 1) {
        $last_month_num  = 12;
        $last_month_year--;
    }
    $last_month_start = sprintf('%04d-%02d-01', $last_month_year, $last_month_num);
    $last_month_full  = date('F', strtotime($last_month_start)); // যেমন August

    // টাইমস্ট্যাম্প কাটঅফ
    $today_cutoff            = strtotime($today . ' 00:00:00');
    $yesterday_cutoff_start  = strtotime($yesterday . ' 00:00:00');
    $this_month_cutoff       = strtotime($this_month_start . ' 00:00:00');
    $last_month_cutoff_start = strtotime($last_month_start . ' 00:00:00');

    // শুধু Telegram বট দিয়ে তৈরি অর্ডারগুলো — গত মাসের ১ তারিখ থেকে আজ পর্যন্ত
    // (৪টা স্ট্যাটের জন্যই এই একটা রেঞ্জ যথেষ্ট চওড়া)
    $orders = wc_get_orders(array(
        'limit'        => -1,
        'date_created' => $last_month_start . '...' . $today,
        'meta_query'   => array(
            array('key' => '_created_via_telegram_bot', 'value' => 'yes'),
        ),
    ));

    // counts[chat_id]['today'|'yesterday'|'last_month'|'this_month'] = সংখ্যা
    $stat_keys = array('today', 'yesterday', 'last_month', 'this_month');
    $counts = array();
    foreach ($moderators as $mod) {
        $counts[strval($mod['chat_id'])] = array_fill_keys($stat_keys, 0);
    }

    foreach ($orders as $order) {
        $chat_id = strval($order->get_meta('_telegram_moderator_chat_id'));
        if (!isset($counts[$chat_id])) continue; // রিমুভ হয়ে যাওয়া মডারেটরের অর্ডার

        $created = $order->get_date_created();
        if (!$created) continue;
        $ts = $created->getTimestamp();

        if ($ts >= $today_cutoff) {
            $counts[$chat_id]['today']++;
        }
        if ($ts >= $yesterday_cutoff_start && $ts < $today_cutoff) {
            $counts[$chat_id]['yesterday']++;
        }
        if ($ts >= $this_month_cutoff) {
            $counts[$chat_id]['this_month']++;
        }
        if ($ts >= $last_month_cutoff_start && $ts < $this_month_cutoff) {
            $counts[$chat_id]['last_month']++;
        }
    }

    // এই মাসের অর্ডার বেশি যার, সে উপরে
    uasort($counts, function($a, $b) { return $b['this_month'] <=> $a['this_month']; });

    // <pre> ব্লকে monospace টেবিল আকারে দেখানো হচ্ছে। ⚠️ <pre> এর ভেতরে <b>
    // কাজ করে না, তাই bold হাইলাইট নেই।
    $total = array_fill_keys($stat_keys, 0);
    $rows  = '';
    $rank  = 1;

    foreach ($counts as $chat_id => $c) {
        $name = function_exists('fithome_get_moderator_name') ? fithome_get_moderator_name($chat_id) : false;
        if ($name === false) $name = 'অজানা';

        $rows .= fithome_mb_pad($rank . '. ' . $name, 15)
              .  sprintf('%6d%6d%6d%6d', $c['today'], $c['yesterday'], $c['last_month'], $c['this_month']) . "\n";

        foreach ($stat_keys as $k) $total[$k] += $c[$k];
        $rank++;
    }

    $header = fithome_mb_pad('Moderator', 15) . sprintf('%6s%6s%6s%6s', 'Today', 'Yest', 'LastM', 'ThisM');
    $line   = str_repeat('-', 39);
    $footer = fithome_mb_pad('TOTAL', 15)
            . sprintf('%6d%6d%6d%6d', $total['today'], $total['yesterday'], $total['last_month'], $total['this_month']);

    $message  = "🏆 <b>মডারেটর অর্ডার রিপোর্ট</b>\n";
    $message .= "🕒 " . current_time('d-m-Y h:i A') . "\n";
    $message .= "📅 <b>গত মাস:</b> {$last_month_full} | <b>এই মাস:</b> {$this_month_full}\n\n";
    $message .= "<pre>" . esc_html($header . "\n" . $line . "\n" . $rows . $line . "\n" . $footer) . "</pre>";

    return $message;
}

// =========================================================================
// ⏰ প্রতি ৬ ঘণ্টায় সব মডারেটরের কাছে লিডারবোর্ড রিপোর্ট পাঠানো
//
// ⚠️ WP-Cron ভিজিটর-নির্ভর — সাইটে ট্রাফিক না থাকলে ঠিক ৬ ঘণ্টায় না-ও
// চলতে পারে। cPanel-এ real cron সেট করা থাকলে নির্ভুলভাবে চলবে।
// =========================================================================

// কাস্টম cron schedule — অন্য ফাইলে একই নামে আগে থেকে থাকলে সেটাই ব্যবহার হবে
add_filter('cron_schedules', 'fithome_order_bot_add_six_hour_schedule');
function fithome_order_bot_add_six_hour_schedule($schedules) {
    if (!isset($schedules['fithome_six_hours'])) {
        $schedules['fithome_six_hours'] = array(
            'interval' => 6 * HOUR_IN_SECONDS,
            'display'  => 'Every 6 Hours (Fit Home)',
        );
    }
    return $schedules;
}

add_action('init', 'fithome_order_bot_schedule_leaderboard_cron');
function fithome_order_bot_schedule_leaderboard_cron() {
    if (!wp_next_scheduled('fithome_order_bot_leaderboard_event')) {
        wp_schedule_event(time() + 60, 'fithome_six_hours', 'fithome_order_bot_leaderboard_event');
    }
}

add_action('fithome_order_bot_leaderboard_event', 'fithome_order_bot_send_leaderboard_broadcast');
function fithome_order_bot_send_leaderboard_broadcast() {
    if (!class_exists('WooCommerce')) return;

    $message = fithome_order_bot_build_leaderboard_message();
    if (empty($message)) return;

    // প্রতিটা মডারেটরের কাছে পাঠানো (Order Creation Bot এর টোকেন দিয়ে)
    foreach (fithome_get_all_moderators() as $mod) {
        fithome_order_bot_send_message($mod['chat_id'], $message);
    }

    // অ্যাডমিন/পার্টনারদেরও পাঠাতে চাইলে নিচের অংশটা আনকমেন্ট করুন
    // ⚠️ অ্যাডমিনরা Order Creation Bot টা একবার /start করে থাকতে হবে,
    // নাহলে Telegram মেসেজটা ডেলিভার করবে না।
    /*
    if (defined('FITHOME_ADMIN_CHAT_IDS')) {
        foreach (FITHOME_ADMIN_CHAT_IDS as $admin_chat_id) {
            fithome_order_bot_send_message($admin_chat_id, $message);
        }
    }
    */
}

// =========================================================================
// ✅ /N দিন — এখন যে মডারেটর কমান্ড পাঠাচ্ছে তার একার কাউন্ট না দেখিয়ে,
// সব মডারেটরের ব্রেকডাউন দেখায় (আগের পার্সোনাল-কাউন্ট ফিচার বাদ দেওয়া হলো)
// =========================================================================
function fithome_order_bot_build_days_report($days, $title) {
    $counts = fithome_order_bot_get_moderator_counts_for_range(fithome_order_bot_build_date_query($days));
    if (empty($counts)) return '';

    $table = fithome_order_bot_build_count_table($counts);

    $message  = "📊 <b>{$title}</b>\n";
    $message .= "🕒 " . current_time('d-m-Y h:i A') . "\n\n";
    $message .= "<pre>" . esc_html($table) . "</pre>";

    return $message;
}

// =========================================================================
// ✅ কাস্টম তারিখ-রেঞ্জ রিপোর্ট (DD/MM/YYYY-DD/MM/YYYY ইনপুট থেকে) — সব
// মডারেটরের ওই রেঞ্জের অর্ডার ব্রেকডাউন। পার্সিং হয় telegram-order-bot.php
// এর fithome_order_bot_parse_date_range() দিয়ে, এই ফাংশন শুধু রিপোর্ট বানায়।
// =========================================================================
function fithome_order_bot_build_daterange_report($start_date, $end_date) {
    $counts = fithome_order_bot_get_moderator_counts_for_range($start_date . '...' . $end_date);
    if (empty($counts)) return '';

    $table       = fithome_order_bot_build_count_table($counts);
    $range_label = date('d-m-Y', strtotime($start_date)) . ' থেকে ' . date('d-m-Y', strtotime($end_date));

    $message  = "📅 <b>কাস্টম রেঞ্জ রিপোর্ট</b>\n";
    $message .= "🗓️ <b>রেঞ্জ:</b> {$range_label}\n\n";
    $message .= "<pre>" . esc_html($table) . "</pre>";

    return $message;
}

// =========================================================================
// ✅ /command বা /help — বটের সব কমান্ডের লিস্ট, বাংলা ইউজ-কেসসহ। সব
// রেজিস্টার্ড মডারেটরের জন্য উন্মুক্ত (কোনো admin-only রেস্ট্রিকশন নেই)।
// =========================================================================
function fithome_order_bot_build_help_message() {
    $message  = "ℹ️ <b>এভেইলেবল কমান্ড লিস্ট</b>\n";
    $message .= "━━━━━━━━━━━━━━━━━\n\n";

    $message .= "📝 <b>নতুন অর্ডার তৈরি:</b>\n";
    $message .= "যেকোনো ফরম্যাটে অর্ডারের তথ্য (নাম/ফোন/ঠিকানা/COD) পাঠান — বট প্রিভিউ দেখাবে, তারপর <b>1</b> বা <b>Ok</b> লিখে কনফার্ম করুন।\n\n";

    $message .= "📊 <b>সব মডারেটরের অর্ডার রিপোর্ট:</b>\n";
    $message .= "/daily বা /1 — আজকের রিপোর্ট\n";
    $message .= "/weekly বা /7 — গত ৭ দিনের রিপোর্ট\n";
    $message .= "/monthly বা /30 — গত ৩০ দিনের রিপোর্ট\n";
    $message .= "/N (যেমন /15, /45) — গত N দিনের রিপোর্ট (সর্বোচ্চ ৩৬৫)\n\n";

    $message .= "🏆 <b>কম্বাইন্ড লিডারবোর্ড:</b>\n";
    $message .= "/order — আজকে + গতকাল + গত মাস + এই মাসের রিপোর্ট একসাথে (এটা প্রতি ৬ ঘণ্টায় সবাইকে অটোমেটিক পাঠানো হয়)\n\n";

    $message .= "📅 <b>কাস্টম তারিখ রেঞ্জ:</b>\n";
    $message .= "DD/MM/YYYY-DD/MM/YYYY ফরম্যাটে তারিখ পাঠান (যেমন: 20/11/2026-20/12/2026) — ওই রেঞ্জে সব মডারেটরের ব্রেকডাউন দেখাবে\n\n";

    $message .= "ℹ️ <b>এই লিস্ট আবার দেখতে:</b>\n";
    $message .= "/command অথবা /help\n\n";

    $message .= "━━━━━━━━━━━━━━━━━\n";
    $message .= "<i>সব কমান্ড সব রেজিস্টার্ড মডারেটরের জন্য উন্মুক্ত।</i>";

    return $message;
}

// =========================================================================
// 🤖 /daily /weekly /monthly /N /order /command — সব স্ট্যাটস কমান্ড হ্যান্ডলার
// (telegram-order-bot.php এর webhook হ্যান্ডলার থেকে কল হয়)
//
// ✅ /N (এবং /daily, /weekly, /monthly) যে মডারেটর কমান্ড পাঠাচ্ছে তার একার
// কাউন্ট না দেখিয়ে সব মডারেটরের ব্রেকডাউন দেখায়। /order এখন আজকে/গতকাল/গত
// মাস/এই মাস — এই ৪টা স্ট্যাটের কম্বাইন্ড লিডারবোর্ড দেখায়।
// =========================================================================
function fithome_order_bot_handle_stats_command($command_text, $chat_id, $moderator_name) {
    $command = strtolower(trim($command_text));

    // 🏆 /order — সব মডারেটরের কম্বাইন্ড রিপোর্ট (আজকে/গতকাল/গত মাস/এই মাস)
    if ($command === '/order') {
        $leaderboard = fithome_order_bot_build_leaderboard_message();
        fithome_order_bot_send_message(
            $chat_id,
            $leaderboard !== '' ? $leaderboard : "⚠️ কোনো মডারেটর যোগ করা নেই।"
        );
        return;
    }

    // ℹ️ /command বা /help — কমান্ড লিস্ট
    if ($command === '/command' || $command === '/help') {
        fithome_order_bot_send_message($chat_id, fithome_order_bot_build_help_message());
        return;
    }

    $days = null; $title = '';

    if ($command === '/daily' || $command === '/1') {
        $days = 1; $title = 'আজকের রিপোর্ট (সব মডারেটর)';
    } elseif ($command === '/weekly' || $command === '/7') {
        $days = 7; $title = 'সাপ্তাহিক রিপোর্ট — গত ৭ দিন (সব মডারেটর)';
    } elseif ($command === '/monthly' || $command === '/30') {
        $days = 30; $title = 'মাসিক রিপোর্ট — গত ৩০ দিন (সব মডারেটর)';
    } elseif (preg_match('/^\/(\d{1,3})$/', $command, $m)) {
        $days  = max(1, min(365, intval($m[1])));
        $title = "কাস্টম রিপোর্ট — গত {$days} দিন (সব মডারেটর)";
    } else {
        // অচেনা কমান্ড — সাইলেন্টলি ইগনোর করা হচ্ছে
        return;
    }

    $report = fithome_order_bot_build_days_report($days, $title);
    fithome_order_bot_send_message(
        $chat_id,
        $report !== '' ? $report : "⚠️ কোনো মডারেটর যোগ করা নেই।"
    );
}

// =========================================================================
// 🖥️ wp-admin ড্যাশবোর্ড — সব মডারেটরের স্ট্যাটস একসাথে (আগের মতোই, প্রতি
// মডারেটরের নিজের কাউন্ট — Telegram কমান্ডের বদলের সাথে এর সম্পর্ক নেই)
// =========================================================================
add_action('admin_menu', 'fithome_register_moderator_stats_page');
function fithome_register_moderator_stats_page() {
    add_submenu_page(
        'woocommerce',
        'Moderator Order Stats',
        'Moderator Order Stats',
        'manage_woocommerce',
        'fithome-moderator-order-stats',
        'fithome_render_moderator_stats_page'
    );
}

function fithome_render_moderator_stats_page() {
    if (!current_user_can('manage_woocommerce')) return;

    $moderators = function_exists('fithome_get_all_moderators') ? fithome_get_all_moderators() : array();

    // কাস্টম ডেট রেঞ্জ (অপশনাল ফিল্টার)
    $custom_days = isset($_GET['days']) && $_GET['days'] !== '' ? max(1, min(365, intval($_GET['days']))) : null;
    ?>
    <div class="wrap">
        <h1>Moderator Order Stats</h1>

        <form method="get" style="margin-bottom:15px;">
            <input type="hidden" name="page" value="fithome-moderator-order-stats">
            <label>কাস্টম দিন সংখ্যা (১-৩৬৫): </label>
            <input type="number" name="days" min="1" max="365" value="<?php echo esc_attr($custom_days ? $custom_days : ''); ?>" style="width:80px;">
            <?php submit_button('ফিল্টার করুন', 'secondary', '', false); ?>
        </form>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>মডারেটর</th>
                    <th>Chat ID</th>
                    <th>আজ (Daily)</th>
                    <th>সাপ্তাহিক (৭ দিন)</th>
                    <th>মাসিক (৩০ দিন)</th>
                    <?php if ($custom_days): ?>
                        <th>কাস্টম (<?php echo esc_html($custom_days); ?> দিন)</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($moderators)): ?>
                    <tr><td colspan="6">কোনো মডারেটর যোগ করা নেই। আগে "Order Bot Moderators" পেজ থেকে যোগ করুন।</td></tr>
                <?php else: ?>
                    <?php foreach ($moderators as $mod): ?>
                        <tr>
                            <td><?php echo esc_html($mod['name']); ?></td>
                            <td><code><?php echo esc_html($mod['chat_id']); ?></code></td>
                            <td><?php echo esc_html(fithome_get_moderator_order_count($mod['chat_id'], 'today')); ?></td>
                            <td><?php echo esc_html(fithome_get_moderator_order_count($mod['chat_id'], 'weekly')); ?></td>
                            <td><?php echo esc_html(fithome_get_moderator_order_count($mod['chat_id'], 'monthly')); ?></td>
                            <?php if ($custom_days): ?>
                                <td><?php echo esc_html(fithome_get_moderator_order_count($mod['chat_id'], $custom_days)); ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}