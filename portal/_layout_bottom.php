<?php
/* portal/_layout_bottom.php — closes the portal chrome opened by portal/_layout.php */
?>
</main>
<footer class="p-footer">
  <div class="p-footer-inner">
    <div><strong style="color:#fff;">Metro Valet Parking</strong> &middot; Customer Portal</div>
    <div style="display:flex;gap:16px;flex-wrap:wrap;">
      <span><?= e(setting('business_phone', '(855)-442-6500')) ?></span>
      <?php $be = (string)setting('business_email', ''); if ($be !== ''): ?>
        <span><a href="mailto:<?= e($be) ?>"><?= e($be) ?></a></span>
      <?php endif; ?>
      <span><a href="<?= e(site_url('index.php')) ?>">Back to website</a></span>
    </div>
  </div>
</footer>
</body>
</html>
