<?php
/** Controls Altervista presentation without disabling hosting or theme dependencies. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Elementor_Edu_Altervista {
	private const PLUGIN = 'altervista/altervista.php';
	private const MODE = 'elementor_edu_altervista_mode';
	private const SERVER_PAGE = 'av-domain-unlocking';
	private static $mode = 'disabled';

	public static function boot() {
		self::$mode = self::get_mode();
		add_action( 'admin_menu', [ __CLASS__, 'register_settings_page' ], 99 );
		add_action( 'admin_post_elementor_edu_altervista_mode', [ __CLASS__, 'save_mode' ] );
		// Hosting callbacks initialize Seamless before the theme loads. Never strip
		// runtime hooks or deactivate the integration, including on multisite.
		self::ensure_integration_active();
		add_action( 'activated_plugin', [ __CLASS__, 'on_plugin_activated' ], PHP_INT_MAX, 2 );
		foreach ( [ 'admin_menu', 'network_admin_menu', 'user_admin_menu', 'wp_dashboard_setup', 'wp_network_dashboard_setup', 'admin_head', 'admin_footer', 'wp_before_admin_bar_render' ] as $hook ) {
			add_action( $hook, [ __CLASS__, 'filter_admin_ui' ], PHP_INT_MAX );
		}
		foreach ( [ 'admin_notices', 'all_admin_notices', 'network_admin_notices', 'user_admin_notices' ] as $hook ) {
			add_action( $hook, [ __CLASS__, 'filter_admin_notices' ], PHP_INT_MIN );
		}
		add_action( 'wp_head', [ __CLASS__, 'hide_credit' ], 0 );
		add_action( 'admin_head', [ __CLASS__, 'hide_credit' ], 0 );
		add_action( 'wp_footer', [ __CLASS__, 'remove_credit_markup' ], PHP_INT_MAX );
		add_action( 'admin_footer', [ __CLASS__, 'remove_credit_markup' ], PHP_INT_MAX );
		// Replace the hosting favicon on the public site and admin bar with
		// WordPress's bundled mark while Elementor Edu is active.
		add_filter( 'get_site_icon_url', [ __CLASS__, 'wordpress_site_icon' ], PHP_INT_MAX );
		add_filter( 'site_icon_url', [ __CLASS__, 'wordpress_site_icon' ], PHP_INT_MAX );
	}

	public static function get_mode() {
		$mode = get_option( self::MODE, 'disabled' );
		return in_array( $mode, [ 'disabled', 'server_to_server', 'all' ], true ) ? $mode : 'disabled';
	}

	public static function register_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		add_submenu_page( 'elementor', __( 'Elementor Edu', 'elementor-edu' ), __( 'Elementor Edu', 'elementor-edu' ), 'manage_options', 'elementor-edu-settings', [ __CLASS__, 'render_settings_page' ] );
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You are not allowed to access this page.', 'elementor-edu' ) ); }
		$mode = self::get_mode();
		echo '<div class="wrap"><h1>' . esc_html__( 'Elementor Edu', 'elementor-edu' ) . '</h1><p>' . esc_html__( 'Choose which Altervista screens to show. Hosting services remain active for theme compatibility.', 'elementor-edu' ) . '</p>';
		if ( isset( $_GET['updated'] ) ) { echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'elementor-edu' ) . '</p></div>'; }
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="elementor_edu_altervista_mode">';
		wp_nonce_field( 'elementor_edu_altervista_mode' );
		foreach ( [ 'disabled' => __( 'Hide Altervista screens (recommended)', 'elementor-edu' ), 'server_to_server' => __( 'Show only Server to server', 'elementor-edu' ), 'all' => __( 'Show all Altervista screens', 'elementor-edu' ) ] as $value => $label ) {
			echo '<p><label><input type="radio" name="altervista_mode" value="' . esc_attr( $value ) . '" ' . checked( $mode, $value, false ) . '> ' . esc_html( $label ) . '</label></p>';
		}
		echo '<p>' . esc_html__( 'These choices affect the interface only. The Altervista plugin and its theme initialization, authentication and background services are preserved.', 'elementor-edu' ) . '</p><p><button class="button button-primary">' . esc_html__( 'Save changes', 'elementor-edu' ) . '</button></p></form></div>';
	}

	public static function save_mode() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You are not allowed to change these settings.', 'elementor-edu' ) ); }
		check_admin_referer( 'elementor_edu_altervista_mode' );
		$mode = isset( $_POST['altervista_mode'] ) ? sanitize_key( wp_unslash( $_POST['altervista_mode'] ) ) : 'disabled';
		if ( ! in_array( $mode, [ 'disabled', 'server_to_server', 'all' ], true ) ) { $mode = 'disabled'; }
		update_option( self::MODE, $mode, false );
		self::$mode = $mode;
		self::apply_mode();
		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'admin.php?page=elementor-edu-settings' ) ) );
		exit;
	}

	private static function apply_mode() {
		self::ensure_integration_active();
		if ( 'all' === self::$mode ) {
			update_option( 'av_disable_audience_tracking', '0', false );
			update_option( 'av_disable_google_analytics', '0', false );
		} else {
			self::filter_admin_ui();
		}
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
		if ( self::PLUGIN === $plugin ) { self::filter_admin_ui(); }
	}

	/** Restrict cosmetic cleanup to registered admin UI, never runtime hooks. */
	public static function filter_admin_ui() {
		if ( 'all' === self::$mode ) { return; }
		self::remove_registered_ui();
		self::clean_toolbar();
	}

	public static function filter_admin_notices() {
		if ( 'all' === self::$mode ) { return; }
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
		global $menu, $submenu, $wp_meta_boxes;
		foreach ( (array) $submenu as $parent => $items ) {
			foreach ( $items as $item ) {
				if ( 'server_to_server' === self::$mode && self::SERVER_PAGE === $item[2] ) { continue; }
				if ( self::owns_page( $item[2], $parent ) ) {
					self::remove_page_callback( $item[2], $parent );
					remove_submenu_page( $parent, $item[2] );
				}
			}
		}
		foreach ( (array) $menu as $item ) {
			if ( 'server_to_server' === self::$mode && self::SERVER_PAGE === $item[2] ) { continue; }
			if ( self::owns_page( $item[2] ) ) {
				self::remove_page_callback( $item[2] );
				remove_menu_page( $item[2] );
			}
		}
		foreach ( [ 'dashboard', 'dashboard-network' ] as $screen ) {
			foreach ( (array) ( $wp_meta_boxes[ $screen ] ?? [] ) as $context => $priorities ) {
				foreach ( $priorities as $boxes ) {
					foreach ( $boxes as $id => $box ) {
						if ( ! empty( $box['callback'] ) && self::owns_callback( $box['callback'] ) ) {
							remove_meta_box( $id, $screen, $context );
						}
					}
				}
			}
		}
	}

	private static function owns_asset( $url ) {
		$path = is_string( $url ) ? wp_parse_url( $url, PHP_URL_PATH ) : '';
		$base = wp_parse_url( plugins_url( 'altervista/' ), PHP_URL_PATH );
		return $path && $base && 0 === strpos( $path, $base );
	}

	public static function clean_toolbar() {
		global $wp_admin_bar;
		if ( ! $wp_admin_bar || ! is_dir( WP_PLUGIN_DIR . '/altervista' ) ) {
			return;
		}
		foreach ( (array) $wp_admin_bar->get_nodes() as $node ) {
			$host = is_string( $node->href ) ? wp_parse_url( $node->href, PHP_URL_HOST ) : '';
			if ( self::owns_asset( $node->href ) || in_array( strtolower( (string) $host ), [ 'altervista.org', 'www.altervista.org', 'it.altervista.org', 'en.altervista.org', 'aa.altervista.org' ], true ) ) {
				$wp_admin_bar->remove_node( $node->id );
			}
		}
		if ( ! $wp_admin_bar->get_node( 'wp-logo' ) && function_exists( 'wp_admin_bar_wp_menu' ) ) {
			wp_admin_bar_wp_menu( $wp_admin_bar );
		}
	}

	public static function hide_credit() {
		if ( 'all' === self::$mode ) { return; }
		// Cover frontend and dashboard output, including late hosting-side injection.
		echo '<style id="elementor-edu-altervista-credit">p.av-credit-link{display:none!important}</style>';
		if ( is_admin() ) {
			echo '<script id="elementor-edu-altervista-settings">document.addEventListener("DOMContentLoaded",function(){["av_disable_audience_tracking","av_disable_google_analytics"].forEach(function(id){var field=document.getElementById(id);if(field){var row=field.closest("tr");if(row){row.remove();}}});});</script>';
		}
	}

	/** Remove the credit node from the rendered page as well as hiding it. */
	public static function remove_credit_markup() {
		if ( 'all' === self::$mode ) { return; }
		echo '<script id="elementor-edu-altervista-credit-removal">(function(){function removeCredit(){document.querySelectorAll("p.av-credit-link").forEach(function(node){node.remove();});}removeCredit();if(document.documentElement&&window.MutationObserver){new MutationObserver(removeCredit).observe(document.documentElement,{childList:true,subtree:true});}})();</script>';
	}
}
