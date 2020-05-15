<?php
/**
 * Background process
 *
 * @package KAGG\Monitor
 */

namespace KAGG\Monitor;

use stdClass;
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
	protected $prefix = 'kagg_monitor';

	/**
	 * Monitor main class
	 *
	 * @var Monitor
	 */
	private $monitor;

	/**
	 * Key of the current process.
	 *
	 * @var string
	 */
	private $key = '';

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
	 * Get data key from process key.
	 *
	 * @return string
	 */
	public function data_key() {
		return str_replace( '_batch_', '_data_', $this->key );
	}

	/**
	 * Generate key
	 *
	 * Generates a unique key based on microtime. Queue items are
	 * given a unique key so that they can be merged upon save.
	 *
	 * @param int $length Length.
	 *
	 * @return string
	 */
	protected function generate_key( $length = 64 ) {
		$this->key = parent::generate_key();

		$this->monitor->save_data();

		return $this->key;
	}

	/**
	 * Get batch
	 *
	 * @return stdClass Return the first batch from the queue
	 */
	protected function get_batch() {
		$batch = parent::get_batch();

		$this->key = $batch->key;

		return $batch;
	}

	/**
	 * Task. Processes single url.
	 *
	 * @param string $url Queue item to iterate over.
	 *
	 * @return bool
	 */
	protected function task( $url ) {
		$this->monitor->get_data();
		$this->monitor->get_html( $url );
		$this->monitor->save_data();

		return false;
	}

	/**
	 * Complete
	 */
	protected function complete() {
		parent::complete();

		$this->monitor->complete();
		$this->monitor->save_data();
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

	/**
	 * Delete queue
	 *
	 * @param string $key Key.
	 *
	 * @return $this
	 */
	public function delete( $key ) {
		parent::delete( $key );
		delete_site_option( $this->data_key() );

		return $this;
	}
}
