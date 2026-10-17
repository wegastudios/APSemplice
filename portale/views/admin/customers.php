<?php /** @var array $rows @var string $role */ include __DIR__ . '/_nav.php'; $csrf = Portale\App::csrf(); ?>
<section class="admin"><div class="wrap">
	<p class="muted">Eliminare un cliente toglie dal portale la sua email, i domini e le licenze (anche quelle «in attesa» di pagamenti mai conclusi), così l'email e i domini si possono usare di nuovo. Il server licenze Wega e i provider di pagamento non vengono toccati.</p>
	<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Email</th><th>Domini</th><th>Licenze</th><th>Registrato il</th><th></th></tr></thead><tbody>
	<?php foreach ( $rows as $c ) : ?>
		<tr><td><a href="/admin/licenze?q=<?php echo urlencode( $c['email'] ); ?>"><?php echo e( $c['email'] ); ?></a></td><td><?php echo e( str_replace( ',', ', ', (string) $c['domains'] ) ); ?></td><td><?php echo (int) $c['licenses']; ?></td><td><?php echo e( date_it( $c['created_at'] ) ); ?></td>
		<td><?php if ( Portale\Staff::ADMIN === $role ) : ?><form method="post" action="/admin/clienti/<?php echo e( $c['id'] ); ?>/elimina" onsubmit="return confirm('Eliminare <?php echo e( $c['email'] ); ?> con tutte le sue licenze e i suoi domini? Non si può annullare.')"><input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>"><button class="btn danger small" type="submit">Elimina</button></form><?php endif; ?></td></tr>
	<?php endforeach; ?>
	<?php if ( ! $rows ) : ?><tr><td colspan="5" class="muted">Nessun cliente per ora.</td></tr><?php endif; ?>
	</tbody></table></div>
</div></section>
