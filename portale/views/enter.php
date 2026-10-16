<?php /** @var string $token */ ?>
<h1>Entra nell'area riservata</h1>
<div class="card">
<form method="post" action="/entra">
	<input type="hidden" name="csrf" value="<?php echo e( Portale\App::csrf() ); ?>">
	<input type="hidden" name="token" value="<?php echo e( $token ); ?>">
	<button class="btn" type="submit">Entra</button>
</form>
</div>
