<?php /** @var array $t @var array $cats */ include __DIR__ . '/_nav.php'; ?>
<section class="admin"><div class="wrap" style="max-width:820px">
	<form method="post" action="/admin/cookie">
		<input type="hidden" name="csrf" value="<?php echo e( Portale\App::csrf() ); ?>">
		<div class="card2">
			<h2>Script di monitoraggio</h2>
			<p class="muted">Incolla qui gli snippet di Google Analytics, Meta Pixel, Matomo o simili. Se scegli una categoria diversa da «Necessari», lo script <b>parte solo dopo il consenso</b> del visitatore e il banner dei cookie compare da solo. Se non c'è nessuno script non necessario, il banner non serve e non viene mostrato.</p>
			<label for="head" style="margin-top:0">Nell'header (prima di &lt;/head&gt;)</label>
			<textarea class="mono" id="head" name="head" rows="7" spellcheck="false" placeholder="&lt;script async src=&quot;https://…&quot;&gt;&lt;/script&gt;"><?php echo e( $t['head'] ); ?></textarea>
			<label for="head_cat">Categoria di consenso</label>
			<select class="field" id="head_cat" name="head_cat"><?php foreach ( $cats as $k => $l ) : ?><option value="<?php echo e( $k ); ?>"<?php echo $k === $t['head_cat'] ? ' selected' : ''; ?>><?php echo e( $l ); ?></option><?php endforeach; ?></select>
			<div class="sep"></div>
			<label for="foot" style="margin-top:0">Nel footer (prima di &lt;/body&gt;)</label>
			<textarea class="mono" id="foot" name="foot" rows="7" spellcheck="false"><?php echo e( $t['foot'] ); ?></textarea>
			<label for="foot_cat">Categoria di consenso</label>
			<select class="field" id="foot_cat" name="foot_cat"><?php foreach ( $cats as $k => $l ) : ?><option value="<?php echo e( $k ); ?>"<?php echo $k === $t['foot_cat'] ? ' selected' : ''; ?>><?php echo e( $l ); ?></option><?php endforeach; ?></select>
			<div class="risk">Se imposti «Necessari» lo script parte subito per tutti, senza chiedere nulla: usalo solo per strumenti che non usano cookie di profilazione né tracciamento. Per statistiche e marketing scegli la categoria giusta.</div>
		</div>
		<div class="card2">
			<h2>Banner dei cookie</h2>
			<label for="banner_text" style="margin-top:0">Testo del banner</label>
			<textarea id="banner_text" name="banner_text" rows="3" maxlength="600" placeholder="<?php echo e( Portale\Tracking::DEFAULT_TEXT ); ?>"><?php echo e( $t['banner_text'] ); ?></textarea>
			<p class="hint">Se lo lasci vuoto si usa il testo mostrato. Il banner offre «Accetta tutti», «Rifiuta» e «Personalizza»; la scelta dura 6 mesi e si cambia da «Preferenze cookie» nel piè di pagina. Quando cambi gli script, a tutti viene chiesto di nuovo.</p>
			<?php $on = Portale\Tracking::banner(); ?>
			<p style="margin:0"><span class="status-dot<?php echo $on ? '' : ' off'; ?>"></span><?php echo $on ? 'Banner attivo: c\'è almeno uno script che richiede consenso.' : 'Banner non mostrato: nessuno script richiede consenso.'; ?></p>
		</div>
		<div class="row-actions"><button class="btn" type="submit">Salva</button><a class="btn alt" href="/cookie" target="_blank" rel="noopener">Vedi l'informativa sui cookie</a></div>
	</form>
</div></section>
