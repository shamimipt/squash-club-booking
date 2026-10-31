<?php
namespace SquashClubBooking\Admin\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Controller for Reports and Data Export in the admin dashboard.
 *
 * @package SquashClubBooking\Admin\Controllers
 */
class ReportController {

    /**
     * Get aggregate report summary metrics.
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public static function summary( \WP_REST_Request $r ): \WP_REST_Response {
        global $wpdb;

        // Service type breakdown.
        $by_service = $wpdb->get_results(
            "SELECT service_type, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS total_amount
             FROM {$wpdb->prefix}scb_bookings
             WHERE booking_status != 'cancelled'
             GROUP BY service_type",
            ARRAY_A
        );

        // Camp registrations total.
        $camp_stats = $wpdb->get_row(
            "SELECT COUNT(*) AS total_registrations, COALESCE(SUM(amount), 0) AS total_amount
             FROM {$wpdb->prefix}scb_camp_registrations
             WHERE registration_status != 'cancelled'",
            ARRAY_A
        );

        // Status breakdown.
        $by_status = $wpdb->get_results(
            "SELECT booking_status, COUNT(*) AS count
             FROM {$wpdb->prefix}scb_bookings
             GROUP BY booking_status",
            ARRAY_A
        );

        // Top coaches by bookings count.
        $top_coaches = $wpdb->get_results(
            "SELECT c.id, c.first_name, c.last_name, COUNT(b.id) AS bookings_count, COALESCE(SUM(b.amount), 0) AS revenue
             FROM {$wpdb->prefix}scb_coaches c
             LEFT JOIN {$wpdb->prefix}scb_bookings b ON c.id = b.coach_id AND b.booking_status NOT IN ('cancelled', 'rejected')
             GROUP BY c.id
             ORDER BY bookings_count DESC
             LIMIT 10",
            ARRAY_A
        );

        // Monthly trends (last 6 months).
        $monthly_revenue = $wpdb->get_results(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS month,
                    COUNT(id) AS bookings_count,
                    COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN amount ELSE 0 END), 0) AS revenue
             FROM {$wpdb->prefix}scb_bookings
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
             GROUP BY month
             ORDER BY month ASC",
            ARRAY_A
        );

        return rest_ensure_response( [
            'success' => true,
            'data'    => [
                'by_service'      => $by_service ?: [],
                'camp_stats'      => $camp_stats ?: [ 'total_registrations' => 0, 'total_amount' => 0 ],
                'by_status'       => $by_status ?: [],
                'top_coaches'     => $top_coaches ?: [],
                'monthly_revenue' => $monthly_revenue ?: [],
            ],
        ] );
    }

    /**
     * Export data as CSV.
     *
     * @param \WP_REST_Request $r
     * @return void
     */
    public static function export_csv( \WP_REST_Request $r ) {
        global $wpdb;

        $type = sanitize_key( $r->get_param( 'type' ) ?? 'bookings' );

        $filename = "scb-{$type}-" . date( 'Y-m-d' ) . '.csv';

        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );
        // Add UTF-8 BOM for Excel compatibility.
        fputs( $output, "\xEF\xBB\xBF" );

        if ( $type === 'camps' ) {
            fputcsv( $output, [ 'Ref', 'Camp Title', 'Student Name', 'Age', 'Parent Name', 'Parent Email', 'Parent Phone', 'Status', 'Payment Status', 'Amount', 'Date' ] );
            $rows = $wpdb->get_results(
                "SELECT r.registration_ref, c.title AS camp_title, r.student_name, r.student_age, r.parent_name, r.parent_email, r.parent_phone, r.registration_status, r.payment_status, r.amount, r.created_at
                 FROM {$wpdb->prefix}scb_camp_registrations r
                 LEFT JOIN {$wpdb->prefix}scb_camps c ON r.camp_id = c.id
                 ORDER BY r.id DESC",
                ARRAY_A
            );
            foreach ( $rows as $row ) {
                fputcsv( $output, $row );
            }

        } elseif ( $type === 'customers' ) {
            fputcsv( $output, [ 'Name', 'Email', 'Phone', 'Total Bookings', 'Total Spent', 'Last Booking Date' ] );
            $rows = $wpdb->get_results(
                "SELECT MAX(customer_name) AS name, customer_email, MAX(customer_phone) AS phone, COUNT(id) AS bookings, COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN amount ELSE 0 END), 0) AS spent, MAX(created_at) AS last_date
                 FROM {$wpdb->prefix}scb_bookings
                 GROUP BY customer_email
                 ORDER BY last_date DESC",
                ARRAY_A
            );
            foreach ( $rows as $row ) {
                fputcsv( $output, $row );
            }

        } else {
            // Default: bookings.
            fputcsv( $output, [ 'Ref', 'Service', 'Customer Name', 'Email', 'Phone', 'Coach / Clinic', 'Date', 'Time', 'Booking Status', 'Payment Status', 'Amount', 'Created At' ] );
            $rows = $wpdb->get_results(
                "SELECT b.booking_ref, b.service_type, b.customer_name, b.customer_email, b.customer_phone,
                        COALESCE(CONCAT(c.first_name, ' ', c.last_name), cs.category, '—') AS provider,
                        COALESCE(b.lesson_date, cs.slot_date, '—') AS booking_date,
                        COALESCE(b.lesson_start_time, cs.start_time, '—') AS booking_time,
                        b.booking_status, b.payment_status, b.amount, b.created_at
                 FROM {$wpdb->prefix}scb_bookings b
                 LEFT JOIN {$wpdb->prefix}scb_coaches c ON b.coach_id = c.id
                 LEFT JOIN {$wpdb->prefix}scb_clinic_slots cs ON b.clinic_slot_id = cs.id
                 ORDER BY b.id DESC",
                ARRAY_A
            );
            foreach ( $rows as $row ) {
                fputcsv( $output, $row );
            }
        }

        fclose( $output );
        exit;
    }
}
