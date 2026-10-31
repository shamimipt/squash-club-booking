<?php
namespace SquashClubBooking\Admin\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use SquashClubBooking\Core\AuditLog;

/**
 * Controller for Summer Camps and Registrations management in the admin dashboard.
 *
 * @package SquashClubBooking\Admin\Controllers
 */
class CampController {

    /**
     * List all camps.
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
            $where .= ' AND publication_status = %s';
            $params[] = $status;
        }

        $orderby_param = sanitize_key( $r->get_param( 'orderby' ) ?: $r->get_param( 'sort_by' ) ?: 'start_date' );
        $order_param   = strtoupper( sanitize_key( $r->get_param( 'order' ) ?: $r->get_param( 'sort_dir' ) ?: 'ASC' ) );
        if ( ! in_array( $order_param, [ 'ASC', 'DESC' ], true ) ) {
            $order_param = 'ASC';
        }

        $allowed_sorts = [
            'id'                  => 'c.id',
            'title'               => 'c.title',
            'start_date'          => 'c.start_date',
            'end_date'            => 'c.end_date',
            'min_age'             => 'c.min_age',
            'price'               => 'c.price',
            'capacity'            => 'c.capacity',
            'registered_count'    => 'c.registered_count',
            'registration_status' => 'c.registration_status',
            'publication_status'  => 'c.publication_status',
            'created_at'          => 'c.created_at',
        ];

        $order_by_sql = $allowed_sorts[ $orderby_param ] ?? 'c.start_date';

        $sql = "SELECT c.*,
                       (SELECT COUNT(*) FROM {$wpdb->prefix}scb_camp_registrations r WHERE r.camp_id = c.id AND r.registration_status != 'cancelled') AS active_registrations_count
                FROM {$wpdb->prefix}scb_camps c
                WHERE {$where}
                ORDER BY {$order_by_sql} {$order_param}";

        $items = $wpdb->get_results( empty( $params ) ? $sql : $wpdb->prepare( $sql, $params ), ARRAY_A );

        return rest_ensure_response( [
            'success' => true,
            'data'    => [ 'items' => $items ?: [] ],
        ] );
    }

    /**
     * Generate dummy summer camps demo data.
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public static function seed_dummy( \WP_REST_Request $r ): \WP_REST_Response {
        $result = \SquashClubBooking\Database\Seeder::seed_camps();

        AuditLog::log(
            'camp_seed_dummy',
            'camp',
            0,
            [],
            [ 'created' => $result['created'], 'skipped' => $result['skipped'] ]
        );

        $msg = $result['created'] > 0
            ? sprintf( __( 'Successfully generated %1$d dummy summer camp(s) (%2$d existing skipped).', 'squash-club-booking' ), $result['created'], $result['skipped'] )
            : sprintf( __( 'No new dummy camps added (%d were already present).', 'squash-club-booking' ), $result['skipped'] );

        return rest_ensure_response( [
            'success' => true,
            'message' => $msg,
            'data'    => $result,
        ] );
    }

    /**
     * Show single camp.
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public static function show( \WP_REST_Request $r ): \WP_REST_Response {
        global $wpdb;

        $id = absint( $r->get_param( 'id' ) );
        $c  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}scb_camps WHERE id = %d", $id ), ARRAY_A );

        if ( ! $c ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Camp not found.', 'squash-club-booking' ) ], 404 );
        }

        return rest_ensure_response( [ 'success' => true, 'data' => $c ] );
    }

    /**
     * Create a new summer camp.
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public static function create( \WP_REST_Request $r ): \WP_REST_Response {
        global $wpdb;

        $title       = sanitize_text_field( $r->get_param( 'title' ) ?? '' );
        $desc        = wp_kses_post( $r->get_param( 'description' ) ?? '' );
        $image_url   = esc_url_raw( $r->get_param( 'image_url' ) ?? '' );
        $location    = sanitize_text_field( $r->get_param( 'location' ) ?? 'Main Squash Arena' );
        $start_date  = sanitize_text_field( $r->get_param( 'start_date' ) ?? '' );
        $end_date    = sanitize_text_field( $r->get_param( 'end_date' ) ?? '' );
        $schedule    = sanitize_text_field( $r->get_param( 'schedule_details' ) ?? 'Mon - Fri, 9:00 AM - 3:00 PM' );
        $min_age     = absint( $r->get_param( 'min_age' ) ?? 6 );
        $max_age     = absint( $r->get_param( 'max_age' ) ?? 16 );
        $price       = floatval( $r->get_param( 'price' ) ?? 250.00 );
        $capacity    = absint( $r->get_param( 'capacity' ) ?? 20 );
        $deadline    = sanitize_text_field( $r->get_param( 'registration_deadline' ) ?? '' );
        $pub_status  = sanitize_key( $r->get_param( 'publication_status' ) ?? 'published' );

        if ( empty( $title ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Please provide a camp title.', 'squash-club-booking' ) ], 400 );
        }
        if ( empty( $start_date ) || empty( $end_date ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Please provide start and end dates.', 'squash-club-booking' ) ], 400 );
        }

        $slug_base = sanitize_title( $title );
        $slug      = $slug_base;
        $counter   = 1;
        while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}scb_camps WHERE slug = %s", $slug ) ) ) {
            $slug = "{$slug_base}-{$counter}";
            $counter++;
        }

        $wpdb->insert(
            $wpdb->prefix . 'scb_camps',
            [
                'title'                 => $title,
                'slug'                  => $slug,
                'description'           => $desc,
                'image_url'             => $image_url,
                'location'              => $location,
                'start_date'            => $start_date,
                'end_date'              => $end_date,
                'schedule_details'      => $schedule,
                'min_age'               => $min_age,
                'max_age'               => $max_age,
                'price'                 => $price,
                'capacity'              => max( 1, $capacity ),
                'registered_count'      => 0,
                'registration_deadline' => ! empty( $deadline ) ? $deadline : null,
                'registration_status'   => 'open',
                'publication_status'    => in_array( $pub_status, [ 'draft', 'published', 'archived' ], true ) ? $pub_status : 'published',
                'created_at'            => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%f', '%d', '%d', '%s', '%s', '%s', '%s' ]
        );

        $camp_id = $wpdb->insert_id;
        AuditLog::log( 'camp_created', 'camp', $camp_id, [], [ 'title' => $title, 'slug' => $slug ] );

        return rest_ensure_response( [
            'success' => true,
            'camp_id' => $camp_id,
            'message' => __( 'Summer camp created successfully.', 'squash-club-booking' ),
        ] );
    }

    /**
     * Update an existing summer camp.
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public static function update( \WP_REST_Request $r ): \WP_REST_Response {
        global $wpdb;

        $id     = absint( $r->get_param( 'id' ) );
        $params = $r->get_json_params() ?: [];

        $camp = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}scb_camps WHERE id = %d", $id ) );
        if ( ! $camp ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Camp not found.', 'squash-club-booking' ) ], 404 );
        }

        $fields  = [];
        $formats = [];

        if ( isset( $params['title'] ) ) {
            $fields['title'] = sanitize_text_field( $params['title'] );
            $formats[] = '%s';
        }
        if ( isset( $params['description'] ) ) {
            $fields['description'] = wp_kses_post( $params['description'] );
            $formats[] = '%s';
        }
        if ( isset( $params['image_url'] ) ) {
            $fields['image_url'] = esc_url_raw( $params['image_url'] );
            $formats[] = '%s';
        }
        if ( isset( $params['location'] ) ) {
            $fields['location'] = sanitize_text_field( $params['location'] );
            $formats[] = '%s';
        }
        if ( isset( $params['start_date'] ) ) {
            $fields['start_date'] = sanitize_text_field( $params['start_date'] );
            $formats[] = '%s';
        }
        if ( isset( $params['end_date'] ) ) {
            $fields['end_date'] = sanitize_text_field( $params['end_date'] );
            $formats[] = '%s';
        }
        if ( isset( $params['schedule_details'] ) ) {
            $fields['schedule_details'] = sanitize_text_field( $params['schedule_details'] );
            $formats[] = '%s';
        }
        if ( isset( $params['min_age'] ) ) {
            $fields['min_age'] = absint( $params['min_age'] );
            $formats[] = '%d';
        }
        if ( isset( $params['max_age'] ) ) {
            $fields['max_age'] = absint( $params['max_age'] );
            $formats[] = '%d';
        }
        if ( isset( $params['price'] ) ) {
            $fields['price'] = floatval( $params['price'] );
            $formats[] = '%f';
        }
        if ( isset( $params['capacity'] ) ) {
            $cap = max( 1, absint( $params['capacity'] ) );
            $fields['capacity'] = $cap;
            $formats[] = '%d';
        }
        if ( isset( $params['registration_status'] ) && in_array( $params['registration_status'], [ 'open', 'closed', 'full', 'cancelled' ], true ) ) {
            $fields['registration_status'] = $params['registration_status'];
            $formats[] = '%s';
        }
        if ( isset( $params['publication_status'] ) && in_array( $params['publication_status'], [ 'draft', 'published', 'archived' ], true ) ) {
            $fields['publication_status'] = $params['publication_status'];
            $formats[] = '%s';
        }

        if ( empty( $fields ) ) {
            return rest_ensure_response( [ 'success' => true, 'message' => __( 'No changes to update.', 'squash-club-booking' ) ] );
        }

        $fields['updated_at'] = current_time( 'mysql' );
        $formats[] = '%s';

        $wpdb->update( $wpdb->prefix . 'scb_camps', $fields, [ 'id' => $id ], $formats, [ '%d' ] );

        AuditLog::log( 'camp_updated', 'camp', $id, (array) $camp, $fields );

        return rest_ensure_response( [
            'success' => true,
            'message' => __( 'Camp updated successfully.', 'squash-club-booking' ),
        ] );
    }

    /**
     * Delete camp.
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public static function delete( \WP_REST_Request $r ): \WP_REST_Response {
        global $wpdb;

        $id   = absint( $r->get_param( 'id' ) );
        $camp = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}scb_camps WHERE id = %d", $id ) );

        if ( ! $camp ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Camp not found.', 'squash-club-booking' ) ], 404 );
        }

        $reg_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}scb_camp_registrations WHERE camp_id = %d AND registration_status != 'cancelled'",
            $id
        ) );

        if ( $reg_count > 0 ) {
            return new \WP_REST_Response( [
                'success' => false,
                'message' => sprintf( __( 'Cannot delete this camp: there are %d active registration(s). Please archive or cancel registrations first.', 'squash-club-booking' ), $reg_count ),
            ], 400 );
        }

        $wpdb->delete( $wpdb->prefix . 'scb_camps', [ 'id' => $id ], [ '%d' ] );
        AuditLog::log( 'camp_deleted', 'camp', $id, (array) $camp, [] );

        return rest_ensure_response( [
            'success' => true,
            'message' => __( 'Camp deleted successfully.', 'squash-club-booking' ),
        ] );
    }

    /**
     * List all camp registrations.
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public static function registrations_index( \WP_REST_Request $r ): \WP_REST_Response {
        global $wpdb;

        $camp_id = absint( $r->get_param( 'camp_id' ) ?? 0 );
        $status  = sanitize_key( $r->get_param( 'status' ) ?? '' );
        $search  = sanitize_text_field( $r->get_param( 'search' ) ?? '' );

        $where  = '1=1';
        $params = [];

        if ( $camp_id > 0 ) {
            $where .= ' AND r.camp_id = %d';
            $params[] = $camp_id;
        }

        if ( ! empty( $status ) ) {
            $where .= ' AND r.registration_status = %s';
            $params[] = $status;
        }

        if ( ! empty( $search ) ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where .= ' AND (r.student_name LIKE %s OR r.parent_name LIKE %s OR r.parent_email LIKE %s OR r.registration_ref LIKE %s)';
            $params = array_merge( $params, [ $like, $like, $like, $like ] );
        }

        $orderby_param = sanitize_key( $r->get_param( 'orderby' ) ?: $r->get_param( 'sort_by' ) ?: 'created_at' );
        $order_param   = strtoupper( sanitize_key( $r->get_param( 'order' ) ?: $r->get_param( 'sort_dir' ) ?: 'DESC' ) );
        if ( ! in_array( $order_param, [ 'ASC', 'DESC' ], true ) ) {
            $order_param = 'DESC';
        }

        $allowed_sorts = [
            'id'                  => 'r.id',
            'registration_ref'    => 'r.registration_ref',
            'student_name'        => 'r.student_name',
            'student_age'         => 'r.student_age',
            'parent_name'         => 'r.parent_name',
            'camp_title'          => 'c.title',
            'amount'              => 'r.amount',
            'registration_status' => 'r.registration_status',
            'payment_status'      => 'r.payment_status',
            'created_at'          => 'r.created_at',
        ];

        $order_by_sql = $allowed_sorts[ $orderby_param ] ?? 'r.created_at';

        $sql = "SELECT r.*, c.title AS camp_title, c.start_date AS camp_start, c.end_date AS camp_end
                FROM {$wpdb->prefix}scb_camp_registrations r
                LEFT JOIN {$wpdb->prefix}scb_camps c ON r.camp_id = c.id
                WHERE {$where}
                ORDER BY {$order_by_sql} {$order_param}
                LIMIT 100";

        $items = $wpdb->get_results( empty( $params ) ? $sql : $wpdb->prepare( $sql, $params ), ARRAY_A );

        return rest_ensure_response( [
            'success' => true,
            'data'    => [ 'items' => $items ?: [] ],
        ] );
    }
}
