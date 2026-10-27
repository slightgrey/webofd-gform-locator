<?php
/**
 * Core plugin bootstrap.
 *
 * @package GFFormLocator
 */

declare( strict_types=1 );

namespace GFFormLocator;

/**
 * Bootstraps all plugin components by wiring hooks through the Loader.
 */
class Plugin {

	/**
	 * Hook loader instance.
	 *
	 * @var Loader
	 */
	private Loader $loader;

	/**
	 * Sets up the Loader and registers all component hooks.
	 */
	public function __construct() {
		$this->loader = new Loader();
		$this->register_hooks();
	}

	/**
	 * Wires all component hooks into the Loader.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		$this->register_i18n_hooks();
		$this->register_upgrade_hooks();
		$this->register_rest_hooks();
		$this->register_admin_hooks();
		$this->register_network_hooks();
	}

	/**
	 * Registers multisite site-lifecycle hooks.
	 *
	 * @return void
	 */
	private function register_network_hooks(): void {
		if ( ! is_multisite() ) {
			return;
		}

		$network = new Network\NetworkHooks();
		$this->loader->add_action( 'wp_initialize_site', array( $network, 'initialize_site' ), 200 );
		$this->loader->add_filter( 'wpmu_drop_tables', array( $network, 'drop_tables' ), 10, 2 );

		if ( is_network_admin() ) {
			$overview = new Admin\NetworkOverviewPage();
			$this->loader->add_action( 'network_admin_menu', array( $overview, 'register_menu' ) );
		}
	}

	/**
	 * Registers the Tools page.
	 *
	 * @return void
	 */
	private function register_admin_hooks(): void {
		if ( ! is_admin() ) {
			return;
		}

		$report = new Admin\ReportPage();
		$this->loader->add_action( 'admin_menu', array( $report, 'register_menu' ) );
		$this->loader->add_action( 'admin_enqueue_scripts', array( $report, 'enqueue_assets' ) );
		$this->loader->add_action( 'admin_notices', array( $report, 'render_gf_notice' ) );
		$this->loader->add_filter( 'set_screen_option_' . Admin\ReportPage::PER_PAGE_OPTION, array( $report, 'save_per_page' ), 10, 3 );
	}

	/**
	 * Registers the scan REST routes.
	 *
	 * @return void
	 */
	private function register_rest_hooks(): void {
		$controller = new Rest\ScanController();
		$this->loader->add_action( 'rest_api_init', array( $controller, 'register_routes' ) );
	}

	/**
	 * Registers the schema upgrade check.
	 *
	 * @return void
	 */
	private function register_upgrade_hooks(): void {
		$this->loader->add_action( 'plugins_loaded', array( Installer::class, 'maybe_upgrade' ) );
	}

	/**
	 * Registers the text domain loader.
	 *
	 * @return void
	 */
	private function register_i18n_hooks(): void {
		$i18n = new I18n();
		$this->loader->add_action( 'init', array( $i18n, 'load_textdomain' ) );
	}

	/**
	 * Runs the loader to register all hooks with WordPress.
	 *
	 * Call this once from the main plugin file.
	 *
	 * @return void
	 */
	public static function run(): void {
		$instance = new self();
		$instance->loader->run();
	}
}
