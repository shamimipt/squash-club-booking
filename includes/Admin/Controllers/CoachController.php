<?php
namespace SquashClubBooking\Admin\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use SquashClubBooking\Core\AuditLog;
use SquashClubBooking\Core\Settings;

/**
 * Controller for Coaches management in the admin dashboard.
 *
 * @package SquashClubBooking\Admin\Controllers
 */
class CoachController {

    /**
     * List all coaches.
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public static function index( \WP_REST_Request $r ): \WP_REST_Response {
        global $wpdb;

        $status = sanitize_key( $r->get_param( 'status' ) ?? '' );
        $where  = '1=1';
        $params = [];

        if ( ! empty( $status ) && $status !== 'all' ) {
            $where .= ' AND status = %s';
            $params[] = $status;
        }

        $orderby_param = sanitize_key( $r->get_param( 'orderby' ) ?: $r->get_param( 'sort_by' ) ?: 'display_order' );
        $order_param   = strtoupper( sanitize_key( $r->get_param( 'order' ) ?: $r->get_param( 'sort_dir' ) ?: 'ASC' ) );
        if ( ! in_array( $order_param, [ 'ASC', 'DESC' ], true ) ) {
            $order_param = 'ASC';
        }

        $allowed_sorts = [
            'id'                   => 'c.id',
            'name'                 => 'c.last_name',
            'first_name'           => 'c.first_name',
            'last_name'            => 'c.last_name',
            'email'                => 'c.email',
            'status'               => 'c.status',
            'display_order'        => 'c.display_order',
            'hourly_rate'          => 'c.hourly_rate',
            'total_bookings_count' => 'total_bookings_count',
            'created_at'           => 'c.created_at',
        ];

        $order_by_sql = $allowed_sorts[ $orderby_param ] ?? 'c.display_order';
        $secondary_order = ( $order_by_sql === 'c.display_order' ) ? ', c.last_name ASC' : '';

        $sql = "SELECT c.*,
                       (SELECT COUNT(*) FROM {$wpdb->prefix}scb_bookings b WHERE b.coach_id = c.id AND b.booking_status NOT IN ('cancelled', 'rejected')) AS total_bookings_count
                FROM {$wpdb->prefix}scb_coaches c
                WHERE {$where}
                ORDER BY {$order_by_sql} {$order_param}{$secondary_order}";

        $items = $wpdb->get_results( empty( $params ) ? $sql : $wpdb->prepare( $sql, $params ), ARRAY_A );

        return rest_ensure_response( [
            'success' => true,
            'data'    => [ 'items' => $items ?: [] ],
        ] );
    }

    /**
     * Show single coach with availability blocks.
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public static function show( \WP_REST_Request $r ): \WP_REST_Response {
        global $wpdb;

        $id = absint( $r->get_param( 'id' ) );
        $c  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}scb_coaches WHERE id = %d", $id ), ARRAY_A );

        if ( ! $c ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Coach not found.', 'squash-club-booking' ) ], 404 );
        }

        $availability = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}scb_coach_availability WHERE coach_id = %d ORDER BY day_of_week ASC, start_time ASC",
            $id
        ), ARRAY_A );

        $c['availability'] = $availability ?: [];

        return rest_ensure_response( [ 'success' => true, 'data' => $c ] );
    }

    /**
     * Create a new coach profile.
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public static function create( \WP_REST_Request $r ): \WP_REST_Response {
        global $wpdb;

        $first_name   = sanitize_text_field( $r->get_param( 'first_name' ) ?? '' );
        $last_name    = sanitize_text_field( $r->get_param( 'last_name' ) ?? '' );
        $email        = sanitize_email( $r->get_param( 'email' ) ?? '' );
        $phone        = sanitize_text_field( $r->get_param( 'phone' ) ?? '' );
        $photo_url    = esc_url_raw( $r->get_param( 'photo_url' ) ?? '' );
        $bio          = sanitize_textarea_field( $r->get_param( 'bio' ) ?? '' );
        $specialties  = sanitize_text_field( $r->get_param( 'specialties' ) ?? '' );
        $lesson_types = sanitize_text_field( $r->get_param( 'lesson_types' ) ?? 'private,semi_private' );
        $status       = sanitize_key( $r->get_param( 'status' ) ?? 'active' );
        $order        = absint( $r->get_param( 'display_order' ) ?? 0 );
        $hourly_rate       = ( $r->get_param( 'hourly_rate' ) !== null && $r->get_param( 'hourly_rate' ) !== '' ) ? floatval( $r->get_param( 'hourly_rate' ) ) : null;
        $semi_private_rate = ( $r->get_param( 'semi_private_rate' ) !== null && $r->get_param( 'semi_private_rate' ) !== '' ) ? floatval( $r->get_param( 'semi_private_rate' ) ) : null;

        if ( empty( $first_name ) || empty( $last_name ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Please provide both first and last name.', 'squash-club-booking' ) ], 400 );
        }

        $slug_base = sanitize_title( "{$first_name}-{$last_name}" );
        $slug      = $slug_base;
        $counter   = 1;
        while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}scb_coaches WHERE slug = %s", $slug ) ) ) {
            $slug = "{$slug_base}-{$counter}";
            $counter++;
        }

        $wpdb->insert(
            $wpdb->prefix . 'scb_coaches',
            [
                'first_name'    => $first_name,
                'last_name'     => $last_name,
                'slug'          => $slug,
                'email'         => $email,
                'phone'         => $phone,
                'photo_url'     => $photo_url,
                'bio'           => $bio,
                'specialties'   => $specialties,
                'lesson_types'  => $lesson_types,
                'status'        => $status,
                'hourly_rate'       => $hourly_rate,
                'semi_private_rate' => $semi_private_rate,
                'display_order'     => $order,
                'created_at'        => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%d', '%s' ]
        );

        $coach_id = $wpdb->insert_id;

        // Create standard Saturday availability block (day 6: 09:00 to 17:00).
        $wpdb->insert(
            $wpdb->prefix . 'scb_coach_availability',
            [
                'coach_id'       => $coach_id,
                'effective_date' => current_time( 'Y-m-d' ),
                'day_of_week'    => 6, // Saturday
                'start_time'     => '09:00:00',
                'end_time'       => '17:00:00',
                'lesson_type'    => 'both',
                'is_blocked'     => 0,
                'notes'          => __( 'Saturday Standard Hours', 'squash-club-booking' ),
                'created_at'     => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );

        AuditLog::log( 'coach_created', 'coach', $coach_id, [], [ 'name' => "{$first_name} {$last_name}", 'slug' => $slug ] );

        return rest_ensure_response( [
            'success'  => true,
            'coach_id' => $coach_id,
            'message'  => __( 'Coach profile created successfully.', 'squash-club-booking' ),
        ] );
    }

    /**
     * Update coach profile.
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public static function update( \WP_REST_Request $r ): \WP_REST_Response {
        global $wpdb;

        $id     = absint( $r->get_param( 'id' ) );
        $params = $r->get_json_params() ?: [];

        $coach = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}scb_coaches WHERE id = %d", $id ) );
        if ( ! $coach ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Coach not found.', 'squash-club-booking' ) ], 404 );
        }

        $fields  = [];
        $formats = [];

        if ( isset( $params['first_name'] ) ) {
            $fields['first_name'] = sanitize_text_field( $params['first_name'] );
            $formats[] = '%s';
        }
        if ( isset( $params['last_name'] ) ) {
            $fields['last_name'] = sanitize_text_field( $params['last_name'] );
            $formats[] = '%s';
        }
        if ( isset( $params['email'] ) ) {
            $fields['email'] = sanitize_email( $params['email'] );
            $formats[] = '%s';
        }
        if ( isset( $params['phone'] ) ) {
            $fields['phone'] = sanitize_text_field( $params['phone'] );
            $formats[] = '%s';
        }
        if ( isset( $params['photo_url'] ) ) {
            $fields['photo_url'] = esc_url_raw( $params['photo_url'] );
            $formats[] = '%s';
        }
        if ( isset( $params['bio'] ) ) {
            $fields['bio'] = sanitize_textarea_field( $params['bio'] );
            $formats[] = '%s';
        }
        if ( isset( $params['specialties'] ) ) {
            $fields['specialties'] = sanitize_text_field( $params['specialties'] );
            $formats[] = '%s';
        }
        if ( isset( $params['lesson_types'] ) ) {
            $fields['lesson_types'] = sanitize_text_field( $params['lesson_types'] );
            $formats[] = '%s';
        }
        if ( isset( $params['status'] ) && in_array( $params['status'], [ 'active', 'inactive', 'on_leave' ], true ) ) {
            $fields['status'] = $params['status'];
            $formats[] = '%s';
        }
        if ( isset( $params['display_order'] ) ) {
            $fields['display_order'] = absint( $params['display_order'] );
            $formats[] = '%d';
        }
        if ( array_key_exists( 'hourly_rate', $params ) ) {
            if ( $params['hourly_rate'] === '' || $params['hourly_rate'] === null ) {
                $fields['hourly_rate'] = null;
                $formats[] = '%s';
            } else {
                $fields['hourly_rate'] = floatval( $params['hourly_rate'] );
                $formats[] = '%f';
            }
        }
        if ( array_key_exists( 'semi_private_rate', $params ) ) {
            if ( $params['semi_private_rate'] === '' || $params['semi_private_rate'] === null ) {
                $fields['semi_private_rate'] = null;
                $formats[] = '%s';
            } else {
                $fields['semi_private_rate'] = floatval( $params['semi_private_rate'] );
                $formats[] = '%f';
            }
        }

        if ( empty( $fields ) ) {
            return rest_ensure_response( [ 'success' => true, 'message' => __( 'No changes to update.', 'squash-club-booking' ) ] );
        }

        $fields['updated_at'] = current_time( 'mysql' );
        $formats[] = '%s';

        $wpdb->update( $wpdb->prefix . 'scb_coaches', $fields, [ 'id' => $id ], $formats, [ '%d' ] );

        AuditLog::log( 'coach_updated', 'coach', $id, (array) $coach, $fields );

        return rest_ensure_response( [
            'success' => true,
            'message' => __( 'Coach updated successfully.', 'squash-club-booking' ),
        ] );
    }

    /**
     * Delete coach profile.
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public static function delete( \WP_REST_Request $r ): \WP_REST_Response {
        global $wpdb;

        $id    = absint( $r->get_param( 'id' ) );
        $coach = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}scb_coaches WHERE id = %d", $id ) );

        if ( ! $coach ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Coach not found.', 'squash-club-booking' ) ], 404 );
        }

        $has_active = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}scb_bookings WHERE coach_id = %d AND booking_status NOT IN ('cancelled', 'rejected')",
            $id
        ) );

        if ( $has_active > 0 ) {
            return new \WP_REST_Response( [
                'success' => false,
                'message' => sprintf( __( 'Cannot delete coach: there are %d active booking(s) assigned to this coach. Please reassign or cancel them first.', 'squash-club-booking' ), $has_active ),
            ], 400 );
        }

        $wpdb->delete( $wpdb->prefix . 'scb_coach_availability', [ 'coach_id' => $id ], [ '%d' ] );
        $wpdb->delete( $wpdb->prefix . 'scb_coaches', [ 'id' => $id ], [ '%d' ] );

        AuditLog::log( 'coach_deleted', 'coach', $id, (array) $coach, [] );

        return rest_ensure_response( [
            'success' => true,
            'message' => __( 'Coach deleted successfully.', 'squash-club-booking' ),
        ] );
    }

    /**
     * Get coach availability blocks.
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public static function get_availability( \WP_REST_Request $r ): \WP_REST_Response {
        global $wpdb;

        $id = absint( $r->get_param( 'id' ) );
        $items = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}scb_coach_availability WHERE coach_id = %d ORDER BY day_of_week ASC, start_time ASC",
            $id
        ), ARRAY_A );

        return rest_ensure_response( [
            'success' => true,
            'data'    => [ 'items' => $items ?: [] ],
        ] );
    }

    /**
     * Save/update coach availability schedule blocks.
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public static function save_availability( \WP_REST_Request $r ): \WP_REST_Response {
        global $wpdb;

        $id     = absint( $r->get_param( 'id' ) );
        $blocks = $r->get_param( 'blocks' );

        $coach = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}scb_coaches WHERE id = %d", $id ) );
        if ( ! $coach ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Coach not found.', 'squash-club-booking' ) ], 404 );
        }

        if ( ! is_array( $blocks ) ) {
            $blocks = [];
        }

        // Delete existing availability blocks.
        $wpdb->delete( $wpdb->prefix . 'scb_coach_availability', [ 'coach_id' => $id ], [ '%d' ] );

        $table = $wpdb->prefix . 'scb_coach_availability';
        $today = current_time( 'Y-m-d' );

        foreach ( $blocks as $b ) {
            $dow   = absint( $b['day_of_week'] ?? 6 );
            $start = sanitize_text_field( $b['start_time'] ?? '09:00' );
            $end   = sanitize_text_field( $b['end_time'] ?? '17:00' );
            $type  = sanitize_key( $b['lesson_type'] ?? 'both' );
            $block = ! empty( $b['is_blocked'] ) ? 1 : 0;
            $notes = sanitize_text_field( $b['notes'] ?? '' );

            $start_fmt = date( 'H:i:00', strtotime( $start ) );
            $end_fmt   = date( 'H:i:00', strtotime( $end ) );

            $wpdb->insert(
                $table,
                [
                    'coach_id'       => $id,
                    'effective_date' => $today,
                    'day_of_week'    => $dow,
                    'start_time'     => $start_fmt,
                    'end_time'       => $end_fmt,
                    'lesson_type'    => in_array( $type, [ 'private', 'semi_private', 'both' ], true ) ? $type : 'both',
                    'is_blocked'     => $block,
                    'notes'          => $notes,
                    'created_at'     => current_time( 'mysql' ),
                ],
                [ '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' ]
            );
        }

        AuditLog::log( 'coach_availability_updated', 'coach', $id, [], [ 'blocks_count' => count( $blocks ) ] );

        return rest_ensure_response( [
            'success' => true,
            'message' => __( 'Coach availability saved successfully.', 'squash-club-booking' ),
        ] );
    }

    /**
     * Compute available time slots for a coach on a specific date.
     * Publicly callable by frontend booking widget.
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public static function available_slots( \WP_REST_Request $r ): \WP_REST_Response {
        global $wpdb;

        $id          = absint( $r->get_param( 'id' ) );
        $date        = sanitize_text_field( $r->get_param( 'date' ) ?? '' );
        $lesson_type = sanitize_key( $r->get_param( 'lesson_type' ) ?? 'private' );

        if ( empty( $date ) || ! strtotime( $date ) ) {
            $date = date( 'Y-m-d', strtotime( 'next saturday' ) );
        }

        $dow = (int) date( 'w', strtotime( $date ) ); // 0=Sun, 6=Sat

        // Coach check.
        $coach = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}scb_coaches WHERE id = %d AND status = 'active'",
            $id
        ) );

        if ( ! $coach ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Coach not available.', 'squash-club-booking' ) ], 404 );
        }

        // Fetch availability blocks for this day of week.
        $blocks = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}scb_coach_availability
             WHERE coach_id = %d AND day_of_week = %d AND is_blocked = 0",
            $id,
            $dow
        ) );

        // If no blocks exist, provide default Saturday hours 09:00 - 17:00 if it is Saturday.
        if ( empty( $blocks ) ) {
            if ( $dow === 6 ) {
                $blocks = [
                    (object) [
                        'start_time'  => '09:00:00',
                        'end_time'    => '17:00:00',
                        'lesson_type' => 'both',
                    ],
                ];
            } else {
                return rest_ensure_response( [ 'success' => true, 'data' => [ 'slots' => [], 'message' => __( 'No hours configured for this day.', 'squash-club-booking' ) ] ] );
            }
        }

        // Fetch existing confirmed/paid bookings on this date for this coach.
        $bookings = $wpdb->get_results( $wpdb->prepare(
            "SELECT lesson_start_time, lesson_end_time
             FROM {$wpdb->prefix}scb_bookings
             WHERE coach_id = %d
               AND lesson_date = %s
               AND booking_status NOT IN ('cancelled', 'rejected')
               AND (payment_status = 'paid' OR amount = 0)",
            $id,
            $date
        ) );

        $duration_mins = absint( Settings::get( 'lesson_duration', 60 ) );
        $slots = [];

        foreach ( $blocks as $block ) {
            $start_ts = strtotime( $date . ' ' . $block->start_time );
            $end_ts   = strtotime( $date . ' ' . $block->end_time );

            $curr = $start_ts;
            while ( ( $curr + ( $duration_mins * 60 ) ) <= $end_ts ) {
                $slot_start_str = date( 'H:i:00', $curr );
                $slot_end_str   = date( 'H:i:00', $curr + ( $duration_mins * 60 ) );
                $slot_label     = date( 'g:i A', $curr );

                // Check overlap with existing bookings.
                $is_booked = false;
                foreach ( $bookings as $b ) {
                    if ( $slot_start_str < $b->lesson_end_time && $slot_end_str > $b->lesson_start_time ) {
                        $is_booked = true;
                        break;
                    }
                }

                $slots[] = [
                    'time'       => date( 'H:i', $curr ),
                    'label'      => $slot_label,
                    'available'  => ! $is_booked,
                ];

                $curr += ( $duration_mins * 60 );
            }
        }

        return rest_ensure_response( [
            'success' => true,
            'data'    => [
                'coach_id' => $id,
                'date'     => $date,
                'slots'    => $slots,
            ],
        ] );
    }

    /**
     * Generate dummy coach profiles.
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public static function seed_dummy( \WP_REST_Request $r ): \WP_REST_Response {
        $result = \SquashClubBooking\Database\Seeder::seed_coaches();

        AuditLog::log(
            'coach_seed_dummy',
            'coach',
            0,
            [],
            [ 'created' => $result['created'], 'skipped' => $result['skipped'] ]
        );

        $msg = $result['created'] > 0
            ? sprintf( __( 'Successfully generated %1$d dummy coach profiles (%2$d existing skipped).', 'squash-club-booking' ), $result['created'], $result['skipped'] )
            : sprintf( __( 'No new dummy coaches added (%d were already present).', 'squash-club-booking' ), $result['skipped'] );

        return rest_ensure_response( [
            'success' => true,
            'message' => $msg,
            'data'    => $result,
        ] );
    }
}
