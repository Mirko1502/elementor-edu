<?php
/** Local translations for the combined distribution; never changes WordPress's locale. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Elementor_Edu_I18n {
	private static $root;
	private const DOMAINS = [ 'elementor-edu', 'elementor', 'elementor-pro' ];
	private const LOCALES = [ 'it_IT', 'pt_BR' ];

	public static function boot( $root ) {
		if ( self::$root ) {
			return;
		}
		self::$root = $root;
		add_action( 'init', [ __CLASS__, 'load' ], -100 );
		add_action( 'change_locale', [ __CLASS__, 'load' ], 20 );
		add_filter( 'load_translation_file', [ __CLASS__, 'translation_file' ], PHP_INT_MAX, 3 );
		add_filter( 'load_textdomain_mofile', [ __CLASS__, 'mo_file' ], PHP_INT_MAX, 2 );
		add_filter( 'pre_load_script_translations', [ __CLASS__, 'script_translations' ], PHP_INT_MAX, 4 );
		if ( did_action( 'init' ) ) {
			self::load();
		}
	}

	public static function locale( $locale = null ) {
		$locale = str_replace( '-', '_', $locale ?? determine_locale() );
		return in_array( $locale, self::LOCALES, true ) ? $locale : 'en_US';
	}

	/** A versioned cache is selected only when it is complete and belongs to these engines. */
	public static function directory( $domain ) {
		if ( 'elementor-edu' !== $domain && class_exists( 'Elementor_Edu_Translation_Updater', false ) ) {
			$cached = Elementor_Edu_Translation_Updater::directory( $domain );
			if ( $cached ) {
				return $cached;
			}
		}
		return self::$root . '/languages/' . $domain;
	}

	public static function load() {
		foreach ( self::DOMAINS as $domain ) {
			unload_textdomain( $domain, true );
			load_plugin_textdomain( $domain, false, dirname( plugin_basename( self::$root . '/elementor-edu.php' ) ) . '/languages/' . $domain );
			// Keep the actual request locale for WP's translation controller.
			load_textdomain( $domain, self::directory( $domain ) . '/' . $domain . '-' . self::locale() . '.mo', determine_locale() );
		}
	}

	public static function mo_file( $file, $domain ) {
		return in_array( $domain, self::DOMAINS, true )
			? self::directory( $domain ) . '/' . $domain . '-' . self::locale() . '.mo' : $file;
	}

	public static function translation_file( $file, $domain, $locale ) {
		return in_array( $domain, self::DOMAINS, true )
			? self::directory( $domain ) . '/' . $domain . '-' . self::locale( $locale ) . '.mo' : $file;
	}

	/** Route scripts by their real upstream path, including chunks from the editor. */
	public static function script_translations( $translations, $file, $handle, $domain ) {
		if ( ! in_array( $domain, self::DOMAINS, true ) ) {
			return $translations;
		}
		$locale = self::locale();
		$empty = wp_json_encode( [ 'locale_data' => [ 'messages' => [ '' => [ 'domain' => $domain, 'lang' => $locale, 'plural-forms' => 'nplurals=2; plural=(n != 1);' ] ] ] ] );
		if ( 'en_US' === $locale ) {
			return $empty;
		}
		$registered = wp_scripts()->registered[ $handle ] ?? null;
		$src = $registered ? wp_parse_url( $registered->src, PHP_URL_PATH ) : '';
		$prefix = '/includes/' . $domain . '/';
		$position = $src ? strpos( $src, $prefix ) : false;
		if ( false === $position ) {
			return $empty;
		}
		$relative = substr( $src, $position + strlen( $prefix ) );
		$relative = preg_replace( '/\.min\.js$/', '.js', $relative );
		$base = self::directory( $domain ) . '/' . $domain . '-' . $locale;
		// The combined catalogue includes the script and its dynamically imported chunks.
		$index_file = $base . '-scripts.json';
		static $indexes = [];
		if ( ! isset( $indexes[ $index_file ] ) ) {
			$indexes[ $index_file ] = is_readable( $index_file ) ? json_decode( file_get_contents( $index_file ), true ) : [];
		}
		$index = $indexes[ $index_file ];
		$key = $index[ $relative ] ?? $index[ preg_replace( '/\.js$/', '.strings.js', $relative ) ] ?? $index['*'] ?? null;
		if ( ! is_string( $key ) || ! preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
			return $empty;
		}
		$path = $base . '-' . $key . '.json';
		return is_readable( $path ) ? file_get_contents( $path ) : $empty;
	}
}
