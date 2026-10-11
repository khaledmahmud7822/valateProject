<?php
require __DIR__.'/lib/bootstrap.php';
// Public "Customer Login" entry point — forwards to the customer portal login.
redirect(site_url('portal/login.php'));
