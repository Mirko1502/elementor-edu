<?php
/** Deploy the bundled engines as real, independently registered WordPress plugins. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Elementor_Edu_Engines {
	const PLUGINS = [ 'elementor/elementor.php', 'elementor-pro/elementor-pro.php' ];
	const STATE = 'elementor_edu_engines_version';
	const JOURNAL = 'elementor_edu_engines_transaction';
	const MARKER = '.elementor-edu.json';
	private static $error;

	public static function boot() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		register_activation_hook( ELEMENTOR_EDU__FILE__, [ __CLASS__, 'activate' ] );
		add_action( 'admin_notices', [ __CLASS__, 'notice' ] );
		add_action( 'network_admin_notices', [ __CLASS__, 'notice' ] );
		add_action( 'wp_loaded', [ __CLASS__, 'finish_activation' ] );
		if ( is_plugin_active( ELEMENTOR_EDU_PLUGIN_BASE ) ) {
			$result = self::deploy();
			if ( is_wp_error( $result ) ) {
				self::$error = $result;
				if ( 'edu_engine_recovery' === $result->get_error_code() ) { wp_die( esc_html__( 'Engine recovery needs attention. Backups were kept.', 'elementor-edu' ), 'Elementor Edu', [ 'response' => 503 ] ); }
			} elseif ( ! self::enrolled() ) {
				self::activate( is_plugin_active_for_network( ELEMENTOR_EDU_PLUGIN_BASE ) );
			}
		}
		// Also handles the first request after migration: WordPress took its active
		// plugin snapshot before Edu installed the engines. require_once prevents
		// a second load when WordPress later reaches their normal entries.
		foreach ( self::PLUGINS as $plugin ) {
			if ( is_plugin_active( $plugin ) && is_readable( WP_PLUGIN_DIR . '/' . $plugin ) ) {
				wp_register_plugin_realpath( WP_PLUGIN_DIR . '/' . $plugin );
				require_once WP_PLUGIN_DIR . '/' . $plugin;
			}
		}
		// A failed migration must not take down an existing combined installation.
		// This temporary fallback is never used alongside a separate engine.
		if ( self::$error && ! defined( 'ELEMENTOR_VERSION' ) && ! defined( 'ELEMENTOR_PRO_VERSION' )
			&& ! is_file( WP_PLUGIN_DIR . '/' . self::PLUGINS[0] ) && ! is_file( WP_PLUGIN_DIR . '/' . self::PLUGINS[1] ) && self::valid_payload() ) {
			require_once dirname( ELEMENTOR_EDU__FILE__ ) . '/includes/elementor/elementor.php';
			require_once dirname( ELEMENTOR_EDU__FILE__ ) . '/includes/elementor-pro/elementor-pro.php';
		}
	}

	private static function valid_payload() {
		try {
			$root = dirname( ELEMENTOR_EDU__FILE__ );
			$manifest = json_decode( file_get_contents( $root . '/bundle.json' ), true );
			foreach ( [ 'elementor', 'elementor-pro' ] as $slug ) {
				if ( empty( $manifest['engine_files'][ $slug ] ) || self::hashes( $root . '/includes/' . $slug ) != $manifest['engine_files'][ $slug ] ) { return false; }
			}
			return true;
		} catch ( Throwable $error ) { return false; }
	}

	public static function ready() {
		return ! self::$error && ELEMENTOR_EDU_VERSION === get_site_option( self::STATE )
			&& is_readable( WP_PLUGIN_DIR . '/' . self::PLUGINS[0] ) && is_readable( WP_PLUGIN_DIR . '/' . self::PLUGINS[1] );
	}

	private static function enrolled() {
		return is_plugin_active_for_network( ELEMENTOR_EDU_PLUGIN_BASE )
			? get_site_option( 'elementor_edu_engines_network_enrolled' ) : get_option( 'elementor_edu_engines_enrolled' );
	}

	public static function activate( $network_wide = false ) {
		$result = self::deploy();
		if ( ! is_wp_error( $result ) ) {
			foreach ( self::PLUGINS as $plugin ) {
				$early = ! did_action( 'wp_loaded' );
				$inactive = $network_wide ? ! is_plugin_active_for_network( $plugin ) : ! is_plugin_active( $plugin );
				if ( $early && $inactive ) {
					$pending = get_option( 'elementor_edu_engine_activation_pending', [] );
					$pending[ $plugin ] = (bool) $network_wide;
					update_option( 'elementor_edu_engine_activation_pending', $pending, false );
				}
				// During migration pluggable functions and the rewrite API are not
				// ready yet. Register the real plugins now, run their lifecycle later.
				$result = activate_plugin( $plugin, '', $network_wide, $early );
				if ( is_wp_error( $result ) ) { break; }
			}
		}
		if ( is_wp_error( $result ) ) {
			self::$error = $result;
			return;
		}
		if ( $network_wide ) {
			update_site_option( 'elementor_edu_engines_network_enrolled', true );
		} else {
			update_option( 'elementor_edu_engines_enrolled', true, false );
		}
	}

	public static function finish_activation() {
		$pending = get_option( 'elementor_edu_engine_activation_pending', [] );
		foreach ( self::PLUGINS as $plugin ) {
			if ( isset( $pending[ $plugin ] ) && is_plugin_active( $plugin ) ) {
				do_action( 'activate_plugin', $plugin, $pending[ $plugin ] );
				do_action( 'activate_' . $plugin, $pending[ $plugin ] );
				do_action( 'activated_plugin', $plugin, $pending[ $plugin ] );
				unset( $pending[ $plugin ] );
				update_option( 'elementor_edu_engine_activation_pending', $pending, false );
			}
		}
		if ( ! $pending ) { delete_option( 'elementor_edu_engine_activation_pending' ); }
	}

	private static function root() { return WP_PLUGIN_DIR . '/.elementor-edu-transaction'; }

	/** A checked direct-filesystem deployment; never overwrite unmanaged folders. */
	public static function deploy() {
		if ( ELEMENTOR_EDU_VERSION === get_site_option( self::STATE ) && ! get_site_option( self::JOURNAL ) ) { return true; }
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( 'direct' !== get_filesystem_method( [], WP_PLUGIN_DIR ) || ! is_writable( WP_PLUGIN_DIR ) ) {
			return new WP_Error( 'edu_engine_filesystem', 'The plugin directory must be writable using the direct filesystem method. Existing engines were kept.' );
		}
		$lock = @fopen( WP_CONTENT_DIR . '/.elementor-edu-engines.lock', 'c' );
		if ( ! $lock || ! flock( $lock, LOCK_EX ) ) {
			if ( $lock ) { fclose( $lock ); }
			return new WP_Error( 'edu_engine_lock', 'Could not lock the engine installation. Please retry.' );
		}
		try {
			// Another request may have completed the same deployment while we waited.
			foreach ( [ self::STATE, self::JOURNAL ] as $key ) {
				wp_cache_delete( $key, 'options' );
				wp_cache_delete( get_current_network_id() . ':' . $key, 'site-options' );
			}
			wp_cache_delete( 'alloptions', 'options' );
			self::recover();
			if ( ELEMENTOR_EDU_VERSION === get_site_option( self::STATE ) ) { return true; }
			if ( defined( 'ELEMENTOR_VERSION' ) || defined( 'ELEMENTOR_PRO_VERSION' ) ) {
				throw new RuntimeException( 'Engines are already loaded. Reload the page to finish installation.' );
			}
			$manifest = json_decode( file_get_contents( dirname( ELEMENTOR_EDU__FILE__ ) . '/bundle.json' ), true );
			$old = [];
			foreach ( self::PLUGINS as $plugin ) {
				$slug = dirname( $plugin );
				$target = WP_PLUGIN_DIR . '/' . $slug;
				$old[ $slug ] = is_dir( $target );
				if ( file_exists( $target ) || is_link( $target ) ) {
					$marker = self::marker( $target );
					if ( is_link( $target ) || ! $marker || self::hashes( $target ) !== $marker['files'] ) {
						throw new RuntimeException( 'An existing Elementor installation is unmanaged or modified. Its files were kept.' );
					}
					$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin, false, false );
					if ( version_compare( $data['Version'], $manifest[ str_replace( '-', '_', $slug ) ], '>' ) ) {
						throw new RuntimeException( 'A newer engine is already installed. Downgrades are not allowed.' );
					}
				}
			}
			self::remove( self::root() );
			if ( ! wp_mkdir_p( self::root() . '/new' ) || ! wp_mkdir_p( self::root() . '/old' ) ) {
				throw new RuntimeException( 'Could not prepare the engine installation. Existing engines were kept.' );
			}
			file_put_contents( self::root() . '/index.php', '<?php // No direct access.' );
			file_put_contents( self::root() . '/.htaccess', 'Deny from all' );
			foreach ( self::PLUGINS as $plugin ) {
				$slug = dirname( $plugin );
				$source = dirname( ELEMENTOR_EDU__FILE__ ) . '/includes/' . $slug;
				$expected = $manifest['engine_files'][ $slug ] ?? [];
				ksort( $expected );
				if ( ! $expected || self::hashes( $source ) !== $expected ) {
					throw new RuntimeException( 'The bundled engines failed integrity verification. Reinstall the complete Edu ZIP.' );
				}
				$stage = self::root() . '/new/' . $slug;
				self::copy( $source, $stage );
				if ( self::hashes( $stage ) !== $expected || false === file_put_contents( $stage . '/' . self::MARKER, wp_json_encode( [ 'version' => ELEMENTOR_EDU_VERSION, 'files' => $expected ] ) ) ) {
					throw new RuntimeException( 'The staged engines failed verification. Existing engines were kept.' );
				}
			}
			$journal = [ 'version' => ELEMENTOR_EDU_VERSION, 'old' => $old ];
			update_site_option( self::JOURNAL, $journal );
			if ( get_site_option( self::JOURNAL ) !== $journal ) { throw new RuntimeException( 'Could not save the engine recovery record.' ); }
			foreach ( self::PLUGINS as $plugin ) {
				$slug = dirname( $plugin );
				$target = WP_PLUGIN_DIR . '/' . $slug;
				if ( $old[ $slug ] && ! rename( $target, self::root() . '/old/' . $slug ) ) { throw new RuntimeException( 'Could not back up an engine.' ); }
				if ( ! rename( self::root() . '/new/' . $slug, $target ) ) { throw new RuntimeException( 'Could not install an engine.' ); }
			}
			update_site_option( self::STATE, ELEMENTOR_EDU_VERSION );
			if ( ELEMENTOR_EDU_VERSION !== get_site_option( self::STATE ) ) { throw new RuntimeException( 'Could not save the engine installation state.' ); }
			self::recover();
			wp_clean_plugins_cache( false );
			return true;
		} catch ( Throwable $error ) {
			try { self::recover(); } catch ( Throwable $recovery ) {
				return new WP_Error( 'edu_engine_recovery', $error->getMessage() . ' ' . $recovery->getMessage() );
			}
			return new WP_Error( 'edu_engine_install', $error->getMessage() );
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	/** Recover an interrupted pair replacement before loading either engine. */
	private static function recover() {
		$journal = get_site_option( self::JOURNAL );
		if ( ! $journal ) { return; }
		if ( ! is_array( $journal ) || ! isset( $journal['version'], $journal['old']['elementor'], $journal['old']['elementor-pro'] ) ) {
			throw new RuntimeException( 'The engine recovery record is damaged. Existing files were kept.' );
		}
		if ( get_site_option( self::STATE ) !== $journal['version'] ) {
			foreach ( self::PLUGINS as $plugin ) {
				$slug = dirname( $plugin );
				$backup = self::root() . '/old/' . $slug;
				$target = WP_PLUGIN_DIR . '/' . $slug;
				if ( is_dir( $backup ) ) {
					self::remove( $target );
					if ( ! rename( $backup, $target ) ) { throw new RuntimeException( 'Engine recovery needs attention. Backups were kept.' ); }
				} elseif ( ! $journal['old'][ $slug ] ) {
					$marker = self::marker( $target );
					if ( $marker && $marker['version'] === $journal['version'] ) { self::remove( $target ); }
				}
			}
		}
		delete_site_option( self::JOURNAL );
		self::remove( self::root() );
	}

	private static function marker( $path ) {
		$file = $path . '/' . self::MARKER;
		$data = is_readable( $file ) ? json_decode( file_get_contents( $file ), true ) : null;
		return is_array( $data ) && isset( $data['version'] ) && is_array( $data['files'] ?? null ) ? $data : false;
	}

	public static function hashes( $directory ) {
		$hashes = [];
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( $file->isLink() ) { throw new RuntimeException( 'Symbolic links are not supported in managed engines.' ); }
			if ( $file->isFile() ) {
				$relative = str_replace( DIRECTORY_SEPARATOR, '/', substr( $file->getPathname(), strlen( $directory ) + 1 ) );
				if ( self::MARKER !== $relative ) { $hashes[ $relative ] = hash_file( 'sha256', $file->getPathname() ); }
			}
		}
		ksort( $hashes );
		return $hashes;
	}

	private static function copy( $source, $target ) {
		if ( ! wp_mkdir_p( $target ) ) { throw new RuntimeException( 'Could not create an engine directory.' ); }
		foreach ( new DirectoryIterator( $source ) as $file ) {
			if ( $file->isDot() ) { continue; }
			$to = $target . '/' . $file->getFilename();
			if ( $file->isLink() ) { throw new RuntimeException( 'Symbolic links are not supported in managed engines.' ); }
			if ( $file->isDir() ) { self::copy( $file->getPathname(), $to ); }
			elseif ( ! copy( $file->getPathname(), $to ) ) { throw new RuntimeException( 'Could not copy an engine file.' ); }
		}
	}

	private static function remove( $path ) {
		if ( ! file_exists( $path ) && ! is_link( $path ) ) { return; }
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			foreach ( new DirectoryIterator( $path ) as $file ) {
				if ( ! $file->isDot() ) { self::remove( $file->getPathname() ); }
			}
			if ( ! rmdir( $path ) ) { throw new RuntimeException( 'Could not remove an engine staging directory.' ); }
		} elseif ( ! unlink( $path ) ) { throw new RuntimeException( 'Could not remove an engine staging file.' ); }
	}

	public static function notice() {
		if ( self::$error && current_user_can( 'install_plugins' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Elementor Edu could not finish installing its separate plugins. Existing files were preserved. Check filesystem permissions and any existing Elementor installations, then reload this page.', 'elementor-edu' ) . '</p><p><code>' . esc_html( self::$error->get_error_code() ) . ': ' . esc_html( self::$error->get_error_message() ) . '</code></p></div>';
		}
	}
}
