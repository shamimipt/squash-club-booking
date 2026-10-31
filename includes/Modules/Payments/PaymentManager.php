<?php
namespace SquashClubBooking\Modules\Payments;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use SquashClubBooking\Core\Settings;
use SquashClubBooking\Core\AuditLog;

/**
 * WooCommerce Payment & Order Manager.
 *
 * Handles order creation, virtual product resolution, and checkout link generation.
 *
 * @package SquashClubBooking\Modules\Payments
 */
class PaymentManager {

    /**
     * Check if WooCommerce is installed and active.
     *
     * @return bool
     */
    public static function is_available(): bool {
        return class_exists( 'WooCommerce' ) && function_exists( 'wc_create_order' );
    }

    /**
     * Get or create a hidden virtual WooCommerce product for booking services.
     *
     * @param string $service_type 'clinic'|'lesson'|'camp'
     * @param string $title
     * @return int Product ID
     */
    public static function get_or_create_service_product( string $service_type, string $title ): int {
        if ( ! self::is_available() ) {
            return 0;
        }

        $option_key = 'scb_wc_product_' . sanitize_key( $service_type );
        $product_id = (int) get_option( $option_key, 0 );

        if ( $product_id && get_post_status( $product_id ) === 'publish' ) {
            return $product_id;
        }

        // Create a new simple virtual product.
        $product = new \WC_Product_Simple();
        $product->set_name( $title );
        $product->set_status( 'publish' );
        $product->set_catalog_visibility( 'hidden' ); // Hidden from shop catalog.
        $product->set_virtual( true );
        $product->set_sold_individually( true );
        $product->set_price( 0 );
        $product->set_regular_price( 0 );
        $new_id = $product->save();

        if ( $new_id ) {
            update_option( $option_key, $new_id );
            return $new_id;
        }

        return 0;
    }

    /**
     * Create a WooCommerce order for a clinic or private lesson booking.
     *
     * @param object|array $booking
     * @param string $item_name
     * @param float $price
     * @return array [ 'order_id' => int, 'checkout_url' => string ]
     */
    public static function create_order_for_booking( $booking, string $item_name, float $price ): array {
        global $wpdb;
        $b = (object) $booking;

        if ( ! self::is_available() || $price <= 0 ) {
            return [ 'order_id' => 0, 'checkout_url' => '' ];
        }

        try {
            $product_id = self::get_or_create_service_product( $b->service_type, 'Squash Club Booking' );

            $order = wc_create_order( [
                'customer_id' => $b->customer_user_id ?: 0,
            ] );

            if ( is_wp_error( $order ) || ! $order ) {
                return [ 'order_id' => 0, 'checkout_url' => '' ];
            }

            // Add line item with custom name and dynamic price.
            $item = new \WC_Order_Item_Product();
            if ( $product_id ) {
                $item->set_product_id( $product_id );
            }
            $item->set_name( $item_name . ' (' . $b->booking_ref . ')' );
            $item->set_quantity( 1 );
            $item->set_subtotal( $price );
            $item->set_total( $price );
            $order->add_item( $item );

            // Set customer billing address.
            $name_parts = explode( ' ', $b->customer_name, 2 );
            $first_name = $name_parts[0] ?? '';
            $last_name  = $name_parts[1] ?? '';

            $order->set_billing_first_name( $first_name );
            $order->set_billing_last_name( $last_name );
            $order->set_billing_email( $b->customer_email );
            if ( ! empty( $b->customer_phone ) ) {
                $order->set_billing_phone( $b->customer_phone );
            }

            // Save custom metadata.
            $order->update_meta_data( '_scb_booking_id', $b->id );
            $order->update_meta_data( '_scb_booking_ref', $b->booking_ref );
            $order->update_meta_data( '_scb_service_type', $b->service_type );

            $order->calculate_totals();
            $order->set_status( 'pending', __( 'Awaiting booking payment.', 'squash-club-booking' ) );
            $order->save();

            $order_id = $order->get_id();

            // Link to booking.
            $wpdb->update(
                $wpdb->prefix . 'scb_bookings',
                [ 'wc_order_id' => $order_id, 'updated_at' => current_time( 'mysql' ) ],
                [ 'id' => $b->id ],
                [ '%d', '%s' ],
                [ '%d' ]
            );

            // Insert into payments table.
            $wpdb->replace(
                $wpdb->prefix . 'scb_payments',
                [
                    'entity_type'    => 'booking',
                    'entity_id'      => $b->id,
                    'wc_order_id'    => $order_id,
                    'amount'         => $price,
                    'currency'       => get_woocommerce_currency(),
                    'payment_method' => '',
                    'payment_status' => 'pending',
                    'created_at'     => current_time( 'mysql' ),
                ],
                [ '%s', '%d', '%d', '%f', '%s', '%s', '%s', '%s' ]
            );

            $checkout_url = $order->get_checkout_payment_url();

            return [
                'order_id'     => $order_id,
                'checkout_url' => $checkout_url,
            ];

        } catch ( \Throwable $e ) {
            error_log( 'SCB WooCommerce Order Error: ' . $e->getMessage() );
            return [ 'order_id' => 0, 'checkout_url' => '' ];
        }
    }

    /**
     * Create a WooCommerce order for a summer camp registration.
     *
     * @param object|array $reg
     * @param object|array $camp
     * @return array [ 'order_id' => int, 'checkout_url' => string ]
     */
    public static function create_order_for_camp( $reg, $camp ): array {
        global $wpdb;
        $r = (object) $reg;
        $c = (object) $camp;

        $price = floatval( $r->amount );
        if ( ! self::is_available() || $price <= 0 ) {
            return [ 'order_id' => 0, 'checkout_url' => '' ];
        }

        try {
            $product_id = self::get_or_create_service_product( 'camp', 'Summer Camp Registration' );

            $order = wc_create_order( [
                'customer_id' => $r->customer_user_id ?: 0,
            ] );

            if ( is_wp_error( $order ) || ! $order ) {
                return [ 'order_id' => 0, 'checkout_url' => '' ];
            }

            $item = new \WC_Order_Item_Product();
            if ( $product_id ) {
                $item->set_product_id( $product_id );
            }
            $item->set_name( $c->title . ' — Student: ' . $r->student_name . ' (' . $r->registration_ref . ')' );
            $item->set_quantity( 1 );
            $item->set_subtotal( $price );
            $item->set_total( $price );
            $order->add_item( $item );

            $name_parts = explode( ' ', $r->parent_name, 2 );
            $order->set_billing_first_name( $name_parts[0] ?? '' );
            $order->set_billing_last_name( $name_parts[1] ?? '' );
            $order->set_billing_email( $r->parent_email );
            if ( ! empty( $r->parent_phone ) ) {
                $order->set_billing_phone( $r->parent_phone );
            }

            $order->update_meta_data( '_scb_camp_registration_id', $r->id );
            $order->update_meta_data( '_scb_registration_ref', $r->registration_ref );
            $order->update_meta_data( '_scb_camp_id', $c->id );

            $order->calculate_totals();
            $order->set_status( 'pending', __( 'Awaiting camp registration payment.', 'squash-club-booking' ) );
            $order->save();

            $order_id = $order->get_id();

            // Link order to camp registration record.
            $wpdb->update(
                $wpdb->prefix . 'scb_camp_registrations',
                [ 'wc_order_id' => $order_id, 'updated_at' => current_time( 'mysql' ) ],
                [ 'id' => $r->id ],
                [ '%d', '%s' ],
                [ '%d' ]
            );

            // Record in payments table.
            $wpdb->replace(
                $wpdb->prefix . 'scb_payments',
                [
                    'entity_type'    => 'camp_registration',
                    'entity_id'      => $r->id,
                    'wc_order_id'    => $order_id,
                    'amount'         => $price,
                    'currency'       => get_woocommerce_currency(),
                    'payment_method' => '',
                    'payment_status' => 'pending',
                    'created_at'     => current_time( 'mysql' ),
                ],
                [ '%s', '%d', '%d', '%f', '%s', '%s', '%s', '%s' ]
            );

            return [
                'order_id'     => $order_id,
                'checkout_url' => $order->get_checkout_payment_url(),
            ];

        } catch ( \Throwable $e ) {
            error_log( 'SCB Camp WC Order Error: ' . $e->getMessage() );
            return [ 'order_id' => 0, 'checkout_url' => '' ];
        }
    }

    /**
     * Cron helper: Expire pending abandoned reservations.
     */
    public static function expire_pending_reservations(): void {
        $module = scb()->module( 'payments' );
        if ( $module && method_exists( $module, 'cron_release_abandoned_reservations' ) ) {
            $module->cron_release_abandoned_reservations();
        }
    }
}
