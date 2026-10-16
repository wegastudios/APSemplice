<?php
namespace Portale;

/**
 * Script di monitoraggio (header e footer) con consenso ai cookie.
 * Gli script «necessari» si stampano subito; gli altri finiscono dentro <template>, che è inerte: partono solo quando la persona
 * dà il consenso alla loro categoria (vedi assets/portale.js).
 */
final class Tracking {

	const CATEGORIES = array( 'analytics' => 'Statistiche', 'marketing' => 'Marketing', 'necessary' => 'Necessari (sempre attivi)' );

	const DEFAULT_TEXT = 'Usiamo cookie tecnici per far funzionare il sito. Con il tuo consenso usiamo anche strumenti di statistica e marketing. Puoi cambiare idea in ogni momento dal link «Preferenze cookie» in fondo alla pagina.';

	public static function conf(): array {
		return (array) Config::get( 'tracking', array() ) + array( 'head' => '', 'head_cat' => 'analytics', 'foot' => '', 'foot_cat' => 'analytics', 'banner_text' => '', 'rev' => 1 );
	}

	/** Controllo prima di salvare: lo snippet non può chiudere il <template> che lo contiene. */
	public static function validate( string $html ): string {
		$html = trim( $html );
		if ( mb_strlen( $html ) > 20000 ) {
			throw new UserError( 'Lo snippet è troppo lungo (massimo 20.000 caratteri).' );
		}
		if ( false !== stripos( $html, '</template' ) ) {
			throw new UserError( 'Lo snippet non può contenere «</template».' );
		}
		return $html;
	}

	/** Categorie non necessarie per cui c'è almeno uno script: se non ce ne sono, non serve alcun banner. */
	public static function categories(): array {
		$c    = self::conf();
		$cats = array();
		foreach ( array( 'head', 'foot' ) as $slot ) {
			if ( '' !== trim( (string) $c[ $slot ] ) && 'necessary' !== $c[ $slot . '_cat' ] && isset( self::CATEGORIES[ $c[ $slot . '_cat' ] ] ) ) {
				$cats[ $c[ $slot . '_cat' ] ] = self::CATEGORIES[ $c[ $slot . '_cat' ] ];
			}
		}
		return $cats;
	}

	public static function banner(): bool {
		return (bool) self::categories();
	}

	public static function bannerText(): string {
		$t = trim( (string) self::conf()['banner_text'] );
		return '' !== $t ? $t : self::DEFAULT_TEXT;
	}

	/** HTML da stampare nello slot `head` o `foot`. */
	public static function slot( string $slot ): string {
		$c    = self::conf();
		$html = trim( (string) ( $c[ $slot ] ?? '' ) );
		if ( '' === $html ) {
			return '';
		}
		$cat = (string) $c[ $slot . '_cat' ];
		return 'necessary' === $cat || ! isset( self::CATEGORIES[ $cat ] )
			? $html . "\n"
			: '<template data-cc-cat="' . htmlspecialchars( $cat, ENT_QUOTES ) . '" data-cc-slot="' . htmlspecialchars( $slot, ENT_QUOTES ) . '">' . $html . "</template>\n";
	}
}
