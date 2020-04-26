<?php
/**
 * Monitor main file.
 *
 * @package kagg-monitor
 */

use KAGG\Monitor\Monitor;

// Load WP.
require_once '../wp-load.php';

require_once '';

// Run monitor.
new Monitor();
