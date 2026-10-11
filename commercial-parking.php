<?php
require __DIR__.'/lib/bootstrap.php';
$page_title = 'Commercial Parking Management';
$page_desc = 'Full parking management for commercial buildings, lots, and garages across Metro Detroit — staffing, enforcement, and revenue control by Metro Valet Parking.';
$page_key = 'services';
include __DIR__.'/includes/site_header.php';
?>

<section class="page-hero">
  <div class="container">
    <p class="crumbs"><a href="index.php">Home</a> &rsaquo; <a href="services.php">Services</a> &rsaquo; Commercial Parking</p>
    <h1>Commercial Parking Management</h1>
    <p>Complete management of parking lots and garages for commercial properties — staffed, enforced, and maintained to professional standards.</p>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="split">
      <div class="reveal">
        <span class="eyebrow">Commercial Properties</span>
        <h2>Your Facility, Fully Managed</h2>
        <hr class="gold-rule">
        <p>Whether you operate a surface lot or a multi-level garage, Metro Valet Parking takes on the day-to-day: staffing, access control, enforcement, signage, and upkeep coordination. You get a professionally run facility and clear reporting — without building an in-house parking team.</p>
        <ul class="check-list">
          <li>Attendant staffing and shift supervision</li>
          <li>Access control and permit enforcement</li>
          <li>Signage, striping, and facility upkeep coordination</li>
          <li>Revenue control and transparent reporting</li>
          <li>Monthly parker programs for building tenants</li>
        </ul>
      </div>
      <div class="split-img reveal">
        <img src="assets/img/valet-service.jpg" alt="Managed commercial parking operation" loading="lazy" onerror="this.remove()">
      </div>
    </div>
  </div>
</section>

<section class="section section--mist">
  <div class="container">
    <div class="center reveal" style="max-width:46rem;margin:0 auto">
      <span class="eyebrow">Process</span>
      <h2>How Commercial Management Works</h2>
      <hr class="gold-rule">
    </div>
    <div class="steps">
      <div class="step reveal">
        <h3>1. Site Review</h3>
        <p>We assess your facility — layout, traffic flow, current pain points — and propose a management plan covering staffing, enforcement, and equipment needs.</p>
      </div>
      <div class="step reveal">
        <h3>2. Transition</h3>
        <p>We onboard staff, install signage and procedures, and transition operations with minimal disruption to your tenants and visitors.</p>
      </div>
      <div class="step reveal">
        <h3>3. Steady Operation</h3>
        <p>Ongoing staffing, supervision, and reporting keep your facility running cleanly — with a dedicated manager you can reach directly.</p>
      </div>
    </div>
  </div>
</section>

<section class="cta-band">
  <div class="container reveal">
    <h2>Let's Talk About Your Facility</h2>
    <p>Send us the details of your lot or garage and we'll respond with a management proposal tailored to your property.</p>
    <div class="hero-ctas">
      <a class="btn btn-gold" href="request-quote.php">Request a Quote</a>
      <a class="btn btn-outline" href="tel:+18554426500">Call (855)-442-6500</a>
    </div>
  </div>
</section>

<?php include __DIR__.'/includes/site_footer.php'; ?>
