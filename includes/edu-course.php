<?php
/**
 * Plugin Name: Elementor Edu – Educational notice
 * Description: Maintains educational notices and usage conditions for Elementor Edu.
 * Text Domain: elementor-edu
 *
 * Elementor Edu course monitor. Retained outside the regular plugin on removal.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extend the original deadline without restarting a site's course window.
 * This runs before boot so it can also repair a V3 monitor loaded earlier in
 * the same request.
 */
if ( ! function_exists( 'elementor_edu_course_extend_existing_window' ) ) {
	function elementor_edu_course_extend_existing_window() {
		$record_key = 'elementor_edu_course_record';
		$record = is_multisite()
			? get_network_option( get_main_network_id(), $record_key, [] )
			: get_option( $record_key, [] );
		if ( ! is_array( $record ) || ! isset( $record['installed_at'], $record['expires_at'] ) || ! is_numeric( $record['installed_at'] ) || ! is_numeric( $record['expires_at'] ) || (int) $record['installed_at'] < 1 ) {
			return;
		}
		$installed = (int) $record['installed_at'];
		if ( (int) ( $record['expires_at'] ?? 0 ) !== $installed + 2592000 ) {
			return;
		}
		$record['expires_at'] = $installed + 7776000;
		$record['expired'] = time() >= $record['expires_at'];
		if ( is_multisite() ) {
			update_network_option( get_main_network_id(), $record_key, $record );
		} else {
			update_option( $record_key, $record, false );
		}
		wp_clear_scheduled_hook( 'elementor_edu_course_check' );
		if ( ! $record['expired'] ) {
			wp_schedule_single_event( (int) $record['expires_at'], 'elementor_edu_course_check' );
		}
	}
}

elementor_edu_course_extend_existing_window();

if ( ! class_exists( 'Elementor_Edu_Course_V3', false ) ) {
	final class Elementor_Edu_Course_V3 {
		private const RECORD = 'elementor_edu_course_record';
		private const INTERVAL = 7776000;
		private const EVENT = 'elementor_edu_course_check';
		private const PRO_URL = 'https://elementor.com/pro/';
		private const DISMISSAL_META = 'elementor_edu_expiration_notice_dismissed_until';
		private static $booted = false;

		public static function boot() {
			if ( self::$booted ) {
				return;
			}
			self::$booted = true;
			add_action( 'init', [ __CLASS__, 'load_translations' ], -110 );
			add_action( 'admin_notices', [ __CLASS__, 'notice' ] );
			add_action( 'network_admin_notices', [ __CLASS__, 'notice' ] );
			add_action( 'admin_footer', [ __CLASS__, 'expiration_notice_script' ] );
			add_action( 'network_admin_footer', [ __CLASS__, 'expiration_notice_script' ] );
			add_action( 'wp_ajax_elementor_edu_dismiss_expiration_notice', [ __CLASS__, 'ajax_dismiss_expiration_notice' ] );
			add_action( self::EVENT, [ __CLASS__, 'enforce' ] );
			// The former MU monitor may already be loaded in this request.
			foreach ( [ 'admin_notices' => 'notice', 'network_admin_notices' => 'notice', self::EVENT => 'enforce' ] as $hook => $method ) {
				remove_action( $hook, [ 'Elementor_Edu_Course', $method ] );
				remove_action( $hook, [ 'Elementor_Edu_Course_V2', $method ] );
			}
			remove_action( 'activate_plugin', [ 'Elementor_Edu_Course', 'check_activation' ], -100 );
			remove_filter( 'pre_update_option_active_plugins', [ 'Elementor_Edu_Course', 'filter_activation' ], PHP_INT_MAX );
			remove_filter( 'pre_update_site_option_active_sitewide_plugins', [ 'Elementor_Edu_Course', 'filter_network_activation' ], PHP_INT_MAX );
			self::enforce();
		}

		private static function record() {
			return is_multisite()
				? get_network_option( get_main_network_id(), self::RECORD, [] )
				: get_option( self::RECORD, [] );
		}

		/** Reject damaged database state without replacing it or restarting its deadline. */
		private static function record_is_valid( $record ) {
			if ( ! is_array( $record ) || ! isset( $record['installed_at'], $record['expires_at'], $record['plugins'] ) ) {
				return false;
			}
			if ( ! is_numeric( $record['installed_at'] ) || ! is_numeric( $record['expires_at'] ) || (int) $record['installed_at'] < 1 || (int) $record['expires_at'] < (int) $record['installed_at'] ) {
				return false;
			}
			if ( ! is_array( $record['plugins'] ) ) {
				return false;
			}
			foreach ( $record['plugins'] as $plugin ) {
				if ( ! is_string( $plugin ) || '' === $plugin || '/' === $plugin[0] || false !== strpos( $plugin, '..' ) || false !== strpos( $plugin, '\\' ) || false !== strpos( $plugin, "\0" ) ) {
					return false;
				}
			}
			return true;
		}

		private static function save( array $record, $create = false ) {
			if ( is_multisite() ) {
				return $create
					? add_network_option( get_main_network_id(), self::RECORD, $record )
					: update_network_option( get_main_network_id(), self::RECORD, $record );
			}
			return $create ? add_option( self::RECORD, $record, '', false ) : update_option( self::RECORD, $record, false );
		}

		/** Register once; replacing or reinstalling the plugin never resets the record. */
		public static function prepare( $plugin_file ) {
			$record = self::record();
			$base = plugin_basename( $plugin_file );
			if ( [] !== $record && null !== $record && false !== $record && ! self::record_is_valid( $record ) ) {
				return 'The Elementor Edu educational settings are damaged. Existing settings were preserved; ask the site administrator to restore the database record.';
			}
			if ( [] === $record || null === $record || false === $record ) {
				// WP does not execute inactive plugins at upload time. Capture the
				// installed file's filesystem timestamp when it first executes.
				$installed = filectime( $plugin_file );
				$installed = $installed > 0 && $installed <= time() ? $installed : time();
				self::save( [
					'installed_at' => $installed,
					'expires_at' => $installed + self::INTERVAL,
					'expired' => false,
					'plugins' => [ $base ],
				], true );
				$record = self::record();
				if ( ! $record ) {
					return 'Could not save the Elementor Edu educational settings. Check the WordPress database.';
				}
			}
			if ( ! in_array( $base, $record['plugins'], true ) ) {
				$record['plugins'][] = $base;
				self::save( $record );
			}
			self::enforce();
			$error = self::install_monitor( dirname( $plugin_file ) . '/includes/edu-course.php' );
			self::schedule();
			return $error;
		}

		private static function install_monitor( $source ) {
			$target = WPMU_PLUGIN_DIR . '/elementor-edu-course.php';
			$contents = @file_get_contents( $source );
			if ( false === $contents ) {
				return 'The Elementor Edu package is incomplete. Reinstall the complete ZIP file.';
			}
			if ( is_file( $target ) ) {
				$current = @file_get_contents( $target );
				if ( $current === $contents ) {
					return '';
				}
				if ( false === $current || false === strpos( $current, 'Elementor Edu course monitor.' ) ) {
					return 'Could not set up educational notices: an incompatible file exists at mu-plugins/elementor-edu-course.php.';
				}
			}
			if ( ! wp_mkdir_p( WPMU_PLUGIN_DIR ) || ! is_writable( WPMU_PLUGIN_DIR ) ) {
				return 'Elementor Edu needs write access to the WordPress mu-plugins directory to maintain educational notices.';
			}
			$temp = @tempnam( WPMU_PLUGIN_DIR, 'edu-' );
			if ( false === $temp ) {
				return 'Could not set up Elementor Edu educational notices. Check the mu-plugins directory permissions.';
			}
			$written = @file_put_contents( $temp, $contents, LOCK_EX );
			if ( strlen( $contents ) !== $written || ! @rename( $temp, $target ) ) {
				@unlink( $temp );
				return 'Could not save Elementor Edu educational notices. Check the mu-plugins directory permissions.';
			}
			@chmod( $target, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 );
			return '';
		}

		public static function expired() {
			$record = self::record();
			if ( [] === $record || null === $record || false === $record ) {
				return false;
			}
			if ( ! self::record_is_valid( $record ) ) {
				// Keep all editing and rendering available, but fail closed for updates.
				return true;
			}
			if ( ! empty( $record['expired'] ) ) {
				return true;
			}
			if ( time() >= (int) $record['expires_at'] ) {
				$record['expired'] = true;
				self::save( $record );
				return true;
			}
			return false;
		}

		private static function schedule() {
			$record = self::record();
			if ( $record && ! self::expired() && ! wp_next_scheduled( self::EVENT ) ) {
				wp_schedule_single_event( (int) $record['expires_at'], self::EVENT );
			}
		}

		/** Expiry stops updates, never the engines or plugin activation. */
		public static function enforce() {
			if ( ! self::expired() ) {
				self::schedule();
				return;
			}
			wp_clear_scheduled_hook( self::EVENT );
			wp_clear_scheduled_hook( 'elementor_edu_daily_update_check' );
			wp_clear_scheduled_hook( 'elementor_edu_translation_refresh' );
			delete_site_option( 'elementor_edu_available_update' );
			$prepared = get_site_transient( 'elementor_edu_prepared_update' );
			if ( is_array( $prepared ) && ! empty( $prepared['path'] ) ) {
				$path = realpath( $prepared['path'] );
				$directory = realpath( WP_CONTENT_DIR . '/upgrade' );
				if ( $path && $directory && 0 === strpos( $path, $directory . DIRECTORY_SEPARATOR ) && is_file( $path ) ) {
					@unlink( $path );
				}
			}
			delete_site_transient( 'elementor_edu_prepared_update' );
		}

		public static function updates_allowed() {
			if ( self::expired() ) {
				self::enforce();
				return false;
			}
			return true;
		}

		private static function expiration_message() {
			$link = '<a href="' . esc_url( self::PRO_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Elementor Pro from the official website', 'elementor-edu' ) . '</a>';
			/* translators: %s: Link to the official Elementor Pro website. */
			return '<p>' . sprintf( esc_html__( 'The update period for this educational copy has ended. Elementor Edu will continue to work normally. If you would like to keep receiving updates, you can install the official %s.', 'elementor-edu' ), $link ) . '</p>';
		}

		private static function notice_dismissed() {
			$until = (int) get_user_meta( get_current_user_id(), self::DISMISSAL_META, true );
			return $until > time();
		}

		/** Store a per-user dismissal deadline; exposed for the authenticated AJAX handler. */
		public static function store_notice_dismissal( $user_id ) {
			$user_id = absint( $user_id );
			if ( ! $user_id ) {
				return false;
			}
			return update_user_meta( $user_id, self::DISMISSAL_META, time() + DAY_IN_SECONDS );
		}

		public static function ajax_dismiss_expiration_notice() {
			check_ajax_referer( 'elementor_edu_dismiss_expiration_notice' );
			if ( ! current_user_can( 'read' ) ) {
				wp_send_json_error( [], 403 );
			}
			self::store_notice_dismissal( get_current_user_id() );
			wp_send_json_success();
		}

		public static function expiration_notice_script() {
			if ( ! self::expired() || self::notice_dismissed() ) {
				return;
			}
			$ajax_url = wp_json_encode( admin_url( 'admin-ajax.php' ) );
			$nonce = wp_json_encode( wp_create_nonce( 'elementor_edu_dismiss_expiration_notice' ) );
			echo '<script>(function(){var n=document.querySelector(".elementor-edu-educational-notice.is-dismissible");if(!n)return;n.addEventListener("click",function(e){if(!e.target.closest(".notice-dismiss"))return;var d=new URLSearchParams({action:"elementor_edu_dismiss_expiration_notice",_ajax_nonce:' . $nonce . '});fetch(' . $ajax_url . ',{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded;charset=UTF-8"},body:d.toString()});});})();</script>';
		}

		public static function load_translations() {
			$record = self::record();
			$plugins = self::record_is_valid( $record ) ? $record['plugins'] : [];
			foreach ( $plugins as $plugin ) {
				$root = dirname( WP_PLUGIN_DIR . '/' . $plugin );
				if ( is_readable( $root . '/includes/edu-i18n.php' ) ) {
					require_once $root . '/includes/edu-i18n.php';
					Elementor_Edu_I18n::boot( $root );
					return;
				}
			}
		}

		public static function notice() {
			$record = self::record();
			if ( ! self::record_is_valid( $record ) ) {
				return;
			}
			$installed = false;
			foreach ( $record['plugins'] as $plugin ) {
				if ( is_file( WP_PLUGIN_DIR . '/' . $plugin ) ) {
					$installed = true;
					break;
				}
			}
			if ( ! $installed ) {
				return;
			}
			if ( self::expired() ) {
				if ( self::notice_dismissed() ) {
					return;
				}
				echo '<div class="notice notice-info is-dismissible elementor-edu-educational-notice">' . wp_kses_post( self::expiration_message() ) . '</div>';
				return;
			}
			echo '<div class="notice notice-warning elementor-edu-educational-notice"><p><strong>' . esc_html__( 'Elementor Edu – Educational use only.', 'elementor-edu' ) . '</strong> '
				. esc_html__( 'This plugin was developed exclusively for educational and training purposes. It must never be used outside activities strictly related to education.', 'elementor-edu' ) . '</p></div>';
		}
	}
}

Elementor_Edu_Course_V3::boot();
