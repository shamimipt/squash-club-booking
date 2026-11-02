<?php
namespace SquashClubBooking\Database;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Database schema installer and upgrader.
 *
 * Uses dbDelta() for safe, incremental table creation and modification.
 * Never drops tables — always additive upgrades only.
 *
 * @package SquashClubBooking\Database
 */
class Schema {

    /**
     * Install or upgrade all plugin tables.
     */
    public static function install(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $tables = self::get_table_definitions( $charset_collate );

        foreach ( $tables as $sql ) {
            dbDelta( $sql );
        }

        // Record schema version.
        // Add hourly_rate column if missing
        if ( ! $wpdb->get_var( "SHOW COLUMNS FROM {$wpdb->prefix}scb_coaches LIKE 'hourly_rate'" ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}scb_coaches ADD COLUMN hourly_rate DECIMAL(10,2) DEFAULT NULL AFTER lesson_types" );
        }
        if ( ! $wpdb->get_var( "SHOW COLUMNS FROM {$wpdb->prefix}scb_coaches LIKE 'semi_private_rate'" ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}scb_coaches ADD COLUMN semi_private_rate DECIMAL(10,2) DEFAULT NULL AFTER hourly_rate" );
        }

        update_option( 'scb_db_version', SCB_DB_VERSION );
    }

    /**
     * Return all CREATE TABLE SQL statements.
     *
     * @param string $charset_collate
     * @return string[]
     */
    private static function get_table_definitions( string $charset_collate ): array {
        global $wpdb;

        return [

            // ── Plugin-wide settings ───────────────────────────────────────────
            "CREATE TABLE {$wpdb->prefix}scb_settings (
                id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                setting_key   VARCHAR(191)        NOT NULL,
                setting_value LONGTEXT            NOT NULL,
                autoload      TINYINT(1)          NOT NULL DEFAULT 1,
                created_at    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY   uq_setting_key (setting_key)
            ) {$charset_collate};",

            // ── Coach profiles ─────────────────────────────────────────────────
            "CREATE TABLE {$wpdb->prefix}scb_coaches (
                id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                wp_user_id    BIGINT(20) UNSIGNED          DEFAULT NULL,
                first_name    VARCHAR(100)        NOT NULL,
                last_name     VARCHAR(100)        NOT NULL,
                slug          VARCHAR(200)        NOT NULL,
                email         VARCHAR(191)                 DEFAULT NULL,
                phone         VARCHAR(50)                  DEFAULT NULL,
                photo_url     VARCHAR(500)                 DEFAULT NULL,
                bio           LONGTEXT                     DEFAULT NULL,
                specialties   TEXT                         DEFAULT NULL,
                lesson_types  VARCHAR(100)        NOT NULL DEFAULT 'private,semi_private',
                hourly_rate   DECIMAL(10,2)                DEFAULT NULL,
                semi_private_rate DECIMAL(10,2)            DEFAULT NULL,
                status        ENUM('active','inactive','on_leave') NOT NULL DEFAULT 'active',
                display_order SMALLINT            NOT NULL DEFAULT 0,
                created_at    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY   uq_slug (slug),
                KEY          idx_status (status)
            ) {$charset_collate};",

            // ── Coach availability (weekly recurring blocks) ────────────────────
            "CREATE TABLE {$wpdb->prefix}scb_coach_availability (
                id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                coach_id      BIGINT(20) UNSIGNED NOT NULL,
                effective_date DATE               NOT NULL COMMENT 'Week starting (Monday)',
                day_of_week   TINYINT(1)          NOT NULL COMMENT '0=Sun, 6=Sat',
                start_time    TIME                NOT NULL,
                end_time      TIME                NOT NULL,
                lesson_type   ENUM('private','semi_private','both') NOT NULL DEFAULT 'both',
                is_blocked    TINYINT(1)          NOT NULL DEFAULT 0,
                notes         VARCHAR(500)                 DEFAULT NULL,
                created_at    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY          idx_coach_date (coach_id, effective_date),
                KEY          idx_coach_dow  (coach_id, day_of_week)
            ) {$charset_collate};",

            // ── Sunday clinic slots ────────────────────────────────────────────
            "CREATE TABLE {$wpdb->prefix}scb_clinic_slots (
                id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                slot_date       DATE                NOT NULL,
                start_time      TIME                NOT NULL,
                end_time        TIME                NOT NULL,
                category        ENUM('junior','adult') NOT NULL DEFAULT 'junior',
                capacity        SMALLINT            NOT NULL DEFAULT 15,
                booked_count    SMALLINT            NOT NULL DEFAULT 0,
                waitlist_count  SMALLINT            NOT NULL DEFAULT 0,
                price           DECIMAL(10,2)       NOT NULL DEFAULT 0.00,
                status          ENUM('open','full','closed','cancelled') NOT NULL DEFAULT 'open',
                notes           TEXT                         DEFAULT NULL,
                created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY    (id),
                UNIQUE KEY     uq_slot (slot_date, start_time, category),
                KEY            idx_slot_date (slot_date),
                KEY            idx_status    (status),
                KEY            idx_date_status (slot_date, status)
            ) {$charset_collate};",

            // ── All bookings (clinics + lessons) ───────────────────────────────
            "CREATE TABLE {$wpdb->prefix}scb_bookings (
                id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                booking_ref         VARCHAR(30)         NOT NULL COMMENT 'Human-readable reference e.g. SCB-2024-0001',
                service_type        ENUM('clinic','private_lesson','semi_private_lesson') NOT NULL,
                customer_user_id    BIGINT(20) UNSIGNED          DEFAULT NULL COMMENT 'WP user ID if logged in',
                customer_name       VARCHAR(200)        NOT NULL,
                customer_email      VARCHAR(191)        NOT NULL,
                customer_phone      VARCHAR(50)                  DEFAULT NULL,
                clinic_slot_id      BIGINT(20) UNSIGNED          DEFAULT NULL,
                coach_id            BIGINT(20) UNSIGNED          DEFAULT NULL,
                lesson_date         DATE                         DEFAULT NULL,
                lesson_start_time   TIME                         DEFAULT NULL,
                lesson_end_time     TIME                         DEFAULT NULL,
                participant_count   TINYINT             NOT NULL DEFAULT 1,
                booking_status      ENUM('pending','approved','rejected','rescheduled','cancelled','waitlisted','completed') NOT NULL DEFAULT 'pending',
                payment_status      ENUM('unpaid','pending','paid','failed','refunded','partially_refunded') NOT NULL DEFAULT 'unpaid',
                wc_order_id         BIGINT(20) UNSIGNED          DEFAULT NULL,
                amount              DECIMAL(10,2)       NOT NULL DEFAULT 0.00,
                notes               TEXT                         DEFAULT NULL,
                admin_notes         TEXT                         DEFAULT NULL,
                rejection_reason    TEXT                         DEFAULT NULL,
                reschedule_reason   TEXT                         DEFAULT NULL,
                cancellation_reason TEXT                         DEFAULT NULL,
                ip_address          VARCHAR(45)                  DEFAULT NULL,
                created_at          DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at          DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY   uq_booking_ref (booking_ref),
                KEY          idx_service_type   (service_type),
                KEY          idx_customer_email (customer_email),
                KEY          idx_customer_user  (customer_user_id),
                KEY          idx_booking_status (booking_status),
                KEY          idx_payment_status (payment_status),
                KEY          idx_clinic_slot    (clinic_slot_id),
                KEY          idx_slot_status    (clinic_slot_id, booking_status),
                KEY          idx_coach          (coach_id),
                KEY          idx_coach_conflict (coach_id, lesson_date, booking_status),
                KEY          idx_lesson_date    (lesson_date),
                KEY          idx_wc_order       (wc_order_id),
                KEY          idx_created_at     (created_at)
            ) {$charset_collate};",

            // ── Booking participants ───────────────────────────────────────────
            "CREATE TABLE {$wpdb->prefix}scb_booking_participants (
                id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                booking_id  BIGINT(20) UNSIGNED NOT NULL,
                first_name  VARCHAR(100)        NOT NULL,
                last_name   VARCHAR(100)        NOT NULL,
                date_of_birth DATE                       DEFAULT NULL,
                age         TINYINT                      DEFAULT NULL,
                skill_level VARCHAR(100)                 DEFAULT NULL,
                notes       TEXT                         DEFAULT NULL,
                created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY         idx_booking (booking_id)
            ) {$charset_collate};",

            // ── Waitlist entries ───────────────────────────────────────────────
            "CREATE TABLE {$wpdb->prefix}scb_waitlist (
                id               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                booking_id       BIGINT(20) UNSIGNED NOT NULL,
                clinic_slot_id   BIGINT(20) UNSIGNED          DEFAULT NULL,
                coach_id         BIGINT(20) UNSIGNED          DEFAULT NULL,
                service_type     ENUM('clinic','private_lesson','semi_private_lesson') NOT NULL,
                customer_name    VARCHAR(200)        NOT NULL,
                customer_email   VARCHAR(191)        NOT NULL,
                customer_phone   VARCHAR(50)                  DEFAULT NULL,
                position         SMALLINT            NOT NULL DEFAULT 1,
                status           ENUM('waiting','promoted','expired','removed') NOT NULL DEFAULT 'waiting',
                notified_at      DATETIME                     DEFAULT NULL,
                expires_at       DATETIME                     DEFAULT NULL,
                promoted_at      DATETIME                     DEFAULT NULL,
                created_at       DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at       DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY          idx_booking      (booking_id),
                KEY          idx_clinic_slot  (clinic_slot_id),
                KEY          idx_coach        (coach_id),
                KEY          idx_status       (status),
                KEY          idx_position     (clinic_slot_id, position)
            ) {$charset_collate};",

            // ── Summer camps ───────────────────────────────────────────────────
            "CREATE TABLE {$wpdb->prefix}scb_camps (
                id                   BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                title                VARCHAR(300)        NOT NULL,
                slug                 VARCHAR(300)        NOT NULL,
                description          LONGTEXT                     DEFAULT NULL,
                image_url            VARCHAR(500)                 DEFAULT NULL,
                location             VARCHAR(300)                 DEFAULT NULL,
                start_date           DATE                NOT NULL,
                end_date             DATE                NOT NULL,
                schedule_details     TEXT                         DEFAULT NULL,
                min_age              TINYINT                      DEFAULT NULL,
                max_age              TINYINT                      DEFAULT NULL,
                price                DECIMAL(10,2)       NOT NULL DEFAULT 0.00,
                capacity             SMALLINT            NOT NULL DEFAULT 20,
                registered_count     SMALLINT            NOT NULL DEFAULT 0,
                registration_deadline DATE                        DEFAULT NULL,
                registration_status  ENUM('open','closed','full','cancelled') NOT NULL DEFAULT 'open',
                publication_status   ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
                required_fields      TEXT                         DEFAULT NULL COMMENT 'JSON array of required field keys',
                custom_fields        TEXT                         DEFAULT NULL COMMENT 'JSON array of custom field definitions',
                notes                TEXT                         DEFAULT NULL,
                created_at           DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at           DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY   uq_camp_slug (slug),
                KEY          idx_pub_status (publication_status),
                KEY          idx_start_date (start_date)
            ) {$charset_collate};",

            // ── Camp registrations ─────────────────────────────────────────────
            "CREATE TABLE {$wpdb->prefix}scb_camp_registrations (
                id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                registration_ref    VARCHAR(30)         NOT NULL,
                camp_id             BIGINT(20) UNSIGNED NOT NULL,
                customer_user_id    BIGINT(20) UNSIGNED          DEFAULT NULL,
                student_name        VARCHAR(200)        NOT NULL,
                student_dob         DATE                         DEFAULT NULL,
                student_age         TINYINT                      DEFAULT NULL,
                skill_level         VARCHAR(100)                 DEFAULT NULL,
                parent_name         VARCHAR(200)        NOT NULL,
                parent_email        VARCHAR(191)        NOT NULL,
                parent_phone        VARCHAR(50)                  DEFAULT NULL,
                emergency_contact   TEXT                         DEFAULT NULL,
                additional_notes    TEXT                         DEFAULT NULL,
                consents            TEXT                         DEFAULT NULL COMMENT 'JSON: acknowledged consent keys',
                custom_field_data   TEXT                         DEFAULT NULL COMMENT 'JSON: custom field values',
                registration_status ENUM('pending','confirmed','cancelled','waitlisted') NOT NULL DEFAULT 'pending',
                payment_status      ENUM('unpaid','pending','paid','failed','refunded') NOT NULL DEFAULT 'unpaid',
                wc_order_id         BIGINT(20) UNSIGNED          DEFAULT NULL,
                amount              DECIMAL(10,2)       NOT NULL DEFAULT 0.00,
                ip_address          VARCHAR(45)                  DEFAULT NULL,
                created_at          DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at          DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY   uq_reg_ref   (registration_ref),
                KEY          idx_camp     (camp_id),
                KEY          idx_camp_status (camp_id, registration_status),
                KEY          idx_user     (customer_user_id),
                KEY          idx_email    (parent_email),
                KEY          idx_status   (registration_status),
                KEY          idx_wc_order (wc_order_id)
            ) {$charset_collate};",

            // ── Payment tracking ───────────────────────────────────────────────
            "CREATE TABLE {$wpdb->prefix}scb_payments (
                id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                entity_type     ENUM('booking','camp_registration') NOT NULL,
                entity_id       BIGINT(20) UNSIGNED NOT NULL,
                wc_order_id     BIGINT(20) UNSIGNED NOT NULL,
                amount          DECIMAL(10,2)       NOT NULL DEFAULT 0.00,
                currency        VARCHAR(10)         NOT NULL DEFAULT 'USD',
                payment_method  VARCHAR(100)                 DEFAULT NULL,
                payment_status  VARCHAR(50)         NOT NULL DEFAULT 'pending',
                transaction_id  VARCHAR(200)                 DEFAULT NULL,
                gateway_response TEXT                        DEFAULT NULL,
                created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY          idx_entity   (entity_type, entity_id),
                KEY          idx_wc_order (wc_order_id),
                UNIQUE KEY   uq_wc_order  (wc_order_id)
            ) {$charset_collate};",

            // ── Email/notification log ─────────────────────────────────────────
            "CREATE TABLE {$wpdb->prefix}scb_notifications (
                id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                notification_key VARCHAR(100)       NOT NULL,
                entity_type     VARCHAR(50)         NOT NULL,
                entity_id       BIGINT(20) UNSIGNED NOT NULL,
                recipient_email VARCHAR(191)        NOT NULL,
                recipient_name  VARCHAR(200)                 DEFAULT NULL,
                subject         VARCHAR(500)        NOT NULL,
                status          ENUM('sent','failed','skipped') NOT NULL DEFAULT 'sent',
                error_message   TEXT                         DEFAULT NULL,
                sent_at         DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY          idx_entity       (entity_type, entity_id),
                KEY          idx_notif_key    (notification_key),
                KEY          idx_recipient    (recipient_email),
                KEY          idx_sent_at      (sent_at)
            ) {$charset_collate};",

            // ── Admin audit log ────────────────────────────────────────────────
            "CREATE TABLE {$wpdb->prefix}scb_audit_log (
                id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id     BIGINT(20) UNSIGNED          DEFAULT NULL,
                action      VARCHAR(100)        NOT NULL,
                entity_type VARCHAR(50)                  DEFAULT NULL,
                entity_id   BIGINT(20) UNSIGNED          DEFAULT NULL,
                old_values  TEXT                         DEFAULT NULL COMMENT 'JSON',
                new_values  TEXT                         DEFAULT NULL COMMENT 'JSON',
                description TEXT                         DEFAULT NULL,
                ip_address  VARCHAR(45)                  DEFAULT NULL,
                created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY         idx_user       (user_id),
                KEY         idx_action     (action),
                KEY         idx_entity     (entity_type, entity_id),
                KEY         idx_created_at (created_at)
            ) {$charset_collate};",

        ];
    }

    /**
     * Check if all tables exist.
     *
     * @return bool
     */
    public static function tables_exist(): bool {
        global $wpdb;
        $tables = [
            $wpdb->prefix . 'scb_settings',
            $wpdb->prefix . 'scb_coaches',
            $wpdb->prefix . 'scb_bookings',
            $wpdb->prefix . 'scb_camps',
        ];

        foreach ( $tables as $table ) {
            if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                return false;
            }
        }
        return true;
    }

    /**
     * Drop all plugin tables. Called ONLY from uninstall.php when user has
     * explicitly opted in to data deletion.
     */
    public static function drop_all_tables(): void {
        global $wpdb;

        $tables = [
            $wpdb->prefix . 'scb_audit_log',
            $wpdb->prefix . 'scb_notifications',
            $wpdb->prefix . 'scb_payments',
            $wpdb->prefix . 'scb_camp_registrations',
            $wpdb->prefix . 'scb_camps',
            $wpdb->prefix . 'scb_waitlist',
            $wpdb->prefix . 'scb_booking_participants',
            $wpdb->prefix . 'scb_bookings',
            $wpdb->prefix . 'scb_clinic_slots',
            $wpdb->prefix . 'scb_coach_availability',
            $wpdb->prefix . 'scb_coaches',
            $wpdb->prefix . 'scb_settings',
        ];

        foreach ( $tables as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }
    }

    /**
     * Reset/truncate all plugin tables and reinitialize to fresh install state.
     */
    public static function reset_all_data(): void {
        global $wpdb;

        $tables = [
            $wpdb->prefix . 'scb_audit_log',
            $wpdb->prefix . 'scb_notifications',
            $wpdb->prefix . 'scb_payments',
            $wpdb->prefix . 'scb_camp_registrations',
            $wpdb->prefix . 'scb_camps',
            $wpdb->prefix . 'scb_waitlist',
            $wpdb->prefix . 'scb_booking_participants',
            $wpdb->prefix . 'scb_bookings',
            $wpdb->prefix . 'scb_clinic_slots',
            $wpdb->prefix . 'scb_coach_availability',
            $wpdb->prefix . 'scb_coaches',
            $wpdb->prefix . 'scb_settings',
        ];

        $wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );
        foreach ( $tables as $table ) {
            $wpdb->query( "TRUNCATE TABLE `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }
        $wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );

        // Ensure schema integrity and re-install default settings
        self::install();
        \SquashClubBooking\Core\Settings::install_defaults();
        \SquashClubBooking\Core\Settings::flush_cache();

        // Mark as seeded so auto-generation does not inject unwanted dummy data
        update_option( 'scb_seeded', 1 );
        update_option( 'squash_club_seeded', 1 );
    }
}
