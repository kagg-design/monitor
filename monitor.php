<?php
/**
 * Monitor main file.
 *
 * @package KAGG\Monitor
 */

use KAGG\Monitor\Monitor;

$loader = require __DIR__ . '/vendor/autoload.php';
$loader->loadClass( Monitor::class );

// Load WP.
require '../wp-load.php';

// Run monitor.
$monitor = new Monitor();
$monitor->run();
$monitor->complete();
