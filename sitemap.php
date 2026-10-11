<?php
// sitemap.php — dynamic XML sitemap.
require __DIR__ . '/lib/bootstrap.php';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'metrovaletparking.com';
$base = $scheme . '://' . $host;

$pages = [
    '' => '1.0', 'about.php' => '0.8', 'services.php' => '0.9', 'monthly-parking.php' => '0.9',
    'hotel-valet.php' => '0.8', 'commercial-parking.php' => '0.8', 'corporate-parking.php' => '0.8',
    'event-valet.php' => '0.8', 'locations.php' => '0.9', 'careers.php' => '0.9', 'apply.php' => '0.8',
    'request-quote.php' => '0.7', 'request-monthly.php' => '0.7', 'contact.php' => '0.7',
    'privacy.php' => '0.3', 'terms.php' => '0.3',
];

// Published locations.
$locs = [];
try {
    $locs = db()->query('SELECT id FROM parking_locations WHERE is_published = 1')->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}

// Open jobs.
$jobs = [];
try {
    $jobs = db()->query("SELECT id FROM jobs WHERE status = 'open'")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}

header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($pages as $p => $pr) {
    echo "  <url><loc>" . htmlspecialchars($base . '/' . $p) . "</loc><priority>$pr</priority></url>\n";
}
foreach ($locs as $lid) {
    echo "  <url><loc>" . htmlspecialchars($base . '/location.php?id=' . (int) $lid) . "</loc><priority>0.7</priority></url>\n";
}
foreach ($jobs as $jid) {
    echo "  <url><loc>" . htmlspecialchars($base . '/apply.php?job=' . (int) $jid) . "</loc><priority>0.8</priority></url>\n";
}
echo '</urlset>';
