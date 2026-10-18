<?php
/** Removes Altervista presentation additions without uninstalling hosting services. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Elementor_Edu_Altervista {
	private const ACTIONS = [
		'admin_menu', 'network_admin_menu', 'user_admin_menu',
		'wp_dashboard_setup', 'wp_network_dashboard_setup',
		'admin_notices', 'all_admin_notices', 'network_admin_notices', 'user_admin_notices',
		'admin_enqueue_scripts', 'admin_head', 'admin_footer',
		'admin_print_styles', 'admin_print_scripts', 'admin_print_footer_scripts',
		'in_admin_header', 'in_admin_footer', 'welcome_panel',
		'admin_bar_menu', 'wp_before_admin_bar_render',
		'wp_head', 'wp_footer', 'wp_enqueue_scripts', 'wp_print_scripts', 'wp_print_footer_scripts',
	];
	private const FILTERS = [ 'admin_footer_text', 'update_footer', 'admin_body_class' ];

	public static function boot() {
		// These are presentation hooks only. Authentication, AJAX, cron, updates,
		// advertising configuration and platform services are deliberately untouched.
		foreach ( self::ACTIONS as $hook ) {
			add_action( $hook, [ __CLASS__, 'remove_presentation_callbacks' ], PHP_INT_MIN );
		}
		foreach ( [ 'plugins_loaded', 'after_setup_theme', 'init', 'admin_init' ] as $hook ) {
			add_action( $hook, [ __CLASS__, 'remove_presentation_callbacks' ], PHP_INT_MAX );
		}
		foreach ( [ 'admin_menu', 'network_admin_menu', 'user_admin_menu', 'wp_dashboard_setup', 'wp_network_dashboard_setup', 'admin_head' ] as $hook ) {
			add_action( $hook, [ __CLASS__, 'remove_registered_ui' ], PHP_INT_MAX );
		}
		add_action( 'wp_before_admin_bar_render', [ __CLASS__, 'clean_toolbar' ], PHP_INT_MAX );
		foreach ( [ 'admin_print_styles', 'admin_print_scripts', 'admin_print_footer_scripts', 'wp_print_scripts', 'wp_print_footer_scripts' ] as $hook ) {
			add_action( $hook, [ __CLASS__, 'remove_admin_assets' ], 0 );
		}
		add_action( 'wp_head', [ __CLASS__, 'hide_credit' ], 0 );
		add_action( 'admin_head', [ __CLASS__, 'hide_credit' ], 0 );
		add_action( 'wp_footer', [ __CLASS__, 'remove_credit_markup' ], PHP_INT_MAX );
		add_action( 'admin_footer', [ __CLASS__, 'remove_credit_markup' ], PHP_INT_MAX );
		// Replace the hosting favicon on the public site and admin bar with
		// WordPress's bundled mark while Elementor Edu is active.
		add_filter( 'get_site_icon_url', [ __CLASS__, 'wordpress_site_icon' ], PHP_INT_MAX );
		add_filter( 'site_icon_url', [ __CLASS__, 'wordpress_site_icon' ], PHP_INT_MAX );
		self::remove_presentation_callbacks();
	}

	public static function wordpress_site_icon( $url ) {
		return includes_url( 'images/w-logo-blue.png' );
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

	private static function presentation_hook( $hook ) {
		// Allow Altervista to register its menus; remove its promotional pages below.
		if ( in_array( $hook, [ 'admin_menu', 'network_admin_menu', 'user_admin_menu' ], true ) ) {
			return false;
		}
		return in_array( $hook, self::ACTIONS, true ) || in_array( $hook, self::FILTERS, true )
			|| preg_match( '/^admin_(?:head|footer|print_styles|print_scripts|print_footer_scripts)-/', $hook );
	}

	public static function remove_presentation_callbacks() {
		global $wp_filter;
		foreach ( $wp_filter as $hook => $registered ) {
			if ( ! self::presentation_hook( $hook ) || ! $registered instanceof WP_Hook ) {
				continue;
			}
			foreach ( $registered->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( self::owns_callback( $callback['function'] ) ) {
						remove_filter( $hook, $callback['function'], $priority );
					}
				}
			}
		}
	}

	private static function owns_page( $slug, $parent = '' ) {
		if ( 'av-domain-unlocking' === $slug ) {
			return false;
		}
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
				if ( self::owns_page( $item[2], $parent ) ) {
					self::remove_page_callback( $item[2], $parent );
					remove_submenu_page( $parent, $item[2] );
				}
			}
		}
		foreach ( (array) $menu as $item ) {
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

	public static function remove_admin_assets() {
		global $wp_styles, $wp_scripts;
		foreach ( [ $wp_styles, $wp_scripts ] as $registry ) {
			if ( ! $registry ) {
				continue;
			}
			foreach ( $registry->queue as $handle ) {
				if ( isset( $registry->registered[ $handle ] ) && self::owns_asset( $registry->registered[ $handle ]->src ) ) {
					$registry->dequeue( $handle );
				}
			}
		}
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
		// Cover frontend and dashboard output, including late hosting-side injection.
		echo '<style id="elementor-edu-altervista-credit">p.av-credit-link{display:none!important}</style>';
		if ( is_admin() ) {
			echo '<script id="elementor-edu-altervista-settings">document.addEventListener("DOMContentLoaded",function(){["av_disable_audience_tracking","av_disable_google_analytics"].forEach(function(id){var field=document.getElementById(id);if(field){var row=field.closest("tr");if(row){row.remove();}}});});</script>';
		}
	}

	/** Remove the credit node from the rendered page as well as hiding it. */
	public static function remove_credit_markup() {
		echo '<script id="elementor-edu-altervista-credit-removal">(function(){function removeCredit(){document.querySelectorAll("p.av-credit-link").forEach(function(node){node.remove();});}removeCredit();if(document.documentElement&&window.MutationObserver){new MutationObserver(removeCredit).observe(document.documentElement,{childList:true,subtree:true});}})();</script>';
	}
}
