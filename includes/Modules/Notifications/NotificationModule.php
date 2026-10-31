<?php
namespace SquashClubBooking\Modules\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use SquashClubBooking\Core\Settings;

/**
 * Handles all email notifications for bookings, camps, and waitlists.
 *
 * @package SquashClubBooking\Modules\Notifications
 */
class NotificationModule {

    /**
     * Register hooks.
     */
    public function register(): void {
        // Module hooks if needed.
    }

    /**
     * Wrap email content in a styled HTML responsive layout.
     *
     * @param string $heading
     * @param string $content_html
     * @return string
     */
    public static function get_html_template( string $heading, string $content_html ): string {
        $club_name = esc_html( Settings::get( 'club_name', 'Squash Club' ) );
        $logo_text = $club_name;

        return '
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . esc_html( $heading ) . '</title>
    <style>
        body { margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #1e293b; line-height: 1.6; }
        .wrapper { width: 100%; max-width: 600px; margin: 30px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08); border: 1px solid #e2e8f0; }
        .header { background: #0f172a; padding: 28px 32px; color: #ffffff; text-align: center; }
        .header h1 { margin: 0; font-size: 20px; font-weight: 700; letter-spacing: -0.02em; color: #ffffff; }
        .header p { margin: 6px 0 0; color: #14b8a6; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }
        .body-content { padding: 32px; font-size: 15px; }
        .body-content h2 { margin-top: 0; font-size: 18px; color: #0f172a; font-weight: 600; border-bottom: 2px solid #f1f5f9; padding-bottom: 12px; }
        .info-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px; margin: 20px 0; }
        .info-row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #f1f5f9; }
        .info-row:last-child { border-bottom: none; }
        .info-label { color: #64748b; font-size: 13px; font-weight: 500; }
        .info-val { color: #0f172a; font-size: 14px; font-weight: 600; text-align: right; }
        .status-badge { display: inline-block; padding: 4px 10px; border-radius: 9999px; font-size: 12px; font-weight: 600; }
        .badge-approved { background: #dcfce7; color: #15803d; }
        .badge-pending { background: #fef3c7; color: #b45309; }
        .badge-waitlisted { background: #e0f2fe; color: #0369a1; }
        .badge-cancelled { background: #fee2e2; color: #b91c1c; }
        .btn { display: inline-block; background: #0ea5e9; color: #ffffff !important; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 14px; margin-top: 16px; text-align: center; }
        .footer { background: #f8fafc; padding: 20px 32px; text-align: center; font-size: 12px; color: #94a3b8; border-top: 1px solid #e2e8f0; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="header">
            <h1>' . $logo_text . '</h1>
            <p>' . esc_html__( 'Sports Booking & Management System', 'squash-club-booking' ) . '</p>
        </div>
        <div class="body-content">
            <h2>' . esc_html( $heading ) . '</h2>
            ' . $content_html . '
        </div>
        <div class="footer">
            <p>' . sprintf( esc_html__( 'Thank you for choosing %s.', 'squash-club-booking' ), $club_name ) . '</p>
            <p>' . esc_html__( 'This is an automated notification. Please contact club administration if you have questions.', 'squash-club-booking' ) . '</p>
        </div>
    </div>
</body>
</html>';
    }

    /**
     * Send email and record in database log.
     *
     * @param string $key
     * @param string $entity_type 'booking'|'camp_registration'|'waitlist'
     * @param int $entity_id
     * @param string $recipient_email
     * @param string $recipient_name
     * @param string $subject
     * @param string $html_body
     * @return bool
     */
    public static function send( string $key, string $entity_type, int $entity_id, string $recipient_email, string $recipient_name, string $subject, string $html_body ): bool {
        global $wpdb;

        if ( ! is_email( $recipient_email ) ) {
            return false;
        }

        // Check if notifications are disabled in settings.
        if ( ! Settings::get( 'enable_notifications', true ) ) {
            return false;
        }

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
        ];

        $from_name  = Settings::get( 'club_name', get_bloginfo( 'name' ) );
        $from_email = Settings::get( 'notification_from_email', get_option( 'admin_email' ) );

        if ( is_email( $from_email ) ) {
            $headers[] = sprintf( 'From: %s <%s>', $from_name, $from_email );
        }

        $sent = wp_mail( $recipient_email, $subject, $html_body, $headers );

        // Log notification to database.
        $wpdb->insert(
            $wpdb->prefix . 'scb_notifications',
            [
                'notification_key' => sanitize_key( $key ),
                'entity_type'     => sanitize_key( $entity_type ),
                'entity_id'       => $entity_id,
                'recipient_email' => sanitize_email( $recipient_email ),
                'recipient_name'  => sanitize_text_field( $recipient_name ),
                'subject'         => sanitize_text_field( $subject ),
                'status'          => $sent ? 'sent' : 'failed',
                'error_message'   => $sent ? null : __( 'wp_mail returned false', 'squash-club-booking' ),
                'sent_at'         => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        return (bool) $sent;
    }

    /**
     * Send booking submission confirmation to customer.
     *
     * @param object|array $booking
     * @param string $extra_details
     */
    public static function send_booking_confirmation( $booking, string $extra_details = '' ): void {
        $b = (object) $booking;

        $subject = sprintf(
            /* translators: 1: Club name, 2: Booking ref */
            __( '[%1$s] Booking Confirmation: %2$s', 'squash-club-booking' ),
            Settings::get( 'club_name', 'Squash Club' ),
            $b->booking_ref
        );

        $service_name = ucwords( str_replace( '_', ' ', $b->service_type ) );

        $content = '<p>' . sprintf( __( 'Hello <strong>%s</strong>,', 'squash-club-booking' ), esc_html( $b->customer_name ) ) . '</p>';
        $content .= '<p>' . __( 'Thank you for your booking. Here are your booking details:', 'squash-club-booking' ) . '</p>';

        $content .= '<div class="info-card">';
        $content .= '<div class="info-row"><span class="info-label">' . __( 'Booking Reference', 'squash-club-booking' ) . '</span><span class="info-val"><strong>' . esc_html( $b->booking_ref ) . '</strong></span></div>';
        $content .= '<div class="info-row"><span class="info-label">' . __( 'Service', 'squash-club-booking' ) . '</span><span class="info-val">' . esc_html( $service_name ) . '</span></div>';
        $content .= '<div class="info-row"><span class="info-label">' . __( 'Status', 'squash-club-booking' ) . '</span><span class="info-val"><span class="status-badge badge-' . esc_attr( $b->booking_status ) . '">' . esc_html( ucfirst( $b->booking_status ) ) . '</span></span></div>';
        if ( ! empty( $b->amount ) && floatval( $b->amount ) > 0 ) {
            $content .= '<div class="info-row"><span class="info-label">' . __( 'Amount', 'squash-club-booking' ) . '</span><span class="info-val">$' . number_format( (float) $b->amount, 2 ) . '</span></div>';
            $content .= '<div class="info-row"><span class="info-label">' . __( 'Payment Status', 'squash-club-booking' ) . '</span><span class="info-val">' . esc_html( ucfirst( $b->payment_status ) ) . '</span></div>';
        }
        $content .= $extra_details;
        $content .= '</div>';

        $content .= '<p>' . __( 'If you have any questions or need to make changes, please contact the club reception.', 'squash-club-booking' ) . '</p>';

        $html = self::get_html_template( __( 'Booking Confirmation', 'squash-club-booking' ), $content );

        self::send( 'booking_confirmed', 'booking', (int) $b->id, $b->customer_email, $b->customer_name, $subject, $html );
    }

    /**
     * Send admin notification for a new booking.
     *
     * @param object|array $booking
     * @param string $extra_details
     */
    public static function notify_admin_new_booking( $booking, string $extra_details = '' ): void {
        $b = (object) $booking;

        $admin_email = Settings::get( 'admin_notification_email', get_option( 'admin_email' ) );
        if ( ! is_email( $admin_email ) ) {
            return;
        }

        $service_name = ucwords( str_replace( '_', ' ', $b->service_type ) );
        $subject      = sprintf(
            __( '[Admin Alert] New %1$s Booking: %2$s', 'squash-club-booking' ),
            $service_name,
            $b->booking_ref
        );

        $content = '<p>' . sprintf( __( 'A new booking has been placed by <strong>%s</strong> (%s).', 'squash-club-booking' ), esc_html( $b->customer_name ), esc_html( $b->customer_email ) ) . '</p>';
        $content .= '<div class="info-card">';
        $content .= '<div class="info-row"><span class="info-label">' . __( 'Reference', 'squash-club-booking' ) . '</span><span class="info-val">' . esc_html( $b->booking_ref ) . '</span></div>';
        $content .= '<div class="info-row"><span class="info-label">' . __( 'Service', 'squash-club-booking' ) . '</span><span class="info-val">' . esc_html( $service_name ) . '</span></div>';
        $content .= '<div class="info-row"><span class="info-label">' . __( 'Phone', 'squash-club-booking' ) . '</span><span class="info-val">' . esc_html( $b->customer_phone ?: '—' ) . '</span></div>';
        $content .= '<div class="info-row"><span class="info-label">' . __( 'Status', 'squash-club-booking' ) . '</span><span class="info-val">' . esc_html( ucfirst( $b->booking_status ) ) . '</span></div>';
        $content .= $extra_details;
        $content .= '</div>';

        $admin_url = admin_url( 'admin.php?page=scb-bookings' );
        $content .= '<p style="text-align: center;"><a href="' . esc_url( $admin_url ) . '" class="btn">' . __( 'View Booking in Dashboard', 'squash-club-booking' ) . '</a></p>';

        $html = self::get_html_template( __( 'New Booking Received', 'squash-club-booking' ), $content );

        self::send( 'admin_new_booking', 'booking', (int) $b->id, $admin_email, 'Admin', $subject, $html );
    }

    /**
     * Send booking status update to customer (approved, rejected, cancelled, rescheduled).
     *
     * @param object|array $booking
     * @param string $status
     * @param string $reason
     */
    public static function send_status_update( $booking, string $status, string $reason = '' ): void {
        $b = (object) $booking;

        $subject = sprintf(
            __( '[%1$s] Booking %2$s: %3$s', 'squash-club-booking' ),
            Settings::get( 'club_name', 'Squash Club' ),
            ucfirst( $status ),
            $b->booking_ref
        );

        $content = '<p>' . sprintf( __( 'Hello <strong>%s</strong>,', 'squash-club-booking' ), esc_html( $b->customer_name ) ) . '</p>';
        $content .= '<p>' . sprintf( __( 'Your booking <strong>%1$s</strong> status has been updated to: <strong>%2$s</strong>.', 'squash-club-booking' ), esc_html( $b->booking_ref ), strtoupper( $status ) ) . '</p>';

        if ( ! empty( $reason ) ) {
            $content .= '<div class="info-card">';
            $content .= '<div class="info-label">' . __( 'Reason / Notes from Club:', 'squash-club-booking' ) . '</div>';
            $content .= '<p style="margin: 8px 0 0; color: #0f172a;">' . nl2br( esc_html( $reason ) ) . '</p>';
            $content .= '</div>';
        }

        $html = self::get_html_template( sprintf( __( 'Booking Status: %s', 'squash-club-booking' ), ucfirst( $status ) ), $content );

        self::send( 'booking_' . $status, 'booking', (int) $b->id, $b->customer_email, $b->customer_name, $subject, $html );
    }

    /**
     * Send waitlist notification when user joins waitlist.
     *
     * @param object|array $booking
     * @param int $position
     */
    public static function send_waitlist_confirmation( $booking, int $position ): void {
        $b = (object) $booking;

        $subject = sprintf(
            __( '[%1$s] Waitlist Confirmation: %2$s', 'squash-club-booking' ),
            Settings::get( 'club_name', 'Squash Club' ),
            $b->booking_ref
        );

        $content = '<p>' . sprintf( __( 'Hello <strong>%s</strong>,', 'squash-club-booking' ), esc_html( $b->customer_name ) ) . '</p>';
        $content .= '<p>' . __( 'You have been added to the waitlist. As soon as a space opens up, our administration will notify you.', 'squash-club-booking' ) . '</p>';
        $content .= '<div class="info-card">';
        $content .= '<div class="info-row"><span class="info-label">' . __( 'Reference', 'squash-club-booking' ) . '</span><span class="info-val">' . esc_html( $b->booking_ref ) . '</span></div>';
        $content .= '<div class="info-row"><span class="info-label">' . __( 'Waitlist Position', 'squash-club-booking' ) . '</span><span class="info-val"><strong>#' . $position . '</strong></span></div>';
        $content .= '</div>';

        $html = self::get_html_template( __( 'Waitlist Confirmation', 'squash-club-booking' ), $content );

        self::send( 'waitlist_confirmed', 'waitlist', (int) $b->id, $b->customer_email, $b->customer_name, $subject, $html );
    }

    /**
     * Send waitlist promotion notification when space is granted.
     *
     * @param object|array $booking
     */
    public static function send_waitlist_promotion( $booking ): void {
        $b = (object) $booking;

        $subject = sprintf(
            __( '[%1$s] Great News! A Space is Available: %2$s', 'squash-club-booking' ),
            Settings::get( 'club_name', 'Squash Club' ),
            $b->booking_ref
        );

        $content = '<p>' . sprintf( __( 'Hello <strong>%s</strong>,', 'squash-club-booking' ), esc_html( $b->customer_name ) ) . '</p>';
        $content .= '<p>' . __( 'A space has opened up and your booking has been promoted from the waitlist!', 'squash-club-booking' ) . '</p>';
        $content .= '<div class="info-card">';
        $content .= '<div class="info-row"><span class="info-label">' . __( 'Reference', 'squash-club-booking' ) . '</span><span class="info-val"><strong>' . esc_html( $b->booking_ref ) . '</strong></span></div>';
        $content .= '<div class="info-row"><span class="info-label">' . __( 'Status', 'squash-club-booking' ) . '</span><span class="info-val"><span class="status-badge badge-approved">' . __( 'Promoted / Approved', 'squash-club-booking' ) . '</span></span></div>';
        $content .= '</div>';
        $content .= '<p>' . __( 'We look forward to seeing you at the club.', 'squash-club-booking' ) . '</p>';

        $html = self::get_html_template( __( 'Waitlist Promotion', 'squash-club-booking' ), $content );

        self::send( 'waitlist_promoted', 'waitlist', (int) $b->id, $b->customer_email, $b->customer_name, $subject, $html );
    }

    /**
     * Send camp registration confirmation to parent/student.
     *
     * @param object|array $reg
     * @param object|array $camp
     */
    public static function send_camp_confirmation( $reg, $camp ): void {
        $r = (object) $reg;
        $c = (object) $camp;

        $subject = sprintf(
            __( '[%1$s] Summer Camp Registration Confirmed: %2$s', 'squash-club-booking' ),
            Settings::get( 'club_name', 'Squash Club' ),
            $r->registration_ref
        );

        $content = '<p>' . sprintf( __( 'Hello <strong>%s</strong>,', 'squash-club-booking' ), esc_html( $r->parent_name ) ) . '</p>';
        $content .= '<p>' . sprintf( __( 'Thank you for registering <strong>%1$s</strong> for <strong>%2$s</strong>!', 'squash-club-booking' ), esc_html( $r->student_name ), esc_html( $c->title ) ) . '</p>';

        $content .= '<div class="info-card">';
        $content .= '<div class="info-row"><span class="info-label">' . __( 'Registration Ref', 'squash-club-booking' ) . '</span><span class="info-val"><strong>' . esc_html( $r->registration_ref ) . '</strong></span></div>';
        $content .= '<div class="info-row"><span class="info-label">' . __( 'Camp', 'squash-club-booking' ) . '</span><span class="info-val">' . esc_html( $c->title ) . '</span></div>';
        $content .= '<div class="info-row"><span class="info-label">' . __( 'Dates', 'squash-club-booking' ) . '</span><span class="info-val">' . esc_html( $c->start_date ) . ' to ' . esc_html( $c->end_date ) . '</span></div>';
        $content .= '<div class="info-row"><span class="info-label">' . __( 'Student', 'squash-club-booking' ) . '</span><span class="info-val">' . esc_html( $r->student_name ) . ( $r->student_age ? ' (Age ' . $r->student_age . ')' : '' ) . '</span></div>';
        $content .= '<div class="info-row"><span class="info-label">' . __( 'Status', 'squash-club-booking' ) . '</span><span class="info-val">' . esc_html( ucfirst( $r->registration_status ) ) . '</span></div>';
        $content .= '<div class="info-row"><span class="info-label">' . __( 'Fee', 'squash-club-booking' ) . '</span><span class="info-val">$' . number_format( (float) $r->amount, 2 ) . ' (' . esc_html( ucfirst( $r->payment_status ) ) . ')</span></div>';
        $content .= '</div>';

        $html = self::get_html_template( __( 'Camp Registration Confirmed', 'squash-club-booking' ), $content );

        self::send( 'camp_registered', 'camp_registration', (int) $r->id, $r->parent_email, $r->parent_name, $subject, $html );

        // Also notify admin.
        $admin_email = Settings::get( 'admin_notification_email', get_option( 'admin_email' ) );
        if ( is_email( $admin_email ) ) {
            $admin_subject = sprintf( __( '[Admin Alert] New Camp Registration: %1$s (%2$s)', 'squash-club-booking' ), $c->title, $r->student_name );
            $admin_content = '<p>' . sprintf( __( 'A new registration has been received for camp <strong>%1$s</strong>.', 'squash-club-booking' ), esc_html( $c->title ) ) . '</p>';
            $admin_content .= '<div class="info-card">';
            $admin_content .= '<div class="info-row"><span class="info-label">' . __( 'Ref', 'squash-club-booking' ) . '</span><span class="info-val">' . esc_html( $r->registration_ref ) . '</span></div>';
            $admin_content .= '<div class="info-row"><span class="info-label">' . __( 'Student', 'squash-club-booking' ) . '</span><span class="info-val">' . esc_html( $r->student_name ) . '</span></div>';
            $admin_content .= '<div class="info-row"><span class="info-label">' . __( 'Parent', 'squash-club-booking' ) . '</span><span class="info-val">' . esc_html( $r->parent_name ) . ' (' . esc_html( $r->parent_email ) . ' / ' . esc_html( $r->parent_phone ) . ')</span></div>';
            $admin_content .= '<div class="info-row"><span class="info-label">' . __( 'Emergency Contact', 'squash-club-booking' ) . '</span><span class="info-val">' . esc_html( $r->emergency_contact ?: '—' ) . '</span></div>';
            $admin_content .= '</div>';
            $admin_html = self::get_html_template( __( 'New Camp Registration', 'squash-club-booking' ), $admin_content );
            self::send( 'admin_camp_registration', 'camp_registration', (int) $r->id, $admin_email, 'Admin', $admin_subject, $admin_html );
        }
    }
}
