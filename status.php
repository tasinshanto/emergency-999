<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

try {
    $pdo = db();
    $reportRows = $pdo->query('SELECT status, COUNT(*) AS total FROM Emergency_Report GROUP BY status')->fetchAll();
    $unitRows = $pdo->query('SELECT status, COUNT(*) AS total FROM Dispatch_Unit GROUP BY status')->fetchAll();
    $responderRows = $pdo->query('SELECT availability_status AS status, COUNT(*) AS total FROM Responder GROUP BY availability_status')->fetchAll();
    $hospitalRows = $pdo->query('SELECT status, COUNT(*) AS total FROM Hospital GROUP BY status')->fetchAll();
    $assignmentRows = $pdo->query('SELECT assignment_status AS status, COUNT(*) AS total FROM Dispatch_Assignment GROUP BY assignment_status')->fetchAll();

    $user = current_user();
    $personal = [];
    if ($user && $user['role'] === 'user') {
        $stmt = $pdo->prepare('SELECT status, COUNT(*) AS total FROM Emergency_Report WHERE user_id = ? GROUP BY status');
        $stmt->execute([$user['id']]);
        $personal['reports'] = $stmt->fetchAll();
    }

    if ($user && $user['role'] === 'responder') {
        $stmt = $pdo->prepare('
            SELECT da.assignment_status AS status, COUNT(*) AS total
            FROM Dispatch_Assignment da
            INNER JOIN Responder r ON r.id = da.responder_id
            WHERE r.user_id = ?
            GROUP BY da.assignment_status
        ');
        $stmt->execute([$user['id']]);
        $personal['assignments'] = $stmt->fetchAll();
    }

    json_response([
        'success' => true,
        'reports' => $reportRows,
        'units' => $unitRows,
        'responders' => $responderRows,
        'hospitals' => $hospitalRows,
        'assignments' => $assignmentRows,
        'personal' => $personal,
    ]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => 'Status summary is unavailable.'], 500);
}
