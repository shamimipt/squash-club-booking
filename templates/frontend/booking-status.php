<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div id="scb-booking-status" class="scb-frontend scb-booking-status" data-ref="<?php echo esc_attr( $atts['ref'] ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'scb_frontend_nonce' ) ); ?>">
    <p class="scb-fe-loading"><?php esc_html_e( 'Loading booking status…', 'squash-club-booking' ); ?></p>
</div>
