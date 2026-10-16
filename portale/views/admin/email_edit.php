<?php /** @var string $event @var array $def @var array $tpl @var bool $always @var array $sample */
include __DIR__ . '/_nav.php'; ?>
<section class="admin"><div class="wrap" style="max-width:820px">
	<p><a href="/admin/email">← Tutti i modelli</a></p>
	<div class="card2">
		<h2><?php echo e( $def['label'] ); ?></h2>
		<p class="muted"><?php echo e( $def['when'] ); ?></p>
		<form method="post" action="/admin/email/<?php echo e( $event ); ?>">
			<input type="hidden" name="csrf" value="<?php echo e( Portale\App::csrf() ); ?>">
			<label for="subject" style="margin-top:0">Oggetto</label><input id="subject" type="text" name="subject" value="<?php echo e( $tpl['subject'] ); ?>" maxlength="255" required>
			<label for="body">Testo</label><textarea class="mono" id="body" name="body" rows="12" required><?php echo e( $tpl['body'] ); ?></textarea>
			<p class="hint" style="margin-bottom:0">Segnaposto disponibili (si sostituiscono all'invio):</p>
			<div class="chips"><?php foreach ( $def['vars'] as $v ) : ?><span class="chip">{<?php echo e( $v ); ?>}</span><?php endforeach; ?></div>
			<p class="hint">In fondo a ogni email viene aggiunta la firma «Associazione Semplice» con l'indirizzo del sito.</p>
			<?php if ( $always ) : ?>
				<p class="hint">Questo evento resta sempre attivo.</p>
			<?php else : ?>
				<label class="check"><input type="checkbox" name="enabled" value="1"<?php echo $tpl['enabled'] ? ' checked' : ''; ?>> Invia questa email</label>
			<?php endif; ?>
			<div class="row-actions">
				<button class="btn" type="submit">Salva</button>
				<button class="btn alt" type="submit" name="test" value="1">Salva e mandami una prova</button>
				<?php if ( $tpl['custom'] ) : ?><button class="btn danger" type="submit" name="reset" value="1" formnovalidate onclick="return confirm('Ripristinare il testo originale?')">Ripristina l'originale</button><?php endif; ?>
			</div>
		</form>
	</div>
	<div class="card2">
		<h2>Anteprima con dati di esempio</h2>
		<p><b><?php echo e( Portale\Emails::render( $tpl['subject'], $sample ) ); ?></b></p>
		<div class="pre"><?php echo e( Portale\Emails::render( $tpl['body'], $sample ) . Portale\Emails::FOOTER ); ?></div>
	</div>
</div></section>
