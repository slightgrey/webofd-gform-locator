<?php
/**
 * Runs a scan in batches: start, batch until done, finish.
 *
 * @package GFFormLocator
 */

declare( strict_types=1 );

namespace GFFormLocator;

use GFFormLocator\Source\PostSource;
use GFFormLocator\Source\WidgetSource;
use WP_Error;

/**
 * Scan engine. State lives in the gffl_scan_state option so each REST
 * request can pick up where the previous one stopped.
 *
 * @phpstan-type ScanState array{queue: array<int, int>, total: int, processed: int, started_at: float, lock_at: int, token: string}
 */
class Scanner {

	/**
	 * Option holding the last completed scan's summary.
	 */
	public const LAST_SCAN_OPTION = 'gffl_last_scan';

	/**
	 * Seconds after which an untouched scan lock is treated as abandoned.
	 */
	public const LOCK_TTL = 600;

	/**
	 * Post source.
	 *
	 * @var PostSource
	 */
	private PostSource $posts;

	/**
	 * Widget source.
	 *
	 * @var WidgetSource
	 */
	private WidgetSource $widgets;

	/**
	 * Results storage.
	 *
	 * @var ResultsRepository
	 */
	private ResultsRepository $results;

	/**
	 * Creates the scanner.
	 *
	 * @param PostSource        $posts   Post source.
	 * @param WidgetSource      $widgets Widget source.
	 * @param ResultsRepository $results Results storage.
	 */
	public function __construct( PostSource $posts, WidgetSource $widgets, ResultsRepository $results ) {
		$this->posts   = $posts;
		$this->widgets = $widgets;
		$this->results = $results;
	}

	/**
	 * Creates the scanner with default dependencies.
	 *
	 * @return self Scanner.
	 */
	public static function create(): self {
		return new self( PostSource::create(), WidgetSource::create(), new ResultsRepository() );
	}

	/**
	 * Starts a scan: clears old results and queues candidate posts.
	 *
	 * The returned token identifies the caller as the scan's owner; batch()
	 * and finish() refuse any other caller, so two browser tabs can never
	 * process the same queue twice.
	 *
	 * @return array{total: int, token: string}|WP_Error Queue size and owner token, or a 409 error if a scan is running.
	 */
	public function start() {
		if ( $this->is_locked() ) {
			return new WP_Error( 'gffl_scan_running', __( 'A scan is already running. Wait for it to finish, or try again in a few minutes.', 'webofd-form-locator' ), array( 'status' => 409 ) );
		}

		$queue = $this->posts->candidate_ids();
		$token = wp_generate_password( 32, false );

		$this->results->truncate();
		$this->save_state(
			array(
				'queue'      => $queue,
				'total'      => count( $queue ),
				'processed'  => 0,
				'started_at' => microtime( true ),
				'lock_at'    => time(),
				'token'      => $token,
			)
		);

		return array(
			'total' => count( $queue ),
			'token' => $token,
		);
	}

	/**
	 * Scans the next chunk of queued posts and stores their results.
	 *
	 * @param string $token Owner token returned by start().
	 *
	 * @return array{processed: int, total: int, done: bool}|WP_Error Progress, or an error if no scan was started or the token is wrong.
	 */
	public function batch( string $token ) {
		$state = $this->owned_state( $token );

		if ( is_wp_error( $state ) ) {
			return $state;
		}

		$chunk = array_slice( $state['queue'], $state['processed'], $this->batch_size() );

		foreach ( $chunk as $post_id ) {
			$this->results->insert_many( $this->posts->scan_post( $post_id ) );
		}

		$state['processed'] += count( $chunk );
		$state['lock_at']    = time();
		$this->save_state( $state );

		return $this->progress( $state );
	}

	/**
	 * Finishes a scan: scans widgets, saves the summary and releases the lock.
	 *
	 * @param string $token Owner token returned by start().
	 *
	 * @return array{matches: int, duration: float}|WP_Error Summary, or an error if no scan is ready to finish.
	 */
	public function finish( string $token ) {
		$state = $this->owned_state( $token );

		if ( is_wp_error( $state ) ) {
			return $state;
		}

		if ( ! $this->progress( $state )['done'] ) {
			return new WP_Error( 'gffl_scan_not_done', __( 'The scan still has posts to process.', 'webofd-form-locator' ), array( 'status' => 409 ) );
		}

		$this->results->insert_many( $this->widgets->scan() );

		$summary = $this->build_summary( $state );
		update_option( self::LAST_SCAN_OPTION, $summary, false );
		Installer::clear_scan_state();

		return array(
			'matches'  => $summary['matches'],
			'duration' => $summary['duration'],
		);
	}

	/**
	 * Reports what the scanner is doing.
	 *
	 * @return array{state: string, processed: int, total: int} state is idle, running or incomplete.
	 */
	public function status(): array {
		$state = $this->state();

		if ( null === $state ) {
			return array(
				'state'     => 'idle',
				'processed' => 0,
				'total'     => 0,
			);
		}

		return array(
			'state'     => $this->is_locked() ? 'running' : 'incomplete',
			'processed' => $state['processed'],
			'total'     => $state['total'],
		);
	}

	/**
	 * Returns the last completed scan's summary.
	 *
	 * @return array<string, mixed>|null Summary, or null if no scan has finished.
	 */
	public function last_scan(): ?array {
		$summary = get_option( self::LAST_SCAN_OPTION, null );

		return is_array( $summary ) ? $summary : null;
	}

	/**
	 * Builds the stored summary for a finished scan.
	 *
	 * @param array<string, mixed> $state Scan state.
	 * @phpstan-param ScanState $state
	 *
	 * Forms, locations and missing are stored so the Network Admin overview can
	 * show every site without querying other sites' tables.
	 *
	 * @return array{started_at: int, finished_at: int, posts_scanned: int, matches: int, forms: int, locations: int, missing: int, duration: float} Summary.
	 */
	private function build_summary( array $state ): array {
		$finished = microtime( true );
		$counts   = $this->results->summary();

		return array(
			'started_at'    => (int) $state['started_at'],
			'finished_at'   => (int) $finished,
			'posts_scanned' => $state['processed'],
			'matches'       => $counts['rows'],
			'forms'         => $counts['forms'],
			'locations'     => $counts['locations'],
			'missing'       => $this->results->count_missing( new FormResolver() ),
			'duration'      => round( $finished - $state['started_at'], 1 ),
		);
	}

	/**
	 * Returns the number of posts scanned per batch request.
	 *
	 * @return int Batch size, at least 1.
	 */
	private function batch_size(): int {
		return max( 1, (int) apply_filters( 'gffl_batch_size', 50 ) );
	}

	/**
	 * Builds the progress payload.
	 *
	 * @param array<string, mixed> $state Scan state.
	 * @phpstan-param ScanState $state
	 *
	 * @return array{processed: int, total: int, done: bool} Progress.
	 */
	private function progress( array $state ): array {
		return array(
			'processed' => $state['processed'],
			'total'     => $state['total'],
			'done'      => $state['processed'] >= $state['total'],
		);
	}

	/**
	 * Loads the scan state if the token matches its owner.
	 *
	 * @param string $token Owner token.
	 *
	 * @return array<string, mixed>|WP_Error State, or a 400 (no scan) / 409 (not the owner) error.
	 * @phpstan-return ScanState|WP_Error
	 */
	private function owned_state( string $token ) {
		$state = $this->state();

		if ( null === $state ) {
			return $this->no_scan_error();
		}

		if ( '' === $token || ! hash_equals( $state['token'], $token ) ) {
			return new WP_Error( 'gffl_scan_not_owner', __( 'This scan is running in another window.', 'webofd-form-locator' ), array( 'status' => 409 ) );
		}

		return $state;
	}

	/**
	 * Error returned when batch or finish is called without a started scan.
	 *
	 * @return WP_Error 400 error.
	 */
	private function no_scan_error(): WP_Error {
		return new WP_Error( 'gffl_no_scan', __( 'No scan is in progress. Start a new scan.', 'webofd-form-locator' ), array( 'status' => 400 ) );
	}

	/**
	 * Tells whether a scan holds a lock that hasn't expired.
	 *
	 * @return bool True if a scan is running.
	 */
	public function is_locked(): bool {
		$state = $this->state();

		return null !== $state && ( time() - $state['lock_at'] ) < self::LOCK_TTL;
	}

	/**
	 * Loads the in-progress scan state.
	 *
	 * @return array<string, mixed>|null State, or null when no scan is in progress.
	 * @phpstan-return ScanState|null
	 */
	private function state(): ?array {
		$state = get_option( Installer::SCAN_STATE_OPTION, null );

		if ( ! is_array( $state ) || ! isset( $state['queue'], $state['total'], $state['processed'], $state['started_at'], $state['lock_at'] ) ) {
			return null;
		}

		return array(
			'queue'      => array_map( 'intval', (array) $state['queue'] ),
			'total'      => (int) $state['total'],
			'processed'  => (int) $state['processed'],
			'started_at' => (float) $state['started_at'],
			'lock_at'    => (int) $state['lock_at'],
			'token'      => (string) ( $state['token'] ?? '' ),
		);
	}

	/**
	 * Saves the scan state without autoloading it.
	 *
	 * @param array<string, mixed> $state State.
	 * @phpstan-param ScanState $state
	 *
	 * @return void
	 */
	private function save_state( array $state ): void {
		update_option( Installer::SCAN_STATE_OPTION, $state, false );
	}
}
