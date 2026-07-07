<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

try {
    $stmt = db()->query('
        SELECT id, name, description, priority_level, icon, color
        FROM Emergency_Type
        WHERE is_active = 1
        ORDER BY FIELD(priority_level, "critical", "high", "medium", "low"), name
    ');

    json_response(['success' => true, 'types' => $stmt->fetchAll()]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => 'Emergency types are unavailable.'], 500);
}
