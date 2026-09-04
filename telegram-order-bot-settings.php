<?php
// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

// =========================================================================
// ⚙️ মডারেটর লিস্ট — WP options এ JSON আকারে সেভ হবে
// =========================================================================
define('FITHOME_ORDER_BOT_MODERATORS_OPTION', 'fithome_order_bot_moderators');

// =========================================================================
// 🔧 HELPER FUNCTIONS
// =========================================================================

// Chat ID দিয়ে মডারেটরের নাম বের করা — অথরাইজড না হলে false রিটার্ন করবে
function fithome_get_moderator_name($chat_id) {
    $moderators = get_option(FITHOME_ORDER_BOT_MODERATORS_OPTION, array());
    foreach ($moderators as $mod) {
        if (strval($mod['chat_id']) === strval($chat_id)) {
            return $mod['name'];
        }
    }
    return false;
}

// সব মডারেটরের লিস্ট (স্ট্যাটস ড্যাশবোর্ডে ব্যবহারের জন্য)
function fithome_get_all_moderators() {
    return get_option(FITHOME_ORDER_BOT_MODERATORS_OPTION, array());
}

// =========================================================================
// 🖥️ wp-admin সেটিংস পেজ (WooCommerce মেনুর সাবপেজ হিসেবে)
// =========================================================================
add_action('admin_menu', 'fithome_register_order_bot_settings_page');
function fithome_register_order_bot_settings_page() {
    add_submenu_page(
        'woocommerce',
        'Order Bot Moderators',
        'Order Bot Moderators',
        'manage_woocommerce',
        'fithome-order-bot-moderators',
        'fithome_render_order_bot_settings_page'
    );
}

function fithome_render_order_bot_settings_page() {
    if (!current_user_can('manage_woocommerce')) return;

    $moderators = fithome_get_all_moderators();

    // ➕ নতুন মডারেটর অ্যাড করা
    if (isset($_POST['fithome_add_moderator']) && check_admin_referer('fithome_order_bot_settings_nonce')) {
        $new_chat_id = sanitize_text_field($_POST['moderator_chat_id']);
        $new_name    = sanitize_text_field($_POST['moderator_name']);

        if (!empty($new_chat_id) && !empty($new_name)) {
            // একই chat_id দুইবার যোগ হওয়া ঠেকানো
            $already_exists = false;
            foreach ($moderators as $mod) {
                if (strval($mod['chat_id']) === strval($new_chat_id)) {
                    $already_exists = true;
                    break;
                }
            }
            if (!$already_exists) {
                $moderators[] = array('chat_id' => $new_chat_id, 'name' => $new_name);
                update_option(FITHOME_ORDER_BOT_MODERATORS_OPTION, $moderators);
                echo '<div class="notice notice-success is-dismissible"><p>মডারেটর যোগ করা হয়েছে।</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>এই Chat ID দিয়ে আগে থেকেই একজন মডারেটর যোগ করা আছে।</p></div>';
            }
        }
    }

    // ❌ মডারেটর রিমুভ করা
    if (isset($_GET['remove_chat_id']) && check_admin_referer('fithome_remove_moderator_' . $_GET['remove_chat_id'])) {
        $remove_id = sanitize_text_field($_GET['remove_chat_id']);
        $moderators = array_values(array_filter($moderators, function($mod) use ($remove_id) {
            return strval($mod['chat_id']) !== strval($remove_id);
        }));
        update_option(FITHOME_ORDER_BOT_MODERATORS_OPTION, $moderators);
        echo '<div class="notice notice-success is-dismissible"><p>মডারেটর রিমুভ করা হয়েছে।</p></div>';
    }
    ?>
    <div class="wrap">
        <h1>Telegram Order Bot — মডারেটর ম্যানেজমেন্ট</h1>
        <p>যাদের Chat ID এখানে যোগ করা থাকবে, শুধু তারাই Telegram Order Bot দিয়ে অর্ডার তৈরি করতে পারবেন।</p>

        <h2>নতুন মডারেটর যোগ করুন</h2>
        <form method="post">
            <?php wp_nonce_field('fithome_order_bot_settings_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="moderator_chat_id">Telegram Chat ID</label></th>
                    <td><input type="text" name="moderator_chat_id" id="moderator_chat_id" class="regular-text" required></td>
                </tr>
                <tr>
                    <th><label for="moderator_name">নাম</label></th>
                    <td><input type="text" name="moderator_name" id="moderator_name" class="regular-text" required></td>
                </tr>
            </table>
            <?php submit_button('মডারেটর যোগ করুন', 'primary', 'fithome_add_moderator'); ?>
        </form>

        <h2>বর্তমান মডারেটর তালিকা</h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>নাম</th>
                    <th>Chat ID</th>
                    <th>অ্যাকশন</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($moderators)): ?>
                    <tr><td colspan="3">এখনো কোনো মডারেটর যোগ করা হয়নি।</td></tr>
                <?php else: ?>
                    <?php foreach ($moderators as $mod): ?>
                        <tr>
                            <td><?php echo esc_html($mod['name']); ?></td>
                            <td><code><?php echo esc_html($mod['chat_id']); ?></code></td>
                            <td>
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=fithome-order-bot-moderators&remove_chat_id=' . $mod['chat_id']), 'fithome_remove_moderator_' . $mod['chat_id'])); ?>"
                                   onclick="return confirm('এই মডারেটরকে রিমুভ করতে চান?');"
                                   style="color:#b32d2e;">রিমুভ করুন</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}