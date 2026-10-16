<?php
/**
 * Elementor Edu deliberately retains its course record and educational monitor.
 * Existing Elementor content is retained as well.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'elementor_edu_daily_update_check' );
$prepared_update = get_site_transient( 'elementor_edu_prepared_update' );
if ( is_array( $prepared_update ) && ! empty( $prepared_update['path'] ) ) {
	$upgrade_dir = trailingslashit( WP_CONTENT_DIR ) . 'upgrade/';
	if ( 0 === strpos( $prepared_update['path'], $upgrade_dir ) && is_file( $prepared_update['path'] ) ) {
		@unlink( $prepared_update['path'] );
	}
}
delete_site_transient( 'elementor_edu_prepared_update' );
delete_site_option( 'elementor_edu_available_update' );

wp_clear_scheduled_hook( 'elementor_edu_translation_refresh' );
delete_site_option( 'elementor_edu_translation_lock' );
delete_site_option( 'elementor_edu_translation_state' );
