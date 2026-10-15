<?php
/**
 * Update checks and complete-package updates for Elementor Edu.
 * Only Pro release metadata is checked daily; both engines are fetched and
 * validated after an administrator explicitly starts the update.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Elementor_Edu_Updater {
	private const EVENT = 'elementor_edu_daily_update_check';
	private const AVAILABLE = 'elementor_edu_available_update';
	private const PREPARED = 'elementor_edu_prepared_update';
	private const PAGE = 'https://www.gpl-astra.com/elementor-pro/';
	private const API = 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=elementor';
	private const PACKAGE_MARKER = 'elementor-edu-prepared-package';
	private const MAX_DOWNLOAD = 157286400;
	private const MAX_UNPACKED = 629145600;

	public static function boot() {
		add_action( 'init', [ __CLASS__, 'schedule' ] );
		add_action( self::EVENT, [ __CLASS__, 'check_release' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_notice' ] );
		add_action( 'admin_post_elementor_edu_update', [ __CLASS__, 'prepare_update' ] );
		add_filter( 'pre_set_site_transient_update_plugins', [ __CLASS__, 'inject_prepared_update' ], PHP_INT_MAX - 1 );
		add_filter( 'site_transient_update_plugins', [ __CLASS__, 'inject_prepared_update' ], PHP_INT_MAX - 1 );
		add_filter( 'upgrader_pre_download', [ __CLASS__, 'provide_prepared_package' ], 10, 3 );
		add_filter( 'upgrader_pre_install', [ __CLASS__, 'guard_install' ], 10, 2 );
		add_action( 'upgrader_process_complete', [ __CLASS__, 'complete_update' ], 10, 2 );
	}

	public static function schedule() {
		if ( ! Elementor_Edu_Course_V2::updates_allowed() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::EVENT ) ) {
			wp_schedule_event( time() + 60, 'daily', self::EVENT );
		}
	}

	public static function check_release() {
		if ( ! Elementor_Edu_Course_V2::updates_allowed() ) {
			return;
		}
		$response = self::request( self::PAGE, 12 );
		if ( is_wp_error( $response ) ) {
			return;
		}
		$html = wp_remote_retrieve_body( $response );
		if ( ! preg_match( '/Elementor\s+Pro\s+v([0-9]+(?:\.[0-9]+)+)\s+Plugin/i', $html, $match ) ) {
			return;
		}
		if ( ! Elementor_Edu_Course_V2::updates_allowed() ) {
			return;
		}
		$remote = $match[1];
		if ( version_compare( $remote, ELEMENTOR_PRO_VERSION, '>' ) ) {
			update_site_option( self::AVAILABLE, [ 'version' => $remote, 'checked_at' => time() ] );
		} else {
			delete_site_option( self::AVAILABLE );
		}
	}

	public static function enqueue_notice() {
		if ( ! Elementor_Edu_Course_V2::updates_allowed() ) {
			return;
		}
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$available = get_site_option( self::AVAILABLE );
		if ( empty( $available['version'] ) || ! version_compare( $available['version'], ELEMENTOR_PRO_VERSION, '>' ) ) {
			return;
		}
		wp_enqueue_script( 'jquery' );
		$action = esc_url( admin_url( 'admin-post.php' ) );
		$nonce  = wp_create_nonce( 'elementor_edu_update' );
		$html   = '<div id="elementor-edu-update-modal" class="elementor-edu-update-modal" role="dialog" aria-modal="true" aria-labelledby="elementor-edu-update-title" style="display:none">'
			. '<div class="elementor-edu-update-card"><h2 id="elementor-edu-update-title">Aggiornamento Elementor disponibile</h2>'
			. '<p>È disponibile una nuova versione di Elementor. Puoi installarla ora.</p>'
			. '<form method="post" action="' . $action . '"><input type="hidden" name="action" value="elementor_edu_update">'
			. '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '">'
			. '<button type="submit" class="button button-primary">Aggiorna Elementor ora</button> '
			. '<button type="button" class="button elementor-edu-dismiss">Più tardi</button></form></div></div>';
		$css = '#elementor-edu-update-modal{position:fixed;z-index:100000;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center}'
			. '#elementor-edu-update-modal .elementor-edu-update-card{background:#fff;max-width:480px;padding:28px 32px;box-shadow:0 8px 30px rgba(0,0,0,.25);border-radius:4px}'
			. '#elementor-edu-update-modal h2{margin-top:0}';
		$script = 'jQuery(function($){var modal=$("#elementor-edu-update-modal");if(!modal.length){return;}modal.css("display","flex");modal.on("click",".elementor-edu-dismiss",function(){modal.hide();});modal.on("click",function(e){if(e.target===this){modal.hide();}});});';
		wp_enqueue_style( 'common' );
		wp_add_inline_style( 'common', $css );
		wp_add_inline_script( 'jquery', $script, 'after' );
		add_action( 'admin_footer', static function () use ( $html ) { echo $html; } );
	}

	public static function prepare_update() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Non hai i permessi per aggiornare i plugin.', 'elementor-edu' ), '', [ 'response' => 403 ] );
		}
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( 'Usa il pulsante di aggiornamento di Elementor.', '', [ 'response' => 405 ] );
		}
		check_admin_referer( 'elementor_edu_update' );
		if ( ! Elementor_Edu_Course_V2::updates_allowed() ) {
			wp_die( esc_html( self::expiry_error()->get_error_message() ), 'Elementor', [ 'response' => 403 ] );
		}
		$available = get_site_option( self::AVAILABLE );
		if ( empty( $available['version'] ) || ! version_compare( $available['version'], ELEMENTOR_PRO_VERSION, '>' ) ) {
			wp_die( esc_html__( 'Non è disponibile un aggiornamento di Elementor.', 'elementor-edu' ), '', [ 'response' => 409 ] );
		}

		$result = self::build_package( $available['version'] );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html__( 'Non è stato possibile preparare l’aggiornamento di Elementor. Riprova più tardi.', 'elementor-edu' ), esc_html__( 'Aggiornamento Elementor', 'elementor-edu' ), [ 'back_link' => true ] );
		}

		if ( ! Elementor_Edu_Course_V2::updates_allowed() ) {
			@unlink( $result['path'] );
			wp_die( esc_html( self::expiry_error()->get_error_message() ), 'Elementor', [ 'response' => 403 ] );
		}
		$prepared = [
			'package' => $result['package'],
			'path' => $result['path'],
			'core' => $result['core'],
			'pro' => $result['pro'],
			'expires' => time() + HOUR_IN_SECONDS,
		];
		set_site_transient( self::PREPARED, $prepared, HOUR_IN_SECONDS );
		$updates = get_site_transient( 'update_plugins' );
		if ( ! is_object( $updates ) ) {
			$updates = (object) [ 'response' => [], 'no_update' => [] ];
		}
		if ( ! isset( $updates->response ) || ! is_array( $updates->response ) ) {
			$updates->response = [];
		}
		$updates->response[ ELEMENTOR_EDU_PLUGIN_BASE ] = (object) [
			'slug' => 'elementor-edu',
			'plugin' => ELEMENTOR_EDU_PLUGIN_BASE,
			'new_version' => $result['pro'],
			'package' => self::PACKAGE_MARKER,
			'url' => 'https://www.gpl-astra.com/elementor-pro/',
		];
		set_site_transient( 'update_plugins', $updates );

		$target = self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( ELEMENTOR_EDU_PLUGIN_BASE ) );
		wp_safe_redirect( wp_nonce_url( $target, 'upgrade-plugin_' . ELEMENTOR_EDU_PLUGIN_BASE ) );
		exit;
	}

	public static function has_prepared_update( $transient ) {
		if ( ! Elementor_Edu_Course_V2::updates_allowed() ) {
			return false;
		}
		$prepared = get_site_transient( self::PREPARED );
		return $prepared && isset( $transient->response ) && ! empty( $prepared['expires'] ) && $prepared['expires'] > time()
			&& isset( $transient->response[ ELEMENTOR_EDU_PLUGIN_BASE ]->package )
			&& self::PACKAGE_MARKER === $transient->response[ ELEMENTOR_EDU_PLUGIN_BASE ]->package;
	}

	public static function provide_prepared_package( $reply, $package, $upgrader ) {
		if ( self::PACKAGE_MARKER !== $package ) {
			return $reply;
		}
		if ( ! Elementor_Edu_Course_V2::updates_allowed() ) {
			return self::expiry_error();
		}
		$prepared = get_site_transient( self::PREPARED );
		if ( ! $prepared || empty( $prepared['path'] ) || ! is_file( $prepared['path'] ) || empty( $prepared['expires'] ) || $prepared['expires'] <= time() ) {
			return new WP_Error( 'elementor_edu_package_missing', 'Il pacchetto Elementor preparato non è più disponibile.' );
		}
		// WordPress empties wp-content/upgrade before unpacking. Give it a
		// disposable copy outside that working directory, like download_url().
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$temp = wp_tempnam( 'elementor-edu-update.zip' );
		if ( ! $temp || ! copy( $prepared['path'], $temp ) ) {
			if ( $temp ) { @unlink( $temp ); }
			return new WP_Error( 'elementor_edu_package_copy', 'Impossibile preparare il pacchetto Elementor per l’installazione.' );
		}
		return $temp;
	}

	public static function inject_prepared_update( $transient ) {
		if ( ! Elementor_Edu_Course_V2::updates_allowed() ) {
			return $transient;
		}
		$prepared = get_site_transient( self::PREPARED );
		if ( ! $prepared || empty( $prepared['expires'] ) || $prepared['expires'] <= time() || empty( $prepared['pro'] ) || empty( $prepared['path'] ) || ! is_file( $prepared['path'] ) ) {
			return $transient;
		}
		if ( ! is_object( $transient ) ) {
			$transient = (object) [];
		}
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = [];
		}
		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = [];
		}
		unset( $transient->no_update[ ELEMENTOR_EDU_PLUGIN_BASE ] );
		$transient->response[ ELEMENTOR_EDU_PLUGIN_BASE ] = (object) [
			'slug' => 'elementor-edu',
			'plugin' => ELEMENTOR_EDU_PLUGIN_BASE,
			'new_version' => $prepared['pro'],
			'package' => self::PACKAGE_MARKER,
			'url' => self::PAGE,
		];
		return $transient;
	}

	public static function complete_update( $upgrader, $options ) {
		$plugins = isset( $options['plugins'] ) ? (array) $options['plugins'] : [];
		if ( isset( $options['plugin'] ) ) {
			$plugins[] = $options['plugin'];
		}
		if ( empty( $options['type'] ) || 'plugin' !== $options['type'] || ! in_array( ELEMENTOR_EDU_PLUGIN_BASE, $plugins, true ) ) {
			return;
		}
		$prepared = get_site_transient( self::PREPARED );
		if ( $prepared && ! empty( $prepared['path'] ) ) {
			@unlink( $prepared['path'] );
		}
		delete_site_transient( self::PREPARED );
		delete_site_option( self::AVAILABLE );
		delete_site_transient( 'update_plugins' );
	}

	private static function expiry_error() {
		return new WP_Error( 'elementor_edu_updates_expired', 'Gli aggiornamenti di questa copia didattica di Elementor non sono più disponibili. Passa alla versione ufficiale di Elementor Pro per continuare a riceverli.' );
	}

	public static function guard_install( $response, $extra ) {
		$plugins = isset( $extra['plugins'] ) ? (array) $extra['plugins'] : [];
		if ( isset( $extra['plugin'] ) ) {
			$plugins[] = $extra['plugin'];
		}
		if ( in_array( ELEMENTOR_EDU_PLUGIN_BASE, $plugins, true ) && ! Elementor_Edu_Course_V2::updates_allowed() ) {
			return self::expiry_error();
		}
		return $response;
	}

	private static function build_package( $expected_pro ) {
		if ( ! Elementor_Edu_Course_V2::updates_allowed() ) {
			return self::expiry_error();
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'elementor_edu_zip_missing', 'Il server non dispone del supporto ZIP richiesto per preparare l’aggiornamento Elementor.' );
		}
		$pro_release = self::discover_pro_release();
		if ( is_wp_error( $pro_release ) ) {
			return $pro_release;
		}
		if ( $expected_pro !== $pro_release['version'] || ! version_compare( $pro_release['version'], ELEMENTOR_PRO_VERSION, '>' ) ) {
			return new WP_Error( 'elementor_edu_pro_changed', 'La versione disponibile è cambiata durante la preparazione. Ricarica la bacheca e riprova.' );
		}
		$core_info = self::request( self::API, 20 );
		if ( is_wp_error( $core_info ) ) {
			return $core_info;
		}
		$metadata = json_decode( wp_remote_retrieve_body( $core_info ), true );
		if ( empty( $metadata['version'] ) || empty( $metadata['download_link'] ) || 'downloads.wordpress.org' !== wp_parse_url( $metadata['download_link'], PHP_URL_HOST ) ) {
			return new WP_Error( 'elementor_edu_core_metadata', 'Impossibile verificare il pacchetto Elementor.' );
		}
		if ( version_compare( $metadata['version'], ELEMENTOR_VERSION, '<' ) ) {
			return new WP_Error( 'elementor_edu_core_downgrade', 'La fonte ha restituito una versione di Elementor precedente a quella installata.' );
		}

		$root = trailingslashit( WP_CONTENT_DIR ) . 'upgrade/elementor-edu-' . wp_generate_password( 12, false );
		$work = $root . '/work';
		$bundle = $work . '/elementor-edu';
		if ( ! wp_mkdir_p( $work ) ) {
			return new WP_Error( 'elementor_edu_temp', 'Impossibile creare l’area temporanea di aggiornamento.' );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! WP_Filesystem( false, $work, true ) ) {
			self::remove_tree( $root );
			return new WP_Error( 'elementor_edu_filesystem', 'Impossibile accedere all’area temporanea per preparare l’aggiornamento di Elementor.' );
		}
		$core_zip = self::download_to_temp( $metadata['download_link'], 45 );
		if ( is_wp_error( $core_zip ) ) {
			self::remove_tree( $root );
			return $core_zip;
		}
		$pro_zip = self::download_to_temp( $pro_release['url'], 45, $pro_release['referer'] );
		if ( is_wp_error( $pro_zip ) ) {
			@unlink( $core_zip );
			self::remove_tree( $root );
			return $pro_zip;
		}
		$core_version = self::validate_zip( $core_zip, 'elementor', 'elementor/elementor.php', 'ELEMENTOR_VERSION', $metadata['version'] );
		$pro_version = self::validate_zip( $pro_zip, 'elementor-pro', 'elementor-pro/elementor-pro.php', 'ELEMENTOR_PRO_VERSION', $pro_release['version'] );
		if ( is_wp_error( $core_version ) || is_wp_error( $pro_version ) ) {
			@unlink( $core_zip );
			@unlink( $pro_zip );
			self::remove_tree( $root );
			return is_wp_error( $core_version ) ? $core_version : $pro_version;
		}
		$minimum_php = self::max_version(
			self::package_requirement( $core_zip, 'elementor/elementor.php', 'Requires PHP' ),
			self::package_requirement( $pro_zip, 'elementor-pro/elementor-pro.php', 'Requires PHP' )
		);
		$minimum_wp = self::max_version(
			self::package_requirement( $core_zip, 'elementor/elementor.php', 'Requires at least' ),
			self::package_requirement( $pro_zip, 'elementor-pro/elementor-pro.php', 'Requires at least' )
		);
		$installed_manifest = json_decode( file_get_contents( dirname( ELEMENTOR_EDU__FILE__ ) . '/bundle.json' ), true );
		if ( is_array( $installed_manifest ) ) {
			$minimum_php = self::max_version( $minimum_php, isset( $installed_manifest['hello_elementor_requires_php'] ) ? $installed_manifest['hello_elementor_requires_php'] : '0' );
			$minimum_wp = self::max_version( $minimum_wp, isset( $installed_manifest['hello_elementor_requires_wordpress'] ) ? $installed_manifest['hello_elementor_requires_wordpress'] : '0' );
		}
		if ( is_wp_error( $minimum_php ) || is_wp_error( $minimum_wp ) ) {
			@unlink( $core_zip );
			@unlink( $pro_zip );
			self::remove_tree( $root );
			return is_wp_error( $minimum_php ) ? $minimum_php : $minimum_wp;
		}
		if ( version_compare( PHP_VERSION, $minimum_php, '<' ) || version_compare( get_bloginfo( 'version' ), $minimum_wp, '<' ) ) {
			@unlink( $core_zip );
			@unlink( $pro_zip );
			self::remove_tree( $root );
			return new WP_Error( 'elementor_edu_requirements', 'Il sito non soddisfa i requisiti della nuova versione di Elementor.' );
		}
		$installed = dirname( ELEMENTOR_EDU__FILE__ );
		$copy_result = copy_dir( $installed, $bundle );
		if ( is_wp_error( $copy_result ) || ! $copy_result ) {
			@unlink( $core_zip );
			@unlink( $pro_zip );
			self::remove_tree( $root );
			return is_wp_error( $copy_result ) ? $copy_result : new WP_Error( 'elementor_edu_copy', 'Impossibile preparare la copia completa del plugin.' );
		}
		$core_extract = $work . '/core';
		$pro_extract = $work . '/pro';
		wp_mkdir_p( $core_extract );
		wp_mkdir_p( $pro_extract );
		$core_result = unzip_file( $core_zip, $core_extract );
		$pro_result = unzip_file( $pro_zip, $pro_extract );
		@unlink( $core_zip );
		@unlink( $pro_zip );
		if ( is_wp_error( $core_result ) || is_wp_error( $pro_result ) ) {
			self::remove_tree( $root );
			return is_wp_error( $core_result ) ? $core_result : $pro_result;
		}
		foreach ( [ 'elementor' => [ $core_extract, $core_version ], 'elementor-pro' => [ $pro_extract, $pro_version ] ] as $slug => $item ) {
			$source = $item[0] . '/' . $slug;
			$target = $bundle . '/includes/' . $slug;
			if ( ! is_dir( $source ) || ! is_dir( $target ) ) {
				self::remove_tree( $root );
				return new WP_Error( 'elementor_edu_layout', 'La struttura del pacchetto scaricato non è valida.' );
			}
			self::remove_tree( $target );
			$copy_result = copy_dir( $source, $target );
			if ( is_wp_error( $copy_result ) || ! $copy_result ) {
				self::remove_tree( $root );
				return is_wp_error( $copy_result ) ? $copy_result : new WP_Error( 'elementor_edu_engine_copy', 'Impossibile integrare i file della nuova versione.' );
			}
		}
		$patches = self::patch_engines( $bundle, $core_version, $pro_version );
		if ( is_wp_error( $patches ) ) {
			self::remove_tree( $root );
			return $patches;
		}
		self::replace_version( $bundle . '/elementor-edu.php', 'Version', ELEMENTOR_PRO_VERSION, $pro_version );
		self::replace_constant( $bundle . '/elementor-edu.php', 'ELEMENTOR_EDU_VERSION', ELEMENTOR_PRO_VERSION, $pro_version );
		$manifest_path = $bundle . '/bundle.json';
		$manifest = json_decode( file_get_contents( $manifest_path ), true );
		if ( ! is_array( $manifest ) ) {
			self::remove_tree( $root );
			return new WP_Error( 'elementor_edu_manifest', 'Il manifest del pacchetto installato non è valido.' );
		}
		$manifest['version'] = $pro_version;
		$manifest['elementor'] = $core_version;
		$manifest['elementor_pro'] = $pro_version;
		self::replace_header_value( $bundle . '/elementor-edu.php', 'Requires PHP', $manifest['requires_php'], $minimum_php );
		self::replace_header_value( $bundle . '/elementor-edu.php', 'Requires at least', $manifest['requires_wordpress'], $minimum_wp );
		self::replace_header_value( $bundle . '/readme.txt', 'Requires PHP', $manifest['requires_php'], $minimum_php );
		self::replace_header_value( $bundle . '/readme.txt', 'Requires at least', $manifest['requires_wordpress'], $minimum_wp );
		self::replace_header_value( $bundle . '/readme.txt', 'Stable tag', ELEMENTOR_PRO_VERSION, $pro_version );
		$readme_path = $bundle . '/readme.txt';
		$readme_contents = file_get_contents( $readme_path );
		$readme_contents = str_replace( 'Elementor ' . ELEMENTOR_VERSION, 'Elementor ' . $core_version, $readme_contents );
		file_put_contents( $readme_path, $readme_contents );
		$manifest['requires_php'] = $minimum_php;
		$manifest['requires_wordpress'] = $minimum_wp;
		$manifest['source_entry_sha256'] = [
			'elementor' => hash_file( 'sha256', $core_extract . '/elementor/elementor.php' ),
			'elementor-pro' => hash_file( 'sha256', $pro_extract . '/elementor-pro/elementor-pro.php' ),
			'hello-elementor' => isset( $manifest['source_entry_sha256']['hello-elementor'] ) ? $manifest['source_entry_sha256']['hello-elementor'] : '',
		];
		$modified = [];
		foreach ( [ 'elementor' => $core_extract . '/elementor', 'elementor-pro' => $pro_extract . '/elementor-pro' ] as $slug => $source_root ) {
			$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source_root, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $files as $source_file ) {
				if ( ! $source_file->isFile() || $source_file->isLink() ) {
					continue;
				}
				$relative = substr( $source_file->getPathname(), strlen( $source_root ) + 1 );
				$packed_file = $bundle . '/includes/' . $slug . '/' . $relative;
				if ( ! is_file( $packed_file ) || hash_file( 'sha256', $source_file->getPathname() ) !== hash_file( 'sha256', $packed_file ) ) {
					$modified[] = $slug . '/' . str_replace( DIRECTORY_SEPARATOR, '/', $relative );
				}
			}
		}
		sort( $modified );
		$manifest['modified_engine_files'] = $modified;
		file_put_contents( $manifest_path, wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );

		$archive = $root . '/elementor-edu-update.zip';
		$zip = new ZipArchive();
		if ( true !== $zip->open( $archive, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			self::remove_tree( $root );
			return new WP_Error( 'elementor_edu_archive', 'Impossibile creare il pacchetto di aggiornamento.' );
		}
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $bundle, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || $file->isLink() || 0 === strpos( $file->getFilename(), '._' ) || in_array( $file->getFilename(), [ '.DS_Store', '__MACOSX' ], true ) ) {
				continue;
			}
			$relative = substr( $file->getPathname(), strlen( $work ) + 1 );
			if ( ! $zip->addFile( $file->getPathname(), str_replace( DIRECTORY_SEPARATOR, '/', $relative ) ) ) {
				$zip->close();
				self::remove_tree( $root );
				return new WP_Error( 'elementor_edu_archive_file', 'Impossibile completare il pacchetto di aggiornamento.' );
			}
		}
		if ( ! $zip->close() ) {
			self::remove_tree( $root );
			return new WP_Error( 'elementor_edu_archive_close', 'Impossibile completare il pacchetto di aggiornamento.' );
		}
		self::remove_tree( $work );
		return [ 'package' => self::PACKAGE_MARKER, 'path' => $archive, 'core' => $core_version, 'pro' => $pro_version ];
	}

	private static function discover_pro_release() {
		$started = microtime( true );
		$deadline = $started + 20;
		$response = self::request( self::PAGE, 8, '', $deadline );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$html = wp_remote_retrieve_body( $response );
		if ( ! preg_match( '/Elementor\s+Pro\s+v([0-9]+(?:\.[0-9.]+)*)\s+Plugin/i', $html, $match ) ) {
			return new WP_Error( 'elementor_edu_release_version', 'Impossibile leggere la versione disponibile.' );
		}
		$version = rtrim( $match[1], '.' );
		$current = self::PAGE;
		$visited = [ $current ];
		for ( $depth = 0; $depth < 6 && microtime( true ) - $started < 20; $depth++ ) {
			$links = self::links( $html );
			$candidate = '';
			foreach ( $links as $link ) {
				$url = self::safe_url( $link['href'], $current );
				if ( ! $url || in_array( $url, $visited, true ) ) {
					continue;
				}
				$path = rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) );
				if ( 'elementor-pro.' . $version . '.zip' === basename( $path ) ) {
					return [ 'version' => $version, 'url' => $url, 'referer' => $current ];
				}
				$label = strtolower( trim( wp_strip_all_tags( $link['text'] ) ) );
				if ( false !== strpos( $label, 'continue to download' ) || false !== strpos( $label, 'click here to continue' ) ) {
					$candidate = $url;
					break;
				}
			}
			if ( ! $candidate ) {
				break;
			}
			$response = self::request( $candidate, 8, $current, $deadline );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$current = $candidate;
			$visited[] = $current;
			$html = wp_remote_retrieve_body( $response );
		}
		return new WP_Error( 'elementor_edu_download_missing', 'Non ho trovato il plugin da scaricare.' );
	}

	private static function validate_zip( $path, $slug, $entry, $constant, $expected ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) || $zip->numFiles < 1 ) {
			return new WP_Error( 'elementor_edu_zip_invalid', 'Il pacchetto scaricato non è un archivio ZIP valido.' );
		}
		$total = 0;
		$names = [];
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			$name = $stat['name'];
			$opsys = 0;
			$attributes = 0;
			$zip->getExternalAttributesIndex( $i, $opsys, $attributes );
			if ( 0120000 === ( ( $attributes >> 16 ) & 0170000 ) ) {
				$zip->close();
				return new WP_Error( 'elementor_edu_zip_symlink', 'Il pacchetto contiene un collegamento simbolico non consentito.' );
			}
			if ( isset( $names[ $name ] ) ) {
				$zip->close();
				return new WP_Error( 'elementor_edu_zip_duplicate', 'Il pacchetto contiene percorsi duplicati.' );
			}
			$names[ $name ] = true;
			$parts = explode( '/', str_replace( '\\', '/', $name ) );
			if ( '' === $name || '/' === $name[0] || in_array( '..', $parts, true ) || $slug !== $parts[0] ) {
				$zip->close();
				return new WP_Error( 'elementor_edu_zip_path', 'Il pacchetto contiene un percorso non consentito.' );
			}
			$total += (int) $stat['size'];
			if ( $total > self::MAX_UNPACKED ) {
				$zip->close();
				return new WP_Error( 'elementor_edu_zip_size', 'Il pacchetto supera la dimensione massima consentita.' );
			}
		}
		if ( filesize( $path ) > self::MAX_DOWNLOAD || false === ( $contents = $zip->getFromName( $entry ) ) ) {
			$zip->close();
			return new WP_Error( 'elementor_edu_zip_entry', 'Il pacchetto non contiene i file attesi.' );
		}
		$zip->close();
		if ( ! preg_match( '/^[ \t]*(?:\*[ \t]*)?Version:[ \t]*([^\r\n]+)/m', $contents, $version_match ) || trim( $version_match[1] ) !== $expected ) {
			return new WP_Error( 'elementor_edu_zip_version', 'La versione del pacchetto non corrisponde a quella rilevata.' );
		}
		if ( ! preg_match( "/define\\(\\s*'" . preg_quote( $constant, '/' ) . "',\\s*'" . preg_quote( $expected, '/' ) . "'\\s*\\)/", $contents ) ) {
			return new WP_Error( 'elementor_edu_zip_constant', 'La versione dichiarata nel pacchetto non coincide con il suo runtime.' );
		}
		return $expected;
	}

	private static function package_requirement( $path, $entry, $header ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) || false === ( $contents = $zip->getFromName( $entry ) ) ) {
			return new WP_Error( 'elementor_edu_requirement_header', 'Impossibile leggere i requisiti del pacchetto Elementor.' );
		}
		$zip->close();
		if ( ! preg_match( '/^[ \t]*(?:\*[ \t]*)?' . preg_quote( $header, '/' ) . ':[ \t]*([^\r\n]+)/m', $contents, $match ) ) {
			return new WP_Error( 'elementor_edu_requirement_missing', 'Il pacchetto Elementor non dichiara i requisiti minimi.' );
		}
		return trim( $match[1] );
	}

	private static function max_version( $first, $second ) {
		if ( is_wp_error( $first ) ) {
			return $first;
		}
		if ( is_wp_error( $second ) ) {
			return $second;
		}
		return version_compare( $first, $second, '>=' ) ? $first : $second;
	}

	private static function patch_engines( $bundle, $core, $pro ) {
		$patches = [
			[ 'elementor/elementor.php', "define( 'ELEMENTOR_PLUGIN_BASE', plugin_basename( ELEMENTOR__FILE__ ) );", "define( 'ELEMENTOR_PLUGIN_BASE', ELEMENTOR_EDU_PLUGIN_BASE );", 1 ],
			[ 'elementor-pro/elementor-pro.php', "define( 'ELEMENTOR_PRO_PLUGIN_BASE', plugin_basename( ELEMENTOR_PRO__FILE__ ) );", "define( 'ELEMENTOR_PRO_PLUGIN_BASE', ELEMENTOR_EDU_PLUGIN_BASE );", 1 ],
			[ 'elementor-pro/elementor-pro.php', "'elementor/elementor.php'", 'ELEMENTOR_PLUGIN_BASE', 4 ],
			[ 'elementor/includes/utils.php', "return 'elementor-pro/elementor-pro.php';", 'return ELEMENTOR_EDU_PLUGIN_BASE;', 1 ],
			[ 'elementor/modules/compatibility-tag/module.php', "'elementor/elementor.php',", 'ELEMENTOR_EDU_PLUGIN_BASE,', 1 ],
		];
		foreach ( [ 'elementor/includes/beta-testers.php', 'elementor/core/admin/canary-deployment.php' ] as $path ) {
			$patches[] = [ $path, 'public function __construct() {', "public function __construct() {\n\t\t// Combined Elementor Edu package updater.\n\t\tif ( defined( 'ELEMENTOR_EDU__FILE__' ) ) {\n\t\t\treturn;\n\t\t}\n", 1 ];
		}
		$patches[] = [ 'elementor-pro/license/updater.php', "\t\t\$this->setup_hooks();", "\t\tif ( defined( 'ELEMENTOR_EDU__FILE__' ) ) {\n\t\t\treturn;\n\t\t}\n\n\t\t\$this->setup_hooks();", 1 ];
		$patches[] = [ 'elementor/includes/settings/tools.php', 'private function get_rollback_versions() {', "private function get_rollback_versions() {\n\t\tif ( defined( 'ELEMENTOR_EDU__FILE__' ) ) {\n\t\t\treturn [];\n\t\t}\n", 1 ];
		$patches[] = [ 'elementor/includes/settings/tools.php', "\t\treturn \$tabs;", "\t\tunset( \$tabs['versions'] );\n\t\treturn \$tabs;", 1 ];
		$patches[] = [ 'elementor-pro/core/admin/admin.php', 'public function register_admin_tools_fields( Tools $tools ) {', "public function register_admin_tools_fields( Tools \$tools ) {\n\t\tif ( defined( 'ELEMENTOR_EDU__FILE__' ) ) {\n\t\t\treturn;\n\t\t}\n", 1 ];
		foreach ( $patches as $patch ) {
			$path = $bundle . '/includes/' . $patch[0];
			if ( ! is_file( $path ) ) {
				return new WP_Error( 'elementor_edu_patch_file', 'Il pacchetto scaricato non è compatibile con Elementor Edu.' );
			}
			$contents = file_get_contents( $path );
			if ( substr_count( $contents, $patch[1] ) !== $patch[3] ) {
				return new WP_Error( 'elementor_edu_patch_mismatch', 'Il pacchetto scaricato richiede un adattamento prima dell’installazione.' );
			}
			$contents = str_replace( $patch[1], $patch[2], $contents );
			$marker = "// Elementor Edu: adapted for the combined educational distribution.\n";
			if ( false === strpos( $contents, $marker ) ) {
				$contents = preg_replace( '/<\?php\s*\n/', "<?php\n" . $marker, $contents, 1 );
			}
			file_put_contents( $path, $contents );
		}
		return true;
	}

	private static function replace_version( $path, $label, $old, $new ) {
		$contents = file_get_contents( $path );
		$contents = preg_replace( '/^([ \t]*\*?[ \t]*' . preg_quote( $label, '/' ) . ':[ \t]*)' . preg_quote( $old, '/' ) . '/m', '${1}' . $new, $contents, 1 );
		file_put_contents( $path, $contents );
	}

	private static function replace_constant( $path, $name, $old, $new ) {
		$contents = file_get_contents( $path );
		$contents = str_replace( "define( '" . $name . "', '" . $old . "' );", "define( '" . $name . "', '" . $new . "' );", $contents );
		file_put_contents( $path, $contents );
	}

	private static function replace_header_value( $path, $label, $old, $new ) {
		$contents = file_get_contents( $path );
		$contents = preg_replace( '/^([ \t]*\*?[ \t]*' . preg_quote( $label, '/' ) . ':[ \t]*)' . preg_quote( $old, '/' ) . '/m', '${1}' . $new, $contents, 1 );
		file_put_contents( $path, $contents );
	}

	private static function links( $html ) {
		$links = [];
		if ( preg_match_all( '/<a\b([^>]*)>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				if ( preg_match( '/\bhref\s*=\s*(["\'])(.*?)\1/is', $match[1], $href ) ) {
					$links[] = [ 'href' => html_entity_decode( $href[2], ENT_QUOTES, 'UTF-8' ), 'text' => $match[2] ];
				}
			}
		}
		return $links;
	}

	private static function safe_url( $url, $base ) {
		$url = WP_Http::make_absolute_url( (string) $url, $base );
		$url = esc_url_raw( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ), [ 'https' ] );
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$host = strtolower( $parts['host'] );
		return 'gpl-astra.com' === $host || '.gpl-astra.com' === substr( $host, -14 ) ? $url : '';
	}

	private static function request( $url, $timeout, $referer = '', $deadline = 0 ) {
		if ( ! Elementor_Edu_Course_V2::updates_allowed() ) {
			return self::expiry_error();
		}
		if ( 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
			return new WP_Error( 'elementor_edu_source_scheme', 'La fonte degli aggiornamenti deve usare HTTPS.' );
		}
		$initial_host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$current = $url;
		for ( $redirects = 0; $redirects <= 3; $redirects++ ) {
			if ( ! Elementor_Edu_Course_V2::updates_allowed() ) {
				return self::expiry_error();
			}
			$remaining = $deadline ? (int) ceil( $deadline - microtime( true ) ) : $timeout;
			if ( $remaining < 1 ) {
				return new WP_Error( 'elementor_edu_source_timeout', 'Non ho trovato il plugin da scaricare.' );
			}
			$args = [ 'timeout' => min( $timeout, $remaining ), 'redirection' => 0, 'limit_response_size' => self::MAX_DOWNLOAD, 'user-agent' => 'Elementor-Edu-Updater/1.0' ];
			if ( $referer ) {
				$args['headers']['Referer'] = $referer;
			}
			$response = wp_remote_get( $current, $args );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$code = wp_remote_retrieve_response_code( $response );
			if ( $code >= 300 && $code < 400 ) {
				$location = wp_remote_retrieve_header( $response, 'location' );
				$next = WP_Http::make_absolute_url( (string) $location, $current );
				$scheme = strtolower( (string) wp_parse_url( $next, PHP_URL_SCHEME ) );
				$host = strtolower( (string) wp_parse_url( $next, PHP_URL_HOST ) );
				if ( 'https' !== $scheme || ! self::redirect_host_allowed( $initial_host, $host ) || $redirects === 3 ) {
					return new WP_Error( 'elementor_edu_redirect_host', 'La fonte ha reindirizzato a un host o protocollo non consentito.' );
				}
				$current = $next;
				continue;
			}
			if ( $code < 200 || $code >= 300 ) {
				return new WP_Error( 'elementor_edu_http', 'La fonte degli aggiornamenti non è raggiungibile.' );
			}
			return $response;
		}
		return new WP_Error( 'elementor_edu_redirect_limit', 'La fonte ha superato il numero di reindirizzamenti consentito.' );
	}

	private static function redirect_host_allowed( $initial_host, $target_host ) {
		if ( 'gpl-astra.com' === $initial_host || '.gpl-astra.com' === substr( $initial_host, -14 ) ) {
			return 'gpl-astra.com' === $target_host || '.gpl-astra.com' === substr( $target_host, -14 );
		}
		if ( 'api.wordpress.org' === $initial_host || 'downloads.wordpress.org' === $initial_host ) {
			return in_array( $target_host, [ 'api.wordpress.org', 'downloads.wordpress.org' ], true );
		}
		return false;
	}

	private static function download_to_temp( $url, $timeout, $referer = '' ) {
		$response = self::request( $url, $timeout, $referer );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = wp_remote_retrieve_body( $response );
		if ( strlen( $body ) < 1 || strlen( $body ) > self::MAX_DOWNLOAD ) {
			return new WP_Error( 'elementor_edu_download_size', 'Il pacchetto scaricato ha una dimensione non valida.' );
		}
		$path = wp_tempnam( 'elementor-edu-' );
		if ( ! $path || false === file_put_contents( $path, $body, LOCK_EX ) ) {
			if ( $path ) {
				@unlink( $path );
			}
			return new WP_Error( 'elementor_edu_download_write', 'Impossibile salvare temporaneamente il pacchetto.' );
		}
		return $path;
	}

	private static function remove_tree( $path ) {
		if ( ! file_exists( $path ) ) {
			return;
		}
		if ( is_link( $path ) || is_file( $path ) ) {
			@unlink( $path );
			return;
		}
		$items = new FilesystemIterator( $path, FilesystemIterator::SKIP_DOTS );
		foreach ( $items as $item ) {
			self::remove_tree( $item->getPathname() );
		}
		@rmdir( $path );
	}
}
