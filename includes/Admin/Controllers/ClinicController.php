<?php
namespace SquashClubBooking\Admin\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use SquashClubBooking\Core\AuditLog;
use SquashClubBooking\Modules\Clinics\SlotGenerator;

/**
 * Controller for Sunday Clinic slots management in the admin dashboard.
 *
 * @package SquashClubBooking\Admin\Controllers
 */
class ClinicController {

    /**
     * List clinic slots.
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public static function slots_index( \WP_REST_Request $r ): \WP_REST_Response {
        global $wpdb;

        $from     = sanitize_text_field( $r->get_param( 'date_from' ) ?? '' );
        $to       = sanitize_text_field( $r->get_param( 'date_to' ) ?? '' );
        $category = sanitize_key( $r->get_param( 'category' ) ?? '' );
        $status   = sanitize_key( $r->get_param( 'status' ) ?? '' );

        $where  = '1=1';
        $params = [];

        if ( ! empty( $from ) ) {
            $where .= ' AND slot_date >= %s';
            $params[] = $from;
        }
        if ( ! empty( $to ) ) {
            $where .= ' AND slot_date <= %s';
            $params[] = $to;
        }
        if ( ! empty( $category ) ) {
            $where .= ' AND category = %s';
            $params[] = $category;
        }
        if ( ! empty( $status ) ) {
            $where .= ' AND status = %s';
            $params[] = $status;
        }

        $orderby_param = sanitize_key( $r->get_param( 'orderby' ) ?: $r->get_param( 'sort_by' ) ?: 'slot_date' );
        $order_param   = strtoupper( sanitize_key( $r->get_param( 'order' ) ?: $r->get_param( 'sort_dir' ) ?: 'DESC' ) );
        if ( ! in_array( $order_param, [ 'ASC', 'DESC' ], true ) ) {
            $order_param = 'DESC';
        }

        $allowed_sorts = [
            'id'             => 'cs.id',
            'slot_date'      => 'cs.slot_date',
            'start_time'     => 'cs.start_time',
            'category'       => 'cs.category',
            'capacity'       => 'cs.capacity',
            'booked_count'   => 'cs.booked_count',
            'waitlist_count' => 'cs.waitlist_count',
            'price'          => 'cs.price',
            'status'         => 'cs.status',
            'created_at'     => 'cs.created_at',
        ];

        $order_by_sql = $allowed_sorts[ $orderby_param ] ?? 'cs.slot_date';
        $secondary_order = ( $order_by_sql === 'cs.slot_date' ) ? ', cs.start_time ASC' : '';

        // Auto-sync slot booked counts to only count paid confirmed bookings.
        // Pending, cancelled, refunded, and failed bookings do not occupy spots.
        $wpdb->query(
            "UPDATE {$wpdb->prefix}scb_clinic_slots cs
             SET cs.booked_count = (
                 SELECT COUNT(*) FROM {$wpdb->prefix}scb_bookings b
                 WHERE b.clinic_slot_id = cs.id
                   AND b.booking_status NOT IN ('cancelled', 'rejected', 'waitlisted')
                   AND b.payment_status = 'paid'
             )"
        );

        // Auto-heal slot statuses based on accurate paid capacity.
        $wpdb->query(
            "UPDATE {$wpdb->prefix}scb_clinic_slots
             SET status = IF(booked_count >= capacity, 'full', 'open')
             WHERE status IN ('open', 'full')"
        );

        $sql = "SELECT cs.*,
                       (SELECT COUNT(*) FROM {$wpdb->prefix}scb_bookings b WHERE b.clinic_slot_id = cs.id AND b.booking_status IN ('approved','pending')) AS active_bookings_count
                FROM {$wpdb->prefix}scb_clinic_slots cs
                WHERE {$where}
                ORDER BY {$order_by_sql} {$order_param}{$secondary_order}
                LIMIT 100";

        $items = $wpdb->get_results( empty( $params ) ? $sql : $wpdb->prepare( $sql, $params ), ARRAY_A );

        return rest_ensure_response( [
            'success' => true,
            'data'    => [ 'items' => $items ?: [] ],
        ] );
    }

    /**
     * Create a new clinic slot manually.
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public static function create_slot( \WP_REST_Request $r ): \WP_REST_Response {
        global $wpdb;

        $date       = sanitize_text_field( $r->get_param( 'slot_date' ) ?? '' );
        $start_time = sanitize_text_field( $r->get_param( 'start_time' ) ?? '' );
        $end_time   = sanitize_text_field( $r->get_param( 'end_time' ) ?? '' );
        $category   = sanitize_key( $r->get_param( 'category' ) ?? 'junior' );
        $capacity   = absint( $r->get_param( 'capacity' ) ?? 15 );
        $price      = floatval( $r->get_param( 'price' ) ?? 25.00 );
        $notes      = sanitize_textarea_field( $r->get_param( 'notes' ) ?? '' );

        if ( empty( $date ) || ! strtotime( $date ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Please provide a valid date.', 'squash-club-booking' ) ], 400 );
        }
        if ( empty( $start_time ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Please provide a start time.', 'squash-club-booking' ) ], 400 );
        }

        $start_fmt = date( 'H:i:00', strtotime( $start_time ) );
        $end_fmt   = ! empty( $end_time ) ? date( 'H:i:00', strtotime( $end_time ) ) : date( 'H:i:00', strtotime( '+60 minutes', strtotime( $start_fmt ) ) );

        // Duplicate check.
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}scb_clinic_slots WHERE slot_date = %s AND start_time = %s AND category = %s",
            $date,
            $start_fmt,
            $category
        ) );

        if ( $exists ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'A clinic slot with this date, time, and category already exists.', 'squash-club-booking' ) ], 400 );
        }

        $wpdb->insert(
            $wpdb->prefix . 'scb_clinic_slots',
            [
                'slot_date'      => $date,
                'start_time'     => $start_fmt,
                'end_time'       => $end_fmt,
                'category'       => $category,
                'capacity'       => max( 1, $capacity ),
                'booked_count'   => 0,
                'waitlist_count' => 0,
                'price'          => $price,
                'status'         => 'open',
                'notes'          => $notes,
                'created_at'     => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%f', '%s', '%s', '%s' ]
        );

        $slot_id = $wpdb->insert_id;
        AuditLog::log( 'slot_created', 'clinic_slot', $slot_id, [], [ 'date' => $date, 'time' => $start_fmt, 'category' => $category ] );

        return rest_ensure_response( [
            'success' => true,
            'slot_id' => $slot_id,
            'message' => __( 'Clinic slot created successfully.', 'squash-club-booking' ),
        ] );
    }

    /**
     * Generate standard slots automatically for upcoming Sunday(s).
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public static function generate_slots( \WP_REST_Request $r ): \WP_REST_Response {
        $weeks  = absint( $r->get_param( 'weeks' ) ?? 1 );
        $res    = SlotGenerator::generate_multiple_sundays( max( 1, min( 12, $weeks ) ) );

        $total_created = array_sum( array_column( $res, 'created' ) );
        $total_skipped = array_sum( array_column( $res, 'skipped' ) );

        return rest_ensure_response( [
            'success' => true,
            'message' => sprintf( __( 'Generated %1$d new clinic slots (%2$d existing slots skipped).', 'squash-club-booking' ), $total_created, $total_skipped ),
            'details' => $res,
        ] );
    }

    /**
     * Update an existing slot.
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public static function update_slot( \WP_REST_Request $r ): \WP_REST_Response {
        global $wpdb;

        $id     = absint( $r->get_param( 'id' ) );
        $params = $r->get_json_params() ?: [];

        $slot = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}scb_clinic_slots WHERE id = %d", $id ) );
        if ( ! $slot ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Slot not found.', 'squash-club-booking' ) ], 404 );
        }

        $fields  = [];
        $formats = [];

        if ( isset( $params['capacity'] ) ) {
            $cap = max( 1, absint( $params['capacity'] ) );
            $fields['capacity'] = $cap;
            $formats[] = '%d';
            // Recalculate status if full.
            $fields['status'] = (int) $slot->booked_count >= $cap ? 'full' : ( $slot->status === 'full' ? 'open' : $slot->status );
            $formats[] = '%s';
        }

        if ( isset( $params['price'] ) ) {
            $fields['price'] = floatval( $params['price'] );
            $formats[] = '%f';
        }

        if ( isset( $params['status'] ) && in_array( $params['status'], [ 'open', 'full', 'closed', 'cancelled' ], true ) ) {
            $fields['status'] = $params['status'];
            $formats[] = '%s';
        }

        if ( isset( $params['notes'] ) ) {
            $fields['notes'] = sanitize_textarea_field( $params['notes'] );
            $formats[] = '%s';
        }

        if ( empty( $fields ) ) {
            return rest_ensure_response( [ 'success' => true, 'message' => __( 'No changes to update.', 'squash-club-booking' ) ] );
        }

        $fields['updated_at'] = current_time( 'mysql' );
        $formats[] = '%s';

        $wpdb->update( $wpdb->prefix . 'scb_clinic_slots', $fields, [ 'id' => $id ], $formats, [ '%d' ] );

        AuditLog::log( 'slot_updated', 'clinic_slot', $id, (array) $slot, $fields );

        return rest_ensure_response( [
            'success' => true,
            'message' => __( 'Clinic slot updated successfully.', 'squash-club-booking' ),
        ] );
    }

    /**
     * Delete a slot. Safe deletion: cannot delete if active bookings exist.
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public static function delete_slot( \WP_REST_Request $r ): \WP_REST_Response {
        global $wpdb;

        $id   = absint( $r->get_param( 'id' ) );
        $slot = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}scb_clinic_slots WHERE id = %d", $id ) );

        if ( ! $slot ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Slot not found.', 'squash-club-booking' ) ], 404 );
        }

        // Check active bookings.
        $active_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}scb_bookings WHERE clinic_slot_id = %d AND booking_status NOT IN ('cancelled', 'rejected')",
            $id
        ) );

        if ( $active_count > 0 ) {
            return new \WP_REST_Response( [
                'success' => false,
                'message' => sprintf( __( 'Cannot delete this slot: there are %d active booking(s). Please reschedule or cancel the bookings first.', 'squash-club-booking' ), $active_count ),
            ], 400 );
        }

        // Remove waitlist entries for this slot.
        $wpdb->delete( $wpdb->prefix . 'scb_waitlist', [ 'clinic_slot_id' => $id ], [ '%d' ] );
        $wpdb->delete( $wpdb->prefix . 'scb_clinic_slots', [ 'id' => $id ], [ '%d' ] );

        AuditLog::log( 'slot_deleted', 'clinic_slot', $id, (array) $slot, [] );

        return rest_ensure_response( [
            'success' => true,
            'message' => __( 'Clinic slot deleted successfully.', 'squash-club-booking' ),
        ] );
    }
}
