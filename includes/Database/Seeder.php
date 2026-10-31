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
                'photo_url'    => 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=400&h=400&q=80',
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
                'photo_url'    => 'https://images.unsplash.com/photo-1544005313-94ddf0286df2?auto=format&fit=crop&w=400&h=400&q=80',
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
                'photo_url'    => 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?auto=format&fit=crop&w=400&h=400&q=80',
                'bio'          => 'Egyptian style squash specialist focusing on wrist deception, devastating boast variations, and tight dying lengths. Tariq has developed multiple junior national team players.',
                'specialties'  => 'Egyptian Attack, Trick Boasts, Nick Hunting, Front Court Agility',
                'lesson_types' => 'private,semi_private',
                'status'       => 'active',
                'display_order'=> 3,
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
                'title'                 => 'Junior Squash Intensive Academy 2027',
                'slug'                  => 'junior-squash-intensive-academy-2027',
                'description'           => 'A comprehensive 5-day developmental camp designed for junior squash athletes aged 8-16. Covers racket skills, tactical decision making, video swing analysis, agility fitness, and daily round-robin tournament play.',
                'image_url'             => 'https://images.unsplash.com/photo-1554068865-24cecd4e34b8?auto=format&fit=crop&w=800&h=450&q=80',
                'location'              => 'Championship Courts 1-4, Squash Club',
                'start_date'            => '2027-07-05',
                'end_date'              => '2027-07-09',
                'schedule_details'      => 'Monday to Friday, 9:00 AM – 3:30 PM (Lunch provided)',
                'min_age'               => 8,
                'max_age'               => 16,
                'price'                 => 385.00,
                'capacity'              => 24,
                'registered_count'      => 0,
                'registration_deadline' => '2027-06-30',
                'registration_status'   => 'open',
                'publication_status'    => 'published',
                'created_at'            => current_time( 'mysql' ),
            ],
            [
                'title'                 => 'Elite Competitive Camp & Tournament Prep',
                'slug'                  => 'elite-competitive-camp-tournament-prep',
                'description'           => 'High-performance camp for junior ranked players and high school varsity athletes. Focuses on advanced shot disguise, game pressure management, sports psychology, and intense fitness conditioning with former PSA pros.',
                'image_url'             => 'https://images.unsplash.com/photo-1587280501635-68a0e82cd5ff?auto=format&fit=crop&w=800&h=450&q=80',
                'location'              => 'Glass Show Court & Courts 5-8',
                'start_date'            => '2027-07-19',
                'end_date'              => '2027-07-23',
                'schedule_details'      => 'Monday to Friday, 9:00 AM – 4:00 PM',
                'min_age'               => 12,
                'max_age'               => 18,
                'price'                 => 475.00,
                'capacity'              => 16,
                'registered_count'      => 0,
                'registration_deadline' => '2027-07-12',
                'registration_status'   => 'open',
                'publication_status'    => 'published',
                'created_at'            => current_time( 'mysql' ),
            ],
            [
                'title'                 => 'Beginners Squash & Multi-Sport Summer Camp',
                'slug'                  => 'beginners-squash-multi-sport-summer-camp',
                'description'           => 'Fun, engaging introduction to squash for younger juniors aged 6-12. Emphasizes hand-eye coordination, rules of the game, sportsmanship, and fun squash mini-games in a supportive team environment.',
                'image_url'             => 'https://images.unsplash.com/photo-1546519638-68e109498ffc?auto=format&fit=crop&w=800&h=450&q=80',
                'location'              => 'Courts 7-9 & Activity Studio',
                'start_date'            => '2027-08-02',
                'end_date'              => '2027-08-06',
                'schedule_details'      => 'Monday to Friday, 9:00 AM – 1:00 PM (Half Day)',
                'min_age'               => 6,
                'max_age'               => 12,
                'price'                 => 240.00,
                'capacity'              => 20,
                'registered_count'      => 0,
                'registration_deadline' => '2027-07-28',
                'registration_status'   => 'open',
                'publication_status'    => 'published',
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
