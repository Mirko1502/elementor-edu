<?php
/** Installs the bundled Hello Elementor theme only after an administrator's click. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Elementor_Edu_Hello_Theme {
	private const SLUG = 'hello-elementor';
	private const ACTION = 'elementor_edu_install_hello';

	public static function boot() {
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_action( 'admin_footer', [ __CLASS__, 'notice' ] );
		add_action( 'elementor/editor/after_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_action( 'elementor/editor/footer', [ __CLASS__, 'notice' ] );
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle_install' ] );
		add_filter( 'hello-plus-theme/rest/admin-config', [ __CLASS__, 'suppress_welcome_banner' ], PHP_INT_MAX );
		// Keep the first request in the dashboard where the setup dialog is available.
		add_action( 'admin_init', [ __CLASS__, 'keep_activation_screen' ], 0 );
	}

	/** Elementor Edu has its own setup flow; never show Hello's competing welcome banner. */
	public static function suppress_welcome_banner( $config ) {
		if ( is_array( $config ) ) {
			$config['welcome'] = [];
		}

		return $config;
	}

	public static function keep_activation_screen() {
		if ( get_transient( 'elementor_activation_redirect' ) && current_user_can( 'switch_themes' ) && self::SLUG !== get_template() ) {
			delete_transient( 'elementor_activation_redirect' );
		}
	}

	public static function enqueue() {
		if ( current_user_can( 'switch_themes' ) && self::SLUG !== get_template() ) {
			wp_enqueue_script( 'jquery' );
		}
	}

	public static function notice() {
		// A child theme of Hello already uses the intended base: preserve it.
		if ( ! current_user_can( 'switch_themes' ) || self::SLUG === get_template() ) {
			return;
		}
		$theme = wp_get_theme( self::SLUG );
		echo '<div id="elementor-edu-hello-overlay"><section id="elementor-edu-hello-dialog" role="dialog" aria-modal="true" aria-labelledby="elementor-edu-hello-title" aria-describedby="elementor-edu-hello-description" tabindex="-1">';
		echo '<h2 id="elementor-edu-hello-title">' . esc_html__( 'Complete your setup with Hello Elementor', 'elementor-edu' ) . '</h2>';
		echo '<p id="elementor-edu-hello-description">' . esc_html__( 'Install and activate Hello Elementor to complete your setup and continue to the dashboard.', 'elementor-edu' ) . '</p>';
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
			submit_button( __( 'Install the Hello Elementor theme', 'elementor-edu' ), 'primary', 'submit', true );
			echo '</form>';
		}
		echo '</section></div>';
		?>
		<style>
			#elementor-edu-hello-overlay { position: fixed; inset: 0; z-index: 2147483647; display: flex; align-items: center; justify-content: center; padding: 24px; box-sizing: border-box; background: rgba(0, 0, 0, 0.78); }
			#elementor-edu-hello-dialog { background: #fff; color: #1d2327; width: 100%; max-width: 560px; max-height: calc(100vh - 48px); overflow: auto; padding: 32px; box-sizing: border-box; border-radius: 8px; box-shadow: 0 12px 48px rgba(0, 0, 0, 0.3); }
			#elementor-edu-hello-dialog h2 { margin: 0 0 20px; font-size: 24px; line-height: 1.3; }
			#elementor-edu-hello-dialog p { font-size: 14px; line-height: 1.6; }
			#elementor-edu-hello-dialog .submit { margin-bottom: 0; padding-bottom: 0; }
			#elementor-edu-hello-dialog .button { display: inline-block; padding: 8px 16px; border: 1px solid #2271b1; border-radius: 3px; background: #2271b1; color: #fff; font-size: 14px; line-height: 1.6; white-space: normal; text-align: center; text-decoration: none; cursor: pointer; }
			#elementor-edu-hello-dialog .button:focus { outline: 2px solid #2271b1; outline-offset: 3px; }
			#elementor-edu-hello-dialog .button:disabled { opacity: 0.6; cursor: wait; }
			body.elementor-edu-hello-required { overflow: hidden; }
		</style>
		<script>
		jQuery(function ($) {
			var $overlay = $('#elementor-edu-hello-overlay').appendTo(document.body);
			var $dialog = $('#elementor-edu-hello-dialog');
			$('body').addClass('elementor-edu-hello-required');
			$overlay.siblings().not('script, style').prop('inert', true);
			function focusAction() {
				var $action = $dialog.find('button:enabled, input[type="submit"]:enabled, a[href]').first();
				($action.length ? $action : $dialog).trigger('focus');
			}
			focusAction();
			$(document).on('focusin.eduHello', function (event) {
				if (!$dialog[0].contains(event.target)) { focusAction(); }
			}).on('keydown.eduHello', function (event) {
				if (event.key === 'Escape') { event.preventDefault(); event.stopImmediatePropagation(); }
				if (event.key === 'Tab') { event.preventDefault(); focusAction(); }
			});
			$dialog.find('form').on('submit', function () {
				$dialog.attr('aria-busy', 'true');
				$(this).find(':submit').prop('disabled', true);
			});
		});
		</script>
		<?php
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
		$skin = new class( [
			'url' => admin_url( 'admin-post.php?action=' . self::ACTION ),
			'nonce' => self::ACTION,
			'title' => 'Hello Elementor',
		] ) extends WP_Upgrader_Skin {
			// Default feedback calls show_message(), which flushes output buffers
			// and sends headers before the final redirect, even inside ob_start().
			public function header() {}
			public function footer() {}
			public function feedback( $feedback, ...$args ) {}
		};
		// Buffer the native installer: success redirects before any HTML is sent.
		// Keep its filesystem credentials form available on hosts requiring FTP/SSH.
		ob_start();
		$result = self::install_and_activate( $skin );
		$output = ob_get_clean();
		if ( true === $result ) {
			delete_transient( 'elementor_activation_redirect' );
			wp_safe_redirect( admin_url( 'index.php' ) );
			exit;
		}
		// Only errors or a required credentials form need a standalone response.
		global $hook_suffix;
		$hook_suffix = 'themes.php';
		set_current_screen( 'themes' );
		iframe_header( 'Hello Elementor' );
		echo '<div class="wrap"><h1>' . esc_html__( 'Hello Elementor setup', 'elementor-edu' ) . '</h1>';
		echo $output; // Trusted HTML from the native WordPress installer, including its nonce form.
		if ( is_wp_error( $result ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
		}
		// false means WordPress displayed its filesystem credentials form.
		echo '</div>';
		iframe_footer();
		exit;
	}

	/** @return true|false|WP_Error False allows the native credentials form to continue. */
	public static function install_and_activate( $skin ) {
		$previous_stylesheet = get_stylesheet();
		$previous_template = get_template();
		// Installing a theme fires upgrader_process_complete. WordPress may then
		// run Language_Pack_Upgrader::async_upgrade() with its own page-rendering
		// skin, which breaks this POST response and prevents the dashboard redirect.
		// Defer only those automatic translation updates to a normal admin request.
		add_filter( 'async_update_translation', [ __CLASS__, 'defer_async_translation_update' ], PHP_INT_MAX, 2 );
		try {
			return self::install_and_activate_safely( $skin );
		} catch ( Throwable $error ) {
			// A theme/upgrader callback can throw while WordPress is switching themes.
			// Restore the saved theme pair without dispatching the same callbacks again.
			if ( $previous_stylesheet && $previous_template && ( get_stylesheet() !== $previous_stylesheet || get_template() !== $previous_template ) ) {
				try {
					switch_theme( $previous_stylesheet );
				} catch ( Throwable $restore_error ) {
					update_option( 'template', $previous_template );
					update_option( 'stylesheet', $previous_stylesheet );
				}
			}
			return new WP_Error( 'edu_theme_operation_failed', __( 'WordPress could not complete the Hello Elementor theme operation. Your previous theme was restored where possible. Check the site error log and try again.', 'elementor-edu' ) );
		} finally {
			remove_filter( 'async_update_translation', [ __CLASS__, 'defer_async_translation_update' ], PHP_INT_MAX );
		}
	}

	/** Keep WordPress's translation updater from hijacking the theme-install response. */
	public static function defer_async_translation_update( $update, $language_update ) {
		return false;
	}

	/** @return true|false|WP_Error False allows the native credentials form to continue. */
	private static function install_and_activate_safely( $skin ) {
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
