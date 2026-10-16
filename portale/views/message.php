<?php /** @var string $title @var string $text */
$bad = http_response_code() >= 400;
?>
<section class="page"><div class="wrap page-narrow">
	<div class="panel notice<?php echo $bad ? ' bad' : ''; ?>">
		<div class="ico"><?php echo icon( $bad ? 'alert' : 'mail' ); ?></div>
		<h1><?php echo e( $title ); ?></h1>
		<p><?php echo e( $text ); ?></p>
		<a class="btn alt" href="/">Torna alla home</a>
	</div>
</div></section>
