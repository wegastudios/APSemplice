<?php /** @var string $error @var array $rows @var array $orphans */ include __DIR__ . '/_nav.php'; $csrf = Portale\App::csrf();
$count = array( 'known' => 0, 'missing' => 0, 'blocked' => 0 );
foreach ( $rows as $r ) {
	++$count[ $r['_state'] ];
}
?>
<section class="admin"><div class="wrap">
	<p class="muted">Le licenze che il server Wega ha emesso per questo portale, confrontate con quelle del portale. L'elenco non contiene le chiavi: le licenze importate non mostrano la chiave al cliente (si legge dal pannello Wega).</p>
	<?php if ( $error ) : ?>
		<div class="card2"><h2>Elenco non disponibile</h2><p><?php echo e( $error ); ?></p></div>
	<?php else : ?>
	<div class="toolbar">
		<span class="badge"><?php echo count( $rows ); ?> su Wega</span> <span class="badge"><?php echo (int) $count['known']; ?> già nel portale</span> <span class="badge warn"><?php echo (int) $count['missing']; ?> da importare</span>
		<?php if ( $count['missing'] ) : ?><form method="post" action="/admin/wega"><input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>"><button class="btn" type="submit" onclick="return confirm('Importare tutte le licenze mancanti?')">Importa tutte le mancanti</button></form><?php endif; ?>
	</div>
	<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Cliente</th><th>Dominio</th><th>Piano</th><th>Stato Wega</th><th>Scade</th><th>Nel portale</th><th></th></tr></thead><tbody>
	<?php foreach ( $rows as $r ) : ?>
		<tr><td><?php echo e( (string) ( $r['customer_id'] ?? '' ) ); ?></td><td><?php echo e( (string) ( $r['domain'] ?? '' ) ); ?></td><td><?php echo e( (string) ( $r['plan_name'] ?? '' ) ); ?></td><td><span class="badge"><?php echo e( (string) ( $r['state'] ?? '' ) ); ?></span></td>
		<td><?php echo e( date_it( ! empty( $r['expires_at'] ) ? gmdate( 'Y-m-d H:i:s', (int) strtotime( (string) $r['expires_at'] ) ) : null ) ); ?></td>
		<td><?php if ( 'known' === $r['_state'] ) : ?><a href="/admin/licenze/<?php echo e( $r['_sub']['id'] ); ?>">Sì</a><?php elseif ( 'blocked' === $r['_state'] ) : ?><span class="muted small"><?php echo e( $r['_reason'] ); ?></span><?php else : ?><b>No</b><?php endif; ?></td>
		<td><?php if ( 'missing' === $r['_state'] ) : ?><form method="post" action="/admin/wega"><input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>"><input type="hidden" name="id" value="<?php echo e( (string) $r['id'] ); ?>"><button class="btn alt small" type="submit">Importa</button></form><?php endif; ?></td></tr>
	<?php endforeach; ?>
	<?php if ( ! $rows ) : ?><tr><td colspan="7" class="muted">Wega non ha licenze per questo portale.</td></tr><?php endif; ?>
	</tbody></table></div>
	<?php if ( $orphans ) : ?>
	<div class="card2" style="margin-top:26px"><h2>Nel portale ma non su Wega</h2>
		<p class="muted">Licenze del portale con un identificativo che Wega non elenca (cancellate o di un altro portale).</p>
		<?php foreach ( $orphans as $s ) : ?><p style="margin:4px 0"><a href="/admin/licenze/<?php echo e( $s['id'] ); ?>"><?php echo e( $s['domain'] ); ?></a> · <?php echo e( $s['email'] ); ?></p><?php endforeach; ?>
	</div>
	<?php endif; ?>
	<?php endif; ?>
</div></section>
