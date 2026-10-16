<?php /** @var array $g @var array $limits @var array $plans @var array $cfg */ include __DIR__ . '/_nav.php';
$names = array(
	'duration_month' => 'Validità a ogni pagamento mensile (giorni)', 'duration_year' => 'Validità a ogni pagamento annuale (giorni)',
	'duration_free' => 'Validità della licenza gratuita (giorni)', 'renew_window' => 'Finestra di rinnovo PayPal (giorni)',
	'reminder_days' => 'Avviso di scadenza, giorni prima (0 = mai)', 'trial_days' => 'Durata della prova Pro (giorni)',
);
?>
<section class="admin"><div class="wrap" style="max-width:820px">
	<form method="post" action="/admin/impostazioni">
		<input type="hidden" name="csrf" value="<?php echo e( Portale\App::csrf() ); ?>">
		<div class="card2">
			<h2>Prova Pro gratuita</h2>
			<p class="muted">Chi ha una licenza gratuita attiva può provare un piano Pro, una sola volta per licenza (e una sola volta per dominio). La prova ha una chiave sua, valida solo per il periodo indicato.</p>
			<label class="check" style="margin-top:0"><input type="checkbox" name="trial_enabled" value="1"<?php echo $g['trial_enabled'] ? ' checked' : ''; ?>> Offri la prova gratuita</label>
			<div class="form-grid two">
				<div><label for="trial_plan">Piano provato</label><select class="field" id="trial_plan" name="trial_plan"><?php foreach ( $plans as $code => $p ) : if ( 'free' === $code ) { continue; } ?><option value="<?php echo e( $code ); ?>"<?php echo $code === $g['trial_plan'] ? ' selected' : ''; ?>><?php echo e( $p['label'] ); ?></option><?php endforeach; ?></select></div>
				<div><label for="trial_days"><?php echo e( $names['trial_days'] ); ?></label><input id="trial_days" type="number" name="trial_days" value="<?php echo (int) $g['trial_days']; ?>" min="<?php echo (int) $limits['trial_days'][1]; ?>" max="<?php echo (int) $limits['trial_days'][2]; ?>"></div>
			</div>
			<div class="risk"><?php echo e( $limits['trial_days'][3] ); ?></div>
		</div>
		<div class="card2">
			<h2>Durate e promemoria</h2>
			<?php foreach ( array( 'duration_month', 'duration_year', 'duration_free', 'renew_window', 'reminder_days' ) as $k ) : ?>
				<label for="<?php echo e( $k ); ?>"><?php echo e( $names[ $k ] ); ?></label>
				<input id="<?php echo e( $k ); ?>" type="number" name="<?php echo e( $k ); ?>" value="<?php echo (int) $g[ $k ]; ?>" min="<?php echo (int) $limits[ $k ][1]; ?>" max="<?php echo (int) $limits[ $k ][2]; ?>">
				<div class="risk"><?php echo e( $limits[ $k ][3] ); ?></div>
			<?php endforeach; ?>
		</div>
		<div class="card2">
			<h2>Sito e posta</h2>
			<label class="check" style="margin-top:0"><input type="checkbox" name="indexable" value="1"<?php echo $g['indexable'] ? ' checked' : ''; ?>> Consenti ai motori di ricerca di indicizzare il sito</label>
			<div class="risk">Spento: robots.txt, intestazione e meta vietano l'indicizzazione (il sito non compare su Google). Accendilo solo al lancio.</div>
			<label for="mail_from">Mittente delle email</label><input id="mail_from" type="email" name="mail_from" value="<?php echo e( $g['mail_from'] ); ?>" placeholder="info@associazionesemplice.it">
			<p class="hint">Deve essere un indirizzo del tuo dominio, altrimenti le email finiscono in spam.</p>
		</div>
		<div class="row-actions"><button class="btn" type="submit">Salva le impostazioni</button></div>
	</form>
	<div class="card2" style="margin-top:26px">
		<h2>Collegamenti (in config.php sul server)</h2>
		<p><span class="status-dot<?php echo $cfg['key'] ? '' : ' off'; ?>"></span>Chiave di cifratura</p>
		<p><span class="status-dot<?php echo $cfg['wega'] ? '' : ' off'; ?>"></span>Server licenze Wega</p>
		<p><span class="status-dot<?php echo $cfg['stripe'] ? '' : ' off'; ?>"></span>Stripe</p>
		<p style="margin:0"><span class="status-dot<?php echo $cfg['paypal'] ? '' : ' off'; ?>"></span>PayPal</p>
		<p class="hint">Le chiavi segrete restano nel file di configurazione sul server e non si modificano da qui.</p>
	</div>
</div></section>
