<?php
namespace SquashClubBooking\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Plugin settings manager.
 *
 * Provides typed get/set/delete for all SCB configuration.
 * Settings are stored in wp_scb_settings (for plugin-specific keys)
 * AND wp_options (for interop with WP Settings API).
 *
 * @package SquashClubBooking\Core
 */
class Settings {

    /** @var array In-memory cache */
    private static array $cache = [];

    /**
     * Install default settings on activation.
     */
    public static function install_defaults(): void {
        $defaults = self::get_defaults();
        foreach ( $defaults as $key => $value ) {
            if ( false === self::get( $key, false ) ) {
                self::set( $key, $value );
            }
        }
    }

    /**
     * All default setting values.
     *
     * @return array
     */
    public static function get_defaults(): array {
        return [
            // General.
            'timezone'                     => wp_timezone_string(),
            'currency'                     => 'USD',
            'date_format'                  => 'Y-m-d',
            'time_format'                  => 'g:i A',

            // Sunday Clinic.
            'clinic_enabled'               => true,
            'clinic_capacity_default'      => 15,
            'clinic_booking_open_days'     => 7,   // Days before slot opens for booking.
            'clinic_booking_close_hours'   => 2,   // Hours before slot booking closes.
            'clinic_price_junior'          => 25.00,
            'clinic_price_adult'           => 30.00,
            'clinic_auto_generate'         => false,
            'clinic_slots'                 => [
                [ 'time' => '09:00', 'duration' => 60, 'category' => 'junior' ],
                [ 'time' => '10:00', 'duration' => 60, 'category' => 'junior' ],
                [ 'time' => '11:00', 'duration' => 60, 'category' => 'junior' ],
                [ 'time' => '12:00', 'duration' => 60, 'category' => 'adult'  ],
            ],

            // Private Lessons.
            'lesson_enabled'               => true,
            'lesson_private_capacity'      => 1,
            'lesson_semi_private_capacity' => 4,
            'lesson_duration_default'      => 60,   // Minutes.
            'lesson_price_private'         => 80.00,
            'lesson_price_semi_private'    => 40.00,
            'lesson_booking_buffer'        => 24,   // Hours notice required.
            'lesson_cancellation_hours'    => 24,   // Hours before cancellation allowed.

            // Summer Camps.
            'camp_enabled'                 => true,
            'camp_reservation_expiry'      => 30,  // Minutes to hold capacity without payment.

            // Payments.
            'payment_enabled'              => true,
            'pending_payment_expiry'       => 60,  // Minutes before unpaid order releases capacity.

            // Notifications.
            'admin_email'                  => get_option( 'admin_email' ),
            'admin_email_new_booking'      => true,
            'admin_email_new_camp'         => true,
            'email_from_name'              => get_option( 'blogname' ),
            'email_from_address'           => get_option( 'admin_email' ),

            // Waitlist.
            'waitlist_enabled'             => true,
            'waitlist_promotion_expiry'    => 24,  // Hours waitlisted customer has to accept.

            // Frontend Shortcode Pages.
            'page_clinic_id'               => 0,
            'page_lesson_id'               => 0,
            'page_camp_id'                 => 0,
            'page_portal_id'               => 0,
            'page_status_id'               => 0,

            // General UI.
            'booking_page_id'              => 0,
            'camps_page_id'                => 0,
        ];
    }

    /**
     * Get all registered frontend shortcodes with their metadata and detected pages.
     *
     * @return array
     */
    public static function get_shortcodes(): array {
        global $wpdb;

        $shortcodes = [
            [
                'tag'              => 'scb_sunday_clinic',
                'shortcode'        => '[scb_sunday_clinic]',
                'title'            => __( 'Sunday Squash Clinic Booking', 'squash-club-booking' ),
                'description'      => __( 'Interactive Sunday Clinic schedule, Junior and Adult session selector, booking form, and automatic waitlist when sessions are full.', 'squash-club-booking' ),
                'recommended_page' => 'Sunday Squash Clinics',
                'recommended_slug' => 'sunday-clinics',
                'category'         => 'Clinics',
                'setting_key'      => 'page_clinic_id',
            ],
            [
                'tag'              => 'scb_private_lesson',
                'shortcode'        => '[scb_private_lesson]',
                'title'            => __( 'Saturday Private & Semi-Private Lessons', 'squash-club-booking' ),
                'description'      => __( 'Private (1-on-1) and Semi-Private (2 players) lesson booking widget with coach selector and real-time slot availability.', 'squash-club-booking' ),
                'recommended_page' => 'Private Lessons',
                'recommended_slug' => 'private-lessons',
                'category'         => 'Lessons',
                'setting_key'      => 'page_lesson_id',
            ],
            [
                'tag'              => 'scb_summer_camp',
                'shortcode'        => '[scb_summer_camp]',
                'title'            => __( 'Summer Camp Registration', 'squash-club-booking' ),
                'description'      => __( 'Summer camp registration form with weekly session picker, participant age validation, and parent/emergency contact fields.', 'squash-club-booking' ),
                'recommended_page' => 'Summer Camps',
                'recommended_slug' => 'summer-camps',
                'category'         => 'Camps',
                'setting_key'      => 'page_camp_id',
            ],
            [
                'tag'              => 'scb_my_bookings',
                'shortcode'        => '[scb_my_bookings]',
                'title'            => __( 'Customer Booking Portal', 'squash-club-booking' ),
                'description'      => __( 'Customer self-service portal displaying logged-in member past and upcoming bookings, status, and details.', 'squash-club-booking' ),
                'recommended_page' => 'My Bookings',
                'recommended_slug' => 'my-bookings',
                'category'         => 'Customer Portal',
                'setting_key'      => 'page_portal_id',
            ],
            [
                'tag'              => 'scb_booking_status',
                'shortcode'        => '[scb_booking_status]',
                'title'            => __( 'Live Booking Status Lookup', 'squash-club-booking' ),
                'description'      => __( 'Search widget for customers to check real-time booking status, confirmation, and payment details using their booking reference code (e.g. SCB-CLN-...).', 'squash-club-booking' ),
                'recommended_page' => 'Booking Status',
                'recommended_slug' => 'booking-status',
                'category'         => 'Lookup',
                'setting_key'      => 'page_status_id',
            ],
        ];

        // Attach live page detection info.
        foreach ( $shortcodes as &$sc ) {
            $saved_page_id = (int) self::get( $sc['setting_key'], 0 );
            $page_post     = null;

            if ( $saved_page_id > 0 ) {
                $page_post = get_post( $saved_page_id );
                if ( ! $page_post || $page_post->post_status === 'trash' ) {
                    $page_post = null;
                }
            }

            // If not found from setting, search pages for shortcode in content.
            if ( ! $page_post ) {
                $found_id = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s LIMIT 1",
                    '%' . $wpdb->esc_like( '[' . $sc['tag'] ) . '%'
                ) );
                if ( $found_id > 0 ) {
                    $page_post = get_post( $found_id );
                    self::set( $sc['setting_key'], $found_id );
                }
            }

            if ( $page_post ) {
                $sc['page_id']    = (int) $page_post->ID;
                $sc['page_title'] = $page_post->post_title;
                $sc['page_url']   = get_permalink( $page_post->ID );
                $sc['has_page']   = true;
            } else {
                $sc['page_id']    = 0;
                $sc['page_title'] = '';
                $sc['page_url']   = '';
                $sc['has_page']   = false;
            }
        }
        unset( $sc );

        return $shortcodes;
    }

    /** @var bool Whether autoloaded settings have been populated */
    private static bool $autoloaded = false;

    /** @var array Key aliases for backward compatibility */
    private static array $aliases = [
        'private_lesson_price'      => 'lesson_price_private',
        'semi_private_lesson_price' => 'lesson_price_semi_private',
        'clinic_capacity'           => 'clinic_capacity_default',
        'lesson_price_private'      => 'private_lesson_price',
        'lesson_price_semi_private' => 'semi_private_lesson_price',
        'clinic_capacity_default'   => 'clinic_capacity',
        'lesson_duration'           => 'lesson_duration_default',
        'lesson_duration_default'   => 'lesson_duration',
    ];

    /**
     * Load all autoloaded settings into memory in a single query.
     */
    private static function load_autoloaded(): void {
        if ( self::$autoloaded ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'scb_settings';

        // Check if table exists before querying (prevents errors during initial install).
        $rows = $wpdb->get_results( "SELECT setting_key, setting_value FROM {$table} WHERE autoload = 1", ARRAY_A );

        if ( ! empty( $rows ) ) {
            foreach ( $rows as $row ) {
                $k = $row['setting_key'];
                if ( ! isset( self::$cache[ $k ] ) ) {
                    self::$cache[ $k ] = self::decode_value( $row['setting_value'] );
                }
            }
        }

        self::$autoloaded = true;
    }

    /**
     * Decode a stored setting value (unserialize or json decode).
     *
     * @param string $raw
     * @return mixed
     */
    private static function decode_value( string $raw ) {
        $value = maybe_unserialize( $raw );
        if ( is_string( $value ) ) {
            $decoded = json_decode( $value, true );
            if ( json_last_error() === JSON_ERROR_NONE && ( is_array( $decoded ) || is_object( $decoded ) ) ) {
                $value = $decoded;
            }
        }
        return $value;
    }

    /**
     * Get a setting value.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public static function get( string $key, $default = null ) {
        self::load_autoloaded();

        if ( array_key_exists( $key, self::$cache ) && self::$cache[ $key ] !== null ) {
            return self::$cache[ $key ];
        }

        if ( isset( self::$aliases[ $key ] ) ) {
            $alias = self::$aliases[ $key ];
            if ( array_key_exists( $alias, self::$cache ) && self::$cache[ $alias ] !== null ) {
                return self::$cache[ $alias ];
            }
        }

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT setting_value FROM {$wpdb->prefix}scb_settings WHERE setting_key = %s LIMIT 1",
                $key
            )
        );

        if ( null === $row && isset( self::$aliases[ $key ] ) ) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT setting_value FROM {$wpdb->prefix}scb_settings WHERE setting_key = %s LIMIT 1",
                    self::$aliases[ $key ]
                )
            );
        }

        if ( null === $row ) {
            self::$cache[ $key ] = $default;
            return $default;
        }

        $value = self::decode_value( $row->setting_value );
        self::$cache[ $key ] = $value;
        if ( isset( self::$aliases[ $key ] ) ) {
            self::$cache[ self::$aliases[ $key ] ] = $value;
        }

        return $value;
    }

    /**
     * Set a setting value.
     *
     * @param string $key
     * @param mixed  $value
     * @return bool
     */
    public static function set( string $key, $value ): bool {
        global $wpdb;

        // Encode arrays/objects as JSON for clean storage.
        if ( is_array( $value ) || is_object( $value ) ) {
            $stored = wp_json_encode( $value );
        } else {
            $stored = maybe_serialize( $value );
        }

        $result = $wpdb->replace(
            $wpdb->prefix . 'scb_settings',
            [
                'setting_key'   => $key,
                'setting_value' => $stored,
                'autoload'      => 1,
            ],
            [ '%s', '%s', '%d' ]
        );

        if ( false !== $result ) {
            self::$cache[ $key ] = $value;
            return true;
        }

        return false;
    }

    /**
     * Delete a setting.
     *
     * @param string $key
     * @return bool
     */
    public static function delete( string $key ): bool {
        global $wpdb;

        $result = $wpdb->delete(
            $wpdb->prefix . 'scb_settings',
            [ 'setting_key' => $key ],
            [ '%s' ]
        );

        unset( self::$cache[ $key ] );

        return false !== $result;
    }

    /**
     * Clear the in-memory cache (useful after bulk updates).
     */
    public static function flush_cache(): void {
        self::$cache      = [];
        self::$autoloaded = false;
    }
}
