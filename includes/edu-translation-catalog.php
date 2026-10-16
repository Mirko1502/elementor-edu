<?php
/** Compile data-only gettext sources with WordPress's own PO/MO readers. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Elementor_Edu_Translation_Catalog {
	public static function compile( $source, $domain, $locale, $directory, $supplements = [] ) {
		require_once ABSPATH . WPINC . '/pomo/po.php';
		require_once ABSPATH . WPINC . '/pomo/mo.php';
		$po = new PO();
		if ( ! $po->import_from_file( $source ) || count( $po->entries ) < 20 ) {
			return new WP_Error( 'edu_translation_invalid', __( 'The translation catalogue is invalid. Existing translations have been kept.', 'elementor-edu' ) );
		}
		$po->set_header( 'Language', $locale );
		$po->set_header( 'Content-Type', 'text/plain; charset=UTF-8' );
		$po->set_header( 'Plural-Forms', 'pt_BR' === $locale ? 'nplurals=2; plural=(n > 1);' : 'nplurals=2; plural=(n != 1);' );
		$mo = new MO();
		$mo->set_headers( $po->headers );
		$messages = [];
		$scripts = [];
		foreach ( $po->entries as $entry ) {
			$key = $entry->key();
			if ( ( empty( $entry->translations[0] ) || in_array( 'fuzzy', $entry->flags, true ) ) && isset( $supplements[ $key ] ) ) {
				$entry->translations = (array) $supplements[ $key ];
				$entry->flags = array_diff( $entry->flags, [ 'fuzzy' ] );
			}
			if ( empty( $entry->translations[0] ) || in_array( 'fuzzy', $entry->flags, true ) ) {
				continue;
			}
			// Numbered sprintf arguments may be reordered by a translation.
			$expected = self::placeholders( $entry->singular );
			$valid = true;
			foreach ( $entry->translations as $translated ) {
				if ( $expected !== self::placeholders( $translated ) ) { $valid = false; break; }
			}
			if ( ! $valid && isset( $supplements[ $key ] ) ) {
				$entry->translations = (array) $supplements[ $key ];
				$valid = true;
				foreach ( $entry->translations as $translated ) {
					if ( $expected !== self::placeholders( $translated ) ) { $valid = false; break; }
				}
			}
			if ( ! $valid ) { continue; }

			$mo->add_entry( $entry );
			foreach ( $entry->references as $reference ) {
				$relative = preg_replace( '/:[0-9]+$/', '', $reference );
				$relative = preg_replace( '#^' . preg_quote( $domain, '#' ) . '/#', '', $relative );
				$relative = preg_replace( '/\.min\.js$/', '.js', $relative );
				if ( ! preg_match( '#^assets/.*\.js$#', $relative ) || false !== strpos( $relative, '..' ) ) {
					continue;
				}
				$scripts[ $relative ][ $key ] = array_values( $entry->translations );
				$messages[ $key ] = array_values( $entry->translations );
			}
		}
		if ( ! wp_mkdir_p( $directory ) ) {
			return self::write_error();
		}
		$base = $directory . '/' . $domain . '-' . $locale;
		if ( ! $mo->export_to_file( $base . '.mo' ) ) {
			return self::write_error();
		}
		// Merge dynamic chunks' generated .strings.js catalogues with their entry point.
		foreach ( array_keys( $scripts ) as $relative ) {
			if ( str_ends_with( $relative, '.strings.js' ) ) {
				$entrypoint = substr( $relative, 0, -strlen( '.strings.js' ) ) . '.js';
				$scripts[ $entrypoint ] = array_merge( $scripts[ $entrypoint ] ?? [], $scripts[ $relative ] );
			}
		}
		$scripts['*'] = $messages;
		$index = [];
		foreach ( $scripts as $relative => $entries ) {
			$hash = md5( $relative );
			$index[ $relative ] = $hash;
			$entries[''] = [ 'domain' => $domain, 'lang' => $locale, 'plural-forms' => $po->get_header( 'Plural-Forms' ) ];
			$json = wp_json_encode( [ 'domain' => $domain, 'locale_data' => [ 'messages' => $entries ] ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( false === file_put_contents( $base . '-' . $hash . '.json', $json ) ) {
				return self::write_error();
			}
		}
		if ( false === file_put_contents( $base . '-scripts.json', wp_json_encode( $index ) ) ) {
			return self::write_error();
		}
		return [ 'translated' => count( $mo->entries ), 'total' => count( $po->entries ), 'source_sha256' => hash_file( 'sha256', $source ) ];
	}

	private static function placeholders( $text ) {
		preg_match_all( '/(?<!%)%(?:([0-9]+)\$)?[-+0#]*(?:[0-9]+)?(?:\.[0-9]+)?([bcdeEfFgGosuxX])/', $text, $matches, PREG_SET_ORDER );
		$result = []; $position = 0;
		foreach ( $matches as $match ) {
			$result[] = ( ! empty( $match[1] ) ? (int) $match[1] : ++$position ) . ':' . $match[2];
		}
		sort( $result );
		return $result;
	}

	private static function write_error() {
		return new WP_Error( 'edu_translation_write', __( 'Could not save translations. Existing translations have been kept.', 'elementor-edu' ) );
	}
}
