<?php /** @var string $plan @var string $period @var array $price @var string $label */
$methods = array();
if ( ! empty( $price['stripe_price'] ) ) { $methods['stripe'] = 'Carta (Stripe)'; }
if ( ! empty( $price['paypal_plan'] ) ) { $methods['paypal'] = 'PayPal'; }
?>
<h1><?php echo e( $label ); ?> · <?php echo 'year' === $period ? 'annuale' : 'mensile'; ?></h1>
<div class="card">
<?php if ( ! $methods ) : ?>
	<p>Questo piano non è ancora acquistabile.</p>
<?php else : ?>
<form method="post" action="/acquista">
	<input type="hidden" name="csrf" value="<?php echo e( Portale\App::csrf() ); ?>">
	<input type="hidden" name="plan" value="<?php echo e( $plan ); ?>"><input type="hidden" name="period" value="<?php echo e( $period ); ?>">
	<label for="email">Email</label><input id="email" type="email" name="email" required>
	<label for="domain">Dominio del sito</label><input id="domain" type="text" name="domain" placeholder="miaassociazione.it" required>
	<label>Pagamento</label>
	<?php $first = true; foreach ( $methods as $k => $t ) : ?>
		<div><label style="font-weight:400"><input type="radio" name="provider" value="<?php echo e( $k ); ?>" <?php echo $first ? 'checked' : ''; ?>> <?php echo e( $t ); ?></label></div>
	<?php $first = false; endforeach; ?>
	<p class="muted">Rinnovo automatico, disdici quando vuoi dall'area riservata.</p>
	<button class="btn" type="submit">Vai al pagamento</button>
</form>
<?php endif; ?>
</div>
