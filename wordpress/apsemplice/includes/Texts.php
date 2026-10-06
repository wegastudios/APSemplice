<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || defined( 'APSE_TESTS' ) || exit;

/**
 * Testi personalizzabili. Ogni testo che il plugin mostra (pagine dei soci, email, PDF, messaggi, amministrazione) si può cambiare dalle
 * impostazioni, uno per uno o tutti insieme esportando e reimportando un file (CSV/Excel).
 *
 * Funziona per SOSTITUZIONE: l'elenco dei testi si ricava dal codice del plugin (ogni frase scritta nei file) e quello che scrivi
 * nella colonna "Personalizzato" prende il posto dell'originale ovunque compaia (pagine, email, ricevute, messaggi). Le sostituzioni valgono
 * per tutta la frase o parola indicata: se un testo originale è contenuto in un altro, cambiano entrambi (di solito è proprio quello che serve).
 */
final class Texts {

	const OPT           = 'apse_texts';
	const MAX_LEN       = 600;
	const MIN_LEN       = 3;
	const GROUP_MANUAL  = 'Aggiunte a mano';
	const GROUP_ORDER   = array(
		'Area soci e pagine pubbliche',
		'Email e promemoria',
		'Ricevute e attestazioni (PDF)',
		'Etichette e messaggi comuni',
		'Messaggi di sistema',
		'Amministrazione',
	);

	/** @var array|null */
	private static $map = null;

	// ---------- Sostituzioni salvate ----------

	/** original => personalizzato */
	public static function overrides(): array {
		if ( null === self::$map ) {
			$v         = get_option( self::OPT, array() );
			self::$map = is_array( $v ) ? $v : array();
		}
		return self::$map;
	}

	public static function save_overrides( array $map ): void {
		$clean = array();
		foreach ( $map as $o => $c ) {
			$o = trim( (string) $o );
			$c = trim( (string) $c );
			if ( '' === $c || $c === $o || strlen( $o ) < self::MIN_LEN || strlen( $o ) > self::MAX_LEN || strlen( $c ) > self::MAX_LEN ) {
				continue;
			}
			$clean[ $o ] = $c;
		}
		update_option( self::OPT, $clean );
		self::$map = $clean;
	}

	/** Dopo un salvataggio o un test: rilegge dal database. */
	public static function flush(): void {
		self::$map = null;
	}

	// ---------- Applicazione ----------

	private static function h( string $s ): string {
		return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8', false );
	}

	/**
	 * Segnaposto per i testi personalizzati: la personalizzazione lavora sulla versione in uso, quindi il testo scelto
	 * non viene poi adattato (tipo di ente e termini) una seconda volta.
	 *
	 * @return array{0:array,1:array} [originale => segnaposto, segnaposto => testo]
	 */
	private static function tokens( array $map, bool $html ): array {
		$to   = array();
		$back = array();
		$i    = 0;
		foreach ( $map as $o => $c ) {
			$t         = "\x1A" . $i++ . "\x1A";
			$to[ $o ]  = $t;
			$back[ $t ] = $html ? self::h( (string) $c ) : (string) $c;
			if ( $html ) {
				$oe = self::h( (string) $o );
				if ( $oe !== $o ) {
					$to[ $oe ] = $t;
				}
			}
		}
		return array( $to, $back );
	}

	/** Testo semplice (email, PDF, messaggi): sostituzione diretta. */
	public static function plain( string $s, ?array $map = null ): string {
		$own = null === $map; // senza una mappa data: anche tipo di ente e termini scelti
		$map = $map ?? self::overrides();
		if ( '' === $s ) {
			return $s;
		}
		list( $to, $back ) = self::tokens( $map, false );
		if ( $to ) {
			$s = strtr( $s, $to );
		}
		if ( $own ) {
			$s = Terms::apply( $s );
		}
		return $back ? strtr( $s, $back ) : $s;
	}

	/** HTML: si sostituisce solo nel testo (non nei tag, negli script e negli stili) e in placeholder/title/aria-label/alt; il nuovo testo è protetto. */
	public static function html( string $html, ?array $map = null ): string {
		$terms = null === $map ? Terms::map() : array();
		$map   = $map ?? self::overrides();
		if ( ( ! $map && ! $terms ) || '' === $html ) {
			return $html;
		}
		list( $to, $back ) = self::tokens( $map, true );
		$parts = preg_split( '#(<script\b.*?</script>|<style\b.*?</style>|<[^>]*>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $parts ) {
			return $html;
		}
		$fix = function ( string $text ) use ( $to, $back, $terms ) {
			if ( $to ) {
				$text = strtr( $text, $to );
			}
			if ( $terms ) {
				$text = Terms::apply_map( str_replace( '&#039;', "'", $text ), $terms );
			}
			return $back ? strtr( $text, $back ) : $text;
		};
		foreach ( $parts as $i => $part ) {
			if ( '' === $part ) {
				continue;
			}
			if ( 0 === $i % 2 ) {
				$parts[ $i ] = $fix( $part );
			} elseif ( '<' === $part[0] && false === stripos( $part, '<script' ) && false === stripos( $part, '<style' ) ) {
				$parts[ $i ] = (string) preg_replace_callback(
					'/\b(placeholder|title|aria-label|alt)="([^"]*)"/i',
					function ( $m ) use ( $fix ) {
						return $m[1] . '="' . $fix( $m[2] ) . '"';
					},
					$part
				);
			}
		}
		return implode( '', $parts );
	}

	/** Amministrazione: tutta la pagina passa dalla sostituzione (tranne la pagina dei testi stessa). */
	public static function start_admin_buffer(): void {
		static $started = false;
		if ( $started || ( ! self::overrides() && ! Terms::map() ) ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'apse-texts' === $page ) {
			return;
		}
		$started = true;
		ob_start( array( __CLASS__, 'admin_filter' ) );
	}

	public static function admin_filter( $html ) {
		return self::html( (string) $html );
	}

	// ---------- Elenco dei testi (dal codice) ----------

	/** Frase o parola che sembra un testo per le persone (e non codice, query, classi CSS, chiavi). */
	public static function is_text( string $s ): bool {
		$s = trim( $s );
		$n = strlen( $s );
		if ( $n < self::MIN_LEN || $n > self::MAX_LEN ) {
			return false;
		}
		if ( ! preg_match( '/\p{L}.*\p{L}.*\p{L}/su', $s ) ) {
			return false;
		}
		if ( preg_match( '/[\\\\${}<>=]|::|->|%[sd]|\(\)|https?:|application\/|text\/|Content-|\.php|\.js|\.css|^[#\/@.]|^\S+\/\S+$/u', $s ) ) {
			return false;
		}
		if ( preg_match( '/^(SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER|FROM|WHERE|JOIN|LEFT JOIN|ORDER BY|GROUP BY|LIMIT|SET|AND|OR)\b/', $s ) ) {
			return false;
		}
		if ( preg_match( '/(SELECT|FROM|WHERE|COALESCE|GROUP BY|ORDER BY|LEFT JOIN)/', $s ) || preg_match( '/^[A-Z0-9-]+$/', $s ) ) {
			return false; // pezzi di query e sigle (UTF-8)
		}
		$words = preg_split( '/\s+/u', $s ) ?: array();
		if ( preg_match( '/^[A-ZÀ-Ý]/u', $s ) ) {
			return ! preg_match( '/^[A-Za-z0-9_]+$/', $s ) || preg_match( '/[a-z]{3}/', $s ); // niente costanti (FOUNDER) né identificatori
		}
		// minuscolo: solo frasi (almeno tre parole, senza trattini/underscore: le classi CSS e le chiavi restano fuori)
		return count( $words ) >= 3 && ! preg_match( '/[_\-:;|]/', $s ) && strlen( $s ) >= 10;
	}

	/** Testi (frasi) che compaiono nel codice PHP dato. @return string[] */
	public static function fragments_of_source( string $src ): array {
		$out = array();
		foreach ( token_get_all( $src ) as $t ) {
			if ( ! is_array( $t ) || T_CONSTANT_ENCAPSED_STRING !== $t[0] ) {
				continue;
			}
			$raw  = (string) $t[1];
			$body = substr( $raw, 1, -1 );
			$s    = "'" === $raw[0] ? strtr( $body, array( '\\\\' => '\\', "\\'" => "'" ) ) : stripcslashes( $body );
			$parts = false !== strpos( $s, '<' ) ? preg_split( '/<[^>]*>/', $s ) : array( $s );
			foreach ( (array) $parts as $p ) {
				$p = trim( (string) $p );
				if ( '' !== $p && self::is_text( $p ) ) {
					$out[ $p ] = true;
				}
			}
		}
		return array_keys( $out );
	}

	/** Gruppo di un file del plugin (percorso relativo a includes/). */
	public static function group_of( string $rel ): string {
		if ( 0 === strpos( $rel, 'Frontend/' ) ) {
			return self::GROUP_ORDER[0];
		}
		if ( 0 === strpos( $rel, 'Admin/' ) ) {
			return self::GROUP_ORDER[5];
		}
		if ( in_array( $rel, array( 'Reminders.php', 'Notices.php', 'PaymentService.php' ), true ) ) {
			return self::GROUP_ORDER[1];
		}
		if ( 'Receipts.php' === $rel ) {
			return self::GROUP_ORDER[2];
		}
		if ( in_array( $rel, array( 'Labels.php', 'MemberType.php', 'ActivityKind.php', 'CancelPolicy.php', 'Visibility.php', 'PaymentItems.php', 'BoardRole.php', 'Privacy.php' ), true ) ) {
			return self::GROUP_ORDER[3];
		}
		return self::GROUP_ORDER[4];
	}

	const SKIP_FILES = array(
		'Install.php', 'Secrets.php', 'QrCode.php', 'Png.php', 'Xlsx.php', 'SheetReader.php', 'ApplePass.php', 'GoogleWallet.php', 'WalletCredentials.php', 'StripeApi.php', 'PayPalApi.php',
		'Gateways.php', 'StripeWebhook.php', 'ActivationToken.php', 'CardToken.php', 'Texts.php', 'Terms.php', 'Pdf.php', 'Ics.php', 'Schedule.php', 'Money.php', 'Db.php', 'Rest/Api.php', 'Audit.php',
		'Color.php', 'Text.php', 'Phone.php', 'Settings.php', 'Plugin.php', 'License.php', 'LicenseRules.php', 'LicensePolicy.php', 'Wallet.php', 'Access.php', 'Rules.php',
	);

	/**
	 * Tutti i testi del plugin, con il gruppo. Calcolato leggendo i file (poi tenuto da parte finché il plugin non cambia).
	 *
	 * @return array[] text, group
	 */
	public static function catalog(): array {
		$dir   = rtrim( APSE_DIR, '/\\' ) . '/includes';
		$files = array();
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		$sig   = APSE_VERSION;
		foreach ( $it as $f ) {
			if ( 'php' === strtolower( $f->getExtension() ) ) {
				$rel = ltrim( str_replace( '\\', '/', substr( $f->getPathname(), strlen( $dir ) ) ), '/' );
				if ( ! in_array( $rel, self::SKIP_FILES, true ) ) {
					$files[ $rel ] = $f->getPathname();
					$sig          .= '|' . $rel . $f->getMTime();
				}
			}
		}
		ksort( $files );
		$key    = 'apse_texts_cat_' . substr( md5( $sig ), 0, 12 );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$best = array(); // testo => indice del gruppo migliore
		foreach ( $files as $rel => $path ) {
			$src = (string) file_get_contents( $path );
			$g   = array_search( self::group_of( $rel ), self::GROUP_ORDER, true );
			foreach ( self::fragments_of_source( $src ) as $s ) {
				if ( ! isset( $best[ $s ] ) || $g < $best[ $s ] ) {
					$best[ $s ] = $g;
				}
			}
		}
		$out = array();
		foreach ( $best as $text => $g ) {
			$out[] = array( 'text' => (string) $text, 'group' => self::GROUP_ORDER[ $g ] );
		}
		usort(
			$out,
			function ( $a, $b ) {
				$ga = array_search( $a['group'], self::GROUP_ORDER, true );
				$gb = array_search( $b['group'], self::GROUP_ORDER, true );
				return $ga <=> $gb ?: strcasecmp( $a['text'], $b['text'] );
			}
		);
		set_transient( $key, $out, WEEK_IN_SECONDS );
		return $out;
	}

	/** Righe da mostrare/esportare: i testi del plugin più le sostituzioni aggiunte a mano. @return array[] group, text, custom */
	public static function rows( bool $only_custom = false ): array {
		$ov   = self::overrides();
		$rows = array();
		$seen = array();
		foreach ( self::catalog() as $c ) {
			$seen[ $c['text'] ] = true;
			$cu                 = $ov[ $c['text'] ] ?? '';
			if ( $only_custom && '' === $cu ) {
				continue;
			}
			$rows[] = array( 'group' => $c['group'], 'text' => $c['text'], 'custom' => $cu );
		}
		foreach ( $ov as $o => $cu ) {
			if ( ! isset( $seen[ $o ] ) ) {
				$rows[] = array( 'group' => self::GROUP_MANUAL, 'text' => (string) $o, 'custom' => (string) $cu );
			}
		}
		return $rows;
	}

	// ---------- Esportazione e importazione ----------

	private static function cell( string $v ): string {
		$v = \ApSemplice\Admin\Exports::neutralize( $v );
		return preg_match( '/[;"\r\n]/', $v ) ? '"' . str_replace( '"', '""', $v ) . '"' : $v;
	}

	/** CSV (separatore ";"), apribile in Excel: Gruppo ; Originale ; Personalizzato. */
	public static function export_csv( bool $only_custom = false ): string {
		$out = "Gruppo;Originale;Versione in uso;Personalizzato\r\n";
		foreach ( self::rows( $only_custom ) as $r ) {
			$out .= self::cell( $r['group'] ) . ';' . self::cell( $r['text'] ) . ';' . self::cell( Terms::apply( $r['text'] ) ) . ';' . self::cell( $r['custom'] ) . "\r\n";
		}
		return "\xEF\xBB\xBF" . $out;
	}

	private static function unneutralize( string $v ): string {
		return preg_match( "/^'[=+\\-@]/", $v ) ? substr( $v, 1 ) : $v;
	}

	/**
	 * Applica le righe lette da un file (CSV/Excel) agli override: la colonna "Personalizzato" vuota toglie la sostituzione.
	 *
	 * @param array[] $rows righe di celle (la prima con l'intestazione, anche non alla prima riga)
	 * @return array set, removed, manual, ignored
	 * @throws \InvalidArgumentException
	 */
	public static function import_rows( array $rows ): array {
		$h = SheetReader::find_header(
			$rows,
			function ( $header ) {
				return SheetReader::col( $header, array( 'originale', 'testooriginale' ) ) >= 0 && SheetReader::col( $header, array( 'personalizzato', 'testopersonalizzato', 'custom' ) ) >= 0;
			}
		);
		if ( null === $h ) {
			throw new \InvalidArgumentException( 'Il file non ha le colonne "Originale" e "Personalizzato": usa il file esportato da qui.' );
		}
		$head = SheetReader::normalize_header( $rows[ $h ] );
		$co   = SheetReader::col( $head, array( 'originale', 'testooriginale' ) );
		$cc   = SheetReader::col( $head, array( 'personalizzato', 'testopersonalizzato', 'custom' ) );
		$cat  = array();
		foreach ( self::catalog() as $c ) {
			$cat[ $c['text'] ] = true;
		}
		$ov  = self::overrides();
		$res = array( 'set' => 0, 'removed' => 0, 'manual' => 0, 'ignored' => 0 );
		foreach ( $rows as $i => $cells ) {
			if ( $i <= $h ) {
				continue;
			}
			$o = trim( self::unneutralize( (string) ( $cells[ $co ] ?? '' ) ) );
			$c = trim( self::unneutralize( (string) ( $cells[ $cc ] ?? '' ) ) );
			if ( '' === $o ) {
				continue;
			}
			if ( strlen( $o ) < self::MIN_LEN || strlen( $o ) > self::MAX_LEN || strlen( $c ) > self::MAX_LEN ) {
				$res['ignored']++;
				continue;
			}
			if ( '' === $c || $c === $o ) {
				if ( isset( $ov[ $o ] ) ) {
					unset( $ov[ $o ] );
					$res['removed']++;
				}
				continue;
			}
			if ( ! isset( $cat[ $o ] ) ) {
				$res['manual']++;
			}
			if ( ( $ov[ $o ] ?? null ) !== $c ) {
				$res['set']++;
			}
			$ov[ $o ] = $c;
		}
		self::save_overrides( $ov );
		return $res;
	}

	/** wp_mail con i testi personalizzati (oggetto e messaggio). */
	public static function mail( $to, string $subject, string $message, $headers = '', $attachments = array() ): bool {
		return (bool) wp_mail( $to, self::plain( $subject ), self::plain( $message ), $headers, $attachments );
	}

	public static function register(): void {
		add_action( 'admin_post_apse_export_texts', array( __CLASS__, 'handle_export' ) );
	}

	public static function export_url( bool $only_custom = false ): string {
		return wp_nonce_url( add_query_arg( array( 'action' => 'apse_export_texts', 'only' => $only_custom ? 'custom' : 'all' ), admin_url( 'admin-post.php' ) ), 'apse_export_texts' );
	}

	public static function handle_export(): void {
		if ( ! current_user_can( Plugin::CAP ) ) {
			wp_die( 'Non autorizzato.', 403 );
		}
		check_admin_referer( 'apse_export_texts' );
		$only = isset( $_GET['only'] ) && 'custom' === sanitize_key( wp_unslash( $_GET['only'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="testi-apsemplice-' . gmdate( 'Y-m-d' ) . '.csv"' );
		echo self::export_csv( $only ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}
}
