<?php
/**
 * Plugin Name:       Squash Club Booking & Management System
 * Plugin URI:        https://github.com/squash-club-booking
 * Description:       Complete booking and registration management system for squash clubs. Handles Sunday Clinics, Private Lessons, and Summer Camp registrations with WooCommerce payment integration.
 * Version:           2.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Squash Club
 * Author URI:        #
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       squash-club-booking
 * Domain Path:       /languages
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants.
define( 'SCB_VERSION',      '2.0.1' );
define( 'SCB_PLUGIN_FILE',  __FILE__ );
define( 'SCB_PLUGIN_DIR',   plugin_dir_path( __FILE__ ) );
define( 'SCB_PLUGIN_URL',   plugin_dir_url( __FILE__ ) );
define( 'SCB_PLUGIN_SLUG',  'squash-club-booking' );
define( 'SCB_DB_VERSION',   '1.0.0' );
define( 'SCB_MIN_PHP',      '7.4' );
define( 'SCB_MIN_WP',       '6.0' );

// Check PHP version.
if ( version_compare( PHP_VERSION, SCB_MIN_PHP, '<' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>' .
            sprintf(
                /* translators: %s: Required PHP version */
                esc_html__( 'Squash Club Booking requires PHP %s or higher. Please upgrade your PHP version.', 'squash-club-booking' ),
                SCB_MIN_PHP
            ) .
            '</p></div>';
    } );
    return;
}

// Autoloader.
spl_autoload_register( function( $class ) {
    $prefix   = 'SquashClubBooking\\';
    $base_dir = SCB_PLUGIN_DIR . 'includes/';

    $len = strlen( $prefix );
    if ( strncmp( $prefix, $class, $len ) !== 0 ) {
        return;
    }

    $relative_class = substr( $class, $len );
    $file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

    if ( file_exists( $file ) ) {
        require $file;
    }
} );

/**
 * Returns the main plugin instance.
 *
 * @return \SquashClubBooking\Core\Plugin
 */
function scb() {
    return \SquashClubBooking\Core\Plugin::instance();
}

// Initialize the plugin.
add_action( 'plugins_loaded', function() {
    scb()->init();
}, 10 );

// Activation / Deactivation hooks.
register_activation_hook(   __FILE__, [ '\SquashClubBooking\Core\Activator',   'activate'   ] );
register_deactivation_hook( __FILE__, [ '\SquashClubBooking\Core\Deactivator', 'deactivate' ] );
