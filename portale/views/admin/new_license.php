<?php /** @var array $plans */ include __DIR__ . '/_nav.php'; ?>
<section class="admin"><div class="wrap" style="max-width:720px">
	<div class="card2">
		<h2>Emetti una licenza a mano</h2>
		<p class="muted">Per regali, collaborazioni o accordi fuori dal portale: la licenza si crea sul server Wega senza alcun pagamento e compare nell'area riservata del cliente.</p>
		<form method="post" action="/admin/licenze/nuova">
			<input type="hidden" name="csrf" value="<?php echo e( Portale\App::csrf() ); ?>">
			<div class="form-grid two">
				<div><label for="email">Email del cliente</label><input id="email" type="email" name="email" required></div>
				<div><label for="domain">Dominio</label><input id="domain" type="text" name="domain" placeholder="miaassociazione.it" autocapitalize="none" required></div>
				<div><label for="plan">Piano</label><select class="field" id="plan" name="plan"><?php foreach ( $plans as $code => $p ) : ?><option value="<?php echo e( $code ); ?>"><?php echo e( $p['label'] ); ?></option><?php endforeach; ?></select></div>
				<div><label for="days">Durata (giorni)</label><input id="days" type="number" name="days" value="365" min="1" max="3650" required></div>
			</div>
			<label for="note">Nota interna</label><input id="note" type="text" name="note" maxlength="255" placeholder="Per esempio: regalo, partner, prova estesa">
			<label class="check"><input type="checkbox" name="notify" value="1" checked> Manda l'email «Abbonamento attivo» con il link per vedere la chiave</label>
			<div class="row-actions"><button class="btn" type="submit">Emetti la licenza</button><a class="btn alt" href="/admin/licenze">Annulla</a></div>
		</form>
	</div>
</div></section>
