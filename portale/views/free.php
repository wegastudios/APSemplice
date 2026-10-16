<section class="page"><div class="wrap page-grid">
	<div class="panel">
		<span class="eyebrow">Gratis, senza scadenza</span>
		<h1>Ottieni la licenza gratuita</h1>
		<p class="muted">Due dati e la chiave è tua. Ti scriviamo un link per confermare l'email.</p>
		<form method="post" action="/free">
			<input type="hidden" name="csrf" value="<?php echo e( Portale\App::csrf() ); ?>">
			<label for="email">La tua email</label>
			<input id="email" type="email" name="email" placeholder="segreteria@miaassociazione.it" autocomplete="email" required>
			<label for="domain">Indirizzo del sito WordPress</label>
			<input id="domain" type="text" name="domain" value="<?php echo e( \Portale\Domain::normalize( (string) ( $_GET['domain'] ?? '' ) ) ); ?>" placeholder="miaassociazione.it" autocapitalize="none" spellcheck="false" required>
			<p class="hint">Basta il dominio, senza «https://». Lo potrai cambiare dall'area riservata.</p>
			<button class="btn big block" type="submit">Ricevi la chiave</button>
		</form>
	</div>
	<div class="side">
		<h2>Cosa ottieni</h2>
		<ul>
			<li><?php echo icon( 'users' ); ?><div><b>Libro soci e tessere</b>Soci, ospiti, quote e QR della tessera.</div></li>
			<li><?php echo icon( 'calendar' ); ?><div><b>Eventi e corsi</b>Iscrizioni, lista d'attesa e promemoria.</div></li>
			<li><?php echo icon( 'book' ); ?><div><b>Prima nota</b>Entrate e uscite con una cassa unica.</div></li>
			<li><?php echo icon( 'shield' ); ?><div><b>Privacy e copia di sicurezza</b>Informativa, consensi, verbali e backup.</div></li>
		</ul>
		<p class="muted small">Nessuna carta di credito richiesta. Se un giorno ti serve di più, passi al Pro senza perdere nulla.</p>
	</div>
</div></section>
