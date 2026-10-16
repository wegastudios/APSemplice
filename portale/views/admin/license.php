<?php /** @var array $sub @var string $key @var array $customer @var ?array $remote @var Portale\Billing $billing */
include __DIR__ . '/_nav.php';
$st    = sub_status( $sub );
$csrf  = Portale\App::csrf();
$label = Portale\Config::get( 'plans.' . $sub['plan_code'] . '.label', $sub['plan_code'] );
$id    = e( $sub['id'] );
?>
<section class="admin"><div class="wrap" style="max-width:900px">
	<p><a href="/admin/licenze">← Tutte le licenze</a></p>
	<div class="card2">
		<div class="sub-top"><h2 style="margin:0"><?php echo e( $sub['domain'] ); ?></h2><span class="badge <?php echo e( $st[1] ); ?>"><?php echo e( $st[0] ); ?></span><span class="muted small"><?php echo e( sub_kind( $sub ) ); ?></span></div>
		<?php if ( $key ) : ?>
			<label style="margin-top:0">Chiave di licenza</label>
			<div class="keybox"><code><?php echo e( $key ); ?></code><button class="btn alt small" type="button" data-copy="<?php echo e( $key ); ?>">Copia</button></div>
		<?php endif; ?>
		<div class="sep"></div>
		<dl class="dl">
			<dt>Cliente</dt><dd><a href="/admin/licenze?q=<?php echo urlencode( $customer['email'] ); ?>"><?php echo e( $customer['email'] ); ?></a></dd>
			<dt>Piano</dt><dd><?php echo e( $label ); ?></dd>
			<dt>Scadenza</dt><dd><?php echo e( date_it( $sub['expires_at'] ) ); ?><?php echo $sub['cancel_at_period_end'] ? ' (rinnovo disdetto)' : ''; ?></dd>
			<dt>ID licenza (Wega)</dt><dd><?php echo e( $sub['license_id'] ?: '—' ); ?></dd>
			<dt>Riferimento <?php echo e( $sub['provider'] ); ?></dt><dd><?php echo e( $sub['provider_ref'] ?: '—' ); ?></dd>
			<dt>Creata il</dt><dd><?php echo e( date_it( $sub['created_at'] ) ); ?></dd>
			<?php if ( ! empty( $sub['trial_used_at'] ) ) : ?><dt>Prova usata il</dt><dd><?php echo e( date_it( $sub['trial_used_at'] ) ); ?></dd><?php endif; ?>
			<?php if ( ! empty( $sub['note'] ) ) : ?><dt>Nota</dt><dd><?php echo e( $sub['note'] ); ?></dd><?php endif; ?>
		</dl>
		<?php if ( $key ) : ?><div class="row-actions"><a class="btn alt small" href="?verifica=1">Controlla sul server licenze</a></div><?php endif; ?>
		<?php if ( $remote ) : ?>
			<div class="sep"></div><h3>Risposta del server licenze</h3>
			<?php if ( isset( $remote['error'] ) ) : ?><p class="muted"><?php echo e( $remote['error'] ); ?></p>
			<?php else : $chk = $remote['check']; ?>
				<p><span class="badge <?php echo ! empty( $chk['valid'] ) ? '' : 'bad'; ?>"><?php echo ! empty( $chk['valid'] ) ? 'Valida' : 'Non valida'; ?></span> <span class="muted"><?php echo e( $chk['reason_code'] ?? '' ); ?></span></p>
				<div class="pre"><?php echo e( json_encode( array( 'info' => $remote['info'], 'validate' => array_diff_key( $chk, array( 'attestation' => 1 ) ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></div>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<div class="card2">
		<h2>Azioni</h2>
		<?php if ( $key ) : ?>
		<form class="form-grid" method="post" action="/admin/licenze/<?php echo $id; ?>/dominio">
			<input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>">
			<div><label for="dom" style="margin-top:0">Cambia dominio</label><input id="dom" type="text" name="domain" placeholder="nuovodominio.it" autocapitalize="none" required></div>
			<div style="align-self:end"><button class="btn alt" type="submit">Aggiorna dominio</button></div>
		</form>
		<div class="sep"></div>
		<form method="post" action="/admin/licenze/<?php echo $id; ?>/rinnova" onsubmit="return confirm('Aggiungere un periodo alla licenza?')">
			<input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>">
			<p class="muted" style="margin:0 0 10px">Aggiunge alla scadenza un periodo (la durata della licenza), senza incasso: per regali, rimborsi o accordi fuori dal portale.</p>
			<label class="check" style="margin-top:0"><input type="checkbox" name="notify" value="1"> Avvisa il cliente con l'email «Rinnovo ricevuto»</label>
			<div class="row-actions"><button class="btn alt" type="submit">Rinnova di un periodo</button></div>
		</form>
		<div class="sep"></div>
		<?php endif; ?>
		<div class="row-actions" style="margin-top:0">
			<?php if ( $billing->isRecurring( $sub ) && ! $sub['cancel_at_period_end'] && 'canceled' !== $sub['status'] ) : ?>
			<form method="post" action="/admin/licenze/<?php echo $id; ?>/disdici" onsubmit="return confirm('Disdire il rinnovo automatico presso il provider?')"><input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>"><button class="btn danger" type="submit">Disdici il rinnovo</button></form>
			<?php endif; ?>
			<form method="post" action="/admin/licenze/<?php echo $id; ?>/stato" onsubmit="return confirm('Cambiare lo stato nel portale? Non tocca il server licenze né il provider.')">
				<input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>"><input type="hidden" name="status" value="<?php echo 'canceled' === $sub['status'] ? 'active' : 'canceled'; ?>">
				<button class="btn alt" type="submit"><?php echo 'canceled' === $sub['status'] ? 'Segna come attiva' : 'Segna come annullata'; ?></button>
			</form>
			<form method="post" action="/admin/licenze/<?php echo $id; ?>/elimina" onsubmit="return confirm('Eliminare questa licenza dal portale? Il dominio torna libero. Il server licenze e il provider non vengono toccati. Non si può annullare.')"><input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>"><button class="btn danger" type="submit">Elimina dal portale</button></form>
		</div>
		<div class="sep"></div>
		<form method="post" action="/admin/licenze/<?php echo $id; ?>/nota">
			<input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>">
			<label for="note" style="margin-top:0">Nota interna (non la vede il cliente)</label>
			<input id="note" type="text" name="note" value="<?php echo e( $sub['note'] ?? '' ); ?>" maxlength="255">
			<div class="row-actions"><button class="btn alt small" type="submit">Salva la nota</button></div>
		</form>
	</div>
</div></section>
