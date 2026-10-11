<?php
/* portal/_layout.php — Customer portal chrome (page top).
 *
 * Expects the including page to have already required lib/bootstrap.php
 * (which starts the session and loads helpers) and to set:
 *   $page_title (string), $customer (array), $active (nav key string)
 * The page must include portal/_layout_bottom.php where its content ends.
 */
$customer = (isset($customer) && is_array($customer)) ? $customer : array();
$scope        = $_SESSION['customer_scope'] ?? 'customer';
$scope_id     = (int)($_SESSION['customer_scope_id'] ?? 0);
$display_name = isset($customer['name']) ? (string)$customer['name'] : 'Account';
if ($scope === 'company' && $scope_id > 0) {
    try {
        $st = db()->prepare('SELECT name FROM companies WHERE id = ? LIMIT 1');
        $st->execute(array($scope_id));
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r && !empty($r['name'])) {
            $display_name = (string)$r['name'];
        }
    } catch (Throwable $e) {
        /* keep fallback name */
    }
}
$scope_label = ($scope === 'company') ? 'Company account' : 'Customer';
$nav = array(
    'dashboard' => array('Dashboard', 'portal/index.php'),
    'invoices'  => array('Invoices',  'portal/invoices.php'),
    'vehicles'  => array('Vehicles',  'portal/vehicles.php'),
    'profile'   => array('Profile',   'portal/profile.php'),
    'support'   => array('Support',   'portal/support.php'),
);
$active = isset($active) ? (string)$active : 'dashboard';
$flash_list = flashes();
$biz_phone  = setting('business_phone', '(855)-442-6500');

/* Shared portal helper: invoice status badge (HTML, escaped). */
if (!function_exists('portal_inv_badge')) {
    function portal_inv_badge($status) {
        $map = array(
            'unpaid'         => array('b-amber', 'Unpaid'),
            'pending'        => array('b-amber', 'Pending'),
            'partially_paid' => array('b-amber', 'Partially paid'),
            'overdue'        => array('b-red',   'Overdue'),
            'paid'           => array('b-green', 'Paid'),
            'refunded'       => array('b-gray',  'Refunded'),
            'voided'         => array('b-gray',  'Voided'),
        );
        $s = isset($map[$status]) ? $map[$status] : array('b-gray', ucfirst(str_replace('_', ' ', (string)$status)));
        return '<span class="badge ' . $s[0] . '">' . e($s[1]) . '</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page_title . ' — Metro Valet Customer Portal') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(rtrim((string)base_url(), '/')) ?>/assets/css/site.css?v=20251011">
<style>
:root{
  --navy:#0A1A2F; --navy-2:#10233D; --gold:#C9A227; --gold-2:#E3B94E;
  --paper:#FFFFFF; --mist:#F4F6F9; --ink:#1B2430; --muted:#5B6774;
  --green:#1E7E46; --amber:#B7791F; --red:#C0392B; --gray:#6B7280;
  --line:#E2E8F0;
}
body.portal{margin:0;background:var(--mist);color:var(--ink);font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;font-size:15px;line-height:1.55;}
.p-top{background:var(--navy);color:#fff;}
.p-topbar{max-width:1120px;margin:0 auto;padding:14px 20px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;}
.p-brand{display:flex;align-items:center;gap:10px;color:#fff;text-decoration:none;}
.p-brand-mark{display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:10px;background:var(--gold);color:var(--navy);font-family:Fraunces,serif;font-weight:600;font-size:17px;}
.p-brand-text{font-family:Fraunces,serif;font-size:19px;font-weight:600;letter-spacing:.2px;line-height:1.15;}
.p-brand-text span{display:block;font-family:Inter,sans-serif;font-size:11px;font-weight:500;letter-spacing:2px;text-transform:uppercase;color:var(--gold-2);}
.p-acct{display:flex;align-items:center;gap:10px;font-size:13px;flex-wrap:wrap;}
.p-acct-name{font-weight:600;}
.p-acct-scope{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:999px;padding:2px 10px;font-size:11px;letter-spacing:.4px;color:#E2E8F0;}
.p-logout{color:var(--gold-2);text-decoration:none;font-weight:600;}
.p-logout:hover{text-decoration:underline;}
.p-nav{max-width:1120px;margin:0 auto;padding:0 20px;display:flex;gap:4px;overflow-x:auto;border-top:1px solid rgba(255,255,255,.08);}
.p-nav a{color:#CBD5E1;text-decoration:none;padding:12px 14px;font-size:14px;font-weight:500;white-space:nowrap;border-bottom:3px solid transparent;}
.p-nav a:hover{color:#fff;}
.p-nav a.on{color:#fff;border-bottom-color:var(--gold);}
.p-main{max-width:1120px;margin:0 auto;padding:24px 20px 48px;}
.p-main h1{font-family:Fraunces,serif;font-size:28px;margin:0 0 6px;color:var(--navy);}
.p-lead{color:var(--muted);margin:0 0 20px;}
.p-card{background:var(--paper);border:1px solid var(--line);border-radius:14px;padding:20px;margin:0 0 18px;box-shadow:0 1px 2px rgba(10,26,47,.05);}
.p-card h2{font-family:Fraunces,serif;font-size:20px;margin:0 0 12px;color:var(--navy);}
.p-card h3{font-size:16px;margin:18px 0 8px;color:var(--navy);}
.p-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;}
.p-tablewrap{overflow-x:auto;border:1px solid var(--line);border-radius:12px;background:#fff;}
table.p-table{width:100%;border-collapse:collapse;font-size:14px;min-width:640px;}
.p-table th{text-align:left;padding:10px 12px;background:#F8FAFC;color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.6px;border-bottom:1px solid var(--line);white-space:nowrap;}
.p-table td{padding:10px 12px;border-bottom:1px solid var(--line);vertical-align:middle;}
.p-table tr:last-child td{border-bottom:none;}
.badge{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;border-radius:999px;padding:3px 10px;white-space:nowrap;}
.badge::before{content:"";width:8px;height:8px;border-radius:50%;background:currentColor;flex:none;}
.b-green{background:#E7F4EC;color:var(--green);}
.b-amber{background:#FBF1DC;color:var(--amber);}
.b-red{background:#FBE7E4;color:var(--red);}
.b-gray{background:#EEF1F5;color:var(--gray);}
.b-navy{background:#E4EAF3;color:var(--navy);}
.btn{display:inline-block;border:none;cursor:pointer;font:600 14px Inter,sans-serif;border-radius:10px;padding:10px 18px;text-decoration:none;transition:box-shadow .15s ease;}
.btn-gold{background:var(--gold);color:var(--navy);}
.btn-gold:hover{background:var(--gold-2);}
.btn-navy{background:var(--navy);color:#fff;}
.btn-navy:hover{background:var(--navy-2);}
.btn-outline{background:#fff;color:var(--navy);border:1px solid var(--line);}
.btn-outline:hover{border-color:var(--navy);}
.btn-sm{padding:7px 12px;font-size:13px;border-radius:8px;}
.btn[disabled]{opacity:.55;cursor:default;}
.p-field{margin:0 0 14px;}
.p-field label{display:block;font-size:13px;font-weight:600;margin:0 0 6px;}
.p-field input,.p-field select,.p-field textarea{width:100%;box-sizing:border-box;border:1px solid var(--line);border-radius:10px;padding:10px 12px;font:400 14px Inter,sans-serif;background:#fff;color:var(--ink);}
.p-field input:focus,.p-field select:focus,.p-field textarea:focus{outline:2px solid var(--gold-2);border-color:var(--gold);}
.p-field .hint{font-size:12px;color:var(--muted);margin-top:4px;}
.p-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:0 16px;}
.p-flash{border-radius:10px;padding:12px 16px;margin:0 0 16px;font-size:14px;border:1px solid;}
.f-success{background:#E7F4EC;border-color:#BFE3CD;color:#14532D;}
.f-error{background:#FBE7E4;border-color:#F3C1BB;color:#7A1F14;}
.f-info{background:#E8EFFA;border-color:#C3D4EE;color:#1E3A66;}
.p-footer{background:var(--navy);color:#CBD5E1;}
.p-footer-inner{max-width:1120px;margin:0 auto;padding:20px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;font-size:13px;align-items:center;}
.p-footer a{color:var(--gold-2);text-decoration:none;}
.p-footer a:hover{text-decoration:underline;}
.p-empty{padding:28px;text-align:center;color:var(--muted);}
.p-kv{display:grid;grid-template-columns:190px 1fr;gap:6px 12px;font-size:14px;margin:0;}
.p-kv dt{color:var(--muted);}
.p-kv dd{margin:0;font-weight:500;}
.p-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;align-items:center;}
.p-due{font-family:Fraunces,serif;font-size:30px;color:var(--navy);}
.p-muted{color:var(--muted);font-size:13px;}
@media (max-width:720px){
  .p-form-grid{grid-template-columns:1fr;}
  .p-main{padding:18px 14px 40px;}
  .p-kv{grid-template-columns:1fr;}
  .p-kv dt{margin-top:10px;}
  .p-main h1{font-size:24px;}
}
</style>
</head>
<body class="portal">
<header class="p-top">
  <div class="p-topbar">
    <a class="p-brand" href="<?= e(site_url('portal/index.php')) ?>">
      <span class="p-brand-mark">MV</span>
      <span class="p-brand-text">Metro Valet<span>Customer Portal</span></span>
    </a>
    <div class="p-acct">
      <span class="p-acct-name"><?= e($display_name) ?></span>
      <span class="p-acct-scope"><?= e($scope_label) ?></span>
      <a class="p-logout" href="<?= e(site_url('portal/logout.php')) ?>">Logout</a>
    </div>
  </div>
  <nav class="p-nav" aria-label="Portal">
    <?php foreach ($nav as $key => $item): ?>
      <a href="<?= e(site_url($item[1])) ?>" class="<?= $key === $active ? 'on' : '' ?>"><?= e($item[0]) ?></a>
    <?php endforeach; ?>
  </nav>
</header>
<main class="p-main">
  <?php foreach ($flash_list as $f):
      $ft = strtolower((string)($f['type'] ?? 'info'));
      if (!in_array($ft, array('success', 'error', 'info'), true)) { $ft = 'info'; }
      $fm = (string)($f['message'] ?? $f['msg'] ?? '');
      if ($fm === '') { continue; }
  ?>
    <div class="p-flash f-<?= e($ft) ?>" role="alert"><?= e($fm) ?></div>
  <?php endforeach; ?>
