<?php
namespace ApSemplice\Admin;

use ApSemplice\Guide;

defined( 'ABSPATH' ) || exit;

/** Guida iniziale: cosa fare per cominciare, le funzioni facoltative e le risposte alle domande più comuni. */
final class GuidePage {

	const FAQ = array(
		'Come iscrivo un nuovo socio?'           => 'Rubrica soci → Soci e ospiti → «Nuovo socio». Con l\'email il socio ha subito l\'accesso all\'area riservata; senza email si attiva dopo, con un link da mandargli su WhatsApp. Per molti soci insieme usa «Importa da Excel/CSV».',
		'Come incasso una quota o un contributo?' => 'Cassa → Nuovo incasso (o la cassa rapida in Bacheca). Scegli la persona: il plugin propone la quota associativa, le mensilità dei corsi dovute e gli eventi. Per più persone insieme usa «Cassa per più persone».',
		'Come creo un corso o un evento?'        => 'Corsi ed eventi → «Nuova attività». Il programma si compone a righe: date uniche o giorni che si ripetono ogni settimana. I corsi si rinnovano ogni mese; gli eventi si prenotano a una data.',
		'Come registro le presenze?'             => 'Registri → Presenze: scegli il corso, il mese e la lezione, spunta chi era presente e salva. Il riepilogo si scarica in PDF o CSV.',
		'Come invio un messaggio ai soci?'       => 'Rubrica soci → Comunicazioni: scegli il gruppo (soci in regola, scaduti, iscritti a un corso…), scrivi il messaggio, controlla i destinatari e invia.',
		'Dove trovo ricevute e rendiconto?'      => 'Le ricevute si scaricano dalla prima nota e dall\'area soci. Il rendiconto per cassa è in Contabilità → Rendiconto, in PDF con le firme.',
		'Come cambio i testi o i termini?'       => 'Impostazioni → Testi personalizzati: puoi scegliere il tipo di ente («socio»/«socia», «iscritto», «sostenitore»…), cambiare ogni frase e anche passare a un\'altra lingua.',
		'Come faccio una copia dei dati?'        => 'Impostazioni → Copia di sicurezza: scarichi un file con tutti i dati; da lì puoi anche ripristinarlo.',
	);

	public static function render(): void {
		Ui::header( 'Guida iniziale' );
		$p = Guide::progress();
		echo '<p>Per mettere in funzione APSemplice ci sono pochi passi: ' . (int) $p['done'] . ' su ' . (int) $p['total'] . ' già fatti.</p>';
		echo '<table class="widefat striped"><tbody>';
		foreach ( Guide::steps() as $s ) {
			echo '<tr><td style="width:2em">' . ( $s['done'] ? '✅' : '⬜' ) . '</td><td><strong>' . esc_html( $s['title'] ) . '</strong><br><span class="description">' . esc_html( $s['hint'] ) . '</span></td>'
				. '<td style="text-align:right">' . ( $s['done'] ? '<span class="description">fatto</span>' : '<a class="button" href="' . esc_url( Ui::url( $s['page'] ) ) . '">Vai</a>' ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>Funzioni facoltative</h2><p class="description">Sono spente finché non le attivi: accendi solo quelle che ti servono.</p><table class="widefat striped"><tbody>';
		foreach ( Guide::options() as $o ) {
			echo '<tr><td><strong>' . esc_html( $o['title'] ) . '</strong><br><span class="description">' . esc_html( $o['hint'] ) . '</span></td><td>' . ( $o['on'] ? 'Attiva' : 'Spenta' ) . '</td>'
				. '<td style="text-align:right"><a class="button" href="' . esc_url( Ui::url( $o['page'] ) ) . '">' . ( $o['on'] ? 'Gestisci' : 'Attiva' ) . '</a></td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>Domande frequenti</h2>';
		foreach ( self::FAQ as $q => $a ) {
			echo '<details class="apse-card"><summary><strong>' . esc_html( $q ) . '</strong></summary><p>' . esc_html( $a ) . '</p></details>';
		}

		$uid = get_current_user_id();
		Ui::form_open( 'apse_guide_dismiss', Ui::url( 'apse-guide' ), false, 'apse-inline' );
		if ( Guide::dismissed( $uid ) ) {
			echo Ui::hidden( 'on', '0' ) . '<p><button class="button">Mostra di nuovo l\'avviso in Bacheca</button></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		} else {
			echo Ui::hidden( 'on', '1' ) . '<p><button class="button">Nascondi l\'avviso in Bacheca</button></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		Ui::form_close();
		Ui::footer();
	}
}
