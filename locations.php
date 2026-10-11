<?php
require __DIR__.'/lib/bootstrap.php';
$page_title = 'Parking Locations';
$page_desc = 'Browse Metro Valet Parking managed locations across Metro Detroit. Search by name, filter by garage, lot, or hotel, and see monthly parking availability.';
$page_key = 'locations';

$q = trim($_GET['q'] ?? '');
$type = trim($_GET['type'] ?? '');
$allowedTypes = ['garage', 'lot', 'hotel'];
if (!in_array($type, $allowedTypes, true)) { $type = ''; }

$where = ['is_published = 1'];
$params = [];
if ($q !== '') {
    $where[] = '(name LIKE :q OR address LIKE :q OR city LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
if ($type !== '') {
    $where[] = 'type = :type';
    $params[':type'] = $type;
}

$locations = [];
try {
    $sql = 'SELECT id, name, address, city, type, description, hours, services, monthly_available, accepts_requests, lat, lng
            FROM parking_locations WHERE ' . implode(' AND ', $where) . ' ORDER BY name';
    $st = db()->prepare($sql);
    $st->execute($params);
    $locations = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $locations = []; }

include __DIR__.'/includes/site_header.php';
?>

<section class="page-hero">
  <div class="container">
    <p class="crumbs"><a href="index.php">Home</a> &rsaquo; Locations</p>
    <h1>Parking Locations</h1>
    <p>Managed parking locations across Metro Detroit. Search by name or area, filter by facility type.</p>
  </div>
</section>

<section class="section">
  <div class="container">
    <form action="locations.php" method="get" role="search" aria-label="Search and filter locations">
      <div class="filter-bar">
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search by name or area&hellip;" aria-label="Search locations">
        <select name="type" aria-label="Filter by facility type">
          <option value="">All types</option>
          <option value="garage"<?= $type === 'garage' ? ' selected' : '' ?>>Garage</option>
          <option value="lot"<?= $type === 'lot' ? ' selected' : '' ?>>Lot</option>
          <option value="hotel"<?= $type === 'hotel' ? ' selected' : '' ?>>Hotel</option>
        </select>
        <button class="btn btn-navy" type="submit">Apply</button>
        <?php if ($q !== '' || $type !== ''): ?>
          <a class="btn btn-ghost" href="locations.php">Clear</a>
        <?php endif; ?>
      </div>
    </form>

    <div id="locMap" style="height:420px;border-radius:14px;margin:1.5rem 0;z-index:1" role="application" aria-label="Map of parking locations"></div>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
    (function(){
      var pts = <?= json_encode(array_values(array_filter(array_map(function($l){
        if (empty($l['lat']) || empty($l['lng'])) return null;
        $addr = trim(($l['address'] ?? '') . ', ' . ($l['city'] ?? ''), ', ');
        return ['lat' => (float)$l['lat'], 'lng' => (float)$l['lng'],
                'name' => $l['name'], 'addr' => $addr, 'id' => (int)$l['id'],
                'monthly' => !empty($l['monthly_available'])];
      }, $locations)))) ?>;
      var map = L.map('locMap').setView([42.33, -83.05], 12);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {attribution:'&copy; OpenStreetMap contributors', maxZoom:19}).addTo(map);
      var bounds = [];
      pts.forEach(function(p){
        var m = L.marker([p.lat, p.lng]).addTo(map);
        m.bindPopup('<strong>'+p.name+'</strong><br>'+p.addr+'<br>'
          + (p.monthly ? '<span style="color:#1e8e5a">Monthly parking available</span><br>' : '')
          + '<a href="location.php?id='+p.id+'">View details</a>');
        bounds.push([p.lat, p.lng]);
      });
      if (bounds.length) map.fitBounds(bounds, {padding:[40,40]});
    })();
    </script>

    <?php if (empty($locations)): ?>
      <div class="empty-state">
        <h3>No locations found</h3>
        <p>Try a different search term or filter. For help finding parking, call <a href="tel:+18554426500">(855)-442-6500</a>.</p>
      </div>
    <?php else: ?>
      <p class="loc-meta" style="margin-bottom:1.5rem"><?= e((string)count($locations)) ?> location<?= count($locations) === 1 ? '' : 's' ?> found</p>
      <div class="grid grid-2">
        <?php foreach ($locations as $loc):
          $addr = trim(($loc['address'] ?? '') . ', ' . ($loc['city'] ?? ''), ', ');
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
        ?>
        <article class="card location-card">
          <div class="loc-body">
            <h3><?= e($loc['name'] ?? 'Parking Location') ?></h3>
            <?php if ($addr !== ''): ?><p class="loc-meta"><?= e($addr) ?></p><?php endif; ?>
            <?php if (!empty($loc['hours'])): ?><p class="loc-meta">Hours: <?= e($loc['hours']) ?></p><?php endif; ?>
            <div>
              <?php if (!empty($loc['type'])): ?><span class="badge"><span class="dot"></span><?= e(ucfirst((string)$loc['type'])) ?></span><?php endif; ?>
              <?php if (!empty($loc['monthly_available'])): ?><span class="badge badge--gold"><span class="dot"></span>Monthly Parking</span><?php endif; ?>
            </div>
            <?php if (!empty($services)): ?>
            <div class="loc-services">
              <?php foreach (array_slice($services, 0, 5) as $svc): ?>
                <span class="badge"><?= e($svc) ?></span>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="loc-actions">
              <a class="btn btn-navy" style="padding:.6rem 1.2rem;font-size:.88rem" href="location.php?id=<?= e((string)$loc['id']) ?>">View Details</a>
            </div>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php include __DIR__.'/includes/site_footer.php'; ?>
