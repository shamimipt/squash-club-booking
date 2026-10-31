<?php
namespace SquashClubBooking\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles plugin activation: creates database tables and sets up defaults.
 *
 * @package SquashClubBooking\Core
 */
class Activator {

    /**
     * Run on plugin activation.
     *
     * @param bool $network_wide
     */
    public static function activate( bool $network_wide = false ): void {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }

        // Verify PHP version.
        if ( version_compare( PHP_VERSION, SCB_MIN_PHP, '<' ) ) {
            deactivate_plugins( plugin_basename( SCB_PLUGIN_FILE ) );
            wp_die(
                sprintf(
                    /* translators: %s: Minimum PHP version */
                    esc_html__( 'Squash Club Booking requires PHP %s or higher.', 'squash-club-booking' ),
                    SCB_MIN_PHP
                )
            );
        }

        // Verify WP version.
        global $wp_version;
        if ( version_compare( $wp_version, SCB_MIN_WP, '<' ) ) {
            deactivate_plugins( plugin_basename( SCB_PLUGIN_FILE ) );
            wp_die(
                sprintf(
                    /* translators: %s: Minimum WP version */
                    esc_html__( 'Squash Club Booking requires WordPress %s or higher.', 'squash-club-booking' ),
                    SCB_MIN_WP
                )
            );
        }

        // Run database setup.
        \SquashClubBooking\Database\Schema::install();

        // Create default settings.
        \SquashClubBooking\Core\Settings::install_defaults();

        // Register capabilities.
        \SquashClubBooking\Core\Capabilities::add_caps();

        // Schedule recurring events.
        \SquashClubBooking\Core\Cron::schedule_events();

        // Flush rewrite rules.
        flush_rewrite_rules();

        // Store activation timestamp.
        update_option( 'scb_activated_at', current_time( 'mysql' ) );
        update_option( 'scb_db_version', SCB_DB_VERSION );
    }
}
