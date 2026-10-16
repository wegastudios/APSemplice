<?php /** @var array $subs @var string $email */
$status = array(
	'active'   => array( 'Attivo', '' ), 'past_due' => array( 'Pagamento non riuscito', 'bad' ),
	'canceled' => array( 'Annullato', 'warn' ), 'pending' => array( 'In attesa', 'warn' ),
);
$csrf = Portale\App::csrf();
?>
<section class="page"><div class="wrap" style="max-width:860px">
	<div class="account-head">
		<div><h1>Le tue licenze</h1><div class="muted"><?php echo e( $email ); ?></div></div>
		<form method="post" action="/esci"><input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>"><button class="btn alt small" type="submit">Esci</button></form>
	</div>
	<?php if ( ! $subs ) : ?>
		<div class="sub empty"><div class="ico" style="margin:0 auto 16px"><?php echo icon( 'key' ); ?></div><h2>Nessuna licenza, per ora</h2><p class="muted">Parti dal piano gratuito oppure scegli un piano Pro.</p>
		<a class="btn" href="/free">Licenza gratuita</a> <a class="btn alt" href="/#prezzi">Vedi i piani</a></div>
	<?php endif; ?>
	<?php foreach ( $subs as $s ) :
		$key = '';
		if ( ! empty( $s['license_key_enc'] ) ) { try { $key = Portale\Secrets::open( $s['license_key_enc'] ); } catch ( \Throwable $e ) { $key = ''; } }
		$label = Portale\Config::get( 'plans.' . $s['plan_code'] . '.label', $s['plan_code'] );
		$st    = $status[ $s['status'] ] ?? array( $s['status'], '' );
		$exp   = $s['expires_at'] ? strtotime( $s['expires_at'] . ' UTC' ) : 0;
		$days  = $exp ? (int) ceil( ( $exp - time() ) / 86400 ) : null;
		$when  = 'free' === $s['provider'] ? 'Gratuita' : ( 'year' === $s['period'] ? 'Annuale' : 'Mensile' );
	?>
	<div class="sub">
		<div class="sub-top"><h2><?php echo e( $label ); ?></h2><span class="badge <?php echo e( $st[1] ); ?>"><?php echo e( $st[0] ); ?></span><span class="muted small"><?php echo e( $when ); ?></span></div>
		<?php if ( $key ) : ?>
			<label style="margin-top:0">Chiave di licenza</label>
			<div class="keybox"><code><?php echo e( $key ); ?></code><button class="btn alt small" type="button" data-copy="<?php echo e( $key ); ?>">Copia</button></div>
			<p class="hint">Incollala in Associazione Semplice → Impostazioni → Licenza, nel tuo sito WordPress.</p>
		<?php endif; ?>
		<div class="facts">
			<div><span>Dominio</span><b><?php echo e( $s['domain'] ); ?></b></div>
			<?php if ( $exp ) : ?><div><span><?php echo $s['cancel_at_period_end'] ? 'Termina il' : 'Valida fino al'; ?></span><b><?php echo e( date( 'd/m/Y', $exp ) ); ?></b><?php if ( null !== $days && $days >= 0 && $days <= 30 ) : ?> <span class="muted">(tra <?php echo (int) $days; ?> giorni)</span><?php endif; ?></div><?php endif; ?>
			<?php if ( $s['cancel_at_period_end'] ) : ?><div><span>Rinnovo</span><b>Disdetto</b></div><?php elseif ( 'free' !== $s['provider'] && 'active' === $s['status'] ) : ?><div><span>Rinnovo</span><b>Automatico</b></div><?php endif; ?>
		</div>
		<div class="actions">
			<?php if ( $key ) : ?>
			<form class="domain-form" method="post" action="/account/dominio">
				<input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>"><input type="hidden" name="id" value="<?php echo e( $s['id'] ); ?>">
				<div><label for="d<?php echo e( $s['id'] ); ?>">Cambia dominio</label>
				<input id="d<?php echo e( $s['id'] ); ?>" type="text" name="domain" placeholder="nuovodominio.it" autocapitalize="none" required></div>
				<button class="btn alt" type="submit">Aggiorna</button>
			</form>
			<?php endif; ?>
			<?php if ( 'stripe' === $s['provider'] && $s['provider_customer'] ) : ?>
			<form method="post" action="/account/fatturazione"><input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>"><input type="hidden" name="id" value="<?php echo e( $s['id'] ); ?>"><button class="btn alt" type="submit">Carta e fatture</button></form>
			<?php endif; ?>
			<?php if ( 'free' !== $s['provider'] && 'canceled' !== $s['status'] && ! $s['cancel_at_period_end'] ) : ?>
			<form method="post" action="/account/annulla" onsubmit="return confirm('Disdire il rinnovo automatico? La licenza resta valida fino alla scadenza.')"><input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>"><input type="hidden" name="id" value="<?php echo e( $s['id'] ); ?>"><button class="btn danger" type="submit">Disdici il rinnovo</button></form>
			<?php endif; ?>
		</div>
		<p class="hint">Il vecchio dominio smette di funzionare al controllo successivo. Puoi cambiarlo fino a 3 volte ogni 30 giorni.</p>
	</div>
	<?php endforeach; ?>
</div></section>
