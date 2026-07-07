<?php
/**
 * Admin: emergency report verification, manual dispatch, and auto-dispatch on verify.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/dispatch_helpers.php';
require_once __DIR__ . '/../includes/audit.php';

$user = require_login('admin');
$pdo = db();

if (is_post()) {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $reportId = (int) ($_POST['report_id'] ?? 0);

    try {
        if ($action === 'save_report') {
            $saveId = (int) ($_POST['id'] ?? 0);
            $userId = (int) ($_POST['user_id'] ?? 0);
            $typeId = (int) ($_POST['emergency_type_id'] ?? 0);
            $title = trim((string) ($_POST['title'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $address = trim((string) ($_POST['address'] ?? ''));
            $severity = (string) ($_POST['severity'] ?? 'medium');
            $status = (string) ($_POST['status'] ?? 'pending');
            $reportedByName = trim((string) ($_POST['reported_by_name'] ?? ''));
            $reportedByPhone = trim((string) ($_POST['reported_by_phone'] ?? ''));
            $latitude = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
            $longitude = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);

            $reqUnits = $_POST['requested_unit_types'] ?? [];
            if (is_array($reqUnits)) {
                $reqUnits = array_intersect($reqUnits, ['fire', 'ambulance', 'police', 'rescue', 'other']);
                $reqUnitsStr = implode(',', $reqUnits);
            } else {
                $reqUnitsStr = '';
            }

            if ($typeId <= 0 || $title === '' || $description === '' || $address === '' || $reportedByName === '' || $reportedByPhone === '') {
                throw new RuntimeException('Complete all report fields.');
            }
            if ($latitude === false || $longitude === false) {
                throw new RuntimeException('Use valid coordinates.');
            }
            if (!in_array($severity, ['low', 'medium', 'high', 'critical'], true) || !in_array($status, report_statuses(), true)) {
                throw new RuntimeException('Invalid report status or severity.');
            }

            if ($reqUnitsStr === '') {
                $typeStmt = $pdo->prepare('SELECT name FROM Emergency_Type WHERE id = ?');
                $typeStmt->execute([$typeId]);
                $typeName = (string) $typeStmt->fetchColumn();
                $defaultTypes = unit_types_for_emergency($typeName);
                $reqUnitsStr = implode(',', $defaultTypes);
            }

            $verifiedBy = in_array($status, ['verified', 'assigned', 'dispatched', 'in_progress', 'resolved', 'rejected'], true) ? $user['id'] : null;
            if ($saveId > 0) {
                $before = audit_fetch_row($pdo, 'Emergency_Report', $saveId);
                $stmt = $pdo->prepare('
                    UPDATE Emergency_Report
                    SET user_id = ?, emergency_type_id = ?, title = ?, description = ?, address = ?, latitude = ?, longitude = ?,
                        severity = ?, status = ?, reported_by_name = ?, reported_by_phone = ?, requested_unit_types = ?, verified_by = COALESCE(verified_by, ?)
                    WHERE id = ?
                ');
                $stmt->execute([$userId ?: null, $typeId, $title, $description, $address, $latitude, $longitude, $severity, $status, $reportedByName, $reportedByPhone, $reqUnitsStr, $verifiedBy, $saveId]);
                if ($before) {
                    audit_log_changes('updated', 'emergency_report', $saveId, $title, audit_compare_rows($before, [
                        'title' => $title,
                        'description' => $description,
                        'address' => $address,
                        'severity' => $severity,
                        'status' => $status,
                        'reported_by_name' => $reportedByName,
                        'reported_by_phone' => $reportedByPhone,
                        'requested_unit_types' => $reqUnitsStr,
                    ], ['title', 'description', 'address', 'severity', 'status', 'reported_by_name', 'reported_by_phone', 'requested_unit_types']), $user);
                }
                set_flash('success', 'Report updated.');
            } else {
                $stmt = $pdo->prepare('
                    INSERT INTO Emergency_Report
                        (user_id, emergency_type_id, title, description, address, latitude, longitude, severity, requested_unit_types, status, reported_by_name, reported_by_phone, verified_by)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([$userId ?: null, $typeId, $title, $description, $address, $latitude, $longitude, $severity, $reqUnitsStr, $status, $reportedByName, $reportedByPhone, $verifiedBy]);
                $newId = (int) $pdo->lastInsertId();
                audit_log('created', 'emergency_report', $newId, $title, 'Admin created emergency report: ' . $title, 'status', null, $status, $user);
                set_flash('success', 'Report created.');
            }
            redirect('admin/reports.php');
        }

        if ($action === 'delete_report') {
            if ($reportId <= 0) {
                throw new RuntimeException('Invalid report.');
            }
            $reportRow = audit_fetch_row($pdo, 'Emergency_Report', $reportId);
            $pdo->beginTransaction();
            release_assignment_resources_for_report($pdo, $reportId, $user);
            $pdo->prepare('DELETE FROM Emergency_Report WHERE id = ?')->execute([$reportId]);
            $pdo->commit();
            audit_log(
                'deleted',
                'emergency_report',
                $reportId,
                $reportRow ? audit_report_label($reportRow) : ('Report #' . $reportId),
                'Admin deleted emergency report',
                actor: $user,
            );
            set_flash('success', 'Report deleted.');
            redirect('admin/reports.php');
        }

        if ($reportId <= 0) {
            throw new RuntimeException('Invalid report.');
        }

        if ($action === 'verify') {
            $reportRow = audit_fetch_row($pdo, 'Emergency_Report', $reportId);
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('UPDATE Emergency_Report SET status = "verified", verified_by = ? WHERE id = ? AND status = "pending"');
            $stmt->execute([$user['id'], $reportId]);
            if ($stmt->rowCount() === 0) {
                $pdo->rollBack();
                throw new RuntimeException('Report could not be verified.');
            }
            audit_log(
                'verified',
                'emergency_report',
                $reportId,
                $reportRow ? audit_report_label($reportRow) : ('Report #' . $reportId),
                null,
                'status',
                $reportRow['status'] ?? 'pending',
                'verified',
                $user,
            );
            $auto = try_auto_dispatch($pdo, $reportId, $user['id'], $user);
            $pdo->commit();
            if ($auto['assigned'] ?? false) {
                set_flash('success', 'Report verified and auto-dispatched to nearest unit (' . ($auto['distance_km'] ?? '?') . ' km).');
            } else {
                set_flash('success', 'Report verified. No available unit within ' . DISPATCH_RANGE_KM . ' km — assign manually.');
            }
        } elseif ($action === 'reject') {
            $notes = trim((string) ($_POST['resolution_notes'] ?? ''));
            $reportRow = audit_fetch_row($pdo, 'Emergency_Report', $reportId);
            $stmt = $pdo->prepare('UPDATE Emergency_Report SET status = "rejected", verified_by = ?, resolution_notes = ? WHERE id = ? AND status IN ("pending", "verified")');
            $stmt->execute([$user['id'], $notes, $reportId]);
            audit_log(
                'rejected',
                'emergency_report',
                $reportId,
                $reportRow ? audit_report_label($reportRow) : ('Report #' . $reportId),
                null,
                'status',
                $reportRow['status'] ?? null,
                'rejected',
                $user,
            );
            set_flash('success', 'Report rejected.');
        } elseif ($action === 'resolve') {
            $notes = trim((string) ($_POST['resolution_notes'] ?? ''));
            $reportRow = audit_fetch_row($pdo, 'Emergency_Report', $reportId);
            $pdo->beginTransaction();
            release_assignment_resources_for_report($pdo, $reportId, $user);
            $pdo->prepare('UPDATE Dispatch_Assignment SET assignment_status = "completed", completed_at = COALESCE(completed_at, NOW()) WHERE emergency_report_id = ? AND assignment_status IN ("assigned", "accepted", "arrived")')->execute([$reportId]);
            $pdo->prepare('UPDATE Emergency_Report SET status = "resolved", resolution_notes = ? WHERE id = ?')->execute([$notes, $reportId]);
            $pdo->commit();
            audit_log(
                'resolved',
                'emergency_report',
                $reportId,
                $reportRow ? audit_report_label($reportRow) : ('Report #' . $reportId),
                null,
                'status',
                $reportRow['status'] ?? null,
                'resolved',
                $user,
            );
            set_flash('success', 'Report resolved and resources released.');
        } elseif ($action === 'assign') {
            $unitId = (int) ($_POST['dispatch_unit_id'] ?? 0);
            $responderId = (int) ($_POST['responder_id'] ?? 0);
            $instructions = trim((string) ($_POST['instructions'] ?? ''));

            if ($unitId <= 0 || $responderId <= 0) {
                throw new RuntimeException('Select a dispatch unit and responder.');
            }

            $pdo->beginTransaction();
            $report = $pdo->prepare('SELECT id, status FROM Emergency_Report WHERE id = ? FOR UPDATE');
            $report->execute([$reportId]);
            $reportRow = $report->fetch();
            if (!$reportRow || in_array($reportRow['status'], ['resolved', 'rejected'], true)) {
                throw new RuntimeException('This report cannot be assigned.');
            }

            create_dispatch_assignment($pdo, $reportId, $unitId, $responderId, $user['id'], $instructions, $user);
            $pdo->commit();
            set_flash('success', 'Dispatch assignment created.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', $e->getMessage() ?: 'Report action failed.');
    }

    redirect('admin/reports.php');
}

$types = $pdo->query('SELECT id, name FROM Emergency_Type ORDER BY name')->fetchAll();
$reportUsers = $pdo->query('SELECT id, name, email FROM Users WHERE role = "user" ORDER BY name')->fetchAll();
$units = $pdo->query('SELECT id, unit_name, status FROM Dispatch_Unit ORDER BY unit_name')->fetchAll();
$responders = $pdo->query('
    SELECT r.id, r.badge_no, r.availability_status, r.dispatch_unit_id, u.name, du.unit_name
    FROM Responder r
    INNER JOIN Users u ON u.id = r.user_id
    LEFT JOIN Dispatch_Unit du ON du.id = r.dispatch_unit_id
    ORDER BY u.name
')->fetchAll();

$editReportId = (int) ($_GET['edit_report'] ?? 0);
$editingReport = null;
if ($editReportId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM Emergency_Report WHERE id = ?');
    $stmt->execute([$editReportId]);
    $editingReport = $stmt->fetch() ?: null;
}

$where = [];
$params = [];
if (($_GET['status'] ?? '') !== '') {
    $where[] = 'er.status = ?';
    $params[] = $_GET['status'];
}
if ((int) ($_GET['type'] ?? 0) > 0) {
    $where[] = 'er.emergency_type_id = ?';
    $params[] = (int) $_GET['type'];
}
if (($_GET['severity'] ?? '') !== '') {
    $where[] = 'er.severity = ?';
    $params[] = $_GET['severity'];
}

$sql = '
    SELECT
        er.*,
        et.name AS type_name,
        reporter.email AS reporter_email,
        verifier.name AS verifier_name,
        da.id AS assignment_id,
        da.assignment_status,
        du.unit_name,
        responder_user.name AS responder_name
    FROM Emergency_Report er
    INNER JOIN Emergency_Type et ON et.id = er.emergency_type_id
    LEFT JOIN Users reporter ON reporter.id = er.user_id
    LEFT JOIN Users verifier ON verifier.id = er.verified_by
    LEFT JOIN Dispatch_Assignment da ON da.id = (
        SELECT da2.id
        FROM Dispatch_Assignment da2
        WHERE da2.emergency_report_id = er.id
        ORDER BY da2.id DESC
        LIMIT 1
    )
    LEFT JOIN Dispatch_Unit du ON du.id = da.dispatch_unit_id
    LEFT JOIN Responder r ON r.id = da.responder_id
    LEFT JOIN Users responder_user ON responder_user.id = r.user_id
';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY FIELD(er.status, "pending", "verified", "assigned", "dispatched", "in_progress", "resolved", "rejected"), er.created_at DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reports = $stmt->fetchAll();

$selectedReqUnits = [];
if ($editingReport) {
    $selectedReqUnits = explode(',', $editingReport['requested_unit_types'] ?? '');
}

page_header('Reports', 'reports');
?>
<section class="space-y-5">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-xs font-bold uppercase text-red-600">Emergency Reports</p>
            <h1 class="mt-1 text-3xl font-black text-slate-950">Verification and Dispatch</h1>
        </div>
        <a class="btn-secondary" href="<?= h(url_path('admin/index.php')) ?>">Dashboard</a>
    </div>

    <form method="post" class="panel space-y-4 p-5">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="save_report">
        <input type="hidden" name="id" value="<?= (int) ($editingReport['id'] ?? 0) ?>">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-xl font-black text-slate-950"><?= $editingReport ? 'Edit Emergency Report' : 'Create Emergency Report' ?></h2>
            <?php if ($editingReport): ?>
                <a class="btn-secondary" href="<?= h(url_path('admin/reports.php')) ?>">Cancel Edit</a>
            <?php endif; ?>
        </div>
        <div class="grid gap-4 md:grid-cols-3">
            <div>
                <label class="field-label" for="user_id">Linked User</label>
                <select class="field-select" id="user_id" name="user_id">
                    <option value="">Public/manual report</option>
                    <?php foreach ($reportUsers as $reportUser): ?>
                        <option value="<?= (int) $reportUser['id'] ?>" <?= (int) ($editingReport['user_id'] ?? 0) === (int) $reportUser['id'] ? 'selected' : '' ?>><?= h($reportUser['name']) ?> (<?= h($reportUser['email']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="field-label" for="emergency_type_id">Emergency Type</label>
                <select class="field-select" id="emergency_type_id" name="emergency_type_id" required>
                    <?php foreach ($types as $type): ?>
                        <option value="<?= (int) $type['id'] ?>" <?= (int) ($editingReport['emergency_type_id'] ?? 0) === (int) $type['id'] ? 'selected' : '' ?>><?= h($type['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="field-label" for="status">Status</label>
                <select class="field-select" id="status" name="status" required>
                    <?php foreach (report_statuses() as $status): ?>
                        <option value="<?= h($status) ?>" <?= ($editingReport['status'] ?? 'pending') === $status ? 'selected' : '' ?>><?= h(ucwords(str_replace('_', ' ', $status))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="field-label" for="title">Title</label>
                <input class="field-input" id="title" name="title" required value="<?= h($editingReport['title'] ?? '') ?>">
            </div>
            <div>
                <label class="field-label" for="severity">Severity</label>
                <select class="field-select" id="severity" name="severity" required>
                    <?php foreach (['low', 'medium', 'high', 'critical'] as $severity): ?>
                        <option value="<?= h($severity) ?>" <?= ($editingReport['severity'] ?? 'medium') === $severity ? 'selected' : '' ?>><?= h(ucfirst($severity)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="field-label" for="reported_by_phone">Reporter Phone</label>
                <input class="field-input" id="reported_by_phone" name="reported_by_phone" required value="<?= h($editingReport['reported_by_phone'] ?? '') ?>">
            </div>
            <div>
                <label class="field-label" for="reported_by_name">Reporter Name</label>
                <input class="field-input" id="reported_by_name" name="reported_by_name" required value="<?= h($editingReport['reported_by_name'] ?? '') ?>">
            </div>
            <div>
                <label class="field-label" for="latitude">Latitude</label>
                <input class="field-input" id="latitude" name="latitude" required value="<?= h($editingReport['latitude'] ?? '23.8103000') ?>">
            </div>
            <div>
                <label class="field-label" for="longitude">Longitude</label>
                <input class="field-input" id="longitude" name="longitude" required value="<?= h($editingReport['longitude'] ?? '90.4125000') ?>">
            </div>
            <div class="md:col-span-3">
                <span class="field-label">Services/Units Needed</span>
                <div class="mt-2 grid grid-cols-2 gap-2 md:grid-cols-4 text-sm">
                    <label class="flex items-center gap-2 rounded border border-slate-200 bg-slate-50 p-2.5 hover:bg-slate-100 transition cursor-pointer">
                        <input type="checkbox" name="requested_unit_types[]" value="fire" <?= in_array('fire', $selectedReqUnits, true) ? 'checked' : '' ?> class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                        <span class="font-semibold text-slate-700">Fire Unit</span>
                    </label>
                    <label class="flex items-center gap-2 rounded border border-slate-200 bg-slate-50 p-2.5 hover:bg-slate-100 transition cursor-pointer">
                        <input type="checkbox" name="requested_unit_types[]" value="ambulance" <?= in_array('ambulance', $selectedReqUnits, true) ? 'checked' : '' ?> class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                        <span class="font-semibold text-slate-700">Ambulance</span>
                    </label>
                    <label class="flex items-center gap-2 rounded border border-slate-200 bg-slate-50 p-2.5 hover:bg-slate-100 transition cursor-pointer">
                        <input type="checkbox" name="requested_unit_types[]" value="police" <?= in_array('police', $selectedReqUnits, true) ? 'checked' : '' ?> class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                        <span class="font-semibold text-slate-700">Police</span>
                    </label>
                    <label class="flex items-center gap-2 rounded border border-slate-200 bg-slate-50 p-2.5 hover:bg-slate-100 transition cursor-pointer">
                        <input type="checkbox" name="requested_unit_types[]" value="rescue" <?= in_array('rescue', $selectedReqUnits, true) ? 'checked' : '' ?> class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                        <span class="font-semibold text-slate-700">Rescue</span>
                    </label>
                </div>
            </div>
            <div class="md:col-span-3">
                <label class="field-label" for="address">Address</label>
                <input class="field-input" id="address" name="address" required value="<?= h($editingReport['address'] ?? '') ?>">
            </div>
            <div class="md:col-span-3">
                <label class="field-label" for="description">Description</label>
                <textarea class="field-textarea" id="description" name="description" required><?= h($editingReport['description'] ?? '') ?></textarea>
            </div>
        </div>
        <button class="btn-primary" type="submit"><?= $editingReport ? 'Update Report' : 'Create Report' ?></button>
    </form>

    <form method="get" class="panel grid gap-3 p-4 md:grid-cols-4">
        <select class="field-select" name="status">
            <option value="">All statuses</option>
            <?php foreach (report_statuses() as $status): ?>
                <option value="<?= h($status) ?>" <?= ($_GET['status'] ?? '') === $status ? 'selected' : '' ?>><?= h(ucwords(str_replace('_', ' ', $status))) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="field-select" name="type">
            <option value="">All types</option>
            <?php foreach ($types as $type): ?>
                <option value="<?= (int) $type['id'] ?>" <?= (int) ($_GET['type'] ?? 0) === (int) $type['id'] ? 'selected' : '' ?>><?= h($type['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="field-select" name="severity">
            <option value="">All severities</option>
            <?php foreach (['low', 'medium', 'high', 'critical'] as $severity): ?>
                <option value="<?= h($severity) ?>" <?= ($_GET['severity'] ?? '') === $severity ? 'selected' : '' ?>><?= h(ucfirst($severity)) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn-primary" type="submit">Apply Filters</button>
    </form>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Report</th>
                    <th>Location</th>
                    <th>Status &amp; Assignment</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reports as $report): ?>
                    <?php
                    $assignments = $pdo->prepare('
                        SELECT da.*, du.unit_name, du.unit_type, u.name AS responder_name
                        FROM Dispatch_Assignment da
                        LEFT JOIN Dispatch_Unit du ON du.id = da.dispatch_unit_id
                        LEFT JOIN Responder r ON r.id = da.responder_id
                        LEFT JOIN Users u ON u.id = r.user_id
                        WHERE da.emergency_report_id = ? AND da.assignment_status IN ("assigned", "accepted", "arrived")
                        ORDER BY da.id ASC
                    ');
                    $assignments->execute([$report['id']]);
                    $activeAssignments = $assignments->fetchAll();
                    ?>
                    <tr>
                        <td class="min-w-56">
                            <strong class="block text-slate-950">#<?= (int) $report['id'] ?> <?= h($report['title']) ?></strong>
                            <span class="block text-sm text-slate-600"><?= h($report['type_name']) ?> · <?= h($report['reported_by_name']) ?></span>
                            <span class="block text-xs text-slate-400"><?= h($report['reported_by_phone']) ?></span>
                            <span class="mt-1 inline-flex badge <?= h(severity_badge_class($report['severity'])) ?>"><?= h($report['severity']) ?></span>
                        </td>
                        <td class="min-w-44">
                            <span class="block text-sm"><?= h($report['address']) ?></span>
                            <span class="text-xs text-slate-500"><?= h($report['latitude']) ?>, <?= h($report['longitude']) ?></span>
                        </td>
                        <td class="min-w-48">
                            <span class="badge <?= h(status_badge_class($report['status'])) ?>"><?= h(str_replace('_', ' ', $report['status'])) ?></span>
                            <?php if ($report['verifier_name']): ?>
                                <span class="mt-1 block text-xs text-slate-500">by <?= h($report['verifier_name']) ?></span>
                            <?php endif; ?>
                            <?php if ($activeAssignments): ?>
                                <div class="mt-2 space-y-1.5">
                                    <?php foreach ($activeAssignments as $assign): ?>
                                        <div class="rounded border border-slate-100 bg-slate-50/50 p-1.5 text-xs">
                                            <strong class="block text-slate-900"><?= h($assign['unit_name'] ?: 'No unit') ?></strong>
                                            <span class="block text-slate-500"><?= h($assign['responder_name'] ?: '—') ?></span>
                                            <span class="mt-0.5 inline-flex badge <?= h(status_badge_class($assign['assignment_status'] ?? 'assigned')) ?>"><?= h(str_replace('_', ' ', $assign['assignment_status'] ?? 'assigned')) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="mt-1 block text-xs text-slate-400">No assignment yet</span>
                            <?php endif; ?>
                        </td>
                        <td class="min-w-72">
                            <div class="space-y-2">
                                <?php if ($report['status'] === 'pending'): ?>
                                    <form method="post">
                                        <?= csrf_input() ?>
                                        <input type="hidden" name="report_id" value="<?= (int) $report['id'] ?>">
                                        <input type="hidden" name="action" value="verify">
                                        <button class="btn-primary w-full" type="submit">✓ Verify Report</button>
                                    </form>
                                <?php endif; ?>

                                <?php if (!in_array($report['status'], ['resolved', 'rejected'], true)): ?>
                                     <?php
                                     $reqTypes = array_filter(explode(',', $report['requested_unit_types'] ?? ''));
                                     if (empty($reqTypes)) {
                                         $reqTypes = unit_types_for_emergency($report['type_name']);
                                     }
                                     ?>
                                     <div class="space-y-3">
                                         <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Assign &amp; Dispatch</p>
                                         <?php foreach ($reqTypes as $reqType): ?>
                                             <?php
                                             $hasActiveType = false;
                                             foreach ($activeAssignments as $assign) {
                                                 if (($assign['unit_type'] ?? '') === $reqType) {
                                                     $hasActiveType = true;
                                                     break;
                                                 }
                                             }
                                             ?>
                                             <form method="post" class="dispatch-assign-form grid gap-2 rounded-lg border border-slate-200 bg-white p-2.5"
                                                   data-report-id="<?= (int) $report['id'] ?>"
                                                   data-unit-type="<?= h($reqType) ?>"
                                                   data-lat="<?= h($report['latitude']) ?>"
                                                   data-lng="<?= h($report['longitude']) ?>">
                                                 <?= csrf_input() ?>
                                                 <input type="hidden" name="report_id" value="<?= (int) $report['id'] ?>">
                                                 <input type="hidden" name="action" value="assign">
                                                 <div class="flex items-center gap-1.5 border-b border-slate-100 pb-1.5">
                                                     <span class="text-[11px] font-black uppercase text-slate-700 flex-1"><?= h(ucfirst($reqType)) ?> Dispatch</span>
                                                     <span class="rounded-full border px-2 py-0.5 text-[9px] font-bold shrink-0 <?= $hasActiveType ? 'bg-emerald-100 border-emerald-300 text-emerald-700' : 'bg-amber-100 border-amber-300 text-amber-700' ?>">
                                                         <?= $hasActiveType ? '✓ Assigned' : 'Pending' ?>
                                                     </span>
                                                 </div>
                                                 <p class="dispatch-nearest-hint text-[10px] text-slate-400">Finding nearest <?= h($reqType) ?> units…</p>
                                                 <select class="field-select dispatch-unit-select" name="dispatch_unit_id" required>
                                                     <option value="">Select <?= h($reqType) ?> unit</option>
                                                     <?php foreach ($units as $unit): ?>
                                                         <option value="<?= (int) $unit['id'] ?>"><?= h($unit['unit_name']) ?> (<?= h($unit['status']) ?>)</option>
                                                     <?php endforeach; ?>
                                                 </select>
                                                 <select class="field-select dispatch-responder-select" name="responder_id" required>
                                                     <option value="">Select responder</option>
                                                     <?php foreach ($responders as $responder): ?>
                                                         <option value="<?= (int) $responder['id'] ?>" data-unit-id="<?= (int) ($responder['dispatch_unit_id'] ?? 0) ?>">
                                                             <?= h($responder['name']) ?> (<?= h($responder['availability_status']) ?>)
                                                         </option>
                                                     <?php endforeach; ?>
                                                 </select>
                                                 <input class="field-input" name="instructions" placeholder="Instructions (optional)">
                                                 <button class="btn-primary w-full !py-1.5 !min-h-0 text-xs font-bold" type="submit">Dispatch <?= h(ucfirst($reqType)) ?></button>
                                             </form>
                                         <?php endforeach; ?>
                                     </div>

                                    <div class="grid gap-1.5">
                                        <form method="post" class="flex gap-1.5">
                                            <?= csrf_input() ?>
                                            <input type="hidden" name="report_id" value="<?= (int) $report['id'] ?>">
                                            <input type="hidden" name="action" value="resolve">
                                            <input class="field-input min-w-0 flex-1" name="resolution_notes" placeholder="Resolution notes">
                                            <button class="btn-secondary shrink-0" type="submit">Resolve</button>
                                        </form>
                                        <?php if (in_array($report['status'], ['pending', 'verified'], true)): ?>
                                            <form method="post" class="flex gap-1.5">
                                                <?= csrf_input() ?>
                                                <input type="hidden" name="report_id" value="<?= (int) $report['id'] ?>">
                                                <input type="hidden" name="action" value="reject">
                                                <input class="field-input min-w-0 flex-1" name="resolution_notes" placeholder="Reason for rejection">
                                                <button class="btn-danger shrink-0" type="submit">Reject</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="flex flex-wrap gap-1.5 border-t border-slate-100 pt-2">
                                    <a class="btn-secondary" href="<?= h(url_path('admin/reports.php?edit_report=' . (int) $report['id'])) ?>">Edit</a>
                                    <form method="post" class="inline-flex" onsubmit="return confirm('Delete this report and all its assignments?');">
                                        <?= csrf_input() ?>
                                        <input type="hidden" name="report_id" value="<?= (int) $report['id'] ?>">
                                        <input type="hidden" name="action" value="delete_report">
                                        <button class="btn-danger" type="submit">Delete</button>
                                    </form>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$reports): ?>
                    <tr><td colspan="4" class="text-center text-slate-500">No reports match the filters.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php page_footer(['assets/js/admin-dispatch-suggestions.js']); ?>
