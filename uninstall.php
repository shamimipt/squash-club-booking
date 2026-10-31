<?php
/**
 * Plugin uninstall handler.
 *
 * This file is executed when the plugin is deleted from WordPress.
 * By default it preserves all booking data. Only drops tables when
 * the user has explicitly enabled data deletion in plugin settings.
 *
 * @package SquashClubBooking
 */

// Security check.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Only destroy data if the admin has opted in.
$delete_data = get_option( 'scb_uninstall_delete_data', false );

if ( $delete_data ) {
    // Load the Schema class without the full plugin bootstrap.
    require_once plugin_dir_path( __FILE__ ) . 'includes/Database/Schema.php';

    \SquashClubBooking\Database\Schema::drop_all_tables();

    // Remove all plugin options.
    $options = [
        'scb_activated_at',
        'scb_db_version',
        'scb_uninstall_delete_data',
    ];
    foreach ( $options as $opt ) {
        delete_option( $opt );
    }

    // Remove capabilities from all roles.
    require_once plugin_dir_path( __FILE__ ) . 'includes/Core/Capabilities.php';
    \SquashClubBooking\Core\Capabilities::remove_caps();
}
