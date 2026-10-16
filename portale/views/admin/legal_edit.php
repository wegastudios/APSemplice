<?php /** @var array $vars @var array $legal */ include __DIR__ . '/_nav.php';
$val = function ( string $k ) use ( $legal ) { return e( $legal[ $k ] ?? '' ); };
?>
<section class="admin"><div class="wrap" style="max-width:820px">
	<form method="post" action="/admin/legale">
		<input type="hidden" name="csrf" value="<?php echo e( Portale\App::csrf() ); ?>">
		<div class="card2">
			<h2>Titolare del trattamento</h2>
			<p class="muted">Questi dati compaiono nelle informative al posto di {titolare}, {indirizzo}, {piva} ed {email}.</p>
			<div class="form-grid two">
				<div><label for="titolare" style="margin-top:0">Ragione sociale</label><input id="titolare" type="text" name="titolare" value="<?php echo $val( 'titolare' ); ?>" placeholder="<?php echo e( $vars['titolare'] ); ?>"></div>
				<div><label for="piva" style="margin-top:0">Partita IVA / codice fiscale</label><input id="piva" type="text" name="piva" value="<?php echo $val( 'piva' ); ?>" placeholder="P.IVA …"></div>
				<div><label for="indirizzo">Indirizzo</label><input id="indirizzo" type="text" name="indirizzo" value="<?php echo $val( 'indirizzo' ); ?>" placeholder="Via … , città"></div>
				<div><label for="email">Email per la privacy</label><input id="email" type="email" name="email" value="<?php echo $val( 'email' ); ?>" placeholder="<?php echo e( $vars['email'] ); ?>"></div>
			</div>
		</div>
		<?php foreach ( Portale\Legal::PAGES as $slug => $p ) : ?>
		<div class="card2">
			<h2><?php echo e( $p[0] ); ?> <a class="small" style="font-weight:400;margin-left:8px" href="/<?php echo e( $slug ); ?>" target="_blank" rel="noopener">vedi la pagina</a></h2>
			<label for="<?php echo e( $p[1] ); ?>" style="margin-top:0">Testo</label>
			<textarea class="mono" id="<?php echo e( $p[1] ); ?>" name="<?php echo e( $p[1] ); ?>" rows="16" spellcheck="false"><?php echo e( Portale\Legal::isCustom( $slug ) ? Portale\Legal::body( $slug ) : Portale\Legal::defaults()[ $slug ] ); ?></textarea>
			<p class="hint">«## Titolo» per i titoli, «- » per gli elenchi, **grassetto**. Segnaposto: {titolare} {indirizzo} {piva} {email} {sito}. Cancella tutto il testo e salva per tornare a quello di base.</p>
		</div>
		<?php endforeach; ?>
		<div class="risk">I testi di base sono un punto di partenza: fai controllare le informative a un consulente prima di mettere il sito in produzione, perché devono descrivere i trattamenti reali (strumenti di statistica inclusi).</div>
		<div class="row-actions"><button class="btn" type="submit">Salva</button></div>
	</form>
</div></section>
