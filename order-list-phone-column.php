<?php
// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

// =========================================================================
// 1. Create a New Column for Phone Number
// =========================================================================

// HPOS (নতুন WooCommerce order storage) support
add_filter( 'manage_woocommerce_page_wc-orders_columns', 'fit_home_add_phone_column_header' );
// Legacy (পুরাতন post-based orders) support
add_filter( 'manage_edit-shop_order_columns', 'fit_home_add_phone_column_header' );

function fit_home_add_phone_column_header( $columns ) {
    $new_columns = array();
    
    foreach ( $columns as $key => $name ) {
        $new_columns[$key] = $name;
        // 'billing_address' কলামের ঠিক পরেই নতুন কলামটি যোগ করা হবে
        if ( 'billing_address' === $key ) {
            $new_columns['billing_phone_col'] = __( 'Phone', 'woocommerce' );
        }
    }
    
    return $new_columns;
}

// =========================================================================
// 2. Show Phone Number inside the New Column
// =========================================================================

// HPOS support
add_action( 'manage_woocommerce_page_wc-orders_custom_column', 'fit_home_show_phone_in_new_column', 20, 2 );
// Legacy support
add_action( 'manage_shop_order_posts_custom_column', 'fit_home_show_phone_in_new_column', 20, 2 );

function fit_home_show_phone_in_new_column( $column, $order ) {
    // শুধুমাত্র আমাদের তৈরি করা নতুন কলামে ডেটা দেখাবো
    if ( $column !== 'billing_phone_col' ) return;

    // HPOS এ $order আসবে WC_Order object হিসেবে, legacy তে post ID হিসেবে
    if ( is_numeric( $order ) ) {
        $order = wc_get_order( $order );
    }
    if ( ! $order ) return;

    $phone = $order->get_billing_phone();
    if ( $phone ) {
        // নতুন কলাম তাই <br> বা margin-top বাদ দেওয়া হয়েছে
        echo '<span style="font-weight:700; color:#1B4D3E;">📞 ' . esc_html( $phone ) . '</span>';
    } else {
        echo '-';
    }
}