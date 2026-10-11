<?php
require __DIR__.'/lib/bootstrap.php';
$page_title = 'Monthly Parking in Metro Detroit';
$page_desc = 'Monthly parking for individuals and companies at Metro Valet Parking managed locations. Request online in three simple steps: request, review, approval and payment.';
$page_key = 'monthly';

// Published locations offering monthly parking
$locations = [];
try {
    $st = db()->prepare("SELECT id, name, address, city, type FROM parking_locations WHERE is_published = 1 AND monthly_available = 1 ORDER BY name");
    $st->execute();
    $locations = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $locations = []; }

include __DIR__.'/includes/site_header.php';
?>

<section class="page-hero">
  <div class="container">
    <p class="crumbs"><a href="index.php">Home</a> &rsaquo; Monthly Parking</p>
    <h1>Monthly Parking in Metro Detroit</h1>
    <p>Reserved monthly parking for individuals and companies — managed by a Detroit team with 20+ years of experience.</p>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="grid grid-2" style="margin-bottom:3.5rem">
      <div class="card reveal">
        <h3>For Individuals</h3>
        <p>Commuters, downtown workers, and residents: secure a reserved monthly spot at one of our managed locations. Register your vehicle, get your permit, and park with confidence every day.</p>
        <ul class="check-list">
          <li>Reserved space at a managed location</li>
          <li>Vehicle registration and permit included</li>
          <li>Professional on-site management</li>
        </ul>
        <a class="card-link" href="request-monthly.php">Request as an individual &rarr;</a>
      </div>
      <div class="card reveal">
        <h3>For Companies</h3>
        <p>Employers and fleet operators: reserve blocks of spaces for your team with consolidated billing, permit management for each vehicle, and a dedicated account contact.</p>
        <ul class="check-list">
          <li>Multiple spaces under one account</li>
          <li>Consolidated monthly billing</li>
          <li>Easy vehicle additions and changes</li>
        </ul>
        <a class="card-link" href="request-monthly.php">Request as a company &rarr;</a>
      </div>
    </div>

    <div class="center reveal" style="max-width:46rem;margin:0 auto">
      <span class="eyebrow">How It Works</span>
      <h2>Three Simple Steps</h2>
      <hr class="gold-rule">
    </div>
    <div class="steps">
      <div class="step reveal">
        <h3>1. Request</h3>
        <p>Submit the online monthly parking request — choose your location, tell us about your vehicle (or your company's needs), and pick a start date.</p>
      </div>
      <div class="step reveal">
        <h3>2. Review</h3>
        <p>Our team reviews your request, confirms availability at your chosen location, and reaches out with next steps and rate information.</p>
      </div>
      <div class="step reveal">
        <h3>3. Approval &amp; Payment</h3>
        <p>Once approved, complete payment to activate your subscription. Your vehicle is registered, your permit is issued, and you're ready to park.</p>
      </div>
    </div>
  </div>
</section>

<section class="section section--mist">
  <div class="container">
    <div class="center reveal" style="max-width:46rem;margin:0 auto 3rem">
      <span class="eyebrow">Availability</span>
      <h2>Locations Offering Monthly Parking</h2>
      <hr class="gold-rule">
      <p class="lead">Monthly parking is available at the following managed locations. Rates vary by location — contact us for current rates.</p>
    </div>
    <?php if (empty($locations)): ?>
      <div class="empty-state">
        <h3>Availability updating</h3>
        <p>Our monthly availability list is being updated. Please call <a href="tel:+18554426500">(855)-442-6500</a> or <a href="contact.php">send us a message</a> for current monthly parking options.</p>
      </div>
    <?php else: ?>
      <div class="grid grid-3">
        <?php foreach ($locations as $loc):
          $addr = trim(($loc['address'] ?? '') . ', ' . ($loc['city'] ?? ''), ', ');
        ?>
        <div class="card reveal">
          <span class="badge badge--gold"><span class="dot"></span>Monthly Available</span>
          <h3><?= e($loc['name'] ?? 'Parking Location') ?></h3>
          <?php if ($addr !== ''): ?><p class="loc-meta"><?= e($addr) ?></p><?php endif; ?>
          <?php if (!empty($loc['type'])): ?><p class="loc-meta">Type: <?= e(ucfirst((string)$loc['type'])) ?></p><?php endif; ?>
          <p class="loc-meta"><strong>Rate:</strong> Contact for rates</p>
          <a class="card-link" href="location.php?id=<?= e((string)$loc['id']) ?>">View location &rarr;</a>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div class="center" style="margin-top:3rem">
      <a class="btn btn-gold" href="request-monthly.php">Request Monthly Parking</a>
    </div>
  </div>
</section>

<section class="section">
  <div class="container" style="max-width:760px">
    <div class="center reveal" style="margin-bottom:1rem">
      <span class="eyebrow">FAQ</span>
      <h2>Monthly Parking Questions</h2>
      <hr class="gold-rule">
    </div>
    <div class="faq reveal">
      <div class="faq-item">
        <button class="faq-q" aria-expanded="false">How do I request monthly parking?<span class="plus">+</span></button>
        <div class="faq-a"><p>Fill out the online request form with your details, preferred location, and vehicle information. Our team reviews it, confirms availability, and contacts you about approval and payment.</p></div>
      </div>
      <div class="faq-item">
        <button class="faq-q" aria-expanded="false">How much does monthly parking cost?<span class="plus">+</span></button>
        <div class="faq-a"><p>Rates vary by location. We don't publish fixed prices because availability and rates change — contact us at (855)-442-6500 for current rates at the location you're interested in.</p></div>
      </div>
      <div class="faq-item">
        <button class="faq-q" aria-expanded="false">Can my company reserve multiple spaces?<span class="plus">+</span></button>
        <div class="faq-a"><p>Yes. Companies can request any number of spaces under a single account with consolidated billing. Choose "Company" on the request form and tell us how many spaces you need.</p></div>
      </div>
      <div class="faq-item">
        <button class="faq-q" aria-expanded="false">What happens after I'm approved?<span class="plus">+</span></button>
        <div class="faq-a"><p>After approval and payment, your subscription is activated: your vehicle is registered to the location, a parking permit is issued, and you can access your customer portal to manage vehicles and invoices.</p></div>
      </div>
    </div>
    <div class="center" style="margin-top:2.5rem">
      <a class="btn btn-navy" href="request-monthly.php">Start Your Request</a>
    </div>
  </div>
</section>

<?php include __DIR__.'/includes/site_footer.php'; ?>
