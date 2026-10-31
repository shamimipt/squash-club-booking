<?php
namespace SquashClubBooking\Admin\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use SquashClubBooking\Core\Settings;
use SquashClubBooking\Core\AuditLog;

/**
 * Controller for Settings management in the admin dashboard.
 *
 * @package SquashClubBooking\Admin\Controllers
 */
class SettingsController {

    /**
     * Get all plugin settings.
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public static function index( \WP_REST_Request $r ): \WP_REST_Response {
        $defaults = Settings::get_defaults();
        $current  = [];

        foreach ( $defaults as $key => $default_val ) {
            $current[ $key ] = Settings::get( $key, $default_val );
        }

        // Additional dynamic info for settings page.
        $current['club_name']               = Settings::get( 'club_name', get_bloginfo( 'name' ) );
        $current['require_admin_approval']  = (bool) Settings::get( 'require_admin_approval', false );
        $current['enable_notifications']    = (bool) Settings::get( 'enable_notifications', true );
        $current['notification_from_email'] = Settings::get( 'notification_from_email', get_option( 'admin_email' ) );
        $current['admin_notification_email']= Settings::get( 'admin_notification_email', get_option( 'admin_email' ) );
        $current['woocommerce_active']      = class_exists( 'WooCommerce' );
        $current['shortcodes']              = Settings::get_shortcodes();

        return rest_ensure_response( [
            'success' => true,
            'data'    => $current,
        ] );
    }

    /**
     * Auto-create default WordPress pages with their corresponding shortcodes.
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public static function create_pages( \WP_REST_Request $r ): \WP_REST_Response {
        $shortcodes = Settings::get_shortcodes();
        $created    = 0;
        $existed    = 0;

        foreach ( $shortcodes as $sc ) {
            if ( ! empty( $sc['has_page'] ) && $sc['page_id'] > 0 ) {
                $existed++;
                continue;
            }

            $page_id = wp_insert_post( [
                'post_title'   => $sc['recommended_page'],
                'post_name'    => $sc['recommended_slug'],
                'post_content' => $sc['shortcode'],
                'post_status'  => 'publish',
                'post_type'    => 'page',
            ] );

            if ( ! is_wp_error( $page_id ) && $page_id > 0 ) {
                Settings::set( $sc['setting_key'], $page_id );
                $created++;
            }
        }

        Settings::flush_cache();

        return rest_ensure_response( [
            'success'    => true,
            'message'    => sprintf(
                /* translators: 1: number of created pages, 2: number of existing pages */
                __( 'Completed! %1$d page(s) created, %2$d page(s) already existed.', 'squash-club-booking' ),
                $created,
                $existed
            ),
            'shortcodes' => Settings::get_shortcodes(),
        ] );
    }

    /**
     * Update settings.
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public static function update( \WP_REST_Request $r ): \WP_REST_Response {
        $params = $r->get_json_params() ?: [];

        foreach ( $params as $key => $value ) {
            $sanitized_key = sanitize_key( $key );

            if ( is_bool( $value ) ) {
                Settings::set( $sanitized_key, (bool) $value );
            } elseif ( is_numeric( $value ) ) {
                Settings::set( $sanitized_key, strpos( (string) $value, '.' ) !== false ? floatval( $value ) : intval( $value ) );
            } elseif ( is_array( $value ) ) {
                Settings::set( $sanitized_key, $value );
            } else {
                Settings::set( $sanitized_key, sanitize_text_field( (string) $value ) );
            }
        }

        Settings::flush_cache();
        AuditLog::log( 'settings_updated', 'settings', 0, [], array_keys( $params ) );

        return rest_ensure_response( [
            'success' => true,
            'message' => __( 'Settings saved successfully.', 'squash-club-booking' ),
        ] );
    }

    /**
     * Reset all plugin tables and restore fresh-install state.
     *
     * @param \WP_REST_Request $r
     * @return \WP_REST_Response
     */
    public static function reset_tables( \WP_REST_Request $r ): \WP_REST_Response {
        \SquashClubBooking\Database\Schema::reset_all_data();

        return rest_ensure_response( [
            'success' => true,
            'message' => __( 'All plugin tables have been successfully wiped and reset to fresh installation state.', 'squash-club-booking' ),
        ] );
    }
}
