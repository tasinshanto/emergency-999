<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/audit.php';

$user = require_login('responder');
$pdo = db();

$stmt = $pdo->prepare('
    SELECT r.*, du.unit_name, du.latitude AS base_lat, du.longitude AS base_lng
    FROM Responder r
    LEFT JOIN Dispatch_Unit du ON du.id = r.dispatch_unit_id
    WHERE r.user_id = ?
');
$stmt->execute([$user['id']]);
$responder = $stmt->fetch();

if (!$responder) {
    page_header('Responder Dashboard', 'dashboard');
    ?>
    <div class="panel p-6">
        <h1 class="text-2xl font-black text-slate-950">Responder profile missing</h1>
        <p class="mt-2 text-slate-600">Ask an admin to create a Responder record for this account.</p>
    </div>
    <?php
    page_footer();
    exit;
}

if (is_post()) {
    verify_csrf();
    $assignmentId = (int) ($_POST['assignment_id'] ?? 0);
    $status = (string) ($_POST['assignment_status'] ?? '');
    $notes = trim((string) ($_POST['responder_notes'] ?? ''));

    try {
        if (!in_array($status, ['accepted', 'arrived', 'completed'], true)) {
            throw new RuntimeException('Invalid assignment status.');
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare('
            SELECT *
            FROM Dispatch_Assignment
            WHERE id = ? AND responder_id = ?
            FOR UPDATE
        ');
        $stmt->execute([$assignmentId, $responder['id']]);
        $assignment = $stmt->fetch();
        if (!$assignment) {
            throw new RuntimeException('Assignment not found.');
        }

        $timeColumn = match ($status) {
            'accepted' => 'accepted_at',
            'arrived' => 'arrived_at',
            'completed' => 'completed_at',
        };
        $oldAssignmentStatus = $assignment['assignment_status'];
        $reportRow = audit_fetch_row($pdo, 'Emergency_Report', (int) $assignment['emergency_report_id']);
        $reportLabel = $reportRow ? audit_report_label($reportRow) : ('Report #' . $assignment['emergency_report_id']);

        $pdo->prepare("
            UPDATE Dispatch_Assignment
            SET assignment_status = ?, responder_notes = ?, $timeColumn = COALESCE($timeColumn, NOW())
            WHERE id = ?
        ")->execute([$status, $notes, $assignmentId]);

        $reportStatus = match ($status) {
            'accepted' => 'dispatched',
            'arrived'  => 'in_progress',
            'completed'=> 'resolved',
        };
        $oldReportStatus = $reportRow['status'] ?? null;
        $pdo->prepare('UPDATE Emergency_Report SET status = ? WHERE id = ?')
            ->execute([$reportStatus, $assignment['emergency_report_id']]);

        audit_log(
            'status_changed',
            'dispatch_assignment',
            $assignmentId,
            $reportLabel,
            null,
            'assignment_status',
            $oldAssignmentStatus,
            $status,
            $user,
        );
        if ($oldReportStatus !== null && $oldReportStatus !== $reportStatus) {
            audit_log(
                'status_changed',
                'emergency_report',
                (int) $assignment['emergency_report_id'],
                $reportLabel,
                null,
                'status',
                $oldReportStatus,
                $reportStatus,
                $user,
            );
        }

        // NOTE: Responder.availability_status and Dispatch_Unit.status are
        // now managed by the after_dispatch_assignment_update DB trigger.
        // No manual PHP updates needed here.

        $pdo->commit();
        set_flash('success', 'Assignment status updated.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', $e->getMessage() ?: 'Status update failed.');
    }

    redirect('responder/index.php');
}

$stmt = $pdo->prepare('
    SELECT
        da.*,
        er.title,
        er.description,
        er.address,
        er.latitude,
        er.longitude,
        er.severity,
        er.status AS report_status,
        er.reported_by_name,
        er.reported_by_phone,
        et.name AS type_name,
        du.unit_name
    FROM Dispatch_Assignment da
    INNER JOIN Emergency_Report er ON er.id = da.emergency_report_id
    INNER JOIN Emergency_Type et ON et.id = er.emergency_type_id
    LEFT JOIN Dispatch_Unit du ON du.id = da.dispatch_unit_id
    WHERE da.responder_id = ?
    ORDER BY FIELD(da.assignment_status, "assigned", "accepted", "arrived", "completed", "cancelled"), da.assigned_at DESC
');
$stmt->execute([$responder['id']]);
$assignments = $stmt->fetchAll();

$openAssignments = array_filter($assignments, fn ($row) => in_array($row['assignment_status'], ['assigned', 'accepted', 'arrived'], true));

page_header('Responder Dashboard', 'dashboard', true);
?>
<section class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-xs font-bold uppercase text-red-600">Responder Dashboard</p>
            <h1 class="mt-1 text-3xl font-black text-slate-950"><?= h($user['name']) ?></h1>
            <p class="mt-1 text-slate-600"><?= h($responder['designation']) ?> | <?= h($responder['badge_no']) ?> | <?= h($responder['unit_name'] ?? 'No unit') ?></p>
        </div>
        <span class="badge <?= h(status_badge_class($responder['availability_status'])) ?>"><?= h(str_replace('_', ' ', $responder['availability_status'])) ?></span>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <div class="panel p-4"><span class="text-xs font-bold uppercase text-slate-500">Open Assignments</span><strong class="mt-2 block text-3xl text-red-600"><?= count($openAssignments) ?></strong></div>
        <div class="panel p-4"><span class="text-xs font-bold uppercase text-slate-500">Unit</span><strong class="mt-2 block text-lg text-slate-950"><?= h($responder['unit_name'] ?? 'Unassigned') ?></strong></div>
        <div class="panel p-4"><span class="text-xs font-bold uppercase text-slate-500">Specialization</span><strong class="mt-2 block text-lg text-slate-950"><?= h($responder['specialization'] ?: 'General response') ?></strong></div>
    </div>

    <div class="panel p-4">
        <div class="mb-3 flex items-center justify-between">
            <h2 class="text-xl font-black text-slate-950">Live Incident Map</h2>
            <span id="dashboard-status" class="text-sm text-slate-500">Loading</span>
        </div>
        <div id="dashboard-map" class="dashboard-map" data-responder="true" data-base-lat="<?= h($responder['base_lat'] ?? '23.8103') ?>" data-base-lng="<?= h($responder['base_lng'] ?? '90.4125') ?>"></div>
    </div>

    <div class="grid gap-4">
        <?php foreach ($assignments as $assignment): ?>
            <article class="panel p-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-bold uppercase text-slate-500">Assignment #<?= (int) $assignment['id'] ?></p>
                        <h2 class="mt-1 text-xl font-black text-slate-950"><?= h($assignment['title']) ?></h2>
                        <p class="mt-1 text-sm text-slate-600"><?= h($assignment['type_name']) ?> | <?= h($assignment['address']) ?></p>
                        <p class="mt-2 text-sm"><?= h($assignment['description']) ?></p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <span class="badge <?= h(severity_badge_class($assignment['severity'])) ?>"><?= h($assignment['severity']) ?></span>
                        <span class="badge <?= h(status_badge_class($assignment['assignment_status'])) ?>"><?= h(str_replace('_', ' ', $assignment['assignment_status'])) ?></span>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 text-sm md:grid-cols-3">
                    <div class="rounded border border-slate-200 bg-slate-50 p-3">
                        <strong class="block text-slate-950">Reporter</strong>
                        <span><?= h($assignment['reported_by_name']) ?>, <?= h($assignment['reported_by_phone']) ?></span>
                    </div>
                    <div class="rounded border border-slate-200 bg-slate-50 p-3">
                        <strong class="block text-slate-950">Coordinates</strong>
                        <span><?= h($assignment['latitude']) ?>, <?= h($assignment['longitude']) ?></span>
                    </div>
                    <div class="rounded border border-slate-200 bg-slate-50 p-3">
                        <strong class="block text-slate-950">Instructions</strong>
                        <span><?= h($assignment['instructions'] ?: 'No instructions') ?></span>
                    </div>
                </div>

                <?php if (in_array($assignment['assignment_status'], ['assigned', 'accepted', 'arrived'], true)): ?>
                    <form method="post" class="mt-4 grid gap-3 rounded border border-slate-200 bg-slate-50 p-3 md:grid-cols-[1fr_1fr_auto]">
                        <?= csrf_input() ?>
                        <input type="hidden" name="assignment_id" value="<?= (int) $assignment['id'] ?>">
                        <select class="field-select" name="assignment_status" required>
                            <option value="accepted" <?= $assignment['assignment_status'] === 'assigned' ? '' : 'disabled' ?>>Accept Dispatch</option>
                            <option value="arrived" <?= in_array($assignment['assignment_status'], ['accepted', 'arrived'], true) ? '' : 'disabled' ?>>Mark Arrived</option>
                            <option value="completed">Complete Assignment</option>
                        </select>
                        <input class="field-input" name="responder_notes" value="<?= h($assignment['responder_notes'] ?? '') ?>" placeholder="Status note">
                        <button class="btn-primary" type="submit">Update</button>
                    </form>
                <?php elseif ($assignment['responder_notes']): ?>
                    <p class="mt-4 rounded border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700"><?= h($assignment['responder_notes']) ?></p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
        <?php if (!$assignments): ?>
            <div class="panel p-6 text-center text-slate-500">No assignments yet.</div>
        <?php endif; ?>
    </div>
</section>
<?php page_footer(['assets/js/emergency-dashboard-map.js']); ?>
