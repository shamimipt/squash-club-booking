<?php
namespace SquashClubBooking\Modules\Clinics;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use SquashClubBooking\Core\Settings;
use SquashClubBooking\Core\AuditLog;

/**
 * Sunday clinic weekly slot generator.
 *
 * Automatically or manually creates standard clinic slots for upcoming Sundays.
 * Default schedule:
 *  - 09:00 - 10:00 : Junior Clinic
 *  - 10:00 - 11:00 : Junior Clinic
 *  - 11:00 - 12:00 : Junior Clinic
 *  - 12:00 - 13:00 : Adult Clinic
 *
 * @package SquashClubBooking\Modules\Clinics
 */
class SlotGenerator {

    /**
     * Standard slot schedule configuration.
     *
     * @return array[]
     */
    public static function get_standard_slots(): array {
        return [
            [
                'start_time' => '09:00:00',
                'end_time'   => '10:00:00',
                'category'   => 'junior',
                'title'      => __( 'Junior Clinic — Morning Session 1', 'squash-club-booking' ),
            ],
            [
                'start_time' => '10:00:00',
                'end_time'   => '11:00:00',
                'category'   => 'junior',
                'title'      => __( 'Junior Clinic — Morning Session 2', 'squash-club-booking' ),
            ],
            [
                'start_time' => '11:00:00',
                'end_time'   => '12:00:00',
                'category'   => 'junior',
                'title'      => __( 'Junior Clinic — Morning Session 3', 'squash-club-booking' ),
            ],
            [
                'start_time' => '12:00:00',
                'end_time'   => '13:00:00',
                'category'   => 'adult',
                'title'      => __( 'Adult Clinic — Noon Session', 'squash-club-booking' ),
            ],
        ];
    }

    /**
     * Generate slots for the next upcoming Sunday (or a specific Sunday date).
     *
     * @param string|null $target_date Y-m-d format of the Sunday. Defaults to next Sunday.
     * @return array Summary of slots created and skipped.
     */
    public static function generate_for_sunday( ?string $target_date = null ): array {
        global $wpdb;

        if ( ! $target_date ) {
            $now = current_time( 'timestamp' );
            $target_date = date( 'Y-m-d', strtotime( 'next sunday', $now ) );
        }

        $table_name = $wpdb->prefix . 'scb_clinic_slots';
        $capacity     = absint( Settings::get( 'clinic_capacity_default', Settings::get( 'clinic_capacity', 15 ) ) );
        $junior_price = floatval( Settings::get( 'clinic_price_junior', 25.00 ) );
        $adult_price  = floatval( Settings::get( 'clinic_price_adult', 30.00 ) );

        $created = 0;
        $skipped = 0;
        $slots   = self::get_standard_slots();

        foreach ( $slots as $slot ) {
            $slot_price = ( $slot['category'] === 'adult' ) ? $adult_price : $junior_price;
            // Check if slot already exists for this date, start_time, and category.
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table_name} WHERE slot_date = %s AND start_time = %s AND category = %s",
                $target_date,
                $slot['start_time'],
                $slot['category']
            ) );

            if ( $exists ) {
                $skipped++;
                continue;
            }

            $inserted = $wpdb->insert(
                $table_name,
                [
                    'slot_date'      => $target_date,
                    'start_time'     => $slot['start_time'],
                    'end_time'       => $slot['end_time'],
                    'category'       => $slot['category'],
                    'capacity'       => $capacity,
                    'booked_count'   => 0,
                    'waitlist_count' => 0,
                    'price'          => $slot_price,
                    'status'         => 'open',
                    'notes'          => $slot['title'],
                    'created_at'     => current_time( 'mysql' ),
                ],
                [ '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%f', '%s', '%s', '%s' ]
            );

            if ( $inserted ) {
                $created++;
            }
        }

        AuditLog::log(
            'slots_generated',
            'clinic_slot',
            0,
            [],
            [ 'date' => $target_date, 'created' => $created, 'skipped' => $skipped ]
        );

        return [
            'date'    => $target_date,
            'created' => $created,
            'skipped' => $skipped,
        ];
    }

    /**
     * Backward-compatible convenience alias.
     *
     * @return array
     */
    public static function generate_for_next_sunday(): array {
        return self::generate_for_sunday();
    }

    /**
     * Generate slots for the next N upcoming Sundays in bulk.
     *
     * @param int $weeks
     * @return array[]
     */
    public static function generate_multiple_sundays( int $weeks = 1 ): array {
        $results = [];
        $now     = current_time( 'timestamp' );
        $first_sunday = ( (int) date( 'w', $now ) === 0 ) ? $now : strtotime( 'next sunday', $now );

        for ( $i = 0; $i < $weeks; $i++ ) {
            $sunday = date( 'Y-m-d', strtotime( "+{$i} weeks", $first_sunday ) );
            $results[] = self::generate_for_sunday( $sunday );
        }

        return $results;
    }
}
