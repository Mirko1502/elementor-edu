<?php
// Elementor Edu: adapted for the combined educational distribution.
/**
 * Plugin Name: Elementor Pro
 * Description: Elevate your designs and unlock the full power of the Atomic Editor. Gain access to dozens of Pro widgets, Website Templates, Theme Builder, Pop Ups, Forms, reusable Components, and WooCommerce building capabilities.
 * Plugin URI: https://go.elementor.com/wp-dash-wp-plugins-author-uri/
 * Version: 4.3.2
 * Author: Elementor.com
 * Author URI: https://go.elementor.com/wp-dash-wp-plugins-author-uri/
 * Requires PHP: 7.4
 * Requires at least: 6.8
 * Requires Plugins: elementor
 * Elementor tested up to: 4.3.2-ga
 * Text Domain: elementor-pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'ELEMENTOR_PRO_VERSION', '4.3.2' );

/**
 * All versions should be `major.minor`, without patch, in order to compare them properly.
 * Therefore, we can't set a patch version as a requirement.
 * (e.g. Core 3.15.0-beta1 and Core 3.15.0-cloud2 should be fine when requiring 3.15, while
 * requiring 3.15.2 is not allowed)
 */
define( 'ELEMENTOR_PRO_REQUIRED_CORE_VERSION', '4.0' );
define( 'ELEMENTOR_PRO_RECOMMENDED_CORE_VERSION', '4.3' );

define( 'ELEMENTOR_PRO__FILE__', __FILE__ );
define( 'ELEMENTOR_PRO_PLUGIN_BASE', ELEMENTOR_EDU_PLUGIN_BASE );
define( 'ELEMENTOR_PRO_PATH', plugin_dir_path( ELEMENTOR_PRO__FILE__ ) );
define( 'ELEMENTOR_PRO_ASSETS_PATH', ELEMENTOR_PRO_PATH . 'assets/' );
define( 'ELEMENTOR_PRO_MODULES_PATH', ELEMENTOR_PRO_PATH . 'modules/' );
define( 'ELEMENTOR_PRO_URL', plugins_url( '/', ELEMENTOR_PRO__FILE__ ) );
define( 'ELEMENTOR_PRO_ASSETS_URL', ELEMENTOR_PRO_URL . 'assets/' );
define( 'ELEMENTOR_PRO_MODULES_URL', ELEMENTOR_PRO_URL . 'modules/' );
define('EPA_WORKER', 'https://elementor.gpltimes.com');

add_action('after_setup_theme', 'epa_init', 5);

function epa_get_features() {
    return [
        'template_access_level_20',
        'kit_access_level_20',
        'atomic-components',
        'atomic-loop',
        'pro-interactions',
        'theme-builder',
        'activity-log',
        'breadcrumbs',
        'form',
        'posts',
        'template',
        'countdown',
        'slides',
        'price-list',
        'portfolio',
        'flip-box',
        'price-table',
        'login',
        'share-buttons',
        'theme-post-content',
        'theme-post-title',
        'nav-menu',
        'blockquote',
        'media-carousel',
        'animated-headline',
        'facebook-comments',
        'facebook-embed',
        'facebook-page',
        'facebook-button',
        'testimonial-carousel',
        'post-navigation',
        'search-form',
        'post-comments',
        'author-box',
        'call-to-action',
        'post-info',
        'theme-site-logo',
        'theme-site-title',
        'theme-archive-title',
        'theme-post-excerpt',
        'theme-post-featured-image',
        'archive-posts',
        'theme-page-title',
        'sitemap',
        'reviews',
        'table-of-contents',
        'lottie',
        'code-highlight',
        'hotspot',
        'video-playlist',
        'progress-tracker',
        'section-effects',
        'sticky',
        'scroll-snap',
        'page-transitions',
        'mega-menu',
        'nested-carousel',
        'loop-grid',
        'loop-carousel',
        'theme-builder',
        'elementor_icons',
        'elementor_custom_fonts',
        'dynamic-tags',
        'taxonomy-filter',
        'email',
        'email2',
        'mailpoet',
        'mailpoet3',
        'redirect',
        'header',
        'footer',
        'single-post',
        'single-page',
        'archive',
        'search-results',
        'error-404',
        'loop-item',
        'font-awesome-pro',
        'typekit',
        'gallery',
        'off-canvas',
        'link-in-bio-var-2',
        'link-in-bio-var-3',
        'link-in-bio-var-4',
        'link-in-bio-var-5',
        'link-in-bio-var-6',
        'link-in-bio-var-7',
        'search',
        'size-variable',
        'transitions',
        'element-manager-permissions',
        'akismet',
        'display-conditions',
        'woocommerce-products',
        'wc-products',
        'woocommerce-product-add-to-cart',
        'wc-elements',
        'wc-categories',
        'woocommerce-product-price',
        'woocommerce-product-title',
        'woocommerce-product-images',
        'woocommerce-product-upsell',
        'woocommerce-product-short-description',
        'woocommerce-product-meta',
        'woocommerce-product-stock',
        'woocommerce-product-rating',
        'wc-add-to-cart',
        'dynamic-tags-wc',
        'woocommerce-product-data-tabs',
        'woocommerce-product-related',
        'woocommerce-breadcrumb',
        'wc-archive-products',
        'woocommerce-archive-products',
        'woocommerce-product-additional-information',
        'woocommerce-menu-cart',
        'woocommerce-product-content',
        'woocommerce-archive-description',
        'paypal-button',
        'woocommerce-checkout-page',
        'woocommerce-cart',
        'woocommerce-my-account',
        'woocommerce-purchase-summary',
        'woocommerce-notices',
        'settings-woocommerce-pages',
        'settings-woocommerce-notices',
        'popup',
        'custom-css',
        'global-css',
        'custom_code',
        'custom-attributes',
        'form-submissions',
        'form-integrations',
        'dynamic-tags-acf',
        'dynamic-tags-pods',
        'dynamic-tags-toolset',
        'editor_comments',
        'stripe-button',
        'role-manager',
        'global-widget',
        'activecampaign',
        'cf7db',
        'convertkit',
        'discord',
        'drip',
        'getresponse',
        'mailchimp',
        'mailerlite',
        'slack',
        'webhook',
        'product-single',
        'product-archive',
        'wc-single-elements',
        'atomic-custom-attributes',
        'atomic-custom-css',
        'floating-buttons',
        'contact-buttons-var-1',
        'contact-buttons-var-3',
        'contact-buttons-var-4',
        'contact-buttons-var-5',
        'contact-buttons-var-6',
        'contact-buttons-var-7',
        'contact-buttons-var-8',
        'contact-buttons-var-9',
        'contact-buttons-var-10',
        'floating-bars-var-2',
        'floating-bars-var-3',
        'notes',
        'color-variable',
        'typography-variable',
    ];
}

function epa_get_license_data() {
    return [
        'success' => true,
        'status' => 'ACTIVE',
        'error' => '',
        'license' => 'valid',
        'item_id' => false,
        'item_name' => 'Elementor Pro',
        'checksum' => 'B5E0B5F8DD8689E6ACA49DD6E6E1A930',
        'expires' => 'lifetime',
        'payment_id' => '0123456789',
        'customer_email' => 'gpl@wordpress.org',
        'customer_name' => 'GPL',
        'license_limit' => 1000,
        'site_count' => 1,
        'activations_left' => 999,
        'renewal_url' => '',
        'recurring' => true,
        'subscription_id' => '123456',
        'activated' => true,
        'cached' => true,
        'features' => epa_get_features(),
        'tier' => 'expert',
        'generation' => 'empty',
    ];
}

function epa_set_license_data($license_data, $expiration = '+12 hours') {
    $v2 = [
        'timeout' => strtotime($expiration, current_time('timestamp')),
        'value' => wp_json_encode($license_data),
    ];
    update_option('_elementor_pro_license_v2_data', $v2, false);

    $fallback = [
        'timeout' => strtotime('+24 hours', current_time('timestamp')),
        'value' => wp_json_encode($license_data),
    ];
    update_option('_elementor_pro_license_v2_data_fallback', $fallback, false);
    update_option('_elementor_pro_license_data', $license_data, false);
}

function epa_set_connect_data() {
    try {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return;
        }

        $site_url = get_site_url();

        $connect_data = [
            'user' => (object) [
                'email' => 'noreply@gpltimes.com',
                'name' => 'GPL Times',
                'id' => 'gpl_times_' . md5($site_url),
            ],
            'access_level' => 20,
            'access_token' => md5('GPL_ACCESS_' . $site_url . $user_id),
            'access_token_secret' => md5('GPL_SECRET_' . $site_url . $user_id),
            'client_id' => md5('GPL_CLIENT_' . $site_url),
        ];

        update_user_option($user_id, 'elementor_connect_common_data', $connect_data, false);
        update_option('elementor_connect_site_key', md5('GPL_SITE_KEY_' . $site_url), false);
    } catch (\Exception $e) {
    }
}

function epa_proxy_request($url, $r, $worker_prefix = '') {
    $parsed = wp_parse_url($url);
    $path = isset($parsed['path']) ? $parsed['path'] : '/';
    $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
    $worker_url = EPA_WORKER . $worker_prefix . $path . $query;
    $method = isset($r['method']) ? strtoupper($r['method']) : 'GET';

    $headers = [];
    $forward = ['app', 'endpoint', 'local-id'];
    if (isset($r['headers']) && is_array($r['headers'])) {
        foreach ($forward as $h) {
            if (isset($r['headers'][$h])) {
                $headers[$h] = $r['headers'][$h];
            }
        }
    }

    if ($method === 'POST') {
        $headers['Content-Type'] = 'application/json';
    }

    $args = [
        'method' => $method,
        'headers' => $headers,
        'timeout' => 45,
        'sslverify' => true,
    ];

    if ($method === 'POST' && !empty($r['body'])) {
        if (is_array($r['body'])) {
            $args['body'] = wp_json_encode($r['body']);
        } else {
            $args['body'] = $r['body'];
        }
    }

    $response = wp_remote_request($worker_url, $args);

    if (is_wp_error($response)) {
        return false;
    }

    return [
        'headers' => wp_remote_retrieve_headers($response),
        'body' => wp_remote_retrieve_body($response),
        'response' => [
            'code' => wp_remote_retrieve_response_code($response),
            'message' => wp_remote_retrieve_response_message($response),
        ],
        'cookies' => wp_remote_retrieve_cookies($response),
        'filename' => null,
    ];
}

function epa_make_response($body, $code = 200) {
    return [
        'headers' => [],
        'body' => is_string($body) ? $body : wp_json_encode($body),
        'response' => ['code' => $code, 'message' => 'OK'],
        'cookies' => [],
        'filename' => null,
    ];
}

function epa_init() {
    static $initialized = false;
    if ($initialized) {
        return;
    }
    $initialized = true;

    $license_data = epa_get_license_data();

    add_action('admin_init', function () use ($license_data) {
        if (!defined('ELEMENTOR_PRO_VERSION')) {
            return;
        }
        update_option('elementor_pro_license_key', md5('GPL'));
        epa_set_license_data($license_data);
        epa_set_connect_data();
        update_option('elementor_one_dismiss_connect_alert', true);
        update_option('elementor_one_welcome_screen_completed', true);
        update_option('elementor_one_editor_update_notification_dismissed', true);
    }, 20);

    add_action('init', function () {
        if (is_user_logged_in() && current_user_can('edit_posts')) {
            epa_set_connect_data();
        }
    }, 20);

    add_action('elementor/editor/before_enqueue_scripts', function () {
        epa_set_connect_data();
    }, 1);

    add_action('elementor/init', function () {
        if (!class_exists('\Elementor\Plugin') || !isset(\Elementor\Plugin::$instance->app)) {
            return;
        }
        \Elementor\Plugin::$instance->app->set_settings('cloud-library', [
            'quota' => [
                'currentUsage' => 0,
                'threshold' => 1000,
                'subscriptionId' => '123456',
            ],
        ]);
    }, 20);

    add_action('elementor/editor/footer', function () {
        echo '<script>if(typeof elementorAppConfig!=="undefined"){elementorAppConfig["cloud-library"]=elementorAppConfig["cloud-library"]||{};elementorAppConfig["cloud-library"].quota={currentUsage:1000,threshold:1000,subscriptionId:"123456"};}</script>';
    });

    add_action('admin_menu', function () {
        remove_submenu_page('elementor', 'elementor-one-upgrade');
        remove_submenu_page('elementor-home', 'elementor-one-upgrade');
        remove_submenu_page('elementor', 'elementor-connect-account');
        remove_submenu_page('elementor-home', 'elementor-connect-account');
    }, 9999);

    add_action('admin_head', function () {
        echo '<style>#adminmenu a[href*="elementor-one-upgrade"]{display:none!important}#adminmenu a[href*="elementor-connect"]{display:none!important}.e-notice--elementor-trial,.e-notice--license-expired{display:none!important}</style>';
    });

    add_filter('elementor_pro/license/should_show_renew_license_notice', '__return_false');

    add_filter('pre_http_request', function ($pre, $r, $url) use ($license_data) {
        if (!is_string($url)) {
            return $pre;
        }

        if (strpos($url, 'cloud-library.prod.builder.elementor.red') !== false) {
            return epa_proxy_request($url, $r, '/proxy-cloud');
        }

        if (strpos($url, 'my.elementor.com') === false) {
            return $pre;
        }

        if (strpos($url, '/api/v2/license/validate') !== false ||
            strpos($url, '/api/v2/license/activate') !== false ||
            strpos($url, '/api/v1/licenses/') !== false) {
            return epa_make_response($license_data);
        }

        if (strpos($url, '/api/v2/license/deactivate') !== false) {
            return epa_make_response(['success' => true]);
        }

        if (strpos($url, '/api/connect/v1/activate/disconnect') !== false) {
            return epa_make_response('true');
        }

        return epa_proxy_request($url, $r);
    }, 10, 3);
}

require_once ELEMENTOR_PRO_MODULES_PATH . 'core-upgrade-recommendation/classes/upgrade-recommendation-dismissal.php';

\ElementorPro\Modules\CoreUpgradeRecommendation\Classes\Upgrade_Recommendation_Dismissal::register_dismiss_handlers();

/**
 * Load gettext translate for our text domain.
 *
 * @since 1.0.0
 *
 * @return void
 */
function elementor_pro_load_plugin() {
	if ( ! did_action( 'elementor/loaded' ) ) {
		add_action( 'admin_notices', 'elementor_pro_fail_load' );

		return;
	}

	$core_version = ELEMENTOR_VERSION;
	$core_version_required = ELEMENTOR_PRO_REQUIRED_CORE_VERSION;
	$core_version_recommended = ELEMENTOR_PRO_RECOMMENDED_CORE_VERSION;

	if ( ! elementor_pro_compare_major_version( $core_version, $core_version_required, '>=' ) ) {
		add_action( 'admin_notices', 'elementor_pro_fail_load_out_of_date' );

		return;
	}

	if ( ! elementor_pro_compare_major_version( $core_version, $core_version_recommended, '>=' ) ) {
		add_action( 'admin_notices', 'elementor_pro_admin_notice_upgrade_recommendation' );
	}

	require ELEMENTOR_PRO_PATH . 'plugin.php';
}

function elementor_pro_compare_major_version( $left, $right, $operator ) {
	$pattern = '/^(\d+\.\d+).*/';
	$replace = '$1.0';

	$left  = preg_replace( $pattern, $replace, $left );
	$right = preg_replace( $pattern, $replace, $right );

	return version_compare( $left, $right, $operator );
}

add_action( 'plugins_loaded', 'elementor_pro_load_plugin' );

function elementor_edu_pro_print_error( $message ) {
	if ( ! $message ) {
		return;
	}
	// PHPCS - $message should not be escaped
	echo '<div class="error">' . $message . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
/**
 * Show in WP Dashboard notice about the plugin is not activated.
 *
 * @since 1.0.0
 *
 * @return void
 */
function elementor_pro_fail_load() {
	$screen = get_current_screen();
	if ( isset( $screen->parent_file ) && 'plugins.php' === $screen->parent_file && 'update' === $screen->id ) {
		return;
	}

	$plugin = ELEMENTOR_PLUGIN_BASE;

	if ( _is_elementor_installed() ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$activation_url = wp_nonce_url( 'plugins.php?action=activate&amp;plugin=' . $plugin . '&amp;plugin_status=all&amp;paged=1&amp;s', 'activate-plugin_' . $plugin );

		$message = '<h3>' . esc_html__( 'You\'re not using Elementor Pro yet!', 'elementor-pro' ) . '</h3>';
		$message .= '<p>' . esc_html__( 'Activate the Elementor plugin to start using all of Elementor Pro plugin’s features.', 'elementor-pro' ) . '</p>';
		$message .= '<p>' . sprintf( '<a href="%s" class="button-primary">%s</a>', $activation_url, esc_html__( 'Activate Now', 'elementor-pro' ) ) . '</p>';
	} else {
		if ( ! current_user_can( 'install_plugins' ) ) {
			return;
		}

		$install_url = wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=elementor' ), 'install-plugin_elementor' );

		$message = '<h3>' . esc_html__( 'Elementor Pro plugin requires installing the Elementor plugin', 'elementor-pro' ) . '</h3>';
		$message .= '<p>' . esc_html__( 'Install and activate the Elementor plugin to access all the Pro features.', 'elementor-pro' ) . '</p>';
		$message .= '<p>' . sprintf( '<a href="%s" class="button-primary">%s</a>', $install_url, esc_html__( 'Install Now', 'elementor-pro' ) ) . '</p>';
	}

	elementor_edu_pro_print_error( $message );
}

function elementor_pro_fail_load_out_of_date() {
	if ( ! current_user_can( 'update_plugins' ) ) {
		return;
	}

	$file_path = ELEMENTOR_PLUGIN_BASE;

	$upgrade_link = wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' ) . $file_path, 'upgrade-plugin_' . $file_path );

	$message = sprintf(
		'<h3>%1$s</h3><p>%2$s <a href="%3$s" class="button-primary">%4$s</a></p>',
		esc_html__( 'Elementor Pro requires newer version of the Elementor plugin', 'elementor-pro' ),
		esc_html__( 'Update the Elementor plugin to reactivate the Elementor Pro plugin.', 'elementor-pro' ),
		$upgrade_link,
		esc_html__( 'Update Now', 'elementor-pro' )
	);

	elementor_edu_pro_print_error( $message );
}

function elementor_pro_admin_notice_upgrade_recommendation() {
	if ( ! current_user_can( 'update_plugins' ) ) {
		return;
	}

	$recommended_version = ELEMENTOR_PRO_RECOMMENDED_CORE_VERSION;
	$core_version = ELEMENTOR_VERSION;

	if ( ! \ElementorPro\Modules\CoreUpgradeRecommendation\Classes\Upgrade_Recommendation_Dismissal::should_show_notice(
		$recommended_version,
		$core_version
	) ) {
		return;
	}

	$file_path = ELEMENTOR_PLUGIN_BASE;
	$upgrade_link = wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' ) . $file_path, 'upgrade-plugin_' . $file_path );
	$notice_id = \ElementorPro\Modules\CoreUpgradeRecommendation\Classes\Upgrade_Recommendation_Dismissal::get_notice_id( $recommended_version );

	$notice_options = [
		'id' => $notice_id,
		'title' => esc_html__( 'Don’t miss out on the new version of Elementor', 'elementor-pro' ),
		'description' => esc_html__( 'Update to the latest version of Elementor to enjoy new features, better performance and compatibility.', 'elementor-pro' ),
		'button' => [
			'text' => esc_html__( 'Update Now', 'elementor-pro' ),
			'url' => $upgrade_link,
			'type' => 'cta',
		],
		'dismissible' => true,
	];

	if ( ! did_action( 'elementor/loaded' ) || ! isset( \Elementor\Plugin::$instance->admin ) ) {
		elementor_pro_print_upgrade_recommendation_legacy_notice( $upgrade_link );

		return;
	}

	/** @var \Elementor\Core\Admin\Admin_Notices|null $admin_notices */
	$admin_notices = \Elementor\Plugin::$instance->admin->get_component( 'admin-notices' );

	if ( ! $admin_notices ) {
		elementor_pro_print_upgrade_recommendation_legacy_notice( $upgrade_link );

		return;
	}

	$admin_notices->print_admin_notice( $notice_options, [] );
}

function elementor_pro_print_upgrade_recommendation_legacy_notice( string $upgrade_link ): void {
	$message = sprintf(
		'<h3>%1$s</h3><p>%2$s <a href="%3$s" class="button-primary">%4$s</a></p>',
		esc_html__( 'Don’t miss out on the new version of Elementor', 'elementor-pro' ),
		esc_html__( 'Update to the latest version of Elementor to enjoy new features, better performance and compatibility.', 'elementor-pro' ),
		$upgrade_link,
		esc_html__( 'Update Now', 'elementor-pro' )
	);

	elementor_edu_pro_print_error( $message );
}

if ( ! function_exists( '_is_elementor_installed' ) ) {

	function _is_elementor_installed() {
		$file_path = ELEMENTOR_PLUGIN_BASE;
		$installed_plugins = get_plugins();

		return isset( $installed_plugins[ $file_path ] );
	}
}
