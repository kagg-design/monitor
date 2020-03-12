<?php
/**
 * Monitor class file.
 *
 * @package kagg-monitor
 */

/**
 * Class Monitor
 */
class Monitor {

	/**
	 * Log level log.
	 */
	const KM_LOG = 0;

	/**
	 * Log level info.
	 */
	const KM_INFO = 1;

	/**
	 * Log level warning.
	 */
	const KM_WARNING = 2;

	/**
	 * Log level error.
	 */
	const KM_ERROR = 3;

	/**
	 * Maximum allowed page loading time in seconds.
	 */
	const MAX_LOAD_TIME = 1;

	/**
	 * Debug mode.
	 */
	const KM_DEBUG = false;

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
	 * Site url.
	 *
	 * @var string
	 */
	private $site_url = 'https://swsgroup.ru';

	/**
	 * Log entries.
	 *
	 * @var array
	 */
	private $log_array = [];

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
	private $to = 'likhoman.maria@yandex.ru, smirnov@swsgroup.ru, maksim@gmgr.ru, lav.ru@inbox.ru, yury.ch59@mail.ru, info@kagg.eu';

	/**
	 * To email address in debug mode.
	 *
	 * @var string
	 */
	private $to_debug = 'info@kagg.eu';

	/**
	 * Email error level.
	 *
	 * @var int
	 */
	private $email_level = self::KM_INFO;

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
	 * Filename containing base links.
	 *
	 * @var string
	 */
	private $base_link_file_name = __DIR__ . '/base_links.txt';

	/**
	 * File containing base links exists.
	 *
	 * @var bool
	 */
	private $base_link_file_exists = false;

	/**
	 * Required http headers.
	 *
	 * @var array
	 */
	private $required_headers = [
		'X-Cache-File-Exists: exists',
	];

	/**
	 * Required elements on relevant pages.
	 *
	 * @var array
	 */
	private $required_elements = [
		'about_company' => '#sbi_load',
	];

	/**
	 * Monitor constructor.
	 */
	public function __construct() {
		$this->time_start = microtime( true );

		$this->sapi_name = php_sapi_name();

		if ( 'cli' !== php_sapi_name() ) {
			if ( '87.110.237.209' !== $this->get_user_ip() ) {
				$this->log( 'Not allowed.', self::KM_ERROR );
				die();
			}
		}

		$this->log( 'Starting checks.' );

		$this->walk_links();

		$this->log( '', self::KM_INFO );
		$this->log( 'There are ' . count( $this->links ) . ' links on site.', self::KM_INFO );
		$this->log( 'There are ' . count( $this->visited ) . ' visited.', self::KM_INFO );

		$diff = array_diff( $this->links, $this->visited );
		if ( 0 < count( $diff ) ) {
			$this->log( 'Not visited:', self::KM_INFO );
			foreach ( $diff as $item ) {
				$this->log( $item, self::KM_ERROR );
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
		$this->log( 'Time elapsed: ' . round( $time, 3 ) . ' seconds.', self::KM_INFO );
		$this->log( 'Finished.' );

		$this->send_email();
	}

	/**
	 * Log messages.
	 *
	 * @param string $message Message.
	 * @param int    $level   Error level.
	 */
	private function log( $message, $level = self::KM_LOG ) {
		if ( self::KM_ERROR === $level ) {
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

		if ( self::KM_DEBUG ) {
			$this->to = $this->to_debug;
		}

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
		$url = $this->normalize_link( $url );

		$this->add_link( $url );

		$time_start = microtime( true );

		// Offset must be 0 with php 7.2.
		$contents = file_get_contents( $url, false, null, 0 );

		$time_end = microtime( true );
		$time     = $time_end - $time_start;

		$html = str_get_html( $contents );

		if ( $html ) {
			$title_node = $html->find( 'title', 0 );
			/**
			 * DOM html node.
			 *
			 * @var simple_html_dom_node $title_node
			 */
			$title = $title_node ? $title_node->plaintext : '';
			$this->log(
				'Checking "' . htmlspecialchars_decode( $title ) .
				'" page. (' . urldecode( $url ) . ')'
			);

			if ( 0 === strpos( $url, $this->site_url ) ) {
				$a_items = $html->find( 'a' );
				foreach ( $a_items as $a_item ) {
					/**
					 * DOM html node.
					 *
					 * @var simple_html_dom_node $a_item
					 */
					if ( isset( $a_item->rel ) && 'nofollow' === $a_item->rel ) {
						continue;
					}

					$this->add_link( $this->normalize_link( $a_item->href ) );
				}

				$this->check_headers( $url, $http_response_header );
				$this->check_required_elements( $url, $html );

				if ( self::MAX_LOAD_TIME < $time ) {
					$this->log(
						'Slow loading of ' . urldecode( $url ) . ' page. ' . round( $time, 3 ) . ' seconds.',
						self::KM_WARNING
					);
				}
			}

			$this->add_visited( $url );
		} else {
			$this->log( 'Cannot load "' . urldecode( $url ) . '" page.', self::KM_ERROR );
		}

		return $html;
	}

	/**
	 * Check http headers.
	 *
	 * @param string $url     Page url.
	 * @param array  $headers Http headers.
	 */
	private function check_headers( $url, $headers ) {
		if ( array_intersect( $this->required_headers, $headers ) !== $this->required_headers ) {
			$this->log( 'Page ' . urldecode( $url ) . ' does not contain required headers.' );
		}
	}

	/**
	 * Check required element on the page.
	 *
	 * @param string          $url  Page url.
	 * @param simple_html_dom $html HTML DOM.
	 */
	private function check_required_elements( $url, $html ) {
		foreach ( $this->required_elements as $key => $required_element ) {
			if ( ! preg_match( '|' . $key . '|', $url ) ) {
				continue;
			}

			if ( ! $html->find( $required_element ) ) {
				$this->log( 'Page ' . urldecode( $url ) . ' does not contain required element "' . esc_html( $required_element ) . '".', self::KM_ERROR );
			}
		}
	}

	/**
	 * Add link.
	 *
	 * @param string $url Url.
	 */
	private function add_link( $url ) {
		if ( ! $url ) {
			return;
		}

		if ( preg_match( '/^.*(@.+$)|(\.(jpg|png|jpeg)$)/i', $url ) ) {
			return;
		}

		if ( ! in_array( $url, $this->links, true ) ) {
			$this->links[] = $url;
		}
	}

	/**
	 * Normalize link.
	 *
	 * @param string $url Url.
	 *
	 * @return string
	 */
	private function normalize_link( $url ) {
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
		$path = rtrim( $path, '/\\' );
		$url  = $url . $path;

		if ( isset( $url_arr['query'] ) ) {
			$url .= '?' . $url_arr['query'];
		}

		return urldecode( $url );
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
	 * Walk all links.
	 */
	private function walk_links() {
		$this->site_url = rtrim( $this->site_url, '/\\' );

		// Load start page.
		$this->get_html( $this->site_url );

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
		if ( file_exists( $this->base_link_file_name ) ) {
			$this->base_link_file_exists = true;
		} else {
			foreach ( $this->links as $link ) {
				file_put_contents( $this->base_link_file_name, $link . "\n", FILE_APPEND );
			}
		}
	}

	/**
	 * Get link differences and print them in console.
	 */
	private function diff_links() {
		if ( ! $this->base_link_file_exists ) {
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

		if ( ! empty( $this->diffs ) && 'cli' === $this->sapi_name ) {
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
