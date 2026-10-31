<?php
namespace SquashClubBooking\Modules\Clinics;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use SquashClubBooking\Core\Settings;
use SquashClubBooking\Core\AuditLog;
use SquashClubBooking\Modules\Payments\PaymentManager;
use SquashClubBooking\Modules\Notifications\NotificationModule;
use SquashClubBooking\Modules\Waitlist\WaitlistManager;

/**
 * Handles customer-facing Sunday Clinic booking submissions.
 *
 * @package SquashClubBooking\Modules\Clinics
 */
class ClinicBookingHandler {

    /**
     * Handle clinic booking REST request.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public static function handle_booking( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        // Enforce login requirement: guest bookings are strictly disallowed.
        if ( ! is_user_logged_in() ) {
            $login_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url();
            return new \WP_REST_Response( [
                'success'      => false,
                'require_auth' => true,
                'login_url'    => $login_url,
                'message'      => __( 'You must be logged in to make a booking. Please log in or create an account.', 'squash-club-booking' ),
            ], 401 );
        }

        $current_user    = wp_get_current_user();
        $current_user_id = $current_user->ID;

        $slot_id        = absint( $request->get_param( 'slot_id' ) );
        $customer_name  = sanitize_text_field( $request->get_param( 'customer_name' ) ?? '' );
        $customer_email = sanitize_email( $request->get_param( 'customer_email' ) ?? '' );
        $customer_phone = sanitize_text_field( $request->get_param( 'customer_phone' ) ?? '' );
        $part_first     = sanitize_text_field( $request->get_param( 'participant_first_name' ) ?? '' );
        $part_last      = sanitize_text_field( $request->get_param( 'participant_last_name' ) ?? '' );
        $part_age       = absint( $request->get_param( 'participant_age' ) ?? 0 );
        $skill_level    = sanitize_text_field( $request->get_param( 'skill_level' ) ?? 'all_levels' );
        $notes          = sanitize_textarea_field( $request->get_param( 'notes' ) ?? '' );
        $join_waitlist  = (bool) $request->get_param( 'join_waitlist' );

        // Fallback to user account info if fields left empty.
        if ( empty( $customer_name ) ) {
            $customer_name = trim( $current_user->first_name . ' ' . $current_user->last_name ) ?: $current_user->display_name;
        }
        if ( empty( $customer_email ) ) {
            $customer_email = $current_user->user_email;
        }
        if ( empty( $customer_phone ) ) {
            $customer_phone = get_user_meta( $current_user_id, 'billing_phone', true );
        }

        // Validation.
        if ( ! $slot_id ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Please select a clinic slot.', 'squash-club-booking' ) ], 400 );
        }
        if ( empty( $customer_name ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Please provide your full name.', 'squash-club-booking' ) ], 400 );
        }
        if ( ! is_email( $customer_email ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Please provide a valid email address.', 'squash-club-booking' ) ], 400 );
        }
        

        // Fetch slot under database transaction lock to eliminate concurrency race conditions.
        $wpdb->query( 'START TRANSACTION' );

        try {
            $slot = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}scb_clinic_slots WHERE id = %d FOR UPDATE",
                $slot_id
            ) );

            if ( ! $slot ) {
                $wpdb->query( 'ROLLBACK' );
                return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Selected clinic slot does not exist.', 'squash-club-booking' ) ], 404 );
            }

            if ( in_array( $slot->status, [ 'cancelled', 'closed' ], true ) ) {
                $wpdb->query( 'ROLLBACK' );
                return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'This clinic session is no longer available.', 'squash-club-booking' ) ], 400 );
            }

            // Prevent duplicate booking by same email for the same slot.
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}scb_bookings WHERE clinic_slot_id = %d AND customer_email = %s AND booking_status NOT IN ('cancelled', 'rejected')",
                $slot_id,
                $customer_email
            ) );
            if ( $existing ) {
                $wpdb->query( 'ROLLBACK' );
                return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'You already have an active booking or waitlist request for this clinic session.', 'squash-club-booking' ) ], 400 );
            }

            // Check capacity under lock.
            $is_full = (int) $slot->booked_count >= (int) $slot->capacity;
            if ( ! $is_full && $slot->status === 'full' ) {
                // Auto-heal if slot was prematurely marked full while space remains.
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}scb_clinic_slots SET status = 'open' WHERE id = %d",
                    $slot_id
                ) );
                $slot->status = 'open';
            }

            // Generate human-friendly reference: SCB-CLN-YYYYMMDD-XXXX.
            $date_tag    = date( 'Ymd', strtotime( $slot->slot_date ) );
            $rand_tag    = strtoupper( wp_generate_password( 4, false ) );
            $booking_ref = "SCB-CLN-{$date_tag}-{$rand_tag}";

            $current_user_id = get_current_user_id() ?: null;
            $ip_address      = AuditLog::get_client_ip();
            $price           = floatval( $slot->price );

            // ── Full Slot Handling ────────────────────────────────────────────────
            if ( $is_full ) {
                if ( ! $join_waitlist ) {
                    $wpdb->query( 'COMMIT' );
                    return new \WP_REST_Response( [
                        'success'   => false,
                        'is_full'   => true,
                        'message'   => __( 'This clinic slot is currently full. Would you like to join the waitlist?', 'squash-club-booking' ),
                    ], 200 );
                }

                // Create booking with waitlisted status.
                $wpdb->insert(
                    $wpdb->prefix . 'scb_bookings',
                    [
                        'booking_ref'       => $booking_ref,
                        'service_type'      => 'clinic',
                        'customer_user_id'  => $current_user_id,
                        'customer_name'     => $customer_name,
                        'customer_email'    => $customer_email,
                        'customer_phone'    => $customer_phone,
                        'clinic_slot_id'    => $slot_id,
                        'participant_count' => 1,
                        'booking_status'    => 'waitlisted',
                        'payment_status'    => 'unpaid',
                        'amount'            => $price,
                        'notes'             => $notes,
                        'ip_address'        => $ip_address,
                        'created_at'        => current_time( 'mysql' ),
                    ],
                    [ '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%f', '%s', '%s', '%s' ]
                );

                $booking_id = $wpdb->insert_id;

                // Add participant record.
                $wpdb->insert(
                    $wpdb->prefix . 'scb_booking_participants',
                    [
                        'booking_id'  => $booking_id,
                        'first_name'  => $part_first ?: $customer_name,
                        'last_name'   => $part_last ?: '',
                        'age'         => $part_age ?: null,
                        'skill_level' => $skill_level,
                        'notes'       => $notes,
                        'created_at'  => current_time( 'mysql' ),
                    ],
                    [ '%d', '%s', '%s', '%d', '%s', '%s', '%s' ]
                );

                $position = WaitlistManager::add(
                    $booking_id,
                    $slot_id,
                    null,
                    'clinic',
                    $customer_name,
                    $customer_email,
                    $customer_phone
                );

                $wpdb->query( 'COMMIT' );

                $new_booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}scb_bookings WHERE id = %d", $booking_id ) );

                // WooCommerce order creation if price > 0.
                $checkout_url = '';
                if ( $price > 0 && PaymentManager::is_available() ) {
                    $cat_label  = ucfirst( $slot->category ) . ' Squash Clinic (Waitlist #' . $position . ')';
                    $date_str   = date( 'M j, Y', strtotime( $slot->slot_date ) ) . ' at ' . date( 'g:i A', strtotime( $slot->start_time ) );
                    $order_info = PaymentManager::create_order_for_booking( $new_booking, "{$cat_label} — {$date_str}", $price );
                    $checkout_url = $order_info['checkout_url'];
                }

                return new \WP_REST_Response( [
                    'success'       => true,
                    'is_waitlist'   => true,
                    'booking_ref'   => $booking_ref,
                    'booking_id'    => $booking_id,
                    'checkout_url'  => $checkout_url,
                    'position'      => $position,
                    'message'       => $checkout_url 
                        ? sprintf( __( 'You have been registered for the waitlist (Position #%d). Redirecting to payment...', 'squash-club-booking' ), $position )
                        : sprintf( __( 'You have been added to the waitlist (Position #%d). We will notify you if a spot opens up!', 'squash-club-booking' ), $position ),
                ] );
            }

            // ── Normal Available Booking ──────────────────────────────────────────
            $auto_approve     = ! Settings::get( 'require_admin_approval', false );
            $initial_b_status = $price > 0 ? 'pending' : ( $auto_approve ? 'approved' : 'pending' );
            $initial_p_status = $price > 0 ? 'unpaid' : 'paid';

            $wpdb->insert(
                $wpdb->prefix . 'scb_bookings',
                [
                    'booking_ref'       => $booking_ref,
                    'service_type'      => 'clinic',
                    'customer_user_id'  => $current_user_id,
                    'customer_name'     => $customer_name,
                    'customer_email'    => $customer_email,
                    'customer_phone'    => $customer_phone,
                    'clinic_slot_id'    => $slot_id,
                    'participant_count' => 1,
                    'booking_status'    => $initial_b_status,
                    'payment_status'    => $initial_p_status,
                    'amount'            => $price,
                    'notes'             => $notes,
                    'ip_address'        => $ip_address,
                    'created_at'        => current_time( 'mysql' ),
                ],
                [ '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%f', '%s', '%s', '%s' ]
            );

            $booking_id = $wpdb->insert_id;

            // Participant record.
            $wpdb->insert(
                $wpdb->prefix . 'scb_booking_participants',
                [
                    'booking_id'  => $booking_id,
                    'first_name'  => $part_first ?: $customer_name,
                    'last_name'   => $part_last ?: '',
                    'age'         => $part_age ?: null,
                    'skill_level' => $skill_level,
                    'notes'       => $notes,
                    'created_at'  => current_time( 'mysql' ),
                ],
                [ '%d', '%s', '%s', '%d', '%s', '%s', '%s' ]
            );

            // Only increment booked_count immediately if booking is free ($price <= 0).
            // For paid bookings, spot is reserved upon successful payment via PaymentModule.
            if ( $price <= 0 ) {
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}scb_clinic_slots
                     SET booked_count = booked_count + 1,
                         status = IF(booked_count >= capacity, 'full', status)
                     WHERE id = %d",
                    $slot_id
                ) );
            }

            $wpdb->query( 'COMMIT' );

        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            error_log( 'SCB Clinic Booking Exception: ' . $e->getMessage() );
            return new \WP_REST_Response( [
                'success' => false,
                'message' => __( 'A database error occurred while reserving your spot. Please try again.', 'squash-club-booking' ),
            ], 500 );
        }

        $new_booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}scb_bookings WHERE id = %d", $booking_id ) );

        // WooCommerce order creation if price > 0.
        $checkout_url = '';
        if ( $price > 0 && PaymentManager::is_available() ) {
            $cat_label = ucfirst( $slot->category ) . ' Squash Clinic';
            $date_str  = date( 'M j, Y', strtotime( $slot->slot_date ) ) . ' at ' . date( 'g:i A', strtotime( $slot->start_time ) );
            $order_info = PaymentManager::create_order_for_booking( $new_booking, "{$cat_label} — {$date_str}", $price );
            $checkout_url = $order_info['checkout_url'];
        }

        // Email notifications.
        $time_display = date( 'g:i A', strtotime( $slot->start_time ) ) . ' - ' . date( 'g:i A', strtotime( $slot->end_time ) );
        $extra_details = '<div class="info-row"><span class="info-label">' . __( 'Date & Time', 'squash-club-booking' ) . '</span><span class="info-val">' . date( 'l, M j, Y', strtotime( $slot->slot_date ) ) . ', ' . $time_display . '</span></div>';
        $extra_details .= '<div class="info-row"><span class="info-label">' . __( 'Clinic Type', 'squash-club-booking' ) . '</span><span class="info-val">' . ucfirst( $slot->category ) . ' Clinic</span></div>';

        NotificationModule::send_booking_confirmation( $new_booking, $extra_details );
        NotificationModule::notify_admin_new_booking( $new_booking, $extra_details );

        AuditLog::log(
            'booking_created',
            'booking',
            $booking_id,
            [],
            [ 'ref' => $booking_ref, 'service' => 'clinic', 'slot_id' => $slot_id ]
        );

        return new \WP_REST_Response( [
            'success'      => true,
            'booking_ref'  => $booking_ref,
            'booking_id'   => $booking_id,
            'checkout_url' => $checkout_url,
            'status'       => $initial_b_status,
            'message'      => __( 'Your clinic spot has been reserved successfully!', 'squash-club-booking' ),
        ] );
    }
}
