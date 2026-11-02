<?php
namespace SquashClubBooking\Modules\Lessons;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use SquashClubBooking\Core\Settings;
use SquashClubBooking\Core\AuditLog;
use SquashClubBooking\Modules\Payments\PaymentManager;
use SquashClubBooking\Modules\Notifications\NotificationModule;

/**
 * Handles private and semi-private lesson booking submissions.
 *
 * @package SquashClubBooking\Modules\Lessons
 */
class LessonBookingHandler {

    /**
     * Handle lesson booking REST request.
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
                'message'      => __( 'You must be logged in to book a private lesson. Please log in or create an account.', 'squash-club-booking' ),
            ], 401 );
        }

        $current_user    = wp_get_current_user();
        $current_user_id = $current_user->ID;

        $coach_id        = absint( $request->get_param( 'coach_id' ) );
        $lesson_type     = sanitize_key( $request->get_param( 'lesson_type' ) ?? 'private' ); // 'private' or 'semi_private'
        $lesson_date     = sanitize_text_field( $request->get_param( 'lesson_date' ) ?? '' );
        $start_time_raw  = sanitize_text_field( $request->get_param( 'start_time' ) ?? '' );
        $customer_name   = sanitize_text_field( $request->get_param( 'customer_name' ) ?? '' );
        $customer_email  = sanitize_email( $request->get_param( 'customer_email' ) ?? '' );
        $customer_phone  = sanitize_text_field( $request->get_param( 'customer_phone' ) ?? '' );
        $participant_1   = sanitize_text_field( $request->get_param( 'participant_1' ) ?? $customer_name );
        $participant_2   = sanitize_text_field( $request->get_param( 'participant_2' ) ?? '' );
        $skill_level     = sanitize_text_field( $request->get_param( 'skill_level' ) ?? 'all_levels' );
        $notes           = sanitize_textarea_field( $request->get_param( 'notes' ) ?? '' );

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
        if ( ! $coach_id ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Please select a coach.', 'squash-club-booking' ) ], 400 );
        }
        if ( ! in_array( $lesson_type, [ 'private', 'semi_private' ], true ) ) {
            $lesson_type = 'private';
        }
        if ( empty( $lesson_date ) || ! strtotime( $lesson_date ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Please select a valid date.', 'squash-club-booking' ) ], 400 );
        }
        if ( empty( $start_time_raw ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Please select a start time.', 'squash-club-booking' ) ], 400 );
        }
        if ( empty( $customer_name ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Please provide your full name.', 'squash-club-booking' ) ], 400 );
        }
        if ( ! is_email( $customer_email ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Please provide a valid email address.', 'squash-club-booking' ) ], 400 );
        }

        // Format times.
        $start_time = date( 'H:i:00', strtotime( $start_time_raw ) );
        $duration   = absint( Settings::get( 'lesson_duration', 60 ) ); // in minutes
        $end_time   = date( 'H:i:00', strtotime( "+{$duration} minutes", strtotime( $start_time ) ) );

        $wpdb->query( 'START TRANSACTION' );

        try {
            // Check coach exists and is active.
            $coach = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}scb_coaches WHERE id = %d AND status = 'active'",
                $coach_id
            ) );

            if ( ! $coach ) {
                $wpdb->query( 'ROLLBACK' );
                return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'The selected coach is unavailable.', 'squash-club-booking' ) ], 404 );
            }

            // Conflict check: Overlapping confirmed/paid bookings for the coach on that date.
            $conflict = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}scb_bookings
                 WHERE coach_id = %d
                   AND lesson_date = %s
                   AND booking_status NOT IN ('cancelled', 'rejected')
                   AND (payment_status = 'paid' OR amount = 0)
                   AND (lesson_start_time < %s AND lesson_end_time > %s)
                 LIMIT 1",
                $coach_id,
                $lesson_date,
                $end_time,
                $start_time
            ) );

            if ( $conflict ) {
                $wpdb->query( 'ROLLBACK' );
                return new \WP_REST_Response( [
                    'success' => false,
                    'message' => __( 'This time slot is no longer available for the selected coach. Please pick another time.', 'squash-club-booking' ),
                ], 400 );
            }

            // Pricing.
            $default_priv_price = floatval( Settings::get( 'lesson_price_private', Settings::get( 'private_lesson_price', 80.00 ) ) );
            $default_semi_price = floatval( Settings::get( 'lesson_price_semi_private', Settings::get( 'semi_private_lesson_price', 40.00 ) ) );

            // Check if coach has custom rates.
            $coach_priv_rate = ( ! empty( $coach->hourly_rate ) && floatval( $coach->hourly_rate ) > 0 )
                ? floatval( $coach->hourly_rate )
                : null;
            $coach_semi_rate = ( ! empty( $coach->semi_private_rate ) && floatval( $coach->semi_private_rate ) > 0 )
                ? floatval( $coach->semi_private_rate )
                : null;

            if ( $lesson_type === 'semi_private' ) {
                $service_type = 'semi_private_lesson';
                if ( $coach_semi_rate !== null ) {
                    $price = $coach_semi_rate;
                } elseif ( $coach_priv_rate !== null ) {
                    $ratio = ($default_priv_price > 0) ? ($default_semi_price / $default_priv_price) : 1.38;
                    $price = round( $coach_priv_rate * $ratio, 2 );
                } else {
                    $price = $default_semi_price;
                }
                $part_count   = ! empty( $participant_2 ) ? 2 : 1;
            } else {
                $service_type = 'private_lesson';
                $price        = ( $coach_priv_rate !== null ) ? $coach_priv_rate : $default_priv_price;
                $part_count   = 1;
            }

            // Reference: SCB-LES-YYYYMMDD-XXXX.
            $date_tag    = date( 'Ymd', strtotime( $lesson_date ) );
            $rand_tag    = strtoupper( wp_generate_password( 4, false ) );
            $booking_ref = "SCB-LES-{$date_tag}-{$rand_tag}";

            $auto_approve     = ! Settings::get( 'require_admin_approval', false );
            $initial_b_status = $price > 0 ? 'pending' : ( $auto_approve ? 'approved' : 'pending' );
            $initial_p_status = $price > 0 ? 'unpaid' : 'paid';
            $ip_address       = AuditLog::get_client_ip();

            $wpdb->insert(
                $wpdb->prefix . 'scb_bookings',
                [
                    'booking_ref'       => $booking_ref,
                    'service_type'      => $service_type,
                    'customer_user_id'  => $current_user_id,
                    'customer_name'     => $customer_name,
                    'customer_email'    => $customer_email,
                    'customer_phone'    => $customer_phone,
                    'coach_id'          => $coach_id,
                    'lesson_date'       => $lesson_date,
                    'lesson_start_time' => $start_time,
                    'lesson_end_time'   => $end_time,
                    'participant_count' => $part_count,
                    'booking_status'    => $initial_b_status,
                    'payment_status'    => $initial_p_status,
                    'amount'            => $price,
                    'notes'             => $notes,
                    'ip_address'        => $ip_address,
                    'created_at'        => current_time( 'mysql' ),
                ],
                [ '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%f', '%s', '%s', '%s' ]
            );

            $booking_id = $wpdb->insert_id;

            // Participants.
            $wpdb->insert(
                $wpdb->prefix . 'scb_booking_participants',
                [
                    'booking_id'  => $booking_id,
                    'first_name'  => $participant_1,
                    'last_name'   => '',
                    'skill_level' => $skill_level,
                    'notes'       => $notes,
                    'created_at'  => current_time( 'mysql' ),
                ],
                [ '%d', '%s', '%s', '%s', '%s', '%s' ]
            );

            if ( $part_count === 2 && ! empty( $participant_2 ) ) {
                $wpdb->insert(
                    $wpdb->prefix . 'scb_booking_participants',
                    [
                        'booking_id'  => $booking_id,
                        'first_name'  => $participant_2,
                        'last_name'   => '',
                        'skill_level' => $skill_level,
                        'notes'       => '',
                        'created_at'  => current_time( 'mysql' ),
                    ],
                    [ '%d', '%s', '%s', '%s', '%s', '%s' ]
                );
            }

            $wpdb->query( 'COMMIT' );

        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            error_log( 'SCB Lesson Booking Exception: ' . $e->getMessage() );
            return new \WP_REST_Response( [
                'success' => false,
                'message' => __( 'A database error occurred while reserving your lesson. Please try again.', 'squash-club-booking' ),
            ], 500 );
        }

        $new_booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}scb_bookings WHERE id = %d", $booking_id ) );

        // WooCommerce order.
        $checkout_url = '';
        if ( $price > 0 && PaymentManager::is_available() ) {
            $type_label = $lesson_type === 'semi_private' ? 'Semi-Private Lesson' : 'Private Lesson';
            $coach_name = $coach->first_name . ' ' . $coach->last_name;
            $date_str   = date( 'M j, Y', strtotime( $lesson_date ) ) . ' @ ' . date( 'g:i A', strtotime( $start_time ) );
            $order_info = PaymentManager::create_order_for_booking( $new_booking, "{$type_label} with {$coach_name} — {$date_str}", $price );
            $checkout_url = $order_info['checkout_url'];
        }

        // Email notifications.
        $coach_full_name = esc_html( $coach->first_name . ' ' . $coach->last_name );
        $time_display    = date( 'g:i A', strtotime( $start_time ) ) . ' - ' . date( 'g:i A', strtotime( $end_time ) );
        $extra_details   = '<div class="info-row"><span class="info-label">' . __( 'Coach', 'squash-club-booking' ) . '</span><span class="info-val">' . $coach_full_name . '</span></div>';
        $extra_details  .= '<div class="info-row"><span class="info-label">' . __( 'Date & Time', 'squash-club-booking' ) . '</span><span class="info-val">' . date( 'l, M j, Y', strtotime( $lesson_date ) ) . ', ' . $time_display . '</span></div>';
        $extra_details  .= '<div class="info-row"><span class="info-label">' . __( 'Lesson Type', 'squash-club-booking' ) . '</span><span class="info-val">' . ( $lesson_type === 'semi_private' ? __( 'Semi-Private (2 players)', 'squash-club-booking' ) : __( 'Private (1 player)', 'squash-club-booking' ) ) . '</span></div>';

        NotificationModule::send_booking_confirmation( $new_booking, $extra_details );
        NotificationModule::notify_admin_new_booking( $new_booking, $extra_details );

        AuditLog::log(
            'lesson_booked',
            'booking',
            $booking_id,
            [],
            [ 'ref' => $booking_ref, 'coach_id' => $coach_id, 'date' => $lesson_date, 'start_time' => $start_time ]
        );

        return new \WP_REST_Response( [
            'success'      => true,
            'booking_ref'  => $booking_ref,
            'booking_id'   => $booking_id,
            'checkout_url' => $checkout_url,
            'status'       => $initial_b_status,
            'message'      => __( 'Your lesson has been booked successfully!', 'squash-club-booking' ),
        ] );
    }
}
