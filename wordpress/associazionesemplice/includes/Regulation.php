<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Regolamento dell'ente: testo (o indirizzo di una pagina) e accettazione obbligatoria all'iscrizione.
 * Si registra quando e come è stato accettato (sul sito, modulo cartaceo…) e per quale versione: cambiando la versione, tutti devono accettare di nuovo.
 */
final class Regulation {

	const SOURCES = array( 'web' => 'Sul sito', 'paper' => 'Modulo cartaceo', 'verbal' => 'A voce / altro', 'import' => 'Importato' );

	public static function enabled(): bool {
		return ! empty( Settings::get( 'rules_enabled' ) ) && ( '' !== trim( (string) Settings::get( 'rules_text' ) ) || '' !== (string) Settings::get( 'rules_url' ) );
	}

	public static function version(): string {
		$v = trim( (string) Settings::get( 'rules_version' ) );
		return '' === $v ? '1' : $v;
	}

	public static function title(): string {
		$t = trim( (string) Settings::get( 'rules_title' ) );
		return '' === $t ? 'Regolamento' : $t;
	}

	/** La persona ha accettato la versione in vigore? (senza regolamento attivo: sì). */
	public static function accepted( array $p ): bool {
		return ! self::enabled() || ( ! empty( $p['rules_accepted_at'] ) && (string) $p['rules_accepted_version'] === self::version() );
	}

	/** Chi deve accettare: i soci (non gli ospiti, che partecipano tramite un socio). */
	public static function applies_to( array $p ): bool {
		return self::enabled() && MemberType::is_member( (string) $p['type'] );
	}

	public static function accept( int $person_id, string $source = 'web' ): void {
		$p = Plugin::people()->get( $person_id );
		if ( ! $p ) {
			throw new \InvalidArgumentException( 'Persona non trovata.' );
		}
		if ( ! isset( self::SOURCES[ $source ] ) ) {
			throw new \InvalidArgumentException( 'Modalità non valida.' );
		}
		Db::db()->update( Db::t( 'people' ), array( 'rules_accepted_at' => Db::now(), 'rules_accepted_version' => self::version(), 'rules_accepted_source' => $source ), array( 'id' => $person_id ) );
		Audit::log( 'rules.accepted', 'person', $person_id, array( 'version' => self::version(), 'source' => $source ) );
	}

	public static function clear( int $person_id ): void {
		Db::db()->update( Db::t( 'people' ), array( 'rules_accepted_at' => null, 'rules_accepted_version' => null, 'rules_accepted_source' => null ), array( 'id' => $person_id ) );
		Audit::log( 'rules.cleared', 'person', $person_id );
	}

	/** Testo del regolamento (in un riquadro scorrevole) e/o il collegamento alla pagina. */
	public static function box_html(): string {
		$html = '';
		$text = trim( (string) Settings::get( 'rules_text' ) );
		if ( '' !== $text ) {
			$html .= '<div class="asemf-rules" style="max-height:220px;overflow:auto;border:1px solid #c3c4c7;border-radius:8px;padding:10px 12px;margin:8px 0;white-space:pre-wrap;background:#fff">' . esc_html( $text ) . '</div>';
		}
		$url = (string) Settings::get( 'rules_url' );
		if ( '' !== $url ) {
			$html .= '<p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">Apri il ' . esc_html( mb_strtolower( self::title(), 'UTF-8' ) ) . ' in una nuova pagina</a></p>';
		}
		return $html;
	}

	/** Messaggio se il socio deve accettare il regolamento per poter prenotare, altrimenti ''. */
	public static function booking_block( array $actor ): string {
		if ( ! self::applies_to( $actor ) || self::accepted( $actor ) || empty( Settings::get( 'rules_block_booking' ) ) ) {
			return '';
		}
		return 'Per prenotare devi prima accettare il ' . mb_strtolower( self::title(), 'UTF-8' ) . ': lo trovi in cima alla tua area riservata.';
	}
}
