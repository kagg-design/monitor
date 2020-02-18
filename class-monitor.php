<?php
/**
 * Monitor class file.
 *
 * @package kagg-monitor
 */

define( 'KM_LOG', 0 );
define( 'KM_INFO', 1 );
define( 'KM_WARNING', 2 );
define( 'KM_ERROR', 3 );

/**
 * Class Monitor
 */
class Monitor {

	/**
	 * Site url.
	 *
	 * @var string
	 */
	private $site_url = 'http://ecosmetics.test';

	/**
	 * Ignore these urls.
	 *
	 * @var array
	 */
	private $ignored_urls = [
		'?add-to-cart',
	];

	/**
	 * Ignore urls outside the site.
	 *
	 * @var bool
	 */
	private $ignore_outer_urls = true;

	/**
	 * Main menu selector.
	 *
	 * @var string
	 */
	private $menu_links_selector = '#menu a';

	/**
	 * From email address.
	 *
	 * @var string
	 */
	private $from = 'info@kagg.eu';

	/**
	 * To email address.
	 *
	 * @var string
	 */
	private $to = 'info@kagg.eu';

	/**
	 * Email error level.
	 *
	 * @var int
	 */
	private $email_level = KM_INFO;

	/**
	 * Filename containing base links.
	 *
	 * @var string
	 */
	private $base_link_file_name = __DIR__ . '/base_links.txt';

	/**
	 * Allowed IP to run not from CLI (console).
	 *
	 * @var string
	 */
	private $allowed_ip = '87.110.237.209';

	/**
	 * Maximum allowed page loading time.
	 *
	 * @var int
	 */
	private $max_load_time = 2;

	/**
	 * Start time.
	 *
	 * @var mixed
	 */
	private $time_start;

	/**
	 * End time.
	 *
	 * @var mixed
	 */
	private $time_end;

	/**
	 * SAPI name.
	 *
	 * @var string
	 */
	private $sapi_name;

	/**
	 * Log entries.
	 *
	 * @var array
	 */
	private $log_array = [];

	/**
	 * Collected links.
	 *
	 * @var array
	 */
	private $links = [];

	/**
	 * Visited links.
	 *
	 * @var array
	 */
	private $visited = [];

	/**
	 * Differences in links.
	 *
	 * @var array
	 */
	private $diffs = [];

	/**
	 * Monitor constructor.
	 */
	public function __construct() {
		$this->time_start = microtime( true );

		$this->sapi_name = php_sapi_name();

		if ( 'cli' !== php_sapi_name() ) {
			if ( $this->allowed_ip !== $this->get_user_ip() ) {
				$this->log( 'Not allowed.', KM_ERROR );
				die();
			}
		}

		$this->log( 'Starting checks.' );

		$this->check_menu_pages();
		$this->walk_links();

		$this->log( 'There are ' . count( $this->links ) . ' links on site.', KM_INFO );
		$this->log( 'There are ' . count( $this->visited ) . ' visited.', KM_INFO );

		$diff = array_diff( $this->links, $this->visited );
		if ( 0 < count( $diff ) ) {
			$this->log( 'Not visited:', KM_INFO );
			foreach ( $diff as $item ) {
				$this->log( $item, KM_ERROR );
			}
		}

		array_filter( $this->links );
		sort( $this->links, SORT_NATURAL );
		$this->maybe_create_base_link_file();
		$this->diff_links();
	}

	/**
	 * Monitor destructor.
	 */
	public function __destruct() {
		$this->time_end = microtime( true );

		$time = $this->time_end - $this->time_start;
		$this->log( 'Time elapsed: ' . round( $time, 3 ) . ' seconds.', KM_INFO );
		$this->log( 'Finished.' );

		$this->send_email();
	}

	/**
	 * Log messages.
	 *
	 * @param string $message Message.
	 * @param int    $level   Error level.
	 */
	private function log( $message, $level = KM_LOG ) {
		if ( KM_ERROR === $level ) {
			$message = '*** ' . $message . ' ***';
		}
		$message .= "\n";
		if ( 'cli' !== $this->sapi_name ) {
			$message = nl2br( $message );
		}

		$log_record = [
			'level'   => $level,
			'message' => $message,
		];

		$this->log_array[] = $log_record;

		echo $message;
	}

	/**
	 * Send email.
	 */
	private function send_email() {
		ob_start();
		?>
		<html>
		<head>
			<style>
				.diff {
					width: 100%;
				}

				.diff td {
					width: 50%;
					vertical-align: top;
					white-space: pre;
					white-space: pre-wrap;
				}

				.diff a {
					text-decoration: none;
				}

				.diffUnmodified {
					display: none;
				}

				.diffDeleted span {
					border: 1px solid #ffc0c0;
					background: #ffe0e0;
				}

				.diffInserted span {
					border: 1px solid #c0ffc0;
					background: #e0ffe0;
				}
			</style>
		</head>
		<body>
		<?php
		$message = ob_get_clean();

		$count = count( $this->log_array );
		foreach ( $this->log_array as $key => $log_record ) {
			if ( $log_record['level'] >= $this->email_level ) {
				$message .= $log_record['message'];
				if ( $key < $count - 1 ) {
					$message .= '<br>';
				}
			}
		}

		if ( ! empty( $this->diffs ) ) {
			$message .= '<p><strong>Differences with base links file:</strong></p>';
			$message .= '<table class="diff"><tbody><tr>';
			$message .= '<td><strong>Removed links</strong></td>';
			$message .= '<td><strong>Added links</strong></td>';
			$message .= '</tr></tbody></table>';
			$message .= Diff::toTable( $this->diffs );
		}

		$message .= '</body></html>';

		$headers = 'MIME-Version: 1.0' . "\r\n";

		$headers .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
		$headers .= 'From: ' . $this->from . "\r\n";

		mail( $this->to, 'Report on ' . $this->site_url . ' monitoring.', $message, $headers );
	}

	/**
	 * Get page html.
	 *
	 * @param string $url Url.
	 *
	 * @return bool|simple_html_dom
	 */
	private function get_html( $url ) {
		if ( ! $this->add_link( $url ) ) {
			return false;
		}

		if ( in_array( $url, $this->visited, true ) ) {
			return false;
		}

		$time_start = microtime( true );

		$html = $this->file_get_html( $url );

		$time_end = microtime( true );
		$time     = $time_end - $time_start;

		if ( $html ) {
			$percent       = 0;
			$visited_count = count( $this->visited );
			if ( $visited_count ) {
				$percent = intval( ( $visited_count + 1 ) / count( $this->links ) * 100 );
			}
			$title_node = $html->find( 'title', 0 );
			/**
			 * DOM html node.
			 *
			 * @var simple_html_dom_node $title_node
			 */
			$title = $title_node ? $title_node->plaintext : '';
			$this->log(
				'Checking "' . html_entity_decode( $title ) . '" page (' . urldecode( $url ) . '). ' . $percent . '%.'
			);

			if ( 0 === strpos( $url, $this->site_url ) ) {
				if ( $this->max_load_time < $time ) {
					$this->log( 'Slow loading of ' . urldecode( $url ) . ' page. ' . round( $time, 3 ) . ' seconds.', KM_WARNING );
				}

				$a_items = $html->find( 'a' );
				foreach ( $a_items as $a_item ) {
					/**
					 * DOM html node.
					 *
					 * @var simple_html_dom_node $a_item
					 */
					$this->add_link( $a_item->href );
				}
			}

			$this->add_visited( $url );
		} else {
			$this->log( 'Cannot load "' . urldecode( $url ) . '" page.', KM_ERROR );
		}

		return $html;
	}

	/**
	 * Get file html.
	 *
	 * @param string $url Url.
	 *
	 * @return bool|simple_html_dom
	 */
	private function file_get_html( $url ) {
		// Offset must be 0 with php 7.2.
		return file_get_html( $url, false, null, 0 );
	}

	/**
	 * Add link.
	 *
	 * @param string $url Url.
	 *
	 * @return bool
	 */
	private function add_link( $url ) {
		if ( ! $url ) {
			return false;
		}

		$url = $this->normalize_link( $url );

		if ( $this->ignore_outer_urls && $this->is_outer_url( $url ) ) {
			return false;
		}

		foreach ( $this->ignored_urls as $ignored_url ) {
			if ( false !== strpos( $url, $ignored_url ) ) {
				return false;
			}
		}

		if ( ! in_array( $url, $this->links, true ) ) {
			$this->links[] = $url;
		}

		return true;
	}

	/**
	 * Normalize link.
	 *
	 * @param string $url Url.
	 *
	 * @return string
	 */
	private function normalize_link( $url ) {
		if ( false !== strpos( $url, '@' ) ) {
			return $url;
		}

		$url_arr = parse_url( $url );

		$scheme = isset( $url_arr['scheme'] ) ? $url_arr['scheme'] : parse_url( $this->site_url, PHP_URL_SCHEME );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return '';
		}

		$host = isset( $url_arr['host'] ) ? $url_arr['host'] : parse_url( $this->site_url, PHP_URL_HOST );
		$url  = $scheme . '://' . $host;

		if ( isset( $url_arr['port'] ) ) {
			$url .= ':' . $url_arr['port'];
		}

		$path = isset( $url_arr['path'] ) ? $url_arr['path'] : '';
		$url  = $url . $path;

		if ( isset( $url_arr['query'] ) ) {
			$url .= '?' . $url_arr['query'];
		}

		return urldecode( $url );
	}

	/**
	 * Check if url is outside of the site.
	 *
	 * @param string $url Url.
	 *
	 * @return bool
	 */
	private function is_outer_url( $url ) {
		return parse_url( $this->site_url, PHP_URL_HOST ) !== parse_url( $url, PHP_URL_HOST );
	}

	/**
	 * Add visited link.
	 *
	 * @param string $url Url.
	 */
	private function add_visited( $url ) {
		if ( ! $url ) {
			return;
		}

		if ( ! in_array( $url, $this->visited, true ) ) {
			$this->visited[] = $url;
		}
	}

	/**
	 * Check menu pages.
	 */
	private function check_menu_pages() {
		// Load start page.
		$html = $this->get_html( $this->site_url );

		// Load pages from menu.
		$this->log( 'Checking pages in menu...' );
		$menu_items = $html->find( $this->menu_links_selector );
		$this->log( 'Found ' . count( $menu_items ) . ' links in menu.' );
		foreach ( $menu_items as $menu_item ) {
			/**
			 * DOM html node.
			 *
			 * @var simple_html_dom_node $menu_item
			 */
			$this->add_link( $menu_item->href );
		}
	}

	/**
	 * Walk all links.
	 */
	private function walk_links() {
		$this->log( 'Walking on links...' );
		$this->log( 'Found ' . count( $this->links ) . ' links in total.' );
		foreach ( $this->links as &$url ) {
			if ( ! in_array( $url, $this->visited, true ) ) {
				$this->get_html( $url );
			}
		}
	}

	/**
	 * Maybe create base link file.
	 */
	private function maybe_create_base_link_file() {
		if ( ! file_exists( $this->base_link_file_name ) ) {
			foreach ( $this->links as $link ) {
				file_put_contents( $this->base_link_file_name, $link . "\n", FILE_APPEND );
			}
		}
	}

	/**
	 * Get link differences.
	 */
	private function diff_links() {
		if ( ! file_exists( $this->base_link_file_name ) ) {
			$this->diffs = [];

			return;
		}

		$links = implode( "\n", $this->links );

		$base_links = file_get_contents( $this->base_link_file_name );
		$base_links = array_filter( explode( "\n", $base_links ) );
		sort( $base_links, SORT_NATURAL );
		$base_links = implode( "\n", $base_links );

		$this->diffs = Diff::compare( $base_links, $links );

		$new_diffs = [];
		foreach ( $this->diffs as $key => $diff ) {
			if ( Diff::UNMODIFIED !== $diff[1] && '' !== $diff[0] ) {
				$new_diffs[] = $this->diffs[ $key ];
			}
		}
		$this->diffs = $new_diffs;

		if ( ! empty( $this->diffs ) ) {
			echo Diff::toString( $this->diffs );
		}
	}

	/**
	 * Get user IP.
	 */
	private function get_user_ip() {
		$user_ip = $_SERVER['REMOTE_ADDR'];

		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$user_ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
		} elseif ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
			$user_ip = $_SERVER['HTTP_CLIENT_IP'];
		} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$user_ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		}

		return $user_ip;
	}
}
