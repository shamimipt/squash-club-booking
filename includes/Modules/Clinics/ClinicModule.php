<?php
namespace SquashClubBooking\Modules\Clinics;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Sunday Clinics module coordinator.
 *
 * @package SquashClubBooking\Modules\Clinics
 */
class ClinicModule {

    /**
     * Register module hooks.
     */
    public function register(): void {
        add_action( 'scb_generate_weekly_slots',   [ $this, 'cron_generate_slots' ] );
        add_action( 'scb_weekly_slot_generation', [ $this, 'cron_generate_slots' ] );
    }

    /**
     * Cron callback: generate upcoming Sunday clinic slots.
     */
    public function cron_generate_slots(): void {
        if ( ! \SquashClubBooking\Core\Settings::get( 'clinic_auto_generate', false ) ) {
            return;
        }
        SlotGenerator::generate_multiple_sundays( 1 );
    }
}
