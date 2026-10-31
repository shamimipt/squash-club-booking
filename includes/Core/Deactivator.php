<?php
namespace SquashClubBooking\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles plugin deactivation.
 *
 * @package SquashClubBooking\Core
 */
class Deactivator {

    /**
     * Run on plugin deactivation.
     */
    public static function deactivate(): void {
        // Clear scheduled cron events.
        \SquashClubBooking\Core\Cron::clear_events();

        // Flush rewrite rules.
        flush_rewrite_rules();
    }
}
