<?php
/**
 * REST endpoints that drive a batched scan.
 *
 * @package GFFormLocator
 */

declare( strict_types=1 );

namespace GFFormLocator\Rest;

use GFFormLocator\Capability;
use GFFormLocator\Scanner;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Routes under webofd-form-locator/v1/scan: start, batch, finish (POST) and status (GET).
 */
class ScanController extends WP_REST_Controller {

	/**
	 * Scanner instance, created on first use.
	 *
	 * @var Scanner|null
	 */
	private ?Scanner $scanner = null;

	/**
	 * Sets the namespace and base.
	 */
	public function __construct() {
		$this->namespace = 'webofd-form-locator/v1';
		$this->rest_base = 'scan';
	}

	/**
	 * Registers the scan routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// Not "required": core validates required args before permission_callback,
		// which would answer unauthorised callers with 400 instead of 401/403.
		// Scanner rejects an empty or wrong token itself.
		$token_args = array(
			'token' => array(
				'description'       => __( 'Owner token returned by /scan/start.', 'webofd-form-locator' ),
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
		$routes     = array(
			'start'  => array( WP_REST_Server::CREATABLE, 'start', array() ),
			'batch'  => array( WP_REST_Server::CREATABLE, 'batch', $token_args ),
			'finish' => array( WP_REST_Server::CREATABLE, 'finish', $token_args ),
			'status' => array( WP_REST_Server::READABLE, 'status', array() ),
		);

		foreach ( $routes as $path => $route ) {
			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . '/' . $path,
				array(
					'methods'             => $route[0],
					'callback'            => array( $this, $route[1] ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $route[2],
				)
			);
		}
	}

	/**
	 * Allows only users with the plugin capability.
	 *
	 * @return true|WP_Error True if allowed, otherwise a 401/403 error.
	 */
	public function permissions_check() {
		if ( Capability::current_user_can() ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to run Web of D Form Locator scans.', 'webofd-form-locator' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * POST /scan/start.
	 *
	 * @return WP_REST_Response|WP_Error Queue size or error.
	 */
	public function start() {
		return $this->respond( $this->scanner()->start() );
	}

	/**
	 * POST /scan/batch.
	 *
	 * @param WP_REST_Request $request Request with the owner token.
	 *
	 * @return WP_REST_Response|WP_Error Progress or error.
	 *
	 * @phpstan-param WP_REST_Request<array{token: string}> $request
	 */
	public function batch( WP_REST_Request $request ) {
		return $this->respond( $this->scanner()->batch( (string) $request->get_param( 'token' ) ) );
	}

	/**
	 * POST /scan/finish.
	 *
	 * @param WP_REST_Request $request Request with the owner token.
	 *
	 * @return WP_REST_Response|WP_Error Summary or error.
	 *
	 * @phpstan-param WP_REST_Request<array{token: string}> $request
	 */
	public function finish( WP_REST_Request $request ) {
		return $this->respond( $this->scanner()->finish( (string) $request->get_param( 'token' ) ) );
	}

	/**
	 * GET /scan/status.
	 *
	 * @return WP_REST_Response|WP_Error Current scan state.
	 */
	public function status() {
		return $this->respond( $this->scanner()->status() );
	}

	/**
	 * Wraps a scanner result in a REST response, passing errors through.
	 *
	 * @param array<string, mixed>|WP_Error $result Scanner result.
	 *
	 * @return WP_REST_Response|WP_Error Response.
	 */
	private function respond( $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
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
