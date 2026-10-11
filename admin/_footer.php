<?php
// admin/_footer.php — closes the admin layout opened by _layout.php.
// Every admin content page ends with: require __DIR__.'/_footer.php';
?>
        </main><!-- /.content -->
        <footer class="content-foot">
            <span><?= e(setting('business_name', 'Metro Valet Parking')) ?> Admin</span>
            <span class="muted">&middot;</span>
            <span class="muted"><?= e(today()) ?></span>
        </footer>
    </div><!-- /.admin-main -->
</div><!-- /.admin-shell -->
<script src="<?= e(site_url('assets/js/admin.js')) ?>"></script>
</body>
</html>
