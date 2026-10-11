<?php
require __DIR__.'/lib/bootstrap.php';
$page_title = 'Careers — Join Our Team';
$page_desc = 'Drive your career forward with Metro Valet Parking. View open valet, parking attendant, cashier, and supervisor positions across Metro Detroit and apply online.';
$page_key = 'careers';

// Open job postings.
$jobs = [];
try {
    $jobs = db()->query(
        "SELECT * FROM jobs WHERE status = 'open' ORDER BY sort_order ASC, created_at DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $jobs = []; }

include __DIR__.'/includes/site_header.php';

// JobPosting structured data — only for real open positions.
if ($jobs !== []):
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? '';
$jobLd = [];
foreach ($jobs as $j) {
    $jobLd[] = [
        '@type' => 'JobPosting',
        'title' => $j['title'],
        'description' => $j['description'],
        'employmentType' => strtoupper(str_replace('-', '_', $j['job_type'])),
        'hiringOrganization' => ['@type' => 'Organization', 'name' => 'Metro Valet Parking'],
        'jobLocation' => ['@type' => 'Place', 'address' => ['@type' => 'PostalAddress', 'addressLocality' => $j['location'] ?: 'Detroit', 'addressRegion' => 'MI']],
        'url' => $scheme . '://' . $host . '/apply.php?job=' . (int) $j['id'],
        'datePosted' => date('Y-m-d', strtotime($j['created_at'])),
    ];
}
?>
<script type="application/ld+json">
<?= json_encode(['@context' => 'https://schema.org', '@graph' => $jobLd], JSON_UNESCAPED_SLASHES) ?>
</script>
<?php endif; ?>

<section class="hero hero-careers">
  <div class="container hero-inner reveal">
    <span class="eyebrow" style="color:var(--gold-2)">Careers at Metro Valet</span>
    <h1>Drive Your Career Forward With Metro Valet Parking</h1>
    <p class="sub">Join a professional parking and valet management team serving hotels, businesses, events, and parking facilities throughout Metro Detroit.</p>
    <div class="hero-ctas">
      <a class="btn btn-gold" href="#apply">Apply for a Job</a>
      <a class="btn btn-outline" href="#openings">View Open Positions</a>
    </div>
  </div>
</section>

<section class="section" id="openings">
  <div class="container">
    <div class="center reveal" style="max-width:46rem;margin:0 auto 3rem">
      <span class="eyebrow">Open Positions</span>
      <h2>Available Positions</h2>
      <hr class="gold-rule">
      <p class="lead">We're looking for professional, customer-focused team members. Select a position to see details and apply.</p>
    </div>
    <?php if ($jobs === []): ?>
      <div class="card reveal" style="max-width:40rem;margin:0 auto;text-align:center">
        <h3>No open positions right now</h3>
        <p class="muted">We don't have active openings at the moment, but we'd still love to hear from you. Submit a general application below and we'll keep it on file.</p>
        <p><a class="btn btn-gold" href="#apply">Submit General Application</a></p>
      </div>
    <?php else: ?>
      <div class="grid grid-2">
        <?php foreach ($jobs as $j): ?>
        <article class="card job-card reveal">
          <div class="job-meta">
            <span class="badge badge-green">Open</span>
            <span class="muted small"><?= e(ucfirst(str_replace('-', ' ', $j['job_type']))) ?></span>
            <?php if ($j['location'] !== ''): ?><span class="muted small">&middot; <?= e($j['location']) ?></span><?php endif; ?>
          </div>
          <h3><?= e($j['title']) ?></h3>
          <?php if ($j['pay_range'] !== ''): ?><p class="job-pay"><?= e($j['pay_range']) ?></p><?php endif; ?>
          <div class="job-desc"><?= nl2br(e(mb_substr($j['description'], 0, 280))) ?><?= mb_strlen($j['description']) > 280 ? '…' : '' ?></div>
          <p><a class="btn btn-gold btn-sm" href="apply.php?job=<?= (int) $j['id'] ?>#apply">Apply Now</a></p>
        </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="section section-alt" id="apply">
  <div class="container">
    <div class="center reveal" style="max-width:46rem;margin:0 auto 2rem">
      <span class="eyebrow">Apply Online</span>
      <h2>Start Your Application</h2>
      <hr class="gold-rule">
      <p class="lead">Our application takes just a few minutes. You'll need your contact info, driver's license status, work history, and availability.</p>
      <p><a class="btn btn-gold btn-lg" href="apply.php#apply">Apply for a Job</a></p>
      <p class="muted small" style="margin-top:1rem">We review every application. If your qualifications match an opening, our hiring team will contact you.</p>
    </div>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="center reveal" style="max-width:52rem;margin:0 auto">
      <span class="eyebrow">Why Metro Valet</span>
      <h2>A Team Worth Joining</h2>
      <hr class="gold-rule">
    </div>
    <div class="grid grid-3">
      <div class="card reveal"><h3>20+ Years Strong</h3><p class="muted">An established Detroit company with long-term hotel and commercial partnerships — stable work with a team that knows the business.</p></div>
      <div class="card reveal"><h3>Growth Opportunities</h3><p class="muted">Many of our supervisors started as attendants. We promote from within and invest in people who take pride in their work.</p></div>
      <div class="card reveal"><h3>Professional Standards</h3><p class="muted">Uniformed, trained, customer-focused teams serving Detroit's premier hotels, events, and businesses.</p></div>
    </div>
  </div>
</section>

<?php include __DIR__.'/includes/site_footer.php'; ?>
