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

if ( ! class_exists( 'Elementor_Edu_Course_V3', false ) ) {
	final class Elementor_Edu_Course_V3 {
		private const RECORD = 'elementor_edu_course_record';
		private const INTERVAL = 2592000;
		private const EVENT = 'elementor_edu_course_check';
		private const PRO_URL = 'https://elementor.com/pro/';
		private static $booted = false;

		public static function boot() {
			if ( self::$booted ) {
				return;
			}
			self::$booted = true;
			add_action( 'init', [ __CLASS__, 'load_translations' ], -110 );
			add_action( 'admin_notices', [ __CLASS__, 'notice' ] );
			add_action( 'network_admin_notices', [ __CLASS__, 'notice' ] );
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
			if ( ! $record ) {
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
			$contents = file_get_contents( $source );
			if ( false === $contents ) {
				return 'The Elementor Edu package is incomplete. Reinstall the complete ZIP file.';
			}
			if ( is_file( $target ) ) {
				$current = file_get_contents( $target );
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
			$temp = tempnam( WPMU_PLUGIN_DIR, 'edu-' );
			if ( false === $temp ) {
				return 'Could not set up Elementor Edu educational notices. Check the mu-plugins directory permissions.';
			}
			$written = file_put_contents( $temp, $contents, LOCK_EX );
			if ( strlen( $contents ) !== $written || ! rename( $temp, $target ) ) {
				@unlink( $temp );
				return 'Could not save Elementor Edu educational notices. Check the mu-plugins directory permissions.';
			}
			@chmod( $target, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 );
			return '';
		}

		public static function expired() {
			$record = self::record();
			if ( ! $record ) {
				return false;
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
			return '<p><strong>' . esc_html__( 'Elementor: educational use only.', 'elementor-edu' ) . '</strong> '
				. esc_html__( 'This copy will keep working, but its update period has ended. Elementor updates will no longer be checked for or installed through this copy.', 'elementor-edu' ) . '</p><p>'
				/* translators: %s: Link to the official Elementor Pro website. */
				. sprintf( esc_html__( 'Please deactivate this copy and replace it with an official installation of %s. Switch to the official version of Elementor Pro to keep receiving Elementor updates.', 'elementor-edu' ), $link ) . '</p>';
		}

		public static function load_translations() {
			foreach ( self::record()['plugins'] ?? [] as $plugin ) {
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
			if ( ! $record ) {
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
				echo '<div class="notice notice-warning elementor-edu-educational-notice">' . wp_kses_post( self::expiration_message() ) . '</div>';
				return;
			}
			echo '<div class="notice notice-warning elementor-edu-educational-notice"><p><strong>' . esc_html__( 'Elementor Edu – Educational use only.', 'elementor-edu' ) . '</strong> '
				. esc_html__( 'This plugin is intended only for course demonstrations and exercises. It must not be used outside activities directly related to the course.', 'elementor-edu' ) . '</p></div>';
		}
	}
}

Elementor_Edu_Course_V3::boot();
