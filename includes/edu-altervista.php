<?php
/** Disables the regular integration with Hello, preserving dependent themes. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Elementor_Edu_Altervista {
	private const PLUGIN = 'altervista/altervista.php';
	private const MODE = 'elementor_edu_altervista_mode';
	private const SUSPENDED = 'elementor_edu_altervista_suspended';
	private const SERVER_PAGE = 'av-domain-unlocking';
	private static $mode = 'disabled';
	private static $restore_sites = [];
	private static $hosting_ui_seen = false;

	public static function boot() {
		self::$mode = self::get_mode();
		add_action( 'admin_menu', [ __CLASS__, 'register_settings_page' ], 99 );
		add_action( 'admin_post_elementor_edu_altervista_mode', [ __CLASS__, 'save_mode' ] );
		add_action( 'admin_post_elementor_edu_notice_visibility', [ __CLASS__, 'save_notice_visibility' ] );
		add_action( 'admin_post_elementor_edu_reset_window', [ __CLASS__, 'reset_window' ] );
		add_action( 'admin_post_elementor_edu_permanent_updates', [ __CLASS__, 'enable_permanent_updates' ] );
		// Run before plugins_loaded: Seamless needs the provider's initialization
		// before WordPress includes the theme. Never emulate its private API.
		self::apply_integration_policy();
		register_deactivation_hook( ELEMENTOR_EDU__FILE__, [ __CLASS__, 'restore_on_deactivation' ] );
		add_action( 'switch_blog', [ __CLASS__, 'refresh_mode' ] );
		add_action( 'activated_plugin', [ __CLASS__, 'on_plugin_activated' ], PHP_INT_MAX, 2 );
		add_action( 'admin_init', [ __CLASS__, 'strip_admin_presentation_callbacks' ], PHP_INT_MAX );
		add_action( 'admin_init', [ __CLASS__, 'filter_plugin_install_screen' ], PHP_INT_MAX );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'filter_admin_assets' ], PHP_INT_MAX );
		foreach ( [ 'admin_menu', 'network_admin_menu', 'user_admin_menu', 'wp_dashboard_setup', 'wp_network_dashboard_setup', 'admin_head', 'admin_footer', 'wp_before_admin_bar_render' ] as $hook ) {
			add_action( $hook, [ __CLASS__, 'filter_admin_ui' ], PHP_INT_MAX );
		}
		add_filter( 'add_menu_classes', [ __CLASS__, 'filter_menu_classes' ], PHP_INT_MAX );
		add_filter( 'all_plugins', [ __CLASS__, 'filter_plugins_list' ], PHP_INT_MAX );
		foreach ( [ 'admin_notices', 'all_admin_notices', 'network_admin_notices', 'user_admin_notices' ] as $hook ) {
			add_action( $hook, [ __CLASS__, 'filter_admin_notices' ], PHP_INT_MIN );
		}
		add_action( 'wp_head', [ __CLASS__, 'hide_credit' ], 0 );
		add_action( 'admin_head', [ __CLASS__, 'hide_credit' ], 0 );
		add_action( 'wp_footer', [ __CLASS__, 'remove_credit_markup' ], PHP_INT_MAX );
		add_action( 'admin_footer', [ __CLASS__, 'remove_credit_markup' ], PHP_INT_MAX );
		add_action( 'admin_head', [ __CLASS__, 'hide_late_host_dashboard_cards' ], 1 );
		// Replace the hosting favicon on the public site and admin bar with
		// WordPress's bundled mark while Elementor Edu is active.
		add_filter( 'get_site_icon_url', [ __CLASS__, 'wordpress_site_icon' ], PHP_INT_MAX );
		add_filter( 'site_icon_url', [ __CLASS__, 'wordpress_site_icon' ], PHP_INT_MAX );
	}

	public static function get_mode() {
		$mode = get_option( self::MODE, 'disabled' );
		return in_array( $mode, [ 'disabled', 'server_to_server', 'all' ], true ) ? $mode : 'disabled';
	}

	public static function refresh_mode() {
		self::$mode = self::get_mode();
	}

	/** Only the bundled standalone theme is known not to require Altervista. */
	private static function can_disable_integration() {
		if ( ! wp_get_theme()->exists() || 'hello-elementor' !== get_template() || 'hello-elementor' !== get_stylesheet() ) { return false; }
		if ( is_plugin_active_for_network( self::PLUGIN ) ) { return false; }
		foreach ( get_plugins() as $file => $data ) {
			if ( is_plugin_active( $file ) && in_array( 'altervista', array_map( 'trim', explode( ',', $data['RequiresPlugins'] ?? '' ) ), true ) ) { return false; }
		}
		return true;
	}

	private static function apply_integration_policy() {
		self::load_plugin_management_api();
		if ( 'disabled' === self::$mode && self::can_disable_integration() ) {
			if ( is_plugin_active( self::PLUGIN ) ) {
				// Record recovery before changing activation. Silent deactivation
				// preserves provider data and avoids unverified teardown callbacks.
				update_option( self::SUSPENDED, true, false );
				if ( get_option( self::SUSPENDED ) ) { deactivate_plugins( self::PLUGIN, true, false ); }
			}
			return;
		}
		self::ensure_integration_active();
	}

	/** Restore only what Edu suspended; never leave a hidden policy behind. */
	public static function restore_on_deactivation( $network_wide = false ) {
		$sites = is_multisite() && $network_wide ? get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) : [ get_current_blog_id() ];
		foreach ( $sites as $site_id ) {
			$switch = (int) $site_id !== get_current_blog_id();
			if ( $switch ) { switch_to_blog( $site_id ); }
			try {
				if ( get_option( self::SUSPENDED ) ) {
					self::ensure_integration_active();
					if ( is_plugin_active( self::PLUGIN ) && ! is_plugin_active_for_network( self::PLUGIN ) ) {
						self::$restore_sites[ get_current_blog_id() ] = true;
						// Core saves a plugin list captured BEFORE deactivation hooks.
						// Preserve the restored entry when that final write occurs.
						add_filter( 'pre_update_option_active_plugins', [ __CLASS__, 'preserve_restored_plugin' ], PHP_INT_MAX );
					}
				}
			} finally {
				if ( $switch ) { restore_current_blog(); }
			}
		}
	}

	public static function preserve_restored_plugin( $plugins ) {
		if ( isset( self::$restore_sites[ get_current_blog_id() ] ) && is_array( $plugins )
			&& ! in_array( ELEMENTOR_EDU_PLUGIN_BASE, $plugins, true ) && ! in_array( self::PLUGIN, $plugins, true ) ) {
			$plugins[] = self::PLUGIN;
			sort( $plugins );
		}
		return $plugins;
	}

	public static function register_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		add_submenu_page( 'elementor', __( 'Elementor Edu', 'elementor-edu' ), __( 'Elementor Edu', 'elementor-edu' ), 'manage_options', 'elementor-edu-settings', [ __CLASS__, 'render_settings_page' ] );
		// Editor One uses a separate top-level menu. Keep the settings entry in
		// Elementor's editor flyout and also place it after Editor (20), before
		// Theme Builder (70), in the visible WordPress admin menu.
		add_submenu_page( 'elementor-home', __( 'Elementor Edu', 'elementor-edu' ), __( 'Elementor Edu', 'elementor-edu' ), 'manage_options', 'elementor-edu-settings', [ __CLASS__, 'render_settings_page' ], 30 );
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You are not allowed to access this page.', 'elementor-edu' ) ); }
		$mode = self::get_mode();
		echo '<div class="wrap"><h1>' . esc_html__( 'Elementor Edu', 'elementor-edu' ) . '</h1><p>' . esc_html__( 'Elementor Edu brings Elementor and Elementor Pro together in one plugin for lessons, demonstrations and guided practice. It preserves the familiar editor and existing content while managing the included components as one educational package.', 'elementor-edu' ) . '</p><p>' . esc_html__( 'Choose which Altervista screens to show. Hide mode also deactivates the regular Altervista plugin when standalone Hello Elementor is active.', 'elementor-edu' ) . '</p>';
		if ( 'disabled' === $mode ) {
			echo '<p class="description" id="elementor-edu-altervista-status">' . ( is_plugin_active( self::PLUGIN )
				? esc_html__( 'Altervista remains active for compatibility; its administration screens are hidden.', 'elementor-edu' )
				: esc_html__( 'The regular Altervista plugin is inactive. Deactivation takes full effect on the next request. Hosting code loaded outside normal plugins is not disabled.', 'elementor-edu' ) ) . '</p>';
		}
		if ( isset( $_GET['updated'] ) ) { echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'elementor-edu' ) . '</p></div>'; }
		if ( isset( $_GET['window_reset'] ) ) { echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'The 90-day update period has been restarted.', 'elementor-edu' ) . '</p></div>'; }
		if ( isset( $_GET['updates_permanent'] ) ) { echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Permanent updates are now enabled.', 'elementor-edu' ) . '</p></div>'; }
		if ( isset( $_GET['permanent_error'] ) ) { echo '<div class="notice notice-error"><p>' . esc_html__( 'Permanent updates could not be enabled. The existing record was kept.', 'elementor-edu' ) . '</p></div>'; }
		if ( isset( $_GET['window_error'] ) ) { echo '<div class="notice notice-error"><p>' . esc_html__( 'The update period could not be restarted. The existing record was kept.', 'elementor-edu' ) . '</p></div>'; }
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="elementor_edu_altervista_mode">';
		wp_nonce_field( 'elementor_edu_altervista_mode' );
		foreach ( [ 'disabled' => __( 'Hide Altervista screens (recommended)', 'elementor-edu' ), 'server_to_server' => __( 'Show only Server to server', 'elementor-edu' ), 'all' => __( 'Show all Altervista screens', 'elementor-edu' ) ] as $value => $label ) {
			echo '<p><label><input type="radio" name="altervista_mode" value="' . esc_attr( $value ) . '" ' . checked( $mode, $value, false ) . '> ' . esc_html( $label ) . '</label></p>';
		}
		echo '<p class="description">' . esc_html__( 'With other themes, child themes, declared plugin dependencies or network activation, Altervista stays active to protect compatibility. Its screens are still hidden. Choosing another mode restores the integration.', 'elementor-edu' ) . '</p><p><button class="button button-primary">' . esc_html__( 'Save changes', 'elementor-edu' ) . '</button></p></form>';
		if ( ! is_multisite() || current_user_can( 'manage_network_options' ) ) {
			echo '<hr><h2>' . esc_html__( 'Permanent updates', 'elementor-edu' ) . '</h2><p>' . esc_html__( 'Remove the time limit for Elementor Edu updates and translation refreshes permanently. On a network, this applies to every site.', 'elementor-edu' ) . '</p>';
			if ( Elementor_Edu_Course_V4::updates_are_permanent() ) {
				echo '<p><strong>' . esc_html__( 'Permanent updates are active.', 'elementor-edu' ) . '</strong></p>';
			} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="elementor_edu_permanent_updates">';
			wp_nonce_field( 'elementor_edu_permanent_updates' );
			echo '<p><label><input type="checkbox" name="confirm_permanent_updates" value="1" required> ' . esc_html__( 'I want to enable updates without a time limit.', 'elementor-edu' ) . '</label></p>';
			echo '<p><button class="button">' . esc_html__( 'Enable permanent updates', 'elementor-edu' ) . '</button></p></form>';
			}
		}
		if ( ! Elementor_Edu_Course_V4::updates_are_permanent() && ( ! is_multisite() || current_user_can( 'manage_network_options' ) ) ) {
			echo '<hr><h2>' . esc_html__( 'Restart the update period', 'elementor-edu' ) . '</h2><p>' . esc_html__( 'Restart the 90-day period for Elementor Edu updates and translation refreshes. On a network, this applies to every site.', 'elementor-edu' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="elementor_edu_reset_window">';
			wp_nonce_field( 'elementor_edu_reset_window' );
			echo '<p><label><input type="checkbox" name="confirm_reset_window" value="1" required> ' . esc_html__( 'I want to restart the 90-day update period now.', 'elementor-edu' ) . '</label></p>';
			echo '<p><button class="button">' . esc_html__( 'Restart 90 days', 'elementor-edu' ) . '</button></p></form>';
		}
		echo '<hr><h2>' . esc_html__( 'Educational notice', 'elementor-edu' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="elementor_edu_notice_visibility">';
		wp_nonce_field( 'elementor_edu_notice_visibility' );
		echo '<p><label><input type="checkbox" name="hide_educational_notice" value="1" ' . checked( Elementor_Edu_Course_V4::educational_notice_hidden(), true, false ) . '> ' . esc_html__( 'Permanently hide the educational use notice on this site.', 'elementor-edu' ) . '</label></p>';
		echo '<p><button class="button">' . esc_html__( 'Save changes', 'elementor-edu' ) . '</button></p></form>';
		echo '</div>';
	}

	public static function save_mode() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You are not allowed to change these settings.', 'elementor-edu' ) ); }
		check_admin_referer( 'elementor_edu_altervista_mode' );
		$mode = isset( $_POST['altervista_mode'] ) && is_string( $_POST['altervista_mode'] ) ? sanitize_key( wp_unslash( $_POST['altervista_mode'] ) ) : 'disabled';
		if ( ! in_array( $mode, [ 'disabled', 'server_to_server', 'all' ], true ) ) { $mode = 'disabled'; }
		update_option( self::MODE, $mode, false );
		self::$mode = $mode;
		self::apply_mode();
		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'admin.php?page=elementor-edu-settings' ) ) );
		exit;
	}

	public static function enable_permanent_updates() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( 'manage_options' ) || ( is_multisite() && ! current_user_can( 'manage_network_options' ) ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'elementor-edu' ) );
		}
		check_admin_referer( 'elementor_edu_permanent_updates' );
		if ( ! isset( $_POST['confirm_permanent_updates'] ) || '1' !== wp_unslash( $_POST['confirm_permanent_updates'] ) ) {
			wp_die( esc_html__( 'Confirm permanent updates before continuing.', 'elementor-edu' ) );
		}
		$reset = Elementor_Edu_Course_V4::enable_permanent_updates();
		wp_safe_redirect( add_query_arg( is_wp_error( $reset ) ? 'permanent_error' : 'updates_permanent', '1', admin_url( 'admin.php?page=elementor-edu-settings' ) ) );
		exit;
	}

	public static function reset_window() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( 'manage_options' ) || ( is_multisite() && ! current_user_can( 'manage_network_options' ) ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'elementor-edu' ) );
		}
		check_admin_referer( 'elementor_edu_reset_window' );
		if ( ! isset( $_POST['confirm_reset_window'] ) || '1' !== wp_unslash( $_POST['confirm_reset_window'] ) ) {
			wp_die( esc_html__( 'Confirm the 90-day reset before continuing.', 'elementor-edu' ) );
		}
		$reset = Elementor_Edu_Course_V4::reset_window();
		wp_safe_redirect( add_query_arg( is_wp_error( $reset ) ? 'window_error' : 'window_reset', '1', admin_url( 'admin.php?page=elementor-edu-settings' ) ) );
		exit;
	}


	public static function save_notice_visibility() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You are not allowed to change these settings.', 'elementor-edu' ) ); }
		check_admin_referer( 'elementor_edu_notice_visibility' );
		$hidden = isset( $_POST['hide_educational_notice'] ) && '1' === $_POST['hide_educational_notice'];
		update_option( 'elementor_edu_hide_educational_notice', $hidden ? '1' : '0', false );
		if ( Elementor_Edu_Course_V4::educational_notice_hidden() !== $hidden ) { wp_die( esc_html__( 'Settings could not be saved.', 'elementor-edu' ) ); }
		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'admin.php?page=elementor-edu-settings' ) ) );
		exit;
	}

	private static function apply_mode() {
		self::apply_integration_policy();
		self::filter_admin_ui();
	}

	/** Restore the normal integration if an older Edu version disabled it. */
	private static function ensure_integration_active() {
		self::load_plugin_management_api();
		if ( ! is_plugin_active( self::PLUGIN ) && is_file( WP_PLUGIN_DIR . '/' . self::PLUGIN ) ) {
			// Use the native activation API; never install an MU or change the theme.
			$result = activate_plugin( self::PLUGIN, '', false, true );
			if ( is_wp_error( $result ) ) {
				throw new RuntimeException( 'Altervista integration: ' . $result->get_error_message() );
			}
		}
		if ( is_plugin_active( self::PLUGIN ) ) { delete_option( self::SUSPENDED ); }
	}

	public static function apply_activation_policy( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $site_id ) {
				switch_to_blog( $site_id );
				try {
					self::$mode = self::get_mode();
					self::apply_mode();
				} finally {
					restore_current_blog();
				}
			}
			self::$mode = self::get_mode();
			return;
		}
		self::$mode = self::get_mode();
		self::apply_mode();
	}

	public static function on_plugin_activated( $plugin, $network_wide = false ) {
		if ( self::PLUGIN === $plugin ) { self::apply_mode(); }
	}

	/** Restrict cosmetic cleanup to registered admin UI, never runtime hooks. */
	public static function filter_admin_ui() {
		if ( 'all' === self::$mode ) {
			return;
		}
		// Server to server is registered by the hosting's admin_menu callback.
		// Let it register, then remove every other hosting page below.
		if ( 'server_to_server' !== self::$mode || 'admin_menu' !== current_filter() ) {
			if ( ! self::on_server_page() ) { self::remove_host_admin_callbacks( current_filter() ); }
		}
		self::remove_registered_ui();
		self::clean_toolbar();
	}

	private static function on_server_page() {
		return 'server_to_server' === self::$mode
			&& isset( $_GET['page'] )
			&& is_string( $_GET['page'] )
			&& self::SERVER_PAGE === sanitize_key( wp_unslash( $_GET['page'] ) );
	}

	/** Remove provider-owned callbacks only from WordPress presentation hooks. */
	public static function strip_admin_presentation_callbacks() {
		if ( 'all' === self::$mode ) { return; }
		$hooks = [
			'admin_menu', 'network_admin_menu', 'user_admin_menu', 'wp_dashboard_setup', 'wp_network_dashboard_setup',
			'admin_head', 'admin_footer', 'admin_notices', 'all_admin_notices', 'network_admin_notices', 'user_admin_notices',
			'admin_bar_menu', 'wp_before_admin_bar_render', 'admin_enqueue_scripts', 'admin_print_styles', 'admin_print_scripts',
			'in_admin_header', 'admin_body_class', 'admin_title', 'admin_footer_text', 'update_footer', 'screen_options_show_screen',
		];
		foreach ( $hooks as $hook ) {
			if ( 'server_to_server' === self::$mode && ( in_array( $hook, [ 'admin_menu', 'network_admin_menu', 'user_admin_menu' ], true ) || self::on_server_page() ) ) { continue; }
			self::remove_host_admin_callbacks( $hook );
		}
	}

	private static function remove_host_admin_callbacks( $hook ) {
		global $wp_filter;
		if ( empty( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof WP_Hook ) { return; }
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( self::owns_callback( $callback['function'] ) ) { remove_filter( $hook, $callback['function'], $priority ); }
			}
		}
	}

	/** Dequeue only Altervista-owned CSS and scripts from the admin request. */
	public static function filter_admin_assets() {
		if ( 'all' === self::$mode || self::on_server_page() ) { return; }
		foreach ( [ 'style' => wp_styles(), 'script' => wp_scripts() ] as $type => $registry ) {
			foreach ( (array) $registry->queue as $handle ) {
				$asset = $registry->registered[ $handle ] ?? null;
				if ( $asset && self::is_host_asset_url( $asset->src ) ) {
					if ( 'style' === $type ) { wp_dequeue_style( $handle ); }
					else { wp_dequeue_script( $handle ); }
				}
			}
		}
	}

	public static function filter_admin_notices() {
		if ( 'all' === self::$mode || self::on_server_page() ) { return; }
		global $wp_filter;
		$hook = current_filter();
		if ( ! in_array( $hook, [ 'admin_notices', 'all_admin_notices', 'network_admin_notices', 'user_admin_notices' ], true ) ) { return; }
		if ( empty( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof WP_Hook ) { return; }
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( self::owns_callback( $callback['function'] ) ) { remove_filter( $hook, $callback['function'], $priority ); }
			}
		}
	}

	/** Restore WordPress's plugin-directory screens without affecting other requests. */
	public static function filter_plugin_install_screen() {
		if ( 'all' === self::$mode ) { return; }
		global $pagenow, $wp_filter;
		if ( 'plugin-install.php' !== $pagenow ) { return; }
		foreach ( $wp_filter as $hook => $registered ) {
			$plugin_install_hook = preg_match( '/^install_plugins_(?:tabs|nonmenu_tabs|pre_[a-z0-9_-]+|[a-z0-9_-]+|table_api_args(?:_[a-z0-9_-]+)?)$/', (string) $hook );
			if ( ! $plugin_install_hook && ! in_array( $hook, [ 'plugins_api', 'plugins_api_args', 'plugins_api_result' ], true ) ) { continue; }
			if ( ! $registered instanceof WP_Hook ) { continue; }
			foreach ( $registered->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( self::owns_callback( $callback['function'] ) ) { remove_filter( $hook, $callback['function'], $priority ); }
				}
			}
		}
	}

	private static function load_plugin_management_api() {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	public static function wordpress_site_icon( $url ) {
		// An empty site icon makes core render its standard admin-bar home glyph.
		return 'all' === self::$mode ? $url : '';
	}

	/** Match the integration directory, never a site's name or a translated label. */
	private static function owns_file( $file ) {
		if ( ! is_string( $file ) || '' === $file ) {
			return false;
		}
		$file = wp_normalize_path( $file );
		$root = realpath( WP_PLUGIN_DIR . '/altervista' );
		return false !== strpos( $file, '/wp-content/plugins/altervista/' )
			|| false !== strpos( $file, '/_av_share/' )
			|| ( $root && 0 === strpos( $file, trailingslashit( wp_normalize_path( $root ) ) ) );
	}

	private static function owns_callback( $callback ) {
		try {
			if ( is_array( $callback ) ) {
				$reflection = new ReflectionMethod( $callback[0], $callback[1] );
			} elseif ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
				$parts = explode( '::', $callback, 2 );
				$reflection = new ReflectionMethod( $parts[0], $parts[1] );
			} elseif ( is_object( $callback ) && ! $callback instanceof Closure ) {
				$reflection = new ReflectionMethod( $callback, '__invoke' );
			} else {
				$reflection = new ReflectionFunction( $callback );
			}
			return self::owns_file( $reflection->getFileName() );
		} catch ( Throwable $error ) {
			return false;
		}
	}

	private static function owns_page( $slug, $parent = '' ) {
		global $wp_filter;
		$hook = get_plugin_page_hookname( $slug, $parent );
		if ( empty( $wp_filter[ $hook ] ) ) {
			return false;
		}
		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( self::owns_callback( $callback['function'] ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function remove_page_callback( $slug, $parent = '' ) {
		$hook = get_plugin_page_hookname( $slug, $parent );
		global $wp_filter;
		if ( empty( $wp_filter[ $hook ] ) ) {
			return;
		}
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( self::owns_callback( $callback['function'] ) ) {
					remove_action( $hook, $callback['function'], $priority );
				}
			}
		}
	}

	/** Catch menus/widgets registered before their usual setup hooks. */
	public static function remove_registered_ui() {
		if ( 'all' === self::$mode ) { return; }
		self::remove_unwanted_top_level_menus();
		global $menu, $submenu, $wp_meta_boxes;
		foreach ( (array) $submenu as $parent => $items ) {
			foreach ( $items as $item ) {
				if ( 'server_to_server' === self::$mode && 'options-general.php' === $parent && self::SERVER_PAGE === $item[2] ) { continue; }
				if ( self::owns_page( $item[2], $parent ) || self::is_hosting_menu_item( $item ) ) {
					self::remove_page_callback( $item[2], $parent );
					remove_submenu_page( $parent, $item[2] );
				}
			}
		}
		foreach ( (array) $menu as $item ) {
			if ( self::owns_page( $item[2] ) || self::is_hosting_menu_item( $item ) ) {
				self::remove_page_callback( $item[2] );
				remove_menu_page( $item[2] );
			}
		}
		self::remove_host_dashboard_boxes();
	}

	/** Some hosting dashboard cards use callbacks outside the plugin directory. */
	private static function remove_host_dashboard_boxes() {
		if ( 'all' === self::$mode ) { return; }
		global $wp_meta_boxes;
		foreach ( [ 'dashboard', 'dashboard-network' ] as $screen ) {
			foreach ( (array) ( $wp_meta_boxes[ $screen ] ?? [] ) as $context => $priorities ) {
				foreach ( $priorities as $boxes ) {
					foreach ( $boxes as $id => $box ) {
						if ( ! is_array( $box ) ) { continue; }
						$title = trim( wp_strip_all_tags( (string) ( $box['title'] ?? '' ) ) );
						$hosting_card = (bool) preg_match( '/(?:^|[_-])(?:av|altervista)(?:[_-]|$)/i', (string) $id )
							|| (bool) preg_match( '/altervista|livello\s+pubblicit[àa]|statistiche\s+visitatori/i', $title );
						if ( $hosting_card || ( ! empty( $box['callback'] ) && self::owns_callback( $box['callback'] ) ) ) {
							remove_meta_box( $id, $screen, $context );
						}
					}
				}
			}
		}
	}

	/** Hide these hosting menus unless all Altervista screens are requested. */
	private static function remove_unwanted_top_level_menus() {
		if ( 'all' === self::$mode || ! self::has_hosting_ui() ) { return; }
		global $menu;
		foreach ( (array) $menu as $item ) {
			if ( self::is_unwanted_top_level_menu( $item ) ) {
				remove_menu_page( $item[2] );
			}
		}
	}

	/** Apply once more as WordPress prepares menu markup, including late entries. */
	public static function filter_menu_classes( $items ) {
		if ( 'all' === self::$mode || ! self::has_hosting_ui( $items ) ) { return $items; }
		self::remove_unwanted_top_level_menus();
		foreach ( $items as $index => $item ) {
			if ( self::is_unwanted_top_level_menu( $item ) ) { unset( $items[ $index ] ); }
		}
		return $items;
	}

	/** Keep the active integration out of the installed-plugins presentation. */
	public static function filter_plugins_list( $plugins ) {
		if ( 'all' !== self::$mode && is_array( $plugins ) ) { unset( $plugins[ self::PLUGIN ] ); }
		return $plugins;
	}

	private static function is_unwanted_top_level_menu( $item ) {
		$label = trim( html_entity_decode( wp_strip_all_tags( (string) ( $item[0] ?? '' ) ), ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' ) );
		return in_array( $label, [ 'Account', 'Guadagna', 'Promuovi', 'Earn', 'Promote', 'Conta', 'Ganhe', 'Promova' ], true );
	}

	/** A standalone Account menu can belong to another plugin. */
	private static function has_hosting_ui( $items = null ) {
		if ( self::$hosting_ui_seen || is_dir( WP_PLUGIN_DIR . '/altervista' ) ) { return true; }
		global $menu;
		$groups = [];
		foreach ( (array) ( $items ?? $menu ) as $item ) {
			if ( preg_match( '/^(?:av|altervista)[_-]/i', (string) ( $item[2] ?? '' ) ) ) { self::$hosting_ui_seen = true; break; }
			$label = trim( wp_strip_all_tags( (string) ( $item[0] ?? '' ) ) );
			foreach ( [ [ 'Account', 'Conta' ], [ 'Guadagna', 'Earn', 'Ganhe' ], [ 'Promuovi', 'Promote', 'Promova' ] ] as $group => $labels ) {
				if ( in_array( $label, $labels, true ) ) { $groups[ $group ] = true; }
			}
		}
		self::$hosting_ui_seen = self::$hosting_ui_seen || count( $groups ) >= 2;
		return self::$hosting_ui_seen;
	}

	/** Match the hosting's exact labels and its namespaced page slugs. */
	private static function is_hosting_menu_item( $item ) {
		$slug  = (string) ( $item[2] ?? '' );
		return self::is_unwanted_top_level_menu( $item ) && (bool) preg_match( '/^(?:av|altervista)[_-]/i', $slug );
	}

	private static function owns_asset( $url ) {
		$path = is_string( $url ) ? wp_parse_url( $url, PHP_URL_PATH ) : '';
		$base = wp_parse_url( plugins_url( 'altervista/' ), PHP_URL_PATH );
		return ( $path && $base && 0 === strpos( $path, $base ) ) || ( $path && false !== strpos( $path, '/_av_share/' ) );
	}

	private static function is_host_asset_url( $url ) {
		$host = is_string( $url ) ? strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) : '';
		return self::owns_asset( $url ) || in_array( $host, [ 'altervista.org', 'www.altervista.org', 'it.altervista.org', 'en.altervista.org', 'aa.altervista.org' ], true );
	}

	public static function clean_toolbar() {
		if ( 'all' === self::$mode ) { return; }
		global $wp_admin_bar;
		if ( ! $wp_admin_bar || ! $wp_admin_bar->get_nodes() ) {
			return;
		}
		foreach ( (array) $wp_admin_bar->get_nodes() as $node ) {
			if ( self::is_host_asset_url( $node->href ) ) {
				self::$hosting_ui_seen = true;
				$wp_admin_bar->remove_node( $node->id );
			}
		}
		if ( self::has_hosting_ui() && function_exists( 'wp_admin_bar_wp_menu' ) ) {
			// Rebuild this node even when hosting code reused the core ID.
			$wp_admin_bar->remove_node( 'wp-logo' );
			wp_admin_bar_wp_menu( $wp_admin_bar );
			// The native renderer uses insertion order. Recreating the logo at
			// this late hook otherwise puts it AFTER the other toolbar commands.
			$nodes = $wp_admin_bar->get_nodes();
			foreach ( $nodes as $node ) { $wp_admin_bar->remove_node( $node->id ); }
			$wp_admin_bar->add_node( (array) $nodes['wp-logo'] );
			foreach ( $nodes as $node ) {
				if ( 'wp-logo' !== $node->id ) { $wp_admin_bar->add_node( (array) $node ); }
			}
		}
	}

	public static function hide_credit() {
		if ( is_admin() && 'all' === self::$mode ) { return; }
		// Cover frontend and dashboard output, including late hosting-side injection.
		echo '<style id="elementor-edu-altervista-credit">p.av-credit-link{display:none!important}</style>';
		if ( is_admin() ) {
			echo '<script id="elementor-edu-altervista-settings">document.addEventListener("DOMContentLoaded",function(){["av_disable_audience_tracking","av_disable_google_analytics"].forEach(function(id){var field=document.getElementById(id);if(field){var row=field.closest("tr");if(row){row.remove();}}});});</script>';
		}
	}

	/** Provider markup can live outside standard meta-boxes and plugin paths. */
	public static function hide_late_host_dashboard_cards() {
		if ( 'all' === self::$mode ) { return; }
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$dashboard = $screen && in_array( $screen->id, [ 'dashboard', 'dashboard-network' ], true );
		echo '<script id="elementor-edu-altervista-dashboard">';
		echo 'var eduAltervistaDashboard=' . ( $dashboard ? 'true' : 'false' ) . ';';
		echo 'var eduAltervistaHosting=' . ( self::has_hosting_ui() ? 'true' : 'false' ) . ';';
		echo <<<'JS'
(function(){
    function normalized(text){return text.replace(/\s+/g," ").trim().toLowerCase();}
    function hostingTitle(text){return /^(livello pubblicit[àa]|statistiche visitatori|advertising level|visitor statistics|nível de publicidade|estatísticas de visitantes)$/.test(normalized(text));}
    function clean(){
        var entries=[];var groups=new Set();
        document.querySelectorAll("#adminmenu > li").forEach(function(item){
            var label=item.querySelector(".wp-menu-name");
            if(!label){return;}
            var copy=label.cloneNode(true);
            copy.querySelectorAll(".awaiting-mod,.update-plugins,.count").forEach(function(badge){badge.remove();});
            var text=normalized(copy.textContent);
            [/^(account|conta)$/, /^(guadagna|earn|ganhe)$/, /^(promuovi|promote|promova)$/].forEach(function(pattern,index){if(pattern.test(text)){groups.add(index);entries.push(item);}});
        });
        eduAltervistaHosting=eduAltervistaHosting||groups.size>=2;
        if(eduAltervistaHosting){entries.forEach(function(item){item.remove();});}
        // Do not remove nodes while the HTML parser is still building them.
        if(!eduAltervistaDashboard||document.readyState==="loading"){return;}
        document.querySelectorAll("#wpbody-content .postbox").forEach(function(box){
            var title=box.querySelector(".hndle,.postbox-header h2");
            if((title&&hostingTitle(title.textContent))||/(?:^|[-_])(?:av|altervista)(?:[-_]|$)/i.test(box.id)){box.remove();}
        });
        // Keep an invisible heading as a section boundary. Removing it loses
        // the anchor when the provider appends the body in a later task.
        document.querySelectorAll("#wpbody-content h2").forEach(function(heading){
            if(!hostingTitle(heading.textContent)){return;}
            heading.hidden=true;
            heading.style.setProperty("display","none","important");
            var start=heading;
            // A title-only wrapper and its following body can be siblings.
            while(start.parentElement&&!start.parentElement.matches("#wpbody-content,.wrap,.postbox,#dashboard-widgets-wrap")&&start.parentElement.childElementCount===1&&normalized(start.parentElement.textContent)===normalized(heading.textContent)){
                start=start.parentElement;
            }
            var sibling=start.nextSibling;
            while(sibling){
                if(sibling.nodeType===1&&(sibling.matches("h1,h2,form,.notice,.postbox,#dashboard-widgets-wrap")||sibling.querySelector("h1,h2,form,.notice,.postbox"))){break;}
                var next=sibling.nextSibling;
                sibling.remove();
                sibling=next;
            }
        });
    }
    var scheduled=false;
    function schedule(){if(!scheduled){scheduled=true;queueMicrotask(function(){scheduled=false;clean();});}}
    clean();
    document.addEventListener("DOMContentLoaded",clean);
    if(document.documentElement&&window.MutationObserver){new MutationObserver(schedule).observe(document.documentElement,{childList:true,subtree:true,characterData:true});}
})();
JS;
		echo '</script>';
	}

	/** Remove the credit node from the rendered page as well as hiding it. */
	public static function remove_credit_markup() {
		if ( is_admin() && 'all' === self::$mode ) { return; }
		echo '<script id="elementor-edu-altervista-credit-removal">(function(){function removeCredit(){document.querySelectorAll("p.av-credit-link").forEach(function(node){node.remove();});}removeCredit();if(document.documentElement&&window.MutationObserver){new MutationObserver(removeCredit).observe(document.documentElement,{childList:true,subtree:true});}})();</script>';
	}
}
