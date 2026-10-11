<?php
// admin/leads.php — valet/B2B lead pipeline: stages, notes, contact log.
require __DIR__ . '/../lib/bootstrap.php';
$page_title = 'Valet Leads';
$nav_section = 'leads';
$required_perm = 'leads.manage';
require __DIR__ . '/_guard.php';

$db = db();
$user = current_user();

$stages = ['new' => 'New', 'contacted' => 'Contacted', 'quoted' => 'Quoted', 'negotiating' => 'Negotiating', 'won' => 'Won', 'lost' => 'Lost'];
$next = ['new' => 'contacted', 'contacted' => 'quoted', 'quoted' => 'negotiating', 'negotiating' => 'won'];

$stage_f = (string) ($_GET['stage'] ?? 'all');
if ($stage_f === 'quoted') {
    $nav_section = 'quotes';
    $page_title = 'Quotes';
}
$search = trim((string) ($_GET['q'] ?? ''));
$view_id = (int) ($_GET['view'] ?? 0);

function mv_lead(PDO $db, int $id): ?array
{
    $st = $db->prepare('SELECT * FROM valet_leads WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// ---------- Actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $lid = (int) ($_POST['id'] ?? 0);
    $lead = mv_lead($db, $lid);
    if (!$lead) {
        flash('Lead not found.', 'error');
        redirect(site_url('admin/leads.php'));
    }
    $op = (string) ($_POST['op'] ?? '');
    $ts = date('Y-m-d H:i');

    if ($op === 'advance' && isset($next[$lead['stage']])) {
        $to = $next[$lead['stage']];
        $db->prepare("UPDATE valet_leads SET stage = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$to, $lid]);
        audit('lead.stage_advanced', 'valet_lead', $lid, ['from' => $lead['stage'], 'to' => $to, 'ref' => $lead['ref_no']]);
        flash('Lead ' . $lead['ref_no'] . ' moved to ' . $stages[$to] . '.', 'success');
    } elseif ($op === 'set_stage' && isset($stages[(string) ($_POST['stage'] ?? '')])) {
        $to = (string) $_POST['stage'];
        $db->prepare("UPDATE valet_leads SET stage = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$to, $lid]);
        audit('lead.stage_changed', 'valet_lead', $lid, ['from' => $lead['stage'], 'to' => $to, 'ref' => $lead['ref_no']]);
        flash('Lead stage set to ' . $stages[$to] . '.', 'success');
    } elseif ($op === 'note') {
        $note = trim((string) ($_POST['note'] ?? ''));
        if ($note === '') {
            flash('Note cannot be empty.', 'error');
        } else {
            $entry = $ts . ' — ' . $user['name'] . ': ' . $note;
            $db->prepare("UPDATE valet_leads SET notes = TRIM(COALESCE(notes,'') || '\n' || ?), updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([$entry, $lid]);
            audit('lead.note_added', 'valet_lead', $lid, ['ref' => $lead['ref_no']]);
            flash('Note added to the contact log.', 'success');
        }
    } else {
        flash('Nothing to do.', 'info');
    }
    redirect(site_url('admin/leads.php?view=' . $lid));
}

// ---------- Detail view ----------
if ($view_id > 0) {
    $lead = mv_lead($db, $view_id);
    if (!$lead) {
        flash('Lead not found.', 'error');
        redirect(site_url('admin/leads.php'));
    }
    $page_title = 'Lead ' . $lead['ref_no'];
    $sc = $lead['stage'] === 'won' ? 'green' : ($lead['stage'] === 'lost' ? 'gray' : 'amber');
    // Null-safe for output: DB NULLs would break e(string).
    $lead = array_map(fn($v) => $v === null ? '' : $v, $lead);
    require __DIR__ . '/_layout.php';
    ?>
    <div class="page-head">
        <h1>Lead <span class="mono"><?= e($lead['ref_no']) ?></span></h1>
        <div class="page-actions"><a class="btn btn-outline" href="<?= e(site_url('admin/leads.php')) ?>">Back to pipeline</a></div>
    </div>
    <div class="card">
        <div class="section-title" style="margin-top:0">
            <h2><?= e($lead['business_name']) ?></h2>
            <span class="badge badge-<?= $sc ?>"><?= status_dot($sc, $stages[$lead['stage']] ?? $lead['stage']) ?></span>
        </div>
        <dl class="dl">
            <dt>Contact person</dt><dd><?= e($lead['contact_person'] ?: '—') ?></dd>
            <dt>Email</dt><dd><?= e($lead['email'] ?: '—') ?></dd>
            <dt>Phone</dt><dd><?= e($lead['phone'] ?: '—') ?></dd>
            <dt>Business type</dt><dd><?= e($lead['business_type'] ?: '—') ?></dd>
            <dt>Property address</dt><dd><?= e($lead['property_address'] ?: '—') ?></dd>
            <dt>Service type</dt><dd><?= e($lead['service_type'] ?: '—') ?></dd>
            <dt>Volume</dt><dd><?= e($lead['volume'] ?: '—') ?></dd>
            <dt>Desired start</dt><dd><?= $lead['start_date'] ? e(fmt_date($lead['start_date'])) : '—' ?></dd>
            <?php if ($lead['requirements']): ?><dt>Requirements</dt><dd><?= e($lead['requirements']) ?></dd><?php endif; ?>
            <dt>Received</dt><dd><?= e($lead['created_at']) ?></dd>
        </dl>
        <hr class="hr-gold">
        <h3>Pipeline</h3>
        <div class="btn-row">
            <?php if (isset($next[$lead['stage']])): ?>
                <form method="post" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $lead['id'] ?>">
                    <input type="hidden" name="op" value="advance">
                    <button type="submit" class="btn btn-primary btn-sm">Advance to <?= e($stages[$next[$lead['stage']]]) ?></button>
                </form>
            <?php endif; ?>
            <?php if (!in_array($lead['stage'], ['won', 'lost'], true)): ?>
                <form method="post" class="inline-form" data-confirm="Mark this lead as won?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $lead['id'] ?>">
                    <input type="hidden" name="op" value="set_stage">
                    <input type="hidden" name="stage" value="won">
                    <button type="submit" class="btn btn-outline btn-sm">Mark won</button>
                </form>
                <form method="post" class="inline-form" data-confirm="Mark this lead as lost?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $lead['id'] ?>">
                    <input type="hidden" name="op" value="set_stage">
                    <input type="hidden" name="stage" value="lost">
                    <button type="submit" class="btn btn-danger btn-sm">Mark lost</button>
                </form>
            <?php else: ?>
                <form method="post" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $lead['id'] ?>">
                    <input type="hidden" name="op" value="set_stage">
                    <select name="stage" aria-label="Reopen to stage">
                        <?php foreach ($stages as $k => $lbl): ?>
                            <option value="<?= $k ?>"<?= $lead['stage'] === $k ? ' selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-outline btn-sm">Reopen</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="card">
        <h3>Notes &amp; contact log</h3>
        <?php if (trim((string) $lead['notes']) !== ''): ?>
            <p style="white-space:pre-line"><?= e($lead['notes']) ?></p>
        <?php else: ?>
            <p class="muted">No notes yet.</p>
        <?php endif; ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $lead['id'] ?>">
            <input type="hidden" name="op" value="note">
            <div class="field">
                <label for="note">Add a timestamped note</label>
                <textarea id="note" name="note" required placeholder="Called — spoke with …"></textarea>
            </div>
            <div class="btn-row"><button type="submit" class="btn btn-outline">Add note</button></div>
        </form>
    </div>
    <?php
    require __DIR__ . '/_footer.php';
    return;
}

// ---------- Pipeline list ----------
$where = [];
$params = [];
if (isset($stages[$stage_f])) {
    $where[] = 'stage = ?';
    $params[] = $stage_f;
}
if ($search !== '') {
    $where[] = '(ref_no LIKE ? OR business_name LIKE ? OR contact_person LIKE ? OR email LIKE ?)';
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like, $like]);
}
$sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 25;
$count_st = $db->prepare("SELECT COUNT(*) FROM valet_leads $sql_where");
$count_st->execute($params);
$total = (int) $count_st->fetchColumn();

$offset = ($page - 1) * $per;
$st = $db->prepare("SELECT * FROM valet_leads $sql_where ORDER BY created_at DESC, id DESC LIMIT $per OFFSET $offset");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Stage counts for the pipeline header.
$counts = [];
$cs = $db->query('SELECT stage, COUNT(*) AS c FROM valet_leads GROUP BY stage')->fetchAll(PDO::FETCH_ASSOC);
foreach ($cs as $c) {
    $counts[$c['stage']] = (int) $c['c'];
}

$base = site_url('admin/leads.php'); // paginate() preserves the current GET filters itself

require __DIR__ . '/_layout.php';
?>

<div class="page-head"><h1><?= e($page_title) ?></h1></div>

<div class="kpi-grid">
    <?php foreach ($stages as $k => $lbl): ?>
        <a href="<?= e(site_url('admin/leads.php?stage=' . $k)) ?>" style="text-decoration:none">
            <div class="kpi<?= $stage_f === $k ? ' gold' : '' ?>">
                <div class="kpi-label"><?= e($lbl) ?></div>
                <div class="kpi-value"><?= $counts[$k] ?? 0 ?></div>
            </div>
        </a>
    <?php endforeach; ?>
</div>

<form method="get" action="<?= e(site_url('admin/leads.php')) ?>" class="filter-bar">
    <?php if ($stage_f !== 'all'): ?><input type="hidden" name="stage" value="<?= e($stage_f) ?>"><?php endif; ?>
    <div class="field">
        <label for="fq">Search</label>
        <input type="text" id="fq" name="q" value="<?= e($search) ?>" placeholder="Ref #, business, contact, email…">
    </div>
    <button type="submit" class="btn btn-outline btn-sm">Search</button>
    <a class="btn btn-ghost btn-sm" href="<?= e(site_url('admin/leads.php')) ?>">Clear</a>
</form>

<?php if ($rows === []): ?>
    <div class="empty"><strong>No leads found</strong>New quote requests from the public site will appear here.</div>
<?php else: ?>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Ref #</th><th>Business</th><th>Contact</th><th>Service</th><th>Received</th><th>Stage</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php $sc = $r['stage'] === 'won' ? 'green' : ($r['stage'] === 'lost' ? 'gray' : 'amber'); ?>
                    <tr>
                        <td><strong class="mono"><?= e($r['ref_no']) ?></strong></td>
                        <td><strong><?= e($r['business_name']) ?></strong><br><span class="muted small"><?= e($r['business_type'] ?: '') ?></span></td>
                        <td class="small"><?= e($r['contact_person'] ?: '—') ?><br><span class="muted"><?= e($r['email'] ?: '') ?></span></td>
                        <td class="small"><?= e($r['service_type'] ?: '—') ?></td>
                        <td class="nowrap small"><?= e(substr($r['created_at'], 0, 10)) ?></td>
                        <td><span class="badge badge-<?= $sc ?>"><?= status_dot($sc, $stages[$r['stage']] ?? $r['stage']) ?></span></td>
                        <td>
                            <div class="row-actions">
                                <a class="btn btn-outline btn-xs" href="<?= e(site_url('admin/leads.php?view=' . (int) $r['id'])) ?>">Open</a>
                                <?php if (isset($next[$r['stage']])): ?>
                                    <form method="post" class="inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                        <input type="hidden" name="op" value="advance">
                                        <button type="submit" class="btn btn-ghost btn-xs">→ <?= e($stages[$next[$r['stage']]]) ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= paginate($total, $per, $page, $base) ?>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
