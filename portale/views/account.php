<?php /** @var array $subs @var string $email @var array $trial @var int $trial_days @var string $trial_label @var Portale\Billing $billing @var bool $is_admin @var array $releases */
$csrf = Portale\App::csrf();
?>
<section class="page"><div class="wrap" style="max-width:860px">
	<div class="account-head">
		<div><h1>Le tue licenze</h1><div class="muted"><?php echo e( $email ); ?></div></div>
		<div style="display:flex;gap:10px;flex-wrap:wrap">
			<?php if ( $is_admin ) : ?><a class="btn small" href="/admin">Gestione</a><?php endif; ?>
			<form method="post" action="/esci"><input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>"><button class="btn alt small" type="submit">Esci</button></form>
		</div>
	</div>
	<?php if ( ! $subs ) : ?>
		<div class="sub empty"><div class="ico" style="margin:0 auto 16px"><?php echo icon( 'key' ); ?></div><h2>Nessuna licenza, per ora</h2><p class="muted">Parti dal piano gratuito oppure scegli un piano Pro.</p>
		<a class="btn" href="/free">Licenza gratuita</a> <a class="btn alt" href="/#prezzi">Vedi i piani</a></div>
	<?php endif; ?>
	<?php if ( ! empty( $releases ) ) : ?>
	<div class="sub">
		<div class="sub-top"><h2>Scarica il plugin</h2></div>
		<?php foreach ( $releases as $prod => $list ) : if ( ! $list ) { continue; } ?>
			<p style="margin:12px 0 6px"><b><?php echo e( Portale\Releases::PRODUCTS[ $prod ][1] ); ?></b> <span class="muted small">versione <?php echo e( $list[0]['version'] ); ?> · <?php echo e( date_it( $list[0]['created_at'] ) ); ?></span></p>
			<a class="btn" href="/scarica/<?php echo e( $prod ); ?>/<?php echo e( $list[0]['version'] ); ?>">Scarica la <?php echo e( $list[0]['version'] ); ?></a>
			<?php if ( count( $list ) > 1 ) : ?>
			<details style="margin-top:10px"><summary class="muted small">Versioni precedenti, per tornare indietro</summary>
				<ul class="muted small" style="margin:8px 0 0;padding-left:18px"><?php foreach ( array_slice( $list, 1 ) as $r ) : ?><li><a href="/scarica/<?php echo e( $prod ); ?>/<?php echo e( $r['version'] ); ?>"><?php echo e( $r['version'] ); ?></a> · <?php echo e( date_it( $r['created_at'] ) ); ?><?php echo $r['note'] ? ' · ' . e( $r['note'] ) : ''; ?></li><?php endforeach; ?></ul>
			</details>
			<?php endif; ?>
		<?php endforeach; ?>
		<p class="hint">I pacchetti si scaricano solo da qui, con la licenza in regola. Carica lo zip da WordPress: Plugin → Aggiungi nuovo → Carica plugin. Per tornare indietro disattiva e sostituisci il plugin con la versione precedente (i dati restano).</p>
	</div>
	<?php endif; ?>
	<?php foreach ( $subs as $s ) :
		$key = '';
		if ( ! empty( $s['license_key_enc'] ) ) { try { $key = Portale\Secrets::open( $s['license_key_enc'] ); } catch ( \Throwable $e ) { $key = ''; } }
		$label = Portale\Config::get( 'plans.' . $s['plan_code'] . '.label', $s['plan_code'] );
		$st    = sub_status( $s );
		$exp   = $s['expires_at'] ? strtotime( $s['expires_at'] . ' UTC' ) : 0;
		$days  = $exp ? (int) ceil( ( $exp - time() ) / 86400 ) : null;
		$rec   = $billing->isRecurring( $s );
		$over  = $exp && $exp < time();
	?>
	<div class="sub">
		<div class="sub-top"><h2><?php echo e( $label ); ?></h2><span class="badge <?php echo e( $st[1] ); ?>"><?php echo e( $st[0] ); ?></span><span class="muted small"><?php echo e( sub_kind( $s ) ); ?></span></div>
		<?php if ( $key ) : ?>
			<label style="margin-top:0">Chiave di licenza</label>
			<div class="keybox"><code><?php echo e( $key ); ?></code><button class="btn alt small" type="button" data-copy="<?php echo e( $key ); ?>">Copia</button></div>
			<p class="hint">Incollala in Associazione Semplice → Impostazioni → Licenza, nel tuo sito WordPress.<?php echo 'trial' === $s['provider'] ? ' Questa è la chiave di prova: alla fine della prova rimetti quella gratuita.' : ''; ?></p>
		<?php endif; ?>
		<div class="facts">
			<div><span>Dominio</span><b><?php echo e( $s['domain'] ); ?></b></div>
			<?php if ( $exp ) : ?><div><span><?php echo $over ? 'Scaduta il' : ( $rec && ! $s['cancel_at_period_end'] ? 'Prossimo rinnovo' : 'Valida fino al' ); ?></span><b><?php echo e( date( 'd/m/Y', $exp ) ); ?></b><?php if ( null !== $days && $days >= 0 && $days <= 30 ) : ?> <span class="muted">(tra <?php echo (int) $days; ?> giorni)</span><?php endif; ?></div><?php endif; ?>
			<?php if ( $s['cancel_at_period_end'] ) : ?><div><span>Rinnovo</span><b>Disdetto</b></div><?php elseif ( $rec && 'active' === $s['status'] ) : ?><div><span>Rinnovo</span><b>Automatico</b></div><?php elseif ( in_array( $s['provider'], array( 'stripe', 'paypal' ), true ) ) : ?><div><span>Rinnovo</span><b>Manuale</b></div><?php endif; ?>
		</div>

		<?php if ( ! empty( $trial[ $s['id'] ] ) ) : ?>
		<div class="trial-box">
			<p><b>Prova <?php echo e( $trial_label ); ?> gratis per <?php echo (int) $trial_days; ?> giorni</b><br><span class="muted small">Una sola volta per licenza. Riceverai una chiave di prova da incollare nel plugin; alla fine rimetti quella gratuita o scegli un piano.</span></p>
			<form method="post" action="/account/prova"><input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>"><input type="hidden" name="id" value="<?php echo e( $s['id'] ); ?>"><button class="btn" type="submit">Attiva la prova</button></form>
		</div>
		<?php endif; ?>

		<?php if ( in_array( $s['provider'], array( 'stripe', 'paypal' ), true ) && ! $rec && $key ) : ?>
		<div class="trial-box">
			<p><b>Rinnova <?php echo 'year' === $s['period'] ? 'per un altro anno' : 'per un altro mese'; ?></b><br><span class="muted small">Pagamento singolo: la chiave resta la stessa, la scadenza si sposta in avanti.</span></p>
			<form method="post" action="/account/rinnova" style="display:flex;gap:8px;flex-wrap:wrap"><input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>"><input type="hidden" name="id" value="<?php echo e( $s['id'] ); ?>">
				<button class="btn" type="submit" name="provider" value="stripe">Paga con carta</button><button class="btn alt" type="submit" name="provider" value="paypal">Paga con PayPal</button></form>
		</div>
		<?php endif; ?>

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
			<?php if ( $rec && 'canceled' !== $s['status'] && ! $s['cancel_at_period_end'] ) : ?>
			<form method="post" action="/account/annulla" onsubmit="return confirm('Disdire il rinnovo automatico? La licenza resta valida fino alla scadenza.')"><input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>"><input type="hidden" name="id" value="<?php echo e( $s['id'] ); ?>"><button class="btn danger" type="submit">Disdici il rinnovo</button></form>
			<?php endif; ?>
		</div>
		<p class="hint">Il vecchio dominio smette di funzionare al controllo successivo. Puoi cambiarlo fino a 3 volte ogni 30 giorni.</p>
	</div>
	<?php endforeach; ?>
</div></section>
