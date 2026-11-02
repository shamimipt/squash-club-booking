<?php
namespace SquashClubBooking\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST API bootstrap.
 *
 * Registers all plugin REST routes under /wp-json/scb/v1/.
 *
 * @package SquashClubBooking\Core
 */
class RestApi {

    const NAMESPACE = 'scb/v1';

    /**
     * Register WordPress hooks.
     */
    public function register(): void {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    /**
     * Register all REST routes.
     */
    public function register_routes(): void {
        // Dashboard stats.
        register_rest_route( self::NAMESPACE, '/dashboard/stats', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_dashboard_stats' ],
            'permission_callback' => [ $this, 'require_admin_access' ],
        ] );

        // Bookings CRUD.
        register_rest_route( self::NAMESPACE, '/bookings', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ '\SquashClubBooking\Admin\Controllers\BookingController', 'index' ],
                'permission_callback' => [ $this, 'require_view_bookings' ],
                'args'                => $this->bookings_list_args(),
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/bookings/(?P<id>\d+)', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ '\SquashClubBooking\Admin\Controllers\BookingController', 'show' ],
                'permission_callback' => [ $this, 'require_view_bookings' ],
            ],
            [
                'methods'             => \WP_REST_Server::EDITABLE,
                'callback'            => [ '\SquashClubBooking\Admin\Controllers\BookingController', 'update' ],
                'permission_callback' => [ $this, 'require_manage_bookings' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/bookings/(?P<id>\d+)/approve', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ '\SquashClubBooking\Admin\Controllers\BookingController', 'approve' ],
            'permission_callback' => [ $this, 'require_approve_bookings' ],
        ] );

        register_rest_route( self::NAMESPACE, '/bookings/(?P<id>\d+)/reject', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ '\SquashClubBooking\Admin\Controllers\BookingController', 'reject' ],
            'permission_callback' => [ $this, 'require_approve_bookings' ],
        ] );

        register_rest_route( self::NAMESPACE, '/bookings/(?P<id>\d+)/cancel', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ '\SquashClubBooking\Admin\Controllers\BookingController', 'cancel' ],
            'permission_callback' => [ $this, 'require_manage_bookings' ],
        ] );

        register_rest_route( self::NAMESPACE, '/bookings/(?P<id>\d+)/reschedule', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ '\SquashClubBooking\Admin\Controllers\BookingController', 'reschedule' ],
            'permission_callback' => [ $this, 'require_manage_bookings' ],
        ] );

        // Clinic slots.
        register_rest_route( self::NAMESPACE, '/clinic-slots', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ '\SquashClubBooking\Admin\Controllers\ClinicController', 'slots_index' ],
                'permission_callback' => [ $this, 'require_view_clinics' ],
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ '\SquashClubBooking\Admin\Controllers\ClinicController', 'create_slot' ],
                'permission_callback' => [ $this, 'require_manage_clinics' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/clinic-slots/public', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_public_clinic_slots' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( self::NAMESPACE, '/clinic-slots/generate', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ '\SquashClubBooking\Admin\Controllers\ClinicController', 'generate_slots' ],
            'permission_callback' => [ $this, 'require_manage_clinics' ],
        ] );

        register_rest_route( self::NAMESPACE, '/clinic-slots/(?P<id>\d+)', [
            [
                'methods'             => \WP_REST_Server::EDITABLE,
                'callback'            => [ '\SquashClubBooking\Admin\Controllers\ClinicController', 'update_slot' ],
                'permission_callback' => [ $this, 'require_manage_clinics' ],
            ],
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [ '\SquashClubBooking\Admin\Controllers\ClinicController', 'delete_slot' ],
                'permission_callback' => [ $this, 'require_manage_clinics' ],
            ],
        ] );

        // Coaches.
        register_rest_route( self::NAMESPACE, '/coaches', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ '\SquashClubBooking\Admin\Controllers\CoachController', 'index' ],
                'permission_callback' => [ $this, 'require_view_coaches' ],
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ '\SquashClubBooking\Admin\Controllers\CoachController', 'create' ],
                'permission_callback' => [ $this, 'require_manage_coaches' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/coaches/seed', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ '\SquashClubBooking\Admin\Controllers\CoachController', 'seed_dummy' ],
            'permission_callback' => [ $this, 'require_manage_coaches' ],
        ] );

        register_rest_route( self::NAMESPACE, '/coaches/public', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_public_coaches' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( self::NAMESPACE, '/coaches/(?P<id>\d+)', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ '\SquashClubBooking\Admin\Controllers\CoachController', 'show' ],
                'permission_callback' => [ $this, 'require_view_coaches' ],
            ],
            [
                'methods'             => \WP_REST_Server::EDITABLE,
                'callback'            => [ '\SquashClubBooking\Admin\Controllers\CoachController', 'update' ],
                'permission_callback' => [ $this, 'require_manage_coaches' ],
            ],
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [ '\SquashClubBooking\Admin\Controllers\CoachController', 'delete' ],
                'permission_callback' => [ $this, 'require_manage_coaches' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/coaches/(?P<id>\d+)/availability', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ '\SquashClubBooking\Admin\Controllers\CoachController', 'get_availability' ],
                'permission_callback' => [ $this, 'require_view_coaches' ],
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ '\SquashClubBooking\Admin\Controllers\CoachController', 'save_availability' ],
                'permission_callback' => [ $this, 'require_manage_schedules' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/coaches/(?P<id>\d+)/available-slots', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ '\SquashClubBooking\Admin\Controllers\CoachController', 'available_slots' ],
            'permission_callback' => '__return_true',
        ] );

        // Camps.
        register_rest_route( self::NAMESPACE, '/camps/seed', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ '\SquashClubBooking\Admin\Controllers\CampController', 'seed_dummy' ],
            'permission_callback' => [ $this, 'require_manage_camps' ],
        ] );

        register_rest_route( self::NAMESPACE, '/camps', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ '\SquashClubBooking\Admin\Controllers\CampController', 'index' ],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ '\SquashClubBooking\Admin\Controllers\CampController', 'create' ],
                'permission_callback' => [ $this, 'require_manage_camps' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/camps/(?P<id>\d+)', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ '\SquashClubBooking\Admin\Controllers\CampController', 'show' ],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => \WP_REST_Server::EDITABLE,
                'callback'            => [ '\SquashClubBooking\Admin\Controllers\CampController', 'update' ],
                'permission_callback' => [ $this, 'require_manage_camps' ],
            ],
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [ '\SquashClubBooking\Admin\Controllers\CampController', 'delete' ],
                'permission_callback' => [ $this, 'require_manage_camps' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/camp-registrations', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ '\SquashClubBooking\Admin\Controllers\CampController', 'registrations_index' ],
            'permission_callback' => [ $this, 'require_manage_camps' ],
        ] );

        // Waitlist.
        register_rest_route( self::NAMESPACE, '/waitlist', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ '\SquashClubBooking\Admin\Controllers\WaitlistController', 'index' ],
            'permission_callback' => [ $this, 'require_manage_waitlist' ],
        ] );

        register_rest_route( self::NAMESPACE, '/waitlist/(?P<id>\d+)/promote', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ '\SquashClubBooking\Admin\Controllers\WaitlistController', 'promote' ],
            'permission_callback' => [ $this, 'require_manage_waitlist' ],
        ] );

        register_rest_route( self::NAMESPACE, '/waitlist/(?P<id>\d+)', [
            'methods'             => \WP_REST_Server::DELETABLE,
            'callback'            => [ '\SquashClubBooking\Admin\Controllers\WaitlistController', 'remove' ],
            'permission_callback' => [ $this, 'require_manage_waitlist' ],
        ] );

        // Customers.
        register_rest_route( self::NAMESPACE, '/customers', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ '\SquashClubBooking\Admin\Controllers\BookingController', 'customers' ],
            'permission_callback' => [ $this, 'require_view_bookings' ],
        ] );

        // Payments.
        register_rest_route( self::NAMESPACE, '/payments', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_payments' ],
            'permission_callback' => [ $this, 'require_admin_access' ],
        ] );

        register_rest_route( self::NAMESPACE, '/payments/sync', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'sync_payments' ],
            'permission_callback' => [ $this, 'require_admin_access' ],
        ] );

        // Notifications log.
        register_rest_route( self::NAMESPACE, '/notifications', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_notifications' ],
            'permission_callback' => [ $this, 'require_admin_access' ],
        ] );

        // Frontend booking submission (strictly requires authentication - no guest bookings).
        register_rest_route( self::NAMESPACE, '/book/clinic', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ '\SquashClubBooking\Modules\Clinics\ClinicBookingHandler', 'handle_booking' ],
            'permission_callback' => 'is_user_logged_in',
        ] );

        register_rest_route( self::NAMESPACE, '/book/lesson', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ '\SquashClubBooking\Modules\Lessons\LessonBookingHandler', 'handle_booking' ],
            'permission_callback' => 'is_user_logged_in',
        ] );

        register_rest_route( self::NAMESPACE, '/register/camp', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ '\SquashClubBooking\Modules\Camps\CampRegistrationHandler', 'handle_registration' ],
            'permission_callback' => 'is_user_logged_in',
        ] );

        // Frontend status lookup (strictly requires authentication).
        register_rest_route( self::NAMESPACE, '/booking/status', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_booking_status_by_ref' ],
            'permission_callback' => 'is_user_logged_in',
        ] );

        // Frontend customer my-bookings.
        register_rest_route( self::NAMESPACE, '/my-bookings', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_my_bookings' ],
            'permission_callback' => 'is_user_logged_in',
        ] );

        // Reports.
        register_rest_route( self::NAMESPACE, '/reports/summary', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ '\SquashClubBooking\Admin\Controllers\ReportController', 'summary' ],
            'permission_callback' => [ $this, 'require_view_reports' ],
        ] );

        register_rest_route( self::NAMESPACE, '/reports/export', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ '\SquashClubBooking\Admin\Controllers\ReportController', 'export_csv' ],
            'permission_callback' => [ $this, 'require_export_data' ],
        ] );

        // Settings.
        register_rest_route( self::NAMESPACE, '/settings', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ '\SquashClubBooking\Admin\Controllers\SettingsController', 'index' ],
                'permission_callback' => [ $this, 'require_manage_settings' ],
            ],
            [
                'methods'             => \WP_REST_Server::EDITABLE,
                'callback'            => [ '\SquashClubBooking\Admin\Controllers\SettingsController', 'update' ],
                'permission_callback' => [ $this, 'require_manage_settings' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/settings/create-pages', [
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ '\SquashClubBooking\Admin\Controllers\SettingsController', 'create_pages' ],
                'permission_callback' => [ $this, 'require_manage_settings' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/settings/reset-tables', [
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ '\SquashClubBooking\Admin\Controllers\SettingsController', 'reset_tables' ],
                'permission_callback' => [ $this, 'require_manage_settings' ],
            ],
        ] );
    }

    // ── Helper Callbacks ──────────────────────────────────────────────────────

    /**
     * Get public clinic slots for frontend booking.
     */
    public function get_public_clinic_slots( \WP_REST_Request $r ): \WP_REST_Response {
        global $wpdb;

        $today = current_time( 'Y-m-d' );
        $slots = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, slot_date, start_time, end_time, category, capacity, booked_count, waitlist_count, price, status, notes
             FROM {$wpdb->prefix}scb_clinic_slots
             WHERE slot_date >= %s AND status IN ('open','full')
             ORDER BY slot_date ASC, start_time ASC
             LIMIT 40",
            $today
        ), ARRAY_A );

        return rest_ensure_response( [ 'success' => true, 'data' => [ 'slots' => $slots ?: [] ] ] );
    }

    /**
     * Get active coaches for frontend lesson booking.
     */
    public function get_public_coaches( \WP_REST_Request $r ): \WP_REST_Response {
        global $wpdb;

        $coaches = $wpdb->get_results(
            "SELECT id, first_name, last_name, slug, photo_url, bio, specialties, lesson_types, hourly_rate, semi_private_rate
             FROM {$wpdb->prefix}scb_coaches
             WHERE status = 'active'
             ORDER BY display_order ASC, last_name ASC",
            ARRAY_A
        );

        return rest_ensure_response( [
            'success' => true,
            'data'    => [
                'coaches'                    => $coaches ?: [],
                'default_private_price'      => floatval( Settings::get( 'lesson_price_private', 80.00 ) ),
                'default_semi_private_price' => floatval( Settings::get( 'lesson_price_semi_private', 40.00 ) ),
            ]
        ] );
    }

    /**
     * Get payment history for admin dashboard.
     */
    public function get_payments( \WP_REST_Request $r ): \WP_REST_Response {
        global $wpdb;

        // Auto-reconcile with WooCommerce to guarantee consistent statuses.
        \SquashClubBooking\Modules\Payments\PaymentModule::reconcile_all_orders();

        $orderby_param = sanitize_key( $r->get_param( 'orderby' ) ?: $r->get_param( 'sort_by' ) ?: 'created_at' );
        $order_param   = strtoupper( sanitize_key( $r->get_param( 'order' ) ?: $r->get_param( 'sort_dir' ) ?: 'DESC' ) );
        if ( ! in_array( $order_param, [ 'ASC', 'DESC' ], true ) ) {
            $order_param = 'DESC';
        }

        $allowed_sorts = [
            'id'             => 'p.id',
            'reference'      => 'reference',
            'customer_name'  => 'customer_name',
            'service_type'   => 'service_type',
            'amount'         => 'p.amount',
            'payment_method' => 'p.payment_method',
            'payment_status' => 'p.payment_status',
            'created_at'     => 'p.created_at',
        ];

        $order_by_sql = $allowed_sorts[ $orderby_param ] ?? 'p.created_at';

        $items = $wpdb->get_results(
            "SELECT p.*,
                    COALESCE(b.booking_ref, cr.registration_ref, '—') AS reference,
                    COALESCE(b.customer_name, cr.parent_name, '—') AS customer_name,
                    COALESCE(b.customer_email, cr.parent_email, '—') AS customer_email,
                    COALESCE(b.service_type, 'camp') AS service_type
             FROM {$wpdb->prefix}scb_payments p
             LEFT JOIN {$wpdb->prefix}scb_bookings b ON p.entity_type = 'booking' AND p.entity_id = b.id
             LEFT JOIN {$wpdb->prefix}scb_camp_registrations cr ON p.entity_type = 'camp_registration' AND p.entity_id = cr.id
             ORDER BY {$order_by_sql} {$order_param}
             LIMIT 100",
            ARRAY_A
        );

        if ( ! empty( $items ) && function_exists( 'wc_get_order' ) ) {
            $is_hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
            foreach ( $items as &$item ) {
                $order_id = (int) ( $item['wc_order_id'] ?? 0 );
                if ( $order_id > 0 ) {
                    $order = wc_get_order( $order_id );
                    if ( $order ) {
                        $item['wc_status']    = $order->get_status();
                        $item['wc_order_url'] = $is_hpos
                            ? admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id )
                            : admin_url( 'post.php?post=' . $order_id . '&action=edit' );
                    } else {
                        $item['wc_status']    = 'not_found';
                        $item['wc_order_url'] = '';
                    }
                } else {
                    $item['wc_status']    = '';
                    $item['wc_order_url'] = '';
                }
            }
            unset( $item );
        }

        return rest_ensure_response( [ 'success' => true, 'data' => [ 'items' => $items ?: [] ] ] );
    }

    /**
     * Manually trigger WooCommerce orders and SCB payments reconciliation.
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public function sync_payments( \WP_REST_Request $r ): \WP_REST_Response {
        $result = \SquashClubBooking\Modules\Payments\PaymentModule::reconcile_all_orders();
        return rest_ensure_response( [
            'success' => true,
            'message' => sprintf( __( 'Synchronization completed. %d records updated.', 'squash-club-booking' ), $result['synced'] ?? 0 ),
            'data'    => $result,
        ] );
    }

    /**
     * Get email notification log for admin dashboard.
     */
    public function get_notifications( \WP_REST_Request $r ): \WP_REST_Response {
        global $wpdb;

        $orderby_param = sanitize_key( $r->get_param( 'orderby' ) ?: $r->get_param( 'sort_by' ) ?: 'sent_at' );
        $order_param   = strtoupper( sanitize_key( $r->get_param( 'order' ) ?: $r->get_param( 'sort_dir' ) ?: 'DESC' ) );
        if ( ! in_array( $order_param, [ 'ASC', 'DESC' ], true ) ) {
            $order_param = 'DESC';
        }

        $allowed_sorts = [
            'id'               => 'id',
            'notification_key' => 'notification_key',
            'recipient_email'  => 'recipient_email',
            'recipient_name'   => 'recipient_name',
            'subject'          => 'subject',
            'status'           => 'status',
            'sent_at'          => 'sent_at',
        ];

        $order_by_sql = $allowed_sorts[ $orderby_param ] ?? 'sent_at';

        $items = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}scb_notifications ORDER BY {$order_by_sql} {$order_param} LIMIT 100",
            ARRAY_A
        );

        return rest_ensure_response( [ 'success' => true, 'data' => [ 'items' => $items ?: [] ] ] );
    }

    /**
     * Public booking status lookup by reference.
     */
    public function get_booking_status_by_ref( \WP_REST_Request $r ): \WP_REST_Response {
        global $wpdb;

        if ( ! is_user_logged_in() ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'You must be logged in to view booking status.', 'squash-club-booking' ) ], 401 );
        }

        $user_id  = get_current_user_id();
        $is_admin = current_user_can( 'manage_options' );

        $ref = sanitize_text_field( $r->get_param( 'ref' ) ?? '' );
        if ( empty( $ref ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Please provide a booking reference.', 'squash-club-booking' ) ], 400 );
        }

        // Try bookings first.
        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.*,
                    cs.slot_date, cs.start_time AS slot_start, cs.end_time AS slot_end, cs.category AS slot_category,
                    c.first_name AS coach_first, c.last_name AS coach_last
             FROM {$wpdb->prefix}scb_bookings b
             LEFT JOIN {$wpdb->prefix}scb_clinic_slots cs ON b.clinic_slot_id = cs.id
             LEFT JOIN {$wpdb->prefix}scb_coaches c ON b.coach_id = c.id
             WHERE b.booking_ref = %s",
            $ref
        ), ARRAY_A );

        if ( $booking ) {
            if ( ! $is_admin && (int) ( $booking['customer_user_id'] ?? 0 ) !== $user_id ) {
                return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'You do not have permission to view this booking.', 'squash-club-booking' ) ], 403 );
            }
            if ( ! empty( $booking['wc_order_id'] ) && function_exists( 'wc_get_order' ) ) {
                $order = wc_get_order( (int) $booking['wc_order_id'] );
                if ( $order && $order->needs_payment() ) {
                    $booking['checkout_url'] = $order->get_checkout_payment_url();
                }
            }
            return rest_ensure_response( [ 'success' => true, 'type' => 'booking', 'data' => $booking ] );
        }

        // Try camp registrations.
        $reg = $wpdb->get_row( $wpdb->prepare(
            "SELECT r.*, c.title AS camp_title, c.start_date, c.end_date, c.location
             FROM {$wpdb->prefix}scb_camp_registrations r
             LEFT JOIN {$wpdb->prefix}scb_camps c ON r.camp_id = c.id
             WHERE r.registration_ref = %s",
            $ref
        ), ARRAY_A );

        if ( $reg ) {
            if ( ! $is_admin && (int) ( $reg['customer_user_id'] ?? 0 ) !== $user_id ) {
                return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'You do not have permission to view this registration.', 'squash-club-booking' ) ], 403 );
            }
            if ( ! empty( $reg['wc_order_id'] ) && function_exists( 'wc_get_order' ) ) {
                $order = wc_get_order( (int) $reg['wc_order_id'] );
                if ( $order && $order->needs_payment() ) {
                    $reg['checkout_url'] = $order->get_checkout_payment_url();
                }
            }
            return rest_ensure_response( [ 'success' => true, 'type' => 'camp', 'data' => $reg ] );
        }

        return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Reference not found.', 'squash-club-booking' ) ], 404 );
    }

    /**
     * Customer's own bookings for [scb_my_bookings].
     */
    public function get_my_bookings( \WP_REST_Request $r ): \WP_REST_Response {
        global $wpdb;

        $user_id = get_current_user_id();
        $user    = wp_get_current_user();
        $email   = $user->user_email;

        $bookings = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.*,
                    cs.slot_date, cs.start_time AS slot_start, cs.end_time AS slot_end, cs.category AS slot_category,
                    c.first_name AS coach_first, c.last_name AS coach_last
             FROM {$wpdb->prefix}scb_bookings b
             LEFT JOIN {$wpdb->prefix}scb_clinic_slots cs ON b.clinic_slot_id = cs.id
             LEFT JOIN {$wpdb->prefix}scb_coaches c ON b.coach_id = c.id
             WHERE b.customer_user_id = %d OR b.customer_email = %s
             ORDER BY b.created_at DESC",
            $user_id,
            $email
        ), ARRAY_A );

        $camps = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.*, c.title AS camp_title, c.start_date, c.end_date
             FROM {$wpdb->prefix}scb_camp_registrations r
             LEFT JOIN {$wpdb->prefix}scb_camps c ON r.camp_id = c.id
             WHERE r.customer_user_id = %d OR r.parent_email = %s
             ORDER BY r.created_at DESC",
            $user_id,
            $email
        ), ARRAY_A );

        return rest_ensure_response( [
            'success' => true,
            'data'    => [
                'bookings' => $bookings ?: [],
                'camps'    => $camps ?: [],
            ],
        ] );
    }

    // ── Dashboard ─────────────────────────────────────────────────────────────

    /**
     * Get dashboard KPI statistics.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function get_dashboard_stats( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        $today     = current_time( 'Y-m-d' );
        $this_week = date( 'Y-m-d', strtotime( 'monday this week' ) );

        $stats = [
            'total_bookings'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}scb_bookings" ),
            'pending_approvals'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}scb_bookings WHERE booking_status = %s", 'pending' ) ),
            'approved_bookings'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}scb_bookings WHERE booking_status = %s", 'approved' ) ),
            'todays_lessons'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}scb_bookings WHERE lesson_date = %s AND service_type IN ('private_lesson','semi_private_lesson') AND booking_status != %s", $today, 'cancelled' ) ),
            'upcoming_clinics'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}scb_clinic_slots WHERE slot_date >= %s AND status = %s", $today, 'open' ) ),
            'waitlisted'         => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}scb_waitlist WHERE status = %s", 'waiting' ) ),
            'camp_registrations' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}scb_camp_registrations WHERE registration_status != 'cancelled'" ),
            'revenue_this_month' => (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}scb_bookings WHERE payment_status = %s AND MONTH(created_at) = MONTH(%s) AND YEAR(created_at) = YEAR(%s)", 'paid', $today, $today ) ),
            'pending_payments'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}scb_bookings WHERE payment_status = %s", 'pending' ) ),
        ];

        return rest_ensure_response( [ 'success' => true, 'data' => $stats ] );
    }

    // ── Permission callbacks ──────────────────────────────────────────────────

    /** @return bool|\WP_Error */
    public function require_admin_access( \WP_REST_Request $r ) {
        return current_user_can( 'scb_access_dashboard' )
            ? true
            : new \WP_Error( 'scb_forbidden', __( 'Access denied.', 'squash-club-booking' ), [ 'status' => 403 ] );
    }

    public function require_view_bookings( \WP_REST_Request $r ) {
        return current_user_can( 'scb_view_bookings' )
            ? true
            : new \WP_Error( 'scb_forbidden', __( 'Access denied.', 'squash-club-booking' ), [ 'status' => 403 ] );
    }

    public function require_manage_bookings( \WP_REST_Request $r ) {
        return current_user_can( 'scb_manage_bookings' )
            ? true
            : new \WP_Error( 'scb_forbidden', __( 'Access denied.', 'squash-club-booking' ), [ 'status' => 403 ] );
    }

    public function require_approve_bookings( \WP_REST_Request $r ) {
        return current_user_can( 'scb_approve_bookings' )
            ? true
            : new \WP_Error( 'scb_forbidden', __( 'Access denied.', 'squash-club-booking' ), [ 'status' => 403 ] );
    }

    public function require_view_clinics( \WP_REST_Request $r ) {
        return current_user_can( 'scb_view_clinic_slots' )
            ? true
            : new \WP_Error( 'scb_forbidden', __( 'Access denied.', 'squash-club-booking' ), [ 'status' => 403 ] );
    }

    public function require_manage_clinics( \WP_REST_Request $r ) {
        return current_user_can( 'scb_manage_clinics' )
            ? true
            : new \WP_Error( 'scb_forbidden', __( 'Access denied.', 'squash-club-booking' ), [ 'status' => 403 ] );
    }

    public function require_view_coaches( \WP_REST_Request $r ) {
        return current_user_can( 'scb_manage_coaches' )
            ? true
            : new \WP_Error( 'scb_forbidden', __( 'Access denied.', 'squash-club-booking' ), [ 'status' => 403 ] );
    }

    public function require_manage_coaches( \WP_REST_Request $r ) {
        return current_user_can( 'scb_manage_coaches' )
            ? true
            : new \WP_Error( 'scb_forbidden', __( 'Access denied.', 'squash-club-booking' ), [ 'status' => 403 ] );
    }

    public function require_manage_schedules( \WP_REST_Request $r ) {
        return current_user_can( 'scb_manage_schedules' )
            ? true
            : new \WP_Error( 'scb_forbidden', __( 'Access denied.', 'squash-club-booking' ), [ 'status' => 403 ] );
    }

    public function require_manage_camps( \WP_REST_Request $r ) {
        return current_user_can( 'scb_manage_camps' )
            ? true
            : new \WP_Error( 'scb_forbidden', __( 'Access denied.', 'squash-club-booking' ), [ 'status' => 403 ] );
    }

    public function require_manage_waitlist( \WP_REST_Request $r ) {
        return current_user_can( 'scb_manage_waitlist' )
            ? true
            : new \WP_Error( 'scb_forbidden', __( 'Access denied.', 'squash-club-booking' ), [ 'status' => 403 ] );
    }

    public function require_view_reports( \WP_REST_Request $r ) {
        return current_user_can( 'scb_view_reports' )
            ? true
            : new \WP_Error( 'scb_forbidden', __( 'Access denied.', 'squash-club-booking' ), [ 'status' => 403 ] );
    }

    public function require_export_data( \WP_REST_Request $r ) {
        return current_user_can( 'scb_export_data' )
            ? true
            : new \WP_Error( 'scb_forbidden', __( 'Access denied.', 'squash-club-booking' ), [ 'status' => 403 ] );
    }

    public function require_manage_settings( \WP_REST_Request $r ) {
        return current_user_can( 'scb_manage_settings' )
            ? true
            : new \WP_Error( 'scb_forbidden', __( 'Access denied.', 'squash-club-booking' ), [ 'status' => 403 ] );
    }

    /**
     * Query args for the bookings list endpoint.
     */
    private function bookings_list_args(): array {
        return [
            'page'           => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
            'per_page'       => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
            'search'         => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            'service_type'   => [ 'type' => 'string' ],
            'booking_status' => [ 'type' => 'string' ],
            'payment_status' => [ 'type' => 'string' ],
            'coach_id'       => [ 'type' => 'integer' ],
            'date_from'      => [ 'type' => 'string' ],
            'date_to'        => [ 'type' => 'string' ],
            'orderby'        => [ 'type' => 'string', 'default' => 'created_at' ],
            'order'          => [ 'type' => 'string', 'default' => 'DESC', 'enum' => [ 'ASC', 'DESC' ] ],
        ];
    }
}
