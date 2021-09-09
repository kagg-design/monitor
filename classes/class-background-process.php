<?php
/**
 * Background process
 *
 * @package KAGG\Monitor
 */

namespace KAGG\Monitor;

use KAGG\WP_Background_Processing\WP_Background_Process;
use stdClass;

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

		add_filter( $this->identifier . '_cron_interval', [ $this, 'cron_interval' ] );
	}

	/**
	 * Set cron interval.
	 *
	 * @param int $interval Cron interval.
	 *
	 * @return int
	 */
	public function cron_interval( $interval ) {

		// Set cron restart interval to 1 min.
		return 1;
	}

	/**
	 * Get data key from process key.
	 *
	 * @return string
	 */
	public function data_key() {
		return preg_replace( '/^(.+)_batch_(?:.+)_(.+)$/', '$1_data_$2', $this->key );
	}

	/**
	 * Save queue
	 *
	 * @return \WP_Background_Process
	 */
	public function save() {
		$save = parent::save();

		// Key will be generated and stored on parent::save.
		$this->monitor->save_data();

		return $save;
	}

	/**
	 * Update queue
	 *
	 * @param string $key  Key.
	 * @param array  $data Data.
	 *
	 * @return \WP_Background_Process
	 */
	public function update( $key, $data ) {
		$update = parent::update( $key, $data );

		$this->key = $key;
		$this->monitor->save_data();

		return $update;
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
		$this->key = parent::generate_key( $length ) . '_' . substr( $this->monitor->log_id(), 0, 31 );

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

		$this->monitor->save_data();
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
	 * @return \WP_Background_Process
	 */
	public function delete( $key ) {
		$delete = parent::delete( $key );

		$this->key = $key;

		return $delete;
	}
}
