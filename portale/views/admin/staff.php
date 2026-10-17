<?php /** @var array $rows @var array $labels @var bool $sso @var string $support_url */ include __DIR__ . '/_nav.php'; $csrf = Portale\App::csrf(); ?>
<section class="admin"><div class="wrap">
	<p class="muted">Lo staff entra con la stessa email e password (o link per email) degli altri utenti. <strong>Amministratore</strong>: gestione del portale e del supporto. <strong>Solo supporto</strong>: entra solo nella parte amministrativa del <a href="<?php echo e( $support_url ); ?>"><?php echo e( preg_replace( '#^https?://#', '', $support_url ) ); ?></a>, senza accesso alla gestione del portale. Gli indirizzi in <code>admins</code> di config.php sono sempre amministratori e si cambiano solo lì.</p>
	<?php if ( ! $sso ) : ?><p class="flash" role="status">Accesso unico al supporto non ancora attivo: manca <code>support.sso_secret</code> (almeno 32 caratteri) in config.php. Finché manca, il link «Supporto» porta alla home del sito di supporto.</p><?php endif; ?>
	<form method="post" action="/admin/staff" class="inline-form">
		<input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>">
		<label>Email <input type="email" name="email" required maxlength="190"></label>
		<label>Ruolo <select name="role"><?php foreach ( $labels as $k => $l ) : ?><option value="<?php echo e( $k ); ?>"><?php echo e( $l ); ?></option><?php endforeach; ?></select></label>
		<button class="btn small" type="submit">Aggiungi o cambia ruolo</button>
	</form>
	<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Email</th><th>Ruolo</th><th>Dal</th><th></th></tr></thead><tbody>
	<?php foreach ( $rows as $r ) : ?>
		<tr><td><?php echo e( $r['email'] ); ?></td><td><?php echo e( $labels[ $r['role'] ] ?? $r['role'] ); ?><?php echo $r['locked'] ? ' <span class="muted small">(config.php)</span>' : ''; ?></td><td><?php echo $r['created_at'] ? e( date_it( $r['created_at'] ) ) : ''; ?></td>
		<td><?php if ( ! $r['locked'] ) : ?><form method="post" action="/admin/staff/rimuovi" onsubmit="return confirm('Togliere <?php echo e( $r['email'] ); ?> dallo staff?')"><input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>"><input type="hidden" name="email" value="<?php echo e( $r['email'] ); ?>"><button class="btn danger small" type="submit">Togli</button></form><?php endif; ?></td></tr>
	<?php endforeach; ?>
	<?php if ( ! $rows ) : ?><tr><td colspan="4" class="muted">Nessuno per ora.</td></tr><?php endif; ?>
	</tbody></table></div>
</div></section>
