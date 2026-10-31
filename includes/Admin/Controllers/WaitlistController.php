<?php
namespace SquashClubBooking\Admin\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use SquashClubBooking\Modules\Waitlist\WaitlistManager;

/**
 * Controller for Waitlist management in the admin dashboard.
 *
 * @package SquashClubBooking\Admin\Controllers
 */
class WaitlistController {

    /**
     * List waitlist entries.
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
            $where .= ' AND w.status = %s';
            $params[] = $status;
        }

        $orderby_param = sanitize_key( $r->get_param( 'orderby' ) ?: $r->get_param( 'sort_by' ) ?: 'position' );
        $order_param   = strtoupper( sanitize_key( $r->get_param( 'order' ) ?: $r->get_param( 'sort_dir' ) ?: 'ASC' ) );
        if ( ! in_array( $order_param, [ 'ASC', 'DESC' ], true ) ) {
            $order_param = 'ASC';
        }

        $allowed_sorts = [
            'id'             => 'w.id',
            'position'       => 'w.position',
            'customer_name'  => 'w.customer_name',
            'customer_email' => 'w.customer_email',
            'service_type'   => 'w.service_type',
            'slot_date'      => 'cs.slot_date',
            'status'         => 'w.status',
            'created_at'     => 'w.created_at',
        ];

        $order_by_sql = $allowed_sorts[ $orderby_param ] ?? 'w.position';
        $secondary_order = ( $order_by_sql === 'w.position' ) ? ', w.created_at ASC' : '';

        $sql = "SELECT w.*,
                       cs.slot_date, cs.start_time AS slot_time, cs.category AS slot_category, cs.capacity, cs.booked_count,
                       c.first_name AS coach_first_name, c.last_name AS coach_last_name
                FROM {$wpdb->prefix}scb_waitlist w
                LEFT JOIN {$wpdb->prefix}scb_clinic_slots cs ON w.clinic_slot_id = cs.id
                LEFT JOIN {$wpdb->prefix}scb_coaches c ON w.coach_id = c.id
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
     * Promote waitlist entry to active booking.
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public static function promote( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r->get_param( 'id' ) );
        $res = WaitlistManager::promote( $id );

        return rest_ensure_response( $res );
    }

    /**
     * Remove waitlist entry.
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public static function remove( \WP_REST_Request $r ): \WP_REST_Response {
        global $wpdb;

        $id    = absint( $r->get_param( 'id' ) );
        $entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}scb_waitlist WHERE id = %d", $id ) );

        if ( ! $entry ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Entry not found.', 'squash-club-booking' ) ], 404 );
        }

        $wpdb->update(
            $wpdb->prefix . 'scb_waitlist',
            [ 'status' => 'removed', 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        if ( $entry->clinic_slot_id ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->prefix}scb_clinic_slots SET waitlist_count = GREATEST(0, waitlist_count - 1) WHERE id = %d",
                $entry->clinic_slot_id
            ) );
        }

        return rest_ensure_response( [
            'success' => true,
            'message' => __( 'Waitlist entry removed.', 'squash-club-booking' ),
        ] );
    }
}
