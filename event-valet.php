<?php
require __DIR__.'/lib/bootstrap.php';
$page_title = 'Event Valet Services';
$page_desc = 'Scalable event valet teams across Metro Detroit — weddings, corporate events, galas, and private occasions staffed by Metro Valet Parking professionals.';
$page_key = 'services';
include __DIR__.'/includes/site_header.php';
?>

<section class="page-hero">
  <div class="container">
    <p class="crumbs"><a href="index.php">Home</a> &rsaquo; <a href="services.php">Services</a> &rsaquo; Event Valet</p>
    <h1>Event Valet Services</h1>
    <p>From intimate weddings to large corporate events — professional valet teams that scale to your guest list, anywhere in Metro Detroit.</p>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="split">
      <div class="reveal">
        <span class="eyebrow">Events</span>
        <h2>Arrivals Your Guests Will Remember</h2>
        <hr class="gold-rule">
        <p>Your event starts at the curb. Metro Valet Parking provides trained, uniformed valet teams for weddings, galas, fundraisers, corporate functions, and private occasions — sized to your guest count and venue layout, and managed on-site from first arrival to last departure.</p>
        <ul class="check-list">
          <li>Teams sized to your guest count and venue</li>
          <li>Weddings, corporate events, galas, private parties</li>
          <li>On-site event captain for the full evening</li>
          <li>Ticketing, key control, and vehicle staging</li>
          <li>Coordination with your venue and planner</li>
        </ul>
      </div>
      <div class="split-img reveal">
        <img src="assets/img/valet-service.jpg" alt="Event valet service in Metro Detroit" loading="lazy" onerror="this.remove()">
      </div>
    </div>
  </div>
</section>

<section class="section section--mist">
  <div class="container">
    <div class="center reveal" style="max-width:46rem;margin:0 auto">
      <span class="eyebrow">Process</span>
      <h2>Booking Event Valet</h2>
      <hr class="gold-rule">
    </div>
    <div class="steps">
      <div class="step reveal">
        <h3>1. Tell Us About Your Event</h3>
        <p>Share your date, venue, and expected guest count through our quote form. The earlier you book, the better we can staff your date.</p>
      </div>
      <div class="step reveal">
        <h3>2. Plan</h3>
        <p>We confirm the venue logistics — drop-off zones, parking areas, timing — and assign a team sized to your event, led by an on-site captain.</p>
      </div>
      <div class="step reveal">
        <h3>3. Event Night</h3>
        <p>Our team arrives early, sets up ticketing and signage, and runs arrivals and departures so you can focus on your guests.</p>
      </div>
    </div>
  </div>
</section>

<section class="cta-band">
  <div class="container reveal">
    <h2>Planning an Event?</h2>
    <p>Request a quote with your event details and we'll respond with availability and a tailored plan.</p>
    <div class="hero-ctas">
      <a class="btn btn-gold" href="request-quote.php">Request a Quote</a>
      <a class="btn btn-outline" href="tel:+18554426500">Call (855)-442-6500</a>
    </div>
  </div>
</section>

<?php include __DIR__.'/includes/site_footer.php'; ?>
