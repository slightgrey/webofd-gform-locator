<?php
/**
 * Network Admin → Settings → Web of D Form Locator: last scan per site.
 *
 * @package GFFormLocator
 */

declare( strict_types=1 );

namespace GFFormLocator\Admin;

use GFFormLocator\Scanner;
use WP_Site;

/**
 * Read-only overview of every site's last scan. Never runs a scan and never
 * reads other sites' results tables; it only reads each site's stored summary.
 */
class NetworkOverviewPage {

	/**
	 * Capability needed to view the overview.
	 */
	private const CAPABILITY = 'manage_network_options';

	/**
	 * Sites per page.
	 */
	private const PER_PAGE = 20;

	/**
	 * Adds the page under Network Admin → Settings.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			'settings.php',
			__( 'Web of D Form Locator', 'webofd-form-locator' ),
			__( 'Form Locator', 'webofd-form-locator' ),
			self::CAPABILITY,
			ReportPage::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Renders the overview.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'webofd-form-locator' ) );
		}

		$page  = $this->current_page();
		$total = (int) get_sites(
			array(
				'count'      => true,
				'network_id' => get_current_network_id(),
			)
		);

		echo '<div class="wrap gffl-network">';
		printf( '<h1>%s</h1>', esc_html__( 'Web of D Form Locator: sites', 'webofd-form-locator' ) );
		printf( '<p>%s</p>', esc_html__( 'Last scan of each site. Scans run per site from Tools → Form Locator.', 'webofd-form-locator' ) );
		$this->render_table( $this->sites( $page ) );
		$this->render_pagination( $page, $total );
		echo '</div>';
	}

	/**
	 * Reads the requested page number.
	 *
	 * @return int Page, at least 1.
	 */
	private function current_page(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		return max( 1, isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1 );
	}

	/**
	 * Loads one page of sites.
	 *
	 * @param int $page Page number.
	 *
	 * @return array<int, WP_Site> Sites.
	 */
	private function sites( int $page ): array {
		$sites = get_sites(
			array(
				'number'     => self::PER_PAGE,
				'offset'     => ( $page - 1 ) * self::PER_PAGE,
				'network_id' => get_current_network_id(),
				'orderby'    => 'id',
			)
		);

		return array_values( $sites );
	}

	/**
	 * Renders the sites table.
	 *
	 * @param array<int, WP_Site> $sites Sites on this page.
	 *
	 * @return void
	 */
	private function render_table( array $sites ): void {
		$headers = array(
			__( 'Site', 'webofd-form-locator' ),
			__( 'Last scanned', 'webofd-form-locator' ),
			__( 'Forms', 'webofd-form-locator' ),
			__( 'Locations', 'webofd-form-locator' ),
			__( 'Missing-form embeds', 'webofd-form-locator' ),
			__( 'Report', 'webofd-form-locator' ),
		);

		echo '<table class="widefat striped gffl-network-table"><thead><tr>';

		foreach ( $headers as $header ) {
			printf( '<th scope="col">%s</th>', esc_html( $header ) );
		}

		echo '</tr></thead><tbody>';

		foreach ( $sites as $site ) {
			$this->render_row( $site );
		}

		echo '</tbody></table>';
	}

	/**
	 * Renders one site's row.
	 *
	 * @param WP_Site $site Site.
	 *
	 * @return void
	 */
	private function render_row( WP_Site $site ): void {
		$site_id = (int) $site->blog_id;
		$summary = get_blog_option( $site_id, Scanner::LAST_SCAN_OPTION, null );
		$report  = get_admin_url( $site_id, 'tools.php?page=' . ReportPage::SLUG );

		echo '<tr>';
		printf(
			'<td><strong>%1$s</strong><br><span class="gffl-muted">%2$s</span></td>',
			esc_html( (string) $site->blogname ),
			esc_html( $site->domain . $site->path )
		);

		if ( ! is_array( $summary ) ) {
			printf( '<td colspan="4"><em>%s</em></td>', esc_html__( 'Not scanned', 'webofd-form-locator' ) );
		} else {
			$this->render_summary_cells( $summary );
		}

		printf( '<td><a href="%1$s">%2$s</a></td>', esc_url( $report ), esc_html__( 'View report', 'webofd-form-locator' ) );
		echo '</tr>';
	}

	/**
	 * Renders the date and count cells from a site's stored summary.
	 *
	 * @param array<string, mixed> $summary gffl_last_scan value.
	 *
	 * @return void
	 */
	private function render_summary_cells( array $summary ): void {
		$format = get_site_option( 'date_format', 'F j, Y' ) . ' ' . get_site_option( 'time_format', 'g:i a' );

		printf( '<td>%s</td>', esc_html( (string) wp_date( (string) $format, (int) ( $summary['finished_at'] ?? 0 ) ) ) );

		foreach ( array( 'forms', 'locations', 'missing' ) as $key ) {
			$value = isset( $summary[ $key ] ) ? number_format_i18n( (int) $summary[ $key ] ) : '—';
			printf( '<td>%s</td>', esc_html( $value ) );
		}
	}

	/**
	 * Renders page links when there is more than one page.
	 *
	 * @param int $page  Current page.
	 * @param int $total Total sites.
	 *
	 * @return void
	 */
	private function render_pagination( int $page, int $total ): void {
		$pages = (int) ceil( $total / self::PER_PAGE );

		if ( $pages < 2 ) {
			return;
		}

		$links = paginate_links(
			array(
				'base'    => add_query_arg( 'paged', '%#%' ),
				'format'  => '',
				'current' => $page,
				'total'   => $pages,
			)
		);

		printf( '<div class="tablenav"><div class="tablenav-pages">%s</div></div>', wp_kses_post( (string) $links ) );
	}
}
