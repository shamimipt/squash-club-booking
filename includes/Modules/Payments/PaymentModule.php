<?php
namespace SquashClubBooking\Modules\Payments;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use SquashClubBooking\Core\AuditLog;
use SquashClubBooking\Core\Settings;
use SquashClubBooking\Modules\Notifications\NotificationModule;

/**
 * WooCommerce payment sync and webhook listener.
 *
 * Listens to WC order status changes and syncs booking & camp payment statuses.
 *
 * @package SquashClubBooking\Modules\Payments
 */
class PaymentModule {

    /**
     * Prevent re-entrant status changes during auto-complete.
     *
     * @var array<int, bool>
     */
    private static array $completing_orders = [];

    /**
     * Prevent recursion when syncing orders from SCB admin to WooCommerce.
     *
     * @var array<int, bool>
     */
    private static array $syncing_from_scb = [];

    /**
     * Register hooks.
     */
    public function register(): void {
        // WooCommerce order status hooks (covers HPOS and standard post orders).
        add_action( 'woocommerce_order_status_changed',    [ $this, 'on_order_status_changed' ], 10, 4 );
        add_action( 'woocommerce_order_status_completed',  [ $this, 'on_order_paid'     ], 10, 1 );
        add_action( 'woocommerce_order_status_processing', [ $this, 'on_order_paid'     ], 10, 1 );
        add_action( 'woocommerce_payment_complete',        [ $this, 'on_order_paid'     ], 10, 1 );
        add_action( 'woocommerce_order_status_pending',   [ $this, 'on_order_pending'  ], 10, 1 );
        add_action( 'woocommerce_order_status_on-hold',   [ $this, 'on_order_pending'  ], 10, 1 );
        add_action( 'woocommerce_order_status_cancelled',  [ $this, 'on_order_cancelled'], 10, 1 );
        add_action( 'woocommerce_order_status_refunded',   [ $this, 'on_order_refunded' ], 10, 1 );
        add_action( 'woocommerce_order_status_failed',     [ $this, 'on_order_failed'   ], 10, 1 );

        // Trash and delete hooks.
        add_action( 'woocommerce_trash_order',             [ $this, 'on_order_cancelled'], 10, 1 );
        add_action( 'wp_trash_post',                       [ $this, 'on_order_cancelled'], 10, 1 );
        add_action( 'woocommerce_delete_order',            [ $this, 'on_order_cancelled'], 10, 1 );
        add_action( 'before_delete_post',                  [ $this, 'on_order_cancelled'], 10, 1 );
        add_action( 'woocommerce_untrash_order',           [ $this, 'on_order_untrash'  ], 10, 1 );

        // Filter order status upon payment complete (auto-complete booking orders).
        add_filter( 'woocommerce_payment_complete_order_status', [ $this, 'filter_autocomplete_order_status' ], 10, 3 );

        // Abandoned capacity cleanup cron.
        add_action( 'scb_hourly_capacity_cleanup',       [ $this, 'cron_release_abandoned_reservations' ] );
        add_action( 'scb_expire_payment_reservations',   [ $this, 'cron_release_abandoned_reservations' ] );
    }

    /**
     * Unified handler for WooCommerce order status changes.
     *
     * @param int $order_id
     * @param string $old_status
     * @param string $new_status
     * @param \WC_Order|null $order
     */
    public function on_order_status_changed( int $order_id, string $old_status, string $new_status, $order = null ): void {
        if ( ! empty( self::$syncing_from_scb[ $order_id ] ) ) {
            return;
        }

        switch ( $new_status ) {
            case 'completed':
            case 'processing':
                $this->on_order_paid( $order_id );
                break;

            case 'pending':
            case 'on-hold':
                $this->on_order_pending( $order_id );
                break;

            case 'cancelled':
                $this->on_order_cancelled( $order_id );
                break;

            case 'refunded':
                $this->on_order_refunded( $order_id );
                break;

            case 'failed':
                $this->on_order_failed( $order_id );
                break;
        }
    }

    /**
     * Handle untrashed order: re-sync its active status.
     *
     * @param int $order_id
     */
    public function on_order_untrash( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( $order ) {
            $this->on_order_status_changed( $order_id, 'trash', $order->get_status(), $order );
        }
    }

    /**
     * Filter WooCommerce payment complete order status to mark booking & camp orders directly as 'completed'.
     *
     * @param string $status
     * @param int $order_id
     * @param \WC_Order|null $order
     * @return string
     */
    public function filter_autocomplete_order_status( string $status, int $order_id, $order = null ): string {
        if ( ! $order && $order_id ) {
            $order = wc_get_order( $order_id );
        }
        if ( $order && ( $order->get_meta( '_scb_booking_id' ) || $order->get_meta( '_scb_camp_registration_id' ) ) ) {
            return 'completed';
        }
        return $status;
    }

    /**
     * Handle successful payment for an order.
     *
     * @param int $order_id
     */
    public function on_order_paid( int $order_id ): void {
        global $wpdb;

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $booking_id = $order->get_meta( '_scb_booking_id' );
        $camp_reg_id = $order->get_meta( '_scb_camp_registration_id' );
        $method      = $order->get_payment_method_title();
        $txn_id      = $order->get_transaction_id();

        // 1. Sync Booking.
        if ( $booking_id ) {
            $booking = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}scb_bookings WHERE id = %d",
                $booking_id
            ) );

            if ( $booking && $booking->payment_status !== 'paid' ) {
                $auto_approve = ! Settings::get( 'require_admin_approval', false );
                $new_b_status = $auto_approve && $booking->booking_status === 'pending' ? 'approved' : $booking->booking_status;

                $wpdb->update(
                    $wpdb->prefix . 'scb_bookings',
                    [
                        'payment_status' => 'paid',
                        'booking_status' => $new_b_status,
                        'updated_at'     => current_time( 'mysql' ),
                    ],
                    [ 'id' => $booking_id ],
                    [ '%s', '%s', '%s' ],
                    [ '%d' ]
                );

                if ( ! empty( $booking->clinic_slot_id ) ) {
                    self::sync_clinic_slot_count( (int) $booking->clinic_slot_id );
                }

                $wpdb->update(
                    $wpdb->prefix . 'scb_payments',
                    [
                        'payment_status'  => 'paid',
                        'payment_method'  => $method,
                        'transaction_id'  => $txn_id,
                        'updated_at'      => current_time( 'mysql' ),
                    ],
                    [ 'wc_order_id' => $order_id ],
                    [ '%s', '%s', '%s', '%s' ],
                    [ '%d' ]
                );

                AuditLog::log(
                    'payment_received',
                    'booking',
                    (int) $booking_id,
                    [ 'payment_status' => $booking->payment_status ],
                    [ 'payment_status' => 'paid', 'order_id' => $order_id ]
                );
            }
        }

        // 2. Sync Camp Registration.
        if ( $camp_reg_id ) {
            $reg = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}scb_camp_registrations WHERE id = %d",
                $camp_reg_id
            ) );

            if ( $reg && $reg->payment_status !== 'paid' ) {
                $wpdb->update(
                    $wpdb->prefix . 'scb_camp_registrations',
                    [
                        'payment_status'      => 'paid',
                        'registration_status' => 'confirmed',
                        'updated_at'          => current_time( 'mysql' ),
                    ],
                    [ 'id' => $camp_reg_id ],
                    [ '%s', '%s', '%s' ],
                    [ '%d' ]
                );

                $wpdb->update(
                    $wpdb->prefix . 'scb_payments',
                    [
                        'payment_status'  => 'paid',
                        'payment_method'  => $method,
                        'transaction_id'  => $txn_id,
                        'updated_at'      => current_time( 'mysql' ),
                    ],
                    [ 'wc_order_id' => $order_id ],
                    [ '%s', '%s', '%s', '%s' ],
                    [ '%d' ]
                );

                AuditLog::log(
                    'payment_received',
                    'camp_registration',
                    (int) $camp_reg_id,
                    [ 'payment_status' => $reg->payment_status ],
                    [ 'payment_status' => 'paid', 'order_id' => $order_id ]
                );
            }
        }

        // 3. Auto-complete WooCommerce order if it is in processing status.
        if ( ( $booking_id || $camp_reg_id ) && $order->has_status( 'processing' ) ) {
            if ( empty( self::$completing_orders[ $order_id ] ) ) {
                self::$completing_orders[ $order_id ] = true;
                $order->update_status( 'completed', __( 'Squash Club Booking: Order automatically completed upon successful payment.', 'squash-club-booking' ) );
                unset( self::$completing_orders[ $order_id ] );
            }
        }
    }

    /**
     * Handle order pending / on-hold.
     * Spot booking does not occur while payment is pending.
     *
     * @param int $order_id
     */
    public function on_order_pending( int $order_id ): void {
        global $wpdb;
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $booking_id  = $order->get_meta( '_scb_booking_id' );
        $camp_reg_id = $order->get_meta( '_scb_camp_registration_id' );

        if ( $booking_id ) {
            $booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}scb_bookings WHERE id = %d", $booking_id ) );
            if ( $booking && $booking->payment_status !== 'paid' ) {
                $wpdb->update(
                    $wpdb->prefix . 'scb_bookings',
                    [
                        'booking_status' => 'pending',
                        'payment_status' => 'pending',
                        'updated_at'     => current_time( 'mysql' ),
                    ],
                    [ 'id' => $booking_id ],
                    [ '%s', '%s', '%s' ],
                    [ '%d' ]
                );

                if ( ! empty( $booking->clinic_slot_id ) ) {
                    self::sync_clinic_slot_count( (int) $booking->clinic_slot_id );
                }
            }
        }

        if ( $camp_reg_id ) {
            $wpdb->update(
                $wpdb->prefix . 'scb_camp_registrations',
                [
                    'registration_status' => 'pending',
                    'payment_status'      => 'pending',
                    'updated_at'          => current_time( 'mysql' ),
                ],
                [ 'id' => $camp_reg_id ],
                [ '%s', '%s', '%s' ],
                [ '%d' ]
            );
        }

        $wpdb->update(
            $wpdb->prefix . 'scb_payments',
            [ 'payment_status' => 'pending', 'updated_at' => current_time( 'mysql' ) ],
            [ 'wc_order_id' => $order_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Handle cancelled order.
     * Cancels booking and releases any clinic slot capacity.
     *
     * @param int $order_id
     */
    public function on_order_cancelled( int $order_id ): void {
        global $wpdb;
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $booking_id  = $order->get_meta( '_scb_booking_id' );
        $camp_reg_id = $order->get_meta( '_scb_camp_registration_id' );

        if ( $booking_id ) {
            $booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}scb_bookings WHERE id = %d", $booking_id ) );

            $wpdb->update(
                $wpdb->prefix . 'scb_bookings',
                [
                    'booking_status'      => 'cancelled',
                    'payment_status'      => 'failed',
                    'cancellation_reason' => __( 'WooCommerce order cancelled.', 'squash-club-booking' ),
                    'updated_at'          => current_time( 'mysql' ),
                ],
                [ 'id' => $booking_id ],
                [ '%s', '%s', '%s', '%s' ],
                [ '%d' ]
            );

            if ( $booking && ! empty( $booking->clinic_slot_id ) ) {
                self::sync_clinic_slot_count( (int) $booking->clinic_slot_id );
            }
        }

        if ( $camp_reg_id ) {
            $wpdb->update(
                $wpdb->prefix . 'scb_camp_registrations',
                [
                    'registration_status' => 'cancelled',
                    'payment_status'      => 'failed',
                    'updated_at'          => current_time( 'mysql' ),
                ],
                [ 'id' => $camp_reg_id ],
                [ '%s', '%s', '%s' ],
                [ '%d' ]
            );
        }

        $wpdb->update(
            $wpdb->prefix . 'scb_payments',
            [ 'payment_status' => 'cancelled', 'updated_at' => current_time( 'mysql' ) ],
            [ 'wc_order_id' => $order_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Handle refunded order.
     * Cancels booking and releases any clinic slot capacity.
     *
     * @param int $order_id
     */
    public function on_order_refunded( int $order_id ): void {
        global $wpdb;
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $booking_id  = $order->get_meta( '_scb_booking_id' );
        $camp_reg_id = $order->get_meta( '_scb_camp_registration_id' );

        if ( $booking_id ) {
            $booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}scb_bookings WHERE id = %d", $booking_id ) );

            $wpdb->update(
                $wpdb->prefix . 'scb_bookings',
                [
                    'booking_status'      => 'cancelled',
                    'payment_status'      => 'refunded',
                    'cancellation_reason' => __( 'WooCommerce order refunded.', 'squash-club-booking' ),
                    'updated_at'          => current_time( 'mysql' ),
                ],
                [ 'id' => $booking_id ],
                [ '%s', '%s', '%s', '%s' ],
                [ '%d' ]
            );

            if ( $booking && ! empty( $booking->clinic_slot_id ) ) {
                self::sync_clinic_slot_count( (int) $booking->clinic_slot_id );
            }
        }

        if ( $camp_reg_id ) {
            $wpdb->update(
                $wpdb->prefix . 'scb_camp_registrations',
                [
                    'registration_status' => 'cancelled',
                    'payment_status'      => 'refunded',
                    'updated_at'          => current_time( 'mysql' ),
                ],
                [ 'id' => $camp_reg_id ],
                [ '%s', '%s', '%s' ],
                [ '%d' ]
            );
        }

        $wpdb->update(
            $wpdb->prefix . 'scb_payments',
            [ 'payment_status' => 'refunded', 'updated_at' => current_time( 'mysql' ) ],
            [ 'wc_order_id' => $order_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Handle failed order.
     * Cancels booking and releases any clinic slot capacity.
     *
     * @param int $order_id
     */
    public function on_order_failed( int $order_id ): void {
        global $wpdb;
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $booking_id  = $order->get_meta( '_scb_booking_id' );
        $camp_reg_id = $order->get_meta( '_scb_camp_registration_id' );

        if ( $booking_id ) {
            $booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}scb_bookings WHERE id = %d", $booking_id ) );

            $wpdb->update(
                $wpdb->prefix . 'scb_bookings',
                [
                    'booking_status'      => 'cancelled',
                    'payment_status'      => 'failed',
                    'cancellation_reason' => __( 'WooCommerce payment failed.', 'squash-club-booking' ),
                    'updated_at'          => current_time( 'mysql' ),
                ],
                [ 'id' => $booking_id ],
                [ '%s', '%s', '%s', '%s' ],
                [ '%d' ]
            );

            if ( $booking && ! empty( $booking->clinic_slot_id ) ) {
                self::sync_clinic_slot_count( (int) $booking->clinic_slot_id );
            }
        }

        if ( $camp_reg_id ) {
            $wpdb->update(
                $wpdb->prefix . 'scb_camp_registrations',
                [
                    'registration_status' => 'cancelled',
                    'payment_status'      => 'failed',
                    'updated_at'          => current_time( 'mysql' ),
                ],
                [ 'id' => $camp_reg_id ],
                [ '%s', '%s', '%s' ],
                [ '%d' ]
            );
        }

        $wpdb->update(
            $wpdb->prefix . 'scb_payments',
            [ 'payment_status' => 'failed', 'updated_at' => current_time( 'mysql' ) ],
            [ 'wc_order_id' => $order_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Recalculates and synchronizes booked_count and status for a clinic slot.
     * Only CONFIRMED & PAID bookings occupy a spot.
     * Pending, cancelled, refunded, and failed bookings do NOT occupy a spot.
     *
     * @param int $slot_id
     * @return int Current paid booked count
     */
    public static function sync_clinic_slot_count( int $slot_id ): int {
        global $wpdb;
        if ( ! $slot_id ) {
            return 0;
        }

        $paid_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}scb_bookings
             WHERE clinic_slot_id = %d
               AND booking_status NOT IN ('cancelled', 'rejected', 'waitlisted')
               AND payment_status = 'paid'",
            $slot_id
        ) );

        $capacity = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT capacity FROM {$wpdb->prefix}scb_clinic_slots WHERE id = %d",
            $slot_id
        ) ) ?: 15;

        $new_status = $paid_count >= $capacity ? 'full' : 'open';

        $wpdb->update(
            $wpdb->prefix . 'scb_clinic_slots',
            [
                'booked_count' => $paid_count,
                'status'       => $new_status,
                'updated_at'   => current_time( 'mysql' ),
            ],
            [ 'id' => $slot_id ],
            [ '%d', '%s', '%s' ],
            [ '%d' ]
        );

        return $paid_count;
    }

    /**
     * Cron: Release temporary reservations where checkout was abandoned.
     * Unpaid pending bookings older than configured hold time (default 60 mins).
     */
    public function cron_release_abandoned_reservations(): void {
        global $wpdb;

        $hold_minutes = absint( Settings::get( 'reservation_hold_minutes', 60 ) );
        $cutoff_time  = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $hold_minutes * 60 ) );

        // Find unpaid clinic bookings older than cutoff.
        $stale_bookings = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, clinic_slot_id FROM {$wpdb->prefix}scb_bookings
             WHERE payment_status = 'unpaid'
               AND booking_status = 'pending'
               AND created_at < %s
               AND clinic_slot_id IS NOT NULL",
            $cutoff_time
        ) );

        foreach ( $stale_bookings as $b ) {
            $wpdb->update(
                $wpdb->prefix . 'scb_bookings',
                [ 'booking_status' => 'cancelled', 'cancellation_reason' => 'Checkout expired (abandoned)', 'updated_at' => current_time( 'mysql' ) ],
                [ 'id' => $b->id ],
                [ '%s', '%s', '%s' ],
                [ '%d' ]
            );

            // Decrement slot booked count safely.
            if ( $b->clinic_slot_id ) {
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}scb_clinic_slots
                     SET booked_count = GREATEST(0, booked_count - 1),
                         status = IF(status = 'full', 'open', status)
                     WHERE id = %d",
                    $b->clinic_slot_id
                ) );
            }
        }

        // Find unpaid camp registrations older than cutoff.
        $stale_camps = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, camp_id FROM {$wpdb->prefix}scb_camp_registrations
             WHERE payment_status IN ('pending', 'unpaid')
               AND registration_status = 'pending'
               AND created_at < %s
               AND camp_id IS NOT NULL",
            $cutoff_time
        ) );

        foreach ( $stale_camps as $c ) {
            $wpdb->update(
                $wpdb->prefix . 'scb_camp_registrations',
                [ 'registration_status' => 'cancelled', 'cancellation_reason' => 'Checkout expired (abandoned)', 'updated_at' => current_time( 'mysql' ) ],
                [ 'id' => $c->id ],
                [ '%s', '%s', '%s' ],
                [ '%d' ]
            );

            if ( $c->camp_id ) {
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}scb_camps
                     SET registered_count = GREATEST(0, registered_count - 1),
                         registration_status = IF(registration_status = 'full', 'open', registration_status)
                     WHERE id = %d",
                    $c->camp_id
                ) );
            }
        }
    }

    /**
     * Synchronize linked WooCommerce order when booking or payment status changes in SCB admin.
     *
     * @param int $booking_id
     * @param string $payment_status
     * @param string $booking_status
     */
    public static function sync_wc_order_from_booking( int $booking_id, string $payment_status, string $booking_status = '' ): void {
        global $wpdb;

        if ( ! function_exists( 'wc_get_order' ) ) {
            return;
        }

        $booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}scb_bookings WHERE id = %d", $booking_id ) );
        if ( ! $booking || empty( $booking->wc_order_id ) ) {
            return;
        }

        $order_id = (int) $booking->wc_order_id;
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $target_status = null;
        if ( $booking_status === 'cancelled' || $booking_status === 'rejected' ) {
            $target_status = 'cancelled';
        } elseif ( $payment_status === 'paid' ) {
            $target_status = 'completed';
        } elseif ( $payment_status === 'refunded' ) {
            $target_status = 'refunded';
        } elseif ( $payment_status === 'failed' ) {
            $target_status = 'failed';
        } elseif ( $payment_status === 'pending' || $payment_status === 'unpaid' ) {
            $target_status = 'pending';
        }

        if ( $target_status && $order->get_status() !== $target_status ) {
            self::$syncing_from_scb[ $order_id ] = true;
            $order->update_status( $target_status, __( 'Squash Club Booking: Order status synchronized from admin dashboard.', 'squash-club-booking' ) );
            unset( self::$syncing_from_scb[ $order_id ] );
        }

        // Also ensure scb_payments is synchronized.
        $wpdb->update(
            $wpdb->prefix . 'scb_payments',
            [ 'payment_status' => $payment_status, 'updated_at' => current_time( 'mysql' ) ],
            [ 'wc_order_id' => $order_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Synchronize linked WooCommerce order when camp registration status changes in SCB admin.
     *
     * @param int $reg_id
     * @param string $payment_status
     * @param string $registration_status
     */
    public static function sync_wc_order_from_camp_registration( int $reg_id, string $payment_status, string $registration_status = '' ): void {
        global $wpdb;

        if ( ! function_exists( 'wc_get_order' ) ) {
            return;
        }

        $reg = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}scb_camp_registrations WHERE id = %d", $reg_id ) );
        if ( ! $reg || empty( $reg->wc_order_id ) ) {
            return;
        }

        $order_id = (int) $reg->wc_order_id;
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $target_status = null;
        if ( $registration_status === 'cancelled' ) {
            $target_status = 'cancelled';
        } elseif ( $payment_status === 'paid' ) {
            $target_status = 'completed';
        } elseif ( $payment_status === 'refunded' ) {
            $target_status = 'refunded';
        } elseif ( $payment_status === 'failed' ) {
            $target_status = 'failed';
        } elseif ( $payment_status === 'pending' || $payment_status === 'unpaid' ) {
            $target_status = 'pending';
        }

        if ( $target_status && $order->get_status() !== $target_status ) {
            self::$syncing_from_scb[ $order_id ] = true;
            $order->update_status( $target_status, __( 'Squash Club Booking: Camp order status synchronized from admin dashboard.', 'squash-club-booking' ) );
            unset( self::$syncing_from_scb[ $order_id ] );
        }

        $wpdb->update(
            $wpdb->prefix . 'scb_payments',
            [ 'payment_status' => $payment_status, 'updated_at' => current_time( 'mysql' ) ],
            [ 'wc_order_id' => $order_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Reconcile all bookings, camp registrations, and payments with WooCommerce orders.
     * Ensures WooCommerce orders and Squash Club Booking payments are 100% in sync.
     *
     * @return array Sync summary counts
     */
    public static function reconcile_all_orders(): array {
        global $wpdb;

        if ( ! function_exists( 'wc_get_order' ) ) {
            return [ 'synced' => 0, 'errors' => 0 ];
        }

        $synced = 0;

        // 1. Reconcile Bookings
        $bookings = $wpdb->get_results( "SELECT id, wc_order_id, booking_status, payment_status, clinic_slot_id FROM {$wpdb->prefix}scb_bookings WHERE wc_order_id IS NOT NULL AND wc_order_id > 0" );
        foreach ( $bookings as $b ) {
            $order = wc_get_order( (int) $b->wc_order_id );
            if ( $order ) {
                $wc_status = $order->get_status();
                $expected_p_status = match ( $wc_status ) {
                    'completed', 'processing' => 'paid',
                    'pending', 'on-hold'     => 'pending',
                    'refunded'                => 'refunded',
                    'cancelled', 'failed', 'trash' => 'failed',
                    default                   => $b->payment_status,
                };

                $expected_b_status = match ( $wc_status ) {
                    'completed', 'processing' => ( $b->booking_status === 'pending' ? 'approved' : $b->booking_status ),
                    'pending', 'on-hold'     => 'pending',
                    'cancelled', 'refunded', 'failed', 'trash' => 'cancelled',
                    default                   => $b->booking_status,
                };

                if ( $b->payment_status !== $expected_p_status || ( in_array( $wc_status, [ 'cancelled', 'refunded', 'failed', 'trash' ], true ) && $b->booking_status !== 'cancelled' ) ) {
                    $wpdb->update(
                        $wpdb->prefix . 'scb_bookings',
                        [
                            'payment_status' => $expected_p_status,
                            'booking_status' => $expected_b_status,
                            'updated_at'     => current_time( 'mysql' ),
                        ],
                        [ 'id' => $b->id ],
                        [ '%s', '%s', '%s' ],
                        [ '%d' ]
                    );

                    if ( ! empty( $b->clinic_slot_id ) ) {
                        self::sync_clinic_slot_count( (int) $b->clinic_slot_id );
                    }

                    $synced++;
                }

                // If WC order is processing, auto-complete it
                if ( $wc_status === 'processing' ) {
                    $order->update_status( 'completed', __( 'Squash Club Booking: Auto-completed during status sync.', 'squash-club-booking' ) );
                }
            } else {
                // If WC order was deleted from WooCommerce, and payment was pending, mark failed/cancelled
                if ( $b->payment_status === 'pending' ) {
                    $wpdb->update(
                        $wpdb->prefix . 'scb_bookings',
                        [
                            'payment_status'      => 'failed',
                            'booking_status'      => 'cancelled',
                            'cancellation_reason' => __( 'Linked WooCommerce order was removed.', 'squash-club-booking' ),
                            'updated_at'          => current_time( 'mysql' ),
                        ],
                        [ 'id' => $b->id ],
                        [ '%s', '%s', '%s', '%s' ],
                        [ '%d' ]
                    );
                    if ( ! empty( $b->clinic_slot_id ) ) {
                        self::sync_clinic_slot_count( (int) $b->clinic_slot_id );
                    }
                    $synced++;
                }
            }
        }

        // 2. Reconcile Camp Registrations
        $camps = $wpdb->get_results( "SELECT id, wc_order_id, registration_status, payment_status FROM {$wpdb->prefix}scb_camp_registrations WHERE wc_order_id IS NOT NULL AND wc_order_id > 0" );
        foreach ( $camps as $c ) {
            $order = wc_get_order( (int) $c->wc_order_id );
            if ( $order ) {
                $wc_status = $order->get_status();
                $expected_p_status = match ( $wc_status ) {
                    'completed', 'processing' => 'paid',
                    'pending', 'on-hold'     => 'pending',
                    'refunded'                => 'refunded',
                    'cancelled', 'failed', 'trash' => 'failed',
                    default                   => $c->payment_status,
                };

                $expected_r_status = match ( $wc_status ) {
                    'completed', 'processing' => ( $c->registration_status === 'pending' ? 'confirmed' : $c->registration_status ),
                    'pending', 'on-hold'     => 'pending',
                    'cancelled', 'refunded', 'failed', 'trash' => 'cancelled',
                    default                   => $c->registration_status,
                };

                if ( $c->payment_status !== $expected_p_status || ( in_array( $wc_status, [ 'cancelled', 'refunded', 'failed', 'trash' ], true ) && $c->registration_status !== 'cancelled' ) ) {
                    $wpdb->update(
                        $wpdb->prefix . 'scb_camp_registrations',
                        [
                            'payment_status'      => $expected_p_status,
                            'registration_status' => $expected_r_status,
                            'updated_at'          => current_time( 'mysql' ),
                        ],
                        [ 'id' => $c->id ],
                        [ '%s', '%s', '%s' ],
                        [ '%d' ]
                    );
                    $synced++;
                }
            }
        }

        // 3. Reconcile Payments Table
        $payments = $wpdb->get_results( "SELECT p.id, p.wc_order_id, p.payment_status, p.entity_type, p.entity_id, b.payment_status AS b_p_status, cr.payment_status AS cr_p_status FROM {$wpdb->prefix}scb_payments p LEFT JOIN {$wpdb->prefix}scb_bookings b ON p.entity_type = 'booking' AND p.entity_id = b.id LEFT JOIN {$wpdb->prefix}scb_camp_registrations cr ON p.entity_type = 'camp_registration' AND p.entity_id = cr.id" );

        foreach ( $payments as $p ) {
            $expected_status = $p->entity_type === 'booking' ? $p->b_p_status : $p->cr_p_status;
            if ( $p->wc_order_id ) {
                $order = wc_get_order( (int) $p->wc_order_id );
                if ( $order ) {
                    $wc_status = $order->get_status();
                    $expected_status = match ( $wc_status ) {
                        'completed', 'processing' => 'paid',
                        'pending', 'on-hold'     => 'pending',
                        'refunded'                => 'refunded',
                        'cancelled'               => 'cancelled',
                        'failed', 'trash'         => 'failed',
                        default                   => $expected_status ?: 'pending',
                    };
                }
            }

            if ( $expected_status && $p->payment_status !== $expected_status ) {
                $wpdb->update(
                    $wpdb->prefix . 'scb_payments',
                    [ 'payment_status' => $expected_status, 'updated_at' => current_time( 'mysql' ) ],
                    [ 'id' => $p->id ],
                    [ '%s', '%s' ],
                    [ '%d' ]
                );
                $synced++;
            }
        }

        return [ 'synced' => $synced ];
    }
}
