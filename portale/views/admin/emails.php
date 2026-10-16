<?php /** @var array $rows */ include __DIR__ . '/_nav.php'; ?>
<section class="admin"><div class="wrap">
	<p class="muted">Un modello per ogni evento. Puoi cambiare oggetto e testo, spegnere l'invio o tornare al testo originale. Gli eventi «Link di accesso» e «Conferma licenza gratuita» non si spengono: senza di loro il cliente non riesce a entrare.</p>
	<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Evento</th><th>Quando parte</th><th>Testo</th><th>Invio</th></tr></thead><tbody>
	<?php foreach ( $rows as $k => $r ) : ?>
		<tr>
			<td><a href="/admin/email/<?php echo e( $k ); ?>"><?php echo e( $r['label'] ); ?></a></td>
			<td class="muted"><?php echo e( $r['when'] ); ?></td>
			<td><span class="badge <?php echo $r['tpl']['custom'] ? '' : 'warn'; ?>"><?php echo $r['tpl']['custom'] ? 'Personalizzato' : 'Predefinito'; ?></span></td>
			<td><?php if ( $r['always'] ) : ?><span class="muted small">Sempre attivo</span><?php else : ?><span class="status-dot<?php echo $r['tpl']['enabled'] ? '' : ' off'; ?>"></span><?php echo $r['tpl']['enabled'] ? 'Attivo' : 'Spento'; ?><?php endif; ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody></table></div>
</div></section>
