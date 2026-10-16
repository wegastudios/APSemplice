<?php /** @var int $active @var int $paying @var int $mrr @var int $trials @var int $expiring @var int $past_due @var int $new30 @var array $by_plan @var array $recent @var array $cfg */
include __DIR__ . '/_nav.php';
$missing = array();
if ( ! $cfg['key'] ) { $missing[] = 'chiave di cifratura (app_key)'; }
if ( ! $cfg['wega'] ) { $missing[] = 'token del server licenze Wega'; }
if ( ! $cfg['stripe'] ) { $missing[] = 'Stripe (chiave segreta e segreto del webhook)'; }
if ( ! $cfg['paypal'] ) { $missing[] = 'PayPal (client, segreto e ID del webhook)'; }
?>
<section class="admin"><div class="wrap">
	<?php if ( $missing ) : ?><div class="card2" style="border-color:var(--accent)"><h2>Da completare</h2><p class="muted" style="margin:0 0 10px"><?php echo e( implode( ' · ', $missing ) ); ?></p><a class="btn small" href="/admin/pagamenti">Inserisci le chiavi</a></div><?php endif; ?>
	<div class="stats">
		<div class="stat"><b><?php echo (int) $active; ?></b><span>Licenze attive</span></div>
		<div class="stat"><b><?php echo (int) $paying; ?></b><span>Abbonamenti a pagamento</span></div>
		<div class="stat"><b><?php echo e( money( $mrr ) ); ?></b><span>Incasso mensile ricorrente</span></div>
		<div class="stat"><b><?php echo (int) $trials; ?></b><span>Prove in corso</span></div>
		<div class="stat <?php echo $expiring ? 'warn' : ''; ?>"><b><?php echo (int) $expiring; ?></b><span>In scadenza (30 giorni)</span></div>
		<div class="stat <?php echo $past_due ? 'bad' : ''; ?>"><b><?php echo (int) $past_due; ?></b><span>Pagamenti non riusciti</span></div>
		<div class="stat"><b><?php echo (int) $new30; ?></b><span>Nuovi clienti (30 giorni)</span></div>
	</div>
	<div class="card2">
		<h2>Ultime licenze</h2>
		<?php if ( ! $recent ) : ?><p class="muted">Nessuna licenza emessa per ora.</p><?php else : ?>
		<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Cliente</th><th>Dominio</th><th>Piano</th><th>Tipo</th><th>Stato</th><th>Scade</th></tr></thead><tbody>
		<?php foreach ( $recent as $s ) : $st = sub_status( $s ); ?>
			<tr><td><?php echo e( $s['email'] ); ?></td><td><a href="/admin/licenze/<?php echo e( $s['id'] ); ?>"><?php echo e( $s['domain'] ); ?></a></td>
			<td><?php echo e( Portale\Config::get( 'plans.' . $s['plan_code'] . '.label', $s['plan_code'] ) ); ?></td><td><?php echo e( sub_kind( $s ) ); ?></td>
			<td><span class="badge <?php echo e( $st[1] ); ?>"><?php echo e( $st[0] ); ?></span></td><td><?php echo e( date_it( $s['expires_at'] ) ); ?></td></tr>
		<?php endforeach; ?></tbody></table></div>
		<?php endif; ?>
	</div>
	<div class="row-actions"><a class="btn" href="/admin/licenze/nuova">Emetti una licenza</a><a class="btn alt" href="/admin/licenze">Tutte le licenze</a></div>
</div></section>
