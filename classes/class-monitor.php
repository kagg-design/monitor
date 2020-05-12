<?php
/**
 * Monitor class file.
 *
 * @package KAGG\Monitor
 */

namespace KAGG\Monitor;

use InvalidArgumentException;
use function KAGG\SimpleHTMLDOM\str_get_html;
use KAGG\SimpleHTMLDOM\simple_html_dom;
use KAGG\SimpleHTMLDOM\simple_html_dom_node;
use KAGG\Diff\Diff;

define( 'KM_LOG', 0 );
define( 'KM_INFO', 1 );
define( 'KM_WARNING', 2 );
define( 'KM_ERROR', 3 );

/**
 * Class Monitor
 */
class Monitor {

	/**
	 * Name of the file containing filters in json format
	 *
	 * @var string
	 */
	private $settings_filename = __DIR__ . '/../monitor.json';

	/**
	 * Default settings.
	 * null means that value must be specified in json file or in settings array passed to the constructor.
	 *
	 * @var array
	 */
	private $default_settings = [
		'site_url'            => null,
		'ignored_urls'        => [],
		'ignore_outer_urls'   => true,
		'menu_links_selector' => '',
		'from'                => '',
		'to'                  => '',
		'allowed_ip'          => '',
		'max_load_time'       => 1,
		'log_id'              => null,
	];

	/**
	 * Settings
	 *
	 * @var array
	 */
	private $settings = [];

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
	 * Background process.
	 *
	 * @var Background_Process
	 */
	private $background_process;

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
		$this->background_process = new Background_Process( $this );
		$this->sapi_name = php_sapi_name();
	}

	/**
	 * Run monitor.
	 *
	 * @param array $settings
	 */
	public function run( $settings = [] ) {
		$this->time_start = microtime( true );

		$this->default_settings['from'] = get_option( 'admin_email' );

		if ( 'cli' === $this->sapi_name ) {
			unset( $this->default_settings['log_id'] ); // Not used in cli. So, not require it.
		}

		$this->settings = $this->load_settings( $settings, $this->default_settings );
		$this->save_data();

		$scheme = parse_url( $this->settings['site_url'], PHP_URL_SCHEME );
		if ( null === $scheme ) {
			$this->settings['site_url'] = 'http://' . $this->settings['site_url'];
		}

		if ( 'cli' !== $this->sapi_name && ! wp_doing_ajax() ) {
			if ( $this->settings['allowed_ip'] !== $this->get_user_ip() ) {
				throw new InvalidArgumentException( 'Not allowed.' );
			}
		}

		$this->check_menu_pages();
		$this->walk_links();
	}

	/**
	 * Complete monitor.
	 */
	public function complete() {
		$this->log( 'There are ' . count( $this->links ) . ' links on site.', KM_INFO );
		$this->log( 'There are ' . count( $this->visited ) . ' visited.', KM_INFO );

		$diff = array_diff( $this->links, $this->visited );
		if ( 0 < count( $diff ) ) {
			$this->log( 'Not visited:', KM_INFO );
			foreach ( $diff as $item ) {
				$this->log( $item, KM_ERROR );
			}
		}

		do_action( 'monitor_completed', $this );

		if ( 'cli' === $this->sapi_name ) {
			array_filter( $this->links );
			sort( $this->links, SORT_NATURAL );
			$this->maybe_create_base_link_file();
			$this->diff_links();
		}

		$this->time_end = microtime( true );

		$time = $this->time_end - $this->time_start;
		$this->log( 'Time elapsed: ' . round( $time, 3 ) . ' seconds.', KM_INFO );

		$this->send_email();
	}

	public function log_id() {
		return $this->settings['log_id'];
	}

	/**
	 * Load settings
	 *
	 * @param array $settings         Settings.
	 * @param array $default_settings Default settings.
	 *
	 * @return array
	 */
	private function load_settings( $settings, $default_settings ) {
		if ( empty( $settings ) ) {
			if ( ! is_readable( $this->settings_filename ) ) {
				throw new InvalidArgumentException( 'Settings file does not exist.' );
			}

			// @phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$settings = file_get_contents( $this->settings_filename );
			// @phpcs:enable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			if ( ! $settings ) {
				throw new InvalidArgumentException( 'Settings file is empty.' );
			}

			$settings = (array) json_decode( $settings, true );
		}

		$out = [];

		foreach ( $default_settings as $name => $default_setting ) {
			if ( array_key_exists( $name, $settings ) ) {
				$out[ $name ] = $settings[ $name ];
			} else {
				$out[ $name ] = $default_setting;
			}
		}

		$settings = $out;

		foreach ( $settings as $name => $setting ) {
			if ( null === $setting ) {
				throw new InvalidArgumentException( "'{$name}' must be defined in settings." );
			}
		}

		return $settings;
	}

	/**
	 * Save object data.
	 */
	private function save_data() {
		$keys = [
			'settings',
			'email_level',
			'time_start',
			'time_end',
			'log_array',
			'links',
			'visited',
			'diffs',
		];
		$data = [];

		foreach ( $keys as $key ) {
			$data[ $key ] = $this->{$key};
		}

		set_transient(
			$this->settings['log_id'],
			$data,
			DAY_IN_SECONDS
		);
	}

	/**
	 * Get saved data.
	 *
	 * @param string $log_id Log id.
	 */
	public function get_data( $log_id ) {
		$data = get_transient( $log_id );

		foreach ( $data as $key => $value ) {
			$this->{$key} = $value;
		}
	}

	/**
	 * Log messages.
	 *
	 * @param string $message Message.
	 * @param int    $level   Error level.
	 */
	public function log( $message, $level = KM_LOG ) {
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

		if ( 'cli' === $this->sapi_name ) {
			echo $message;
		} else {
			$this->save_data();
		}
	}

	/**
	 * Send email.
	 */
	private function send_email() {
		if ( ! $this->settings['to'] ) {
			return;
		}

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
		$headers .= 'From: ' . $this->settings['from'] . "\r\n";

		wp_mail( $this->settings['to'], 'Report on ' . $this->settings['site_url'] . ' monitoring.', $message, $headers );
	}

	/**
	 * Get page html.
	 *
	 * @param string $url Url.
	 *
	 * @return bool|simple_html_dom
	 */
	public function get_html( $url ) {
		if ( ! $this->add_link( $url ) ) {
			return false;
		}

		if ( $this->is_visited( $url ) ) {
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
				'Checking "' . html_entity_decode( $title ) . '" page (' . urldecode( $url ) . '). ' . $percent . '%'
			);
			$memory_used = round( memory_get_usage( true ) / 1024 /1024, 0 ) . 'M';
			$this->log(
				'Memory used: ' . $memory_used . '. Total time spent: ' . round( $time_end - $this->time_start, 0 ) . ' sec.'
			);

			do_action( 'monitor_process_url', $this, $url );

			if ( ! $this->is_outer_url( $url ) ) {
				if ( $this->settings['max_load_time'] < $time ) {
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
		$response = wp_remote_get( $url );
		$body = wp_remote_retrieve_body( $response );
		if ( ! $body ) {
			return false;
		}

		return str_get_html( $body );
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

		if ( $this->settings['ignore_outer_urls'] && $this->is_outer_url( $url ) ) {
			return false;
		}

		foreach ( $this->settings['ignored_urls'] as $ignored_url ) {
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

		$scheme = isset( $url_arr['scheme'] ) ? $url_arr['scheme'] : parse_url( $this->settings['site_url'], PHP_URL_SCHEME );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return '';
		}

		$host = isset( $url_arr['host'] ) ? $url_arr['host'] : parse_url( $this->settings['site_url'], PHP_URL_HOST );
		$url  = $scheme . '://' . $host;

		if ( isset( $url_arr['port'] ) ) {
			$url .= ':' . $url_arr['port'];
		}

		$path = isset( $url_arr['path'] ) ? $url_arr['path'] : '';
		$path = rtrim( $path, '/\\' );

		$url = $url . $path;

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
		return parse_url( $this->settings['site_url'], PHP_URL_HOST ) !== parse_url( $url, PHP_URL_HOST );
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

		if ( ! $this->is_visited( $url ) ) {
			$this->visited[] = $url;
		}
	}

	/**
	 * Check menu pages.
	 */
	private function check_menu_pages() {
		// Load start page.
		$html = $this->get_html( $this->normalize_link( $this->settings['site_url'] ) );

		if ( ! $this->settings['menu_links_selector'] ) {
			return;
		}

		// Load pages from menu.
		$this->log( 'Checking pages in menu...' );
		$menu_items = $html->find( $this->settings['menu_links_selector'] );
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
			if ( $this->is_visited( $url ) ) {
				continue;
			}

			if ( 'cli' === $this->sapi_name ) {
				$this->get_html( $url );
			} else {
				$this->background_process->push_to_queue( $url );
			}
		}

		if ( 'cli' !== $this->sapi_name ) {
			$this->background_process->save()->dispatch();
		}
	}

	/**
	 * Check if url is already visited.
	 *
	 * @param string $url Url.
	 *
	 * @return bool
	 */
	private function is_visited( $url ) {
		return in_array( $url, $this->visited, true );
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
