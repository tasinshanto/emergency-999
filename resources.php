<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$user = current_user();
if (!$user) {
    json_response(['success' => false, 'message' => 'Authentication required.'], 401);
}

try {
    $pdo = db();
    $units = $pdo->query('
        SELECT id, unit_name, unit_type, phone, base_address, latitude, longitude, status, capacity
        FROM Dispatch_Unit
        ORDER BY unit_name
    ')->fetchAll();

    $hospitals = $pdo->query('
        SELECT id, name, phone, address, latitude, longitude, available_beds, emergency_capacity, status
        FROM Hospital
        ORDER BY name
    ')->fetchAll();

    $responders = $pdo->query('
        SELECT
            r.id,
            r.badge_no,
            r.designation,
            r.specialization,
            r.availability_status,
            u.name,
            u.phone,
            du.unit_name,
            du.latitude,
            du.longitude
        FROM Responder r
        INNER JOIN Users u ON u.id = r.user_id
        LEFT JOIN Dispatch_Unit du ON du.id = r.dispatch_unit_id
        ORDER BY u.name
    ')->fetchAll();

    // Count responders per dispatch unit for staffing warnings
    $unitRespCounts = [];
    foreach ($pdo->query('SELECT dispatch_unit_id, COUNT(*) AS cnt FROM Responder WHERE dispatch_unit_id IS NOT NULL GROUP BY dispatch_unit_id')->fetchAll() as $row) {
        $unitRespCounts[(int) $row['dispatch_unit_id']] = (int) $row['cnt'];
    }
    foreach ($units as &$unit) {
        $unit['responder_count'] = $unitRespCounts[(int) $unit['id']] ?? 0;
    }
    unset($unit);

    json_response([
        'success'    => true,
        'units'      => $units,
        'hospitals'  => $hospitals,
        'responders' => $responders,
    ]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => 'Resources are unavailable.'], 500);
}
