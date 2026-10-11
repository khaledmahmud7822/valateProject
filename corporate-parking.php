<?php
require __DIR__.'/lib/bootstrap.php';
$page_title = 'Corporate Parking Programs';
$page_desc = 'Monthly corporate parking programs across Metro Detroit — reserved spaces for employees and fleets, consolidated billing, and dedicated account management from Metro Valet Parking.';
$page_key = 'services';
include __DIR__.'/includes/site_header.php';
?>

<section class="page-hero">
  <div class="container">
    <p class="crumbs"><a href="index.php">Home</a> &rsaquo; <a href="services.php">Services</a> &rsaquo; Corporate Parking</p>
    <h1>Corporate Parking Programs</h1>
    <p>Reserved monthly parking for your team — multiple spaces under one account, consolidated billing, and a dedicated contact on our Detroit team.</p>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="split">
      <div class="reveal">
        <span class="eyebrow">For Employers</span>
        <h2>Parking That Works for Your Whole Team</h2>
        <hr class="gold-rule">
        <p>Give your employees a reliable place to park. Metro Valet Parking sets up corporate accounts with reserved spaces at managed locations across Metro Detroit — one agreement, one monthly invoice, and simple vehicle management as your team changes.</p>
        <ul class="check-list">
          <li>Reserved blocks of spaces for employees or fleets</li>
          <li>One account with consolidated monthly billing</li>
          <li>Permit management for each registered vehicle</li>
          <li>Easy additions, removals, and vehicle changes</li>
          <li>Dedicated account manager in Detroit</li>
        </ul>
      </div>
      <div class="split-img reveal">
        <img src="assets/img/valet-service.jpg" alt="Corporate monthly parking at a managed facility" loading="lazy" onerror="this.remove()">
      </div>
    </div>
  </div>
</section>

<section class="section section--mist">
  <div class="container">
    <div class="center reveal" style="max-width:46rem;margin:0 auto">
      <span class="eyebrow">Process</span>
      <h2>Setting Up a Corporate Account</h2>
      <hr class="gold-rule">
    </div>
    <div class="steps">
      <div class="step reveal">
        <h3>1. Request</h3>
        <p>Submit a company request with your location preference, number of spaces, and billing contact. We'll confirm availability and rates.</p>
      </div>
      <div class="step reveal">
        <h3>2. Agreement</h3>
        <p>We finalize the corporate account — spaces, vehicles, and billing terms — and issue permits for each registered vehicle.</p>
      </div>
      <div class="step reveal">
        <h3>3. Manage</h3>
        <p>Your team parks, you receive one consolidated invoice each cycle, and changes are a phone call or message away.</p>
      </div>
    </div>
    <div class="center" style="margin-top:2.6rem">
      <a class="btn btn-gold" href="request-monthly.php">Request Corporate Parking</a>
    </div>
  </div>
</section>

<section class="cta-band">
  <div class="container reveal">
    <h2>Need Valet for Your Building Too?</h2>
    <p>Many corporate clients pair monthly parking with front-door valet service. Ask us about a combined program for your property.</p>
    <div class="hero-ctas">
      <a class="btn btn-gold" href="request-quote.php">Request a Quote</a>
      <a class="btn btn-outline" href="tel:+18554426500">Call (855)-442-6500</a>
    </div>
  </div>
</section>

<?php include __DIR__.'/includes/site_footer.php'; ?>
