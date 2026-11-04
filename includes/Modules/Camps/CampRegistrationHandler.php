<?php
namespace SquashClubBooking\Modules\Camps;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use SquashClubBooking\Core\Settings;
use SquashClubBooking\Core\AuditLog;
use SquashClubBooking\Modules\Payments\PaymentManager;
use SquashClubBooking\Modules\Notifications\NotificationModule;

/**
 * Handles summer camp registration submissions.
 *
 * @package SquashClubBooking\Modules\Camps
 */
class CampRegistrationHandler {

    /**
     * Handle camp registration REST request.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public static function handle_registration( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        // Enforce login requirement: guest registrations are strictly disallowed.
        if ( ! is_user_logged_in() ) {
            $login_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url();
            return new \WP_REST_Response( [
                'success'      => false,
                'require_auth' => true,
                'login_url'    => $login_url,
                'message'      => __( 'You must be logged in to register for a summer camp. Please log in or create an account.', 'squash-club-booking' ),
            ], 401 );
        }

        $current_user    = wp_get_current_user();
        $current_user_id = $current_user->ID;

        $camp_id           = absint( $request->get_param( 'camp_id' ) );
        $student_name      = sanitize_text_field( $request->get_param( 'student_name' ) ?? '' );
        $student_dob       = sanitize_text_field( $request->get_param( 'student_dob' ) ?? '' );
        $student_age       = absint( $request->get_param( 'student_age' ) ?? 0 );
        $skill_level       = sanitize_text_field( $request->get_param( 'skill_level' ) ?? 'all_levels' );
        $parent_name       = sanitize_text_field( $request->get_param( 'parent_name' ) ?? '' );
        $parent_email      = sanitize_email( $request->get_param( 'parent_email' ) ?? '' );
        $parent_phone      = sanitize_text_field( $request->get_param( 'parent_phone' ) ?? '' );
        $emergency_contact = sanitize_textarea_field( $request->get_param( 'emergency_contact' ) ?? '' );
        $notes             = sanitize_textarea_field( $request->get_param( 'additional_notes' ) ?? '' );
        $consents_input    = $request->get_param( 'consents' );

        // Fallback to user account info if parent info left empty.
        if ( empty( $parent_name ) ) {
            $parent_name = trim( $current_user->first_name . ' ' . $current_user->last_name ) ?: $current_user->display_name;
        }
        if ( empty( $parent_email ) ) {
            $parent_email = $current_user->user_email;
        }
        if ( empty( $parent_phone ) ) {
            $parent_phone = get_user_meta( $current_user_id, 'billing_phone', true );
        }

        // Validation.
        if ( ! $camp_id ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Please select a summer camp.', 'squash-club-booking' ) ], 400 );
        }
        if ( empty( $student_name ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Please provide the student\'s name.', 'squash-club-booking' ) ], 400 );
        }
        if ( empty( $parent_name ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Please provide parent/guardian name.', 'squash-club-booking' ) ], 400 );
        }
        if ( ! is_email( $parent_email ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Please provide a valid parent email address.', 'squash-club-booking' ) ], 400 );
        }

        $wpdb->query( 'START TRANSACTION' );

        try {
            // Fetch camp with row lock to eliminate race conditions.
            $camp = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}scb_camps WHERE id = %d AND publication_status = 'published' FOR UPDATE",
                $camp_id
            ) );

            if ( ! $camp ) {
                $wpdb->query( 'ROLLBACK' );
                return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'The selected summer camp is not available.', 'squash-club-booking' ) ], 404 );
            }

            // Status & Capacity.
            if ( $camp->registration_status === 'closed' || $camp->registration_status === 'cancelled' ) {
                $wpdb->query( 'ROLLBACK' );
                return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Registration is closed for this camp.', 'squash-club-booking' ) ], 400 );
            }

            $current_active = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}scb_camp_registrations WHERE camp_id = %d AND registration_status != 'cancelled'",
                $camp_id
            ) );

            if ( $current_active >= (int) $camp->capacity || $camp->registration_status === 'full' ) {
                $wpdb->query( 'ROLLBACK' );
                return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'This camp has reached maximum capacity.', 'squash-club-booking' ) ], 400 );
            }

            // Deadline check.
            if ( ! empty( $camp->registration_deadline ) ) {
                if ( current_time( 'Y-m-d' ) > $camp->registration_deadline ) {
                    $wpdb->query( 'ROLLBACK' );
                    return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'The registration deadline for this camp has passed.', 'squash-club-booking' ) ], 400 );
                }
            }

            // Age eligibility check.
            if ( $student_age > 0 ) {
                if ( $camp->min_age && $student_age < (int) $camp->min_age ) {
                    $wpdb->query( 'ROLLBACK' );
                    return new \WP_REST_Response( [ 'success' => false, 'message' => sprintf( __( 'Student must be at least %d years old for this camp.', 'squash-club-booking' ), $camp->min_age ) ], 400 );
                }
                if ( $camp->max_age && $student_age > (int) $camp->max_age ) {
                    $wpdb->query( 'ROLLBACK' );
                    return new \WP_REST_Response( [ 'success' => false, 'message' => sprintf( __( 'Student must be at most %d years old for this camp.', 'squash-club-booking' ), $camp->max_age ) ], 400 );
                }
            }

            // Duplicate submission prevention.
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}scb_camp_registrations
                 WHERE camp_id = %d AND parent_email = %s AND student_name = %s AND registration_status != 'cancelled'
                 LIMIT 1",
                $camp_id,
                $parent_email,
                $student_name
            ) );
            if ( $existing ) {
                $wpdb->query( 'ROLLBACK' );
                return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'A registration for this student has already been submitted under this email address.', 'squash-club-booking' ) ], 400 );
            }

            // Unique reference: SCB-CMP-YYYYMMDD-XXXX.
            $date_tag = date( 'Ymd' );
            $rand_tag = strtoupper( wp_generate_password( 4, false ) );
            $reg_ref  = "SCB-CMP-{$date_tag}-{$rand_tag}";

            $price           = floatval( $camp->price );
            $auto_approve    = ! Settings::get( 'require_admin_approval', false );
            $reg_status      = $price > 0 ? 'pending' : ( $auto_approve ? 'confirmed' : 'pending' );
            $pay_status      = $price > 0 ? 'unpaid' : 'paid';
            $consents_json   = is_array( $consents_input ) ? wp_json_encode( $consents_input ) : ( $consents_input ? wp_json_encode( [ $consents_input ] ) : '[]' );
            $ip_address      = AuditLog::get_client_ip();

            $wpdb->insert(
                $wpdb->prefix . 'scb_camp_registrations',
                [
                    'registration_ref'    => $reg_ref,
                    'camp_id'             => $camp_id,
                    'customer_user_id'    => $current_user_id,
                    'student_name'        => $student_name,
                    'student_dob'         => ! empty( $student_dob ) ? $student_dob : null,
                    'student_age'         => $student_age ?: null,
                    'skill_level'         => $skill_level,
                    'parent_name'         => $parent_name,
                    'parent_email'        => $parent_email,
                    'parent_phone'        => $parent_phone,
                    'emergency_contact'   => $emergency_contact,
                    'additional_notes'    => $notes,
                    'consents'            => $consents_json,
                    'registration_status' => $reg_status,
                    'payment_status'      => $pay_status,
                    'amount'              => $price,
                    'ip_address'          => $ip_address,
                    'created_at'          => current_time( 'mysql' ),
                ],
                [ '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s' ]
            );

            $reg_id = $wpdb->insert_id;

            // Synchronize camp registered count and status from real registrations.
            $new_count  = $current_active + 1;
            $new_status = ( $new_count >= (int) $camp->capacity ) ? 'full' : $camp->registration_status;
            $wpdb->update(
                $wpdb->prefix . 'scb_camps',
                [
                    'registered_count'    => $new_count,
                    'registration_status' => $new_status,
                ],
                [ 'id' => $camp_id ],
                [ '%d', '%s' ],
                [ '%d' ]
            );

            $wpdb->query( 'COMMIT' );

        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            error_log( 'SCB Camp Registration Exception: ' . $e->getMessage() );
            return new \WP_REST_Response( [
                'success' => false,
                'message' => __( 'A database error occurred while registering for the camp. Please try again.', 'squash-club-booking' ),
            ], 500 );
        }

        $new_reg = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}scb_camp_registrations WHERE id = %d", $reg_id ) );

        // WooCommerce order creation if price > 0.
        $checkout_url = '';
        if ( $price > 0 && PaymentManager::is_available() ) {
            $order_info   = PaymentManager::create_order_for_camp( $new_reg, $camp );
            $checkout_url = $order_info['checkout_url'];
        }

        // Email notifications.
        NotificationModule::send_camp_confirmation( $new_reg, $camp );

        AuditLog::log(
            'camp_registered',
            'camp_registration',
            $reg_id,
            [],
            [ 'ref' => $reg_ref, 'camp_id' => $camp_id, 'student' => $student_name ]
        );

        return new \WP_REST_Response( [
            'success'          => true,
            'registration_ref' => $reg_ref,
            'registration_id'  => $reg_id,
            'checkout_url'     => $checkout_url,
            'status'           => $reg_status,
            'message'          => __( 'Summer camp registration submitted successfully!', 'squash-club-booking' ),
        ] );
    }
}
