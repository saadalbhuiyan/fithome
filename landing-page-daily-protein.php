   <?php
/*
Template Name: Fit Home - Daily Protein Landing
*/

if ( ! defined( 'ABSPATH' ) ) exit;

function fithome_get_real_ip() {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return sanitize_text_field($_SERVER['HTTP_CF_CONNECTING_IP']);
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip_list = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return sanitize_text_field(trim($ip_list[0]));
    }
    return sanitize_text_field($_SERVER['REMOTE_ADDR']);
}

add_filter('show_admin_bar', '__return_false', 999);
remove_action('wp_head', '_admin_bar_bump_cb');


// -------------------------------------------------------------------------
// [SAFETY] functions.php না থাকলেও যেন fatal error না হয় — fallback ডিফাইন
// -------------------------------------------------------------------------
if ( ! function_exists( 'fithome_get_packages' ) ) {
    function fithome_get_packages() { return array( 1 => 990, 2 => 1749, 3 => 2549 ); }
}
if ( ! function_exists( 'fithome_get_package_price' ) ) {
    function fithome_get_package_price( $qty ) {
        $p = fithome_get_packages(); $qty = intval($qty);
        return isset($p[$qty]) ? $p[$qty] : false;
    }
}
if ( ! function_exists( 'fithome_get_shipping_rates' ) ) {
    function fithome_get_shipping_rates() { return array( 'inside_dhaka' => 59, 'outside_dhaka' => 89 ); }
}
if ( ! defined( 'FITHOME_FREE_SHIP_MIN_QTY' ) ) define( 'FITHOME_FREE_SHIP_MIN_QTY', 2 );
if ( ! defined( 'FITHOME_PRODUCT_ID' ) )        define( 'FITHOME_PRODUCT_ID', 1905 );
if ( ! function_exists( 'fithome_calc_shipping' ) ) {
    function fithome_calc_shipping( $qty, $location ) {
        if ( intval($qty) >= FITHOME_FREE_SHIP_MIN_QTY ) return 0;
        $r = fithome_get_shipping_rates();
        return isset($r[$location]) ? $r[$location] : $r['inside_dhaka'];
    }
}
if ( ! function_exists( 'fithome_bn_num' ) ) {
    function fithome_bn_num( $num ) {
        return str_replace( array('0','1','2','3','4','5','6','7','8','9'),
                            array('০','১','২','৩','৪','৫','৬','৭','৮','৯'), (string) $num );
    }
}
if ( ! function_exists( 'fithome_normalize_phone' ) ) {
    // [TRACKING FIX] আগে '1712345678' (শূন্য ছাড়া ১০ ডিজিট) দিলে '881712345678' হতো —
    // শূন্য মিসিং, ভুল E.164। এখন '8801712345678' হবে।
    function fithome_normalize_phone( $phone ) {
        $d = preg_replace('/\D/', '', (string) $phone);
        if ($d === '') return '';
        if (strpos($d, '880') === 0) return $d;
        if (strpos($d, '0') === 0)   return '88' . $d;
        if (strlen($d) === 10 && strpos($d, '1') === 0) return '880' . $d;
        return '88' . $d;
    }
}

$fithome_packages  = fithome_get_packages();
$fithome_shipping  = fithome_get_shipping_rates();


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fithome_order_submit'])) {
    if (class_exists('WooCommerce')) {

        // [FIX] আগে isset চেক ছাড়া $_POST অ্যাক্সেস হতো — PHP 8-এ "Undefined array key"
        // warning + sanitize_text_field(null) deprecation নোটিশ পড়ত (worst case redirect ভেঙে যেত)
        $billing_first_name = isset($_POST['billing_first_name']) ? sanitize_text_field( wp_unslash($_POST['billing_first_name']) ) : '';
        $billing_phone      = isset($_POST['billing_phone'])      ? sanitize_text_field( wp_unslash($_POST['billing_phone']) )      : '';
        $billing_address    = isset($_POST['billing_address'])    ? sanitize_text_field( wp_unslash($_POST['billing_address']) )    : '';
        $product_qty        = isset($_POST['product_qty'])        ? intval($_POST['product_qty'])                                   : 1;
        $delivery_location  = isset($_POST['delivery_location'])  ? sanitize_text_field( wp_unslash($_POST['delivery_location']) )  : '';


        if (isset($_POST['fithome_bot_trap']) && !empty($_POST['fithome_bot_trap'])) {
            wp_die('Spam detected! Request blocked.');
        }


        if (!preg_match('/^01[3-9][0-9]{8}$/', $billing_phone)) {
            wp_die('<div style="font-family:sans-serif; text-align:center; margin-top:50px; color:red;"><h3>দয়া করে একটি সঠিক ১১ ডিজিটের বাংলাদেশি মোবাইল নম্বর দিন।</h3><br><a href="javascript:history.back()" style="padding:10px 20px; background:#1B4D3E; color:#fff; text-decoration:none; border-radius:5px;">ফিরে যান</a></div>');
        }

        // [FIX] নাম ও ঠিকানা খালি থাকলে আগে খালি অর্ডার তৈরি হয়ে যেত
        if ( $billing_first_name === '' || $billing_address === '' ) {
            wp_die('<div style="font-family:sans-serif; text-align:center; margin-top:50px; color:red;"><h3>দয়া করে নাম ও সম্পূর্ণ ডেলিভারি ঠিকানা লিখুন।</h3><br><a href="javascript:history.back()" style="padding:10px 20px; background:#1B4D3E; color:#fff; text-decoration:none; border-radius:5px;">ফিরে যান</a></div>');
        }

        // [FIX — ক্রিটিক্যাল] আগে product_qty সরাসরি POST থেকে নেওয়া হতো।
        // কেউ product_qty=10 পাঠালে কোনো condition ম্যাচ করত না → দাম থাকত ৯৯০,
        // কিন্তু অর্ডার হয়ে যেত ১০ কেজি + ফ্রি ডেলিভারি। এখন whitelist ছাড়া কিছুই গ্রহণ হয় না।
        $custom_package_price = fithome_get_package_price( $product_qty );
        if ( ! $custom_package_price ) {
            wp_die('<div style="font-family:sans-serif; text-align:center; margin-top:50px; color:red;"><h3>দুঃখিত, প্যাকেজটি সঠিক নয়।</h3><p>অনুগ্রহ করে আবার প্যাকেজ সিলেক্ট করে অর্ডার করুন।</p><br><a href="javascript:history.back()" style="padding:10px 20px; background:#1B4D3E; color:#fff; text-decoration:none; border-radius:5px;">ফিরে যান</a></div>');
        }

        // [FIX] delivery_location-ও whitelist — যেকোনো স্ট্রিং পাঠিয়ে শিপিং লজিক ঘোরানো যেত
        if ( ! isset( $fithome_shipping[ $delivery_location ] ) ) {
            $delivery_location = 'inside_dhaka';
        }


        $user_ip = fithome_get_real_ip();

        $transient_ip_key    = 'fithome_limit_ip_' . md5($user_ip);
        $transient_phone_key = 'fithome_limit_phone_' . md5($billing_phone);

        $ip_order_count    = (int) get_transient($transient_ip_key);
        $phone_order_count = (int) get_transient($transient_phone_key);

        /* [FIX — রেট লিমিট] IP-প্রতি ঘণ্টায় ২টি, ফোন-প্রতি সপ্তাহে ২টি।
           ব্লক হলে কাস্টমারকে ফেসবুক পেজে পাঠানো হচ্ছে, যাতে অর্ডারটি হারিয়ে না যায়। */
        $max_orders_per_ip_hour    = 2;   // এক IP থেকে ঘণ্টায় ২টি
        $max_orders_per_phone_week = 2;   // এক নম্বর থেকে সপ্তাহে ২টি

        // ---- ফোন লিমিট (সাপ্তাহিক) ----
        if ($phone_order_count >= $max_orders_per_phone_week) {
            wp_die('<div style="font-family:sans-serif; text-align:center; margin-top:50px; color:#E64A19; padding:0 20px;"><h3>আপনি এই সপ্তাহে সর্বোচ্চ সংখ্যক (২টি) অর্ডার করে ফেলেছেন।</h3><p style="color:#333; font-size:16px; line-height:1.6;">আরও অর্ডার করতে চাইলে আমাদের <b>ফেসবুক পেজে মেসেজ দিন</b> — আমরা সাথে সাথেই অর্ডার নিয়ে নেব।</p><br><a href="https://www.facebook.com/fithomebangladesh" style="padding:14px 28px; background:#1877F2; color:#fff; text-decoration:none; border-radius:6px; font-weight:bold; font-size:16px; display:inline-block;">📩 ফেসবুক পেজে অর্ডার করুন</a><br><br><a href="javascript:history.back()" style="padding:10px 20px; background:#1B4D3E; color:#fff; text-decoration:none; border-radius:5px; display:inline-block;">ফিরে যান</a></div>');
        }

        // ---- IP লিমিট (ঘণ্টাভিত্তিক) ----
        if ($ip_order_count >= $max_orders_per_ip_hour) {
            wp_die('<div style="font-family:sans-serif; text-align:center; margin-top:50px; color:#E64A19; padding:0 20px;"><h3>এই মুহূর্তে অর্ডারটি সম্পন্ন করা যাচ্ছে না।</h3><p style="color:#333; font-size:16px; line-height:1.6;">আপনার নেটওয়ার্ক থেকে অল্প সময়ে একাধিক অর্ডার হয়েছে।<br>চিন্তার কিছু নেই — আমাদের <b>ফেসবুক পেজে মেসেজ দিলেই</b> আমরা আপনার অর্ডারটি নিয়ে নেব।</p><br><a href="https://www.facebook.com/fithomebangladesh" style="padding:14px 28px; background:#1877F2; color:#fff; text-decoration:none; border-radius:6px; font-weight:bold; font-size:16px; display:inline-block;">📩 ফেসবুক পেজে অর্ডার করুন</a><br><br><p style="color:#6c757d; font-size:14px;">অথবা ১ ঘণ্টা পর আবার চেষ্টা করুন।</p><a href="javascript:history.back()" style="padding:10px 20px; background:#1B4D3E; color:#fff; text-decoration:none; border-radius:5px; display:inline-block;">ফিরে যান</a></div>');
        }


        $product_id = FITHOME_PRODUCT_ID;

        // ডেলিভারি চার্জ — এখন কেন্দ্রীয় ফাংশন থেকে (JS ও PHP একই লজিক ব্যবহার করে)
        $shipping_charge = fithome_calc_shipping( $product_qty, $delivery_location );

        // [FIX] প্রোডাক্ট ডিলিট/ট্র্যাশ হলে আগে fatal error হতো — এখন চেক করে গ্রেসফুলি বন্ধ করে
        $product = wc_get_product($product_id);
        if ( ! $product ) {
            wp_die('<div style="font-family:sans-serif; text-align:center; margin-top:50px; color:red;"><h3>দুঃখিত, এই মুহূর্তে প্রোডাক্টটি পাওয়া যাচ্ছে না।</h3><p>অনুগ্রহ করে কিছুক্ষণ পরে আবার চেষ্টা করুন অথবা আমাদের হটলাইনে যোগাযোগ করুন।</p><br><a href="javascript:history.back()" style="padding:10px 20px; background:#1B4D3E; color:#fff; text-decoration:none; border-radius:5px;">ফিরে যান</a></div>');
        }

        $order = wc_create_order();

        $args = array(
            'subtotal' => $custom_package_price,
            'total'    => $custom_package_price,
        );
        $order->add_product($product, $product_qty, $args);


        $address = array(
            'first_name' => $billing_first_name,
            'last_name'  => '', // WooCommerce বিলিং অ্যারে-তে এই কী প্রত্যাশা করে — খালি হলেও পাস করা নিরাপদ
            'phone'      => $billing_phone,
            'address_1'  => $billing_address,
            'city'       => ($delivery_location === 'inside_dhaka') ? 'Dhaka' : 'Outside Dhaka',
            'country'    => 'BD'
        );

        $order->set_address($address, 'billing');
        $order->set_address($address, 'shipping');


        if ($shipping_charge > 0) {
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

        $order->update_status('processing', 'Order created via Fit Home Daily Protein Custom Landing Page.', true);


        set_transient($transient_ip_key, $ip_order_count + 1, HOUR_IN_SECONDS);
        set_transient($transient_phone_key, $phone_order_count + 1, WEEK_IN_SECONDS);


        // [FIX — ক্রিটিক্যাল] আগে শুধু order ID দিয়ে থ্যাংক ইউ পেজ খোলা যেত (IDOR) —
        // ?wc_order=1234 বদলে বদলে যে কেউ সব কাস্টমারের নাম-ফোন-অর্ডার দেখে ফেলতে পারত।
        // এখন order_key ছাড়া থ্যাংক ইউ পেজ ডিটেইলস দেখাবে না।
        wp_redirect( home_url('/order-confirmed/?wc_order=' . $order->get_id() . '&key=' . $order->get_order_key()) );
        exit;
    }
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>

<!-- [FIX] charset সবসময় সবার আগে — বাংলা টেক্সটে mojibake এড়াতে -->
<meta charset="<?php bloginfo( 'charset' ); ?>">

<!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','GTM-54232S48');</script>
<!-- End Google Tag Manager -->


    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fit Home </title>

<link rel="preload" href="<?php echo get_stylesheet_directory_uri(); ?>/fonts/hind-siliguri-v14-bengali_latin-700.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preconnect" href="https://www.youtube.com">

    <?php wp_head(); ?>

    <style>


/* ========================================================
   FIT HOME - PERFECT LOCAL FONTS OPTIMIZATION (v14 & v33)
   ======================================================== */

/* Hind Siliguri - 400, 600, 700 */
@font-face {
    font-family: 'Hind Siliguri';
    font-style: normal;
    font-weight: 400;
    font-display: swap;
    src: url('<?php echo get_stylesheet_directory_uri(); ?>/fonts/hind-siliguri-v14-bengali_latin-regular.woff2') format('woff2');
}
@font-face {
    font-family: 'Hind Siliguri';
    font-style: normal;
    font-weight: 600;
    font-display: swap;
    src: url('<?php echo get_stylesheet_directory_uri(); ?>/fonts/hind-siliguri-v14-bengali_latin-600.woff2') format('woff2');
}
@font-face {
    font-family: 'Hind Siliguri';
    font-style: normal;
    font-weight: 700;
    font-display: swap;
    src: url('<?php echo get_stylesheet_directory_uri(); ?>/fonts/hind-siliguri-v14-bengali_latin-700.woff2') format('woff2');
}

/* Noto Sans Bengali - 600, 800 */
@font-face {
    font-family: 'Noto Sans Bengali';
    font-style: normal;
    font-weight: 600;
    font-display: swap;
    src: url('<?php echo get_stylesheet_directory_uri(); ?>/fonts/noto-sans-bengali-v33-bengali_latin-600.woff2') format('woff2');
}
@font-face {
    font-family: 'Noto Sans Bengali';
    font-style: normal;
    font-weight: 800;
    font-display: swap;
    src: url('<?php echo get_stylesheet_directory_uri(); ?>/fonts/noto-sans-bengali-v33-bengali_latin-800.woff2') format('woff2');
}





        :root {
            --primary-dark:  #1B4D3E;
            --primary-mid:   #2F7A60;
            --primary-pale:  #EAF5EF;
            --accent:        #FF5722;
            --accent-dark:   #E64A19;
            --gold:          #FFC94D;
            --gold-deep:     #FFB300;
            --cream:         #FFF8EC;
            --warm-border:   #F0D9B2;
            --text-dark:     #2c3e50;
            --text-muted:    #6c757d;
            --success-green: #198754;
            --border-soft:   #ECEFED;
            --radius-card:   14px;
            --shadow-card:   0 6px 18px rgba(27,77,62,0.07);
        }

        .pkg-desc {
            font-family: 'Noto Sans Bengali', sans-serif !important;
            font-size: clamp(12.5px, 3.6vw, 16px);
            color: #333;
            font-weight: 600;
            margin-bottom: 5px;
            line-height: 1.3;
        }


        * { box-sizing: border-box; }

        html { -webkit-text-size-adjust: 100%; }

        body {
            font-family: 'Hind Siliguri', sans-serif !important;
            background-color: #e9ecef;
            color: var(--text-dark);
            margin: 0;
            padding: 0;
            scroll-behavior: smooth;
            -webkit-font-smoothing: antialiased;
        }

        img { max-width: 100%; display: block; }

        .wrapper {
            max-width: 860px;
            width: 100%;
            margin: 0 auto;
            background: #ffffff;
            box-shadow: 0 0 25px rgba(0,0,0,0.10);
            overflow: hidden;
            position: relative;
        }

        .wrapper::before {
            content: '';
            display: block;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-dark), var(--gold), var(--accent));
        }

        a:focus-visible, button:focus-visible, input:focus-visible, summary:focus-visible {
            outline: 3px solid var(--gold);
            outline-offset: 2px;
        }

        @media (prefers-reduced-motion: reduce) {
            *, .btn-cta, .pulse-dot { animation: none !important; transition: none !important; }
        }


        .top-brand-bar {
            background-color: #ffffff;
            text-align: center;
            padding: clamp(4px, 1vw, 6px) 20px;
            border-bottom: 1px solid #f0f0f0;
        }
        .brand-name-text {
            font-size: clamp(22px, 5.5vw, 30px);
            font-weight: 800;
            color: #10241c;
            margin: 0;
            letter-spacing: 2px;
            text-transform: uppercase;
        }
        .brand-tagline {
            font-size: clamp(10px, 2.6vw, 12px);
            color: var(--text-muted);
            letter-spacing: 3px;
            text-transform: uppercase;
            font-weight: 700;
            margin-top: 4px;
        }


        .hero-banner {
            position: relative;
            background: linear-gradient(165deg, #0F3A2E 0%, #1B4D3E 45%, #2F7A60 100%);
            color: #ffffff;
            text-align: center;
            padding: clamp(16px, 4vw, 25px) clamp(18px, 5vw, 30px) clamp(18px, 4vw, 25px);
            overflow: hidden;
        }
        .hero-banner::before {
            content: '';
            position: absolute;
            width: 260px; height: 260px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(255,255,255,0.07), transparent 70%);
            top: -100px; right: -70px;
            pointer-events: none;
        }
        .hero-banner::after {
            content: '';
            position: absolute;
            width: 200px; height: 200px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(255,201,77,0.12), transparent 70%);
            bottom: -80px; left: -50px;
            pointer-events: none;
        }
        .hero-inner { position: relative; z-index: 1; }

        .hero-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: clamp(11px, 2.8vw, 13px);
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            background: rgba(255,255,255,0.10);
            border: 1px solid rgba(255,255,255,0.22);
            color: var(--gold);
            padding: 7px 16px;
            border-radius: 50px;
            margin-bottom: clamp(14px, 4vw, 18px);
        }

        .hero-banner h1 {
            font-size: clamp(22px, 6vw, 34px);
            font-weight: 700;
            margin: 0;
            line-height: 1.5;
            color: #ffffff;
        }

        .hero-banner h1 .highlight {
            display: inline-block;
            background: linear-gradient(135deg, #FFD54F 0%, #FF9800 100%);
            color: #062116;
            font-size: clamp(28px, 8vw, 48px);
            font-weight: 900;
            padding: 8px clamp(22px, 6vw, 40px);
            margin-top: 12px;
            border-radius: 12px;
            transform: rotate(-2.5deg);


            border: 2px solid rgba(255, 255, 255, 0.4);
            box-shadow: 0 8px 0 #b36b00, 0 15px 35px rgba(255, 152, 0, 0.35);
            letter-spacing: 0.5px;
            transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);


            animation: glowingBorder 2.5s infinite alternate;
        }

        .hero-banner h1 .highlight:hover {
            transform: rotate(-1deg) scale(1.02) translateY(-2px);
            animation-play-state: paused;
        }


        @keyframes glowingBorder {
            0% {
                border-color: rgba(255, 213, 79, 0.3);
                box-shadow: 0 8px 0 #b36b00, 0 15px 35px rgba(255, 152, 0, 0.2);
            }
            100% {
                border-color: rgba(255, 255, 255, 0.9);
                box-shadow: 0 8px 0 #b36b00, 0 15px 35px rgba(255, 152, 0, 0.6), 0 0 15px rgba(255, 255, 255, 0.5);
            }
        }

        .social-proof-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: clamp(12.5px, 3.2vw, 16px);
            font-weight: 600;
            color: #fff;
            background: rgba(255, 255, 255, 0.08);
            padding: 10px clamp(14px, 4vw, 20px);
            border-radius: 50px;
            border: 1px dashed rgba(255,255,255,0.35);
            margin-top: clamp(18px, 5vw, 22px);
            line-height: 1.5;
            text-align: left;
            max-width: 100%;
        }
        .social-proof-badge .pulse-dot {
            width: 9px; height: 9px;
            border-radius: 50%;
            background: var(--gold);
            flex-shrink: 0;
            animation: pulseDot 2s infinite;
        }
        @keyframes pulseDot {
            0%   { box-shadow: 0 0 0 0 rgba(255,201,77,0.6); }
            70%  { box-shadow: 0 0 0 8px rgba(255,201,77,0); }
            100% { box-shadow: 0 0 0 0 rgba(255,201,77,0); }
        }

        /* সোশ্যাল প্রুফ ব্যাজের সংখ্যা আরও হাইলাইট করা হলো */
.social-proof-badge .badge-num {
    font-family: 'Noto Sans Bengali', sans-serif !important; 
    color: #FFC94D; /* উজ্জ্বল গোল্ডেন কালার */
    font-size: 1.4em; /* ফন্ট সাইজ আগের চেয়ে আরও বড় করা হয়েছে */
    font-weight: 800;
    line-height: 0.8; /* এর কারণে ফন্ট বড় হলেও আশেপাশের টেক্সট উপরে-নিচে সরবে না */
    display: inline-block;
    padding: 0 4px; /* সংখ্যার দুই পাশে একটু ফাঁকা জায়গা রাখার জন্য */
}

        .video-container, .video-item {
            position: relative !important;
            overflow: hidden;
            background: #000;
            cursor: pointer;
        }

        .video-container {
            padding-bottom: 125%;
            height: 0;
            border-bottom: 4px solid var(--accent);
        }

        .video-item {
            padding-bottom: 125%;
            height: 0;
        }

        .video-container iframe, .video-container img,
        .video-item iframe, .video-item img {  
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            border: none;
            
        }


        .video-play-btn {
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            width: 65px; height: 65px;
            background: rgba(230, 74, 25, 0.95);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 6px 20px rgba(0,0,0,0.4);
            transition: all 0.3s ease;
            z-index: 5;
            pointer-events: none;
        }
        .video-container:hover .video-play-btn, .video-item:hover .video-play-btn {
            background: #FF5722;
            transform: translate(-50%, -50%) scale(1.08);
        }
        .video-play-btn::after {
            content: '';
            display: block;
            width: 0; height: 0;
            border-style: solid;
            border-width: 10px 0 10px 18px;
            border-color: transparent transparent transparent #ffffff;
            margin-left: 4px;
        }

        .content-padding { padding: clamp(28px, 7vw, 40px) clamp(16px, 5vw, 30px) clamp(14px, 4vw, 20px); }


        .btn-cta {
            display: block;
            background: var(--accent);
            color: #ffffff;
            font-size: clamp(16px, 4.5vw, 22px);
            font-weight: 700;
            text-decoration: none;
            text-align: center;
            padding: clamp(13px, 3.5vw, 16px);
            border-radius: 10px;
            margin: clamp(18px, 5vw, 25px) auto;
            width: 100%;
            max-width: 500px;
            box-shadow: 0 6px 15px rgba(255, 87, 34, 0.4);
            border: none;
            cursor: pointer;
            animation: pulseCTA 2s infinite;
            transition: all 0.3s ease;
        }
        .btn-cta:hover { transform: translateY(-3px); background: var(--accent-dark); }
        @keyframes pulseCTA {
            0% { transform: scale(1); box-shadow: 0 6px 15px rgba(255, 87, 34, 0.4); }
            50% { transform: scale(1.02); box-shadow: 0 8px 20px rgba(255, 87, 34, 0.6); }
            100% { transform: scale(1); box-shadow: 0 6px 15px rgba(255, 87, 34, 0.4); }
        }


        .section-title {
            text-align: center;
            color: var(--primary-dark);
            font-size: clamp(20px, 5.5vw, 28px);
            font-weight: 800;
            margin: clamp(30px, 8vw, 40px) 0 8px;
            line-height: 1.3;
        }
        .section-title::after {
            content: '';
            display: block;
            width: 56px; height: 4px;
            background: linear-gradient(90deg, var(--accent), var(--gold));
            margin: 12px auto 0;
            border-radius: 2px;
        }
        .section-sub {
            text-align: center;
            color: var(--text-muted);
            font-size: clamp(12px, 3.2vw, 14.5px);
            font-weight: 600;
            margin: 0 0 clamp(18px, 5vw, 26px);
            line-height: 1.5;
        }


        .benefits-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: clamp(10px, 3vw, 18px);
            margin-bottom: clamp(20px, 5vw, 30px);
        }
        .benefit-card {
            background: #ffffff;
            border: 1px solid var(--border-soft);
            border-radius: var(--radius-card);
            padding: clamp(16px, 4.5vw, 22px) clamp(8px, 2.5vw, 12px);
            text-align: center;
            box-shadow: var(--shadow-card);
            transition: transform 0.25s ease, box-shadow 0.25s ease;
        }
        .benefit-card:hover { transform: translateY(-5px); box-shadow: 0 10px 24px rgba(27,77,62,0.14); }
        .benefit-icon {
            width: clamp(46px, 13vw, 58px);
            height: clamp(46px, 13vw, 58px);
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-mid), var(--primary-dark));
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto clamp(8px, 2.5vw, 12px);
            font-size: clamp(20px, 5.5vw, 26px);
            box-shadow: 0 4px 10px rgba(27,77,62,0.28);
        }
        .benefit-card p { margin: 0; font-size: clamp(12.5px, 3.4vw, 15px); font-weight: 700; color: var(--text-dark); line-height: 1.4; }


        .ingredients-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: clamp(10px, 3vw, 18px);
            margin-bottom: clamp(20px, 5vw, 30px);
        }
        .ingredient-card {
            background: linear-gradient(160deg, var(--cream), #ffffff);
            border: 1.5px dashed var(--warm-border);
            border-radius: var(--radius-card);
            padding: clamp(16px, 4.5vw, 22px) clamp(8px, 2.5vw, 12px);
            text-align: center;
        }
        .ingredient-icon {
            width: clamp(46px, 13vw, 58px);
            height: clamp(46px, 13vw, 58px);
            border-radius: 50%;
            background: #ffffff;
            border: 2px solid var(--accent);
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto clamp(8px, 2.5vw, 12px);
            font-size: clamp(20px, 5.5vw, 26px);
            box-shadow: 0 4px 10px rgba(255,87,34,0.15);
        }
        .ingredient-card p { margin: 0; font-size: clamp(12.5px, 3.4vw, 15px); font-weight: 700; color: var(--primary-dark); line-height: 1.4; }

        @media (max-width: 768px) {
            .benefits-grid, .ingredients-grid { grid-template-columns: repeat(2, 1fr); }
        }


        .demo-img {
            width: 100%;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            margin-bottom: 15px;
        }

        .image-slider-wrapper {
            display: flex;
            gap: 20px;
            overflow-x: auto;
            padding: 20px 10px 30px 10px;
            scroll-snap-type: x mandatory;
            scroll-behavior: smooth;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
            scrollbar-color: #ccc transparent;
        }

        .slider-image-item {
            flex: 0 0 32%;
            min-width: 280px;
            border-radius: 16px;
            background: #ffffff;
            box-shadow: 0 12px 28px rgba(27, 77, 62, 0.12), 0 4px 10px rgba(0,0,0,0.04);
            overflow: hidden;
            scroll-snap-align: center;
            border: 2px solid #fff;
            padding: 6px;
            aspect-ratio: 3 / 4;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .slider-image-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            border-radius: 12px;
        }

        .slider-image-item:hover {
            transform: translateY(-4px) scale(1.02);
            box-shadow: 0 20px 40px rgba(27, 77, 62, 0.18);
        }

        @media (max-width: 820px) {
            .slider-image-item {
                flex: 0 0 48%;
                min-width: 200px;
            }
        }

        @media (max-width: 500px) {
            .slider-image-item {
                flex: 0 0 88%;
                min-width: 0;
                aspect-ratio: 4 / 5;
            }
            .image-slider-wrapper {
                gap: 12px;
                padding: 12px 0 20px 0;
            }
        }


        .image-slider-wrapper::-webkit-scrollbar { height: 4px; }
        .image-slider-wrapper::-webkit-scrollbar-track { background: #e9ecef; border-radius: 8px; }
        .image-slider-wrapper::-webkit-scrollbar-thumb { background: var(--primary-mid); border-radius: 8px; }
        .image-slider-wrapper::-webkit-scrollbar-thumb:hover { background: var(--primary-dark); }


        .video-reviews-grid {
            display: flex;
            flex-direction: column;
            gap: 16px;
            margin: 20px auto 0;
            width: 100%;
            max-width: 650px;
        }


        .faq-accordion { max-width: 650px; margin: 0 auto 10px; }
        .faq-item { background: #fff; border: 1px solid #ddd; margin-bottom: 12px; border-radius: 8px; overflow: hidden; }
        .faq-item summary { font-size: clamp(14.5px, 3.8vw, 18px); font-weight: 700; padding: clamp(12px, 3.5vw, 15px) clamp(14px, 4vw, 20px); cursor: pointer; background: #f8f9fa; color: var(--primary-dark); list-style: none; display: flex; justify-content: space-between; align-items: center; gap: 10px; }
        .faq-item summary::-webkit-details-marker { display: none; }
        .faq-item summary::after { content: '+'; font-size: 24px; color: var(--accent); transition: transform 0.3s; flex-shrink: 0; }
        .faq-item[open] summary::after { content: '-'; transform: rotate(180deg); }
        .faq-item p { padding: clamp(12px, 3.5vw, 15px) clamp(14px, 4vw, 20px); margin: 0; color: #555; line-height: 1.6; font-size: clamp(13.5px, 3.6vw, 16px); border-top: 1px solid #eee; }


        .checkout-section {
            background: #ffffff;
            border: 2px solid var(--primary-dark);
            border-radius: 14px;
            padding: clamp(22px, 6vw, 35px) clamp(14px, 4vw, 30px);
            margin: clamp(10px, 3vw, 15px) auto clamp(35px, 8vw, 50px);
            max-width: 650px;
            box-shadow: 0 12px 30px rgba(0,0,0,0.06);
        }
        .checkout-header { text-align: center; font-size: clamp(19px, 5.2vw, 26px); font-weight: 800; color: var(--primary-dark); margin-bottom: 4px; }
        .checkout-sub { text-align: center; font-size: clamp(12px, 3.2vw, 14px); color: var(--text-muted); font-weight: 600; margin: 0 0 clamp(20px, 5vw, 30px); }

        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: 800; margin-bottom: 8px; color: #10241c; font-size: clamp(14px, 3.8vw, 17px); }
        .form-control { width: 100%; padding: clamp(12px, 3.5vw, 15px); border: 1.5px solid #ccc; border-radius: 8px; box-sizing: border-box; font-size: clamp(14px, 3.8vw, 16px); font-family: inherit; transition: border-color 0.25s, box-shadow 0.25s; background: #fafafa; }
        .form-control:focus { border-color: var(--primary-dark); outline: none; background: #fff; box-shadow: 0 0 0 3px rgba(27,77,62,0.08); }


        .package-options {
            display: flex;
            flex-direction: column;
            gap: clamp(12px, 3.5vw, 16px);
            margin-bottom: clamp(20px, 5vw, 25px);
        }
        .package-box {
            position: relative;
            display: grid;
            grid-template-columns: auto minmax(0, 1fr);
            align-items: center;
            gap: clamp(10px, 3vw, 16px);
            background: #fbfcfb;
            border: 2px solid #e6eae8;
            padding: clamp(16px, 4.5vw, 20px) clamp(12px, 3.5vw, 18px);
            padding-top: clamp(24px, 6.5vw, 28px);
            cursor: pointer;
            transition: border-color 0.25s ease, background 0.25s ease, box-shadow 0.25s ease, transform 0.25s ease;
            border-radius: 12px;
            width: 100%;
            min-height: clamp(98px, 27vw, 120px);
        }
        .package-box:hover { border-color: #cfdbd6; }
        .package-box.active {
            border-color: var(--primary-dark);
            background: linear-gradient(135deg, var(--primary-pale), #ffffff);
            box-shadow: 0 8px 22px rgba(27, 77, 62, 0.16);
            transform: translateY(-2px);
        }
        .package-box input[type="radio"] { display: none; }

        .pkg-left { display: flex; align-items: center; gap: clamp(8px, 2.5vw, 12px); flex-shrink: 0; }
        .custom-radio {
            width: clamp(22px, 5.5vw, 28px); height: clamp(22px, 5.5vw, 28px); border-radius: 50%; background: #fff; border: 2px solid #c7cfcc; position: relative; flex-shrink: 0; transition: border-color 0.2s ease; box-sizing: border-box;
        }
        .package-box.active .custom-radio { border-color: var(--primary-dark); }
        .package-box.active .custom-radio::after {
            content: ''; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 58%; height: 58%; background: var(--primary-dark); border-radius: 50%;
        }
        .pkg-img {
            width: clamp(54px, 15vw, 76px); height: clamp(54px, 15vw, 76px); object-fit: contain; border: 1px solid #eee; border-radius: 8px; background: #fff; flex-shrink: 0; padding: 4px;
        }
        .pkg-info { display: flex; flex-direction: column; justify-content: center; min-width: 0; padding-right: 4px; }
        .pkg-title { font-size: clamp(15px, 4.2vw, 20px); font-weight: 800; color: #10241c; margin-bottom: 3px; line-height: 1.25; }
        .pkg-price { font-size: clamp(15px, 4.2vw, 20px); color: var(--primary-dark); font-weight: 800; line-height: 1.2; }
        .pkg-price del { text-decoration-thickness: 2px; color: #a3aca8; margin-right: 8px; font-weight: 500; white-space: nowrap; font-size: 0.85em; }

        .pkg-badge {
            position: absolute; top: -12px; right: 12px;
            font-size: clamp(10.5px, 2.8vw, 13px);
            padding: 5px clamp(8px, 2.5vw, 12px);
            font-weight: 800;
            border-radius: 20px;
            z-index: 2;
            white-space: nowrap;
            color: #fff;
            box-shadow: 0 3px 8px rgba(0,0,0,0.15);
            letter-spacing: 0.2px;
            max-width: calc(100% - 90px);
            overflow: hidden; text-overflow: ellipsis;
        }
        .package-box:nth-child(1) .pkg-badge { background: linear-gradient(135deg, #8a93a0, #6c757d); }
        .package-box:nth-child(2) .pkg-badge { background: linear-gradient(135deg, #FF7A45, var(--accent)); }
        .package-box:nth-child(3) .pkg-badge { background: linear-gradient(135deg, #FF6B6B, #E53935); }

        .pkg-ribbon {
            position: absolute; top: -1px; left: -1px;
            background: var(--primary-dark);
            color: #fff;
            font-size: clamp(10px, 2.6vw, 12px);
            font-weight: 800;
            padding: 5px clamp(10px, 3vw, 14px) 5px clamp(8px, 2.5vw, 10px);
            border-radius: 10px 0 10px 0;
            z-index: 2;
            letter-spacing: 0.3px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            line-height: 1.2;
        }

        .guarantee-text {
            text-align: center;
            font-size: clamp(12px, 3.2vw, 14px);
            color: var(--primary-mid);
            margin-bottom: clamp(18px, 5vw, 25px);
            font-weight: 700;
            background: var(--primary-pale);
            padding: 10px;
            border-radius: 8px;
            border: 1px dashed #bfe3d2;
        }


        .location-options { display: flex; gap: clamp(10px, 3vw, 15px); margin-top: 10px; }
        .location-radio { cursor: pointer; display: flex; align-items: center; font-weight: 600; font-size: clamp(13.5px, 3.6vw, 16px); background: #fdfdfd; padding: clamp(12px, 3.5vw, 14px) clamp(12px, 3.5vw, 15px); border: 1.5px solid #ccc; border-radius: 8px; flex: 1; transition: border-color 0.25s, background 0.25s; }
        .location-radio:hover { border-color: var(--primary-dark); }
        .location-radio:has(input:checked) { border-color: var(--primary-dark); background: var(--primary-pale); }

.location-radio input[type="radio"] { 
    margin: 0 10px 0 0; 
    width: 22px; 
    height: 22px; 
    accent-color: var(--primary-dark); 
    cursor: pointer; 
    flex-shrink: 0; 
    transform: translateY(6px); /* এটি রেডিও বাটনটিকে ঠিক ২ পিক্সেল নিচে নামিয়ে টেক্সটের সমান করবে */
}
        @media (max-width: 480px) {
            .location-options { flex-direction: column; gap: 10px; }
            .wrapper { box-shadow: none; }
            .social-proof-badge { border-radius: 16px; text-align: center; }

            .pkg-badge {
                top: -10px;
                right: -2px;
                padding: 1px 6px;
                font-size: 11px;
                max-width: calc(100% - 75px);
            }

            .package-box { padding-top: 28px; }
        }


        .order-summary {
            background: var(--primary-pale);
            padding: clamp(16px, 4.5vw, 20px);
            border-radius: 10px;
            border: 1px solid #d6ece2;
            font-weight: 600;
        }
        .order-summary .row {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 15px;
            margin-bottom: 8px;
            font-size: clamp(13.5px, 3.6vw, 16px);
        }
        .order-summary hr {
            border: 0;
            border-top: 1px dashed #ccc;
            margin: 12px 0;
        }
        .order-summary .total-row {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 15px;
            color: var(--primary-dark);
            font-size: clamp(17px, 4.6vw, 20px);
            font-weight: 800;
        }
        
        .order-summary .cod-note {
            margin-top: 12px;
            font-size: clamp(12px, 3.2vw, 14px);
            color: #555;
            font-weight: normal;
            text-align: center;
        }


        .spinner-icon {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s linear infinite;
            vertical-align: middle;
            margin-right: 8px;
            margin-top: -3px;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .btn-cta.is-loading {
            background: #6c757d !important;
            cursor: not-allowed;
            box-shadow: none !important;
            transform: none !important;
            opacity: 0.9;
        }


        .input-with-icon {
            position: relative;
            display: block;
        }
        .check-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 16px;
            opacity: 0;
            transition: opacity 0.3s ease, transform 0.3s ease;
            pointer-events: none;
        }
        .form-control.is-valid {
            border-color: var(--success-green) !important;
            box-shadow: 0 0 0 3px rgba(25, 135, 84, 0.15) !important;
            padding-right: 40px;
            background-color: #f4fdf8 !important;
        }
        .form-control.is-valid + .check-icon {
            opacity: 1;
            transform: translateY(-50%) scale(1.2);
        }


.fomo-timer-container {
    background: #FFF8EC; /* Soft cream background */
    border: 1.5px dashed #FFB300; /* Gold/Yellow border */
    border-radius: 12px;
    padding: 16px;
    text-align: center;
    margin: 20px auto;
    max-width: 650px;
    box-shadow: 0 6px 15px rgba(255, 179, 0, 0.1);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
}

.fomo-text {
    color: #1B4D3E; /* Brand dark green */
    font-weight: 700;
    font-size: clamp(15px, 4vw, 18px);
}

.fomo-countdown {
    background: #E64A19; /* Accent dark orange/red */
    color: #ffffff;
    padding: 6px 16px;
    border-radius: 8px;
    box-shadow: 0 4px 10px rgba(230, 74, 25, 0.3);
}

#fomo-timer {
    font-family: 'Noto Sans Bengali', sans-serif;
    font-weight: 800;
    font-size: clamp(20px, 5.5vw, 24px);
    letter-spacing: 2px;
}


.ingredient-card {
    position: relative;
    cursor: pointer;
}
.ingredient-tooltip {
    visibility: hidden;
    position: absolute;
    z-index: 99;
    bottom: 105%;
    left: 50%;
    transform: translateX(-50%) translateY(5px);
    width: 220px;
    background-color: #1B4D3E;
    color: #fff;
    text-align: center;
    padding: 10px 12px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 500;
    line-height: 1.4;
    box-shadow: 0 8px 20px rgba(0,0,0,0.2);
    opacity: 0;
    transition: opacity 0.3s ease, transform 0.3s ease, visibility 0.3s;
}
.ingredient-tooltip::after {
    content: "";
    position: absolute;
    top: 100%;
    left: 50%;
    margin-left: -6px;
    border-width: 6px;
    border-style: solid;
    border-color: #1B4D3E transparent transparent transparent;
}

.ingredient-card:hover .ingredient-tooltip,
.ingredient-card.show-tooltip .ingredient-tooltip {
    visibility: visible;
    opacity: 1;
    transform: translateX(-50%) translateY(0);
}


.social-proof-toast {
    position: fixed;
    bottom: -100px;
    left: 20px;
    background: #ffffff;
    border-left: 4px solid var(--primary-dark);
    box-shadow: 0 10px 30px rgba(0,0,0,0.15);
    border-radius: 10px;
    padding: 12px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    z-index: 99999;
    max-width: 340px;
    width: calc(100% - 40px);
    transition: bottom 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}
.social-proof-toast.active {
    bottom: 20px;
}
.toast-avatar {
    width: 42px;
    height: 42px;
    background: var(--primary-pale);
    color: var(--primary-dark);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}
.toast-content {
    display: flex;
    flex-direction: column;
}
.toast-title {
    font-size: 13.5px;
    font-weight: 700;
    color: #111;
    line-height: 1.3;
}
.toast-meta {
    font-size: 11px;
    color: var(--text-muted);
    margin-top: 2px;
}

@media (max-width: 480px) {
    .social-proof-toast {
        left: 10px;
        bottom: -120px;
        width: calc(100% - 20px);
        padding: 10px 12px;
    }
    .social-proof-toast.active {
        bottom: 10px;
    }
}

</style>
</head>
<body>

<!-- Google Tag Manager (noscript) -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-54232S48"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->

<div class="wrapper">

    <div class="top-brand-bar">
        <h2 class="brand-name-text">Fit Home</h2>
    </div>

    <div class="hero-banner">
        <div class="hero-inner">
<h1>সাইড ইফেক্ট ছাড়াই<br>
    <a href="#order-form" style="text-decoration: none;">
        <span class="highlight">ওজন বাড়ান</span>
    </a>
</h1>

<div class="social-proof-badge">
    <span class="pulse-dot"></span> ইতিমধ্যেই <span class="badge-num">৬৫০০+ </span> মানুষ সফলভাবে ওজন বাড়িয়েছেন
</div>

</div>
    </div>

    <div class="video-container" data-video-id="9j83-DKIAx8">
    <div class="video-play-btn"></div>
    <img 
        src="https://fithomebd.com/wp-content/uploads/2026/06/main-video-thumb.webp"
        alt="Fit Home Video Main"
        width="720"
        height="900"
        fetchpriority="high"
        decoding="async">
</div>


    <div class="content-padding">

        <a href="#order-form" class="btn-cta">👉  অর্ডার করতে চাই</a>

        <h2 class="section-title">ডেইলি প্রোটিন ব্যবহারের উপকারিতা</h2>
<p class="section-sub">নিয়মিত খেলে শরীরে যেসব ইতিবাচক পরিবর্তন আসবে</p>
<div class="benefits-grid">
    <div class="benefit-card"><div class="benefit-icon">⚖️</div><p>মাসে ৪-৫ কেজি ওজন বৃদ্ধি পাবে</p></div>
    <div class="benefit-card"><div class="benefit-icon">💪</div><p>পেশী ভরাট ও শরীর শক্তিশালী হবে</p></div>
    <div class="benefit-card"><div class="benefit-icon">🍽️</div><p>খাবারের রুচি ও হজমশক্তি বাড়বে</p></div>
    <div class="benefit-card"><div class="benefit-icon">⚡</div><p>সারাদিন ক্লান্তি থাকবে না, এনার্জি পাবে</p></div>
    <div class="benefit-card"><div class="benefit-icon">🛡️</div><p>১০০% প্রাকৃতিক — সাইড ইফেক্ট নেই</p></div>
    <div class="benefit-card"><div class="benefit-icon">✅</div><p>বাড়ানো ওজন স্থায়ীভাবে থাকে</p></div>
</div>

        <h2 class="section-title"> ১০০% প্রাকৃতিক উপকরণ</h2>
<p class="section-sub">কোনো কেমিক্যাল বা আর্টিফিশিয়াল ফ্লেভার নেই </p>
<div class="ingredients-grid">
    <div class="ingredient-card">
        <div class="ingredient-tooltip">কাঠবাদামে থাকা হেলদি ফ্যাট ও প্রোটিন পেশী মজবুত করে দ্রুত ওজন বাড়াতে সাহায্য করে।</div>
        <div class="ingredient-icon">🌰</div><p>কাঠ বাদাম</p>
    </div>
    <div class="ingredient-card">
        <div class="ingredient-tooltip">কাজুবাদাম ক্যালোরি ও খনিজ সমৃদ্ধ, যা শরীরের ক্লান্তি দূর করে ও ওজন বাড়ায়।</div>
        <div class="ingredient-icon">🥜</div><p>কাজুবাদাম</p>
    </div>
    <div class="ingredient-card">
        <div class="ingredient-tooltip">সূর্যমুখীর বীজ ভিটামিন-ই ও ম্যাগনেসিয়ামের চমৎকার উৎস, যা মেটাবলিজম ঠিক রাখে।</div>
        <div class="ingredient-icon">🌻</div><p>সূর্যমুখীর বীজ</p>
    </div>
    <div class="ingredient-card">
        <div class="ingredient-tooltip">ভাজা ছোলা উচ্চমানের উদ্ভিজ্জ প্রোটিন সরবরাহ করে শরীরকে শক্তিশালী ও পেশীবহুল করে।</div>
        <div class="ingredient-icon">🌾</div><p>ভাজা ছোলা</p>
    </div>
    <div class="ingredient-card">
        <div class="ingredient-tooltip">চিনা বাদাম দ্রুত শক্তি যোগায় এবং কম সময়ে শরীরে স্বাস্থ্যকর ক্যালোরি যুক্ত করে।</div>
        <div class="ingredient-icon">🥜</div><p>চিনা বাদাম</p>
    </div>
    <div class="ingredient-card">
        <div class="ingredient-tooltip">কুমড়ার বীজে আছে প্রচুর জিঙ্ক ও ওমেগা-৩, যা হরমোনাল ব্যালেন্স ও ওজন বাড়াতে দারুণ কাজ করে।</div>
        <div class="ingredient-icon">🎃</div><p>কুমড়ার বীজ</p>
    </div>
</div>

        <a href="#order-form" class="btn-cta">👉 ডিসকাউন্টে অর্ডার করুন </a>

        <h2 class="section-title">BCSIR - কর্তৃক পরীক্ষিত</h2>
        <p class="section-sub">ল্যাব টেস্টেড ও প্রতিটি ব্যাচ মান নিয়ন্ত্রণের মধ্য দিয়ে যায়</p>

<img src="https://fithomebd.com/wp-content/uploads/2026/06/177901085441382307804_6722d616_348b_4e84_bf5c_e9958dc62cbb.webp" class="demo-img" alt="Lab Test Certificate" width="800" height="600" loading="lazy" decoding="async">
        <a href="#order-form" class="btn-cta">✅ নিশ্চিন্তে অর্ডার করুন  </a>

        <h2 class="section-title"> Product Images Gallery </h2>


        <div class="image-slider-wrapper">


            <div class="slider-image-item">
    <img src="https://fithomebd.com/wp-content/uploads/2026/06/177592419488316964532_resizeimage1-1-2.webp" alt="Review 1" width="600" height="800" loading="lazy" decoding="async">
</div>
<div class="slider-image-item">
    <img src="https://fithomebd.com/wp-content/uploads/2026/06/177895005357543347210_gemini_generated_image_2znehp2znehp2zne-1.webp" alt="Review 2" width="600" height="800" loading="lazy" decoding="async">
</div>
<div class="slider-image-item">
    <img src="https://fithomebd.com/wp-content/uploads/2026/06/webimage2.webp" alt="Review 3" width="600" height="800" loading="lazy" decoding="async">
</div>


 </div>

        <h2 class="section-title">"আলহামদুলিল্লাহ! হাজারো মানুষের ওজন বাড়ানোর সফল গল্প"</h2>
        <p class="section-sub">আমাদের কাস্টমারদের  অভিজ্ঞতা</p>

   <div class="video-reviews-grid">
    <div class="video-item" data-video-id="nunSsyJsPgU">
        <div class="video-play-btn"></div>
        <img 
    src="https://fithomebd.com/wp-content/uploads/2026/06/review-1-thumb.webp"
    alt="Review 1 Thumbnail"
    width="720"
    height="900"
    loading="lazy"
    decoding="async">
    </div>

    <div class="video-item" data-video-id="RSYo3GY8FAE">
        <div class="video-play-btn"></div>
        <img 
    src="https://fithomebd.com/wp-content/uploads/2026/06/review-2-thumb.webp"
    alt="Review 2 Thumbnail"
    width="720"
    height="900"
    loading="lazy"
    decoding="async">
    </div>

    <div class="video-item" data-video-id="JqdN2KZu5PQ">
        <div class="video-play-btn"></div>
        <img 
    src="https://fithomebd.com/wp-content/uploads/2026/06/review-3-thumb.webp"
    alt="Review 3 Thumbnail"
    width="720"
    height="900"
    loading="lazy"
    decoding="async">
    </div>
</div>

        <h2 class="section-title">সাধারণ জিজ্ঞাসা </h2>
        <p class="section-sub">আপনার মনে থাকা সাধারণ প্রশ্নগুলোর উত্তর</p>
        <div class="faq-accordion">
    <details class="faq-item">
        <summary>১) কারা খেতে পারবে ?</summary>
        <p>৭ বছরের বাচ্চা থেকে যেকোনো বয়সের ছেলে ও  মেয়ে খেতে পারবে। যাদের শরীর <b>দুর্বল, হালকা-পাতলা ও  ওজন বাড়াতে চান  </b> — তাদের জন্যই এই প্রোটিন বিশেষভাবে তৈরি।</p>
    </details>
    <details class="faq-item">
    <summary>২) একটি খেলে কত কেজি বাড়বে ?</summary>
    <p>নিয়মিত খেলে প্রতি প্যাকেজে যতটুকু ওজন বাড়বে:</p>

    <div style="display:flex; flex-direction:column; gap:10px; margin:12px 0 8px;">

        <div style="display:flex; align-items:center; gap:12px;">
            <span style="font-size:12px; font-weight:700; color:#6c757d; white-space:nowrap; min-width:90px; padding: 6px 10px; background:#f1f3f2; border-radius:6px; text-align:center;">১ প্যাকেজ<br><span style="color:#1B4D3E;">১ মাস</span></span>
            <div style="flex:1; background:#e9ecef; border-radius:20px; height:28px; overflow:hidden;">
                <div style="width:33%; height:100%; background:linear-gradient(90deg,#2F7A60,#1B4D3E); border-radius:20px; display:flex; align-items:center; justify-content:flex-end; padding-right:10px;">
                    <span style="font-size:11px; font-weight:800; color:#fff; white-space:nowrap;">৪-৫ কেজি</span>
                </div>
            </div>
        </div>

        <div style="display:flex; align-items:center; gap:12px;">
            <span style="font-size:12px; font-weight:700; color:#6c757d; white-space:nowrap; min-width:90px; padding: 6px 10px; background:#f1f3f2; border-radius:6px; text-align:center;">২ প্যাকেজ<br><span style="color:#FF5722;">২ মাস</span></span>
            <div style="flex:1; background:#e9ecef; border-radius:20px; height:28px; overflow:hidden;">
                <div style="width:66%; height:100%; background:linear-gradient(90deg,#FF7A45,#FF5722); border-radius:20px; display:flex; align-items:center; justify-content:flex-end; padding-right:10px;">
                    <span style="font-size:11px; font-weight:800; color:#fff; white-space:nowrap;">৮-১০ কেজি</span>
                </div>
            </div>
        </div>

        <div style="display:flex; align-items:center; gap:12px;">
            <span style="font-size:12px; font-weight:700; color:#6c757d; white-space:nowrap; min-width:90px; padding: 6px 10px; background:#f1f3f2; border-radius:6px; text-align:center;">৩ প্যাকেজ<br><span style="color:#b38000;">৩ মাস</span></span>
            <div style="flex:1; background:#e9ecef; border-radius:20px; height:28px; overflow:hidden;">
                <div style="width:100%; height:100%; background:linear-gradient(90deg,#FFC94D,#FFB300); border-radius:20px; display:flex; align-items:center; justify-content:flex-end; padding-right:10px;">
                    <span style="font-size:11px; font-weight:800; color:#5a3e00; white-space:nowrap;">১৩-১৫ কেজি</span>
                </div>
            </div>
        </div>

    </div>
    <p style="margin-top:6px;">ফলাফল নির্ভর করে খাদ্যাভ্যাস ও নিয়মিততার উপর।</p>
</details>
    <details class="faq-item">
        <summary>৩) কোন সাইড ইফেক্ট আছে ?</summary>
        <p>একদমই না। Fit Home এর  Daily Protein টি  সম্পূর্ণ <b>প্রাকৃতিক উপকরণ  </b> দিয়ে তৈরি — কোনো কেমিক্যাল বা আর্টিফিশিয়াল উপাদান নেই। BCSIR ল্যাব টেস্টেড, তাই নিশ্চিন্তে খাওয়া যাবে।</p>
    </details>
    <details class="faq-item">
        <summary>৪) ওজন কি স্থায়ীভাবে থাকবে ?</summary>
        <p>হ্যাঁ। এটি শরীরে চর্বি নয়, পেশী তৈরি করে — <b> তাই বাড়ানো ওজন দীর্ঘস্থায়ী হয়। </b> </p>
    </details>
</div>

    </div>
    <div class="fomo-timer-container">
    <div class="fomo-text">🔥 আজকের স্পেশাল অফারটি শেষ হতে সময় বাকি:</div>
    <div class="fomo-countdown">
        <span id="fomo-timer">40:00</span>
    </div>
</div>


<div style="text-align: center; font-size: clamp(11px, 3.2vw, 13.5px); color: #2c3e50; font-weight: 700; margin: 10px auto; font-family: 'Hind Siliguri', sans-serif;">
    🔴 <span id="live-viewers" style="color: #E53935; background: #FFEBEB; padding: 1px 5px; border-radius: 4px; font-weight: 800;">৪৫</span> জন এই মুহূর্তে পেজটি দেখছেন
    <span style="color: #ccc; margin: 0 4px;">|</span>
    🛒 <span id="live-cart" style="color: #1B4D3E; font-weight: 800;">৩</span> জন ফর্ম পূরণ করছেন
</div>


<div id="social-proof" class="social-proof-toast">
    <div class="toast-avatar">🛒</div>
    <div class="toast-content">
        <span class="toast-title" id="toast-text">...</span>
        <span class="toast-meta" id="toast-time">১ মিনিট আগে</span>
    </div>
</div>

    <div id="order-form" class="checkout-section">
        <div class="checkout-header">অর্ডার কনফার্ম করতে নিচের তথ্যগুলো দিন</div>
        <p class="checkout-sub">কোনো অগ্রিম পেমেন্ট লাগবে না! পণ্য হাতে পেয়ে টাকা দিন।</p>

        <form method="POST" action="" id="purchase-form">

            <div class="form-group">
                <label>প্যাকেজ সিলেক্ট করুন:</label>
                <div class="package-options">

                    <div class="package-box" onclick="selectPackage(this, 1)">
                        <div class="pkg-badge">২৭% ছাড়</div>
                        <input type="radio" name="product_qty" value="1">

                        <div class="pkg-left">
                            <div class="custom-radio"></div>
<img src="https://fithomebd.com/wp-content/uploads/2026/06/E1294D89-ED58-4F11-84B1-83544B451AFD_3_-removebg-preview.webp" alt="1 Month Pack" class="pkg-img" width="150" height="150" loading="lazy" decoding="async">                        </div>

                        <div class="pkg-info">
                            <div class="pkg-title">১ মাসের প্যাকেজ - ১ কেজি</div>
                            <div class="pkg-desc">🎯 ৪-৫ কেজি ওজন বৃদ্ধি</div>
                            <div class="pkg-price"><del>৳১৩৫০</del> ৳ <?php echo fithome_bn_num( $fithome_packages[1] ); ?></div>
                        </div>
                    </div>

                    <div class="package-box active" onclick="selectPackage(this, 2)">
                        <div class="pkg-ribbon">🔥 ১,০৫০ টাকা সাশ্রয়!  </div>
                        <div class="pkg-badge">৩৫% ছাড় + ফ্রি ডেলিভারি</div>
                        <input type="radio" name="product_qty" value="2" checked>

                        <div class="pkg-left">
                            <div class="custom-radio"></div>
<img src="https://fithomebd.com/wp-content/uploads/2026/06/E1294D89-ED58-4F11-84B1-83544B451AFD_3_-removebg-preview.webp" alt="2 Months Pack" class="pkg-img" width="150" height="150" loading="lazy" decoding="async">                        </div>

                        <div class="pkg-info">
                            <div class="pkg-title">২ মাসের প্যাকেজ - ২ কেজি </div>
                            <div class="pkg-desc">🎯 ৮-১০ কেজি ওজন বৃদ্ধি</div>
                            <div class="pkg-price"><del>৳২৭০০</del> ৳ <?php echo fithome_bn_num( $fithome_packages[2] ); ?></div>
                        </div>
                    </div>

                    <div class="package-box" onclick="selectPackage(this, 3)">
                        <div class="pkg-badge">৩৭% ছাড় + ফ্রি ডেলিভারি</div>
                        <input type="radio" name="product_qty" value="3">

                        <div class="pkg-left">
                            <div class="custom-radio"></div>
<img src="https://fithomebd.com/wp-content/uploads/2026/06/E1294D89-ED58-4F11-84B1-83544B451AFD_3_-removebg-preview.webp" alt="3 Months Pack" class="pkg-img" width="150" height="150" loading="lazy" decoding="async">                        </div>

                        <div class="pkg-info">
                            <div class="pkg-title">৩ মাসের প্যাকেজ - ৩ কেজি </div>
                            <div class="pkg-desc">🎯 ১৩-১৫ কেজি ওজন বৃদ্ধি</div>
                            <div class="pkg-price"><del>৳৪০৫০</del> ৳ <?php echo fithome_bn_num( $fithome_packages[3] ); ?></div>
                        </div>
                    </div>

                </div>
                <div class="guarantee-text">✔️ ১০০% ন্যাচারাল ও সাইড-ইফেক্ট মুক্ত</div>
            </div>

            <div class="form-group">
                <label>আপনার নাম *</label>
                <div class="input-with-icon">
                    <input type="text" name="billing_first_name" id="billing_first_name" class="form-control" placeholder="আপনার সম্পূর্ণ নাম লিখুন" required>
                    <span class="check-icon">✔️</span>
                </div>
            </div>

            <div class="form-group">
                <label>আপনার মোবাইল নম্বর *</label>
                <div class="input-with-icon">
                    <input type="tel" inputmode="numeric" pattern="^01[3-9]\d{8}$" minlength="11" maxlength="11" title="দয়া করে সঠিক ১১ ডিজিটের মোবাইল নম্বর দিন" name="billing_phone" id="billing_phone" class="form-control" placeholder="01XXXXXXXXX" required>
                    <span class="check-icon">✔️</span>
                </div>
            </div>


            <div class="form-group">
                <label>সম্পূর্ণ ডেলিভারি ঠিকানা *</label>
                <div class="input-with-icon">
                    <input type="text" name="billing_address" id="billing_address" class="form-control" placeholder="গ্রাম/এলাকা, থানা, জেলা" required>
                    <span class="check-icon">✔️</span>
                </div>
            </div>


            <div class="form-group">
                <label>ডেলিভারি এলাকা নির্বাচন করুন *</label>
                <div class="location-options">
    <label class="location-radio">
        <input type="radio" name="delivery_location" value="inside_dhaka" onchange="updateTotal()" checked required>
        ঢাকার ভিতরে - <span id="label_inside_dhaka"><?php echo fithome_bn_num( $fithome_shipping['inside_dhaka'] ); ?> ৳</span>
    </label>
    <label class="location-radio">
        <input type="radio" name="delivery_location" value="outside_dhaka" onchange="updateTotal()" required>
         ঢাকার বাইরে - <span id="label_outside_dhaka"><?php echo fithome_bn_num( $fithome_shipping['outside_dhaka'] ); ?> ৳</span>
    </label>
</div>
            </div>

            <div class="form-group order-summary">
                <div class="row">
                    <span>প্রোডাক্ট মূল্য:</span> <span><span id="display_subtotal"><?php echo esc_html( $fithome_packages[2] ); ?></span> টাকা</span>
                </div>
                <div class="row">
                    <span>ডেলিভারি চার্জ:</span>
                    <span>
                        <span id="display_shipping_strike" style="color:#adb5bd; text-decoration:line-through; font-size:14px; margin-right:5px;"><?php echo esc_html( $fithome_shipping['inside_dhaka'] ); ?></span>
                        <span id="display_shipping" style="color:var(--success-green); font-weight:800;">০ (ফ্রি)</span> টাকা
                    </span>
                </div>
                <hr>
                <div class="total-row">
                    <span>সর্বমোট মূল্য:</span> <span><span id="display_total"><?php echo esc_html( $fithome_packages[2] ); ?></span> টাকা</span>
                </div>
                <div class="cod-note">
                    * পেমেন্ট মেথড: পণ্য হাতে পেয়ে টাকা দিন
                </div>
            </div>
                <input type="text" name="fithome_bot_trap" value="" style="display:none !important;" tabindex="-1" autocomplete="off">
<button type="submit" id="submit-btn" name="fithome_order_submit" class="btn-cta" style="margin-top: 15px; animation: none;">✅ অর্ডার কনফার্ম করুন</button>         </form>
    </div>

</div>

<script>

/* =========================================================================
   [NEW] সব দাম ও শিপিং রেট এখন PHP থেকেই আসে — JS-এ আর হার্ডকোড নেই।
   ফলে ভবিষ্যতে দাম বদলালে শুধু functions.php-এ বদলালেই সব জায়গায় মিলে যাবে।
   ========================================================================= */
var FITHOME = {
    packages:        <?php echo wp_json_encode( $fithome_packages ); ?>,
    shipping:        <?php echo wp_json_encode( $fithome_shipping ); ?>,
    freeShipMinQty:  <?php echo intval( FITHOME_FREE_SHIP_MIN_QTY ); ?>,
    productId:       '<?php echo intval( FITHOME_PRODUCT_ID ); ?>',
    ajaxUrl:         '<?php echo esc_js( admin_url('admin-ajax.php') ); ?>'
};

/* [FIX — ক্রিটিক্যাল] crypto.randomUUID() পুরনো Android WebView ও Facebook in-app
   ব্রাউজারে (Chrome <92 / Safari <15.4) নেই। না থাকলে ওখানেই TypeError হয়ে
   নিচের পুরো স্ক্রিপ্ট বন্ধ হয়ে যেত — updateTotal, blur listener (begin_checkout +
   abandoned cart), submit handler কিছুই রেজিস্টার হতো না। এখন fallback আছে। */
function fithomeUUID() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
        return window.crypto.randomUUID();
    }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
        var r = Math.random() * 16 | 0;
        var v = (c === 'x') ? r : ((r & 0x3) | 0x8);
        return v.toString(16);
    });
}

/* [FIX] বাংলা ডিজিট কনভার্টার আগে initLiveVibeTrigger()-এর ভেতরে আটকা ছিল —
   এখন গ্লোবাল, তাই ডেলিভারি লেবেলেও ব্যবহার করা যায় */
function fithomeBn(num) {
    var bd = ["০","১","২","৩","৪","৫","৬","৭","৮","৯"];
    return num.toString().split('').map(function(d){ return bd[d] || d; }).join('');
}

/* [TRACKING FIX] Meta CAPI ম্যাচ কোয়ালিটির জন্য ফোন E.164 ফরম্যাটে (8801XXXXXXXXX)।
   আগে '1712345678' (শূন্য ছাড়া) দিলে '881712345678' হতো — শূন্য মিসিং, ভুল ফরম্যাট। */
function fithomePhoneE164(phone) {
    var d = (phone || '').replace(/\D/g, '');
    if (!d) return '';
    if (d.indexOf('880') === 0) return d;
    if (d.indexOf('0') === 0)   return '88' + d;
    if (d.length === 10 && d.indexOf('1') === 0) return '880' + d;
    return '88' + d;
}

/* [TRACKING FIX] Meta-র `ct` (city) প্যারামিটার আসল শহরের নাম হ্যাশ করে ম্যাচ করে।
   "Outside Dhaka" কোনো শহরের নাম নয় — পাঠালে ওই ফিল্ডে ০% ম্যাচ হয়ে
   সামগ্রিক match quality নামিয়ে দেয়। তাই ঢাকার বাইরে হলে খালি পাঠানো হচ্ছে। */
function fithomeCity(location) {
    return (location === 'inside_dhaka') ? 'Dhaka' : '';
}

// Selected package (qty+price) — সব সময় এই একটাই ফাংশন থেকে আসবে
function fithomeGetSelectedPackageInfo() {
    var checked = document.querySelector('input[name="product_qty"]:checked');
    var qty = checked ? parseInt(checked.value, 10) : 1;
    if (!FITHOME.packages[qty]) qty = 1;
    return { qty: qty, price: FITHOME.packages[qty] };
}

function fithomeGetSelectedLocation() {
    var el = document.querySelector('input[name="delivery_location"]:checked');
    var loc = el ? el.value : 'inside_dhaka';
    if (typeof FITHOME.shipping[loc] === 'undefined') loc = 'inside_dhaka';
    return loc;
}

function fithomeGetShipping(qty, location) {
    if (qty >= FITHOME.freeShipMinQty) return 0;
    return FITHOME.shipping[location];
}

/* [TRACKING FIX] GA4 আইটেম রেভিনিউ হিসাব করে price × quantity।
   আগে price-এ পুরো প্যাকেজের দাম (১৭৪৯) আর quantity-তে ২ দেওয়ায়
   GA4 ধরত ৩৪৯৮ — অথচ value যেত ১৭৪৯, ফলে Item Revenue ডাবল দেখাত।
   এখন ইউনিট প্রাইস পাঠানো হচ্ছে, তাই price × quantity = value মিলে যায়। */
function fithomeItemsArray(pkg) {
    return [{
        item_id: FITHOME.productId,
        item_name: 'Daily Protein',
        price: Math.round((pkg.price / pkg.qty) * 100) / 100,
        quantity: pkg.qty
    }];
}

/* [NEW — ecommerce nesting] GA4-র স্ট্যান্ডার্ড ইকমার্স স্কিমা অনুযায়ী
   value/currency/items এখন `ecommerce` অবজেক্টের ভেতরে যাচ্ছে।
   Meta Pixel `ecommerce` অবজেক্ট বোঝে না, তাই Meta-র প্যারামিটারগুলো
   (content_ids / content_type / content_name / num_items / value / currency)
   টপ লেভেলেও রাখা হয়েছে — দুই প্ল্যাটফর্মই নিজের ফরম্যাটে ডেটা পাবে। */

// পেজ লোড হওয়ার সাথে সাথে GTM ডাটালেয়ারে view_item পুশ
window.dataLayer = window.dataLayer || [];
var fithomeInitialPackage = fithomeGetSelectedPackageInfo();

window.dataLayer.push({ ecommerce: null });   // আগের ecommerce ডেটা ক্লিয়ার
window.dataLayer.push({
    'event': 'view_item',
    'event_id': fithomeUUID(),

    // ----- Meta Pixel (flat) -----
    'content_ids': [FITHOME.productId],
    'content_name': 'Daily Protein',
    'content_type': 'product',
    'currency': 'BDT',
    'value': fithomeInitialPackage.price,

    // ----- GA4 (nested) -----
    'ecommerce': {
        'currency': 'BDT',
        'value': fithomeInitialPackage.price,
        'items': fithomeItemsArray(fithomeInitialPackage)
    }
});






document.querySelectorAll('.video-container, .video-item').forEach(function(videoBlock) {
    videoBlock.addEventListener('click', function() {
        let videoId = this.getAttribute('data-video-id');
        if(!videoId) return;

        let iframe = document.createElement('iframe');
        iframe.setAttribute('src', 'https://www.youtube.com/embed/' + videoId + '?autoplay=1&rel=0');
        iframe.setAttribute('frameborder', '0');
        iframe.setAttribute('allowfullscreen', '1');
        iframe.setAttribute('allow', 'autoplay; encrypted-media');

        this.innerHTML = '';
        this.appendChild(iframe);
    });
});


    document.getElementById('billing_first_name').addEventListener('input', function() {
        let nameValue = this.value.trim();
        if (nameValue.length >= 3) {
            this.classList.add('is-valid');
        } else {
            this.classList.remove('is-valid');
        }
    });


    document.getElementById('billing_phone').addEventListener('input', function() {
        let phoneValue = this.value.trim();
        let phoneRegex = /^01[3-9]\d{8}$/;

        if (phoneRegex.test(phoneValue)) {
            this.classList.add('is-valid');
        } else {
            this.classList.remove('is-valid');
        }
    });


    document.getElementById('billing_address').addEventListener('input', function() {
        let addressValue = this.value.trim();
        if (addressValue.length >= 5) {
            this.classList.add('is-valid');
        } else {
            this.classList.remove('is-valid');
        }
    });


    function selectPackage(element, qty) {
        let radioInput = element.querySelector('input[type="radio"]');

        document.querySelectorAll('.package-box').forEach(box => {
            box.classList.remove('active');
        });

        element.classList.add('active');
        radioInput.checked = true;

        updateTotal();
    }


    function updateTotal() {
    var pkg      = fithomeGetSelectedPackageInfo();
    var location = fithomeGetSelectedLocation();
    var baseShippingCharge = FITHOME.shipping[location];
    var finalShippingCharge = fithomeGetShipping(pkg.qty, location);

    var displayShippingElement = document.getElementById('display_shipping');
    var strikeShippingElement  = document.getElementById('display_shipping_strike');

    // রেডিও বাটনের টেক্সট ধরার জন্য এলিমেন্ট সিলেক্ট 
    var labelInside  = document.getElementById('label_inside_dhaka');
    var labelOutside = document.getElementById('label_outside_dhaka');

    if (finalShippingCharge === 0) {
        strikeShippingElement.style.display = 'inline';
        strikeShippingElement.innerText = baseShippingCharge;
        displayShippingElement.innerText = '০ (ফ্রি)';
        displayShippingElement.style.color = '#198754';
        displayShippingElement.style.fontWeight = '800';

        // ডায়নামিক টেক্সট আপডেট (ফ্রি দেখানো হবে)
        labelInside.innerHTML  = '<del style="color:#ccc; font-size:12px;">' + fithomeBn(FITHOME.shipping.inside_dhaka)  + ' ৳</del> <span style="color:#198754; font-weight:bold;">ফ্রি</span>';
        labelOutside.innerHTML = '<del style="color:#ccc; font-size:12px;">' + fithomeBn(FITHOME.shipping.outside_dhaka) + ' ৳</del> <span style="color:#198754; font-weight:bold;">ফ্রি</span>';

    } else {
        strikeShippingElement.style.display = 'none';
        displayShippingElement.innerText = finalShippingCharge;
        displayShippingElement.style.color = '#333';
        displayShippingElement.style.fontWeight = '600';

        // ডায়নামিক টেক্সট আগের অবস্থায় ফিরিয়ে আনা
        labelInside.innerText  = fithomeBn(FITHOME.shipping.inside_dhaka)  + ' ৳';
        labelOutside.innerText = fithomeBn(FITHOME.shipping.outside_dhaka) + ' ৳';
    }

    var grandTotal = pkg.price + finalShippingCharge;

    document.getElementById('display_subtotal').innerText = pkg.price;
    document.getElementById('display_total').innerText = grandTotal;

    // [TRACKING FIX] প্যাকেজ বা এলাকা বদলালে begin_checkout-এর ভ্যালুও আপডেট হবে
    tryFireBeginCheckout();
}  

    window.addEventListener('DOMContentLoaded', (event) => {
        updateTotal();
    });


  

// ===== Abandoned cart =====
function fithomeSaveAbandonedCart() {
    let phone = document.getElementById('billing_phone').value.trim();
    let phoneRegex = /^01[3-9]\d{8}$/;
    if (!phoneRegex.test(phone)) return;

    let name    = document.getElementById('billing_first_name').value.trim();
    let address = document.getElementById('billing_address').value.trim();
    let pkg     = fithomeGetSelectedPackageInfo();
    let loc     = fithomeGetSelectedLocation();

    let data = new FormData();
    data.append('action', 'save_abandoned_cart');
    data.append('phone', phone);
    data.append('name', name);
    data.append('address', address);
    data.append('product_id', FITHOME.productId);
    data.append('product_qty', pkg.qty);             // [FIX] qty সেভ — নাহলে convert-এ ভুল দাম হতো
    data.append('location', loc);                    // [FIX] location সেভ — convert-এ ডেলিভারি চার্জের জন্য

    fetch(FITHOME.ajaxUrl, { method: 'POST', body: data })
        .then(response => response.text())
        .then(result => console.log('Abandoned Cart AJAX Saved/Updated'))
        .catch(error => console.error('Error:', error));
}

/* ===== begin_checkout =====
   [TRACKING FIX] আগে একবার ফায়ার হলেই ফ্ল্যাগ লক হয়ে যেত। ফর্ম ফিল করার পর কাস্টমার
   ১ মাস থেকে ৩ মাসের প্যাকেজে সুইচ করলে Meta-তে ভ্যালু থেকে যেত ৯৯০, অথচ অর্ডার হতো ২৫৪৯ —
   ভ্যালু-বেসড অপটিমাইজেশনের সিগন্যাল নষ্ট হতো। এখন প্যাকেজ/এলাকা বদলালে আবার ফায়ার হয়,
   কিন্তু একই কম্বিনেশনে বারবার নয়। */
let lastCheckoutSignature = '';
function tryFireBeginCheckout() {
    let phone   = document.getElementById('billing_phone').value.trim();
    let name    = document.getElementById('billing_first_name').value.trim();
    let address = document.getElementById('billing_address').value.trim();
    let phoneRegex = /^01[3-9]\d{8}$/;
    if (!phoneRegex.test(phone) || name.length < 1 || address.length < 1) return;

    let pkg      = fithomeGetSelectedPackageInfo();
    let location = fithomeGetSelectedLocation();

    let signature = pkg.qty + '|' + location;
    if (signature === lastCheckoutSignature) return;

    let checkoutValue = pkg.price + fithomeGetShipping(pkg.qty, location);

    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({ ecommerce: null });   // আগের ecommerce ডেটা ক্লিয়ার
    window.dataLayer.push({
        'event': 'begin_checkout',
        'event_id': fithomeUUID(),

        // ----- Advanced Matching (flat) -----
        'full_name': name,
        'user_phone': fithomePhoneE164(phone),
        'city': fithomeCity(location),
        'country': 'BD',

        // ----- Meta Pixel (flat) -----
        'content_ids': [FITHOME.productId],
        'content_type': 'product',
        'num_items': pkg.qty,
        'currency': 'BDT',
        'value': checkoutValue,

        // ----- GA4 (nested) -----
        'ecommerce': {
            'currency': 'BDT',
            'value': checkoutValue,
            'items': fithomeItemsArray(pkg)
        }
    });

    lastCheckoutSignature = signature;
    console.log('GTM InitiateCheckout Pushed Perfectly!');
}

// ===== Phone, Name, Address — তিনটা ফিল্ডেই একই listener =====
document.getElementById('billing_phone').addEventListener('blur', function() {
    fithomeSaveAbandonedCart();
    tryFireBeginCheckout();
});
document.getElementById('billing_first_name').addEventListener('blur', function() {
    fithomeSaveAbandonedCart();
    tryFireBeginCheckout();
});
document.getElementById('billing_address').addEventListener('blur', function() {
    fithomeSaveAbandonedCart();
    tryFireBeginCheckout();
});


    document.getElementById('purchase-form').addEventListener('submit', function(e) {

    if (!this.checkValidity()) {
        return;
    }

    let submitBtn = document.getElementById('submit-btn');
    setTimeout(() => {
        submitBtn.classList.add('is-loading');
        submitBtn.innerHTML = '<span class="spinner-icon"></span> অর্ডার প্রসেস হচ্ছে...';
        submitBtn.disabled = true;
    }, 10);
});

// [FIX] আগে ছোট সংখ্যায় বাংলা "০" + ইংরেজি সংখ্যা মিক্সড হয়ে দেখাচ্ছিল (যেমন "০5")
// এখন টাইমার সবসময় সম্পূর্ণ ইংরেজি সংখ্যায় (padStart দিয়ে) দেখাবে, যেকোনো মান-এর জন্যই সঠিক
function initFomoTimer() {
    const timerDuration = 40 * 60;
    const localStorageKey = 'fithome_timer_expiry';
    let currentTime = Math.floor(Date.now() / 1000);
    let expiryTime = localStorage.getItem(localStorageKey);


    if (!expiryTime || currentTime > expiryTime) {
        expiryTime = currentTime + timerDuration;
        localStorage.setItem(localStorageKey, expiryTime);
    }

    const timerDisplay = document.getElementById('fomo-timer');

    function updateTimerDisplay() {
        let now = Math.floor(Date.now() / 1000);
        let remainingSeconds = expiryTime - now;

        if (remainingSeconds <= 0) {

            expiryTime = Math.floor(Date.now() / 1000) + timerDuration;
            localStorage.setItem(localStorageKey, expiryTime);
            remainingSeconds = timerDuration;
        }

        let minutes = Math.floor(remainingSeconds / 60);
        let seconds = remainingSeconds % 60;

        timerDisplay.textContent = String(minutes).padStart(2, '0') + ":" + String(seconds).padStart(2, '0');
    }

    updateTimerDisplay();
    setInterval(updateTimerDisplay, 1000);
}


document.querySelectorAll('.ingredient-card').forEach(card => {
    card.addEventListener('click', function(e) {
        e.stopPropagation();

        document.querySelectorAll('.ingredient-card').forEach(c => {
            if (c !== card) c.classList.remove('show-tooltip');
        });

        this.classList.toggle('show-tooltip');
    });
});
document.addEventListener('click', () => {
    document.querySelectorAll('.ingredient-card').forEach(c => c.classList.remove('show-tooltip'));
});


const buyersData = [
    { name: "আরিফ রহমান", district: "ঢাকা", pkg: "২ মাসের প্যাকেজ" },
    { name: "রাসেল আহমেদ", district: "চট্টগ্রাম", pkg: "৩ মাসের প্যাকেজ" },
    { name: "মোঃ সুলতান", district: "সিলেট", pkg: "২ মাসের প্যাকেজ" },
    { name: "ইমরান খান", district: "বগুড়া", pkg: "১ মাসের প্যাকেজ" },
    { name: "ফরহাদ হোসেন", district: "খুলনা", pkg: "২ মাসের প্যাকেজ" },
    { name: "মিজানুর রহমান", district: "রাজশাহী", pkg: "৩ মাসের প্যাকেজ" },
    { name: "উজ্জ্বল কুণ্ডু", district: "যশোর", pkg: "২ মাসের প্যাকেজ" },
    { name: "নাহিদ হাসান", district: "রংপুর", pkg: "১ মাসের প্যাকেজ" },
    { name: "আহসান হাবীব", district: "বরিশাল", pkg: "২ মাসের প্যাকেজ" },
    { name: "শাকিল আহমেদ", district: "কুমিল্লা", pkg: "৩ মাসের প্যাকেজ" },
    { name: "মোস্তফা কামাল", district: "ময়মনসিংহ", pkg: "২ মাসের প্যাকেজ" },
    { name: "জাহিদুল ইসলাম", district: "গাজীপুর", pkg: "২ মাসের প্যাকেজ" },
    { name: "সাইফুল ইসলাম", district: "নারায়ণগঞ্জ", pkg: "৩ মাসের প্যাকেজ" },
    { name: "তাহের আলী", district: "নোয়াখালী", pkg: "১ মাসের প্যাকেজ" },
    { name: "রাশেদুল বারী", district: "দিনাজপুর", pkg: "২ মাসের প্যাকেজ" },
    { name: "শরিফুল ইসলাম", district: "ফরিদপুর", pkg: "২ মাসের প্যাকেজ" },
    { name: "মনির হোসেন", district: "পাবনা", pkg: "৩ মাসের প্যাকেজ" },
    { name: "আসাদুজ্জামান", district: "কুষ্টিয়া", pkg: "১ মাসের প্যাকেজ" },
    { name: "এনামুল হক", district: "টাঙ্গাইল", pkg: "২ মাসের প্যাকেজ" },
    { name: "শামীম ওসমান", district: "ফেনী", pkg: "২ মাসের প্যাকেজ" },
    { name: "কামরুল হাসান", district: "সিরাজগঞ্জ", pkg: "৩ মাসের প্যাকেজ" },
    { name: "তারেক আজিজ", district: "কক্সবাজার", pkg: "২ মাসের প্যাকেজ" },
    { name: "আরিফুল হক", district: "জামালপুর", pkg: "১ মাসের প্যাকেজ" },
    { name: "সুমন মিয়া", district: "ব্রাহ্মণবাড়িয়া", pkg: "২ মাসের প্যাকেজ" },
    { name: "মাসুদ রানা", district: "চাঁদপুর", pkg: "৩ মাসের প্যাকেজ" },
    { name: "রিপন সরকার", district: "গাইবান্ধা", pkg: "২ মাসের প্যাকেজ" },
    { name: "খায়রুল ইসলাম", district: "ঝিনাইদহ", pkg: "১ মাসের প্যাকেজ" },
    { name: "আতিকুর রহমান", district: "সাতক্ষীরা", pkg: "২ মাসের প্যাকেজ" },
    { name: "মাহবুব আলম", district: "নরসিংদী", pkg: "৩ মাসের প্যাকেজ" },
    { name: "জুবায়ের আহমেদ", district: "মৌলভীবাজার", pkg: "২ মাসের প্যাকেজ" }
];

const timeData = ["১ মিনিট আগে", "২ মিনিট আগে", "৩ মিনিট আগে", "৪ মিনিট আগে", "মাত্র কয়েক সেকেন্ড আগে"];

/* [FIX — UX] অর্ডার ফর্ম স্ক্রিনে থাকলে সোশ্যাল প্রুফ টোস্ট দেখাবে না।
   আগে মোবাইলে টোস্টটা (bottom:10px, z-index:99999) ঠিক "অর্ডার কনফার্ম করুন"
   বাটনের উপরে বসে যেত — ফর্ম ভরার সময় কাস্টমার বাটনে ট্যাপ করতে পারত না। */
let fithomeFormInView = false;

function showSocialProof() {
    const toast = document.getElementById('social-proof');
    const toastText = document.getElementById('toast-text');
    const toastTime = document.getElementById('toast-time');

    if (!toast) return;

    // ফর্ম ভিউপোর্টে থাকলে টোস্ট দেখানো হবে না
    if (fithomeFormInView) {
        toast.classList.remove('active');
        return;
    }

    const randomBuyer = buyersData[Math.floor(Math.random() * buyersData.length)];
    const randomTime = timeData[Math.floor(Math.random() * timeData.length)];


    toastText.innerHTML = `<strong>${randomBuyer.name}</strong> (${randomBuyer.district} থেকে) ${randomBuyer.pkg} অর্ডার করেছেন`;
    toastTime.innerText = randomTime;


    toast.classList.add('active');


    setTimeout(() => {
        toast.classList.remove('active');
    }, 6000);
}


function initSocialProof() {
    setTimeout(() => {
        showSocialProof();
        setInterval(showSocialProof, 25000);
    }, 10000);
}

// ফর্ম স্ক্রিনে এলো কি না — IntersectionObserver দিয়ে ওয়াচ
function initFormVisibilityWatcher() {
    const formSection = document.getElementById('order-form');
    const toast = document.getElementById('social-proof');

    if (!formSection || !toast) return;
    if (!('IntersectionObserver' in window)) return;   // পুরনো ব্রাউজারে আগের মতোই চলবে

    const observer = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            fithomeFormInView = entry.isIntersecting;

            // ফর্ম স্ক্রিনে ঢোকার সাথে সাথেই চালু টোস্ট সরিয়ে দাও
            if (fithomeFormInView) {
                toast.classList.remove('active');
            }
        });
    }, { threshold: 0 });

    observer.observe(formSection);
}


document.addEventListener('DOMContentLoaded', () => {
    initFomoTimer();
    initFormVisibilityWatcher();
    initSocialProof();
});


function initLiveVibeTrigger() {
    const viewersDisplay = document.getElementById('live-viewers');
    const cartDisplay = document.getElementById('live-cart');

    if (!viewersDisplay || !cartDisplay) return;

    function updateLiveStats() {

        let currentViewers = Math.floor(Math.random() * (58 - 35 + 1)) + 35;


        let currentCart = Math.floor(Math.random() * (6 - 2 + 1)) + 2;


        viewersDisplay.textContent = fithomeBn(currentViewers);
        cartDisplay.textContent = fithomeBn(currentCart);
    }


    setInterval(updateLiveStats, 4000);


    updateLiveStats();
}


document.addEventListener('DOMContentLoaded', () => {
    initLiveVibeTrigger();
});

// Fix for Back-Forward Cache (BFCache) Button stuck issue
window.addEventListener('pageshow', function(event) {
    // event.persisted চেক করে পেজটি ব্রাউজার ক্যাশ (Back button) থেকে এসেছে কি না
    if (event.persisted) {
        let submitBtn = document.getElementById('submit-btn');
        if (submitBtn) {
            // বাটনটিকে আবার আগের অবস্থায় ফিরিয়ে আনা  
            submitBtn.classList.remove('is-loading');
            submitBtn.innerHTML = '✅ অর্ডার কনফার্ম করুন';
            submitBtn.disabled = false;
        }
    }
});


</script>

<?php wp_footer(); ?>
</body>
</html>