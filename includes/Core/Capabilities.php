<?php
namespace SquashClubBooking\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Custom capabilities for plugin access control.
 *
 * Registers and removes capabilities on the administrator role.
 * A dedicated 'scb_manager' meta capability allows granting
 * plugin access without full administrator privileges.
 *
 * @package SquashClubBooking\Core
 */
class Capabilities {

    /**
     * All plugin-specific capabilities.
     */
    const CAPS = [
        // Global access.
        'scb_access_dashboard',
        'scb_view_bookings',
        'scb_manage_bookings',
        'scb_approve_bookings',

        // Clinics.
        'scb_manage_clinics',
        'scb_view_clinic_slots',

        // Lessons / Coaches.
        'scb_manage_coaches',
        'scb_manage_lessons',
        'scb_manage_schedules',

        // Camps.
        'scb_manage_camps',
        'scb_view_registrations',
        'scb_manage_registrations',

        // Waitlist.
        'scb_manage_waitlist',

        // Payments.
        'scb_view_payments',
        'scb_manage_payments',

        // Reports / Export.
        'scb_view_reports',
        'scb_export_data',

        // Notifications.
        'scb_manage_notifications',

        // Settings.
        'scb_manage_settings',

        // Audit log.
        'scb_view_audit_log',
    ];

    /**
     * Add all capabilities to the administrator role.
     */
    public static function add_caps(): void {
        $admin = get_role( 'administrator' );

        if ( ! $admin ) {
            return;
        }

        foreach ( self::CAPS as $cap ) {
            $admin->add_cap( $cap );
        }
    }

    /**
     * Remove all capabilities from all roles. Called on uninstall.
     */
    public static function remove_caps(): void {
        global $wp_roles;

        if ( ! isset( $wp_roles ) ) {
            $wp_roles = new \WP_Roles();
        }

        foreach ( array_keys( $wp_roles->roles ) as $role_slug ) {
            $role = get_role( $role_slug );
            if ( $role ) {
                foreach ( self::CAPS as $cap ) {
                    $role->remove_cap( $cap );
                }
            }
        }
    }

    /**
     * Register hook to map meta capability 'scb_manager'.
     */
    public function register(): void {
        add_filter( 'map_meta_cap', [ $this, 'map_meta_caps' ], 10, 4 );
    }

    /**
     * Map the 'scb_manager' meta cap to the full suite of SCB caps.
     *
     * @param string[] $caps    Primitive caps array.
     * @param string   $cap     Requested cap.
     * @param int      $user_id User ID.
     * @param array    $args    Additional arguments.
     * @return string[]
     */
    public function map_meta_caps( array $caps, string $cap, int $user_id, array $args ): array {
        if ( 'scb_manager' === $cap ) {
            // A user with any SCB management cap qualifies.
            $has_access = false;
            foreach ( [ 'scb_access_dashboard', 'scb_manage_bookings' ] as $check_cap ) {
                if ( user_can( $user_id, $check_cap ) ) {
                    $has_access = true;
                    break;
                }
            }
            $caps = $has_access ? [ 'exist' ] : [ 'do_not_allow' ];
        }
        return $caps;
    }
}
