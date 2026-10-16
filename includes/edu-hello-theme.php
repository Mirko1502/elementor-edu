<?php
/** Installs the bundled Hello Elementor theme only after an administrator's click. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Elementor_Edu_Hello_Theme {
	private const SLUG = 'hello-elementor';
	private const ACTION = 'elementor_edu_install_hello';

	public static function boot() {
		add_action( 'admin_notices', [ __CLASS__, 'notice' ] );
		add_action( 'network_admin_notices', [ __CLASS__, 'notice' ] );
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle_install' ] );
		// Elementor's first-run redirect otherwise hides the post-activation notice.
		add_action( 'admin_init', [ __CLASS__, 'keep_activation_screen' ], 0 );
	}

	public static function keep_activation_screen() {
		if ( get_transient( 'elementor_activation_redirect' ) && current_user_can( 'switch_themes' ) && self::SLUG !== get_template() ) {
			delete_transient( 'elementor_activation_redirect' );
		}
	}

	public static function notice() {
		// A child theme of Hello already uses the intended base: preserve it.
		if ( ! current_user_can( 'switch_themes' ) || self::SLUG === get_template() ) {
			return;
		}
		$theme = wp_get_theme( self::SLUG );
		echo '<div class="notice notice-info elementor-edu-hello-notice"><h2>' . esc_html__( 'Complete your setup with Hello Elementor', 'elementor-edu' ) . '</h2>';
		echo '<p>' . esc_html__( 'We recommend Hello Elementor, the base theme included in Elementor Edu. Your current theme will remain active until you click the button.', 'elementor-edu' ) . '</p>';
		if ( is_network_admin() ) {
			echo '<p>' . esc_html__( 'Each site in the network has its own theme. Open the site dashboard to configure it.', 'elementor-edu' ) . '</p><p><a class="button button-primary" href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Set up this site\'s theme', 'elementor-edu' ) . '</a></p>';
		} elseif ( ! $theme->exists() && ! current_user_can( 'install_themes' ) ) {
			echo '<p>' . esc_html__( 'Ask an authorized administrator to install Hello Elementor. You can then activate it here.', 'elementor-edu' ) . '</p>';
		} elseif ( is_multisite() && $theme->exists() && ! $theme->is_allowed() && ! current_user_can( 'manage_network_themes' ) ) {
			echo '<p>' . esc_html__( 'Ask the network administrator to enable Hello Elementor for this site.', 'elementor-edu' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Activating the theme will change the appearance of your site. Your previous theme and content will be preserved.', 'elementor-edu' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php?action=' . self::ACTION ) ) . '">';
			wp_nonce_field( self::ACTION );
			submit_button( $theme->exists() ? __( 'Activate Hello Elementor', 'elementor-edu' ) : __( 'Install and activate Hello Elementor', 'elementor-edu' ), 'primary', 'submit', true );
			echo '</form>';
		}
		echo '</div>';
	}

	/** The request guard is separate from the installation for native WP integration tests. */
	public static function authorize_request() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( __( 'Use the Hello Elementor installation button.', 'elementor-edu' ), 'Elementor Edu', [ 'response' => 405 ] );
		}
		if ( ! current_user_can( 'switch_themes' ) ) {
			wp_die( __( 'You do not have permission to change this site\'s theme.', 'elementor-edu' ), 'Elementor Edu', [ 'response' => 403 ] );
		}
		check_admin_referer( self::ACTION );
	}

	public static function handle_install() {
		self::authorize_request();
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		// admin-post.php has no admin menu bootstrap: use WP's standalone installer shell.
		global $hook_suffix;
		$hook_suffix = 'themes.php';
		set_current_screen( 'themes' );
		iframe_header( 'Hello Elementor' );
		echo '<div class="wrap"><h1>' . esc_html__( 'Hello Elementor setup', 'elementor-edu' ) . '</h1>';
		$skin = new WP_Upgrader_Skin( [
			'url' => admin_url( 'admin-post.php?action=' . self::ACTION ),
			'nonce' => self::ACTION,
			'title' => 'Hello Elementor',
		] );
		$result = self::install_and_activate( $skin );
		if ( is_wp_error( $result ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
		} elseif ( true === $result ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Hello Elementor is now the active theme for this site.', 'elementor-edu' ) . '</p></div>';
			echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'themes.php' ) ) . '">' . esc_html__( 'Go to Themes', 'elementor-edu' ) . '</a> <a class="button" href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Visit site', 'elementor-edu' ) . '</a></p>';
		}
		// false means WordPress displayed its filesystem credentials form.
		echo '</div>';
		iframe_footer();
		exit;
	}

	/** @return true|false|WP_Error False allows the native credentials form to continue. */
	public static function install_and_activate( $skin ) {
		if ( ! current_user_can( 'switch_themes' ) ) {
			return new WP_Error( 'edu_theme_forbidden', __( 'You do not have permission to change the theme.', 'elementor-edu' ) );
		}
		if ( self::SLUG === get_template() ) {
			return true;
		}
		$theme = wp_get_theme( self::SLUG );
		if ( ! $theme->exists() ) {
			if ( ! current_user_can( 'install_themes' ) ) {
				return new WP_Error( 'edu_install_forbidden', __( 'You do not have permission to install themes.', 'elementor-edu' ) );
			}
			$package = __DIR__ . '/../packages/hello-elementor.zip';
			if ( ! is_readable( $package ) ) {
				return new WP_Error( 'edu_theme_missing', __( 'The Hello Elementor package is unavailable. Reinstall the complete Elementor Edu ZIP file.', 'elementor-edu' ) );
			}
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			$upgrader = new Theme_Upgrader( $skin );
			$result = $upgrader->install( $package );
			if ( true !== $result ) {
				return $result;
			}
			wp_clean_themes_cache();
			$theme = wp_get_theme( self::SLUG );
		}
		// Never overwrite an existing theme, even if the bundled version differs.
		if ( ! $theme->exists() || $theme->errors() ) {
			return new WP_Error( 'edu_theme_invalid', __( 'Hello Elementor is incomplete or invalid. Restore it from Appearance > Themes and try again.', 'elementor-edu' ) );
		}
		$requirements = validate_theme_requirements( self::SLUG );
		if ( is_wp_error( $requirements ) ) {
			return $requirements;
		}
		if ( is_multisite() && ! $theme->is_allowed() ) {
			if ( ! current_user_can( 'manage_network_themes' ) ) {
				return new WP_Error( 'edu_theme_not_allowed', __( 'The network administrator must enable Hello Elementor for this site.', 'elementor-edu' ) );
			}
			// Enable only for the current site, without changing other sites' themes.
			$allowed = (array) get_option( 'allowedthemes', [] );
			$allowed[ self::SLUG ] = true;
			update_option( 'allowedthemes', $allowed );
		}
		switch_theme( self::SLUG );
		if ( self::SLUG !== get_stylesheet() ) {
			return new WP_Error( 'edu_theme_activation_failed', __( 'Hello Elementor is installed but could not be activated. Try again from Appearance > Themes.', 'elementor-edu' ) );
		}
		return true;
	}
}
