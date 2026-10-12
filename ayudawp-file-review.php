<?php
/**
 * Plugin Name:       AyudaWP File Review
 * Plugin URI:        https://github.com/fernandotellado/ayudawp-file-review
 * Description:       One-off, read-only review of your WordPress files: core integrity, unknown items in the root, PHP in uploads, common malware camouflage and recent changes. Delete it when you are done.
 * Version:           1.0.0
 * Requires at least: 5.5
 * Requires PHP:      7.4
 * Author:            Fernando Tellado
 * Author URI:        https://ayudawp.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ayudawp-file-review
 *
 * Drop this single file into wp-content/mu-plugins/ and go to Tools > File review.
 * It only reads files. It never changes, moves or sends anything anywhere, except the
 * request for the official core checksums to api.wordpress.org, and the only file it can
 * delete is itself, when you press its delete button.
 *
 * @package AyudaWP_File_Review
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Loaded twice (for example from mu-plugins and from plugins): keep the first copy.
if ( defined( 'AYUDAWP_FR_VERSION' ) ) {
	return;
}

define( 'AYUDAWP_FR_VERSION', '1.0.0' );

/**
 * One-off, read-only file review.
 */
final class Ayudawp_File_Review {

	/** Option that holds the scan state and results (never autoloaded). */
	const OPTION = 'ayudawp_fr_scan';

	/** Admin page slug. */
	const PAGE = 'ayudawp-file-review';

	/** Nonce action for the AJAX scan. */
	const NONCE = 'ayudawp_fr_scan';

	/** Nonce action for the self-delete form. */
	const NONCE_DELETE = 'ayudawp_fr_delete';

	/** Files bigger than this are not read for content checks (bytes). */
	const MAX_READ = 2097152;

	/** Maximum number of findings stored. */
	const MAX_FINDINGS = 2000;

	/** Maximum number of recently changed files listed one by one. */
	const MAX_RECENT_FILES = 300;

	/**
	 * Admin page hook suffix.
	 *
	 * @var string
	 */
	private static $hook_suffix = '';

	/**
	 * Cached Spanish locale check.
	 *
	 * @var bool|null
	 */
	private static $is_spanish = null;

	/**
	 * Register hooks. Nothing runs on the front end.
	 */
	public static function init() {
		add_filter( 'gettext_ayudawp-file-review', array( __CLASS__, 'translate' ), 10, 2 );

		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_ayudawp_fr_start', array( __CLASS__, 'ajax_start' ) );
		add_action( 'wp_ajax_ayudawp_fr_step', array( __CLASS__, 'ajax_step' ) );
		add_action( 'admin_post_ayudawp_fr_delete', array( __CLASS__, 'handle_delete' ) );
	}

	/**
	 * On activation as a regular plugin, make sure that deleting it from the Plugins
	 * screen also removes the stored results, not only the delete button of the tool.
	 */
	public static function activate() {
		register_uninstall_hook( __FILE__, array( __CLASS__, 'uninstall' ) );
	}

	/**
	 * Remove the stored results when the plugin is deleted from the Plugins screen.
	 */
	public static function uninstall() {
		delete_option( self::OPTION );
	}

	/**
	 * Capability needed to use the tool. The filesystem is shared by the whole network
	 * on multisite, so a subsite administrator must not be able to read it.
	 *
	 * @return string
	 */
	private static function cap() {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	/**
	 * Add the page under Tools.
	 */
	public static function add_page() {
		self::$hook_suffix = (string) add_management_page(
			__( 'File review', 'ayudawp-file-review' ),
			__( 'File review', 'ayudawp-file-review' ),
			self::cap(),
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Load the small script and stylesheet, only on our page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue( $hook ) {
		if ( '' === self::$hook_suffix || $hook !== self::$hook_suffix ) {
			return;
		}

		wp_register_style( 'ayudawp-fr-admin', false, array(), AYUDAWP_FR_VERSION );
		wp_enqueue_style( 'ayudawp-fr-admin' );
		wp_add_inline_style( 'ayudawp-fr-admin', self::css() );

		wp_register_script( 'ayudawp-fr-admin', false, array(), AYUDAWP_FR_VERSION, true );
		wp_enqueue_script( 'ayudawp-fr-admin' );

		$config = array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			'i18n'    => array(
				'starting' => __( 'Starting the review…', 'ayudawp-file-review' ),
				'error'    => __( 'The review stopped because of an error. Reload the page and try again.', 'ayudawp-file-review' ),
				'copied'   => __( 'Report copied', 'ayudawp-file-review' ),
			),
		);

		wp_add_inline_script( 'ayudawp-fr-admin', 'var ayudawpFr = ' . wp_json_encode( $config ) . ';', 'before' );
		wp_add_inline_script( 'ayudawp-fr-admin', self::js() );
	}

	/**
	 * Page styles.
	 *
	 * @return string
	 */
	private static function css() {
		return '.ayudawp-fr-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:600;color:#fff;white-space:nowrap}'
			. '.ayudawp-fr-high{background:#b32d2e}.ayudawp-fr-medium{background:#8a6100}.ayudawp-fr-info{background:#2271b1}'
			. '.ayudawp-fr-path{font-family:monospace;overflow-wrap:anywhere}'
			. '.ayudawp-fr-findings th:nth-child(1){width:72px}.ayudawp-fr-findings th:nth-child(2){width:30%}'
			. '.ayudawp-fr-item{margin:0 0 10px}.ayudawp-fr-item:last-child{margin-bottom:0}'
			. '.ayudawp-fr-report{width:100%;min-height:260px;font-family:monospace;font-size:12px}'
			. '.ayudawp-fr-box{background:#fff;border:1px solid #c3c4c7;padding:12px 16px;margin:16px 0;max-width:1100px}'
			. '.ayudawp-fr-progress{font-weight:600;margin-left:8px}'
			. '.ayudawp-fr-table td,.ayudawp-fr-table th{vertical-align:top}'
			. '.ayudawp-fr-danger{border-left:4px solid #b32d2e}'
			. '.ayudawp-fr-missing{margin:4px 0 8px 20px;list-style:disc}';
	}

	/**
	 * Page script. It only drives the batches and the copy button: results are
	 * rendered by PHP after a reload, so no HTML is built in JavaScript.
	 *
	 * @return string
	 */
	private static function js() {
		return <<<'JS'
(function () {
	'use strict';
	var cfg = window.ayudawpFr || {};
	var btn = document.getElementById('ayudawp-fr-start');
	var progress = document.getElementById('ayudawp-fr-progress');
	var copyBtn = document.getElementById('ayudawp-fr-copy');
	var report = document.getElementById('ayudawp-fr-report');

	function setProgress(text) {
		if (progress) {
			progress.textContent = text;
		}
	}

	function fail() {
		setProgress(cfg.i18n.error);
		if (btn) {
			btn.disabled = false;
		}
	}

	function post(action, extra) {
		var body = new FormData();
		body.append('action', action);
		body.append('_ajax_nonce', cfg.nonce);
		if (extra) {
			Object.keys(extra).forEach(function (key) {
				body.append(key, extra[key]);
			});
		}
		return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (response) { return response.json(); });
	}

	function step() {
		post('ayudawp_fr_step').then(function (res) {
			if (!res || !res.success) {
				fail();
				return;
			}
			setProgress(String(res.data.message));
			if (res.data.done) {
				window.location.reload();
			} else {
				step();
			}
		}).catch(fail);
	}

	if (btn) {
		btn.addEventListener('click', function () {
			var days = document.getElementById('ayudawp-fr-days');
			btn.disabled = true;
			setProgress(cfg.i18n.starting);
			post('ayudawp_fr_start', { days: days ? days.value : '7' }).then(function (res) {
				if (!res || !res.success) {
					fail();
					return;
				}
				setProgress(String(res.data.message));
				step();
			}).catch(fail);
		});
	}

	if (copyBtn && report) {
		copyBtn.addEventListener('click', function () {
			var done = function () { copyBtn.textContent = cfg.i18n.copied; };
			var legacy = function () {
				report.focus();
				report.select();
				document.execCommand('copy');
				done();
			};
			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText(report.value).then(done, legacy);
			} else {
				legacy();
			}
		});
	}
})();
JS;
	}

	/*
	 * Scan.
	 */

	/**
	 * AJAX: start a new scan.
	 */
	public static function ajax_start() {
		check_ajax_referer( self::NONCE );

		if ( ! current_user_can( self::cap() ) ) {
			wp_send_json_error( null, 403 );
		}

		$days = isset( $_POST['days'] ) ? absint( wp_unslash( $_POST['days'] ) ) : 7;
		if ( ! in_array( $days, array( 1, 7, 30 ), true ) ) {
			$days = 7;
		}

		$state = self::new_state( $days );
		self::save_state( $state );

		wp_send_json_success(
			array(
				'done'    => false,
				'message' => __( 'Checking the root and the core checksums…', 'ayudawp-file-review' ),
			)
		);
	}

	/**
	 * AJAX: run one batch, bounded by time.
	 */
	public static function ajax_step() {
		check_ajax_referer( self::NONCE );

		if ( ! current_user_can( self::cap() ) ) {
			wp_send_json_error( null, 403 );
		}

		$state = get_option( self::OPTION );
		// A scan left half done by another copy of the tool is not resumed: it is started again.
		if ( ! is_array( $state ) || ! isset( $state['status'], $state['groups'], $state['recent_pkg'] ) || 'running' !== $state['status'] ) {
			wp_send_json_error( null, 400 );
		}

		$deadline = microtime( true ) + self::time_budget();

		while ( ! empty( $state['stack'] ) && microtime( true ) < $deadline ) {
			$frame = array_pop( $state['stack'] );
			$dir   = $frame['dir'];

			if ( ! is_dir( $dir ) || ! is_readable( $dir ) ) {
				continue;
			}

			$entries = scandir( $dir );
			if ( false === $entries ) {
				continue;
			}

			$total = count( $entries );
			for ( $i = (int) $frame['offset']; $i < $total; $i++ ) {
				if ( microtime( true ) >= $deadline ) {
					$state['stack'][] = array(
						'dir'    => $dir,
						'offset' => $i,
					);
					break 2;
				}

				$name = $entries[ $i ];
				if ( '.' === $name || '..' === $name ) {
					continue;
				}

				$path = $dir . '/' . $name;

				// Never follow links: they can point outside the install or loop.
				if ( is_link( $path ) ) {
					continue;
				}

				// No folder is skipped by name: a name on a skip list is a place to hide.
				if ( is_dir( $path ) ) {
					$state['stack'][] = array(
						'dir'    => $path,
						'offset' => 0,
					);
					++$state['dirs'];
					continue;
				}

				self::check_file( $path, $state );
			}
		}

		$done = empty( $state['stack'] );

		if ( $done ) {
			self::finish( $state );
		}

		self::save_state( $state );

		wp_send_json_success(
			array(
				'done'    => $done,
				'message' => $done
					? __( 'Done. Loading the results…', 'ayudawp-file-review' )
					: sprintf(
						/* translators: 1: number of files, 2: number of folders */
						__( 'Reviewing… %1$s files in %2$s folders so far.', 'ayudawp-file-review' ),
						number_format_i18n( $state['files'] ),
						number_format_i18n( $state['dirs'] )
					),
			)
		);
	}

	/**
	 * Seconds each batch may run, well below max_execution_time.
	 *
	 * @return float
	 */
	private static function time_budget() {
		$max    = (int) ini_get( 'max_execution_time' );
		$budget = ( $max <= 0 ) ? 10.0 : (float) min( 10, max( 2, $max / 3 ) );

		/**
		 * Seconds each batch of the review may run.
		 *
		 * @param float $budget Seconds.
		 */
		$budget = (float) apply_filters( 'ayudawp_fr_time_budget', $budget );

		return max( 0.1, $budget );
	}

	/**
	 * Build the initial state: root inventory, checksums and the folders to walk.
	 *
	 * @param int $days Window for the recent changes check.
	 * @return array
	 */
	private static function new_state( $days ) {
		$abspath = untrailingslashit( wp_normalize_path( ABSPATH ) );

		$uploads     = wp_upload_dir( null, false );
		$uploads_dir = isset( $uploads['basedir'] ) ? untrailingslashit( wp_normalize_path( $uploads['basedir'] ) ) : '';

		$state = array(
			'status'        => 'running',
			'version'       => AYUDAWP_FR_VERSION,
			'started'       => time(),
			'finished'      => 0,
			'days'          => (int) $days,
			'cutoff'        => time() - ( (int) $days * DAY_IN_SECONDS ),
			'abspath'       => $abspath,
			'uploads'       => $uploads_dir,
			'self'          => wp_normalize_path( __FILE__ ),
			'stack'         => array(
				array(
					'dir'    => $abspath,
					'offset' => 0,
				),
			),
			'files'         => 0,
			'dirs'          => 1,
			'core'          => null,
			'core_total'    => 0,
			'core_missing'  => array(),
			'groups'        => array(),
			'checksum_note' => '',
			'root'          => array(),
			'findings'      => array(),
			'truncated'     => false,
			'recent'        => array(),
			'recent_files'  => array(),
			'recent_pkg'    => array(),
			'recent_old'    => 0,
			'recent_total'  => 0,
		);

		// wp-content can live outside ABSPATH.
		$content = untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		if ( 0 !== strpos( $content . '/', $abspath . '/' ) && is_dir( $content ) ) {
			$state['stack'][] = array(
				'dir'    => $content,
				'offset' => 0,
			);
		}

		$core = self::fetch_checksums();
		if ( is_array( $core ) ) {
			$state['core']       = $core;
			$state['core_total'] = count( $core );
		} else {
			$state['checksum_note'] = 'unavailable';
		}

		$state['root'] = self::root_inventory( $abspath, is_array( $core ) ? $core : array() );

		return $state;
	}

	/**
	 * Official checksums of the installed version, limited to the root, wp-admin and wp-includes.
	 *
	 * @return array|false Relative path => md5, or false when they cannot be fetched.
	 */
	private static function fetch_checksums() {
		global $wp_version, $wp_local_package;

		if ( ! function_exists( 'get_core_checksums' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$locale    = ! empty( $wp_local_package ) ? $wp_local_package : 'en_US';
		$checksums = get_core_checksums( $wp_version, $locale );

		if ( ( ! is_array( $checksums ) || empty( $checksums ) ) && 'en_US' !== $locale ) {
			$checksums = get_core_checksums( $wp_version, 'en_US' );
		}

		if ( ! is_array( $checksums ) || empty( $checksums ) ) {
			return false;
		}

		$core = array();
		foreach ( $checksums as $file => $md5 ) {
			$file = (string) $file;
			if ( 0 === strpos( $file, 'wp-admin/' ) || 0 === strpos( $file, 'wp-includes/' ) || false === strpos( $file, '/' ) ) {
				$core[ $file ] = (string) $md5;
			}
		}

		return $core;
	}

	/**
	 * List what sits in the root and is not part of WordPress.
	 *
	 * @param string $abspath Normalized ABSPATH without trailing slash.
	 * @param array  $core    Core checksums (relative path => md5).
	 * @return array
	 */
	private static function root_inventory( $abspath, $core ) {
		$known = array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-config.php', '.htaccess', 'robots.txt', 'favicon.ico', '.well-known', 'cgi-bin', '.user.ini', 'php.ini', 'error_log' );

		foreach ( array_keys( $core ) as $file ) {
			if ( false === strpos( $file, '/' ) ) {
				$known[] = $file;
			}
		}

		if ( empty( $core ) ) {
			$known = array_merge(
				$known,
				array( 'index.php', 'license.txt', 'readme.html', 'wp-activate.php', 'wp-blog-header.php', 'wp-comments-post.php', 'wp-config-sample.php', 'wp-cron.php', 'wp-links-opml.php', 'wp-load.php', 'wp-login.php', 'wp-mail.php', 'wp-settings.php', 'wp-signup.php', 'wp-trackback.php', 'xmlrpc.php' )
			);
		}

		$items   = array();
		$entries = is_readable( $abspath ) ? scandir( $abspath ) : false;

		if ( false === $entries ) {
			return $items;
		}

		foreach ( $entries as $name ) {
			if ( '.' === $name || '..' === $name || in_array( $name, $known, true ) ) {
				continue;
			}

			$path = $abspath . '/' . $name;

			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$count   = self::count_php( $path );
				$items[] = array(
					'name'  => $name,
					'type'  => 'dir',
					'php'   => $count['php'],
					'more'  => $count['more'],
					'mtime' => (int) filemtime( $path ),
				);
			} else {
				$items[] = array(
					'name'  => $name,
					'type'  => self::is_php_name( $name ) ? 'php' : 'file',
					'php'   => 0,
					'more'  => false,
					'mtime' => (int) filemtime( $path ),
				);
			}
		}

		return $items;
	}

	/**
	 * Count PHP files inside a folder, with a hard limit so a big folder cannot stall the start.
	 *
	 * @param string $dir Folder.
	 * @return array{php:int,more:bool}
	 */
	private static function count_php( $dir ) {
		$php     = 0;
		$visited = 0;
		$stack   = array( $dir );

		while ( ! empty( $stack ) ) {
			$current = array_pop( $stack );
			$entries = is_readable( $current ) ? scandir( $current ) : false;
			if ( false === $entries ) {
				continue;
			}
			foreach ( $entries as $name ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				if ( ++$visited > 5000 ) {
					return array(
						'php'  => $php,
						'more' => true,
					);
				}
				$path = $current . '/' . $name;
				if ( is_link( $path ) ) {
					continue;
				}
				if ( is_dir( $path ) ) {
					$stack[] = $path;
				} elseif ( self::is_php_name( $name ) ) {
					++$php;
				}
			}
		}

		return array(
			'php'  => $php,
			'more' => false,
		);
	}

	/**
	 * Whether a file name has a PHP-executable extension.
	 *
	 * @param string $name File name.
	 * @return bool
	 */
	private static function is_php_name( $name ) {
		$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		return in_array( $ext, array( 'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar', 'pht', 'phps' ), true );
	}

	/**
	 * Run every check that applies to one file.
	 *
	 * @param string $path  Absolute path.
	 * @param array  $state Scan state (by reference).
	 */
	private static function check_file( $path, &$state ) {
		$path = wp_normalize_path( $path );

		if ( $path === $state['self'] ) {
			return;
		}

		++$state['files'];

		$rel     = self::rel( $path, $state['abspath'] );
		$name    = basename( $path );
		$is_php  = self::is_php_name( $name );
		$is_ini  = ( 'php.ini' === $name || '.user.ini' === $name );
		$is_htac = ( '.htaccess' === $name || 'htaccess' === $name );
		$core_ok = false;

		// 1. Core integrity: root files, wp-admin and wp-includes.
		$in_core_area = ( 0 === strpos( $rel, 'wp-admin/' ) || 0 === strpos( $rel, 'wp-includes/' ) || false === strpos( $rel, '/' ) );

		if ( is_array( $state['core'] ) && $in_core_area ) {
			if ( isset( $state['core'][ $rel ] ) ) {
				if ( md5_file( $path ) === $state['core'][ $rel ] ) {
					$core_ok = true;
				} else {
					self::add( $state, 'high', 'core_modified', $rel, $path );
				}
				unset( $state['core'][ $rel ] );
			} elseif ( false !== strpos( $rel, '/' ) ) {
				if ( $is_php ) {
					self::add( $state, 'high', 'core_extra_php', $rel, $path );
				} elseif ( $is_ini || $is_htac ) {
					self::add( $state, 'medium', 'core_extra', $rel, $path );
				} else {
					self::add( $state, 'info', 'core_extra', $rel, $path );
				}
			}
		}

		// 2. PHP inside uploads.
		if ( $is_php && '' !== $state['uploads'] && 0 === strpos( $path, $state['uploads'] . '/' ) ) {
			if ( 'index.php' === $name && is_readable( $path ) && filesize( $path ) < 1024 && self::is_silent_php( self::read( $path ) ) ) {
				self::add( $state, 'info', 'uploads_index', $rel, $path );
			} elseif ( self::is_template_cache( $path ) ) {
				self::add_group( $state, 'template_cache', $path );
			} else {
				self::add( $state, 'high', 'uploads_php', $rel, $path );
			}
		}

		// Copies of wp-config.php that the server does not run and may hand out as plain text.
		if ( ! $is_php && preg_match( '/^\.?wp-config/i', $name ) ) {
			self::add( $state, 'medium', 'config_copy', $rel, $path );
		}

		// 3. Content checks. Core files that match their checksum are already known to be clean.
		if ( ! $core_ok && ( $is_php || $is_ini || $is_htac ) ) {
			$size = (int) filesize( $path );
			if ( $size > self::MAX_READ ) {
				if ( $is_php ) {
					self::add( $state, 'info', 'too_big', $rel, $path );
				}
			} elseif ( $size > 0 && ! is_readable( $path ) ) {
				if ( $is_php ) {
					self::add( $state, 'info', 'unreadable', $rel, $path );
				}
			} elseif ( $size > 0 ) {
				$src = self::read( $path );
				if ( '' !== $src ) {
					if ( $is_php ) {
						self::check_php_content( $src, $rel, $path, $state );
					} elseif ( $is_ini ) {
						self::check_ini_content( $src, $rel, $path, $state );
					} else {
						self::check_htaccess_content( $src, $rel, $path, $state );
					}
				}
			}
		}

		// 4. Recent changes, by ctime, which a touch() cannot fake.
		if ( $is_php && ! $core_ok ) {
			$ctime = (int) filectime( $path );
			if ( $ctime >= $state['cutoff'] ) {
				$component = self::component( $rel );
				$mtime     = (int) filemtime( $path );
				$old_date  = ( $ctime - $mtime ) > 30 * DAY_IN_SECONDS;

				++$state['recent_total'];
				if ( ! isset( $state['recent'][ $component ] ) ) {
					$state['recent'][ $component ] = 0;
				}
				++$state['recent'][ $component ];

				if ( $old_date ) {
					++$state['recent_old'];
				}

				$entry = array(
					'path'  => $rel,
					'ctime' => $ctime,
					'mtime' => $mtime,
					'old'   => $old_date,
				);

				// Files that belong to no plugin, theme or translation are always listed.
				$is_package = ( 'languages' === $component || 0 === strpos( $component, 'plugin:' ) || 0 === strpos( $component, 'theme:' ) );

				if ( ! $is_package ) {
					if ( count( $state['recent_files'] ) < self::MAX_RECENT_FILES ) {
						$state['recent_files'][] = $entry;
					}
				} elseif ( $old_date && count( $state['recent_pkg'] ) < self::MAX_RECENT_FILES ) {
					$state['recent_pkg'][] = $entry;
				}
			}
		}
	}

	/**
	 * Content rules for PHP files. Patterns tested against a real compromise and against
	 * WordPress core and seven popular plugins.
	 *
	 * @param string $src   File contents.
	 * @param string $rel   Relative path.
	 * @param string $path  Absolute path.
	 * @param array  $state Scan state (by reference).
	 */
	private static function check_php_content( $src, $rel, $path, &$state ) {
		$head = substr( $src, 0, 4 );
		if ( 0 === strpos( $head, 'GIF8' ) || "\x89PNG" === $head || 0 === strpos( $head, "\xFF\xD8\xFF" ) ) {
			self::add( $state, 'high', 'image_header', $rel, $path );
		}

		if ( preg_match( self::strrev_pattern(), $src ) ) {
			self::add( $state, 'high', 'strrev', $rel, $path );
		}

		if ( preg_match( '/\btouch\s*\(\s*(?:__FILE__|\$_SERVER\s*\[\s*[\'"]SCRIPT_FILENAME)[^;]*filemtime\s*\(/i', $src ) ) {
			self::add( $state, 'high', 'fake_date', $rel, $path );
		}

		if ( preg_match( '/(?:fopen|move_uploaded_file|copy|file_put_contents|rename)\s*\([^;]*\$_FILES\s*\[[^\]]+\]\s*\[\s*[\'"]name[\'"]\s*\]/i', $src ) ) {
			self::add( $state, 'high', 'upload_unsafe', $rel, $path );
		}

		if ( preg_match( '/\$_(?:POST|GET|REQUEST)\s*\[\s*[\'"](?:ccnum|cc_?num(?:ber)?|card_?number|cc_?cvv|cvv2?|cvc|senha|cpf|ccrg)[\'"]\s*\]/i', $src ) ) {
			self::add( $state, 'high', 'phishing_fields', $rel, $path );
		}

		$encoded = self::encoded_line( $src );
		if ( 'code' === $encoded ) {
			self::add( $state, 'high', 'encoded_line', $rel, $path );
		} elseif ( 'data' === $encoded ) {
			self::add( $state, 'info', 'encoded_data', $rel, $path );
		}

		if ( preg_match( '/\beval\s*\(\s*\$/i', $src ) ) {
			self::add( $state, 'medium', 'eval_var', $rel, $path );
		}

		if ( preg_match( '/header\s*\(\s*[\'"]\s*location\s*:\s*https?:\/\/(\d{1,3}(?:\.\d{1,3}){3})/i', $src, $match ) ) {
			self::add( $state, 'medium', 'ip_redirect', $rel, $path, $match[1] );
		}

		if ( preg_match( '/fopen\s*\(\s*[\'"][^\'"]+\.(?:html?|txt)[\'"]\s*,\s*[\'"]a\+?[\'"]/i', $src ) && preg_match( '/\$_(?:POST|GET|REQUEST)\b|REMOTE_ADDR/', $src ) ) {
			self::add( $state, 'medium', 'request_log', $rel, $path );
		}
	}

	/**
	 * Read a local file. WP_Filesystem is not used on purpose: on hosts without direct
	 * access it asks for FTP credentials, and this tool only reads.
	 *
	 * @param string $path Absolute path.
	 * @return string Contents, or an empty string when the file cannot be read.
	 */
	private static function read( $path ) {
		$src = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file, read only, see the docblock.
		return ( false === $src ) ? '' : $src;
	}

	/**
	 * Pattern for strrev() applied to the backwards name of a dangerous function.
	 * The names are written forwards and turned around at run time, so this file does not
	 * carry the backwards spellings that malware scanners look for and get flagged itself.
	 *
	 * @return string
	 */
	private static function strrev_pattern() {
		static $pattern = null;

		if ( null === $pattern ) {
			$names = array(
				'base64',
				'base64_decode',
				'decode',
				'gzinflate',
				'gzdeflate',
				'gzuncompress',
				'gzdecode',
				'eval',
				'assert',
				'system',
				'exec',
				'shell_exec',
				'passthru',
				'popen',
				'proc_open',
				'str_rot13',
				'urldecode',
				'create_function',
			);

			$backwards = array();
			foreach ( $names as $name ) {
				$backwards[] = preg_quote( implode( '', array_reverse( str_split( $name ) ) ), '/' );
			}

			$pattern = '/strrev\s*\(\s*[\'"](?:' . implode( '|', $backwards ) . ')[\'"]/i';
		}

		return $pattern;
	}

	/**
	 * Whether a PHP file in uploads is a compiled Twig template, the cache that WPML and
	 * other plugins keep there. A template that reads request data or calls functions that
	 * run code or commands is not treated as one.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	private static function is_template_cache( $path ) {
		if ( ! is_readable( $path ) || filesize( $path ) > self::MAX_READ ) {
			return false;
		}

		$src = self::read( $path );

		if ( ! preg_match( '/\bclass\s+__TwigTemplate_[0-9a-f]{16,}(?:___\d+)?\s+extends\s+[\\\\\w]*Template\b/', $src ) ) {
			return false;
		}

		return ! preg_match( '/\$_(?:GET|POST|REQUEST|COOKIE|FILES|SERVER)\b|\b(?:eval|assert|create_function|base64_decode|gzinflate|str_rot13|system|exec|shell_exec|passthru|popen|proc_open)\s*\(/i', $src );
	}

	/**
	 * Count a file into a group that is reported as one finding per folder, for caches
	 * that spread many files over subfolders named after a hash prefix (ab/, 3f/…).
	 *
	 * @param array  $state Scan state (by reference).
	 * @param string $rule  Rule code.
	 * @param string $path  Absolute path.
	 */
	private static function add_group( &$state, $rule, $path ) {
		$dir = dirname( $path );
		if ( preg_match( '/^[0-9a-f]{2}$/i', basename( $dir ) ) ) {
			$dir = dirname( $dir );
		}

		$key = $rule . '|' . $dir;
		if ( ! isset( $state['groups'][ $key ] ) ) {
			$state['groups'][ $key ] = 0;
		}
		++$state['groups'][ $key ];
	}

	/**
	 * Whether a PHP file holds nothing but tags, comments and whitespace, like the
	 * "Silence is golden" index.php that WordPress places in its folders.
	 *
	 * @param string $src File contents.
	 * @return bool
	 */
	private static function is_silent_php( $src ) {
		$code = (string) preg_replace( '~/\*.*?\*/~s', '', $src );
		// A line comment ends at the line break or at a closing tag, as PHP reads it.
		$code = (string) preg_replace( '~(?://|#)(?:(?!\?>)[^\r\n])*~', '', $code );
		$code = (string) preg_replace( '~<\?(?:php)?|\?>~i', '', $code );

		return '' === trim( $code );
	}

	/**
	 * Detect a very long line that is almost all base64 or percent-encoding. What the line
	 * decodes to decides the result: images and fonts embedded as data URIs (menu icons,
	 * logos, icon fonts) are ignored, serialized data (sample settings in old themes) is
	 * information only, and anything else counts as hidden code.
	 *
	 * @param string $src File contents.
	 * @return string '' when there is none, 'data' for serialized data, 'code' otherwise.
	 */
	private static function encoded_line( $src ) {
		if ( strlen( $src ) < 2000 ) {
			return '';
		}

		// A file that runs code with eval() gets no benefit of the doubt.
		$has_eval = (bool) preg_match( '/\beval\s*\(/i', $src );
		$found    = '';

		foreach ( preg_split( '/\R/', $src ) as $line ) {
			$len = strlen( $line );
			if ( $len <= 2000 ) {
				continue;
			}
			$encoded = preg_match_all( '/[A-Za-z0-9+\/=%#]/', $line );
			if ( $encoded / $len <= 0.97 ) {
				continue;
			}

			$kind = self::classify_encoded( $line );
			if ( 'asset' === $kind && ! $has_eval ) {
				continue;
			}
			if ( 'data' === $kind && ! $has_eval ) {
				$found = 'data';
				continue;
			}

			return 'code';
		}

		return $found;
	}

	/**
	 * Decode the long blobs in a line and say what they are. Nothing decoded here is ever
	 * run: the result is only compared with the first bytes of known file types. Every blob
	 * in the line has to be harmless for the line to be.
	 *
	 * @param string $line A long, almost fully encoded line.
	 * @return string asset, data or code.
	 */
	private static function classify_encoded( $line ) {
		$decoded_parts = array();

		if ( preg_match_all( '/[A-Za-z0-9+\/=]{2000,}/', $line, $blobs ) ) {
			foreach ( $blobs[0] as $blob ) {
				$decoded_parts[] = base64_decode( $blob, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- classifies a suspicious blob by its first bytes, nothing is executed.
			}
		} elseif ( preg_match( '/%3C(?:svg|%3Fxml)/i', $line, $match, PREG_OFFSET_CAPTURE ) ) {
			// An SVG written as a percent-encoded data URI.
			$decoded_parts[] = rawurldecode( substr( $line, $match[0][1] ) );
		}

		if ( empty( $decoded_parts ) ) {
			return 'code';
		}

		$result = 'asset';
		foreach ( $decoded_parts as $decoded ) {
			$kind = self::classify_decoded( $decoded );
			if ( 'code' === $kind ) {
				return 'code';
			}
			if ( 'data' === $kind ) {
				$result = 'data';
			}
		}

		return $result;
	}

	/**
	 * What a decoded blob is, by its first bytes.
	 *
	 * @param string|false $decoded Decoded blob.
	 * @return string asset, data or code.
	 */
	private static function classify_decoded( $decoded ) {
		if ( false === $decoded || '' === $decoded ) {
			return 'code';
		}

		$head = ltrim( substr( $decoded, 0, 512 ), "\xEF\xBB\xBF \t\r\n" );
		$sigs = array( "\x89PNG", 'GIF8', "\xFF\xD8\xFF", 'wOFF', 'wOF2', 'OTTO', "\x00\x01\x00\x00", "\x00\x00\x01\x00" );
		foreach ( $sigs as $sig ) {
			if ( 0 === strpos( $head, $sig ) ) {
				return 'asset';
			}
		}
		if ( 0 === strpos( $head, 'RIFF' ) && 'WEBP' === substr( $head, 8, 4 ) ) {
			return 'asset';
		}
		if ( preg_match( '/^(?:<\?xml[^>]*>\s*)?(?:<!--.*?-->\s*)*(?:<!DOCTYPE[^>]*>\s*)?<svg[\s>]/is', $head ) && false === stripos( $decoded, '<?php' ) ) {
			return 'asset';
		}
		if ( preg_match( '/^(?:a|O):\d+:/', $decoded ) ) {
			return 'data';
		}

		return 'code';
	}

	/**
	 * Content rules for php.ini and .user.ini.
	 *
	 * @param string $src   File contents.
	 * @param string $rel   Relative path.
	 * @param string $path  Absolute path.
	 * @param array  $state Scan state (by reference).
	 */
	private static function check_ini_content( $src, $rel, $path, &$state ) {
		if ( preg_match( '/^\s*(?:disable_functions\s*=\s*["\']?\s*["\']?\s*$|safe_mode\s*=\s*off|allow_url_include\s*=\s*(?:on|1))/im', $src ) ) {
			self::add( $state, 'high', 'ini_relax', $rel, $path );
		}
		if ( preg_match( '/^\s*auto_prepend_file\s*=\s*["\']?([^"\'\r\n]+)/im', $src, $match ) ) {
			self::add( $state, 'medium', 'ini_prepend', $rel, $path, trim( $match[1] ) );
		}
	}

	/**
	 * Content rules for .htaccess files.
	 *
	 * @param string $src   File contents.
	 * @param string $rel   Relative path.
	 * @param string $path  Absolute path.
	 * @param array  $state Scan state (by reference).
	 */
	private static function check_htaccess_content( $src, $rel, $path, &$state ) {
		if ( preg_match_all( '/^\s*allow\s+from\s+\d{1,3}\s*$/im', $src ) >= 5 ) {
			self::add( $state, 'medium', 'htaccess_geo', $rel, $path );
		}
	}

	/**
	 * Store a finding.
	 *
	 * @param array  $state Scan state (by reference).
	 * @param string $sev   high, medium or info.
	 * @param string $rule  Rule code.
	 * @param string $rel   Relative path.
	 * @param string $path  Absolute path.
	 * @param string $extra Optional detail (never file contents beyond a matched IP or setting).
	 */
	private static function add( &$state, $sev, $rule, $rel, $path, $extra = '' ) {
		if ( count( $state['findings'] ) >= self::MAX_FINDINGS ) {
			$state['truncated'] = true;
			return;
		}

		$state['findings'][] = array(
			'sev'   => $sev,
			'rule'  => $rule,
			'path'  => $rel,
			'extra' => (string) $extra,
			'mtime' => (int) filemtime( $path ),
			'ctime' => (int) filectime( $path ),
		);
	}

	/**
	 * Close the scan: missing core files and final status.
	 *
	 * @param array $state Scan state (by reference).
	 */
	private static function finish( &$state ) {
		if ( is_array( $state['core'] ) ) {
			$state['core_missing']       = array_slice( array_keys( $state['core'] ), 0, 50 );
			$state['core_missing_count'] = count( $state['core'] );
		}

		foreach ( $state['groups'] as $key => $count ) {
			list( $rule, $dir ) = explode( '|', $key, 2 );
			self::add(
				$state,
				'info',
				$rule,
				self::rel( $dir, $state['abspath'] ) . '/',
				$dir,
				sprintf(
					/* translators: %s: number of files */
					__( 'Files: %s', 'ayudawp-file-review' ),
					number_format_i18n( $count )
				)
			);
		}

		$state['core']     = null;
		$state['stack']    = array();
		$state['status']   = 'done';
		$state['finished'] = time();
	}

	/**
	 * Save the state without autoload.
	 *
	 * @param array $state Scan state.
	 */
	private static function save_state( $state ) {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Path relative to ABSPATH.
	 *
	 * @param string $path    Absolute normalized path.
	 * @param string $abspath Normalized ABSPATH without trailing slash.
	 * @return string
	 */
	private static function rel( $path, $abspath ) {
		if ( 0 === strpos( $path, $abspath . '/' ) ) {
			return substr( $path, strlen( $abspath ) + 1 );
		}
		return $path;
	}

	/**
	 * Which part of the install a relative path belongs to.
	 *
	 * @param string $rel Relative path.
	 * @return string plugin:slug, theme:slug, plugins-loose, themes-loose, mu-plugins, uploads, languages,
	 *                wp-admin, wp-includes, root or other.
	 */
	private static function component( $rel ) {
		$abspath = untrailingslashit( wp_normalize_path( ABSPATH ) );
		$plugins = self::rel( untrailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) ), $abspath ) . '/';
		$themes  = self::rel( untrailingslashit( wp_normalize_path( get_theme_root() ) ), $abspath ) . '/';
		$mu      = self::rel( untrailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) ), $abspath ) . '/';
		$langs   = self::rel( untrailingslashit( wp_normalize_path( WP_LANG_DIR ) ), $abspath ) . '/';
		$content = self::rel( untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ), $abspath ) . '/';
		$uploads = wp_upload_dir( null, false );
		$up_rel  = isset( $uploads['basedir'] ) ? self::rel( untrailingslashit( wp_normalize_path( $uploads['basedir'] ) ), $abspath ) . '/' : '';

		// Translation files in PHP format (*.l10n.php) change with every language update.
		if ( 0 === strpos( $rel, $langs ) && '.l10n.php' === substr( $rel, -9 ) ) {
			return 'languages';
		}
		// A file directly in the plugins or themes folder belongs to no plugin or theme folder.
		if ( 0 === strpos( $rel, $plugins ) ) {
			$sub = substr( $rel, strlen( $plugins ) );
			return ( false === strpos( $sub, '/' ) ) ? 'plugins-loose' : 'plugin:' . strtok( $sub, '/' );
		}
		if ( 0 === strpos( $rel, $themes ) ) {
			$sub = substr( $rel, strlen( $themes ) );
			return ( false === strpos( $sub, '/' ) ) ? 'themes-loose' : 'theme:' . strtok( $sub, '/' );
		}
		if ( 0 === strpos( $rel, $mu ) ) {
			return 'mu-plugins';
		}
		if ( '' !== $up_rel && 0 === strpos( $rel, $up_rel ) ) {
			return 'uploads';
		}
		if ( 0 === strpos( $rel, 'wp-admin/' ) ) {
			return 'wp-admin';
		}
		if ( 0 === strpos( $rel, 'wp-includes/' ) ) {
			return 'wp-includes';
		}
		if ( false === strpos( $rel, '/' ) ) {
			return 'root';
		}
		if ( 0 === strpos( $rel, $content ) ) {
			return 'content';
		}
		if ( 0 !== strpos( $rel, '/' ) ) {
			// A folder in the root that WordPress does not create.
			return 'rootdir:' . substr( $rel, 0, strpos( $rel, '/' ) );
		}
		return 'other';
	}

	/*
	 * Self-delete.
	 */

	/**
	 * Remove the folder the plugin came in, once its main file is gone, but only when the
	 * rest of it is a readme or a licence, the files a zip from GitHub brings along.
	 * Anything else in the folder means it is not only ours, and it is left as it is.
	 *
	 * @param string $dir Normalized absolute path of the folder.
	 */
	private static function remove_own_folder( $dir ) {
		// readme.md, README.es.md, LICENSE, changelog.txt and the like, plus git's own files.
		$companion = '/^(?:(?:readme|license|licence|changelog)(?:\.[a-z]{2}(?:[_-][a-z]{2})?)?(?:\.(?:md|txt))?|\.gitignore|\.gitattributes)$/i';

		$entries = scandir( $dir );
		if ( false === $entries ) {
			return;
		}
		$entries = array_diff( $entries, array( '.', '..' ) );

		foreach ( $entries as $entry ) {
			if ( ! preg_match( $companion, $entry ) || ! is_file( $dir . '/' . $entry ) ) {
				return;
			}
		}

		foreach ( $entries as $entry ) {
			wp_delete_file( $dir . '/' . $entry );
		}

		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removes the plugin's own folder, already empty; WP_Filesystem could ask for FTP credentials here.
	}

	/**
	 * Delete the stored results and this file.
	 */
	public static function handle_delete() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ayudawp-file-review' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::NONCE_DELETE );

		if ( empty( $_POST['ayudawp_fr_confirm'] ) ) {
			wp_safe_redirect( admin_url( 'tools.php?page=' . self::PAGE ) );
			exit;
		}

		delete_option( self::OPTION );

		$file       = wp_normalize_path( __FILE__ );
		$plugin_dir = untrailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
		$as_plugin  = ( 0 === strpos( $file, $plugin_dir . '/' ) );

		// Installed from Plugins > Add New instead of mu-plugins: switch it off first.
		if ( $as_plugin ) {
			if ( ! function_exists( 'deactivate_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			deactivate_plugins( plugin_basename( $file ), true );

			// The uninstall callback saved on activation would point to a file that is gone.
			$uninstallable = (array) get_option( 'uninstall_plugins', array() );
			if ( isset( $uninstallable[ plugin_basename( $file ) ] ) ) {
				unset( $uninstallable[ plugin_basename( $file ) ] );
				update_option( 'uninstall_plugins', $uninstallable );
			}
		}

		// Sites that lock file changes from the dashboard (DISALLOW_FILE_MODS) keep the file.
		if ( wp_is_file_mod_allowed( 'ayudawp_fr_self_delete' ) ) {
			wp_delete_file( $file );

			if ( $as_plugin && ! file_exists( $file ) && dirname( $file ) !== $plugin_dir ) {
				self::remove_own_folder( dirname( $file ) );
			}
		}

		if ( file_exists( $file ) ) {
			$message = sprintf(
				/* translators: %s: path of this file */
				__( 'The results were deleted, but this file could not be removed. Delete it by hand with your file manager: %s', 'ayudawp-file-review' ),
				wp_normalize_path( $file )
			);
		} else {
			$message = __( 'Done. The results and the review tool have been deleted from your site.', 'ayudawp-file-review' );
		}

		wp_die(
			esc_html( $message ),
			esc_html__( 'File review', 'ayudawp-file-review' ),
			array(
				'response'  => 200,
				'link_url'  => esc_url( admin_url() ),
				'link_text' => esc_html__( 'Go to the Dashboard', 'ayudawp-file-review' ),
			)
		);
	}

	/*
	 * Page.
	 */

	/**
	 * Texts for each rule: title, what it is and what to do.
	 *
	 * @return array
	 */
	private static function rules() {
		return array(
			'core_modified'   => array(
				__( 'Modified core file', 'ayudawp-file-review' ),
				__( 'This WordPress file does not match the original of your version.', 'ayudawp-file-review' ),
				__( 'Replace it with the same file from a clean download of your version. If it is index.php, wp-login.php or wp-settings.php, assume the site is infected.', 'ayudawp-file-review' ),
			),
			'core_extra_php'  => array(
				__( 'PHP file that is not part of WordPress', 'ayudawp-file-review' ),
				__( 'There is a PHP file inside wp-admin or wp-includes that your version of WordPress does not include.', 'ayudawp-file-review' ),
				__( 'Open it and read it. If it is not a leftover from an old version, delete it and look for more.', 'ayudawp-file-review' ),
			),
			'core_extra'      => array(
				__( 'File that is not part of WordPress', 'ayudawp-file-review' ),
				__( 'There is a file inside wp-admin or wp-includes that your version of WordPress does not include.', 'ayudawp-file-review' ),
				__( 'If it is a rules file (.htaccess) or a PHP configuration file, check who created it. Other leftovers are usually harmless.', 'ayudawp-file-review' ),
			),
			'uploads_php'     => array(
				__( 'PHP file in uploads', 'ayudawp-file-review' ),
				__( 'The uploads folder is for images and documents, not for code.', 'ayudawp-file-review' ),
				__( 'Open it. Unless a plugin you know put it there, delete it.', 'ayudawp-file-review' ),
			),
			'uploads_index'   => array(
				__( 'Empty index.php in uploads', 'ayudawp-file-review' ),
				__( 'A tiny index.php with no code, which some plugins add so the folder cannot be listed.', 'ayudawp-file-review' ),
				__( 'Usually harmless.', 'ayudawp-file-review' ),
			),
			'strrev'          => array(
				__( 'Dangerous function name written backwards', 'ayudawp-file-review' ),
				__( 'The code builds names such as base64_decode or gzinflate backwards with strrev(), so that searches for those names do not find it.', 'ayudawp-file-review' ),
				__( 'Legitimate code has no reason to do this. Open the file: it is very likely malware.', 'ayudawp-file-review' ),
			),
			'fake_date'       => array(
				__( 'Changes its own modification date', 'ayudawp-file-review' ),
				__( 'The file copies the date of another file onto itself with touch(), a trick to go unnoticed when sorting by date.', 'ayudawp-file-review' ),
				__( 'Legitimate code has no reason to do this. Treat it as malware.', 'ayudawp-file-review' ),
			),
			'image_header'    => array(
				__( 'PHP disguised as an image', 'ayudawp-file-review' ),
				__( 'The file starts with the signature of an image (GIF, PNG or JPEG) but it is PHP.', 'ayudawp-file-review' ),
				__( 'Treat it as malware.', 'ayudawp-file-review' ),
			),
			'upload_unsafe'   => array(
				__( 'File upload with no control', 'ayudawp-file-review' ),
				__( 'The file saves an uploaded file with the name chosen by whoever sends it, with no checks.', 'ayudawp-file-review' ),
				__( 'This is a classic backdoor to upload more files. Unless it belongs to a plugin you know, delete it.', 'ayudawp-file-review' ),
			),
			'phishing_fields' => array(
				__( 'Typical phishing form fields', 'ayudawp-file-review' ),
				__( 'The file reads fields such as card number, CVV or national ID numbers from the request.', 'ayudawp-file-review' ),
				__( 'Unless it is your own payment form, treat it as a phishing kit.', 'ayudawp-file-review' ),
			),
			'encoded_line'    => array(
				__( 'Long encoded line', 'ayudawp-file-review' ),
				__( 'A line of thousands of characters that is almost all base64, the usual way to hide a payload.', 'ayudawp-file-review' ),
				__( 'Open the file and check what it does with that text.', 'ayudawp-file-review' ),
			),
			'encoded_data'    => array(
				__( 'Long encoded data', 'ayudawp-file-review' ),
				__( 'A long base64 text that decodes to serialized data, not to code. Some old themes store default settings this way.', 'ayudawp-file-review' ),
				__( 'Usually harmless.', 'ayudawp-file-review' ),
			),
			'eval_var'        => array(
				__( 'eval() of a variable', 'ayudawp-file-review' ),
				__( 'The file runs code stored in a variable.', 'ayudawp-file-review' ),
				__( 'Some legitimate plugins do this, for example those that let you add code snippets from the Dashboard. If the file belongs to one of them it is normal; if not, open it.', 'ayudawp-file-review' ),
			),
			'ip_redirect'     => array(
				__( 'Redirect to an IP address', 'ayudawp-file-review' ),
				__( 'The file sends visitors to a bare IP address instead of a domain.', 'ayudawp-file-review' ),
				__( 'Common in the redirectors of malicious campaigns. Check where it points and why.', 'ayudawp-file-review' ),
			),
			'request_log'     => array(
				__( 'Saves request data to an HTML or TXT file', 'ayudawp-file-review' ),
				__( 'The file appends visit or form data to a .html or .txt file, the way phishing kits store what they steal.', 'ayudawp-file-review' ),
				__( 'Check what it stores and whether that file can be read from the web.', 'ayudawp-file-review' ),
			),
			'ini_relax'       => array(
				__( 'PHP configuration that removes restrictions', 'ayudawp-file-review' ),
				__( 'This php.ini or .user.ini turns off safe mode, empties the list of disabled functions or allows remote includes.', 'ayudawp-file-review' ),
				__( 'Delete it unless you or your hosting created it on purpose.', 'ayudawp-file-review' ),
			),
			'ini_prepend'     => array(
				__( 'PHP configuration that loads a file before every request', 'ayudawp-file-review' ),
				__( 'auto_prepend_file loads another file before any PHP runs.', 'ayudawp-file-review' ),
				__( 'Some security plugins use it legitimately, for example the Wordfence firewall. Check that the file it loads is one you recognise.', 'ayudawp-file-review' ),
			),
			'htaccess_geo'    => array(
				__( 'Hand-made country filter', 'ayudawp-file-review' ),
				__( 'The rules only let in IP addresses that start with certain numbers.', 'ayudawp-file-review' ),
				__( 'Phishing kits use this to show the fraud only to their targets. If you did not write it, delete it.', 'ayudawp-file-review' ),
			),
			'too_big'         => array(
				__( 'PHP file too big to read', 'ayudawp-file-review' ),
				__( 'It was not read because it is larger than 2 MB, which is unusual for a PHP file.', 'ayudawp-file-review' ),
				__( 'Check what it is.', 'ayudawp-file-review' ),
			),
			'template_cache'  => array(
				__( 'Template cache in uploads', 'ayudawp-file-review' ),
				__( 'Compiled Twig templates. Some plugins, such as WPML, keep them in the uploads folder. They are PHP, but the plugin writes them itself.', 'ayudawp-file-review' ),
				__( 'Normal if you use WPML or another plugin that works with Twig. If you use none, open the folder.', 'ayudawp-file-review' ),
			),
			'config_copy'     => array(
				__( 'Copy of wp-config.php', 'ayudawp-file-review' ),
				__( 'A copy of the configuration file with another extension. The server does not run it as PHP, so it may hand it out as plain text, database password included.', 'ayudawp-file-review' ),
				__( 'Delete it, or move it out of the public folder if you need to keep it. If anyone could have downloaded it, change the database password.', 'ayudawp-file-review' ),
			),
			'unreadable'      => array(
				__( 'PHP file the web server cannot read', 'ayudawp-file-review' ),
				__( 'Its permissions or its owner do not let the web server open it, so its content was not checked. The server cannot run it either.', 'ayudawp-file-review' ),
				__( 'Check its owner and permissions with your file manager or ask your hosting provider. If you do not recognise it, delete it.', 'ayudawp-file-review' ),
			),
		);
	}

	/**
	 * Severity labels.
	 *
	 * @return array
	 */
	private static function severities() {
		return array(
			'high'   => __( 'High', 'ayudawp-file-review' ),
			'medium' => __( 'Medium', 'ayudawp-file-review' ),
			'info'   => __( 'Info', 'ayudawp-file-review' ),
		);
	}

	/**
	 * Human name of a component.
	 *
	 * @param string $component Component code.
	 * @return string
	 */
	private static function component_label( $component ) {
		static $plugins = null;
		static $themes  = null;

		if ( 0 === strpos( $component, 'plugin:' ) ) {
			if ( null === $plugins ) {
				if ( ! function_exists( 'get_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				$plugins = array();
				foreach ( get_plugins() as $file => $data ) {
					$plugins[ strtok( $file, '/' ) ] = $data['Name'];
				}
			}
			$slug = substr( $component, 7 );
			return sprintf(
				/* translators: %s: plugin name or folder */
				__( 'Plugin: %s', 'ayudawp-file-review' ),
				isset( $plugins[ $slug ] ) ? $plugins[ $slug ] : $slug
			);
		}

		if ( 0 === strpos( $component, 'theme:' ) ) {
			if ( null === $themes ) {
				$themes = array();
				foreach ( wp_get_themes() as $slug => $theme ) {
					$themes[ $slug ] = $theme->get( 'Name' );
				}
			}
			$slug = substr( $component, 6 );
			return sprintf(
				/* translators: %s: theme name or folder */
				__( 'Theme: %s', 'ayudawp-file-review' ),
				isset( $themes[ $slug ] ) ? $themes[ $slug ] : $slug
			);
		}

		if ( 0 === strpos( $component, 'rootdir:' ) ) {
			return sprintf(
				/* translators: %s: folder name */
				__( 'Folder in the root: "%s"', 'ayudawp-file-review' ),
				substr( $component, 8 )
			);
		}

		$labels = array(
			'plugins-loose' => __( 'Plugins folder (loose files)', 'ayudawp-file-review' ),
			'themes-loose'  => __( 'Themes folder (loose files)', 'ayudawp-file-review' ),
			'mu-plugins'    => __( 'Must-use plugins', 'ayudawp-file-review' ),
			'uploads'       => __( 'Uploads', 'ayudawp-file-review' ),
			'languages'     => __( 'Translation files', 'ayudawp-file-review' ),
			'content'       => __( 'wp-content folder', 'ayudawp-file-review' ),
			'wp-admin'      => 'wp-admin',
			'wp-includes'   => 'wp-includes',
			'root'          => __( 'Root folder', 'ayudawp-file-review' ),
			'other'         => __( 'Other folders', 'ayudawp-file-review' ),
		);

		return isset( $labels[ $component ] ) ? $labels[ $component ] : $component;
	}

	/**
	 * Date in the site format.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	private static function date( $timestamp ) {
		if ( empty( $timestamp ) ) {
			return '';
		}
		return wp_date( 'Y-m-d H:i', (int) $timestamp );
	}

	/**
	 * Render the admin page.
	 */
	public static function render_page() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ayudawp-file-review' ) );
		}

		$state = get_option( self::OPTION );
		$done  = is_array( $state ) && isset( $state['status'] ) && 'done' === $state['status'];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'File review', 'ayudawp-file-review' ); ?></h1>

			<div class="ayudawp-fr-box">
				<p><?php esc_html_e( 'This tool reviews the files of your WordPress install: core files against the official originals, what sits in the root and is not part of WordPress, PHP inside uploads, the usual malware camouflage tricks and the PHP files changed in the last few days.', 'ayudawp-file-review' ); ?></p>
				<p><strong><?php esc_html_e( 'It only reads.', 'ayudawp-file-review' ); ?></strong> <?php esc_html_e( 'It does not delete, move or send anything anywhere, apart from asking WordPress.org for the checksums of your version, and it only runs when you press the button.', 'ayudawp-file-review' ); ?></p>
				<p>
					<label for="ayudawp-fr-days"><?php esc_html_e( 'Recent changes to show:', 'ayudawp-file-review' ); ?></label>
					<select id="ayudawp-fr-days">
						<option value="1"><?php esc_html_e( 'Last 24 hours', 'ayudawp-file-review' ); ?></option>
						<option value="7" selected="selected"><?php esc_html_e( 'Last 7 days', 'ayudawp-file-review' ); ?></option>
						<option value="30"><?php esc_html_e( 'Last 30 days', 'ayudawp-file-review' ); ?></option>
					</select>
					<button type="button" class="button button-primary" id="ayudawp-fr-start">
						<?php echo $done ? esc_html__( 'Run the review again', 'ayudawp-file-review' ) : esc_html__( 'Start the review', 'ayudawp-file-review' ); ?>
					</button>
					<span class="ayudawp-fr-progress" id="ayudawp-fr-progress" aria-live="polite"></span>
				</p>
			</div>

			<?php
			if ( $done ) {
				self::render_results( $state );
			}
			self::render_delete_box();
			?>
		</div>
		<?php
	}

	/**
	 * Findings grouped by file, most serious first. A file appears once, at the highest
	 * level among its findings, with every reason it was flagged for.
	 *
	 * @param array $findings Findings as stored by add().
	 * @return array
	 */
	private static function group_findings( $findings ) {
		$order  = array(
			'high'   => 0,
			'medium' => 1,
			'info'   => 2,
		);
		$groups = array();

		foreach ( $findings as $finding ) {
			$key = $finding['path'];
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'path'  => $finding['path'],
					'sev'   => $finding['sev'],
					'mtime' => $finding['mtime'],
					'ctime' => $finding['ctime'],
					'items' => array(),
				);
			} elseif ( $order[ $finding['sev'] ] < $order[ $groups[ $key ]['sev'] ] ) {
				$groups[ $key ]['sev'] = $finding['sev'];
			}
			$groups[ $key ]['items'][] = $finding;
		}

		$by_level = function ( $a, $b ) use ( $order ) {
			return $order[ $a['sev'] ] - $order[ $b['sev'] ];
		};

		foreach ( array_keys( $groups ) as $key ) {
			usort( $groups[ $key ]['items'], $by_level );
		}

		$groups = array_values( $groups );
		usort(
			$groups,
			function ( $a, $b ) use ( $by_level ) {
				$cmp = $by_level( $a, $b );
				return 0 !== $cmp ? $cmp : strcmp( $a['path'], $b['path'] );
			}
		);

		return $groups;
	}

	/**
	 * Render the results of a finished scan.
	 *
	 * @param array $state Scan state.
	 */
	private static function render_results( $state ) {
		$rules  = self::rules();
		$sevs   = self::severities();
		$groups = self::group_findings( $state['findings'] );

		$counts = array(
			'high'   => 0,
			'medium' => 0,
			'info'   => 0,
		);
		foreach ( $groups as $group ) {
			++$counts[ $group['sev'] ];
		}

		$root_alerts = 0;
		foreach ( $state['root'] as $item ) {
			if ( ( 'dir' === $item['type'] && $item['php'] > 0 ) || 'php' === $item['type'] ) {
				++$root_alerts;
			}
		}
		?>
		<h2><?php esc_html_e( 'Summary', 'ayudawp-file-review' ); ?></h2>
		<div class="ayudawp-fr-box">
			<p>
				<?php
				printf(
					/* translators: 1: date, 2: number of files, 3: number of folders */
					esc_html__( 'Review finished on %1$s: %2$s files in %3$s folders.', 'ayudawp-file-review' ),
					esc_html( self::date( $state['finished'] ) ),
					esc_html( number_format_i18n( $state['files'] ) ),
					esc_html( number_format_i18n( $state['dirs'] ) )
				);
				?>
			</p>
			<p>
				<?php foreach ( $sevs as $sev => $label ) : ?>
					<span class="ayudawp-fr-badge ayudawp-fr-<?php echo esc_attr( $sev ); ?>"><?php echo esc_html( $label . ': ' . number_format_i18n( $counts[ $sev ] ) ); ?></span>
				<?php endforeach; ?>
				<?php if ( $root_alerts > 0 ) : ?>
					<span class="ayudawp-fr-badge ayudawp-fr-high">
						<?php
						printf(
							/* translators: %s: number of items */
							esc_html__( 'Suspicious items in the root: %s', 'ayudawp-file-review' ),
							esc_html( number_format_i18n( $root_alerts ) )
						);
						?>
					</span>
				<?php endif; ?>
			</p>
			<?php if ( 'unavailable' === $state['checksum_note'] ) : ?>
				<p><?php esc_html_e( 'The official checksums could not be fetched from WordPress.org, so core files were not compared with the originals. The other checks did run.', 'ayudawp-file-review' ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $state['truncated'] ) ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: maximum number of findings */
						esc_html__( 'There were more than %s findings and only the first ones are shown.', 'ayudawp-file-review' ),
						esc_html( number_format_i18n( self::MAX_FINDINGS ) )
					);
					?>
				</p>
			<?php endif; ?>
			<?php if ( ! empty( $state['core_missing_count'] ) ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: number of files */
						esc_html__( 'Core files missing: %s', 'ayudawp-file-review' ),
						esc_html( number_format_i18n( $state['core_missing_count'] ) )
					);
					?>
				</p>
				<ul class="ayudawp-fr-missing">
					<?php foreach ( $state['core_missing'] as $missing ) : ?>
						<li><code><?php echo esc_html( $missing ); ?></code></li>
					<?php endforeach; ?>
				</ul>
				<p class="description"><?php esc_html_e( 'Deleting readme.html, license.txt or wp-config-sample.php is a common hardening step and does no harm. If a file of wp-admin or wp-includes is missing, reinstall WordPress from Dashboard > Updates.', 'ayudawp-file-review' ); ?></p>
			<?php endif; ?>
			<p><strong><?php esc_html_e( 'Finding nothing does not mean your site is clean, only that it has nothing this tool looks for.', 'ayudawp-file-review' ); ?></strong></p>
		</div>

		<h2><?php esc_html_e( 'Findings', 'ayudawp-file-review' ); ?></h2>
		<?php if ( empty( $groups ) ) : ?>
			<p><?php esc_html_e( 'No findings.', 'ayudawp-file-review' ); ?></p>
		<?php else : ?>
			<table class="widefat striped ayudawp-fr-table ayudawp-fr-findings">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Level', 'ayudawp-file-review' ); ?></th>
						<th><?php esc_html_e( 'File', 'ayudawp-file-review' ); ?></th>
						<th><?php esc_html_e( 'What it is', 'ayudawp-file-review' ); ?></th>
						<th><?php esc_html_e( 'What to do', 'ayudawp-file-review' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $groups as $group ) : ?>
						<?php
						// What to do follows the most serious reasons only: the advice of a milder one
						// (eval() is normal in some plugins) would contradict the verdict on the file.
						$actions = array();
						foreach ( $group['items'] as $item ) {
							$rule = isset( $rules[ $item['rule'] ] ) ? $rules[ $item['rule'] ] : array( $item['rule'], '', '' );
							if ( $item['sev'] === $group['sev'] && '' !== $rule[2] ) {
								$actions[ $rule[2] ] = true;
							}
						}
						?>
						<tr>
							<td><span class="ayudawp-fr-badge ayudawp-fr-<?php echo esc_attr( $group['sev'] ); ?>"><?php echo esc_html( $sevs[ $group['sev'] ] ); ?></span></td>
							<td>
								<span class="ayudawp-fr-path"><?php echo wp_kses( str_replace( '/', '/<wbr>', esc_html( $group['path'] ) ), array( 'wbr' => array() ) ); ?></span><br>
								<small>
									<?php echo esc_html( self::component_label( self::component( $group['path'] ) ) ); ?><br>
									<?php
									/* translators: %s: date */
									echo esc_html( sprintf( __( 'Modified: %s', 'ayudawp-file-review' ), self::date( $group['mtime'] ) ) );
									?>
									<br>
									<?php
									/* translators: %s: date */
									echo esc_html( sprintf( __( 'Changed on disk: %s', 'ayudawp-file-review' ), self::date( $group['ctime'] ) ) );
									?>
								</small>
							</td>
							<td>
								<?php foreach ( $group['items'] as $item ) : ?>
									<?php $rule = isset( $rules[ $item['rule'] ] ) ? $rules[ $item['rule'] ] : array( $item['rule'], '', '' ); ?>
									<p class="ayudawp-fr-item">
										<strong><?php echo esc_html( $rule[0] ); ?></strong><br>
										<?php echo esc_html( $rule[1] ); ?>
										<?php if ( '' !== $item['extra'] ) : ?>
											<br><code><?php echo esc_html( $item['extra'] ); ?></code>
										<?php endif; ?>
									</p>
								<?php endforeach; ?>
							</td>
							<td>
								<?php foreach ( array_keys( $actions ) as $action ) : ?>
									<p class="ayudawp-fr-item"><?php echo esc_html( $action ); ?></p>
								<?php endforeach; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php self::render_root( $state ); ?>
		<?php self::render_recent( $state ); ?>
		<?php self::render_admins(); ?>

		<h2><?php esc_html_e( 'Report to copy', 'ayudawp-file-review' ); ?></h2>
		<div class="ayudawp-fr-box">
			<p><?php esc_html_e( 'If you are not sure what something means, copy this report and paste it into Claude, ChatGPT or the AI you use, and ask it to explain it. It contains paths, dates and the reason for each finding, never the contents of your files or your user names.', 'ayudawp-file-review' ); ?></p>
			<textarea id="ayudawp-fr-report" class="ayudawp-fr-report" readonly="readonly"><?php echo esc_textarea( self::build_report( $state, $groups ) ); ?></textarea>
			<p><button type="button" class="button" id="ayudawp-fr-copy"><?php esc_html_e( 'Copy report', 'ayudawp-file-review' ); ?></button></p>
		</div>
		<?php
	}

	/**
	 * Root inventory table.
	 *
	 * @param array $state Scan state.
	 */
	private static function render_root( $state ) {
		?>
		<h2><?php esc_html_e( 'What is in the root and is not part of WordPress', 'ayudawp-file-review' ); ?></h2>
		<?php if ( empty( $state['root'] ) ) : ?>
			<p><?php esc_html_e( 'Nothing unexpected in the root.', 'ayudawp-file-review' ); ?></p>
			<?php
			return;
		endif;
		?>
		<p><?php esc_html_e( 'Some of these may be legitimate (verification files, other applications, a test folder of yours), but each one should have an explanation. A folder you do not recognise with PHP inside is the first thing to open.', 'ayudawp-file-review' ); ?></p>
		<table class="widefat striped ayudawp-fr-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'ayudawp-file-review' ); ?></th>
					<th><?php esc_html_e( 'Type', 'ayudawp-file-review' ); ?></th>
					<th><?php esc_html_e( 'Modified', 'ayudawp-file-review' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $state['root'] as $item ) : ?>
					<?php $alert = ( 'dir' === $item['type'] && $item['php'] > 0 ) || 'php' === $item['type']; ?>
					<tr<?php echo $alert ? ' class="ayudawp-fr-danger"' : ''; ?>>
						<td class="ayudawp-fr-path"><?php echo esc_html( $item['name'] ); ?></td>
						<td><?php echo esc_html( self::root_type_label( $item ) ); ?></td>
						<td><?php echo esc_html( self::date( $item['mtime'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Label for a root item.
	 *
	 * @param array $item Root item.
	 * @return string
	 */
	private static function root_type_label( $item ) {
		if ( 'dir' === $item['type'] ) {
			if ( $item['php'] > 0 ) {
				return sprintf(
					/* translators: %s: number of PHP files */
					__( 'Folder with %s PHP files inside', 'ayudawp-file-review' ),
					number_format_i18n( $item['php'] ) . ( $item['more'] ? '+' : '' )
				);
			}
			return __( 'Folder without PHP', 'ayudawp-file-review' );
		}
		if ( 'php' === $item['type'] ) {
			return __( 'PHP file', 'ayudawp-file-review' );
		}
		return __( 'File', 'ayudawp-file-review' );
	}

	/**
	 * Whether the recent changes look like a migration or a restored backup.
	 *
	 * @param array $state Scan state.
	 * @return bool
	 */
	private static function is_migration( $state ) {
		return $state['recent_old'] > 200;
	}

	/**
	 * Recent files to list. After a migration every plugin and theme file shows an old
	 * modification date, so only files that belong to no package stay in the list.
	 *
	 * @param array $state Scan state.
	 * @return array
	 */
	private static function recent_list( $state ) {
		$list = $state['recent_files'];
		if ( ! self::is_migration( $state ) && ! empty( $state['recent_pkg'] ) ) {
			$list = array_merge( $list, $state['recent_pkg'] );
		}
		return $list;
	}

	/**
	 * Recent changes by component.
	 *
	 * @param array $state Scan state.
	 */
	private static function render_recent( $state ) {
		?>
		<h2>
			<?php
			printf(
				/* translators: %s: number of days */
				esc_html__( 'PHP files changed in the last %s days', 'ayudawp-file-review' ),
				esc_html( number_format_i18n( $state['days'] ) )
			);
			?>
		</h2>
		<?php if ( empty( $state['recent_total'] ) ) : ?>
			<p><?php esc_html_e( 'None, apart from WordPress core files that match the originals.', 'ayudawp-file-review' ); ?></p>
			<?php
			return;
		endif;
		?>
		<p><?php esc_html_e( 'This uses the date the system recorded the change (ctime), which cannot be faked from PHP. After an update many files of the updated plugin or theme appear here, and that is normal. Look for what does not match any update of yours.', 'ayudawp-file-review' ); ?></p>
		<?php if ( self::is_migration( $state ) ) : ?>
			<p><?php esc_html_e( 'Many files were changed recently but keep an old modification date. That usually means the site was migrated or a backup was restored, so the list below leaves out the files of plugins and themes.', 'ayudawp-file-review' ); ?></p>
		<?php endif; ?>
		<table class="widefat striped ayudawp-fr-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Where', 'ayudawp-file-review' ); ?></th>
					<th><?php esc_html_e( 'PHP files changed', 'ayudawp-file-review' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $state['recent'] as $component => $count ) : ?>
					<tr>
						<td><?php echo esc_html( self::component_label( $component ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php $recent_list = self::recent_list( $state ); ?>
		<?php if ( ! empty( $recent_list ) ) : ?>
			<p>
				<?php
				if ( self::is_migration( $state ) ) {
					esc_html_e( 'Files outside plugins and themes:', 'ayudawp-file-review' );
				} else {
					esc_html_e( 'Files outside plugins and themes, and files changed recently that show an old modification date:', 'ayudawp-file-review' );
				}
				?>
			</p>
			<table class="widefat striped ayudawp-fr-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'File', 'ayudawp-file-review' ); ?></th>
						<th><?php esc_html_e( 'Changed on disk', 'ayudawp-file-review' ); ?></th>
						<th><?php esc_html_e( 'Modified (as shown in listings)', 'ayudawp-file-review' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $recent_list as $file ) : ?>
						<tr<?php echo $file['old'] ? ' class="ayudawp-fr-danger"' : ''; ?>>
							<td class="ayudawp-fr-path"><?php echo esc_html( $file['path'] ); ?></td>
							<td><?php echo esc_html( self::date( $file['ctime'] ) ); ?></td>
							<td><?php echo esc_html( self::date( $file['mtime'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p><small><?php esc_html_e( 'Highlighted rows were changed recently but show a modification date more than 30 days older. After a migration or a restored backup that is normal; otherwise it is the trace left by the date trick.', 'ayudawp-file-review' ); ?></small></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Administrators, with the date they were created.
	 */
	private static function render_admins() {
		$admins = self::get_admins();
		?>
		<h2><?php esc_html_e( 'Administrators', 'ayudawp-file-review' ); ?></h2>
		<p><?php esc_html_e( 'Check that you recognise all of them. An administrator created around the date of a suspicious file deserves a close look.', 'ayudawp-file-review' ); ?></p>
		<table class="widefat striped ayudawp-fr-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'User', 'ayudawp-file-review' ); ?></th>
					<th><?php esc_html_e( 'Created', 'ayudawp-file-review' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $admins as $admin ) : ?>
					<tr>
						<td><?php echo esc_html( $admin['login'] ); ?></td>
						<td><?php echo esc_html( self::date( $admin['registered'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Administrators of the site (and super admins on multisite).
	 *
	 * @return array
	 */
	private static function get_admins() {
		$users = get_users(
			array(
				'role'   => 'administrator',
				'number' => 100,
				'fields' => array( 'ID', 'user_login', 'user_registered' ),
			)
		);

		$admins = array();
		foreach ( $users as $user ) {
			$admins[ $user->user_login ] = array(
				'login'      => $user->user_login,
				'registered' => strtotime( $user->user_registered . ' UTC' ),
			);
		}

		if ( is_multisite() ) {
			foreach ( get_super_admins() as $login ) {
				if ( isset( $admins[ $login ] ) ) {
					continue;
				}
				$user = get_user_by( 'login', $login );
				if ( $user ) {
					$admins[ $login ] = array(
						'login'      => $login,
						'registered' => strtotime( $user->user_registered . ' UTC' ),
					);
				}
			}
		}

		return array_values( $admins );
	}

	/**
	 * Plain text report, without file contents or user names.
	 *
	 * @param array $state  Scan state.
	 * @param array $groups Findings grouped by file, from group_findings().
	 * @return string
	 */
	private static function build_report( $state, $groups ) {
		global $wp_version;

		$rules = self::rules();
		$sevs  = self::severities();
		$lines = array();

		$lines[] = __( 'This is the report of a file review of a WordPress site. Explain what each finding means and what I should do, starting with the most serious ones.', 'ayudawp-file-review' );
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: 1: tool version, 2: date */
			__( 'AyudaWP File Review %1$s, %2$s', 'ayudawp-file-review' ),
			AYUDAWP_FR_VERSION,
			self::date( $state['finished'] )
		);
		$lines[] = sprintf(
			/* translators: 1: WordPress version, 2: PHP version */
			__( 'WordPress %1$s, PHP %2$s', 'ayudawp-file-review' ),
			$wp_version,
			PHP_VERSION
		);
		$lines[] = sprintf(
			/* translators: 1: number of files, 2: number of folders */
			__( 'Files reviewed: %1$s in %2$s folders', 'ayudawp-file-review' ),
			$state['files'],
			$state['dirs']
		);
		$lines[] = 'unavailable' === $state['checksum_note']
			? __( 'Core files: NOT compared (the official checksums could not be fetched)', 'ayudawp-file-review' )
			: __( 'Core files: compared with the official checksums of WordPress.org', 'ayudawp-file-review' );

		foreach ( $sevs as $sev => $label ) {
			$block = array();
			$files = 0;
			foreach ( $groups as $group ) {
				if ( $sev !== $group['sev'] ) {
					continue;
				}
				++$files;
				$block[] = '- ' . $group['path'] . ' (' . sprintf(
					/* translators: 1: modification date, 2: change date */
					__( 'modified %1$s, changed on disk %2$s', 'ayudawp-file-review' ),
					self::date( $group['mtime'] ),
					self::date( $group['ctime'] )
				) . ')';
				foreach ( $group['items'] as $item ) {
					$rule    = isset( $rules[ $item['rule'] ] ) ? $rules[ $item['rule'] ] : array( $item['rule'], '', '' );
					$block[] = '  * ' . $rule[0] . ( '' !== $item['extra'] ? ' [' . $item['extra'] . ']' : '' );
				}
			}
			if ( ! empty( $block ) ) {
				$lines[] = '';
				$lines[] = '== ' . $label . ' (' . $files . ') ==';
				$lines   = array_merge( $lines, $block );
			}
		}

		if ( ! empty( $state['root'] ) ) {
			$lines[] = '';
			$lines[] = '== ' . __( 'In the root and not part of WordPress', 'ayudawp-file-review' ) . ' ==';
			foreach ( $state['root'] as $item ) {
				$lines[] = '- ' . $item['name'] . ': ' . self::root_type_label( $item ) . ' (' . self::date( $item['mtime'] ) . ')';
			}
		}

		if ( ! empty( $state['core_missing_count'] ) ) {
			$lines[] = '';
			$lines[] = sprintf(
				/* translators: %s: number of files */
				__( 'Core files missing: %s', 'ayudawp-file-review' ),
				$state['core_missing_count']
			);
			foreach ( $state['core_missing'] as $missing ) {
				$lines[] = '- ' . $missing;
			}
		}

		if ( ! empty( $state['recent_total'] ) ) {
			$lines[] = '';
			$lines[] = '== ' . sprintf(
				/* translators: %s: number of days */
				__( 'PHP files changed in the last %s days', 'ayudawp-file-review' ),
				$state['days']
			) . ' ==';
			foreach ( $state['recent'] as $component => $count ) {
				$lines[] = '- ' . self::component_label( $component ) . ': ' . $count;
			}
			if ( self::is_migration( $state ) ) {
				$lines[] = __( 'Note: many files keep an old modification date, which usually means a migration or a restored backup. Plugin and theme files are not listed.', 'ayudawp-file-review' );
			}
			foreach ( self::recent_list( $state ) as $file ) {
				$lines[] = '  ' . $file['path'] . ' (' . sprintf(
					/* translators: 1: change date, 2: modification date */
					__( 'changed on disk %1$s, modified %2$s', 'ayudawp-file-review' ),
					self::date( $file['ctime'] ),
					self::date( $file['mtime'] )
				) . ')';
			}
		}

		$admins  = self::get_admins();
		$lines[] = '';
		$lines[] = '== ' . __( 'Administrators', 'ayudawp-file-review' ) . ' ==';
		$i       = 0;
		foreach ( $admins as $admin ) {
			++$i;
			$lines[] = '- ' . sprintf(
				/* translators: 1: number, 2: date */
				__( 'Administrator %1$s, created %2$s', 'ayudawp-file-review' ),
				$i,
				self::date( $admin['registered'] )
			);
		}

		return implode( "\n", $lines );
	}

	/**
	 * Delete box.
	 */
	private static function render_delete_box() {
		?>
		<h2><?php esc_html_e( 'When you are done', 'ayudawp-file-review' ); ?></h2>
		<div class="ayudawp-fr-box">
			<p><?php esc_html_e( 'This is a one-off review tool, not something to keep installed. This button deletes the stored results and the tool itself.', 'ayudawp-file-review' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ayudawp_fr_delete">
				<?php wp_nonce_field( self::NONCE_DELETE ); ?>
				<p>
					<label>
						<input type="checkbox" name="ayudawp_fr_confirm" value="1" required="required">
						<?php esc_html_e( 'Yes, delete the results and this tool', 'ayudawp-file-review' ); ?>
					</label>
				</p>
				<?php submit_button( __( 'Delete the review tool', 'ayudawp-file-review' ), 'delete', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/*
	 * Spanish translation, bundled so the tool stays a single file.
	 */

	/**
	 * Translate our own strings to Spanish when the admin language is Spanish.
	 * Hooked to gettext_ayudawp-file-review, so it never runs for other text domains.
	 *
	 * @param string $translation Current translation.
	 * @param string $text        Original text.
	 * @return string
	 */
	public static function translate( $translation, $text ) {
		if ( null === self::$is_spanish ) {
			$locale           = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
			self::$is_spanish = ( 'es' === $locale || 0 === strpos( $locale, 'es_' ) );
		}

		if ( ! self::$is_spanish || $translation !== $text ) {
			return $translation;
		}

		$strings = self::spanish();

		return isset( $strings[ $text ] ) ? $strings[ $text ] : $translation;
	}

	/**
	 * Spanish strings.
	 *
	 * @return array
	 */
	private static function spanish() {
		static $strings = null;

		if ( null !== $strings ) {
			return $strings;
		}

		$strings = array(
			'File review'                                 => 'Revisión de archivos',
			'Starting the review…'                        => 'Empezando la revisión…',
			'The review stopped because of an error. Reload the page and try again.' => 'La revisión se ha parado por un error. Recarga la página y vuelve a intentarlo.',
			'Report copied'                               => 'Informe copiado',
			'Checking the root and the core checksums…'   => 'Revisando la raíz y los checksums del núcleo…',
			'Done. Loading the results…'                  => 'Terminado. Cargando los resultados…',
			'Reviewing… %1$s files in %2$s folders so far.' => 'Revisando… %1$s archivos en %2$s carpetas por ahora.',
			'You are not allowed to do this.'             => 'No tienes permiso para hacer esto.',
			'The results were deleted, but this file could not be removed. Delete it by hand with your file manager: %s' => 'Se han borrado los resultados, pero este archivo no se ha podido eliminar. Bórralo a mano con tu gestor de archivos: %s',
			'Done. The results and the review tool have been deleted from your site.' => 'Hecho. Los resultados y la herramienta de revisión se han eliminado de tu web.',
			'Go to the Dashboard'                         => 'Ir al escritorio',
			'Modified core file'                          => 'Archivo del núcleo modificado',
			'This WordPress file does not match the original of your version.' => 'Este archivo de WordPress no coincide con el original de tu versión.',
			'Replace it with the same file from a clean download of your version. If it is index.php, wp-login.php or wp-settings.php, assume the site is infected.' => 'Sustitúyelo por el mismo archivo de una descarga limpia de tu versión. Si es index.php, wp-login.php o wp-settings.php, da la web por infectada.',
			'PHP file that is not part of WordPress'      => 'PHP que no forma parte de WordPress',
			'There is a PHP file inside wp-admin or wp-includes that your version of WordPress does not include.' => 'Hay un archivo PHP dentro de wp-admin o wp-includes que tu versión de WordPress no incluye.',
			'Open it and read it. If it is not a leftover from an old version, delete it and look for more.' => 'Ábrelo y léelo. Si no es un resto de una versión antigua, bórralo y busca más.',
			'File that is not part of WordPress'          => 'Archivo que no forma parte de WordPress',
			'There is a file inside wp-admin or wp-includes that your version of WordPress does not include.' => 'Hay un archivo dentro de wp-admin o wp-includes que tu versión de WordPress no incluye.',
			'If it is a rules file (.htaccess) or a PHP configuration file, check who created it. Other leftovers are usually harmless.' => 'Si es un archivo de reglas (.htaccess) o de configuración de PHP, comprueba quién lo creó. Otros restos suelen ser inofensivos.',
			'PHP file in uploads'                         => 'PHP en la carpeta de subidas',
			'The uploads folder is for images and documents, not for code.' => 'La carpeta de subidas es para imágenes y documentos, no para código.',
			'Open it. Unless a plugin you know put it there, delete it.' => 'Ábrelo. Salvo que lo haya puesto ahí un plugin que conozcas, bórralo.',
			'Empty index.php in uploads'                  => 'index.php vacío en subidas',
			'A tiny index.php with no code, which some plugins add so the folder cannot be listed.' => 'Un index.php diminuto y sin código, que algunos plugins añaden para que no se pueda listar la carpeta.',
			'Usually harmless.'                           => 'Suele ser inofensivo.',
			'Dangerous function name written backwards'   => 'Nombre de función peligrosa escrito al revés',
			'The code builds names such as base64_decode or gzinflate backwards with strrev(), so that searches for those names do not find it.' => 'El código construye nombres como base64_decode o gzinflate al revés con strrev(), para que las búsquedas de esos nombres no lo encuentren.',
			'Legitimate code has no reason to do this. Open the file: it is very likely malware.' => 'El código legítimo no tiene motivos para hacer esto. Abre el archivo: lo más probable es que sea malware.',
			'Changes its own modification date'           => 'Se cambia su propia fecha de modificación',
			'The file copies the date of another file onto itself with touch(), a trick to go unnoticed when sorting by date.' => 'El archivo se pone la fecha de otro archivo con touch(), un truco para pasar desapercibido al ordenar por fecha.',
			'Legitimate code has no reason to do this. Treat it as malware.' => 'El código legítimo no tiene motivos para hacer esto. Trátalo como malware.',
			'PHP disguised as an image'                   => 'PHP disfrazado de imagen',
			'The file starts with the signature of an image (GIF, PNG or JPEG) but it is PHP.' => 'El archivo empieza con la firma de una imagen (GIF, PNG o JPEG), pero es PHP.',
			'Treat it as malware.'                        => 'Trátalo como malware.',
			'File upload with no control'                 => 'Subida de archivos sin control',
			'The file saves an uploaded file with the name chosen by whoever sends it, with no checks.' => 'El archivo guarda lo que le suben con el nombre que elija quien lo envía, sin ninguna comprobación.',
			'This is a classic backdoor to upload more files. Unless it belongs to a plugin you know, delete it.' => 'Es la puerta trasera clásica para subir más archivos. Salvo que sea de un plugin que conozcas, bórralo.',
			'Typical phishing form fields'                => 'Campos típicos de phishing',
			'The file reads fields such as card number, CVV or national ID numbers from the request.' => 'El archivo lee de la petición campos como el número de tarjeta, el CVV o números de documento de identidad.',
			'Unless it is your own payment form, treat it as a phishing kit.' => 'Salvo que sea tu propio formulario de pago, trátalo como un kit de phishing.',
			'Long encoded line'                           => 'Línea codificada muy larga',
			'A line of thousands of characters that is almost all base64, the usual way to hide a payload.' => 'Una línea de miles de caracteres casi todo en base64, la forma habitual de esconder código malicioso.',
			'Open the file and check what it does with that text.' => 'Abre el archivo y comprueba qué hace con ese texto.',
			'Long encoded data'                           => 'Datos codificados largos',
			'A long base64 text that decodes to serialized data, not to code. Some old themes store default settings this way.' => 'Un texto largo en base64 que, decodificado, son datos serializados y no código. Algunos temas antiguos guardan así sus ajustes de ejemplo.',
			'eval() of a variable'                        => 'eval() de una variable',
			'The file runs code stored in a variable.'    => 'El archivo ejecuta código guardado en una variable.',
			'Some legitimate plugins do this, for example those that let you add code snippets from the Dashboard. If the file belongs to one of them it is normal; if not, open it.' => 'Algunos plugins legítimos lo hacen, por ejemplo los que te dejan añadir fragmentos de código desde el escritorio. Si el archivo es de uno de ellos, es normal; si no, ábrelo.',
			'Redirect to an IP address'                   => 'Redirección a una dirección IP',
			'The file sends visitors to a bare IP address instead of a domain.' => 'El archivo manda a los visitantes a una dirección IP en lugar de a un dominio.',
			'Common in the redirectors of malicious campaigns. Check where it points and why.' => 'Es típico de los redireccionadores de campañas maliciosas. Comprueba adónde apunta y por qué.',
			'Saves request data to an HTML or TXT file'   => 'Guarda datos de la petición en un archivo HTML o TXT',
			'The file appends visit or form data to a .html or .txt file, the way phishing kits store what they steal.' => 'El archivo va añadiendo datos de visitas o formularios a un .html o .txt, como hacen los kits de phishing con lo que roban.',
			'Check what it stores and whether that file can be read from the web.' => 'Comprueba qué guarda y si ese archivo se puede leer desde la web.',
			'PHP configuration that removes restrictions' => 'Configuración de PHP que quita restricciones',
			'This php.ini or .user.ini turns off safe mode, empties the list of disabled functions or allows remote includes.' => 'Este php.ini o .user.ini desactiva el modo seguro, vacía la lista de funciones prohibidas o permite incluir archivos remotos.',
			'Delete it unless you or your hosting created it on purpose.' => 'Bórralo, salvo que lo hayas creado tú o tu hosting a propósito.',
			'PHP configuration that loads a file before every request' => 'Configuración de PHP que carga un archivo antes de cada petición',
			'auto_prepend_file loads another file before any PHP runs.' => 'auto_prepend_file carga otro archivo antes de que se ejecute cualquier PHP.',
			'Some security plugins use it legitimately, for example the Wordfence firewall. Check that the file it loads is one you recognise.' => 'Algunos plugins de seguridad lo usan de forma legítima, por ejemplo el cortafuegos de Wordfence. Comprueba que el archivo que carga es uno que reconoces.',
			'Hand-made country filter'                    => 'Filtro por país hecho a mano',
			'The rules only let in IP addresses that start with certain numbers.' => 'Las reglas solo dejan pasar direcciones IP que empiezan por ciertos números.',
			'Phishing kits use this to show the fraud only to their targets. If you did not write it, delete it.' => 'Los kits de phishing lo usan para enseñar el fraude solo a sus objetivos. Si no lo has escrito tú, bórralo.',
			'PHP file too big to read'                    => 'PHP demasiado grande para leerlo',
			'It was not read because it is larger than 2 MB, which is unusual for a PHP file.' => 'No se ha leído porque ocupa más de 2 MB, algo poco habitual en un archivo PHP.',
			'Check what it is.'                           => 'Comprueba qué es.',
			'Deleting readme.html, license.txt or wp-config-sample.php is a common hardening step and does no harm. If a file of wp-admin or wp-includes is missing, reinstall WordPress from Dashboard > Updates.' => 'Borrar readme.html, license.txt o wp-config-sample.php es una medida de seguridad habitual y no hace daño. Si falta un archivo de wp-admin o wp-includes, reinstala WordPress desde Escritorio > Actualizaciones.',
			'Modified: %s'                                => 'Modificado: %s',
			'Changed on disk: %s'                         => 'Cambiado en disco: %s',
			'Folder in the root: "%s"'                    => 'Carpeta de la raíz: «%s»',
			'wp-content folder'                           => 'Carpeta wp-content',
			'Template cache in uploads'                   => 'Caché de plantillas en subidas',
			'Compiled Twig templates. Some plugins, such as WPML, keep them in the uploads folder. They are PHP, but the plugin writes them itself.' => 'Plantillas Twig compiladas. Algunos plugins, como WPML, las guardan en la carpeta de subidas. Son PHP, pero las escribe el propio plugin.',
			'Normal if you use WPML or another plugin that works with Twig. If you use none, open the folder.' => 'Es normal si usas WPML u otro plugin que trabaje con Twig. Si no usas ninguno, abre la carpeta.',
			'Copy of wp-config.php'                       => 'Copia de wp-config.php',
			'A copy of the configuration file with another extension. The server does not run it as PHP, so it may hand it out as plain text, database password included.' => 'Una copia del archivo de configuración con otra extensión. El servidor no la ejecuta como PHP, así que puede entregarla como texto, contraseña de la base de datos incluida.',
			'Delete it, or move it out of the public folder if you need to keep it. If anyone could have downloaded it, change the database password.' => 'Bórrala, o sácala de la carpeta pública si necesitas conservarla. Si alguien ha podido descargarla, cambia la contraseña de la base de datos.',
			'Files: %s'                                   => 'Archivos: %s',
			'PHP file the web server cannot read'         => 'Archivo PHP que el servidor web no puede leer',
			'Its permissions or its owner do not let the web server open it, so its content was not checked. The server cannot run it either.' => 'Sus permisos o su propietario no dejan que el servidor web lo abra, así que no se ha revisado su contenido. Tampoco puede ejecutarlo.',
			'Check its owner and permissions with your file manager or ask your hosting provider. If you do not recognise it, delete it.' => 'Revisa su propietario y sus permisos con el gestor de archivos o pregunta a tu proveedor de alojamiento. Si no lo reconoces, bórralo.',
			'High'                                        => 'Alto',
			'Medium'                                      => 'Medio',
			'Info'                                        => 'Informativo',
			'Plugin: %s'                                  => 'Plugin: %s',
			'Theme: %s'                                   => 'Tema: %s',
			'Plugins folder (loose files)'                => 'Carpeta de plugins (archivos sueltos)',
			'Themes folder (loose files)'                 => 'Carpeta de temas (archivos sueltos)',
			'Must-use plugins'                            => 'Plugins obligatorios (mu-plugins)',
			'Uploads'                                     => 'Subidas',
			'Translation files'                           => 'Archivos de traducción',
			'Root folder'                                 => 'Carpeta raíz',
			'Other folders'                               => 'Otras carpetas',
			'This tool reviews the files of your WordPress install: core files against the official originals, what sits in the root and is not part of WordPress, PHP inside uploads, the usual malware camouflage tricks and the PHP files changed in the last few days.' => 'Esta herramienta revisa los archivos de tu instalación de WordPress: los del núcleo comparados con los originales oficiales, lo que hay en la raíz y no es de WordPress, los PHP dentro de la carpeta de subidas, los trucos de camuflaje habituales del malware y los PHP cambiados en los últimos días.',
			'It only reads.'                              => 'Solo mira, no toca nada.',
			'It does not delete, move or send anything anywhere, apart from asking WordPress.org for the checksums of your version, and it only runs when you press the button.' => 'No borra, no mueve ni envía nada a ningún sitio, salvo la consulta de los checksums de tu versión a WordPress.org, y solo se ejecuta cuando pulsas el botón.',
			'Recent changes to show:'                     => 'Cambios recientes que mostrar:',
			'Last 24 hours'                               => 'Últimas 24 horas',
			'Last 7 days'                                 => 'Últimos 7 días',
			'Last 30 days'                                => 'Últimos 30 días',
			'Run the review again'                        => 'Repetir la revisión',
			'Start the review'                            => 'Empezar la revisión',
			'Summary'                                     => 'Resumen',
			'Review finished on %1$s: %2$s files in %3$s folders.' => 'Revisión terminada el %1$s: %2$s archivos en %3$s carpetas.',
			'Suspicious items in the root: %s'            => 'Elementos sospechosos en la raíz: %s',
			'The official checksums could not be fetched from WordPress.org, so core files were not compared with the originals. The other checks did run.' => 'No se han podido descargar los checksums oficiales de WordPress.org, así que los archivos del núcleo no se han comparado con los originales. El resto de comprobaciones sí se han hecho.',
			'There were more than %s findings and only the first ones are shown.' => 'Había más de %s avisos y solo se muestran los primeros.',
			'Finding nothing does not mean your site is clean, only that it has nothing this tool looks for.' => 'No encontrar nada no significa que tu web esté limpia, solo que no tiene nada de lo que esta herramienta busca.',
			'Findings'                                    => 'Avisos',
			'No findings.'                                => 'Ningún aviso.',
			'Level'                                       => 'Nivel',
			'File'                                        => 'Archivo',
			'What it is'                                  => 'Qué es',
			'What to do'                                  => 'Qué hacer',
			'Report to copy'                              => 'Informe para copiar',
			'If you are not sure what something means, copy this report and paste it into Claude, ChatGPT or the AI you use, and ask it to explain it. It contains paths, dates and the reason for each finding, never the contents of your files or your user names.' => 'Si no tienes claro qué significa algo, copia este informe, pégalo en Claude, ChatGPT o la IA que uses y pídele que te lo explique. Lleva rutas, fechas y el motivo de cada aviso, nunca el contenido de tus archivos ni tus nombres de usuario.',
			'Copy report'                                 => 'Copiar informe',
			'What is in the root and is not part of WordPress' => 'Lo que hay en la raíz y no es de WordPress',
			'Nothing unexpected in the root.'             => 'Nada inesperado en la raíz.',
			'Some of these may be legitimate (verification files, other applications, a test folder of yours), but each one should have an explanation. A folder you do not recognise with PHP inside is the first thing to open.' => 'Algunos pueden ser legítimos (archivos de verificación, otras aplicaciones, alguna carpeta tuya de pruebas), pero cada uno debería tener una explicación. Una carpeta que no reconoces y tiene PHP dentro es lo primero que hay que abrir.',
			'Name'                                        => 'Nombre',
			'Type'                                        => 'Tipo',
			'Modified'                                    => 'Modificado',
			'Folder with %s PHP files inside'             => 'Carpeta con %s archivos PHP dentro',
			'Folder without PHP'                          => 'Carpeta sin PHP',
			'PHP file'                                    => 'Archivo PHP',
			'PHP files changed in the last %s days'       => 'PHP cambiados en los últimos %s días',
			'None, apart from WordPress core files that match the originals.' => 'Ninguno, aparte de archivos del núcleo que coinciden con los originales.',
			'This uses the date the system recorded the change (ctime), which cannot be faked from PHP. After an update many files of the updated plugin or theme appear here, and that is normal. Look for what does not match any update of yours.' => 'Esto usa la fecha en que el sistema registró el cambio (ctime), que no se puede falsificar desde PHP. Después de una actualización salen aquí muchos archivos del plugin o tema actualizado, y es normal. Fíjate en lo que no cuadre con ninguna actualización tuya.',
			'Many files were changed recently but keep an old modification date. That usually means the site was migrated or a backup was restored, so the list below leaves out the files of plugins and themes.' => 'Muchos archivos han cambiado hace poco pero conservan una fecha de modificación antigua. Eso suele indicar que la web se ha migrado o que se ha restaurado una copia de seguridad, así que la lista de abajo deja fuera los archivos de plugins y temas.',
			'Where'                                       => 'Dónde',
			'PHP files changed'                           => 'PHP cambiados',
			'Files outside plugins and themes, and files changed recently that show an old modification date:' => 'Archivos fuera de plugins y temas, y archivos cambiados hace poco que muestran una fecha de modificación antigua:',
			'Files outside plugins and themes:'           => 'Archivos fuera de plugins y temas:',
			'Note: many files keep an old modification date, which usually means a migration or a restored backup. Plugin and theme files are not listed.' => 'Nota: muchos archivos conservan una fecha de modificación antigua, lo que suele indicar una migración o una copia de seguridad restaurada. No se listan los archivos de plugins y temas.',
			'Changed on disk'                             => 'Cambiado en disco',
			'Modified (as shown in listings)'             => 'Modificado (lo que se ve en los listados)',
			'Highlighted rows were changed recently but show a modification date more than 30 days older. After a migration or a restored backup that is normal; otherwise it is the trace left by the date trick.' => 'Las filas marcadas han cambiado hace poco, pero muestran una fecha de modificación más de 30 días anterior. Después de una migración o de restaurar una copia es normal; si no, es el rastro que deja el truco de la fecha.',
			'Administrators'                              => 'Administradores',
			'Check that you recognise all of them. An administrator created around the date of a suspicious file deserves a close look.' => 'Comprueba que los reconoces a todos. Un administrador creado en torno a la fecha de un archivo sospechoso merece una mirada a fondo.',
			'User'                                        => 'Usuario',
			'Created'                                     => 'Alta',
			'This is the report of a file review of a WordPress site. Explain what each finding means and what I should do, starting with the most serious ones.' => 'Este es el informe de una revisión de archivos de una web WordPress. Explícame qué significa cada aviso y qué debería hacer, empezando por los más graves.',
			'AyudaWP File Review %1$s, %2$s'              => 'Revisión de archivos de AyudaWP %1$s, %2$s',
			'WordPress %1$s, PHP %2$s'                    => 'WordPress %1$s, PHP %2$s',
			'Files reviewed: %1$s in %2$s folders'        => 'Archivos revisados: %1$s en %2$s carpetas',
			'Core files: NOT compared (the official checksums could not be fetched)' => 'Archivos del núcleo: NO comparados (no se han podido descargar los checksums oficiales)',
			'Core files: compared with the official checksums of WordPress.org' => 'Archivos del núcleo: comparados con los checksums oficiales de WordPress.org',
			'modified %1$s, changed on disk %2$s'         => 'modificado %1$s, cambiado en disco %2$s',
			'In the root and not part of WordPress'       => 'En la raíz y no es de WordPress',
			'Core files missing: %s'                      => 'Archivos del núcleo que faltan: %s',
			'changed on disk %1$s, modified %2$s'         => 'cambiado en disco %1$s, modificado %2$s',
			'Administrator %1$s, created %2$s'            => 'Administrador %1$s, alta %2$s',
			'When you are done'                           => 'Cuando acabes',
			'This is a one-off review tool, not something to keep installed. This button deletes the stored results and the tool itself.' => 'Es una herramienta de revisión puntual, no algo para tener instalado. Este botón borra los resultados guardados y la propia herramienta.',
			'Yes, delete the results and this tool'       => 'Sí, borrar los resultados y esta herramienta',
			'Delete the review tool'                      => 'Eliminar la herramienta de revisión',
		);

		return $strings;
	}
}

Ayudawp_File_Review::init();

// Only fires when it is installed as a regular plugin: mu-plugins are never activated.
register_activation_hook( __FILE__, array( 'Ayudawp_File_Review', 'activate' ) );
