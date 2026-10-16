<?php /** @var array $plans */ include __DIR__ . '/_nav.php';
$csrf = Portale\App::csrf();
$eur  = function ( $c ) { return null === $c ? '' : number_format( $c / 100, 2, ',', '' ); };
?>
<section class="admin"><div class="wrap" style="max-width:900px">
	<p class="muted">I prezzi mostrati sul sito e i dati per incassare. Il <b>pagamento singolo</b> usa l'importo qui scritto e non richiede nulla su Stripe o PayPal; il <b>rinnovo automatico</b> richiede invece il prezzo ricorrente creato su Stripe (<code>price_…</code>) e/o il piano creato su PayPal (<code>P-…</code>), che devono avere lo stesso importo.</p>
	<?php foreach ( $plans as $code => $p ) :
		$paid = 'free' !== $code;
		$feat = implode( "\n", (array) ( $p['features'] ?? array() ) );
	?>
	<form class="card2 plan-edit" id="<?php echo e( $code ); ?>" method="post" action="/admin/prezzi/<?php echo e( $code ); ?>">
		<input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>">
		<h2><?php echo e( $p['label'] ); ?> <span class="muted small">codice «<?php echo e( $code ); ?>»</span></h2>
		<div class="form-grid two">
			<div><label for="l<?php echo e( $code ); ?>" style="margin-top:0">Nome sul sito</label><input id="l<?php echo e( $code ); ?>" type="text" name="label" value="<?php echo e( $p['label'] ); ?>" maxlength="40"></div>
			<div><label for="n<?php echo e( $code ); ?>" style="margin-top:0">Nome inviato al server licenze</label><input id="n<?php echo e( $code ); ?>" type="text" name="plan_name" value="<?php echo e( $p['plan_name'] ); ?>" maxlength="40"><p class="hint">Vale per le nuove licenze; quelle già emesse non cambiano.</p></div>
			<div><label for="t<?php echo e( $code ); ?>">Etichetta in evidenza</label><input id="t<?php echo e( $code ); ?>" type="text" name="tag" value="<?php echo e( $p['tag'] ?? '' ); ?>" maxlength="30" placeholder="Per esempio: Il più completo"></div>
			<div><label class="check" style="margin-top:44px"><input type="checkbox" name="visible" value="1"<?php echo false !== ( $p['visible'] ?? true ) ? ' checked' : ''; ?>> Mostra questo piano sul sito</label></div>
		</div>
		<label for="g<?php echo e( $code ); ?>">Descrizione breve</label><input id="g<?php echo e( $code ); ?>" type="text" name="tagline" value="<?php echo e( $p['tagline'] ?? '' ); ?>" maxlength="200" placeholder="Se vuoto si usa il testo predefinito">
		<label for="f<?php echo e( $code ); ?>">Funzioni elencate (una per riga)</label><textarea id="f<?php echo e( $code ); ?>" name="features" rows="6" placeholder="Se vuoto si usa l'elenco predefinito"><?php echo e( $feat ); ?></textarea>
		<?php if ( $paid ) : foreach ( array( 'month' => 'Mensile', 'year' => 'Annuale' ) as $per => $txt ) : $pr = $p['prices'][ $per ] ?? null; ?>
			<div class="sep"></div>
			<h3 style="margin-bottom:4px"><?php echo e( $txt ); ?></h3><p class="hint" style="margin:0 0 6px">Lascia vuoto il prezzo per non vendere questo periodo.</p>
			<div class="form-grid">
				<div><label for="p<?php echo e( $code . $per ); ?>">Prezzo in euro</label><input id="p<?php echo e( $code . $per ); ?>" type="text" inputmode="decimal" name="price_<?php echo e( $per ); ?>" value="<?php echo e( $eur( $pr['cents'] ?? null ) ); ?>" placeholder="49,90"></div>
				<div><label for="s<?php echo e( $code . $per ); ?>">Stripe · prezzo ricorrente</label><input id="s<?php echo e( $code . $per ); ?>" type="text" name="stripe_<?php echo e( $per ); ?>" value="<?php echo e( $pr['stripe_price'] ?? '' ); ?>" placeholder="price_…" autocapitalize="none" spellcheck="false"></div>
				<div><label for="a<?php echo e( $code . $per ); ?>">PayPal · piano ricorrente</label><input id="a<?php echo e( $code . $per ); ?>" type="text" name="paypal_<?php echo e( $per ); ?>" value="<?php echo e( $pr['paypal_plan'] ?? '' ); ?>" placeholder="P-…" autocapitalize="none" spellcheck="false"></div>
			</div>
			<label class="check"><input type="checkbox" name="once_<?php echo e( $per ); ?>" value="1"<?php echo false !== ( $pr['once'] ?? true ) ? ' checked' : ''; ?>> Consenti anche il pagamento singolo (senza rinnovo automatico)</label>
		<?php endforeach; else : ?>
			<p class="hint">Il piano gratuito non ha prezzo.</p>
		<?php endif; ?>
		<div class="row-actions"><button class="btn" type="submit">Salva <?php echo e( $p['label'] ); ?></button></div>
	</form>
	<?php endforeach; ?>
</div></section>
