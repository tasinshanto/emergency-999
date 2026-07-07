<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/audit.php';

$user = require_login('user');
$error = '';

if (is_post()) {
    verify_csrf();
    $typeId = (int) ($_POST['emergency_type_id'] ?? 0);
    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $address = trim((string) ($_POST['address'] ?? ''));
    $severity = trim((string) ($_POST['severity'] ?? 'medium'));
    $latitude = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
    $longitude = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);

    $reqUnits = $_POST['requested_unit_types'] ?? [];
    if (is_array($reqUnits)) {
        $reqUnits = array_intersect($reqUnits, ['fire', 'ambulance', 'police', 'rescue', 'other']);
        $reqUnitsStr = implode(',', $reqUnits);
    } else {
        $reqUnitsStr = '';
    }

    if ($typeId <= 0 || $title === '' || $description === '' || $address === '' || $latitude === false || $longitude === false) {
        $error = 'Please complete the report, including a valid location.';
    } elseif (!in_array($severity, ['low', 'medium', 'high', 'critical'], true)) {
        $error = 'Invalid severity value.';
    } else {
        try {
            if ($reqUnitsStr === '') {
                $typeStmt = db()->prepare('SELECT name FROM Emergency_Type WHERE id = ?');
                $typeStmt->execute([$typeId]);
                $typeName = (string) $typeStmt->fetchColumn();
                require_once __DIR__ . '/../includes/dispatch_helpers.php';
                $defaultTypes = unit_types_for_emergency($typeName);
                $reqUnitsStr = implode(',', $defaultTypes);
            }

            $stmt = db()->prepare('
                INSERT INTO Emergency_Report
                    (user_id, emergency_type_id, title, description, address, latitude, longitude, severity, requested_unit_types, status, reported_by_name, reported_by_phone)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, "pending", ?, ?)
            ');
            $stmt->execute([
                $user['id'],
                $typeId,
                $title,
                $description,
                $address,
                $latitude,
                $longitude,
                $severity,
                $reqUnitsStr,
                $user['name'],
                $user['phone'],
            ]);
            $reportId = (int) db()->lastInsertId();
            audit_log(
                'created',
                'emergency_report',
                $reportId,
                $title,
                'Citizen submitted emergency report: ' . $title,
                'status',
                null,
                'pending',
                $user,
            );
            set_flash('success', 'Emergency report submitted for admin verification.');
            redirect('user/dashboard.php');
        } catch (Throwable $e) {
            $error = 'Report could not be saved.';
        }
    }
}

$types = db()->query('SELECT id, name FROM Emergency_Type WHERE is_active = 1 ORDER BY name')->fetchAll();
$stmt = db()->prepare('
    SELECT er.*, et.name AS type_name
    FROM Emergency_Report er
    INNER JOIN Emergency_Type et ON et.id = er.emergency_type_id
    WHERE er.user_id = ?
    ORDER BY er.created_at DESC
');
$stmt->execute([$user['id']]);
$reports = $stmt->fetchAll();

page_header('User Dashboard', 'dashboard', true);
?>
<div class="grid gap-6 lg:grid-cols-[25rem_1fr]">
    <form method="post" class="panel space-y-4 p-5">
        <?= csrf_input() ?>
        <div>
            <p class="text-xs font-bold uppercase text-red-600">User Dashboard</p>
            <h1 class="mt-1 text-2xl font-black text-slate-950">Submit Emergency Report</h1>
        </div>
        <?php if ($error): ?>
            <div class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"><?= h($error) ?></div>
        <?php endif; ?>
        <div>
            <label class="field-label" for="emergency_type_id">Emergency Type</label>
            <select class="field-select" id="emergency_type_id" name="emergency_type_id" required>
                <option value="">Select type</option>
                <?php foreach ($types as $type): ?>
                    <option value="<?= (int) $type['id'] ?>"><?= h($type['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <span class="field-label">Services/Units Needed</span>
            <div class="mt-2 grid grid-cols-2 gap-2 text-sm">
                <label class="flex items-center gap-2 rounded border border-slate-200 bg-slate-50 p-2.5 hover:bg-slate-100 transition cursor-pointer">
                    <input type="checkbox" name="requested_unit_types[]" value="fire" class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                    <span class="font-semibold text-slate-700">Fire Unit</span>
                </label>
                <label class="flex items-center gap-2 rounded border border-slate-200 bg-slate-50 p-2.5 hover:bg-slate-100 transition cursor-pointer">
                    <input type="checkbox" name="requested_unit_types[]" value="ambulance" class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                    <span class="font-semibold text-slate-700">Ambulance</span>
                </label>
                <label class="flex items-center gap-2 rounded border border-slate-200 bg-slate-50 p-2.5 hover:bg-slate-100 transition cursor-pointer">
                    <input type="checkbox" name="requested_unit_types[]" value="police" class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                    <span class="font-semibold text-slate-700">Police</span>
                </label>
                <label class="flex items-center gap-2 rounded border border-slate-200 bg-slate-50 p-2.5 hover:bg-slate-100 transition cursor-pointer">
                    <input type="checkbox" name="requested_unit_types[]" value="rescue" class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                    <span class="font-semibold text-slate-700">Rescue</span>
                </label>
            </div>
        </div>
        <div>
            <label class="field-label" for="title">Title</label>
            <input class="field-input" id="title" name="title" maxlength="160" required>
        </div>
        <div>
            <label class="field-label" for="severity">Severity</label>
            <select class="field-select" id="severity" name="severity">
                <?php foreach (['low', 'medium', 'high', 'critical'] as $severity): ?>
                    <option value="<?= h($severity) ?>" <?= $severity === 'medium' ? 'selected' : '' ?>><?= h(ucfirst($severity)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="field-label" for="description">Description</label>
            <textarea class="field-textarea" id="description" name="description" required></textarea>
        </div>
        <div>
            <label class="field-label" for="address">Address</label>
            <input class="field-input" id="address" name="address" required>
        </div>
        <div id="report-map" class="dashboard-map"></div>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="field-label" for="latitude">Latitude</label>
                <input class="field-input" id="latitude" name="latitude" required value="23.8103000">
            </div>
            <div>
                <label class="field-label" for="longitude">Longitude</label>
                <input class="field-input" id="longitude" name="longitude" required value="90.4125000">
            </div>
        </div>
        <button type="button" id="use-location" class="btn-secondary w-full">Use Current Location</button>
        <button class="btn-primary w-full" type="submit">Submit Report</button>
    </form>

    <section class="space-y-4">
        <div class="panel p-5">
            <p class="text-xs font-bold uppercase text-slate-500">Personal history</p>
            <h2 class="mt-1 text-2xl font-black text-slate-950">My Reports</h2>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Incident</th>
                        <th>Type</th>
                        <th>Severity</th>
                        <th>Status</th>
                        <th>Reported</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reports as $report): ?>
                        <tr>
                            <td>#<?= (int) $report['id'] ?></td>
                            <td>
                                <strong class="block text-slate-950"><?= h($report['title']) ?></strong>
                                <span class="text-sm text-slate-600"><?= h($report['address']) ?></span>
                            </td>
                            <td><?= h($report['type_name']) ?></td>
                            <td><span class="badge <?= h(severity_badge_class($report['severity'])) ?>"><?= h($report['severity']) ?></span></td>
                            <td><span class="badge <?= h(status_badge_class($report['status'])) ?>"><?= h(str_replace('_', ' ', $report['status'])) ?></span></td>
                            <td><?= h($report['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$reports): ?>
                        <tr><td colspan="6" class="text-center text-slate-500">No reports yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php page_footer(['assets/js/user-report-location-map.js']); ?>
