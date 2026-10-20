<?php
/**
 * Elementor Edu course lifecycle coordinator.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extend the original deadline without restarting a site's course window.
 * This runs before boot so it can also repair an older monitor loaded earlier
 * in the same request.
 */
if ( ! function_exists( 'elementor_edu_course_extend_existing_window_v4' ) ) {
	function elementor_edu_course_extend_existing_window_v4() {
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

elementor_edu_course_extend_existing_window_v4();

if ( ! class_exists( 'Elementor_Edu_Course_V4', false ) ) {
	final class Elementor_Edu_Course_V4 {
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
				foreach ( [ 'Elementor_Edu_Course', 'Elementor_Edu_Course_V2', 'Elementor_Edu_Course_V3' ] as $legacy_class ) {
					remove_action( $hook, [ $legacy_class, $method ] );
				}
			}
			foreach ( [ 'Elementor_Edu_Course', 'Elementor_Edu_Course_V2', 'Elementor_Edu_Course_V3' ] as $legacy_class ) {
				remove_action( 'activate_plugin', [ $legacy_class, 'check_activation' ], -100 );
				remove_filter( 'pre_update_option_active_plugins', [ $legacy_class, 'filter_activation' ], PHP_INT_MAX );
				remove_filter( 'pre_update_site_option_active_sitewide_plugins', [ $legacy_class, 'filter_network_activation' ], PHP_INT_MAX );
			}
			self::remove_legacy_monitor();
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

		/** Register once; normal updates and deactivation preserve the deadline. */
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
			self::schedule();
			return self::remove_legacy_monitor();
		}

		/** Remove only the known monitor left by earlier Elementor Edu releases. */
		public static function remove_legacy_monitor() {
			if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
				return '';
			}
			$target = trailingslashit( WPMU_PLUGIN_DIR ) . 'elementor-edu-course.php';
			if ( ! file_exists( $target ) && ! is_link( $target ) ) {
				return '';
			}
			if ( is_link( $target ) || ! is_file( $target ) ) {
				return '';
			}
			$contents = @file_get_contents( $target );
			if ( false === $contents || false === strpos( $contents, 'Elementor Edu course monitor.' ) || false === strpos( $contents, 'Plugin Name: Elementor Edu' ) ) {
				return '';
			}
			if ( @unlink( $target ) || ! file_exists( $target ) ) {
				return '';
			}
			return 'The old Elementor Edu MU monitor could not be removed from mu-plugins. Elementor Edu no longer uses this file; check its permissions and remove only the obsolete Elementor Edu monitor.';
		}

		/** Keep the stored deadline on deactivation while dropping its inactive cron event. */
		public static function deactivate() {
			wp_clear_scheduled_hook( self::EVENT );
			self::remove_legacy_monitor();
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

Elementor_Edu_Course_V4::boot();
