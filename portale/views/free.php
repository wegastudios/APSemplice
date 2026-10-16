<h1>Licenza gratuita</h1>
<div class="card">
<form method="post" action="/free">
	<input type="hidden" name="csrf" value="<?php echo e( Portale\App::csrf() ); ?>">
	<label for="email">Email</label><input id="email" type="email" name="email" required>
	<label for="domain">Dominio del sito</label><input id="domain" type="text" name="domain" placeholder="miaassociazione.it" required>
	<p class="muted">Ti scriviamo un link per confermare l'email: la chiave compare nell'area riservata.</p>
	<button class="btn" type="submit">Ricevi la chiave</button>
</form>
</div>
