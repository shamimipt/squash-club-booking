<?php
namespace SquashClubBooking\Database;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Seeds initial demo/production data for coaches, slots, and camps.
 *
 * @package SquashClubBooking\Database
 */
class Seeder {

    /**
     * Seed initial records if tables are empty.
     */
    public static function seed_if_empty(): void {
        global $wpdb;

        // Avoid executing COUNT queries on every request once seeded.
        if ( get_option( 'scb_seeded', false ) || get_option( 'squash_club_seeded', false ) ) {
            return;
        }

        // Note: Do not auto-generate coaches on fresh install.
        // Coaches should be created manually via "Add New Coach" or generated via "Generate Dummy Data".

        // Note: Do not auto-generate clinic slots on fresh install.
        // Slots should be created by club administration via "Generate Weekly Slots" or "Add Clinic Slot".

        // Check if camps table is empty.
        $camp_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}scb_camps" );
        if ( $camp_count === 0 ) {
            self::seed_camps();
        }

        update_option( 'scb_seeded', 1 );
        update_option( 'squash_club_seeded', 1 );
    }

    /**
     * Seed 3 initial coach profiles with Saturday availability.
     *
     * @return array [ 'created' => int, 'skipped' => int ]
     */
    public static function seed_coaches(): array {
        global $wpdb;

        $coaches = [
            [
                'first_name'   => 'Marcus',
                'last_name'    => 'Sterling',
                'slug'         => 'marcus-sterling',
                'email'        => 'marcus.sterling@squashclub.example.com',
                'phone'        => '(555) 234-8901',
                'photo_url'    => set_url_scheme( SCB_PLUGIN_URL . 'assets/frontend/images/coaches/coach-1.jpg', 'https' ),
                'bio'          => 'Former PSA World Top 35 professional with over 14 years of international tournament experience. Marcus specializes in modern deceptive racket technique, high-paced length rallying, and tactical game plans for tournament contenders.',
                'specialties'  => 'Deceptive Drops, Volley Kills, Match Strategy, High-Paced Rallies',
                'lesson_types' => 'private,semi_private',
                'status'       => 'active',
                'display_order'=> 1,
            ],
            [
                'first_name'   => 'Elena',
                'last_name'    => 'Rostova',
                'slug'         => 'elena-rostova',
                'email'        => 'elena.rostova@squashclub.example.com',
                'phone'        => '(555) 345-6789',
                'photo_url'    => set_url_scheme( SCB_PLUGIN_URL . 'assets/frontend/images/coaches/coach-2.jpg', 'https' ),
                'bio'          => 'Certified Level 3 Elite Coach and former European national champion. Elena is renowned for her biomechanical approach to swing fundamentals, explosive footwork development, and junior elite training.',
                'specialties'  => 'Biomechanical Swing, Explosive Movement, Junior Development, Forehand Technique',
                'lesson_types' => 'private,semi_private',
                'status'       => 'active',
                'display_order'=> 2,
            ],
            [
                'first_name'   => 'Tariq',
                'last_name'    => 'Al-Mansoor',
                'slug'         => 'tariq-al-mansoor',
                'email'        => 'tariq.mansoor@squashclub.example.com',
                'phone'        => '(555) 456-7890',
                'photo_url'    => set_url_scheme( SCB_PLUGIN_URL . 'assets/frontend/images/coaches/coach-3.jpg', 'https' ),
                'bio'          => 'Egyptian style squash specialist focusing on wrist deception, devastating boast variations, and tight dying lengths. Tariq has developed multiple junior national team players.',
                'specialties'  => 'Egyptian Attack, Trick Boasts, Nick Hunting, Front Court Agility',
                'lesson_types' => 'private,semi_private',
                'status'       => 'active',
                'display_order'=> 3,
            ],
            [
                'first_name'   => 'Chloe',
                'last_name'    => 'Beaumont',
                'slug'         => 'chloe-beaumont',
                'email'        => 'chloe.beaumont@squashclub.example.com',
                'phone'        => '(555) 567-8901',
                'photo_url'    => set_url_scheme( SCB_PLUGIN_URL . 'assets/frontend/images/coaches/coach-4.jpg', 'https' ),
                'bio'          => 'Former British Junior Open champion and certified high-performance fitness conditioning specialist. Chloe focuses on dynamic court coverage, endurance rallies, wrist control, and mental resilience under pressure.',
                'specialties'  => 'Agility & Footwork, Cross-Court Volleys, Mental Toughness, Match Conditioning',
                'lesson_types' => 'private,semi_private',
                'status'       => 'active',
                'display_order'=> 4,
            ],
        ];

        $today = current_time( 'Y-m-d' );
        $created = 0;
        $skipped = 0;

        foreach ( $coaches as $c ) {
            // Check if coach with this slug or email already exists.
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}scb_coaches WHERE slug = %s OR email = %s LIMIT 1",
                $c['slug'],
                $c['email']
            ) );

            if ( $exists ) {
                $skipped++;
                continue;
            }

            $c['created_at'] = current_time( 'mysql' );
            $wpdb->insert(
                $wpdb->prefix . 'scb_coaches',
                $c,
                [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
            );
            $coach_id = $wpdb->insert_id;

            if ( $coach_id ) {
                $created++;
                // Saturday availability: 09:00 - 17:00.
                $wpdb->insert(
                    $wpdb->prefix . 'scb_coach_availability',
                    [
                        'coach_id'       => $coach_id,
                        'effective_date' => $today,
                        'day_of_week'    => 6, // Saturday
                        'start_time'     => '09:00:00',
                        'end_time'       => '17:00:00',
                        'lesson_type'    => 'both',
                        'is_blocked'     => 0,
                        'notes'          => 'Standard Saturday Lessons',
                        'created_at'     => current_time( 'mysql' ),
                    ],
                    [ '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' ]
                );
            }
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
        ];
    }

    /**
     * Seed summer camps demo data.
     *
     * @return array [ 'created' => int, 'skipped' => int ]
     */
    public static function seed_camps(): array {
        global $wpdb;

        $camps = [
            [
                'title'                 => 'Junior Squash Fundamentals & Fun Camp',
                'slug'                  => 'junior-squash-fundamentals-fun-camp',
                'description'           => 'An energetic 5-day introductory camp for beginners and young juniors aged 6-10. Covers racquet grip, hand-eye coordination, rules of the game, footwork fundamentals, and fun squash mini-games in a friendly, supportive environment.',
                'image_url'             => set_url_scheme( SCB_PLUGIN_URL . 'assets/frontend/images/camps/camp-1.jpg', 'https' ),
                'location'              => 'Junior Activity Courts 1-3',
                'start_date'            => '2027-06-14',
                'end_date'              => '2027-06-18',
                'schedule_details'      => 'Monday to Friday, 9:00 AM – 1:00 PM (Half-Day Session)',
                'min_age'               => 6,
                'max_age'               => 10,
                'price'                 => 250.00,
                'capacity'              => 18,
                'registered_count'      => 0,
                'registration_deadline' => '2027-06-07',
                'registration_status'   => 'open',
                'publication_status'    => 'published',
                'notes'                 => 'Racquets and safety eyewear provided free of charge for beginners.',
                'created_at'            => current_time( 'mysql' ),
            ],
            [
                'title'                 => 'Youth Intermediate Skill & Tactics Academy',
                'slug'                  => 'youth-intermediate-skill-tactics-academy',
                'description'           => 'Designed for intermediate juniors looking to elevate their match play. Emphasizes volley drops, boast accuracy, cross-court recovery, tactical game planning, video swing analysis, and supervised round-robin matches.',
                'image_url'             => set_url_scheme( SCB_PLUGIN_URL . 'assets/frontend/images/camps/camp-2.jpg', 'https' ),
                'location'              => 'Championship Courts 4-6',
                'start_date'            => '2027-06-28',
                'end_date'              => '2027-07-02',
                'schedule_details'      => 'Monday to Friday, 9:00 AM – 3:30 PM (Full Day, Lunch included)',
                'min_age'               => 10,
                'max_age'               => 14,
                'price'                 => 395.00,
                'capacity'              => 20,
                'registered_count'      => 0,
                'registration_deadline' => '2027-06-21',
                'registration_status'   => 'open',
                'publication_status'    => 'published',
                'notes'                 => 'Nutritious lunch and hydration pack included daily.',
                'created_at'            => current_time( 'mysql' ),
            ],
            [
                'title'                 => 'Elite High-Performance & Tournament Prep Camp',
                'slug'                  => 'elite-high-performance-tournament-prep-camp',
                'description'           => 'High-intensity masterclass for ranked junior tournament players and high school varsity athletes. Focuses on deceptive shot-making, game pressure simulation, sports psychology, mental resilience, and advanced fitness conditioning with former PSA tour pros.',
                'image_url'             => set_url_scheme( SCB_PLUGIN_URL . 'assets/frontend/images/camps/camp-3.jpg', 'https' ),
                'location'              => 'Glass Show Court & Courts 7-8',
                'start_date'            => '2027-07-12',
                'end_date'              => '2027-07-16',
                'schedule_details'      => 'Monday to Friday, 8:30 AM – 4:00 PM (Elite Intensive)',
                'min_age'               => 12,
                'max_age'               => 18,
                'price'                 => 485.00,
                'capacity'              => 16,
                'registered_count'      => 0,
                'registration_deadline' => '2027-07-05',
                'registration_status'   => 'open',
                'publication_status'    => 'published',
                'notes'                 => 'Individual stroke biomechanics report and video breakdown provided to each athlete.',
                'created_at'            => current_time( 'mysql' ),
            ],
            [
                'title'                 => 'Girls Squash & Leadership Summer Intensive',
                'slug'                  => 'girls-squash-leadership-summer-intensive',
                'description'           => 'An inspiring, all-girls squash development camp blending technical on-court drilling, agility circuit training, sports nutrition guidance, and peer mentorship workshops led by top female coaches.',
                'image_url'             => set_url_scheme( SCB_PLUGIN_URL . 'assets/frontend/images/camps/camp-4.jpg', 'https' ),
                'location'              => 'Courts 2-4 & Fitness Studio',
                'start_date'            => '2027-07-26',
                'end_date'              => '2027-07-30',
                'schedule_details'      => 'Monday to Friday, 9:00 AM – 3:00 PM',
                'min_age'               => 9,
                'max_age'               => 16,
                'price'                 => 360.00,
                'capacity'              => 20,
                'registered_count'      => 0,
                'registration_deadline' => '2027-07-19',
                'registration_status'   => 'open',
                'publication_status'    => 'published',
                'notes'                 => 'Camp t-shirt, water bottle, and performance journal included.',
                'created_at'            => current_time( 'mysql' ),
            ],
            [
                'title'                 => 'All-Stars Pre-Season Masters Boot Camp',
                'slug'                  => 'all-stars-pre-season-masters-boot-camp',
                'description'           => 'The ultimate pre-season conditioning and match fitness camp before the school term starts. Features high-tempo ghosting intervals, endurance testing, tactical box leagues, and racquet stringing / equipment masterclasses.',
                'image_url'             => set_url_scheme( SCB_PLUGIN_URL . 'assets/frontend/images/camps/camp-5.jpg', 'https' ),
                'location'              => 'Courts 1-6 & Strength Lab',
                'start_date'            => '2027-08-09',
                'end_date'              => '2027-08-13',
                'schedule_details'      => 'Monday to Friday, 9:00 AM – 4:00 PM (Full Day)',
                'min_age'               => 11,
                'max_age'               => 18,
                'price'                 => 440.00,
                'capacity'              => 24,
                'registered_count'      => 0,
                'registration_deadline' => '2027-08-02',
                'registration_status'   => 'open',
                'publication_status'    => 'published',
                'notes'                 => 'Includes pre-season fitness assessment and tournament readiness certificate.',
                'created_at'            => current_time( 'mysql' ),
            ],
        ];

        $created = 0;
        $skipped = 0;

        foreach ( $camps as $camp ) {
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}scb_camps WHERE slug = %s",
                $camp['slug']
            ) );

            if ( $exists ) {
                $skipped++;
                continue;
            }

            $wpdb->insert(
                $wpdb->prefix . 'scb_camps',
                $camp,
                [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%f', '%d', '%d', '%s', '%s', '%s', '%s' ]
            );

            if ( $wpdb->insert_id ) {
                $created++;
            }
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
        ];
    }
}
