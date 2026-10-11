<?php
require __DIR__.'/lib/bootstrap.php';
$page_title = 'Our Services';
$page_desc = 'Metro Valet Parking services: hotel valet, commercial parking management, corporate parking programs, event valet, and monthly parking across Metro Detroit.';
$page_key = 'services';
include __DIR__.'/includes/site_header.php';
?>

<section class="page-hero">
  <div class="container">
    <p class="crumbs"><a href="index.php">Home</a> &rsaquo; Services</p>
    <h1>Our Services</h1>
    <p>Professional valet and parking management across Metro Detroit — backed by 20+ years of experience.</p>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="grid grid-2">
      <div class="card card--service reveal">
        <span class="badge badge--gold"><span class="dot"></span>Flagship</span>
        <h3>Hotel Valet</h3>
        <p>Complete front-door valet operations for hotels: guest arrivals and departures, vehicle handling and key management, luggage assistance coordination, and late-night coverage — all delivered by uniformed, trained attendants.</p>
        <a class="card-link" href="hotel-valet.php">Explore hotel valet &rarr;</a>
      </div>
      <div class="card card--service reveal">
        <span class="badge"><span class="dot"></span>Management</span>
        <h3>Commercial Parking</h3>
        <p>Full management of parking lots and garages for commercial properties: staffing, enforcement, signage, maintenance coordination, and revenue control — handled to professional standards every day.</p>
        <a class="card-link" href="commercial-parking.php">Explore commercial parking &rarr;</a>
      </div>
      <div class="card card--service reveal">
        <span class="badge"><span class="dot"></span>Business</span>
        <h3>Corporate Parking</h3>
        <p>Monthly parking programs for companies: reserved spaces for employees and fleets, consolidated billing, permit management, and a dedicated account contact on our Detroit team.</p>
        <a class="card-link" href="corporate-parking.php">Explore corporate parking &rarr;</a>
      </div>
      <div class="card card--service reveal">
        <span class="badge"><span class="dot"></span>Events</span>
        <h3>Event Valet</h3>
        <p>Scalable valet teams for weddings, corporate events, galas, and private occasions — from intimate gatherings to large guest lists, anywhere in Metro Detroit.</p>
        <a class="card-link" href="event-valet.php">Explore event valet &rarr;</a>
      </div>
    </div>
  </div>
</section>

<section class="section section--mist">
  <div class="container">
    <div class="center reveal" style="max-width:46rem;margin:0 auto 2.5rem">
      <span class="eyebrow">For Drivers</span>
      <h2>Monthly Parking</h2>
      <hr class="gold-rule">
      <p class="lead">Looking for a parking spot of your own? We offer monthly parking for individuals and companies at managed locations across Metro Detroit.</p>
    </div>
    <div class="center">
      <a class="btn btn-gold" href="monthly-parking.php">Find Monthly Parking</a>
    </div>
  </div>
</section>

<section class="cta-band">
  <div class="container reveal">
    <h2>Not Sure Which Service Fits?</h2>
    <p>Tell us about your property or event and we'll recommend the right approach — no obligation.</p>
    <div class="hero-ctas">
      <a class="btn btn-gold" href="request-quote.php">Request a Quote</a>
      <a class="btn btn-outline" href="tel:+18554426500">Call (855)-442-6500</a>
    </div>
  </div>
</section>

<?php include __DIR__.'/includes/site_footer.php'; ?>
