/**
 * Web of D Form Locator: runs a batched scan from the Tools page.
 *
 * Start → batch until done → finish, updating the progress bar, then reload
 * to show the results.
 *
 * The scan's owner token is kept in sessionStorage, which survives a reload
 * of the same tab but isn't shared with other tabs. Reloading the scanning
 * tab resumes the scan; any other tab only watches progress, so two tabs can
 * never process the same queue.
 */
( function ( wp, settings ) {
	'use strict';

	if ( ! wp || ! wp.apiFetch || ! wp.i18n || ! settings ) {
		return;
	}

	const { __, sprintf } = wp.i18n;

	const button = document.getElementById( 'gffl-scan-button' );
	const progress = document.getElementById( 'gffl-scan-progress' );
	const progressText = document.getElementById( 'gffl-scan-progress-text' );
	const message = document.getElementById( 'gffl-scan-message' );
	const TOKEN_KEY = 'gfflScanToken';
	const WATCH_INTERVAL = 2000;

	if ( ! button || ! progress || ! progressText || ! message ) {
		return;
	}

	/**
	 * Calls one scan endpoint.
	 *
	 * @param {string} action Endpoint name: start, batch, finish or status.
	 * @param {string} method HTTP method.
	 * @param {Object} data   Request body.
	 * @return {Promise<Object>} Response body.
	 */
	function request( action, method = 'POST', data = undefined ) {
		return wp.apiFetch( { path: settings.restPath + '/' + action, method, data } );
	}

	/**
	 * Reads this tab's scan token.
	 *
	 * @return {string} Token, or '' if none or storage is unavailable.
	 */
	function loadToken() {
		try {
			return window.sessionStorage.getItem( TOKEN_KEY ) || '';
		} catch ( error ) {
			return '';
		}
	}

	/**
	 * Stores or clears this tab's scan token.
	 *
	 * @param {string} token Token, or '' to clear.
	 */
	function saveToken( token ) {
		try {
			if ( token ) {
				window.sessionStorage.setItem( TOKEN_KEY, token );
			} else {
				window.sessionStorage.removeItem( TOKEN_KEY );
			}
		} catch ( error ) {
			// Storage blocked: the scan still runs, it just can't resume after a reload.
		}
	}

	/**
	 * Switches the button between idle and busy.
	 *
	 * @param {boolean} busy  Whether a scan is running.
	 * @param {string}  label Busy label; defaults to "Scanning…".
	 */
	function setBusy( busy, label = '' ) {
		button.disabled = busy;
		button.textContent = busy ? label || __( 'Scanning…', 'webofd-form-locator' ) : __( 'Scan now', 'webofd-form-locator' );
	}

	/**
	 * Shows scan progress.
	 *
	 * @param {{processed: number, total: number}} state Progress.
	 */
	function renderProgress( state ) {
		progress.hidden = false;
		progress.max = Math.max( 1, state.total );
		progress.value = state.processed;
		progressText.textContent = sprintf(
			/* translators: 1: posts scanned so far, 2: total posts to scan. */
			__( '%1$d / %2$d posts scanned', 'webofd-form-locator' ),
			state.processed,
			state.total
		);
	}

	/**
	 * Shows an error as an inline notice.
	 *
	 * @param {Object} error apiFetch error ({code, message}) or Error.
	 */
	function showError( error ) {
		const paragraph = document.createElement( 'p' );

		paragraph.textContent = ( error && error.message ) || __( 'The scan failed. Please try again.', 'webofd-form-locator' );
		message.className = 'gffl-scan-message notice notice-error inline';
		message.replaceChildren( paragraph );
	}

	/**
	 * Clears any previous error.
	 */
	function clearMessage() {
		message.className = 'gffl-scan-message';
		message.replaceChildren();
	}

	/**
	 * Requests batches until the server reports the queue is done.
	 *
	 * @param {{processed: number, total: number, done: boolean}} state Current progress.
	 * @param {string}                                             token Owner token.
	 * @return {Promise<void>}
	 */
	async function runBatches( state, token ) {
		let current = state;

		while ( ! current.done ) {
			current = await request( 'batch', 'POST', { token } );
			renderProgress( current );
		}
	}

	/**
	 * Finishes the scan and reloads the page to show results.
	 *
	 * @param {string} token Owner token.
	 * @return {Promise<void>}
	 */
	async function finish( token ) {
		await request( 'finish', 'POST', { token } );
		saveToken( '' );
		progressText.textContent = __( 'Scan complete. Loading results…', 'webofd-form-locator' );
		window.location.reload();
	}

	/**
	 * Runs batches then finishes, reporting any error. If another tab owns
	 * the scan, switches to watching it instead.
	 *
	 * @param {{processed: number, total: number, done: boolean}} state Starting progress.
	 * @param {string}                                             token Owner token.
	 * @return {Promise<void>}
	 */
	async function complete( state, token ) {
		try {
			renderProgress( state );
			await runBatches( state, token );
			await finish( token );
		} catch ( error ) {
			if ( error && 'gffl_scan_not_owner' === error.code ) {
				saveToken( '' );
				watch();
				return;
			}

			showError( error );
			setBusy( false );
		}
	}

	/**
	 * Follows a scan owned by another tab: polls status, reloads when it ends.
	 */
	function watch() {
		setBusy( true, __( 'Scan running in another window…', 'webofd-form-locator' ) );

		const poll = async () => {
			let status;

			try {
				status = await request( 'status', 'GET' );
			} catch ( error ) {
				window.setTimeout( poll, WATCH_INTERVAL );
				return;
			}

			if ( 'running' !== status.state ) {
				window.location.reload();
				return;
			}

			renderProgress( status );
			window.setTimeout( poll, WATCH_INTERVAL );
		};

		poll();
	}

	/**
	 * Starts a new scan from the button.
	 *
	 * @return {Promise<void>}
	 */
	async function startScan() {
		setBusy( true );
		clearMessage();

		let started;

		try {
			started = await request( 'start' );
		} catch ( error ) {
			showError( error );
			setBusy( false );
			return;
		}

		saveToken( started.token );
		await complete( { processed: 0, total: started.total, done: 0 === started.total }, started.token );
	}

	/**
	 * On page load: resume this tab's own scan, or watch another tab's.
	 *
	 * @return {Promise<void>}
	 */
	async function resumeIfRunning() {
		let status;

		try {
			status = await request( 'status', 'GET' );
		} catch ( error ) {
			return;
		}

		if ( 'running' !== status.state ) {
			saveToken( '' );
			return;
		}

		const token = loadToken();

		if ( ! token ) {
			watch();
			return;
		}

		setBusy( true );
		await complete( { processed: status.processed, total: status.total, done: status.processed >= status.total }, token );
	}

	button.addEventListener( 'click', startScan );
	resumeIfRunning();
}( window.wp, window.gfflScan ) );
