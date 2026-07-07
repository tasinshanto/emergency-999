<?php
/**
 * API: responder self-assigns to an active incident from the live map.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dispatch_helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['success' => false, 'message' => 'POST is required.'], 405);
}

$user = current_user();
if (!$user || $user['role'] !== 'responder') {
    json_response(['success' => false, 'message' => 'Responder access required.'], 403);
}

$payload = $_POST ?: read_json_body();
$reportId = (int) ($payload['report_id'] ?? 0);
if ($reportId <= 0) {
    json_response(['success' => false, 'message' => 'report_id is required.'], 422);
}

try {
    $pdo = db();
    $stmt = $pdo->prepare('
        SELECT r.*, du.latitude AS base_lat, du.longitude AS base_lng, du.unit_name
        FROM Responder r
        LEFT JOIN Dispatch_Unit du ON du.id = r.dispatch_unit_id
        WHERE r.user_id = ?
    ');
    $stmt->execute([$user['id']]);
    $responder = $stmt->fetch();
    if (!$responder) {
        json_response(['success' => false, 'message' => 'Responder profile not found.'], 404);
    }

    $report = fetch_dispatchable_report($pdo, $reportId);
    if (!$report) {
        json_response(['success' => false, 'message' => 'Report not available.'], 404);
    }

    $check = evaluate_responder_self_dispatch($pdo, $responder, $report);
    dispatch_debug_log('api/responder_self_dispatch.php', 'eligibility', [
        'reportId' => $reportId,
        'responderId' => (int) $responder['id'],
        'check' => $check,
    ], 'H6');

    if (!$check['allowed']) {
        json_response([
            'success' => false,
            'message' => match ($check['reason'] ?? '') {
                'responder_busy' => 'You must be available to self-dispatch.',
                'assigned_to_other' => 'Another responder is already assigned.',
                'already_assigned_to_you' => 'You already have an assignment on this incident.',
                'invalid_report_status' => 'This incident is no longer open for self-assignment.',
                'no_unit' => 'Ask admin to link you to a dispatch unit first.',
                default => 'Self-dispatch is not allowed for this incident.',
            },
            'reason' => $check['reason'] ?? 'denied',
        ], 403);
    }

    $unitId = (int) $responder['dispatch_unit_id'];
    $pdo->beginTransaction();
    create_dispatch_assignment(
        $pdo,
        $reportId,
        $unitId,
        (int) $responder['id'],
        null,
        $check['all_busy_override']
            ? 'Self-dispatched (extended range — all nearby units busy).'
            : 'Self-dispatched from live map.',
        $user,
    );
    $pdo->commit();

    dispatch_debug_log('api/responder_self_dispatch.php', 'self dispatched', [
        'reportId' => $reportId,
        'unitId' => $unitId,
        'override' => $check['all_busy_override'],
    ], 'H7');

    json_response([
        'success' => true,
        'message' => 'You are dispatched to this incident.',
        'assignment' => [
            'report_id' => $reportId,
            'unit_id' => $unitId,
            'distance_km' => $check['distance_km'] ?? null,
            'all_busy_override' => $check['all_busy_override'],
        ],
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(['success' => false, 'message' => 'Self-dispatch failed.'], 500);
}
