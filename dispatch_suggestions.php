<?php
/**
 * API: nearest dispatch units and hospitals for a report (admin assignment suggestions).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dispatch_helpers.php';

$user = current_user();
if (!$user || $user['role'] !== 'admin') {
    json_response(['success' => false, 'message' => 'Admin access required.'], 403);
}

$reportId = (int) ($_GET['report_id'] ?? 0);
if ($reportId <= 0) {
    json_response(['success' => false, 'message' => 'report_id is required.'], 422);
}

try {
    $pdo = db();
    $report = fetch_dispatchable_report($pdo, $reportId);
    if (!$report) {
        json_response(['success' => false, 'message' => 'Report not found.'], 404);
    }

    $lat = (float) $report['latitude'];
    $lng = (float) $report['longitude'];
    $requestedTypes = !empty($report['requested_unit_types']) ? $report['requested_unit_types'] : (string) $report['type_name'];
    $data = nearest_dispatch_suggestions($pdo, $lat, $lng, $requestedTypes, 15);

    dispatch_debug_log('api/dispatch_suggestions.php', 'suggestions', [
        'reportId' => $reportId,
        'unitCount' => count($data['units']),
        'nearestKm' => $data['units'][0]['distance_km'] ?? null,
    ], 'H5');

    json_response([
        'success' => true,
        'report_id' => $reportId,
        'range_km' => DISPATCH_RANGE_KM,
        'units' => $data['units'],
        'hospitals' => $data['hospitals'],
        'responders' => $data['responders'],
    ]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => 'Suggestions unavailable.'], 500);
}
