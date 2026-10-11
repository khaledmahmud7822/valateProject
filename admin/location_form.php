<?php
// admin/location_form.php — create / edit a parking location, incl. photo uploads.
require __DIR__ . '/../lib/bootstrap.php';
$page_title = 'Location';
$nav_section = 'locations';
$required_perm = 'locations.edit';
require __DIR__ . '/_guard.php';

$db = db();
$user = current_user();

$id = (int) ($_GET['id'] ?? 0);
$location = null;
if ($id > 0) {
    $st = $db->prepare('SELECT * FROM parking_locations WHERE id = ?');
    $st->execute([$id]);
    $location = $st->fetch(PDO::FETCH_ASSOC);
    if (!$location) {
        flash('Location not found.', 'error');
        redirect(site_url('admin/locations.php'));
    }
}
$is_new = $location === null;
$page_title = $is_new ? 'Add Location' : 'Edit Location';

$errors = [];
$values = [
    'name' => '', 'address' => '', 'city' => '', 'state' => '', 'zip' => '',
    'type' => '', 'services' => '', 'hours' => '', 'description' => '',
    'capacity' => '', 'rate_dollars' => '', 'lat' => '', 'lng' => '',
    'monthly_available' => 0, 'accepts_requests' => 0, 'is_published' => 0, 'needs_review' => 0,
];
if ($location) {
    $values['name'] = $location['name'];
    $values['address'] = $location['address'];
    $values['city'] = $location['city'];
    $values['state'] = $location['state'];
    $values['zip'] = $location['zip'];
    $values['type'] = $location['type'];
    $values['services'] = $location['services'];
    $values['hours'] = $location['hours'];
    $values['description'] = $location['description'];
    $values['capacity'] = $location['capacity'] ?? '';
    $values['rate_dollars'] = ($location['monthly_rate_cents'] === null || $location['monthly_rate_cents'] === '')
        ? '' : number_format(((int) $location['monthly_rate_cents']) / 100, 2, '.', '');
    $values['lat'] = $location['lat'] ?? '';
    $values['lng'] = $location['lng'] ?? '';
    $values['monthly_available'] = (int) $location['monthly_available'];
    $values['accepts_requests'] = (int) $location['accepts_requests'];
    $values['is_published'] = (int) $location['is_published'];
    $values['needs_review'] = (int) $location['needs_review'];
}

function mv_upload_dir(): string
{
    $dir = dirname(__DIR__) . '/uploads/locations';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    {
        foreach (['name', 'address', 'city', 'state', 'zip', 'type', 'services', 'hours', 'description', 'capacity', 'rate_dollars', 'lat', 'lng'] as $f) {
            $values[$f] = trim((string) ($_POST[$f] ?? ''));
        }
        foreach (['monthly_available', 'accepts_requests', 'is_published', 'needs_review'] as $f) {
            $values[$f] = isset($_POST[$f]) ? 1 : 0;
        }

        if ($values['name'] === '') {
            $errors[] = 'Name is required.';
        }
        $capacity = null;
        if ($values['capacity'] !== '') {
            if (!ctype_digit($values['capacity'])) {
                $errors[] = 'Capacity must be a whole number.';
            } else {
                $capacity = (int) $values['capacity'];
            }
        }
        $rate_cents = null;
        if ($values['rate_dollars'] !== '') {
            if (!is_numeric($values['rate_dollars']) || (float) $values['rate_dollars'] < 0) {
                $errors[] = 'Monthly rate must be a non-negative number (leave blank for "Contact for rates").';
            } else {
                $rate_cents = (int) round((float) $values['rate_dollars'] * 100);
            }
        }
        $lat = $values['lat'] !== '' && is_numeric($values['lat']) ? (float) $values['lat'] : null;
        $lng = $values['lng'] !== '' && is_numeric($values['lng']) ? (float) $values['lng'] : null;
        if (($values['lat'] !== '' && $lat === null) || ($values['lng'] !== '' && $lng === null)) {
            $errors[] = 'Latitude and longitude must be numbers.';
        }

        if ($errors === []) {
            $db->beginTransaction();
            try {
                $row = [
                    $values['name'], $values['address'], $values['city'], $values['state'], $values['zip'],
                    $values['type'], $values['services'], $values['monthly_available'], $values['accepts_requests'],
                    $values['is_published'], $values['needs_review'], $capacity, $rate_cents,
                    $values['hours'], $values['description'], $lat, $lng,
                ];
                if ($is_new) {
                    $db->prepare(
                        'INSERT INTO parking_locations (name, address, city, state, zip, type, services, monthly_available, accepts_requests, is_published, needs_review, capacity, monthly_rate_cents, hours, description, lat, lng, created_at)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, CURRENT_TIMESTAMP)'
                    )->execute($row);
                    $id = (int) $db->lastInsertId();
                    $location = ['id' => $id];
                    audit('location.created', 'parking_location', $id, ['name' => $values['name']]);
                } else {
                    $row[] = $id;
                    $db->prepare(
                        'UPDATE parking_locations SET name=?, address=?, city=?, state=?, zip=?, type=?, services=?, monthly_available=?, accepts_requests=?, is_published=?, needs_review=?, capacity=?, monthly_rate_cents=?, hours=?, description=?, lat=?, lng=? WHERE id=?'
                    )->execute($row);
                    audit('location.updated', 'parking_location', $id, ['name' => $values['name']]);
                }

                // Delete checked photos.
                $del_photos = $_POST['delete_photo'] ?? [];
                if (is_array($del_photos)) {
                    foreach ($del_photos as $pid) {
                        $ps = $db->prepare('SELECT * FROM location_photos WHERE id = ? AND location_id = ?');
                        $ps->execute([(int) $pid, $id]);
                        $ph = $ps->fetch(PDO::FETCH_ASSOC);
                        if ($ph) {
                            $fp = dirname(__DIR__) . '/uploads/locations/' . basename((string) $ph['path']);
                            if (is_file($fp)) {
                                unlink($fp);
                            }
                            $db->prepare('DELETE FROM location_photos WHERE id = ?')->execute([(int) $pid]);
                        }
                    }
                }

                // Handle new uploads.
                $upload_errors = [];
                if (!empty($_FILES['photos']['name'][0])) {
                    $dir = mv_upload_dir();
                    $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
                    $max_sort = (int) $db->query('SELECT COALESCE(MAX(sort), 0) FROM location_photos WHERE location_id = ' . $id)->fetchColumn();
                    $captions = $_POST['photo_caption'] ?? [];
                    $n = count($_FILES['photos']['name']);
                    for ($i = 0; $i < $n; $i++) {
                        if ($_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK) {
                            continue;
                        }
                        $tmp = $_FILES['photos']['tmp_name'][$i];
                        $orig = (string) $_FILES['photos']['name'][$i];
                        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                        if (!isset($allowed[$ext])) {
                            $upload_errors[] = $orig . ': unsupported file type.';
                            continue;
                        }
                        if ($_FILES['photos']['size'][$i] > 5 * 1024 * 1024) {
                            $upload_errors[] = $orig . ': file too large (max 5 MB).';
                            continue;
                        }
                        $info = @getimagesize($tmp);
                        if ($info === false || !in_array($info['mime'], $allowed, true)) {
                            $upload_errors[] = $orig . ': not a valid image.';
                            continue;
                        }
                        $fname = 'loc' . $id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                        if (!move_uploaded_file($tmp, $dir . '/' . $fname)) {
                            $upload_errors[] = $orig . ': upload failed.';
                            continue;
                        }
                        $max_sort++;
                        $cap = trim((string) ($captions[$i] ?? ''));
                        $db->prepare('INSERT INTO location_photos (location_id, path, caption, sort) VALUES (?,?,?,?)')
                            ->execute([$id, $fname, $cap, $max_sort]);
                    }
                }

                $db->commit();
                foreach ($upload_errors as $ue) {
                    flash($ue, 'warning');
                }
                flash($is_new ? 'Location created.' : 'Location updated.', 'success');
                redirect(site_url('admin/location_form.php?id=' . $id));
            } catch (Exception $ex) {
                $db->rollBack();
                $errors[] = 'Save failed: ' . $ex->getMessage();
            }
        }
    }
}

// Existing photos for edit.
$photos = [];
if (!$is_new) {
    $ps = $db->prepare('SELECT * FROM location_photos WHERE location_id = ? ORDER BY sort, id');
    $ps->execute([$id]);
    $photos = $ps->fetchAll(PDO::FETCH_ASSOC);
}

require __DIR__ . '/_layout.php';
?>

<div class="page-head">
    <h1><?= $is_new ? 'Add Location' : 'Edit Location' ?></h1>
    <div class="page-actions">
        <a class="btn btn-outline" href="<?= e(site_url('admin/locations.php')) ?>">Back to list</a>
    </div>
</div>

<?php foreach ($errors as $er): ?>
    <div class="flash flash-error"><?= e($er) ?></div>
<?php endforeach; ?>

<form method="post" enctype="multipart/form-data" class="card">
    <?= csrf_field() ?>
    <div class="form-grid">
        <div class="field">
            <label for="name">Name *</label>
            <input type="text" id="name" name="name" value="<?= e($values['name']) ?>" required maxlength="200">
        </div>
        <div class="field">
            <label for="type">Type</label>
            <input type="text" id="type" name="type" value="<?= e($values['type']) ?>" maxlength="80" placeholder="e.g. Garage, Surface Lot, Hotel">
        </div>
        <div class="field full">
            <label for="address">Street address</label>
            <input type="text" id="address" name="address" value="<?= e($values['address']) ?>" maxlength="255">
        </div>
        <div class="field">
            <label for="city">City</label>
            <input type="text" id="city" name="city" value="<?= e($values['city']) ?>" maxlength="120">
        </div>
        <div class="field">
            <label for="zip">ZIP</label>
            <input type="text" id="zip" name="zip" value="<?= e($values['zip']) ?>" maxlength="20">
        </div>
        <div class="field">
            <label for="state">State</label>
            <input type="text" id="state" name="state" value="<?= e($values['state']) ?>" maxlength="40">
        </div>
        <div class="field">
            <label for="capacity">Capacity (spaces)</label>
            <input type="number" id="capacity" name="capacity" value="<?= e($values['capacity']) ?>" min="0" step="1">
        </div>
        <div class="field">
            <label for="rate_dollars">Monthly rate ($)</label>
            <input type="number" id="rate_dollars" name="rate_dollars" value="<?= e($values['rate_dollars']) ?>" min="0" step="0.01">
            <div class="hint">Leave blank to show "Contact for rates" on the public site.</div>
        </div>
        <div class="field">
            <label for="hours">Hours</label>
            <input type="text" id="hours" name="hours" value="<?= e($values['hours']) ?>" maxlength="200" placeholder="e.g. 24/7, Mon–Fri 6am–10pm">
        </div>
        <div class="field">
            <label for="services">Services</label>
            <input type="text" id="services" name="services" value="<?= e($values['services']) ?>" maxlength="500" placeholder="Comma-separated, e.g. Valet, EV charging, Covered">
        </div>
        <div class="field">
            <label for="lat">Latitude</label>
            <input type="text" id="lat" name="lat" value="<?= e($values['lat']) ?>" placeholder="42.3314">
        </div>
        <div class="field">
            <label for="lng">Longitude</label>
            <input type="text" id="lng" name="lng" value="<?= e($values['lng']) ?>" placeholder="-83.0458">
        </div>
        <div class="field full">
            <label for="description">Description</label>
            <textarea id="description" name="description"><?= e($values['description']) ?></textarea>
        </div>
        <div class="field full">
            <label class="check-line"><input type="checkbox" name="is_published" value="1"<?= $values['is_published'] ? ' checked' : '' ?>> Published (visible on the public site)</label>
        </div>
        <div class="field">
            <label class="check-line"><input type="checkbox" name="monthly_available" value="1"<?= $values['monthly_available'] ? ' checked' : '' ?>> Monthly parking available</label>
        </div>
        <div class="field">
            <label class="check-line"><input type="checkbox" name="accepts_requests" value="1"<?= $values['accepts_requests'] ? ' checked' : '' ?>> Accepts monthly requests</label>
        </div>
        <div class="field full">
            <label class="check-line"><input type="checkbox" name="needs_review" value="1"<?= $values['needs_review'] ? ' checked' : '' ?>> Needs review (e.g. address unconfirmed)</label>
        </div>
    </div>

    <hr class="hr-gold">
    <h3>Photos</h3>
    <?php if ($photos !== []): ?>
        <div class="photo-row">
            <?php foreach ($photos as $ph): ?>
                <div class="photo-thumb">
                    <img src="<?= e(site_url('uploads/locations/' . $ph['path'])) ?>" alt="<?= e($ph['caption'] ?: 'Location photo') ?>">
                    <div class="caption"><?= e($ph['caption'] ?: 'No caption') ?></div>
                    <label class="check-line small"><input type="checkbox" name="delete_photo[]" value="<?= (int) $ph['id'] ?>"> Delete</label>
                </div>
            <?php endforeach; ?>
        </div>
    <?php elseif (!$is_new): ?>
        <p class="muted">No photos yet.</p>
    <?php endif; ?>
    <div class="form-grid" style="margin-top:12px">
        <div class="field">
            <label for="photos">Upload photos</label>
            <input type="file" id="photos" name="photos[]" accept="image/*" multiple>
            <div class="hint">JPG, PNG, WebP or GIF, max 5 MB each.</div>
        </div>
        <div class="field">
            <label for="photo_caption">Caption for uploads</label>
            <input type="text" id="photo_caption" name="photo_caption[]" maxlength="200" placeholder="Applies to each uploaded photo">
        </div>
    </div>

    <div class="btn-row">
        <button type="submit" class="btn btn-primary"><?= $is_new ? 'Create location' : 'Save changes' ?></button>
        <a class="btn btn-ghost" href="<?= e(site_url('admin/locations.php')) ?>">Cancel</a>
    </div>
</form>

<?php require __DIR__ . '/_footer.php'; ?>
