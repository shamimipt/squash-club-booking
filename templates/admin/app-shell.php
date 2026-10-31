<?php
/**
 * Admin application shell template.
 *
 * This is the single root HTML container that the admin JS app mounts into.
 * The sidebar, top bar, and all page views are rendered by admin.js using
 * the REST API. The $view variable is set by Dashboard::render_app_shell().
 *
 * @var string $view  Current page view slug (without 'scb-' prefix).
 * @var string $current_page Full page slug.
 *
 * @package SquashClubBooking
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Collect user info for the app.
$current_user = wp_get_current_user();
$user_name    = $current_user->display_name;
$user_avatar  = get_avatar_url( $current_user->ID, [ 'size' => 40 ] );
?>
<div class="wrap scb-admin-wrap">
    <h1 class="wp-heading-inline screen-reader-text"><?php echo esc_html( get_admin_page_title() ); ?></h1>
    <div id="scb-admin-notices" class="scb-admin-notices"></div>
    <hr class="wp-header-end">

<div
    id="scb-app"
    class="scb-app"
    data-view="<?php echo esc_attr( $view ); ?>"
    data-page="<?php echo esc_attr( $current_page ); ?>"
    data-user-name="<?php echo esc_attr( $user_name ); ?>"
    data-user-avatar="<?php echo esc_attr( $user_avatar ); ?>"
    data-admin-url="<?php echo esc_attr( admin_url( 'admin.php' ) ); ?>"
    data-plugin-url="<?php echo esc_attr( SCB_PLUGIN_URL ); ?>"
>

    <!-- Sidebar Navigation -->
    <aside class="scb-sidebar" id="scb-sidebar">
        <div class="scb-sidebar__header">
            <div class="scb-sidebar__logo">
                <div class="scb-sidebar__logo-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <circle cx="12" cy="12" r="4"/>
                        <line x1="12" y1="2" x2="12" y2="8"/>
                        <line x1="12" y1="16" x2="12" y2="22"/>
                        <line x1="2" y1="12" x2="8" y2="12"/>
                        <line x1="16" y1="12" x2="22" y2="12"/>
                    </svg>
                </div>
                <div class="scb-sidebar__logo-text">
                    <span class="scb-sidebar__brand">Squash Club</span>
                    <span class="scb-sidebar__brand-sub">Booking System</span>
                </div>
            </div>
            <button class="scb-sidebar__collapse-btn" id="scb-sidebar-toggle" aria-label="<?php esc_attr_e( 'Toggle Sidebar', 'squash-club-booking' ); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="3" y1="6" x2="21" y2="6"/>
                    <line x1="3" y1="12" x2="21" y2="12"/>
                    <line x1="3" y1="18" x2="21" y2="18"/>
                </svg>
            </button>
        </div>

        <nav class="scb-sidebar__nav" aria-label="<?php esc_attr_e( 'Plugin navigation', 'squash-club-booking' ); ?>">
            <ul class="scb-sidebar__menu" role="list">

                <li class="scb-sidebar__item" data-view="dashboard">
                    <a class="scb-sidebar__link" href="<?php echo esc_url( admin_url( 'admin.php?page=scb-dashboard' ) ); ?>" data-page="scb-dashboard" title="<?php esc_attr_e( 'Overview', 'squash-club-booking' ); ?>">
                        <span class="scb-sidebar__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
                                <rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>
                            </svg>
                        </span>
                        <span class="scb-sidebar__label"><?php esc_html_e( 'Overview', 'squash-club-booking' ); ?></span>
                    </a>
                </li>

                <li class="scb-sidebar__separator"><span><?php esc_html_e( 'Bookings', 'squash-club-booking' ); ?></span></li>

                <li class="scb-sidebar__item" data-view="bookings">
                    <a class="scb-sidebar__link" href="<?php echo esc_url( admin_url( 'admin.php?page=scb-bookings' ) ); ?>" data-page="scb-bookings" title="<?php esc_attr_e( 'All Bookings', 'squash-club-booking' ); ?>">
                        <span class="scb-sidebar__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>
                                <line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>
                            </svg>
                        </span>
                        <span class="scb-sidebar__label"><?php esc_html_e( 'All Bookings', 'squash-club-booking' ); ?></span>
                        <span class="scb-sidebar__badge" id="scb-pending-badge" style="display:none;"></span>
                    </a>
                </li>

                <li class="scb-sidebar__item" data-view="clinics">
                    <a class="scb-sidebar__link" href="<?php echo esc_url( admin_url( 'admin.php?page=scb-clinics' ) ); ?>" data-page="scb-clinics" title="<?php esc_attr_e( 'Sunday Clinics', 'squash-club-booking' ); ?>">
                        <span class="scb-sidebar__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <polyline points="12 6 12 12 16 14"/>
                            </svg>
                        </span>
                        <span class="scb-sidebar__label"><?php esc_html_e( 'Sunday Clinics', 'squash-club-booking' ); ?></span>
                    </a>
                </li>

                <li class="scb-sidebar__item" data-view="private-lessons">
                    <a class="scb-sidebar__link" href="<?php echo esc_url( admin_url( 'admin.php?page=scb-private-lessons' ) ); ?>" data-page="scb-private-lessons" title="<?php esc_attr_e( 'Private Lessons', 'squash-club-booking' ); ?>">
                        <span class="scb-sidebar__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                                <circle cx="9" cy="7" r="4"/>
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                            </svg>
                        </span>
                        <span class="scb-sidebar__label"><?php esc_html_e( 'Private Lessons', 'squash-club-booking' ); ?></span>
                    </a>
                </li>

                <li class="scb-sidebar__separator"><span><?php esc_html_e( 'Management', 'squash-club-booking' ); ?></span></li>

                <li class="scb-sidebar__item" data-view="coaches">
                    <a class="scb-sidebar__link" href="<?php echo esc_url( admin_url( 'admin.php?page=scb-coaches' ) ); ?>" data-page="scb-coaches" title="<?php esc_attr_e( 'Coaches', 'squash-club-booking' ); ?>">
                        <span class="scb-sidebar__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                <circle cx="12" cy="7" r="4"/>
                            </svg>
                        </span>
                        <span class="scb-sidebar__label"><?php esc_html_e( 'Coaches', 'squash-club-booking' ); ?></span>
                    </a>
                </li>

                <li class="scb-sidebar__item" data-view="schedule">
                    <a class="scb-sidebar__link" href="<?php echo esc_url( admin_url( 'admin.php?page=scb-schedule' ) ); ?>" data-page="scb-schedule" title="<?php esc_attr_e( 'Schedule & Calendar', 'squash-club-booking' ); ?>">
                        <span class="scb-sidebar__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                <line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/>
                                <line x1="3" y1="10" x2="21" y2="10"/>
                            </svg>
                        </span>
                        <span class="scb-sidebar__label"><?php esc_html_e( 'Schedule & Calendar', 'squash-club-booking' ); ?></span>
                    </a>
                </li>

                <li class="scb-sidebar__item" data-view="waitlist">
                    <a class="scb-sidebar__link" href="<?php echo esc_url( admin_url( 'admin.php?page=scb-waitlist' ) ); ?>" data-page="scb-waitlist" title="<?php esc_attr_e( 'Waitlist', 'squash-club-booking' ); ?>">
                        <span class="scb-sidebar__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/>
                                <line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/>
                                <line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>
                            </svg>
                        </span>
                        <span class="scb-sidebar__label"><?php esc_html_e( 'Waitlist', 'squash-club-booking' ); ?></span>
                    </a>
                </li>

                <li class="scb-sidebar__separator"><span><?php esc_html_e( 'Summer Camps', 'squash-club-booking' ); ?></span></li>

                <li class="scb-sidebar__item" data-view="camps">
                    <a class="scb-sidebar__link" href="<?php echo esc_url( admin_url( 'admin.php?page=scb-camps' ) ); ?>" data-page="scb-camps" title="<?php esc_attr_e( 'Summer Camps', 'squash-club-booking' ); ?>">
                        <span class="scb-sidebar__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                                <polyline points="9 22 9 12 15 12 15 22"/>
                            </svg>
                        </span>
                        <span class="scb-sidebar__label"><?php esc_html_e( 'Summer Camps', 'squash-club-booking' ); ?></span>
                    </a>
                </li>

                <li class="scb-sidebar__item" data-view="registrations">
                    <a class="scb-sidebar__link" href="<?php echo esc_url( admin_url( 'admin.php?page=scb-registrations' ) ); ?>" data-page="scb-registrations" title="<?php esc_attr_e( 'Registrations', 'squash-club-booking' ); ?>">
                        <span class="scb-sidebar__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                            </svg>
                        </span>
                        <span class="scb-sidebar__label"><?php esc_html_e( 'Registrations', 'squash-club-booking' ); ?></span>
                    </a>
                </li>

                <li class="scb-sidebar__separator"><span><?php esc_html_e( 'Finance & Reports', 'squash-club-booking' ); ?></span></li>

                <li class="scb-sidebar__item" data-view="payments">
                    <a class="scb-sidebar__link" href="<?php echo esc_url( admin_url( 'admin.php?page=scb-payments' ) ); ?>" data-page="scb-payments" title="<?php esc_attr_e( 'Payments', 'squash-club-booking' ); ?>">
                        <span class="scb-sidebar__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
                                <line x1="1" y1="10" x2="23" y2="10"/>
                            </svg>
                        </span>
                        <span class="scb-sidebar__label"><?php esc_html_e( 'Payments', 'squash-club-booking' ); ?></span>
                    </a>
                </li>

                <li class="scb-sidebar__item" data-view="customers">
                    <a class="scb-sidebar__link" href="<?php echo esc_url( admin_url( 'admin.php?page=scb-customers' ) ); ?>" data-page="scb-customers" title="<?php esc_attr_e( 'Customers', 'squash-club-booking' ); ?>">
                        <span class="scb-sidebar__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                                <circle cx="9" cy="7" r="4"/>
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                            </svg>
                        </span>
                        <span class="scb-sidebar__label"><?php esc_html_e( 'Customers', 'squash-club-booking' ); ?></span>
                    </a>
                </li>

                <li class="scb-sidebar__item" data-view="reports">
                    <a class="scb-sidebar__link" href="<?php echo esc_url( admin_url( 'admin.php?page=scb-reports' ) ); ?>" data-page="scb-reports" title="<?php esc_attr_e( 'Reports', 'squash-club-booking' ); ?>">
                        <span class="scb-sidebar__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/>
                                <line x1="6" y1="20" x2="6" y2="14"/><line x1="2" y1="20" x2="22" y2="20"/>
                            </svg>
                        </span>
                        <span class="scb-sidebar__label"><?php esc_html_e( 'Reports', 'squash-club-booking' ); ?></span>
                    </a>
                </li>

                <li class="scb-sidebar__separator"><span><?php esc_html_e( 'System', 'squash-club-booking' ); ?></span></li>

                <li class="scb-sidebar__item" data-view="notifications">
                    <a class="scb-sidebar__link" href="<?php echo esc_url( admin_url( 'admin.php?page=scb-notifications' ) ); ?>" data-page="scb-notifications" title="<?php esc_attr_e( 'Notifications', 'squash-club-booking' ); ?>">
                        <span class="scb-sidebar__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                                <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                            </svg>
                        </span>
                        <span class="scb-sidebar__label"><?php esc_html_e( 'Notifications', 'squash-club-booking' ); ?></span>
                    </a>
                </li>

                <li class="scb-sidebar__item" data-view="settings">
                    <a class="scb-sidebar__link" href="<?php echo esc_url( admin_url( 'admin.php?page=scb-settings' ) ); ?>" data-page="scb-settings" title="<?php esc_attr_e( 'Settings', 'squash-club-booking' ); ?>">
                        <span class="scb-sidebar__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="3"/>
                                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                            </svg>
                        </span>
                        <span class="scb-sidebar__label"><?php esc_html_e( 'Settings', 'squash-club-booking' ); ?></span>
                    </a>
                </li>

            </ul>
        </nav>

        <!-- Sidebar User Block -->
        <div class="scb-sidebar__user">
            <img class="scb-sidebar__user-avatar" src="<?php echo esc_url( $user_avatar ); ?>" alt="<?php echo esc_attr( $user_name ); ?>" width="36" height="36">
            <div class="scb-sidebar__user-info">
                <span class="scb-sidebar__user-name"><?php echo esc_html( $user_name ); ?></span>
                <a class="scb-sidebar__user-wp" href="<?php echo esc_url( admin_url() ); ?>"><?php esc_html_e( 'WP Admin', 'squash-club-booking' ); ?> ↗</a>
            </div>
        </div>

    </aside><!-- /.scb-sidebar -->

    <!-- Main Content Area -->
    <div class="scb-main" id="scb-main">

        <!-- Top Bar -->
        <header class="scb-topbar" id="scb-topbar">
            <div class="scb-topbar__left">
                <button class="scb-topbar__menu-toggle" id="scb-mobile-menu-toggle" aria-label="<?php esc_attr_e( 'Open menu', 'squash-club-booking' ); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
                    </svg>
                </button>
                <div class="scb-topbar__app-badge">
                    <svg viewBox="0 0 24 24" fill="currentColor" width="16" height="16">
                        <circle cx="4" cy="4" r="2"/><circle cx="12" cy="4" r="2"/><circle cx="20" cy="4" r="2"/>
                        <circle cx="4" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="20" cy="12" r="2"/>
                        <circle cx="4" cy="20" r="2"/><circle cx="12" cy="20" r="2"/><circle cx="20" cy="20" r="2"/>
                    </svg>
                    <span>Squash Club</span>
                </div>
                <nav class="scb-topbar__breadcrumbs" id="scb-breadcrumbs" aria-label="<?php esc_attr_e( 'Breadcrumb', 'squash-club-booking' ); ?>">
                    <span class="scb-breadcrumb"><?php esc_html_e( 'Dashboard', 'squash-club-booking' ); ?></span>
                </nav>
            </div>
            <div class="scb-topbar__right">
                <!-- Notification bell -->
                <button class="scb-topbar__btn" id="scb-notif-btn" aria-label="<?php esc_attr_e( 'Notifications', 'squash-club-booking' ); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                    </svg>
                    <span class="scb-topbar__badge" id="scb-notif-count" style="display:none;">0</span>
                </button>
                <!-- Refresh data -->
                <button class="scb-topbar__btn" id="scb-refresh-btn" aria-label="<?php esc_attr_e( 'Refresh data', 'squash-club-booking' ); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="23 4 23 10 17 10"/>
                        <polyline points="1 20 1 14 7 14"/>
                        <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
                    </svg>
                </button>
                <!-- User Profile Pill -->
                <div class="scb-topbar__profile">
                    <img class="scb-topbar__avatar" src="<?php echo esc_url( $user_avatar ); ?>" alt="<?php echo esc_attr( $user_name ); ?>" width="30" height="30">
                    <span class="scb-topbar__username"><?php echo esc_html( $user_name ); ?></span>
                </div>
            </div>
        </header>

        <!-- Page Content Mount Point -->
        <main class="scb-content" id="scb-content" role="main">
            <!-- Initial loading state -->
            <div class="scb-loading-overlay" id="scb-initial-loader">
                <div class="scb-spinner scb-spinner--lg"></div>
                <p><?php esc_html_e( 'Loading…', 'squash-club-booking' ); ?></p>
            </div>
        </main>

    </div><!-- /.scb-main -->

    <!-- Toast Notification Container -->
    <div class="scb-toast-container" id="scb-toast-container" role="status" aria-live="polite" aria-atomic="false"></div>

    <!-- Global Modal Overlay -->
    <div class="scb-modal-overlay" id="scb-modal-overlay" hidden>
        <div class="scb-modal" id="scb-modal" role="dialog" aria-modal="true" aria-labelledby="scb-modal-title">
            <div class="scb-modal__header">
                <h2 class="scb-modal__title" id="scb-modal-title"></h2>
                <button class="scb-modal__close" id="scb-modal-close" aria-label="<?php esc_attr_e( 'Close', 'squash-club-booking' ); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>
            <div class="scb-modal__body" id="scb-modal-body"></div>
            <div class="scb-modal__footer" id="scb-modal-footer"></div>
        </div>
    </div>

</div><!-- /#scb-app -->
</div><!-- /.wrap.scb-admin-wrap -->
