<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

final class Install {

	const DB_VERSION_OPTION = 'apse_db_version';
	const DB_VERSION        = '22';

	public static function activate(): void {
		self::create_tables();
		self::add_roles_and_caps();
		self::seed();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/** Esegue gli aggiornamenti dello schema se il plugin è stato aggiornato senza riattivarlo. */
	public static function maybe_upgrade(): void {
		$old = (string) get_option( self::DB_VERSION_OPTION, '' );
		if ( $old !== self::DB_VERSION ) {
			self::activate();
			if ( '' !== $old && version_compare( $old, '21', '<' ) ) {
				self::migrate_reimbursements();
			}
			if ( '' !== $old && version_compare( $old, '17', '<' ) ) {
				self::migrate_membership_years();
			}
			if ( '' !== $old && version_compare( $old, '3', '<' ) ) {
				// Dalla v3 il contributo di un'attività è `fee_cents` (per i corsi resta "al mese").
				Db::db()->query( 'UPDATE ' . Db::t( 'activities' ) . ' SET fee_cents = monthly_fee_cents WHERE fee_cents = 0 AND monthly_fee_cents > 0' );
			}
		}
	}

	/**
	 * La tessera dura l'anno solare (scade il 31 dicembre): le iscrizioni registrate con l'anno sociale (es. "2025/2026", fino al 31 agosto)
	 * diventano l'anno solare in cui finivano ("2026", fino al 31 dicembre). Non si accorcia nessuna validità. @return int iscrizioni convertite
	 */
	public static function migrate_membership_years(): int {
		$db   = Db::db();
		$tbl  = Db::t( 'memberships' );
		$rows = $db->get_results( "SELECT * FROM $tbl WHERE deleted_at IS NULL AND social_year LIKE '%/%'", ARRAY_A ) ?: array();
		$n    = 0;
		foreach ( $rows as $r ) {
			$year = (int) substr( $r['social_year'], (int) strpos( $r['social_year'], '/' ) + 1, 4 );
			if ( $year < 2000 ) {
				continue;
			}
			$label    = (string) $year;
			$to       = $year . '-12-31';
			$existing = $db->get_row( $db->prepare( "SELECT * FROM $tbl WHERE person_id = %d AND social_year = %s", (int) $r['person_id'], $label ), ARRAY_A );
			if ( $existing && null === $existing['deleted_at'] ) {
				$db->update( $tbl, array( 'deleted_at' => Db::now() ), array( 'id' => (int) $r['id'] ) ); // c'è già l'anno solare: basta quello
			} elseif ( $existing ) {
				$db->update( $tbl, array( 'valid_from' => $r['valid_from'], 'valid_to' => $to, 'source' => $r['source'], 'transaction_id' => $r['transaction_id'], 'deleted_at' => null ), array( 'id' => (int) $existing['id'] ) );
				$db->update( $tbl, array( 'deleted_at' => Db::now() ), array( 'id' => (int) $r['id'] ) );
			} else {
				$db->update( $tbl, array( 'social_year' => $label, 'valid_to' => $to ), array( 'id' => (int) $r['id'] ) );
			}
			$n++;
		}
		return $n;
	}

	/**
	 * Per soci e volontari esistono solo rimborsi (mai compensi) e gli istruttori non sono un ruolo: la voce
	 * "Compenso / rimborso istruttore" si unisce a "Rimborso spese socio/volontario" (i movimenti passano alla voce unica).
	 *
	 * @return int voci unite
	 */
	public static function migrate_reimbursements(): int {
		$db     = Db::db();
		$cat    = Db::t( 'categories' );
		$tx     = Db::t( 'transactions' );
		$old    = $db->get_results( "SELECT * FROM $cat WHERE kind = 'instructor_reimbursement' AND deleted_at IS NULL ORDER BY id", ARRAY_A ) ?: array();
		$target = $db->get_row( "SELECT * FROM $cat WHERE kind = 'member_reimbursement' AND deleted_at IS NULL ORDER BY id LIMIT 1", ARRAY_A );
		$n      = 0;
		if ( ! $target && $old ) {
			$first = array_shift( $old ); // non c'è la voce unica: la prima vecchia diventa quella
			$db->update( $cat, array( 'kind' => 'member_reimbursement' ), array( 'id' => (int) $first['id'] ) );
			$target = $first;
			$n++;
		}
		foreach ( $old as $o ) {
			$db->query( $db->prepare( "UPDATE $tx SET category_id = %d WHERE category_id = %d", (int) $target['id'], (int) $o['id'] ) );
			$db->update( $cat, array( 'deleted_at' => Db::now() ), array( 'id' => (int) $o['id'] ) );
			$n++;
		}
		if ( $target ) {
			$db->update( $cat, array( 'name' => 'Rimborso spese socio/volontario' ), array( 'id' => (int) $target['id'] ) );
		}
		return $n;
	}

	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$p = $wpdb->prefix . 'apse_';

		// dbDelta è esigente: un campo per riga, due spazi dopo PRIMARY KEY, nomi per ogni KEY.
		$tables   = array();
		$tables[] = "CREATE TABLE {$p}people (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  wp_user_id bigint(20) unsigned DEFAULT NULL,
  type varchar(20) NOT NULL,
  card_number varchar(40) DEFAULT NULL,
  first_name varchar(120) NOT NULL,
  last_name varchar(120) NOT NULL,
  email varchar(190) DEFAULT NULL,
  phone varchar(60) DEFAULT NULL,
  tax_code varchar(32) DEFAULT NULL,
  host_person_id bigint(20) unsigned DEFAULT NULL,
  joined_on date DEFAULT NULL,
  suspended_at datetime DEFAULT NULL,
  notes text,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  deleted_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY card_number (card_number),
  UNIQUE KEY wp_user_id (wp_user_id),
  KEY type (type),
  KEY host_person_id (host_person_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}memberships (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  person_id bigint(20) unsigned NOT NULL,
  social_year varchar(12) NOT NULL,
  valid_from date NOT NULL,
  valid_to date NOT NULL,
  source varchar(20) NOT NULL,
  transaction_id bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  deleted_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY person_year (person_id,social_year),
  KEY transaction_id (transaction_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}accounts (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(120) NOT NULL,
  type varchar(20) NOT NULL,
  opening_cents bigint(20) NOT NULL DEFAULT 0,
  closed_at datetime DEFAULT NULL,
  sort_order int(11) NOT NULL DEFAULT 0,
  deleted_at datetime DEFAULT NULL,
  PRIMARY KEY  (id)
) $c;";

		$tables[] = "CREATE TABLE {$p}fiscal_years (
  year smallint(5) unsigned NOT NULL,
  status varchar(8) NOT NULL DEFAULT 'open',
  created_at datetime NOT NULL,
  closed_at datetime DEFAULT NULL,
  PRIMARY KEY  (year)
) $c;";

		$tables[] = "CREATE TABLE {$p}funds (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(200) NOT NULL,
  activity_id bigint(20) unsigned DEFAULT NULL,
  person_id bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  closed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY activity_id (activity_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}fund_entries (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  fund_id bigint(20) unsigned NOT NULL,
  kind varchar(8) NOT NULL,
  cents bigint(20) NOT NULL,
  tx_id bigint(20) unsigned DEFAULT NULL,
  entry_date date NOT NULL,
  note varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY fund_id (fund_id),
  KEY tx_id (tx_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}categories (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(160) NOT NULL,
  kind varchar(40) NOT NULL,
  fiscal_group varchar(160) DEFAULT NULL,
  deleted_at datetime DEFAULT NULL,
  PRIMARY KEY  (id)
) $c;";

		$tables[] = "CREATE TABLE {$p}activities (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(160) NOT NULL,
  social_year varchar(12) NOT NULL,
  instructor_person_id bigint(20) unsigned DEFAULT NULL,
  monthly_fee_cents bigint(20) NOT NULL DEFAULT 0,
  kind varchar(20) NOT NULL DEFAULT 'course',
  fee_cents bigint(20) NOT NULL DEFAULT 0,
  guest_fee_cents bigint(20) DEFAULT NULL,
  cancellable tinyint(1) NOT NULL DEFAULT 0,
  cancel_policy varchar(4) DEFAULT NULL,
  booking_qr tinyint(1) NOT NULL DEFAULT 0,
  lesson_weekday tinyint(1) NOT NULL DEFAULT 0,
  lesson_slots text,
  billing varchar(8) NOT NULL DEFAULT 'monthly',
  lesson_start char(5) DEFAULT NULL,
  lesson_end char(5) DEFAULT NULL,
  location varchar(190) DEFAULT NULL,
  starts_on date DEFAULT NULL,
  ends_on date DEFAULT NULL,
  fund_mode varchar(8) NOT NULL DEFAULT '',
  fund_value bigint(20) NOT NULL DEFAULT 0,
  notes text,
  created_at datetime NOT NULL,
  deleted_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY social_year (social_year)
) $c;";

		$tables[] = "CREATE TABLE {$p}enrollments (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  activity_id bigint(20) unsigned NOT NULL,
  person_id bigint(20) unsigned NOT NULL,
  start_month char(7) NOT NULL,
  end_month char(7) DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY activity_person (activity_id,person_id),
  KEY person_id (person_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}transactions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tx_date date NOT NULL,
  type varchar(20) NOT NULL,
  amount_cents bigint(20) NOT NULL,
  account_id bigint(20) unsigned NOT NULL,
  method varchar(20) NOT NULL,
  category_id bigint(20) unsigned NOT NULL,
  activity_id bigint(20) unsigned DEFAULT NULL,
  person_id bigint(20) unsigned DEFAULT NULL,
  description varchar(255) NOT NULL DEFAULT '',
  session_id bigint(20) unsigned DEFAULT NULL,
  competence_month char(7) DEFAULT NULL,
  social_year varchar(12) DEFAULT NULL,
  document_ref varchar(80) DEFAULT NULL,
  discount_cents bigint(20) NOT NULL DEFAULT 0,
  payer_person_id bigint(20) unsigned DEFAULT NULL,
  receipt_id varchar(40) DEFAULT NULL,
  import_batch bigint(20) unsigned DEFAULT NULL,
  transfer_id varchar(40) DEFAULT NULL,
  void_reason varchar(255) DEFAULT NULL,
  voided_at datetime DEFAULT NULL,
  created_by bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY tx_date (tx_date),
  KEY account_id (account_id),
  KEY activity_id (activity_id),
  KEY person_id (person_id),
  KEY receipt_id (receipt_id),
  KEY import_batch (import_batch),
  KEY transfer_id (transfer_id),
  KEY session_id (session_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}cash_counts (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  account_id bigint(20) unsigned NOT NULL,
  count_date date NOT NULL,
  counted_cents bigint(20) NOT NULL,
  expected_cents bigint(20) NOT NULL,
  difference_cents bigint(20) NOT NULL,
  adjustment_tx_id bigint(20) unsigned DEFAULT NULL,
  notes varchar(255) DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY account_id (account_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}sessions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  activity_id bigint(20) unsigned NOT NULL,
  session_date date NOT NULL,
  start_time char(5) DEFAULT NULL,
  end_time char(5) DEFAULT NULL,
  location varchar(190) DEFAULT NULL,
  capacity int(11) DEFAULT NULL,
  notes varchar(255) DEFAULT NULL,
  cancelled_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY activity_id (activity_id),
  KEY session_date (session_date)
) $c;";

		$tables[] = "CREATE TABLE {$p}bookings (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint(20) unsigned NOT NULL,
  person_id bigint(20) unsigned NOT NULL,
  status varchar(12) NOT NULL DEFAULT 'booked',
  fee_due_cents bigint(20) NOT NULL DEFAULT 0,
  transferred_to bigint(20) unsigned DEFAULT NULL,
  transferred_from bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  cancelled_at datetime DEFAULT NULL,
  checked_in_at datetime DEFAULT NULL,
  checked_in_by bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY session_person (session_id,person_id),
  KEY person_id (person_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}activity_staff (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  activity_id bigint(20) unsigned NOT NULL,
  person_id bigint(20) unsigned NOT NULL,
  can_cash tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY activity_person (activity_id,person_id),
  KEY person_id (person_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}notices (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  activity_id bigint(20) unsigned NOT NULL,
  session_id bigint(20) unsigned DEFAULT NULL,
  author_user_id bigint(20) unsigned DEFAULT NULL,
  author_name varchar(120) NOT NULL DEFAULT '',
  subject varchar(160) NOT NULL,
  body text NOT NULL,
  recipients int(11) NOT NULL DEFAULT 0,
  emailed int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY activity_id (activity_id),
  KEY created_at (created_at)
) $c;";

		$tables[] = "CREATE TABLE {$p}payments (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  public_id varchar(40) NOT NULL,
  provider varchar(12) NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'created',
  amount_cents bigint(20) NOT NULL,
  currency char(3) NOT NULL DEFAULT 'EUR',
  payer_person_id bigint(20) unsigned NOT NULL,
  payer_user_id bigint(20) unsigned NOT NULL,
  items longtext NOT NULL,
  provider_ref varchar(120) DEFAULT NULL,
  provider_payment_id varchar(120) DEFAULT NULL,
  allocated_cents bigint(20) NOT NULL DEFAULT 0,
  review tinyint(1) NOT NULL DEFAULT 0,
  error varchar(255) DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  paid_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY public_id (public_id),
  KEY provider_ref (provider_ref),
  KEY status (status),
  KEY payer_person_id (payer_person_id)
) $c;";

		$tables[] = "CREATE TABLE {$p}attachments (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  transaction_id bigint(20) unsigned NOT NULL,
  original_name varchar(190) NOT NULL,
  stored_name varchar(40) NOT NULL,
  mime varchar(60) NOT NULL,
  size_bytes bigint(20) unsigned NOT NULL,
  sha256 char(64) NOT NULL,
  uploaded_by bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  removed_at datetime DEFAULT NULL,
  removed_by bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY transaction_id (transaction_id),
  KEY sha256 (sha256)
) $c;";

		$tables[] = "CREATE TABLE {$p}import_batches (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  source varchar(190) NOT NULL DEFAULT '',
  summary text,
  data longtext,
  undone_at datetime DEFAULT NULL,
  undone_by bigint(20) unsigned DEFAULT NULL,
  undo_result text,
  PRIMARY KEY  (id)
) $c;";

		$tables[] = "CREATE TABLE {$p}audit_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  action varchar(60) NOT NULL,
  object_type varchar(30) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned DEFAULT NULL,
  details text,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY action (action)
) $c;";

		foreach ( $tables as $sql ) {
			dbDelta( $sql );
		}
	}

	public static function add_roles_and_caps(): void {
		// Ruolo per i soci creati come utenti: può solo "read", quindi nessun accesso alla gestione.
		if ( ! get_role( Plugin::ROLE_MEMBER ) ) {
			add_role( Plugin::ROLE_MEMBER, 'Socio APS', array( 'read' => true ) );
		}
		// Per ora solo gli amministratori gestiscono il plugin. I ruoli (tesoriere, operatore...) verranno dopo.
		$admin = get_role( 'administrator' );
		if ( $admin && ! $admin->has_cap( Plugin::CAP ) ) {
			$admin->add_cap( Plugin::CAP );
		}
	}

	public static function seed(): void {
		global $wpdb;
		$cat = Db::t( 'categories' );
		if ( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $cat" ) ) {
			$entrate  = 'Entrate da attività di interesse generale';
			$uscite   = 'Uscite da attività di interesse generale';
			$seed     = array(
				array( 'Quota associativa', 'membership', 'Entrate da quote associative' ),
				array( 'Quota attività / corso', 'activity_fee', $entrate ),
				array( 'Erogazione liberale', 'donation', 'Erogazioni liberali' ),
				array( 'Altre entrate', 'other_income', 'Altre entrate' ),
				array( 'Rimborso spese socio/volontario', 'member_reimbursement', $uscite ),
				array( 'Costi attività (materiali, noleggi)', 'activity_cost', $uscite ),
				array( 'Costi generali (affitto, utenze, assicurazione)', 'general_cost', 'Uscite di supporto generale' ),
				array( 'Rettifica di cassa', 'adjustment', 'Rettifiche' ),
			);
			foreach ( $seed as $s ) {
				$wpdb->insert( $cat, array( 'name' => $s[0], 'kind' => $s[1], 'fiscal_group' => $s[2] ) );
			}
		}
		FiscalYears::seed(); // l'anno solare in corso (e quelli con movimenti) esiste sempre
		$acc = Db::t( 'accounts' );
		if ( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $acc" ) ) {
			$wpdb->insert( $acc, array( 'name' => 'Cassa contanti', 'type' => 'cash', 'sort_order' => 0 ) );
			$wpdb->insert( $acc, array( 'name' => 'Conto corrente', 'type' => 'bank', 'sort_order' => 1 ) );
		}
	}
}
