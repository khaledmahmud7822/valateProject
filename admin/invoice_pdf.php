<?php
// admin/invoice_pdf.php — download the invoice as a PDF file.
require __DIR__ . '/../lib/bootstrap.php';
$required_perm = 'invoices.view';
require __DIR__ . '/_guard.php';

$id = (int) ($_GET['id'] ?? 0);
$pdf = build_invoice_pdf($id);
if ($pdf === null) {
    flash('Invoice not found.', 'error');
    redirect(site_url('admin/invoices.php'));
}

$db = db();
$st = $db->prepare('SELECT number FROM invoices WHERE id = ? LIMIT 1');
$st->execute([$id]);
$number = (string) ($st->fetchColumn() ?: ('invoice-' . $id));
$fname = 'Invoice-' . preg_replace('/[^A-Za-z0-9-]+/', '', $number) . '.pdf';

audit('invoice.pdf_downloaded', 'invoice', $id, ['number' => $number]);

header('Content-Type: application/pdf');
header('Content-Length: ' . strlen($pdf));
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
echo $pdf;
exit;
