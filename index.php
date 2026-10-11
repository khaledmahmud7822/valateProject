<?php
require __DIR__.'/lib/bootstrap.php';
$page_title = 'Detroit Valet & Parking Management';
$page_desc = 'Over 20 years of professional valet, parking management, and monthly parking solutions across Metro Detroit. Our Experience Is Your Advantage.';
$page_key = 'home';

// Published locations for the map/list section
$locations = [];
try {
    $st = db()->prepare("SELECT id, name, address, city, type, monthly_available FROM parking_locations WHERE is_published = 1 ORDER BY name");
    $st->execute();
    $locations = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $locations = []; }

include __DIR__.'/includes/site_header.php';
?>

<section class="hero hero--luxury">
  <div class="container hero-inner reveal">
    <h1>Detroit Parking. Elevated.</h1>
    <p class="sub">Over 20 Years of Professional Valet, Parking Management, and Monthly Parking Solutions Across Metro Detroit.</p>
    <div class="hero-ctas">
      <a class="btn btn-gold" href="monthly-parking.php">Find Monthly Parking</a>
      <a class="btn btn-outline" href="request-quote.php">Request Valet Services</a>
    </div>
    <p class="hero-note">Talk to our team: <a href="tel:+18554426500" style="color:#fff;font-weight:700">(855)-442-6500</a></p>
  </div>
</section>

<div class="trust-strip">
  <div class="container">
    <div class="trust-item"><span class="tick">&#10003;</span> 20+ Years Serving Detroit</div>
    <div class="trust-item"><span class="tick">&#10003;</span> Detroit-Based Management Team</div>
    <div class="trust-item"><span class="tick">&#10003;</span> Professional Valet Attendants</div>
    <div class="trust-item"><span class="tick">&#10003;</span> Dedicated Account Management</div>
  </div>
</div>

<section class="section">
  <div class="container">
    <div class="center reveal" style="max-width:46rem;margin:0 auto 3rem">
      <span class="eyebrow">What We Do</span>
      <h2>Our Parking Solutions</h2>
      <hr class="gold-rule">
      <p class="lead">From daily commuters to hotel guests to corporate fleets, Metro Valet Parking delivers professionally managed parking across Metro Detroit.</p>
    </div>
    <div class="grid grid-4">
      <div class="card reveal">
        <div class="icon" aria-hidden="true">&#128663;</div>
        <h3>Monthly Parking</h3>
        <p>Reserved monthly parking for individuals and companies at managed locations across Metro Detroit.</p>
        <a class="card-link" href="monthly-parking.php">Explore monthly parking &rarr;</a>
      </div>
      <div class="card reveal">
        <div class="icon" aria-hidden="true">&#127976;</div>
        <h3>Hotel Valet</h3>
        <p>Full-service valet operations for hotels — arrivals, departures, and guest vehicle care handled with professionalism.</p>
        <a class="card-link" href="hotel-valet.php">Hotel valet services &rarr;</a>
      </div>
      <div class="card reveal">
        <div class="icon" aria-hidden="true">&#127970;</div>
        <h3>Commercial Management</h3>
        <p>Complete parking management for commercial buildings, lots, and garages — staffing, enforcement, and revenue control.</p>
        <a class="card-link" href="commercial-parking.php">Commercial parking &rarr;</a>
      </div>
      <div class="card reveal">
        <div class="icon" aria-hidden="true">&#127881;</div>
        <h3>Event Valet</h3>
        <p>Scalable valet teams for weddings, corporate events, and special occasions throughout Metro Detroit.</p>
        <a class="card-link" href="event-valet.php">Event valet services &rarr;</a>
      </div>
    </div>
  </div>
</section>

<section class="section section--mist">
  <div class="container">
    <div class="split">
      <div class="reveal">
        <span class="eyebrow">Locations</span>
        <h2>Find Your Parking Location</h2>
        <hr class="gold-rule">
        <p>Search our managed parking locations across Metro Detroit — garages, lots, and hotel facilities. Filter by type and see which locations offer monthly parking.</p>
        <form action="locations.php" method="get" role="search" style="margin-top:1.6rem">
          <div class="filter-bar" style="margin-bottom:0">
            <input type="search" name="q" placeholder="Search by name or area&hellip;" aria-label="Search locations by name or area">
            <button class="btn btn-navy" type="submit">Search Locations</button>
          </div>
        </form>
      </div>
      <div class="map-panel reveal" aria-hidden="true">
        <div class="pin">&#128205;</div>
        <h3>Serving Metro Detroit</h3>
        <p>Our managed locations span the greater Detroit area. Select any location for address details, services, hours, and directions.</p>
      </div>
    </div>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="split">
      <div class="split-img reveal">
        <img src="assets/img/valet-service.jpg" alt="Metro Valet Parking attendant providing valet service" loading="lazy" onerror="this.remove()">
      </div>
      <div class="reveal">
        <span class="eyebrow">Monthly Parking</span>
        <h2>Monthly Parking for Individuals &amp; Companies</h2>
        <hr class="gold-rule">
        <p>Need a dependable parking spot near work, home, or downtown? Metro Valet Parking offers monthly parking for individual drivers and corporate accounts — with reserved spaces, managed facilities, and straightforward billing.</p>
        <ul class="check-list">
          <li>Reserved monthly spaces at managed locations</li>
          <li>Corporate accounts with consolidated billing</li>
          <li>Professional on-site attendants and management</li>
          <li>Simple online request — approval and payment follow</li>
        </ul>
        <div class="hero-ctas" style="margin-top:1.8rem">
          <a class="btn btn-gold" href="request-monthly.php">Request Monthly Parking</a>
          <a class="btn btn-ghost" href="monthly-parking.php">How It Works</a>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="section section--navy">
  <div class="container">
    <div class="center reveal" style="max-width:48rem;margin:0 auto 3rem">
      <span class="eyebrow">Partnerships</span>
      <h2>Hotel &amp; Commercial Valet Partnerships</h2>
      <hr class="gold-rule">
      <p class="lead">Hotels, restaurants, commercial buildings, and event venues across Metro Detroit trust Metro Valet Parking to run their front-door experience. With more than 20 years in the business, we staff, train, and manage every detail of your valet or parking operation.</p>
    </div>
    <div class="grid grid-3">
      <div class="card card--service reveal">
        <h3>Full-Service Valet Operations</h3>
        <p>Uniformed attendants, guest greeting, vehicle handling, and key management — a seamless arrival experience for your guests and tenants.</p>
      </div>
      <div class="card card--service reveal">
        <h3>Parking Management</h3>
        <p>Staffing, enforcement, signage, and revenue control for lots and garages — managed to professional standards, every day.</p>
      </div>
      <div class="card card--service reveal">
        <h3>Dedicated Management</h3>
        <p>A single point of contact, regular reporting, and a team that treats your property like our own. Our experience is your advantage.</p>
      </div>
    </div>
    <div class="center" style="margin-top:2.6rem">
      <a class="btn btn-gold" href="request-quote.php">Request Valet Services</a>
    </div>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="center reveal" style="max-width:46rem;margin:0 auto 3rem">
      <span class="eyebrow">Why Metro Valet</span>
      <h2>Why Choose Metro Valet Parking</h2>
      <hr class="gold-rule">
    </div>
    <div class="grid grid-4">
      <div class="card reveal">
        <h3>20+ Years of Experience</h3>
        <p>More than two decades operating valet and parking services — experience that shows in every detail of our operations.</p>
      </div>
      <div class="card reveal">
        <h3>Detroit-Focused</h3>
        <p>A Detroit-based company serving Metro Detroit. We know the market, the venues, and the neighborhoods we park in.</p>
      </div>
      <div class="card reveal">
        <h3>Professional Attendants</h3>
        <p>Trained, uniformed attendants who represent your property with courtesy and care at every arrival and departure.</p>
      </div>
      <div class="card reveal">
        <h3>Dedicated Management</h3>
        <p>Every account gets hands-on management — staffing, oversight, and responsive communication from our Detroit team.</p>
      </div>
    </div>
  </div>
</section>

<section class="section section--mist" id="map">
  <div class="container">
    <div class="center reveal" style="max-width:46rem;margin:0 auto 3rem">
      <span class="eyebrow">Where We Operate</span>
      <h2>Parking Location Map</h2>
      <hr class="gold-rule">
      <p class="lead">Browse our published parking locations below. Select a location for full details, or get driving directions instantly.</p>
    </div>
    <?php if (empty($locations)): ?>
      <div class="empty-state">
        <h3>No locations published yet</h3>
        <p>Our location directory is being updated. Please call <a href="tel:+18554426500">(855)-442-6500</a> for current parking availability.</p>
      </div>
    <?php else: ?>
      <div class="grid grid-2">
        <?php foreach ($locations as $loc):
          $addr = trim(($loc['address'] ?? '') . ', ' . ($loc['city'] ?? ''), ', ');
          $dirUrl = 'https://www.google.com/maps/search/?api=1&query=' . urlencode($addr !== '' ? $addr : ($loc['name'] ?? ''));
        ?>
        <div class="card location-card reveal">
          <div class="loc-body">
            <h3><?= e($loc['name'] ?? 'Parking Location') ?></h3>
            <?php if ($addr !== ''): ?><p class="loc-meta"><?= e($addr) ?></p><?php endif; ?>
            <div style="margin-bottom:.4rem">
              <?php if (!empty($loc['monthly_available'])): ?><span class="badge badge--gold"><span class="dot"></span>Monthly Parking</span><?php endif; ?>
              <?php if (!empty($loc['type'])): ?><span class="badge"><span class="dot"></span><?= e(ucfirst((string)$loc['type'])) ?></span><?php endif; ?>
            </div>
            <div class="loc-actions">
              <a class="btn btn-navy" style="padding:.6rem 1.2rem;font-size:.88rem" href="location.php?id=<?= e((string)$loc['id']) ?>">View Details</a>
              <a class="btn btn-ghost" style="padding:.6rem 1.2rem;font-size:.88rem" href="<?= e($dirUrl) ?>" target="_blank" rel="noopener">Get Directions</a>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="section section--mist">
  <div class="container">
    <div class="split">
      <div class="reveal">
        <span class="eyebrow">Careers</span>
        <h2>Join Our Team</h2>
        <hr class="gold-rule">
        <p>Drive your career forward with Metro Valet Parking. We're hiring valet attendants, parking lot attendants, cashiers, and supervisors across Metro Detroit — professional people who take pride in great service.</p>
        <ul class="check-list">
          <li>Stable work with an established Detroit company</li>
          <li>Growth opportunities — many supervisors started as attendants</li>
          <li>Professional, uniformed teams at premier locations</li>
        </ul>
        <div class="hero-ctas" style="margin-top:1.8rem">
          <a class="btn btn-gold" href="careers.php#apply">Apply Now</a>
          <a class="btn btn-ghost" href="careers.php#openings">View Open Positions</a>
        </div>
      </div>
      <div class="split-img reveal">
        <img src="assets/img/valet-service.jpg" alt="Metro Valet Parking team member providing professional valet service" loading="lazy" onerror="this.remove()">
      </div>
    </div>
  </div>
</section>

<section class="cta-band">
  <div class="container reveal">
    <h2>Partner With Us</h2>
    <p>Hotels, restaurants, commercial properties, and event venues: let's talk about how Metro Valet Parking can elevate your guest experience. Tell us about your property and we'll respond with a tailored proposal.</p>
    <div class="hero-ctas">
      <a class="btn btn-gold" href="request-quote.php">Request a Quote</a>
      <a class="btn btn-outline" href="contact.php">Contact Us</a>
    </div>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="center reveal" style="max-width:44rem;margin:0 auto">
      <span class="eyebrow">Get In Touch</span>
      <h2>Contact / Request a Quote</h2>
      <hr class="gold-rule">
      <p class="lead" style="margin-bottom:2rem">Whether you need monthly parking, valet services for your business, or have a question about a location — reach out and our Detroit team will respond.</p>
      <div class="hero-ctas" style="justify-content:center">
        <a class="btn btn-gold" href="request-quote.php">Request Valet Services</a>
        <a class="btn btn-navy" href="contact.php">Send a Message</a>
        <a class="btn btn-ghost" href="tel:+18554426500">Call (855)-442-6500</a>
      </div>
    </div>
  </div>
</section>

<?php include __DIR__.'/includes/site_footer.php'; ?>
