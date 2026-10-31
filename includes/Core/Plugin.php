<?php
namespace SquashClubBooking\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main Plugin singleton.
 *
 * Bootstraps all sub-modules and wires up hooks after WordPress has fully loaded.
 *
 * @package SquashClubBooking\Core
 */
class Plugin {

    /** @var Plugin|null Single instance */
    private static ?Plugin $instance = null;

    /** @var array Loaded modules */
    private array $modules = [];

    /** Private constructor — use Plugin::instance() */
    private function __construct() {}

    /**
     * Return (or create) the singleton.
     */
    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Run after 'plugins_loaded'. Boot every sub-system in order.
     */
    public function init(): void {
        $this->load_textdomain();
        $this->check_dependencies();
        $this->boot_modules();

        // Seed initial data if tables are empty.
        \SquashClubBooking\Database\Seeder::seed_if_empty();
    }

    /**
     * Load plugin translations.
     */
    private function load_textdomain(): void {
        load_plugin_textdomain(
            'squash-club-booking',
            false,
            dirname( plugin_basename( SCB_PLUGIN_FILE ) ) . '/languages'
        );
    }

    /**
     * Check that required plugins (WooCommerce) are active.
     */
    private function check_dependencies(): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', [ $this, 'notice_woocommerce_missing' ] );
        }
    }

    /**
     * Admin notice when WooCommerce is not active.
     */
    public function notice_woocommerce_missing(): void {
        ?>
        <div class="notice notice-warning is-dismissible">
            <p>
                <?php
                esc_html_e(
                    'Squash Club Booking: WooCommerce is not active. Payment processing features will be unavailable until WooCommerce is installed and activated.',
                    'squash-club-booking'
                );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * Instantiate and register all plugin modules.
     */
    private function boot_modules(): void {
        $modules = [
            'assets'       => \SquashClubBooking\Core\Assets::class,
            'settings'     => \SquashClubBooking\Core\Settings::class,
            'capabilities' => \SquashClubBooking\Core\Capabilities::class,
            'admin'        => \SquashClubBooking\Admin\Dashboard::class,
            'rest_api'     => \SquashClubBooking\Core\RestApi::class,
            'cron'         => \SquashClubBooking\Core\Cron::class,
            'clinics'      => \SquashClubBooking\Modules\Clinics\ClinicModule::class,
            'lessons'      => \SquashClubBooking\Modules\Lessons\LessonModule::class,
            'camps'        => \SquashClubBooking\Modules\Camps\CampModule::class,
            'payments'     => \SquashClubBooking\Modules\Payments\PaymentModule::class,
            'notifications' => \SquashClubBooking\Modules\Notifications\NotificationModule::class,
            'waitlist'     => \SquashClubBooking\Modules\Waitlist\WaitlistModule::class,
            'frontend'     => \SquashClubBooking\Core\Frontend::class,
        ];

        foreach ( $modules as $key => $class ) {
            if ( class_exists( $class ) ) {
                $this->modules[ $key ] = new $class();
                if ( method_exists( $this->modules[ $key ], 'register' ) ) {
                    $this->modules[ $key ]->register();
                }
            }
        }
    }

    /**
     * Get a loaded module by key.
     *
     * @param string $key
     * @return mixed|null
     */
    public function module( string $key ) {
        return $this->modules[ $key ] ?? null;
    }
}
