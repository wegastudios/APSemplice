<?php /** @var array $rows @var string $q @var string $status @var int $page @var bool $more */
include __DIR__ . '/_nav.php'; ?>
<section class="admin"><div class="wrap">
	<form class="toolbar" method="get" action="/admin/licenze">
		<input type="text" name="q" value="<?php echo e( $q ); ?>" placeholder="Cerca email o dominio" aria-label="Cerca">
		<select name="stato" aria-label="Stato"><option value="">Tutti gli stati</option>
		<?php foreach ( array( 'active' => 'Attive', 'pending' => 'In attesa', 'past_due' => 'Pagamento non riuscito', 'canceled' => 'Annullate', 'merged' => 'Rinnovi registrati' ) as $k => $t ) : ?><option value="<?php echo e( $k ); ?>"<?php echo $k === $status ? ' selected' : ''; ?>><?php echo e( $t ); ?></option><?php endforeach; ?></select>
		<button class="btn alt" type="submit">Filtra</button><?php if ( Portale\Staff::ADMIN === $role ) : ?><a class="btn" href="/admin/licenze/nuova">Nuova licenza</a><?php endif; ?>
	</form>
	<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Cliente</th><th>Dominio</th><th>Piano</th><th>Tipo</th><th>Stato</th><th>Scade</th><th></th></tr></thead><tbody>
	<?php foreach ( $rows as $s ) : $st = sub_status( $s ); ?>
		<tr><td><?php echo e( $s['email'] ); ?></td><td><a href="/admin/licenze/<?php echo e( $s['id'] ); ?>"><?php echo e( $s['domain'] ); ?></a></td>
		<td><?php echo e( Portale\Config::get( 'plans.' . $s['plan_code'] . '.label', $s['plan_code'] ) ); ?></td><td><?php echo e( sub_kind( $s ) ); ?></td>
		<td><span class="badge <?php echo e( $st[1] ); ?>"><?php echo e( $st[0] ); ?></span></td><td><?php echo e( date_it( $s['expires_at'] ) ); ?></td><td><?php if ( Portale\Staff::ADMIN === $role ) : ?><form method="post" action="/admin/licenze/<?php echo e( $s['id'] ); ?>/elimina" onsubmit="return confirm('Eliminare dal portale la licenza di <?php echo e( $s['domain'] ); ?>? Il dominio torna libero. Non si può annullare.')"><input type="hidden" name="csrf" value="<?php echo e( Portale\App::csrf() ); ?>"><button class="btn danger small" type="submit">Elimina</button></form><?php endif; ?></td></tr>
	<?php endforeach; ?>
	<?php if ( ! $rows ) : ?><tr><td colspan="7" class="muted">Nessuna licenza trovata.</td></tr><?php endif; ?>
	</tbody></table></div>
	<div class="row-actions">
		<?php if ( $page > 1 ) : ?><a class="btn alt small" href="?q=<?php echo urlencode( $q ); ?>&amp;stato=<?php echo urlencode( $status ); ?>&amp;p=<?php echo (int) $page - 1; ?>">← Precedenti</a><?php endif; ?>
		<?php if ( $more ) : ?><a class="btn alt small" href="?q=<?php echo urlencode( $q ); ?>&amp;stato=<?php echo urlencode( $status ); ?>&amp;p=<?php echo (int) $page + 1; ?>">Successive →</a><?php endif; ?>
	</div>
</div></section>
