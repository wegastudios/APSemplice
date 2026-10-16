<?php /** @var string $title @var string $html */ ?>
<section class="page"><div class="wrap" style="max-width:760px">
	<div class="panel legal">
		<h1><?php echo e( $title ); ?></h1>
		<?php echo $html; // Già filtrato da Legal::html(). ?>
	</div>
</div></section>
