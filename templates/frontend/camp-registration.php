<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div id="scb-summer-camp" class="scb-frontend scb-camp-registration" data-nonce="<?php echo esc_attr( wp_create_nonce( 'scb_frontend_nonce' ) ); ?>">
    <p class="scb-fe-loading"><?php esc_html_e( 'Loading summer camp registration…', 'squash-club-booking' ); ?></p>
</div>
