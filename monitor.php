<?php
/**
 * Monitor main file.
 *
 * @package KAGG\Monitor
 */

use KAGG\Monitor\Monitor;

// Load WP.
require_once '../wp-load.php';

require_once __DIR__ . '/vendor/autoload.php';

// Run monitor.
new Monitor();
