<?php /** @var string $plan @var string $period @var array $price @var string $label */
$methods = array();
if ( ! empty( $price['stripe_price'] ) ) { $methods['stripe'] = 'Carta di credito o debito'; }
if ( ! empty( $price['paypal_plan'] ) ) { $methods['paypal'] = 'PayPal'; }
$cents  = (int) $price['cents'];
$amount = number_format( $cents / 100, ( $cents % 100 ) ? 2 : 0, ',', '.' ) . ' €';
$year   = 'year' === $period;
?>
<section class="page"><div class="wrap page-grid">
	<div class="panel">
		<span class="eyebrow">Piano <?php echo e( $label ); ?></span>
		<h1>Completa l'abbonamento</h1>
		<div class="summary"><span><?php echo e( $label ); ?> · <?php echo $year ? 'annuale' : 'mensile'; ?></span><b><?php echo e( $amount ); ?> <small class="muted"><?php echo $year ? '/ anno' : '/ mese'; ?></small></b></div>
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
			<?php $first = true; foreach ( $methods as $k => $t ) : ?>
				<label><input type="radio" name="provider" value="<?php echo e( $k ); ?>"<?php echo $first ? ' checked' : ''; ?>> <?php echo e( $t ); ?></label>
			<?php $first = false; endforeach; ?>
			</div>
			<button class="btn big block" type="submit">Vai al pagamento sicuro</button>
			<p class="hint">Rinnovo automatico: disdici quando vuoi dall'area riservata. La licenza resta valida fino alla scadenza già pagata.</p>
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
