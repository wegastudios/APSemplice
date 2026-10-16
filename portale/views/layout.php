<?php /** @var string $content */
$robots = ! \Portale\Config::get( 'indexable', false );
$logged = ! empty( $_SESSION['customer'] );
$v      = (string) @filemtime( __DIR__ . '/../public/assets/portale.css' );
?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Associazione Semplice · Il gestionale per le associazioni</title>
<meta name="description" content="Soci, eventi, prima nota e privacy per la tua associazione. Plugin WordPress gratuito, con piani Pro per pagamenti online e contabilità.">
<?php if ( $robots ) : ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
<meta name="theme-color" content="#0f6b57">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='9' fill='%230f6b57'/%3E%3Cpath d='M9 16.5l4.5 4.5L23 11.5' fill='none' stroke='white' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E">
<link rel="stylesheet" href="/assets/portale.css?v=<?php echo e( $v ); ?>">
</head>
<body>
<header class="site-header"><div class="wrap">
	<a class="logo" href="/"><span class="logo-mark"><?php echo icon( 'users' ); ?></span>Associazione Semplice</a>
	<nav class="nav" aria-label="Principale">
		<a href="/#funzioni">Funzioni</a><a href="/#prezzi">Prezzi</a><a href="/#domande">Domande</a>
		<a class="btn small" href="<?php echo $logged ? '/account' : '/accedi'; ?>">Area riservata</a>
	</nav>
</div></header>
<main><?php echo $content; ?></main>
<footer class="site-footer"><div class="wrap">
	<div>© <?php echo date( 'Y' ); ?> Associazione Semplice · un progetto di Wega Studios</div>
	<div><a href="/free">Licenza gratuita</a><a href="/accedi">Area riservata</a><a href="mailto:info@associazionesemplice.it">info@associazionesemplice.it</a></div>
</div></footer>
<script src="/assets/portale.js?v=<?php echo e( $v ); ?>" defer></script>
</body>
</html>
