<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['success' => false, 'message' => 'POST is required.'], 405);
}

$user = current_user();
if (!$user) {
    json_response(['success' => false, 'message' => 'Authentication required.'], 401);
}
if (!in_array($user['role'], ['admin', 'responder'], true)) {
    json_response(['success' => false, 'message' => 'Access denied.'], 403);
}
$payload = $_POST ?: read_json_body();
$assignmentId = (int) ($payload['assignment_id'] ?? 0);
$status = trim((string) ($payload['assignment_status'] ?? ''));
$notes = trim((string) ($payload['responder_notes'] ?? ''));

if ($assignmentId <= 0 || !in_array($status, assignment_statuses(), true)) {
    json_response(['success' => false, 'message' => 'Invalid assignment update.'], 422);
}

if ($status === 'cancelled' && $user['role'] !== 'admin') {
    json_response(['success' => false, 'message' => 'Only admins can cancel assignments.'], 403);
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('
        SELECT da.*, r.user_id AS responder_user_id
        FROM Dispatch_Assignment da
        LEFT JOIN Responder r ON r.id = da.responder_id
        WHERE da.id = ?
        FOR UPDATE
    ');
    $stmt->execute([$assignmentId]);
    $assignment = $stmt->fetch();

    if (!$assignment) {
        $pdo->rollBack();
        json_response(['success' => false, 'message' => 'Assignment not found.'], 404);
    }

    if ($user['role'] === 'responder' && (int) $assignment['responder_user_id'] !== (int) $user['id']) {
        $pdo->rollBack();
        json_response(['success' => false, 'message' => 'Assignment does not belong to this responder.'], 403);
    }

    $timeColumn = match ($status) {
        'accepted' => 'accepted_at',
        'arrived' => 'arrived_at',
        'completed' => 'completed_at',
        default => null,
    };

    $sql = 'UPDATE Dispatch_Assignment SET assignment_status = ?, responder_notes = ?';
    $params = [$status, $notes];
    if ($timeColumn) {
        $sql .= ', ' . $timeColumn . ' = COALESCE(' . $timeColumn . ', NOW())';
    }
    $sql .= ' WHERE id = ?';
    $params[] = $assignmentId;
    $oldAssignmentStatus = $assignment['assignment_status'];
    $reportRow = audit_fetch_row($pdo, 'Emergency_Report', (int) $assignment['emergency_report_id']);
    $reportLabel = $reportRow ? audit_report_label($reportRow) : ('Report #' . $assignment['emergency_report_id']);

    $pdo->prepare($sql)->execute($params);

    $reportStatus = match ($status) {
        'accepted' => 'dispatched',
        'arrived'  => 'in_progress',
        'completed'=> 'resolved',
        'cancelled'=> 'verified',
        default    => 'assigned',
    };
    $oldReportStatus = $reportRow['status'] ?? null;
    $pdo->prepare('UPDATE Emergency_Report SET status = ? WHERE id = ?')->execute([$reportStatus, $assignment['emergency_report_id']]);

    $eventType = $status === 'cancelled' ? 'cancelled' : 'status_changed';
    audit_log(
        $eventType,
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

    // NOTE: Dispatch_Unit.status and Responder.availability_status are now
    // managed entirely by the after_dispatch_assignment_update DB trigger.
    // No manual updates are needed here.

    $pdo->commit();
    json_response(['success' => true, 'message' => 'Assignment updated.']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(['success' => false, 'message' => 'Assignment update failed.'], 500);
}
