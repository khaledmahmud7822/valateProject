<?php
/* ------------------------------------------------------------------
 * lib/pdf.php — minimal pure-PHP PDF writer (no extensions required).
 *
 * Supports core fonts (Helvetica / Helvetica-Bold), text, filled
 * rectangles and thin rules — enough for a clean, professional
 * invoice. Coordinates are in points; origin is bottom-left.
 * ------------------------------------------------------------------ */

class MiniPDF {
    private array $pages = [];
    private array $page = [];
    private float $w = 612.0;
    private float $h = 792.0;

    public function __construct(float $w = 612.0, float $h = 792.0) {
        $this->w = $w;
        $this->h = $h;
        $this->newPage();
    }

    public function newPage(): void {
        if ($this->page !== []) {
            $this->pages[] = $this->page;
        }
        $this->page = [];
    }

    private static function esc(string $s): string {
        $s = str_replace('\\', '\\\\', $s);
        $s = str_replace('(', '\\(', $s);
        $s = str_replace(')', '\\)', $s);
        // Strip non-latin1 characters the core fonts cannot render.
        $out = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $o = ord($s[$i]);
            $out .= ($o >= 32 && $o <= 255 && $o !== 127) ? $s[$i] : '?';
        }
        return $out;
    }

    /** Add a line of text. $x/$y measured from bottom-left. */
    public function text(float $x, float $y, string $text, float $size = 10.0, bool $bold = false, array $rgb = [0, 0, 0]): void {
        $r = $rgb[0] / 255; $g = $rgb[1] / 255; $b = $rgb[2] / 255;
        $font = $bold ? '/F2' : '/F1';
        $this->page[] = sprintf(
            "BT %s %.2f Tf %.2f %.2f %.2f rg %.2f %.2f Td (%s) Tj ET\n",
            $font, $size, $r, $g, $b, $x, $y, self::esc($text)
        );
    }

    /** Right-aligned text inside column [$x1,$x2]. */
    public function textRight(float $x1, float $x2, float $y, string $text, float $size = 10.0, bool $bold = false, array $rgb = [0, 0, 0]): void {
        // Approximate width: Helvetica ~0.55 * size per char (conservative).
        $w = strlen($text) * $size * 0.52;
        $this->text(max($x1, $x2 - $w), $y, $text, $size, $bold, $rgb);
    }

    /** Filled rectangle. */
    public function rect(float $x, float $y, float $w, float $h, array $rgb): void {
        $this->page[] = sprintf(
            "%.2f %.2f %.2f rg %.2f %.2f %.2f %.2f re f\n",
            $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255, $x, $y, $w, $h
        );
    }

    /** Horizontal rule. */
    public function rule(float $x1, float $x2, float $y, array $rgb = [210, 215, 222], float $w = 0.75): void {
        $this->page[] = sprintf(
            "%.2f %.2f %.2f RG %.2f w %.2f %.2f m %.2f %.2f l S\n",
            $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255, $w, $x1, $y, $x2, $y
        );
    }

    public function render(): string {
        $this->pages[] = $this->page;
        $this->page = [];

        $objects = [];
        $n = 0;
        $newObj = function (string $body) use (&$objects, &$n): int {
            $n++;
            $objects[$n] = "$n 0 obj\n$body\nendobj\n";
            return $n;
        };

        $font1 = $newObj("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>");
        $font2 = $newObj("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>");

        $pageKids = [];
        foreach ($this->pages as $cmds) {
            $stream = implode('', $cmds);
            $len = strlen($stream);
            $content = $newObj("<< /Length $len >>\nstream\n$stream\nendstream");
            $pageKids[] = $newObj(
                "<< /Type /Page /Parent __PAGES__ /MediaBox [0 0 {$this->w} {$this->h}] "
                . "/Resources << /Font << /F1 $font1 0 R /F2 $font2 0 R >> >> /Contents $content 0 R >>"
            );
        }

        $pagesId = $newObj("<< /Type /Pages /Kids [" . implode(' ', array_map(fn($k) => "$k 0 R", $pageKids)) . "] /Count " . count($pageKids) . " >>");
        $catalogId = $newObj("<< /Type /Catalog /Pages $pagesId 0 R >>");

        // Fix the __PAGES__ placeholder now that we know the id.
        foreach ($pageKids as $k) {
            $objects[$k] = str_replace('__PAGES__', "$pagesId 0 R", $objects[$k]);
        }

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        for ($i = 1; $i <= $n; $i++) {
            $offsets[$i] = strlen($pdf);
            $pdf .= $objects[$i];
        }
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 " . ($n + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $n; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size " . ($n + 1) . " /Root $catalogId 0 R >>\n";
        $pdf .= "startxref\n$xrefPos\n%%EOF";
        return $pdf;
    }
}

/* ------------------------------------------------------------------
 * invoice_pdf_data() — gather everything the PDF (and the paid email)
 * need for one invoice. Returns null when the invoice does not exist.
 * ------------------------------------------------------------------ */
function invoice_pdf_data(int $invoice_id): ?array {
    $pdo = db();
    $st = $pdo->prepare(
        'SELECT i.*,
                c.name AS customer_name, c.email AS customer_email, c.phone AS customer_phone,
                co.name AS company_name, co.billing_email, co.billing_phone,
                l.name AS location_name,
                s.start_date AS period_start, s.end_date AS period_end
         FROM invoices i
         LEFT JOIN customers c ON c.id = i.customer_id
         LEFT JOIN companies co ON co.id = i.company_id
         LEFT JOIN subscriptions s ON s.id = i.subscription_id
         LEFT JOIN parking_locations l ON l.id = s.location_id
         WHERE i.id = ? LIMIT 1'
    );
    $st->execute([$invoice_id]);
    $inv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$inv) {
        return null;
    }
    $it = $pdo->prepare('SELECT description, qty, unit_cents, amount_cents FROM invoice_items WHERE invoice_id = ? ORDER BY id');
    $it->execute([$invoice_id]);
    $items = $it->fetchAll(PDO::FETCH_ASSOC);

    $plates = [];
    if (!empty($inv['subscription_id'])) {
        $vp = $pdo->prepare(
            'SELECT v.plate FROM vehicles v
             JOIN subscriptions s ON (s.vehicle_id = v.id OR (s.company_id IS NOT NULL AND s.company_id = v.company_id))
             WHERE s.id = ? LIMIT 5'
        );
        $vp->execute([(int) $inv['subscription_id']]);
        $plates = $vp->fetchAll(PDO::FETCH_COLUMN);
    }
    // Fallback: any vehicle of the customer/company.
    if ($plates === []) {
        if (!empty($inv['customer_id'])) {
            $vp = $pdo->prepare('SELECT plate FROM vehicles WHERE customer_id = ? ORDER BY id LIMIT 5');
            $vp->execute([(int) $inv['customer_id']]);
            $plates = $vp->fetchAll(PDO::FETCH_COLUMN);
        } elseif (!empty($inv['company_id'])) {
            $vp = $pdo->prepare('SELECT plate FROM vehicles WHERE company_id = ? ORDER BY id LIMIT 5');
            $vp->execute([(int) $inv['company_id']]);
            $plates = $vp->fetchAll(PDO::FETCH_COLUMN);
        }
    }

    $pay = $pdo->prepare("SELECT paid_at, reference, method FROM payments WHERE invoice_id = ? ORDER BY paid_at DESC, id DESC LIMIT 1");
    $pay->execute([$invoice_id]);
    $last_pay = $pay->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'inv'       => $inv,
        'items'     => $items,
        'plates'    => array_values(array_filter($plates)),
        'last_pay'  => $last_pay,
        'bill_name' => ($inv['customer_name'] ?? '') !== '' ? (string) $inv['customer_name'] : (($inv['company_name'] ?? '') !== '' ? (string) $inv['company_name'] : (string) ($inv['bill_name'] ?? '')),
        'bill_email'=> ($inv['customer_email'] ?? '') !== '' ? (string) $inv['customer_email'] : (($inv['billing_email'] ?? '') !== '' ? (string) $inv['billing_email'] : (string) ($inv['bill_email'] ?? '')),
        'bill_phone'=> ($inv['customer_phone'] ?? '') !== '' ? (string) $inv['customer_phone'] : (string) ($inv['billing_phone'] ?? ''),
    ];
}

/* ------------------------------------------------------------------
 * build_invoice_pdf() — render the PDF bytes for an invoice.
 * ------------------------------------------------------------------ */
function build_invoice_pdf(int $invoice_id): ?string {
    $d = invoice_pdf_data($invoice_id);
    if ($d === null) {
        return null;
    }
    $inv = $d['inv'];
    $business = (string) setting('business_name', 'Metro Valet Parking');
    $phone = (string) setting('business_phone', '');
    $email = (string) setting('business_email', '');

    $navy = [10, 26, 47]; $gold = [150, 115, 25]; $muted = [91, 103, 116];
    $green = [30, 126, 70]; $line = [226, 232, 240];

    $pdf = new MiniPDF();
    $x0 = 56; $x1 = 556; $y = 740;

    // Header band.
    $pdf->rect($x0, $y - 6, $x1 - $x0, 52, $navy);
    $pdf->text($x0 + 16, $y + 22, $business, 17, true, [255, 255, 255]);
    $contact = trim($phone . ($phone !== '' && $email !== '' ? '  |  ' : '') . $email);
    if ($contact !== '') {
        $pdf->text($x0 + 16, $y + 4, $contact, 9, false, [201, 162, 39]);
    }
    $pdf->textRight($x0, $x1 - 16, $y + 22, 'INVOICE', 17, true, [255, 255, 255]);
    $pdf->textRight($x0, $x1 - 16, $y + 4, (string) $inv['number'], 11, true, [255, 255, 255]);
    $y -= 34;

    // Status stamp.
    $status = strtoupper(str_replace('_', ' ', (string) $inv['status']));
    $stamp_rgb = $inv['status'] === 'paid' ? $green : ($inv['status'] === 'overdue' ? [176, 42, 42] : $muted);
    $pdf->rect($x1 - 116, $y - 2, 100, 24, $stamp_rgb);
    $pdf->text($x1 - 104, $y + 6, $status, 11, true, [255, 255, 255]);
    $y -= 14;

    // Bill-to + meta.
    $pdf->text($x0, $y, 'Billed to', 9, true, $muted); $y -= 15;
    $pdf->text($x0, $y, $d['bill_name'] !== '' ? $d['bill_name'] : '—', 12, true, $navy); $y -= 15;
    if ($d['bill_email'] !== '') { $pdf->text($x0, $y, $d['bill_email'], 10, false, $muted); $y -= 14; }
    if ($d['bill_phone'] !== '') { $pdf->text($x0, $y, $d['bill_phone'], 10, false, $muted); $y -= 14; }

    $meta_y = $y + 44;
    $meta = [
        'Issue date' => (string) ($inv['issue_date'] ?? ''),
        'Due date'   => (string) ($inv['due_date'] ?? '—'),
    ];
    if (!empty($d['inv']['location_name'])) { $meta['Location'] = (string) $d['inv']['location_name']; }
    if ($d['plates'] !== []) { $meta['License plate'] = implode(', ', $d['plates']); }
    if (!empty($inv['period_start'])) { $meta['Billing period'] = (string) $inv['period_start'] . ' to ' . (string) ($inv['period_end'] ?? ''); }
    foreach ($meta as $k => $v) {
        $pdf->text(360, $meta_y, $k . ':', 9, true, $muted);
        $pdf->text(470, $meta_y, $v, 9, false, $navy);
        $meta_y -= 14;
    }
    $y -= 10;

    // Items table.
    $pdf->rule($x0, $x1, $y); $y -= 16;
    $pdf->text($x0, $y, 'Description', 9, true, $muted);
    $pdf->text(380, $y, 'Qty', 9, true, $muted);
    $pdf->textRight(430, 500, $y, 'Unit', 9, true, $muted);
    $pdf->textRight(500, $x1, $y, 'Amount', 9, true, $muted);
    $y -= 6; $pdf->rule($x0, $x1, $y); $y -= 16;
    foreach ($d['items'] as $row) {
        if ($y < 130) { break; } // single-page invoices; items list is short
        $pdf->text($x0, $y, (string) $row['description'], 10, false, $navy);
        $pdf->text(380, $y, (string) (int) $row['qty'], 10, false, $navy);
        $pdf->textRight(430, 500, $y, money((int) $row['unit_cents']), 10, false, $navy);
        $pdf->textRight(500, $x1, $y, money((int) $row['amount_cents']), 10, true, $navy);
        $y -= 16;
    }
    $pdf->rule($x0, $x1, $y + 6);

    // Totals.
    $y -= 14;
    $total = (int) $inv['amount_cents'];
    $paid = (int) $inv['amount_paid_cents'];
    $pdf->textRight(400, $x1, $y, 'Total:   ' . money($total), 11, true, $navy); $y -= 18;
    $pdf->textRight(400, $x1, $y, 'Paid:   ' . money($paid), 10, false, $green); $y -= 18;
    $pdf->textRight(400, $x1, $y, 'Balance due:   ' . money($total - $paid), 12, true, $navy); $y -= 30;

    if (!empty($d['last_pay']['paid_at'])) {
        $pdf->text($x0, $y, 'Paid on ' . (string) $d['last_pay']['paid_at']
            . (!empty($d['last_pay']['reference']) ? '  |  Ref: ' . (string) $d['last_pay']['reference'] : ''), 9, false, $muted);
        $y -= 20;
    }

    if (!empty($inv['notes'])) {
        $pdf->text($x0, $y, 'Notes: ' . (string) $inv['notes'], 9, false, $muted);
        $y -= 16;
    }

    // Footer.
    $pdf->rule($x0, $x1, 70, $line);
    $pdf->text($x0, 52, 'Thank you for choosing ' . $business . '. We appreciate your business.', 9, false, $muted);
    if ($phone !== '') {
        $pdf->text($x0, 38, 'Questions? Call ' . $phone, 9, false, $muted);
    }

    return $pdf->render();
}
