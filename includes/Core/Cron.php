<?php
namespace SquashClubBooking\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WordPress Cron event scheduler.
 *
 * Schedules recurring jobs for clinic slot generation,
 * waitlist expiry, and payment reservation cleanup.
 *
 * @package SquashClubBooking\Core
 */
class Cron {

    /** Cron event hook names. */
    const HOOK_GENERATE_SLOTS        = 'scb_generate_weekly_slots';
    const HOOK_EXPIRE_WAITLIST       = 'scb_expire_waitlist_promotions';
    const HOOK_EXPIRE_RESERVATIONS   = 'scb_expire_payment_reservations';
    const HOOK_CLEANUP_AUDIT_LOG     = 'scb_cleanup_old_audit_logs';

    /**
     * Register WordPress hooks.
     */
    public function register(): void {
        // Slot generation: every Monday at 00:05 WP timezone.
        add_action( self::HOOK_GENERATE_SLOTS,      [ $this, 'run_generate_slots'      ] );
        add_action( self::HOOK_EXPIRE_WAITLIST,     [ $this, 'run_expire_waitlist'     ] );
        add_action( self::HOOK_EXPIRE_RESERVATIONS, [ $this, 'run_expire_reservations' ] );
        add_action( self::HOOK_CLEANUP_AUDIT_LOG,   [ $this, 'run_cleanup_audit_log'   ] );
    }

    /**
     * Schedule all cron events on plugin activation.
     */
    public static function schedule_events(): void {
        // Weekly slot generation — only if auto-generate is enabled.
        if ( Settings::get( 'clinic_auto_generate', false ) ) {
            if ( ! wp_next_scheduled( self::HOOK_GENERATE_SLOTS ) ) {
                $next_monday = self::next_monday_timestamp();
                wp_schedule_event( $next_monday, 'weekly', self::HOOK_GENERATE_SLOTS );
            }
        } else {
            $ts = wp_next_scheduled( self::HOOK_GENERATE_SLOTS );
            if ( $ts ) {
                wp_unschedule_event( $ts, self::HOOK_GENERATE_SLOTS );
            }
        }

        // Waitlist expiry check — every hour.
        if ( ! wp_next_scheduled( self::HOOK_EXPIRE_WAITLIST ) ) {
            wp_schedule_event( time(), 'hourly', self::HOOK_EXPIRE_WAITLIST );
        }

        // Payment reservation expiry — every 15 minutes.
        if ( ! wp_next_scheduled( self::HOOK_EXPIRE_RESERVATIONS ) ) {
            wp_schedule_event( time(), 'scb_every_15_minutes', self::HOOK_EXPIRE_RESERVATIONS );
        }

        // Monthly audit log cleanup.
        if ( ! wp_next_scheduled( self::HOOK_CLEANUP_AUDIT_LOG ) ) {
            wp_schedule_event( time(), 'monthly', self::HOOK_CLEANUP_AUDIT_LOG );
        }
    }

    /**
     * Register custom cron intervals.
     * Must be called early (init or plugins_loaded).
     */
    public static function add_cron_intervals(): void {
        add_filter( 'cron_schedules', function( array $schedules ): array {
            $schedules['scb_every_15_minutes'] = [
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display'  => __( 'Every 15 Minutes', 'squash-club-booking' ),
            ];
            $schedules['weekly'] = [
                'interval' => WEEK_IN_SECONDS,
                'display'  => __( 'Once Weekly', 'squash-club-booking' ),
            ];
            $schedules['monthly'] = [
                'interval' => 30 * DAY_IN_SECONDS,
                'display'  => __( 'Once Monthly', 'squash-club-booking' ),
            ];
            return $schedules;
        } );
    }

    /**
     * Clear all scheduled cron events on deactivation.
     */
    public static function clear_events(): void {
        $hooks = [
            self::HOOK_GENERATE_SLOTS,
            self::HOOK_EXPIRE_WAITLIST,
            self::HOOK_EXPIRE_RESERVATIONS,
            self::HOOK_CLEANUP_AUDIT_LOG,
        ];

        foreach ( $hooks as $hook ) {
            $timestamp = wp_next_scheduled( $hook );
            if ( $timestamp ) {
                wp_unschedule_event( $timestamp, $hook );
            }
        }
    }

    // ── Cron job handlers ─────────────────────────────────────────────────────

    /**
     * Generate next week's Sunday clinic slots.
     */
    public function run_generate_slots(): void {
        if ( ! Settings::get( 'clinic_auto_generate', false ) ) {
            return;
        }
        if ( class_exists( '\SquashClubBooking\Modules\Clinics\SlotGenerator' ) ) {
            \SquashClubBooking\Modules\Clinics\SlotGenerator::generate_for_next_sunday();
        }
    }

    /**
     * Expire waitlist promotions that were not acted on in time.
     */
    public function run_expire_waitlist(): void {
        if ( class_exists( '\SquashClubBooking\Modules\Waitlist\WaitlistManager' ) ) {
            \SquashClubBooking\Modules\Waitlist\WaitlistManager::expire_stale_promotions();
        }
    }

    /**
     * Release capacity for bookings whose payment has expired.
     */
    public function run_expire_reservations(): void {
        if ( class_exists( '\SquashClubBooking\Modules\Payments\PaymentManager' ) ) {
            \SquashClubBooking\Modules\Payments\PaymentManager::expire_pending_reservations();
        }
    }

    /**
     * Remove audit log entries older than 90 days.
     */
    public function run_cleanup_audit_log(): void {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}scb_audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                90
            )
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Get timestamp of the next Monday at 00:05 in the WP timezone.
     *
     * @return int Unix timestamp.
     */
    private static function next_monday_timestamp(): int {
        $tz        = wp_timezone();
        $now       = new \DateTime( 'now', $tz );
        $day_of_week = (int) $now->format( 'N' ); // 1=Mon, 7=Sun.

        // Days until next Monday.
        $days_until = ( $day_of_week === 1 ) ? 7 : ( 8 - $day_of_week );

        $next_monday = clone $now;
        $next_monday->modify( "+{$days_until} days" );
        $next_monday->setTime( 0, 5, 0 ); // 00:05 AM.

        return $next_monday->getTimestamp();
    }
}

// Register custom intervals very early.
Cron::add_cron_intervals();
