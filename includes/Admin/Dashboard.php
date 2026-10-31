<?php
namespace SquashClubBooking\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin Dashboard — registers the admin menu and renders the SPA-style
 * admin interface for the Squash Club Booking plugin.
 *
 * All actual UI is driven by the admin.js + admin.css assets; this class
 * merely wires up the WordPress admin menu and provides the root HTML shell.
 *
 * @package SquashClubBooking\Admin
 */
class Dashboard {

    /**
     * Register WordPress hooks.
     */
    public function register(): void {
        add_action( 'admin_menu',            [ $this, 'register_menus'     ] );
        add_action( 'admin_bar_menu',        [ $this, 'admin_bar_link'     ], 100 );
        add_action( 'wp_ajax_scb_admin',     [ $this, 'handle_ajax'        ] );
    }

    /**
     * Register the top-level plugin menu and all sub-page entries.
     */
    public function register_menus(): void {
        $icon = $this->get_menu_icon_svg();

        // Top-level menu.
        add_menu_page(
            __( 'Squash Club Booking', 'squash-club-booking' ),
            __( 'Squash Booking', 'squash-club-booking' ),
            'scb_access_dashboard',
            'scb-dashboard',
            [ $this, 'render_app_shell' ],
            $icon,
            30
        );

        // Sub-pages (all render the same shell; JS router handles the view).
        $sub_pages = [
            'scb-dashboard'          => __( 'Overview',        'squash-club-booking' ),
            'scb-bookings'           => __( 'All Bookings',    'squash-club-booking' ),
            'scb-clinics'            => __( 'Sunday Clinics',  'squash-club-booking' ),
            'scb-private-lessons'    => __( 'Private Lessons', 'squash-club-booking' ),
            'scb-coaches'            => __( 'Coaches',         'squash-club-booking' ),
            'scb-schedule'           => __( 'Schedule & Calendar', 'squash-club-booking' ),
            'scb-waitlist'           => __( 'Waitlist',        'squash-club-booking' ),
            'scb-camps'              => __( 'Summer Camps',    'squash-club-booking' ),
            'scb-registrations'      => __( 'Registrations',   'squash-club-booking' ),
            'scb-payments'           => __( 'Payments',        'squash-club-booking' ),
            'scb-customers'          => __( 'Customers',       'squash-club-booking' ),
            'scb-notifications'      => __( 'Notifications',   'squash-club-booking' ),
            'scb-reports'            => __( 'Reports',         'squash-club-booking' ),
            'scb-settings'           => __( 'Settings',        'squash-club-booking' ),
        ];

        // The first item (Overview) already maps to the top-level page.
        $first = true;
        foreach ( $sub_pages as $slug => $label ) {
            $capability = $this->get_page_cap( $slug );
            if ( $first ) {
                // Rename the default submenu item that WP auto-creates.
                add_submenu_page(
                    'scb-dashboard',
                    __( 'Overview — Squash Club Booking', 'squash-club-booking' ),
                    $label,
                    $capability,
                    'scb-dashboard',
                    [ $this, 'render_app_shell' ]
                );
                $first = false;
                continue;
            }

            add_submenu_page(
                'scb-dashboard',
                $label . ' — ' . __( 'Squash Club Booking', 'squash-club-booking' ),
                $label,
                $capability,
                $slug,
                [ $this, 'render_app_shell' ]
            );
        }
    }

    /**
     * Render the root HTML shell into which the JS app mounts.
     *
     * The active page is inferred from the ?page= query arg and
     * passed to the JS layer via a data-page attribute.
     */
    public function render_app_shell(): void {
        if ( ! current_user_can( 'scb_access_dashboard' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions.', 'squash-club-booking' ) );
        }

        $current_page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : 'scb-dashboard'; // phpcs:ignore WordPress.Security.NonceVerification
        $view         = str_replace( 'scb-', '', $current_page );

        // Load the template.
        include SCB_PLUGIN_DIR . 'templates/admin/app-shell.php';
    }

    /**
     * Add a quick-access link in the WP admin bar.
     *
     * @param \WP_Admin_Bar $admin_bar
     */
    public function admin_bar_link( \WP_Admin_Bar $admin_bar ): void {
        if ( ! current_user_can( 'scb_access_dashboard' ) ) {
            return;
        }

        $admin_bar->add_node( [
            'id'    => 'scb-admin-bar',
            'title' => '🎾 ' . __( 'Squash Booking', 'squash-club-booking' ),
            'href'  => admin_url( 'admin.php?page=scb-dashboard' ),
            'meta'  => [ 'title' => __( 'Squash Club Booking Dashboard', 'squash-club-booking' ) ],
        ] );
    }

    /**
     * Central AJAX handler (legacy non-REST requests).
     */
    public function handle_ajax(): void {
        check_ajax_referer( 'scb_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'scb_access_dashboard' ) ) {
            wp_send_json_error( [ 'message' => __( 'Access denied.', 'squash-club-booking' ) ], 403 );
        }

        $action = isset( $_POST['scb_action'] ) ? sanitize_key( $_POST['scb_action'] ) : '';

        /**
         * Fires for a specific SCB AJAX action.
         *
         * @param string $action The requested action key.
         */
        do_action( "scb_ajax_{$action}" );

        wp_send_json_error( [ 'message' => __( 'Unknown action.', 'squash-club-booking' ) ] );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Map admin page slug to the minimum required capability.
     *
     * @param string $slug
     * @return string
     */
    private function get_page_cap( string $slug ): string {
        $map = [
            'scb-dashboard'       => 'scb_access_dashboard',
            'scb-bookings'        => 'scb_view_bookings',
            'scb-clinics'         => 'scb_view_clinic_slots',
            'scb-private-lessons' => 'scb_manage_lessons',
            'scb-coaches'         => 'scb_manage_coaches',
            'scb-schedule'        => 'scb_manage_schedules',
            'scb-waitlist'        => 'scb_manage_waitlist',
            'scb-camps'           => 'scb_manage_camps',
            'scb-registrations'   => 'scb_view_registrations',
            'scb-payments'        => 'scb_view_payments',
            'scb-customers'       => 'scb_view_bookings',
            'scb-notifications'   => 'scb_manage_notifications',
            'scb-reports'         => 'scb_view_reports',
            'scb-settings'        => 'scb_manage_settings',
        ];

        return $map[ $slug ] ?? 'scb_access_dashboard';
    }

    /**
     * Return the SVG icon for the admin menu (base64-encoded).
     *
     * @return string
     */
    private function get_menu_icon_svg(): string {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8a4 4 0 0 0-4 4 4 4 0 0 0 4 4 4 4 0 0 0 4-4 4 4 0 0 0-4-4z"/><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode( $svg );
    }
}
