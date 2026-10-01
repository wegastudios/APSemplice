<?php
namespace ApSemplice\Frontend;

use ApSemplice\Access;
use ApSemplice\License;
use ApSemplice\MemberType;
use ApSemplice\Plugin;
use ApSemplice\Settings;
use ApSemplice\Visibility;

defined( 'ABSPATH' ) || exit;

/**
 * Contenuti riservati ai soci: pagine e articoli interi (riquadro "Accesso" nell'editor) e singole parti di pagina
 * (shortcode / blocco / widget "Contenuto riservato"). Le regole sono in {@see Visibility}.
 *
 * Esempio: la pagina dell'evento del corso è pubblica; il "programma della prima lezione" è visibile solo
 * ai soci iscritti a quell'attività.
 */
final class Restrict {

	const META_RULE       = '_aps_access';
	const META_ACTIVITIES = '_aps_access_activities';

	public static function register(): void {
		add_filter( 'the_content', array( __CLASS__, 'filter_content' ), 999 );
		add_filter( 'the_excerpt', array( __CLASS__, 'filter_excerpt' ), 999 );
		add_filter( 'get_the_excerpt', array( __CLASS__, 'filter_get_excerpt' ), 999, 2 );
		add_filter( 'the_content_feed', array( __CLASS__, 'filter_content' ), 999 );
		add_action( 'init', array( __CLASS__, 'hook_post_types' ), 20 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save_meta_box' ), 10, 2 );
		add_shortcode( 'apsemplice_riservato', array( __CLASS__, 'shortcode' ) );
	}

	/** Tipi di contenuto che si possono riservare: tutti quelli pubblici (pagine, articoli, tipi personalizzati). */
	public static function post_types(): array {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		return array_values( $types );
	}

	public static function hook_post_types(): void {
		foreach ( self::post_types() as $type ) {
			add_filter( "rest_prepare_$type", array( __CLASS__, 'filter_rest' ), 10, 2 );
			add_filter( "manage_{$type}_posts_columns", array( __CLASS__, 'add_column' ) );
			add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'print_column' ), 10, 2 );
		}
	}

	// ---------- Regole e permessi ----------

	/** @return array [regola, id attività] di un contenuto */
	public static function rule_of( int $post_id ): array {
		$rule = (string) get_post_meta( $post_id, self::META_RULE, true );
		if ( ! Visibility::is_valid( $rule ) ) {
			$rule = Visibility::PUBLIC_;
		}
		$ids = array_values( array_filter( array_map( 'intval', (array) get_post_meta( $post_id, self::META_ACTIVITIES, true ) ) ) );
		return array( $rule, $ids );
	}

	/** Dati dell'utente per {@see Visibility::decide()}. */
	public static function context( ?int $user_id = null, array $required_activity_ids = array() ): array {
		$uid    = $user_id ?? get_current_user_id();
		$person = $uid > 0 ? Access::person_for_user( $uid ) : null;
		$ctx    = array(
			'is_admin'              => Access::is_admin_user( $uid ),
			'logged_in'             => $uid > 0,
			'member_area_allowed'   => License::allows( 'member_area' ),
			'active_member'         => false,
			'person_type'           => $person ? $person['type'] : null,
			'required_activity_ids' => $required_activity_ids,
			'my_activity_ids'       => array(),
			'taught_activity_ids'   => array(),
		);
		if ( $person ) {
			$ctx['active_member']       = MemberType::is_member( $person['type'] ) && Plugin::people()->is_active_member( (int) $person['id'] );
			$ctx['my_activity_ids']     = Plugin::activities()->person_activity_ids( (int) $person['id'] );
			$ctx['taught_activity_ids'] = Plugin::activities()->taught_activity_ids( (int) $person['id'] );
		}
		return $ctx;
	}

	public static function allowed( string $rule, array $activity_ids = array(), ?int $user_id = null ): bool {
		if ( Visibility::PUBLIC_ === $rule ) {
			return true;
		}
		return Visibility::decide( $rule, self::context( $user_id, $activity_ids ) );
	}

	public static function can_view_post( int $post_id, ?int $user_id = null ): bool {
		list( $rule, $ids ) = self::rule_of( $post_id );
		return self::allowed( $rule, $ids, $user_id );
	}


	/** Indirizzo della pagina corrente (per tornarci dopo il login). */
	public static function current_url(): string {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		return '' === $host ? home_url( '/' ) : set_url_scheme( '//' . $host . $uri );
	}
	// ---------- Messaggio al posto del contenuto ----------

	private static function activity_names( array $ids ): array {
		$names = array();
		foreach ( $ids as $id ) {
			$a = Plugin::activities()->get( (int) $id );
			if ( $a ) {
				$names[] = $a['name'];
			}
		}
		return $names;
	}

	/** Riquadro mostrato a chi non può vedere il contenuto. */
	public static function gate_html( string $rule, array $activity_ids, string $return_url = '', string $custom_message = '' ): string {
		Assets::enqueue();
		$ctx    = self::context( null, $activity_ids );
		$reason = Visibility::denial_reason( $ctx );
		if ( '' !== $custom_message ) {
			$text = $custom_message;
		} elseif ( '' !== (string) Settings::get( 'gate_message' ) && 'license' !== $reason ) {
			$text = (string) Settings::get( 'gate_message' );
		} elseif ( 'license' === $reason ) {
			$text = 'Questo contenuto non è al momento disponibile.';
		} elseif ( Visibility::ACTIVITY === $rule && $activity_ids ) {
			$text = 'Contenuto riservato ai soci iscritti a: ' . implode( ', ', self::activity_names( $activity_ids ) ) . '.';
		} elseif ( Visibility::VOLUNTEERS === $rule ) {
			$text = 'Contenuto riservato ai soci e volontari.';
		} else {
			$text = 'Contenuto riservato ai soci.';
		}
		$html = '<div class="apsf apsf-gate"><p class="apsf-gate-text">🔒 ' . esc_html( $text ) . '</p>';
		if ( 'login' === $reason ) {
			$url   = wp_login_url( '' !== $return_url ? $return_url : self::current_url() );
			$html .= '<p><a class="apsf-btn wp-element-button" href="' . esc_url( $url ) . '">Accedi</a></p>';
		}
		$html .= '</div>';
		return (string) apply_filters( 'aps_gate_html', $html, $rule, $activity_ids );
	}

	// ---------- Filtri sul contenuto ----------

	public static function filter_content( $content ) {
		$id = get_the_ID();
		if ( ! $id ) {
			return $content;
		}
		list( $rule, $ids ) = self::rule_of( (int) $id );
		if ( Visibility::PUBLIC_ === $rule || self::allowed( $rule, $ids ) ) {
			return $content;
		}
		return self::gate_html( $rule, $ids, (string) get_permalink( $id ) );
	}

	public static function filter_excerpt( $excerpt ) {
		$id = get_the_ID();
		return $id && ! self::can_view_post( (int) $id ) ? '' : $excerpt;
	}

	public static function filter_get_excerpt( $excerpt, $post = null ) {
		$post = get_post( $post );
		return $post && ! self::can_view_post( (int) $post->ID ) ? '' : $excerpt;
	}

	/** Anche l'API REST non deve rivelare il contenuto riservato. */
	public static function filter_rest( $response, $post ) {
		if ( self::can_view_post( (int) $post->ID ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( isset( $data['content'] ) ) {
			$data['content']['rendered']  = '';
			$data['content']['protected'] = true;
			unset( $data['content']['raw'] );
		}
		if ( isset( $data['excerpt'] ) ) {
			$data['excerpt']['rendered']  = '';
			$data['excerpt']['protected'] = true;
		}
		$data['aps_restricted'] = true;
		$response->set_data( $data );
		return $response;
	}

	// ---------- Riquadro nell'editor ----------

	public static function add_meta_box(): void {
		foreach ( self::post_types() as $type ) {
			add_meta_box( 'aps_access', 'Accesso (APSemplice)', array( __CLASS__, 'render_meta_box' ), $type, 'side', 'default' );
		}
	}

	public static function render_meta_box( \WP_Post $post ): void {
		list( $rule, $ids ) = self::rule_of( (int) $post->ID );
		wp_nonce_field( 'aps_access_save', 'aps_access_nonce' );
		echo '<p class="description">Chi può leggere questo contenuto?</p>';
		foreach ( Visibility::labels() as $value => $label ) {
			echo '<p style="margin:4px 0"><label><input type="radio" name="aps_access" value="' . esc_attr( $value ) . '"' . checked( $rule, $value, false ) . '> ' . esc_html( $label ) . '</label></p>';
		}
		echo '<div id="aps-access-activities" style="margin-top:8px"><label for="aps-access-activities-select">Attività:</label><br>';
		echo '<select multiple size="6" style="width:100%" id="aps-access-activities-select" name="aps_access_activities[]">';
		foreach ( Plugin::activities()->all_for_select() as $a ) {
			echo '<option value="' . (int) $a['id'] . '"' . selected( in_array( (int) $a['id'], $ids, true ), true, false ) . '>' . esc_html( $a['name'] . ' (' . $a['social_year'] . ')' ) . '</option>';
		}
		echo '</select><p class="description">Vedono il contenuto gli iscritti (o prenotati) a una qualsiasi delle attività scelte e chi le tiene.</p></div>';
		echo '<p class="description">Gli amministratori vedono sempre tutto.</p>';
		echo '<script>(function(){var box=document.getElementById("aps-access-activities");if(!box){return;}var radios=document.querySelectorAll("input[name=aps_access]");function u(){var v="";radios.forEach(function(r){if(r.checked){v=r.value;}});box.style.display=v==="activity"?"":"none";}radios.forEach(function(r){r.addEventListener("change",u);});u();})();</script>';
	}

	public static function save_meta_box( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['aps_access_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aps_access_nonce'] ) ), 'aps_access_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$rule = isset( $_POST['aps_access'] ) ? sanitize_key( wp_unslash( $_POST['aps_access'] ) ) : Visibility::PUBLIC_;
		if ( ! Visibility::is_valid( $rule ) ) {
			$rule = Visibility::PUBLIC_;
		}
		$ids = isset( $_POST['aps_access_activities'] ) ? array_values( array_filter( array_map( 'intval', (array) wp_unslash( $_POST['aps_access_activities'] ) ) ) ) : array();
		if ( Visibility::PUBLIC_ === $rule ) {
			delete_post_meta( $post_id, self::META_RULE );
			delete_post_meta( $post_id, self::META_ACTIVITIES );
			return;
		}
		update_post_meta( $post_id, self::META_RULE, $rule );
		update_post_meta( $post_id, self::META_ACTIVITIES, Visibility::ACTIVITY === $rule ? $ids : array() );
	}

	// ---------- Colonna "Accesso" negli elenchi ----------

	public static function add_column( $columns ) {
		$columns['aps_access'] = 'Accesso';
		return $columns;
	}

	public static function print_column( $column, $post_id ): void {
		if ( 'aps_access' !== $column ) {
			return;
		}
		list( $rule, $ids ) = self::rule_of( (int) $post_id );
		echo Visibility::PUBLIC_ === $rule ? '—' : '🔒 ' . esc_html( Visibility::short_label( $rule, self::activity_names( $ids ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	// ---------- Parti di pagina: shortcode, blocco, widget ----------

	private static function rule_from_text( string $t ): string {
		$map = array(
			'soci' => Visibility::MEMBERS, 'members' => Visibility::MEMBERS,
			'volontari' => Visibility::VOLUNTEERS, 'volunteers' => Visibility::VOLUNTEERS,
			'attivita' => Visibility::ACTIVITY, 'attività' => Visibility::ACTIVITY, 'activity' => Visibility::ACTIVITY,
		);
		return $map[ strtolower( trim( $t ) ) ] ?? Visibility::MEMBERS;
	}

	/** [apsemplice_riservato accesso="soci|volontari|attivita" attivita="12,13" messaggio="..."]contenuto[/apsemplice_riservato] */
	public static function shortcode( $atts, $content = '' ): string {
		$a = shortcode_atts( array( 'accesso' => 'soci', 'attivita' => '', 'messaggio' => '' ), (array) $atts, 'apsemplice_riservato' );
		$ids = array_values( array_filter( array_map( 'intval', preg_split( '/[\s,;]+/', (string) $a['attivita'] ) ?: array() ) ) );
		return self::render_reserved( self::rule_from_text( (string) $a['accesso'] ), $ids, do_shortcode( (string) $content ), (string) $a['messaggio'] );
	}

	/** Mostra $content a chi può vederlo, altrimenti il riquadro "riservato". Usato da shortcode, blocco e widget. */
	public static function render_reserved( string $rule, array $activity_ids, string $content, string $message = '' ): string {
		if ( self::allowed( $rule, $activity_ids ) ) {
			return $content;
		}
		return self::gate_html( $rule, $activity_ids, '', $message );
	}
}
