<?php
/**
 * Plugin Name: Elementor Edu
 * Description: Elementor in one package for educational and demonstration use.
 * Version: 4.3.2
 * Author: Elementor.com; Elementor Edu packaging
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

define( 'ELEMENTOR_EDU_VERSION', '4.3.2' );
define( 'ELEMENTOR_EDU__FILE__', __FILE__ );
define( 'ELEMENTOR_EDU_PLUGIN_BASE', plugin_basename( __FILE__ ) );

/** Coordinates the two engines under a single WordPress plugin identity. */
final class Elementor_Edu {

	private static $error = '';
	private static $error_args = [];
	private const ORIGINAL_PLUGINS = [ 'elementor/elementor.php', 'elementor-pro/elementor-pro.php' ];

	public static function boot() {
		// Validate before the activation callbacks registered by the engines.
		register_activation_hook( ELEMENTOR_EDU__FILE__, [ __CLASS__, 'activate' ] );

		require_once __DIR__ . '/includes/edu-i18n.php';
		Elementor_Edu_I18n::boot( __DIR__ );
		require_once __DIR__ . '/includes/edu-course.php';
		$course_error = Elementor_Edu_Course_V3::prepare( ELEMENTOR_EDU__FILE__ );

		if ( $course_error ) {
			self::$error = $course_error;
		} elseif ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			self::$error = 'Elementor Edu requires PHP %s or later.';
			self::$error_args = [ '7.4' ];
		} elseif ( version_compare( get_bloginfo( 'version' ), '6.8', '<' ) ) {
			self::$error = 'Elementor Edu requires WordPress %s or later.';
			self::$error_args = [ '6.8' ];
		} elseif ( self::has_conflict() ) {
			self::$error = 'Deactivate the separate Elementor and Elementor Pro plugins before activating Elementor Edu. Existing content will be preserved.';
		} elseif ( ! is_readable( __DIR__ . '/includes/elementor/elementor.php' ) || ! is_readable( __DIR__ . '/includes/elementor-pro/elementor-pro.php' ) ) {
			self::$error = 'The Elementor Edu package is incomplete. Reinstall the complete ZIP file.';
		}

		if ( self::$error ) {
			add_action( 'admin_notices', [ __CLASS__, 'notice' ] );
			add_action( 'network_admin_notices', [ __CLASS__, 'notice' ] );
			return;
		}

		// Keep the physical engine paths intact; only their plugin identity changes.
		require_once __DIR__ . '/includes/elementor/elementor.php';
		require_once __DIR__ . '/includes/elementor-pro/elementor-pro.php';
		require_once __DIR__ . '/includes/edu-hello-theme.php';
		require_once __DIR__ . '/includes/edu-updater.php';
		require_once __DIR__ . '/includes/edu-translation-updater.php';
		Elementor_Edu_Hello_Theme::boot();
		Elementor_Edu_Updater::boot();
		Elementor_Edu_Translation_Updater::boot();

		add_filter( 'pre_set_site_transient_update_plugins', [ __CLASS__, 'filter_updates' ], PHP_INT_MAX );
		add_filter( 'site_transient_update_plugins', [ __CLASS__, 'filter_updates' ], PHP_INT_MAX );
		add_filter( 'auto_update_plugin', [ __CLASS__, 'auto_update' ], PHP_INT_MAX, 2 );
		add_filter( 'plugin_auto_update_setting_html', [ __CLASS__, 'auto_update_html' ], PHP_INT_MAX, 2 );
		add_action( 'admin_post_elementor_rollback', [ __CLASS__, 'block_rollback' ], -100 );
		add_action( 'admin_post_elementor_pro_rollback', [ __CLASS__, 'block_rollback' ], -100 );
		add_filter( 'elementor/admin/show_beta_tester', '__return_false' );
	}

	private static function has_conflict() {
		$active = (array) get_option( 'active_plugins', [] );
		$network = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) : [];
		return defined( 'ELEMENTOR_VERSION' ) || defined( 'ELEMENTOR_PRO_VERSION' ) || array_intersect( self::ORIGINAL_PLUGINS, array_merge( $active, $network ) );
	}

	public static function activate( $network_wide ) {
		if ( self::$error ) {
			wp_die( esc_html( self::error_message() ), 'Elementor Edu', [ 'back_link' => true ] );
		}
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $site_id ) {
				if ( array_intersect( self::ORIGINAL_PLUGINS, (array) get_blog_option( $site_id, 'active_plugins', [] ) ) ) {
					wp_die( esc_html( __( 'Deactivate the separate Elementor and Elementor Pro plugins on every site before activating Elementor Edu across the network.', 'elementor-edu' ) ), 'Elementor Edu', [ 'back_link' => true ] );
				}
			}
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

	/** Never install a single upstream engine over the combined plugin. */
	public static function filter_updates( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$files = [
			ELEMENTOR_EDU_PLUGIN_BASE,
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
		return isset( $item->plugin ) && ELEMENTOR_EDU_PLUGIN_BASE === $item->plugin ? false : $update;
	}

	public static function auto_update_html( $html, $plugin_file ) {
		if ( ELEMENTOR_EDU_PLUGIN_BASE !== $plugin_file ) {
			return $html;
		}
		return esc_html( Elementor_Edu_Course_V3::updates_allowed() ? __( 'Elementor package update', 'elementor-edu' ) : __( 'Updates are unavailable for this copy', 'elementor-edu' ) );
	}

	public static function block_rollback() {
		wp_die( esc_html( __( 'To change versions, upload a complete Elementor Edu package. Rolling back individual components is unavailable.', 'elementor-edu' ) ), 'Elementor Edu', [ 'back_link' => true ] );
	}
}

Elementor_Edu::boot();
