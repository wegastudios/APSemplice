<?php
/** Genera un PDF di prova (due pagine, accenti, euro, parentesi) per controllarlo con poppler (pdfinfo, pdftotext). Uso: php tests/pdf_sample.php /tmp/prova.pdf */
require __DIR__ . '/bootstrap.php';

$pdf = new ApSemplice\Pdf();
$pdf->text( 50, 62, 'Associazione Prova APS', 17, true );
$pdf->text( 50, 100, 'RICEVUTA DI PAGAMENTO', 14, true );
$pdf->text( 545, 100, 'N. 12/2026', 14, true, 'R' );
$pdf->text( 56, 140, 'Quota associativa 2026 — per Perché (famiglia)', 11 );
$pdf->text( 539, 140, '10,00 €', 11, false, 'R' );
$pdf->rect( 50, 150, 495, 20 );
$pdf->line( 50, 180, 545, 180 );
$pdf->add_page();
$pdf->text( 50, 62, 'Seconda pagina: attestazione', 12 );
file_put_contents( $argv[1] ?? '/tmp/prova.pdf', $pdf->output() );
