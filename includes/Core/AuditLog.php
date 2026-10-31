<?php
namespace SquashClubBooking\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Audit log helper — records administrative actions.
 *
 * @package SquashClubBooking\Core
 */
class AuditLog {

    /**
     * Record an audit entry.
     *
     * @param string     $action      e.g. 'booking_approved'
     * @param string     $entity_type e.g. 'booking'
     * @param int        $entity_id
     * @param array|null $old_values
     * @param array|null $new_values
     * @param string     $description Optional human-readable note.
     */
    public static function log(
        string $action,
        string $entity_type = '',
        int    $entity_id   = 0,
        ?array $old_values  = null,
        ?array $new_values  = null,
        string $description = ''
    ): void {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'scb_audit_log',
            [
                'user_id'     => get_current_user_id() ?: null,
                'action'      => $action,
                'entity_type' => $entity_type,
                'entity_id'   => $entity_id ?: null,
                'old_values'  => $old_values  ? wp_json_encode( $old_values )  : null,
                'new_values'  => $new_values  ? wp_json_encode( $new_values )  : null,
                'description' => $description ?: null,
                'ip_address'  => self::get_client_ip(),
                'created_at'  => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    /**
     * Get the client IP address.
     *
     * @return string
     */
    public static function get_client_ip(): string {
        foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ] as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip = trim( explode( ',', $_SERVER[ $key ] )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return sanitize_text_field( $ip );
                }
            }
        }
        return '';
    }
}
