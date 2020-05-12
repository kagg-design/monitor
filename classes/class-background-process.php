<?php
/**
 * Background process
 *
 * @package KAGG\Monitor
 */

namespace KAGG\Monitor;

use WP_Background_Process;

/**
 * Class Background_Process
 */
class Background_Process extends WP_Background_Process {

	/**
	 * Prefix
	 *
	 * @var string
	 */
	protected $prefix = 'KAGG_MONITOR_PREFIX';

	/**
	 * Process action name
	 *
	 * @var string
	 */
	protected $action = 'KAGG_MONITOR_ACTION';

	/**
	 * Monitor main class
	 *
	 * @var Monitor
	 */
	protected $monitor;

	/**
	 * Conversion_Process constructor
	 *
	 * @param Monitor $monitor Monitor main class.
	 */
	public function __construct( $monitor ) {
		$this->monitor = $monitor;

		parent::__construct();
	}

	/**
	 * Task. Processes single url.
	 *
	 * @param string $url Queue item to iterate over.
	 *
	 * @return bool
	 */
	protected function task( $url ) {
		$log_id = $_POST[ 'log_id' ];

		$this->monitor->get_data( $log_id );

		$this->monitor->get_html( $url );

		return false;
	}

	/**
	 * Get query args
	 *
	 * @return array
	 */
	protected function get_query_args() {
		$query_args = parent::get_query_args();

		$query_args['log_id'] = $this->monitor->log_id();

		return $query_args;
	}

	/**
	 * Complete
	 */
	protected function complete() {
		parent::complete();

		$this->monitor->complete();
	}

	// phpcs:disable Generic.CodeAnalysis.UselessOverridingMethod.Found
	/**
	 * Is process running
	 *
	 * Check whether the current process is already running
	 * in a background process.
	 */
	public function is_process_running() {
		return parent::is_process_running();
	}
	// phpcs:enable Generic.CodeAnalysis.UselessOverridingMethod.Found
}
