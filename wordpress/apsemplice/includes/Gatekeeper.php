<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Soci e volontari lavorano nell'area riservata del sito, non in wp-admin: l'area di amministrazione
 * resta solo per gli amministratori. Vale per gli utenti che hanno SOLO il ruolo "Socio APS": chi ha anche
 * altri ruoli (editor, amministratore…) non viene toccato.
 */
final class Gatekeeper {

	/** @param string[] $roles ruoli dell'utente */
	public static function is_member_only( array $roles ): bool {
		return array( Plugin::ROLE_MEMBER ) === array_values( $roles );
	}

	public static function register(): void {
		add_filter( 'show_admin_bar', array( __CLASS__, 'filter_admin_bar' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'filter_login_redirect' ), 10, 3 );
		add_action( 'admin_init', array( __CLASS__, 'keep_out_of_admin' ) );
	}

	private static function current_is_member_only(): bool {
		$user = wp_get_current_user();
		return $user && $user->exists() && self::is_member_only( (array) $user->roles ) && ! user_can( $user, Plugin::CAP_OPS ); // presidente e vicepresidente lavorano come la segreteria
	}

	/** Pagina dell'area riservata scelta nelle impostazioni, altrimenti la home. */
	public static function area_url(): string {
		$id = (int) Settings::get( 'member_area_page_id' );
		if ( $id > 0 && 'publish' === get_post_status( $id ) ) {
			$url = get_permalink( $id );
			if ( $url ) {
				return $url;
			}
		}
		return home_url( '/' );
	}

	public static function filter_admin_bar( $show ) {
		return self::current_is_member_only() ? false : $show;
	}

	public static function filter_login_redirect( $redirect_to, $requested, $user ) {
		if ( $user instanceof \WP_User && self::is_member_only( (array) $user->roles ) && ! user_can( $user, Plugin::CAP_OPS ) ) {
			return self::area_url();
		}
		return $redirect_to;
	}

	/** Chi non è amministratore non deve restare in wp-admin (ajax e admin-post restano raggiungibili: controllano loro i permessi). */
	public static function keep_out_of_admin(): void {
		if ( ! self::current_is_member_only() || wp_doing_ajax() ) {
			return;
		}
		global $pagenow;
		if ( 'admin-post.php' === $pagenow || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}
		wp_safe_redirect( self::area_url() );
		exit;
	}
}
