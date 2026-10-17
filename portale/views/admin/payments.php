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
?>
<section class="admin"><div class="wrap" style="max-width:820px">
	<p class="muted">Qui inserisci le chiavi per incassare e per emettere le licenze. Vengono salvate <b>cifrate</b> nel database, non si rivedono mai per intero (solo gli ultimi caratteri) e il campo lasciato vuoto non cambia nulla. Dopo il salvataggio usa «Prova il collegamento».</p>

	<form class="card2" method="post" action="/admin/pagamenti">
		<input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>">
		<h2>Stripe</h2>
		<input type="hidden" name="stripe_live_present" value="1">
		<p class="muted small" style="margin-top:0">Dove trovare le credenziali: <a href="<?php echo $s['stripe_live'] ? 'https://dashboard.stripe.com/apikeys' : 'https://dashboard.stripe.com/test/apikeys'; ?>" target="_blank" rel="noopener">chiavi API<?php echo $s['stripe_live'] ? '' : ' (prova)'; ?></a> · <a href="<?php echo $s['stripe_live'] ? 'https://dashboard.stripe.com/webhooks' : 'https://dashboard.stripe.com/test/webhooks'; ?>" target="_blank" rel="noopener">webhook</a> · <a href="https://docs.stripe.com/keys" target="_blank" rel="noopener">istruzioni di Stripe</a>. Per provare senza incassi veri tieni spenta la modalità reale e usa le chiavi «test» (carta di prova 4242 4242 4242 4242).</p>
		<label class="check" style="margin-top:0"><input type="checkbox" name="stripe_live" value="1"<?php echo $s['stripe_live'] ? ' checked' : ''; ?>> Modalità reale (spento = prova)</label>
		<div class="risk">Le chiavi di prova (sk_test_…) e quelle reali (sk_live_…) sono diverse e il segreto del webhook è diverso per ciascuna modalità. Il portale rifiuta una chiave che non corrisponde alla modalità scelta.</div>
		<?php $field( 'stripe_secret', 'Chiave segreta', $s['stripe_live'] ? 'sk_live_…' : 'sk_test_…' ); ?>
		<?php $field( 'stripe_webhook', 'Segreto del webhook', 'whsec_…', 'Si ottiene creando il webhook qui sotto.' ); ?>
		<div class="sep"></div>
		<p class="small" style="margin:0"><b>Webhook da creare su Stripe</b> (Sviluppatori → Webhook → Aggiungi endpoint):</p>
		<div class="keybox" style="margin:8px 0"><code><?php echo e( $base ); ?>/webhook/stripe</code><button class="btn alt small" type="button" data-copy="<?php echo e( $base ); ?>/webhook/stripe">Copia</button></div>
		<p class="hint">Eventi da selezionare: <span class="chip">checkout.session.completed</span> <span class="chip">invoice.paid</span> <span class="chip">invoice.payment_failed</span> <span class="chip">customer.subscription.updated</span> <span class="chip">customer.subscription.deleted</span></p>
		<div class="row-actions"><button class="btn" type="submit">Salva</button></div>
	</form>
	<div style="margin:-8px 0 20px"><?php $test( 'stripe' ); ?></div>

	<form class="card2" method="post" action="/admin/pagamenti">
		<input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>"><input type="hidden" name="paypal_live_present" value="1">
		<h2>PayPal</h2>
		<p class="muted small" style="margin-top:0">Dove trovare le credenziali: <a href="<?php echo $s['paypal_live'] ? 'https://developer.paypal.com/dashboard/applications/live' : 'https://developer.paypal.com/dashboard/applications/sandbox'; ?>" target="_blank" rel="noopener">app <?php echo $s['paypal_live'] ? 'reali' : 'sandbox'; ?></a> (crea un'app e copia Client ID e segreto) · <a href="https://developer.paypal.com/api/rest/" target="_blank" rel="noopener">istruzioni di PayPal</a>. Per il sandbox servono anche gli <a href="https://developer.paypal.com/dashboard/accounts" target="_blank" rel="noopener">account di prova</a> (venditore e acquirente).</p>
		<label class="check" style="margin-top:0"><input type="checkbox" name="paypal_live" value="1"<?php echo $s['paypal_live'] ? ' checked' : ''; ?>> Modalità reale (spento = sandbox di prova)</label>
		<div class="risk">Le credenziali di prova e quelle reali sono diverse: se cambi modalità, inserisci anche le credenziali giuste.</div>
		<?php $field( 'paypal_client', 'Client ID', 'AbC…' ); ?>
		<?php $field( 'paypal_secret', 'Segreto', '…' ); ?>
		<?php $field( 'paypal_webhook', 'ID del webhook', '…', 'Si ottiene creando il webhook qui sotto.' ); ?>
		<div class="sep"></div>
		<p class="small" style="margin:0"><b>Webhook da creare su PayPal</b> (nell'app → Webhooks → Add webhook):</p>
		<div class="keybox" style="margin:8px 0"><code><?php echo e( $base ); ?>/webhook/paypal</code><button class="btn alt small" type="button" data-copy="<?php echo e( $base ); ?>/webhook/paypal">Copia</button></div>
		<p class="hint">Eventi: <span class="chip">BILLING.SUBSCRIPTION.ACTIVATED</span> <span class="chip">BILLING.SUBSCRIPTION.CANCELLED</span> <span class="chip">BILLING.SUBSCRIPTION.EXPIRED</span> <span class="chip">BILLING.SUBSCRIPTION.SUSPENDED</span> <span class="chip">BILLING.SUBSCRIPTION.PAYMENT.FAILED</span> <span class="chip">PAYMENT.SALE.COMPLETED</span> <span class="chip">PAYMENT.CAPTURE.COMPLETED</span></p>
		<div class="row-actions"><button class="btn" type="submit">Salva</button></div>
	</form>
	<div style="margin:-8px 0 20px"><?php $test( 'paypal' ); ?></div>

	<form class="card2" method="post" action="/admin/pagamenti">
		<input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>">
		<h2>Server licenze Wega <?php if ( $s['wega_token']['set'] ) : ?><span class="badge">collegato</span><?php else : ?><span class="badge warn">da collegare</span><?php endif; ?></h2>
		<p class="muted small" style="margin-top:0">Dove trovare le credenziali: <a href="https://license.wegastudios.com/admin/portali" target="_blank" rel="noopener">pannello Wega → Portali</a> (apri il portale e usa «Ruota» per ottenere un nuovo token, si vede una sola volta; il precedente resta valido 24 ore) · <a href="https://license.wegastudios.com/docs/wega-license.pdf" target="_blank" rel="noopener">documentazione API</a>.</p>
		<?php $field( 'wega_token', 'Token (Client ID e segreto)', 'wic_xxxxxxxx.segreto', 'Lo genera l\'amministratore del server licenze e si vede una volta sola. Servono i permessi per creare, leggere e cambiare dominio.' ); ?>
		<div class="row-actions"><button class="btn" type="submit">Salva</button></div>
	</form>
	<div style="margin:-8px 0 20px"><?php $test( 'wega' ); ?></div>

	<p class="muted small">Per il pagamento ricorrente servono anche il prezzo ricorrente su Stripe e il piano su PayPal: li inserisci in <a href="/admin/prezzi">Piani e prezzi</a>. Il pagamento singolo non richiede nulla di più.</p>
</div></section>
