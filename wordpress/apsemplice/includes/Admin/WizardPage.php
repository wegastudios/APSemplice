<?php
namespace ApSemplice\Admin;

use ApSemplice\Fiscal;
use ApSemplice\Levels;
use ApSemplice\Modules;
use ApSemplice\Money;
use ApSemplice\Pages;
use ApSemplice\PaymentConfig;
use ApSemplice\Settings;
use ApSemplice\Terms;
use ApSemplice\Wizard;
use ApSemplice\WooBridge;
use ApSemplice\WooLinks;

defined( 'ABSPATH' ) || exit;

/**
 * Configurazione guidata a domande: prima si decide quali parti del gestionale servono, poi si configurano solo quelle scelte.
 * Un passo alla volta (senza JavaScript compaiono tutti insieme); i passi e i blocchi che non servono si saltano.
 */
final class WizardPage {

	/** Pagina => condizione (nome=valore) perché sia proposta. */
	const PAGE_IF = array(
		'attivita'    => 'mod[activities]=1',
		'calendario'  => 'mod[activities]=1',
		'bonifico'    => 'bank_enabled=1',
		'cinquemille' => 'fivepm_enabled=1',
		'tesoriere'   => 'mod[ledger]=1',
		'ingressi'    => 'mod[activities]=1',
	);

	/** Collegamenti ai tutorial: si aprono in una nuova finestra. */
	public static function tutorial_links( string $provider ): string {
		$out = array();
		foreach ( Wizard::tutorials()[ $provider ] ?? array() as $l ) {
			$out[] = '<a href="' . esc_url( $l[1] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $l[0] ) . ' ↗</a>';
		}
		return $out ? '<span class="description apse-wiz-links">Guide: ' . implode( ' · ', $out ) . '</span>' : '';
	}

	private static function product_select( string $name, int $current, array $products ): string {
		$opts = array( '' => $current ? 'Lascia il collegamento attuale' : 'Non collegare adesso', 'new' => 'Crea un prodotto nuovo' );
		foreach ( $products as $id => $label ) {
			$opts[ (string) $id ] = $label;
		}
		return '<select name="' . esc_attr( $name ) . '">' . Ui::options( $opts, '' ) . '</select>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private static function step( string $title, string $intro, string $if = '' ): void {
		echo '<section class="apse-wiz-step"' . ( '' !== $if ? ' data-if="' . esc_attr( $if ) . '"' : '' ) . '><h2>' . esc_html( $title ) . '</h2>' . ( '' !== $intro ? '<p class="description">' . esc_html( $intro ) . '</p>' : '' );
	}

	private static function end_step(): void {
		echo '</section>';
	}

	/** Domanda sì/no: due pulsanti di scelta con lo stesso nome. */
	private static function yes_no( string $name, string $question, bool $current ): string {
		return '<p class="apse-wiz-q"><strong>' . esc_html( $question ) . '</strong><br>'
			. '<label><input type="radio" name="' . esc_attr( $name ) . '" value="1"' . checked( $current, true, false ) . '> Sì</label> &nbsp; '
			. '<label><input type="radio" name="' . esc_attr( $name ) . '" value="0"' . checked( ! $current, true, false ) . '> No</label></p>';
	}

	private static function features_box( array $s ): string {
		$conditional = array( 'card_qr_enabled' => 'card_enabled=1', 'wallet_enabled' => 'card_enabled=1' );
		$html        = '<input type="hidden" name="features_present" value="1">';
		foreach ( SettingsPage::FEATURES as $k => $label ) {
			if ( in_array( $k, \ApSemplice\Wizard::OWN_STEP, true ) ) {
				continue;
			}
			$html .= '<div' . ( isset( $conditional[ $k ] ) ? ' data-if="' . esc_attr( $conditional[ $k ] ) . '" style="margin-left:24px"' : '' ) . '><label><input type="checkbox" name="' . esc_attr( $k ) . '" value="1"' . checked( ! empty( $s[ $k ] ), true, false ) . '> ' . esc_html( $label ) . '</label></div>';
		}
		return $html;
	}

	public static function render(): void {
		$s    = Settings::all();
		$woo  = WooBridge::active();
		$euro = $woo && WooBridge::currency_is_euro();
		Ui::header( 'Configurazione guidata' );
		echo '<p>Poche domande per tenere solo ciò che ti serve: prima scegli quali parti del gestionale usare, poi configuri solo quelle. Nulla viene cancellato: una parte spenta si riaccende riaprendo questa procedura da Impostazioni → Soci e quote. Le funzioni facoltative restano spente se non le scegli.</p>';
		Ui::form_open( 'apse_wizard_save', Ui::url( 'apse-wizard' ), false, 'apse-wizard' );

		// 1. Ente
		$ents = array_keys( Terms::entity_types( (string) $s['entity_types_custom'] ) );
		$mems = array_keys( Terms::member_terms( (string) $s['member_terms_custom'] ) );
		self::step( 'Il tuo ente', 'Tipo di ente e termine per chi partecipa: tutti i testi si adattano da soli (articoli compresi).' );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>Come si chiama</th><td><input type="text" name="association_name" value="' . esc_attr( (string) $s['association_name'] ) . '" class="regular-text"></td></tr>';
		echo '<tr><th>Di che tipo è</th><td><select name="entity_type">' . Ui::options( array_combine( $ents, $ents ), (string) $s['entity_type'] ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Chi partecipa si chiama</th><td><select name="member_term">' . Ui::options( array_combine( $mems, $mems ), (string) $s['member_term'] ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Codice fiscale dell\'ente</th><td><input type="text" name="tax_code" value="' . esc_attr( (string) $s['tax_code'] ) . '" class="regular-text"></td></tr>';
		echo '<tr><th>L\'anno sociale inizia a</th><td><select name="social_year_start_month">' . Ui::options( Ui::MONTHS, (int) $s['social_year_start_month'] ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Sede (facoltativa)</th><td><input type="text" name="legal_address" value="' . esc_attr( (string) $s['legal_address'] ) . '" class="regular-text" placeholder="Indirizzo"> <input type="text" name="legal_zip" value="' . esc_attr( (string) $s['legal_zip'] ) . '" size="6" placeholder="CAP"> <input type="text" name="legal_city" value="' . esc_attr( (string) $s['legal_city'] ) . '" placeholder="Comune"> <input type="text" name="legal_province" value="' . esc_attr( (string) $s['legal_province'] ) . '" size="4" placeholder="Prov."></td></tr>';
		echo '</tbody></table>';
		self::end_step();

		// 1b. Partita IVA
		self::step( 'Partita IVA', 'Senza partita IVA non compare nulla di fiscale (niente aliquote né importi IVA). Con la partita IVA la gestione resta essenziale: il commercialista completa i quadri.' );
		echo self::yes_no( 'has_vat', 'L\'ente ha la partita IVA?', ! empty( $s['has_vat'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		$rates = array();
		foreach ( Fiscal::RATES as $r ) {
			$rates[ $r ] = $r . '%';
		}
		echo '<div data-if="has_vat=1"><table class="form-table"><tbody>';
		echo '<tr><th>Partita IVA</th><td><input type="text" name="vat_number" value="' . esc_attr( (string) $s['vat_number'] ) . '" class="regular-text" maxlength="13" placeholder="11 cifre"></td></tr>';
		echo '<tr><th>Regime</th><td><select name="fiscal_regime">' . Ui::options( Fiscal::regimes(), (string) $s['fiscal_regime'] ) . '</select><p class="description">Nel regime forfettario l\'IVA non si applica.</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Aliquota proposta</th><td><select name="vat_default_rate">' . Ui::options( $rates, (int) $s['vat_default_rate'] ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Gli importi si inseriscono</th><td><select name="vat_prices_mode">' . Ui::options( array( Fiscal::INCLUDED => 'IVA compresa', Fiscal::EXCLUDED => 'IVA esclusa' ), (string) $s['vat_prices_mode'] ) . '</select><p class="description">Scelta iniziale: su ogni attività, incasso e spesa si può indicare diversamente.</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		$mr = Fiscal::membership_rate();
		echo '<tr><th>IVA sulle quote associative</th><td><select name="vat_membership_rate">' . Ui::options( Fiscal::rate_options(), null === $mr ? 'none' : (string) $mr ) . '</select><p class="description">Di norma le quote dei soci sono fuori campo IVA.</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Codice destinatario (SDI)</th><td><input type="text" name="sdi_code" value="' . esc_attr( (string) $s['sdi_code'] ) . '" size="9" maxlength="7"> <span class="description">facoltativo</span></td></tr>';
		echo '</tbody></table></div>';
		self::end_step();

		// 2. Chi può iscriversi
		self::step( 'Chi può iscriversi', 'Decide cosa succede a chi chiede l\'accesso dal sito senza essere già in elenco.' );
		$jm = (string) $s['join_mode'];
		echo '<p><label><input type="radio" name="join_mode" value="request"' . checked( 'invite' !== $jm, true, false ) . '> <strong>Chiunque può chiedere di iscriversi</strong></label><br><span class="description">La richiesta arriva alla segreteria, che verifica e approva.</span></p>';
		echo '<p><label><input type="radio" name="join_mode" value="invite"' . checked( 'invite' === $jm, true, false ) . '> <strong>Solo su presentazione</strong></label><br><span class="description">Chi non è in elenco non può iscriversi da solo: le iscrizioni le fa la segreteria. «Primo accesso» resta per chi è già iscritto.</span></p>';
		self::end_step();

		// 3. Tipi di socio e quote
		self::step( 'Tipi di socio e quote', 'La quota proposta vale per tutti i tipi di socio senza una quota propria.' );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>Quota associativa</th><td><input type="text" name="membership_fee" value="' . esc_attr( Money::plain( (int) $s['membership_fee_cents'] ) ) . '" inputmode="decimal"> €</td></tr>';
		$have = array();
		foreach ( Levels::all( true ) as $lv ) {
			$have[] = $lv['name'] . ( null === $lv['fee_cents'] ? '' : ' (' . Money::format( (int) $lv['fee_cents'] ) . ')' );
		}
		echo '<tr><th>Tipi già presenti</th><td>' . esc_html( implode( ', ', $have ) ) . '</td></tr>';
		echo '<tr><th>Altri tipi di socio</th><td><textarea name="extra_levels" rows="4" class="large-text" placeholder="Ridotto; 15&#10;Sostenitore; 100"></textarea><p class="description">Una riga per tipo: «Nome; quota». Senza quota si usa quella proposta. Con la partita IVA le quote dei livelli sono IVA compresa. Altri livelli, basi e quote si gestiscono in Impostazioni → Soci e quote.</p></td></tr>';
		echo '<tr><th>Sconto nucleo familiare</th><td><input type="number" min="0" max="100" name="family_discount_pct" value="' . (int) $s['family_discount_pct'] . '"> %<p class="description">0 = nessuno sconto.</p></td></tr>';
		echo '</tbody></table>';
		self::end_step();

		// 4. Parti da usare
		self::step( 'Cosa ti serve', 'Rispondi sì solo a ciò che usi davvero: il resto sparisce dal menu e dalle schede.' );
		echo '<input type="hidden" name="mod_present" value="1">';
		foreach ( Modules::defs() as $key => $d ) {
			echo '<div' . ( '' !== $d['needs'] ? ' data-if="mod[' . esc_attr( $d['needs'] ) . ']=1" style="margin-left:24px"' : '' ) . '>' . self::yes_no( 'mod[' . $key . ']', $d['ask'], Modules::on( $key ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		self::end_step();

		// 5. Prima nota
		self::step( 'Ricevute e prima nota', 'Riga in fondo alle ricevute (ad esempio il riferimento normativo): la decidi tu.', 'mod[ledger]=1' );
		echo '<p><input type="text" name="receipt_footer" value="' . esc_attr( (string) $s['receipt_footer'] ) . '" class="large-text" maxlength="300"></p>';
		self::end_step();

		// 5b. Adempimenti (solo se la contabilità si tiene qui)
		self::step( 'Adempimenti', 'Scadenze e documenti della contabilità tenuta qui.', 'mod[accounting]=1' );
		echo '<input type="hidden" name="adempimenti_present" value="1">';
		echo self::yes_no( 'fivepm_enabled', 'Raccogli il 5x1000? Si attiva il messaggio per i soci, il promemoria e il registro dei contributi ricevuti.', ! empty( $s['fivepm_enabled'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		self::end_step();

		// 6. Privacy
		self::step( 'Privacy', 'L\'informativa privacy va accettata da chi attiva l\'accesso; dopo il tempo di inattività che indichi il plugin propone di anonimizzare i dati.' );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>Indirizzo dell\'informativa</th><td><input type="url" name="privacy_url" value="' . esc_attr( (string) $s['privacy_url'] ) . '" class="regular-text" placeholder="https://"><p class="description">Lascia vuoto se non hai ancora la pagina: chi attiva l\'accesso non dovrà accettarla.</p></td></tr>';
		echo '<tr><th>Conservazione dei dati</th><td><input type="number" min="1" max="30" name="privacy_retention_years" value="' . (int) $s['privacy_retention_years'] . '"> anni di inattività prima di proporre l\'anonimizzazione</td></tr>';
		echo '</tbody></table>';
		self::end_step();

		// 7. Funzioni facoltative
		self::step( 'Altre funzioni', 'Tutte facoltative: accendi solo quelle che usi. App e notifiche hanno la loro scheda.' );
		echo self::features_box( $s ); // phpcs:ignore WordPress.Security.EscapeOutput
		self::end_step();

		// 8. Pagamenti
		self::step( 'Pagamenti dei soci', 'Come i soci versano quote e contributi dal sito. Puoi cambiare idea in qualsiasi momento da Pagamenti online.', 'mod[ledger]=1' );
		$current = (string) $s['payment_provider'];
		foreach ( Wizard::payment_choices() as $val => $c ) {
			$off  = PaymentConfig::WOOCOMMERCE === $val && ! $euro;
			$note = '';
			if ( PaymentConfig::WOOCOMMERCE === $val ) {
				$note = ! $woo ? ' <em>WooCommerce non è stato rilevato su questo sito: installalo e attivalo per usare questa opzione.</em>' : ( ! $euro ? ' <em>WooCommerce è attivo, ma il negozio non usa l\'euro: non è utilizzabile.</em>' : ' <strong>WooCommerce è attivo su questo sito.</strong>' );
			}
			$links = '';
			foreach ( PaymentConfig::BOTH === $val ? array( PaymentConfig::STRIPE, PaymentConfig::PAYPAL ) : ( in_array( $val, array( PaymentConfig::STRIPE, PaymentConfig::PAYPAL, PaymentConfig::WOOCOMMERCE ), true ) ? array( $val ) : array() ) as $pv ) {
				$links .= ' ' . self::tutorial_links( $pv );
			}
			echo '<p><label><input type="radio" name="payment_choice" value="' . esc_attr( $val ) . '"' . checked( $current === $val, true, false ) . disabled( $off, true, false ) . '> <strong>' . esc_html( $c[0] ) . '</strong></label><br><span class="description">' . esc_html( $c[1] ) . '</span>' . $note . $links . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '<p><label><input type="checkbox" name="bank_enabled" value="1"' . checked( ! empty( $s['bank_enabled'] ), true, false ) . '> Mostra anche le coordinate bancarie (bonifico)</label><br><span class="description">Gli IBAN si inseriscono subito dopo, in Pagamenti online. Il bonifico può affiancare o sostituire i pagamenti online.</span></p>';
		self::end_step();

		// 9. Prodotti WooCommerce
		if ( $euro ) {
			$products = WooBridge::products();
			$map      = WooLinks::map();
			self::step( 'Prodotti di WooCommerce', 'Ogni quota deve corrispondere a un prodotto del negozio: collegane uno esistente oppure crealo ora (prodotto virtuale, non visibile in vetrina; il prezzo applicato lo calcola il plugin).', 'payment_choice=woocommerce' );
			echo '<table class="form-table"><tbody>';
			foreach ( Levels::all( true ) as $lv ) {
				$cur = (int) ( $map[ 'level:' . (int) $lv['id'] ] ?? 0 );
				echo '<tr><th>Quota «' . esc_html( (string) $lv['name'] ) . '»</th><td>' . self::product_select( 'product_level[' . (int) $lv['id'] . ']', $cur, $products ) . ( $cur ? ' <span class="description">già collegata</span>' : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			$def = (int) $s['woo_default_product'];
			echo '<tr><th>Altre voci (corsi, eventi…)</th><td>' . self::product_select( 'product_default', $def, $products ) . ( $def ? ' <span class="description">già impostato</span>' : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</tbody></table>';
			self::end_step();
		}

		// 10. Pagine
		$exist = Pages::existing();
		self::step( 'Pagine del sito', 'Le pagine con gli shortcode già inseriti, proposte in base alle tue risposte. Potrai personalizzarne l\'impaginazione; quelle già create non si duplicano.' );
		echo '<input type="hidden" name="pages_present" value="1">';
		$titles = \ApSemplice\Areas::titles();
		foreach ( Pages::by_area() as $area => $defs ) {
			$conds = array_unique( array_map( function ( $k ) {
				return self::PAGE_IF[ $k ] ?? '';
			}, array_keys( $defs ) ) );
			$wrap  = 1 === count( $conds ) && '' !== $conds[0] ? ' data-if="' . esc_attr( $conds[0] ) . '"' : ''; // l'intera area compare solo se serve
			echo '<div' . $wrap . '><h3 style="margin-bottom:4px">' . esc_html( $titles[ $area ] ?? $area ) . '</h3>'; // phpcs:ignore WordPress.Security.EscapeOutput
		foreach ( $defs as $key => $d ) {
			$if = self::PAGE_IF[ $key ] ?? '';
			echo '<div' . ( '' !== $if ? ' data-if="' . esc_attr( $if ) . '"' : '' ) . '><label><input type="checkbox" name="pages[]" value="' . esc_attr( $key ) . '"' . checked( isset( $exist[ $key ] ) || ! empty( $d['default'] ), true, false ) . disabled( isset( $exist[ $key ] ), true, false ) . '> <strong>' . esc_html( $d['title'] ) . '</strong>'
				. ( isset( $exist[ $key ] ) ? ' <em>(già creata)</em>' : '' ) . '</label><br><span class="description" style="margin-left:24px">' . esc_html( $d['hint'] ) . '</span></div>';
		}
		echo '</div>';
		}
		self::end_step();

		echo '<p class="apse-wiz-nav"><span class="apse-wiz-count"></span> <button type="button" class="button" data-wiz="back">← Indietro</button> <button type="button" class="button button-primary" data-wiz="next">Avanti →</button> '
			. '<button type="submit" class="button button-primary" data-wiz="finish">Applica la configurazione</button></p>';
		Ui::form_close();
		Ui::form_open( 'apse_wizard_skip', Ui::url( 'apse' ), false, 'apse-inline' );
		echo '<p><button class="button-link">Salta per ora</button></p>';
		Ui::form_close();
		self::script();
		Ui::footer();
	}

	/** Un passo alla volta, saltando ciò che non serve; i campi nascosti non vengono inviati. */
	private static function script(): void {
		echo '<style>.apse-wizard:not(.apse-wiz-js) [data-wiz=back],.apse-wizard:not(.apse-wiz-js) [data-wiz=next],.apse-wizard:not(.apse-wiz-js) .apse-wiz-count{display:none}.apse-wiz-step{max-width:820px}</style>';
		echo '<script>(function(){var f=document.querySelector("form.apse-wizard");if(!f)return;'
			. 'var steps=[].slice.call(f.querySelectorAll(".apse-wiz-step")),cur=0;f.classList.add("apse-wiz-js");'
			. 'function ok(el){var c=el.getAttribute("data-if");if(!c)return true;var i=c.lastIndexOf("="),n=c.slice(0,i),v=c.slice(i+1);'
			. 'var els=f.querySelectorAll("[name=\\""+n+"\\"]:checked");for(var k=0;k<els.length;k++){if(els[k].value===v&&!els[k].disabled)return true;}return false;}'
			. 'function avail(s){var p=s;while(p&&p!==f){if(p.hasAttribute&&p.hasAttribute("data-if")&&!ok(p))return false;p=p.parentNode;}return true;}'
			. 'function sync(){var b=[].slice.call(f.querySelectorAll("[data-if]"));b.forEach(function(e){var show=avail(e);if(e.classList.contains("apse-wiz-step")){e.setAttribute("data-skip",show?"0":"1");}else{e.style.display=show?"":"none";}'
			. '[].slice.call(e.querySelectorAll("input,select,textarea")).forEach(function(i){if(!i.hasAttribute("data-was"))i.setAttribute("data-was",i.disabled?"1":"0");i.disabled=!show||i.getAttribute("data-was")==="1";});});}'
			. 'function vis(){return steps.filter(function(s){return s.getAttribute("data-skip")!=="1";});}'
			. 'function show(){sync();var v=vis();if(v.indexOf(steps[cur])<0){cur=steps.indexOf(v[0]);}steps.forEach(function(s,i){s.style.display=i===cur?"":"none";});'
			. 'var n=v.indexOf(steps[cur]);f.querySelector(".apse-wiz-count").textContent="Passo "+(n+1)+" di "+v.length;'
			. 'f.querySelector("[data-wiz=back]").style.display=n>0?"":"none";f.querySelector("[data-wiz=next]").style.display=n<v.length-1?"":"none";f.querySelector("[data-wiz=finish]").style.display=n===v.length-1?"":"none";}'
			. 'f.addEventListener("change",show);'
			. 'f.querySelector("[data-wiz=next]").addEventListener("click",function(){var v=vis(),n=v.indexOf(steps[cur]);cur=steps.indexOf(v[Math.min(n+1,v.length-1)]);show();window.scrollTo(0,0);});'
			. 'f.querySelector("[data-wiz=back]").addEventListener("click",function(){var v=vis(),n=v.indexOf(steps[cur]);cur=steps.indexOf(v[Math.max(n-1,0)]);show();window.scrollTo(0,0);});'
			. 'show();})();</script>';
	}
}
