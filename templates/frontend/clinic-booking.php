<?php
/**
 * Frontend template: Clinic Booking.
 * Rendered by [scb_sunday_clinic] shortcode.
 *
 * @package SquashClubBooking
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div id="scb-clinic-booking" class="scb-frontend scb-clinic-booking" data-category="<?php echo esc_attr( $category ?? '' ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'scb_frontend_nonce' ) ); ?>">
    <p class="scb-fe-loading"><?php esc_html_e( 'Loading available clinic slots…', 'squash-club-booking' ); ?></p>
</div>
