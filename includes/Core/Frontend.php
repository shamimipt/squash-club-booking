<?php

namespace SquashClubBooking\Core;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Frontend shortcode registration and public-facing functionality.
 *
 * @package SquashClubBooking\Core
 */
class Frontend
{

    /**
     * Register WordPress hooks.
     */
    public function register(): void
    {
        add_shortcode('scb_sunday_clinic',  [$this, 'shortcode_clinic']);
        add_shortcode('scb_private_lesson', [$this, 'shortcode_lesson']);
        add_shortcode('scb_summer_camp',    [$this, 'shortcode_camp']);
        add_shortcode('scb_my_bookings',    [$this, 'shortcode_my_bookings']);
        add_shortcode('scb_booking_status', [$this, 'shortcode_booking_status']);

        // WooCommerce My Account integration.
        add_action('init', [$this, 'add_wc_account_endpoint']);
        add_filter('woocommerce_account_menu_items', [$this, 'add_wc_account_menu_item']);
        add_action('woocommerce_account_my-bookings_endpoint', [$this, 'render_wc_account_my_bookings']);
    }

    /**
     * Register custom WooCommerce account rewrite endpoint.
     */
    public function add_wc_account_endpoint(): void
    {
        add_rewrite_endpoint('my-bookings', EP_ROOT | EP_PAGES);
    }

    /**
     * Add "My Bookings" link into WooCommerce My Account sidebar menu.
     *
     * @param array $items
     * @return array
     */
    public function add_wc_account_menu_item(array $items): array
    {
        $new_items = [];
        foreach ($items as $key => $label) {
            $new_items[$key] = $label;
            if ($key === 'orders') {
                $new_items['my-bookings'] = __('My Bookings', 'squash-club-booking');
            }
        }
        if (! isset($new_items['my-bookings'])) {
            $new_items['my-bookings'] = __('My Bookings', 'squash-club-booking');
        }
        return $new_items;
    }

    /**
     * Render the booking list inside WooCommerce My Account dashboard.
     */
    public function render_wc_account_my_bookings(): void
    {
        include SCB_PLUGIN_DIR . 'templates/frontend/my-bookings.php';
    }

    /**
     * [scb_sunday_clinic] shortcode.
     *
     * @param array $atts
     * @return string
     */
    public function shortcode_clinic($atts = []): string
    {
        if (! \SquashClubBooking\Core\Settings::get('clinic_enabled', true)) {
            return '<p>' . esc_html__('Clinic booking is currently unavailable.', 'squash-club-booking') . '</p>';
        }

        $atts = shortcode_atts([
            'category' => '', // 'junior', 'adult', or empty for all
        ], (array) $atts, 'scb_sunday_clinic');

        $category = sanitize_key(strtolower(trim($atts['category'] ?? '')));

        ob_start();
        include SCB_PLUGIN_DIR . 'templates/frontend/clinic-booking.php';
        return ob_get_clean();
    }

    /**
     * [scb_private_lesson] shortcode.
     *
     * @param array $atts
     * @return string
     */
    public function shortcode_lesson(array $atts): string
    {
        if (! \SquashClubBooking\Core\Settings::get('lesson_enabled', true)) {
            return '<p>' . esc_html__('Lesson booking is currently unavailable.', 'squash-club-booking') . '</p>';
        }

        ob_start();
        include SCB_PLUGIN_DIR . 'templates/frontend/lesson-booking.php';
        return ob_get_clean();
    }

    /**
     * [scb_summer_camp] shortcode.
     *
     * @param array $atts
     * @return string
     */
    public function shortcode_camp(array $atts): string
    {
        if (! \SquashClubBooking\Core\Settings::get('camp_enabled', true)) {
            return '<p>' . esc_html__('Camp registration is currently unavailable.', 'squash-club-booking') . '</p>';
        }

        ob_start();
        include SCB_PLUGIN_DIR . 'templates/frontend/camp-registration.php';
        return ob_get_clean();
    }

    /**
     * [scb_my_bookings] shortcode — customer booking history.
     *
     * @param array $atts
     * @return string
     */
    public function shortcode_my_bookings(array $atts): string
    {
        if (! is_user_logged_in()) {
            $login_url = function_exists('wc_get_page_permalink')
                ? wc_get_page_permalink('myaccount')
                : wp_login_url(get_permalink());

            $title    = esc_html__('Account Required', 'squash-club-booking');
            $desc     = esc_html__('You must be logged in to view your bookings and pay for reservations. Please log in or register an account.', 'squash-club-booking');
            $btn_text = esc_html__('Log In / Register', 'squash-club-booking');

            return '<div class="scb-frontend scb-status-card" style="text-align:center;max-width:480px;margin:30px auto;padding:36px 24px;border:1px solid #e2e8f0;border-radius:12px;background:#fff;box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);">'
                . '<div style="font-size:40px;margin-bottom:14px;">🔒</div>'
                . '<h3 style="margin:0 0 8px;font-size:22px;font-weight:700;color:#0f172a;">' . $title . '</h3>'
                . '<p style="color:#64748b;font-size:15px;margin-bottom:24px;line-height:1.5;">' . $desc . '</p>'
                . '<div><a href="' . esc_url($login_url) . '" class="scb-fe-btn scb-fe-btn--primary" style="display:inline-block;padding:12px 28px;font-size:15px;font-weight:700;text-decoration:none;border-radius:8px;">' . $btn_text . '</a></div>'
                . '</div>';
        }

        ob_start();
        include SCB_PLUGIN_DIR . 'templates/frontend/my-bookings.php';
        return shortcode_unautop(trim(ob_get_clean()));
    }

    /**
     * [scb_booking_status ref="SCB-2024-0001"] shortcode.
     *
     * @param array $atts
     * @return string
     */
    public function shortcode_booking_status(array $atts): string
    {
        $atts = shortcode_atts(['ref' => ''], $atts, 'scb_booking_status');

        ob_start();
        include SCB_PLUGIN_DIR . 'templates/frontend/booking-status.php';
        return ob_get_clean();
    }
}
