<?php /** @var array $subs @var string $email */
$status = array( 'active' => 'Attivo', 'past_due' => 'Pagamento non riuscito', 'canceled' => 'Annullato', 'pending' => 'In attesa' );
$csrf   = Portale\App::csrf();
?>
<h1>I tuoi abbonamenti</h1>
<div class="muted"><?php echo e( $email ); ?> · <form method="post" action="/esci" style="display:inline"><input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>"><button class="btn alt" type="submit">Esci</button></form></div>
<?php if ( ! $subs ) : ?><div class="card"><p>Non hai ancora nessuna licenza. <a href="/">Scegli un piano</a>.</p></div><?php endif; ?>
<?php foreach ( $subs as $s ) :
	$key = '';
	if ( ! empty( $s['license_key_enc'] ) ) { try { $key = Portale\Secrets::open( $s['license_key_enc'] ); } catch ( \Throwable $e ) { $key = ''; } }
	$label = Portale\Config::get( 'plans.' . $s['plan_code'] . '.label', $s['plan_code'] );
?>
<div class="card" style="margin-bottom:16px">
	<h2><?php echo e( $label ); ?> <span class="muted">· <?php echo e( $status[ $s['status'] ] ?? $s['status'] ); ?></span></h2>
	<?php if ( $key ) : ?><p>Chiave di licenza:<br><code><?php echo e( $key ); ?></code></p><?php endif; ?>
	<p>Dominio: <strong><?php echo e( $s['domain'] ); ?></strong>
	<?php if ( $s['expires_at'] ) : ?><br>Valida fino al <?php echo e( date( 'd/m/Y', strtotime( $s['expires_at'] . ' UTC' ) ) ); ?><?php echo $s['cancel_at_period_end'] ? ' (non si rinnova)' : ''; ?><?php endif; ?></p>
	<?php if ( $key ) : ?>
	<form method="post" action="/account/dominio">
		<input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>"><input type="hidden" name="id" value="<?php echo e( $s['id'] ); ?>">
		<label for="d<?php echo e( $s['id'] ); ?>">Cambia dominio</label>
		<input id="d<?php echo e( $s['id'] ); ?>" type="text" name="domain" placeholder="nuovodominio.it" required>
		<p class="muted">Il vecchio dominio smette di funzionare al controllo successivo. Massimo 3 cambi ogni 30 giorni.</p>
		<button class="btn alt" type="submit">Cambia dominio</button>
	</form>
	<?php endif; ?>
	<?php if ( 'free' !== $s['provider'] && 'canceled' !== $s['status'] && ! $s['cancel_at_period_end'] ) : ?>
	<form method="post" action="/account/annulla" style="margin-top:12px" onsubmit="return confirm('Disdire il rinnovo automatico?')">
		<input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>"><input type="hidden" name="id" value="<?php echo e( $s['id'] ); ?>">
		<button class="btn alt" type="submit">Disdici il rinnovo</button>
	</form>
	<?php endif; ?>
	<?php if ( 'stripe' === $s['provider'] && $s['provider_customer'] ) : ?>
	<form method="post" action="/account/fatturazione" style="margin-top:12px">
		<input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>"><input type="hidden" name="id" value="<?php echo e( $s['id'] ); ?>">
		<button class="btn alt" type="submit">Carta e fatture</button>
	</form>
	<?php endif; ?>
</div>
<?php endforeach; ?>
