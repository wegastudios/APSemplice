<?php
namespace Portale;

/** Informativa privacy e cookie: testi di base modificabili dal pannello, con i dati del titolare. */
final class Legal {

	const PAGES = array(
		'privacy' => array( 'Informativa sulla privacy', 'privacy_body' ),
		'cookie'  => array( 'Informativa sui cookie', 'cookie_body' ),
	);

	public static function vars(): array {
		$l    = (array) Config::get( 'legal', array() );
		$none = function ( string $k, string $fallback ) use ( $l ) {
			return '' !== trim( (string) ( $l[ $k ] ?? '' ) ) ? (string) $l[ $k ] : $fallback;
		};
		return array(
			'titolare'  => $none( 'titolare', 'Wega Studios' ),
			'indirizzo' => $none( 'indirizzo', '' ),
			'piva'      => $none( 'piva', '' ),
			'email'     => $none( 'email', 'info@associazionesemplice.it' ),
			'sito'      => 'associazionesemplice.it',
		);
	}

	/** Testo della pagina: quello salvato dal pannello oppure quello di base. */
	public static function body( string $slug ): string {
		$own = self::own( $slug );
		return '' !== $own ? $own : self::defaults()[ $slug ];
	}

	private static function own( string $slug ): string {
		$l   = (array) Config::get( 'legal', array() );
		$key = self::PAGES[ $slug ][1] ?? '';
		return trim( (string) ( $l[ $key ] ?? '' ) );
	}

	public static function isCustom( string $slug ): bool {
		return '' !== self::own( $slug );
	}

	/** Sostituisce i segnaposto e trasforma il testo semplice in HTML (titoli «## », elenchi «- », **grassetto**). */
	public static function html( string $text ): string {
		$map = array();
		foreach ( self::vars() as $k => $v ) {
			$map[ '{' . $k . '}' ] = $v;
		}
		$text   = strtr( $text, $map );
		$text   = preg_replace( '/[ 	]+(?=[.,;])/', '', preg_replace( '/[ 	]{2,}/', ' ', $text ) );
		$blocks = preg_split( '/\n{2,}/', trim( str_replace( "\r", '', $text ) ) );
		$out    = '';
		$inline = function ( string $s ): string {
			return preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', e( $s ) );
		};
		foreach ( $blocks as $b ) {
			$lines = array_values( array_filter( array_map( 'rtrim', explode( "\n", $b ) ), 'strlen' ) );
			if ( ! $lines ) {
				continue;
			}
			if ( 0 === strpos( $lines[0], '## ' ) ) {
				$out .= '<h2>' . $inline( substr( array_shift( $lines ), 3 ) ) . '</h2>';
				if ( ! $lines ) {
					continue;
				}
			}
			if ( 0 === strpos( $lines[0], '- ' ) ) {
				$out .= '<ul>';
				foreach ( $lines as $l ) {
					$out .= '<li>' . $inline( preg_replace( '/^- /', '', $l ) ) . '</li>';
				}
				$out .= '</ul>';
			} else {
				$out .= '<p>' . implode( '<br>', array_map( $inline, $lines ) ) . '</p>';
			}
		}
		return $out;
	}

	public static function defaults(): array {
		return array(
			'privacy' => <<<'TXT'
## Chi tratta i tuoi dati
Il titolare del trattamento è {titolare} {indirizzo} {piva}. Per qualsiasi richiesta scrivi a {email}.

## Quali dati trattiamo
- Indirizzo email e dominio del sito che indichi quando chiedi una licenza o acquisti un piano.
- Chiave di licenza, piano, scadenza e stato dei pagamenti.
- Dati tecnici di navigazione necessari al funzionamento del sito (cookie tecnici).

I dati della carta di credito non passano da noi: i pagamenti sono gestiti da Stripe e da PayPal.

## Perché e su quale base
Trattiamo i dati per fornirti la licenza, gestire il tuo abbonamento, inviarti le comunicazioni legate al servizio (accesso, rinnovi, scadenze) e adempiere agli obblighi di legge e fiscali. La base giuridica è l'esecuzione del contratto e il rispetto di obblighi di legge.

## A chi li comunichiamo
Solo a fornitori che ci permettono di erogare il servizio: Stripe e PayPal per i pagamenti, il servizio di gestione delle licenze di Wega Studios, il fornitore di hosting e di posta. Non vendiamo i tuoi dati.

## Per quanto tempo li conserviamo
Per tutta la durata del rapporto e, dopo, per il tempo richiesto dalla legge per le scritture contabili e fiscali (di norma 10 anni). I dati di accesso inutilizzati si cancellano su richiesta.

## I tuoi diritti
Puoi chiedere accesso, rettifica, cancellazione, limitazione, portabilità e opporti al trattamento scrivendo a {email}. Hai anche il diritto di proporre reclamo al Garante per la protezione dei dati personali (garanteprivacy.it).
TXT
			,
			'cookie' => <<<'TXT'
## Che cosa sono i cookie
I cookie sono piccoli file che il sito salva nel tuo browser. Alcuni servono a farlo funzionare, altri a capire come viene usato.

## Cookie che usiamo
- **Tecnici (sempre attivi):** mantengono la sessione quando accedi all'area riservata e ricordano le tue scelte sui cookie. Non richiedono consenso.
- **Statistiche (solo con il tuo consenso):** ci aiutano a capire quali pagine sono utili, in forma aggregata.
- **Marketing (solo con il tuo consenso):** servono a misurare l'efficacia delle comunicazioni.

Gli strumenti di statistica e marketing partono solo se li accetti. Se non li accetti, non vengono caricati.

## Come cambiare scelta
Puoi modificare o ritirare il consenso in ogni momento dal link «Preferenze cookie» in fondo a ogni pagina. Puoi anche cancellare i cookie dalle impostazioni del browser.

## Titolare e contatti
Il titolare è {titolare}. Per domande scrivi a {email}. Per saperne di più su come trattiamo i tuoi dati leggi l'informativa sulla privacy.
TXT
			,
		);
	}
}
