<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div id="scb-my-bookings" class="scb-frontend scb-my-bookings" data-nonce="<?php echo esc_attr( wp_create_nonce( 'scb_frontend_nonce' ) ); ?>">
    <p class="scb-fe-loading"><?php esc_html_e( 'Loading your bookings…', 'squash-club-booking' ); ?></p>
</div>
