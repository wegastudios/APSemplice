<section class="page"><div class="wrap page-narrow">
	<div class="panel">
		<h1>Area riservata</h1>
		<p class="muted">Entra con la tua email e la tua password.</p>
		<form method="post" action="/accedi">
			<input type="hidden" name="csrf" value="<?php echo e( Portale\App::csrf() ); ?>">
			<label for="email">Email</label>
			<input id="email" type="email" name="email" placeholder="segreteria@miaassociazione.it" autocomplete="username" required>
			<label for="password">Password</label>
			<input id="password" type="password" name="password" autocomplete="current-password">
			<button class="btn big block" type="submit">Entra</button>
			<p class="hint" style="margin-top:16px">Prima volta o password dimenticata? <button class="linklike" type="submit" name="mode" value="link" formnovalidate>Mandami un link per entrare</button> (vale 15 minuti): da lì puoi scegliere la password.</p>
			<p class="hint">Non hai ancora una licenza? <a href="/free">Ottienila gratis</a>.</p>
		</form>
	</div>
</div></section>
