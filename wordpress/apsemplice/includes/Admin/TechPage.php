<?php
namespace ApSemplice\Admin;

use ApSemplice\Levels;
use ApSemplice\PaymentConfig;
use ApSemplice\Plugin;
use ApSemplice\Settings;
use ApSemplice\WooBridge;
use ApSemplice\WooLinks;

defined( 'ABSPATH' ) || exit;

/** Impostazioni → Tecniche (integrazioni, ruoli e accessi) e Contabilità (opzioni contabili). Solo amministratori. */
final class TechPage {

	/** Ruoli che non si toccano: l'amministratore ha già tutto, la segreteria si gestisce dalle schede dei soci, il socio non è un ruolo di gestione. */
	const LOCKED_ROLES = array( 'administrator', 'apse_secretary', 'apse_member' );

	// ---------- Integrazioni ----------

	public static function render_integrations(): void {
		Ui::header( 'Integrazioni' );
		$pay    = \ApSemplice\Edition::has( 'payments' ); // WooCommerce e i gateway sono dei pagamenti online
		$woo    = $pay && WooBridge::active();
		$on     = PaymentConfig::WOOCOMMERCE === (string) Settings::get( 'payment_provider' );
		$prods  = $pay ? WooBridge::products() : array();
		$map    = $pay ? WooLinks::map() : array();
		$select = function ( string $name, int $current ) use ( $prods ) {
			$html = '<select name="' . esc_attr( $name ) . '"><option value="0">— nessun prodotto —</option>';
			foreach ( $prods as $id => $label ) {
				$html .= '<option value="' . (int) $id . '"' . selected( $current, $id, false ) . '>' . esc_html( $label ) . '</option>';
			}
			if ( $current && ! isset( $prods[ $current ] ) ) {
				$html .= '<option value="' . (int) $current . '" selected>Prodotto n. ' . (int) $current . ' (non trovato)</option>';
			}
			return $html . '</select>';
		};
		if ( $pay ) {
		echo '<h2>WooCommerce</h2>';
		if ( ! $woo ) {
			echo '<p class="description">WooCommerce non è attivo su questo sito: installalo e attivalo per far pagare quote e contributi dal negozio.</p>';
		} else {
			echo '<p class="description">I soci pagano quote e contributi dal carrello e dal checkout del negozio. Ogni quota (per livello di socio) e ogni corso o evento si collega a un prodotto semplice, preferibilmente virtuale: '
				. 'nel carrello il prezzo è quello calcolato da APSemplice (quota del livello, sconto familiare, mensilità dovute) e quando l\'ordine risulta pagato l\'incasso entra in prima nota con la sua ricevuta. '
				. 'Gli ordini senza voci APSemplice non vengono toccati.</p>';
			Ui::form_open( 'apse_save_woo', Ui::url( 'apse-tech' ) );
			echo '<p><label><input type="checkbox" name="woo_enabled" value="1"' . checked( $on, true, false ) . '> <strong>Usa WooCommerce per i pagamenti online</strong></label> '
				. '<span class="description">Al posto di Stripe e PayPal (il gateway scelto cambia).</span></p>';
			echo '<h3>Quote associative</h3><table class="widefat striped"><thead><tr><th>Livello</th><th>Prodotto</th></tr></thead><tbody>';
			foreach ( Levels::all() as $lv ) {
				echo '<tr><td>' . esc_html( $lv['name'] ) . ' <span class="description">(' . esc_html( \ApSemplice\MemberType::label( $lv['base_type'] ) ) . ')</span></td><td>' . $select( 'link_level[' . (int) $lv['id'] . ']', (int) ( $map[ 'level:' . (int) $lv['id'] ] ?? 0 ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</tbody></table>';
			echo '<h3>Corsi ed eventi</h3>';
			$acts = Plugin::activities()->for_year( Settings::social_year()->label() );
			if ( ! $acts ) {
				echo '<p class="description">Nessuna attività in questo anno sociale.</p>';
			} else {
				echo '<table class="widefat striped"><thead><tr><th>Attività</th><th>Prodotto</th></tr></thead><tbody>';
				foreach ( $acts as $a ) {
					echo '<tr><td>' . esc_html( $a['name'] ) . '</td><td>' . $select( 'link_activity[' . (int) $a['id'] . ']', (int) ( $map[ 'activity:' . (int) $a['id'] ] ?? 0 ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
				}
				echo '</tbody></table>';
			}
			echo '<h3>Prodotto generico</h3><p>' . $select( 'woo_default_product', (int) Settings::get( 'woo_default_product' ) ) . ' <span class="description">Per le voci senza un prodotto proprio (ad esempio le mensilità di un corso). Senza prodotto generico quelle voci non si possono pagare dal negozio.</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<p><button class="button button-primary">Salva</button></p>';
			Ui::form_close();
			echo '<p class="description">I rimborsi di un ordine si registrano a mano in prima nota.</p>';
		}
		}
		echo '<h2>Altre integrazioni</h2><ul>';
		if ( $pay ) echo '<li><strong>Stripe e PayPal</strong> — carta e PayPal senza negozio: <a href="' . esc_url( Ui::url( 'apse-payments' ) ) . '">Pagamenti online</a>.</li>';
		echo '<li><strong>Apple Wallet, Google Wallet, QR e calendario</strong>: <a href="' . esc_url( Ui::url( 'apse-card' ) ) . '">Tessera, QR e Wallet</a>.</li>';
		echo '<li><strong>WP All Import</strong> — ' . ( class_exists( 'PMXI_Plugin' ) ? 'attivo su questo sito' : 'non attivo' ) . ': <a href="' . esc_url( Ui::url( 'apse-wpai' ) ) . '">Import con WP All Import</a>.</li>';
		echo '<li><strong>Elementor e Gutenberg</strong> — widget e blocchi «APSemplice» disponibili dove gli editor sono attivi.</li></ul>';
		Ui::footer();
	}

	// ---------- Ruoli e accessi ----------

	/** @return array<string,array{name:string,has:bool,users:int}> */
	public static function roles(): array {
		$counts = count_users();
		$out    = array();
		foreach ( wp_roles()->roles as $key => $r ) {
			if ( in_array( $key, self::LOCKED_ROLES, true ) ) {
				continue;
			}
			$role        = get_role( $key );
			$out[ $key ] = array( 'name' => translate_user_role( (string) $r['name'] ), 'has' => (bool) ( $role && $role->has_cap( Plugin::CAP_OPS ) ), 'users' => (int) ( $counts['avail_roles'][ $key ] ?? 0 ) );
		}
		return $out;
	}

	/** Dà o toglie l'accesso operativo (come la segreteria) a un ruolo di WordPress. @throws \InvalidArgumentException */
	public static function set_role_access( string $key, bool $on ): void {
		if ( in_array( $key, self::LOCKED_ROLES, true ) ) {
			throw new \InvalidArgumentException( 'Questo ruolo non si cambia da qui.' );
		}
		$role = get_role( $key );
		if ( ! $role ) {
			throw new \InvalidArgumentException( 'Ruolo non trovato.' );
		}
		if ( $on && ! $role->has_cap( Plugin::CAP_OPS ) ) {
			$role->add_cap( Plugin::CAP_OPS );
		} elseif ( ! $on && $role->has_cap( Plugin::CAP_OPS ) ) {
			$role->remove_cap( Plugin::CAP_OPS );
		}
	}

	public static function render_roles(): void {
		Ui::header( 'Ruoli e accessi' );
		echo '<p class="description">Chi può usare il plugin: gli <strong>amministratori</strong> hanno tutto; la <strong>Segreteria APS</strong> lavora su soci, attività e contabilità senza toccare le impostazioni (si assegna dalla scheda del socio). '
			. 'Se ti serve, puoi dare lo stesso accesso operativo a un altro ruolo di WordPress (ad esempio «Editor» o un ruolo creato da te): chi lo ha vede e gestisce soci, attività, registri e prima nota, ma non le impostazioni, i pagamenti, la copia di sicurezza e i testi. '
			. 'Il tesoriere e lo staff degli eventi sono ruoli sulle persone (scheda del socio e scheda dell\'attività), non ruoli di WordPress.</p>';
		Ui::form_open( 'apse_save_roles', Ui::url( 'apse-roles' ) );
		echo '<table class="widefat striped"><thead><tr><th>Ruolo di WordPress</th><th>Utenti</th><th>Accesso operativo al plugin</th></tr></thead><tbody>';
		foreach ( self::roles() as $key => $r ) {
			echo '<tr><td>' . esc_html( $r['name'] ) . ' <span class="description">(' . esc_html( $key ) . ')</span></td><td>' . (int) $r['users'] . '</td><td><label><input type="checkbox" name="roles[]" value="' . esc_attr( $key ) . '"' . checked( $r['has'], true, false ) . '> Come la segreteria</label></td></tr>';
		}
		echo '</tbody></table><p><button class="button button-primary">Salva</button></p>';
		Ui::form_close();
		echo '<p class="description">Per dare l\'accesso a una sola persona, crea o assegna il ruolo «Segreteria APS» dalla sua scheda in Rubrica.</p>';
		self::roles_matrix();
		self::roles_people();
		Ui::footer();
	}

	/** Chi può fare cosa: i ruoli dell'ente. */
	public static function matrix(): array {
		$yes = '✓';
		$no  = '—';
		return array(
			'columns' => array( 'Amministratore', 'Segreteria', 'Presidente e vice', 'Tesoriere', 'Volontario', 'Staff dell\'ente', 'Staff dell\'evento', 'Socio' ),
			'rows'    => array(
				'Impostazioni, pagamenti online, copia di sicurezza' => array( $yes, $no, $no, $no, $no, $no, $no, $no ),
				'Soci e iscrizioni'                                   => array( $yes, $yes, $yes, 'iscrive', $no, $no, $no, $no ),
				'Incassi e spese'                                     => array( $yes, $yes, $yes, $yes, $no, $no, $no, $no ),
				'Contabilità, registri e adempimenti'                 => array( $yes, $yes, $yes, $no, $no, $no, $no, $no ),
				'Comunicazioni agli iscritti'                         => array( $yes, $yes, $yes, $no, 'ai propri eventi', $no, $no, $no ),
				'Eventi e attività'                                   => array( $yes, $yes, $yes, 'vende', 'propri', $no, $no, $no ),
				'Verifica degli ingressi'                             => array( $yes, $yes, $yes, $no, 'propri eventi', 'tutti gli eventi', 'suo evento', $no ),
				'Incasso del biglietto sul posto'                     => array( $yes, $yes, $yes, $yes, 'propri eventi', $no, 'se abilitato', $no ),
				'Propri dati, tessera e pagamenti'                    => array( $yes, $yes, $yes, $yes, $yes, $yes, $yes, $yes ),
			),
		);
	}

	private static function roles_matrix(): void {
		$m = self::matrix();
		echo '<h2>Chi può fare cosa</h2><p class="description">Il presidente e il vicepresidente (in regola con la tessera) agiscono come la segreteria su tutto. Il tesoriere lavora dall\'area riservata: incassa, registra spese, iscrive soci e vende gli eventi, ma non vede comunicazioni né impostazioni. Il volontario non gestisce soldi: gestisce comunicazioni e staff dei propri eventi. Lo staff verifica gli ingressi: di tutti gli eventi se è staff dell\'ente, solo di quello indicato se è staff dell\'evento (e lì, se abilitato, può anche incassare).</p>';
		echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th></th>';
		foreach ( $m['columns'] as $c ) {
			echo '<th>' . esc_html( $c ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $m['rows'] as $label => $cells ) {
			echo '<tr><td><strong>' . esc_html( $label ) . '</strong></td>';
			foreach ( $cells as $c ) {
				echo '<td>' . esc_html( $c ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	/** Chi ha oggi un ruolo che dà permessi: cariche, segreteria, tesorieri e staff. */
	public static function role_holders(): array {
		$out = array();
		foreach ( Plugin::people()->search() as $p ) {
			$roles = array();
			if ( in_array( (string) $p['board_role'], array( \ApSemplice\BoardRole::PRESIDENT, \ApSemplice\BoardRole::VICE_PRESIDENT ), true ) ) {
				$roles[] = \ApSemplice\BoardRole::label( $p['board_role'] ) . ' (come la segreteria)';
			}
			if ( ! empty( $p['wp_user_id'] ) ) {
				$u = (int) $p['wp_user_id'];
				if ( user_can( $u, Plugin::CAP_OPS ) && ! user_can( $u, Plugin::CAP ) && $roles === array() ) {
					$roles[] = 'Segreteria';
				}
				if ( \ApSemplice\Access::is_treasurer( $u ) ) {
					$roles[] = 'Tesoriere';
				}
				if ( \ApSemplice\Access::is_entity_staff( $u ) ) {
					$roles[] = 'Staff dell\'ente';
				}
			}
			if ( $roles ) {
				$out[] = array( 'person' => $p, 'roles' => $roles );
			}
		}
		return $out;
	}

	private static function roles_people(): void {
		$rows = self::role_holders();
		echo '<h2>Chi ha un ruolo</h2>';
		if ( ! $rows ) {
			echo '<p class="description">Nessuno ha ancora un ruolo con permessi particolari. Si assegnano dalla scheda della persona in Rubrica.</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:700px"><thead><tr><th>Persona</th><th>Ruolo</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td><a href="' . esc_url( Ui::url( 'apse-person', array( 'id' => (int) $r['person']['id'] ) ) ) . '">' . esc_html( trim( $r['person']['first_name'] . ' ' . $r['person']['last_name'] ) ) . '</a></td><td>' . esc_html( implode( ', ', $r['roles'] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	// ---------- Contabilità ----------

	public static function render_accounting(): void {
		Ui::header( 'Opzioni contabili' );
		Ui::form_open( 'apse_save_acct', Ui::url( 'apse-acct' ) );
		echo '<p><label><input type="checkbox" name="reports_enabled" value="1"' . checked( ! empty( Settings::get( 'reports_enabled' ) ), true, false ) . '> <strong>Report e rendiconto</strong></label><br>'
			. '<span class="description">Mostra le schede «Report» e «Rendiconto» in Contabilità (valutazione dell\'anno sociale, rendiconto per cassa in PDF). Spegnile se la contabilità la tiene qualcun altro: la prima nota resta.</span></p>';
		echo '<p><button class="button button-primary">Salva</button></p>';
		Ui::form_close();
		echo '<h2>Dove sono le altre impostazioni contabili</h2><ul>'
			. '<li><a href="' . esc_url( Ui::url( 'apse-settings' ) ) . '">Soci e quote</a> — quota associativa, livelli di socio, sconto del nucleo familiare.</li>'
			. '<li><a href="' . esc_url( Ui::url( 'apse-years' ) ) . '">Anni solari</a> — apertura e chiusura degli anni contabili.</li>'
			. '<li><a href="' . esc_url( Ui::url( 'apse-accounts' ) ) . '">Conti e fondi</a> — cassa, banca, POS e fondi per i rimborsi ai volontari.</li>'
			. '<li><a href="' . esc_url( Ui::url( 'apse-comms' ) ) . '">Privacy, regolamento e ricevute</a> — riga in fondo alla ricevuta, promemoria dei pagamenti.</li></ul>';
		Ui::footer();
	}
}
