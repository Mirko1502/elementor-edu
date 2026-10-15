<?php
/** Installs the bundled Hello Elementor theme only after an administrator's click. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Elementor_Edu_Hello_Theme {
	private const SLUG = 'hello-elementor';
	private const ACTION = 'elementor_edu_install_hello';

	public static function boot() {
		add_action( 'admin_notices', [ __CLASS__, 'notice' ] );
		add_action( 'network_admin_notices', [ __CLASS__, 'notice' ] );
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle_install' ] );
		// Elementor's first-run redirect otherwise hides the post-activation notice.
		add_action( 'admin_init', [ __CLASS__, 'keep_activation_screen' ], 0 );
	}

	public static function keep_activation_screen() {
		if ( get_transient( 'elementor_activation_redirect' ) && current_user_can( 'switch_themes' ) && self::SLUG !== get_template() ) {
			delete_transient( 'elementor_activation_redirect' );
		}
	}

	public static function notice() {
		// A child theme of Hello already uses the intended base: preserve it.
		if ( ! current_user_can( 'switch_themes' ) || self::SLUG === get_template() ) {
			return;
		}
		$theme = wp_get_theme( self::SLUG );
		echo '<div class="notice notice-info elementor-edu-hello-notice"><h2>Completa la configurazione con Hello Elementor</h2>';
		echo '<p>Per lavorare con Elementor ti consigliamo Hello Elementor, il tema base incluso in Elementor Edu. Il tema attuale resterà attivo finché non premi il pulsante.</p>';
		if ( is_network_admin() ) {
			echo '<p>Il tema viene scelto per ogni sito della rete. Apri l’amministrazione del sito per configurarlo.</p><p><a class="button button-primary" href="' . esc_url( admin_url( 'plugins.php' ) ) . '">Configura il tema di questo sito</a></p>';
		} elseif ( ! $theme->exists() && ! current_user_can( 'install_themes' ) ) {
			echo '<p>Chiedi a un amministratore autorizzato di installare Hello Elementor; poi potrai attivarlo qui.</p>';
		} elseif ( is_multisite() && $theme->exists() && ! $theme->is_allowed() && ! current_user_can( 'manage_network_themes' ) ) {
			echo '<p>Chiedi all’amministratore della rete di abilitare Hello Elementor per questo sito.</p>';
		} else {
			echo '<p>L’attivazione cambierà l’aspetto del sito. Il tema precedente e i contenuti saranno conservati.</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php?action=' . self::ACTION ) ) . '">';
			wp_nonce_field( self::ACTION );
			submit_button( $theme->exists() ? 'Attiva Hello Elementor' : 'Installa e attiva Hello Elementor', 'primary', 'submit', true );
			echo '</form>';
		}
		echo '</div>';
	}

	/** The request guard is separate from the installation for native WP integration tests. */
	public static function authorize_request() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( 'Usa il pulsante di installazione di Hello Elementor.', 'Elementor Edu', [ 'response' => 405 ] );
		}
		if ( ! current_user_can( 'switch_themes' ) ) {
			wp_die( 'Non hai i permessi per cambiare il tema di questo sito.', 'Elementor Edu', [ 'response' => 403 ] );
		}
		check_admin_referer( self::ACTION );
	}

	public static function handle_install() {
		self::authorize_request();
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		// admin-post.php has no admin menu bootstrap: use WP's standalone installer shell.
		global $hook_suffix;
		$hook_suffix = 'themes.php';
		set_current_screen( 'themes' );
		iframe_header( 'Hello Elementor' );
		echo '<div class="wrap"><h1>Configurazione di Hello Elementor</h1>';
		$skin = new WP_Upgrader_Skin( [
			'url' => admin_url( 'admin-post.php?action=' . self::ACTION ),
			'nonce' => self::ACTION,
			'title' => 'Hello Elementor',
		] );
		$result = self::install_and_activate( $skin );
		if ( is_wp_error( $result ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
		} elseif ( true === $result ) {
			echo '<div class="notice notice-success"><p>Hello Elementor è attivo come tema del sito.</p></div>';
			echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'themes.php' ) ) . '">Vai ai temi</a> <a class="button" href="' . esc_url( home_url( '/' ) ) . '">Visita il sito</a></p>';
		}
		// false means WordPress displayed its filesystem credentials form.
		echo '</div>';
		iframe_footer();
		exit;
	}

	/** @return true|false|WP_Error False allows the native credentials form to continue. */
	public static function install_and_activate( $skin ) {
		if ( ! current_user_can( 'switch_themes' ) ) {
			return new WP_Error( 'edu_theme_forbidden', 'Non hai i permessi per cambiare il tema.' );
		}
		if ( self::SLUG === get_template() ) {
			return true;
		}
		$theme = wp_get_theme( self::SLUG );
		if ( ! $theme->exists() ) {
			if ( ! current_user_can( 'install_themes' ) ) {
				return new WP_Error( 'edu_install_forbidden', 'Non hai i permessi per installare temi.' );
			}
			$package = __DIR__ . '/../packages/hello-elementor.zip';
			if ( ! is_readable( $package ) ) {
				return new WP_Error( 'edu_theme_missing', 'Il pacchetto Hello Elementor non è disponibile. Reinstalla lo ZIP completo di Elementor Edu.' );
			}
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			$upgrader = new Theme_Upgrader( $skin );
			$result = $upgrader->install( $package );
			if ( true !== $result ) {
				return $result;
			}
			wp_clean_themes_cache();
			$theme = wp_get_theme( self::SLUG );
		}
		// Never overwrite an existing theme, even if the bundled version differs.
		if ( ! $theme->exists() || $theme->errors() ) {
			return new WP_Error( 'edu_theme_invalid', 'Hello Elementor risulta incompleto o non valido. Ripristina il tema dalla pagina Aspetto > Temi e riprova.' );
		}
		$requirements = validate_theme_requirements( self::SLUG );
		if ( is_wp_error( $requirements ) ) {
			return $requirements;
		}
		if ( is_multisite() && ! $theme->is_allowed() ) {
			if ( ! current_user_can( 'manage_network_themes' ) ) {
				return new WP_Error( 'edu_theme_not_allowed', 'L’amministratore della rete deve abilitare Hello Elementor per questo sito.' );
			}
			// Enable only for the current site, without changing other sites' themes.
			$allowed = (array) get_option( 'allowedthemes', [] );
			$allowed[ self::SLUG ] = true;
			update_option( 'allowedthemes', $allowed );
		}
		switch_theme( self::SLUG );
		if ( self::SLUG !== get_stylesheet() ) {
			return new WP_Error( 'edu_theme_activation_failed', 'Hello Elementor è installato, ma non è stato possibile attivarlo. Riprova da Aspetto > Temi.' );
		}
		return true;
	}
}
