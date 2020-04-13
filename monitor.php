<?php
/**
 * Monitor main file.
 *
 * @package kagg-monitor
 */

// Load WP.
require_once '../wp-load.php';

// Load monitor classes.
require_once 'monitor-load.php';

// Run monitor.
new Monitor();
