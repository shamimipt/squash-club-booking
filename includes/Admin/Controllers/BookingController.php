<?php

namespace SquashClubBooking\Admin\Controllers;

if (! defined('ABSPATH')) {
    exit;
}

use SquashClubBooking\Core\AuditLog;
use SquashClubBooking\Modules\Notifications\NotificationModule;

/**
 * Controller for Bookings management in the admin dashboard.
 *
 * @package SquashClubBooking\Admin\Controllers
 */
class BookingController
{

    /**
     * List bookings with filtering, search, sorting, and pagination.
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public static function index(\WP_REST_Request $r): \WP_REST_Response
    {
        global $wpdb;

        // Ensure bookings and WC orders are in sync.
        \SquashClubBooking\Modules\Payments\PaymentModule::reconcile_all_orders();

        $page     = max(1, (int) $r->get_param('page'));
        $per_page = min(100, max(1, (int) $r->get_param('per_page')));
        $offset   = ($page - 1) * $per_page;
        $search   = sanitize_text_field($r->get_param('search') ?? '');
        $service  = sanitize_key($r->get_param('service_type') ?? '');
        $bstatus  = sanitize_key($r->get_param('booking_status') ?? '');
        $pstatus  = sanitize_key($r->get_param('payment_status') ?? '');
        $coach_id = absint($r->get_param('coach_id') ?? 0);
        $from     = sanitize_text_field($r->get_param('date_from') ?? '');
        $to       = sanitize_text_field($r->get_param('date_to') ?? '');

        $where  = '1=1';
        $params = [];

        if (! empty($search)) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= ' AND (b.customer_name LIKE %s OR b.customer_email LIKE %s OR b.customer_phone LIKE %s OR b.booking_ref LIKE %s)';
            $params = array_merge($params, [$like, $like, $like, $like]);
        }

        if (! empty($service)) {
            if ($service === 'lessons' || $service === 'all_lessons') {
                $where .= " AND b.service_type IN ('private_lesson', 'semi_private_lesson')";
            } else {
                $where .= ' AND b.service_type = %s';
                $params[] = $service;
            }
        }

        if (! empty($bstatus)) {
            $where .= ' AND b.booking_status = %s';
            $params[] = $bstatus;
        }

        if (! empty($pstatus)) {
            $where .= ' AND b.payment_status = %s';
            $params[] = $pstatus;
        }

        if ($coach_id > 0) {
            $where .= ' AND b.coach_id = %d';
            $params[] = $coach_id;
        }

        if (! empty($from)) {
            $where .= ' AND (b.lesson_date >= %s OR cs.slot_date >= %s OR b.created_at >= %s)';
            $params = array_merge($params, [$from, $from, $from . ' 00:00:00']);
        }

        if (! empty($to)) {
            $where .= ' AND (b.lesson_date <= %s OR cs.slot_date <= %s OR b.created_at <= %s)';
            $params = array_merge($params, [$to, $to, $to . ' 23:59:59']);
        }

        $count_sql = "SELECT COUNT(DISTINCT b.id)
                      FROM {$wpdb->prefix}scb_bookings b
                      LEFT JOIN {$wpdb->prefix}scb_clinic_slots cs ON b.clinic_slot_id = cs.id
                      WHERE {$where}";

        $total = (int) $wpdb->get_var(empty($params) ? $count_sql : $wpdb->prepare($count_sql, $params));

        $orderby_param = sanitize_key($r->get_param('orderby') ?: $r->get_param('sort_by') ?: 'created_at');
        $order_param   = strtoupper(sanitize_key($r->get_param('order') ?: $r->get_param('sort_dir') ?: 'DESC'));
        if (! in_array($order_param, ['ASC', 'DESC'], true)) {
            $order_param = 'DESC';
        }

        $allowed_sorts = [
            'id'             => 'b.id',
            'booking_ref'    => 'b.booking_ref',
            'customer_name'  => 'b.customer_name',
            'customer_email' => 'b.customer_email',
            'coach_name'     => 'c.first_name',
            'service_type'   => 'b.service_type',
            'booking_date'   => 'COALESCE(b.lesson_date, cs.slot_date, b.created_at)',
            'booking_status' => 'b.booking_status',
            'payment_status' => 'b.payment_status',
            'amount'         => 'b.amount',
            'created_at'     => 'b.created_at',
        ];

        $order_by_sql = $allowed_sorts[$orderby_param] ?? 'b.created_at';

        $query = "SELECT b.*,
                         c.first_name AS coach_first_name, c.last_name AS coach_last_name, c.hourly_rate AS coach_hourly_rate, c.semi_private_rate AS coach_semi_private_rate,
                         cs.slot_date AS clinic_date, cs.start_time AS clinic_start_time, cs.end_time AS clinic_end_time, cs.category AS clinic_category,
                         cs.slot_date, cs.start_time, cs.end_time
                  FROM {$wpdb->prefix}scb_bookings b
                  LEFT JOIN {$wpdb->prefix}scb_coaches c ON b.coach_id = c.id
                  LEFT JOIN {$wpdb->prefix}scb_clinic_slots cs ON b.clinic_slot_id = cs.id
                  WHERE {$where}
                  ORDER BY {$order_by_sql} {$order_param}
                  LIMIT %d OFFSET %d";

        $p_copy   = $params;
        $p_copy[] = $per_page;
        $p_copy[] = $offset;

        $items = $wpdb->get_results($wpdb->prepare($query, $p_copy), ARRAY_A);

        return rest_ensure_response([
            'success' => true,
            'data'    => [
                'items'    => $items ?: [],
                'total'    => $total,
                'page'     => $page,
                'per_page' => $per_page,
            ],
        ]);
    }

    /**
     * Create a manual booking (admin adds pre-arranged / offline client).
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public static function create(\WP_REST_Request $r): \WP_REST_Response
    {
        global $wpdb;

        $params = $r->get_json_params() ?: [];

        $service_type = sanitize_key($params['service_type'] ?? 'clinic');
        if (! in_array($service_type, ['clinic', 'private_lesson', 'semi_private_lesson'], true)) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => __('Invalid service type specified.', 'squash-club-booking'),
            ], 400);
        }

        $customer_name  = sanitize_text_field($params['customer_name'] ?? '');
        $customer_email = sanitize_email($params['customer_email'] ?? '');
        $customer_phone = sanitize_text_field($params['customer_phone'] ?? '');

        if (empty($customer_name)) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => __('Customer name is required.', 'squash-club-booking'),
            ], 400);
        }

        if (empty($customer_email) || ! is_email($customer_email)) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => __('A valid customer email is required.', 'squash-club-booking'),
            ], 400);
        }

        // Link existing WP user if available
        $customer_user_id = ! empty($params['customer_user_id']) ? absint($params['customer_user_id']) : null;
        if (! $customer_user_id) {
            $user = get_user_by('email', $customer_email);
            if ($user) {
                $customer_user_id = $user->ID;
            }
        }

        $booking_status = sanitize_key($params['booking_status'] ?? 'approved');
        if (! in_array($booking_status, ['pending', 'approved', 'waitlisted', 'completed'], true)) {
            $booking_status = 'approved';
        }

        $payment_status = sanitize_key($params['payment_status'] ?? 'paid');
        if (! in_array($payment_status, ['paid', 'unpaid', 'pending', 'refunded'], true)) {
            $payment_status = 'paid';
        }

        $amount            = isset($params['amount']) ? floatval($params['amount']) : 0.00;
        $notes             = sanitize_textarea_field($params['notes'] ?? '');
        $admin_notes       = sanitize_textarea_field($params['admin_notes'] ?? '');
        $participant_name  = sanitize_text_field($params['participant_name'] ?? $customer_name);
        $skill_level       = sanitize_text_field($params['skill_level'] ?? 'beginner');
        $send_notification = ! empty($params['send_notification']);

        $ip_address = AuditLog::get_client_ip();
        $wpdb->query('START TRANSACTION');

        try {
            if ($service_type === 'clinic') {
                $slot_id = absint($params['clinic_slot_id'] ?? 0);
                if (! $slot_id) {
                    $wpdb->query('ROLLBACK');
                    return new \WP_REST_Response([
                        'success' => false,
                        'message' => __('Please select a clinic slot.', 'squash-club-booking'),
                    ], 400);
                }

                $slot = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}scb_clinic_slots WHERE id = %d FOR UPDATE",
                    $slot_id
                ));

                if (! $slot) {
                    $wpdb->query('ROLLBACK');
                    return new \WP_REST_Response([
                        'success' => false,
                        'message' => __('Selected clinic slot does not exist.', 'squash-club-booking'),
                    ], 404);
                }

                // Reference: SCB-CLN-YYYYMMDD-XXXX
                $date_tag    = date('Ymd', strtotime($slot->slot_date));
                $rand_tag    = strtoupper(wp_generate_password(4, false));
                $booking_ref = "SCB-CLN-{$date_tag}-{$rand_tag}";

                $wpdb->insert(
                    $wpdb->prefix . 'scb_bookings',
                    [
                        'booking_ref'       => $booking_ref,
                        'service_type'      => 'clinic',
                        'customer_user_id'  => $customer_user_id,
                        'customer_name'     => $customer_name,
                        'customer_email'    => $customer_email,
                        'customer_phone'    => $customer_phone,
                        'clinic_slot_id'    => $slot_id,
                        'participant_count' => 1,
                        'booking_status'    => $booking_status,
                        'payment_status'    => $payment_status,
                        'amount'            => $amount,
                        'notes'             => $notes,
                        'admin_notes'       => $admin_notes,
                        'ip_address'        => $ip_address,
                        'created_at'        => current_time('mysql'),
                        'updated_at'        => current_time('mysql'),
                    ],
                    ['%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s']
                );

                $booking_id = $wpdb->insert_id;

                $wpdb->insert(
                    $wpdb->prefix . 'scb_booking_participants',
                    [
                        'booking_id'  => $booking_id,
                        'first_name'  => $participant_name,
                        'last_name'   => '',
                        'skill_level' => $skill_level,
                        'notes'       => $notes,
                        'created_at'  => current_time('mysql'),
                    ],
                    ['%d', '%s', '%s', '%s', '%s', '%s']
                );

                $wpdb->query('COMMIT');

                // Recalculate slot capacity
                \SquashClubBooking\Modules\Payments\PaymentModule::sync_clinic_slot_count((int) $slot_id);

                $new_booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}scb_bookings WHERE id = %d", $booking_id));

                if ($send_notification) {
                    $time_display  = date('g:i A', strtotime($slot->start_time)) . ' - ' . date('g:i A', strtotime($slot->end_time));
                    $extra_details = '<div class="info-row"><span class="info-label">' . __('Date & Time', 'squash-club-booking') . '</span><span class="info-val">' . date('l, M j, Y', strtotime($slot->slot_date)) . ', ' . $time_display . '</span></div>';
                    $extra_details .= '<div class="info-row"><span class="info-label">' . __('Clinic Type', 'squash-club-booking') . '</span><span class="info-val">' . ucfirst($slot->category) . ' Clinic</span></div>';
                    $extra_details .= '<div class="info-row"><span class="info-label">' . __('Booking Type', 'squash-club-booking') . '</span><span class="info-val">' . __('Manual / Pre-Arranged Entry', 'squash-club-booking') . '</span></div>';

                    NotificationModule::send_booking_confirmation($new_booking, $extra_details);
                }

                AuditLog::log('manual_booking_created', 'booking', $booking_id, [], (array) $new_booking);

                return rest_ensure_response([
                    'success' => true,
                    'message' => __('Client successfully added to Sunday Clinic.', 'squash-club-booking'),
                    'data'    => $new_booking,
                ]);

            } else {
                // Private or Semi-Private Lesson
                $coach_id = absint($params['coach_id'] ?? 0);
                if (! $coach_id) {
                    $wpdb->query('ROLLBACK');
                    return new \WP_REST_Response([
                        'success' => false,
                        'message' => __('Please select a coach for this lesson.', 'squash-club-booking'),
                    ], 400);
                }

                $coach = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}scb_coaches WHERE id = %d", $coach_id));
                if (! $coach) {
                    $wpdb->query('ROLLBACK');
                    return new \WP_REST_Response([
                        'success' => false,
                        'message' => __('Selected coach was not found.', 'squash-club-booking'),
                    ], 404);
                }

                $lesson_date = sanitize_text_field($params['lesson_date'] ?? '');
                $start_time  = sanitize_text_field($params['lesson_start_time'] ?? '');
                $duration    = max(15, absint($params['duration_minutes'] ?? 60));

                if (empty($lesson_date)) {
                    $wpdb->query('ROLLBACK');
                    return new \WP_REST_Response([
                        'success' => false,
                        'message' => __('Lesson date is required.', 'squash-club-booking'),
                    ], 400);
                }

                if (empty($start_time)) {
                    $wpdb->query('ROLLBACK');
                    return new \WP_REST_Response([
                        'success' => false,
                        'message' => __('Lesson start time is required.', 'squash-club-booking'),
                    ], 400);
                }

                if (strlen($start_time) === 5) {
                    $start_time .= ':00';
                }

                $end_time = date('H:i:s', strtotime($start_time) + ($duration * 60));

                $participant_2 = sanitize_text_field($params['participant_2'] ?? '');
                $part_count    = ($service_type === 'semi_private_lesson' && ! empty($participant_2)) ? 2 : 1;

                // Reference: SCB-LES-YYYYMMDD-XXXX
                $date_tag    = date('Ymd', strtotime($lesson_date));
                $rand_tag    = strtoupper(wp_generate_password(4, false));
                $booking_ref = "SCB-LES-{$date_tag}-{$rand_tag}";

                $wpdb->insert(
                    $wpdb->prefix . 'scb_bookings',
                    [
                        'booking_ref'       => $booking_ref,
                        'service_type'      => $service_type,
                        'customer_user_id'  => $customer_user_id,
                        'customer_name'     => $customer_name,
                        'customer_email'    => $customer_email,
                        'customer_phone'    => $customer_phone,
                        'coach_id'          => $coach_id,
                        'lesson_date'       => $lesson_date,
                        'lesson_start_time' => $start_time,
                        'lesson_end_time'   => $end_time,
                        'participant_count' => $part_count,
                        'booking_status'    => $booking_status,
                        'payment_status'    => $payment_status,
                        'amount'            => $amount,
                        'notes'             => $notes,
                        'admin_notes'       => $admin_notes,
                        'ip_address'        => $ip_address,
                        'created_at'        => current_time('mysql'),
                        'updated_at'        => current_time('mysql'),
                    ],
                    ['%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s']
                );

                $booking_id = $wpdb->insert_id;

                // Participant 1
                $wpdb->insert(
                    $wpdb->prefix . 'scb_booking_participants',
                    [
                        'booking_id'  => $booking_id,
                        'first_name'  => $participant_name,
                        'last_name'   => '',
                        'skill_level' => $skill_level,
                        'notes'       => $notes,
                        'created_at'  => current_time('mysql'),
                    ],
                    ['%d', '%s', '%s', '%s', '%s', '%s']
                );

                // Participant 2 if semi-private
                if ($service_type === 'semi_private_lesson' && ! empty($participant_2)) {
                    $wpdb->insert(
                        $wpdb->prefix . 'scb_booking_participants',
                        [
                            'booking_id'  => $booking_id,
                            'first_name'  => $participant_2,
                            'last_name'   => '',
                            'skill_level' => $skill_level,
                            'notes'       => '',
                            'created_at'  => current_time('mysql'),
                        ],
                        ['%d', '%s', '%s', '%s', '%s', '%s']
                    );
                }

                $wpdb->query('COMMIT');

                $new_booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}scb_bookings WHERE id = %d", $booking_id));

                if ($send_notification) {
                    $coach_name    = trim($coach->first_name . ' ' . ($coach->last_name ?? ''));
                    $time_display  = date('g:i A', strtotime($start_time)) . ' - ' . date('g:i A', strtotime($end_time));
                    $service_label = ($service_type === 'semi_private_lesson') ? __('Semi-Private Lesson (2-on-1)', 'squash-club-booking') : __('Private Lesson (1-on-1)', 'squash-club-booking');

                    $extra_details = '<div class="info-row"><span class="info-label">' . __('Coach', 'squash-club-booking') . '</span><span class="info-val">' . esc_html($coach_name) . '</span></div>';
                    $extra_details .= '<div class="info-row"><span class="info-label">' . __('Date & Time', 'squash-club-booking') . '</span><span class="info-val">' . date('l, M j, Y', strtotime($lesson_date)) . ', ' . $time_display . '</span></div>';
                    $extra_details .= '<div class="info-row"><span class="info-label">' . __('Lesson Type', 'squash-club-booking') . '</span><span class="info-val">' . esc_html($service_label) . '</span></div>';
                    $extra_details .= '<div class="info-row"><span class="info-label">' . __('Booking Type', 'squash-club-booking') . '</span><span class="info-val">' . __('Manual / Pre-Arranged Entry', 'squash-club-booking') . '</span></div>';

                    NotificationModule::send_booking_confirmation($new_booking, $extra_details);
                }

                AuditLog::log('manual_booking_created', 'booking', $booking_id, [], (array) $new_booking);

                return rest_ensure_response([
                    'success' => true,
                    'message' => __('Lesson booking successfully created for client.', 'squash-club-booking'),
                    'data'    => $new_booking,
                ]);
            }
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            error_log('SCB Manual Booking Error: ' . $e->getMessage());
            return new \WP_REST_Response([
                'success' => false,
                'message' => __('Database error while creating booking: ', 'squash-club-booking') . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Show booking details including participants and payment.
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public static function show(\WP_REST_Request $r): \WP_REST_Response
    {
        global $wpdb;

        $id = absint($r->get_param('id'));
        $b  = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*,
                    c.first_name AS coach_first_name, c.last_name AS coach_last_name, c.hourly_rate AS coach_hourly_rate, c.semi_private_rate AS coach_semi_private_rate, c.email AS coach_email,
                    cs.slot_date AS clinic_date, cs.start_time AS clinic_start_time, cs.end_time AS clinic_end_time, cs.category AS clinic_category, cs.capacity AS clinic_capacity, cs.booked_count AS clinic_booked_count,
                    cs.slot_date, cs.start_time, cs.end_time
             FROM {$wpdb->prefix}scb_bookings b
             LEFT JOIN {$wpdb->prefix}scb_coaches c ON b.coach_id = c.id
             LEFT JOIN {$wpdb->prefix}scb_clinic_slots cs ON b.clinic_slot_id = cs.id
             WHERE b.id = %d",
            $id
        ), ARRAY_A);

        if (! $b) {
            return new \WP_REST_Response(['success' => false, 'message' => __('Booking not found.', 'squash-club-booking')], 404);
        }

        if (empty($b['start_time']) && ! empty($b['lesson_start_time'])) {
            $b['start_time'] = $b['lesson_start_time'];
            $b['end_time']   = $b['lesson_end_time'];
        }

        // Fetch participants.
        $participants = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}scb_booking_participants WHERE booking_id = %d",
            $id
        ), ARRAY_A);

        // Fetch payment.
        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}scb_payments WHERE entity_type = 'booking' AND entity_id = %d ORDER BY id DESC LIMIT 1",
            $id
        ), ARRAY_A);

        $b['participants'] = $participants ?: [];
        $b['payment']      = $payment ?: null;

        return rest_ensure_response(['success' => true, 'data' => $b]);
    }

    /**
     * Update booking administrative fields and notes.
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public static function update(\WP_REST_Request $r): \WP_REST_Response
    {
        global $wpdb;

        $id     = absint($r->get_param('id'));
        $params = $r->get_json_params() ?: [];

        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}scb_bookings WHERE id = %d", $id));
        if (! $booking) {
            return new \WP_REST_Response(['success' => false, 'message' => __('Booking not found.', 'squash-club-booking')], 404);
        }

        $fields = [];
        $formats = [];

        if (isset($params['customer_name'])) {
            $fields['customer_name'] = sanitize_text_field($params['customer_name']);
            $formats[] = '%s';
        }
        if (isset($params['customer_email']) && is_email($params['customer_email'])) {
            $fields['customer_email'] = sanitize_email($params['customer_email']);
            $formats[] = '%s';
        }
        if (isset($params['customer_phone'])) {
            $fields['customer_phone'] = sanitize_text_field($params['customer_phone']);
            $formats[] = '%s';
        }
        if (isset($params['admin_notes'])) {
            $fields['admin_notes'] = sanitize_textarea_field($params['admin_notes']);
            $formats[] = '%s';
        }
        if (isset($params['notes'])) {
            $fields['notes'] = sanitize_textarea_field($params['notes']);
            $formats[] = '%s';
        }
        if (isset($params['payment_status']) && in_array($params['payment_status'], ['unpaid', 'pending', 'paid', 'refunded', 'failed'], true)) {
            $fields['payment_status'] = $params['payment_status'];
            $formats[] = '%s';
        }
        if (isset($params['booking_status']) && in_array($params['booking_status'], ['pending', 'approved', 'rejected', 'rescheduled', 'cancelled', 'completed'], true)) {
            $fields['booking_status'] = $params['booking_status'];
            $formats[] = '%s';
        }

        if (empty($fields)) {
            return rest_ensure_response(['success' => true, 'message' => __('No changes to update.', 'squash-club-booking')]);
        }

        $fields['updated_at'] = current_time('mysql');
        $formats[] = '%s';

        $wpdb->update($wpdb->prefix . 'scb_bookings', $fields, ['id' => $id], $formats, ['%d']);

        // Sync scb_payments table if payment_status changed.
        if (isset($fields['payment_status']) && ! empty($booking->wc_order_id)) {
            $wpdb->update(
                $wpdb->prefix . 'scb_payments',
                ['payment_status' => $fields['payment_status'], 'updated_at' => current_time('mysql')],
                ['wc_order_id' => $booking->wc_order_id],
                ['%s', '%s'],
                ['%d']
            );
        }

        // Two-way sync with linked WooCommerce order.
        \SquashClubBooking\Modules\Payments\PaymentModule::sync_wc_order_from_booking(
            $id,
            $fields['payment_status'] ?? $booking->payment_status,
            $fields['booking_status'] ?? $booking->booking_status
        );

        if (! empty($booking->clinic_slot_id)) {
            \SquashClubBooking\Modules\Payments\PaymentModule::sync_clinic_slot_count((int) $booking->clinic_slot_id);
        }

        AuditLog::log('booking_updated', 'booking', $id, (array) $booking, $fields);

        return rest_ensure_response(['success' => true, 'message' => __('Booking updated successfully.', 'squash-club-booking')]);
    }

    /**
     * Approve booking.
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public static function approve(\WP_REST_Request $r): \WP_REST_Response
    {
        global $wpdb;

        $id = absint($r->get_param('id'));
        $b  = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}scb_bookings WHERE id = %d", $id));

        if (! $b) {
            return new \WP_REST_Response(['success' => false, 'message' => __('Booking not found.', 'squash-club-booking')], 404);
        }

        $wpdb->update(
            $wpdb->prefix . 'scb_bookings',
            ['booking_status' => 'approved', 'updated_at' => current_time('mysql')],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );

        NotificationModule::send_status_update($b, 'approved');
        AuditLog::log('booking_approved', 'booking', $id, ['booking_status' => $b->booking_status], ['booking_status' => 'approved']);

        return rest_ensure_response(['success' => true, 'message' => __('Booking approved successfully.', 'squash-club-booking')]);
    }

    /**
     * Reject booking.
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public static function reject(\WP_REST_Request $r): \WP_REST_Response
    {
        global $wpdb;

        $id     = absint($r->get_param('id'));
        $reason = sanitize_textarea_field($r->get_param('reason') ?? '');
        $b      = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}scb_bookings WHERE id = %d", $id));

        if (! $b) {
            return new \WP_REST_Response(['success' => false, 'message' => __('Booking not found.', 'squash-club-booking')], 404);
        }

        $wpdb->update(
            $wpdb->prefix . 'scb_bookings',
            [
                'booking_status'   => 'rejected',
                'rejection_reason' => $reason,
                'updated_at'       => current_time('mysql'),
            ],
            ['id' => $id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        // Release capacity if clinic slot.
        if ($b->clinic_slot_id) {
            \SquashClubBooking\Modules\Payments\PaymentModule::sync_clinic_slot_count((int) $b->clinic_slot_id);
        }

        // Two-way sync with linked WooCommerce order.
        \SquashClubBooking\Modules\Payments\PaymentModule::sync_wc_order_from_booking($id, 'failed', 'rejected');

        NotificationModule::send_status_update($b, 'rejected', $reason);
        AuditLog::log('booking_rejected', 'booking', $id, ['booking_status' => $b->booking_status], ['booking_status' => 'rejected', 'reason' => $reason]);

        return rest_ensure_response(['success' => true, 'message' => __('Booking rejected.', 'squash-club-booking')]);
    }

    /**
     * Cancel booking.
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public static function cancel(\WP_REST_Request $r): \WP_REST_Response
    {
        global $wpdb;

        $id     = absint($r->get_param('id'));
        $reason = sanitize_textarea_field($r->get_param('reason') ?? '');
        $b      = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}scb_bookings WHERE id = %d", $id));

        if (! $b) {
            return new \WP_REST_Response(['success' => false, 'message' => __('Booking not found.', 'squash-club-booking')], 404);
        }

        $wpdb->update(
            $wpdb->prefix . 'scb_bookings',
            [
                'booking_status'      => 'cancelled',
                'cancellation_reason' => $reason,
                'updated_at'          => current_time('mysql'),
            ],
            ['id' => $id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        // Release capacity if clinic slot.
        if ($b->clinic_slot_id) {
            \SquashClubBooking\Modules\Payments\PaymentModule::sync_clinic_slot_count((int) $b->clinic_slot_id);
        }

        // Two-way sync with linked WooCommerce order.
        \SquashClubBooking\Modules\Payments\PaymentModule::sync_wc_order_from_booking($id, 'failed', 'cancelled');

        NotificationModule::send_status_update($b, 'cancelled', $reason);
        AuditLog::log('booking_cancelled', 'booking', $id, ['booking_status' => $b->booking_status], ['booking_status' => 'cancelled', 'reason' => $reason]);

        return rest_ensure_response(['success' => true, 'message' => __('Booking cancelled.', 'squash-club-booking')]);
    }

    /**
     * Reschedule booking to another clinic slot or lesson date/time.
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public static function reschedule(\WP_REST_Request $r): \WP_REST_Response
    {
        global $wpdb;

        $id        = absint($r->get_param('id'));
        $new_slot  = absint($r->get_param('new_slot_id') ?? 0);
        $new_date  = sanitize_text_field($r->get_param('new_date') ?? '');
        $new_start = sanitize_text_field($r->get_param('new_start_time') ?? '');
        $reason    = sanitize_textarea_field($r->get_param('reason') ?? '');

        $b = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}scb_bookings WHERE id = %d", $id));
        if (! $b) {
            return new \WP_REST_Response(['success' => false, 'message' => __('Booking not found.', 'squash-club-booking')], 404);
        }

        if ($b->service_type === 'clinic') {
            if (! $new_slot || $new_slot === (int) $b->clinic_slot_id) {
                return new \WP_REST_Response(['success' => false, 'message' => __('Please select a different clinic slot.', 'squash-club-booking')], 400);
            }

            $target_slot = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}scb_clinic_slots WHERE id = %d", $new_slot));
            if (! $target_slot) {
                return new \WP_REST_Response(['success' => false, 'message' => __('Target slot not found.', 'squash-club-booking')], 404);
            }
            if ((int) $target_slot->booked_count >= (int) $target_slot->capacity) {
                return new \WP_REST_Response(['success' => false, 'message' => __('Target slot is already full.', 'squash-club-booking')], 400);
            }

            $wpdb->update(
                $wpdb->prefix . 'scb_bookings',
                [
                    'clinic_slot_id'    => $new_slot,
                    'booking_status'    => 'rescheduled',
                    'reschedule_reason' => $reason,
                    'updated_at'        => current_time('mysql'),
                ],
                ['id' => $id],
                ['%d', '%s', '%s', '%s'],
                ['%d']
            );

            // Re-sync slot capacities.
            if ($b->clinic_slot_id) {
                \SquashClubBooking\Modules\Payments\PaymentModule::sync_clinic_slot_count((int) $b->clinic_slot_id);
            }
            \SquashClubBooking\Modules\Payments\PaymentModule::sync_clinic_slot_count((int) $new_slot);
        } else {
            // Private or semi-private lesson.
            if (empty($new_date) || empty($new_start)) {
                return new \WP_REST_Response(['success' => false, 'message' => __('Please provide new date and start time.', 'squash-club-booking')], 400);
            }

            $start_fmt = date('H:i:00', strtotime($new_start));
            $duration  = 60;
            if ($b->lesson_start_time && $b->lesson_end_time) {
                $duration = max(30, (strtotime($b->lesson_end_time) - strtotime($b->lesson_start_time)) / 60);
            }
            $end_fmt = date('H:i:00', strtotime("+{$duration} minutes", strtotime($start_fmt)));

            // Conflict check.
            $conflict = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}scb_bookings
                 WHERE coach_id = %d
                   AND lesson_date = %s
                   AND id != %d
                   AND booking_status NOT IN ('cancelled', 'rejected')
                   AND (lesson_start_time < %s AND lesson_end_time > %s)",
                $b->coach_id,
                $new_date,
                $id,
                $end_fmt,
                $start_fmt
            ));

            if ($conflict) {
                return new \WP_REST_Response(['success' => false, 'message' => __('The coach is unavailable at that new date and time.', 'squash-club-booking')], 400);
            }

            $wpdb->update(
                $wpdb->prefix . 'scb_bookings',
                [
                    'lesson_date'       => $new_date,
                    'lesson_start_time' => $start_fmt,
                    'lesson_end_time'   => $end_fmt,
                    'booking_status'    => 'rescheduled',
                    'reschedule_reason' => $reason,
                    'updated_at'        => current_time('mysql'),
                ],
                ['id' => $id],
                ['%s', '%s', '%s', '%s', '%s', '%s'],
                ['%d']
            );
        }

        NotificationModule::send_status_update($b, 'rescheduled', $reason);
        AuditLog::log('booking_rescheduled', 'booking', $id, (array) $b, ['reason' => $reason]);

        return rest_ensure_response(['success' => true, 'message' => __('Booking rescheduled successfully.', 'squash-club-booking')]);
    }

    /**
     * Get unique customer directory aggregated from bookings and camp registrations.
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public static function customers(\WP_REST_Request $r): \WP_REST_Response
    {
        global $wpdb;

        $search = sanitize_text_field($r->get_param('search') ?? '');
        $where  = '1=1';
        $params = [];

        if (! empty($search)) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= ' AND (customer_name LIKE %s OR customer_email LIKE %s OR customer_phone LIKE %s)';
            $params = [$like, $like, $like];
        }

        $orderby_param = sanitize_key($r->get_param('orderby') ?: $r->get_param('sort_by') ?: 'last_booking_date');
        $order_param   = strtoupper(sanitize_key($r->get_param('order') ?: $r->get_param('sort_dir') ?: 'DESC'));
        if (! in_array($order_param, ['ASC', 'DESC'], true)) {
            $order_param = 'DESC';
        }

        $allowed_sorts = [
            'customer_name'     => 'customer_name',
            'customer_email'    => 'customer_email',
            'customer_phone'    => 'customer_phone',
            'total_bookings'    => 'total_bookings',
            'total_spent'       => 'total_spent',
            'last_booking_date' => 'last_booking_date',
        ];

        $order_by_sql = $allowed_sorts[$orderby_param] ?? 'last_booking_date';

        $sql = "SELECT customer_email,
                       MAX(customer_name) AS customer_name,
                       MAX(customer_phone) AS customer_phone,
                       COUNT(id) AS total_bookings,
                       COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN amount ELSE 0 END), 0) AS total_spent,
                       MAX(created_at) AS last_booking_date
                FROM {$wpdb->prefix}scb_bookings
                WHERE {$where}
                GROUP BY customer_email
                ORDER BY {$order_by_sql} {$order_param}
                LIMIT 100";

        $customers = $wpdb->get_results(empty($params) ? $sql : $wpdb->prepare($sql, $params), ARRAY_A);

        return rest_ensure_response(['success' => true, 'data' => ['items' => $customers ?: []]]);
    }
}
