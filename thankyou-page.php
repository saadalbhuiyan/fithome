<?php
/**
 * Template Name: Custom Thank You Page
 *
 * [NOTE] এই ফাইলে এই দফায় কোনো পরিবর্তন করা হয়নি — আগের ভার্সনই অপরিবর্তিত।
 * (নিচের fallback normalize_phone এখন আর দরকার পড়বে না, কারণ functions.php-এর
 *  আসল ফাংশনেই ১০-ডিজিটের কেসটা ফিক্স করা হয়েছে — তবু safety হিসেবে রাখা হলো।)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/*
 * [FIX — ক্রিটিক্যাল] আগে শুধু ?wc_order=1234 দিয়েই যেকোনো অর্ডার দেখা যেত (IDOR)।
 * ID বদলে বদলে যে কেউ সব কাস্টমারের নাম, ফোন নম্বর, প্যাকেজ ও টোটাল দেখে ফেলতে পারত।
 * এখন WooCommerce-এর order_key মিললে তবেই ডিটেইলস দেখাবে।
 */
$order_id  = isset($_GET['wc_order']) ? intval($_GET['wc_order']) : 0;
$order_key = isset($_GET['key'])      ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
$order     = false;

if ( $order_id && function_exists('wc_get_order') ) {
    $maybe_order = wc_get_order( $order_id );
    if ( $maybe_order && $order_key !== '' && hash_equals( (string) $maybe_order->get_order_key(), $order_key ) ) {
        $order = $maybe_order;
    }
}

// key না মিললে অর্ডার ডিটেইলস দেখানো হবে না
if ( ! $order ) {   
    $order_id = 0;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <!-- [FIX] user-scalable=no সরানো হলো — অ্যাক্সেসিবিলিটির জন্য জুম চালু থাকা দরকার -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ধন্যবাদ - আপনার অর্ডারটি সফল হয়েছে | Fit Home</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700;800&family=Noto+Sans+Bengali:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','GTM-54232S48');</script>
<!-- End Google Tag Manager -->
    
    <?php wp_head(); ?>
    
    <style>
        /* ========================================================
           DESIGN TOKENS (Consistent with Landing Page)
           ======================================================== */
        :root {
            --primary-dark:  #1B4D3E; 
            --primary-mid:   #2F7A60;
            --primary-pale:  #EAF5EF;
            --accent:        #FF5722; 
            --gold:          #FFC94D; 
            --text-dark:     #2c3e50;
            --text-muted:    #6c757d;
            --border-soft:   #ECEFED;
            /* [FIX] এই ভেরিয়েবলটা আগে ডিফাইনই ছিল না, অথচ "ফ্রি" লেখায় ব্যবহার হচ্ছিল */
            --success-green: #198754;
        }

        body { 
            font-family: 'Hind Siliguri', sans-serif !important; 
            background: linear-gradient(135deg, #f4f7f5 0%, #e9ecef 100%);
            color: var(--text-dark);
            margin: 0; 
            padding: 0; 
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        .wrapper-ty {
            max-width: 600px;
            width: 92%;
            margin: 30px auto;
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(27,77,62,0.08), 0 5px 15px rgba(0,0,0,0.03);
            overflow: hidden;
            position: relative;
            animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes slideUp {
            0% { transform: translateY(30px); opacity: 0; }
            100% { transform: translateY(0); opacity: 1; }
        }

        /* Top Brand Bar */
        .top-brand-bar {
            background: #ffffff;
            text-align: center;
            padding: 15px; 
            border-bottom: 1px solid var(--border-soft);
            position: relative;
        }
        .top-brand-bar::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 5px;
            background: linear-gradient(90deg, var(--primary-dark), var(--gold), var(--accent));
        }
        .brand-name-text {
            font-size: clamp(20px, 5vw, 26px);
            font-weight: 800;
            color: #10241c;
            margin: 0;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        /* Content Area */
        .ty-content {
            padding: clamp(25px, 6vw, 40px) clamp(20px, 5vw, 35px);
            text-align: center;
        }

        /* Animated Success Icon */
        .success-animation {
            margin: 0 auto 20px;
        }
        .checkmark-circle {
            width: 80px;
            height: 80px;
            position: relative;
            display: inline-block;
            vertical-align: top;
            border-radius: 50%;
            background: var(--primary-dark);
            box-shadow: 0 0 0 10px var(--primary-pale);
            animation: scalePulse 0.8s ease-out;
        }
        .checkmark-circle::after {
            content: '';
            position: absolute;
            left: 23px; top: 38px;
            width: 18px; height: 10px;
            border-left: 4px solid #fff;
            border-bottom: 4px solid #fff;
            transform: rotate(-45deg) translateY(-50%);
            transform-origin: center;
        }
        @keyframes scalePulse {
            0% { transform: scale(0); }
            50% { transform: scale(1.1); box-shadow: 0 0 0 20px rgba(234, 245, 239, 0.5); }
            100% { transform: scale(1); box-shadow: 0 0 0 10px var(--primary-pale); }
        }

        .ty-title {
            font-size: clamp(24px, 6vw, 32px);
            font-weight: 800;
            color: var(--primary-dark);
            margin: 0 0 8px;
            line-height: 1.2;
        }
        .ty-subtitle {
            font-size: clamp(15px, 4vw, 17px);
            color: var(--text-dark);
            font-weight: 600;
            margin: 0 0 5px;
        }
        .ty-note {
            font-size: clamp(13px, 3.5vw, 15px);
            color: var(--text-muted);
            margin: 0;
            background: #fff8eb;
            padding: 8px 15px;
            border-radius: 8px;
            display: inline-block;
            border: 1px dashed var(--gold);
            margin-top: 10px;
            font-weight: 600;
        }

        /* Order Summary Box */
        .order-summary-block { 
            background: #fbfcfb; 
            border: 2px solid #e6eae8; 
            border-radius: 14px; 
            margin-top: clamp(25px, 6vw, 35px); 
            text-align: left; 
            overflow: hidden;
        }
        .os-header {
            background: var(--primary-pale);
            padding: 15px 20px;
            border-bottom: 2px solid #e6eae8;
            font-weight: 800;
            font-size: 18px;
            color: var(--primary-dark);
            display: flex;
            justify-content: center; /* Center aligned since order ID is removed */
            align-items: center;
        }
        .os-body { padding: 20px; }
        
        .order-row { 
            display: flex; 
            justify-content: space-between; 
            padding: 10px 0; 
            font-size: clamp(14px, 3.8vw, 16px);
            border-bottom: 1px dashed #eee;
        }
        .order-row:last-child { border-bottom: none; }
        .order-row .label { color: var(--text-muted); font-weight: 600; }
        .order-row .val { color: var(--text-dark); font-weight: 700; text-align: right; max-width: 60%; }
        
        .order-total-row {
            display: flex; justify-content: space-between; align-items: center;
            background: #fff;
            padding: 15px 20px;
            border-top: 2px solid #e6eae8;
            font-size: clamp(18px, 4.5vw, 20px);
            font-weight: 800;
            color: var(--primary-dark);
        }
        .order-total-row .val { color: var(--accent); }

        /* Trust Badges */
        .trust-badges {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 30px;
        }
        .trust-item {
            text-align: center;
            flex: 1;
        }
        .trust-item div {
            width: 40px; height: 40px;
            background: var(--primary-pale);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 5px;
            font-size: 18px;
            color: var(--primary-dark);
        }
        .trust-item p {
            margin: 0;
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
        }

        /* Footer */
        .green-footer-banner { 
            background: var(--primary-dark); 
            color: #ffffff; 
            text-align: center;
            padding: 15px; 
            font-weight: 600; 
            font-size: 13px;
            letter-spacing: 0.5px;
        }
        .green-footer-banner a { color: var(--gold); text-decoration: none; }

        @media (max-width: 480px) {
            .wrapper-ty { margin: 15px auto; width: 95%; border-radius: 15px; }
            .os-body { padding: 15px; }
            .trust-badges { gap: 10px; }
            .trust-item div { width: 35px; height: 35px; font-size: 16px; }
        }
    </style>
</head>
<body>
<!-- Google Tag Manager (noscript) -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-54232S48"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->
<div class="wrapper-ty">
    
    <div class="top-brand-bar">
        <h2 class="brand-name-text">Fit Home</h2>
    </div>

    <div class="ty-content">
        <div class="success-animation">
            <div class="checkmark-circle"></div>
        </div>
        
        <h1 class="ty-title">অর্ডার কনফার্ম!</h1>
        <p class="ty-subtitle"> আমরা আপনার অর্ডারটি পেয়েছি।</p>
        <p class="ty-note">📞 দ্রুত ডেলিভারির জন্য আমাদের প্রতিনিধি আপনাকে কল করবেন</p>

        <?php 
        if ( $order ) :

                // Delivery Charge Logic
                $delivery_charge = 0;
                foreach ( $order->get_fees() as $item_id => $item ) {
                    $delivery_charge += $item->get_total();
                }
                $shipping_display = ( $delivery_charge > 0 )
                    ? esc_html( $delivery_charge ) . ' ৳'
                    : '<span style="color:var(--success-green);">ফ্রি</span>';
        ?>
            <div class="order-summary-block">
                <div class="os-header">
                    অর্ডারের বিবরণ
                </div>
                
                <div class="os-body">
                    <div class="order-row">
                        <span class="label">কাস্টমারের নাম:</span>
                        <span class="val"><?php echo esc_html( $order->get_billing_first_name() ); ?></span>
                    </div>
                    <div class="order-row">
                        <span class="label">মোবাইল নম্বর:</span>
                        <span class="val"><?php echo esc_html( $order->get_billing_phone() ); ?></span>
                    </div>
                    <div class="order-row">
                        <span class="label">অর্ডারকৃত প্যাকেজ:</span>
                        <span class="val">
                            <?php 
                            foreach ($order->get_items() as $item_id => $item) {
                                echo esc_html( $item->get_name() ) . ' <span style="color:var(--accent);">× ' . esc_html( $item->get_quantity() ) . '</span><br>';
                            }
                            ?>
                        </span>
                    </div>
                    <div class="order-row">
                        <span class="label">ডেলিভারি চার্জ:</span>
                        <span class="val"><?php echo $shipping_display; ?></span>
                    </div>
                    <div class="order-row">
                        <span class="label">পেমেন্ট মেথড:</span>
                        <span class="val">ক্যাশ অন ডেলিভারি</span>
                    </div>
                </div>
                
                <div class="order-total-row">
                    <span>সর্বমোট বিল:</span>
                    <span class="val"><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></span>
                </div>
            </div>
        <?php 
        endif; 
        ?>

        <div class="trust-badges">
            <div class="trust-item">
                <div>📦</div>
                <p>ফাস্ট ডেলিভারি</p>
            </div>
            <div class="trust-item">
                <div>🛡️</div>
                <p>১০০% অরিজিনাল</p>
            </div>
            <div class="trust-item">
                <div>💵</div>
                <p>ক্যাশ অন ডেলিভারি</p>
            </div>
        </div>

    </div>

    <div class="green-footer-banner">
        &copy; <?php echo date('Y'); ?> Fit Home. All Rights Reserved. <br>
    </div>

</div>
<?php 
/*
 * [TRACKING FIX] আগে `_fithome_purchase_tracked` মেটা ফ্ল্যাগ পেজ রেন্ডার হওয়ার সময়েই বসে যেত —
 * ব্রাউজার স্ক্রিপ্ট আদৌ চলেছে কি না তার নিশ্চয়তা ছাড়াই। কাস্টমারের অ্যাডব্লকার থাকলে বা
 * GTM লোডের আগে পেজ বন্ধ করলে ওই পারচেজ চিরতরে হারিয়ে যেত, রিফ্রেশেও ফিরত না।
 *
 * এখন ফ্ল্যাগ বাদ — বদলে ৪৮ ঘণ্টার উইন্ডো। এই সময়ের মধ্যে রিফ্রেশ করলে ইভেন্ট আবার যাবে,
 * কিন্তু event_id (`purchase_<order_id>`) ও transaction_id একই থাকায় Meta ও GA4 নিজেরাই
 * ডিডুপ্লিকেট করবে — ডাবল কাউন্ট হবে না। ৪৮ ঘণ্টা পরে (Meta-র dedupe উইন্ডো শেষ)
 * পুরনো লিংক খুললে আর ফায়ার হবে না, তাই তখনও ডুপ্লিকেটের ঝুঁকি নেই।
 */
$fithome_should_track = false;
if ( $order ) {
    $created = $order->get_date_created();
    if ( $created ) {
        $fithome_should_track = ( ( time() - $created->getTimestamp() ) < ( 48 * HOUR_IN_SECONDS ) );
    } else {
        $fithome_should_track = true;
    }
}

if ( $fithome_should_track ) {

    $total_value    = $order->get_total();
    $currency       = $order->get_currency();
    $phone          = $order->get_billing_phone();
    $items          = $order->get_items();
    $product_names  = [];
    $ga4_items      = [];
    $total_qty      = 0;

    // ডেলিভারি চার্জ আলাদা করে বের করা (GA4-এর shipping প্যারামিটারের জন্য)
    $ga4_shipping = 0;
    foreach ( $order->get_fees() as $fee_item ) {
        $ga4_shipping += (float) $fee_item->get_total();
    }

    foreach ($items as $item) {
        $product_names[] = $item->get_name();
        $item_qty        = max( 1, (int) $item->get_quantity() );
        $total_qty      += $item->get_quantity();

        $ga4_items[] = array(
            'item_id'   => (string) ( defined('FITHOME_PRODUCT_ID') ? FITHOME_PRODUCT_ID : 1905 ),
            'item_name' => $item->get_name(),
            // [TRACKING FIX] GA4 আইটেম রেভিনিউ = price × quantity। আগে এখানে লাইন টোটাল
            // (get_total = ১৭৪৯) আর quantity ২ দেওয়ায় GA4 ধরত ৩৪৯৮ — অথচ value যেত ১৭৪৯,
            // ফলে Item Revenue ডাবল দেখাত। এখন ইউনিট প্রাইস পাঠানো হচ্ছে।
            'price'     => round( ( (float) $item->get_total() ) / $item_qty, 2 ),
            'quantity'  => (int) $item->get_quantity(),
        );
    }
    $product_list = implode(', ', $product_names);

    /* [TRACKING FIX] Meta CAPI ম্যাচ কোয়ালিটির জন্য ফোন E.164 ফরম্যাটে (8801XXXXXXXXX)।
       fallback-এও শূন্য ছাড়া ১০ ডিজিটের কেসটা হ্যান্ডেল করা হলো। */
    if ( function_exists('fithome_normalize_phone') ) {
        $phone_e164 = fithome_normalize_phone( $phone );
    } else {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if ( $digits === '' ) {
            $phone_e164 = '';
        } elseif ( strpos($digits, '880') === 0 ) {
            $phone_e164 = $digits;
        } elseif ( strpos($digits, '0') === 0 ) {
            $phone_e164 = '88' . $digits;
        } elseif ( strlen($digits) === 10 && strpos($digits, '1') === 0 ) {
            $phone_e164 = '880' . $digits;
        } else {
            $phone_e164 = '88' . $digits;
        }
    }

    /* [TRACKING FIX] Meta-র `ct` (city) প্যারামিটার আসল শহরের নাম হ্যাশ করে ম্যাচ করে।
       "Outside Dhaka" কোনো শহরের নাম নয় — পাঠালে ওই ফিল্ডে ০% ম্যাচ হয়ে সামগ্রিক
       match quality নামিয়ে দেয়। তাই ঢাকার বাইরে হলে খালি পাঠানো হচ্ছে। */
    $billing_city = $order->get_billing_city();
    $ga4_city     = ( strtolower( trim( (string) $billing_city ) ) === 'dhaka' ) ? 'Dhaka' : '';

    $fithome_pid = intval( defined('FITHOME_PRODUCT_ID') ? FITHOME_PRODUCT_ID : 1905 );
    ?>
    <!-- Fit Home Custom Purchase DataLayer -->

    <script>
/* [NEW — ecommerce nesting] GA4-র স্ট্যান্ডার্ড ইকমার্স স্কিমা অনুযায়ী
   transaction_id / value / currency / shipping / items এখন `ecommerce` অবজেক্টে।
   Meta Pixel `ecommerce` বোঝে না, তাই Meta-র প্যারামিটার টপ লেভেলেও রাখা হয়েছে। */
window.dataLayer = window.dataLayer || [];
window.dataLayer.push({ ecommerce: null });   // আগের ecommerce ডেটা ক্লিয়ার
window.dataLayer.push({
    'event': 'purchase',
    'event_id': 'purchase_<?php echo intval($order_id); ?>',

    // ----- Advanced Matching (flat) -----
    'user_phone': '<?php echo esc_js($phone_e164); ?>',
    'full_name': '<?php echo esc_js($order->get_billing_first_name()); ?>', 
    'city': '<?php echo esc_js($ga4_city); ?>',
    'country': '<?php echo esc_js($order->get_billing_country()); ?>',

    // ----- Meta Pixel (flat) -----
    'content_ids': ['<?php echo $fithome_pid; ?>'],
    'content_type': 'product',
    'num_items': <?php echo intval($total_qty); ?>,
    'content_name': '<?php echo esc_js($product_list); ?>',
    'currency': '<?php echo esc_js($currency); ?>',
    'value': <?php echo floatval($total_value); ?>,

    // ----- GA4 (nested) -----
    'ecommerce': {
        'transaction_id': '<?php echo intval($order_id); ?>',
        'currency': '<?php echo esc_js($currency); ?>',
        'value': <?php echo floatval($total_value); ?>,
        'shipping': <?php echo floatval($ga4_shipping); ?>,
        'items': <?php echo wp_json_encode( $ga4_items ); ?>
    }
});
</script>

<?php
}
?>

<?php wp_footer(); ?>
</body>
</html>