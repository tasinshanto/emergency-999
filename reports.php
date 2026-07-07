<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['success' => false, 'message' => 'POST is required.'], 405);
}

$payload = $_POST ?: read_json_body();

$typeId = (int) ($payload['emergency_type_id'] ?? 0);
$title = trim((string) ($payload['title'] ?? ''));
$description = trim((string) ($payload['description'] ?? ''));
$address = trim((string) ($payload['address'] ?? ''));
$severity = trim((string) ($payload['severity'] ?? 'medium'));
$reportedByName = trim((string) ($payload['reported_by_name'] ?? ''));
$reportedByPhone = trim((string) ($payload['reported_by_phone'] ?? ''));
$latitude = filter_var($payload['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
$longitude = filter_var($payload['longitude'] ?? null, FILTER_VALIDATE_FLOAT);

$reqUnits = $payload['requested_unit_types'] ?? [];
if (is_array($reqUnits)) {
    $reqUnits = array_intersect($reqUnits, ['fire', 'ambulance', 'police', 'rescue', 'other']);
    $reqUnitsStr = implode(',', $reqUnits);
} else {
    $reqUnitsStr = '';
}

if ($typeId <= 0 || $title === '' || $description === '' || $address === '' || $reportedByName === '' || $reportedByPhone === '') {
    json_response(['success' => false, 'message' => 'Please complete all required report fields.'], 422);
}

if ($latitude === false || $longitude === false || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
    json_response(['success' => false, 'message' => 'Please provide valid latitude and longitude.'], 422);
}

if (!in_array($severity, ['low', 'medium', 'high', 'critical'], true)) {
    json_response(['success' => false, 'message' => 'Invalid severity value.'], 422);
}

try {
    $typeQuery = db()->prepare('SELECT id, name FROM Emergency_Type WHERE id = ? AND is_active = 1');
    $typeQuery->execute([$typeId]);
    $typeRow = $typeQuery->fetch();
    if (!$typeRow) {
        json_response(['success' => false, 'message' => 'Invalid emergency type.'], 422);
    }

    if ($reqUnitsStr === '') {
        require_once __DIR__ . '/../includes/dispatch_helpers.php';
        $defaultTypes = unit_types_for_emergency((string) $typeRow['name']);
        $reqUnitsStr = implode(',', $defaultTypes);
    }

    $user = current_user();
    $userId = $user ? (int) $user['id'] : null;
    $stmt = db()->prepare('
        INSERT INTO Emergency_Report
            (user_id, emergency_type_id, title, description, address, latitude, longitude, severity, requested_unit_types, status, reported_by_name, reported_by_phone)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, "pending", ?, ?)
    ');
    $stmt->execute([
        $userId,
        $typeId,
        $title,
        $description,
        $address,
        $latitude,
        $longitude,
        $severity,
        $reqUnitsStr,
        $reportedByName,
        $reportedByPhone,
    ]);
    $reportId = (int) db()->lastInsertId();
    audit_log(
        'created',
        'emergency_report',
        $reportId,
        $title,
        'Emergency report submitted: ' . $title,
        'status',
        null,
        'pending',
        $user,
    );

    json_response([
        'success' => true,
        'message' => 'Report submitted for admin verification.',
        'report_id' => $reportId,
    ], 201);
} catch (Throwable $e) {
    error_log('Emergency report save failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    json_response(['success' => false, 'message' => 'Report could not be saved.'], 500);
}
