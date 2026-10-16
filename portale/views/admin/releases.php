<?php /** @var array $rows @var int $keep */ include __DIR__ . '/_nav.php';
$csrf = Portale\App::csrf();
?>
<section class="admin"><div class="wrap">
	<div class="card2">
		<h2>Carica una versione</h2>
		<p class="muted">Scegli lo zip costruito con <code>wordpress/build.sh</code>: il numero di versione si legge dal pacchetto. Restano online le ultime <?php echo (int) $keep; ?> versioni di ciascun plugin (si cambia in <a href="/admin/impostazioni">Impostazioni</a>). I file stanno fuori dalla cartella pubblica e si scaricano solo dall'area cliente, con licenza in regola.</p>
		<form method="post" action="/admin/versioni" enctype="multipart/form-data">
			<input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>">
			<div class="form-grid two">
				<div><label for="product">Plugin</label><select class="field" id="product" name="product"><?php foreach ( Portale\Releases::PRODUCTS as $k => $p ) : ?><option value="<?php echo e( $k ); ?>"><?php echo e( $p[1] ); ?></option><?php endforeach; ?></select></div>
				<div><label for="package">File zip</label><input id="package" type="file" name="package" accept=".zip" required></div>
			</div>
			<label for="note">Nota (facoltativa)</label><input id="note" type="text" name="note" maxlength="500" placeholder="Cosa cambia in questa versione">
			<div class="row-actions"><button class="btn" type="submit">Pubblica</button></div>
		</form>
	</div>
	<?php foreach ( Portale\Releases::PRODUCTS as $k => $p ) : ?>
	<div class="card2">
		<h2><?php echo e( $p[1] ); ?></h2>
		<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Versione</th><th>Pubblicata (UTC)</th><th>Dimensione</th><th>Nota</th><th></th></tr></thead><tbody>
		<?php foreach ( $rows[ $k ] as $i => $r ) : ?>
			<tr><td><b><?php echo e( $r['version'] ); ?></b><?php echo 0 === $i ? ' <span class="badge">ultima</span>' : ''; ?></td><td><?php echo e( $r['created_at'] ); ?></td><td><?php echo e( number_format( $r['size'] / 1024, 0, ',', '.' ) ); ?> KB</td><td><?php echo e( (string) $r['note'] ); ?></td>
			<td style="white-space:nowrap"><a class="btn alt small" href="/scarica/<?php echo e( $k ); ?>/<?php echo e( $r['version'] ); ?>">Scarica</a>
			<form method="post" action="/admin/versioni/elimina" style="display:inline" onsubmit="return confirm('Eliminare per sempre la versione <?php echo e( $r['version'] ); ?>?')"><input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>"><input type="hidden" name="product" value="<?php echo e( $k ); ?>"><input type="hidden" name="version" value="<?php echo e( $r['version'] ); ?>"><button class="btn danger small" type="submit">Elimina</button></form></td></tr>
		<?php endforeach; ?>
		<?php if ( ! $rows[ $k ] ) : ?><tr><td colspan="5" class="muted">Nessuna versione caricata.</td></tr><?php endif; ?>
		</tbody></table></div>
	</div>
	<?php endforeach; ?>
</div></section>
