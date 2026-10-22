<?php
/** Remove Elementor Edu's own lifecycle data and any monitor left by older releases. */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( is_multisite() ) {
	foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $site_id ) {
		switch_to_blog( $site_id );
		wp_clear_scheduled_hook( 'elementor_edu_course_check' );
		wp_clear_scheduled_hook( 'elementor_edu_daily_update_check' );
		wp_clear_scheduled_hook( 'elementor_edu_translation_refresh' );
		delete_option( 'elementor_edu_hide_educational_notice' );
		restore_current_blog();
	}
	delete_site_option( 'elementor_edu_course_record' );
} else {
	wp_clear_scheduled_hook( 'elementor_edu_course_check' );
	wp_clear_scheduled_hook( 'elementor_edu_daily_update_check' );
	wp_clear_scheduled_hook( 'elementor_edu_translation_refresh' );
	delete_option( 'elementor_edu_course_record' );
}
delete_option( 'elementor_edu_hide_educational_notice' );

// Also remove a misplaced per-site copy left by earlier versions on a network.
delete_option( 'elementor_edu_course_record' );

$prepared_update = get_site_transient( 'elementor_edu_prepared_update' );
if ( is_array( $prepared_update ) && ! empty( $prepared_update['path'] ) ) {
	$upgrade_dir = trailingslashit( WP_CONTENT_DIR ) . 'upgrade/';
	if ( 0 === strpos( $prepared_update['path'], $upgrade_dir ) && is_file( $prepared_update['path'] ) ) {
		@unlink( $prepared_update['path'] );
	}
}
delete_site_transient( 'elementor_edu_prepared_update' );
delete_site_option( 'elementor_edu_available_update' );
delete_site_option( 'elementor_edu_translation_lock' );
delete_site_option( 'elementor_edu_translation_state' );

if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
	$legacy_monitor = trailingslashit( WPMU_PLUGIN_DIR ) . 'elementor-edu-course.php';
	if ( is_file( $legacy_monitor ) && ! is_link( $legacy_monitor ) ) {
		$contents = @file_get_contents( $legacy_monitor );
		if ( false !== $contents && false !== strpos( $contents, 'Elementor Edu course monitor.' ) && false !== strpos( $contents, 'Plugin Name: Elementor Edu' ) ) {
			@unlink( $legacy_monitor );
		}
	}
	$safe_mode = trailingslashit( WPMU_PLUGIN_DIR ) . 'elementor-safe-mode.php';
	if ( is_file( $safe_mode ) && ! is_link( $safe_mode ) ) {
		$contents = @file_get_contents( $safe_mode );
		if ( false !== $contents && false !== strpos( $contents, 'Plugin Name: Elementor Safe Mode' ) && false !== strpos( $contents, 'class Safe_Mode' ) ) {
			@unlink( $safe_mode );
		}
	}
}

delete_option( 'elementor_safe_mode' );
delete_option( 'elementor_safe_mode_allowed_plugins' );
delete_option( 'elementor_safe_mode_created_mu_dir' );
delete_option( 'elementor_safe_mode_token' );
delete_option( 'theme_mods_elementor-safe' );

global $wpdb;
if ( isset( $wpdb->usermeta ) ) {
	$wpdb->delete( $wpdb->usermeta, [ 'meta_key' => 'elementor_edu_expiration_notice_dismissed_until' ], [ '%s' ] );
	$wpdb->delete( $wpdb->usermeta, [ 'meta_key' => 'elementor_edu_setup_success_pending' ], [ '%s' ] );
	$wpdb->delete( $wpdb->usermeta, [ 'meta_key' => 'elementor_edu_setup_success_seen' ], [ '%s' ] );
	$wpdb->delete( $wpdb->usermeta, [ 'meta_key' => 'elementor_edu_install_complete_pending' ], [ '%s' ] );
}
