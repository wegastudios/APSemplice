<?php /** @var array $plans */
$euro = function ( $c ) { return number_format( $c / 100, ( $c % 100 ) ? 2 : 0, ',', '.' ) . ' €'; };

// Testi di vetrina: si possono sostituire da config.php (plans.<codice>.tagline / features).
$copy = array(
	'free' => array( 'tagline' => 'Per partire subito, senza costi e senza scadenza.', 'features' => array(
		'Libro soci, ospiti e tessere con QR', 'Eventi, corsi, iscrizioni e lista d\'attesa', 'Prima nota con una cassa unica', 'Privacy, verbali e copia di sicurezza', 'Donazioni con PayPal',
	) ),
	'lite' => array( 'tagline' => 'Tutto il gratuito, più incassi online e comunicazione ai soci. Senza la parte contabile.', 'features' => array(
		'Tutto il piano Gratuito', 'Pagamenti online con carta e PayPal', 'Più quote socio e livelli', 'Comunicazioni email ai soci', 'App installabile e tessera nel telefono', 'Presenze e incasso sul posto',
	) ),
	'full' => array( 'tagline' => 'Per chi vuole anche la contabilità completa dell\'ente.', 'tag' => 'Il più completo', 'features' => array(
		'Tutto il Pro Lite', 'Conti, fondi e giroconti', 'Ricevute PDF e rendiconto', 'IVA e anni solari', '5 per mille', 'Assicurazioni dei soci',
	) ),
);
$features = array(
	array( 'users', 'Libro soci in ordine', 'Soci, ospiti e figure diverse, con tessera, quote e storico. Importi l\'elenco che hai già, in pochi minuti.' ),
	array( 'calendar', 'Eventi e corsi', 'Iscrizioni, prenotazioni, lista d\'attesa e calendario. Chi si iscrive riceve avvisi e promemoria per email.' ),
	array( 'book', 'Prima nota', 'Entrate e uscite sempre al loro posto, con allegati e viste pronte per il bilancio.' ),
	array( 'shield', 'Privacy senza pensieri', 'Consensi, informativa compilata, esportazione dei dati e anonimizzazione quando serve.' ),
	array( 'card', 'Area riservata per i soci', 'Ogni socio ha la sua pagina sul sito: tessera, pagamenti, ingressi. Nel menu del sito, non in wp-admin.' ),
	array( 'phone', 'Pagamenti e app', 'Con il Pro: quote e biglietti online con carta o PayPal, app installabile e tessera nel telefono.' ),
);
$save = 0;
foreach ( $plans as $p ) {
	if ( ! empty( $p['prices']['month']['cents'] ) && ! empty( $p['prices']['year']['cents'] ) ) {
		$save = max( $save, (int) round( 100 - 100 * $p['prices']['year']['cents'] / ( 12 * $p['prices']['month']['cents'] ) ) );
	}
}
$faq = array(
	array( 'Devo pagare per usare Associazione Semplice?', 'No. Il plugin è gratuito e resta tale. Serve solo una licenza gratuita, legata al dominio del tuo sito, che ricevi in due minuti.' ),
	array( 'Cosa significa «licenza legata a un dominio»?', 'La chiave funziona sul sito per cui l\'hai richiesta. Se cambi indirizzo, lo aggiorni tu dall\'area riservata (fino a 3 volte ogni 30 giorni), senza scrivere a nessuno.' ),
	array( 'Posso passare dal gratuito al Pro più avanti?', 'Sì, in qualsiasi momento. I dati restano dove sono: si attivano soltanto le funzioni in più.' ),
	array( 'Come funziona il rinnovo?', 'Il rinnovo è automatico, mensile o annuale a scelta. Puoi disdire quando vuoi dall\'area riservata: la licenza resta valida fino alla scadenza già pagata.' ),
	array( 'Cosa succede se un pagamento non va a buon fine?', 'Hai alcuni giorni di tolleranza. I tuoi dati non vengono mai cancellati: se la licenza scade, tornano disponibili le funzioni di base.' ),
	array( 'Come pago?', 'Con carta tramite Stripe oppure con PayPal. Non vediamo né conserviamo i dati della tua carta.' ),
);
?>
<section class="hero"><div class="wrap hero-grid">
	<div>
		<span class="eyebrow">Plugin WordPress per associazioni</span>
		<h1>La segreteria della tua associazione, finalmente semplice.</h1>
		<p class="lead">Soci, eventi, prima nota e privacy in un unico plugin per il tuo sito WordPress. Si parte gratis; i pagamenti online e la contabilità arrivano quando servono.</p>
		<div class="hero-cta">
			<a class="btn big" href="/free">Ottieni la licenza gratuita</a>
			<a class="btn alt big" href="#prezzi">Vedi i piani Pro</a>
		</div>
		<ul class="ticks"><li>Gratis, senza scadenza</li><li>Dati sempre tuoi</li><li>In italiano</li></ul>
	</div>
	<div class="mock" aria-hidden="true">
		<div class="mock-bar"><i></i><i></i><i></i></div>
		<div class="mock-stats"><div><b>128</b><span>Soci attivi</span></div><div><b>6</b><span>Eventi aperti</span></div><div><b>14</b><span>Da rinnovare</span></div></div>
		<div class="mock-row"><span class="avatar">GR</span><div><b>Giulia Rossi</b><span>Socia ordinaria · tessera 0042</span></div><span class="pill">In regola</span></div>
		<div class="mock-row"><span class="avatar">MB</span><div><b>Marco Bianchi</b><span>Corso di fotografia</span></div><span class="pill warn">Da pagare</span></div>
		<div class="mock-row"><span class="avatar">AV</span><div><b>Anna Verdi</b><span>Socia volontaria · tessera 0057</span></div><span class="pill">In regola</span></div>
		<div class="mock-row"><span class="avatar">LN</span><div><b>Luca Neri</b><span>Quota annuale 2026</span></div><span class="pill bad">Scaduta</span></div>
	</div>
</div></section>

<section class="section" id="funzioni"><div class="wrap">
	<div class="section-head"><h2>Tutto quello che serve a una segreteria</h2><p>Pensato per chi gestisce un'associazione nel tempo libero: poche schermate, parole chiare, niente da imparare a memoria.</p></div>
	<div class="grid-3">
	<?php foreach ( $features as $f ) : ?>
		<div class="feature"><div class="ico"><?php echo icon( $f[0] ); ?></div><h3><?php echo e( $f[1] ); ?></h3><p><?php echo e( $f[2] ); ?></p></div>
	<?php endforeach; ?>
	</div>
</div></section>

<section class="section alt"><div class="wrap">
	<div class="section-head"><h2>Si parte in tre passi</h2></div>
	<div class="steps">
		<div class="step"><h3>Chiedi la chiave</h3><p>Inserisci email e dominio del sito: la licenza gratuita arriva subito, nella tua area riservata.</p></div>
		<div class="step"><h3>Installa il plugin</h3><p>Lo installi da WordPress, incolli la chiave e la procedura guidata ti accompagna con poche domande.</p></div>
		<div class="step"><h3>Cresci quando vuoi</h3><p>Ti servono incassi online o contabilità? Passi al Pro dall'area riservata, senza reinstallare nulla.</p></div>
	</div>
</div></section>

<section class="section pricing" id="prezzi" data-period="year"><div class="wrap">
	<div class="section-head"><h2>Prezzi chiari, disdici quando vuoi</h2><p>Il piano gratuito resta gratuito. I piani Pro si rinnovano da soli e si cambiano o si disdicono in autonomia.</p></div>
	<div class="seg" role="group" aria-label="Periodo di fatturazione">
		<button type="button" data-period="month" aria-pressed="false">Mensile</button>
		<button type="button" data-period="year" aria-pressed="true">Annuale<?php echo $save > 0 ? ' <span class="save">-' . (int) $save . '%</span>' : ''; ?></button>
	</div>
	<div class="plans">
	<?php foreach ( $plans as $code => $p ) :
		if ( false === ( $p['visible'] ?? true ) ) { continue; }
		$c    = $copy[ $code ] ?? array( 'tagline' => $p['description'] ?? '', 'features' => array() );
		$tag  = $p['tag'] ?? ( $c['tag'] ?? '' );
		$feat = $p['features'] ?? $c['features'];
		$m    = $p['prices']['month']['cents'] ?? null;
		$y    = $p['prices']['year']['cents'] ?? null;
		$hot  = 'full' === $code;
	?>
		<div class="plan<?php echo $hot ? ' hot' : ''; ?>">
			<?php if ( $tag ) : ?><span class="tag"><?php echo e( $tag ); ?></span><?php endif; ?>
			<h3><?php echo e( $p['label'] ); ?></h3>
			<p class="for"><?php echo e( $p['tagline'] ?? $c['tagline'] ); ?></p>
			<?php if ( 'free' === $code ) : ?>
				<div class="price"><b>0 €</b><span>per sempre</span></div><div class="price-note">Serve solo la licenza gratuita</div>
			<?php else : ?>
				<?php if ( $m ) : ?><div class="only-month"><div class="price"><b><?php echo e( $euro( $m ) ); ?></b><span>al mese</span></div><div class="price-note">Rinnovo mensile, senza vincoli</div></div><?php endif; ?>
				<?php if ( $y ) : ?><div class="only-year"><div class="price"><b><?php echo e( $euro( (int) round( $y / 12 ) ) ); ?></b><span>al mese</span></div><div class="price-note"><?php echo e( $euro( $y ) ); ?> all'anno<?php echo $m && $m * 12 > $y ? ' · risparmi ' . e( $euro( $m * 12 - $y ) ) : ''; ?></div></div><?php endif; ?>
			<?php endif; ?>
			<ul><?php foreach ( $feat as $line ) : ?><li><?php echo e( $line ); ?></li><?php endforeach; ?></ul>
			<?php if ( 'free' === $code ) : ?>
				<a class="btn alt block" href="/free">Inizia gratis</a>
			<?php else : ?>
				<?php if ( $m ) : ?><a class="btn<?php echo $hot ? '' : ' alt'; ?> block only-month" href="/acquista?plan=<?php echo e( $code ); ?>&amp;period=month">Scegli <?php echo e( $p['label'] ); ?></a><?php endif; ?>
				<?php if ( $y ) : ?><a class="btn<?php echo $hot ? '' : ' alt'; ?> block only-year" href="/acquista?plan=<?php echo e( $code ); ?>&amp;period=year">Scegli <?php echo e( $p['label'] ); ?></a><?php endif; ?>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
	</div>
	<?php if ( ! empty( $trial ) ) : ?><p class="fine" style="font-weight:600;color:var(--fg)">Hai già la licenza gratuita? Prova il piano Pro gratis per <?php echo (int) $trial; ?> giorni dall'area riservata, una volta sola.</p><?php endif; ?>
	<p class="fine">Prezzi in euro. Pagamento sicuro con Stripe o PayPal. Ogni licenza è legata a un dominio, modificabile dall'area riservata.</p>
</div></section>

<section class="section alt" id="domande"><div class="wrap">
	<div class="section-head"><h2>Domande frequenti</h2></div>
	<div class="faq">
	<?php foreach ( $faq as $q ) : ?>
		<details><summary><?php echo e( $q[0] ); ?></summary><p><?php echo e( $q[1] ); ?></p></details>
	<?php endforeach; ?>
	</div>
</div></section>

<section class="section"><div class="wrap">
	<div class="cta-band">
		<h2>Metti ordine nella tua associazione oggi</h2>
		<p>La licenza gratuita arriva in due minuti. Il resto, quando ti serve.</p>
		<a class="btn big" href="/free">Ottieni la licenza gratuita</a>
	</div>
</div></section>
