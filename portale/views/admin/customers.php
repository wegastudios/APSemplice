<?php /** @var array $rows */ include __DIR__ . '/_nav.php'; ?>
<section class="admin"><div class="wrap">
	<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Email</th><th>Licenze</th><th>Registrato il</th></tr></thead><tbody>
	<?php foreach ( $rows as $c ) : ?>
		<tr><td><a href="/admin/licenze?q=<?php echo urlencode( $c['email'] ); ?>"><?php echo e( $c['email'] ); ?></a></td><td><?php echo (int) $c['licenses']; ?></td><td><?php echo e( date_it( $c['created_at'] ) ); ?></td></tr>
	<?php endforeach; ?>
	<?php if ( ! $rows ) : ?><tr><td colspan="3" class="muted">Nessun cliente per ora.</td></tr><?php endif; ?>
	</tbody></table></div>
</div></section>
