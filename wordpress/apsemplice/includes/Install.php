<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

final class Install {

	const DB_VERSION_OPTION = 'apse_db_version';
	const DB_VERSION        = '5';

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
			if ( '' !== $old && version_compare( $old, '3', '<' ) ) {
				// Dalla v3 il contributo di un'attività è `fee_cents` (per i corsi resta "al mese").
				Db::db()->query( 'UPDATE ' . Db::t( 'activities' ) . ' SET fee_cents = monthly_fee_cents WHERE fee_cents = 0 AND monthly_fee_cents > 0' );
			}
		}
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
  sort_order int(11) NOT NULL DEFAULT 0,
  deleted_at datetime DEFAULT NULL,
  PRIMARY KEY  (id)
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
  receipt_id varchar(40) DEFAULT NULL,
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
  PRIMARY KEY  (id),
  UNIQUE KEY session_person (session_id,person_id),
  KEY person_id (person_id)
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
				array( 'Compenso / rimborso istruttore', 'instructor_reimbursement', $uscite ),
				array( 'Rimborso spese socio', 'member_reimbursement', $uscite ),
				array( 'Costi attività (materiali, noleggi)', 'activity_cost', $uscite ),
				array( 'Costi generali (affitto, utenze, assicurazione)', 'general_cost', 'Uscite di supporto generale' ),
				array( 'Rettifica di cassa', 'adjustment', 'Rettifiche' ),
			);
			foreach ( $seed as $s ) {
				$wpdb->insert( $cat, array( 'name' => $s[0], 'kind' => $s[1], 'fiscal_group' => $s[2] ) );
			}
		}
		$acc = Db::t( 'accounts' );
		if ( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $acc" ) ) {
			$wpdb->insert( $acc, array( 'name' => 'Cassa contanti', 'type' => 'cash', 'sort_order' => 0 ) );
			$wpdb->insert( $acc, array( 'name' => 'Conto corrente', 'type' => 'bank', 'sort_order' => 1 ) );
		}
	}
}
