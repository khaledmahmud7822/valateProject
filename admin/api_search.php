<?php
// admin/api_search.php — smart search JSON endpoint for the command center.
// Searches plates, parker names, companies, and invoice numbers.
// Results are gated by the caller's permissions.
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_guard.php';

header('Content-Type: application/json; charset=utf-8');

$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    echo json_encode(['results' => []]);
    exit;
}

$db = db();
$like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
$results = [];

$add = function (string $type, string $title, string $sub, string $url) use (&$results) {
    if (count($results) >= 12) {
        return;
    }
    $results[] = ['type' => $type, 'title' => $title, 'sub' => $sub, 'url' => site_url($url)];
};

// License plates -> parker detail.
if (can('plates.lookup') || can('parkers.view')) {
    try {
        $st = $db->prepare(
            "SELECT v.plate, v.make, v.model, c.name AS customer_name, co.name AS company_name, s.id AS sub_id
             FROM vehicles v
             LEFT JOIN subscriptions s ON s.vehicle_id = v.id
             LEFT JOIN customers c ON c.id = s.customer_id
             LEFT JOIN companies co ON co.id = s.company_id
             WHERE v.plate LIKE ? ESCAPE '\\'
             GROUP BY v.id
             LIMIT 4"
        );
        $st->execute([$like]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $who = $r['company_name'] ?: $r['customer_name'];
            $add('Plate', $r['plate'] ?: '(no plate)', trim(($who ?: '') . ' ' . ($r['make'] ?? '') . ' ' . ($r['model'] ?? '')),
                $r['sub_id'] ? 'admin/parkers.php?action=edit&id=' . (int) $r['sub_id'] : 'admin/plate_lookup.php');
        }
    } catch (Throwable $ex) {
    }
}

// Parker names (customers).
if (can('parkers.view')) {
    try {
        $st = $db->prepare(
            "SELECT c.name, c.email, s.id AS sub_id
             FROM customers c
             LEFT JOIN subscriptions s ON s.customer_id = c.id AND s.status = 'active'
             WHERE c.name LIKE ? ESCAPE '\\'
             GROUP BY c.id
             LIMIT 4"
        );
        $st->execute([$like]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $add('Parker', $r['name'], (string) ($r['email'] ?? ''),
                $r['sub_id'] ? 'admin/parkers.php?action=edit&id=' . (int) $r['sub_id'] : 'admin/parkers.php');
        }
    } catch (Throwable $ex) {
    }
}

// Companies.
if (can('companies.view')) {
    try {
        $st = $db->prepare('SELECT id, name FROM companies WHERE name LIKE ? ESCAPE ? LIMIT 3');
        $st->execute([$like, '\\']);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $add('Company', $r['name'], 'Corporate account', 'admin/companies.php?id=' . (int) $r['id']);
        }
    } catch (Throwable $ex) {
    }
}

// Invoice numbers.
if (can('invoices.view')) {
    try {
        $st = $db->prepare(
            "SELECT i.number, i.status, c.name AS customer_name, co.name AS company_name
             FROM invoices i
             LEFT JOIN customers c ON c.id = i.customer_id
             LEFT JOIN companies co ON co.id = i.company_id
             WHERE i.number LIKE ? ESCAPE '\\'
             LIMIT 3"
        );
        $st->execute([$like]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $who = $r['company_name'] ?: $r['customer_name'];
            $add('Invoice', $r['number'], trim(($who ?: '') . ' · ' . $r['status']), 'admin/invoices.php?number=' . urlencode($r['number']));
        }
    } catch (Throwable $ex) {
    }
}

echo json_encode(['results' => $results]);
