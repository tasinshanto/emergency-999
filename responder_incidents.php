<?php
/**
 * API: incidents for responder map with distance, assignment state, and self-dispatch eligibility.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dispatch_helpers.php';

$user = current_user();
if (!$user || $user['role'] !== 'responder') {
    json_response(['success' => false, 'message' => 'Responder access required.'], 403);
}

try {
    $pdo = db();

    // Get responder's dispatch unit location for distance calculation
    $stmt = $pdo->prepare('
        SELECT r.id AS responder_id, du.latitude AS base_lat, du.longitude AS base_lng
        FROM Responder r
        LEFT JOIN Dispatch_Unit du ON du.id = r.dispatch_unit_id
        WHERE r.user_id = ?
    ');
    $stmt->execute([$user['id']]);
    $responder = $stmt->fetch();

    $baseLat = $responder['base_lat'] ?? 23.8103;
    $baseLng = $responder['base_lng'] ?? 90.4125;

    // Fetch all active incidents with distance from responder base
    // Uses Haversine approximation for distance in km
    $stmt = $pdo->prepare('
        SELECT
            er.id,
            er.title,
            er.description,
            er.address,
            er.latitude,
            er.longitude,
            er.severity,
            er.status,
            er.created_at,
            er.updated_at,
            et.name AS type_name,
            et.color,
            et.icon,
            du.unit_name,
            du.unit_type,
            ru.name AS responder_name,
            r2.badge_no,
            (6371 * ACOS(
                LEAST(1, COS(RADIANS(?)) * COS(RADIANS(er.latitude))
                * COS(RADIANS(er.longitude) - RADIANS(?))
                + SIN(RADIANS(?)) * SIN(RADIANS(er.latitude)))
            )) AS distance_km,
            CASE
                WHEN da_mine.id IS NOT NULL THEN 1
                ELSE 0
            END AS is_assigned_to_me
        FROM Emergency_Report er
        INNER JOIN Emergency_Type et ON et.id = er.emergency_type_id
        LEFT JOIN Dispatch_Assignment da ON da.id = (
            SELECT da2.id
            FROM Dispatch_Assignment da2
            WHERE da2.emergency_report_id = er.id
              AND da2.assignment_status <> "cancelled"
            ORDER BY da2.id DESC
            LIMIT 1
        )
        LEFT JOIN Dispatch_Unit du ON du.id = da.dispatch_unit_id
        LEFT JOIN Responder r2 ON r2.id = da.responder_id
        LEFT JOIN Users ru ON ru.id = r2.user_id
        LEFT JOIN Dispatch_Assignment da_mine ON da_mine.emergency_report_id = er.id
            AND da_mine.responder_id = ?
            AND da_mine.assignment_status <> "cancelled"
        WHERE er.status IN ("verified", "assigned", "dispatched", "in_progress")
        ORDER BY is_assigned_to_me DESC,
                 FIELD(er.severity, "critical", "high", "medium", "low"),
                 distance_km ASC
    ');
    $stmt->execute([$baseLat, $baseLng, $baseLat, $responder['responder_id'] ?? 0]);

    $incidents = $stmt->fetchAll();

    $responderRow = [
        'id' => (int) ($responder['responder_id'] ?? 0),
        'availability_status' => 'available',
        'dispatch_unit_id' => null,
        'base_lat' => $baseLat,
        'base_lng' => $baseLng,
    ];
    $rStmt = $pdo->prepare('SELECT id, availability_status, dispatch_unit_id FROM Responder WHERE user_id = ?');
    $rStmt->execute([$user['id']]);
    if ($full = $rStmt->fetch()) {
        $responderRow = array_merge($responderRow, $full);
    }

    foreach ($incidents as &$inc) {
        $inc['distance_km'] = round((float) $inc['distance_km'], 2);
        $inc['is_assigned_to_me'] = (int) $inc['is_assigned_to_me'];
        $reportRow = [
            'id' => (int) $inc['id'],
            'status' => $inc['status'],
            'latitude' => $inc['latitude'],
            'longitude' => $inc['longitude'],
            'type_name' => $inc['type_name'],
        ];
        $eval = evaluate_responder_self_dispatch($pdo, $responderRow, $reportRow);
        $inc['can_self_dispatch'] = $eval['allowed'] ? 1 : 0;
        $inc['within_range'] = ($eval['within_range'] ?? false) ? 1 : 0;
        $inc['all_busy_override'] = ($eval['all_busy_override'] ?? false) ? 1 : 0;
        $inc['self_dispatch_reason'] = $eval['reason'] ?? null;
    }
    unset($inc);

    json_response([
        'success' => true,
        'incidents' => $incidents,
        'base_lat' => (float) $baseLat,
        'base_lng' => (float) $baseLng,
        'dispatch_range_km' => DISPATCH_RANGE_KM,
    ]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => 'Incidents are unavailable.'], 500);
}
