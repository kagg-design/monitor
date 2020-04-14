<?php
/**
 * Monitor load file.
 *
 * @package kagg-monitor
 */

// Require files and run monitor.
if ( ! class_exists( '\KAGG\SimpleHTMLDOM\simple_html_dom_node' ) ) {
	require_once 'simple_html_dom.php';
}

if ( ! class_exists( '\KAGG\Diff\Diff' ) ) {
	require_once 'class.Diff.php';
}

require_once 'class-monitor.php';
