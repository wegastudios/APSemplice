<?php /** @var array $plans */
$euro = function ( $c ) { return number_format( $c / 100, 2, ',', '.' ) . ' €'; };
?>
<h1>Scegli come usare Associazione Semplice</h1>
<p class="muted">Ogni licenza è legata a un dominio, che puoi cambiare dall'area riservata.</p>
<div class="grid">
<?php foreach ( $plans as $code => $p ) : ?>
	<div class="card">
		<h2><?php echo e( $p['label'] ); ?></h2>
		<p><?php echo e( 'free' === $code ? 'Libro soci, eventi, prima nota e privacy. Sempre gratis.' : ( $p['description'] ?? '' ) ); ?></p>
		<?php if ( 'free' === $code ) : ?>
			<p><a class="btn" href="/free">Ottieni la licenza gratuita</a></p>
		<?php else : foreach ( array( 'month' => 'al mese', 'year' => 'all\'anno' ) as $per => $txt ) : if ( empty( $p['prices'][ $per ] ) ) { continue; } ?>
			<p><strong><?php echo e( $euro( $p['prices'][ $per ]['cents'] ) ); ?></strong> <?php echo e( $txt ); ?><br>
			<a class="btn alt" href="/acquista?plan=<?php echo e( $code ); ?>&amp;period=<?php echo e( $per ); ?>">Scegli</a></p>
		<?php endforeach; endif; ?>
	</div>
<?php endforeach; ?>
</div>
<p class="muted">Il rinnovo è automatico e puoi disdire quando vuoi: la licenza resta valida fino alla scadenza già pagata.</p>
