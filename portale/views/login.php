<section class="page"><div class="wrap page-narrow">
	<div class="panel">
		<h1>Area riservata</h1>
		<p class="muted">Niente password da ricordare: scrivi la tua email e ti mandiamo un link per entrare.</p>
		<form method="post" action="/accedi">
			<input type="hidden" name="csrf" value="<?php echo e( Portale\App::csrf() ); ?>">
			<label for="email">Email</label>
			<input id="email" type="email" name="email" placeholder="segreteria@miaassociazione.it" autocomplete="email" required>
			<button class="btn big block" type="submit">Mandami il link</button>
			<p class="hint">Il link vale 15 minuti ed è monouso. Non hai ancora una licenza? <a href="/free">Ottienila gratis</a>.</p>
		</form>
	</div>
</div></section>
