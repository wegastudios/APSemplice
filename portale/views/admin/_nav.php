<?php /** @var string $nav @var string $flash @var string $admin_email */
$tabs = array( 'cruscotto' => array( '/admin', 'Cruscotto' ), 'licenze' => array( '/admin/licenze', 'Licenze' ), 'clienti' => array( '/admin/clienti', 'Clienti' ), 'prezzi' => array( '/admin/prezzi', 'Piani e prezzi' ), 'email' => array( '/admin/email', 'Email' ), 'pagamenti' => array( '/admin/pagamenti', 'Pagamenti' ), 'cookie' => array( '/admin/cookie', 'Cookie e script' ), 'legale' => array( '/admin/legale', 'Pagine legali' ), 'impostazioni' => array( '/admin/impostazioni', 'Impostazioni' ), 'registro' => array( '/admin/registro', 'Registro' ) );
?>
<div class="wrap" style="padding-top:28px">
<div class="admin-top"><h1>Gestione</h1><span class="muted small"><?php echo e( $admin_email ); ?> · <a href="/account">La mia area cliente</a></span></div>
<nav class="tabs" aria-label="Gestione">
<?php foreach ( $tabs as $k => $t ) : ?><a href="<?php echo e( $t[0] ); ?>"<?php echo $k === $nav ? ' aria-current="page"' : ''; ?>><?php echo e( $t[1] ); ?></a><?php endforeach; ?>
</nav>
<?php if ( $flash ) : ?><div class="flash" role="status"><?php echo e( $flash ); ?></div><?php endif; ?>
</div>
