<h1>Area riservata</h1>
<div class="card">
<form method="post" action="/accedi">
	<input type="hidden" name="csrf" value="<?php echo e( Portale\App::csrf() ); ?>">
	<label for="email">Email</label><input id="email" type="email" name="email" required>
	<p class="muted">Niente password: ti mandiamo un link per entrare.</p>
	<button class="btn" type="submit">Invia il link</button>
</form>
</div>
