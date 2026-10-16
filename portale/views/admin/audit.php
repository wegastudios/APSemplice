<?php /** @var array $rows */ include __DIR__ . '/_nav.php'; ?>
<section class="admin"><div class="wrap">
	<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Quando (UTC)</th><th>Chi</th><th>Azione</th><th>Dettaglio</th></tr></thead><tbody>
	<?php foreach ( $rows as $r ) : ?>
		<tr><td style="white-space:nowrap"><?php echo e( $r['at'] ); ?></td><td><?php echo e( $r['actor'] ); ?></td><td><span class="badge"><?php echo e( $r['action'] ); ?></span></td><td><?php echo e( $r['detail'] ); ?></td></tr>
	<?php endforeach; ?>
	<?php if ( ! $rows ) : ?><tr><td colspan="4" class="muted">Ancora nessuna azione registrata.</td></tr><?php endif; ?>
	</tbody></table></div>
</div></section>
