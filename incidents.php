<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

try {
    $stmt = db()->query('
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
            r.badge_no
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
        LEFT JOIN Responder r ON r.id = da.responder_id
        LEFT JOIN Users ru ON ru.id = r.user_id
        WHERE er.status IN ("verified", "assigned", "dispatched", "in_progress")
        ORDER BY FIELD(er.severity, "critical", "high", "medium", "low"), er.updated_at DESC
    ');

    json_response(['success' => true, 'incidents' => $stmt->fetchAll()]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => 'Incidents are unavailable.'], 500);
}
