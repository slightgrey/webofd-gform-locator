<?php
/**
 * The Tools → Web of D Form Locator screen.
 *
 * @package GFFormLocator
 */

declare( strict_types=1 );

namespace GFFormLocator\Admin;

use GFFormLocator\Capability;
use GFFormLocator\FormResolver;
use GFFormLocator\ResultsRepository;
use GFFormLocator\Scanner;

/**
 * Registers the report page and renders its header, scan controls and summary.
 */
class ReportPage {

	/**
	 * Admin page slug.
	 */
	public const SLUG = 'webofd-form-locator';

	/**
	 * Screen option name for rows per page.
	 *
	 * Lives here, not on ResultsTable, so registering hooks never autoloads
	 * ResultsTable (and its WP_List_Table parent) before core has loaded it.
	 */
	public const PER_PAGE_OPTION = 'gffl_per_page';

	/**
	 * Hook suffix returned by add_management_page().
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Scanner, used for scan status and the last-scan summary.
	 *
	 * @var Scanner|null
	 */
	private ?Scanner $scanner = null;

	/**
	 * Form lookup shared by the table, summary and notice.
	 *
	 * @var FormResolver|null
	 */
	private ?FormResolver $forms = null;

	/**
	 * Results storage.
	 *
	 * @var ResultsRepository|null
	 */
	private ?ResultsRepository $results = null;

	/**
	 * Adds the page under Tools.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$this->hook_suffix = (string) add_management_page(
			__( 'Web of D Form Locator', 'webofd-form-locator' ),
			__( 'Form Locator', 'webofd-form-locator' ),
			Capability::required(),
			self::SLUG,
			array( $this, 'render' )
		);

		if ( '' !== $this->hook_suffix ) {
			add_action( 'load-' . $this->hook_suffix, array( $this, 'add_screen_options' ) );
		}
	}

	/**
	 * Adds the "Embeds per page" screen option.
	 *
	 * @return void
	 */
	public function add_screen_options(): void {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Embeds per page', 'webofd-form-locator' ),
				'default' => 20,
				'option'  => self::PER_PAGE_OPTION,
			)
		);
	}

	/**
	 * Saves the "Embeds per page" screen option.
	 *
	 * @param mixed  $screen_option Value to save (false to skip).
	 * @param string $option        Option name.
	 * @param mixed  $value         Submitted value.
	 *
	 * @return mixed Clamped value for our option, otherwise unchanged.
	 */
	public function save_per_page( $screen_option, $option, $value ) {
		if ( self::PER_PAGE_OPTION !== $option ) {
			return $screen_option;
		}

		return min( 999, max( 1, absint( $value ) ) );
	}

	/**
	 * Enqueues the scan script and styles on this page only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 *
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'gffl-admin', GFFL_URL . 'assets/css/admin.css', array(), GFFL_VERSION );
		wp_enqueue_script( 'gffl-admin-scan', GFFL_URL . 'assets/js/admin-scan.js', array( 'wp-api-fetch', 'wp-i18n' ), GFFL_VERSION, true );
		wp_localize_script( 'gffl-admin-scan', 'gfflScan', array( 'restPath' => 'webofd-form-locator/v1/scan' ) );
		wp_set_script_translations( 'gffl-admin-scan', 'webofd-form-locator', GFFL_DIR . 'languages' );
	}

	/**
	 * Explains, on this page only, that form titles are unavailable without Gravity Forms data.
	 *
	 * @return void
	 */
	public function render_gf_notice(): void {
		$screen = get_current_screen();

		if ( null === $screen || '' === $this->hook_suffix || $screen->id !== $this->hook_suffix || ! Capability::current_user_can() ) {
			return;
		}

		if ( $this->forms()->has_source() ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%s</p></div>',
			esc_html__( "Gravity Forms isn't installed and no form tables were found. Form titles can't be shown; IDs only.", 'webofd-form-locator' )
		);
	}

	/**
	 * Renders the whole page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! Capability::current_user_can() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'webofd-form-locator' ) );
		}

		echo '<div class="wrap gffl-report">';
		printf( '<h1 class="wp-heading-inline">%s</h1>', esc_html__( 'Web of D Form Locator', 'webofd-form-locator' ) );
		$this->render_scan_controls();
		$this->render_status();
		$this->render_summary();
		$this->render_table();
		echo '</div>';
	}

	/**
	 * Renders "X forms used in Y locations · Last scanned …" and the missing-form warning.
	 *
	 * @return void
	 */
	private function render_summary(): void {
		$last_scan = $this->scanner()->last_scan();

		if ( null === $last_scan ) {
			return;
		}

		$summary = $this->results()->summary();

		printf(
			'<p class="gffl-summary">%1$s &middot; %2$s</p>',
			esc_html( $this->usage_text( $summary['forms'], $summary['locations'] ) ),
			esc_html( $this->last_scan_text( $last_scan ) )
		);

		$this->render_missing_warning();
	}

	/**
	 * Builds "X forms used in Y locations".
	 *
	 * @param int $forms     Distinct forms.
	 * @param int $locations Distinct locations.
	 *
	 * @return string Text.
	 */
	private function usage_text( int $forms, int $locations ): string {
		return sprintf(
			/* translators: 1: number of forms with its noun, e.g. 3 forms. 2: number of locations with its noun, e.g. 12 locations. */
			__( '%1$s used in %2$s', 'webofd-form-locator' ),
			/* translators: %s: number of forms. */
			sprintf( _n( '%s form', '%s forms', $forms, 'webofd-form-locator' ), number_format_i18n( $forms ) ),
			/* translators: %s: number of locations. */
			sprintf( _n( '%s location', '%s locations', $locations, 'webofd-form-locator' ), number_format_i18n( $locations ) )
		);
	}

	/**
	 * Builds "Last scanned <date> (<n> s)".
	 *
	 * @param array<string, mixed> $last_scan Stored summary.
	 *
	 * @return string Text.
	 */
	private function last_scan_text( array $last_scan ): string {
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$date   = wp_date( $format, (int) ( $last_scan['finished_at'] ?? 0 ) );

		return sprintf(
			/* translators: 1: date and time, 2: duration in seconds. */
			__( 'Last scanned %1$s (%2$s s)', 'webofd-form-locator' ),
			(string) $date,
			number_format_i18n( (float) ( $last_scan['duration'] ?? 0 ), 1 )
		);
	}

	/**
	 * Warns when embeds point to forms that no longer exist.
	 *
	 * @return void
	 */
	private function render_missing_warning(): void {
		$missing = $this->missing_embed_count();

		if ( 0 === $missing ) {
			return;
		}

		printf(
			'<div class="notice notice-warning inline gffl-missing"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: number of embeds. */
					_n( '%s embed points to a form that no longer exists. Filter by the forms marked Missing to find it.', '%s embeds point to forms that no longer exist. Filter by the forms marked Missing to find them.', $missing, 'webofd-form-locator' ),
					number_format_i18n( $missing )
				)
			)
		);
	}

	/**
	 * Counts embeds whose form resolves as missing.
	 *
	 * @return int Embed count.
	 */
	private function missing_embed_count(): int {
		return $this->results()->count_missing( $this->forms() );
	}

	/**
	 * Renders the results table inside a GET form (filters, search, sorting).
	 *
	 * @return void
	 */
	private function render_table(): void {
		if ( null === $this->scanner()->last_scan() ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

		$table = new ResultsTable( $this->results(), $this->forms(), true );
		$table->prepare_items();

		echo '<form method="get" class="gffl-results">';
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( self::SLUG ) );
		$table->search_box( __( 'Search locations', 'webofd-form-locator' ), 'gffl-search' );
		$table->display();
		echo '</form>';
	}

	/**
	 * Renders the Scan now button, progress bar and message area.
	 *
	 * @return void
	 */
	private function render_scan_controls(): void {
		?>
		<div class="gffl-scan">
			<button type="button" class="button button-primary" id="gffl-scan-button"><?php esc_html_e( 'Scan now', 'webofd-form-locator' ); ?></button>
			<progress id="gffl-scan-progress" max="100" value="0" hidden></progress>
			<span id="gffl-scan-progress-text" class="gffl-scan-progress-text" aria-live="polite"></span>
			<div id="gffl-scan-message" class="gffl-scan-message" role="alert"></div>
		</div>
		<?php
	}

	/**
	 * Renders the scan state: no scan yet, incomplete, or the last-scan line.
	 *
	 * @return void
	 */
	private function render_status(): void {
		$status = $this->scanner()->status();

		if ( 'incomplete' === $status['state'] ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html__( 'Last scan incomplete: it stopped before finishing. Run Scan now again for complete results.', 'webofd-form-locator' )
			);
		}

		if ( null === $this->scanner()->last_scan() && 'idle' === $status['state'] ) {
			printf( '<p class="gffl-empty">%s</p>', esc_html__( 'No scan yet: click Scan now.', 'webofd-form-locator' ) );
		}
	}

	/**
	 * Returns the form lookup, creating it on first use.
	 *
	 * @return FormResolver Form lookup.
	 */
	private function forms(): FormResolver {
		if ( null === $this->forms ) {
			$this->forms = new FormResolver();
		}

		return $this->forms;
	}

	/**
	 * Returns the results storage, creating it on first use.
	 *
	 * @return ResultsRepository Results storage.
	 */
	private function results(): ResultsRepository {
		if ( null === $this->results ) {
			$this->results = new ResultsRepository();
		}

		return $this->results;
	}

	/**
	 * Returns the scanner, creating it on first use.
	 *
	 * @return Scanner Scanner.
	 */
	private function scanner(): Scanner {
		if ( null === $this->scanner ) {
			$this->scanner = Scanner::create();
		}

		return $this->scanner;
	}
}
