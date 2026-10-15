<?php
/**
 * Plugin Name: Elementor Edu – Avviso educativo
 * Description: Mantiene gli avvisi e le condizioni di utilizzo didattico di Elementor Edu.
 *
 * Elementor Edu course monitor. Retained outside the regular plugin on removal.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Elementor_Edu_Course_V2', false ) ) {
	final class Elementor_Edu_Course_V2 {
		private const RECORD = 'elementor_edu_course_record';
		private const INTERVAL = 2592000;
		private const EVENT = 'elementor_edu_course_check';
		private const PRO_URL = 'https://elementor.com/it/pro/';
		private static $booted = false;

		public static function boot() {
			if ( self::$booted ) {
				return;
			}
			self::$booted = true;
			add_action( 'admin_notices', [ __CLASS__, 'notice' ] );
			add_action( 'network_admin_notices', [ __CLASS__, 'notice' ] );
			add_action( self::EVENT, [ __CLASS__, 'enforce' ] );
			// The former MU monitor may already be loaded in this request.
			foreach ( [ 'admin_notices' => 'notice', 'network_admin_notices' => 'notice', self::EVENT => 'enforce' ] as $hook => $method ) {
				remove_action( $hook, [ 'Elementor_Edu_Course', $method ] );
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
					return 'Impossibile salvare la configurazione didattica di Elementor Edu. Verifica il database di WordPress.';
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
				return 'Il pacchetto Elementor Edu è incompleto. Reinstalla lo ZIP completo.';
			}
			if ( is_file( $target ) ) {
				$current = file_get_contents( $target );
				if ( $current === $contents ) {
					return '';
				}
				if ( false === $current || false === strpos( $current, 'Elementor Edu course monitor.' ) ) {
					return 'Impossibile predisporre gli avvisi didattici: esiste un file incompatibile in mu-plugins/elementor-edu-course.php.';
				}
			}
			if ( ! wp_mkdir_p( WPMU_PLUGIN_DIR ) || ! is_writable( WPMU_PLUGIN_DIR ) ) {
				return 'Elementor Edu richiede che la cartella mu-plugins di WordPress sia scrivibile per mantenere gli avvisi didattici.';
			}
			$temp = tempnam( WPMU_PLUGIN_DIR, 'edu-' );
			if ( false === $temp ) {
				return 'Impossibile predisporre gli avvisi didattici di Elementor Edu. Verifica i permessi della cartella mu-plugins.';
			}
			$written = file_put_contents( $temp, $contents, LOCK_EX );
			if ( strlen( $contents ) !== $written || ! rename( $temp, $target ) ) {
				@unlink( $temp );
				return 'Impossibile salvare gli avvisi didattici di Elementor Edu. Verifica i permessi della cartella mu-plugins.';
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
			return '<p><strong>Elementor: copia a uso puramente didattico.</strong> Questa copia continua a funzionare, ma il periodo previsto per ricevere gli aggiornamenti è terminato. Da questo momento non vengono più cercati né installati aggiornamenti di Elementor tramite questa copia.</p>'
				. '<p>Ti invitiamo a disattivarla e a sostituirla con un’installazione regolare di <a href="' . esc_url( self::PRO_URL ) . '" target="_blank" rel="noopener noreferrer">Elementor Pro dal sito ufficiale</a>. Per continuare a ricevere gli aggiornamenti di Elementor, passa alla versione ufficiale di Elementor Pro.</p>';
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
			echo '<div class="notice notice-warning elementor-edu-educational-notice"><p><strong>Elementor Edu – Utilizzo esclusivamente educativo.</strong> '
				. 'Questo plugin è destinato esclusivamente alle dimostrazioni e alle esercitazioni del corso. Non deve in nessun caso essere utilizzato al di fuori delle attività strettamente legate al corso.</p></div>';
		}
	}
}

Elementor_Edu_Course_V2::boot();
