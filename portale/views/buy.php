<?php /** @var string $plan @var string $period @var array $price @var string $label @var array $methods @var int $days */
$year  = 'year' === $period;
$names = array(
	'stripe:recurring' => array( 'Carta · rinnovo automatico', 'Si rinnova da sola ' . ( $year ? 'ogni anno' : 'ogni mese' ) . '. Disdici quando vuoi.' ),
	'paypal:recurring' => array( 'PayPal · rinnovo automatico', 'Si rinnova da sola ' . ( $year ? 'ogni anno' : 'ogni mese' ) . '. Disdici quando vuoi.' ),
	'stripe:once'      => array( 'Carta · pagamento singolo', 'Paghi una volta sola: la licenza vale ' . ( $year ? '1 anno' : '1 mese' ) . ' e non si rinnova da sola.' ),
	'paypal:once'      => array( 'PayPal · pagamento singolo', 'Paghi una volta sola: la licenza vale ' . ( $year ? '1 anno' : '1 mese' ) . ' e non si rinnova da sola.' ),
);
?>
<section class="page"><div class="wrap page-grid">
	<div class="panel">
		<span class="eyebrow">Piano <?php echo e( $label ); ?></span>
		<h1>Completa l'acquisto</h1>
		<div class="summary"><span><?php echo e( $label ); ?> · <?php echo $year ? 'annuale' : 'mensile'; ?></span><b><?php echo e( money( $price['cents'] ) ); ?> <small class="muted"><?php echo $year ? '/ anno' : '/ mese'; ?></small></b></div>
		<?php if ( ! $methods ) : ?>
			<p class="muted">Questo piano non è ancora acquistabile: riprova tra poco o <a href="mailto:info@associazionesemplice.it">scrivici</a>.</p>
		<?php else : ?>
		<form method="post" action="/acquista">
			<input type="hidden" name="csrf" value="<?php echo e( Portale\App::csrf() ); ?>">
			<input type="hidden" name="plan" value="<?php echo e( $plan ); ?>"><input type="hidden" name="period" value="<?php echo e( $period ); ?>">
			<label for="email">La tua email</label>
			<input id="email" type="email" name="email" placeholder="segreteria@miaassociazione.it" autocomplete="email" required>
			<label for="domain">Indirizzo del sito WordPress</label>
			<input id="domain" type="text" name="domain" placeholder="miaassociazione.it" autocapitalize="none" spellcheck="false" required>
			<p class="hint">La licenza sarà legata a questo dominio; potrai cambiarlo in seguito.</p>
			<label>Come vuoi pagare</label>
			<div class="choice">
			<?php $first = true; foreach ( $methods as $m ) : $k = $m[0] . ':' . $m[1]; ?>
				<label><input type="radio" name="method" value="<?php echo e( $k ); ?>"<?php echo $first ? ' checked' : ''; ?>><span><?php echo e( $names[ $k ][0] ); ?><small class="muted" style="display:block;font-weight:400"><?php echo e( $names[ $k ][1] ); ?></small></span></label>
			<?php $first = false; endforeach; ?>
			</div>
			<button class="btn big block" type="submit">Vai al pagamento sicuro</button>
		</form>
		<?php endif; ?>
	</div>
	<div class="side">
		<h2>Cosa succede dopo</h2>
		<ul>
			<li><?php echo icon( 'card' ); ?><div><b>Paghi in modo sicuro</b>Sul sito di Stripe o di PayPal: noi non vediamo i dati della carta.</div></li>
			<li><?php echo icon( 'mail' ); ?><div><b>Ricevi un'email</b>Con il link per entrare nell'area riservata.</div></li>
			<li><?php echo icon( 'key' ); ?><div><b>Trovi la chiave</b>Incolla la chiave nel plugin e le funzioni Pro si attivano.</div></li>
		</ul>
		<p class="muted small"><a href="/#prezzi">Confronta di nuovo i piani</a></p>
	</div>
</div></section>
