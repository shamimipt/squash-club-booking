<?php
namespace SquashClubBooking\Modules\Waitlist;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use SquashClubBooking\Core\AuditLog;
use SquashClubBooking\Modules\Notifications\NotificationModule;

/**
 * Manages customer waitlists for Sunday clinics and lessons.
 *
 * @package SquashClubBooking\Modules\Waitlist
 */
class WaitlistManager {

    /**
     * Add a booking to the waitlist.
     *
     * @param int $booking_id
     * @param int|null $slot_id
     * @param int|null $coach_id
     * @param string $service_type
     * @param string $customer_name
     * @param string $customer_email
     * @param string|null $customer_phone
     * @return int Waitlist position
     */
    public static function add( int $booking_id, ?int $slot_id, ?int $coach_id, string $service_type, string $customer_name, string $customer_email, ?string $customer_phone = null ): int {
        global $wpdb;

        $table = $wpdb->prefix . 'scb_waitlist';

        // Get next position for this slot or coach.
        if ( $slot_id ) {
            $current_max = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(MAX(position), 0) FROM {$table} WHERE clinic_slot_id = %d AND status = 'waiting'",
                $slot_id
            ) );
        } else {
            $current_max = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(MAX(position), 0) FROM {$table} WHERE coach_id = %d AND status = 'waiting'",
                $coach_id
            ) );
        }

        $position = $current_max + 1;

        $wpdb->insert(
            $table,
            [
                'booking_id'     => $booking_id,
                'clinic_slot_id' => $slot_id,
                'coach_id'       => $coach_id,
                'service_type'   => $service_type,
                'customer_name'  => $customer_name,
                'customer_email' => $customer_email,
                'customer_phone' => $customer_phone,
                'position'       => $position,
                'status'         => 'waiting',
                'created_at'     => current_time( 'mysql' ),
            ],
            [ '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );

        $waitlist_id = $wpdb->insert_id;

        // If clinic slot, update waitlist count on slot.
        if ( $slot_id ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->prefix}scb_clinic_slots SET waitlist_count = waitlist_count + 1 WHERE id = %d",
                $slot_id
            ) );
        }

        // Notify customer.
        $booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}scb_bookings WHERE id = %d", $booking_id ) );
        if ( $booking ) {
            NotificationModule::send_waitlist_confirmation( $booking, $position );
        }

        AuditLog::log(
            'waitlist_joined',
            'waitlist',
            $waitlist_id,
            [],
            [ 'booking_id' => $booking_id, 'position' => $position ]
        );

        return $position;
    }

    /**
     * Promote a waitlist entry to an active booking.
     *
     * @param int $waitlist_id
     * @return array [ 'success' => bool, 'message' => string ]
     */
    public static function promote( int $waitlist_id ): array {
        global $wpdb;

        $wpdb->query( 'START TRANSACTION' );

        try {
            $entry = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}scb_waitlist WHERE id = %d FOR UPDATE",
                $waitlist_id
            ) );

            if ( ! $entry ) {
                $wpdb->query( 'ROLLBACK' );
                return [ 'success' => false, 'message' => __( 'Waitlist entry not found.', 'squash-club-booking' ) ];
            }

            if ( $entry->status !== 'waiting' ) {
                $wpdb->query( 'ROLLBACK' );
                return [ 'success' => false, 'message' => __( 'Entry is not currently waiting.', 'squash-club-booking' ) ];
            }

            // If clinic slot, check if space is available or can be allocated.
            if ( $entry->clinic_slot_id ) {
                $slot = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}scb_clinic_slots WHERE id = %d FOR UPDATE",
                    $entry->clinic_slot_id
                ) );

                if ( $slot ) {
                    $wpdb->query( $wpdb->prepare(
                        "UPDATE {$wpdb->prefix}scb_clinic_slots
                         SET booked_count = booked_count + 1,
                             waitlist_count = GREATEST(0, waitlist_count - 1),
                             status = IF(booked_count >= capacity, 'full', status)
                         WHERE id = %d",
                        $slot->id
                    ) );
                }
            }

            $expiry_hours = absint( \SquashClubBooking\Core\Settings::get( 'waitlist_promotion_expiry', 24 ) );
            $now          = current_time( 'mysql' );
            $expires_at   = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + ( $expiry_hours * HOUR_IN_SECONDS ) );

            // Update waitlist entry status.
            $wpdb->update(
                $wpdb->prefix . 'scb_waitlist',
                [
                    'status'      => 'promoted',
                    'promoted_at' => $now,
                    'expires_at'  => $expires_at,
                    'updated_at'  => $now,
                ],
                [ 'id' => $waitlist_id ],
                [ '%s', '%s', '%s', '%s' ],
                [ '%d' ]
            );

            // Update linked booking status.
            $wpdb->update(
                $wpdb->prefix . 'scb_bookings',
                [
                    'booking_status' => 'approved',
                    'updated_at'     => $now,
                ],
                [ 'id' => $entry->booking_id ],
                [ '%s', '%s' ],
                [ '%d' ]
            );

            $wpdb->query( 'COMMIT' );

        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            error_log( 'SCB Waitlist Promotion Error: ' . $e->getMessage() );
            return [ 'success' => false, 'message' => __( 'Database error during promotion. Please try again.', 'squash-club-booking' ) ];
        }

        // Notify customer.
        $booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}scb_bookings WHERE id = %d", $entry->booking_id ) );
        if ( $booking ) {
            NotificationModule::send_waitlist_promotion( $booking );
        }

        AuditLog::log(
            'waitlist_promoted',
            'waitlist',
            $waitlist_id,
            [ 'status' => 'waiting' ],
            [ 'status' => 'promoted', 'booking_id' => $entry->booking_id ]
        );

        return [ 'success' => true, 'message' => __( 'Waitlist entry promoted and customer notified.', 'squash-club-booking' ) ];
    }

    /**
     * Cron helper: Expire promoted entries that were not paid/confirmed within configured expiry,
     * safely releasing slot capacity and cancelling the abandoned reservation.
     *
     * @return int Number of expired entries processed
     */
    public static function expire_stale_promotions(): int {
        global $wpdb;

        $now          = current_time( 'mysql' );
        $expiry_hours = absint( \SquashClubBooking\Core\Settings::get( 'waitlist_promotion_expiry', 24 ) );
        $cutoff       = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $expiry_hours * HOUR_IN_SECONDS ) );

        $stale = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, booking_id, clinic_slot_id FROM {$wpdb->prefix}scb_waitlist
             WHERE status = 'promoted'
               AND ( ( expires_at IS NOT NULL AND expires_at < %s ) OR ( expires_at IS NULL AND promoted_at < %s ) )",
            $now,
            $cutoff
        ) );

        if ( empty( $stale ) ) {
            return 0;
        }

        $count = 0;

        foreach ( $stale as $entry ) {
            $wpdb->query( 'START TRANSACTION' );

            try {
                // Check if booking was already paid. If paid, do not cancel!
                $booking = $wpdb->get_row( $wpdb->prepare(
                    "SELECT id, payment_status, booking_status FROM {$wpdb->prefix}scb_bookings WHERE id = %d FOR UPDATE",
                    $entry->booking_id
                ) );

                if ( $booking && $booking->payment_status === 'paid' ) {
                    // Customer paid in time; complete the promotion record.
                    $wpdb->update(
                        $wpdb->prefix . 'scb_waitlist',
                        [ 'status' => 'completed', 'updated_at' => current_time( 'mysql' ) ],
                        [ 'id' => $entry->id ],
                        [ '%s', '%s' ],
                        [ '%d' ]
                    );
                    $wpdb->query( 'COMMIT' );
                    continue;
                }

                // Expire waitlist entry.
                $wpdb->update(
                    $wpdb->prefix . 'scb_waitlist',
                    [ 'status' => 'expired', 'updated_at' => current_time( 'mysql' ) ],
                    [ 'id' => $entry->id ],
                    [ '%s', '%s' ],
                    [ '%d' ]
                );

                // Cancel the linked unpaid booking.
                if ( $booking && $booking->booking_status !== 'cancelled' ) {
                    $wpdb->update(
                        $wpdb->prefix . 'scb_bookings',
                        [
                            'booking_status'      => 'cancelled',
                            'cancellation_reason' => __( 'Waitlist promotion offer expired (unpaid/unconfirmed within deadline)', 'squash-club-booking' ),
                            'updated_at'          => current_time( 'mysql' ),
                        ],
                        [ 'id' => $entry->booking_id ],
                        [ '%s', '%s', '%s' ],
                        [ '%d' ]
                    );
                }

                // Release clinic slot capacity safely.
                if ( $entry->clinic_slot_id ) {
                    $wpdb->query( $wpdb->prepare(
                        "UPDATE {$wpdb->prefix}scb_clinic_slots
                         SET booked_count = GREATEST(0, booked_count - 1),
                             status = IF(status = 'full', 'open', status)
                         WHERE id = %d",
                        $entry->clinic_slot_id
                    ) );
                }

                $wpdb->query( 'COMMIT' );
                $count++;

                AuditLog::log(
                    'waitlist_expired',
                    'waitlist',
                    $entry->id,
                    [ 'status' => 'promoted' ],
                    [ 'status' => 'expired', 'booking_id' => $entry->booking_id ]
                );

            } catch ( \Throwable $e ) {
                $wpdb->query( 'ROLLBACK' );
                error_log( 'SCB Expire Waitlist Error: ' . $e->getMessage() );
            }
        }

        return $count;
    }
}
