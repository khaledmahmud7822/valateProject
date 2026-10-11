<?php
require __DIR__.'/lib/bootstrap.php';
$page_title = 'About Us';
$page_desc = 'Metro Valet Parking is a Detroit-based parking management company with over 20 years of experience in valet, parking management, and monthly parking. Our Experience Is Your Advantage.';
$page_key = 'about';
include __DIR__.'/includes/site_header.php';
?>

<section class="page-hero">
  <div class="container">
    <p class="crumbs"><a href="index.php">Home</a> &rsaquo; About</p>
    <h1>About Metro Valet Parking</h1>
    <p>Our Experience Is Your Advantage.</p>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="split">
      <div class="reveal">
        <span class="eyebrow">Our Story</span>
        <h2>20+ Years of Detroit Parking Experience</h2>
        <hr class="gold-rule">
        <div class="prose">
          <p>Metro Valet Parking is a Detroit-based parking management company with more than 20 years of experience operating valet services, managing commercial parking facilities, and providing monthly parking across Metro Detroit.</p>
          <p>We work with hotels, restaurants, commercial buildings, and event venues — as well as individual drivers and corporate fleets — delivering professionally staffed, carefully managed parking operations. Every account is overseen by our Detroit management team, because we believe great parking service comes down to experience, training, and accountability.</p>
          <p>That belief is our tagline and our standard: <strong>Our Experience Is Your Advantage.</strong></p>
        </div>
      </div>
      <div class="split-img reveal">
        <img src="assets/img/hero-detroit.jpg" alt="Downtown Detroit skyline at dusk" loading="lazy" onerror="this.remove()">
      </div>
    </div>
  </div>
</section>

<section class="section section--mist">
  <div class="container">
    <div class="center reveal" style="max-width:46rem;margin:0 auto 3rem">
      <span class="eyebrow">What We Stand For</span>
      <h2>Our Values</h2>
      <hr class="gold-rule">
    </div>
    <div class="grid grid-3">
      <div class="card reveal">
        <h3>Professionalism</h3>
        <p>Uniformed, trained attendants and managers who treat every vehicle — and every guest — with care and respect.</p>
      </div>
      <div class="card reveal">
        <h3>Reliability</h3>
        <p>Staffed operations, consistent oversight, and responsive communication. When we commit to your property, we show up.</p>
      </div>
      <div class="card reveal">
        <h3>Local Accountability</h3>
        <p>We're based in Detroit and we manage here. Our clients deal directly with the team responsible for their operation — no distant call centers.</p>
      </div>
    </div>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="center reveal" style="max-width:46rem;margin:0 auto 3rem">
      <span class="eyebrow">What We Offer</span>
      <h2>Services Built on Experience</h2>
      <hr class="gold-rule">
    </div>
    <div class="grid grid-4">
      <div class="card reveal">
        <h3>Hotel Valet</h3>
        <p>Full-service valet operations for hotels across Metro Detroit.</p>
        <a class="card-link" href="hotel-valet.php">Learn more &rarr;</a>
      </div>
      <div class="card reveal">
        <h3>Commercial Parking</h3>
        <p>Management for lots, garages, and commercial properties.</p>
        <a class="card-link" href="commercial-parking.php">Learn more &rarr;</a>
      </div>
      <div class="card reveal">
        <h3>Corporate Parking</h3>
        <p>Monthly parking programs for companies and their teams.</p>
        <a class="card-link" href="corporate-parking.php">Learn more &rarr;</a>
      </div>
      <div class="card reveal">
        <h3>Event Valet</h3>
        <p>Scalable valet teams for weddings and special events.</p>
        <a class="card-link" href="event-valet.php">Learn more &rarr;</a>
      </div>
    </div>
  </div>
</section>

<section class="cta-band">
  <div class="container reveal">
    <h2>Work With an Experienced Team</h2>
    <p>Whether you manage a property or need a monthly parking spot, talk to the Detroit team with 20+ years in the business.</p>
    <div class="hero-ctas">
      <a class="btn btn-gold" href="request-quote.php">Request a Quote</a>
      <a class="btn btn-outline" href="contact.php">Contact Us</a>
    </div>
  </div>
</section>

<?php include __DIR__.'/includes/site_footer.php'; ?>
