<?php
/**
 * Plugin Name: Elementor Edu
 * Description: Installs, manages, and keeps Elementor and Elementor Pro up to date for educational use, providing the full functionality of a standard Elementor Pro installation.
 * Version: 4.3.2.23
 * Author: Elementor.com
 * Requires PHP: 7.4
 * Requires at least: 6.8
 * Text Domain: elementor-edu
 * Domain Path: /languages/elementor-edu
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Update URI: false
 *
 * The bundled engines are by Elementor Ltd. This is an altered distribution.
 * Original copyright notices and licenses are retained in the bundle.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ELEMENTOR_EDU_VERSION', '4.3.2.23' );
define( 'ELEMENTOR_EDU__FILE__', __FILE__ );
define( 'ELEMENTOR_EDU_PLUGIN_BASE', plugin_basename( __FILE__ ) );

/** Coordinates separate Elementor plugins and educational settings. */
final class Elementor_Edu {

	private static $error = '';
	private static $error_args = [];
	private static $maintenance_warning = '';
	private const ORIGINAL_PLUGINS = [ 'elementor/elementor.php', 'elementor-pro/elementor-pro.php' ];

	/** Remove only Elementor Safe Mode leftovers from an older Edu installation. */
	private static function cleanup_legacy_safe_mode() {
		if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
			$file = trailingslashit( WPMU_PLUGIN_DIR ) . 'elementor-safe-mode.php';
			if ( is_file( $file ) && ! is_link( $file ) ) {
				$contents = @file_get_contents( $file );
				if ( false !== $contents && false !== strpos( $contents, 'Plugin Name: Elementor Safe Mode' ) && false !== strpos( $contents, 'class Safe_Mode' ) ) {
					@unlink( $file );
				}
			}
		}

		delete_option( 'elementor_safe_mode' );
		delete_option( 'elementor_safe_mode_allowed_plugins' );
		delete_option( 'elementor_safe_mode_created_mu_dir' );
		delete_option( 'elementor_safe_mode_token' );
		delete_option( 'theme_mods_elementor-safe' );
	}

	public static function boot() {
		// MU-plugins load before regular plugins. Remove the known upstream
		// Safe Mode remnant early so it cannot affect subsequent requests.
		self::cleanup_legacy_safe_mode();

		// Validate before the activation callbacks registered by the engines.
		register_activation_hook( ELEMENTOR_EDU__FILE__, [ __CLASS__, 'activate' ] );

		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			self::$error = 'Elementor Edu requires PHP %s or later.';
			self::$error_args = [ '7.4' ];
		} elseif ( version_compare( get_bloginfo( 'version' ), '6.8', '<' ) ) {
			self::$error = 'Elementor Edu requires WordPress %s or later.';
			self::$error_args = [ '6.8' ];

		}

		// Validate all bootstrap files before requiring any of them or creating
		// the persistent record. Interrupted uploads must produce a useful error.
		foreach ( [
			'edu-i18n.php', 'edu-course.php', 'edu-hello-theme.php',
			'edu-updater.php', 'edu-translation-updater.php', 'edu-translation-catalog.php',
			'edu-altervista.php', 'edu-editor-promotions.php', 'edu-engines.php',
			'elementor/elementor.php', 'elementor/includes/plugin.php',
			'elementor-pro/elementor-pro.php', 'elementor-pro/plugin.php',
		] as $file ) {
			if ( ! self::$error && ! is_readable( __DIR__ . '/includes/' . $file ) ) {
				self::$error = 'The Elementor Edu package is incomplete. Reinstall the complete ZIP file.';
			}
		}

		if ( ! self::$error ) {
			require_once __DIR__ . '/includes/edu-i18n.php';
			Elementor_Edu_I18n::boot( __DIR__ );
			require_once __DIR__ . '/includes/edu-course.php';
			register_deactivation_hook( ELEMENTOR_EDU__FILE__, [ Elementor_Edu_Course_V4::class, 'deactivate' ] );
			self::$maintenance_warning = Elementor_Edu_Course_V4::prepare( ELEMENTOR_EDU__FILE__ );
			if ( self::$maintenance_warning ) {
				add_action( 'admin_notices', [ __CLASS__, 'maintenance_notice' ] );
				add_action( 'network_admin_notices', [ __CLASS__, 'maintenance_notice' ] );
			}
		}

		if ( self::$error ) {
			add_action( 'admin_notices', [ __CLASS__, 'notice' ] );
			add_action( 'network_admin_notices', [ __CLASS__, 'notice' ] );
			return;
		}

		// Standard plugin files provide their own identities and lifecycle hooks.
		require_once __DIR__ . '/includes/edu-engines.php';
		Elementor_Edu_Engines::boot();
		require_once __DIR__ . '/includes/edu-hello-theme.php';
		require_once __DIR__ . '/includes/edu-updater.php';
		require_once __DIR__ . '/includes/edu-translation-updater.php';
		require_once __DIR__ . '/includes/edu-altervista.php';
		require_once __DIR__ . '/includes/edu-editor-promotions.php';
		Elementor_Edu_Hello_Theme::boot();
		Elementor_Edu_Updater::boot();
		Elementor_Edu_Translation_Updater::boot();
		Elementor_Edu_Altervista::boot();
		Elementor_Edu_Editor_Promotions::boot();
		// Elementor's activation transient redirects administrators into its setup wizard.
		// Mark onboarding complete persistently and clear the redirect on every load.
		add_action( 'admin_init', [ __CLASS__, 'suppress_onboarding' ], PHP_INT_MIN );
		add_filter( 'elementor/onboarding/steps', '__return_empty_array', PHP_INT_MAX );
		// Educational installations do not collect or transmit Elementor usage telemetry.
		update_option( 'elementor_allow_tracking', 'no', false );
		// Altervista tracking stays off in every integration mode.
		update_option( 'av_disable_audience_tracking', '1', false );
		update_option( 'av_disable_google_analytics', '1', false );
		add_filter( 'pre_update_option_elementor_allow_tracking', [ __CLASS__, 'disable_tracking' ], PHP_INT_MAX );

		add_filter( 'pre_set_site_transient_update_plugins', [ __CLASS__, 'filter_updates' ], PHP_INT_MAX );
		add_filter( 'site_transient_update_plugins', [ __CLASS__, 'filter_updates' ], PHP_INT_MAX );
		add_filter( 'auto_update_plugin', [ __CLASS__, 'auto_update' ], PHP_INT_MAX, 2 );
		add_filter( 'plugin_auto_update_setting_html', [ __CLASS__, 'auto_update_html' ], PHP_INT_MAX, 2 );
		add_filter( 'plugin_action_links_' . ELEMENTOR_EDU_PLUGIN_BASE, [ __CLASS__, 'settings_link' ] );
		add_filter( 'all_plugins', [ __CLASS__, 'filter_edu_plugin_metadata' ], PHP_INT_MAX );
		add_action( 'admin_post_elementor_rollback', [ __CLASS__, 'block_rollback' ], -100 );
		add_action( 'admin_post_elementor_pro_rollback', [ __CLASS__, 'block_rollback' ], -100 );
		add_filter( 'elementor/admin/show_beta_tester', '__return_false' );
	}

	public static function suppress_onboarding() {
		delete_transient( 'elementor_activation_redirect' );
		if ( ! get_option( 'elementor_onboarded' ) ) {
			update_option( 'elementor_onboarded', 'elementor-edu', false );
		}
		update_option( 'av_disable_audience_tracking', '1', false );
		update_option( 'av_disable_google_analytics', '1', false );
	}

	public static function settings_link( $links ) {
		if ( current_user_can( 'manage_options' ) ) {
			$url = admin_url( ( defined( 'ELEMENTOR_VERSION' ) ? 'admin.php' : 'options-general.php' ) . '?page=elementor-edu-settings' );
			$settings = [ 'edu_settings' => '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'elementor-edu' ) . '</a>' ];
			$links = $settings + $links;
		}
		return $links;
	}

	/** Keep only the version in Edu's plugin-row metadata. */
	public static function filter_edu_plugin_metadata( $plugins ) {
		if ( isset( $plugins[ ELEMENTOR_EDU_PLUGIN_BASE ] ) && is_array( $plugins[ ELEMENTOR_EDU_PLUGIN_BASE ] ) ) {
			$plugins[ ELEMENTOR_EDU_PLUGIN_BASE ]['Author'] = '';
		}
		return $plugins;
	}

	public static function disable_tracking( $value ) {
		return 'no';
	}

	public static function activate( $network_wide ) {
		if ( self::$error ) {
			wp_die( esc_html( self::error_message() ), 'Elementor Edu', [ 'back_link' => true ] );
		}
		if ( class_exists( 'Elementor_Edu_Altervista' ) ) {
			Elementor_Edu_Altervista::apply_activation_policy( $network_wide );
		}
	}

	private static function error_message() {
		// Delayed until the user locale and translations are available.
		$message = translate( self::$error, 'elementor-edu' );
		return self::$error_args ? vsprintf( $message, self::$error_args ) : $message;
	}

	public static function notice() {
		echo '<div class="notice notice-error"><p><strong>Elementor Edu:</strong> ' . esc_html( self::error_message() ) . '</p></div>';
	}

	/** Convert recoverable PHP errors during plugin boot into an admin notice. */
	public static function handle_bootstrap_failure( Throwable $error ) {
		self::$error = 'The Elementor Edu package is incomplete. Reinstall the complete ZIP file.';
		if ( function_exists( 'error_log' ) ) {
			error_log( 'Elementor Edu could not complete plugin startup: ' . $error->getMessage() );
		}
		add_action( 'admin_notices', [ __CLASS__, 'notice' ] );
		add_action( 'network_admin_notices', [ __CLASS__, 'notice' ] );
	}

	public static function maintenance_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		if ( 'The Elementor Edu educational settings are damaged. Existing settings were preserved; ask the site administrator to restore the database record.' === self::$maintenance_warning ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Elementor Edu found damaged educational settings. It preserved the stored data and paused update checks; Elementor and Elementor Pro remain available. Ask the site administrator to restore the database record.', 'elementor-edu' ) . '</p></div>';
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Elementor Edu is active, but one maintenance task needs attention:', 'elementor-edu' ) . ' ' . esc_html( translate( self::$maintenance_warning, 'elementor-edu' ) ) . '</p></div>';
	}

	/** Never install a single upstream engine over the combined plugin. */
	public static function filter_updates( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$files = [
			ELEMENTOR_EDU_PLUGIN_BASE,
			'elementor/elementor.php', 'elementor-pro/elementor-pro.php',
			plugin_basename( __DIR__ . '/includes/elementor/elementor.php' ),
			plugin_basename( __DIR__ . '/includes/elementor-pro/elementor-pro.php' ),
		];
		foreach ( [ 'response', 'no_update' ] as $property ) {
			foreach ( $files as $file ) {
				if ( ELEMENTOR_EDU_PLUGIN_BASE === $file && Elementor_Edu_Updater::has_prepared_update( $transient ) ) {
					continue;
				}
				unset( $transient->{$property}[ $file ] );
			}
		}
		return $transient;
	}

	public static function auto_update( $update, $item ) {
		return isset( $item->plugin ) && in_array( $item->plugin, array_merge( [ ELEMENTOR_EDU_PLUGIN_BASE ], self::ORIGINAL_PLUGINS ), true ) ? false : $update;
	}

	public static function auto_update_html( $html, $plugin_file ) {
		if ( ! in_array( $plugin_file, array_merge( [ ELEMENTOR_EDU_PLUGIN_BASE ], self::ORIGINAL_PLUGINS ), true ) ) {
			return $html;
		}
		return '';
	}

	public static function block_rollback() {
		wp_die( esc_html( __( 'To change versions, upload a complete Elementor Edu package. Rolling back individual components is unavailable.', 'elementor-edu' ) ), 'Elementor Edu', [ 'back_link' => true ] );
	}
}

try {
	Elementor_Edu::boot();
} catch ( Throwable $error ) {
	Elementor_Edu::handle_bootstrap_failure( $error );
}
