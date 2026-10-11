<?php
require __DIR__.'/lib/bootstrap.php';
$page_title = 'Hotel Valet Services';
$page_desc = 'Full-service hotel valet operations across Metro Detroit by Metro Valet Parking — guest arrivals, vehicle handling, and professional front-door service backed by 20+ years of experience.';
$page_key = 'services';
include __DIR__.'/includes/site_header.php';
?>

<section class="page-hero">
  <div class="container">
    <p class="crumbs"><a href="index.php">Home</a> &rsaquo; <a href="services.php">Services</a> &rsaquo; Hotel Valet</p>
    <h1>Hotel Valet Services</h1>
    <p>A polished front-door experience for your guests — arrivals, departures, and vehicle care handled by trained, uniformed professionals.</p>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="split">
      <div class="reveal">
        <span class="eyebrow">Hotels</span>
        <h2>Your Front Door, Professionally Run</h2>
        <hr class="gold-rule">
        <p>The valet stand is the first and last impression your guests get. Metro Valet Parking staffs and manages complete hotel valet operations — greeting guests by name, handling vehicles with care, and keeping arrivals moving smoothly even at peak times.</p>
        <ul class="check-list">
          <li>Guest greeting, arrivals, and departures</li>
          <li>Secure vehicle handling and key management</li>
          <li>Coordination with front desk and bell staff</li>
          <li>Coverage for peak hours, weekends, and events</li>
          <li>Uniformed, trained attendants and on-site supervision</li>
        </ul>
      </div>
      <div class="split-img reveal">
        <img src="assets/img/valet-service.jpg" alt="Valet attendant assisting hotel guests" loading="lazy" onerror="this.remove()">
      </div>
    </div>
  </div>
</section>

<section class="section section--mist">
  <div class="container">
    <div class="center reveal" style="max-width:46rem;margin:0 auto">
      <span class="eyebrow">Process</span>
      <h2>How a Hotel Valet Partnership Works</h2>
      <hr class="gold-rule">
    </div>
    <div class="steps">
      <div class="step reveal">
        <h3>1. Assessment</h3>
        <p>We walk your property, review arrival patterns and porte-cochère logistics, and design a staffing plan around your occupancy and peak periods.</p>
      </div>
      <div class="step reveal">
        <h3>2. Launch</h3>
        <p>Our team recruits and trains attendants for your property, sets up key control and ticketing procedures, and launches the operation with on-site management.</p>
      </div>
      <div class="step reveal">
        <h3>3. Ongoing Management</h3>
        <p>Dedicated supervision, regular reporting, and responsive communication keep the operation running smoothly — season after season.</p>
      </div>
    </div>
  </div>
</section>

<section class="cta-band">
  <div class="container reveal">
    <h2>Elevate Your Guest Arrival Experience</h2>
    <p>Tell us about your hotel and we'll prepare a tailored valet proposal — staffing plan, coverage options, and transparent terms.</p>
    <div class="hero-ctas">
      <a class="btn btn-gold" href="request-quote.php">Request a Quote</a>
      <a class="btn btn-outline" href="tel:+18554426500">Call (855)-442-6500</a>
    </div>
  </div>
</section>

<?php include __DIR__.'/includes/site_footer.php'; ?>
