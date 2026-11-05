<?php
namespace SquashClubBooking\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Asset (script & style) enqueueing for admin and frontend.
 *
 * @package SquashClubBooking\Core
 */
class Assets {

    /**
     * Register all WordPress hooks.
     */
    public function register(): void {
        add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_admin'    ] );
        add_action( 'wp_enqueue_scripts',     [ $this, 'enqueue_frontend' ], 99 );
    }

    /**
     * Enqueue admin assets only on SCB admin pages.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_admin( string $hook ): void {
        // Only load on SCB pages (those with 'scb' in the page ID or the top-level menu).
        if ( ! $this->is_scb_admin_page() ) {
            return;
        }

        // Fonts: Outfit (Headings) & Inter (Body/Buttons) from Google Fonts matching learnsquash.wpelitee.com theme.
        wp_enqueue_style(
            'scb-google-fonts',
            'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap',
            [],
            null
        );

        $admin_css_file = SCB_PLUGIN_DIR . 'assets/admin/css/admin.css';
        $admin_js_file  = SCB_PLUGIN_DIR . 'assets/admin/js/admin.js';
        $admin_css_ver  = file_exists( $admin_css_file ) ? SCB_VERSION . '.' . filemtime( $admin_css_file ) : SCB_VERSION;
        $admin_js_ver   = file_exists( $admin_js_file )  ? SCB_VERSION . '.' . filemtime( $admin_js_file )  : SCB_VERSION;

        // Main admin CSS.
        wp_enqueue_style(
            'scb-admin',
            SCB_PLUGIN_URL . 'assets/admin/css/admin.css',
            [ 'scb-google-fonts' ],
            $admin_css_ver
        );

        // WordPress built-in: dashicons, wp-color-picker.
        wp_enqueue_media();
        wp_enqueue_style( 'wp-color-picker' );

        // Admin JS (defer non-critical).
        wp_enqueue_script(
            'scb-admin',
            SCB_PLUGIN_URL . 'assets/admin/js/admin.js',
            [ 'jquery', 'wp-color-picker' ],
            $admin_js_ver,
            true  // In footer.
        );

        // Localize for AJAX and nonces.
        wp_localize_script(
            'scb-admin',
            'scbAdmin',
            [
                'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
                'restUrl'       => rest_url( 'scb/v1/' ),
                'nonce'         => wp_create_nonce( 'scb_admin_nonce' ),
                'restNonce'     => wp_create_nonce( 'wp_rest' ),
                'pluginUrl'     => set_url_scheme( SCB_PLUGIN_URL ),
                'dateFormat'    => \SquashClubBooking\Core\Settings::get( 'date_format', 'Y-m-d' ),
                'timeFormat'    => \SquashClubBooking\Core\Settings::get( 'time_format', 'g:i A' ),
                'defaultPrivatePrice'     => floatval( \SquashClubBooking\Core\Settings::get( 'lesson_price_private', 80.00 ) ),
                'defaultSemiPrivatePrice' => floatval( \SquashClubBooking\Core\Settings::get( 'lesson_price_semi_private', 40.00 ) ),
                'clinicPriceJunior'       => floatval( \SquashClubBooking\Core\Settings::get( 'clinic_price_junior', 25.00 ) ),
                'clinicPriceAdult'        => floatval( \SquashClubBooking\Core\Settings::get( 'clinic_price_adult', 30.00 ) ),
                'strings'       => $this->get_admin_js_strings(),
            ]
        );
    }

    /**
     * Enqueue frontend assets only on pages with SCB shortcodes.
     */
    public function enqueue_frontend(): void {
        global $post;

        $should_enqueue = false;

        // Check if current page contains an SCB shortcode.
        if ( is_a( $post, 'WP_Post' ) && $this->page_has_scb_shortcode( $post->post_content ) ) {
            $should_enqueue = true;
        }

        // Check if on WooCommerce My Account page by post ID.
        $my_account_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'myaccount' ) : 0;
        if ( $my_account_id && is_a( $post, 'WP_Post' ) && (int) $post->ID === $my_account_id ) {
            $should_enqueue = true;
        }

        // Enqueue on WooCommerce My Account pages via conditionals.
        if ( function_exists( 'is_account_page' ) && is_account_page() ) {
            $should_enqueue = true;
        }

        // Enqueue on WooCommerce custom my-bookings endpoint URL.
        if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'my-bookings' ) ) {
            $should_enqueue = true;
        }

        // Check query vars for my-bookings rewrite endpoint.
        if ( isset( $GLOBALS['wp']->query_vars['my-bookings'] ) || isset( $_GET['my-bookings'] ) ) {
            $should_enqueue = true;
        }

        if ( ! $should_enqueue ) {
            return;
        }

        // Fonts: Outfit (Headings) & Inter (Body/Buttons) from Google Fonts matching learnsquash.wpelitee.com theme.
        wp_enqueue_style(
            'scb-google-fonts',
            'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap',
            [],
            null
        );

        $fe_css_file = SCB_PLUGIN_DIR . 'assets/frontend/css/frontend.css';
        $fe_js_file  = SCB_PLUGIN_DIR . 'assets/frontend/js/frontend.js';
        $fe_css_ver  = file_exists( $fe_css_file ) ? SCB_VERSION . '.' . filemtime( $fe_css_file ) : SCB_VERSION;
        $fe_js_ver   = file_exists( $fe_js_file )  ? SCB_VERSION . '.' . filemtime( $fe_js_file )  : SCB_VERSION;

        // Frontend CSS.
        wp_enqueue_style(
            'scb-frontend',
            SCB_PLUGIN_URL . 'assets/frontend/css/frontend.css',
            [ 'scb-google-fonts' ],
            $fe_css_ver
        );

        // Frontend JS.
        wp_enqueue_script(
            'scb-frontend',
            SCB_PLUGIN_URL . 'assets/frontend/js/frontend.js',
            [ 'jquery' ],
            $fe_js_ver,
            true
        );

        $current_user   = wp_get_current_user();
        $is_logged_in   = is_user_logged_in();
        $my_account_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url();
        $current_url    = wp_unslash( $_SERVER['REQUEST_URI'] ?? home_url() );

        wp_localize_script(
            'scb-frontend',
            'scbFrontend',
            [
                'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
                'restUrl'     => set_url_scheme( rest_url( 'scb/v1/' ) ),
                'nonce'       => wp_create_nonce( 'scb_frontend_nonce' ),
                'restNonce'   => wp_create_nonce( 'wp_rest' ),
                'pluginUrl'     => set_url_scheme( SCB_PLUGIN_URL ),
                'isLoggedIn'  => $is_logged_in,
                'loginUrl'    => add_query_arg( 'redirect_to', urlencode( $current_url ), $my_account_url ),
                'registerUrl' => add_query_arg( 'redirect_to', urlencode( $current_url ), $my_account_url ),
                'currentUser' => $is_logged_in ? [
                    'id'    => $current_user->ID,
                    'name'  => trim( $current_user->first_name . ' ' . $current_user->last_name ) ?: $current_user->display_name,
                    'email' => $current_user->user_email,
                    'phone' => get_user_meta( $current_user->ID, 'billing_phone', true ),
                ] : null,
                'privatePrice'      => floatval( \SquashClubBooking\Core\Settings::get( 'lesson_price_private', 80.00 ) ),
                'semiPrivatePrice'  => floatval( \SquashClubBooking\Core\Settings::get( 'lesson_price_semi_private', 40.00 ) ),
                'clinicPriceJunior' => floatval( \SquashClubBooking\Core\Settings::get( 'clinic_price_junior', 25.00 ) ),
                'clinicPriceAdult'  => floatval( \SquashClubBooking\Core\Settings::get( 'clinic_price_adult', 30.00 ) ),
                'strings'     => $this->get_frontend_js_strings(),
            ]
        );
    }

    /**
     * Check if we are on an SCB admin page.
     *
     * @return bool
     */
    private function is_scb_admin_page(): bool {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

        if ( ! $screen ) {
            return false;
        }

        // The top-level menu page slug is 'scb-dashboard'.
        // All sub-pages will have IDs containing 'scb-'.
        return (
            strpos( $screen->id, 'scb-' ) !== false ||
            strpos( $screen->base, 'scb-' ) !== false ||
            ( isset( $_GET['page'] ) && strpos( sanitize_key( $_GET['page'] ), 'scb-' ) === 0 ) // phpcs:ignore WordPress.Security.NonceVerification
        );
    }

    /**
     * Check if a post's content contains an SCB shortcode.
     *
     * @param string $content
     * @return bool
     */
    private function page_has_scb_shortcode( string $content ): bool {
        $shortcodes = [
            'scb_sunday_clinic',
            'scb_private_lesson',
            'scb_summer_camp',
            'scb_booking_status',
            'scb_my_bookings',
        ];

        foreach ( $shortcodes as $sc ) {
            if ( has_shortcode( $content, $sc ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Translatable strings for admin JS.
     *
     * @return array
     */
    private function get_admin_js_strings(): array {
        return [
            'confirm'                 => __( 'Are you sure?', 'squash-club-booking' ),
            'cancel'                  => __( 'Cancel', 'squash-club-booking' ),
            'confirm_action'          => __( 'Confirm', 'squash-club-booking' ),
            'saving'                  => __( 'Saving…', 'squash-club-booking' ),
            'saved'                   => __( 'Saved successfully.', 'squash-club-booking' ),
            'error'                   => __( 'An error occurred. Please try again.', 'squash-club-booking' ),
            'loading'                 => __( 'Loading…', 'squash-club-booking' ),
            'delete_confirm'          => __( 'This action cannot be undone. Are you sure?', 'squash-club-booking' ),
            'unsaved_changes'         => __( 'You have unsaved changes. Leave anyway?', 'squash-club-booking' ),
            'booking_approved'        => __( 'Booking approved successfully.', 'squash-club-booking' ),
            'booking_rejected'        => __( 'Booking rejected.', 'squash-club-booking' ),
            'booking_cancelled'       => __( 'Booking cancelled successfully.', 'squash-club-booking' ),
            'booking_rescheduled'     => __( 'Booking rescheduled successfully.', 'squash-club-booking' ),
            'coach_saved'             => __( 'Coach saved successfully.', 'squash-club-booking' ),
            'coach_deleted'           => __( 'Coach deleted.', 'squash-club-booking' ),
            'camp_saved'              => __( 'Camp saved successfully.', 'squash-club-booking' ),
            'settings_saved'          => __( 'Settings saved.', 'squash-club-booking' ),
            'availability_saved'      => __( 'Coach availability saved.', 'squash-club-booking' ),
            'slots_generated'         => __( 'Weekly slots generated.', 'squash-club-booking' ),
            'waitlist_promoted'       => __( 'Waitlist customer promoted.', 'squash-club-booking' ),
            'export_started'          => __( 'Export started. Download will begin shortly.', 'squash-club-booking' ),
        ];
    }

    /**
     * Translatable strings for frontend JS.
     *
     * @return array
     */
    private function get_frontend_js_strings(): array {
        return [
            'loading'        => __( 'Loading…', 'squash-club-booking' ),
            'slot_full'      => __( 'This slot is now full. Please select another.', 'squash-club-booking' ),
            'slot_taken'     => __( 'This time is no longer available.', 'squash-club-booking' ),
            'booking_error'  => __( 'Unable to complete booking. Please try again.', 'squash-club-booking' ),
            'select_time'    => __( 'Please select a time slot.', 'squash-club-booking' ),
            'select_coach'   => __( 'Please select a coach.', 'squash-club-booking' ),
            'required_field' => __( 'This field is required.', 'squash-club-booking' ),
            'invalid_email'  => __( 'Please enter a valid email address.', 'squash-club-booking' ),
            'invalid_phone'  => __( 'Please enter a valid phone number.', 'squash-club-booking' ),
            'invalid_dob'    => __( 'Please enter a valid date of birth.', 'squash-club-booking' ),
            'duplicate_warn' => __( 'You may already have a booking for this slot.', 'squash-club-booking' ),
            'proceed'        => __( 'Proceed to Payment', 'squash-club-booking' ),
            'submitting'     => __( 'Submitting…', 'squash-club-booking' ),
        ];
    }
}
