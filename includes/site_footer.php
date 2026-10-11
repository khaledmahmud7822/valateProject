<?php // Shared public site footer. Closes <main> opened in site_header.php. ?>
</main>
<footer class="site-footer">
  <div class="container footer-grid">
    <div>
      <a class="brand" href="index.php" aria-label="Metro Valet Parking — home">
        <img src="assets/img/metro-valet-logo-orig.png" alt="Metro Valet Parking" class="brand-logo-orig" width="150" height="104">
      </a>
      <p class="footer-tag">Our Experience Is Your Advantage</p>
      <p style="font-size:.93rem;color:#aeb9c9">Professional valet, parking management, and monthly parking solutions across Metro Detroit — for over 20 years.</p>
    </div>
    <div>
      <h4>Quick Links</h4>
      <ul class="footer-links">
        <li><a href="index.php">Home</a></li>
        <li><a href="monthly-parking.php">Monthly Parking</a></li>
        <li><a href="locations.php">Locations</a></li>
        <li><a href="about.php">About Us</a></li>
        <li><a href="contact.php">Contact</a>
          <a href="careers.php">Careers</a></li>
        <li><a href="request-monthly.php">Request Monthly Parking</a></li>
      </ul>
    </div>
    <div>
      <h4>Services</h4>
      <ul class="footer-links">
        <li><a href="hotel-valet.php">Hotel Valet</a></li>
        <li><a href="commercial-parking.php">Commercial Parking</a></li>
        <li><a href="corporate-parking.php">Corporate Parking</a></li>
        <li><a href="event-valet.php">Event Valet</a></li>
        <li><a href="request-quote.php">Request a Quote</a></li>
      </ul>
    </div>
    <div class="footer-contact">
      <h4>Contact</h4>
      <p>Phone: <a href="tel:+18554426500">(855)-442-6500</a></p>
      <p>Detroit, Michigan</p>
      <p><a href="contact.php">Send us a message</a></p>
      <div class="social-links" aria-label="Follow us">
        <a href="https://www.facebook.com/metrovaletparking" target="_blank" rel="noopener" aria-label="Facebook" class="social-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M13.5 21v-7h2.4l.4-3h-2.8V9.1c0-.9.3-1.5 1.6-1.5h1.3V4.9c-.3 0-1.1-.1-2.1-.1-2.1 0-3.6 1.3-3.6 3.7V11H8.3v3h2.4v7h2.8z"/></svg></a>
        <a href="https://www.instagram.com/metrovaletparking" target="_blank" rel="noopener" aria-label="Instagram" class="social-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.2" cy="6.8" r="1.2" fill="currentColor" stroke="none"/></svg></a>
        <a href="https://www.linkedin.com/company/metro-valet-parking" target="_blank" rel="noopener" aria-label="LinkedIn" class="social-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M6.5 8.8v12H3V8.8h3.5zM4.7 3.5a2 2 0 1 1 0 4.1 2 2 0 0 1 0-4.1zM20.5 13.4v7.4h-3.5v-6.9c0-1.7-.6-2.9-2.2-2.9-1.2 0-1.9.8-2.2 1.6-.1.3-.1.7-.1 1.1v7.1H9V8.8h3.5v1.7c.5-.7 1.3-1.8 3.2-1.8 2.3 0 4.8 1.5 4.8 4.7z"/></svg></a>
        <a href="https://x.com/metrovalet" target="_blank" rel="noopener" aria-label="X" class="social-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M17.7 3H21l-7.1 8.1L22.2 21h-6.6l-5.1-6.1L4.6 21H1.3l7.6-8.7L1.8 3h6.7l4.6 5.6L17.7 3zm-1.2 16h1.8L7.1 4.9H5.2L16.5 19z"/></svg></a>
      </div>
    </div>
  </div>
  <div class="container footer-bottom">
    <span>&copy; <?= e(date('Y')) ?> Metro Valet Parking. All rights reserved.</span>
    <nav aria-label="Legal">
      <a href="privacy.php">Privacy Policy</a>
      <a href="terms.php">Terms of Service</a>
      <a href="customer-login.php">Customer Login</a>
    </nav>
  </div>
</footer>
<script src="assets/js/site.js"></script>
</body>
</html>
