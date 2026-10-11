<?php
require __DIR__.'/lib/bootstrap.php';

$id = trim($_GET['id'] ?? '');
$loc = null;
if ($id !== '' && ctype_digit($id)) {
    try {
        $st = db()->prepare("SELECT id, name, address, city, type, description, hours, services, monthly_available, accepts_requests, monthly_rate_cents
                             FROM parking_locations WHERE id = :id AND is_published = 1 LIMIT 1");
        $st->execute([':id' => (int)$id]);
        $loc = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { $loc = null; }
}

if (!$loc) {
    http_response_code(404);
    $page_title = 'Location Not Found';
    $page_desc = 'The requested parking location could not be found.';
    $page_key = 'locations';
    include __DIR__.'/includes/site_header.php';
    ?>
    <section class="section"><div class="container" style="max-width:640px;text-align:center">
      <h1>Location Not Found</h1>
      <p class="lead">This parking location isn't available or may have been removed.</p>
      <div class="hero-ctas" style="justify-content:center">
        <a class="btn btn-navy" href="locations.php">Browse Locations</a>
        <a class="btn btn-ghost" href="index.php">Back Home</a>
      </div>
    </div></section>
    <?php
    include __DIR__.'/includes/site_footer.php';
    exit;
}

$page_title = $loc['name'] ?? 'Parking Location';
$page_desc = 'Details for ' . ($loc['name'] ?? 'this Metro Valet Parking location') . ' — address, hours, services, and monthly parking availability.';
$page_key = 'locations';

// Photos (optional table; degrade gracefully)
$photos = [];
try {
    $st = db()->prepare('SELECT path, caption FROM location_photos WHERE location_id = :id ORDER BY sort, id');
    $st->execute([':id' => $loc['id']]);
    $photos = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $photos = []; }

$services = [];
if (!empty($loc['services'])) {
    $raw = trim((string)$loc['services']);
    if (str_starts_with($raw, '[')) {
        $dec = json_decode($raw, true);
        if (is_array($dec)) { $services = array_map('strval', $dec); }
    } else {
        $services = array_filter(array_map('trim', explode(',', $raw)));
    }
}

$addr = trim(($loc['address'] ?? '') . ', ' . ($loc['city'] ?? ''), ', ');
$dirUrl = 'https://www.google.com/maps/search/?api=1&query=' . urlencode($addr !== '' ? $addr : ($loc['name'] ?? ''));
$rateText = (isset($loc['monthly_rate_cents']) && $loc['monthly_rate_cents'] !== null && $loc['monthly_rate_cents'] !== '')
    ? money((int)$loc['monthly_rate_cents']) . ' / month'
    : 'Contact for rates';

include __DIR__.'/includes/site_header.php';
?>

<section class="page-hero">
  <div class="container">
    <p class="crumbs"><a href="index.php">Home</a> &rsaquo; <a href="locations.php">Locations</a> &rsaquo; <?= e($loc['name'] ?? '') ?></p>
    <h1><?= e($loc['name'] ?? 'Parking Location') ?></h1>
    <?php if ($addr !== ''): ?><p><?= e($addr) ?></p><?php endif; ?>
  </div>
</section>

<section class="section">
  <div class="container detail-grid">
    <div>
      <?php if (!empty($photos)): ?>
        <div class="loc-hero" style="margin-bottom:2rem">
          <img src="<?= e($photos[0]['path']) ?>" alt="<?= e($photos[0]['caption'] ?: ($loc['name'] ?? 'Parking location')) ?>">
          <div class="loc-hero-label"><?= e($loc['name'] ?? '') ?></div>
        </div>
        <?php if (count($photos) > 1): ?>
        <div class="grid grid-3" style="margin-bottom:2rem">
          <?php foreach (array_slice($photos, 1) as $ph): ?>
            <div class="loc-hero" style="min-height:160px">
              <img src="<?= e($ph['path']) ?>" alt="<?= e($ph['caption'] ?: ($loc['name'] ?? 'Parking location')) ?>" loading="lazy">
            </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      <?php else: ?>
        <div class="loc-hero" style="margin-bottom:2rem" aria-hidden="true">
          <div class="loc-hero-label"><?= e($loc['name'] ?? 'Parking Location') ?></div>
        </div>
      <?php endif; ?>

      <?php if (!empty($loc['description'])): ?>
        <h2>About This Location</h2>
        <div class="prose"><p><?= nl2br(e($loc['description'])) ?></p></div>
      <?php endif; ?>

      <?php if (!empty($services)): ?>
        <h2 style="margin-top:2rem">Services</h2>
        <div class="loc-services">
          <?php foreach ($services as $svc): ?>
            <span class="badge"><?= e($svc) ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <aside class="side-card">
      <h3>Location Details</h3>
      <table class="info-table">
        <tr><th>Address</th><td><?= $addr !== '' ? e($addr) : '—' ?></td></tr>
        <?php if (!empty($loc['type'])): ?><tr><th>Type</th><td><?= e(ucfirst((string)$loc['type'])) ?></td></tr><?php endif; ?>
        <?php if (!empty($loc['hours'])): ?><tr><th>Hours</th><td><?= e($loc['hours']) ?></td></tr><?php endif; ?>
        <tr><th>Monthly Parking</th><td>
          <?php if (!empty($loc['monthly_available'])): ?>
            <span class="badge badge--gold"><span class="dot"></span>Available</span>
          <?php else: ?>
            <span class="badge"><span class="dot"></span>Not offered</span>
          <?php endif; ?>
        </td></tr>
        <?php if (!empty($loc['monthly_available'])): ?>
        <tr><th>Monthly Rate</th><td><?= e($rateText) ?></td></tr>
        <?php endif; ?>
      </table>
      <div style="display:flex;flex-direction:column;gap:.7rem;margin-top:1.2rem">
        <?php if (!empty($loc['monthly_available']) && !empty($loc['accepts_requests'])): ?>
          <a class="btn btn-gold" href="request-monthly.php?location=<?= e((string)$loc['id']) ?>">Request Monthly Parking</a>
        <?php else: ?>
          <a class="btn btn-gold" href="request-quote.php">Request Valet Services</a>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= e($dirUrl) ?>" target="_blank" rel="noopener">Get Directions</a>
      </div>
    </aside>
  </div>
</section>

<?php include __DIR__.'/includes/site_footer.php'; ?>
