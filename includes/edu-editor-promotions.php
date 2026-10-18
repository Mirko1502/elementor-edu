<?php
/** Suppress the Angie installation promotion before the editor can render it. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Elementor_Edu_Editor_Promotions {
	public static function boot() {
		// A virtual dismissal applies to every user/site visit without writing consent,
		// installation state or first-visit counters into the database.
		add_filter( 'pre_option_elementor_angie_guide_auto_shown', [ __CLASS__, 'dismiss_angie' ] );
		add_filter( 'elementor/editor/localize_settings', [ __CLASS__, 'editor_settings' ], PHP_INT_MAX );
		add_filter( 'elementor/editor/v2/packages', [ __CLASS__, 'editor_packages' ], PHP_INT_MAX );
		add_action( 'elementor/editor/wp_head', [ __CLASS__, 'hide_promotion_controls' ] );
	}

	public static function dismiss_angie() {
		return 'yes';
	}

	public static function editor_settings( $settings ) {
		$settings['angie']['autoShow'] = false;
		return $settings;
	}

	public static function editor_packages( $packages ) {
		// This package mounts CreateWidgetModal and its create-widget event listener.
		// Excluding it prevents automatic and manually triggered installation popups.
		return array_values( array_diff( $packages, [ 'editor-widget-creation' ] ) );
	}

	public static function hide_promotion_controls() {
		// Keep the editor's functional toolbar and existing custom widgets visible.
		echo '<style id="elementor-edu-editor-promotions">button[aria-label="Angie"],.elementor-panel-custom-widgets__cta,.elementor-panel-elements-widget-creation,[data-testid="e-angie-guide-card"]{display:none!important}</style>';
	}
}
