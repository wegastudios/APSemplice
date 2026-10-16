<?php /** @var string $token */ ?>
<section class="page"><div class="wrap page-narrow">
	<div class="panel notice">
		<div class="ico"><?php echo icon( 'key' ); ?></div>
		<h1>Entra nell'area riservata</h1>
		<p>Un ultimo clic per confermare che sei tu.</p>
		<form method="post" action="/entra">
			<input type="hidden" name="csrf" value="<?php echo e( Portale\App::csrf() ); ?>">
			<input type="hidden" name="token" value="<?php echo e( $token ); ?>">
			<button class="btn big block" type="submit">Entra</button>
		</form>
	</div>
</div></section>
