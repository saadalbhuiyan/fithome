<?php
// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

// =========================================================================
// 📊 SHARED HELPER: নির্দিষ্ট মডারেটরের নির্দিষ্ট সময়ের অর্ডার count
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
// 🏆 NEW: LEADERBOARD — সব মডারেটরের ১/৩/৭/১৫/৩০ দিনের অর্ডার count একসাথে
//
// ⚠️ পারফরম্যান্স নোট: প্রতিটা রেঞ্জের জন্য প্রতিটা মডারেটরে আলাদা করে
// fithome_get_moderator_order_count() কল করলে ৫ × N টা wc_get_orders()
// কোয়েরি চলত। তাই এখানে একবারেই ৩০ দিনের সব বট-অর্ডার এনে PHP-তে
// প্রতিটা রেঞ্জের bucket-এ ফেলা হচ্ছে — মোট কোয়েরি মাত্র ১টা।
//
// ফাঁকা স্ট্রিং রিটার্ন করে যদি কোনো মডারেটর যোগ করা না থাকে।
// =========================================================================
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

function fithome_order_bot_build_leaderboard_message() {
    if (!class_exists('WooCommerce')) return '';

    $moderators = function_exists('fithome_get_all_moderators') ? fithome_get_all_moderators() : array();
    if (empty($moderators)) return '';

    $ranges = array(1, 3, 7, 15, 30);
    $now    = current_time('timestamp');

    // দিনের সীমা — "গত ১ দিন" = আজকের দিন, "গত ৩ দিন" = আজ সহ ৩ দিন
    $cutoffs = array();
    foreach ($ranges as $d) {
        $cutoffs[$d] = strtotime(date('Y-m-d 00:00:00', strtotime("-" . ($d - 1) . " days", $now)));
    }

    $start_date = date('Y-m-d', strtotime('-30 days', $now));
    $today      = current_time('Y-m-d');

    // শুধু Telegram বট দিয়ে তৈরি অর্ডারগুলো (কাস্টম মেটা — HPOS/legacy দুটোতেই
    // meta_query এখানে নির্ভরযোগ্য, কারণ এটা WooCommerce-এর কোনো ডেডিকেটেড
    // কলাম নয়, সাধারণ অর্ডার মেটা)
    $orders = wc_get_orders(array(
        'limit'        => -1,
        'date_created' => $start_date . '...' . $today,
        'meta_query'   => array(
            array('key' => '_created_via_telegram_bot', 'value' => 'yes'),
        ),
    ));

    // counts[chat_id][days] = সংখ্যা
    $counts = array();
    foreach ($moderators as $mod) {
        $counts[strval($mod['chat_id'])] = array_fill_keys($ranges, 0);
    }

    foreach ($orders as $order) {
        $chat_id = strval($order->get_meta('_telegram_moderator_chat_id'));
        if (!isset($counts[$chat_id])) continue; // রিমুভ হয়ে যাওয়া মডারেটরের অর্ডার

        $created = $order->get_date_created();
        if (!$created) continue;
        $ts = $created->getTimestamp();

        foreach ($ranges as $d) {
            if ($ts >= $cutoffs[$d]) $counts[$chat_id][$d]++;
        }
    }

    // ৩০ দিনের অর্ডার বেশি যার, সে উপরে
    uasort($counts, function($a, $b) { return $b[30] <=> $a[30]; });

    // ✅ CHANGED: এখন <pre> ব্লকে monospace টেবিল আকারে দেখানো হচ্ছে (আগে ছিল
    // প্রতি মডারেটরে ৩ লাইনের লিস্ট)। মোট প্রস্থ ৩৩ ক্যারেক্টার — ছোট ফোনেও
    // লাইন ভেঙে যায় না। ⚠️ <pre> এর ভেতরে <b> কাজ করে না, তাই bold হাইলাইট নেই।
    $total = array_fill_keys($ranges, 0);
    $rows  = '';
    $rank  = 1;

    foreach ($counts as $chat_id => $c) {
        $name = function_exists('fithome_get_moderator_name') ? fithome_get_moderator_name($chat_id) : false;
        if ($name === false) $name = 'অজানা';

        $rows .= fithome_mb_pad($rank . '. ' . $name, 13)
              .  sprintf('%4d%4d%4d%4d%4d', $c[1], $c[3], $c[7], $c[15], $c[30]) . "\n";

        foreach ($ranges as $d) $total[$d] += $c[$d];
        $rank++;
    }

    $header = fithome_mb_pad('Moderator', 13) . sprintf('%4s%4s%4s%4s%4s', '1d', '3d', '7d', '15d', '30d');
    $line   = str_repeat('-', 33);
    $footer = fithome_mb_pad('TOTAL', 13)
            . sprintf('%4d%4d%4d%4d%4d', $total[1], $total[3], $total[7], $total[15], $total[30]);

    $message  = "🏆 <b>মডারেটর অর্ডার রিপোর্ট</b>\n";
    $message .= "🕒 " . current_time('d-m-Y h:i A') . "\n\n";
    $message .= "<pre>" . esc_html($header . "\n" . $line . "\n" . $rows . $line . "\n" . $footer) . "</pre>";

    return $message;
}

// =========================================================================
// ⏰ NEW: প্রতি ৬ ঘণ্টায় সব মডারেটরের কাছে লিডারবোর্ড রিপোর্ট পাঠানো
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
// 🤖 মডারেটরের নিজের /daily /weekly /monthly /N কমান্ড হ্যান্ডলার
// (telegram-order-bot.php এর webhook হ্যান্ডলার থেকে কল হয়)
//
// ✅ NEW: /order কমান্ড যোগ করা হয়েছে — সব মডারেটরের ১/৩/৭/১৫/৩০ দিনের
// রিপোর্ট (৬-ঘণ্টার অটো মেসেজে যেটা যায়, হুবহু একই)
// =========================================================================
function fithome_order_bot_handle_stats_command($command_text, $chat_id, $moderator_name) {
    $command = strtolower(trim($command_text));
    $range = null; $title = '';

    // 🏆 /order — সব মডারেটরের কম্বাইন্ড রিপোর্ট
    if ($command === '/order') {
        $leaderboard = fithome_order_bot_build_leaderboard_message();
        fithome_order_bot_send_message(
            $chat_id,
            $leaderboard !== '' ? $leaderboard : "⚠️ কোনো মডারেটর যোগ করা নেই।"
        );
        return;
    }

    if ($command === '/daily' || $command === '/1') {
        $range = 'today'; $title = 'আজকের রিপোর্ট';
    } elseif ($command === '/weekly' || $command === '/7') {
        $range = 'weekly'; $title = 'সাপ্তাহিক রিপোর্ট (৭ দিন)';
    } elseif ($command === '/monthly' || $command === '/30') {
        $range = 'monthly'; $title = 'মাসিক রিপোর্ট (৩০ দিন)';
    } elseif (preg_match('/^\/(\d{1,3})$/', $command, $m)) {
        $days = max(1, min(365, intval($m[1])));
        $range = $days;
        $title = "কাস্টম রিপোর্ট (গত {$days} দিন)";
    } else {
        // অচেনা কমান্ড — সাইলেন্টলি ইগনোর করা হচ্ছে
        return;
    }

    $count = fithome_get_moderator_order_count($chat_id, $range);

    $message = "📊 <b>{$title}</b>\n"
             . "━━━━━━━━━━━━━━━━━\n"
             . "👤 <b>মডারেটর:</b> " . esc_html($moderator_name) . "\n"
             . "📦 <b>মোট অর্ডার তৈরি:</b> {$count} টি";

    fithome_order_bot_send_message($chat_id, $message);
}

// =========================================================================
// 🖥️ wp-admin ড্যাশবোর্ড — সব মডারেটরের স্ট্যাটস একসাথে
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