<?php /** @var array $s @var string $base */ include __DIR__ . '/_nav.php';
$csrf = Portale\App::csrf();
$field = function ( string $name, string $label, string $placeholder, string $hint = '' ) use ( $s ) {
	$st = $s[ $name ];
	?>
	<label for="<?php echo e( $name ); ?>"><?php echo e( $label ); ?></label>
	<input id="<?php echo e( $name ); ?>" type="password" name="<?php echo e( $name ); ?>" autocomplete="new-password" autocapitalize="none" spellcheck="false" placeholder="<?php echo e( $st['set'] ? 'Impostata (' . $st['mask'] . ') · lascia vuoto per non cambiarla' : $placeholder ); ?>">
	<?php if ( $st['set'] ) : ?>
		<p class="hint"><span class="status-dot"></span>In uso, inserita da <?php echo e( 'pannello' === $st['source'] ? 'questo pannello' : 'config.php' ); ?>.
		<?php if ( 'pannello' === $st['source'] ) : ?><label class="check" style="display:inline-flex;margin:0 0 0 10px;font-weight:400"><input type="checkbox" name="remove_<?php echo e( $name ); ?>" value="1"> Rimuovi</label><?php endif; ?></p>
	<?php elseif ( '' !== $hint ) : ?><p class="hint"><?php echo e( $hint ); ?></p><?php endif;
};
$test = function ( string $which ) use ( $csrf ) { ?>
	<form method="post" action="/admin/pagamenti/prova/<?php echo e( $which ); ?>" style="display:inline"><input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>"><button class="btn alt" type="submit">Prova il collegamento</button></form>
<?php };
$group = function ( bool $live, bool $active, callable $inner ) {
	?>
	<div class="sep"></div>
	<h3 style="margin:0 0 4px"><?php echo $live ? 'Credenziali reali' : 'Credenziali di prova'; ?> <?php echo $active ? '<span class="badge">in uso</span>' : '<span class="badge warn">non in uso</span>'; ?></h3>
	<?php $inner();
};
?>
<section class="admin"><div class="wrap" style="max-width:820px">
	<p class="muted">Qui inserisci le chiavi per incassare e per emettere le licenze. Vengono salvate <b>cifrate</b> nel database, non si rivedono mai per intero (solo gli ultimi caratteri) e il campo lasciato vuoto non cambia nulla. Dopo il salvataggio usa «Prova il collegamento».</p>

	<form class="card2" method="post" action="/admin/pagamenti">
		<input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>"><input type="hidden" name="stripe_live_present" value="1">
		<h2>Stripe</h2>
		<p class="muted small" style="margin-top:0">Dove trovare le credenziali: <a href="https://dashboard.stripe.com/apikeys" target="_blank" rel="noopener">chiavi API reali</a> · <a href="https://dashboard.stripe.com/test/apikeys" target="_blank" rel="noopener">chiavi API di prova</a> · <a href="https://dashboard.stripe.com/webhooks" target="_blank" rel="noopener">webhook reali</a> · <a href="https://dashboard.stripe.com/test/webhooks" target="_blank" rel="noopener">webhook di prova</a> · <a href="https://docs.stripe.com/keys" target="_blank" rel="noopener">istruzioni di Stripe</a>. Per provare senza incassi veri usa le credenziali di prova (carta 4242 4242 4242 4242).</p>
		<label class="check" style="margin-top:0"><input type="checkbox" name="stripe_live" value="1"<?php echo $s['stripe_live'] ? ' checked' : ''; ?>> Modalità reale (spento = prova)</label>
		<div class="risk">Si passa da una modalità all'altra con questa casella: ognuna ha le sue credenziali, salvate qui sotto. Il portale non incassa mai con credenziali di una modalità diversa da quella scelta.</div>
		<?php $group( true, (bool) $s['stripe_live'], function () use ( $field ) {
			$field( 'stripe_secret', 'Chiave segreta', 'sk_live_…' );
			$field( 'stripe_webhook', 'Segreto del webhook', 'whsec_…', 'Si ottiene creando il webhook qui sotto (sul Dashboard reale).' );
		} ); ?>
		<?php $group( false, ! $s['stripe_live'], function () use ( $field ) {
			$field( 'stripe_secret_test', 'Chiave segreta', 'sk_test_…' );
			$field( 'stripe_webhook_test', 'Segreto del webhook', 'whsec_…', 'Si ottiene creando il webhook qui sotto (sul Dashboard di prova).' );
		} ); ?>
		<div class="sep"></div>
		<p class="small" style="margin:0"><b>Webhook da creare su Stripe</b> (Sviluppatori → Webhook → Aggiungi endpoint, una volta per modalità):</p>
		<div class="keybox" style="margin:8px 0"><code><?php echo e( $base ); ?>/webhook/stripe</code><button class="btn alt small" type="button" data-copy="<?php echo e( $base ); ?>/webhook/stripe">Copia</button></div>
		<p class="hint">Eventi da selezionare: <span class="chip">checkout.session.completed</span> <span class="chip">invoice.paid</span> <span class="chip">invoice.payment_failed</span> <span class="chip">customer.subscription.updated</span> <span class="chip">customer.subscription.deleted</span></p>
		<div class="row-actions"><button class="btn" type="submit">Salva</button></div>
	</form>
	<div style="margin:-8px 0 20px"><?php $test( 'stripe' ); ?></div>

	<form class="card2" method="post" action="/admin/pagamenti">
		<input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>"><input type="hidden" name="paypal_live_present" value="1">
		<h2>PayPal</h2>
		<p class="muted small" style="margin-top:0">Dove trovare le credenziali: <a href="https://developer.paypal.com/dashboard/applications/live" target="_blank" rel="noopener">app reali</a> · <a href="https://developer.paypal.com/dashboard/applications/sandbox" target="_blank" rel="noopener">app sandbox</a> (crea un'app e copia Client ID e segreto) · <a href="https://developer.paypal.com/api/rest/" target="_blank" rel="noopener">istruzioni di PayPal</a>. Per il sandbox servono anche gli <a href="https://developer.paypal.com/dashboard/accounts" target="_blank" rel="noopener">account di prova</a> (venditore e acquirente).</p>
		<label class="check" style="margin-top:0"><input type="checkbox" name="paypal_live" value="1"<?php echo $s['paypal_live'] ? ' checked' : ''; ?>> Modalità reale (spento = sandbox di prova)</label>
		<div class="risk">Si passa da una modalità all'altra con questa casella: ognuna ha le sue credenziali, salvate qui sotto.</div>
		<?php $group( true, (bool) $s['paypal_live'], function () use ( $field ) {
			$field( 'paypal_client', 'Client ID', 'AbC…' );
			$field( 'paypal_secret', 'Segreto', '…' );
			$field( 'paypal_webhook', 'ID del webhook', '…', 'Si ottiene creando il webhook qui sotto.' );
		} ); ?>
		<?php $group( false, ! $s['paypal_live'], function () use ( $field ) {
			$field( 'paypal_client_test', 'Client ID (sandbox)', 'AbC…' );
			$field( 'paypal_secret_test', 'Segreto (sandbox)', '…' );
			$field( 'paypal_webhook_test', 'ID del webhook (sandbox)', '…', 'Si ottiene creando il webhook qui sotto, nell\'app sandbox.' );
		} ); ?>
		<div class="sep"></div>
		<p class="small" style="margin:0"><b>Webhook da creare su PayPal</b> (nell'app → Webhooks → Add webhook, una volta per modalità):</p>
		<div class="keybox" style="margin:8px 0"><code><?php echo e( $base ); ?>/webhook/paypal</code><button class="btn alt small" type="button" data-copy="<?php echo e( $base ); ?>/webhook/paypal">Copia</button></div>
		<p class="hint">Eventi: <span class="chip">BILLING.SUBSCRIPTION.ACTIVATED</span> <span class="chip">BILLING.SUBSCRIPTION.CANCELLED</span> <span class="chip">BILLING.SUBSCRIPTION.EXPIRED</span> <span class="chip">BILLING.SUBSCRIPTION.SUSPENDED</span> <span class="chip">BILLING.SUBSCRIPTION.PAYMENT.FAILED</span> <span class="chip">PAYMENT.SALE.COMPLETED</span> <span class="chip">PAYMENT.CAPTURE.COMPLETED</span></p>
		<div class="row-actions"><button class="btn" type="submit">Salva</button></div>
	</form>
	<div style="margin:-8px 0 20px"><?php $test( 'paypal' ); ?></div>

	<form class="card2" method="post" action="/admin/pagamenti">
		<input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>">
		<h2>Server licenze Wega <?php if ( $s['wega_token']['set'] ) : ?><span class="badge">collegato</span><?php else : ?><span class="badge warn">da collegare</span><?php endif; ?></h2>
		<p class="muted small" style="margin-top:0">Dove trovare le credenziali: <a href="https://license.wegastudios.com/admin/portali" target="_blank" rel="noopener">pannello Wega → Portali</a> (apri il portale e usa «Ruota» per ottenere un nuovo token, si vede una sola volta; il precedente resta valido 24 ore) · <a href="https://license.wegastudios.com/docs/wega-license.pdf" target="_blank" rel="noopener">documentazione API</a>.</p>
		<?php $field( 'wega_token', 'Token (Client ID e segreto)', 'wic_xxxxxxxx.segreto', 'Permessi necessari: licenses:create, validate, read, list, manage, reveal-key e change-binding, e il tipo di licenza 3 abilitato.' ); ?>
		<div class="row-actions"><button class="btn" type="submit">Salva</button></div>
	</form>
	<div style="margin:-8px 0 20px"><?php $test( 'wega' ); ?></div>

	<p class="muted small">Per il pagamento ricorrente servono anche il prezzo ricorrente su Stripe e il piano su PayPal: li inserisci in <a href="/admin/prezzi">Piani e prezzi</a>. Il pagamento singolo non richiede nulla di più.</p>
</div></section>
