<?php
/** Refresh public translation data without installing or executing downloaded PHP. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Elementor_Edu_Translation_Updater {
	private const EVENT = 'elementor_edu_translation_refresh';
	private const STATE = 'elementor_edu_translation_state';
	private const LOCK = 'elementor_edu_translation_lock';
	private const LIMIT = 8388608;

	public static function boot() {
		add_action( 'init', [ __CLASS__, 'schedule' ], 5 );
		add_action( self::EVENT, [ __CLASS__, 'refresh' ] );
	}

	private static function versions() {
		return [ 'elementor' => ELEMENTOR_VERSION, 'elementor-pro' => ELEMENTOR_PRO_VERSION ];
	}

	public static function schedule() {
		if ( ! Elementor_Edu_Course_V3::updates_allowed() ) {
			wp_clear_scheduled_hook( self::EVENT );
			return;
		}
		$state = get_site_option( self::STATE, [] );
		$next = wp_next_scheduled( self::EVENT );
		// A manual or native upgrade also triggers a refresh on the next cron run.
		if ( ( $state['attempted_versions'] ?? [] ) !== self::versions() && $next && $next > time() + 60 ) {
			wp_clear_scheduled_hook( self::EVENT );
			$next = false;
		}
		if ( ! $next ) {
			wp_schedule_event( time() + 60, 'daily', self::EVENT );
		}
	}

	private static function cache_root() {
		return WP_LANG_DIR . '/elementor-edu';
	}

	public static function directory( $domain ) {
		$state = get_site_option( self::STATE, [] );
		if ( ( $state['versions'] ?? [] ) !== self::versions() || ! preg_match( '/^[a-f0-9-]{36}$/', $state['generation'] ?? '' ) ) {
			return false;
		}
		$directory = self::cache_root() . '/' . $state['generation'] . '/' . $domain;
		return is_readable( $directory . '/' . $domain . '-it_IT.mo' ) && is_readable( $directory . '/' . $domain . '-pt_BR.mo' ) ? $directory : false;
	}

	public static function refresh() {
		if ( ! Elementor_Edu_Course_V3::updates_allowed() ) {
			return;
		}
		// add_site_option is an atomic lock on both single-site and multisite.
		$locked_at = (int) get_site_option( self::LOCK, 0 );
		if ( $locked_at && $locked_at < time() - 15 * MINUTE_IN_SECONDS ) {
			delete_site_option( self::LOCK );
		}
		if ( ! add_site_option( self::LOCK, time() ) ) {
			return;
		}
		$state = get_site_option( self::STATE, [] );
		$generation = wp_generate_uuid4();
		$target = self::cache_root() . '/' . $generation;
		try {
			$result = self::collect( $target, dirname( ELEMENTOR_EDU__FILE__ ) . '/languages' );
			$state['attempted_at'] = time();
			$state['attempted_versions'] = self::versions();
			if ( is_wp_error( $result ) || ! Elementor_Edu_Course_V3::updates_allowed() ) {
				$state['error'] = is_wp_error( $result ) ? $result->get_error_code() : 'expired';
				self::remove_directory( $target );
			} else {
				$previous = $state['generation'] ?? '';
				$state = array_merge( $state, [ 'versions' => self::versions(), 'generation' => $generation, 'updated_at' => time(), 'error' => '', 'catalogues' => $result ] );
				update_site_option( self::STATE, $state );
				// Keep the previous generation for requests already using its pointer.
				$state['previous_generation'] = $previous;
				self::prune( [ $generation, $previous ] );
			}
			update_site_option( self::STATE, $state );
		} finally {
			delete_site_option( self::LOCK );
		}
	}

	/** Used on a staging copy before a combined engine update is archived. */
	public static function refresh_bundle( $bundle ) {
		$stage = $bundle . '/languages/.refresh-' . wp_generate_uuid4();
		$result = self::collect( $stage, $bundle . '/languages' );
		if ( ! is_wp_error( $result ) ) {
			foreach ( [ 'elementor', 'elementor-pro' ] as $domain ) {
				$target = $bundle . '/languages/' . $domain;
				$old = $target . '.previous';
				if ( ! rename( $target, $old ) ) {
					$result = new WP_Error( 'edu_translation_write' );
					break;
				}
				if ( ! rename( $stage . '/' . $domain, $target ) ) {
					rename( $old, $target );
					$result = new WP_Error( 'edu_translation_write' );
					break;
				}
				self::remove_directory( $old );
			}
		}
		if ( ! is_wp_error( $result ) ) {
			file_put_contents( $bundle . '/languages/catalogues.json', wp_json_encode( $result, JSON_PRETTY_PRINT ) );
		}
		self::remove_directory( $stage );
		// An unavailable translation source never destroys the working bundled copy.
		file_put_contents( $bundle . '/languages/status.json', wp_json_encode( [ 'checked_at' => time(), 'error' => is_wp_error( $result ) ? $result->get_error_code() : '', 'retry' => is_wp_error( $result ) ] ) );
		return $result;
	}

	private static function collect( $directory, $fallback ) {
		require_once __DIR__ . '/edu-translation-catalog.php';
		$catalogues = [];
		foreach ( [ 'elementor', 'elementor-pro' ] as $domain ) {
			foreach ( [ 'it_IT' => 'it', 'pt_BR' => 'pt-br' ] as $locale => $slug ) {
				if ( ! Elementor_Edu_Course_V3::updates_allowed() ) {
					return new WP_Error( 'edu_translation_expired' );
				}
				$url = 'elementor' === $domain
					? 'https://translate.wordpress.org/projects/wp-plugins/elementor/stable/' . $slug . '/default/export-translations/?format=po'
					: 'https://translate.elementor.com/projects/elementor-pro/' . $slug . '/default/export-translations/?format=po';
				$response = wp_safe_remote_get( $url, [ 'timeout' => 15, 'redirection' => 0, 'limit_response_size' => self::LIMIT, 'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) ] );
				if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
					return new WP_Error( 'edu_translation_source', __( 'A translation source is unavailable. Existing translations have been kept; another attempt is scheduled.', 'elementor-edu' ) );
				}
				$body = wp_remote_retrieve_body( $response );
				if ( strlen( $body ) >= self::LIMIT || false === strpos( $body, 'msgid ""' ) || false === strpos( $body, 'msgstr' ) || false !== strpos( $body, '<?php' ) ) {
					return new WP_Error( 'edu_translation_invalid' );
				}
				$target = $directory . '/' . $domain;
				if ( ! wp_mkdir_p( $target ) ) {
					return new WP_Error( 'edu_translation_write' );
				}
				$source = $target . '/' . $domain . '-' . $locale . '.po';
				if ( strlen( $body ) !== file_put_contents( $source, $body ) ) {
					return new WP_Error( 'edu_translation_write' );
				}
				$supplement_path = $fallback . '/' . $domain . '/supplements-' . $locale . '.json';
				$supplements = is_readable( $supplement_path ) ? json_decode( file_get_contents( $supplement_path ), true ) : [];
				if ( $supplements ) {
					copy( $supplement_path, $target . '/supplements-' . $locale . '.json' );
				}
				$result = Elementor_Edu_Translation_Catalog::compile( $source, $domain, $locale, $target, $supplements ?: [] );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$catalogues[ $domain ][ $locale ] = array_merge( $result, [ 'source' => $url ] );
			}
		}
		file_put_contents( $directory . '/catalogues.json', wp_json_encode( $catalogues, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		return $catalogues;
	}

	private static function prune( $keep ) {
		foreach ( glob( self::cache_root() . '/*', GLOB_ONLYDIR ) ?: [] as $path ) {
			if ( preg_match( '/^[a-f0-9-]{36}$/', basename( $path ) ) && ! in_array( basename( $path ), $keep, true ) && filemtime( $path ) < time() - DAY_IN_SECONDS ) {
				self::remove_directory( $path );
			}
		}
	}

	public static function remove_directory( $directory ) {
		if ( ! is_dir( $directory ) || is_link( $directory ) ) { return; }
		foreach ( new DirectoryIterator( $directory ) as $file ) {
			if ( $file->isDot() ) { continue; }
			if ( $file->isDir() && ! $file->isLink() ) { self::remove_directory( $file->getPathname() ); }
			else { @unlink( $file->getPathname() ); }
		}
		@rmdir( $directory );
	}
}
